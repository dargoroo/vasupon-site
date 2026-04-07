<?php

// verification_board.php - กระดานหน้าสำหรับกรรมการประเมินหลักฐานลายนิ้วมือการทวนสอบ (Tracking Dashboard)
require_once __DIR__ . '/bootstrap.php';

$pdo = null;
$mock_mode = false;
$bootstrap_error_message = '';
$safe_mode = false;
$state = $safe_mode ? app_bootstrap_state() : aunqa_bootstrap_state();
$pdo = $state['pdo'];

if (!$state['ok']) {
    $pdo = null;
    $mock_mode = true;
    $bootstrap_error_message = $state['error'];
}

$flash_message = '';
$flash_type = 'success';

if (isset($_GET['flash'])) {
    $flash_message = trim((string) $_GET['flash']);
    $flash_type = (isset($_GET['flash_type']) && $_GET['flash_type'] === 'danger') ? 'danger' : 'success';
}

function board_table_columns($pdo, $table_name) {
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table_name}`");
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['Field'])) {
                $columns[] = $row['Field'];
            }
        }
        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

function board_has_column($pdo, $table_name, $column_name) {
    return app_column_exists($pdo, $table_name, $column_name, 'aunqa');
}

function board_delete_verification_record($pdo, $vid) {
    $child_delete_sql = [
        "DELETE FROM aunqa_pdca_links WHERE verification_id = :vid",
        "DELETE FROM aunqa_pdca_actions WHERE pdca_issue_id IN (SELECT id FROM aunqa_pdca_issues WHERE verification_id = :vid)",
        "DELETE FROM aunqa_pdca_issues WHERE verification_id = :vid",
        "DELETE FROM aunqa_clo_evaluations WHERE verification_id = :vid",
        "DELETE FROM aunqa_verification_activities WHERE verification_id = :vid",
        "DELETE FROM aunqa_verification_plo_coverage WHERE verification_id = :vid",
        "DELETE FROM aunqa_verification_bloom WHERE verification_id = :vid",
        "DELETE FROM aunqa_verification_matrix WHERE verification_id = :vid",
        "DELETE FROM aunqa_verification_clo_details WHERE verification_id = :vid",
        "DELETE FROM aunqa_verification_checklists WHERE verification_id = :vid"
    ];

    foreach ($child_delete_sql as $sql) {
        try {
            $stmtChildDelete = $pdo->prepare($sql);
            $stmtChildDelete->execute([':vid' => $vid]);
        } catch (PDOException $e) {
            // บางตารางอาจยังไม่มีในฐานเก่า ให้ข้ามได้
        }
    }

    $stmtDelete = $pdo->prepare("DELETE FROM aunqa_verification_records WHERE id=:vid");
    $stmtDelete->execute([':vid' => $vid]);

    return $stmtDelete->rowCount();
}

// 1. รับค่ากรณี POST จากหน้า verification.php
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_selection') {
    $target_year = $_POST['year'];
    $selected_courses = isset($_POST['selected_courses']) ? $_POST['selected_courses'] : [];

    if ($pdo && !empty($selected_courses)) {
        // วนลูปเซฟลงตาราง `aunqa_verification_records`
        $stmtCreate = $pdo->prepare("INSERT INTO aunqa_verification_records (year, semester, course_code, course_name, instructor, tqf3_link, tqf5_link, verification_status) VALUES (:y, :s, :code, :name, :inst, :l3, :l5, 'รอรับเอกสาร')");

        // เช็คก่อนว่าเซฟการทวนสอบวิชานั้นไปแล้วหรือยังในปีนั้นเทอมนั้น หากยังค่อยเซฟ
        $stmtCheck = $pdo->prepare("SELECT id FROM aunqa_verification_records WHERE year = :y AND semester = :s AND course_code = :c");

        foreach ($selected_courses as $course_json_str) {
            $course = json_decode($course_json_str, true);
            if ($course) {
                // Check exist
                $stmtCheck->execute([
                    ':y' => $target_year,
                    ':s' => $course['semester'],
                    ':c' => $course['code']
                ]);
                $exists = $stmtCheck->fetchColumn();

                if (!$exists) {
                    $stmtCreate->execute([
                        ':y' => $target_year,
                        ':s' => $course['semester'],
                        ':code' => $course['code'],
                        ':name' => $course['name'],
                        ':inst' => $course['instructor'],
                        ':l3' => isset($course['tqf3_link']) ? $course['tqf3_link'] : '',
                        ':l5' => isset($course['tqf5_link']) ? $course['tqf5_link'] : ''
                    ]);
                    $new_id = $pdo->lastInsertId();

                    // สร้าง record เปล่าใน `aunqa_verification_checklists` รอไว้เลย
                    $stmtChecklist = $pdo->prepare("INSERT INTO aunqa_verification_checklists (verification_id) VALUES (:vid)");
                    $stmtChecklist->execute([':vid' => $new_id]);
                }
            }
        }
    }
}

// 2. รับค่าบันทึกการประเมิน Checklist
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_evaluation') {
    if ($pdo) {
        $vid = $_POST['verification_id'];
        $is_override_mode = isset($_POST['human_override_mode']) && $_POST['human_override_mode'] === '1';
        $strength = $_POST['reviewer_strength'];
        $improvement = $_POST['reviewer_improvement'];
        $pdca_status = isset($_POST['pdca_status']) ? $_POST['pdca_status'] : 'not_started';
        $pdca_resolution_percent = isset($_POST['pdca_resolution_percent']) ? (float) $_POST['pdca_resolution_percent'] : 0;
        $pdca_resolution_percent = max(0, min(100, $pdca_resolution_percent));
        $pdca_last_year_summary = isset($_POST['pdca_last_year_summary']) ? $_POST['pdca_last_year_summary'] : '';
        $pdca_current_action = isset($_POST['pdca_current_action']) ? $_POST['pdca_current_action'] : '';
        $pdca_evidence_note = isset($_POST['pdca_evidence_note']) ? $_POST['pdca_evidence_note'] : '';

        if ($is_override_mode) {
            $chk_bloom = isset($_POST['chk_bloom']) ? 1 : 0;
            $chk_map = isset($_POST['chk_map']) ? 1 : 0;
            $chk_activity = isset($_POST['chk_activity']) ? 1 : 0;
        } else {
            $stmtCurrent = $pdo->prepare("SELECT check_clo_verb, check_clo_plo_map, check_class_activity FROM aunqa_verification_checklists WHERE verification_id=:vid");
            $stmtCurrent->execute([':vid' => $vid]);
            $currentChecks = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

            $chk_bloom = isset($currentChecks['check_clo_verb']) ? (int) $currentChecks['check_clo_verb'] : 0;
            $chk_map = isset($currentChecks['check_clo_plo_map']) ? (int) $currentChecks['check_clo_plo_map'] : 0;
            $chk_activity = isset($currentChecks['check_class_activity']) ? (int) $currentChecks['check_class_activity'] : 0;
        }

        $stmtUpdateCL = $pdo->prepare("UPDATE aunqa_verification_checklists SET check_clo_verb=:c1, check_clo_plo_map=:c2, check_class_activity=:c3, reviewer_strength=:str, reviewer_improvement=:imp, pdca_status=:pdca_status, pdca_resolution_percent=:pdca_pct, pdca_last_year_summary=:pdca_last, pdca_current_action=:pdca_action, pdca_evidence_note=:pdca_note WHERE verification_id=:vid");
        $stmtUpdateCL->execute([
            ':c1' => $chk_bloom,
            ':c2' => $chk_map,
            ':c3' => $chk_activity,
            ':str' => $strength,
            ':imp' => $improvement,
            ':pdca_status' => $pdca_status,
            ':pdca_pct' => $pdca_resolution_percent,
            ':pdca_last' => $pdca_last_year_summary,
            ':pdca_action' => $pdca_current_action,
            ':pdca_note' => $pdca_evidence_note,
            ':vid' => $vid
        ]);

        // อัปเดต State ถ้าประเมินครบ ก็กลายเป็น 'ทวนสอบเสร็จสิ้น'
        if ($chk_bloom && $chk_map && $chk_activity) {
            $stmtState = $pdo->prepare("UPDATE aunqa_verification_records SET verification_status = 'ผ่านการทวนสอบ' WHERE id=:vid");
            $stmtState->execute([':vid' => $vid]);
        } else {
            $stmtState = $pdo->prepare("UPDATE aunqa_verification_records SET verification_status = 'กำลังตรวจสอบ' WHERE id=:vid");
            $stmtState->execute([':vid' => $vid]);
        }
    }
}


// 3. รับค่าการลบรายวิชา
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_evaluation') {
    if ($pdo) {
        $vid = isset($_POST['verification_id']) ? (int) $_POST['verification_id'] : 0;
        $redirect_params = [];
        if (isset($_GET['f_year']) && $_GET['f_year'] !== '') {
            $redirect_params['f_year'] = (string) $_GET['f_year'];
        }
        if (isset($_GET['f_sem']) && $_GET['f_sem'] !== '') {
            $redirect_params['f_sem'] = (string) $_GET['f_sem'];
        }

        if ($vid <= 0) {
            $redirect_params['flash'] = 'ไม่พบรหัสรายวิชาที่ต้องการลบ';
            $redirect_params['flash_type'] = 'danger';
        } else {
            try {
                $pdo->beginTransaction();
                $deleted_count = board_delete_verification_record($pdo, $vid);

                if ($deleted_count > 0) {
                    $pdo->commit();
                    $redirect_params['flash'] = 'ลบรายวิชาออกจากกระดานทวนสอบเรียบร้อยแล้ว';
                    $redirect_params['flash_type'] = 'success';
                } else {
                    $pdo->rollBack();
                    $redirect_params['flash'] = 'ไม่พบข้อมูลรายวิชาที่ต้องการลบ หรือรายการอาจถูกลบไปก่อนแล้ว';
                    $redirect_params['flash_type'] = 'danger';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $redirect_params['flash'] = 'ลบรายวิชาไม่สำเร็จ: ' . $e->getMessage();
                $redirect_params['flash_type'] = 'danger';
            }
        }

        header('Location: verification_board.php' . (!empty($redirect_params) ? '?' . http_build_query($redirect_params) : ''));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_all_evaluations') {
    if ($pdo) {
        $filter_year_post = isset($_POST['f_year']) ? trim((string) $_POST['f_year']) : '';
        $filter_sem_post = isset($_POST['f_sem']) ? trim((string) $_POST['f_sem']) : '';
        $redirect_params = [];

        if ($filter_year_post !== '') {
            $redirect_params['f_year'] = $filter_year_post;
        }
        if ($filter_sem_post !== '') {
            $redirect_params['f_sem'] = $filter_sem_post;
        }

        try {
            $where_clauses = [];
            $params = [];

            if ($filter_year_post !== '') {
                $where_clauses[] = "year = :f_year";
                $params[':f_year'] = $filter_year_post;
            }
            if ($filter_sem_post !== '') {
                $where_clauses[] = "semester = :f_sem";
                $params[':f_sem'] = $filter_sem_post;
            }

            $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
            $stmtIds = $pdo->prepare("SELECT id FROM aunqa_verification_records {$where_sql}");
            $stmtIds->execute($params);
            $ids = array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN));

            if (empty($ids)) {
                $redirect_params['flash'] = 'ไม่พบรายการในมุมมองปัจจุบันให้ลบ';
                $redirect_params['flash_type'] = 'danger';
            } else {
                $pdo->beginTransaction();
                $deleted_total = 0;
                foreach ($ids as $id) {
                    $deleted_total += board_delete_verification_record($pdo, $id);
                }
                $pdo->commit();

                $redirect_params['flash'] = "ลบรายการประเมินทั้งหมด {$deleted_total} รายการจากมุมมองปัจจุบันเรียบร้อยแล้ว";
                $redirect_params['flash_type'] = 'success';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $redirect_params['flash'] = 'ลบทั้งหมดไม่สำเร็จ: ' . $e->getMessage();
            $redirect_params['flash_type'] = 'danger';
        }

        header('Location: verification_board.php' . (!empty($redirect_params) ? '?' . http_build_query($redirect_params) : ''));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_year_evaluations') {
    if ($pdo) {
        $target_year = isset($_POST['target_year']) ? trim((string) $_POST['target_year']) : '';
        $redirect_params = [];

        if ($target_year === '') {
            $redirect_params['flash'] = 'กรุณาระบุปีการศึกษาที่ต้องการลบ';
            $redirect_params['flash_type'] = 'danger';
        } else {
            try {
                $stmtIds = $pdo->prepare("SELECT id FROM aunqa_verification_records WHERE year = :target_year");
                $stmtIds->execute([':target_year' => $target_year]);
                $ids = array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN));

                if (empty($ids)) {
                    $redirect_params['flash'] = "ไม่พบรายการของปีการศึกษา {$target_year} ให้ลบ";
                    $redirect_params['flash_type'] = 'danger';
                } else {
                    $pdo->beginTransaction();
                    $deleted_total = 0;
                    foreach ($ids as $id) {
                        $deleted_total += board_delete_verification_record($pdo, $id);
                    }
                    $pdo->commit();

                    $redirect_params['flash'] = "ลบรายการประเมินทั้งหมดของปีการศึกษา {$target_year} จำนวน {$deleted_total} รายการเรียบร้อยแล้ว";
                    $redirect_params['flash_type'] = 'success';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $redirect_params['flash'] = 'ลบทั้งปีไม่สำเร็จ: ' . $e->getMessage();
                $redirect_params['flash_type'] = 'danger';
            }
        }

        header('Location: verification_board.php' . (!empty($redirect_params) ? '?' . http_build_query($redirect_params) : ''));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_all_years_evaluations') {
    if ($pdo) {
        $redirect_params = [];

        try {
            $stmtIds = $pdo->query("SELECT id FROM aunqa_verification_records");
            $ids = array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN));

            if (empty($ids)) {
                $redirect_params['flash'] = 'ไม่พบรายการประเมินในระบบให้ลบ';
                $redirect_params['flash_type'] = 'danger';
            } else {
                $pdo->beginTransaction();
                $deleted_total = 0;
                foreach ($ids as $id) {
                    $deleted_total += board_delete_verification_record($pdo, $id);
                }
                $pdo->commit();

                $redirect_params['flash'] = "ลบรายการประเมินทั้งหมดทุกปีจำนวน {$deleted_total} รายการเรียบร้อยแล้ว";
                $redirect_params['flash_type'] = 'success';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $redirect_params['flash'] = 'ลบทั้งหมดทุกปีไม่สำเร็จ: ' . $e->getMessage();
            $redirect_params['flash_type'] = 'danger';
        }

        header('Location: verification_board.php' . (!empty($redirect_params) ? '?' . http_build_query($redirect_params) : ''));
        exit;
    }
}

// 4. บันทึกการตั้งค่า AI
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'save_settings' && $pdo) {
        $api_key = trim($_POST['gemini_api_key']);
        $api_model = isset($_POST['gemini_api_model']) ? $_POST['gemini_api_model'] : 'gemini-2.5-flash';
        $auto_pass_threshold = isset($_POST['ai_auto_pass_threshold']) ? (int) $_POST['ai_auto_pass_threshold'] : 80;
        if ($auto_pass_threshold < 0)
            $auto_pass_threshold = 0;
        if ($auto_pass_threshold > 100)
            $auto_pass_threshold = 100;

        if (!empty($api_key)) {
            // Delete old key first to prevent duplicates 
            $pdo->query("DELETE FROM aunqa_settings WHERE setting_key = 'gemini_api_key'");
            $stmtSet = $pdo->prepare("INSERT INTO aunqa_settings (setting_key, setting_value) VALUES ('gemini_api_key', :v)");
            $stmtSet->execute([':v' => $api_key]);
        }

        // Delete old model first to prevent duplicates
        $pdo->query("DELETE FROM aunqa_settings WHERE setting_key = 'gemini_api_model'");
        $stmtSetModel = $pdo->prepare("INSERT INTO aunqa_settings (setting_key, setting_value) VALUES ('gemini_api_model', :m)");
        $stmtSetModel->execute([':m' => $api_model]);

        $pdo->query("DELETE FROM aunqa_settings WHERE setting_key = 'ai_auto_pass_threshold'");
        $stmtSetThreshold = $pdo->prepare("INSERT INTO aunqa_settings (setting_key, setting_value) VALUES ('ai_auto_pass_threshold', :t)");
        $stmtSetThreshold->execute([':t' => (string) $auto_pass_threshold]);
    } else if ($_POST['action'] == 'delete_api_key' && $pdo) {
        $stmtDel = $pdo->prepare("DELETE FROM aunqa_settings WHERE setting_key = 'gemini_api_key'");
        $stmtDel->execute();
    }
}

// ดึงค่าการตั้งค่าเพื่อเอาไปแสดงผล
$current_api_key = '';
$current_api_model = 'gemini-2.5-flash';
$current_auto_pass_threshold = 80;
if ($pdo) {
    $stmtSet = $pdo->query("SELECT setting_key, setting_value FROM aunqa_settings WHERE setting_key IN ('gemini_api_key', 'gemini_api_model', 'ai_auto_pass_threshold')");
    while ($row = $stmtSet->fetch()) {
        if ($row['setting_key'] == 'gemini_api_key')
            $current_api_key = $row['setting_value'];
        if ($row['setting_key'] == 'gemini_api_model')
            $current_api_model = $row['setting_value'];
        if ($row['setting_key'] == 'ai_auto_pass_threshold')
            $current_auto_pass_threshold = max(0, min(100, (int) $row['setting_value']));
    }
}

$threshold_badge_class = 'bg-success';
$threshold_label = 'สูง';
if ($current_auto_pass_threshold < 60) {
    $threshold_badge_class = 'bg-danger';
    $threshold_label = 'ต่ำ';
} else if ($current_auto_pass_threshold < 80) {
    $threshold_badge_class = 'bg-warning text-dark';
    $threshold_label = 'กลาง';
}

// 4. เตรียมข้อมูลปีและเทอมสำหรับตัวกรอง (Filter)
$filter_year = isset($_GET['f_year']) ? $_GET['f_year'] : '';
$filter_sem = isset($_GET['f_sem']) ? $_GET['f_sem'] : '';

$available_years = [];
$available_sems = [];
if ($pdo) {
    $stmtYears = $pdo->query("SELECT DISTINCT year FROM aunqa_verification_records ORDER BY year DESC");
    $available_years = $stmtYears->fetchAll(PDO::FETCH_COLUMN);

    $stmtSems = $pdo->query("SELECT DISTINCT semester FROM aunqa_verification_records ORDER BY semester ASC");
    $available_sems = $stmtSems->fetchAll(PDO::FETCH_COLUMN);

    // ถ้าเข้าหน้าโดยยังไม่ได้เลือก filter ให้ยึดปีล่าสุดเป็นค่าเริ่มต้น
    if (!isset($_GET['f_year']) && !empty($available_years)) {
        $filter_year = (string) $available_years[0];
    }
}

// 5. ดึงรายการรายวิชาที่อยู่ในระบบ เพื่อแสดงผลบนกระดาน (ตามตัวกรอง)
$records = [];
$initial_open_verification_id = isset($_GET['open_vid']) ? (int) $_GET['open_vid'] : 0;
if ($pdo) {
    $where_clauses = [];
    $params = [];

    if (!empty($filter_year)) {
        $where_clauses[] = "r.year = :f_year";
        $params[':f_year'] = $filter_year;
    }
    if (!empty($filter_sem)) {
        $where_clauses[] = "r.semester = :f_sem";
        $params[':f_sem'] = $filter_sem;
    }

    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $record_selects = ["r.*"];
    $checklist_selects = [
        "c.check_clo_verb",
        "c.check_clo_plo_map",
        "c.check_class_activity",
        "c.reviewer_strength",
        "c.reviewer_improvement"
    ];

    $optional_checklist_columns = [
        'pdca_followup',
        'pdca_status',
        'pdca_resolution_percent',
        'pdca_last_year_summary',
        'pdca_current_action',
        'pdca_evidence_note'
    ];

    foreach ($optional_checklist_columns as $column_name) {
        if (board_has_column($pdo, 'aunqa_verification_checklists', $column_name)) {
            $checklist_selects[] = "c.`{$column_name}`";
        } else {
            $checklist_selects[] = "NULL AS `{$column_name}`";
        }
    }

    if (!board_has_column($pdo, 'aunqa_verification_records', 'seed_source')) {
        $record_selects[] = "'' AS seed_source";
    }

    $select_sql = implode(", ", array_merge($record_selects, $checklist_selects));

    try {
        $stmtQuery = $pdo->prepare("
            SELECT {$select_sql}
            FROM aunqa_verification_records r 
            LEFT JOIN aunqa_verification_checklists c ON r.id = c.verification_id
            $where_sql
            ORDER BY r.year DESC, r.semester ASC
        ");
        $stmtQuery->execute($params);
        $records = $stmtQuery->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $records = [];
        $flash_message = 'หน้า Tracking โหลดข้อมูลไม่ครบ: ' . $e->getMessage();
        $flash_type = 'danger';
    }
}

?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AUNQA Verification Tracking 🧭</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Sarabun', sans-serif;
        }

        .hero {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 20px 0;
            border-radius: 0 0 20px 20px;
            margin-bottom: 30px;
        }

        .card-eval {
            border-left: 4px solid #f39c12;
            transition: 0.2s;
            cursor: pointer;
        }

        .card-eval:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            border-left-color: #e67e22;
        }

        .status-done {
            border-left-color: #27ae60 !important;
        }

        .status-wait {
            border-left-color: #e74c3c !important;
        }

        .nav-link {
            color: #555;
            font-weight: 500;
        }

        .nav-link.active {
            font-weight: bold;
            color: #1e3c72;
            border-bottom: 3px solid #1e3c72;
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-expand-lg navbar-light bg-white py-3 shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="index.php"><i class="bi bi-rocket-takeoff"></i> AUNQA
                Hub</a>
            <div class="navbar-nav w-100 d-flex justify-content-between align-items-center">
                <div class="d-flex">
                    <a class="nav-link" href="index.php">ติดตาม มคอ (Dashboard)</a>
                    <a class="nav-link" href="verification.php">กระดานคัดเลือกทวนสอบ (Verification)</a>
                    <a class="nav-link active" href="verification_board.php">ประเมินและทวนสอบผล (Tracking)</a>
                    <a class="nav-link" href="verification_dashboard.php">สรุปรอบประเมิน</a>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                        data-bs-target="#settingsModal"><i class="bi bi-gear-fill"></i> ตั้งค่า AI Assistant</button>
                </div>
            </div>
        </div>
    </nav>

    <div class="hero text-center">
        <div class="container">
            <h2 class="fw-bold">ประเมินและติดตามการทวนสอบผลสัมฤทธิ์ 🧭</h2>
            <p class="mb-0">สำหรับกรรมการประเมินร่องรอยหลักฐานการทวนสอบรายวิชาที่ถูกเลือก</p>
        </div>
    </div>

    <div class="container pb-5">

        <div class="row mb-3">
            <div class="col-md-12 d-flex justify-content-between align-items-center bg-white p-3 rounded shadow-sm">
                <h4 class="fw-bold text-dark mb-0"><i class="bi bi-list-task"></i> รายการวิชาที่รอประเมิน</h4>
                <div class="d-flex gap-2 align-items-center">
                    <form class="d-flex gap-2 align-items-center mb-0" method="GET">
                        <span class="small fw-bold text-muted">กรองตามปีการศึกษา:</span>
                        <select name="f_year" class="form-select form-select-sm" style="width: auto;"
                            onchange="this.form.submit()">
                            <option value="">ทั้งหมด</option>
                            <?php foreach ($available_years as $y): ?>
                                <option value="<?= $y ?>" <?= $filter_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="f_sem" class="form-select form-select-sm" style="width: auto;"
                            onchange="this.form.submit()">
                            <option value="">เทอมทั้งหมด</option>
                            <?php foreach ($available_sems as $s): ?>
                                <option value="<?= $s ?>" <?= $filter_sem == $s ? 'selected' : '' ?>>เทอม <?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($filter_year) || !empty($filter_sem)): ?>
                            <a href="verification_board.php" class="btn btn-sm btn-outline-danger">ล้างตัวกรอง</a>
                        <?php endif; ?>
                    </form>
                    <?php if ($pdo && !empty($records)): ?>
                        <form method="POST" class="mb-0"
                            onsubmit="return confirm('ต้องการลบรายการประเมินทั้งหมดที่กำลังแสดงในมุมมองปัจจุบันใช่หรือไม่? การกระทำนี้ไม่สามารถย้อนกลับได้');">
                            <input type="hidden" name="action" value="delete_all_evaluations">
                            <input type="hidden" name="f_year" value="<?= htmlspecialchars($filter_year) ?>">
                            <input type="hidden" name="f_sem" value="<?= htmlspecialchars($filter_sem) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">
                                <i class="bi bi-trash3-fill"></i> ลบทั้งหมดในมุมมองนี้
                            </button>
                        </form>
                        <?php if (!empty($filter_year)): ?>
                            <form method="POST" class="mb-0"
                                onsubmit="return confirm('ต้องการลบรายการประเมินทั้งหมดของปีการศึกษา <?= htmlspecialchars($filter_year) ?> ใช่หรือไม่? การกระทำนี้ไม่สามารถย้อนกลับได้');">
                                <input type="hidden" name="action" value="delete_year_evaluations">
                                <input type="hidden" name="target_year" value="<?= htmlspecialchars($filter_year) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-calendar-x"></i> ลบทั้งหมดของปีนี้
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" class="mb-0"
                            onsubmit="return confirm('ต้องการลบรายการประเมินทั้งหมดทุกปีในระบบใช่หรือไม่? การกระทำนี้ไม่สามารถย้อนกลับได้และเหมาะใช้เฉพาะตอนเริ่มต้นใหม่เท่านั้น');">
                            <input type="hidden" name="action" value="delete_all_years_evaluations">
                            <button type="submit" class="btn btn-sm btn-outline-dark">
                                <i class="bi bi-exclamation-octagon"></i> ลบทั้งหมดทุกปี
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <form method="POST"
            action="verification_board.php<?= (!empty($filter_year) || !empty($filter_sem)) ? '?' . htmlspecialchars(http_build_query(array_filter(['f_year' => $filter_year, 'f_sem' => $filter_sem], fn($v) => $v !== null && $v !== '')), ENT_QUOTES) : '' ?>"
            id="deleteEvaluationForm" class="d-none">
            <input type="hidden" name="action" value="delete_evaluation">
            <input type="hidden" name="verification_id" id="deleteEvaluationId">
        </form>

        <!-- ลิสต์วิชาที่ถูกเลือก -->
        <div class="row g-3">
            <?php if ($mock_mode): ?>
                <div class="col-12">
                    <div class="alert alert-danger shadow-sm">
                        <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill"></i>
                            ระบบเชื่อมฐานข้อมูลสำหรับหน้า Tracking ไม่สำเร็จ</div>
                        <div class="small">กรุณาตรวจสอบ `config.php`, สิทธิ์ผู้ใช้ฐานข้อมูล, หรือคำสั่ง migration
                            ที่จำเป็นบน server</div>
                        <?php if ($bootstrap_error_message !== ''): ?>
                            <div class="small mt-2"><code><?= htmlspecialchars($bootstrap_error_message) ?></code></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!empty($flash_message)): ?>
                <div class="col-12">
                    <div class="alert alert-<?= $flash_type === 'danger' ? 'danger' : 'success' ?> shadow-sm">
                        <?= htmlspecialchars($flash_message) ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (empty($records)): ?>
                <div class="col-12 text-center text-muted my-5">
                    <i class="bi bi-inbox fs-1"></i>
                    <p>ยังไม่มีรายวิชาที่ถูกคัดเลือก กรุณาเพิ่มข้อมูลจากหน้า <b>กระดานคัดเลือก</b> ก่อน</p>
                </div>
            <?php else: ?>

                <?php foreach ($records as $rec): ?>
                    <?php
                    $is_done = ($rec['verification_status'] == 'ผ่านการทวนสอบ');
                    $card_class = $is_done ? 'status-done' : 'status-wait';
                    $badge_class = $is_done ? 'bg-success' : 'bg-warning text-dark';
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card card-eval <?= $card_class ?> h-100 shadow-sm"
                            onclick="openEvalModal(<?= htmlspecialchars(json_encode($rec)) ?>)">
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2 align-items-center">
                                    <div>
                                        <span class="badge bg-primary">ปี <?= $rec['year'] ?>/<?= $rec['semester'] ?></span>
                                        <span class="badge <?= $badge_class ?>"><?= $rec['verification_status'] ?></span>
                                        <?php if (($rec['seed_source'] ?? '') === 'carry_forward_seed'): ?>
                                            <span class="badge bg-info text-dark">Seeded from carry forward</span>
                                        <?php endif; ?>
                                    </div>
                                    <div onclick="event.stopPropagation()">
                                        <button type="button" class="btn btn-sm btn-outline-danger border-0 p-1"
                                            title="ลบวิชานี้ออกจากการประเมิน"
                                            onclick="submitDeleteEvaluation(event, <?= (int) $rec['id'] ?>)">
                                            <i class="bi bi-trash3-fill"></i>
                                        </button>
                                    </div>
                                </div>
                                <h5 class="fw-bold text-dark mb-1 text-truncate" title="<?= $rec['course_name'] ?>">
                                    <?= $rec['course_name'] ?>
                                </h5>
                                <p class="text-muted small mb-3">รหัส: <?= $rec['course_code'] ?> | ผู้สอน:
                                    <?= $rec['instructor'] ?>
                                </p>

                                <div class="mb-3 d-flex gap-2">
                                    <?php if (!empty($rec['tqf3_link'])): ?>
                                        <a href="<?= $rec['tqf3_link'] ?>" target="_blank"
                                            class="badge bg-light text-primary border text-decoration-none"
                                            onclick="event.stopPropagation()"><i class="bi bi-link-45deg"></i> มคอ.3</a>
                                    <?php endif; ?>
                                    <?php if (!empty($rec['tqf5_link'])): ?>
                                        <a href="<?= $rec['tqf5_link'] ?>" target="_blank"
                                            class="badge bg-light text-success border text-decoration-none"
                                            onclick="event.stopPropagation()"><i class="bi bi-link-45deg"></i> มคอ.5</a>
                                    <?php endif; ?>
                                </div>

                                <!-- ตัวบ่งชี้ความคืบหน้า -->
                                <div class="d-flex gap-2">
                                    <?php if ($rec['check_clo_verb']): ?><i class="bi bi-check-circle-fill text-success"
                                            title="CLO ถูกต้อง"></i><?php else: ?><i
                                            class="bi bi-circle text-muted"></i><?php endif; ?>
                                    <?php if ($rec['check_clo_plo_map']): ?><i class="bi bi-check-circle-fill text-success"
                                            title="Map ครบถ้วน"></i><?php else: ?><i
                                            class="bi bi-circle text-muted"></i><?php endif; ?>
                                    <?php if ($rec['check_class_activity']): ?><i class="bi bi-check-circle-fill text-success"
                                            title="กิจกรรมสอดคล้อง"></i><?php else: ?><i
                                            class="bi bi-circle text-muted"></i><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>
        </div>
    </div>

    <!-- Modal สำหรับตั้งค่าประเมิน (Evaluation Modal) -->
    <div class="modal fade" id="evalModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="modalTitle">ฟอร์มประเมินการทวนสอบรายวิชา</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="verification_board.php">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_evaluation">
                        <input type="hidden" name="verification_id" id="vidInput">
                        <input type="hidden" name="human_override_mode" id="humanOverrideModeInput" value="0">

                        <div class="alert alert-info border-info" style="background-color: #e3f2fd;">
                            <h6 class="fw-bold mb-1" id="mCourseName">-</h6>
                            <p class="mb-0 small" id="mCourseDetails">-</p>
                        </div>

                        <!-- AI Assisted Upload Section -->
                        <div class="card mb-4 border-primary">
                            <div class="card-header bg-primary text-white py-2">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-robot"></i> ผู้ช่วย AI ทวนสอบวิเคราะห์อัตโนมัติ
                                </h6>
                            </div>
                            <div class="card-body bg-light">
                                <p class="small text-muted mb-2">อัปโหลดไฟล์ <code>.docx</code> เพื่อให้ AI
                                    ช่วยสกัดข้อมูลและร่องรอยการประเมิน Checklist ให้โดยอัตโนมัติ</p>
                                <div class="row g-2 align-items-center">
                                    <div class="col-md-5">
                                        <label class="form-label small fw-bold mb-1">ไฟล์ มคอ.3 (Course Spec):</label>
                                        <div id="sys_t3_status" class="mb-1 small text-success fw-bold d-none">
                                            <i class="bi bi-cloud-check-fill"></i> แขวนในระบบแล้ว
                                            <a id="sys_t3_dl" href="#" target="_blank"
                                                class="ms-1 badge bg-success text-white text-decoration-none"
                                                title="คลิกเพื่อดาวน์โหลด"><i class="bi bi-download"></i> โหลด</a>
                                        </div>
                                        <div id="sys_t3_warning"
                                            class="mb-1 small text-danger fw-bold d-none bg-danger-subtle p-1 rounded">
                                            <i class="bi bi-exclamation-triangle-fill"></i> ไฟล์ระบบเป็น .doc/.pdf <a
                                                id="sys_t3_warning_dl" href="#" target="_blank"
                                                class="ms-1 badge bg-danger text-white text-decoration-none">คลิกโหลด</a><br>
                                            <span style="font-size: 0.7rem;">โปรดแปลงบรรจุเป็น .docx
                                                แล้วอัปโหลดแทรกใหม่</span>
                                        </div>
                                        <input type="file" class="form-control form-control-sm" id="ai_tqf3"
                                            accept=".docx">
                                        <div id="ai_tqf3_badge" class="mt-1 small d-none"></div>
                                        <input type="hidden" id="sys_tqf3_url">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small fw-bold mb-1">ไฟล์ มคอ.5 (Course Report):</label>
                                        <div id="sys_t5_status" class="mb-1 small text-success fw-bold d-none">
                                            <i class="bi bi-cloud-check-fill"></i> แขวนในระบบแล้ว
                                            <a id="sys_t5_dl" href="#" target="_blank"
                                                class="ms-1 badge bg-success text-white text-decoration-none"
                                                title="คลิกเพื่อดาวน์โหลด"><i class="bi bi-download"></i> โหลด</a>
                                        </div>
                                        <div id="sys_t5_warning"
                                            class="mb-1 small text-warning-emphasis fw-bold d-none bg-warning-subtle p-1 rounded border border-warning-subtle">
                                            <i class="bi bi-info-circle-fill"></i> <span
                                                id="sys_t5_warning_text">ไฟล์ระบบเป็น .doc/.pdf (อ่านไม่ได้)</span> <a
                                                id="sys_t5_warning_dl" href="#" target="_blank"
                                                class="ms-1 badge bg-warning text-dark text-decoration-none border border-warning">คลิกโหลด</a><br>
                                            <span id="sys_t5_warning_subtext" style="font-size: 0.7rem;"
                                                class="fw-normal">โปรดใช้ .docx เพื่อความแม่นยำสูงสุด</span>
                                        </div>
                                        <input type="file" class="form-control form-control-sm" id="ai_tqf5"
                                            accept=".doc,.docx">
                                        <div id="ai_tqf5_badge" class="mt-1 small d-none"></div>
                                        <input type="hidden" id="sys_tqf5_url">
                                        <small class="text-muted"
                                            style="font-size: 0.7rem;">(เว้นว่างได้ถ้าไม่มี)</small>
                                    </div>
                                    <div class="col-md-2 mt-auto text-end">
                                        <button type="button" class="btn btn-primary btn-sm w-100"
                                            onclick="runAiAnalysis()"><i class="bi bi-stars"></i>
                                            เริ่มส่งวิเคราะห์</button>
                                    </div>
                                </div>
                                <div id="aiLoadingIndicator" class="mt-3 text-center d-none">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                    <span class="text-primary fw-bold small ms-2">กำลังให้ AI วิเคราะห์เอกสาร
                                        กรุณารอสักครู่ (ประมาณ 10-30 วินาที)...</span>
                                </div>
                                <div id="aiResultAlert" class="mt-2 alert alert-success fw-bold small d-none mb-0 py-2">
                                    <i class="bi bi-check-circle-fill"></i> AI ดึงข้อมูลทวนสอบเข้าระบบเรียบร้อยแล้ว
                                    กดยืนยันบันทึกผลได้เลยครับ
                                </div>
                                <div id="aiAnalysisMeta" class="mt-2 d-none"></div>
                                <div id="aiErrorAlert" class="mt-2 alert alert-danger d-none small mb-0 py-2">
                                    <i class="bi bi-exclamation-triangle-fill"></i> <span id="aiErrorMsg"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Global Scores & Checkboxes (Human Override Mode) -->
                        <div class="row align-items-center mb-4 mt-4">
                            <div class="col-12 d-flex justify-content-between align-items-center mb-1">
                                <div>
                                    <h6 class="fw-bold text-primary m-0"><i class="bi bi-ui-checks"></i>
                                        สรุปผลการประเมิน (Executive Summary)</h6>
                                    <div class="small text-muted mt-1">
                                        <i class="bi bi-sliders"></i> AI auto-pass threshold:
                                        <span class="badge <?= $threshold_badge_class ?>"
                                            id="ai_auto_pass_threshold_badge"><?= $threshold_label ?>
                                            <?= (int) $current_auto_pass_threshold ?>%</span>
                                    </div>
                                </div>
                                <div class="form-check form-switch text-warning">
                                    <input class="form-check-input" type="checkbox" id="humanOverrideToggle"
                                        onchange="toggleHumanOverride(this)">
                                    <label class="form-check-label small fw-bold" for="humanOverrideToggle"><i
                                            class="bi bi-person-fill-gear"></i> โหมดแก้ไขโดยกรรมการ (Override)</label>
                                </div>
                            </div>

                            <!-- List Group for Scores & Manual Checks -->
                            <div class="col-12">
                                <div class="list-group">
                                    <!-- Bloom -->
                                    <label class="list-group-item d-flex gap-3 align-items-center">
                                        <input class="form-check-input flex-shrink-0" type="checkbox" name="chk_bloom"
                                            id="chk_bloom" style="transform: scale(1.3);">
                                        <div class="w-100">
                                            <div class="d-flex justify-content-between">
                                                <h6 class="my-0">1. CLO ใช้คำกริยาตาม Bloom's Taxonomy ถูกต้อง</h6>
                                                <span class="badge bg-secondary" id="txt_score_bloom">0%</span>
                                            </div>
                                            <div class="progress mt-1" style="height: 6px;">
                                                <div id="pb_bloom" class="progress-bar bg-primary" role="progressbar"
                                                    style="width: 0%;"></div>
                                            </div>
                                        </div>
                                    </label>

                                    <!-- PLO Mapping -->
                                    <label class="list-group-item d-flex gap-3 align-items-center">
                                        <input class="form-check-input flex-shrink-0" type="checkbox" name="chk_map"
                                            id="chk_map" style="transform: scale(1.3);">
                                        <div class="w-100">
                                            <div class="d-flex justify-content-between">
                                                <h6 class="my-0">2. ความครอบคลุมหลักสูตร (PLO Coverage ครบถ้วน)</h6>
                                                <span class="badge bg-secondary" id="txt_score_plo">0%</span>
                                            </div>
                                            <div class="progress mt-1" style="height: 6px;">
                                                <div id="pb_plo" class="progress-bar bg-success" role="progressbar"
                                                    style="width: 0%;"></div>
                                            </div>
                                        </div>
                                    </label>

                                    <!-- Activity -->
                                    <label class="list-group-item d-flex gap-3 align-items-center">
                                        <input class="form-check-input flex-shrink-0" type="checkbox"
                                            name="chk_activity" id="chk_activity" style="transform: scale(1.3);">
                                        <div class="w-100">
                                            <div class="d-flex justify-content-between">
                                                <h6 class="my-0">3. กิจกรรมการเรียนการสอนสอดคล้องกับเป้าหมาย</h6>
                                                <span class="badge bg-secondary" id="txt_score_act">0%</span>
                                            </div>
                                            <div class="progress mt-1" style="height: 6px;">
                                                <div id="pb_act" class="progress-bar bg-info" role="progressbar"
                                                    style="width: 0%;"></div>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Reviewer Strengths & Improvements -->
                        <div class="row mb-3">
                            <div class="col-md-6 mb-2">
                                <label class="form-label fw-bold text-success"><i class="bi bi-star-fill"></i>
                                    จุดเด่นของรายวิชานี้ (Strengths):</label>
                                <textarea class="form-control border-success" name="reviewer_strength"
                                    id="reviewer_strength" rows="3"
                                    placeholder="ระบุจุดเด่นหรือข้อดีของแผนการสอน"></textarea>
                            </div>
                            <div class="col-md-6 mb-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label fw-bold text-danger mb-0"><i class="bi bi-tools"></i>
                                        จุดที่ควรพัฒนา (Areas for Improvement):</label>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                        onclick="importReviewerImprovementToPdca()">
                                        <i class="bi bi-magic"></i> แปลงเป็น PDCA issue
                                    </button>
                                </div>
                                <textarea class="form-control border-danger" name="reviewer_improvement"
                                    id="reviewer_improvement" rows="3"
                                    placeholder="ระบุสิ่งที่ควรปรับปรุงหรือแก้ไขในเทอมถัดไป"></textarea>
                                <div class="small text-muted mt-1">ระบบจะพยายามแยกข้อความตามข้อ, bullet หรือบรรทัดใหม่
                                    แล้วสร้างเป็น PDCA issue ให้อัตโนมัติ</div>
                            </div>
                        </div>

                        <div class="card border-info mb-3">
                            <div class="card-header bg-info-subtle">
                                <h6 class="mb-0 fw-bold text-info-emphasis"><i class="bi bi-arrow-repeat"></i> PDCA
                                    Follow-up ระดับรายวิชา</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">สถานะการติดตาม PDCA</label>
                                        <select class="form-select" name="pdca_status" id="pdca_status">
                                            <option value="not_started">ยังไม่เริ่ม</option>
                                            <option value="in_progress">กำลังดำเนินการ</option>
                                            <option value="partially_resolved">แก้ได้บางส่วน</option>
                                            <option value="resolved">แก้ไขแล้ว</option>
                                            <option value="carried_forward">ยกไปติดตามต่อรอบถัดไป</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">ความคืบหน้าการแก้ปัญหา (%)</label>
                                        <input type="number" class="form-control" name="pdca_resolution_percent"
                                            id="pdca_resolution_percent" min="0" max="100" step="1" value="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">สรุปปัญหาจากปีก่อน</label>
                                        <textarea class="form-control" name="pdca_last_year_summary"
                                            id="pdca_last_year_summary" rows="3"
                                            placeholder="เช่น ปีก่อนพบว่า CLO ไม่ชัด, PLO coverage ไม่ครบ"></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">การปรับปรุงในปีปัจจุบัน</label>
                                        <textarea class="form-control" name="pdca_current_action"
                                            id="pdca_current_action" rows="3"
                                            placeholder="เช่น ปรับ CLO ใหม่, ปรับกิจกรรม, อัปโหลดเอกสารใหม่"></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">หลักฐาน/ข้อสังเกตกรรมการ</label>
                                        <textarea class="form-control" name="pdca_evidence_note" id="pdca_evidence_note"
                                            rows="3"
                                            placeholder="เช่น อ้างอิงจาก มคอ.3/5, ความเห็นกรรมการ, ผลติดตามล่าสุด"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card border-secondary mb-3">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-bold text-secondary"><i class="bi bi-list-check"></i> PDCA Issues
                                    รายประเด็น</h6>
                                <span class="badge bg-secondary" id="pdca_issue_count">0 ประเด็น</span>
                            </div>
                            <div class="card-body">
                                <div class="row g-2 align-items-end border rounded p-2 mb-3 bg-light-subtle">
                                    <div class="col-md-2">
                                        <label class="form-label small fw-bold mb-1">หมวด</label>
                                        <select class="form-select form-select-sm" id="pdca_issue_category">
                                            <option value="other">อื่น ๆ</option>
                                            <option value="bloom">Bloom</option>
                                            <option value="plo">PLO</option>
                                            <option value="activity">Activity</option>
                                            <option value="clo_result">CLO Result</option>
                                            <option value="document">Document</option>
                                            <option value="assessment">Assessment</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-bold mb-1">ชื่อประเด็น</label>
                                        <input type="text" class="form-control form-control-sm" id="pdca_issue_title"
                                            placeholder="เช่น CLO ไม่ชัดเจน">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-bold mb-1">รายละเอียด</label>
                                        <input type="text" class="form-control form-control-sm" id="pdca_issue_detail"
                                            placeholder="สรุปรายละเอียดสั้น ๆ">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small fw-bold mb-1">สถานะ</label>
                                        <select class="form-select form-select-sm" id="pdca_issue_status">
                                            <option value="open">เปิดประเด็น</option>
                                            <option value="in_progress">กำลังแก้ไข</option>
                                            <option value="partially_resolved">แก้ได้บางส่วน</option>
                                            <option value="resolved">แก้ไขแล้ว</option>
                                            <option value="carried_forward">ยกไปรอบถัดไป</option>
                                        </select>
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label small fw-bold mb-1">%</label>
                                        <input type="number" class="form-control form-control-sm"
                                            id="pdca_issue_resolution_percent" min="0" max="100" step="1" value="0">
                                    </div>
                                    <div class="col-md-1 d-grid">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick="savePdcaIssue()"><i class="bi bi-plus-circle"></i></button>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm align-middle mb-0"
                                        style="font-size:0.85rem;">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="18%">หมวด/ความเชื่อมั่น</th>
                                                <th width="18%">ประเด็น</th>
                                                <th>รายละเอียด</th>
                                                <th width="16%">สถานะ</th>
                                                <th width="10%">%</th>
                                                <th width="18%">จัดการ</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tbody_pdca_issues">
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-3">ยังไม่มีประเด็น PDCA
                                                    สำหรับรายวิชานี้</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Accordion for Deep Analytics -->
                        <div id="ai_deep_analysis_container" class="mt-4 mb-2 d-none">
                            <h6 class="fw-bold text-success mb-3"><i class="bi bi-robot"></i> ข้อมูลเชิงลึกจาก AI
                                (Granular Insights)</h6>
                            <div class="accordion mb-3" id="accordionAI">
                                <!-- Bloom Accordion -->
                                <div class="accordion-item border-primary">
                                    <h2 class="accordion-header" id="headingBloom">
                                        <button class="accordion-button collapsed fw-bold text-primary" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#collapseBloom">
                                            <i class="bi bi-bar-chart-steps me-2"></i> 1. วิเคราะห์คำกริยา (Bloom's
                                            Taxonomy) รายข้อ
                                        </button>
                                    </h2>
                                    <div id="collapseBloom" class="accordion-collapse collapse"
                                        data-bs-parent="#accordionAI">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-sm align-middle mb-0"
                                                    style="font-size: 0.85rem;">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th width="15%">รหัส</th>
                                                            <th>พฤติกรรมอ้างอิงของวิชา</th>
                                                            <th width="15%">กริยา</th>
                                                            <th width="15%">ระดับความคิด</th>
                                                            <th>ข้อเสนอแนะ AI</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="tbody_bloom"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- PLO Accordion -->
                                <div class="accordion-item border-success">
                                    <h2 class="accordion-header" id="headingPLO">
                                        <button class="accordion-button collapsed fw-bold text-success" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#collapsePLO">
                                            <i class="bi bi-diagram-3 me-2"></i> 2. วิเคราะห์ความครอบคลุมหลักสูตร (PLO
                                            Coverage)
                                        </button>
                                    </h2>
                                    <div id="collapsePLO" class="accordion-collapse collapse"
                                        data-bs-parent="#accordionAI">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-sm align-middle mb-0"
                                                    style="font-size: 0.85rem;">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th width="15%">รหัส PLO</th>
                                                            <th>จุดประสงค์หลักสูตร</th>
                                                            <th width="15%">% Coverage</th>
                                                            <th>CLO ที่รองรับ</th>
                                                            <th>ข้อเสนอแนะ / แจ้งเตือนหลุดเป้า</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="tbody_plo"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Activity Accordion -->
                                <div class="accordion-item border-info">
                                    <h2 class="accordion-header" id="headingAct">
                                        <button class="accordion-button collapsed fw-bold text-info" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#collapseAct">
                                            <i class="bi bi-easel me-2"></i> 3. วิเคราะห์กิจกรรมการสอน (Teaching
                                            Activities)
                                        </button>
                                    </h2>
                                    <div id="collapseAct" class="accordion-collapse collapse"
                                        data-bs-parent="#accordionAI">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-sm align-middle mb-0"
                                                    style="font-size: 0.85rem;">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th width="25%">กิจกรรม (ที่ระบุใน มคอ.)</th>
                                                            <th>สอดคล้อง CLO ใดบ้าง</th>
                                                            <th width="15%">% สอดคล้อง</th>
                                                            <th>แนวทางปรับปรุงรอบถัดไป</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="tbody_act"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- CLO Deep Performance Accordion -->
                                <div class="accordion-item border-warning">
                                    <h2 class="accordion-header" id="headingCLO">
                                        <button class="accordion-button collapsed fw-bold text-warning"
                                            style="background-color:#fff3cd;" type="button" data-bs-toggle="collapse"
                                            data-bs-target="#collapseCLO">
                                            <i class="bi bi-graph-up-arrow me-2"></i> 4.
                                            วิเคราะห์และรับรองผลสัมฤทธิ์ระดับ CLO (Target vs Actual)
                                        </button>
                                    </h2>
                                    <div id="collapseCLO" class="accordion-collapse collapse"
                                        data-bs-parent="#accordionAI">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-sm align-middle mb-0"
                                                    style="font-size: 0.85rem;" id="table_clo_eval">
                                                    <thead class="table-warning">
                                                        <tr>
                                                            <th width="10%">รหัส</th>
                                                            <th width="10%">เป้าหมาย</th>
                                                            <th width="10%">ทำได้จริง</th>
                                                            <th width="25%">ปัญหา/อุปสรรค</th>
                                                            <th width="25%">แผนปรับปรุง (CQI)</th>
                                                            <th width="20%">มติทวนสอบรายข้อ</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="tbody_clo"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="ai_debug_container" class="mt-3 d-none">
                            <div class="card border-secondary">
                                <div
                                    class="card-header bg-secondary-subtle d-flex justify-content-between align-items-center">
                                    <button type="button"
                                        class="btn btn-sm text-secondary fw-bold p-0 border-0 bg-transparent d-flex align-items-center gap-2"
                                        data-bs-toggle="collapse" data-bs-target="#debug_clo_collapse"
                                        aria-expanded="false" aria-controls="debug_clo_collapse">
                                        <i class="bi bi-bug-fill"></i>
                                        <span>Debug Parser: CLO Evaluations</span>
                                        <i class="bi bi-chevron-down small"></i>
                                    </button>
                                    <span class="badge bg-secondary" id="debug_clo_count">0 รายการ</span>
                                </div>
                                <div id="debug_clo_collapse" class="collapse">
                                    <div class="card-body">
                                        <p class="small text-muted mb-2">ใช้ดูว่า parser จับบรรทัดไหนจาก มคอ.5
                                            มาแปลงเป็นข้อมูลในตารางข้อ 4 เพื่อช่วยจูนกับเอกสารจริง</p>
                                        <div id="debug_clo_summary" class="small mb-3"></div>
                                        <div id="debug_clo_entries" class="vstack gap-2"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-save"></i>
                            บันทึกผลประเมิน</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div class="modal fade" id="settingsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-gear-fill"></i> ตั้งค่า AI Assistant (System Level)
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <form method="POST" action="verification_board.php">
                    <div class="modal-body">

                        <div class="mb-3">
                            <label class="form-label fw-bold d-flex justify-content-between align-items-center">
                                <span>ระบบ AI ปัจจุบันที่ใช้ประมวลผล (Model):</span>
                                <button type="button" class="btn btn-xs btn-outline-primary py-0 px-1"
                                    style="font-size: 0.7rem;" onclick="fetchAvailableModels()">
                                    <i class="bi bi-arrow-clockwise"></i> อัปเดตรายชื่อ
                                </button>
                            </label>
                            <select class="form-select border-primary" name="gemini_api_model" id="gemini_api_model">
                                <option value="<?= htmlspecialchars($current_api_model) ?>" selected>กำลังดึงข้อมูล
                                    หรือใช้ค่าปัจจุบัน: <?= htmlspecialchars($current_api_model) ?></option>
                            </select>
                            <small class="text-muted">รายชื่อโมเดลจะอัปเดตอัตโนมัติตาม API Key ที่ท่านใส่ไว้</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">เกณฑ์คะแนนผ่านอัตโนมัติของ AI (%):</label>
                            <input type="number" class="form-control border-warning" id="ai_auto_pass_threshold_input"
                                name="ai_auto_pass_threshold" min="0" max="100" step="1"
                                value="<?= (int) $current_auto_pass_threshold ?>">
                            <div class="mt-2 small text-muted">
                                Preview:
                                <span class="badge <?= $threshold_badge_class ?>"
                                    id="ai_auto_pass_threshold_badge_modal"><?= $threshold_label ?>
                                    <?= (int) $current_auto_pass_threshold ?>%</span>
                            </div>
                            <small class="text-muted">ถ้า AI ให้คะแนนหัวข้อใดตั้งแต่ค่านี้ขึ้นไป
                                ระบบจะติ๊กผ่านให้อัตโนมัติ ค่าเริ่มต้นคือ 80%</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Gemini API Key:</label>

                            <?php if (!empty($current_api_key)):
                                $masked_key = substr($current_api_key, 0, 10) . '****************' . substr($current_api_key, -5);
                                ?>
                                <div
                                    class="alert alert-success p-2 small mb-2 d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="bi bi-shield-check"></i> คีย์ปัจจุบัน:
                                        <strong><?= $masked_key ?></strong><br>
                                        <span class="text-muted" style="font-size: 0.7rem;">(ไม่สามารถระบุอีเมลผู้ให้คีย์ได้
                                            แต่ตรวจสอบรหัสผ่าน 10 ตัวแรกได้)</span>
                                    </div>
                                    <button type="submit" name="action" value="delete_api_key"
                                        class="btn btn-sm btn-danger fw-bold ms-2"
                                        onclick="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบ API Key ปัจจุบันทิ้ง? (ระบบจะใช้งาน AI ไม่ได้จนกว่าจะใส่ข้อมูลใหม่)');"><i
                                            class="bi bi-trash-fill"></i> ลบคีย์ทิ้ง</button>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning p-2 small mb-2">
                                    <i class="bi bi-exclamation-circle-fill"></i> ยังไม่มี API Key ในระบบ
                                </div>
                            <?php endif; ?>

                            <input type="password" class="form-control" name="gemini_api_key"
                                placeholder="วาง API Key อันใหม่ที่นี่ (AIzaSy...)" <?= empty($current_api_key) ? 'required' : '' ?>>

                            <div class="mt-2 p-2 bg-light border rounded small">
                                <strong class="text-primary"><i class="bi bi-lightbulb-fill"></i> วิธีขอ API Key
                                    ใหม่ฟรี:</strong>
                                <ol class="mb-1 mt-1 ps-3 text-muted">
                                    <li>ล็อกอินด้วยบัญชี Google ส่วนตัวของท่าน</li>
                                    <li>ไปที่เว็บไซต์ <a href="https://aistudio.google.com/app/apikey" target="_blank"
                                            class="text-decoration-none fw-bold"><i
                                                class="bi bi-box-arrow-up-right"></i> Google AI Studio</a></li>
                                    <li>กดปุ่ม <strong>Create API Key</strong> และเลือกโปรเจกต์ ก๊อปปี้รหัสยาวๆ
                                        มาวางด้านบนได้เลย</small>
                                </ol>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" name="action" value="save_settings" class="btn btn-dark fw-bold"><i
                                class="bi bi-save"></i> บันทึกการตั้งค่า</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const evalModal = new bootstrap.Modal(document.getElementById('evalModal'));
        let latestAiDebugInfo = null;
        const verificationRecords = <?= json_encode(array_values($records), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const initialOpenVerificationId = <?= json_encode($initial_open_verification_id) ?>;
        const savedAiAutoPassThreshold = <?= (int) $current_auto_pass_threshold ?>;
        let aiAutoPassThreshold = savedAiAutoPassThreshold;

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
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

        function updateAiAutoPassBadge(value) {
            const threshold = normalizeAiAutoPassThreshold(value);
            const meta = getAiAutoPassBadgeMeta(threshold);
            const badges = [
                document.getElementById('ai_auto_pass_threshold_badge'),
                document.getElementById('ai_auto_pass_threshold_badge_modal')
            ];

            badges.forEach(badge => {
                if (!badge) {
                    return;
                }
                badge.className = `badge ${meta.className}`;
                badge.textContent = `${meta.label} ${threshold}%`;
            });

            aiAutoPassThreshold = threshold;
        }

        function resetAiDebugPanel() {
            latestAiDebugInfo = null;
            document.getElementById('ai_debug_container').classList.add('d-none');
            const debugCollapse = document.getElementById('debug_clo_collapse');
            if (debugCollapse && debugCollapse.classList.contains('show')) {
                bootstrap.Collapse.getOrCreateInstance(debugCollapse, { toggle: false }).hide();
            }
            document.getElementById('debug_clo_count').textContent = '0 รายการ';
            document.getElementById('debug_clo_summary').innerHTML = '';
            document.getElementById('debug_clo_entries').innerHTML = '';
        }

        function renderAiDebugPanel(debugInfo) {
            const container = document.getElementById('ai_debug_container');
            const countBadge = document.getElementById('debug_clo_count');
            const summary = document.getElementById('debug_clo_summary');
            const entries = document.getElementById('debug_clo_entries');

            if (!debugInfo || !debugInfo.clo_parser) {
                container.classList.add('d-none');
                countBadge.textContent = '0 รายการ';
                summary.innerHTML = '';
                entries.innerHTML = '';
                return;
            }

            const parserInfo = debugInfo.clo_parser;
            const mergedCount = parserInfo.merged_count || 0;
            const fallbackCandidates = Array.isArray(parserInfo.fallback_candidates) ? parserInfo.fallback_candidates : [];
            const parserEntries = Array.isArray(parserInfo.entries) ? parserInfo.entries : [];

            countBadge.textContent = `${mergedCount} รายการ`;
            summary.innerHTML = `
                <div class="alert alert-secondary py-2 px-3 mb-0">
                    <div><strong>Fallback candidates:</strong> ${fallbackCandidates.length} บรรทัด</div>
                    <div><strong>Final merged rows:</strong> ${mergedCount} รายการ</div>
                </div>
            `;

            entries.innerHTML = '';
            if (parserEntries.length === 0) {
                entries.innerHTML = '<div class="text-muted small">ยังไม่มีข้อมูล debug จากรอบวิเคราะห์ล่าสุด</div>';
            } else {
                parserEntries.forEach((entry, index) => {
                    const sourceBadges = (entry.sources || []).map(source => `<span class="badge bg-light text-dark border me-1">${escapeHtml(source)}</span>`).join('');
                    const sourceLines = (entry.lines || []).length > 0
                        ? (entry.lines || []).map(line => `<li><code>${escapeHtml(line)}</code></li>`).join('')
                        : '<li class="text-muted">ไม่มีบรรทัดต้นทาง</li>';

                    const parsed = entry.parsed || {};
                    const block = document.createElement('div');
                    block.className = 'border rounded p-2 bg-light';
                    block.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="fw-bold text-dark">#${index + 1} ${escapeHtml(entry.clo_code || '-')}</div>
                                <div class="small">${sourceBadges}</div>
                            </div>
                        </div>
                        <div class="small mb-2">
                            <strong>Parsed:</strong>
                            target=<span class="text-primary">${escapeHtml(parsed.target_percent || '-')}</span>,
                            actual=<span class="text-success">${escapeHtml(parsed.actual_percent || '-')}</span>,
                            issue=<span class="text-danger">${escapeHtml(parsed.problem_found || '-')}</span>,
                            cqi=<span class="text-secondary">${escapeHtml(parsed.improvement_plan || '-')}</span>
                        </div>
                        <div class="small fw-bold text-muted">Source lines</div>
                        <ol class="small mb-0 ps-3">${sourceLines}</ol>
                    `;
                    entries.appendChild(block);
                });
            }

            container.classList.remove('d-none');
        }

        function openEvalModal(data) {
            document.getElementById('vidInput').value = data.id;
            document.getElementById('mCourseName').textContent = data.course_code + ' - ' + data.course_name;
            document.getElementById('mCourseDetails').textContent = `ผู้สอน: ${data.instructor} | เทอม ${data.semester}/${data.year}`;

            document.getElementById('chk_bloom').checked = parseInt(data.check_clo_verb) === 1;
            document.getElementById('chk_map').checked = parseInt(data.check_clo_plo_map) === 1;
            document.getElementById('chk_activity').checked = parseInt(data.check_class_activity) === 1;

            // disable checkboxes by default (AI Mode)
            document.getElementById('humanOverrideToggle').checked = false;
            toggleHumanOverride(document.getElementById('humanOverrideToggle'));

            document.getElementById('reviewer_strength').value = data.reviewer_strength || "";
            document.getElementById('reviewer_improvement').value = data.reviewer_improvement || "";
            document.getElementById('pdca_status').value = data.pdca_status || 'not_started';
            document.getElementById('pdca_resolution_percent').value = parseFloat(data.pdca_resolution_percent || 0);
            document.getElementById('pdca_last_year_summary').value = data.pdca_last_year_summary || '';
            document.getElementById('pdca_current_action').value = data.pdca_current_action || '';
            document.getElementById('pdca_evidence_note').value = data.pdca_evidence_note || '';
            resetPdcaIssueComposer();
            document.getElementById('pdca_issue_count').textContent = '0 ประเด็น';
            document.getElementById('tbody_pdca_issues').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">กำลังโหลดข้อมูล PDCA...</td></tr>';

            // reset UI AI
            document.getElementById('ai_tqf3').value = '';
            document.getElementById('ai_tqf5').value = '';
            document.getElementById('aiLoadingIndicator').classList.add('d-none');
            document.getElementById('aiResultAlert').classList.add('d-none');
            document.getElementById('aiResultAlert').classList.remove('alert-warning', 'alert-danger');
            document.getElementById('aiResultAlert').classList.add('alert-success');
            document.getElementById('aiAnalysisMeta').classList.add('d-none');
            document.getElementById('aiAnalysisMeta').innerHTML = '';

            document.getElementById('ai_deep_analysis_container').classList.add('d-none');
            document.getElementById('tbody_bloom').innerHTML = '';
            document.getElementById('tbody_plo').innerHTML = '';
            document.getElementById('tbody_act').innerHTML = '';
            document.getElementById('tbody_clo').innerHTML = '';
            resetAiDebugPanel();

            // Fetch system URLs
            const t3Url = data.tqf3_link;
            const t5Url = data.tqf5_link;
            document.getElementById('sys_tqf3_url').value = t3Url || '';
            document.getElementById('sys_tqf5_url').value = t5Url || '';

            document.getElementById('sys_t3_status').classList.add('d-none');
            document.getElementById('sys_t3_warning').classList.add('d-none');
            if (t3Url) {
                if (t3Url.match(/\.(doc|pdf)$/i)) {
                    document.getElementById('sys_t3_warning').classList.remove('d-none');
                    document.getElementById('sys_t3_warning_dl').href = t3Url;
                } else {
                    document.getElementById('sys_t3_status').classList.remove('d-none');
                    document.getElementById('sys_t3_dl').href = t3Url;
                }
            }

            document.getElementById('sys_t5_status').classList.add('d-none');
            document.getElementById('sys_t5_warning').classList.add('d-none');
            if (t5Url) {
                const isDoc = t5Url.match(/\.doc$/i);
                const isPdf = t5Url.match(/\.pdf$/i);

                if (isDoc || isPdf) {
                    document.getElementById('sys_t5_warning').classList.remove('d-none');
                    document.getElementById('sys_t5_warning_dl').href = t5Url;

                    if (isDoc) {
                        document.getElementById('sys_t5_warning_text').textContent = "ไฟล์ระบบเป็น .doc (Legacy)";
                        document.getElementById('sys_t5_warning_subtext').innerHTML = "AI จะพยายามวิเคราะห์ให้ดีที่สุด แต่แนะนำให้แปลงเป็น <strong>.docx</strong> เพื่อความแม่นยำสูงสุดครับ";
                    } else {
                        document.getElementById('sys_t5_warning_text').textContent = "ไฟล์ระบบเป็น .pdf (อ่านไม่ได้)";
                        document.getElementById('sys_t5_warning_subtext').textContent = "AI จะข้ามการอ่าน มคอ.5 โปรดอัปโหลดใหม่เป็น .docx หากต้องให้ AI ตรวจสอบสรุปผลครับ";
                    }
                } else {
                    document.getElementById('sys_t5_status').classList.remove('d-none');
                    document.getElementById('sys_t5_dl').href = t5Url;
                }
            }

            // Fetch granular details
            fetch('ajax_ai_analyzer.php?action=get_deep_details&verification_id=' + data.id)
                .then(res => res.json())
                .then(json => {
                    if (json.success) {
                        renderGranularAnalysis(json);
                        renderPdcaSection(json);
                    } else {
                        console.error("API Error: ", json.error);
                        alert("แจ้งเตือนจากระบบ: " + json.error);
                    }
                })
                .catch(err => console.error("Error fetching details", err));

            evalModal.show();
        }

        function toggleHumanOverride(toggleObj) {
            const isManual = toggleObj.checked;
            document.getElementById('humanOverrideModeInput').value = isManual ? '1' : '0';
            document.getElementById('chk_bloom').disabled = !isManual;
            document.getElementById('chk_map').disabled = !isManual;
            document.getElementById('chk_activity').disabled = !isManual;
        }

        function renderGranularAnalysis(json) {
            const renderMissingInline = (value, emptyLabel = 'ไม่พบข้อมูล') => {
                if (value === null || value === undefined) {
                    return `<span class="text-muted">${emptyLabel}</span>`;
                }

                const normalized = String(value).trim();
                if (normalized === '' || normalized === '-') {
                    return `<span class="text-muted">${emptyLabel}</span>`;
                }

                return escapeHtml(normalized);
            };

            // Render Global Progress bars
            const sBloom = parseFloat(json.global_scores.score_bloom) || 0;
            const sPlo = parseFloat(json.global_scores.score_plo) || 0;
            const sAct = parseFloat(json.global_scores.score_activity) || 0;

            document.getElementById('txt_score_bloom').textContent = sBloom + '%';
            document.getElementById('pb_bloom').style.width = sBloom + '%';
            document.getElementById('pb_bloom').className = 'progress-bar ' + (sBloom > 80 ? 'bg-success' : (sBloom > 50 ? 'bg-warning' : 'bg-danger'));

            document.getElementById('txt_score_plo').textContent = sPlo + '%';
            document.getElementById('pb_plo').style.width = sPlo + '%';
            document.getElementById('pb_plo').className = 'progress-bar ' + (sPlo > 80 ? 'bg-success' : (sPlo > 50 ? 'bg-warning' : 'bg-danger'));

            document.getElementById('txt_score_act').textContent = sAct + '%';
            document.getElementById('pb_act').style.width = sAct + '%';
            document.getElementById('pb_act').className = 'progress-bar ' + (sAct > 80 ? 'bg-success' : (sAct > 50 ? 'bg-warning' : 'bg-danger'));

            // 1. Bloom Accordion
            const tbBloom = document.getElementById('tbody_bloom');
            tbBloom.innerHTML = '';
            if (json.bloom_analysis && json.bloom_analysis.length > 0) {
                json.bloom_analysis.forEach(c => {
                    let bloomClass = 'badge bg-secondary';
                    if (c.bloom_level && c.bloom_level.includes('Apply')) bloomClass = 'badge bg-info text-dark';
                    else if (c.bloom_level && c.bloom_level.includes('Analyze')) bloomClass = 'badge bg-primary';
                    else if (c.bloom_level && c.bloom_level.includes('Create')) bloomClass = 'badge bg-success';
                    else if (c.bloom_level && c.bloom_level.includes('Evaluate')) bloomClass = 'badge bg-warning text-dark';

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="fw-bold text-center">${c.clo_code || '-'}</td>
                        <td>${c.clo_text || '-'}</td>
                        <td class="text-primary fw-medium">${c.bloom_verb || '-'}</td>
                        <td><span class="${bloomClass}">${c.bloom_level || '-'}</span></td>
                        <td class="text-muted small">${c.suggestion || '-'}</td>
                    `;
                    tbBloom.appendChild(tr);
                });
            } else {
                tbBloom.innerHTML = '<tr><td colspan="5" class="text-center text-muted">ไม่พบข้อมูล (ต้องรัน AI ก่อน)</td></tr>';
            }

            // 2. PLO Accordion
            const tbPlo = document.getElementById('tbody_plo');
            tbPlo.innerHTML = '';
            if (json.plo_coverage && json.plo_coverage.length > 0) {
                json.plo_coverage.forEach(p => {
                    const pct = parseFloat(p.coverage_percent) || 0;
                    const pctClass = pct >= 100 ? 'text-success' : (pct > 0 ? 'text-warning' : 'text-danger fw-bold');
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="fw-bold text-center">${p.plo_code || '-'}</td>
                        <td class="small">${p.plo_text || '-'}</td>
                        <td class="${pctClass} fw-bold text-center">${pct}%</td>
                        <td><span class="badge border border-secondary text-secondary">${p.contributing_clos || '-'}</span></td>
                        <td class="text-muted small text-danger">${p.suggestion || '-'}</td>
                    `;
                    tbPlo.appendChild(tr);
                });
            } else {
                tbPlo.innerHTML = '<tr><td colspan="5" class="text-center text-muted">ไม่พบข้อมูล</td></tr>';
            }

            // 3. Activity Accordion
            const tbAct = document.getElementById('tbody_act');
            tbAct.innerHTML = '';
            if (json.activity_mapping && json.activity_mapping.length > 0) {
                json.activity_mapping.forEach(a => {
                    const pct = parseFloat(a.contribution_percent) || 0;
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="fw-medium">${a.activity_name || '-'}</td>
                        <td><span class="badge bg-light text-dark border">${a.target_clo || '-'}</span></td>
                        <td class="text-center fw-bold text-info">${pct}%</td>
                        <td class="text-muted small">${a.suggestion || '-'}</td>
                    `;
                    tbAct.appendChild(tr);
                });
            } else {
                tbAct.innerHTML = '<tr><td colspan="4" class="text-center text-muted">ไม่พบข้อมูล</td></tr>';
            }

            // 4. CLO Evaluations
            const tbCLO = document.getElementById('tbody_clo');
            tbCLO.innerHTML = '';
            if (json.clo_evals && json.clo_evals.length > 0) {
                json.clo_evals.forEach(c => {
                    const selPending = c.committee_status === 'Pending' ? 'selected' : '';
                    const selApproved = c.committee_status === 'Approved' ? 'selected' : '';
                    const selRejected = c.committee_status === 'Rejected' ? 'selected' : '';
                    const cmt = c.committee_comment || '';
                    const cloDesc = c.clo_description || '';
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="fw-bold" title="${cloDesc.replace(/"/g, '&quot;')}">${c.clo_code || '-'}</td>
                        <td class="text-center">${renderMissingInline(c.target_percent, 'ไม่พบข้อมูล')}</td>
                        <td class="text-center fw-bold text-primary">${renderMissingInline(c.actual_percent, 'ไม่พบข้อมูล')}</td>
                        <td><small class="text-secondary">${renderMissingInline(c.problem_found, 'ไม่พบข้อมูลในเอกสาร')}</small></td>
                        <td><small class="text-success">${renderMissingInline(c.improvement_plan, 'ไม่พบข้อมูลในเอกสาร')}</small></td>
                        <td>
                            <select class="form-select form-select-sm mb-1" onchange="saveCloFeedback(${c.id}, this.value, 'status')">
                                <option value="Pending" ${selPending}>⏳ รอพิจารณา</option>
                                <option value="Approved" ${selApproved}>✅ รับรองตามผล</option>
                                <option value="Rejected" ${selRejected}>❌ ไม่รับรอง/แก้</option>
                            </select>
                            <input type="text" class="form-control form-control-sm" placeholder="ข้อเสนอแนะกรรมการ..." value="${cmt}" onblur="saveCloFeedback(${c.id}, this.value, 'comment')">
                        </td>
                    `;
                    tbCLO.appendChild(tr);
                });
            } else {
                tbCLO.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">ไม่พบข้อมูล หรือ มคอ.5 ไม่ได้ระบุแยกผลสัมฤทธิ์รายข้อเอาไว้</td></tr>';
            }

            document.getElementById('ai_deep_analysis_container').classList.remove('d-none');
        }

        function renderPdcaSection(json) {
            const summary = json.pdca_summary || {};
            document.getElementById('pdca_status').value = summary.pdca_status || 'not_started';
            document.getElementById('pdca_resolution_percent').value = parseFloat(summary.pdca_resolution_percent || 0);
            document.getElementById('pdca_last_year_summary').value = summary.pdca_last_year_summary || '';
            document.getElementById('pdca_current_action').value = summary.pdca_current_action || '';
            document.getElementById('pdca_evidence_note').value = summary.pdca_evidence_note || '';

            const issueBody = document.getElementById('tbody_pdca_issues');
            const issueCount = document.getElementById('pdca_issue_count');
            const issues = Array.isArray(json.pdca_issues) ? json.pdca_issues : [];
            issueCount.textContent = `${issues.length} ประเด็น`;
            issueBody.innerHTML = '';

            if (issues.length === 0) {
                issueBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">ยังไม่มีประเด็น PDCA สำหรับรายวิชานี้</td></tr>';
                return;
            }

            issues.forEach(issue => {
                const confidence = parseFloat(issue.category_confidence || 0);
                const confidenceClass = confidence >= 80 ? 'bg-success'
                    : (confidence >= 60 ? 'bg-warning text-dark' : 'bg-danger');
                const categoryClassMap = {
                    bloom: 'bg-primary',
                    plo: 'bg-success',
                    activity: 'bg-info text-dark',
                    clo_result: 'bg-warning text-dark',
                    document: 'bg-secondary',
                    assessment: 'bg-danger',
                    other: 'bg-light text-dark border'
                };
                const categoryClass = categoryClassMap[issue.issue_category] || 'bg-light text-dark border';
                const inferenceLabel = issue.category_inferred_by === 'manual' ? 'manual'
                    : (issue.category_inferred_by === 'rule_based' ? 'auto' : 'ai');
                const confidenceAdvice = confidence >= 80
                    ? 'เชื่อถือได้ค่อนข้างมาก'
                    : (confidence >= 60
                        ? 'ควรอ่านเอกสารทวนอีกครั้งก่อนยืนยัน'
                        : 'ความเชื่อมั่นต่ำ ควรเปิดเอกสารอ่านเองหรือแจ้งผู้สอนปรับแก้เอกสาร');
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>
                        <div><span class="badge ${categoryClass}">${escapeHtml(issue.issue_category || 'other')}</span></div>
                        <div class="mt-1"><span class="badge ${confidenceClass}">${escapeHtml(inferenceLabel)} ${escapeHtml(confidence)}%</span></div>
                        <div class="small text-muted mt-1">${escapeHtml(issue.category_reason || 'ไม่พบเหตุผลประกอบ')}</div>
                        <div class="small ${confidence >= 80 ? 'text-success' : (confidence >= 60 ? 'text-warning' : 'text-danger')} mt-1">${escapeHtml(confidenceAdvice)}</div>
                    </td>
                    <td class="fw-medium">${escapeHtml(issue.issue_title || '-')}</td>
                    <td class="small">${escapeHtml(issue.issue_detail || '-')}</td>
                    <td>
                        <select class="form-select form-select-sm" onchange="savePdcaIssue(${issue.id}, this.closest('tr'))">
                            <option value="open" ${issue.current_status === 'open' ? 'selected' : ''}>เปิดประเด็น</option>
                            <option value="in_progress" ${issue.current_status === 'in_progress' ? 'selected' : ''}>กำลังแก้ไข</option>
                            <option value="partially_resolved" ${issue.current_status === 'partially_resolved' ? 'selected' : ''}>แก้ได้บางส่วน</option>
                            <option value="resolved" ${issue.current_status === 'resolved' ? 'selected' : ''}>แก้ไขแล้ว</option>
                            <option value="carried_forward" ${issue.current_status === 'carried_forward' ? 'selected' : ''}>ยกไปรอบถัดไป</option>
                        </select>
                    </td>
                    <td><input type="number" class="form-control form-control-sm" min="0" max="100" step="1" value="${escapeHtml(issue.resolution_percent || 0)}" onblur="savePdcaIssue(${issue.id}, this.closest('tr'))"></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" onclick="savePdcaIssue(${issue.id}, this.closest('tr'))"><i class="bi bi-save"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deletePdcaIssue(${issue.id})"><i class="bi bi-trash"></i></button>
                        <input type="hidden" class="pdca-category" value="${escapeHtml(issue.issue_category || 'other')}">
                        <input type="hidden" class="pdca-title" value="${escapeHtml(issue.issue_title || '')}">
                        <input type="hidden" class="pdca-detail" value="${escapeHtml(issue.issue_detail || '')}">
                    </td>
                `;
                issueBody.appendChild(tr);
            });
        }

        function saveCloFeedback(clo_id, value, field) {
            let formData = new FormData();
            formData.append('action', 'save_clo_feedback');
            formData.append('clo_id', clo_id);
            formData.append('field', field);
            formData.append('value', value);

            fetch('ajax_save_clo_feedback.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        alert('บันทึกผิดพลาด: ' + data.error);
                    }
                })
                .catch(err => console.error(err));
        }

        function resetPdcaIssueComposer() {
            document.getElementById('pdca_issue_category').value = 'other';
            document.getElementById('pdca_issue_title').value = '';
            document.getElementById('pdca_issue_detail').value = '';
            document.getElementById('pdca_issue_status').value = 'open';
            document.getElementById('pdca_issue_resolution_percent').value = 0;
        }

        function savePdcaIssue(issueId = null, row = null) {
            const formData = new FormData();
            formData.append('action', 'save_pdca_issue');
            formData.append('verification_id', document.getElementById('vidInput').value);

            if (issueId) {
                formData.append('pdca_issue_id', issueId);
                formData.append('issue_category', row.querySelector('.pdca-category').value);
                formData.append('issue_title', row.querySelector('.pdca-title').value);
                formData.append('issue_detail', row.querySelector('.pdca-detail').value);
                formData.append('current_status', row.querySelector('select').value);
                formData.append('resolution_percent', row.querySelector('input[type="number"]').value);
            } else {
                formData.append('issue_category', document.getElementById('pdca_issue_category').value);
                formData.append('issue_title', document.getElementById('pdca_issue_title').value);
                formData.append('issue_detail', document.getElementById('pdca_issue_detail').value);
                formData.append('current_status', document.getElementById('pdca_issue_status').value);
                formData.append('resolution_percent', document.getElementById('pdca_issue_resolution_percent').value);
            }

            fetch('ajax_pdca_tracking.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        alert('บันทึก PDCA issue ไม่สำเร็จ: ' + (data.error || 'unknown error'));
                        return;
                    }

                    if (!issueId) {
                        resetPdcaIssueComposer();
                    }

                    fetch('ajax_ai_analyzer.php?action=get_deep_details&verification_id=' + document.getElementById('vidInput').value)
                        .then(res => res.json())
                        .then(json => {
                            if (json.success) {
                                renderPdcaSection(json);
                            }
                        });
                })
                .catch(err => alert('เกิดข้อผิดพลาดในการบันทึก PDCA issue: ' + err.message));
        }

        function deletePdcaIssue(issueId) {
            if (!confirm('ต้องการลบประเด็น PDCA นี้ใช่หรือไม่')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete_pdca_issue');
            formData.append('pdca_issue_id', issueId);

            fetch('ajax_pdca_tracking.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        alert('ลบ PDCA issue ไม่สำเร็จ: ' + (data.error || 'unknown error'));
                        return;
                    }

                    fetch('ajax_ai_analyzer.php?action=get_deep_details&verification_id=' + document.getElementById('vidInput').value)
                        .then(res => res.json())
                        .then(json => {
                            if (json.success) {
                                renderPdcaSection(json);
                            }
                        });
                })
                .catch(err => alert('เกิดข้อผิดพลาดในการลบ PDCA issue: ' + err.message));
        }

        function importReviewerImprovementToPdca() {
            const verificationId = document.getElementById('vidInput').value;
            const improvementText = (document.getElementById('reviewer_improvement').value || '').trim();

            if (!improvementText) {
                alert('ยังไม่มีข้อความในช่องจุดที่ควรพัฒนาให้แปลงเป็น PDCA issue');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'import_reviewer_improvement');
            formData.append('verification_id', verificationId);
            formData.append('reviewer_improvement', improvementText);

            fetch('ajax_pdca_tracking.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        alert('แปลง reviewer_improvement เป็น PDCA issue ไม่สำเร็จ: ' + (data.error || 'unknown error'));
                        return;
                    }

                    fetch('ajax_ai_analyzer.php?action=get_deep_details&verification_id=' + verificationId)
                        .then(res => res.json())
                        .then(json => {
                            if (json.success) {
                                renderPdcaSection(json);
                            }
                        });

                    const createdCount = parseInt(data.created_count || 0, 10);
                    alert(createdCount > 0
                        ? `สร้าง PDCA issue ใหม่ ${createdCount} ประเด็นแล้ว`
                        : 'ไม่พบประเด็นใหม่ที่ควรสร้างเพิ่มเติม');
                })
                .catch(err => alert('เกิดข้อผิดพลาดในการแปลงเป็น PDCA issue: ' + err.message));
        }

        function formatAiWarnings(warnings) {
            if (!Array.isArray(warnings) || warnings.length === 0) {
                return '';
            }

            return warnings
                .filter(w => typeof w === 'string' && w.trim() !== '')
                .map(w => `- ${w}`)
                .join('<br>');
        }

        function renderAnalysisMeta(debugInfo) {
            const container = document.getElementById('aiAnalysisMeta');
            const resultAlert = document.getElementById('aiResultAlert');
            if (!container) {
                return;
            }

            const quality = debugInfo && debugInfo.analysis_quality ? debugInfo.analysis_quality : null;
            const sourceKind = debugInfo && debugInfo.tqf5_source_kind ? debugInfo.tqf5_source_kind : '';
            const badges = [];
            const notes = [];

            if (sourceKind === 'legacy_doc') {
                badges.push('<span class="badge bg-warning text-dark me-2">legacy .doc</span>');
                notes.push('ผลรอบนี้อ้างอิงไฟล์ มคอ.5 แบบ legacy .doc ซึ่งมีโอกาสแยกข้อความผิดตำแหน่ง');
            } else if (sourceKind === 'pdf') {
                badges.push('<span class="badge bg-danger me-2">PDF จำกัดการอ่าน</span>');
                notes.push('มคอ.5 เป็น PDF ทำให้ระบบอ่านเชิงโครงสร้างได้จำกัด');
            } else if (sourceKind === 'missing') {
                badges.push('<span class="badge bg-secondary me-2">ไม่มี มคอ.5 ที่อ่านได้</span>');
                notes.push('ผลรอบนี้อาจอิงจาก มคอ.3 เป็นหลัก เพราะไม่พบ มคอ.5 ที่อ่านได้');
            }

            if (quality && Array.isArray(quality.reasons)) {
                quality.reasons = quality.reasons.map(reason => {
                    if (reason.includes('ข้อความดิบทีละบรรทัด')) {
                        return `${reason} กล่าวง่าย ๆ คือระบบไม่ได้อ่านจากตารางที่จัดรูปแบบชัดเจน จึงอาจจับค่าบางช่องสลับกันหรือไม่ครบ`;
                    }
                    return reason;
                });
            }

            if (quality) {
                const levelClass = quality.level === 'high'
                    ? 'bg-success'
                    : (quality.level === 'medium' ? 'bg-warning text-dark' : 'bg-danger');
                badges.push(`<span class="badge ${levelClass}">Evidence Quality: ${escapeHtml(quality.label)} ${escapeHtml(quality.score)}%</span>`);
                if (Array.isArray(quality.reasons)) {
                    notes.push(...quality.reasons);
                }
            }

            if (badges.length === 0 && notes.length === 0) {
                container.classList.add('d-none');
                container.innerHTML = '';
                if (resultAlert) {
                    resultAlert.classList.remove('alert-warning', 'alert-danger');
                    resultAlert.classList.add('alert-success');
                }
                return;
            }

            if (resultAlert && quality) {
                resultAlert.classList.remove('alert-success', 'alert-warning', 'alert-danger');
                if (quality.level === 'high') {
                    resultAlert.classList.add('alert-success');
                } else if (quality.level === 'medium') {
                    resultAlert.classList.add('alert-warning');
                } else {
                    resultAlert.classList.add('alert-danger');
                }
            }

            const uniqueNotes = [...new Set(notes.filter(Boolean))];
            container.classList.remove('d-none');
            container.innerHTML = `
                <div class="alert alert-light border small mb-0 py-2">
                    <div class="mb-1">${badges.join('')}</div>
                    ${uniqueNotes.length > 0 ? `<div class="text-muted">${uniqueNotes.map(note => `- ${escapeHtml(note)}`).join('<br>')}</div>` : ''}
                </div>
            `;
        }

        function getAiFileIssue(file, label, requiredDocx) {
            if (!file) {
                return '';
            }

            const fileName = (file.name || '').toLowerCase();
            if (fileName.endsWith('.pdf')) {
                return `${label} เป็น PDF ระบบจะไม่สามารถอ่านวิเคราะห์ได้ กรุณาใช้ไฟล์ .docx`;
            }

            if (fileName.endsWith('.doc')) {
                return `${label} เป็น Word รุ่นเก่า (.doc) ระบบจะไม่สามารถอ่านวิเคราะห์ได้ กรุณาแปลงเป็น .docx`;
            }

            if (!fileName.endsWith('.docx')) {
                return `${label} ไม่ใช่ไฟล์ .docx ที่ระบบรองรับ`;
            }

            if (requiredDocx && !fileName.endsWith('.docx')) {
                return `${label} ต้องเป็นไฟล์ .docx`;
            }

            return '';
        }

        function updateAiFileBadge(inputId, badgeId, label, requiredDocx) {
            const input = document.getElementById(inputId);
            const badge = document.getElementById(badgeId);
            if (!input || !badge) {
                return;
            }

            const file = input.files && input.files[0] ? input.files[0] : null;
            if (!file) {
                badge.className = 'mt-1 small d-none';
                badge.innerHTML = '';
                return;
            }

            const issue = getAiFileIssue(file, label, requiredDocx);
            if (issue) {
                const toneClass = file.name.toLowerCase().endsWith('.pdf') || file.name.toLowerCase().endsWith('.doc')
                    ? 'alert alert-warning'
                    : 'alert alert-danger';
                badge.className = `${toneClass} mt-1 mb-0 py-1 px-2 small`;
                badge.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i>${issue}`;
                return;
            }

            badge.className = 'alert alert-success mt-1 mb-0 py-1 px-2 small';
            badge.innerHTML = `<i class="bi bi-check-circle-fill me-1"></i>${label} พร้อมวิเคราะห์แล้ว`;
        }

        async function runAiAnalysis() {
            const vid = document.getElementById('vidInput').value;
            const t3File = document.getElementById('ai_tqf3').files[0];
            const t5File = document.getElementById('ai_tqf5').files[0];
            const t3Url = document.getElementById('sys_tqf3_url').value;
            const t5Url = document.getElementById('sys_tqf5_url').value;

            if (!t3File && !t3Url) {
                alert("จำเป็นต้องเลือกไฟล์ หรือ มีลิงก์ มคอ.3 อยู่ในระบบเพื่อทำการวิเคราะห์ครับ");
                return;
            }

            const t3Issue = getAiFileIssue(t3File, 'มคอ.3', true);
            if (t3Issue) {
                document.getElementById('aiErrorAlert').classList.remove('d-none');
                document.getElementById('aiErrorMsg').innerHTML = t3Issue;
                return;
            }

            const t5Issue = getAiFileIssue(t5File, 'มคอ.5', false);

            document.getElementById('aiLoadingIndicator').classList.remove('d-none');
            document.getElementById('aiResultAlert').classList.add('d-none');
            document.getElementById('aiResultAlert').classList.remove('alert-warning', 'alert-danger');
            document.getElementById('aiResultAlert').classList.add('alert-success');
            document.getElementById('aiAnalysisMeta').classList.add('d-none');
            document.getElementById('aiAnalysisMeta').innerHTML = '';
            document.getElementById('aiErrorAlert').classList.add('d-none');

            let formData = new FormData();
            formData.append('action', 'run_ai');
            formData.append('verification_id', vid);

            if (t3File) formData.append('tqf3_file', t3File);
            else formData.append('tqf3_url', t3Url);

            if (t5File) formData.append('tqf5_file', t5File);
            else if (t5Url) formData.append('tqf5_url', t5Url);

            try {
                let response = await fetch('ajax_ai_analyzer.php', {
                    method: 'POST',
                    body: formData
                });
                let result = await response.json();

                document.getElementById('aiLoadingIndicator').classList.add('d-none');

                if (result.success) {
                    const aiData = result.data;
                    latestAiDebugInfo = result.debug_info || null;
                    document.getElementById('humanOverrideToggle').checked = false;
                    toggleHumanOverride(document.getElementById('humanOverrideToggle'));

                    document.getElementById('chk_bloom').checked = (parseFloat(aiData.score_bloom) || 0) >= aiAutoPassThreshold;
                    document.getElementById('chk_map').checked = (parseFloat(aiData.score_plo) || 0) >= aiAutoPassThreshold;
                    document.getElementById('chk_activity').checked = (parseFloat(aiData.score_activity) || 0) >= aiAutoPassThreshold;

                    document.getElementById('reviewer_strength').value = aiData.reviewer_strength || "";
                    document.getElementById('reviewer_improvement').value = aiData.reviewer_improvement || "";

                    // Fetch and re-render the stored DB values
                    fetch('ajax_ai_analyzer.php?action=get_deep_details&verification_id=' + vid)
                        .then(res => res.json())
                        .then(json => {
                            if (json.success) {
                                renderGranularAnalysis(json);
                                renderPdcaSection(json);
                                renderAiDebugPanel(latestAiDebugInfo);
                                renderAnalysisMeta(latestAiDebugInfo);
                            }
                        });

                    document.getElementById('aiResultAlert').classList.remove('d-none');
                    if (t5Issue || (Array.isArray(result.warnings) && result.warnings.length > 0)) {
                        document.getElementById('aiErrorAlert').classList.remove('d-none');
                        document.getElementById('aiErrorAlert').classList.remove('alert-danger');
                        document.getElementById('aiErrorAlert').classList.add('alert-warning');
                        document.getElementById('aiErrorMsg').innerHTML = formatAiWarnings([t5Issue].concat(result.warnings || []));
                    }
                } else {
                    document.getElementById('aiErrorAlert').classList.remove('d-none');
                    document.getElementById('aiErrorAlert').classList.remove('alert-warning');
                    document.getElementById('aiErrorAlert').classList.add('alert-danger');
                    document.getElementById('aiErrorMsg').innerHTML = result.error || "เกิดข้อผิดพลาดในการวิเคราะห์";
                }
            } catch (err) {
                document.getElementById('aiLoadingIndicator').classList.add('d-none');
                document.getElementById('aiAnalysisMeta').classList.add('d-none');
                document.getElementById('aiAnalysisMeta').innerHTML = '';
                document.getElementById('aiErrorAlert').classList.remove('d-none');
                document.getElementById('aiErrorAlert').classList.remove('alert-warning');
                document.getElementById('aiErrorAlert').classList.add('alert-danger');
                document.getElementById('aiErrorMsg').innerHTML = "Error calling server: " + err.message;
            }
        }

        document.getElementById('ai_tqf3').addEventListener('change', function () {
            updateAiFileBadge('ai_tqf3', 'ai_tqf3_badge', 'มคอ.3', true);
        });

        document.getElementById('ai_tqf5').addEventListener('change', function () {
            updateAiFileBadge('ai_tqf5', 'ai_tqf5_badge', 'มคอ.5', false);
        });

        const aiAutoPassThresholdInput = document.getElementById('ai_auto_pass_threshold_input');
        if (aiAutoPassThresholdInput) {
            aiAutoPassThresholdInput.addEventListener('input', function () {
                updateAiAutoPassBadge(this.value);
            });

            aiAutoPassThresholdInput.addEventListener('change', function () {
                const normalized = normalizeAiAutoPassThreshold(this.value);
                this.value = normalized;
                updateAiAutoPassBadge(normalized);
            });
        }

        // --- Dynamic Model Discovery ---
        async function fetchAvailableModels() {
            const select = document.getElementById('gemini_api_model');
            const currentModel = "<?= $current_api_model ?>";

            // Show loading state
            const originalText = select.options[0] ? select.options[0].text : '';
            if (select.options.length <= 1) {
                select.innerHTML = '<option value="">⏳ กำลังดึงรายชื่อโมเดลล่าสุดจาก Google...</option>';
            }

            try {
                const response = await fetch('ajax_ai_analyzer.php?action=get_available_models');
                const result = await response.json();

                if (result.success && result.models) {
                    select.innerHTML = '';
                    result.models.forEach(m => {
                        const opt = document.createElement('option');
                        const modelId = m.name.replace('models/', '');
                        const displayName = m.displayName || m.name;
                        opt.value = modelId;
                        opt.textContent = (modelId.includes('3.') || modelId.includes('2.')) ? '✨ ' + displayName : displayName;
                        if (modelId === currentModel) opt.selected = true;
                        select.appendChild(opt);
                    });
                } else if (result.error) {
                    console.warn("Model fetch error:", result.error);
                    // Keep current model if fetch fails
                    select.innerHTML = `<option value="${currentModel}" selected>${currentModel} (ไม่สามารถอัปเดตรายชื่อได้)</option>`;
                }
            } catch (err) {
                console.error("Failed to fetch models:", err);
            }
        }

        // Trigger fetch when Settings Modal is shown
        document.getElementById('settingsModal').addEventListener('shown.bs.modal', function () {
            if (aiAutoPassThresholdInput) {
                aiAutoPassThresholdInput.value = aiAutoPassThreshold;
                updateAiAutoPassBadge(aiAutoPassThresholdInput.value);
            }
            fetchAvailableModels();
        });

        document.getElementById('settingsModal').addEventListener('hidden.bs.modal', function () {
            if (aiAutoPassThresholdInput) {
                aiAutoPassThresholdInput.value = savedAiAutoPassThreshold;
            }
            updateAiAutoPassBadge(savedAiAutoPassThreshold);
        });

        function submitDeleteEvaluation(event, verificationId) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }

            if (!verificationId) {
                return false;
            }

            if (!confirm('รายวิชาและข้อมูลการประเมินนี้จะถูกลบออก ถ้ายืนยันกด OK')) {
                return false;
            }

            const form = document.getElementById('deleteEvaluationForm');
            const idInput = document.getElementById('deleteEvaluationId');
            if (!form || !idInput) {
                return false;
            }

            idInput.value = verificationId;
            form.submit();
            return false;
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (!initialOpenVerificationId) {
                return;
            }

            const targetRecord = verificationRecords.find(record => parseInt(record.id, 10) === parseInt(initialOpenVerificationId, 10));
            if (targetRecord) {
                openEvalModal(targetRecord);
            }
        });
    </script>
</body>

</html>
