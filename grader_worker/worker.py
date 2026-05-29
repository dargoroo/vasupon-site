#!/usr/bin/env python3
import json
import os
import re
import signal
import ssl
import subprocess
import tempfile
import time
import uuid
from html.parser import HTMLParser
from pathlib import Path
from typing import Any, Callable, Dict, List, Optional
from urllib import error, request


BASE_URL = os.getenv("GRADERAPP_BASE_URL", "http://localhost:8080/grader_app")
WORKER_TOKEN = os.getenv("GRADERAPP_WORKER_SHARED_TOKEN", "grader-worker-token")
WORKER_NAME = os.getenv("GRADERAPP_WORKER_NAME", "rbruai2-worker")
WORKER_HOST = os.getenv("GRADERAPP_WORKER_HOST", "rbruai2.rbru.ac.th")
RUNNER_TARGET = os.getenv("GRADERAPP_RUNNER_TARGET", "rbruai2")
POLL_SECONDS = int(os.getenv("GRADERAPP_POLL_SECONDS", "5"))
DOCKER_BIN = os.getenv("GRADERAPP_DOCKER_BIN", "docker")
PYTHON_IMAGE = os.getenv("GRADERAPP_PYTHON_IMAGE", "python:3.11-alpine")
CPU_LIMIT = os.getenv("GRADERAPP_DOCKER_CPUS", "0.50")
DEFAULT_MEMORY_LIMIT_MB = int(os.getenv("GRADERAPP_DOCKER_MEMORY_MB", "128"))
DOCKER_PIDS_LIMIT = os.getenv("GRADERAPP_DOCKER_PIDS_LIMIT", "64")
DOCKER_TMPFS_MB = int(os.getenv("GRADERAPP_DOCKER_TMPFS_MB", "32"))
STARTUP_GRACE_SECONDS = float(
    os.getenv(
        "GRADERAPP_DOCKER_STARTUP_GRACE_SECONDS",
        os.getenv("GRADERAPP_DOCKER_TIMEOUT_GRACE_SECONDS", "8"),
    )
)
MIN_TIME_LIMIT_SECONDS = float(os.getenv("GRADERAPP_MIN_TIME_LIMIT_SECONDS", "0.1"))
MIN_DOCKER_MEMORY_LIMIT_MB = int(os.getenv("GRADERAPP_MIN_DOCKER_MEMORY_LIMIT_MB", "6"))
MAX_STDIO_BYTES = int(os.getenv("GRADERAPP_MAX_STDIO_BYTES", "32768"))
WORKSPACE_ROOT = Path(os.getenv("GRADERAPP_WORKSPACE_ROOT", "/tmp/grader_worker_jobs"))
SSL_VERIFY = os.getenv("GRADERAPP_SSL_VERIFY", "true").strip().lower() not in {"0", "false", "no", "off"}
URL_CONTEXT = None if SSL_VERIFY else ssl._create_unverified_context()
SHOULD_STOP = False


def request_stop(_signum: int, _frame: object) -> None:
    global SHOULD_STOP
    SHOULD_STOP = True


def sleep_poll() -> None:
    deadline = time.monotonic() + POLL_SECONDS
    while not SHOULD_STOP and time.monotonic() < deadline:
        time.sleep(min(0.5, deadline - time.monotonic()))


def api_url(path: str) -> str:
    return f"{BASE_URL.rstrip('/')}/api/{path.lstrip('/')}"


def worker_headers() -> Dict[str, str]:
    return {
        "Content-Type": "application/json; charset=utf-8",
        "X-Worker-Token": WORKER_TOKEN,
    }


def post_json(path: str, payload: Dict[str, Any], timeout: int = 30) -> Dict[str, Any]:
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = request.Request(api_url(path), data=body, headers=worker_headers(), method="POST")
    try:
        with request.urlopen(req, timeout=timeout, context=URL_CONTEXT) as response:
            return json.loads(response.read().decode("utf-8"))
    except error.HTTPError as exc:
        details = exc.read().decode("utf-8", "replace")
        raise RuntimeError(f"HTTP {exc.code}: {details}") from exc


