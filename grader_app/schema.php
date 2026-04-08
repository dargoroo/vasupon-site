<?php

function graderapp_schema_statements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS `grader_users` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `portal_user_id` BIGINT NULL,
            `email` VARCHAR(255) NOT NULL,
            `full_name` VARCHAR(255) NOT NULL,
            `role` VARCHAR(30) NOT NULL DEFAULT 'student',
            `student_code` VARCHAR(50) DEFAULT '',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_grader_users_email` (`email`),
            KEY `idx_grader_users_portal_user_id` (`portal_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `grader_courses` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `course_code` VARCHAR(50) NOT NULL,
            `course_name` VARCHAR(255) NOT NULL,
            `academic_year` VARCHAR(10) DEFAULT '',
            `semester` VARCHAR(10) DEFAULT '',
            `owner_user_id` BIGINT NULL,
            `join_code` VARCHAR(50) DEFAULT '',
            `theme_color` VARCHAR(7) NOT NULL DEFAULT '#185b86',
            `status` VARCHAR(30) NOT NULL DEFAULT 'draft',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_grader_courses_owner_user_id` (`owner_user_id`),
            KEY `idx_grader_courses_period` (`academic_year`, `semester`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `grader_course_enrollments` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `course_id` BIGINT NOT NULL,
            `user_id` BIGINT NOT NULL,
            `role_in_course` VARCHAR(30) NOT NULL DEFAULT 'student',
            `enrolled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_grader_course_enrollment` (`course_id`, `user_id`),
            CONSTRAINT `fk_grader_enrollments_course`
                FOREIGN KEY (`course_id`) REFERENCES `grader_courses`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_grader_enrollments_user`
                FOREIGN KEY (`user_id`) REFERENCES `grader_users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `grader_modules` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `course_id` BIGINT NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_grader_modules_course` (`course_id`, `sort_order`),
            CONSTRAINT `fk_grader_modules_course`
                FOREIGN KEY (`course_id`) REFERENCES `grader_courses`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `grader_problems` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `module_id` BIGINT NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL,
            `description_md` LONGTEXT NULL,
            `starter_code` LONGTEXT NULL,
            `language` VARCHAR(30) NOT NULL DEFAULT 'python',
            `time_limit_sec` DECIMAL(5,2) NOT NULL DEFAULT 2.00,
            `memory_limit_mb` INT NOT NULL DEFAULT 128,
            `max_score` INT NOT NULL DEFAULT 100,
            `visibility` VARCHAR(30) NOT NULL DEFAULT 'draft',
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_by` BIGINT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_grader_problems_slug` (`slug`),
            KEY `idx_grader_problems_module` (`module_id`, `sort_order`),
            CONSTRAINT `fk_grader_problems_module`
                FOREIGN KEY (`module_id`) REFERENCES `grader_modules`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `grader_test_cases` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `problem_id` BIGINT NOT NULL,
            `case_type` VARCHAR(30) NOT NULL DEFAULT 'hidden',
            `stdin_text` LONGTEXT NULL,
            `expected_stdout` LONGTEXT NULL,
            `score_weight` INT NOT NULL DEFAULT 0,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_grader_test_cases_problem` (`problem_id`, `sort_order`),
            CONSTRAINT `fk_grader_test_cases_problem`
                FOREIGN KEY (`problem_id`) REFERENCES `grader_problems`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `grader_submissions` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `problem_id` BIGINT NOT NULL,
            `user_id` BIGINT NOT NULL,
            `course_id` BIGINT NULL,
            `language` VARCHAR(30) NOT NULL DEFAULT 'python',
            `source_code` LONGTEXT NOT NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'queued',
            `score` INT NOT NULL DEFAULT 0,
            `passed_cases` INT NOT NULL DEFAULT 0,
            `total_cases` INT NOT NULL DEFAULT 0,
            `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `graded_at` DATETIME NULL,
            KEY `idx_grader_submissions_problem` (`problem_id`),
            KEY `idx_grader_submissions_user` (`user_id`),
            KEY `idx_grader_submissions_status` (`status`),
            CONSTRAINT `fk_grader_submissions_problem`
                FOREIGN KEY (`problem_id`) REFERENCES `grader_problems`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_grader_submissions_user`
                FOREIGN KEY (`user_id`) REFERENCES `grader_users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_grader_submissions_course`
                FOREIGN KEY (`course_id`) REFERENCES `grader_courses`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `grader_submission_results` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `submission_id` BIGINT NOT NULL,
            `test_case_id` BIGINT NULL,
            `status` VARCHAR(40) NOT NULL DEFAULT 'pending',
            `actual_stdout` LONGTEXT NULL,
            `stderr_text` LONGTEXT NULL,
            `execution_time_ms` INT NOT NULL DEFAULT 0,
            `memory_used_kb` INT NOT NULL DEFAULT 0,
            `score_awarded` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_grader_submission_results_submission` (`submission_id`),
            CONSTRAINT `fk_grader_submission_results_submission`
                FOREIGN KEY (`submission_id`) REFERENCES `grader_submissions`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_grader_submission_results_case`
                FOREIGN KEY (`test_case_id`) REFERENCES `grader_test_cases`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `grader_jobs` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `submission_id` BIGINT NOT NULL,
            `job_status` VARCHAR(30) NOT NULL DEFAULT 'queued',
            `runner_target` VARCHAR(100) DEFAULT 'default',
            `claimed_by_worker` VARCHAR(100) DEFAULT '',
            `claim_token` VARCHAR(100) DEFAULT '',
            `attempt_count` INT NOT NULL DEFAULT 0,
            `priority` INT NOT NULL DEFAULT 100,
            `last_error` TEXT NULL,
            `queued_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `claimed_at` DATETIME NULL,
            `finished_at` DATETIME NULL,
            KEY `idx_grader_jobs_status` (`job_status`, `priority`, `queued_at`),
            CONSTRAINT `fk_grader_jobs_submission`
                FOREIGN KEY (`submission_id`) REFERENCES `grader_submissions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `grader_workers` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `worker_name` VARCHAR(100) NOT NULL,
            `worker_host` VARCHAR(255) DEFAULT '',
            `worker_token_hash` VARCHAR(255) DEFAULT '',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `last_seen_at` DATETIME NULL,
            `capabilities_json` LONGTEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_grader_workers_name` (`worker_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `grader_problem_assets` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `problem_id` BIGINT NOT NULL,
            `asset_type` VARCHAR(50) NOT NULL DEFAULT 'attachment',
            `file_path` VARCHAR(500) NOT NULL,
            `original_name` VARCHAR(255) DEFAULT '',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_grader_problem_assets_problem` (`problem_id`),
            CONSTRAINT `fk_grader_problem_assets_problem`
                FOREIGN KEY (`problem_id`) REFERENCES `grader_problems`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `grader_settings` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `setting_key` VARCHAR(100) NOT NULL,
            `setting_value` LONGTEXT NULL,
            `scope_type` VARCHAR(30) NOT NULL DEFAULT 'system',
            `scope_id` BIGINT NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_grader_settings_scope` (`setting_key`, `scope_type`, `scope_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function graderapp_ensure_schema(PDO $pdo): void
{
    cpeapp_schema_ensure(
        $pdo,
        'grader_app',
        graderapp_schema_statements(),
        [
            'graderapp_apply_legacy_migrations',
            'graderapp_seed_default_settings',
            'graderapp_seed_default_worker',
            'graderapp_seed_demo_data',
        ]
    );
}
