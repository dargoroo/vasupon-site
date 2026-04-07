<?php

function cpeapp_schema_cache_bucket(string $scope): array
{
    if (!isset($GLOBALS['cpeapp_schema_exists_cache']) || !is_array($GLOBALS['cpeapp_schema_exists_cache'])) {
        $GLOBALS['cpeapp_schema_exists_cache'] = [];
    }

    if (!isset($GLOBALS['cpeapp_schema_exists_cache'][$scope]) || !is_array($GLOBALS['cpeapp_schema_exists_cache'][$scope])) {
        $GLOBALS['cpeapp_schema_exists_cache'][$scope] = [
            'tables' => [],
            'columns' => [],
        ];
    }

    return $GLOBALS['cpeapp_schema_exists_cache'][$scope];
}

function cpeapp_schema_reset_cache(?string $scope = null): void
{
    if ($scope === null) {
        $GLOBALS['cpeapp_schema_exists_cache'] = [];
        return;
    }

    if (!isset($GLOBALS['cpeapp_schema_exists_cache']) || !is_array($GLOBALS['cpeapp_schema_exists_cache'])) {
        $GLOBALS['cpeapp_schema_exists_cache'] = [];
    }

    unset($GLOBALS['cpeapp_schema_exists_cache'][$scope]);
}

function cpeapp_schema_table_exists(PDO $pdo, string $table_name, string $scope = 'default'): bool
{
    $bucket = cpeapp_schema_cache_bucket($scope);
    if (array_key_exists($table_name, $bucket['tables'])) {
        return (bool) $bucket['tables'][$table_name];
    }

    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $table_name]);
        $exists = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }

    $GLOBALS['cpeapp_schema_exists_cache'][$scope]['tables'][$table_name] = $exists;
    return $exists;
}

function cpeapp_schema_column_exists(PDO $pdo, string $table_name, string $column_name, string $scope = 'default'): bool
{
    $cache_key = $table_name . '.' . $column_name;
    $bucket = cpeapp_schema_cache_bucket($scope);
    if (array_key_exists($cache_key, $bucket['columns'])) {
        return (bool) $bucket['columns'][$cache_key];
    }

    if (!cpeapp_schema_table_exists($pdo, $table_name, $scope)) {
        $GLOBALS['cpeapp_schema_exists_cache'][$scope]['columns'][$cache_key] = false;
        return false;
    }

    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '``', $table_name) . '` LIKE :column_name');
        $stmt->execute([':column_name' => $column_name]);
        $exists = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }

    $GLOBALS['cpeapp_schema_exists_cache'][$scope]['columns'][$cache_key] = $exists;
    return $exists;
}

function cpeapp_schema_apply(PDO $pdo, array $statements): void
{
    foreach ($statements as $sql) {
        $sql = trim((string) $sql);
        if ($sql === '') {
            continue;
        }
        $pdo->exec($sql);
    }
}

function cpeapp_schema_ensure(PDO $pdo, string $scope, array $statements = [], array $callbacks = []): void
{
    static $ensured_scopes = [];

    if (!empty($ensured_scopes[$scope])) {
        return;
    }

    cpeapp_schema_apply($pdo, $statements);
    cpeapp_schema_reset_cache($scope);

    foreach ($callbacks as $callback) {
        if (is_callable($callback)) {
            $callback($pdo);
        }
    }

    cpeapp_schema_reset_cache($scope);
    $ensured_scopes[$scope] = true;
}

function cpeapp_bootstrap_state(callable $pdo_factory, ?callable $ensure_callback = null): array
{
    try {
        $pdo = $pdo_factory();
        if ($ensure_callback !== null) {
            $ensure_callback($pdo);
        }

        return [
            'ok' => true,
            'pdo' => $pdo,
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'pdo' => null,
            'error' => $e->getMessage(),
        ];
    }
}
