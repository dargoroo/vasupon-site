<?php

require_once dirname(__DIR__) . '/bootstrap.php';

$state = graderapp_bootstrap_state();
$pdo = $state['pdo'];

if (!$state['ok'] || !$pdo) {
    graderapp_json_response([
        'ok' => false,
        'error' => $state['error'] ?: 'Database unavailable',
    ], 500);
}

graderapp_require_worker_token();

$input = array_merge($_POST, graderapp_json_input());
$workerName = trim((string) ($input['worker_name'] ?? ''));
$workerHost = trim((string) ($input['worker_host'] ?? ''));
$capabilities = $input['capabilities'] ?? [];

if ($workerName === '') {
    graderapp_json_response([
        'ok' => false,
        'error' => 'Missing worker_name',
    ], 400);
}

$stmt = $pdo->prepare("
    INSERT INTO grader_workers (worker_name, worker_host, worker_token_hash, is_active, last_seen_at, capabilities_json)
    VALUES (:worker_name, :worker_host, :worker_token_hash, 1, NOW(), :capabilities_json)
    ON DUPLICATE KEY UPDATE
        worker_host = VALUES(worker_host),
        worker_token_hash = VALUES(worker_token_hash),
        is_active = 1,
        last_seen_at = NOW(),
        capabilities_json = VALUES(capabilities_json)
");
$stmt->execute([
    ':worker_name' => $workerName,
    ':worker_host' => $workerHost,
    ':worker_token_hash' => password_hash(graderapp_worker_token(), PASSWORD_DEFAULT),
    ':capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

graderapp_json_response([
    'ok' => true,
    'worker_name' => $workerName,
    'last_seen_at' => date('c'),
]);