def heartbeat() -> None:
    payload = {
        "worker_token": WORKER_TOKEN,
        "worker_name": WORKER_NAME,
        "worker_host": WORKER_HOST,
        "capabilities": {
            "languages": ["python", "html", "web"],
            "supportsDocker": True,
            "supportsStaticWebChecks": True,
            "supportsQueuePolling": True,
            "pythonImage": PYTHON_IMAGE,
        },
    }
    post_json("worker_heartbeat.php", payload, timeout=15)


def claim_job() -> Dict[str, Any]:
    payload = {
        "worker_token": WORKER_TOKEN,
        "worker_name": WORKER_NAME,
        "runner_target": RUNNER_TARGET,
    }
    return post_json("worker_claim.php", payload, timeout=30)


def fetch_preview_worker_state(preview_run_id: int, claim_token: str) -> Dict[str, Any]:
    path = f"preview_worker_state.php?preview_run_id={preview_run_id}&claim_token={claim_token}"
    req = request.Request(api_url(path), headers=worker_headers(), method="GET")
    try:
        with request.urlopen(req, timeout=15, context=URL_CONTEXT) as response:
            return json.loads(response.read().decode("utf-8"))
    except error.HTTPError as exc:
        details = exc.read().decode("utf-8", "replace")
        raise RuntimeError(f"HTTP {exc.code}: {details}") from exc


def report_progress(job: Dict[str, Any], stage: str, label: str, case_index: int = 0, case_total: int = 0) -> None:
    payload = {
        "worker_token": WORKER_TOKEN,
        "job_id": job["id"],
        "claim_token": job["claim_token"],
        "stage": stage,
        "label": label,
        "case_index": case_index,
        "case_total": case_total,
    }
    if int(job.get("preview_run_id") or 0) > 0:
        payload["preview_run_id"] = int(job["preview_run_id"])
    else:
        payload["submission_id"] = int(job["submission_id"])
    post_json("worker_progress.php", payload, timeout=15)


def safe_report_progress(job: Dict[str, Any], stage: str, label: str, case_index: int = 0, case_total: int = 0) -> None:
    try:
        report_progress(job, stage, label, case_index=case_index, case_total=case_total)
    except Exception as exc:
        print(f"[grader_worker] progress warning stage={stage}: {exc}")


def normalize_output(value: str) -> str:
    return coerce_text(value).replace("\r\n", "\n").strip()


def coerce_text(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, bytes):
        return value.decode("utf-8", "replace")
    return str(value)


def truncate_text(value: Any) -> str:
    value = coerce_text(value)
    if len(value) <= MAX_STDIO_BYTES:
        return value
    return value[: MAX_STDIO_BYTES - 64] + "\n...[truncated]..."


class WebSubmissionParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.tags: List[Dict[str, Any]] = []
        self.text_parts: List[str] = []

    def handle_starttag(self, tag: str, attrs: List[tuple]) -> None:
        self.tags.append({
            "tag": tag.lower(),
            "attrs": {str(key).lower(): str(value or "") for key, value in attrs},
        })

    def handle_data(self, data: str) -> None:
        if data:
            self.text_parts.append(data)


def web_selector_matches(parsed: WebSubmissionParser, selector: str) -> bool:
    selector = selector.strip()
    if selector == "":
        return False

    if selector.startswith("#"):
        expected_id = selector[1:]
        return any(tag["attrs"].get("id") == expected_id for tag in parsed.tags)

    if selector.startswith("."):
        expected_class = selector[1:]
        return any(
            expected_class in tag["attrs"].get("class", "").split()
            for tag in parsed.tags
        )

    return any(tag["tag"] == selector.lower() for tag in parsed.tags)


def normalize_web_check_payload(expected_stdout: str, stdin_text: str) -> Dict[str, Any]:
    raw = expected_stdout.strip() or stdin_text.strip()
    if raw == "":
        return {"checks": []}
    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        return {
            "checks": [
                {
                    "type": "contains_text",
                    "value": raw,
                    "message": f"HTML should contain: {raw}",
                }
            ]
        }
    if isinstance(decoded, list):
        return {"checks": decoded}
    if isinstance(decoded, dict):
        return decoded
    return {"checks": []}


