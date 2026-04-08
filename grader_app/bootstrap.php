<?php

require_once dirname(__DIR__) . '/shared/config_helpers.php';
require_once dirname(__DIR__) . '/shared/schema_helpers.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/seeds.php';

function graderapp_config(string $key, $default = null)
{
    return cpeapp_config($key, $default);
}

function graderapp_required_config(string $key)
{
    return cpeapp_required_config($key);
}

function graderapp_pdo(): PDO
{
    return cpeapp_pdo_from_root_config();
}

function graderapp_base_path(string $segment = 'root'): string
{
    $defaults = [
        'root' => '/grader_app',
        'admin' => '/grader_app/admin',
        'api' => '/grader_app/api',
    ];

    $configKeys = [
        'root' => 'GRADERAPP_PATH_ROOT',
        'admin' => 'GRADERAPP_PATH_ADMIN',
        'api' => 'GRADERAPP_PATH_API',
    ];

    $configured = graderapp_config($configKeys[$segment] ?? '', $defaults[$segment] ?? '/grader_app');
    $path = '/' . trim((string) $configured, '/');

    return $path === '/' ? ($defaults[$segment] ?? '/grader_app') : $path;
}

function graderapp_path(string $route, array $params = []): string
{
    $root = graderapp_base_path('root');
    $admin = graderapp_base_path('admin');
    $api = graderapp_base_path('api');

    $routes = [
        'grader.home' => $root . '/index.php',
        'grader.dashboard' => $root . '/dashboard.php',
        'grader.classroom' => $root . '/classroom.php',
        'grader.problem' => $root . '/problem.php',
        'grader.admin' => $admin . '/index.php',
        'grader.admin.courses' => $admin . '/courses.php',
        'grader.admin.modules' => $admin . '/modules.php',
        'grader.admin.problems' => $admin . '/problems.php',
        'grader.admin.logout' => $admin . '/index.php?action=logout',
        'grader.api.root' => $api,
        'grader.api.submit' => $api . '/submit.php',
        'grader.api.status' => $api . '/status.php',
        'grader.api.worker.claim' => $api . '/worker_claim.php',
        'grader.api.worker.report' => $api . '/worker_report.php',
        'grader.api.worker.heartbeat' => $api . '/worker_heartbeat.php',
    ];

    $path = $routes[$route] ?? ($root . '/index.php');
    if (!$params) {
        return $path;
    }

    $query = http_build_query($params);
    return $query === '' ? $path : $path . (strpos($path, '?') !== false ? '&' : '?') . $query;
}

function graderapp_table_exists(PDO $pdo, string $table): bool
{
    return cpeapp_schema_table_exists($pdo, $table, 'grader_app');
}

function graderapp_column_exists(PDO $pdo, string $table, string $column): bool
{
    return cpeapp_schema_column_exists($pdo, $table, $column, 'grader_app');
}

