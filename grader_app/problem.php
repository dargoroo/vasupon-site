<?php

require_once __DIR__ . '/bootstrap.php';

$state = graderapp_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && $pdo;
$error_message = $state['error'] ?? '';
$problemId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$demo = null;

if ($db_ready && $problemId > 0) {
    try {
        $demo = graderapp_demo_context($pdo, $problemId);
    } catch (Throwable $e) {
        $db_ready = false;
        $error_message = $e->getMessage();
    }
}

$problem = $demo['problem'] ?? null;
$demoUser = $demo['demo_user'] ?? null;
$sampleCases = $demo['sample_cases'] ?? [];
$title = $problem ? ((string) $problem['title'] . ' | Demo') : 'Problem Demo';
$starterCode = $problem['starter_code'] ?? "print('Hello, world!')\n";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            font-family: "Sarabun", system-ui, sans-serif;
            background:
                radial-gradient(circle at top right, rgba(26, 92, 164, 0.08), transparent 28%),
                linear-gradient(180deg, #f7f9fc 0%, #ffffff 100%);
            color: #18283b;
        }
        .shell {
            max-width: 1240px;
        }
        .surface-card,
        .hero-card {
            border: 0;
            border-radius: 28px;
            background: rgba(255,255,255,0.98);
            box-shadow: 0 18px 46px rgba(17, 39, 67, 0.08);
        }
        .hero-card {
            padding: 1.5rem;
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            border-radius: 999px;
            padding: .32rem .78rem;
            background: #eaf1fb;
            color: #24456e;
            font-weight: 700;
            font-size: .9rem;
        }
        .meta-chip {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            border-radius: 999px;
            padding: .35rem .72rem;
            background: #eef4fb;
            color: #23436f;
            font-weight: 700;
            font-size: .88rem;
        }
        .surface-card {
            padding: 1.35rem;
        }
        .section-title {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: .8rem;
        }
        .editor {
            width: 100%;
            min-height: 360px;
            border-radius: 22px;
            border: 1px solid #d9e4f2;
            background: #f8fbff;
            padding: 1rem;
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: .97rem;
            line-height: 1.55;
            resize: vertical;
        }
        .sample-card {
            border: 1px solid #e3eaf4;
            border-radius: 20px;
            padding: 1rem;
            background: #fff;
            height: 100%;
        }
        .sample-box {
            border-radius: 16px;
            background: #f6f9fd;
            padding: .85rem;
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: .9rem;
            white-space: pre-wrap;
            color: #214160;
        }
        .result-card {
            border-radius: 22px;
            border: 1px solid #dde7f4;
            background: #fbfdff;
            padding: 1rem;
        }
        .result-pass {
            color: #1d7a4a;
        }
        .result-fail {
            color: #b14a5d;
        }
        .result-pending {
            color: #7b5f18;
        }
        .empty-state {
            border: 1px dashed #d6e0ed;
            border-radius: 22px;
            padding: 1.4rem;
            color: #73839a;
            background: #fcfdff;
        }
    </style>
