<?php

function graderapp_apply_legacy_migrations(PDO $pdo): void
{
    $alterStatements = [];

    if (graderapp_table_exists($pdo, 'grader_jobs') && !graderapp_column_exists($pdo, 'grader_jobs', 'priority')) {
        $alterStatements[] = "ALTER TABLE grader_jobs ADD COLUMN priority INT NOT NULL DEFAULT 100 AFTER attempt_count";
    }

    if (graderapp_table_exists($pdo, 'grader_users') && !graderapp_column_exists($pdo, 'grader_users', 'portal_user_id')) {
        $alterStatements[] = "ALTER TABLE grader_users ADD COLUMN portal_user_id BIGINT NULL AFTER id";
    }

    cpeapp_schema_apply($pdo, $alterStatements);
}
