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
$runnerTarget = trim((string) ($input['runner_target'] ?? graderapp_setting_get($pdo, 'grader_runner_target_default', 'rbruai2')));

if ($workerName === '') {
    graderapp_json_response([
        'ok' => false,
        'error' => 'Missing worker_name',
    ], 400);
}

$pdo->beginTransaction();

try {
    $jobStmt = $pdo->prepare("
        SELECT j.id, j.submission_id, j.runner_target
        FROM grader_jobs j
        WHERE j.job_status = 'queued'
          AND (j.runner_target = :runner_target OR j.runner_target = 'default')
        ORDER BY j.priority ASC, j.queued_at ASC, j.id ASC
        LIMIT 1
        FOR UPDATE
    ");
    $jobStmt->execute([':runner_target' => $runnerTarget]);
    $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        $pdo->commit();
        graderapp_json_response([
            'ok' => true,
            'job' => null,
            'message' => 'No queued jobs available',
        ]);
    }

    $claimToken = bin2hex(random_bytes(16));
    $updateStmt = $pdo->prepare("
        UPDATE grader_jobs
        SET job_status = 'claimed',
            claimed_by_worker = :worker_name,
            claim_token = :claim_token,
            claimed_at = NOW(),
            attempt_count = attempt_count + 1
        WHERE id = :id
          AND job_status = 'queued'
    ");
    $updateStmt->execute([
        ':worker_name' => $workerName,
        ':claim_token' => $claimToken,
        ':id' => (int) $job['id'],
    ]);

    if ($updateStmt->rowCount() !== 1) {
        throw new RuntimeException('Failed to claim job');
    }

    $submissionStmt = $pdo->prepare("
        SELECT s.*, p.title AS problem_title, p.description_md, p.starter_code, p.time_limit_sec, p.memory_limit_mb,
               p.max_score, p.language AS problem_language, p.visibility
        FROM grader_submissions s
        INNER JOIN grader_problems p ON p.id = s.problem_id
        WHERE s.id = :submission_id
        LIMIT 1
    ");
    $submissionStmt->execute([':submission_id' => (int) $job['submission_id']]);
    $submission = $submissionStmt->fetch(PDO::FETCH_ASSOC);

    $caseStmt = $pdo->prepare("
        SELECT id, case_type, stdin_text, expected_stdout, score_weight, sort_order
        FROM grader_test_cases
        WHERE problem_id = :problem_id
        ORDER BY sort_order ASC, id ASC
    ");
    $caseStmt->execute([':problem_id' => (int) $submission['problem_id']]);
    $testCases = $caseStmt->fetchAll(PDO::FETCH_ASSOC);

    $statusStmt = $pdo->prepare("UPDATE grader_submissions SET status = 'running' WHERE id = :id");
    $statusStmt->execute([':id' => (int) $submission['id']]);

    $pdo->commit();

    graderapp_json_response([
        'ok' => true,
        'job' => [
            'id' => (int) $job['id'],
            'submission_id' => (int) $job['submission_id'],
            'claim_token' => $claimToken,
            'runner_target' => (string) $job['runner_target'],
        ],
        'submission' => $submission,
        'test_cases' => $testCases,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    graderapp_json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
