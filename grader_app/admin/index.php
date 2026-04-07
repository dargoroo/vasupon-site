<?php

require_once __DIR__ . '/auth.php';

$state = graderapp_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && $pdo;
$error_message = $state['error'] ?? '';
$flash = graderapp_admin_consume_flash();
$login_error = '';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    graderapp_admin_logout();
    header('Location: ' . graderapp_path('grader.admin'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'login') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (graderapp_admin_login($username, $password, $pdo)) {
        graderapp_admin_flash('success', 'เข้าสู่ระบบ Grader Admin เรียบร้อยแล้ว');
        header('Location: ' . graderapp_path('grader.admin'));
        exit;
    }
    $login_error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
}

$summary = [
    'courses' => 0,
    'modules' => 0,
    'problems' => 0,
    'submissions' => 0,
    'queued_jobs' => 0,
    'stale_jobs' => 0,
    'active_workers' => 0,
];
$recentSubmissions = [];
$recentJobs = [];
$recentProblems = [];

if ($db_ready && graderapp_admin_is_authenticated()) {
    try {
        $summary['courses'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_courses")->fetchColumn();
        $summary['modules'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_modules")->fetchColumn();
        $summary['problems'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_problems")->fetchColumn();
        $summary['submissions'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_submissions")->fetchColumn();
        $summary['queued_jobs'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_jobs WHERE job_status IN ('queued', 'claimed', 'running')")->fetchColumn();
        $summary['stale_jobs'] = graderapp_count_stale_jobs($pdo);
        $summary['active_workers'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_workers WHERE is_active = 1")->fetchColumn();

        $recentSubmissions = $pdo->query("
            SELECT s.id, s.status, s.score, s.submitted_at, u.full_name, p.title
            FROM grader_submissions s
            LEFT JOIN grader_users u ON u.id = s.user_id
            LEFT JOIN grader_problems p ON p.id = s.problem_id
            ORDER BY s.id DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        $recentJobs = $pdo->query("
            SELECT j.id, j.job_status, j.runner_target, j.claimed_by_worker, j.queued_at
            FROM grader_jobs j
            ORDER BY j.id DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        $recentProblems = $pdo->query("
            SELECT p.title, p.language, p.visibility, m.title AS module_title, c.course_code
            FROM grader_problems p
            LEFT JOIN grader_modules m ON m.id = p.module_id
            LEFT JOIN grader_courses c ON c.id = m.course_id
            ORDER BY p.id DESC
            LIMIT 6
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $db_ready = false;
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grader Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            font-family: "Sarabun", system-ui, sans-serif;
            background: linear-gradient(180deg, #eef3fb 0%, #fafcff 100%);
            color: #16263a;
        }
        .hero-card,
        .panel-card {
            border: 0;
            border-radius: 28px;
            box-shadow: 0 18px 46px rgba(17, 39, 67, 0.08);
        }
        .hero-card {
            background: linear-gradient(135deg, #0d2e57 0%, #204f88 100%);
            color: #fff;
        }
        .metric-card {
            border-radius: 24px;
            padding: 1rem 1.1rem;
            background: #fff;
            height: 100%;
        }
        .metric-number {
            font-size: 2rem;
            font-weight: 800;
        }
    </style>
</head>
<body>
    <div class="container py-4 py-lg-5">
        <div class="hero-card p-4 p-lg-5 mb-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                <div>
                    <div class="text-uppercase fw-bold small opacity-75 mb-2">Grader App Admin</div>
                    <h1 class="fw-bold mb-2">หลังบ้านสำหรับตรวจระบบ grader ใหม่</h1>
                    <div class="opacity-75">ใช้หน้านี้ตรวจ schema, queue, worker readiness และต่อยอดไปยัง CRUD รายวิชา/โจทย์ในรอบถัดไป</div>
                </div>
                <div class="d-flex gap-2 align-self-start">
                    <a href="<?= htmlspecialchars(graderapp_path('grader.home')) ?>" class="btn btn-light rounded-pill px-4 fw-bold">
                        <i class="bi bi-house-door-fill"></i> หน้า public
                    </a>
                    <?php if ($db_ready && graderapp_admin_is_authenticated()): ?>
                        <a href="<?= htmlspecialchars(graderapp_path('grader.admin.logout')) ?>" class="btn btn-outline-light rounded-pill px-4 fw-bold">
                            <i class="bi bi-box-arrow-right"></i> ออกจากระบบ
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> rounded-4"><?= htmlspecialchars($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$db_ready): ?>
            <div class="alert alert-danger rounded-4 shadow-sm">
                <div class="fw-bold mb-1">Grader Admin ยังเชื่อมฐานข้อมูลไม่ได้</div>
                <?php if ($error_message !== ''): ?>
                    <div><code><?= htmlspecialchars($error_message) ?></code></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($db_ready && !graderapp_admin_is_authenticated()): ?>
            <div class="row justify-content-center">
                <div class="col-lg-5">
                    <div class="panel-card p-4 bg-white">
                        <h2 class="fw-bold mb-3">เข้าสู่ระบบ</h2>
                        <div class="text-secondary mb-3">ใช้บัญชีผู้ดูแลของ `grader_app` ตามที่กำหนดใน `config.php`</div>
                        <?php if ($login_error !== ''): ?>
                            <div class="alert alert-danger rounded-4"><?= htmlspecialchars($login_error) ?></div>
                        <?php endif; ?>
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="action" value="login">
                            <div class="col-12">
                                <label class="form-label fw-bold">Username</label>
                                <input type="text" name="username" class="form-control form-control-lg" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Password</label>
                                <input type="password" name="password" class="form-control form-control-lg" required>
                            </div>
                            <div class="col-12 d-grid">
                                <button type="submit" class="btn btn-dark btn-lg rounded-pill fw-bold">
                                    <i class="bi bi-shield-lock-fill"></i> เข้าสู่ระบบหลังบ้าน
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php elseif ($db_ready): ?>
            <div class="row g-3 mb-4">
                <div class="col-lg-4">
                    <a href="<?= htmlspecialchars(graderapp_path('grader.admin.courses')) ?>" class="text-decoration-none">
                        <div class="panel-card p-4 bg-white h-100">
                            <div class="d-flex align-items-center gap-3">
                                <div class="fs-2 text-primary"><i class="bi bi-journal-bookmark-fill"></i></div>
                                <div>
                                    <div class="fw-bold">จัดการรายวิชา</div>
                                    <div class="small text-secondary">สร้างรายวิชา กำหนดภาคเรียน และ owner</div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-lg-4">
                    <a href="<?= htmlspecialchars(graderapp_path('grader.admin.modules')) ?>" class="text-decoration-none">
                        <div class="panel-card p-4 bg-white h-100">
                            <div class="d-flex align-items-center gap-3">
                                <div class="fs-2 text-primary"><i class="bi bi-collection-fill"></i></div>
                                <div>
                                    <div class="fw-bold">จัดการโมดูล</div>
                                    <div class="small text-secondary">แยกบทเรียนหรือหัวข้อก่อนสร้างโจทย์</div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-lg-4">
                    <a href="<?= htmlspecialchars(graderapp_path('grader.admin.problems')) ?>" class="text-decoration-none">
                        <div class="panel-card p-4 bg-white h-100">
                            <div class="d-flex align-items-center gap-3">
                                <div class="fs-2 text-primary"><i class="bi bi-code-slash"></i></div>
                                <div>
                                    <div class="fw-bold">จัดการโจทย์</div>
                                    <div class="small text-secondary">ตั้งค่าเวลา หน่วยความจำ starter code และสถานะเผยแพร่</div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-6 col-xl-2">
                    <div class="metric-card"><div class="text-secondary small fw-bold mb-1">Courses</div><div class="metric-number"><?= number_format($summary['courses']) ?></div></div>
                </div>
                <div class="col-md-6 col-xl-2">
                    <div class="metric-card"><div class="text-secondary small fw-bold mb-1">Modules</div><div class="metric-number"><?= number_format($summary['modules']) ?></div></div>
                </div>
                <div class="col-md-6 col-xl-2">
                    <div class="metric-card"><div class="text-secondary small fw-bold mb-1">Problems</div><div class="metric-number"><?= number_format($summary['problems']) ?></div></div>
                </div>
                <div class="col-md-6 col-xl-2">
                    <div class="metric-card"><div class="text-secondary small fw-bold mb-1">Submissions</div><div class="metric-number"><?= number_format($summary['submissions']) ?></div></div>
                </div>
                <div class="col-md-6 col-xl-2">
                    <div class="metric-card"><div class="text-secondary small fw-bold mb-1">Queue</div><div class="metric-number"><?= number_format($summary['queued_jobs']) ?></div></div>
                </div>
                <div class="col-md-6 col-xl-2">
                    <div class="metric-card"><div class="text-secondary small fw-bold mb-1">Stale Jobs</div><div class="metric-number"><?= number_format($summary['stale_jobs']) ?></div></div>
                </div>
                <div class="col-md-6 col-xl-2">
                    <div class="metric-card"><div class="text-secondary small fw-bold mb-1">Workers</div><div class="metric-number"><?= number_format($summary['active_workers']) ?></div></div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="panel-card p-4 bg-white h-100">
                        <h3 class="fw-bold mb-3">Submission ล่าสุด</h3>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>โจทย์</th>
                                        <th>ผู้ส่ง</th>
                                        <th>สถานะ</th>
                                        <th>คะแนน</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($recentSubmissions): ?>
                                    <?php foreach ($recentSubmissions as $row): ?>
                                        <tr>
                                            <td><?= (int) $row['id'] ?></td>
                                            <td><?= htmlspecialchars((string) ($row['title'] ?: '-')) ?></td>
                                            <td><?= htmlspecialchars((string) ($row['full_name'] ?: '-')) ?></td>
                                            <td><?= htmlspecialchars((string) $row['status']) ?></td>
                                            <td><?= (int) $row['score'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-secondary">ยังไม่มี submission</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="panel-card p-4 bg-white h-100">
                        <h3 class="fw-bold mb-3">Queue และ worker</h3>
                        <div class="small text-secondary mb-3">งานที่ค้างในสถานะ `claimed/running` เกิน <?= htmlspecialchars((string) graderapp_stale_job_seconds($pdo)) ?> วินาที จะถูก requeue อัตโนมัติเมื่อ worker ขอ claim งานใหม่</div>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Job</th>
                                        <th>สถานะ</th>
                                        <th>Runner</th>
                                        <th>Worker</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($recentJobs): ?>
                                    <?php foreach ($recentJobs as $job): ?>
                                        <tr>
                                            <td><?= (int) $job['id'] ?></td>
                                            <td><?= htmlspecialchars((string) $job['job_status']) ?></td>
                                            <td><?= htmlspecialchars((string) $job['runner_target']) ?></td>
                                            <td><?= htmlspecialchars((string) ($job['claimed_by_worker'] ?: '-')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-secondary">ยังไม่มีงานใน queue</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="panel-card p-4 bg-white">
                        <h3 class="fw-bold mb-3">โจทย์ที่มีในระบบ</h3>
                        <div class="row g-3">
                            <?php if ($recentProblems): ?>
                                <?php foreach ($recentProblems as $problem): ?>
                                    <div class="col-lg-4">
                                        <div class="border rounded-4 p-3 h-100">
                                            <div class="fw-bold mb-1"><?= htmlspecialchars((string) $problem['title']) ?></div>
                                            <div class="small text-secondary mb-2"><?= htmlspecialchars((string) (($problem['course_code'] ?: 'Demo Course') . ' • ' . ($problem['module_title'] ?: 'Module'))) ?></div>
                                            <div class="d-flex gap-2 flex-wrap">
                                                <span class="badge text-bg-light"><?= htmlspecialchars((string) $problem['language']) ?></span>
                                                <span class="badge text-bg-light"><?= htmlspecialchars((string) $problem['visibility']) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12 text-secondary">ยังไม่มีโจทย์ในระบบ</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
