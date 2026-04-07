#!/usr/bin/env python3
import json
import os
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any, Dict, List, Optional
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
TIMEOUT_GRACE_SECONDS = float(os.getenv("GRADERAPP_DOCKER_TIMEOUT_GRACE_SECONDS", "0.5"))
MAX_STDIO_BYTES = int(os.getenv("GRADERAPP_MAX_STDIO_BYTES", "32768"))


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
        with request.urlopen(req, timeout=timeout) as response:
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
            "languages": ["python"],
            "supportsDocker": True,
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


def normalize_output(value: str) -> str:
    return value.replace("\r\n", "\n").strip()


def truncate_text(value: str) -> str:
    if len(value) <= MAX_STDIO_BYTES:
        return value
    return value[: MAX_STDIO_BYTES - 64] + "\n...[truncated]..."


def docker_command(workspace: Path, memory_limit_mb: int) -> List[str]:
    return [
        DOCKER_BIN,
        "run",
        "--rm",
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
        "-w",
        "/tmp",
        PYTHON_IMAGE,
        "python3",
        "-B",
        "-u",
        "/workspace/main.py",
    ]


def execute_test_case(source_code: str, stdin_text: str, time_limit_sec: float, memory_limit_mb: int) -> Dict[str, Any]:
    with tempfile.TemporaryDirectory(prefix="grader_worker_") as temp_dir:
        workspace = Path(temp_dir)
        main_file = workspace / "main.py"

        main_file.write_text(source_code, encoding="utf-8")

        command = docker_command(workspace, memory_limit_mb)
        start = time.perf_counter()
        try:
            completed = subprocess.run(
                command,
                input=stdin_text or "",
                capture_output=True,
                text=True,
                timeout=max(1.0, float(time_limit_sec)) + TIMEOUT_GRACE_SECONDS,
                check=False,
            )
            elapsed_ms = int((time.perf_counter() - start) * 1000)
            stdout = truncate_text(completed.stdout or "")
            stderr = truncate_text(completed.stderr or "")

            if completed.returncode == 0:
                status = "pass"
            else:
                status = "runtime_error"

            return {
                "status": status,
                "actual_stdout": stdout,
                "stderr_text": stderr,
                "execution_time_ms": elapsed_ms,
                "memory_used_kb": 0,
            }
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


def run_submission(submission: Dict[str, Any], test_cases: List[Dict[str, Any]]) -> Dict[str, Any]:
    source_code = str(submission.get("source_code", ""))
    expected_language = str(submission.get("language") or submission.get("problem_language") or "python")
    time_limit_sec = float(submission.get("time_limit_sec") or 2.0)
    memory_limit_mb = int(submission.get("memory_limit_mb") or DEFAULT_MEMORY_LIMIT_MB)

    if expected_language != "python":
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
                if actual_stdout_normalized == expected_stdout:
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
        "submission_id": job["submission_id"],
        "claim_token": job["claim_token"],
        **result,
    }
    post_json("worker_report.php", payload, timeout=30)


def main() -> None:
    print(
        f"[grader_worker] starting worker={WORKER_NAME} "
        f"base_url={BASE_URL} runner_target={RUNNER_TARGET} image={PYTHON_IMAGE}"
    )
    while True:
        try:
            heartbeat()
            claimed = claim_job()
            job = claimed.get("job")
            if not job:
                time.sleep(POLL_SECONDS)
                continue

            submission = claimed.get("submission", {})
            test_cases = claimed.get("test_cases", [])
            result = run_submission(submission, test_cases)
            report_result(job, result)
            print(
                f"[grader_worker] completed submission={job['submission_id']} "
                f"job={job['id']} status={result['submission_status']} score={result['score']}"
            )
        except Exception as exc:
            print(f"[grader_worker] error: {exc}")
            time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    main()
