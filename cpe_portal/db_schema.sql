CREATE TABLE IF NOT EXISTS `cpeportal_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_key` VARCHAR(100) NOT NULL UNIQUE,
    `category_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cpeportal_apps` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `app_key` VARCHAR(100) NOT NULL UNIQUE,
    `category_id` INT NULL,
    `app_name` VARCHAR(255) NOT NULL,
    `app_description` TEXT NULL,
    `entry_url` VARCHAR(255) NOT NULL,
    `admin_url` VARCHAR(255) DEFAULT '',
    `icon_class` VARCHAR(100) DEFAULT 'bi-grid-1x2-fill',
    `theme_color` VARCHAR(20) DEFAULT '#1f4f7b',
    `status_label` VARCHAR(50) DEFAULT 'พร้อมใช้งาน',
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `is_featured` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_cpeportal_apps_category`
        FOREIGN KEY (`category_id`) REFERENCES `cpeportal_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cpeportal_settings` (
    `setting_key` VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