def evaluate_web_check(source_code: str, parsed: WebSubmissionParser, check: Dict[str, Any]) -> Optional[str]:
    check_type = str(check.get("type") or "").strip().lower()
    value = str(check.get("value") or check.get("selector") or check.get("tag") or "").strip()
    message = str(check.get("message") or "").strip()
    haystack = source_code
    visible_text = " ".join(part.strip() for part in parsed.text_parts if part.strip())

    def fail(default: str) -> str:
        return message or default

    if check_type in {"contains_text", "text_contains"}:
        if value not in visible_text and value not in haystack:
            return fail(f"Missing text: {value}")
        return None

    if check_type in {"not_contains_text", "text_not_contains"}:
        if value in visible_text or value in haystack:
            return fail(f"Forbidden text found: {value}")
        return None

    if check_type in {"tag_exists", "has_tag"}:
        if not any(tag["tag"] == value.lower() for tag in parsed.tags):
            return fail(f"Missing <{value}> tag")
        return None

    if check_type in {"selector_exists", "has_selector"}:
        if not web_selector_matches(parsed, value):
            return fail(f"Missing selector: {value}")
        return None

    if check_type in {"css_contains", "style_contains"}:
        if value not in haystack:
            return fail(f"Missing CSS snippet: {value}")
        return None

    if check_type in {"js_contains", "script_contains", "canvas_uses"}:
        if value not in haystack:
            return fail(f"Missing JavaScript/canvas snippet: {value}")
        return None

    if check_type == "regex":
        flags = re.IGNORECASE if bool(check.get("ignore_case", False)) else 0
        try:
            if re.search(value, haystack, flags=flags | re.MULTILINE | re.DOTALL) is None:
                return fail(f"Pattern not found: {value}")
        except re.error as exc:
            return fail(f"Invalid regex in test case: {exc}")
        return None

    if check_type == "attribute_equals":
        selector = str(check.get("selector") or "").strip()
        attr = str(check.get("attribute") or "").strip().lower()
        expected = str(check.get("expected") or check.get("value") or "")
        for tag in parsed.tags:
            tag_selector_matches = (
                selector == ""
                or (selector.startswith("#") and tag["attrs"].get("id") == selector[1:])
                or (selector.startswith(".") and selector[1:] in tag["attrs"].get("class", "").split())
                or tag["tag"] == selector.lower()
            )
            if tag_selector_matches and tag["attrs"].get(attr) == expected:
                return None
        return fail(f"Missing attribute {attr}={expected} on {selector or 'any tag'}")

    return fail(f"Unsupported web check type: {check_type or '(blank)'}")


def execute_web_test_case(source_code: str, stdin_text: str, expected_stdout: str) -> Dict[str, Any]:
    started = time.perf_counter()
    parser = WebSubmissionParser()
    try:
        parser.feed(source_code)
    except Exception as exc:
        return {
            "status": "runtime_error",
            "actual_stdout": "",
            "stderr_text": f"HTML parse error: {exc}",
            "execution_time_ms": int((time.perf_counter() - started) * 1000),
            "memory_used_kb": 0,
        }

    payload = normalize_web_check_payload(expected_stdout, stdin_text)
    checks = payload.get("checks", [])
    if not isinstance(checks, list):
        checks = []

    failures = []
    for check in checks:
        if not isinstance(check, dict):
            failures.append("Invalid web check item")
            continue
        failure = evaluate_web_check(source_code, parser, check)
        if failure:
            failures.append(failure)

    status = "pass" if not failures else "fail"
    summary = {
        "checks_total": len(checks),
        "checks_passed": len(checks) - len(failures),
        "failures": failures,
    }
    return {
        "status": status,
        "actual_stdout": json.dumps(summary, ensure_ascii=False),
        "stderr_text": "\n".join(failures),
        "execution_time_ms": int((time.perf_counter() - started) * 1000),
        "memory_used_kb": 0,
    }


def normalize_time_limit_seconds(value: Any) -> float:
    try:
        parsed = float(value)
    except (TypeError, ValueError):
        parsed = 2.0
    return max(MIN_TIME_LIMIT_SECONDS, parsed)


def normalize_memory_limit_mb(value: Any) -> int:
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        parsed = DEFAULT_MEMORY_LIMIT_MB
    return max(1, parsed)


