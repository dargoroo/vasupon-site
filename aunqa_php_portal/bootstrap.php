<?php

require_once dirname(__DIR__) . '/shared/config_helpers.php';
require_once dirname(__DIR__) . '/shared/schema_helpers.php';

function app_load_root_config() {
    cpeapp_load_root_config();
}

function app_config($key, $default = null) {
    return cpeapp_config((string) $key, $default);
}

function app_required_config($key) {
    return cpeapp_required_config((string) $key);
}

function app_pdo() {
    return cpeapp_pdo_from_root_config();
}

function app_table_exists(PDO $pdo, string $table_name, string $scope = 'app'): bool
{
    return cpeapp_schema_table_exists($pdo, $table_name, $scope);
}

function app_column_exists(PDO $pdo, string $table_name, string $column_name, string $scope = 'app'): bool
{
    return cpeapp_schema_column_exists($pdo, $table_name, $column_name, $scope);
}

function app_schema_reset_cache(?string $scope = null): void
{
    cpeapp_schema_reset_cache($scope);
}

function app_bootstrap_state(?callable $ensure_callback = null): array
{
    return cpeapp_bootstrap_state(
        function () {
            return app_pdo();
        },
        $ensure_callback
    );
}

require_once __DIR__ . '/schema.php';
