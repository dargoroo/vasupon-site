<?php
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

try {
    $pdo = app_pdo();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$action = $_POST['action'];

function extract_pdca_issue_candidates($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return [];
    }

    $parts = preg_split('/\R+/u', $text);
    $candidates = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        $part = preg_replace('/^\s*(?:[-*•]+|\d+[.)]|[ก-ฮ]\.)\s*/u', '', $part);
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        $title = mb_substr($part, 0, 120);
        $candidates[] = [
            'issue_title' => $title,
            'issue_detail' => $part
        ];
    }

    return $candidates;
}

function infer_pdca_issue_category($text) {
    $text = mb_strtolower(trim((string) $text));
    if ($text === '') {
        return [
            'issue_category' => 'other',
            'category_confidence' => 20.0,
            'category_reason' => 'ไม่พบคำสำคัญชัดเจนในข้อความ',
            'category_inferred_by' => 'rule_based'
        ];
    }

    $rules = [
        'clo_result' => [
            'keywords' => ['clo', 'ผลสัมฤทธิ์', 'target', 'actual', 'cqI', 'cqi', 'ร้อยละ', 'บรรลุผล', 'มคอ.5', 'tqf5'],
            'base_confidence' => 82.0
        ],
        'bloom' => [
            'keywords' => ['bloom', 'taxonomy', 'คำกริยา', 'กริยา', 'understand', 'apply', 'analyze', 'evaluate', 'create'],
            'base_confidence' => 88.0
        ],
        'plo' => [
            'keywords' => ['plo', 'coverage', 'mapping', 'map', 'หลักสูตร', 'ความครอบคลุมหลักสูตร'],
            'base_confidence' => 85.0
        ],
        'activity' => [
            'keywords' => ['กิจกรรม', 'การสอน', 'activity', 'learning activity', 'วิธีสอน', 'การเรียนการสอน'],
            'base_confidence' => 83.0
        ],
        'document' => [
            'keywords' => ['เอกสาร', 'docx', '.doc', '.pdf', 'มคอ.3', 'tqf3', 'ไฟล์', 'อัปโหลด', 'อ่านไม่ได้'],
            'base_confidence' => 90.0
        ],
        'assessment' => [
            'keywords' => ['การประเมิน', 'assessment', 'rubric', 'คะแนน', 'ข้อสอบ', 'วัดผล'],
            'base_confidence' => 80.0
        ]
    ];

    $bestCategory = 'other';
    $bestConfidence = 35.0;
    $bestReason = 'ไม่พบคำสำคัญชัดเจนในข้อความ';

    foreach ($rules as $category => $rule) {
        $matchedKeywords = [];
        foreach ($rule['keywords'] as $keyword) {
            if (mb_strpos($text, mb_strtolower($keyword)) !== false) {
                $matchedKeywords[] = $keyword;
            }
        }

        if (empty($matchedKeywords)) {
            continue;
        }

        $confidence = min(98.0, $rule['base_confidence'] + (count($matchedKeywords) - 1) * 4.0);
        if ($confidence > $bestConfidence) {
            $bestCategory = $category;
            $bestConfidence = $confidence;
            $bestReason = 'ตรวจพบคำสำคัญ: ' . implode(', ', array_slice($matchedKeywords, 0, 3));
        }
    }

    return [
        'issue_category' => $bestCategory,
        'category_confidence' => $bestConfidence,
        'category_reason' => $bestReason,
        'category_inferred_by' => 'rule_based'
    ];
}