def docker_command(workspace: Path, result_dir: Path, memory_limit_mb: int, container_name: str) -> List[str]:
    return [
        DOCKER_BIN,
        "run",
        "--rm",
        "--name",
        container_name,
        "-i",
        "--network",
        "none",
        "--cpus",
        CPU_LIMIT,
        "--memory",
        f"{memory_limit_mb}m",
        "--pids-limit",
        DOCKER_PIDS_LIMIT,
        "--read-only",
        "--cap-drop",
        "ALL",
        "--security-opt",
        "no-new-privileges",
        "--tmpfs",
        f"/tmp:rw,noexec,nosuid,nodev,size={DOCKER_TMPFS_MB}m",
        "-e",
        "PYTHONDONTWRITEBYTECODE=1",
        "-v",
        f"{workspace}:/workspace:ro",
        "-v",
        f"{result_dir}:/result:rw",
        "-w",
        "/tmp",
        PYTHON_IMAGE,
        "python3",
        "-B",
        "-u",
        "/workspace/runner.py",
    ]


def cleanup_container(container_name: str) -> None:
    try:
        subprocess.run(
            [DOCKER_BIN, "rm", "-f", container_name],
            capture_output=True,
            text=True,
            check=False,
            timeout=10,
        )
    except Exception:
        pass