</head>
<body>
    <div class="container shell py-4 py-lg-5">
        <div class="hero-card mb-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                <div>
                    <a href="<?= htmlspecialchars(graderapp_path('grader.home')) ?>" class="text-decoration-none text-secondary small fw-bold">
                        <i class="bi bi-arrow-left"></i> กลับไปหน้าแรก
                    </a>
                    <div class="eyebrow mt-3 mb-3"><i class="bi bi-play-circle-fill"></i> Demo Student View</div>
                    <h1 class="fw-bold mb-2"><?= htmlspecialchars($problem['title'] ?? 'Problem Demo') ?></h1>
                    <?php if ($problem): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="meta-chip"><i class="bi bi-journal-text"></i> <?= htmlspecialchars((string) $problem['course_code']) ?></span>
                            <span class="meta-chip"><i class="bi bi-collection"></i> <?= htmlspecialchars((string) $problem['module_title']) ?></span>
                            <span class="meta-chip"><i class="bi bi-code"></i> <?= htmlspecialchars((string) $problem['language']) ?></span>
                            <span class="meta-chip"><i class="bi bi-stopwatch"></i> <?= htmlspecialchars((string) $problem['time_limit_sec']) ?>s</span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($demoUser): ?>
                    <div class="text-secondary small">
                        กำลังทดลองด้วยบัญชีตัวอย่าง:
                        <span class="fw-bold text-dark"><?= htmlspecialchars((string) $demoUser['full_name']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$db_ready): ?>
            <div class="alert alert-danger rounded-4 shadow-sm">
                <div class="fw-bold mb-1">ระบบยังเชื่อมฐานข้อมูลไม่ได้</div>
                <?php if ($error_message !== ''): ?>
                    <div><code><?= htmlspecialchars($error_message) ?></code></div>
                <?php endif; ?>
            </div>
        <?php elseif (!$problem || !$demoUser): ?>
            <div class="empty-state">ยังไม่พบโจทย์ demo ที่พร้อมใช้งาน หรือยังไม่มีผู้ใช้ตัวอย่างสำหรับทดสอบในระบบ</div>
        <?php else: ?>
            <div class="row g-4">
                <div class="col-xl-5">
                    <div class="surface-card h-100">
                        <div class="section-title">รายละเอียดโจทย์</div>
                        <div class="text-secondary mb-4"><?= nl2br(htmlspecialchars((string) ($problem['description_md'] ?: 'โจทย์นี้ยังไม่มีคำอธิบายเพิ่มเติม'))) ?></div>

                        <div class="section-title">ตัวอย่างข้อมูลเข้า / ออก</div>
                        <?php if ($sampleCases): ?>
                            <div class="row g-3">
                                <?php foreach ($sampleCases as $sample): ?>
                                    <div class="col-12">
                                        <div class="sample-card">
                                            <div class="small fw-bold mb-2">ตัวอย่าง <?= (int) $sample['sort_order'] ?></div>
                                            <div class="row g-2">
                                                <div class="col-md-6">
                                                    <div class="small text-secondary mb-1">Input</div>
                                                    <div class="sample-box"><?= htmlspecialchars((string) $sample['stdin_text']) ?></div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="small text-secondary mb-1">Output</div>
                                                    <div class="sample-box"><?= htmlspecialchars((string) $sample['expected_stdout']) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">ยังไม่มี sample case แสดงในระบบ</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-xl-7">
                    <div class="surface-card">
                        <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 align-items-lg-center mb-3">
                            <div>
                                <div class="section-title mb-1">ทดลองส่งคำตอบ</div>
                                <div class="text-secondary small">กดส่งตรวจเพื่อทดลอง flow แบบนักศึกษาโดยไม่ต้องล็อกอิน</div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" id="resetStarterButton">
                                <i class="bi bi-arrow-counterclockwise"></i> กลับไปใช้โค้ดเริ่มต้น
                            </button>
                        </div>

                        <textarea id="demoSourceEditor" class="editor"><?= htmlspecialchars((string) $starterCode) ?></textarea>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="button" class="btn btn-dark rounded-pill px-4 fw-bold" id="submitDemoButton">
                                <i class="bi bi-play-circle-fill"></i> ส่งตรวจแบบ Demo
                            </button>
                            <span class="small text-secondary align-self-center" id="submitHint">ระบบจะส่งงานเข้า queue และรอผลจาก worker จริง</span>
                        </div>

                        <div class="result-card mt-4">
                            <div class="d-flex justify-content-between gap-2 align-items-center mb-2">
                                <div class="section-title mb-0">ผลการตรวจล่าสุด</div>
                                <div class="small text-secondary" id="resultMeta">ยังไม่ได้ส่งตรวจ</div>
                            </div>
                            <div id="resultStatus" class="fw-bold result-pending mb-2">พร้อมทดลอง</div>
                            <div id="resultSummary" class="text-secondary mb-3">เมื่อส่งคำตอบแล้ว ผลจะปรากฏที่ส่วนนี้</div>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Case</th>
                                            <th>สถานะ</th>
                                            <th>คะแนน</th>
                                            <th>เวลา</th>
                                        </tr>
                                    </thead>
                                    <tbody id="resultTableBody">
                                        <tr><td colspan="4" class="text-secondary">ยังไม่มีข้อมูลการตรวจ</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($problem && $demoUser): ?>
        <script>
            const submitButton = document.getElementById('submitDemoButton');
            const resetButton = document.getElementById('resetStarterButton');
            const editor = document.getElementById('demoSourceEditor');
            const resultMeta = document.getElementById('resultMeta');
            const resultStatus = document.getElementById('resultStatus');
            const resultSummary = document.getElementById('resultSummary');
            const resultTableBody = document.getElementById('resultTableBody');
            const submitHint = document.getElementById('submitHint');

            const starterCode = <?= json_encode((string) $starterCode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const submitUrl = <?= json_encode(graderapp_path('grader.api.submit'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const statusBaseUrl = <?= json_encode(graderapp_path('grader.api.status'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const submitPayloadBase = {
                problem_id: <?= (int) $problem['problem_id'] ?>,
                user_id: <?= (int) $demoUser['id'] ?>,
                course_id: <?= (int) $problem['course_id'] ?>,
                language: <?= json_encode((string) $problem['language'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
            };

            let pollingTimer = null;

            function setPendingState(message) {
                resultStatus.className = 'fw-bold result-pending mb-2';
                resultStatus.textContent = message;
            }

            function renderResults(payload) {
                const submission = payload.submission || {};
                const job = payload.job || {};
                const results = Array.isArray(payload.results) ? payload.results : [];

                resultMeta.textContent = submission.id ? `Submission #${submission.id}` : 'ผลการตรวจ';
                resultSummary.textContent = `สถานะงาน: ${job.job_status || '-'} | worker: ${job.claimed_by_worker || '-'} | คะแนน: ${submission.score || 0}/${problem.max_score || 100}`;

                if (submission.status === 'completed') {
                    resultStatus.className = 'fw-bold result-pass mb-2';
                    resultStatus.textContent = `ผ่าน ${submission.passed_cases || 0}/${submission.total_cases || 0} cases`;
                } else if (submission.status === 'failed') {
                    resultStatus.className = 'fw-bold result-fail mb-2';
                    resultStatus.textContent = job.last_error ? `ตรวจไม่ผ่าน: ${job.last_error}` : 'ตรวจไม่ผ่าน';
                } else {
                    setPendingState(`กำลังตรวจ... (${submission.status || 'queued'})`);
                }

                if (!results.length) {
                    resultTableBody.innerHTML = '<tr><td colspan="4" class="text-secondary">ยังไม่มีผลราย case</td></tr>';
                    return;
                }

                resultTableBody.innerHTML = results.map((row, index) => `
                    <tr>
                        <td>${row.sort_order || index + 1}</td>
                        <td>${row.status || '-'}</td>
                        <td>${row.score_awarded || 0}</td>
                        <td>${row.execution_time_ms || 0} ms</td>
                    </tr>
                `).join('');
            }

            async function pollSubmission(submissionId) {
                if (pollingTimer) {
                    clearTimeout(pollingTimer);
                }

                try {
                    const response = await fetch(`${statusBaseUrl}?submission_id=${submissionId}`);
                    const payload = await response.json();
                    renderResults(payload);

                    const status = payload?.submission?.status || 'queued';
                    if (status === 'queued' || status === 'running') {
                        pollingTimer = window.setTimeout(() => pollSubmission(submissionId), 2000);
                    } else {
                        submitButton.disabled = false;
                        submitHint.textContent = 'ตรวจเสร็จแล้ว สามารถแก้โค้ดแล้วส่งใหม่ได้';
                    }
                } catch (error) {
                    resultStatus.className = 'fw-bold result-fail mb-2';
                    resultStatus.textContent = 'ไม่สามารถอ่านผลการตรวจได้';
                    resultSummary.textContent = error instanceof Error ? error.message : 'Unknown error';
                    submitButton.disabled = false;
                }
            }

            submitButton?.addEventListener('click', async () => {
                const sourceCode = editor.value.trim();
                if (!sourceCode) {
                    setPendingState('กรุณาใส่โค้ดก่อนส่งตรวจ');
                    return;
                }

                submitButton.disabled = true;
                submitHint.textContent = 'กำลังส่งงานเข้า queue...';
                setPendingState('กำลังส่งงาน...');
                resultSummary.textContent = 'รอ worker claim งาน';
                resultTableBody.innerHTML = '<tr><td colspan="4" class="text-secondary">กำลังเตรียมผลการตรวจ</td></tr>';

                try {
                    const payload = { ...submitPayloadBase, source_code: editor.value };
                    const response = await fetch(submitUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload),
                    });
                    const data = await response.json();
                    if (!data.ok) {
                        throw new Error(data.error || 'Submit failed');
                    }

                    resultMeta.textContent = `Submission #${data.submission_id}`;
                    submitHint.textContent = 'ส่งงานแล้ว กำลังรอผลจาก worker';
                    pollSubmission(data.submission_id);
                } catch (error) {
                    resultStatus.className = 'fw-bold result-fail mb-2';
                    resultStatus.textContent = 'ส่งงานไม่สำเร็จ';
                    resultSummary.textContent = error instanceof Error ? error.message : 'Unknown error';
                    submitButton.disabled = false;
                    submitHint.textContent = 'ลองตรวจการเชื่อมต่อแล้วส่งใหม่อีกครั้ง';
                }
            });

            resetButton?.addEventListener('click', () => {
                editor.value = starterCode;
            });
        </script>
    <?php endif; ?>
</body>
</html>
