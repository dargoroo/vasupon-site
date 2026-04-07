<?php

require_once __DIR__ . '/auth.php';

officefb_admin_start_session();

$login_error = '';
$password_error = '';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    officefb_admin_logout();
    header('Location: ' . officefb_path('admin.home'));
    exit;
}

$state = officefb_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok']
    && officefb_table_exists($pdo, 'officefb_staff')
    && officefb_table_exists($pdo, 'officefb_ratings');
$error_message = $state['error'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

    if (officefb_admin_login($username, $password, $pdo)) {
        header('Location: ' . officefb_path('admin.home'));
        exit;
    }

    $login_error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
}

$is_authenticated = officefb_admin_is_authenticated();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password' && $is_authenticated) {
    $current_password = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
    $new_password = isset($_POST['new_password']) ? trim((string) $_POST['new_password']) : '';
    $confirm_password = isset($_POST['confirm_password']) ? trim((string) $_POST['confirm_password']) : '';

    if (!$db_ready || !$pdo) {
        $password_error = 'ยังไม่สามารถเชื่อมฐานข้อมูลของโมดูลนี้ได้ จึงเปลี่ยนรหัสผ่านผ่านหน้าเว็บไม่ได้ในขณะนี้';
    } elseif (!officefb_admin_verify_password($current_password, $pdo)) {
        $password_error = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
    } elseif ($new_password === '') {
        $password_error = 'กรุณากรอกรหัสผ่านใหม่';
    } elseif (mb_strlen($new_password) < 6) {
        $password_error = 'รหัสผ่านใหม่ควรมีอย่างน้อย 6 ตัวอักษร';
    } elseif ($new_password !== $confirm_password) {
        $password_error = 'รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน';
    } else {
        officefb_admin_update_password($pdo, $new_password);
        officefb_admin_flash('success', 'เปลี่ยนรหัสผ่านผู้ดูแลเรียบร้อยแล้ว');
        header('Location: ' . officefb_path('admin.home'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password_override' && $is_authenticated) {
    if (!$db_ready || !$pdo) {
        officefb_admin_flash('danger', 'ยังไม่สามารถเชื่อมฐานข้อมูลของโมดูลนี้ได้ จึงรีเซ็ตรหัสผ่านไม่ได้ในขณะนี้');
    } else {
        officefb_admin_reset_password_override($pdo);
        officefb_admin_flash('warning', 'ระบบลบรหัสผ่านที่ override ไว้แล้ว และจะกลับไปใช้ค่าจาก config.php ในการเข้าสู่ระบบครั้งถัดไป');
    }

    header('Location: ' . officefb_path('admin.home'));
    exit;
}

$flash = officefb_admin_consume_flash();

$current_period = officefb_academic_period();
$report_mode = isset($_GET['report_mode']) ? (string) $_GET['report_mode'] : 'academic_year';
$allowed_modes = ['today', 'month', 'academic_year'];
if (!in_array($report_mode, $allowed_modes, true)) {
    $report_mode = 'academic_year';
}

$selected_academic_year = isset($_GET['academic_year']) ? trim((string) $_GET['academic_year']) : $current_period['academic_year'];
$selected_month = isset($_GET['selected_month']) ? trim((string) $_GET['selected_month']) : date('Y-m');
$selected_date = isset($_GET['selected_date']) ? trim((string) $_GET['selected_date']) : date('Y-m-d');
$available_academic_years = [$current_period['academic_year']];

function officefb_period_filter($mode, $alias, $selected_date, $selected_month, $selected_academic_year)
{
    if ($mode === 'today') {
        return [
            'sql' => "DATE({$alias}.submitted_at) = :selected_date",
            'params' => [':selected_date' => $selected_date],
            'label' => 'วันนี้ ' . date('d/m/Y', strtotime($selected_date)),
        ];
    }

    if ($mode === 'month') {
        return [
            'sql' => "DATE_FORMAT({$alias}.submitted_at, '%Y-%m') = :selected_month",
            'params' => [':selected_month' => $selected_month],
            'label' => 'เดือน ' . date('m/Y', strtotime($selected_month . '-01')),
        ];
    }

    return [
        'sql' => "{$alias}.academic_year = :selected_academic_year",
        'params' => [':selected_academic_year' => $selected_academic_year],
        'label' => 'รอบปีการศึกษา ' . $selected_academic_year,
    ];
}

$periodFilter = officefb_period_filter($report_mode, 'r', $selected_date, $selected_month, $selected_academic_year);
$periodLabel = $periodFilter['label'];

$summary = [
    'period_total' => 0,
    'period_avg' => 0,
    'unique_staff' => 0,
    'active_staff' => 0,
    'top_staff_name' => '-',
    'top_staff_count' => 0,
];
$recent_feedback = [];
$staff_summary = [];
$trend_labels = [];
$trend_counts = [];
$trend_avg_scores = [];
$distribution = [
    'Excellent' => 0,
    'Good' => 0,
    'Poor' => 0,
    'Very Poor' => 0,
];
$staff_chart_labels = [];
$staff_chart_counts = [];
$staff_chart_avg_scores = [];

if ($db_ready) {
    try {
        $stmtYears = $pdo->query("
            SELECT DISTINCT academic_year
            FROM officefb_ratings
            WHERE academic_year <> ''
            ORDER BY academic_year DESC
        ");
        $available_academic_years = $stmtYears->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($current_period['academic_year'], $available_academic_years, true)) {
            $available_academic_years[] = $current_period['academic_year'];
            rsort($available_academic_years);
        }

        $summary['active_staff'] = (int) $pdo->query("SELECT COUNT(*) FROM officefb_staff WHERE is_active = 1")->fetchColumn();

        $stmtSummary = $pdo->prepare("
            SELECT
                COUNT(*) AS period_total,
                COALESCE(AVG(r.rating_score), 0) AS period_avg,
                COUNT(DISTINCT r.staff_id) AS unique_staff
            FROM officefb_ratings r
            WHERE {$periodFilter['sql']}
        ");
        $stmtSummary->execute($periodFilter['params']);
        $summaryRow = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary['period_total'] = (int) ($summaryRow['period_total'] ?? 0);
        $summary['period_avg'] = (float) ($summaryRow['period_avg'] ?? 0);
        $summary['unique_staff'] = (int) ($summaryRow['unique_staff'] ?? 0);

        $stmtTopStaff = $pdo->prepare("
            SELECT s.full_name, COUNT(*) AS rating_count
            FROM officefb_ratings r
            INNER JOIN officefb_staff s ON s.id = r.staff_id
            WHERE {$periodFilter['sql']}
            GROUP BY s.id, s.full_name
            ORDER BY rating_count DESC, s.full_name ASC
            LIMIT 1
        ");
        $stmtTopStaff->execute($periodFilter['params']);
        $topStaff = $stmtTopStaff->fetch(PDO::FETCH_ASSOC);
        if ($topStaff) {
            $summary['top_staff_name'] = (string) $topStaff['full_name'];
            $summary['top_staff_count'] = (int) $topStaff['rating_count'];
        }

        $stmtRecent = $pdo->prepare("
            SELECT r.submitted_at, r.rating_score, r.rating_label, r.service_topic, r.comment_text,
                   s.full_name, s.position_name
            FROM officefb_ratings r
            INNER JOIN officefb_staff s ON s.id = r.staff_id
            WHERE {$periodFilter['sql']}
            ORDER BY r.submitted_at DESC
            LIMIT 15
        ");
        $stmtRecent->execute($periodFilter['params']);
        $recent_feedback = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

        $joinFilter = officefb_period_filter($report_mode, 'r', $selected_date, $selected_month, $selected_academic_year);
        $stmtStaff = $pdo->prepare("
            SELECT s.id, s.full_name, s.position_name, s.photo_url,
                   COUNT(r.id) AS rating_count,
                   ROUND(COALESCE(AVG(r.rating_score), 0), 2) AS avg_score,
                   SUM(CASE WHEN r.rating_score = 4 THEN 1 ELSE 0 END) AS excellent_count,
                   SUM(CASE WHEN r.rating_score = 3 THEN 1 ELSE 0 END) AS good_count,
                   SUM(CASE WHEN r.rating_score = 2 THEN 1 ELSE 0 END) AS poor_count,
                   SUM(CASE WHEN r.rating_score = 1 THEN 1 ELSE 0 END) AS very_poor_count
            FROM officefb_staff s
            LEFT JOIN officefb_ratings r ON r.staff_id = s.id AND {$joinFilter['sql']}
            WHERE s.is_active = 1
            GROUP BY s.id, s.full_name, s.position_name, s.photo_url
            ORDER BY rating_count DESC, avg_score DESC, s.display_order ASC
        ");
        $stmtStaff->execute($joinFilter['params']);
        $staff_summary = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);

        foreach ($staff_summary as $staff) {
            $staff_chart_labels[] = $staff['full_name'];
            $staff_chart_counts[] = (int) $staff['rating_count'];
            $staff_chart_avg_scores[] = (float) $staff['avg_score'];
        }

        $stmtDistribution = $pdo->prepare("
            SELECT rating_label, COUNT(*) AS total_count
            FROM officefb_ratings r
            WHERE {$periodFilter['sql']}
            GROUP BY rating_label
        ");
        $stmtDistribution->execute($periodFilter['params']);
        foreach ($stmtDistribution->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $label = (string) $row['rating_label'];
            if (isset($distribution[$label])) {
                $distribution[$label] = (int) $row['total_count'];
            }
        }

        $stmtTrend = $pdo->prepare("
            SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS month_key,
                   COUNT(*) AS total_count,
                   ROUND(COALESCE(AVG(rating_score), 0), 2) AS avg_score
            FROM officefb_ratings
            WHERE academic_year = :trend_year
            GROUP BY month_key
            ORDER BY month_key ASC
        ");
        $stmtTrend->execute([':trend_year' => $selected_academic_year]);
        foreach ($stmtTrend->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $trend_labels[] = date('m/Y', strtotime($row['month_key'] . '-01'));
            $trend_counts[] = (int) $row['total_count'];
            $trend_avg_scores[] = (float) $row['avg_score'];
        }
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
    <title>Office Feedback Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(180deg, #f6f0e8 0%, #f9fbff 100%);
            font-family: "Sarabun", system-ui, sans-serif;
            color: #2d241b;
        }
        .hero {
            background: linear-gradient(135deg, #473018 0%, #8d5b2a 100%);
            color: white;
            border-radius: 0 0 28px 28px;
            padding: 2.5rem 0 2rem;
            margin-bottom: 1.5rem;
        }
        .panel-card {
            border: 0;
            border-radius: 24px;
            box-shadow: 0 18px 40px rgba(59, 34, 17, 0.09);
        }
        .metric-card {
            border-radius: 24px;
            padding: 1.25rem;
            height: 100%;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248, 242, 234, 0.96));
        }
        .metric-number {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: .2rem;
        }
        .staff-tile {
            border-radius: 22px;
            padding: 1rem;
            background: white;
            height: 100%;
        }
        .staff-photo {
            width: 72px;
            height: 72px;
            border-radius: 18px;
            object-fit: cover;
            background: #ead8c4;
        }
        .rating-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .35rem .65rem;
            border-radius: 999px;
            font-weight: 700;
            font-size: .9rem;
            background: rgba(71, 48, 24, 0.08);
            color: #6a3f14;
        }
        .login-card {
            max-width: 520px;
            margin: 6vh auto 0;
            border-radius: 28px;
        }
        .filter-card {
            border-radius: 24px;
            background: rgba(255,255,255,0.88);
        }
        .chart-card {
            min-height: 420px;
        }
        .chart-wrap {
            position: relative;
            height: 320px;
            width: 100%;
            overflow: hidden;
        }
        .chart-wrap.chart-wrap-tall {
            height: 360px;
        }
        .chart-wrap canvas {
            width: 100% !important;
            height: 100% !important;
            display: block;
        }
        .hero-actions {
            display: flex;
            align-items: center;
            gap: .6rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .hero-settings-btn {
            width: 48px;
            height: 48px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .security-modal .modal-content {
            border: 0;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(59, 34, 17, 0.18);
        }
        .security-modal .modal-header {
            background: linear-gradient(135deg, #473018 0%, #8d5b2a 100%);
            color: white;
            border-bottom: 0;
            padding: 1.25rem 1.5rem;
        }
        .security-modal .modal-body {
            padding: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="hero">
        <div class="container">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="small text-uppercase fw-bold opacity-75 mb-2">Office Feedback Admin</div>
                    <h1 class="fw-bold mb-2">Dashboard ประเมินการให้บริการสำนักงานคณะ</h1>
                    <p class="mb-0 opacity-75">ดูภาพรวมคะแนน ความถี่การประเมิน และ feedback ล่าสุดสำหรับใช้ประกอบ AUN-QA</p>
                </div>
                <div class="hero-actions">
                    <a href="<?= htmlspecialchars(officefb_path('kiosk.home')) ?>" class="btn btn-light rounded-pill px-4">
                        <i class="bi bi-tablet-landscape"></i> เปิดหน้า Kiosk
                    </a>
                    <a href="<?= htmlspecialchars(officefb_path('report.home')) ?>" class="btn btn-outline-light rounded-pill px-4">
                        <i class="bi bi-qr-code"></i> เปิดรายงานสาธารณะ
                    </a>
                    <?php if ($is_authenticated): ?>
                        <a href="<?= htmlspecialchars(officefb_path('admin.sar')) ?>" class="btn btn-light rounded-pill px-4">
                            <i class="bi bi-stars"></i> SAR Assistant
                        </a>
                        <a href="<?= htmlspecialchars(officefb_path('admin.staff')) ?>" class="btn btn-warning rounded-pill px-4 fw-bold">
                            <i class="bi bi-people-fill"></i> จัดการรายชื่อเจ้าหน้าที่
                        </a>
                        <a href="<?= htmlspecialchars(officefb_path('admin.topics')) ?>" class="btn btn-light rounded-pill px-4">
                            <i class="bi bi-card-checklist"></i> จัดการหัวข้อบริการ
                        </a>
                        <a href="<?= htmlspecialchars(officefb_path('admin.logout')) ?>" class="btn btn-outline-light rounded-pill px-4">
                            <i class="bi bi-box-arrow-right"></i> ออกจากระบบ
                        </a>
                        <button
                            type="button"
                            class="btn btn-outline-light hero-settings-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#securityModal"
                            title="ตั้งค่าความปลอดภัยผู้ดูแล"
                            aria-label="ตั้งค่าความปลอดภัยผู้ดูแล"
                        >
                            <i class="bi bi-gear-fill"></i>
                        </button>
                    <?php else: ?>
                        <a href="/aunqa_php_portal/index.php" class="btn btn-outline-light rounded-pill px-4">
                            <i class="bi bi-arrow-left"></i> กลับ AUNQA Hub
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <?php if (!$is_authenticated): ?>
            <div class="card panel-card login-card p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="display-6 mb-2">🔐</div>
                    <h3 class="fw-bold mb-2">เข้าสู่ระบบผู้ดูแล Office Feedback</h3>
                    <div class="text-muted">ชื่อผู้ใช้จะอ่านจาก `config.php` และถ้ามีการเปลี่ยนรหัสผ่านผ่านหน้า admin ระบบจะใช้รหัสผ่านล่าสุดจากฐานข้อมูลของโมดูลนี้</div>
                </div>

                <?php if ($login_error !== ''): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($login_error) ?></div>
                <?php endif; ?>

                <form method="POST" class="row g-3">
                    <input type="hidden" name="action" value="login">
                    <div class="col-12">
                        <label class="form-label fw-bold">Username</label>
                        <input type="text" class="form-control form-control-lg" name="username" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold">Password</label>
                        <input type="password" class="form-control form-control-lg" name="password" required>
                    </div>
                    <div class="col-12 d-grid mt-2">
                        <button type="submit" class="btn btn-dark btn-lg fw-bold rounded-pill">
                            <i class="bi bi-box-arrow-in-right"></i> เข้าสู่ระบบ
                        </button>
                    </div>
                </form>
            </div>
        <?php elseif (!$db_ready): ?>
            <div class="alert alert-danger panel-card p-4">
                <h4 class="fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> ระบบยังไม่พร้อมใช้งาน</h4>
                <p class="mb-2">ระบบพยายามเตรียมตาราง `officefb_*` อัตโนมัติแล้ว แต่ยังไม่สำเร็จ กรุณาตรวจสอบสิทธิ์ผู้ใช้ฐานข้อมูลหรือค่าใน `config.php`</p>
                <?php if ($error_message !== ''): ?>
                    <code><?= htmlspecialchars($error_message) ?></code>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> panel-card p-3">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <div class="card panel-card filter-card p-4 mb-4">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-lg-3">
                        <label class="form-label fw-bold">มุมมองรายงาน</label>
                        <select name="report_mode" class="form-select">
                            <option value="academic_year" <?= $report_mode === 'academic_year' ? 'selected' : '' ?>>รอบปีการศึกษา</option>
                            <option value="month" <?= $report_mode === 'month' ? 'selected' : '' ?>>รายเดือน</option>
                            <option value="today" <?= $report_mode === 'today' ? 'selected' : '' ?>>รายวัน</option>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label fw-bold">ปีการศึกษา</label>
                        <select name="academic_year" class="form-select">
                            <?php foreach ($available_academic_years as $year): ?>
                                <option value="<?= htmlspecialchars($year) ?>" <?= $selected_academic_year === (string) $year ? 'selected' : '' ?>><?= htmlspecialchars($year) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label fw-bold">เดือน</label>
                        <input type="month" name="selected_month" class="form-control" value="<?= htmlspecialchars($selected_month) ?>">
                    </div>
                    <div class="col-lg-2">
                        <label class="form-label fw-bold">วันที่</label>
                        <input type="date" name="selected_date" class="form-control" value="<?= htmlspecialchars($selected_date) ?>">
                    </div>
                    <div class="col-lg-1 d-grid">
                        <button type="submit" class="btn btn-dark fw-bold">
                            <i class="bi bi-funnel-fill"></i>
                        </button>
                    </div>
                </form>
                <div class="small text-muted mt-3">
                    ช่วงที่กำลังดู: <strong><?= htmlspecialchars($periodLabel) ?></strong> |
                    กรรมการจะเห็นทั้งคะแนนเฉลี่ยและจำนวนการประเมิน เพื่อไม่ให้ตีความจากคะแนนเพียงอย่างเดียว
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6 col-xl-3">
                    <div class="metric-card panel-card">
                        <div class="text-secondary small fw-bold">จำนวนการประเมินในช่วงนี้</div>
                        <div class="metric-number"><?= number_format($summary['period_total']) ?></div>
                        <div class="text-secondary">ปริมาณ feedback ทั้งหมดใน <?= htmlspecialchars($periodLabel) ?></div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="metric-card panel-card">
                        <div class="text-secondary small fw-bold">คะแนนเฉลี่ยในช่วงนี้</div>
                        <div class="metric-number"><?= number_format($summary['period_avg'], 2) ?></div>
                        <div class="text-secondary">จากสเกล 1-4 ในช่วงเวลาที่เลือก</div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="metric-card panel-card">
                        <div class="text-secondary small fw-bold">เจ้าหน้าที่ที่ถูกประเมิน</div>
                        <div class="metric-number"><?= number_format($summary['unique_staff']) ?></div>
                        <div class="text-secondary">จำนวนบุคลากรที่มี feedback ในช่วงนี้</div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="metric-card panel-card">
                        <div class="text-secondary small fw-bold">ผู้ถูกประเมินมากที่สุด</div>
                        <div class="metric-number"><?= number_format($summary['top_staff_count']) ?></div>
                        <div class="text-secondary"><?= htmlspecialchars($summary['top_staff_name']) ?></div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="panel-card p-4 chart-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="fw-bold mb-0">แนวโน้มรอบปีการศึกษา</h3>
                            <span class="rating-chip"><i class="bi bi-graph-up-arrow"></i> ปี <?= htmlspecialchars($selected_academic_year) ?></span>
                        </div>
                        <div class="chart-wrap chart-wrap-tall">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="panel-card p-4 chart-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="fw-bold mb-0">สัดส่วนคะแนน</h3>
                            <span class="rating-chip"><i class="bi bi-pie-chart-fill"></i> <?= htmlspecialchars($periodLabel) ?></span>
                        </div>
                        <div class="chart-wrap">
                            <canvas id="distributionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mt-1">
                <div class="col-lg-7">
                    <div class="panel-card p-4 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="fw-bold mb-0">Feedback ล่าสุด</h3>
                            <span class="rating-chip"><i class="bi bi-clock-history"></i> ล่าสุด 15 รายการในช่วงนี้</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>เวลา</th>
                                        <th>เจ้าหน้าที่</th>
                                        <th>คะแนน</th>
                                        <th>หัวข้อ</th>
                                        <th>หมายเหตุ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_feedback)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มี feedback ในระบบ</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_feedback as $row): ?>
                                            <tr>
                                                <td class="small"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($row['submitted_at']))) ?></td>
                                                <td>
                                                    <div class="fw-bold"><?= htmlspecialchars($row['full_name']) ?></div>
                                                    <div class="small text-muted"><?= htmlspecialchars($row['position_name']) ?></div>
                                                </td>
                                                <td><span class="rating-chip"><?= htmlspecialchars($row['rating_label']) ?> (<?= (int) $row['rating_score'] ?>)</span></td>
                                                <td class="small"><?= htmlspecialchars($row['service_topic'] !== '' ? $row['service_topic'] : '-') ?></td>
                                                <td class="small"><?= htmlspecialchars($row['comment_text'] !== '' ? $row['comment_text'] : '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="panel-card p-4 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="fw-bold mb-0">สรุปรายเจ้าหน้าที่</h3>
                            <span class="rating-chip"><i class="bi bi-bar-chart-fill"></i> คะแนน + ปริมาณ</span>
                        </div>
                        <div class="chart-wrap mb-3">
                            <canvas id="staffChart"></canvas>
                        </div>
                        <div class="row g-3">
                            <?php foreach ($staff_summary as $staff): ?>
                                <?php
                                $photo_url = trim((string) $staff['photo_url']);
                                if ($photo_url === '') {
                                    $photo_url = 'https://placehold.co/300x300/ead8c4/6a3f14?text=Staff';
                                }
                                ?>
                                <div class="col-12">
                                    <div class="staff-tile">
                                        <div class="d-flex gap-3">
                                            <img
                                                src="<?= htmlspecialchars($photo_url) ?>"
                                                alt="<?= htmlspecialchars($staff['full_name']) ?>"
                                                class="staff-photo"
                                                onerror="this.onerror=null;this.src='https://placehold.co/300x300/ead8c4/6a3f14?text=Staff';"
                                            >
                                            <div class="flex-grow-1">
                                                <div class="fw-bold"><?= htmlspecialchars($staff['full_name']) ?></div>
                                                <div class="text-muted small mb-2"><?= htmlspecialchars($staff['position_name']) ?></div>
                                                <div class="d-flex gap-2 flex-wrap mb-2">
                                                    <span class="rating-chip">เฉลี่ย <?= number_format((float) $staff['avg_score'], 2) ?></span>
                                                    <span class="rating-chip">ทั้งหมด <?= (int) $staff['rating_count'] ?></span>
                                                </div>
                                                <div class="small text-muted">
                                                    Excellent <?= (int) $staff['excellent_count'] ?> |
                                                    Good <?= (int) $staff['good_count'] ?> |
                                                    Poor <?= (int) $staff['poor_count'] ?> |
                                                    Very Poor <?= (int) $staff['very_poor_count'] ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($is_authenticated && $db_ready): ?>
        <div class="modal fade security-modal" id="securityModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <div class="small text-uppercase fw-bold opacity-75 mb-1">Admin Security</div>
                            <h3 class="fw-bold mb-0">ความปลอดภัยผู้ดูแล</h3>
                        </div>
                        <button type="button" class="btn btn-outline-light rounded-pill" data-bs-dismiss="modal" aria-label="Close">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-4 align-items-start">
                            <div class="col-lg-6">
                                <div class="text-muted mb-2">เปลี่ยนรหัสผ่านของผู้ดูแลโมดูล Office Feedback ได้จากหน้านี้โดยตรง ระบบจะบันทึกเป็น hash ลงตาราง `officefb_settings` แยกจากส่วนอื่น</div>
                                <div class="small text-muted mb-3">หากต้องการกลับไปใช้ค่าจาก `config.php` ก็สามารถรีเซ็ต override ได้จากกล่องนี้</div>
                                <span class="rating-chip"><i class="bi bi-person-lock"></i> Username: <?= htmlspecialchars(officefb_admin_username()) ?></span>
                            </div>
                            <div class="col-lg-6">
                                <?php if ($password_error !== ''): ?>
                                    <div class="alert alert-danger"><?= htmlspecialchars($password_error) ?></div>
                                <?php endif; ?>
                                <form method="POST" class="row g-3">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="col-12">
                                        <label class="form-label fw-bold">รหัสผ่านปัจจุบัน</label>
                                        <input type="password" class="form-control" name="current_password" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">รหัสผ่านใหม่</label>
                                        <input type="password" class="form-control" name="new_password" minlength="6" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">ยืนยันรหัสผ่านใหม่</label>
                                        <input type="password" class="form-control" name="confirm_password" minlength="6" required>
                                    </div>
                                    <div class="col-12 d-grid d-md-flex justify-content-md-end">
                                        <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold">
                                            <i class="bi bi-shield-lock-fill"></i> บันทึกรหัสผ่านใหม่
                                        </button>
                                    </div>
                                </form>
                                <hr class="my-4">
                                <form method="POST" onsubmit="return confirm('ต้องการลบรหัสผ่านที่ override ไว้และกลับไปใช้ค่าจาก config.php ใช่หรือไม่');">
                                    <input type="hidden" name="action" value="reset_password_override">
                                    <div class="d-grid d-md-flex justify-content-md-end">
                                        <button type="submit" class="btn btn-outline-danger rounded-pill px-4 fw-bold">
                                            <i class="bi bi-arrow-counterclockwise"></i> รีเซ็ตรหัสผ่านกลับไปใช้ config.php
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
        <script>
            <?php if ($password_error !== ''): ?>
            document.addEventListener('DOMContentLoaded', () => {
                const modalElement = document.getElementById('securityModal');
                if (modalElement && window.bootstrap) {
                    const modal = new bootstrap.Modal(modalElement);
                    modal.show();
                }
            });
            <?php endif; ?>

            const trendLabels = <?= json_encode($trend_labels, JSON_UNESCAPED_UNICODE) ?>;
            const trendCounts = <?= json_encode($trend_counts) ?>;
            const trendAvgScores = <?= json_encode($trend_avg_scores) ?>;
            const staffChartLabels = <?= json_encode($staff_chart_labels, JSON_UNESCAPED_UNICODE) ?>;
            const staffChartCounts = <?= json_encode($staff_chart_counts) ?>;
            const staffChartAvgScores = <?= json_encode($staff_chart_avg_scores) ?>;
            const distributionLabels = <?= json_encode(array_keys($distribution), JSON_UNESCAPED_UNICODE) ?>;
            const distributionValues = <?= json_encode(array_values($distribution)) ?>;

            if (document.getElementById('trendChart')) {
                new Chart(document.getElementById('trendChart'), {
                    type: 'bar',
                    data: {
                        labels: trendLabels,
                        datasets: [
                            {
                                type: 'bar',
                                label: 'จำนวนการประเมิน',
                                data: trendCounts,
                                backgroundColor: 'rgba(141, 91, 42, 0.75)',
                                borderRadius: 10,
                                yAxisID: 'y'
                            },
                            {
                                type: 'line',
                                label: 'คะแนนเฉลี่ย',
                                data: trendAvgScores,
                                borderColor: '#2d6a4f',
                                backgroundColor: '#2d6a4f',
                                tension: 0.35,
                                yAxisID: 'y1'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: { display: true, text: 'จำนวนการประเมิน' }
                            },
                            y1: {
                                position: 'right',
                                beginAtZero: true,
                                max: 4,
                                grid: { drawOnChartArea: false },
                                title: { display: true, text: 'คะแนนเฉลี่ย' }
                            }
                        }
                    }
                });
            }

            if (document.getElementById('distributionChart')) {
                new Chart(document.getElementById('distributionChart'), {
                    type: 'doughnut',
                    data: {
                        labels: distributionLabels,
                        datasets: [{
                            data: distributionValues,
                            backgroundColor: ['#2d6a4f', '#40916c', '#f4a261', '#d62828']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom' }
                        }
                    }
                });
            }

            if (document.getElementById('staffChart')) {
                new Chart(document.getElementById('staffChart'), {
                    type: 'bar',
                    data: {
                        labels: staffChartLabels,
                        datasets: [
                            {
                                type: 'bar',
                                label: 'จำนวนการประเมิน',
                                data: staffChartCounts,
                                backgroundColor: 'rgba(157, 107, 47, 0.75)',
                                borderRadius: 8,
                                yAxisID: 'y'
                            },
                            {
                                type: 'line',
                                label: 'คะแนนเฉลี่ย',
                                data: staffChartAvgScores,
                                borderColor: '#1d3557',
                                backgroundColor: '#1d3557',
                                tension: 0.3,
                                yAxisID: 'y1'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: { display: true, text: 'จำนวนการประเมิน' }
                            },
                            y1: {
                                position: 'right',
                                beginAtZero: true,
                                max: 4,
                                grid: { drawOnChartArea: false },
                                title: { display: true, text: 'คะแนนเฉลี่ย' }
                            }
                        },
                        plugins: {
                            legend: { position: 'bottom' }
                        }
                    }
                });
            }
        </script>
    <?php endif; ?>
</body>
</html>
