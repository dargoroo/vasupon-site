<?php
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_clo_feedback') {
    $clo_id = $_POST['clo_id'];
    $field = $_POST['field']; // 'status' or 'comment'
    $value = $_POST['value'];

    try {
        $pdo = app_pdo();

        if ($field === 'status') {
            $stmt = $pdo->prepare("UPDATE aunqa_clo_evaluations SET committee_status = :val WHERE id = :id");
        } else if ($field === 'comment') {
            $stmt = $pdo->prepare("UPDATE aunqa_clo_evaluations SET committee_comment = :val WHERE id = :id");
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid field.']);
            exit;
        }

        $stmt->execute([':val' => $value, ':id' => $clo_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}
?>
