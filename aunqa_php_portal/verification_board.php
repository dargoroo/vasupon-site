<?php
// verification_board.php - กระดานหน้าสำหรับกรรมการประเมินหลักฐานลายนิ้วมือการทวนสอบ (Tracking Dashboard)
require_once '../config.php'; // สมมติว่ามีไฟล์ config.php อยู่ที่ root ถ้าไม่มีให้แก้ปรับบรรทัดนี้ หรือใช้ตัวแปรตรง
// Fallback กรณีไม่มี config.php
$db_host = isset($DB_HOST) ? $DB_HOST : 'localhost';
$db_name = isset($DB_NAME) ? $DB_NAME : 'vasupon_p';
$db_user = isset($DB_USER) ? $DB_USER : 'root';
$db_pass = getenv('DB_PASS') ?: 'your_db_password';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Auto add columns if not exists
    try {
        $pdo->exec("ALTER TABLE aunqa_verification_records ADD COLUMN tqf3_link VARCHAR(500) DEFAULT ''");
        $pdo->exec("ALTER TABLE aunqa_verification_records ADD COLUMN tqf5_link VARCHAR(500) DEFAULT ''");
    } catch(PDOException $e) {}

    // Auto create new tables for AI integration
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `aunqa_verification_clo_details` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `verification_id` INT NOT NULL,
          `clo_code` VARCHAR(20) NOT NULL,
          `clo_text` TEXT NOT NULL,
          `bloom_verb` VARCHAR(100),
          `bloom_level` VARCHAR(100),
          `mapped_plos` VARCHAR(255),
          `activities` TEXT,
          FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `aunqa_verification_matrix` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `verification_id` INT NOT NULL,
          `clo_code` VARCHAR(20) NOT NULL,
          `plo_code` VARCHAR(20) NOT NULL,
          `weight_percentage` DECIMAL(5,2) DEFAULT 0.00,
          FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `aunqa_settings` (
          `setting_key` VARCHAR(50) PRIMARY KEY,
          `setting_value` TEXT NOT NULL,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    
} catch (PDOException $e) {
    // ถ้าต่อ DB ไม่ได้ ให้ใช้ Mock Mode สำหรับสาธิต UI
    $pdo = null;
    $mock_mode = true;
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
        $chk_bloom = isset($_POST['chk_bloom']) ? 1 : 0;
        $chk_map = isset($_POST['chk_map']) ? 1 : 0;
        $chk_activity = isset($_POST['chk_activity']) ? 1 : 0;
        $strength = $_POST['reviewer_strength'];
        $improvement = $_POST['reviewer_improvement'];

        $stmtUpdateCL = $pdo->prepare("UPDATE aunqa_verification_checklists SET check_clo_verb=:c1, check_clo_plo_map=:c2, check_class_activity=:c3, reviewer_strength=:str, reviewer_improvement=:imp WHERE verification_id=:vid");
        $stmtUpdateCL->execute([
            ':c1' => $chk_bloom,
            ':c2' => $chk_map,
            ':c3' => $chk_activity,
            ':str' => $strength,
            ':imp' => $improvement,
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
        $vid = $_POST['verification_id'];
        // ลบข้อมูล (ด้วย ON DELETE CASCADE ในฐานข้อมูล จะลบ checklist ออกให้อัตโนมัติด้วย)
        $stmtDelete = $pdo->prepare("DELETE FROM aunqa_verification_records WHERE id=:vid");
        $stmtDelete->execute([':vid' => $vid]);
    }
}

// 4. บันทึกการตั้งค่า AI
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'save_settings' && $pdo) {
        $api_key = $_POST['gemini_api_key'];
        $api_model = isset($_POST['gemini_api_model']) ? $_POST['gemini_api_model'] : 'gemini-2.5-flash';
        
        $stmtSet = $pdo->prepare("INSERT INTO aunqa_settings (setting_key, setting_value) VALUES ('gemini_api_key', :v) ON DUPLICATE KEY UPDATE setting_value = :v");
        $stmtSet->execute([':v' => $api_key]);
        
        $stmtSetModel = $pdo->prepare("INSERT INTO aunqa_settings (setting_key, setting_value) VALUES ('gemini_api_model', :m) ON DUPLICATE KEY UPDATE setting_value = :m");
        $stmtSetModel->execute([':m' => $api_model]);
    } else if ($_POST['action'] == 'delete_api_key' && $pdo) {
        $stmtDel = $pdo->prepare("DELETE FROM aunqa_settings WHERE setting_key = 'gemini_api_key'");
        $stmtDel->execute();
    }
}

