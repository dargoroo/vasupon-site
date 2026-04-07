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

$submissionId = isset($_GET['submission_id']) ? (int) $_GET['submission_id'] : 0;
if ($submissionId <= 0) {
    graderapp_json_response([
        'ok' => false,
        'error' => 'Missing submission_id',
    ], 400);
}

$stmt = $pdo->prepare("
    SELECT s.*, p.title AS problem_title, u.full_name AS user_name
    FROM grader_submissions s
    LEFT JOIN grader_problems p ON p.id = s.problem_id
    LEFT JOIN grader_users u ON u.id = s.user_id
    WHERE s.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $submissionId]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    graderapp_json_response([
        'ok' => false,
        'error' => 'Submission not found',
    ], 404);
}

$resultStmt = $pdo->prepare("
    SELECT r.*, tc.case_type, tc.sort_order
    FROM grader_submission_results r
    LEFT JOIN grader_test_cases tc ON tc.id = r.test_case_id
    WHERE r.submission_id = :submission_id
    ORDER BY tc.sort_order ASC, r.id ASC
");
$resultStmt->execute([':submission_id' => $submissionId]);
$results = $resultStmt->fetchAll(PDO::FETCH_ASSOC);

$jobStmt = $pdo->prepare("
    SELECT id, job_status, runner_target, claimed_by_worker, queued_at, claimed_at, finished_at, last_error
    FROM grader_jobs
    WHERE submission_id = :submission_id
    ORDER BY id DESC
    LIMIT 1
");
$jobStmt->execute([':submission_id' => $submissionId]);
$job = $jobStmt->fetch(PDO::FETCH_ASSOC) ?: null;

graderapp_json_response([
    'ok' => true,
    'submission' => $submission,
    'job' => $job,
    'results' => $results,
]);
