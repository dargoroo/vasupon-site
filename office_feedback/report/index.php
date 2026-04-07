<?php

require_once dirname(__DIR__) . '/bootstrap.php';

$state = officefb_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok']
    && officefb_table_exists($pdo, 'officefb_staff')
    && officefb_table_exists($pdo, 'officefb_ratings');
$error_message = $state['error'];

$current_period = officefb_academic_period();
$selected_academic_year = isset($_GET['academic_year']) ? trim((string) $_GET['academic_year']) : $current_period['academic_year'];
$available_academic_years = [$current_period['academic_year']];

$summary = [
    'period_total' => 0,
    'period_avg' => 0,
    'unique_staff' => 0,
    'top_staff_name' => '-',
    'top_staff_count' => 0,
    'top_topic_name' => '-',
    'top_topic_count' => 0,
];
$trend_labels = [];
$trend_counts = [];
$trend_avg_scores = [];
$distribution = [
    'Excellent' => 0,
    'Good' => 0,
    'Poor' => 0,
    'Very Poor' => 0,
];
$staff_summary = [];
$topic_summary = [];
$report_generated_at = date('d/m/Y H:i');

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
        if (!in_array($selected_academic_year, $available_academic_years, true)) {
            $selected_academic_year = $current_period['academic_year'];
        }

        $stmtSummary = $pdo->prepare("
            SELECT
                COUNT(*) AS period_total,
                COALESCE(AVG(r.rating_score), 0) AS period_avg,
                COUNT(DISTINCT r.staff_id) AS unique_staff
            FROM officefb_ratings r
            WHERE r.academic_year = :academic_year
        ");
        $stmtSummary->execute([':academic_year' => $selected_academic_year]);
        $summaryRow = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary['period_total'] = (int) ($summaryRow['period_total'] ?? 0);
        $summary['period_avg'] = (float) ($summaryRow['period_avg'] ?? 0);
        $summary['unique_staff'] = (int) ($summaryRow['unique_staff'] ?? 0);

        $stmtTopStaff = $pdo->prepare("
            SELECT s.full_name, COUNT(*) AS rating_count
            FROM officefb_ratings r
            INNER JOIN officefb_staff s ON s.id = r.staff_id
            WHERE r.academic_year = :academic_year
            GROUP BY s.id, s.full_name
            ORDER BY rating_count DESC, s.full_name ASC
            LIMIT 1
        ");
        $stmtTopStaff->execute([':academic_year' => $selected_academic_year]);
        $topStaff = $stmtTopStaff->fetch(PDO::FETCH_ASSOC);
        if ($topStaff) {
            $summary['top_staff_name'] = (string) $topStaff['full_name'];
            $summary['top_staff_count'] = (int) $topStaff['rating_count'];
        }

        $stmtTopTopic = $pdo->prepare("
            SELECT service_topic, COUNT(*) AS topic_count
            FROM officefb_ratings
            WHERE academic_year = :academic_year
              AND service_topic <> ''
            GROUP BY service_topic
            ORDER BY topic_count DESC, service_topic ASC
            LIMIT 1
        ");
        $stmtTopTopic->execute([':academic_year' => $selected_academic_year]);
        $topTopic = $stmtTopTopic->fetch(PDO::FETCH_ASSOC);
        if ($topTopic) {
            $summary['top_topic_name'] = (string) $topTopic['service_topic'];
            $summary['top_topic_count'] = (int) $topTopic['topic_count'];
        }

        $stmtDistribution = $pdo->prepare("
            SELECT rating_label, COUNT(*) AS total_count
            FROM officefb_ratings
            WHERE academic_year = :academic_year
            GROUP BY rating_label
        ");
        $stmtDistribution->execute([':academic_year' => $selected_academic_year]);
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
            WHERE academic_year = :academic_year
            GROUP BY month_key
            ORDER BY month_key ASC
        ");
        $stmtTrend->execute([':academic_year' => $selected_academic_year]);
        foreach ($stmtTrend->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $trend_labels[] = date('m/Y', strtotime($row['month_key'] . '-01'));
            $trend_counts[] = (int) $row['total_count'];
            $trend_avg_scores[] = (float) $row['avg_score'];
        }

        $stmtStaff = $pdo->prepare("
            SELECT s.full_name, s.position_name, s.photo_url,
                   COUNT(r.id) AS rating_count,
                   ROUND(COALESCE(AVG(r.rating_score), 0), 2) AS avg_score,
                   SUM(CASE WHEN r.rating_score = 4 THEN 1 ELSE 0 END) AS excellent_count,
                   SUM(CASE WHEN r.rating_score = 3 THEN 1 ELSE 0 END) AS good_count,
                   SUM(CASE WHEN r.rating_score = 2 THEN 1 ELSE 0 END) AS poor_count,
                   SUM(CASE WHEN r.rating_score = 1 THEN 1 ELSE 0 END) AS very_poor_count
            FROM officefb_staff s
            LEFT JOIN officefb_ratings r
                ON r.staff_id = s.id
               AND r.academic_year = :academic_year
            WHERE s.is_active = 1
            GROUP BY s.id, s.full_name, s.position_name, s.photo_url
            ORDER BY rating_count DESC, avg_score DESC, s.display_order ASC, s.id ASC
        ");
        $stmtStaff->execute([':academic_year' => $selected_academic_year]);
        $staff_summary = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);

        $stmtTopics = $pdo->prepare("
            SELECT service_topic, COUNT(*) AS topic_count
            FROM officefb_ratings
            WHERE academic_year = :academic_year
              AND service_topic <> ''
            GROUP BY service_topic
            ORDER BY topic_count DESC, service_topic ASC
            LIMIT 8
        ");
        $stmtTopics->execute([':academic_year' => $selected_academic_year]);
        $topic_summary = $stmtTopics->fetchAll(PDO::FETCH_ASSOC);
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
    <title>Office Feedback Report</title>
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
            border-radius: 0 0 32px 32px;
            padding: 2.6rem 0 2rem;
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
        .filter-card {
            border-radius: 24px;
            background: rgba(255,255,255,0.88);
        }
        .chart-wrap {
            position: relative;
            height: 340px;
            width: 100%;
            overflow: hidden;
        }
        .chart-wrap.chart-wrap-tall {
            height: 380px;
        }
        .chart-wrap canvas {
            width: 100% !important;
            height: 100% !important;
            display: block;
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
        .topic-pill {
            display: inline-flex;
            align-items: center;
            padding: .55rem .85rem;
            border-radius: 999px;
            background: rgba(141, 91, 42, 0.1);
            color: #6a3f14;
            font-weight: 700;
            margin: 0 .5rem .5rem 0;
        }
        .qr-preview {
            width: min(260px, 100%);
            aspect-ratio: 1;
            object-fit: contain;
            background: white;
            border-radius: 24px;
            padding: 1rem;
            border: 1px solid rgba(71, 48, 24, 0.08);
            box-shadow: 0 16px 32px rgba(59, 34, 17, 0.08);
        }
        .print-note {
            display: none;
        }
        .print-only {
            display: none;
        }
        .print-page-header {
            display: none;
        }
        .print-signature-block {
            display: none;
        }
        .print-section-title {
            font-size: 13pt;
            font-weight: 800;
            margin-bottom: 2.5mm;
            color: #2d241b;
        }
        .print-meta-table,
        .print-summary-table,
        .print-data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .print-meta-table th,
        .print-meta-table td,
        .print-summary-table th,
        .print-summary-table td,
        .print-data-table th,
        .print-data-table td {
            border: 1px solid rgba(0, 0, 0, 0.12);
            padding: 2.2mm 2.5mm;
            vertical-align: top;
        }
        .print-meta-table th,
        .print-summary-table th,
        .print-data-table th {
            background: rgba(141, 91, 42, 0.08);
            font-weight: 800;
        }
        .print-narrative {
            font-size: 10pt;
            color: #444;
            line-height: 1.5;
        }
        @page {
            size: A4 portrait;
            margin: 11mm 10mm 11mm 10mm;
        }
        @media screen {
            .print-only {
                display: none !important;
            }
        }
        @media print {
            @bottom-right {
                content: "หน้า " counter(page);
            }
            html, body {
                background: #fff !important;
            }
            body {
                color: #1f1f1f;
                font-size: 11pt;
                line-height: 1.35;
            }
            .container {
                max-width: 190mm !important;
                width: 190mm !important;
            }
            .hero {
                background: none !important;
                color: #1f1f1f !important;
                border-radius: 0 !important;
                border-bottom: 1px solid rgba(0, 0, 0, 0.12);
                padding: 0 0 5mm !important;
                margin-bottom: 5mm !important;
            }
            .hero .small,
            .hero .opacity-75 {
                opacity: 1 !important;
                color: #555 !important;
            }
            .hero h1 {
                font-size: 18pt;
                margin-bottom: 2mm !important;
            }
            .hero p {
                font-size: 10pt;
                margin-bottom: 0 !important;
            }
            .hero .btn,
            .filter-card .btn,
            .modal,
            .btn {
                display: none !important;
            }
            .screen-only {
                display: none !important;
            }
            .print-note {
                display: block;
                margin-top: 2mm;
                font-size: 9pt;
                color: #555;
            }
            .print-only {
                display: block !important;
            }
            .print-page-header,
            .print-signature-block {
                display: block !important;
            }
            .print-page-header {
                margin-bottom: 4mm;
                padding-bottom: 3mm;
                border-bottom: 1px solid rgba(0, 0, 0, 0.12);
            }
            .print-page-header .org-name {
                font-size: 12.5pt;
                font-weight: 800;
                margin-bottom: 1mm;
            }
            .print-page-header .org-subtitle {
                font-size: 9.5pt;
                color: #555;
            }
            .panel-card,
            .metric-card,
            .filter-card,
            .staff-tile {
                box-shadow: none !important;
                border: 1px solid rgba(0, 0, 0, 0.08) !important;
                background: #fff !important;
                border-radius: 12px !important;
            }
            .panel-card,
            .filter-card {
                padding: 4mm !important;
            }
            .metric-card {
                padding: 3mm !important;
            }
            .metric-number {
                font-size: 16pt !important;
                margin-bottom: 1mm !important;
            }
            .row {
                --bs-gutter-x: 3mm;
                --bs-gutter-y: 3mm;
            }
            .mb-4 {
                margin-bottom: 3mm !important;
            }
            .p-4 {
                padding: 4mm !important;
            }
            .chart-wrap {
                height: 58mm !important;
            }
            .chart-wrap.chart-wrap-tall {
                height: 66mm !important;
            }
            .rating-chip,
            .topic-pill {
                font-size: 8.5pt !important;
                padding: 1.5mm 2.5mm !important;
            }
            .staff-photo {
                width: 15mm !important;
                height: 15mm !important;
                border-radius: 4mm !important;
            }
            .staff-tile .fw-bold {
                font-size: 10pt;
            }
            .staff-tile .small,
            .text-muted,
            .text-secondary {
                color: #555 !important;
            }
            .print-report-header,
            .print-report-summary,
            .print-report-detail {
                display: block !important;
                margin-bottom: 4mm;
            }
            .print-report-header {
                border: 1px solid rgba(0, 0, 0, 0.12);
                border-radius: 12px;
                padding: 4mm;
                background: #fff;
            }
            .print-report-header h2 {
                font-size: 15pt;
                margin-bottom: 1.5mm;
            }
            .print-section-title {
                font-size: 11.5pt;
                margin-bottom: 2mm;
            }
            .print-meta-table,
            .print-summary-table,
            .print-data-table {
                font-size: 9.5pt;
            }
            .print-signature-block {
                margin-top: 5mm;
                padding-top: 3mm;
                border-top: 1px solid rgba(0, 0, 0, 0.12);
            }
            .print-signature-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 6mm;
                margin-top: 4mm;
            }
            .print-signature-card {
                border: 1px solid rgba(0, 0, 0, 0.1);
                border-radius: 10px;
                padding: 4mm;
                min-height: 34mm;
            }
            .print-signature-title {
                font-size: 9.5pt;
                font-weight: 800;
                margin-bottom: 12mm;
            }
            .print-signature-line {
                border-top: 1px solid rgba(0, 0, 0, 0.6);
                padding-top: 2mm;
                font-size: 9pt;
                color: #444;
            }
            .print-data-table .text-end {
                text-align: right;
            }
            #summary-kpi,
            #summary-insights,
            #trend-annual,
            #staff-overview,
            #topic-overview,
            .print-report-header,
            .print-report-summary,
            .print-report-detail,
            .print-signature-block,
            .panel-card,
            .staff-tile {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            #trend-annual .col-lg-8,
            #trend-annual .col-lg-4,
            #staff-overview .col-lg-7,
            #staff-overview .col-lg-5 {
                width: 100% !important;
                flex: 0 0 100% !important;
                max-width: 100% !important;
            }
        }
    </style>
</head>
<body>
    <div class="container print-page-header">
        <div class="org-name">คณะวิทยาการคอมพิวเตอร์และเทคโนโลยีสารสนเทศ มหาวิทยาลัยราชภัฏรำไพพรรณี</div>
        <div class="org-subtitle">รายงานสรุปผลประเมินการให้บริการสำนักงานคณะ เพื่อประกอบการพิจารณาของกรรมการและการจัดทำ SAR / AUN-QA</div>
    </div>

    <div class="hero">
        <div class="container">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="small text-uppercase fw-bold opacity-75 mb-2">Public Annual Dashboard</div>
                    <h1 class="fw-bold mb-2">รายงานสรุปผลประเมินการให้บริการสำนักงานคณะ</h1>
                    <p class="mb-0 opacity-75">สำหรับกรรมการและผู้ประเมิน ใช้อ่านประกอบ SAR / AUN-QA โดยไม่ต้องเข้าสู่ระบบ</p>
                    <div class="print-note">รายงานฉบับพิมพ์สำหรับประกอบการพิจารณาของกรรมการในปีการศึกษา <?= htmlspecialchars($selected_academic_year) ?></div>
                </div>
                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <span class="btn btn-outline-light rounded-pill px-4 disabled">
                        <i class="bi bi-eye-fill"></i> Read-only
                    </span>
                    <button type="button" class="btn btn-outline-light rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#qrModal">
                        <i class="bi bi-qr-code"></i> QR code
                    </button>
                    <button type="button" class="btn btn-light rounded-pill px-4" onclick="window.print()">
                        <i class="bi bi-printer-fill"></i> พิมพ์รายงาน
                    </button>
                    <a href="<?= htmlspecialchars(officefb_path('admin.home')) ?>" class="btn btn-outline-light rounded-pill px-4">
                        <i class="bi bi-box-arrow-in-right"></i> เข้า Admin
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <?php if (!$db_ready): ?>
            <div class="alert alert-danger panel-card p-4">
                <h4 class="fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> ระบบรายงานยังไม่พร้อมใช้งาน</h4>
                <p class="mb-2">ไม่สามารถอ่านข้อมูลรายงานของโมดูล Office Feedback ได้ในขณะนี้ กรุณาตรวจสอบฐานข้อมูลหรือการตั้งค่าระบบ</p>
                <?php if ($error_message !== ''): ?>
                    <code><?= htmlspecialchars($error_message) ?></code>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="print-only print-report-header">
                <div class="print-section-title">ข้อมูลรายงาน</div>
                <table class="print-meta-table">
                    <tbody>
                        <tr>
                            <th style="width: 24%;">ชื่อรายงาน</th>
                            <td>รายงานสรุปผลประเมินการให้บริการสำนักงานคณะ</td>
                            <th style="width: 18%;">ปีการศึกษา</th>
                            <td style="width: 18%;"><?= htmlspecialchars($selected_academic_year) ?></td>
                        </tr>
                        <tr>
                            <th>วัตถุประสงค์</th>
                            <td>ใช้ประกอบการพิจารณาของกรรมการและการจัดทำ SAR / AUN-QA สำหรับงานบริการสนับสนุนของสำนักงานคณะ</td>
                            <th>วันที่พิมพ์</th>
                            <td><?= htmlspecialchars($report_generated_at) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card panel-card filter-card p-4 mb-4 screen-only">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-lg-4">
                        <label class="form-label fw-bold">ปีการศึกษา</label>
                        <select name="academic_year" class="form-select">
                            <?php foreach ($available_academic_years as $year): ?>
                                <option value="<?= htmlspecialchars($year) ?>" <?= $selected_academic_year === (string) $year ? 'selected' : '' ?>><?= htmlspecialchars($year) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3 d-grid">
                        <button type="submit" class="btn btn-dark fw-bold rounded-pill">
                            <i class="bi bi-funnel-fill"></i> แสดงรายงานรอบปี
                        </button>
                    </div>
                    <div class="col-lg-5">
                        <div class="small text-muted mt-2 mt-lg-0">
                            หน้านี้เน้นสถิติภาพรวมสำหรับกรรมการ โดยแสดงทั้งคะแนนและปริมาณการประเมิน เพื่อไม่ให้ตีความจากค่าเฉลี่ยเพียงอย่างเดียว
                        </div>
                    </div>
                </form>
            </div>

            <div class="print-only print-report-summary">
                <div class="print-section-title">สรุปภาพรวมรอบปี</div>
                <table class="print-summary-table">
                    <thead>
                        <tr>
                            <th>จำนวนการประเมินทั้งปี</th>
                            <th>คะแนนเฉลี่ยทั้งปี</th>
                            <th>เจ้าหน้าที่ที่ถูกประเมิน</th>
                            <th>ผู้ถูกประเมินมากที่สุด</th>
                            <th>หัวข้อบริการเด่น</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= number_format($summary['period_total']) ?></td>
                            <td><?= number_format($summary['period_avg'], 2) ?></td>
                            <td><?= number_format($summary['unique_staff']) ?></td>
                            <td><?= htmlspecialchars($summary['top_staff_name']) ?> (<?= number_format($summary['top_staff_count']) ?>)</td>
                            <td><?= htmlspecialchars($summary['top_topic_name']) ?> (<?= number_format($summary['top_topic_count']) ?>)</td>
                        </tr>
                    </tbody>
                </table>
                <div class="print-narrative mt-2">
                    รายงานนี้แสดงทั้งคุณภาพการบริการและปริมาณการประเมิน เพื่อช่วยให้กรรมการตีความผลอย่างระมัดระวัง ไม่พิจารณาจากค่าเฉลี่ยเพียงอย่างเดียว โดยเฉพาะกรณีที่จำนวนผู้ตอบของแต่ละบุคลากรแตกต่างกันมาก
                </div>
            </div>

            <div class="row g-3 mb-4 screen-only" id="summary-kpi">
                <div class="col-md-6 col-xl-3">
                    <div class="metric-card panel-card">
                        <div class="text-secondary small fw-bold">จำนวนการประเมินทั้งปี</div>
                        <div class="metric-number"><?= number_format($summary['period_total']) ?></div>
                        <div class="text-secondary">feedback ทั้งหมดในปีการศึกษา <?= htmlspecialchars($selected_academic_year) ?></div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="metric-card panel-card">
                        <div class="text-secondary small fw-bold">คะแนนเฉลี่ยทั้งปี</div>
                        <div class="metric-number"><?= number_format($summary['period_avg'], 2) ?></div>
                        <div class="text-secondary">คะแนนเฉลี่ยจากสเกล 1-4</div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="metric-card panel-card">
                        <div class="text-secondary small fw-bold">เจ้าหน้าที่ที่ถูกประเมิน</div>
                        <div class="metric-number"><?= number_format($summary['unique_staff']) ?></div>
                        <div class="text-secondary">จำนวนบุคลากรที่มี feedback</div>
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

            <div class="row g-3 mb-4 screen-only" id="summary-insights">
                <div class="col-md-6">
                    <div class="metric-card panel-card">
                        <div class="text-secondary small fw-bold">หัวข้อบริการที่ถูกเลือกมากที่สุด</div>
                        <div class="metric-number"><?= number_format($summary['top_topic_count']) ?></div>
                        <div class="text-secondary"><?= htmlspecialchars($summary['top_topic_name']) ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="metric-card panel-card">
                        <div class="text-secondary small fw-bold">การตีความสำหรับกรรมการ</div>
                        <div class="text-secondary">
                            รายงานนี้แสดงทั้งแนวโน้มรายเดือน สัดส่วนคะแนน และจำนวนการประเมินรายบุคคล เพื่อให้เห็นคุณภาพการบริการควบคู่กับความหนาแน่นของข้อมูลในรอบปีเดียวกัน
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4 screen-only" id="trend-annual">
                <div class="col-lg-8">
                    <div class="panel-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="fw-bold mb-0">แนวโน้มตลอดปีการศึกษา</h3>
                            <span class="rating-chip"><i class="bi bi-graph-up-arrow"></i> ปี <?= htmlspecialchars($selected_academic_year) ?></span>
                        </div>
                        <div class="chart-wrap chart-wrap-tall">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="panel-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="fw-bold mb-0">สัดส่วนคะแนน</h3>
                            <span class="rating-chip"><i class="bi bi-pie-chart-fill"></i> ภาพรวมทั้งปี</span>
                        </div>
                        <div class="chart-wrap">
                            <canvas id="distributionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 screen-only" id="staff-overview">
                <div class="col-lg-7">
                    <div class="panel-card p-4 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="fw-bold mb-0">สรุปรายเจ้าหน้าที่</h3>
                            <span class="rating-chip"><i class="bi bi-bar-chart-fill"></i> คะแนน + ปริมาณ</span>
                        </div>
                        <div class="row g-3">
                            <?php if (empty($staff_summary)): ?>
                                <div class="col-12 text-center text-muted py-4">ยังไม่มีข้อมูลในรอบปีนี้</div>
                            <?php else: ?>
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
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5" id="topic-overview">
                    <div class="panel-card p-4 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="fw-bold mb-0">หัวข้อบริการที่ถูกกล่าวถึง</h3>
                            <span class="rating-chip"><i class="bi bi-tags-fill"></i> Top topics</span>
                        </div>
                        <?php if (empty($topic_summary)): ?>
                            <div class="text-center text-muted py-4">ยังไม่มีการระบุหัวข้อบริการในรอบปีนี้</div>
                        <?php else: ?>
                            <div class="mb-3">
                                <?php foreach ($topic_summary as $topic): ?>
                                    <span class="topic-pill">
                                        <?= htmlspecialchars($topic['service_topic']) ?> · <?= (int) $topic['topic_count'] ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="chart-wrap mb-3">
                            <canvas id="staffChart"></canvas>
                        </div>
                        <div class="small text-muted">
                            กราฟนี้ช่วยให้กรรมการเห็นความต่างระหว่าง “คะแนนเฉลี่ย” และ “จำนวนการประเมิน” ของแต่ละบุคลากรในรอบปีเดียวกัน
                        </div>
                    </div>
                </div>
            </div>

            <div class="print-only print-report-detail">
                <div class="print-section-title">แนวโน้มรายเดือนและสัดส่วนคะแนน</div>
                <table class="print-data-table">
                    <thead>
                        <tr>
                            <th>เดือน/ปี</th>
                            <th class="text-end">จำนวนการประเมิน</th>
                            <th class="text-end">คะแนนเฉลี่ย</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($trend_labels)): ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted">ยังไม่มีข้อมูลรายเดือนในรอบปีนี้</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($trend_labels as $index => $label): ?>
                                <tr>
                                    <td><?= htmlspecialchars($label) ?></td>
                                    <td class="text-end"><?= (int) ($trend_counts[$index] ?? 0) ?></td>
                                    <td class="text-end"><?= number_format((float) ($trend_avg_scores[$index] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <table class="print-data-table mt-3">
                    <thead>
                        <tr>
                            <th>ระดับคะแนน</th>
                            <th class="text-end">จำนวนครั้ง</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($distribution as $label => $count): ?>
                            <tr>
                                <td><?= htmlspecialchars($label) ?></td>
                                <td class="text-end"><?= (int) $count ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="print-only print-report-detail">
                <div class="print-section-title">รายละเอียดรายเจ้าหน้าที่</div>
                <table class="print-data-table">
                    <thead>
                        <tr>
                            <th>ชื่อเจ้าหน้าที่</th>
                            <th>ตำแหน่ง</th>
                            <th class="text-end">คะแนนเฉลี่ย</th>
                            <th class="text-end">จำนวนการประเมิน</th>
                            <th class="text-end">Excellent</th>
                            <th class="text-end">Good</th>
                            <th class="text-end">Poor</th>
                            <th class="text-end">Very Poor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($staff_summary)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">ยังไม่มีข้อมูลในรอบปีนี้</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($staff_summary as $staff): ?>
                                <tr>
                                    <td><?= htmlspecialchars($staff['full_name']) ?></td>
                                    <td><?= htmlspecialchars($staff['position_name']) ?></td>
                                    <td class="text-end"><?= number_format((float) $staff['avg_score'], 2) ?></td>
                                    <td class="text-end"><?= (int) $staff['rating_count'] ?></td>
                                    <td class="text-end"><?= (int) $staff['excellent_count'] ?></td>
                                    <td class="text-end"><?= (int) $staff['good_count'] ?></td>
                                    <td class="text-end"><?= (int) $staff['poor_count'] ?></td>
                                    <td class="text-end"><?= (int) $staff['very_poor_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="print-only print-report-detail">
                <div class="print-section-title">หัวข้อบริการที่ถูกกล่าวถึง</div>
                <table class="print-data-table">
                    <thead>
                        <tr>
                            <th>หัวข้อบริการ</th>
                            <th class="text-end">จำนวนครั้ง</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topic_summary)): ?>
                            <tr>
                                <td colspan="2" class="text-center text-muted">ยังไม่มีการระบุหัวข้อบริการในรอบปีนี้</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($topic_summary as $topic): ?>
                                <tr>
                                    <td><?= htmlspecialchars($topic['service_topic']) ?></td>
                                    <td class="text-end"><?= (int) $topic['topic_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="print-signature-block">
                <div class="print-section-title">การรับรองรายงาน</div>
                <table class="print-meta-table">
                    <tbody>
                        <tr>
                            <th style="width: 22%;">วันที่รายงาน</th>
                            <td style="width: 28%;"><?= htmlspecialchars($report_generated_at) ?></td>
                            <th style="width: 22%;">ปีการศึกษา</th>
                            <td style="width: 28%;"><?= htmlspecialchars($selected_academic_year) ?></td>
                        </tr>
                    </tbody>
                </table>
                <div class="print-signature-grid">
                    <div class="print-signature-card">
                        <div class="print-signature-title">ผู้จัดทำรายงาน</div>
                        <div class="print-signature-line">ลงชื่อ ...............................................................</div>
                    </div>
                    <div class="print-signature-card">
                        <div class="print-signature-title">ประธานกรรมการ / ผู้รับรอง</div>
                        <div class="print-signature-line">ลงชื่อ ...............................................................</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow-lg">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <div class="small text-uppercase fw-bold text-secondary mb-1">QR Access</div>
                        <h4 class="fw-bold mb-0">สแกนเพื่อเปิดรายงานหน้านี้</h4>
                    </div>
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="modal-body text-center pt-3">
                    <img id="qrPreviewImage" class="qr-preview mb-3" alt="QR code สำหรับรายงานสาธารณะ">
                    <div class="small text-muted mb-3">ใช้สำหรับแนบใน SAR หรือทำป้ายให้กรรมการสแกนเข้ามาดู dashboard รายปีได้ทันที</div>
                    <div class="input-group">
                        <input type="text" class="form-control" id="reportLinkField" readonly>
                        <button type="button" class="btn btn-dark" id="copyReportLinkBtn">
                            <i class="bi bi-copy"></i> คัดลอกลิงก์
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const publicReportUrl = window.location.href;
        const qrPreviewImage = document.getElementById('qrPreviewImage');
        const reportLinkField = document.getElementById('reportLinkField');
        const copyReportLinkBtn = document.getElementById('copyReportLinkBtn');

        if (qrPreviewImage) {
            qrPreviewImage.src = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' + encodeURIComponent(publicReportUrl);
        }
        if (reportLinkField) {
            reportLinkField.value = publicReportUrl;
        }
        if (copyReportLinkBtn && reportLinkField) {
            copyReportLinkBtn.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(reportLinkField.value);
                    copyReportLinkBtn.innerHTML = '<i class="bi bi-check2"></i> คัดลอกแล้ว';
                    setTimeout(() => {
                        copyReportLinkBtn.innerHTML = '<i class="bi bi-copy"></i> คัดลอกลิงก์';
                    }, 1800);
                } catch (error) {
                    reportLinkField.select();
                }
            });
        }
    </script>

    <?php if ($db_ready): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
        <script>
            const trendLabels = <?= json_encode($trend_labels, JSON_UNESCAPED_UNICODE) ?>;
            const trendCounts = <?= json_encode($trend_counts) ?>;
            const trendAvgScores = <?= json_encode($trend_avg_scores) ?>;
            const distributionLabels = <?= json_encode(array_keys($distribution), JSON_UNESCAPED_UNICODE) ?>;
            const distributionValues = <?= json_encode(array_values($distribution)) ?>;
            const staffChartLabels = <?= json_encode(array_map(function ($row) { return $row['full_name']; }, $staff_summary), JSON_UNESCAPED_UNICODE) ?>;
            const staffChartCounts = <?= json_encode(array_map(function ($row) { return (int) $row['rating_count']; }, $staff_summary)) ?>;
            const staffChartAvgScores = <?= json_encode(array_map(function ($row) { return (float) $row['avg_score']; }, $staff_summary)) ?>;

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
