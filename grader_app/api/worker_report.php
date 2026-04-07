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

try {
    $jobId = (int) ($input['job_id'] ?? 0);
    $submissionId = (int) ($input['submission_id'] ?? 0);
    $claimToken = trim((string) ($input['claim_token'] ?? ''));
    $jobStatus = trim((string) ($input['job_status'] ?? 'done'));
    $submissionStatus = trim((string) ($input['submission_status'] ?? 'completed'));
    $score = (int) ($input['score'] ?? 0);
    $passedCases = (int) ($input['passed_cases'] ?? 0);
    $totalCases = (int) ($input['total_cases'] ?? 0);
    $lastError = trim((string) ($input['last_error'] ?? ''));
    $results = isset($input['results']) && is_array($input['results']) ? $input['results'] : [];

    if ($jobId <= 0 || $submissionId <= 0 || $claimToken === '') {
        throw new RuntimeException('Missing report payload');
    }

    $pdo->beginTransaction();

    $jobStmt = $pdo->prepare("
        SELECT id
        FROM grader_jobs
        WHERE id = :id
          AND submission_id = :submission_id
          AND claim_token = :claim_token
        LIMIT 1
        FOR UPDATE
    ");
    $jobStmt->execute([
        ':id' => $jobId,
        ':submission_id' => $submissionId,
        ':claim_token' => $claimToken,
    ]);
    $job = $jobStmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        throw new RuntimeException('Invalid job claim token');
    }

    $deleteStmt = $pdo->prepare("DELETE FROM grader_submission_results WHERE submission_id = :submission_id");
    $deleteStmt->execute([':submission_id' => $submissionId]);

    if ($results) {
        $insertStmt = $pdo->prepare("
            INSERT INTO grader_submission_results
                (submission_id, test_case_id, status, actual_stdout, stderr_text, execution_time_ms, memory_used_kb, score_awarded)
            VALUES
                (:submission_id, :test_case_id, :status, :actual_stdout, :stderr_text, :execution_time_ms, :memory_used_kb, :score_awarded)
        ");

        foreach ($results as $result) {
            $insertStmt->execute([
                ':submission_id' => $submissionId,
                ':test_case_id' => isset($result['test_case_id']) ? (int) $result['test_case_id'] : null,
                ':status' => (string) ($result['status'] ?? 'pending'),
                ':actual_stdout' => (string) ($result['actual_stdout'] ?? ''),
                ':stderr_text' => (string) ($result['stderr_text'] ?? ''),
                ':execution_time_ms' => (int) ($result['execution_time_ms'] ?? 0),
                ':memory_used_kb' => (int) ($result['memory_used_kb'] ?? 0),
                ':score_awarded' => (int) ($result['score_awarded'] ?? 0),
            ]);
        }
    }

    $submissionStmt = $pdo->prepare("
        UPDATE grader_submissions
        SET status = :status,
            score = :score,
            passed_cases = :passed_cases,
            total_cases = :total_cases,
            graded_at = NOW()
        WHERE id = :id
    ");
    $submissionStmt->execute([
        ':status' => $submissionStatus,
        ':score' => $score,
        ':passed_cases' => $passedCases,
        ':total_cases' => $totalCases,
        ':id' => $submissionId,
    ]);

    $jobUpdate = $pdo->prepare("
        UPDATE grader_jobs
        SET job_status = :job_status,
            finished_at = NOW(),
            last_error = :last_error
        WHERE id = :id
    ");
    $jobUpdate->execute([
        ':job_status' => $jobStatus,
        ':last_error' => $lastError,
        ':id' => $jobId,
    ]);

    $pdo->commit();

    graderapp_json_response([
        'ok' => true,
        'submission_id' => $submissionId,
        'job_id' => $jobId,
        'status' => $submissionStatus,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    graderapp_json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);
}
