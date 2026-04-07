<?php

require_once __DIR__ . '/bootstrap.php';

$state = graderapp_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && $pdo;
$error_message = $state['error'] ?? '';

$title = 'CPE Grader';
$tagline = 'ระบบตรวจแบบฝึกหัดเขียนโปรแกรมสำหรับนักศึกษาและอาจารย์';
$stats = [
    'courses' => 0,
    'problems' => 0,
    'submissions' => 0,
    'queued_jobs' => 0,
];
$recentProblems = [];
$recentCourses = [];

if ($db_ready) {
    try {
        $title = (string) graderapp_setting_get($pdo, 'grader_title', $title);
        $tagline = (string) graderapp_setting_get($pdo, 'grader_tagline', $tagline);

        $stats['courses'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_courses")->fetchColumn();
        $stats['problems'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_problems")->fetchColumn();
        $stats['submissions'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_submissions")->fetchColumn();
        $stats['queued_jobs'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_jobs WHERE job_status IN ('queued', 'claimed', 'running')")->fetchColumn();

        $recentCourses = $pdo->query("
            SELECT course_code, course_name, academic_year, semester, status
            FROM grader_courses
            ORDER BY id DESC
            LIMIT 3
        ")->fetchAll(PDO::FETCH_ASSOC);

        $recentProblems = $pdo->query("
            SELECT p.title, p.language, p.visibility, c.course_code
            FROM grader_problems p
            LEFT JOIN grader_modules m ON m.id = p.module_id
            LEFT JOIN grader_courses c ON c.id = m.course_id
            ORDER BY p.id DESC
            LIMIT 4
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
    <title><?= htmlspecialchars($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            font-family: "Sarabun", system-ui, sans-serif;
            background: linear-gradient(180deg, #f4f7fb 0%, #fcfdff 100%);
            color: #1f2d3d;
        }
        .hero {
            padding: 3rem 0 2rem;
        }
        .hero-card,
        .panel-card {
            border: 0;
            border-radius: 28px;
            box-shadow: 0 18px 50px rgba(18, 41, 74, 0.08);
        }
        .hero-card {
            background: linear-gradient(135deg, #173b6d 0%, #28569b 100%);
            color: #fff;
        }
        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            border-radius: 999px;
            padding: .45rem .85rem;
            background: rgba(255,255,255,.12);
            font-weight: 700;
            font-size: .95rem;
        }
        .metric-card {
            border-radius: 24px;
            padding: 1.15rem 1.25rem;
            background: #fff;
            height: 100%;
        }
        .metric-number {
            font-size: 2.1rem;
            font-weight: 800;
            line-height: 1;
        }
        .list-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: .3rem .7rem;
            background: #edf3fb;
            color: #23436f;
            font-weight: 700;
            font-size: .9rem;
        }
        .mini-card {
            border: 1px solid #e5ebf5;
            border-radius: 20px;
            padding: 1rem 1.1rem;
            background: #fff;
        }
    </style>
</head>
<body>
    <div class="container py-4 py-lg-5">
        <section class="hero">
            <div class="hero-card p-4 p-lg-5">
                <div class="row g-4 align-items-center">
                    <div class="col-lg-8">
                        <div class="meta-pill mb-3"><i class="bi bi-code-square"></i> Grader App Scaffold</div>
                        <h1 class="display-4 fw-bold mb-3"><?= htmlspecialchars($title) ?></h1>
                        <p class="lead mb-4"><?= htmlspecialchars($tagline) ?></p>
                        <div class="d-flex flex-wrap gap-3">
                            <a href="<?= htmlspecialchars(graderapp_path('grader.admin')) ?>" class="btn btn-light btn-lg rounded-pill px-4 fw-bold">
                                <i class="bi bi-gear-fill"></i> เปิดหลังบ้าน
                            </a>
                            <span class="meta-pill"><i class="bi bi-diagram-3-fill"></i> Web + API + Worker Ready</span>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="mini-card text-dark">
                            <div class="fw-bold mb-2">Deployment Strategy</div>
                            <div class="small text-secondary mb-3">หน้าเว็บและฐานข้อมูลอยู่ฝั่ง `vasupon-p` ส่วน worker/docker runner ย้ายไป `rbruai2` หรือเครื่องใหม่ในอนาคตได้โดยไม่ต้องรื้อ schema หลัก</div>
                            <div class="d-grid gap-2">
                                <div class="list-chip"><i class="bi bi-hdd-network"></i> DB queue based</div>
                                <div class="list-chip"><i class="bi bi-boxes"></i> Worker stateless</div>
                                <div class="list-chip"><i class="bi bi-arrow-repeat"></i> Failover friendly</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <?php if (!$db_ready): ?>
            <div class="alert alert-danger rounded-4 shadow-sm">
                <div class="fw-bold mb-1">Grader App ยังเชื่อมฐานข้อมูลไม่ได้</div>
                <div>ระบบจะสร้างตาราง `grader_*` ให้อัตโนมัติเมื่อเชื่อมฐานข้อมูลได้สำเร็จ</div>
                <?php if ($error_message !== ''): ?>
                    <div class="mt-2"><code><?= htmlspecialchars($error_message) ?></code></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="metric-card hero-card">
                    <div class="text-uppercase small fw-bold opacity-75 mb-2">รายวิชา</div>
                    <div class="metric-number"><?= number_format($stats['courses']) ?></div>
                    <div class="small opacity-75">Course containers พร้อมใช้</div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="metric-card">
                    <div class="text-uppercase small fw-bold text-secondary mb-2">โจทย์</div>
                    <div class="metric-number"><?= number_format($stats['problems']) ?></div>
                    <div class="small text-secondary">โจทย์ฝึกเขียนโปรแกรมในระบบ</div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="metric-card">
                    <div class="text-uppercase small fw-bold text-secondary mb-2">Submission</div>
                    <div class="metric-number"><?= number_format($stats['submissions']) ?></div>
                    <div class="small text-secondary">ประวัติการส่งตรวจทั้งหมด</div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="metric-card">
                    <div class="text-uppercase small fw-bold text-secondary mb-2">Queue</div>
                    <div class="metric-number"><?= number_format($stats['queued_jobs']) ?></div>
                    <div class="small text-secondary">งานที่รอ worker ประมวลผล</div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="panel-card p-4 h-100 bg-white">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h4 fw-bold mb-0">โครงสร้างพร้อมต่อยอด</h2>
                        <span class="list-chip">PHP Schema Installer</span>
                    </div>
                    <div class="small text-secondary mb-3">App นี้ออกแบบให้ขึ้น server ได้โดยไม่ต้อง import ผ่าน phpMyAdmin เป็น flow หลักอีกแล้ว</div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item px-0 py-3 d-flex gap-3">
                            <i class="bi bi-database-check fs-4 text-primary"></i>
                            <div>
                                <div class="fw-bold">สร้างตารางอัตโนมัติ</div>
                                <div class="text-secondary small">ใช้ `graderapp_ensure_schema()` ตรวจและสร้าง `grader_*` ตั้งแต่หน้าแรก</div>
                            </div>
                        </li>
                        <li class="list-group-item px-0 py-3 d-flex gap-3">
                            <i class="bi bi-robot fs-4 text-primary"></i>
                            <div>
                                <div class="fw-bold">รองรับ worker แยกเครื่อง</div>
                                <div class="text-secondary small">เปลี่ยน runner host ในอนาคตได้โดยไม่ต้องย้ายหน้าเว็บหรือฐานข้อมูลหลัก</div>
                            </div>
                        </li>
                        <li class="list-group-item px-0 py-3 d-flex gap-3">
                            <i class="bi bi-shield-lock fs-4 text-primary"></i>
                            <div>
                                <div class="fw-bold">พร้อมสำหรับ portal login</div>
                                <div class="text-secondary small">โครงตาราง `grader_users` เผื่อ `portal_user_id` สำหรับเชื่อมกับ `cpe_portal` ในรอบถัดไป</div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="panel-card p-4 h-100 bg-white">
                    <h2 class="h4 fw-bold mb-3">ข้อมูลตั้งต้นใน scaffold</h2>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="mini-card">
                                <div class="fw-bold mb-2">รายวิชาตัวอย่าง</div>
                                <?php if ($recentCourses): ?>
                                    <?php foreach ($recentCourses as $course): ?>
                                        <div class="d-flex justify-content-between gap-3 small py-1">
                                            <span><?= htmlspecialchars($course['course_code'] . ' ' . $course['course_name']) ?></span>
                                            <span class="text-secondary"><?= htmlspecialchars($course['academic_year'] . '/' . $course['semester']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-secondary small">ยังไม่มีรายวิชาในระบบ</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mini-card">
                                <div class="fw-bold mb-2">โจทย์ตัวอย่างล่าสุด</div>
                                <?php if ($recentProblems): ?>
                                    <?php foreach ($recentProblems as $problem): ?>
                                        <div class="d-flex justify-content-between gap-3 small py-1">
                                            <span><?= htmlspecialchars($problem['title']) ?></span>
                                            <span class="text-secondary"><?= htmlspecialchars(($problem['course_code'] ?: 'Demo') . ' • ' . $problem['language']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-secondary small">ยังไม่มีโจทย์ในระบบ</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
