<?php

function aunqa_schema_legacy_statements(): array
{
    return [
        "ALTER TABLE aunqa_verification_records ADD COLUMN tqf3_link VARCHAR(500) DEFAULT ''",
        "ALTER TABLE aunqa_verification_records ADD COLUMN tqf5_link VARCHAR(500) DEFAULT ''",
        "ALTER TABLE aunqa_verification_records ADD COLUMN seed_batch_token VARCHAR(64) NULL",
        "ALTER TABLE aunqa_verification_records ADD COLUMN seed_source VARCHAR(50) DEFAULT ''",
        "ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_followup TEXT",
        "ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_status ENUM('not_started','in_progress','partially_resolved','resolved','carried_forward') DEFAULT 'not_started'",
        "ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_resolution_percent DECIMAL(5,2) DEFAULT 0.00",
        "ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_last_year_summary TEXT NULL",
        "ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_current_action TEXT NULL",
        "ALTER TABLE aunqa_verification_checklists ADD COLUMN pdca_evidence_note TEXT NULL",
        "ALTER TABLE aunqa_pdca_issues ADD COLUMN category_confidence DECIMAL(5,2) DEFAULT 100.00",
        "ALTER TABLE aunqa_pdca_issues ADD COLUMN category_reason VARCHAR(255) DEFAULT ''",
        "ALTER TABLE aunqa_pdca_issues ADD COLUMN category_inferred_by ENUM('manual','rule_based','ai') DEFAULT 'manual'",
        "ALTER TABLE aunqa_pdca_issues ADD COLUMN committee_note TEXT NULL",
        "ALTER TABLE aunqa_pdca_issues ADD COLUMN next_round_action TEXT NULL",
        "ALTER TABLE aunqa_verification_activities MODIFY activity_name TEXT NOT NULL",
        "ALTER TABLE aunqa_verification_activities MODIFY target_clo TEXT NULL",
    ];
}

function aunqa_apply_legacy_schema_updates(PDO $pdo): void
{
    foreach (aunqa_schema_legacy_statements() as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Ignore legacy migration errors for already-upgraded databases.
        }
    }
}
