<?php
require_once '../config.php';
// Fallback กรณีไม่มี config.php
$db_host = isset($DB_HOST) ? $DB_HOST : 'localhost';
$db_name = isset($DB_NAME) ? $DB_NAME : 'vasupon_p';
$db_user = isset($DB_USER) ? $DB_USER : 'root';
$db_pass = isset($DB_PASS) ? $DB_PASS : '';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_clo_feedback') {
    $clo_id = $_POST['clo_id'];
    $field = $_POST['field']; // 'status' or 'comment'
    $value = $_POST['value'];

    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