function graderapp_setting_get(PDO $pdo, string $key, $default = null)
{
    if (!graderapp_table_exists($pdo, 'grader_settings')) {
        return $default;
    }

    $stmt = $pdo->prepare("
        SELECT setting_value
        FROM grader_settings
        WHERE setting_key = :setting_key
          AND scope_type = 'system'
          AND scope_id = 0
        LIMIT 1
    ");
    $stmt->execute([':setting_key' => $key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? $value : $default;
}

function graderapp_setting_set(PDO $pdo, string $key, string $value): bool
{
    if (!graderapp_table_exists($pdo, 'grader_settings')) {
        return false;
    }

    $stmt = $pdo->prepare("
        INSERT INTO grader_settings (setting_key, setting_value, scope_type, scope_id)
        VALUES (:setting_key, :setting_value, 'system', 0)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    return $stmt->execute([
        ':setting_key' => $key,
        ':setting_value' => $value,
    ]);
}

function graderapp_stale_job_seconds(PDO $pdo): int
{
    $configured = (int) graderapp_setting_get($pdo, 'grader_stale_job_seconds', '90');
    return max(15, $configured);
}

function graderapp_count_stale_jobs(PDO $pdo): int
{
    if (!graderapp_table_exists($pdo, 'grader_jobs')) {
        return 0;
    }

    $seconds = graderapp_stale_job_seconds($pdo);
    $sql = "
        SELECT COUNT(*)
        FROM grader_jobs
        WHERE job_status IN ('claimed', 'running')
          AND claimed_at IS NOT NULL
          AND claimed_at < DATE_SUB(NOW(), INTERVAL {$seconds} SECOND)
    ";

    return (int) $pdo->query($sql)->fetchColumn();
}

function graderapp_requeue_stale_jobs(PDO $pdo): int
{
    if (!graderapp_table_exists($pdo, 'grader_jobs') || !graderapp_table_exists($pdo, 'grader_submissions')) {
        return 0;
    }

    $seconds = graderapp_stale_job_seconds($pdo);
    $condition = "
        j.job_status IN ('claimed', 'running')
        AND j.claimed_at IS NOT NULL
        AND j.claimed_at < DATE_SUB(NOW(), INTERVAL {$seconds} SECOND)
    ";

    $pdo->exec("
        UPDATE grader_submissions s
        INNER JOIN grader_jobs j ON j.submission_id = s.id
        SET s.status = 'queued',
            s.graded_at = NULL
        WHERE {$condition}
    ");

    $message = sprintf('Requeued after stale worker claim timeout (%d seconds)', $seconds);
    $stmt = $pdo->prepare("
        UPDATE grader_jobs j
        SET j.job_status = 'queued',
            j.claimed_by_worker = '',
            j.claim_token = NULL,
            j.claimed_at = NULL,
            j.finished_at = NULL,
            j.last_error = :last_error
        WHERE {$condition}
    ");
    $stmt->execute([
        ':last_error' => $message,
    ]);

    return (int) $stmt->rowCount();
}

function graderapp_bootstrap_state(): array
{
    return cpeapp_bootstrap_state(
        static function (): PDO {
            return graderapp_pdo();
        },
        'graderapp_ensure_schema'
    );
}

function graderapp_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'problem-' . time();
}

function graderapp_generate_join_code(int $length = 8): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $maxIndex = strlen($alphabet) - 1;
    $length = max(6, $length);
    $code = '';

    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $maxIndex)];
    }

    return $code;
}

function graderapp_normalize_theme_color(string $color, string $default = '#185b86'): string
{
    $color = strtoupper(trim($color));
    if ($color === '') {
        return $default;
    }

    if (preg_match('/^#?[0-9A-F]{6}$/', $color) !== 1) {
        return $default;
    }

    return '#' . ltrim($color, '#');
}

function graderapp_json_input(): array
{
    static $cached = null;

    if (is_array($cached)) {
        return $cached;
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        $cached = [];
        return $cached;
    }

    $decoded = json_decode($raw, true);
    $cached = is_array($decoded) ? $decoded : [];

    return $cached;
}

function graderapp_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function graderapp_demo_context(PDO $pdo, int $problemId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            p.id AS problem_id,
            p.title,
            p.description_md,
            p.starter_code,
            p.language,
            p.time_limit_sec,
            p.memory_limit_mb,
            p.max_score,
            p.visibility,
            m.id AS module_id,
            m.title AS module_title,
            c.id AS course_id,
            c.course_code,
            c.course_name,
            c.academic_year,
            c.semester
        FROM grader_problems p
        INNER JOIN grader_modules m ON m.id = p.module_id
        INNER JOIN grader_courses c ON c.id = m.course_id
        WHERE p.id = :problem_id
          AND p.visibility = 'published'
        LIMIT 1
    ");
    $stmt->execute([':problem_id' => $problemId]);
    $problem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$problem) {
        return null;
    }

    $userStmt = $pdo->prepare("
        SELECT u.id, u.full_name
        FROM grader_course_enrollments e
        INNER JOIN grader_users u ON u.id = e.user_id
        WHERE e.course_id = :course_id
          AND e.role_in_course = 'student'
          AND u.is_active = 1
        ORDER BY e.id ASC
        LIMIT 1
    ");
    $userStmt->execute([':course_id' => (int) $problem['course_id']]);
    $demoUser = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$demoUser) {
        $fallbackStmt = $pdo->query("
            SELECT id, full_name
            FROM grader_users
            WHERE role = 'student'
              AND is_active = 1
            ORDER BY id ASC
            LIMIT 1
        ");
        $demoUser = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$demoUser) {
        return null;
    }

    $sampleStmt = $pdo->prepare("
        SELECT id, stdin_text, expected_stdout, score_weight, sort_order
        FROM grader_test_cases
        WHERE problem_id = :problem_id
          AND case_type = 'sample'
        ORDER BY sort_order ASC, id ASC
        LIMIT 5
    ");
    $sampleStmt->execute([':problem_id' => $problemId]);
    $sampleCases = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'problem' => $problem,
        'demo_user' => $demoUser,
        'sample_cases' => $sampleCases,
    ];
}

function graderapp_worker_token(): string
{
    return (string) graderapp_config('GRADERAPP_WORKER_SHARED_TOKEN', 'grader-worker-token');
}

function graderapp_require_worker_token(): void
{
    $token = '';
    if (isset($_SERVER['HTTP_X_WORKER_TOKEN'])) {
        $token = (string) $_SERVER['HTTP_X_WORKER_TOKEN'];
    } elseif (isset($_POST['worker_token'])) {
        $token = (string) $_POST['worker_token'];
    } else {
        $json = graderapp_json_input();
        if (isset($json['worker_token'])) {
            $token = (string) $json['worker_token'];
        }
    }

    if ($token === '' || !hash_equals(graderapp_worker_token(), $token)) {
        graderapp_json_response([
            'ok' => false,
            'error' => 'Unauthorized worker token',
        ], 401);
    }
}
