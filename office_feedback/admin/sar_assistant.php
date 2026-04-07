<?php

require_once __DIR__ . '/auth.php';

officefb_admin_require_auth();
officefb_admin_start_session();

$state = officefb_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok']
    && officefb_table_exists($pdo, 'officefb_staff')
    && officefb_table_exists($pdo, 'officefb_ratings');
$error_message = $state['error'];
$flash = officefb_admin_consume_flash();
$settings_modal_open = false;

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
$staff_summary = [];
$topic_summary = [];
$trend_rows = [];
$distribution = [
    'Excellent' => 0,
    'Good' => 0,
    'Poor' => 0,
    'Very Poor' => 0,
];
$ai_settings = [
    'api_key' => '',
    'api_model' => 'gemini-2.5-flash',
];
$ai_ready = false;
$draft_result = [
    'executive_summary' => '',
    'strengths' => '',
    'improvements' => '',
    'pdca_recommendations' => '',
];
$draft_error = '';
$public_report_base_url = officefb_path('report.home', ['academic_year' => $selected_academic_year]);
$sar_compiled_text = '';
$current_api_key = '';
$current_api_model = 'gemini-2.5-flash';
$current_auto_pass_threshold = 80;

function officefb_threshold_badge_meta($value): array
{
    $value = max(0, min(100, (int) $value));
    if ($value < 60) {
        return ['class' => 'bg-danger', 'label' => 'ต่ำ'];
    }
    if ($value < 80) {
        return ['class' => 'bg-warning text-dark', 'label' => 'กลาง'];
    }
    return ['class' => 'bg-success', 'label' => 'สูง'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $pdo) {
    if ($_POST['action'] === 'save_ai_settings') {
        try {
            $api_key = trim((string) ($_POST['gemini_api_key'] ?? ''));
            $api_model = trim((string) ($_POST['gemini_api_model'] ?? 'gemini-2.5-flash'));
            $auto_pass_threshold = isset($_POST['ai_auto_pass_threshold']) ? (int) $_POST['ai_auto_pass_threshold'] : 80;
            $auto_pass_threshold = max(0, min(100, $auto_pass_threshold));

            if ($api_key !== '') {
                officefb_setting_set($pdo, 'officefb_gemini_api_key', $api_key);
            }

            officefb_setting_set($pdo, 'officefb_gemini_api_model', $api_model !== '' ? $api_model : 'gemini-2.5-flash');
            officefb_setting_set($pdo, 'officefb_ai_auto_pass_threshold', (string) $auto_pass_threshold);

            officefb_admin_flash('success', 'บันทึกการตั้งค่า AI Assistant ของโมดูล Office Feedback เรียบร้อยแล้ว');
        } catch (Throwable $e) {
            officefb_admin_flash('danger', 'บันทึกการตั้งค่า AI Assistant ไม่สำเร็จ: ' . $e->getMessage());
        }

        header('Location: ' . officefb_path('admin.sar', ['academic_year' => $selected_academic_year]));
        exit;
    }

    if ($_POST['action'] === 'delete_api_key') {
        try {
            officefb_setting_delete($pdo, 'officefb_gemini_api_key');
            officefb_admin_flash('success', 'ลบ Gemini API Key เรียบร้อยแล้ว');
        } catch (Throwable $e) {
            officefb_admin_flash('danger', 'ลบ Gemini API Key ไม่สำเร็จ: ' . $e->getMessage());
        }

        header('Location: ' . officefb_path('admin.sar', ['academic_year' => $selected_academic_year]));
        exit;
    }
}

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
        $trend_rows = $stmtTrend->fetchAll(PDO::FETCH_ASSOC);

        $stmtStaff = $pdo->prepare("
            SELECT s.full_name, s.position_name,
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
            GROUP BY s.id, s.full_name, s.position_name
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
            LIMIT 10
        ");
        $stmtTopics->execute([':academic_year' => $selected_academic_year]);
        $topic_summary = $stmtTopics->fetchAll(PDO::FETCH_ASSOC);

        $storedApiKey = (string) officefb_setting_get($pdo, 'officefb_gemini_api_key', (string) officefb_config('OFFICEFB_GEMINI_API_KEY', ''));
        $storedApiModel = (string) officefb_setting_get($pdo, 'officefb_gemini_api_model', (string) officefb_config('OFFICEFB_GEMINI_API_MODEL', 'gemini-2.5-flash'));
        $storedThreshold = (int) officefb_setting_get($pdo, 'officefb_ai_auto_pass_threshold', (string) officefb_config('OFFICEFB_AI_AUTO_PASS_THRESHOLD', '80'));

        if (trim($storedApiModel) !== '') {
            $ai_settings['api_model'] = str_replace('models/', '', $storedApiModel);
            $current_api_model = $ai_settings['api_model'];
        }

        $ai_settings['api_key'] = $storedApiKey;
        $current_api_key = $storedApiKey;
        $current_auto_pass_threshold = max(0, min(100, $storedThreshold));

        $ai_ready = trim($ai_settings['api_key']) !== '' && trim($ai_settings['api_key']) !== 'mock';
    } catch (Throwable $e) {
        $db_ready = false;
        $error_message = $e->getMessage();
    }
}

