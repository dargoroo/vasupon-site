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

    if (graderapp_table_exists($pdo, 'grader_courses') && !graderapp_column_exists($pdo, 'grader_courses', 'theme_color')) {
        $alterStatements[] = "ALTER TABLE grader_courses ADD COLUMN theme_color VARCHAR(7) NOT NULL DEFAULT '#185b86' AFTER join_code";
    }

    cpeapp_schema_apply($pdo, $alterStatements);
}
