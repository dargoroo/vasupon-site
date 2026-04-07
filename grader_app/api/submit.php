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

$input = array_merge($_POST, graderapp_json_input());

try {
    $problemId = (int) ($input['problem_id'] ?? 0);
    $userId = (int) ($input['user_id'] ?? 0);
    $courseId = isset($input['course_id']) && $input['course_id'] !== '' ? (int) $input['course_id'] : null;
    $language = trim((string) ($input['language'] ?? graderapp_setting_get($pdo, 'grader_default_language', 'python')));
    $sourceCode = (string) ($input['source_code'] ?? '');

    if ($problemId <= 0 || $userId <= 0 || trim($sourceCode) === '') {
        throw new RuntimeException('Missing required submit payload');
    }

    $totalCasesStmt = $pdo->prepare("SELECT COUNT(*) FROM grader_test_cases WHERE problem_id = :problem_id");
    $totalCasesStmt->execute([':problem_id' => $problemId]);
    $totalCases = (int) $totalCasesStmt->fetchColumn();

    $pdo->beginTransaction();

    $submissionStmt = $pdo->prepare("
        INSERT INTO grader_submissions (problem_id, user_id, course_id, language, source_code, status, total_cases)
        VALUES (:problem_id, :user_id, :course_id, :language, :source_code, 'queued', :total_cases)
    ");
    $submissionStmt->execute([
        ':problem_id' => $problemId,
        ':user_id' => $userId,
        ':course_id' => $courseId,
        ':language' => $language,
        ':source_code' => $sourceCode,
        ':total_cases' => $totalCases,
    ]);
    $submissionId = (int) $pdo->lastInsertId();

    $jobStmt = $pdo->prepare("
        INSERT INTO grader_jobs (submission_id, job_status, runner_target, priority)
        VALUES (:submission_id, 'queued', :runner_target, :priority)
    ");
    $jobStmt->execute([
        ':submission_id' => $submissionId,
        ':runner_target' => graderapp_setting_get($pdo, 'grader_runner_target_default', graderapp_config('GRADERAPP_RUNNER_TARGET_DEFAULT', 'rbruai2')),
        ':priority' => 100,
    ]);
    $jobId = (int) $pdo->lastInsertId();

    $pdo->commit();

    graderapp_json_response([
        'ok' => true,
        'submission_id' => $submissionId,
        'job_id' => $jobId,
        'status' => 'queued',
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