// ดึงค่าการตั้งค่าเพื่อเอาไปแสดงผล
$current_api_key = '';
$current_api_model = 'gemini-2.5-flash';
if ($pdo) {
    $stmtSet = $pdo->query("SELECT setting_key, setting_value FROM aunqa_settings WHERE setting_key IN ('gemini_api_key', 'gemini_api_model')");
    while($row = $stmtSet->fetch()) {
        if($row['setting_key'] == 'gemini_api_key') $current_api_key = $row['setting_value'];
        if($row['setting_key'] == 'gemini_api_model') $current_api_model = $row['setting_value'];
    }
}

// 4. ดึงรายการรายวิชาที่อยู่ในระบบ เพื่อแสดงผลบนกระดาน
$records = [];
if ($pdo) {
    // JOIN 2 tables มาโชว์
    $stmtQuery = $pdo->prepare("
        SELECT r.*, c.check_clo_verb, c.check_clo_plo_map, c.check_class_activity, c.reviewer_strength, c.reviewer_improvement 
        FROM aunqa_verification_records r 
        LEFT JOIN aunqa_verification_checklists c ON r.id = c.verification_id
        ORDER BY r.year DESC, r.semester ASC
    ");
    $stmtQuery->execute();
    $records = $stmtQuery->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Mock Data ถ้ายังสร้างฐานข้อมูลไม่สำเร็จ
    $records = [
        [
            'id' => 1,
            'year' => '2568',
            'semester' => '2',
            'course_code' => '9062081',
            'course_name' => 'Computer Programming',
            'instructor' => 'Vasupon P.',
            'tqf3_link' => '#',
            'tqf5_link' => '#',
            'verification_status' => 'รอรับเอกสาร',
            'check_clo_verb' => 0,
            'check_clo_plo_map' => 0,
            'check_class_activity' => 0,
            'reviewer_strength' => '',
            'reviewer_improvement' => ''
        ]
    ];
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
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#settingsModal"><i class="bi bi-gear-fill"></i> ตั้งค่า AI Assistant</button>
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
            <div class="col-md-12 d-flex justify-content-between align-items-center">
                <h4 class="fw-bold text-dark"><i class="bi bi-list-task"></i> รายการวิชาที่รอประเมิน</h4>
            </div>
        </div>

        <!-- ลิสต์วิชาที่ถูกเลือก -->
        <div class="row g-3">
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
                                    </div>
                                    <form method="POST" action="verification_board.php" onsubmit="return confirm('รายวิชาและข้อมูลการประเมินนี้จะถูกลบออก ถ้ายืนยันกด OK');" onclick="event.stopPropagation()">
                                        <input type="hidden" name="action" value="delete_evaluation">
                                        <input type="hidden" name="verification_id" value="<?= $rec['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger border-0 p-1" title="ลบวิชานี้ออกจากการประเมิน">
                                            <i class="bi bi-trash3-fill"></i>
                                        </button>
                                    </form>
                                </div>
                                <h5 class="fw-bold text-dark mb-1 text-truncate" title="<?= $rec['course_name'] ?>">
                                    <?= $rec['course_name'] ?></h5>
                                <p class="text-muted small mb-3">รหัส: <?= $rec['course_code'] ?> | ผู้สอน:
                                    <?= $rec['instructor'] ?></p>

                                <div class="mb-3 d-flex gap-2">
                                    <?php if(!empty($rec['tqf3_link'])): ?>
                                        <a href="<?= $rec['tqf3_link'] ?>" target="_blank" class="badge bg-light text-primary border text-decoration-none" onclick="event.stopPropagation()"><i class="bi bi-link-45deg"></i> มคอ.3</a>
                                    <?php endif; ?>
                                    <?php if(!empty($rec['tqf5_link'])): ?>
                                        <a href="<?= $rec['tqf5_link'] ?>" target="_blank" class="badge bg-light text-success border text-decoration-none" onclick="event.stopPropagation()"><i class="bi bi-link-45deg"></i> มคอ.5</a>
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
    <div class="modal fade" id="evalModal" tabindex="-1" aria-hidden="true">
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

                        <div class="alert alert-info border-info" style="background-color: #e3f2fd;">
                            <h6 class="fw-bold mb-1" id="mCourseName">-</h6>
                            <p class="mb-0 small" id="mCourseDetails">-</p>
                        </div>

                        <!-- AI Assisted Upload Section -->
                        <div class="card mb-4 border-primary">
                            <div class="card-header bg-primary text-white py-2">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-robot"></i> ผู้ช่วย AI ทวนสอบวิเคราะห์อัตโนมัติ</h6>
                            </div>
                            <div class="card-body bg-light">
                                <p class="small text-muted mb-2">อัปโหลดไฟล์ <code>.docx</code> เพื่อให้ AI ช่วยสกัดข้อมูลและร่องรอยการประเมิน Checklist ให้โดยอัตโนมัติ</p>
                                <div class="row g-2 align-items-center">
                                    <div class="col-md-5">
                                        <label class="form-label small fw-bold mb-1">ไฟล์ มคอ.3 (Course Spec):</label>
                                        <div id="sys_t3_status" class="mb-1 small text-success fw-bold d-none">
                                            <i class="bi bi-cloud-check-fill"></i> แขวนในระบบแล้ว
                                            <a id="sys_t3_dl" href="#" target="_blank" class="ms-1 badge bg-success text-white text-decoration-none" title="คลิกเพื่อดาวน์โหลด"><i class="bi bi-download"></i> โหลด</a>
                                        </div>
                                        <div id="sys_t3_warning" class="mb-1 small text-danger fw-bold d-none bg-danger-subtle p-1 rounded">
                                            <i class="bi bi-exclamation-triangle-fill"></i> ไฟล์ระบบเป็น .doc/.pdf <a id="sys_t3_warning_dl" href="#" target="_blank" class="ms-1 badge bg-danger text-white text-decoration-none">คลิกโหลด</a><br>
                                            <span style="font-size: 0.7rem;">โปรดแปลงบรรจุเป็น .docx แล้วอัปโหลดแทรกใหม่</span>
                                        </div>
                                        <input type="file" class="form-control form-control-sm" id="ai_tqf3" accept=".docx">
                                        <input type="hidden" id="sys_tqf3_url">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small fw-bold mb-1">ไฟล์ มคอ.5 (Course Report):</label>
                                        <div id="sys_t5_status" class="mb-1 small text-success fw-bold d-none">
                                            <i class="bi bi-cloud-check-fill"></i> แขวนในระบบแล้ว
                                            <a id="sys_t5_dl" href="#" target="_blank" class="ms-1 badge bg-success text-white text-decoration-none" title="คลิกเพื่อดาวน์โหลด"><i class="bi bi-download"></i> โหลด</a>
                                        </div>
                                        <div id="sys_t5_warning" class="mb-1 small text-warning-emphasis fw-bold d-none bg-warning-subtle p-1 rounded">
                                            <i class="bi bi-info-circle-fill"></i> ไฟล์ระบบเป็น .doc/.pdf (ระบบข้ามการตรวจไฟล์นี้) <a id="sys_t5_warning_dl" href="#" target="_blank" class="ms-1 badge bg-warning text-dark text-decoration-none border border-warning">คลิกโหลด</a><br>
                                            <span style="font-size: 0.7rem;" class="fw-normal">คุณสามารถกดเริ่มวิเคราะห์ต่อได้เลย (AI จะตรวจแต่ มคอ.3) หรือแปลงเป็น .docx แล้วอัปโหลดแทรกครับ</span>
                                        </div>
                                        <input type="file" class="form-control form-control-sm" id="ai_tqf5" accept=".docx">
                                        <input type="hidden" id="sys_tqf5_url">
                                        <small class="text-muted" style="font-size: 0.7rem;">(เว้นว่างได้ถ้าไม่มี)</small>
                                    </div>
                                    <div class="col-md-2 mt-auto text-end">
                                        <button type="button" class="btn btn-primary btn-sm w-100" onclick="runAiAnalysis()"><i class="bi bi-stars"></i> เริ่มส่งวิเคราะห์</button>
                                    </div>
                                </div>
                                <div id="aiLoadingIndicator" class="mt-3 text-center d-none">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                    <span class="text-primary fw-bold small ms-2">กำลังให้ AI วิเคราะห์เอกสาร กรุณารอสักครู่ (ประมาณ 10-30 วินาที)...</span>
                                </div>
                                <div id="aiResultAlert" class="mt-2 text-success fw-bold small d-none">
                                    <i class="bi bi-check-circle-fill"></i> AI ดึงข้อมูลทวนสอบเข้าระบบเรียบร้อยแล้ว กดยืนยันบันทึกผลได้เลยครับ
                                </div>
                                <div id="aiErrorAlert" class="mt-2 alert alert-danger d-none small mb-0 py-2">
                                    <i class="bi bi-exclamation-triangle-fill"></i> <span id="aiErrorMsg"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Global Scores & Checkboxes (Human Override Mode) -->
                        <div class="row align-items-center mb-4 mt-4">
                            <div class="col-12 d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-bold text-primary m-0"><i class="bi bi-ui-checks"></i> สรุปผลการประเมิน (Executive Summary)</h6>
                                <div class="form-check form-switch text-warning">
                                    <input class="form-check-input" type="checkbox" id="humanOverrideToggle" onchange="toggleHumanOverride(this)">
                                    <label class="form-check-label small fw-bold" for="humanOverrideToggle"><i class="bi bi-person-fill-gear"></i> โหมดแก้ไขโดยกรรมการ (Override)</label>
                                </div>
                            </div>

                            <!-- List Group for Scores & Manual Checks -->
                            <div class="col-12">
                                <div class="list-group">
                                    <!-- Bloom -->
                                    <label class="list-group-item d-flex gap-3 align-items-center">
                                        <input class="form-check-input flex-shrink-0" type="checkbox" name="chk_bloom" id="chk_bloom" style="transform: scale(1.3);">
                                        <div class="w-100">
                                            <div class="d-flex justify-content-between">
                                                <h6 class="my-0">1. CLO ใช้คำกริยาตาม Bloom's Taxonomy ถูกต้อง</h6>
                                                <span class="badge bg-secondary" id="txt_score_bloom">0%</span>
                                            </div>
                                            <div class="progress mt-1" style="height: 6px;">
                                                <div id="pb_bloom" class="progress-bar bg-primary" role="progressbar" style="width: 0%;"></div>
                                            </div>
                                        </div>
                                    </label>
                                    
                                    <!-- PLO Mapping -->
                                    <label class="list-group-item d-flex gap-3 align-items-center">
                                        <input class="form-check-input flex-shrink-0" type="checkbox" name="chk_map" id="chk_map" style="transform: scale(1.3);">
                                        <div class="w-100">
                                            <div class="d-flex justify-content-between">
                                                <h6 class="my-0">2. ความครอบคลุมหลักสูตร (PLO Coverage ครบถ้วน)</h6>
                                                <span class="badge bg-secondary" id="txt_score_plo">0%</span>
                                            </div>
                                            <div class="progress mt-1" style="height: 6px;">
                                                <div id="pb_plo" class="progress-bar bg-success" role="progressbar" style="width: 0%;"></div>
                                            </div>
                                        </div>
                                    </label>

                                    <!-- Activity -->
                                    <label class="list-group-item d-flex gap-3 align-items-center">
                                        <input class="form-check-input flex-shrink-0" type="checkbox" name="chk_activity" id="chk_activity" style="transform: scale(1.3);">
                                        <div class="w-100">
                                            <div class="d-flex justify-content-between">
                                                <h6 class="my-0">3. กิจกรรมการเรียนการสอนสอดคล้องกับเป้าหมาย</h6>
                                                <span class="badge bg-secondary" id="txt_score_act">0%</span>
                                            </div>
                                            <div class="progress mt-1" style="height: 6px;">
                                                <div id="pb_act" class="progress-bar bg-info" role="progressbar" style="width: 0%;"></div>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Reviewer Strengths & Improvements -->
                        <div class="row mb-3">
                            <div class="col-md-6 mb-2">
                                <label class="form-label fw-bold text-success"><i class="bi bi-star-fill"></i> จุดเด่นของรายวิชานี้ (Strengths):</label>
                                <textarea class="form-control border-success" name="reviewer_strength" id="reviewer_strength" rows="3" placeholder="ระบุจุดเด่นหรือข้อดีของแผนการสอน"></textarea>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label fw-bold text-danger"><i class="bi bi-tools"></i> จุดที่ควรพัฒนา (Areas for Improvement):</label>
                                <textarea class="form-control border-danger" name="reviewer_improvement" id="reviewer_improvement" rows="3" placeholder="ระบุสิ่งที่ควรปรับปรุงหรือแก้ไขในเทอมถัดไป"></textarea>
                            </div>
                        </div>

                        <!-- Accordion for Deep Analytics -->
                        <div id="ai_deep_analysis_container" class="mt-4 mb-2 d-none">
                            <h6 class="fw-bold text-success mb-3"><i class="bi bi-robot"></i> ข้อมูลเชิงลึกจาก AI (Granular Insights)</h6>
                            <div class="accordion mb-3" id="accordionAI">
                                <!-- Bloom Accordion -->
                                <div class="accordion-item border-primary">
                                    <h2 class="accordion-header" id="headingBloom">
                                        <button class="accordion-button collapsed fw-bold text-primary" type="button" data-bs-toggle="collapse" data-bs-target="#collapseBloom">
                                            <i class="bi bi-bar-chart-steps me-2"></i> 1. วิเคราะห์คำกริยา (Bloom's Taxonomy) รายข้อ
                                        </button>
                                    </h2>
                                    <div id="collapseBloom" class="accordion-collapse collapse" data-bs-parent="#accordionAI">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-sm align-middle mb-0" style="font-size: 0.85rem;">
                                                    <thead class="table-light">
                                                        <tr><th width="15%">รหัส</th><th>พฤติกรรมอ้างอิงของวิชา</th><th width="15%">กริยา</th><th width="15%">ระดับความคิด</th><th>ข้อเสนอแนะ AI</th></tr>
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
                                        <button class="accordion-button collapsed fw-bold text-success" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePLO">
                                            <i class="bi bi-diagram-3 me-2"></i> 2. วิเคราะห์ความครอบคลุมหลักสูตร (PLO Coverage)
                                        </button>
                                    </h2>
                                    <div id="collapsePLO" class="accordion-collapse collapse" data-bs-parent="#accordionAI">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-sm align-middle mb-0" style="font-size: 0.85rem;">
                                                    <thead class="table-light">
                                                        <tr><th width="15%">รหัส PLO</th><th>จุดประสงค์หลักสูตร</th><th width="15%">% Coverage</th><th>CLO ที่รองรับ</th><th>ข้อเสนอแนะ / แจ้งเตือนหลุดเป้า</th></tr>
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
                                        <button class="accordion-button collapsed fw-bold text-info" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAct">
                                            <i class="bi bi-easel me-2"></i> 3. วิเคราะห์กิจกรรมการสอน (Teaching Activities)
                                        </button>
                                    </h2>
                                    <div id="collapseAct" class="accordion-collapse collapse" data-bs-parent="#accordionAI">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-sm align-middle mb-0" style="font-size: 0.85rem;">
                                                    <thead class="table-light">
                                                        <tr><th width="25%">กิจกรรม (ที่ระบุใน มคอ.)</th><th>สอดคล้อง CLO ใดบ้าง</th><th width="15%">% สอดคล้อง</th><th>แนวทางปรับปรุงรอบถัดไป</th></tr>
                                                    </thead>
                                                    <tbody id="tbody_act"></tbody>
                                                </table>
                                            </div>
                                        </div>
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
    <div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-gear-fill"></i> ตั้งค่า AI Assistant (System Level)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="verification_board.php">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_settings">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">ระบบ AI ปัจจุบันที่ใช้ประมวลผล (Model):</label>
                            <select class="form-select border-primary" name="gemini_api_model" id="gemini_api_model">
                                <option value="gemini-3.1-pro" <?= $current_api_model == 'gemini-3.1-pro' ? 'selected' : '' ?>>✨ Google Gemini 3.1 Pro (ใหม่ล่าสุด! ตัวท็อปขั้นเทพ - Preview)</option>
                                <option value="gemini-3.1-flash" <?= $current_api_model == 'gemini-3.1-flash' ? 'selected' : '' ?>>✨ Google Gemini 3.1 Flash (ใหม่ล่าสุด! เร็ว แรง - Preview)</option>
                                <option value="gemini-2.5-flash" <?= $current_api_model == 'gemini-2.5-flash' ? 'selected' : '' ?>>Google Gemini 2.5 Flash (มาตรฐาน, เร็ว, ราคาถูก)</option>
                                <option value="gemini-2.5-pro" <?= $current_api_model == 'gemini-2.5-pro' ? 'selected' : '' ?>>Google Gemini 2.5 Pro (ฉลาดมาก, ซับซ้อนกว่า)</option>
                                <option value="gemini-1.5-pro" <?= $current_api_model == 'gemini-1.5-pro' ? 'selected' : '' ?>>Google Gemini 1.5 Pro (รุ่นเก่า, มีความเสถียร)</option>
                                <option value="gemini-1.5-flash" <?= $current_api_model == 'gemini-1.5-flash' ? 'selected' : '' ?>>Google Gemini 1.5 Flash (รุ่นเก่า, เบา)</option>
                            </select>
                            <small class="text-muted">อาจารย์สามารถเลือกเปลี่ยนโมเดล AI ให้เหมาะสมกับการวิเคราะห์ได้ตามต้องการ</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Gemini API Key:</label>
                            
                            <?php if(!empty($current_api_key)): 
                                $masked_key = substr($current_api_key, 0, 10) . '****************' . substr($current_api_key, -5);
                            ?>
                                <div class="alert alert-success p-2 small mb-2 d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="bi bi-shield-check"></i> คีย์ปัจจุบัน: <strong><?= $masked_key ?></strong><br>
                                        <span class="text-muted" style="font-size: 0.7rem;">(ไม่สามารถระบุอีเมลผู้ให้คีย์ได้ แต่ตรวจสอบรหัสผ่าน 10 ตัวแรกได้)</span>
                                    </div>
                                    <button type="submit" name="action" value="delete_api_key" class="btn btn-sm btn-danger fw-bold ms-2" onclick="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบ API Key ปัจจุบันทิ้ง? (ระบบจะใช้งาน AI ไม่ได้จนกว่าจะใส่ข้อมูลใหม่)');"><i class="bi bi-trash-fill"></i> ลบคีย์ทิ้ง</button>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning p-2 small mb-2">
                                    <i class="bi bi-exclamation-circle-fill"></i> ยังไม่มี API Key ในระบบ
                                </div>
                            <?php endif; ?>

                            <input type="password" class="form-control" name="gemini_api_key" placeholder="วาง API Key อันใหม่ที่นี่ (AIzaSy...)" <?= empty($current_api_key) ? 'required' : '' ?>>
                            
                            <div class="mt-2 p-2 bg-light border rounded small">
                                <strong class="text-primary"><i class="bi bi-lightbulb-fill"></i> วิธีขอ API Key ใหม่ฟรี:</strong>
                                <ol class="mb-1 mt-1 ps-3 text-muted">
                                    <li>ล็อกอินด้วยบัญชี Google ส่วนตัวของท่าน</li>
                                    <li>ไปที่เว็บไซต์ <a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-decoration-none fw-bold"><i class="bi bi-box-arrow-up-right"></i> Google AI Studio</a></li>
                                    <li>กดปุ่ม <strong>Create API Key</strong> และเลือกโปรเจกต์ ก๊อปปี้รหัสยาวๆ มาวางด้านบนได้เลย</small>
                                </ol>
                            </div>
                        </div>
                        
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="action" value="save_settings" id="settingsFormAction">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-dark fw-bold" onclick="document.getElementById('settingsFormAction').value='save_settings';"><i class="bi bi-save"></i> บันทึกการตั้งค่า</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const evalModal = new bootstrap.Modal(document.getElementById('evalModal'));

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
            
            // reset UI AI
            document.getElementById('ai_tqf3').value = '';
            document.getElementById('ai_tqf5').value = '';
            document.getElementById('aiLoadingIndicator').classList.add('d-none');
            document.getElementById('aiResultAlert').classList.add('d-none');
            
            document.getElementById('ai_deep_analysis_container').classList.add('d-none');
            document.getElementById('tbody_bloom').innerHTML = '';
            document.getElementById('tbody_plo').innerHTML = '';
            document.getElementById('tbody_act').innerHTML = '';
            
            // Fetch system URLs
            const t3Url = data.tqf3_link;
            const t5Url = data.tqf5_link;
            document.getElementById('sys_tqf3_url').value = t3Url || '';
            document.getElementById('sys_tqf5_url').value = t5Url || '';
            
            document.getElementById('sys_t3_status').classList.add('d-none');
            document.getElementById('sys_t3_warning').classList.add('d-none');
            if(t3Url) {
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
            if(t5Url) {
                if (t5Url.match(/\.(doc|pdf)$/i)) {
                    document.getElementById('sys_t5_warning').classList.remove('d-none');
                    document.getElementById('sys_t5_warning_dl').href = t5Url;
                } else {
                    document.getElementById('sys_t5_status').classList.remove('d-none');
                    document.getElementById('sys_t5_dl').href = t5Url;
                }
            }
            
            // Fetch granular details
            fetch('ajax_ai_analyzer.php?action=get_deep_details&verification_id=' + data.id)
                .then(res => res.json())
                .then(json => {
                    if(json.success) {
                        renderGranularAnalysis(json);
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
            document.getElementById('chk_bloom').disabled = !isManual;
            document.getElementById('chk_map').disabled = !isManual;
            document.getElementById('chk_activity').disabled = !isManual;
        }

        function renderGranularAnalysis(json) {
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
            if(json.bloom_analysis && json.bloom_analysis.length > 0) {
                json.bloom_analysis.forEach(c => {
                    let bloomClass = 'badge bg-secondary';
                    if(c.bloom_level && c.bloom_level.includes('Apply')) bloomClass = 'badge bg-info text-dark';
                    else if(c.bloom_level && c.bloom_level.includes('Analyze')) bloomClass = 'badge bg-primary';
                    else if(c.bloom_level && c.bloom_level.includes('Create')) bloomClass = 'badge bg-success';
                    else if(c.bloom_level && c.bloom_level.includes('Evaluate')) bloomClass = 'badge bg-warning text-dark';
                    
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
            if(json.plo_coverage && json.plo_coverage.length > 0) {
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
            if(json.activity_mapping && json.activity_mapping.length > 0) {
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
            
            document.getElementById('ai_deep_analysis_container').classList.remove('d-none');
        }

        async function runAiAnalysis() {
            const vid = document.getElementById('vidInput').value;
            const t3File = document.getElementById('ai_tqf3').files[0];
            const t5File = document.getElementById('ai_tqf5').files[0];
            const t3Url = document.getElementById('sys_tqf3_url').value;
            const t5Url = document.getElementById('sys_tqf5_url').value;
            
            if(!t3File && !t3Url) {
                alert("จำเป็นต้องเลือกไฟล์ หรือ มีลิงก์ มคอ.3 อยู่ในระบบเพื่อทำการวิเคราะห์ครับ");
                return;
            }
            
            document.getElementById('aiLoadingIndicator').classList.remove('d-none');
            document.getElementById('aiResultAlert').classList.add('d-none');
            document.getElementById('aiErrorAlert').classList.add('d-none');
            
            let formData = new FormData();
            formData.append('action', 'run_ai');
            formData.append('verification_id', vid);
            
            if(t3File) formData.append('tqf3_file', t3File);
            else formData.append('tqf3_url', t3Url);
            
            if(t5File) formData.append('tqf5_file', t5File);
            else if(t5Url) formData.append('tqf5_url', t5Url);
            
            try {
                let response = await fetch('ajax_ai_analyzer.php', {
                    method: 'POST',
                    body: formData
                });
                let result = await response.json();
                
                document.getElementById('aiLoadingIndicator').classList.add('d-none');
                
                if(result.success) {
                    const aiData = result.data;
                    document.getElementById('humanOverrideToggle').checked = false;
                    toggleHumanOverride(document.getElementById('humanOverrideToggle'));
                    
                    document.getElementById('chk_bloom').checked = parseInt(aiData.check_clo_verb) === 1;
                    document.getElementById('chk_map').checked = parseInt(aiData.check_clo_plo_map) === 1;
                    document.getElementById('chk_activity').checked = parseInt(aiData.check_class_activity) === 1;
                    
                    document.getElementById('reviewer_strength').value = aiData.reviewer_strength || "";
                    document.getElementById('reviewer_improvement').value = aiData.reviewer_improvement || "";
                    
                    // Fetch and re-render the stored DB values
                    fetch('ajax_ai_analyzer.php?action=get_deep_details&verification_id=' + vid)
                        .then(res => res.json())
                        .then(json => {
                            if(json.success) renderGranularAnalysis(json);
                        });
                    
                    document.getElementById('aiResultAlert').classList.remove('d-none');
                } else {
                    document.getElementById('aiErrorAlert').classList.remove('d-none');
                    document.getElementById('aiErrorMsg').innerHTML = result.error || "เกิดข้อผิดพลาดในการวิเคราะห์";
                }
            } catch (err) {
                document.getElementById('aiLoadingIndicator').classList.add('d-none');
                document.getElementById('aiErrorAlert').classList.remove('d-none');
                document.getElementById('aiErrorMsg').innerHTML = "Error calling server: " + err.message;
            }
        }
    </script>
</body>

</html>