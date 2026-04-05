<?php
require_once __DIR__ . '/bootstrap.php';

function dashboard_doc_status($link) {
    $link = trim((string) $link);
    if ($link === '') {
        return 'missing';
    }
    if (preg_match('/\.docx(?:\?|$)/i', $link)) {
        return 'docx';
    }
    if (preg_match('/\.doc(?:\?|$)/i', $link)) {
        return 'legacy_doc';
    }
    if (preg_match('/\.pdf(?:\?|$)/i', $link)) {
        return 'pdf';
    }
    return 'other';
}

function dashboard_pct($value) {
    return round((float) $value, 2);
}

function dashboard_progress_class($value) {
    $value = (float) $value;
    if ($value >= 80) {
        return 'bg-success';
    }
    if ($value >= 60) {
        return 'bg-warning';
    }
    return 'bg-danger';
}

function dashboard_export_filename($selectedYear, $selectedSemester, $suffix, $extension) {
    $yearPart = $selectedYear !== '' ? $selectedYear : 'all-years';
    $semesterPart = $selectedSemester !== '' ? 'sem-' . $selectedSemester : 'all-semesters';
    return sprintf('aunqa-summary-%s-%s-%s.%s', $yearPart, $semesterPart, $suffix, $extension);
}

function dashboard_status_label($status) {
    $status = trim((string) $status);
    return $status !== '' ? $status : 'ยังไม่ระบุสถานะ';
}

function dashboard_pdca_label($status) {
    $map = [
        'not_started' => 'ยังไม่เริ่ม',
        'in_progress' => 'กำลังดำเนินการ',
        'partially_resolved' => 'แก้ได้บางส่วน',
        'resolved' => 'แก้ไขแล้ว',
        'carried_forward' => 'ยกไปรอบถัดไป',
    ];
    $status = trim((string) $status);
    return isset($map[$status]) ? $map[$status] : ($status !== '' ? $status : 'ยังไม่ระบุ');
}

function dashboard_guess_next_year($year) {
    $year = trim((string) $year);
    if ($year !== '' && ctype_digit($year)) {
        return (string) (((int) $year) + 1);
    }
    return $year;
}

function dashboard_seed_source_note() {
    return '[SEEDED_FROM_CARRY_FORWARD] นำเข้าจาก carry forward ของรอบก่อนเพื่อใช้ตั้งต้นรอบใหม่';
}

