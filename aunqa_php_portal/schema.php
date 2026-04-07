<?php

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/seeds.php';

function aunqa_schema_create_statements(): array
{
    return [
        "
        CREATE TABLE IF NOT EXISTS `aunqa_settings` (
            `setting_key` VARCHAR(50) PRIMARY KEY,
            `setting_value` TEXT NOT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "
        CREATE TABLE IF NOT EXISTS `aunqa_verification_clo_details` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `verification_id` INT NOT NULL,
            `clo_code` VARCHAR(20) NOT NULL,
            `clo_text` TEXT NOT NULL,
            `bloom_verb` VARCHAR(100),
            `bloom_level` VARCHAR(100),
            `mapped_plos` VARCHAR(255),
            `activities` TEXT,
            FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "
        CREATE TABLE IF NOT EXISTS `aunqa_verification_matrix` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `verification_id` INT NOT NULL,
            `clo_code` VARCHAR(20) NOT NULL,
            `plo_code` VARCHAR(20) NOT NULL,
            `weight_percentage` DECIMAL(5,2) DEFAULT 0.00,
            FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "
        CREATE TABLE IF NOT EXISTS `aunqa_clo_evaluations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `verification_id` INT NOT NULL,
            `clo_code` VARCHAR(20) NOT NULL,
            `clo_description` TEXT,
            `target_percent` VARCHAR(50),
            `actual_percent` VARCHAR(50),
            `problem_found` TEXT,
            `improvement_plan` TEXT,
            `committee_status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
            `committee_comment` TEXT,
            FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "
        CREATE TABLE IF NOT EXISTS `aunqa_pdca_issues` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `verification_id` INT NOT NULL,
            `previous_issue_id` INT NULL,
            `academic_year` VARCHAR(10) NOT NULL,
            `semester` VARCHAR(2) NOT NULL,
            `issue_category` ENUM('bloom','plo','activity','clo_result','document','assessment','other') DEFAULT 'other',
            `category_confidence` DECIMAL(5,2) DEFAULT 100.00,
            `category_reason` VARCHAR(255) DEFAULT '',
            `category_inferred_by` ENUM('manual','rule_based','ai') DEFAULT 'manual',
            `issue_title` VARCHAR(255) NOT NULL,
            `issue_detail` TEXT NULL,
            `severity_level` ENUM('low','medium','high') DEFAULT 'medium',
            `source_type` ENUM('ai','committee','mixed') DEFAULT 'mixed',
            `source_reference` VARCHAR(255) DEFAULT '',
            `is_recurring` TINYINT(1) DEFAULT 0,
            `current_status` ENUM('open','in_progress','partially_resolved','resolved','carried_forward') DEFAULT 'open',
            `resolution_percent` DECIMAL(5,2) DEFAULT 0.00,
            `committee_note` TEXT NULL,
            `next_round_action` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_pdca_issue_verification` (`verification_id`),
            KEY `idx_pdca_issue_year_sem` (`academic_year`, `semester`),
            KEY `idx_pdca_issue_category` (`issue_category`),
            KEY `idx_pdca_issue_status` (`current_status`),
            CONSTRAINT `fk_pdca_issue_verification_shared`
                FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pdca_issue_previous_shared`
                FOREIGN KEY (`previous_issue_id`) REFERENCES `aunqa_pdca_issues`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "
        CREATE TABLE IF NOT EXISTS `aunqa_pdca_actions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `pdca_issue_id` INT NOT NULL,
            `plan_text` TEXT NULL,
            `do_text` TEXT NULL,
            `check_text` TEXT NULL,
            `act_text` TEXT NULL,
            `owner_name` VARCHAR(255) DEFAULT '',
            `target_academic_year` VARCHAR(10) DEFAULT '',
            `target_semester` VARCHAR(2) DEFAULT '',
            `current_status` ENUM('open','in_progress','partially_resolved','resolved','carried_forward') DEFAULT 'open',
            `resolution_percent` DECIMAL(5,2) DEFAULT 0.00,
            `evidence_note` TEXT NULL,
            `closed_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_pdca_action_issue` (`pdca_issue_id`),
            KEY `idx_pdca_action_target` (`target_academic_year`, `target_semester`),
            CONSTRAINT `fk_pdca_action_issue_shared`
                FOREIGN KEY (`pdca_issue_id`) REFERENCES `aunqa_pdca_issues`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "
        CREATE TABLE IF NOT EXISTS `aunqa_pdca_links` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `pdca_issue_id` INT NOT NULL,
            `verification_id` INT NOT NULL,
            `link_type` ENUM('followup_review','recurred','resolved_in_course','partial_improvement','manual_reference') DEFAULT 'followup_review',
            `committee_note` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_pdca_link_issue` (`pdca_issue_id`),
            KEY `idx_pdca_link_verification` (`verification_id`),
            CONSTRAINT `fk_pdca_link_issue_shared`
                FOREIGN KEY (`pdca_issue_id`) REFERENCES `aunqa_pdca_issues`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pdca_link_verification_shared`
                FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
    ];
}

function aunqa_ensure_schema(PDO $pdo): void
{
    cpeapp_schema_ensure(
        $pdo,
        'aunqa',
        aunqa_schema_create_statements(),
        ['aunqa_apply_legacy_schema_updates', 'aunqa_seed_default_data']
    );
}

function aunqa_bootstrap_state(): array
{
    return cpeapp_bootstrap_state(
        function () {
            return app_pdo();
        },
        'aunqa_ensure_schema'
    );
}