def execute_test_case(
    source_code: str,
    stdin_text: str,
    time_limit_sec: float,
    memory_limit_mb: int,
    should_cancel: Optional[Callable[[], bool]] = None,
) -> Dict[str, Any]:
    memory_limit_mb = normalize_memory_limit_mb(memory_limit_mb)
    if memory_limit_mb < MIN_DOCKER_MEMORY_LIMIT_MB:
        return {
            "status": "memory_limit",
            "actual_stdout": "",
            "stderr_text": (
                f"Memory limit exceeded: {memory_limit_mb} MB is below the worker runtime minimum "
                f"of {MIN_DOCKER_MEMORY_LIMIT_MB} MB. Please set at least 16 MB for Python problems."
            ),
            "execution_time_ms": 0,
            "memory_used_kb": 0,
        }

    WORKSPACE_ROOT.mkdir(parents=True, exist_ok=True)
    os.chmod(WORKSPACE_ROOT, 0o755)

    with tempfile.TemporaryDirectory(prefix="grader_worker_", dir=str(WORKSPACE_ROOT)) as temp_dir:
        workspace = Path(temp_dir)
        result_dir = workspace / "result"
        main_file = workspace / "main.py"
        runner_file = workspace / "runner.py"
        metrics_file = result_dir / "metrics.json"
        container_name = f"grader-runner-{uuid.uuid4().hex[:12]}"

        os.chmod(workspace, 0o755)
        result_dir.mkdir(parents=True, exist_ok=True)
        os.chmod(result_dir, 0o777)
        main_file.write_text(source_code, encoding="utf-8")
        os.chmod(main_file, 0o644)
        runner_file.write_text(
            "\n".join(
                [
                    "import json",
                    "import os",
                    "import runpy",
                    "import signal",
                    "import sys",
                    "import time",
                    "",
                    "_started_at = time.perf_counter()",
                    "",
                    "def _write_metrics(status):",
                    "    try:",
                    "        elapsed_ms = int((time.perf_counter() - _started_at) * 1000)",
                    "        with open('/result/metrics.json', 'w', encoding='utf-8') as metrics_file:",
                    "            json.dump({'status': status, 'execution_time_ms': elapsed_ms}, metrics_file)",
                    "    except Exception:",
                    "        pass",
                    "",
                    "def _timeout(_signum, _frame):",
                    "    _write_metrics('timeout')",
                    "    sys.stderr.write('Execution timed out\\n')",
                    "    sys.stderr.flush()",
                    "    os._exit(124)",
                    "",
                    "signal.signal(signal.SIGALRM, _timeout)",
                    f"signal.setitimer(signal.ITIMER_REAL, {normalize_time_limit_seconds(time_limit_sec)!r})",
                    "try:",
                    "    runpy.run_path('/workspace/main.py', run_name='__main__')",
                    "    _write_metrics('completed')",
                    "finally:",
                    "    signal.setitimer(signal.ITIMER_REAL, 0)",
                    "",
                ]
            ),
            encoding="utf-8",
        )
        os.chmod(runner_file, 0o644)

        command = docker_command(workspace, result_dir, memory_limit_mb, container_name)
        start = time.perf_counter()
        try:
            process = subprocess.Popen(
                command,
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
            )
            assert process.stdin is not None
            process.stdin.write(stdin_text or "")
            process.stdin.close()
            process.stdin = None

            timeout_at = start + normalize_time_limit_seconds(time_limit_sec) + STARTUP_GRACE_SECONDS
            last_cancel_check = 0.0

            while True:
                return_code = process.poll()
                if return_code is not None:
                    stdout_text, stderr_text = process.communicate(timeout=2)
                    elapsed_ms = int((time.perf_counter() - start) * 1000)
                    if metrics_file.exists():
                        try:
                            metrics = json.loads(metrics_file.read_text(encoding="utf-8"))
                            elapsed_ms = int(metrics.get("execution_time_ms", elapsed_ms))
                        except Exception:
                            pass
                    stdout = truncate_text(stdout_text or "")
                    stderr = truncate_text(stderr_text or "")
                    if return_code == 124 and "Execution timed out" in stderr:
                        status = "timeout"
                    elif return_code in {137, -9} or "minimum memory limit allowed" in stderr.lower():
                        status = "memory_limit"
                        if stderr == "":
                            stderr = f"Memory limit exceeded ({memory_limit_mb} MB)"
                        elif "minimum memory limit allowed" in stderr.lower():
                            stderr = (
                                f"Memory limit exceeded: {memory_limit_mb} MB is below the worker runtime minimum "
                                f"of {MIN_DOCKER_MEMORY_LIMIT_MB} MB. Please set at least 16 MB for Python problems."
                            )
                    else:
                        status = "pass" if return_code == 0 else "runtime_error"
                    return {
                        "status": status,
                        "actual_stdout": stdout,
                        "stderr_text": stderr,
                        "execution_time_ms": elapsed_ms,
                        "memory_used_kb": 0,
                    }

                now = time.perf_counter()
                if should_cancel is not None and (last_cancel_check == 0.0 or now - last_cancel_check >= 0.75):
                    last_cancel_check = now
                    if should_cancel():
                        process.terminate()
                        try:
                            stdout_text, stderr_text = process.communicate(timeout=2)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            stdout_text, stderr_text = process.communicate(timeout=2)
                        cleanup_container(container_name)
                        elapsed_ms = int((time.perf_counter() - start) * 1000)
                        stdout = truncate_text(stdout_text or "")
                        stderr = truncate_text((stderr_text or "").strip() or "Preview cancelled by user")
                        return {
                            "status": "cancelled",
                            "actual_stdout": stdout,
                            "stderr_text": stderr,
                            "execution_time_ms": elapsed_ms,
                            "memory_used_kb": 0,
                        }

                if now >= timeout_at:
                    process.terminate()
                    try:
                        stdout_text, stderr_text = process.communicate(timeout=2)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        stdout_text, stderr_text = process.communicate(timeout=2)
                    cleanup_container(container_name)
                    elapsed_ms = int((time.perf_counter() - start) * 1000)
                    stdout = truncate_text(stdout_text or "")
                    stderr = truncate_text((stderr_text or "").strip() or "Execution timed out")
                    return {
                        "status": "timeout",
                        "actual_stdout": stdout,
                        "stderr_text": stderr,
                        "execution_time_ms": elapsed_ms,
                        "memory_used_kb": 0,
                    }

                time.sleep(0.1)
        except subprocess.TimeoutExpired as exc:
            elapsed_ms = int((time.perf_counter() - start) * 1000)
            stdout = truncate_text(exc.stdout or "") if exc.stdout else ""
            stderr = truncate_text(exc.stderr or "") if exc.stderr else "Execution timed out"
            return {
                "status": "timeout",
                "actual_stdout": stdout,
                "stderr_text": stderr,
                "execution_time_ms": elapsed_ms,
                "memory_used_kb": 0,
            }
        except FileNotFoundError as exc:
            raise RuntimeError(f"Docker binary not found: {DOCKER_BIN}") from exc
        finally:
            cleanup_container(container_name)


