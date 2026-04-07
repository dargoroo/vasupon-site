<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

function probe_log($title, $ok, $detail = '') {
    echo ($ok ? "[OK] " : "[FAIL] ") . $title . PHP_EOL;
    if ($detail !== '') {
        echo "       " . $detail . PHP_EOL;
    }
}

function probe_table_columns($pdo, $table) {
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['Field'])) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

echo "verification_board_probe.php" . PHP_EOL;
echo "Time: " . date('c') . PHP_EOL;
echo "PHP: " . PHP_VERSION . PHP_EOL . PHP_EOL;

try {
    require_once dirname(__DIR__) . '/bootstrap.php';
    probe_log('bootstrap.php loaded', true);
} catch (Throwable $e) {
    probe_log('bootstrap.php loaded', false, $e->getMessage());
    exit;
}

try {
    $pdo = app_pdo();
    probe_log('app_pdo()', true);
} catch (Throwable $e) {
    probe_log('app_pdo()', false, $e->getMessage());
    exit;
}

$tables_to_check = [
    'aunqa_verification_records',
    'aunqa_verification_checklists',
    'aunqa_verification_bloom',
    'aunqa_verification_plo_coverage',
    'aunqa_verification_activities',
    'aunqa_clo_evaluations',
    'aunqa_pdca_issues',
    'aunqa_pdca_actions',
    'aunqa_pdca_links',
    'aunqa_settings'
];

foreach ($tables_to_check as $table) {
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        probe_log("table exists: {$table}", true);
    } catch (Throwable $e) {
        probe_log("table exists: {$table}", false, $e->getMessage());
    }
}

echo PHP_EOL . "-- Column checks --" . PHP_EOL;

$column_expectations = [
    'aunqa_verification_records' => ['id', 'year', 'semester', 'course_code', 'course_name', 'instructor', 'tqf3_link', 'tqf5_link', 'verification_status', 'seed_source'],
    'aunqa_verification_checklists' => ['verification_id', 'check_clo_verb', 'check_clo_plo_map', 'check_class_activity', 'reviewer_strength', 'reviewer_improvement', 'pdca_followup', 'pdca_status', 'pdca_resolution_percent', 'pdca_last_year_summary', 'pdca_current_action', 'pdca_evidence_note'],
    'aunqa_pdca_issues' => ['verification_id', 'issue_category', 'category_confidence', 'category_reason', 'category_inferred_by', 'current_status', 'resolution_percent']
];

foreach ($column_expectations as $table => $expected_columns) {
    try {
        $actual_columns = probe_table_columns($pdo, $table);
        $missing = array_values(array_diff($expected_columns, $actual_columns));
        probe_log("columns in {$table}", empty($missing), empty($missing) ? 'ครบ' : 'ขาด: ' . implode(', ', $missing));
    } catch (Throwable $e) {
        probe_log("columns in {$table}", false, $e->getMessage());
    }
}

echo PHP_EOL . "-- Query checks --" . PHP_EOL;

try {
    $stmtYears = $pdo->query("SELECT DISTINCT year FROM aunqa_verification_records ORDER BY year DESC");
    $years = $stmtYears->fetchAll(PDO::FETCH_COLUMN);
    probe_log('fetch available years', true, empty($years) ? 'ไม่มีข้อมูลปี' : implode(', ', $years));
} catch (Throwable $e) {
    probe_log('fetch available years', false, $e->getMessage());
}

try {
    $recordColumns = probe_table_columns($pdo, 'aunqa_verification_records');
    $checklistColumns = probe_table_columns($pdo, 'aunqa_verification_checklists');

    $recordSelects = ['r.*'];
    $checklistSelects = [
        'c.check_clo_verb',
        'c.check_clo_plo_map',
        'c.check_class_activity',
        'c.reviewer_strength',
        'c.reviewer_improvement'
    ];

    $optionalChecklistColumns = [
        'pdca_followup',
        'pdca_status',
        'pdca_resolution_percent',
        'pdca_last_year_summary',
        'pdca_current_action',
        'pdca_evidence_note'
    ];

    foreach ($optionalChecklistColumns as $column) {
        if (in_array($column, $checklistColumns, true)) {
            $checklistSelects[] = "c.`{$column}`";
        } else {
            $checklistSelects[] = "NULL AS `{$column}`";
        }
    }

    if (!in_array('seed_source', $recordColumns, true)) {
        $recordSelects[] = "'' AS seed_source";
    }

    $selectSql = implode(', ', array_merge($recordSelects, $checklistSelects));
    $stmt = $pdo->query("
        SELECT {$selectSql}
        FROM aunqa_verification_records r
        LEFT JOIN aunqa_verification_checklists c ON r.id = c.verification_id
        ORDER BY r.year DESC, r.semester ASC
        LIMIT 5
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    probe_log('main board query', true, 'rows=' . count($rows));
} catch (Throwable $e) {
    probe_log('main board query', false, $e->getMessage());
}

try {
    $stmt = $pdo->prepare("DELETE FROM aunqa_verification_records WHERE id = :vid");
    $stmt->execute([':vid' => -999999]);
    probe_log('delete statement prepare/execute', true, 'rowCount=' . $stmt->rowCount());
} catch (Throwable $e) {
    probe_log('delete statement prepare/execute', false, $e->getMessage());
}

echo PHP_EOL . "Done" . PHP_EOL;