try {
    $pdo = app_pdo();

    try { $pdo->exec("ALTER TABLE aunqa_verification_records ADD COLUMN tqf3_link VARCHAR(500) DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_verification_records ADD COLUMN tqf5_link VARCHAR(500) DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_verification_records ADD COLUMN seed_batch_token VARCHAR(64) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_verification_records ADD COLUMN seed_source VARCHAR(50) DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_followup TEXT"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_status ENUM('not_started','in_progress','partially_resolved','resolved','carried_forward') DEFAULT 'not_started'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_resolution_percent DECIMAL(5,2) DEFAULT 0.00"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_last_year_summary TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_current_action TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_evidence_note TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_pdca_issues ADD COLUMN category_confidence DECIMAL(5,2) DEFAULT 100.00"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_pdca_issues ADD COLUMN category_reason VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_pdca_issues ADD COLUMN category_inferred_by ENUM('manual','rule_based','ai') DEFAULT 'manual'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_pdca_issues ADD COLUMN committee_note TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE aunqa_pdca_issues ADD COLUMN next_round_action TEXT NULL"); } catch (PDOException $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `aunqa_settings` (
          `setting_key` VARCHAR(50) PRIMARY KEY,
          `setting_value` TEXT NOT NULL,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `aunqa_pdca_issues` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `verification_id` INT NOT NULL,
          `previous_issue_id` INT NULL,
          `academic_year` VARCHAR(10) NOT NULL,
          `semester` VARCHAR(2) NOT NULL,
          `issue_category` ENUM('bloom','plo','activity','clo_result','document','assessment','other') DEFAULT 'other',
          `category_confidence` DECIMAL(5,2) DEFAULT 100.00,
          `category_reason` VARCHAR(255) DEFAULT '',
          `category_inferred_by` ENUM('manual','rule_based','ai') DEFAULT 'manual',
          `issue_title` VARCHAR(255) NOT NULL,
          `issue_detail` TEXT NULL,
          `severity_level` ENUM('low','medium','high') DEFAULT 'medium',
          `source_type` ENUM('ai','committee','mixed') DEFAULT 'mixed',
          `source_reference` VARCHAR(255) DEFAULT '',
          `is_recurring` TINYINT(1) DEFAULT 0,
          `current_status` ENUM('open','in_progress','partially_resolved','resolved','carried_forward') DEFAULT 'open',
          `resolution_percent` DECIMAL(5,2) DEFAULT 0.00,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY `idx_pdca_issue_verification` (`verification_id`),
          KEY `idx_pdca_issue_year_sem` (`academic_year`, `semester`),
          KEY `idx_pdca_issue_category` (`issue_category`),
          KEY `idx_pdca_issue_status` (`current_status`),
          CONSTRAINT `fk_pdca_issue_verification_dash`
            FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_pdca_issue_previous_dash`
            FOREIGN KEY (`previous_issue_id`) REFERENCES `aunqa_pdca_issues`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (PDOException $e) {
    $pdo = null;
}

$availableYears = [];
$availableSemesters = [];
$selectedYear = '';
$selectedSemester = isset($_GET['f_sem']) ? trim($_GET['f_sem']) : '';
$nextRoundTargetYear = '';
$nextRoundTargetSemester = '';
$nextRoundFlash = [
    'created_courses' => 0,
    'created_issues' => 0,
    'skipped_courses' => 0,
    'message' => '',
    'target_year' => '',
    'target_semester' => '',
    'seed_batch_token' => '',
];
$autoPassThreshold = 80;
$records = [];
$yearlyTrend = [];
$categorySummary = [];
$pdcaStatusSummary = [];
$attentionRows = [];
$carryForwardIssues = [];

if ($pdo) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_seed_round') {
        $seedBatchToken = isset($_POST['seed_batch_token']) ? trim((string) $_POST['seed_batch_token']) : '';
        $targetYear = isset($_POST['target_year']) ? trim((string) $_POST['target_year']) : '';
        $targetSemester = isset($_POST['target_semester']) ? trim((string) $_POST['target_semester']) : '';
        $sourceYear = isset($_POST['source_year']) ? trim((string) $_POST['source_year']) : '';
        $sourceSemester = isset($_POST['source_semester']) ? trim((string) $_POST['source_semester']) : '';

        $redirectParams = [];
        if ($sourceYear !== '') {
            $redirectParams['f_year'] = $sourceYear;
        }
        if ($sourceSemester !== '') {
            $redirectParams['f_sem'] = $sourceSemester;
        }

        if ($seedBatchToken === '' || $targetYear === '' || $targetSemester === '') {
            $redirectParams['seed_msg'] = 'ลบรอบที่เพิ่งสร้างไม่สำเร็จ: ข้อมูล batch ไม่ครบ';
            header('Location: verification_dashboard.php?' . http_build_query($redirectParams));
            exit;
        }

        try {
            $stmtDeleteSeed = $pdo->prepare("
                DELETE FROM aunqa_verification_records
                WHERE year = :year
                  AND semester = :semester
                  AND seed_batch_token = :seed_batch_token
                  AND seed_source = 'carry_forward_seed'
            ");
            $stmtDeleteSeed->execute([
                ':year' => $targetYear,
                ':semester' => $targetSemester,
                ':seed_batch_token' => $seedBatchToken,
            ]);

            $deletedCount = $stmtDeleteSeed->rowCount();
            $redirectParams['seed_msg'] = $deletedCount > 0
                ? sprintf('ลบรอบทดลองที่ seed ไว้แล้ว %d วิชา', $deletedCount)
                : 'ไม่พบรอบทดลองที่ตรงกับ batch นี้ หรืออาจถูกลบไปก่อนแล้ว';
            header('Location: verification_dashboard.php?' . http_build_query($redirectParams));
            exit;
        } catch (Throwable $e) {
            $redirectParams['seed_msg'] = 'ลบรอบที่เพิ่งสร้างไม่สำเร็จ: ' . $e->getMessage();
            header('Location: verification_dashboard.php?' . http_build_query($redirectParams));
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_carry_forward_committee_note') {
        $issueId = isset($_POST['pdca_issue_id']) ? (int) $_POST['pdca_issue_id'] : 0;
        $committeeNote = isset($_POST['committee_note']) ? trim((string) $_POST['committee_note']) : '';
        $nextRoundAction = isset($_POST['next_round_action']) ? trim((string) $_POST['next_round_action']) : '';
        $sourceYear = isset($_POST['source_year']) ? trim((string) $_POST['source_year']) : '';
        $sourceSemester = isset($_POST['source_semester']) ? trim((string) $_POST['source_semester']) : '';

        $redirectParams = [];
        if ($sourceYear !== '') {
            $redirectParams['f_year'] = $sourceYear;
        }
        if ($sourceSemester !== '') {
            $redirectParams['f_sem'] = $sourceSemester;
        }

        if ($issueId <= 0) {
            $redirectParams['seed_msg'] = 'บันทึกหมายเหตุกรรมการไม่สำเร็จ: ไม่พบ issue ที่ต้องการแก้ไข';
            header('Location: verification_dashboard.php?' . http_build_query($redirectParams));
            exit;
        }

        try {
            $stmtSaveCommitteeNote = $pdo->prepare("
                UPDATE aunqa_pdca_issues
                SET committee_note = :committee_note,
                    next_round_action = :next_round_action
                WHERE id = :id
            ");
            $stmtSaveCommitteeNote->execute([
                ':committee_note' => $committeeNote,
                ':next_round_action' => $nextRoundAction,
                ':id' => $issueId,
            ]);
            $redirectParams['seed_msg'] = 'บันทึกหมายเหตุกรรมการเรียบร้อยแล้ว';
            header('Location: verification_dashboard.php?' . http_build_query($redirectParams));
            exit;
        } catch (Throwable $e) {
            $redirectParams['seed_msg'] = 'บันทึกหมายเหตุกรรมการไม่สำเร็จ: ' . $e->getMessage();
            header('Location: verification_dashboard.php?' . http_build_query($redirectParams));
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_next_round_from_carry_forward') {
        $sourceYear = isset($_POST['source_year']) ? trim((string) $_POST['source_year']) : '';
        $sourceSemester = isset($_POST['source_semester']) ? trim((string) $_POST['source_semester']) : '';
        $targetYear = isset($_POST['target_year']) ? trim((string) $_POST['target_year']) : '';
        $targetSemester = isset($_POST['target_semester']) ? trim((string) $_POST['target_semester']) : '';

        $redirectParams = [];
        if ($sourceYear !== '') {
            $redirectParams['f_year'] = $sourceYear;
        }
        if ($sourceSemester !== '') {
            $redirectParams['f_sem'] = $sourceSemester;
        }

        if ($targetYear === '' || $targetSemester === '') {
            $redirectParams['seed_msg'] = 'กรุณาระบุปีการศึกษาและภาคเรียนปลายทางก่อนเริ่มรอบใหม่';
            header('Location: verification_dashboard.php?' . http_build_query($redirectParams));
            exit;
        }

        try {
            $pdo->beginTransaction();
            $seedBatchToken = bin2hex(random_bytes(8));

            $seedWhere = ["p.current_status = 'carried_forward'"];
            $seedParams = [];
            if ($sourceYear !== '') {
                $seedWhere[] = 'r.year = :seed_year';
                $seedParams[':seed_year'] = $sourceYear;
            }
            if ($sourceSemester !== '') {
                $seedWhere[] = 'r.semester = :seed_semester';
                $seedParams[':seed_semester'] = $sourceSemester;
            }

            $stmtSeed = $pdo->prepare("
                SELECT
                    p.id AS old_issue_id,
                    p.issue_category,
                    p.issue_title,
                    p.issue_detail,
                    p.category_confidence,
                    p.category_reason,
                    p.category_inferred_by,
                    p.severity_level,
                    p.source_type,
                    p.source_reference,
                    p.resolution_percent,
                    r.id AS old_verification_id,
                    r.course_code,
                    r.course_name,
                    r.instructor,
                    r.tqf3_link,
                    r.tqf5_link
                FROM aunqa_pdca_issues p
                INNER JOIN aunqa_verification_records r ON r.id = p.verification_id
                WHERE " . implode(' AND ', $seedWhere) . "
                ORDER BY r.course_code ASC, p.updated_at DESC, p.id DESC
            ");
            $stmtSeed->execute($seedParams);
            $seedRows = $stmtSeed->fetchAll(PDO::FETCH_ASSOC);

            $coursesByCode = [];
            foreach ($seedRows as $row) {
                $courseCode = (string) $row['course_code'];
                if (!isset($coursesByCode[$courseCode])) {
                    $coursesByCode[$courseCode] = [
                        'course' => $row,
                        'issues' => [],
                    ];
                }
                $coursesByCode[$courseCode]['issues'][] = $row;
            }

            $stmtCheckRecord = $pdo->prepare("
                SELECT id FROM aunqa_verification_records
                WHERE year = :year AND semester = :semester AND course_code = :course_code
                LIMIT 1
            ");
            $stmtCheckChecklist = $pdo->prepare("
                SELECT id FROM aunqa_verification_checklists
                WHERE verification_id = :verification_id
                LIMIT 1
            ");
            $stmtCreateRecord = $pdo->prepare("
                INSERT INTO aunqa_verification_records
                    (year, semester, course_code, course_name, instructor, tqf3_link, tqf5_link, verification_status, seed_batch_token, seed_source)
                VALUES
                    (:year, :semester, :course_code, :course_name, :instructor, :tqf3_link, :tqf5_link, 'รอรับเอกสาร', :seed_batch_token, 'carry_forward_seed')
            ");
            $stmtCreateChecklist = $pdo->prepare("
                INSERT INTO aunqa_verification_checklists
                    (verification_id, pdca_status, pdca_resolution_percent, pdca_last_year_summary, pdca_current_action, pdca_evidence_note)
                VALUES
                    (:verification_id, 'in_progress', 0, :last_year_summary, '', :evidence_note)
            ");
            $stmtUpdateChecklist = $pdo->prepare("
                UPDATE aunqa_verification_checklists
                SET pdca_status = 'in_progress',
                    pdca_last_year_summary = :last_year_summary,
                    pdca_evidence_note = :evidence_note
                WHERE verification_id = :verification_id
            ");
            $stmtCheckIssue = $pdo->prepare("
                SELECT id FROM aunqa_pdca_issues
                WHERE verification_id = :verification_id
                  AND previous_issue_id = :previous_issue_id
                LIMIT 1
            ");
            $stmtCreateIssue = $pdo->prepare("
                INSERT INTO aunqa_pdca_issues
                    (verification_id, previous_issue_id, academic_year, semester, issue_category,
                     category_confidence, category_reason, category_inferred_by,
                     issue_title, issue_detail, severity_level, source_type, source_reference,
                     is_recurring, current_status, resolution_percent)
                VALUES
                    (:verification_id, :previous_issue_id, :academic_year, :semester, :issue_category,
                     :category_confidence, :category_reason, :category_inferred_by,
                     :issue_title, :issue_detail, :severity_level, :source_type, :source_reference,
                     1, 'open', 0)
            ");

            if (empty($coursesByCode)) {
                throw new RuntimeException('ไม่พบ PDCA issue ที่มีสถานะ carry forward สำหรับใช้ตั้งต้นรอบใหม่');
            }

            foreach ($coursesByCode as $courseCode => $bundle) {
                $course = $bundle['course'];
                $issues = $bundle['issues'];

                $stmtCheckRecord->execute([
                    ':year' => $targetYear,
                    ':semester' => $targetSemester,
                    ':course_code' => $courseCode,
                ]);
                $newVerificationId = $stmtCheckRecord->fetchColumn();
                $createdNewRecord = false;

                $summaryLines = [];
                foreach ($issues as $issue) {
                    $summaryLines[] = sprintf('[%s] %s', $issue['issue_category'], $issue['issue_title']);
                }
                $summaryText = implode("\n", array_unique($summaryLines));
                $evidenceNote = dashboard_seed_source_note();

                if (!$newVerificationId) {
                    $stmtCreateRecord->execute([
                        ':year' => $targetYear,
                        ':semester' => $targetSemester,
                        ':course_code' => $course['course_code'],
                        ':course_name' => $course['course_name'],
                        ':instructor' => $course['instructor'],
                        ':tqf3_link' => $course['tqf3_link'],
                        ':tqf5_link' => $course['tqf5_link'],
                        ':seed_batch_token' => $seedBatchToken,
                    ]);
                    $newVerificationId = (int) $pdo->lastInsertId();
                    $stmtCreateChecklist->execute([
                        ':verification_id' => $newVerificationId,
                        ':last_year_summary' => $summaryText,
                        ':evidence_note' => $evidenceNote,
                    ]);
                    $nextRoundFlash['created_courses']++;
                    $createdNewRecord = true;
                } else {
                    $nextRoundFlash['skipped_courses']++;
                }

                $stmtCheckChecklist->execute([
                    ':verification_id' => $newVerificationId,
                ]);
                $existingChecklistId = $stmtCheckChecklist->fetchColumn();
                if (!$existingChecklistId) {
                    $stmtCreateChecklist->execute([
                        ':verification_id' => $newVerificationId,
                        ':last_year_summary' => $summaryText,
                        ':evidence_note' => $evidenceNote,
                    ]);
                } else if (!$createdNewRecord) {
                    $stmtUpdateChecklist->execute([
                        ':verification_id' => $newVerificationId,
                        ':last_year_summary' => $summaryText,
                        ':evidence_note' => $evidenceNote,
                    ]);
                }

                foreach ($issues as $issue) {
                    $stmtCheckIssue->execute([
                        ':verification_id' => $newVerificationId,
                        ':previous_issue_id' => $issue['old_issue_id'],
                    ]);
                    $existingIssueId = $stmtCheckIssue->fetchColumn();
                    if ($existingIssueId) {
                        continue;
                    }

                    $stmtCreateIssue->execute([
                        ':verification_id' => $newVerificationId,
                        ':previous_issue_id' => $issue['old_issue_id'],
                        ':academic_year' => $targetYear,
                        ':semester' => $targetSemester,
                        ':issue_category' => $issue['issue_category'],
                        ':category_confidence' => $issue['category_confidence'],
                        ':category_reason' => $issue['category_reason'],
                        ':category_inferred_by' => $issue['category_inferred_by'],
                        ':issue_title' => $issue['issue_title'],
                        ':issue_detail' => $issue['issue_detail'],
                        ':severity_level' => $issue['severity_level'],
                        ':source_type' => $issue['source_type'],
                        ':source_reference' => $issue['source_reference'],
                    ]);
                    $nextRoundFlash['created_issues']++;
                }
            }

            $pdo->commit();
            $redirectParams['seed_msg'] = sprintf(
                'สร้างรอบใหม่แล้ว %d วิชา และคัดลอก PDCA issue %d ประเด็น',
                $nextRoundFlash['created_courses'],
                $nextRoundFlash['created_issues']
            );
            if ($nextRoundFlash['skipped_courses'] > 0) {
                $redirectParams['seed_msg'] .= sprintf(' (ข้ามวิชาที่มีอยู่แล้ว %d วิชา)', $nextRoundFlash['skipped_courses']);
            }
            $redirectParams['seed_target_year'] = $targetYear;
            $redirectParams['seed_target_semester'] = $targetSemester;
            $redirectParams['seed_created_courses'] = $nextRoundFlash['created_courses'];
            if ($nextRoundFlash['created_courses'] > 0) {
                $redirectParams['seed_batch_token'] = $seedBatchToken;
            }
            header('Location: verification_dashboard.php?' . http_build_query($redirectParams));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $redirectParams['seed_msg'] = 'เริ่มรอบใหม่ไม่สำเร็จ: ' . $e->getMessage();
            header('Location: verification_dashboard.php?' . http_build_query($redirectParams));
            exit;
        }
    }

    $availableYears = $pdo->query("SELECT DISTINCT year FROM aunqa_verification_records ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
    $availableSemesters = $pdo->query("SELECT DISTINCT semester FROM aunqa_verification_records ORDER BY semester ASC")->fetchAll(PDO::FETCH_COLUMN);
    $selectedYear = isset($_GET['f_year']) && trim($_GET['f_year']) !== '' ? trim($_GET['f_year']) : (isset($availableYears[0]) ? $availableYears[0] : '');
    $nextRoundTargetYear = isset($_GET['target_year']) ? trim((string) $_GET['target_year']) : dashboard_guess_next_year($selectedYear);
    $nextRoundTargetSemester = isset($_GET['target_sem']) ? trim((string) $_GET['target_sem']) : ($selectedSemester !== '' ? $selectedSemester : '1');
    if (isset($_GET['seed_msg'])) {
        $nextRoundFlash['message'] = trim((string) $_GET['seed_msg']);
    }
    if (isset($_GET['seed_target_year'])) {
        $nextRoundFlash['target_year'] = trim((string) $_GET['seed_target_year']);
    }
    if (isset($_GET['seed_target_semester'])) {
        $nextRoundFlash['target_semester'] = trim((string) $_GET['seed_target_semester']);
    }
    if (isset($_GET['seed_batch_token'])) {
        $nextRoundFlash['seed_batch_token'] = trim((string) $_GET['seed_batch_token']);
    }
    if (isset($_GET['seed_created_courses'])) {
        $nextRoundFlash['created_courses'] = max(0, (int) $_GET['seed_created_courses']);
    }

    $stmtSettings = $pdo->query("SELECT setting_value FROM aunqa_settings WHERE setting_key = 'ai_auto_pass_threshold'");
    $thresholdValue = $stmtSettings->fetchColumn();
    if ($thresholdValue !== false) {
        $autoPassThreshold = max(0, min(100, (int) $thresholdValue));
    }

    $where = [];
    $params = [];
    if ($selectedYear !== '') {
        $where[] = 'r.year = :year';
        $params[':year'] = $selectedYear;
    }
    if ($selectedSemester !== '') {
        $where[] = 'r.semester = :semester';
        $params[':semester'] = $selectedSemester;
    }
    $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $stmtRecords = $pdo->prepare("
        SELECT r.*, c.check_clo_verb, c.check_clo_plo_map, c.check_class_activity,
               c.score_bloom, c.score_plo, c.score_activity,
               c.pdca_status, c.pdca_resolution_percent, c.pdca_last_year_summary,
               c.pdca_current_action, c.pdca_evidence_note
        FROM aunqa_verification_records r
        LEFT JOIN aunqa_verification_checklists c ON c.verification_id = r.id
        $whereSql
        ORDER BY r.year DESC, r.semester ASC, r.course_code ASC
    ");
    $stmtRecords->execute($params);
    $records = $stmtRecords->fetchAll(PDO::FETCH_ASSOC);

    $stmtTrend = $pdo->query("
        SELECT r.year,
               COUNT(*) AS total_courses,
               SUM(CASE WHEN r.verification_status = 'ผ่านการทวนสอบ' THEN 1 ELSE 0 END) AS passed_courses,
               ROUND(AVG(COALESCE(c.score_bloom, 0)), 2) AS avg_bloom,
               ROUND(AVG(COALESCE(c.score_plo, 0)), 2) AS avg_plo,
               ROUND(AVG(COALESCE(c.score_activity, 0)), 2) AS avg_activity,
               ROUND(AVG(COALESCE(c.pdca_resolution_percent, 0)), 2) AS avg_pdca_resolution
        FROM aunqa_verification_records r
        LEFT JOIN aunqa_verification_checklists c ON c.verification_id = r.id
        GROUP BY r.year
        ORDER BY r.year DESC
    ");
    $yearlyTrend = $stmtTrend->fetchAll(PDO::FETCH_ASSOC);

    $stmtCategory = $pdo->prepare("
        SELECT p.issue_category, COUNT(*) AS issue_count,
               ROUND(AVG(COALESCE(p.category_confidence, 0)), 2) AS avg_confidence
        FROM aunqa_pdca_issues p
        INNER JOIN aunqa_verification_records r ON r.id = p.verification_id
        " . ($whereSql === '' ? '' : str_replace('r.', 'r.', $whereSql)) . "
        GROUP BY p.issue_category
        ORDER BY issue_count DESC, p.issue_category ASC
    ");
    $stmtCategory->execute($params);
    $categorySummary = $stmtCategory->fetchAll(PDO::FETCH_ASSOC);

    $stmtPdcaStatus = $pdo->prepare("
        SELECT COALESCE(c.pdca_status, 'not_started') AS pdca_status, COUNT(*) AS status_count
        FROM aunqa_verification_records r
        LEFT JOIN aunqa_verification_checklists c ON c.verification_id = r.id
        $whereSql
        GROUP BY COALESCE(c.pdca_status, 'not_started')
        ORDER BY status_count DESC
    ");
    $stmtPdcaStatus->execute($params);
    $pdcaStatusSummary = $stmtPdcaStatus->fetchAll(PDO::FETCH_ASSOC);

    $carryForwardSql = "
        SELECT p.id, p.issue_category, p.issue_title, p.issue_detail, p.committee_note, p.next_round_action, p.resolution_percent,
               r.course_code, r.course_name, r.instructor
        FROM aunqa_pdca_issues p
        INNER JOIN aunqa_verification_records r ON r.id = p.verification_id
    ";
    if ($whereSql !== '') {
        $carryForwardSql .= " $whereSql AND p.current_status = 'carried_forward'";
    } else {
        $carryForwardSql .= " WHERE p.current_status = 'carried_forward'";
    }
    $carryForwardSql .= " ORDER BY r.course_code ASC, p.updated_at DESC LIMIT 20";
    $stmtCarryForward = $pdo->prepare($carryForwardSql);
    $stmtCarryForward->execute($params);
    $carryForwardIssues = $stmtCarryForward->fetchAll(PDO::FETCH_ASSOC);
}

$totalCourses = count($records);
$passedCourses = 0;
$inProgressCourses = 0;
$waitingCourses = 0;
$docxReadyCourses = 0;
$legacyDocCourses = 0;
$missingDocCourses = 0;
$avgBloom = 0.0;
$avgPlo = 0.0;
$avgActivity = 0.0;
$avgPdcaResolution = 0.0;
$sumBloom = 0.0;
$sumPlo = 0.0;
$sumActivity = 0.0;
$sumPdcaResolution = 0.0;
$lowScoreCourses = 0;
$carryForwardCourses = 0;

foreach ($records as $record) {
    $status = (string) ($record['verification_status'] ?? '');
    if ($status === 'ผ่านการทวนสอบ') {
        $passedCourses++;
    } else if ($status === 'กำลังตรวจสอบ') {
        $inProgressCourses++;
    } else {
        $waitingCourses++;
    }

    $tqf3Status = dashboard_doc_status($record['tqf3_link'] ?? '');
    $tqf5Status = dashboard_doc_status($record['tqf5_link'] ?? '');
    if ($tqf3Status === 'docx' && $tqf5Status === 'docx') {
        $docxReadyCourses++;
    }
    if (in_array($tqf3Status, ['legacy_doc', 'pdf', 'other'], true) || in_array($tqf5Status, ['legacy_doc', 'pdf', 'other'], true)) {
        $legacyDocCourses++;
    }
    if ($tqf3Status === 'missing' || $tqf5Status === 'missing') {
        $missingDocCourses++;
    }

    $scoreBloom = dashboard_pct($record['score_bloom'] ?? 0);
    $scorePlo = dashboard_pct($record['score_plo'] ?? 0);
    $scoreActivity = dashboard_pct($record['score_activity'] ?? 0);
    $pdcaResolution = dashboard_pct($record['pdca_resolution_percent'] ?? 0);
    $sumBloom += $scoreBloom;
    $sumPlo += $scorePlo;
    $sumActivity += $scoreActivity;
    $sumPdcaResolution += $pdcaResolution;

    if ($scoreBloom < $autoPassThreshold || $scorePlo < $autoPassThreshold || $scoreActivity < $autoPassThreshold) {
        $lowScoreCourses++;
    }
    if (($record['pdca_status'] ?? '') === 'carried_forward') {
        $carryForwardCourses++;
    }

    $needsAttention = [];
    if ($status !== 'ผ่านการทวนสอบ') {
        $needsAttention[] = 'ยังไม่ผ่านการทวนสอบ';
    }
    if ($tqf3Status !== 'docx' || $tqf5Status !== 'docx') {
        $needsAttention[] = 'เอกสารยังไม่พร้อมเป็น .docx';
    }
    if ($scoreBloom < $autoPassThreshold || $scorePlo < $autoPassThreshold || $scoreActivity < $autoPassThreshold) {
        $needsAttention[] = 'มีคะแนนต่ำกว่าเกณฑ์ auto-pass';
    }
    if (($record['pdca_status'] ?? '') === 'carried_forward') {
        $needsAttention[] = 'มีประเด็น PDCA ที่ยกไปปีถัดไป';
    }

    if (!empty($needsAttention)) {
        $attentionRows[] = [
            'id' => $record['id'],
            'course_code' => $record['course_code'],
            'course_name' => $record['course_name'],
            'instructor' => $record['instructor'],
            'status' => $status,
            'score_bloom' => $scoreBloom,
            'score_plo' => $scorePlo,
            'score_activity' => $scoreActivity,
            'reasons' => $needsAttention,
            'pdca_status' => $record['pdca_status'] ?: 'not_started'
        ];
    }
}

if ($totalCourses > 0) {
    $avgBloom = round($sumBloom / $totalCourses, 2);
    $avgPlo = round($sumPlo / $totalCourses, 2);
    $avgActivity = round($sumActivity / $totalCourses, 2);
    $avgPdcaResolution = round($sumPdcaResolution / $totalCourses, 2);
}

$exportMode = isset($_GET['export']) ? trim((string) $_GET['export']) : '';
$printMode = isset($_GET['print_mode']) ? trim((string) $_GET['print_mode']) : 'standard';
$isCompactPrint = ($printMode === 'compact');
$reportLabel = 'สรุปรอบประเมินปีการศึกษา ' . ($selectedYear !== '' ? $selectedYear : 'ทุกปี');
if ($selectedSemester !== '') {
    $reportLabel .= ' ภาคเรียน ' . $selectedSemester;
}

if ($exportMode === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . dashboard_export_filename($selectedYear, $selectedSemester, 'summary', 'xls') . '"');
    echo "\xEF\xBB\xBF";
    ?>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Sarabun, Arial, sans-serif; }
            table { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
            th, td { border: 1px solid #999; padding: 6px 8px; vertical-align: top; }
            th { background: #dbeafe; }
            h2, h3 { margin: 0 0 10px; }
            .muted { color: #666; }
        </style>
    </head>
    <body>
        <h2><?= htmlspecialchars($reportLabel) ?></h2>
        <div class="muted">Exported at <?= htmlspecialchars(date('Y-m-d H:i:s')) ?></div>

        <h3>ภาพรวม</h3>
        <table>
            <tr><th>ตัวชี้วัด</th><th>ค่า</th></tr>
            <tr><td>รายวิชาทั้งหมด</td><td><?= (int) $totalCourses ?></td></tr>
            <tr><td>ผ่านการทวนสอบแล้ว</td><td><?= (int) $passedCourses ?></td></tr>
            <tr><td>กำลังตรวจสอบ</td><td><?= (int) $inProgressCourses ?></td></tr>
            <tr><td>รอเอกสาร/รอเริ่ม</td><td><?= (int) $waitingCourses ?></td></tr>
            <tr><td>เอกสารพร้อมใช้ (.docx ครบ)</td><td><?= (int) $docxReadyCourses ?></td></tr>
            <tr><td>เอกสารเสี่ยง (.doc/.pdf/อื่น ๆ)</td><td><?= (int) $legacyDocCourses ?></td></tr>
            <tr><td>ขาดเอกสารบางส่วน</td><td><?= (int) $missingDocCourses ?></td></tr>
            <tr><td>Bloom เฉลี่ย</td><td><?= dashboard_pct($avgBloom) ?>%</td></tr>
            <tr><td>PLO เฉลี่ย</td><td><?= dashboard_pct($avgPlo) ?>%</td></tr>
            <tr><td>Activity เฉลี่ย</td><td><?= dashboard_pct($avgActivity) ?>%</td></tr>
            <tr><td>PDCA Resolution เฉลี่ย</td><td><?= dashboard_pct($avgPdcaResolution) ?>%</td></tr>
            <tr><td>คะแนนต่ำกว่าเกณฑ์ auto-pass</td><td><?= (int) $lowScoreCourses ?></td></tr>
            <tr><td>PDCA carried forward</td><td><?= (int) $carryForwardCourses ?></td></tr>
        </table>

        <h3>วิชาที่ควรติดตามก่อนยืนยันผล</h3>
        <table>
            <tr>
                <th>รหัสวิชา</th>
                <th>ชื่อวิชา</th>
                <th>ผู้สอน</th>
                <th>Bloom</th>
                <th>PLO</th>
                <th>Activity</th>
                <th>สถานะ</th>
                <th>เหตุผลที่ต้องติดตาม</th>
            </tr>
            <?php if (empty($attentionRows)): ?>
                <tr><td colspan="8">ยังไม่พบวิชาที่ต้องติดตามเพิ่มเติม</td></tr>
            <?php else: ?>
                <?php foreach ($attentionRows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['course_code']) ?></td>
                        <td><?= htmlspecialchars($row['course_name']) ?></td>
                        <td><?= htmlspecialchars($row['instructor']) ?></td>
                        <td><?= dashboard_pct($row['score_bloom']) ?>%</td>
                        <td><?= dashboard_pct($row['score_plo']) ?>%</td>
                        <td><?= dashboard_pct($row['score_activity']) ?>%</td>
                        <td><?= htmlspecialchars($row['status']) ?></td>
                        <td><?= htmlspecialchars(implode(' | ', $row['reasons'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <h3>ประเด็นที่ต้องนำไปต่อในรอบถัดไป</h3>
        <table>
            <tr>
                <th>รายวิชา</th>
                <th>หมวด</th>
                <th>ประเด็น</th>
                <th>ความคืบหน้า</th>
            </tr>
            <?php if (empty($carryForwardIssues)): ?>
                <tr><td colspan="4">ยังไม่พบประเด็น carry forward ในเงื่อนไขที่เลือก</td></tr>
            <?php else: ?>
                <?php foreach ($carryForwardIssues as $issue): ?>
                    <tr>
                        <td><?= htmlspecialchars($issue['course_code']) ?> - <?= htmlspecialchars($issue['course_name']) ?></td>
                        <td><?= htmlspecialchars($issue['issue_category']) ?></td>
                        <td><?= htmlspecialchars($issue['issue_title']) ?><?= !empty($issue['issue_detail']) ? ' | ' . htmlspecialchars($issue['issue_detail']) : '' ?></td>
                        <td><?= dashboard_pct($issue['resolution_percent']) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <h3>แนวโน้มรายปี</h3>
        <table>
            <tr>
                <th>ปีการศึกษา</th>
                <th>ผ่าน/ทั้งหมด</th>
                <th>Bloom เฉลี่ย</th>
                <th>PLO เฉลี่ย</th>
                <th>Activity เฉลี่ย</th>
                <th>PDCA Resolution เฉลี่ย</th>
            </tr>
            <?php if (empty($yearlyTrend)): ?>
                <tr><td colspan="6">ยังไม่มีข้อมูลแนวโน้มรายปี</td></tr>
            <?php else: ?>
                <?php foreach ($yearlyTrend as $trend): ?>
                    <tr>
                        <td><?= htmlspecialchars($trend['year']) ?></td>
                        <td><?= (int) $trend['passed_courses'] ?>/<?= (int) $trend['total_courses'] ?></td>
                        <td><?= dashboard_pct($trend['avg_bloom']) ?>%</td>
                        <td><?= dashboard_pct($trend['avg_plo']) ?>%</td>
                        <td><?= dashboard_pct($trend['avg_activity']) ?>%</td>
                        <td><?= dashboard_pct($trend['avg_pdca_resolution']) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </body>
    </html>
    <?php
    exit;
}

if ($exportMode === 'print' || $exportMode === 'pdf') {
    $autoPrint = ($exportMode === 'pdf');
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($reportLabel) ?></title>
        <style>
            @page {
                size: A4 portrait;
                margin: 10mm 9mm 10mm 9mm;
            }
            body { font-family: Sarabun, Arial, sans-serif; color: #1f2937; margin: 0; background: #eef4fb; }
            .page { width: 190mm; max-width: 190mm; margin: 0 auto; padding: 6mm; box-sizing: border-box; }
            .sheet {
                background: #fff;
                border: 1px solid #d6e4f0;
                border-radius: 16px;
                padding: 7mm;
                box-shadow: 0 16px 42px rgba(15, 23, 42, 0.08);
                box-sizing: border-box;
            }
            .toolbar { display: flex; gap: 10px; margin-bottom: 18px; }
            .toolbar button, .toolbar a {
                border: none;
                border-radius: 10px;
                padding: 10px 14px;
                text-decoration: none;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
            }
            .toolbar .primary { background: #15335f; color: #fff; }
            .toolbar .secondary { background: #f3f4f6; color: #111827; }
            .header {
                background: linear-gradient(135deg, #15335f 0%, #2b6cb0 100%);
                color: #fff;
                border-radius: 14px;
                padding: 6mm;
                margin-bottom: 5mm;
            }
            .header h1 { margin: 0 0 2mm; font-size: 22pt; line-height: 1.15; }
            .header p { margin: 0; color: rgba(255,255,255,0.88); font-size: 10.5pt; }
            .meta-row {
                display: flex;
                justify-content: space-between;
                gap: 4mm;
                flex-wrap: wrap;
                margin-top: 3mm;
                font-size: 9pt;
                color: rgba(255,255,255,0.9);
            }
            .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 3mm; margin-bottom: 5mm; }
            .card { border: 1px solid #d1d5db; border-radius: 12px; padding: 3.2mm; background: #fbfdff; }
            .label { color: #6b7280; font-size: 8.5pt; }
            .value { font-size: 18pt; font-weight: 700; margin-top: 1.5mm; color: #15335f; line-height: 1.1; }
            .subvalue { font-size: 8.5pt; color: #6b7280; margin-top: 1.5mm; line-height: 1.2; }
            .section {
                margin-top: 5mm;
                padding-top: 4mm;
                border-top: 1px solid #e5e7eb;
            }
            .section h2 { margin: 0 0 2.5mm; color: #15335f; font-size: 15pt; line-height: 1.15; }
            .section-note {
                margin: 0 0 3mm;
                color: #6b7280;
                font-size: 8.8pt;
                line-height: 1.25;
            }
            .highlight-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 3mm; margin-bottom: 3mm; }
            .highlight {
                border-radius: 12px;
                padding: 3.2mm;
                border: 1px solid #d1d5db;
            }
            .highlight strong { display: block; font-size: 16pt; margin-top: 1.5mm; line-height: 1.1; }
            .highlight.green { background: #ecfdf3; border-color: #bbf7d0; }
            .highlight.yellow { background: #fffbeb; border-color: #fde68a; }
            .highlight.red { background: #fef2f2; border-color: #fecaca; }
            table { width: 100%; border-collapse: collapse; margin-top: 2mm; table-layout: fixed; }
            th, td { border: 1px solid #d1d5db; padding: 2.1mm; vertical-align: top; font-size: 8.8pt; line-height: 1.25; word-break: break-word; }
            th { background: #eff6ff; text-align: left; color: #15335f; }
            .score-pill {
                display: inline-block;
                padding: 1.2mm 2mm;
                border-radius: 999px;
                background: #f3f4f6;
                margin-right: 1.2mm;
                margin-bottom: 1.2mm;
                font-size: 8.2pt;
            }
            .status-badge {
                display: inline-block;
                padding: 1.2mm 2mm;
                border-radius: 999px;
                font-size: 8.2pt;
                font-weight: 700;
            }
            .status-success { background: #dcfce7; color: #166534; }
            .status-warning { background: #fef3c7; color: #92400e; }
            .status-muted { background: #e5e7eb; color: #374151; }
            .print-note { margin-top: 4mm; color: #6b7280; font-size: 8.2pt; line-height: 1.25; }
            .resolution-box {
                border: 1px solid #d1d5db;
                border-radius: 12px;
                padding: 3.5mm;
                min-height: 28mm;
                background: #fafcff;
            }
            .meeting-meta {
                display: grid;
                grid-template-columns: 34mm 1fr;
                gap: 2mm 3mm;
                align-items: center;
            }
            .meeting-line {
                border-bottom: 1px dashed #9ca3af;
                min-height: 7mm;
            }
            .signature-grid {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 3mm;
                margin-top: 4mm;
                align-items: stretch;
            }
            .signature-card {
                border: 1px solid #d1d5db;
                border-radius: 12px;
                padding: 4mm 3.2mm 3mm;
                min-height: 36mm;
                background: #fff;
            }
            .signature-space {
                height: 10mm;
            }
            .signature-line {
                border-bottom: 1px solid #111827;
                margin-bottom: 2mm;
            }
            .signature-role {
                font-weight: 700;
                color: #15335f;
                margin-bottom: 2mm;
                font-size: 10pt;
            }
            .footer-scale-wrap {
                transform-origin: top center;
            }
            body.compact-print .page { padding: 3mm; }
            body.compact-print .sheet { padding: 4.5mm; }
            body.compact-print .header { padding: 4.5mm; margin-bottom: 3.5mm; }
            body.compact-print .header h1 { font-size: 18pt; }
            body.compact-print .header p { font-size: 9.2pt; }
            body.compact-print .meta-row { margin-top: 2mm; font-size: 8.2pt; gap: 2.5mm; }
            body.compact-print .grid { gap: 2mm; margin-bottom: 3.5mm; }
            body.compact-print .card { padding: 2.2mm; }
            body.compact-print .label,
            body.compact-print .subvalue { font-size: 7.6pt; }
            body.compact-print .value { font-size: 15pt; margin-top: 1mm; }
            body.compact-print .section { margin-top: 3.5mm; padding-top: 2.8mm; }
            body.compact-print .section h2 { font-size: 12.5pt; margin-bottom: 1.8mm; }
            body.compact-print .section-note { font-size: 7.9pt; margin-bottom: 2mm; line-height: 1.18; }
            body.compact-print .highlight-grid { gap: 2mm; margin-bottom: 2mm; }
            body.compact-print .highlight { padding: 2.2mm; }
            body.compact-print .highlight strong { font-size: 13pt; margin-top: 1mm; }
            body.compact-print table { margin-top: 1.2mm; }
            body.compact-print th,
            body.compact-print td { padding: 1.5mm; font-size: 7.8pt; line-height: 1.15; }
            body.compact-print .score-pill,
            body.compact-print .status-badge { font-size: 7.2pt; padding: 0.8mm 1.4mm; margin-right: 0.8mm; margin-bottom: 0.8mm; }
            body.compact-print .resolution-box { padding: 2.2mm; min-height: 20mm; }
            body.compact-print .meeting-meta { grid-template-columns: 28mm 1fr; gap: 1.2mm 2mm; }
            body.compact-print .meeting-line { min-height: 5mm; }
            body.compact-print .signature-grid { gap: 2mm; margin-top: 2.5mm; }
            body.compact-print .signature-card { padding: 2.5mm 2.2mm 2mm; min-height: 26mm; }
            body.compact-print .signature-space { height: 6mm; }
            body.compact-print .signature-role { font-size: 8.6pt; margin-bottom: 1.2mm; }
            body.compact-print .print-note { margin-top: 2.5mm; font-size: 7.4pt; line-height: 1.15; }
            @media print {
                body { margin: 0; background: #fff; }
                .page { width: 190mm; max-width: 190mm; padding: 0; }
                .sheet { box-shadow: none; border: none; border-radius: 0; padding: 0; }
                .no-print { display: none !important; }
                .header { break-inside: avoid; }
                .section, table { break-inside: avoid; }
                .section { page-break-inside: avoid; }
                .footer-scale-wrap {
                    transform: scale(0.92);
                    width: 108.7%;
                    margin-left: -4.3%;
                }
                .meeting-meta { grid-template-columns: 28mm 1fr; }
                .resolution-box { min-height: 20mm; }
                .signature-grid {
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                    gap: 2mm;
                    margin-top: 3mm;
                }
                .signature-card {
                    min-height: 28mm;
                    padding: 2.8mm 2.2mm 2.2mm;
                }
                .signature-space { height: 7mm; }
                .signature-role { font-size: 8.8pt; }
                .label { font-size: 7.8pt; }
            }
            @media screen and (max-width: 900px) {
                .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .highlight-grid { grid-template-columns: 1fr; }
                .signature-grid { grid-template-columns: 1fr; }
                .meeting-meta { grid-template-columns: 1fr; }
            }
        </style>
    </head>
    <body class="<?= $isCompactPrint ? 'compact-print' : '' ?>">
        <div class="page">
            <div class="sheet">
                <div class="toolbar no-print">
                    <button class="primary" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
                    <a class="secondary" href="verification_dashboard.php?<?= htmlspecialchars(http_build_query(['f_year' => $selectedYear, 'f_sem' => $selectedSemester])) ?>">กลับหน้า Dashboard</a>
                    <?php if ($isCompactPrint): ?>
                        <a class="secondary" href="verification_dashboard.php?<?= htmlspecialchars(http_build_query(['f_year' => $selectedYear, 'f_sem' => $selectedSemester, 'export' => $exportMode])) ?>">สลับเป็น Standard</a>
                    <?php else: ?>
                        <a class="secondary" href="verification_dashboard.php?<?= htmlspecialchars(http_build_query(['f_year' => $selectedYear, 'f_sem' => $selectedSemester, 'export' => $exportMode, 'print_mode' => 'compact'])) ?>">สลับเป็น Compact</a>
                    <?php endif; ?>
                </div>

                <div class="header">
                    <h1>รายงานสรุปการทวนสอบรายวิชา</h1>
                    <p><?= htmlspecialchars($reportLabel) ?></p>
                    <div class="meta-row">
                        <span>ออกรายงานเมื่อ <?= htmlspecialchars(date('Y-m-d H:i:s')) ?></span>
                        <span>เกณฑ์ AI auto-pass <?= (int) $autoPassThreshold ?>%</span>
                        <span>ใช้สำหรับการประชุมกรรมการและการลงนามรับรองผล</span>
                    </div>
                </div>

                <div class="grid">
                    <div class="card"><div class="label">รายวิชาทั้งหมด</div><div class="value"><?= (int) $totalCourses ?></div><div class="subvalue">ในรอบที่เลือก</div></div>
                    <div class="card"><div class="label">ผ่านการทวนสอบแล้ว</div><div class="value"><?= (int) $passedCourses ?></div><div class="subvalue"><?= $totalCourses > 0 ? round(($passedCourses / $totalCourses) * 100) : 0 ?>% ของรอบนี้</div></div>
                    <div class="card"><div class="label">เอกสารพร้อมใช้ (.docx ครบ)</div><div class="value"><?= (int) $docxReadyCourses ?></div><div class="subvalue">เอกสารเสี่ยง <?= (int) $legacyDocCourses ?> วิชา</div></div>
                    <div class="card"><div class="label">PDCA Resolution เฉลี่ย</div><div class="value"><?= dashboard_pct($avgPdcaResolution) ?>%</div><div class="subvalue">carry forward <?= (int) $carryForwardCourses ?> วิชา</div></div>
                </div>

                <div class="section">
                    <h2>Executive Snapshot</h2>
                    <p class="section-note">สรุปประเด็นหลักที่ควรใช้ประกอบการประชุมก่อนกรรมการยืนยันผลทวนสอบทั้งรอบ</p>
                    <div class="highlight-grid">
                        <div class="highlight green">
                            วิชาที่พร้อมยืนยันผล
                            <strong><?= (int) $passedCourses ?></strong>
                            <div class="subvalue">สถานะผ่านการทวนสอบแล้ว</div>
                        </div>
                        <div class="highlight yellow">
                            วิชาที่ต้องติดตามเพิ่ม
                            <strong><?= count($attentionRows) ?></strong>
                            <div class="subvalue">ยังมีเหตุผลที่ควรตรวจทานก่อนปิดรอบ</div>
                        </div>
                        <div class="highlight red">
                            เอกสารเสี่ยงหรือไม่ครบ
                            <strong><?= (int) ($legacyDocCourses + $missingDocCourses) ?></strong>
                            <div class="subvalue">ควรตามผู้สอนให้อัปเดตเอกสารก่อนยืนยันผล</div>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <h2>Score Overview</h2>
                    <p class="section-note">ใช้ดูความพร้อมเชิงคุณภาพของ CLO, PLO และกิจกรรมการเรียนการสอนในภาพรวม</p>
                    <table>
                        <tr><th>ตัวชี้วัด</th><th>ค่า</th><th>หมายเหตุ</th></tr>
                        <tr><td>Bloom เฉลี่ย</td><td><?= dashboard_pct($avgBloom) ?>%</td><td><?= $avgBloom >= $autoPassThreshold ? 'อยู่ในระดับที่น่าพอใจ' : 'ยังมีวิชาที่ควรทบทวน CLO verb เพิ่ม' ?></td></tr>
                        <tr><td>PLO เฉลี่ย</td><td><?= dashboard_pct($avgPlo) ?>%</td><td><?= $avgPlo >= $autoPassThreshold ? 'coverage โดยรวมดี' : 'ควรตรวจ mapping และความครอบคลุมหลักสูตร' ?></td></tr>
                        <tr><td>Activity เฉลี่ย</td><td><?= dashboard_pct($avgActivity) ?>%</td><td><?= $avgActivity >= $autoPassThreshold ? 'กิจกรรมโดยรวมสอดคล้อง' : 'ควรทบทวนกิจกรรมให้สอดคล้อง CLO มากขึ้น' ?></td></tr>
                        <tr><td>คะแนนต่ำกว่าเกณฑ์ auto-pass</td><td><?= (int) $lowScoreCourses ?> วิชา</td><td>เกณฑ์รอบนี้ <?= (int) $autoPassThreshold ?>%</td></tr>
                    </table>
                </div>

                <div class="section">
                    <h2>วิชาที่ควรติดตามก่อนยืนยันผล</h2>
                    <p class="section-note">รายการนี้เหมาะสำหรับกรรมการใช้ไล่ดูวิชาที่มีความเสี่ยงด้านคะแนน เอกสาร หรือ PDCA ค้างจากรอบก่อน</p>
                    <table>
                        <tr>
                            <th>รหัสวิชา</th>
                            <th>ชื่อวิชาและผู้สอน</th>
                            <th>คะแนนภาพรวม</th>
                            <th>สถานะ / PDCA</th>
                            <th>เหตุผลที่ต้องติดตาม</th>
                        </tr>
                        <?php if (empty($attentionRows)): ?>
                            <tr><td colspan="5">ยังไม่พบวิชาที่ต้องติดตามเพิ่มเติม</td></tr>
                        <?php else: ?>
                            <?php foreach ($attentionRows as $row): ?>
                                <?php
                                $statusClass = $row['status'] === 'ผ่านการทวนสอบ'
                                    ? 'status-success'
                                    : ($row['status'] === 'กำลังตรวจสอบ' ? 'status-warning' : 'status-muted');
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['course_code']) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($row['course_name']) ?></strong><br>
                                        <span class="label">ผู้สอน <?= htmlspecialchars($row['instructor']) ?></span>
                                    </td>
                                    <td>
                                        <span class="score-pill">Bloom <?= dashboard_pct($row['score_bloom']) ?>%</span>
                                        <span class="score-pill">PLO <?= dashboard_pct($row['score_plo']) ?>%</span>
                                        <span class="score-pill">Activity <?= dashboard_pct($row['score_activity']) ?>%</span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars(dashboard_status_label($row['status'])) ?></span><br>
                                        <span class="label">PDCA: <?= htmlspecialchars(dashboard_pdca_label($row['pdca_status'])) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars(implode(' | ', $row['reasons'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>

                <div class="section">
                    <h2>แนวโน้มรายปี</h2>
                    <p class="section-note">ใช้ดูภาพการพัฒนาอย่างต่อเนื่องของหลักสูตรและประสิทธิผลของการติดตามแบบ PDCA</p>
                    <table>
                        <tr>
                            <th>ปีการศึกษา</th>
                            <th>ผ่าน/ทั้งหมด</th>
                            <th>Bloom</th>
                            <th>PLO</th>
                            <th>Activity</th>
                            <th>PDCA</th>
                        </tr>
                        <?php if (empty($yearlyTrend)): ?>
                            <tr><td colspan="6">ยังไม่มีข้อมูลแนวโน้มรายปี</td></tr>
                        <?php else: ?>
                            <?php foreach ($yearlyTrend as $trend): ?>
                                <tr>
                                    <td><?= htmlspecialchars($trend['year']) ?></td>
                                    <td><?= (int) $trend['passed_courses'] ?>/<?= (int) $trend['total_courses'] ?></td>
                                    <td><?= dashboard_pct($trend['avg_bloom']) ?>%</td>
                                    <td><?= dashboard_pct($trend['avg_plo']) ?>%</td>
                                    <td><?= dashboard_pct($trend['avg_activity']) ?>%</td>
                                    <td><?= dashboard_pct($trend['avg_pdca_resolution']) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>

                <div class="section">
                    <h2>ความพร้อมสำหรับรอบถัดไป</h2>
                    <p class="section-note">ใช้รายการนี้เป็นฐานตั้งต้นของการคัดเลือกรายวิชาและติดตาม PDCA ในรอบประเมินถัดไป โดยเฉพาะประเด็นที่ยัง carry forward อยู่</p>
                    <table>
                        <tr>
                            <th>รายวิชา</th>
                            <th>หมวด</th>
                            <th>ประเด็นที่ต้องสานต่อ</th>
                            <th>ความคืบหน้าปัจจุบัน</th>
                        </tr>
                        <?php if (empty($carryForwardIssues)): ?>
                            <tr><td colspan="4">ยังไม่พบประเด็นที่ต้อง carry forward ไปยังรอบถัดไป</td></tr>
                        <?php else: ?>
                            <?php foreach ($carryForwardIssues as $issue): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($issue['course_code']) ?></strong><br>
                                        <span class="label"><?= htmlspecialchars($issue['course_name']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($issue['issue_category']) ?></td>
                                    <td><?= htmlspecialchars($issue['issue_title']) ?><?= !empty($issue['issue_detail']) ? '<br><span class="label">' . htmlspecialchars($issue['issue_detail']) . '</span>' : '' ?></td>
                                    <td><?= dashboard_pct($issue['resolution_percent']) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>

                <div class="footer-scale-wrap">
                    <div class="section">
                        <h2>มติกรรมการและการลงนามรับรองผล</h2>
                        <p class="section-note">ส่วนนี้เว้นไว้สำหรับบันทึกผลการประชุมและการลงนามรับรองผลการทวนสอบของคณะกรรมการ</p>

                        <div class="resolution-box">
                            <div class="meeting-meta">
                                <div><strong>วันที่ประชุม</strong></div>
                                <div class="meeting-line"></div>
                                <div><strong>มติกรรมการ</strong></div>
                                <div class="meeting-line"></div>
                                <div><strong>ข้อสังเกตเพิ่มเติม</strong></div>
                                <div class="meeting-line"></div>
                            </div>
                        </div>

                        <div class="signature-grid">
                            <div class="signature-card">
                                <div class="signature-role">ประธานกรรมการ</div>
                                <div class="signature-space"></div>
                                <div class="signature-line"></div>
                                <div class="label">ลงชื่อ ...............................................................</div>
                                <div class="label">ตำแหน่ง/หมายเหตุ ................................................</div>
                            </div>
                            <div class="signature-card">
                                <div class="signature-role">กรรมการผู้ร่วมพิจารณา</div>
                                <div class="signature-space"></div>
                                <div class="signature-line"></div>
                                <div class="label">ลงชื่อ ...............................................................</div>
                                <div class="label">ตำแหน่ง/หมายเหตุ ................................................</div>
                            </div>
                            <div class="signature-card">
                                <div class="signature-role">เลขานุการ / ผู้ตรวจทาน</div>
                                <div class="signature-space"></div>
                                <div class="signature-line"></div>
                                <div class="label">ลงชื่อ ...............................................................</div>
                                <div class="label">ตำแหน่ง/หมายเหตุ ................................................</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="print-note">หมายเหตุ: เอกสารนี้ออกแบบให้พิมพ์หรือบันทึกเป็น PDF ได้โดยตรงจากเบราว์เซอร์ โดยไม่ต้องติดตั้ง library เพิ่มบน server</div>
            </div>
        </div>
        <?php if ($autoPrint): ?>
            <script>window.print();</script>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AUNQA Verification Summary</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Sarabun', sans-serif; }
        .hero { background: linear-gradient(135deg, #15335f 0%, #2b6cb0 100%); color: white; padding: 24px 0; border-radius: 0 0 24px 24px; margin-bottom: 28px; }
        .nav-link { color: #555; font-weight: 500; }
        .nav-link.active { font-weight: bold; color: #1e3c72; border-bottom: 3px solid #1e3c72; }
        .summary-card, .panel-card { border: none; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06); }
        .summary-card { overflow: hidden; min-height: 140px; }
        .metric-label { font-size: 0.85rem; color: #6c757d; }
        .metric-value { font-size: 2rem; font-weight: 700; line-height: 1; }
        .metric-chip { font-size: 0.78rem; font-weight: 600; }
        .section-title { font-weight: 700; color: #1e3c72; }
        .mini-progress { height: 8px; border-radius: 999px; background: #e9ecef; overflow: hidden; }
        .mini-progress > span { display: block; height: 100%; border-radius: 999px; }
        .trend-row { display: grid; grid-template-columns: 80px 1fr 70px; gap: 12px; align-items: center; margin-bottom: 10px; }
        .legend-chip { padding: 4px 8px; border-radius: 999px; font-size: 0.78rem; font-weight: 600; display: inline-block; }
        .legend-green { background: #d1e7dd; color: #0f5132; }
        .legend-yellow { background: #fff3cd; color: #856404; }
        .legend-red { background: #f8d7da; color: #842029; }
        .legend-blue { background: #dbeafe; color: #1d4ed8; }
        .note-chip { font-size: 0.72rem; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-white py-3 shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold text-primary" href="index.php"><i class="bi bi-rocket-takeoff"></i> AUNQA Hub</a>
        <div class="navbar-nav">
            <a class="nav-link" href="index.php">ติดตาม มคอ (Dashboard)</a>
            <a class="nav-link" href="verification.php">กระดานคัดเลือกทวนสอบ (Verification)</a>
            <a class="nav-link" href="verification_board.php">ประเมินและทวนสอบผล (Tracking)</a>
            <a class="nav-link active" href="verification_dashboard.php">สรุปรอบประเมิน</a>
        </div>
    </div>
</nav>

<div class="hero text-center">
    <div class="container">
        <h2 class="fw-bold mb-1">Dashboard Summary รอบการทวนสอบ</h2>
        <p class="mb-0">สรุปภาพรวมรายวิชา คะแนน เอกสาร และ PDCA ของรอบประเมินปัจจุบัน</p>
    </div>
</div>

<div class="container pb-5">
    <div class="card panel-card p-3 mb-4">
        <form class="row g-3 align-items-end" method="GET">
            <div class="col-md-4">
                <label class="form-label fw-bold">ปีการศึกษา</label>
                <select name="f_year" class="form-select">
                    <?php if (empty($availableYears)): ?>
                        <option value="">ยังไม่มีข้อมูล</option>
                    <?php else: ?>
                        <?php foreach ($availableYears as $yearOption): ?>
                            <option value="<?= htmlspecialchars($yearOption) ?>" <?= $selectedYear === $yearOption ? 'selected' : '' ?>><?= htmlspecialchars($yearOption) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">ภาคเรียน</label>
                <select name="f_sem" class="form-select">
                    <option value="">ทุกภาคเรียน</option>
                    <?php foreach ($availableSemesters as $semesterOption): ?>
                        <option value="<?= htmlspecialchars($semesterOption) ?>" <?= $selectedSemester === $semesterOption ? 'selected' : '' ?>>เทอม <?= htmlspecialchars($semesterOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">เกณฑ์ auto-pass ปัจจุบัน</label>
                <div class="form-control bg-light"><?= (int) $autoPassThreshold ?>%</div>
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-funnel"></i> กรอง</button>
            </div>
        </form>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <a class="btn btn-outline-success btn-sm" href="?<?= htmlspecialchars(http_build_query(['f_year' => $selectedYear, 'f_sem' => $selectedSemester, 'export' => 'excel'])) ?>">
                <i class="bi bi-file-earmark-excel"></i> Export Excel
            </a>
            <a class="btn btn-outline-primary btn-sm" target="_blank" href="?<?= htmlspecialchars(http_build_query(['f_year' => $selectedYear, 'f_sem' => $selectedSemester, 'export' => 'print'])) ?>">
                <i class="bi bi-printer"></i> เปิดมุมมองสำหรับพิมพ์
            </a>
            <a class="btn btn-outline-dark btn-sm" target="_blank" href="?<?= htmlspecialchars(http_build_query(['f_year' => $selectedYear, 'f_sem' => $selectedSemester, 'export' => 'print', 'print_mode' => 'compact'])) ?>">
                <i class="bi bi-arrows-angle-contract"></i> Print Compact
            </a>
            <a class="btn btn-outline-danger btn-sm" target="_blank" href="?<?= htmlspecialchars(http_build_query(['f_year' => $selectedYear, 'f_sem' => $selectedSemester, 'export' => 'pdf'])) ?>">
                <i class="bi bi-file-earmark-pdf"></i> Export PDF
            </a>
            <a class="btn btn-danger btn-sm" target="_blank" href="?<?= htmlspecialchars(http_build_query(['f_year' => $selectedYear, 'f_sem' => $selectedSemester, 'export' => 'pdf', 'print_mode' => 'compact'])) ?>">
                <i class="bi bi-file-earmark-pdf-fill"></i> Compact PDF
            </a>
        </div>
        <div class="mt-3 small text-muted">
            <span class="legend-chip legend-green me-2">พร้อมทวนสอบ</span>
            <span class="legend-chip legend-yellow me-2">เอกสารยังเสี่ยง</span>
            <span class="legend-chip legend-red me-2">ต้องติดตามเพิ่ม</span>
            <span class="legend-chip legend-blue">ใช้ดูภาพรวมก่อนกรรมการยืนยันผลรอบปี</span>
        </div>
    </div>

    <?php if (!empty($nextRoundFlash['message'])): ?>
        <div class="alert <?= str_contains($nextRoundFlash['message'], 'ไม่สำเร็จ') ? 'alert-danger' : 'alert-info' ?>"><?= htmlspecialchars($nextRoundFlash['message']) ?></div>
    <?php endif; ?>
    <?php if (!empty($nextRoundFlash['target_year']) && !empty($nextRoundFlash['target_semester'])): ?>
        <div class="card panel-card p-3 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="fw-bold text-primary">รอบใหม่ที่เพิ่งสร้าง</div>
                    <div class="small text-muted">ปี <?= htmlspecialchars($nextRoundFlash['target_year']) ?> / เทอม <?= htmlspecialchars($nextRoundFlash['target_semester']) ?></div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-primary btn-sm" href="verification_board.php?<?= htmlspecialchars(http_build_query(['f_year' => $nextRoundFlash['target_year'], 'f_sem' => $nextRoundFlash['target_semester']])) ?>">
                        <i class="bi bi-box-arrow-up-right"></i> เปิดรอบใหม่ที่เพิ่งสร้าง
                    </a>
                    <?php if (!empty($nextRoundFlash['seed_batch_token']) && $nextRoundFlash['created_courses'] > 0): ?>
                        <form method="POST" onsubmit="return confirm('ต้องการลบรอบทดลองที่เพิ่ง seed ไว้ทั้งหมดใช่หรือไม่');">
                            <input type="hidden" name="action" value="delete_seed_round">
                            <input type="hidden" name="seed_batch_token" value="<?= htmlspecialchars($nextRoundFlash['seed_batch_token']) ?>">
                            <input type="hidden" name="target_year" value="<?= htmlspecialchars($nextRoundFlash['target_year']) ?>">
                            <input type="hidden" name="target_semester" value="<?= htmlspecialchars($nextRoundFlash['target_semester']) ?>">
                            <input type="hidden" name="source_year" value="<?= htmlspecialchars($selectedYear) ?>">
                            <input type="hidden" name="source_semester" value="<?= htmlspecialchars($selectedSemester) ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-trash3"></i> ลบรอบนั้นทิ้ง
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$pdo): ?>
        <div class="alert alert-danger">ไม่สามารถเชื่อมฐานข้อมูลได้ในขณะนี้</div>
    <?php elseif ($totalCourses === 0): ?>
        <div class="alert alert-warning">ยังไม่มีข้อมูลรายวิชาทวนสอบสำหรับเงื่อนไขที่เลือก</div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card summary-card p-3">
                    <div class="metric-label">รายวิชาที่อยู่ในรอบนี้</div>
                    <div class="metric-value text-primary mt-2"><?= $totalCourses ?></div>
                    <div class="metric-chip text-muted mt-2">ปี <?= htmlspecialchars($selectedYear ?: '-') ?> <?= $selectedSemester !== '' ? '/ เทอม ' . htmlspecialchars($selectedSemester) : '/ ทุกเทอม' ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card p-3">
                    <div class="metric-label">ผ่านการทวนสอบแล้ว</div>
                    <div class="metric-value text-success mt-2"><?= $passedCourses ?></div>
                    <div class="metric-chip text-muted mt-2"><?= $totalCourses > 0 ? round(($passedCourses / $totalCourses) * 100) : 0 ?>% ของรอบนี้</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card p-3">
                    <div class="metric-label">เอกสารพร้อมใช้ (.docx ครบ)</div>
                    <div class="metric-value text-info mt-2"><?= $docxReadyCourses ?></div>
                    <div class="metric-chip text-muted mt-2">เอกสารเสี่ยง <?= $legacyDocCourses ?> วิชา</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card p-3">
                    <div class="metric-label">PDCA Resolution เฉลี่ย</div>
                    <div class="metric-value text-warning mt-2"><?= dashboard_pct($avgPdcaResolution) ?>%</div>
                    <div class="metric-chip text-muted mt-2">carry forward <?= $carryForwardCourses ?> วิชา</div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="card panel-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="section-title mb-0"><i class="bi bi-bar-chart-line-fill"></i> Score Overview</h5>
                        <span class="badge bg-dark">Threshold <?= (int) $autoPassThreshold ?>%</span>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1"><span>Bloom Score เฉลี่ย</span><strong><?= dashboard_pct($avgBloom) ?>%</strong></div>
                        <div class="mini-progress"><span class="<?= dashboard_progress_class($avgBloom) ?>" style="width: <?= max(0, min(100, $avgBloom)) ?>%"></span></div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1"><span>PLO Coverage เฉลี่ย</span><strong><?= dashboard_pct($avgPlo) ?>%</strong></div>
                        <div class="mini-progress"><span class="<?= dashboard_progress_class($avgPlo) ?>" style="width: <?= max(0, min(100, $avgPlo)) ?>%"></span></div>
                    </div>
                    <div class="mb-4">
                        <div class="d-flex justify-content-between mb-1"><span>Activity Alignment เฉลี่ย</span><strong><?= dashboard_pct($avgActivity) ?>%</strong></div>
                        <div class="mini-progress"><span class="<?= dashboard_progress_class($avgActivity) ?>" style="width: <?= max(0, min(100, $avgActivity)) ?>%"></span></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="p-3 rounded bg-light h-100">
                                <div class="small text-muted">คะแนนต่ำกว่าเกณฑ์</div>
                                <div class="fs-4 fw-bold text-danger"><?= $lowScoreCourses ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 rounded bg-light h-100">
                                <div class="small text-muted">กำลังตรวจสอบ</div>
                                <div class="fs-4 fw-bold text-warning"><?= $inProgressCourses ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 rounded bg-light h-100">
                                <div class="small text-muted">รอเอกสาร/รอเริ่ม</div>
                                <div class="fs-4 fw-bold text-secondary"><?= $waitingCourses ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card panel-card p-4 h-100">
                    <h5 class="section-title mb-3"><i class="bi bi-folder-check"></i> Document Readiness</h5>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1"><span>.docx ครบทั้ง มคอ.3 และ มคอ.5</span><strong><?= $docxReadyCourses ?></strong></div>
                        <div class="mini-progress"><span class="bg-success" style="width: <?= $totalCourses > 0 ? round(($docxReadyCourses / $totalCourses) * 100) : 0 ?>%"></span></div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1"><span>มีไฟล์เสี่ยง (.doc/.pdf/อื่น ๆ)</span><strong><?= $legacyDocCourses ?></strong></div>
                        <div class="mini-progress"><span class="bg-warning" style="width: <?= $totalCourses > 0 ? round(($legacyDocCourses / $totalCourses) * 100) : 0 ?>%"></span></div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1"><span>ขาดเอกสารบางส่วน</span><strong><?= $missingDocCourses ?></strong></div>
                        <div class="mini-progress"><span class="bg-danger" style="width: <?= $totalCourses > 0 ? round(($missingDocCourses / $totalCourses) * 100) : 0 ?>%"></span></div>
                    </div>
                    <div class="alert alert-light border small mb-0">
                        วิชาที่ยังไม่เป็น `.docx` ควรตามผู้สอนให้อัปเอกสารใหม่ก่อนยืนยันผลรอบปี เพื่อให้การอ่าน CLO และ AI parser มีความน่าเชื่อถือมากขึ้น
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card panel-card p-4 h-100">
                    <h5 class="section-title mb-3"><i class="bi bi-arrow-repeat"></i> PDCA Status Summary</h5>
                    <?php if (empty($pdcaStatusSummary)): ?>
                        <div class="text-muted">ยังไม่มีข้อมูล PDCA สำหรับรอบนี้</div>
                    <?php else: ?>
                        <?php foreach ($pdcaStatusSummary as $statusRow): ?>
                            <?php $width = $totalCourses > 0 ? round(($statusRow['status_count'] / $totalCourses) * 100) : 0; ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span><?= htmlspecialchars($statusRow['pdca_status']) ?></span>
                                    <strong><?= (int) $statusRow['status_count'] ?></strong>
                                </div>
                                <div class="mini-progress"><span class="<?= dashboard_progress_class($width) ?>" style="width: <?= $width ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card panel-card p-4 h-100">
                    <h5 class="section-title mb-3"><i class="bi bi-tags-fill"></i> PDCA Issue Categories</h5>
                    <?php if (empty($categorySummary)): ?>
                        <div class="text-muted">ยังไม่มี PDCA issue ในรอบนี้</div>
                    <?php else: ?>
                        <?php $maxCategoryCount = max(array_map(function ($item) { return (int) $item['issue_count']; }, $categorySummary)); ?>
                        <?php foreach ($categorySummary as $categoryRow): ?>
                            <?php $width = $maxCategoryCount > 0 ? round(((int) $categoryRow['issue_count'] / $maxCategoryCount) * 100) : 0; ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span><?= htmlspecialchars($categoryRow['issue_category']) ?></span>
                                    <strong><?= (int) $categoryRow['issue_count'] ?> issue</strong>
                                </div>
                                <div class="mini-progress"><span class="bg-primary" style="width: <?= $width ?>%"></span></div>
                                <div class="small text-muted mt-1">confidence เฉลี่ย <?= dashboard_pct($categoryRow['avg_confidence']) ?>%</div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card panel-card p-4 mb-4">
            <h5 class="section-title mb-3"><i class="bi bi-exclamation-diamond-fill"></i> วิชาที่ควรติดตามก่อนยืนยันผล</h5>
            <?php if (empty($attentionRows)): ?>
                <div class="alert alert-success mb-0">ยังไม่พบวิชาที่ต้องติดตามเพิ่มเติมในเงื่อนไขที่เลือก</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>รหัสวิชา</th>
                                <th>ชื่อวิชา</th>
                                <th>ผู้สอน</th>
                                <th>คะแนน</th>
                                <th>สถานะ</th>
                                <th>เหตุผลที่ต้องติดตาม</th>
                                <th class="text-center">เปิดประเมิน</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($attentionRows as $row): ?>
                            <?php
                            $openQuery = [
                                'open_vid' => $row['id']
                            ];
                            if ($selectedYear !== '') {
                                $openQuery['f_year'] = $selectedYear;
                            }
                            if ($selectedSemester !== '') {
                                $openQuery['f_sem'] = $selectedSemester;
                            }
                            ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($row['course_code']) ?></span></td>
                                <td class="fw-medium"><?= htmlspecialchars($row['course_name']) ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($row['instructor']) ?></td>
                                <td class="small">B <?= dashboard_pct($row['score_bloom']) ?> / P <?= dashboard_pct($row['score_plo']) ?> / A <?= dashboard_pct($row['score_activity']) ?></td>
                                <td><span class="badge <?= $row['status'] === 'ผ่านการทวนสอบ' ? 'bg-success' : ($row['status'] === 'กำลังตรวจสอบ' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                                <td class="small">
                                    <?= htmlspecialchars(implode(' | ', $row['reasons'])) ?>
                                    <?php if ($row['pdca_status'] === 'carried_forward'): ?>
                                        <div class="text-danger mt-1">มีประเด็นค้างจากรอบก่อน</div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a class="btn btn-sm btn-primary" href="verification_board.php?<?= htmlspecialchars(http_build_query($openQuery)) ?>">
                                        <i class="bi bi-box-arrow-up-right"></i> เปิดประเมิน
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card panel-card p-4 mb-4">
            <h5 class="section-title mb-3"><i class="bi bi-arrow-repeat"></i> ความพร้อมสำหรับรอบถัดไป</h5>
            <form method="POST" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="action" value="start_next_round_from_carry_forward">
                <input type="hidden" name="source_year" value="<?= htmlspecialchars($selectedYear) ?>">
                <input type="hidden" name="source_semester" value="<?= htmlspecialchars($selectedSemester) ?>">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">ปีการศึกษารอบใหม่</label>
                    <input type="text" class="form-control" name="target_year" value="<?= htmlspecialchars($nextRoundTargetYear) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">ภาคเรียน</label>
                    <input type="text" class="form-control" name="target_semester" value="<?= htmlspecialchars($nextRoundTargetSemester) ?>" required>
                </div>
                <div class="col-md-5">
                    <div class="small text-muted">ระบบจะสร้างวิชาตั้งต้นจากรายการ carry forward และคัดลอก PDCA issue ค้างไปยังรอบใหม่แบบกันซ้ำอัตโนมัติ</div>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('ต้องการสร้างรายการตั้งต้นของรอบใหม่จาก carry forward ใช่หรือไม่');">
                        <i class="bi bi-arrow-right-circle"></i> เริ่มรอบใหม่
                    </button>
                </div>
            </form>
            <?php if (empty($carryForwardIssues)): ?>
                <div class="alert alert-success mb-0">ยังไม่พบประเด็น PDCA ที่ต้อง carry forward ไปยังรอบถัดไปในเงื่อนไขที่เลือก</div>
            <?php else: ?>
                <div class="small text-muted mb-3">รายการนี้ช่วยให้กรรมการและผู้รับผิดชอบหลักสูตรหยิบประเด็นค้างไปใช้ตั้งต้นในการทวนสอบรอบถัดไปได้ทันที</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>รายวิชา</th>
                                <th>หมวด</th>
                                <th>ประเด็นที่ต้องสานต่อ</th>
                                <th>ความคืบหน้า</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($carryForwardIssues as $issue): ?>
                            <tr>
                                <td>
                                    <div class="fw-medium"><?= htmlspecialchars($issue['course_code']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($issue['course_name']) ?></div>
                                </td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($issue['issue_category']) ?></span></td>
                                <td class="small">
                                    <?= htmlspecialchars($issue['issue_title']) ?>
                                    <?php if (!empty($issue['issue_detail'])): ?>
                                        <div class="text-muted mt-1"><?= htmlspecialchars($issue['issue_detail']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($issue['committee_note'])): ?>
                                        <div class="mt-2">
                                            <span class="badge bg-warning text-dark note-chip">หมายเหตุกรรมการ</span>
                                            <div class="text-dark mt-1"><?= nl2br(htmlspecialchars($issue['committee_note'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($issue['next_round_action'])): ?>
                                        <div class="mt-2">
                                            <span class="badge bg-primary note-chip">คำสั่งสำหรับรอบถัดไป</span>
                                            <div class="text-dark mt-1"><?= nl2br(htmlspecialchars($issue['next_round_action'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#committeeNoteModal"
                                            data-issue-id="<?= (int) $issue['id'] ?>"
                                            data-course-code="<?= htmlspecialchars($issue['course_code'], ENT_QUOTES) ?>"
                                            data-issue-title="<?= htmlspecialchars($issue['issue_title'], ENT_QUOTES) ?>"
                                            data-committee-note="<?= htmlspecialchars((string) ($issue['committee_note'] ?? ''), ENT_QUOTES) ?>"
                                            data-next-round-action="<?= htmlspecialchars((string) ($issue['next_round_action'] ?? ''), ENT_QUOTES) ?>"
                                        >
                                            <i class="bi bi-pencil-square"></i> แก้ไขหมายเหตุกรรมการ
                                        </button>
                                    </div>
                                </td>
                                <td class="small fw-bold text-warning"><?= dashboard_pct($issue['resolution_percent']) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card panel-card p-4">
            <h5 class="section-title mb-3"><i class="bi bi-graph-up-arrow"></i> แนวโน้มรายปี</h5>
            <?php if (empty($yearlyTrend)): ?>
                <div class="text-muted">ยังไม่มีข้อมูลแนวโน้มรายปี</div>
            <?php else: ?>
                <?php foreach ($yearlyTrend as $trend): ?>
                    <?php $passRate = ((int) $trend['total_courses']) > 0 ? round(((int) $trend['passed_courses'] / (int) $trend['total_courses']) * 100) : 0; ?>
                    <div class="trend-row">
                        <div class="fw-bold text-primary"><?= htmlspecialchars($trend['year']) ?></div>
                        <div>
                            <div class="mini-progress mb-1"><span class="bg-primary" style="width: <?= $passRate ?>%"></span></div>
                            <div class="small text-muted">ผ่าน <?= $passRate ?>% | Bloom <?= dashboard_pct($trend['avg_bloom']) ?> | PLO <?= dashboard_pct($trend['avg_plo']) ?> | Activity <?= dashboard_pct($trend['avg_activity']) ?> | PDCA <?= dashboard_pct($trend['avg_pdca_resolution']) ?>%</div>
                        </div>
                        <div class="text-end small text-muted"><?= (int) $trend['passed_courses'] ?>/<?= (int) $trend['total_courses'] ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php if ($pdo): ?>
    <div class="modal fade" id="committeeNoteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">แก้ไขหมายเหตุกรรมการ</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_carry_forward_committee_note">
                        <input type="hidden" name="pdca_issue_id" id="committee_note_issue_id">
                        <input type="hidden" name="source_year" value="<?= htmlspecialchars($selectedYear) ?>">
                        <input type="hidden" name="source_semester" value="<?= htmlspecialchars($selectedSemester) ?>">
                        <div class="small text-muted mb-2" id="committee_note_context">-</div>
                        <label class="form-label fw-bold">หมายเหตุกรรมการ</label>
                        <textarea class="form-control" name="committee_note" id="committee_note_text" rows="5" placeholder="ระบุข้อสังเกตเพิ่มเติม คำสั่ง หรือข้อเสนอสำหรับรอบถัดไป"></textarea>
                        <label class="form-label fw-bold mt-3">คำสั่งสำหรับรอบถัดไป</label>
                        <textarea class="form-control" name="next_round_action" id="next_round_action_text" rows="4" placeholder="ระบุสิ่งที่ต้องให้ผู้สอนหรือผู้รับผิดชอบดำเนินการในรอบถัดไป"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึกหมายเหตุกรรมการ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const committeeNoteModal = document.getElementById('committeeNoteModal');
    if (committeeNoteModal) {
        committeeNoteModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) {
                return;
            }

            document.getElementById('committee_note_issue_id').value = button.getAttribute('data-issue-id') || '';
            document.getElementById('committee_note_text').value = button.getAttribute('data-committee-note') || '';
            document.getElementById('next_round_action_text').value = button.getAttribute('data-next-round-action') || '';
            document.getElementById('committee_note_context').textContent = `${button.getAttribute('data-course-code') || '-'} | ${button.getAttribute('data-issue-title') || '-'}`;
        });
    }
</script>
</body>
</html>
