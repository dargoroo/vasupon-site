<?php

require_once __DIR__ . '/bootstrap.php';

$state = graderapp_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && $pdo;
$error_message = $state['error'] ?? '';

$title = 'CPE Grader';
$tagline = 'พื้นที่เรียนรู้และตรวจแบบฝึกหัดเขียนโปรแกรมสำหรับนักศึกษาและอาจารย์';
$stats = [
    'courses' => 0,
    'problems' => 0,
];
$recentProblems = [];
$recentCourses = [];

if ($db_ready) {
    try {
        $title = (string) graderapp_setting_get($pdo, 'grader_title', $title);
        $tagline = (string) graderapp_setting_get($pdo, 'grader_tagline', $tagline);

        $stats['courses'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_courses WHERE status = 'published'")->fetchColumn();
        $stats['problems'] = (int) $pdo->query("SELECT COUNT(*) FROM grader_problems WHERE visibility = 'published'")->fetchColumn();

        $recentCourses = $pdo->query("
            SELECT course_code, course_name, academic_year, semester
            FROM grader_courses
            WHERE status = 'published'
            ORDER BY id DESC
            LIMIT 3
        ")->fetchAll(PDO::FETCH_ASSOC);

        $recentProblems = $pdo->query("
            SELECT p.id, p.title, p.description_md, p.language, c.course_code, m.title AS module_title
            FROM grader_problems p
            LEFT JOIN grader_modules m ON m.id = p.module_id
            LEFT JOIN grader_courses c ON c.id = m.course_id
            WHERE p.visibility = 'published'
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
            background:
                radial-gradient(circle at top right, rgba(32, 79, 136, 0.10), transparent 28%),
                linear-gradient(180deg, #f7f9fc 0%, #ffffff 100%);
            color: #16263a;
        }
        .shell {
            max-width: 1180px;
        }
        .hero-card,
        .surface-card,
        .demo-card {
            border: 0;
            border-radius: 28px;
            background: rgba(255,255,255,0.96);
            box-shadow: 0 18px 46px rgba(17, 39, 67, 0.08);
        }
        .brand-mark {
            width: 2.8rem;
            height: 2.8rem;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #173b6d 0%, #2b6ab3 100%);
            color: #fff;
            font-size: 1.2rem;
        }
        .hero-card {
            padding: 2.2rem;
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .35rem .85rem;
            border-radius: 999px;
            background: #eaf1fb;
            color: #24456e;
            font-weight: 700;
            font-size: .92rem;
        }
        .hero-title {
            font-size: clamp(2.2rem, 5vw, 4.3rem);
            line-height: .98;
            letter-spacing: -.04em;
            font-weight: 800;
            margin-bottom: 1rem;
        }
        .hero-copy {
            max-width: 43rem;
            font-size: 1.08rem;
            color: #607089;
        }
        .login-card {
            border-radius: 24px;
            border: 1px solid #e2e9f4;
            padding: 1.15rem;
            background: #fff;
        }
        .login-button {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .75rem;
            width: 100%;
            border-radius: 18px;
            padding: .95rem 1rem;
            font-weight: 700;
            border: 1px solid #dce4f0;
            background: #fff;
            color: #1f2d3d;
            text-decoration: none;
        }
        .login-button.is-disabled {
            opacity: .7;
            cursor: default;
        }
        .stat-pill {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .55rem .9rem;
            border-radius: 999px;
            background: #fff;
            border: 1px solid #e3eaf4;
            color: #27466d;
            font-weight: 700;
        }
        .section-title {
            font-size: 1.45rem;
            font-weight: 800;
            margin-bottom: .35rem;
        }
        .section-copy {
            color: #72829a;
            margin-bottom: 0;
        }
        .surface-card,
        .demo-card {
            padding: 1.4rem;
        }
        .demo-card {
            height: 100%;
            border: 1px solid #e6edf7;
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .demo-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 40px rgba(18, 41, 74, 0.10);
        }
        .demo-meta {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            flex-wrap: wrap;
            color: #607089;
            font-size: .92rem;
        }
        .demo-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border-radius: 999px;
            padding: .25rem .65rem;
            background: #edf3fb;
            color: #23436f;
            font-weight: 700;
            font-size: .86rem;
        }
        .empty-card {
            border: 1px dashed #d7e0ed;
            border-radius: 22px;
            padding: 1.2rem;
            color: #75859c;
            background: #fcfdff;
        }
        .db-alert {
            border-radius: 22px;
            background: #fff4f4;
            color: #9a3d4f;
            border: 1px solid #f1d5da;
        }
        @media (max-width: 991.98px) {
            .hero-card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container shell py-4 py-lg-5">
        <section class="hero-card mb-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <div class="eyebrow mb-3"><i class="bi bi-stars"></i> Programming Exercise Platform</div>
                    <h1 class="hero-title"><?= htmlspecialchars($title) ?></h1>
                    <p class="hero-copy mb-4"><?= htmlspecialchars($tagline) ?></p>
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <span class="stat-pill"><i class="bi bi-book"></i> <?= number_format($stats['courses']) ?> วิชาเผยแพร่</span>
                        <span class="stat-pill"><i class="bi bi-code-slash"></i> <?= number_format($stats['problems']) ?> โจทย์ตัวอย่าง</span>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="login-card">
                        <div class="fw-bold fs-5 mb-2">ทางเข้าใช้งาน</div>
                        <div class="text-secondary small mb-3">สำหรับผู้ใช้ทั่วไปและสำหรับทีมผู้สอน</div>
                        <div class="d-grid gap-2 mb-3">
                            <a href="<?= htmlspecialchars(graderapp_path('grader.dashboard', ['provider' => 'google'])) ?>" class="login-button">
                                <i class="bi bi-google"></i> เข้าสู่ระบบด้วย Google
                            </a>
                            <a href="<?= htmlspecialchars(graderapp_path('grader.dashboard', ['provider' => 'github'])) ?>" class="login-button">
                                <i class="bi bi-github"></i> เข้าสู่ระบบด้วย GitHub
                            </a>
                        </div>
                        <div class="text-secondary small mb-3">ตอนนี้ปุ่มเหล่านี้พาเข้า preview ของหน้าหลังล็อกอิน เพื่อวางโครง UX ก่อนเชื่อม auth จริง</div>
                        <a href="<?= htmlspecialchars(graderapp_path('grader.dashboard', ['provider' => 'google'])) ?>" class="btn btn-outline-dark rounded-pill w-100 fw-bold">
                            <i class="bi bi-grid-1x2-fill"></i> ดูตัวอย่างหน้าหลังล็อกอิน
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <?php if (!$db_ready): ?>
            <div class="alert db-alert shadow-sm mb-4">
                <div class="fw-bold mb-1">ระบบยังเชื่อมฐานข้อมูลไม่ได้</div>
                <div class="small">เมื่อเชื่อมได้แล้ว ระบบจะสร้างตารางสำหรับ grader ให้อัตโนมัติ</div>
                <?php if ($error_message !== ''): ?>
                    <div class="small mt-2"><code><?= htmlspecialchars($error_message) ?></code></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <section id="demo" class="mb-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 align-items-lg-end mb-3">
                <div>
                    <h2 class="section-title mb-1">ทดลองเนื้อหาแบบ Demo</h2>
                    <p class="section-copy">เปิดดูโจทย์ตัวอย่างและโครงวิชาก่อนได้โดยไม่ต้องล็อกอิน</p>
                </div>
            </div>
            <?php if ($recentProblems): ?>
                <div class="row g-4">
                    <?php foreach ($recentProblems as $problem): ?>
                        <div class="col-lg-6">
                            <div class="demo-card">
                                <div class="demo-meta mb-2">
                                    <span class="demo-chip"><i class="bi bi-journal-text"></i> <?= htmlspecialchars((string) ($problem['course_code'] ?: 'Demo Course')) ?></span>
                                    <span class="demo-chip"><i class="bi bi-code"></i> <?= htmlspecialchars((string) $problem['language']) ?></span>
                                </div>
                                <div class="fw-bold fs-5 mb-2"><?= htmlspecialchars((string) $problem['title']) ?></div>
                                <div class="text-secondary small mb-3"><?= htmlspecialchars((string) ($problem['module_title'] ?: 'บทเรียนตัวอย่าง')) ?></div>
                                <div class="text-secondary mb-3">
                                    <?= htmlspecialchars(mb_strimwidth(trim(strip_tags((string) ($problem['description_md'] ?: 'โจทย์ตัวอย่างสำหรับทดลองระบบ grader แบบไม่ต้องล็อกอิน'))), 0, 220, '...')) ?>
                                </div>
                                <a href="<?= htmlspecialchars(graderapp_path('grader.problem', ['id' => (int) $problem['id']])) ?>" class="btn btn-outline-dark rounded-pill fw-bold">
                                    <i class="bi bi-box-arrow-in-right"></i> เปิดหน้า demo นี้
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-card">เมื่อผู้สอนเพิ่มโจทย์และเผยแพร่แล้ว รายการ demo จะปรากฏที่ส่วนนี้โดยอัตโนมัติ</div>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>