try {
    if ($action === 'save_pdca_issue') {
        $verificationId = isset($_POST['verification_id']) ? (int) $_POST['verification_id'] : 0;
        $issueId = isset($_POST['pdca_issue_id']) ? (int) $_POST['pdca_issue_id'] : 0;
        $issueCategory = isset($_POST['issue_category']) ? trim($_POST['issue_category']) : 'other';
        $issueTitle = isset($_POST['issue_title']) ? trim($_POST['issue_title']) : '';
        $issueDetail = isset($_POST['issue_detail']) ? trim($_POST['issue_detail']) : '';
        $currentStatus = isset($_POST['current_status']) ? trim($_POST['current_status']) : 'open';
        $resolutionPercent = isset($_POST['resolution_percent']) ? (float) $_POST['resolution_percent'] : 0;
        $resolutionPercent = max(0, min(100, $resolutionPercent));

        if ($verificationId <= 0 && $issueId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Missing verification_id']);
            exit;
        }

        if ($issueTitle === '' && $issueId === 0) {
            echo json_encode(['success' => false, 'error' => 'กรุณาระบุชื่อประเด็น PDCA']);
            exit;
        }

        $allowedCategories = ['bloom', 'plo', 'activity', 'clo_result', 'document', 'assessment', 'other'];
        if (!in_array($issueCategory, $allowedCategories, true)) {
            $issueCategory = 'other';
        }

        $allowedStatuses = ['open', 'in_progress', 'partially_resolved', 'resolved', 'carried_forward'];
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            $currentStatus = 'open';
        }

        if ($issueId > 0) {
            $stmt = $pdo->prepare("UPDATE aunqa_pdca_issues SET current_status=:status, resolution_percent=:pct, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
            $stmt->execute([
                ':status' => $currentStatus,
                ':pct' => $resolutionPercent,
                ':id' => $issueId
            ]);

            echo json_encode(['success' => true, 'id' => $issueId]);
            exit;
        }

        $stmtRef = $pdo->prepare("SELECT year, semester FROM aunqa_verification_records WHERE id = :id");
        $stmtRef->execute([':id' => $verificationId]);
        $record = $stmtRef->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            echo json_encode(['success' => false, 'error' => 'ไม่พบรายวิชาสำหรับผูก PDCA issue']);
            exit;
        }

        $categoryMeta = [
            'issue_category' => $issueCategory,
            'category_confidence' => 100.0,
            'category_reason' => 'กรรมการเลือกหมวดด้วยตนเอง',
            'category_inferred_by' => 'manual'
        ];

        $stmt = $pdo->prepare("
            INSERT INTO aunqa_pdca_issues (
                verification_id, previous_issue_id, academic_year, semester, issue_category,
                category_confidence, category_reason, category_inferred_by,
                issue_title, issue_detail, severity_level, source_type, source_reference,
                is_recurring, current_status, resolution_percent
            ) VALUES (
                :verification_id, NULL, :academic_year, :semester, :issue_category,
                :category_confidence, :category_reason, :category_inferred_by,
                :issue_title, :issue_detail, 'medium', 'committee', '',
                0, :current_status, :resolution_percent
            )
        ");
        $stmt->execute([
            ':verification_id' => $verificationId,
            ':academic_year' => $record['year'],
            ':semester' => $record['semester'],
            ':issue_category' => $categoryMeta['issue_category'],
            ':category_confidence' => $categoryMeta['category_confidence'],
            ':category_reason' => $categoryMeta['category_reason'],
            ':category_inferred_by' => $categoryMeta['category_inferred_by'],
            ':issue_title' => $issueTitle,
            ':issue_detail' => $issueDetail,
            ':current_status' => $currentStatus,
            ':resolution_percent' => $resolutionPercent
        ]);

        echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'delete_pdca_issue') {
        $issueId = isset($_POST['pdca_issue_id']) ? (int) $_POST['pdca_issue_id'] : 0;
        if ($issueId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Missing pdca_issue_id']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM aunqa_pdca_issues WHERE id = :id");
        $stmt->execute([':id' => $issueId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'import_reviewer_improvement') {
        $verificationId = isset($_POST['verification_id']) ? (int) $_POST['verification_id'] : 0;
        $reviewerImprovement = isset($_POST['reviewer_improvement']) ? trim($_POST['reviewer_improvement']) : '';

        if ($verificationId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Missing verification_id']);
            exit;
        }

        if ($reviewerImprovement === '') {
            echo json_encode(['success' => false, 'error' => 'Missing reviewer_improvement']);
            exit;
        }

        $stmtRef = $pdo->prepare("SELECT year, semester FROM aunqa_verification_records WHERE id = :id");
        $stmtRef->execute([':id' => $verificationId]);
        $record = $stmtRef->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            echo json_encode(['success' => false, 'error' => 'ไม่พบรายวิชาสำหรับผูก PDCA issue']);
            exit;
        }

        $candidates = extract_pdca_issue_candidates($reviewerImprovement);
        if (empty($candidates)) {
            echo json_encode(['success' => true, 'created_count' => 0]);
            exit;
        }

        $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM aunqa_pdca_issues WHERE verification_id = :verification_id AND issue_title = :issue_title");
        $stmtInsert = $pdo->prepare("
            INSERT INTO aunqa_pdca_issues (
                verification_id, previous_issue_id, academic_year, semester, issue_category,
                category_confidence, category_reason, category_inferred_by,
                issue_title, issue_detail, severity_level, source_type, source_reference,
                is_recurring, current_status, resolution_percent
            ) VALUES (
                :verification_id, NULL, :academic_year, :semester, :issue_category,
                :category_confidence, :category_reason, :category_inferred_by,
                :issue_title, :issue_detail, 'medium', 'committee', 'reviewer_improvement',
                0, 'open', 0
            )
        ");

        $createdCount = 0;
        foreach ($candidates as $candidate) {
            $stmtDup->execute([
                ':verification_id' => $verificationId,
                ':issue_title' => $candidate['issue_title']
            ]);

            if ((int) $stmtDup->fetchColumn() > 0) {
                continue;
            }

            $categoryMeta = infer_pdca_issue_category($candidate['issue_detail']);

            $stmtInsert->execute([
                ':verification_id' => $verificationId,
                ':academic_year' => $record['year'],
                ':semester' => $record['semester'],
                ':issue_category' => $categoryMeta['issue_category'],
                ':category_confidence' => $categoryMeta['category_confidence'],
                ':category_reason' => $categoryMeta['category_reason'],
                ':category_inferred_by' => $categoryMeta['category_inferred_by'],
                ':issue_title' => $candidate['issue_title'],
                ':issue_detail' => $candidate['issue_detail']
            ]);
            $createdCount++;
        }

        echo json_encode(['success' => true, 'created_count' => $createdCount]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
