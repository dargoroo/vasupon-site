-- Reference schema for grader_app.
-- Runtime installation should use grader_app/schema.php via graderapp_ensure_schema().

CREATE TABLE IF NOT EXISTS `grader_users` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `portal_user_id` BIGINT NULL,
    `email` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `role` VARCHAR(30) NOT NULL DEFAULT 'student',
    `student_code` VARCHAR(50) DEFAULT '',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_grader_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `grader_courses` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `course_code` VARCHAR(50) NOT NULL,
    `course_name` VARCHAR(255) NOT NULL,
    `academic_year` VARCHAR(10) DEFAULT '',
    `semester` VARCHAR(10) DEFAULT '',
    `owner_user_id` BIGINT NULL,
    `join_code` VARCHAR(50) DEFAULT '',
    `status` VARCHAR(30) NOT NULL DEFAULT 'draft',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `grader_settings` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` LONGTEXT NULL,
    `scope_type` VARCHAR(30) NOT NULL DEFAULT 'system',
    `scope_id` BIGINT NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_grader_settings_scope` (`setting_key`, `scope_type`, `scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
