<?php

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$state = officefb_bootstrap_state();
if (!$state['ok']) {
    echo json_encode(['success' => false, 'error' => $state['error']]);
    exit;
}

$pdo = $state['pdo'];

if (!officefb_table_exists($pdo, 'officefb_staff') || !officefb_table_exists($pdo, 'officefb_ratings')) {
    echo json_encode([
        'success' => false,
        'error' => 'ยังไม่พบตาราง officefb_* กรุณา import office_feedback/db_schema.sql ก่อน'
    ]);
    exit;
}

$staff_id = isset($_POST['staff_id']) ? (int) $_POST['staff_id'] : 0;
$rating_score = isset($_POST['rating_score']) ? (int) $_POST['rating_score'] : 0;
$service_topic = isset($_POST['service_topic']) ? trim((string) $_POST['service_topic']) : '';
$comment_text = isset($_POST['comment_text']) ? trim((string) $_POST['comment_text']) : '';
$device_token = isset($_POST['device_token']) ? trim((string) $_POST['device_token']) : '';
$device_name = isset($_POST['device_name']) ? trim((string) $_POST['device_name']) : '';

if ($staff_id <= 0 || $rating_score < 1 || $rating_score > 4) {
    echo json_encode(['success' => false, 'error' => 'ข้อมูลการประเมินไม่ครบ']);
    exit;
}

if ($device_token === '') {
    $device_token = 'web-' . substr(sha1(uniqid('', true)), 0, 16);
}

try {
    $stmtStaff = $pdo->prepare("SELECT id FROM officefb_staff WHERE id = :id AND is_active = 1");
    $stmtStaff->execute([':id' => $staff_id]);
    if (!$stmtStaff->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบเจ้าหน้าที่ที่เลือก']);
        exit;
    }

    $stmtDevice = null;
    $device_id = null;
    if (officefb_table_exists($pdo, 'officefb_devices')) {
        $stmtDevice = $pdo->prepare("
            INSERT INTO officefb_devices (device_code, device_name, location_name, is_active, last_seen_at)
            VALUES (:device_code, :device_name, 'Kiosk', 1, NOW())
            ON DUPLICATE KEY UPDATE
                device_name = VALUES(device_name),
                last_seen_at = NOW()
        ");
        $stmtDevice->execute([
            ':device_code' => $device_token,
            ':device_name' => $device_name !== '' ? $device_name : 'Tablet Kiosk'
        ]);

        $stmtGetDevice = $pdo->prepare("SELECT id FROM officefb_devices WHERE device_code = :device_code LIMIT 1");
        $stmtGetDevice->execute([':device_code' => $device_token]);
        $device_id = $stmtGetDevice->fetchColumn() ?: null;
    }

    $stmtCooldown = $pdo->prepare("
        SELECT submitted_at
        FROM officefb_ratings
        WHERE device_token = :device_token
        ORDER BY submitted_at DESC
        LIMIT 1
    ");
    $stmtCooldown->execute([':device_token' => $device_token]);
    $last_submitted_at = $stmtCooldown->fetchColumn();

    if ($last_submitted_at) {
        $elapsed = time() - strtotime($last_submitted_at);
        if ($elapsed < 5) {
            echo json_encode([
                'success' => false,
                'error' => 'อุปกรณ์นี้เพิ่งส่งแบบประเมินไป กรุณารอสักครู่ก่อนกดใหม่',
                'cooldown_remaining' => 5 - $elapsed
            ]);
            exit;
        }
    }

    $meta = officefb_rating_meta($rating_score);
    $period = officefb_academic_period();
    $submitted_date = date('Y-m-d');
    $submitted_hour = (int) date('G');

    $stmtInsert = $pdo->prepare("
        INSERT INTO officefb_ratings (
            staff_id, device_id, device_token, rating_score, rating_label,
            service_topic, comment_text, submitted_at, submitted_date, submitted_hour,
            academic_year, semester
        ) VALUES (
            :staff_id, :device_id, :device_token, :rating_score, :rating_label,
            :service_topic, :comment_text, NOW(), :submitted_date, :submitted_hour,
            :academic_year, :semester
        )
    ");
    $stmtInsert->execute([
        ':staff_id' => $staff_id,
        ':device_id' => $device_id,
        ':device_token' => $device_token,
        ':rating_score' => $rating_score,
        ':rating_label' => $meta['label'],
        ':service_topic' => $service_topic,
        ':comment_text' => $comment_text,
        ':submitted_date' => $submitted_date,
        ':submitted_hour' => $submitted_hour,
        ':academic_year' => $period['academic_year'],
        ':semester' => $period['semester']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'บันทึกแบบประเมินเรียบร้อยแล้ว',
        'meta' => $meta
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