if ($current_api_model === '') {
    $current_api_model = $ai_settings['api_model'];
}

$threshold_badge = officefb_threshold_badge_meta($current_auto_pass_threshold);
$settings_modal_open = isset($_GET['open_ai_settings']) && $_GET['open_ai_settings'] === '1';

$sar_sections = [
    'executive_summary' => [
        'title' => 'บทสรุปผู้บริหาร',
        'anchor' => 'summary-kpi',
    ],
    'strengths' => [
        'title' => 'จุดเด่น',
        'anchor' => 'staff-overview',
    ],
    'improvements' => [
        'title' => 'จุดที่ควรพัฒนา',
        'anchor' => 'topic-overview',
    ],
    'pdca_recommendations' => [
        'title' => 'ข้อเสนอเชิง PDCA',
        'anchor' => 'trend-annual',
    ],
];

function officefb_compile_sar_text(array $draft_result, array $sar_sections): string
{
    $parts = [];
    foreach ($sar_sections as $key => $meta) {
        $text = isset($draft_result[$key]) ? trim((string) $draft_result[$key]) : '';
        if ($text !== '') {
            $parts[] = $meta['title'] . "\n" . $text;
        }
    }

    return implode("\n\n", $parts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_sar' && $db_ready) {
    $selected_academic_year = isset($_POST['academic_year']) ? trim((string) $_POST['academic_year']) : $selected_academic_year;

    if (!$ai_ready) {
        $draft_error = 'ยังไม่พบ Gemini API Key ของโมดูล Office Feedback กรุณาไปตั้งค่า AI Assistant ของโมดูลนี้ก่อน';
    } else {
        $report_data = [
            'academic_year' => $selected_academic_year,
            'summary' => $summary,
            'distribution' => $distribution,
            'trend_rows' => $trend_rows,
            'staff_summary' => array_slice($staff_summary, 0, 8),
            'topic_summary' => $topic_summary,
        ];

        $prompt = "
คุณคือผู้ช่วยผู้เชี่ยวชาญด้าน AUN-QA และการเขียน SAR
กรุณาใช้ข้อมูลสถิติการประเมินการให้บริการของสำนักงานคณะด้านล่าง เพื่อร่างข้อความภาษาไทยอย่างเป็นทางการ กระชับ อ่านง่าย และพร้อมให้ผู้ดูแลนำไปปรับแก้ต่อใน SAR

ให้ส่งผลลัพธ์เป็น JSON เท่านั้น ตามโครงสร้าง:
{
  \"executive_summary\": \"...\",
  \"strengths\": \"...\",
  \"improvements\": \"...\",
  \"pdca_recommendations\": \"...\"
}

หลักเกณฑ์:
- เขียนโดยอิงข้อมูลจริงที่ให้มาเท่านั้น
- ระบุทั้งคะแนนเฉลี่ยและปริมาณการประเมินเพื่อกันการตีความเกินจริง
- ถ้าจำนวนข้อมูลยังน้อย ให้กล่าวอย่างระมัดระวังว่าเป็นแนวโน้มเบื้องต้น
- strengths ให้สรุปจุดเด่นของงานบริการ
- improvements ให้สรุปจุดที่ควรพัฒนา
- pdca_recommendations ให้เขียนเชิงวงจรพัฒนาอย่างต่อเนื่องสำหรับรอบถัดไป
- ห้ามใส่ markdown code fence
";

        $content = json_encode($report_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($ai_settings['api_model']) . ":generateContent?key=" . $ai_settings['api_key'];
        $payload = [
            'contents' => [
                ['parts' => [
                    ['text' => $prompt],
                    ['text' => $content],
                ]]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json'
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $response = curl_exec($ch);
        if ($response === false) {
            $draft_error = 'ไม่สามารถเชื่อมต่อ Gemini API ได้: ' . curl_error($ch);
        } else {
            $decoded = json_decode($response, true);
            if (isset($decoded['error'])) {
                $draft_error = 'Gemini API Error: ' . ($decoded['error']['message'] ?? 'Unknown error');
            } elseif (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
                $json_str = trim(str_replace(['```json', '```'], '', $decoded['candidates'][0]['content']['parts'][0]['text']));
                $result = json_decode($json_str, true);
                if (is_array($result)) {
                    $draft_result['executive_summary'] = isset($result['executive_summary']) ? (string) $result['executive_summary'] : '';
                    $draft_result['strengths'] = isset($result['strengths']) ? (string) $result['strengths'] : '';
                    $draft_result['improvements'] = isset($result['improvements']) ? (string) $result['improvements'] : '';
                    $draft_result['pdca_recommendations'] = isset($result['pdca_recommendations']) ? (string) $result['pdca_recommendations'] : '';
                } else {
                    $draft_error = 'AI ตอบกลับมาในรูปแบบที่แปลง JSON ไม่ได้';
                }
            } else {
                $draft_error = 'AI ไม่ได้ส่งผลลัพธ์กลับมาในรูปแบบที่คาดไว้';
            }
        }
        curl_close($ch);
    }
}

$sar_compiled_text = officefb_compile_sar_text($draft_result, $sar_sections);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Office Feedback SAR Assistant</title>
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
            padding: 2.3rem 0 1.8rem;
            margin-bottom: 1.5rem;
        }
        .panel-card {
            border: 0;
            border-radius: 24px;
            box-shadow: 0 18px 40px rgba(59, 34, 17, 0.09);
        }
        .draft-box {
            min-height: 180px;
            white-space: pre-wrap;
            background: rgba(255,255,255,0.92);
            border-radius: 20px;
            padding: 1rem;
            border: 1px solid rgba(71, 48, 24, 0.08);
        }
        .metric-card {
            border-radius: 24px;
            padding: 1.1rem;
            height: 100%;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248, 242, 234, 0.96));
        }
        .metric-number {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: .2rem;
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
        .draft-actions {
            display: flex;
            gap: .75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        .settings-modal .modal-content {
            border: 0;
            border-radius: 24px;
            box-shadow: 0 24px 48px rgba(59, 34, 17, 0.16);
        }
        .settings-modal .modal-header {
            background: linear-gradient(135deg, #473018 0%, #8d5b2a 100%);
            color: white;
            border-radius: 24px 24px 0 0;
        }
    </style>
</head>
<body>
    <div class="hero">
        <div class="container">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="small text-uppercase fw-bold opacity-75 mb-2">AI Drafting</div>
                    <h1 class="fw-bold mb-2">SAR Assistant สำหรับ Office Feedback</h1>
                    <p class="mb-0 opacity-75">ใช้ AI ช่วยร่างข้อความรายงานประกอบ SAR จาก dashboard รายปีของระบบประเมินการให้บริการสำนักงานคณะ</p>
                </div>
                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <button type="button" class="btn btn-outline-light rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#aiSettingsModal">
                        <i class="bi bi-gear-fill"></i> ตั้งค่า AI Assistant
                    </button>
                    <a href="<?= htmlspecialchars(officefb_path('report.home', ['academic_year' => $selected_academic_year])) ?>" class="btn btn-light rounded-pill px-4">
                        <i class="bi bi-qr-code"></i> เปิดรายงานสาธารณะ
                    </a>
                    <a href="<?= htmlspecialchars(officefb_path('admin.home')) ?>" class="btn btn-outline-light rounded-pill px-4">
                        <i class="bi bi-arrow-left"></i> กลับ Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> panel-card p-3">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$db_ready): ?>
            <div class="alert alert-danger panel-card p-4">
                <h4 class="fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> ระบบยังไม่พร้อมใช้งาน</h4>
                <p class="mb-2">ไม่สามารถอ่านข้อมูลของโมดูล Office Feedback ได้ในขณะนี้</p>
                <?php if ($error_message !== ''): ?>
                    <code><?= htmlspecialchars($error_message) ?></code>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if (!$ai_ready): ?>
                <div class="alert alert-warning panel-card p-4">
                    <h4 class="fw-bold"><i class="bi bi-stars"></i> ยังไม่พบการตั้งค่า AI Assistant</h4>
                    <p class="mb-3">หน้านี้ใช้ Gemini API ของโมดูล Office Feedback โดยเฉพาะ เก็บแยกใน `officefb_settings` เพื่อให้ deploy และดูแลระบบนี้ได้แบบ standalone</p>
                    <button type="button" class="btn btn-dark rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#aiSettingsModal">
                        <i class="bi bi-gear-fill"></i> ไปตั้งค่า AI Assistant ของโมดูลนี้
                    </button>
                </div>
            <?php endif; ?>

            <?php if ($draft_error !== ''): ?>
                <div class="alert alert-danger panel-card p-4"><?= htmlspecialchars($draft_error) ?></div>
            <?php endif; ?>

            <div class="card panel-card p-4 mb-4">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-lg-4">
                        <label class="form-label fw-bold">ปีการศึกษา</label>
                        <select name="academic_year" class="form-select">
                            <?php foreach ($available_academic_years as $year): ?>
                                <option value="<?= htmlspecialchars($year) ?>" <?= $selected_academic_year === (string) $year ? 'selected' : '' ?>><?= htmlspecialchars($year) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4">
                        <div class="small text-muted">โมเดลที่ตั้งค่าไว้: <strong><?= htmlspecialchars($ai_settings['api_model']) ?></strong></div>
                        <div class="small text-muted">สถานะ AI: <?= $ai_ready ? '<span class="text-success fw-bold">พร้อมใช้งาน</span>' : '<span class="text-danger fw-bold">ยังไม่พร้อม</span>' ?></div>
                        <div class="small text-muted">Threshold ของโมดูลนี้: <span class="badge <?= htmlspecialchars($threshold_badge['class']) ?>" id="ai_auto_pass_threshold_badge_page"><?= htmlspecialchars($threshold_badge['label']) ?> <?= (int) $current_auto_pass_threshold ?>%</span></div>
                    </div>
                    <div class="col-lg-4 d-grid d-md-flex justify-content-md-end gap-2">
                        <button type="submit" class="btn btn-outline-dark rounded-pill px-4">
                            <i class="bi bi-funnel-fill"></i> เปลี่ยนปีการศึกษา
                        </button>
                    </div>
                </form>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6 col-xl-3">
                    <div class="metric-card panel-card">
                        <div class="text-secondary small fw-bold">จำนวนการประเมินทั้งปี</div>
                        <div class="metric-number"><?= number_format($summary['period_total']) ?></div>
                        <div class="text-secondary">ปีการศึกษา <?= htmlspecialchars($selected_academic_year) ?></div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="metric-card panel-card">
                        <div class="text-secondary small fw-bold">คะแนนเฉลี่ยทั้งปี</div>
                        <div class="metric-number"><?= number_format($summary['period_avg'], 2) ?></div>
                        <div class="text-secondary">ค่าเฉลี่ยจากสเกล 1-4</div>
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
                        <div class="text-secondary small fw-bold">หัวข้อเด่น</div>
                        <div class="metric-number"><?= number_format($summary['top_topic_count']) ?></div>
                        <div class="text-secondary"><?= htmlspecialchars($summary['top_topic_name']) ?></div>
                    </div>
                </div>
            </div>

            <div class="card panel-card p-4 mb-4">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <h3 class="fw-bold mb-2">ข้อมูลตั้งต้นที่ AI จะใช้</h3>
                        <div class="small text-muted mb-3">AI จะใช้สถิติรอบปีนี้ เช่น คะแนนเฉลี่ย จำนวนการประเมิน แนวโน้มรายเดือน เจ้าหน้าที่ที่มี feedback และหัวข้อบริการที่ถูกเลือกบ่อย เพื่อร่างข้อความสำหรับ SAR</div>
                        <div class="d-flex gap-2 flex-wrap mb-3">
                            <span class="rating-chip"><i class="bi bi-person-badge-fill"></i> ผู้ถูกประเมินมากที่สุด: <?= htmlspecialchars($summary['top_staff_name']) ?> (<?= (int) $summary['top_staff_count'] ?>)</span>
                            <span class="rating-chip"><i class="bi bi-tags-fill"></i> หัวข้อที่พบบ่อย: <?= htmlspecialchars($summary['top_topic_name']) ?></span>
                        </div>
                        <div class="small text-muted">
                            แนวโน้มรายเดือน:
                            <?php if (empty($trend_rows)): ?>
                                ยังไม่มีข้อมูลรายเดือนในรอบปีนี้
                            <?php else: ?>
                                <?php foreach ($trend_rows as $index => $row): ?>
                                    <?= $index > 0 ? ' | ' : '' ?><?= htmlspecialchars(date('m/Y', strtotime($row['month_key'] . '-01'))) ?>: <?= (int) $row['total_count'] ?> ครั้ง / เฉลี่ย <?= number_format((float) $row['avg_score'], 2) ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <h3 class="fw-bold mb-2">สั่งให้ AI ร่าง SAR</h3>
                        <form method="POST" class="d-grid gap-3">
                            <input type="hidden" name="action" value="generate_sar">
                            <input type="hidden" name="academic_year" value="<?= htmlspecialchars($selected_academic_year) ?>">
                            <button type="submit" class="btn btn-dark btn-lg rounded-pill fw-bold" <?= $ai_ready ? '' : 'disabled' ?>>
                                <i class="bi bi-stars"></i> ให้ AI ร่างข้อความรายงาน
                            </button>
                            <div class="small text-muted">AI จะร่าง 4 ส่วน: บทสรุปผู้บริหาร, จุดเด่น, จุดที่ควรพัฒนา, และข้อเสนอเชิง PDCA สำหรับรอบถัดไป</div>
                        </form>
                        <div class="draft-actions">
                            <button type="button" class="btn btn-outline-dark rounded-pill px-4" id="copySarAllBtn" <?= $sar_compiled_text !== '' ? '' : 'disabled' ?>>
                                <i class="bi bi-copy"></i> คัดลอกข้อความ SAR ทั้งหมด
                            </button>
                            <a href="<?= htmlspecialchars($public_report_base_url) ?>" class="btn btn-outline-secondary rounded-pill px-4" target="_blank">
                                <i class="bi bi-box-arrow-up-right"></i> เปิดรายงานสาธารณะ
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <?php foreach ($sar_sections as $key => $meta): ?>
                    <?php
                    $default_text = [
                        'executive_summary' => 'กดปุ่มให้ AI ร่างข้อความก่อน ระบบจะแสดงบทสรุปผู้บริหารในกล่องนี้',
                        'strengths' => 'จุดเด่นของการให้บริการจะถูกสรุปในกล่องนี้',
                        'improvements' => 'ประเด็นที่ควรพัฒนาจะถูกสรุปในกล่องนี้',
                        'pdca_recommendations' => 'ข้อเสนอเชิง PDCA สำหรับรอบถัดไปจะถูกสรุปในกล่องนี้',
                    ];
                    $section_link_labels = [
                        'executive_summary' => 'ดูส่วนสรุปภาพรวมในรายงาน',
                        'strengths' => 'ดูสถิติรายเจ้าหน้าที่',
                        'improvements' => 'ดูหัวข้อบริการที่ถูกกล่าวถึง',
                        'pdca_recommendations' => 'ดูแนวโน้มรายปี',
                    ];
                    $section_text = trim((string) ($draft_result[$key] ?? ''));
                    ?>
                    <div class="col-lg-6">
                        <div class="panel-card p-4 h-100">
                            <h3 class="fw-bold mb-3"><?= htmlspecialchars($meta['title']) ?></h3>
                            <div class="draft-box"><?= htmlspecialchars($section_text !== '' ? $section_text : $default_text[$key]) ?></div>
                            <div class="draft-actions">
                                <a href="<?= htmlspecialchars($public_report_base_url . '#' . $meta['anchor']) ?>" class="btn btn-outline-secondary rounded-pill" target="_blank">
                                    <i class="bi bi-box-arrow-up-right"></i> <?= htmlspecialchars($section_link_labels[$key]) ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal fade settings-modal" id="aiSettingsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-gear-fill"></i> ตั้งค่า AI Assistant ของ Office Feedback</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">ระบบ AI ปัจจุบันที่ใช้ประมวลผล (Model)</label>
                            <select class="form-select" name="gemini_api_model">
                                <?php
                                $model_options = [
                                    'gemini-2.5-flash',
                                    'gemini-2.5-flash-lite',
                                    'gemini-2.5-pro',
                                ];
                                if (!in_array($current_api_model, $model_options, true)) {
                                    array_unshift($model_options, $current_api_model);
                                }
                                foreach ($model_options as $model_option):
                                ?>
                                    <option value="<?= htmlspecialchars($model_option) ?>" <?= $current_api_model === $model_option ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($model_option) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">ค่าชุดนี้จะถูกใช้ร่วมกับระบบ AUN-QA ฝั่ง verification ด้วย</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">เกณฑ์คะแนนผ่านอัตโนมัติของ AI (%)</label>
                            <input type="number" class="form-control" id="ai_auto_pass_threshold_input" name="ai_auto_pass_threshold" min="0" max="100" step="1" value="<?= (int) $current_auto_pass_threshold ?>">
                            <div class="mt-2 small text-muted">
                                Preview:
                                <span class="badge <?= htmlspecialchars($threshold_badge['class']) ?>" id="ai_auto_pass_threshold_badge_modal"><?= htmlspecialchars($threshold_badge['label']) ?> <?= (int) $current_auto_pass_threshold ?>%</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Gemini API Key</label>
                            <?php if ($current_api_key !== ''): ?>
                                <?php $masked_key = substr($current_api_key, 0, 10) . '****************' . substr($current_api_key, -5); ?>
                                <div class="alert alert-success p-2 small mb-2 d-flex justify-content-between align-items-center gap-2">
                                    <div>
                                        <i class="bi bi-shield-check"></i> คีย์ปัจจุบัน: <strong><?= htmlspecialchars($masked_key) ?></strong>
                                    </div>
                                    <button type="submit" name="action" value="delete_api_key" class="btn btn-sm btn-danger fw-bold" onclick="return confirm('ต้องการลบ API Key ปัจจุบันใช่หรือไม่');">
                                        <i class="bi bi-trash-fill"></i> ลบคีย์
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning p-2 small mb-2">
                                    <i class="bi bi-exclamation-circle-fill"></i> ยังไม่มี API Key ในระบบ
                                </div>
                            <?php endif; ?>
                            <input type="password" class="form-control" name="gemini_api_key" placeholder="วาง API Key อันใหม่ที่นี่ (AIzaSy...)" <?= $current_api_key === '' ? 'required' : '' ?>>
                            <small class="text-muted">ถ้าใส่คีย์ใหม่ ระบบจะอัปเดตชุดตั้งค่ากลางให้ทุกหน้าที่ใช้ AI ทันที</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" name="action" value="save_ai_settings" class="btn btn-dark fw-bold">
                            <i class="bi bi-save"></i> บันทึกการตั้งค่า
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const savedAiAutoPassThreshold = <?= (int) $current_auto_pass_threshold ?>;
        const sarCompiledText = <?= json_encode($sar_compiled_text, JSON_UNESCAPED_UNICODE) ?>;
        const copySarAllBtn = document.getElementById('copySarAllBtn');

        if (copySarAllBtn) {
            copySarAllBtn.addEventListener('click', async () => {
                if (!sarCompiledText) {
                    return;
                }
                try {
                    await navigator.clipboard.writeText(sarCompiledText);
                    copySarAllBtn.innerHTML = '<i class="bi bi-check2"></i> คัดลอกแล้ว';
                    setTimeout(() => {
                        copySarAllBtn.innerHTML = '<i class="bi bi-copy"></i> คัดลอกข้อความ SAR ทั้งหมด';
                    }, 1800);
                } catch (error) {
                    alert('คัดลอกอัตโนมัติไม่สำเร็จ กรุณาลองอีกครั้ง');
                }
            });
        }

        function normalizeAiAutoPassThreshold(value) {
            const parsed = parseInt(value, 10);
            if (Number.isNaN(parsed)) {
                return savedAiAutoPassThreshold;
            }
            return Math.max(0, Math.min(100, parsed));
        }

        function getAiAutoPassBadgeMeta(value) {
            if (value < 60) {
                return { className: 'bg-danger', label: 'ต่ำ' };
            }
            if (value < 80) {
                return { className: 'bg-warning text-dark', label: 'กลาง' };
            }
            return { className: 'bg-success', label: 'สูง' };
        }

        function updateAiAutoPassBadges(value) {
            const threshold = normalizeAiAutoPassThreshold(value);
            const meta = getAiAutoPassBadgeMeta(threshold);
            [
                document.getElementById('ai_auto_pass_threshold_badge_modal'),
                document.getElementById('ai_auto_pass_threshold_badge_page')
            ].forEach((badge) => {
                if (!badge) {
                    return;
                }
                badge.className = `badge ${meta.className}`;
                badge.textContent = `${meta.label} ${threshold}%`;
            });
        }

        const aiAutoPassThresholdInput = document.getElementById('ai_auto_pass_threshold_input');
        if (aiAutoPassThresholdInput) {
            aiAutoPassThresholdInput.addEventListener('input', () => {
                updateAiAutoPassBadges(aiAutoPassThresholdInput.value);
            });
        }
        updateAiAutoPassBadges(savedAiAutoPassThreshold);

        <?php if ($settings_modal_open): ?>
        window.addEventListener('load', () => {
            const modalEl = document.getElementById('aiSettingsModal');
            if (modalEl) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