def run_submission(submission: Dict[str, Any], test_cases: List[Dict[str, Any]]) -> Dict[str, Any]:
    source_code = str(submission.get("source_code", ""))
    expected_language = str(submission.get("language") or submission.get("problem_language") or "python").strip().lower()
    time_limit_sec = float(submission.get("time_limit_sec") or 2.0)
    memory_limit_mb = int(submission.get("memory_limit_mb") or DEFAULT_MEMORY_LIMIT_MB)

    if expected_language not in {"python", "html", "web"}:
        return {
            "submission_status": "failed",
            "job_status": "failed",
            "score": 0,
            "passed_cases": 0,
            "total_cases": len(test_cases),
            "last_error": f"Unsupported language for current worker: {expected_language}",
            "results": [],
        }

    results = []
    passed = 0
    score = 0
    hard_failure: Optional[str] = None

    try:
        for case in test_cases:
            if expected_language in {"html", "web"}:
                raw_result = execute_web_test_case(
                    source_code=source_code,
                    stdin_text=str(case.get("stdin_text", "")),
                    expected_stdout=str(case.get("expected_stdout", "")),
                )
                expected_stdout = ""
                actual_stdout_normalized = ""
            else:
                raw_result = execute_test_case(
                    source_code=source_code,
                    stdin_text=str(case.get("stdin_text", "")),
                    time_limit_sec=time_limit_sec,
                    memory_limit_mb=memory_limit_mb,
                )
                expected_stdout = normalize_output(str(case.get("expected_stdout", "")))
                actual_stdout_normalized = normalize_output(str(raw_result["actual_stdout"]))
            score_awarded = 0
            status = raw_result["status"]

            if status == "pass":
                if expected_language in {"html", "web"} or actual_stdout_normalized == expected_stdout:
                    score_awarded = int(case.get("score_weight", 0))
                    passed += 1
                else:
                    status = "fail"
            elif hard_failure is None:
                hard_failure = raw_result["stderr_text"] or status

            score += score_awarded
            results.append({
                "test_case_id": int(case["id"]),
                "status": status,
                "actual_stdout": raw_result["actual_stdout"],
                "stderr_text": raw_result["stderr_text"],
                "execution_time_ms": int(raw_result["execution_time_ms"]),
                "memory_used_kb": int(raw_result["memory_used_kb"]),
                "score_awarded": score_awarded,
            })
    except Exception as exc:
        return {
            "submission_status": "failed",
            "job_status": "failed",
            "score": 0,
            "passed_cases": 0,
            "total_cases": len(test_cases),
            "last_error": str(exc),
            "results": [],
        }

    submission_status = "completed" if hard_failure is None else "failed"
    job_status = "done" if hard_failure is None else "failed"

    return {
        "submission_status": submission_status,
        "job_status": job_status,
        "score": score,
        "passed_cases": passed,
        "total_cases": len(test_cases),
        "last_error": hard_failure or "",
        "results": results,
    }


def report_result(job: Dict[str, Any], result: Dict[str, Any]) -> None:
    payload = {
        "worker_token": WORKER_TOKEN,
        "job_id": job["id"],
        "claim_token": job["claim_token"],
        **result,
    }
    if int(job.get("preview_run_id") or 0) > 0:
        payload["preview_run_id"] = int(job["preview_run_id"])
    else:
        payload["submission_id"] = int(job["submission_id"])
    post_json("worker_report.php", payload, timeout=30)


def run_preview(job: Dict[str, Any], preview_run: Dict[str, Any]) -> Dict[str, Any]:
    source_code = str(preview_run.get("source_code", ""))
    stdin_text = str(preview_run.get("stdin_text", ""))
    expected_language = str(preview_run.get("language") or preview_run.get("problem_language") or "python").strip().lower()
    time_limit_sec = float(preview_run.get("time_limit_sec") or 2.0)
    memory_limit_mb = int(preview_run.get("memory_limit_mb") or DEFAULT_MEMORY_LIMIT_MB)

    if expected_language not in {"python", "html", "web"}:
        return {
            "preview_status": "failed",
            "stdout_text": "",
            "stderr_text": "",
            "exit_code": None,
            "timed_out": False,
            "execution_time_ms": 0,
            "last_error": f"Unsupported language for current worker: {expected_language}",
        }

    if expected_language in {"html", "web"}:
        raw_result = execute_web_test_case(
            source_code=source_code,
            stdin_text=stdin_text,
            expected_stdout=str(preview_run.get("expected_stdout", "")),
        )
        return {
            "preview_status": "completed" if raw_result["status"] in {"pass", "fail"} else "failed",
            "stdout_text": raw_result["actual_stdout"],
            "stderr_text": raw_result["stderr_text"],
            "exit_code": 0 if raw_result["status"] == "pass" else 1,
            "timed_out": False,
            "execution_time_ms": int(raw_result["execution_time_ms"]),
            "last_error": "" if raw_result["status"] in {"pass", "fail"} else raw_result["stderr_text"],
        }

    preview_run_id = int(job.get("preview_run_id") or preview_run.get("id") or 0)
    claim_token = str(job.get("claim_token") or "")

    def preview_cancel_requested() -> bool:
        if preview_run_id <= 0 or claim_token == "":
            return False
        state_payload = fetch_preview_worker_state(preview_run_id, claim_token)
        return bool(state_payload.get("cancel_requested"))

    try:
        if preview_cancel_requested():
            return {
                "preview_status": "cancelled",
                "stdout_text": "",
                "stderr_text": "Preview cancelled by user before execution started",
                "exit_code": None,
                "timed_out": False,
                "execution_time_ms": 0,
                "last_error": "Preview cancelled by user before execution started",
            }

        raw_result = execute_test_case(
            source_code=source_code,
            stdin_text=stdin_text,
            time_limit_sec=time_limit_sec,
            memory_limit_mb=memory_limit_mb,
            should_cancel=preview_cancel_requested,
        )
    except Exception as exc:
        return {
            "preview_status": "failed",
            "stdout_text": "",
            "stderr_text": "",
            "exit_code": None,
            "timed_out": False,
            "execution_time_ms": 0,
            "last_error": str(exc),
        }

    if raw_result["status"] == "cancelled":
        return {
            "preview_status": "cancelled",
            "stdout_text": raw_result["actual_stdout"],
            "stderr_text": raw_result["stderr_text"],
            "exit_code": None,
            "timed_out": False,
            "execution_time_ms": int(raw_result["execution_time_ms"]),
            "last_error": "Preview cancelled by user",
        }

    exit_code = 0
    last_error = ""
    if raw_result["status"] == "timeout":
        exit_code = 124
    elif raw_result["status"] == "memory_limit":
        exit_code = 137
    elif raw_result["status"] == "runtime_error":
        exit_code = 1

    if raw_result["status"] not in ["pass", "runtime_error", "timeout", "memory_limit", "fail"]:
        last_error = str(raw_result["status"])

    return {
        "preview_status": "completed" if last_error == "" else "failed",
        "stdout_text": raw_result["actual_stdout"],
        "stderr_text": raw_result["stderr_text"],
        "exit_code": exit_code,
        "timed_out": raw_result["status"] == "timeout",
        "execution_time_ms": int(raw_result["execution_time_ms"]),
        "last_error": last_error,
    }


def main() -> None:
    signal.signal(signal.SIGTERM, request_stop)
    signal.signal(signal.SIGINT, request_stop)
    print(
        f"[grader_worker] starting worker={WORKER_NAME} "
        f"base_url={BASE_URL} runner_target={RUNNER_TARGET} image={PYTHON_IMAGE}"
    )
    while not SHOULD_STOP:
        try:
            heartbeat()
            claimed = claim_job()
            job = claimed.get("job")
            if not job:
                sleep_poll()
                continue

            job_kind = str(job.get("kind") or "submission")

            if job_kind == "preview":
                preview_run = claimed.get("preview_run", {})
                safe_report_progress(
                    job,
                    "preparing",
                    "Preparing preview environment",
                    case_index=0,
                    case_total=1,
                )
                safe_report_progress(
                    job,
                    "running_case",
                    "Running preview in sandbox",
                    case_index=1,
                    case_total=1,
                )
                result = run_preview(job, preview_run)
                safe_report_progress(
                    job,
                    "reporting",
                    "Sending preview result back to the server",
                    case_index=1,
                    case_total=1,
                )
                report_result(job, result)
                print(
                    f"[grader_worker] completed preview={job['preview_run_id']} "
                    f"job={job['id']} status={result['preview_status']} exit_code={result['exit_code']}"
                )
                continue

            submission = claimed.get("submission", {})
            test_cases = claimed.get("test_cases", [])
            total_cases = len(test_cases)
            safe_report_progress(
                job,
                "preparing",
                f"Preparing grading environment for {total_cases} test case(s)",
                case_index=0,
                case_total=total_cases,
            )

            source_code = str(submission.get("source_code", ""))
            expected_language = str(submission.get("language") or submission.get("problem_language") or "python").strip().lower()
            time_limit_sec = float(submission.get("time_limit_sec") or 2.0)
            memory_limit_mb = int(submission.get("memory_limit_mb") or DEFAULT_MEMORY_LIMIT_MB)

            if expected_language not in {"python", "html", "web"}:
                result = {
                    "submission_status": "failed",
                    "job_status": "failed",
                    "score": 0,
                    "passed_cases": 0,
                    "total_cases": total_cases,
                    "last_error": f"Unsupported language for current worker: {expected_language}",
                    "results": [],
                }
            else:
                results = []
                passed = 0
                score = 0
                hard_failure: Optional[str] = None

                try:
                    for index, case in enumerate(test_cases, start=1):
                        safe_report_progress(
                            job,
                            "running_case",
                            f"Running test case {index}/{total_cases}",
                            case_index=index,
                            case_total=total_cases,
                        )
                        if expected_language in {"html", "web"}:
                            raw_result = execute_web_test_case(
                                source_code=source_code,
                                stdin_text=str(case.get("stdin_text", "")),
                                expected_stdout=str(case.get("expected_stdout", "")),
                            )
                            expected_stdout = ""
                            actual_stdout_normalized = ""
                        else:
                            raw_result = execute_test_case(
                                source_code=source_code,
                                stdin_text=str(case.get("stdin_text", "")),
                                time_limit_sec=time_limit_sec,
                                memory_limit_mb=memory_limit_mb,
                            )
                            expected_stdout = normalize_output(str(case.get("expected_stdout", "")))
                            actual_stdout_normalized = normalize_output(str(raw_result["actual_stdout"]))
                        score_awarded = 0
                        status = raw_result["status"]

                        if status == "pass":
                            if expected_language in {"html", "web"} or actual_stdout_normalized == expected_stdout:
                                score_awarded = int(case.get("score_weight", 0))
                                passed += 1
                            else:
                                status = "fail"
                        elif hard_failure is None:
                            hard_failure = raw_result["stderr_text"] or status

                        score += score_awarded
                        results.append({
                            "test_case_id": int(case["id"]),
                            "status": status,
                            "actual_stdout": raw_result["actual_stdout"],
                            "stderr_text": raw_result["stderr_text"],
                            "execution_time_ms": int(raw_result["execution_time_ms"]),
                            "memory_used_kb": int(raw_result["memory_used_kb"]),
                            "score_awarded": score_awarded,
                        })
                except Exception as exc:
                    result = {
                        "submission_status": "failed",
                        "job_status": "failed",
                        "score": 0,
                        "passed_cases": 0,
                        "total_cases": total_cases,
                        "last_error": str(exc),
                        "results": [],
                    }
                else:
                    submission_status = "completed" if hard_failure is None else "failed"
                    job_status = "done" if hard_failure is None else "failed"
                    result = {
                        "submission_status": submission_status,
                        "job_status": job_status,
                        "score": score,
                        "passed_cases": passed,
                        "total_cases": total_cases,
                        "last_error": hard_failure or "",
                        "results": results,
                    }

            safe_report_progress(
                job,
                "reporting",
                "Sending grading results back to the server",
                case_index=result.get("total_cases", 0),
                case_total=result.get("total_cases", 0),
            )
            report_result(job, result)
            print(
                f"[grader_worker] completed submission={job['submission_id']} "
                f"job={job['id']} status={result['submission_status']} score={result['score']}"
            )
        except Exception as exc:
            print(f"[grader_worker] error: {exc}")
            sleep_poll()

    print(f"[grader_worker] stopping worker={WORKER_NAME}")


if __name__ == "__main__":
    main()
