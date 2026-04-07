<?php

require_once dirname(__DIR__) . '/shared/config_helpers.php';
require_once dirname(__DIR__) . '/shared/schema_helpers.php';

function cpeportal_config(string $key, $default = null)
{
    return cpeapp_config($key, $default);
}

function cpeportal_required_config(string $key)
{
    return cpeapp_required_config($key);
}

function cpeportal_pdo(): PDO
{
    return cpeapp_pdo_from_root_config();
}

function cpeportal_base_path(string $segment = 'root'): string
{
    $defaults = [
        'root' => '/cpe_portal',
        'admin' => '/cpe_portal/admin',
    ];

    $configKeys = [
        'root' => 'CPEPORTAL_PATH_ROOT',
        'admin' => 'CPEPORTAL_PATH_ADMIN',
    ];

    $configured = cpeportal_config($configKeys[$segment] ?? '', $defaults[$segment] ?? '/cpe_portal');
    $path = '/' . trim((string) $configured, '/');

    return $path === '/' ? ($defaults[$segment] ?? '/cpe_portal') : $path;
}

function cpeportal_path(string $route, array $params = []): string
{
    $root = cpeportal_base_path('root');
    $admin = cpeportal_base_path('admin');

    $routes = [
        'portal.home' => $root . '/index.php',
        'portal.admin' => $admin . '/index.php',
        'portal.logout' => $admin . '/index.php?action=logout',
    ];

    $path = $routes[$route] ?? $root . '/index.php';
    if (!$params) {
        return $path;
    }

    $query = http_build_query($params);
    return $query === '' ? $path : $path . (strpos($path, '?') !== false ? '&' : '?') . $query;
}

function cpeportal_table_exists(PDO $pdo, string $table): bool
{
    return cpeapp_schema_table_exists($pdo, $table, 'cpe_portal');
}

function cpeportal_column_exists(PDO $pdo, string $table, string $column): bool
{
    return cpeapp_schema_column_exists($pdo, $table, $column, 'cpe_portal');
}

function cpeportal_setting_get(PDO $pdo, string $key, $default = null)
{
    if (!cpeportal_table_exists($pdo, 'cpeportal_settings')) {
        return $default;
    }

    $stmt = $pdo->prepare("
        SELECT setting_value
        FROM cpeportal_settings
        WHERE setting_key = :setting_key
        LIMIT 1
    ");
    $stmt->execute([':setting_key' => $key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? $value : $default;
}

function cpeportal_setting_set(PDO $pdo, string $key, string $value): bool
{
    if (!cpeportal_table_exists($pdo, 'cpeportal_settings')) {
        return false;
    }

    $stmt = $pdo->prepare("
        INSERT INTO cpeportal_settings (setting_key, setting_value)
        VALUES (:setting_key, :setting_value)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    return $stmt->execute([
        ':setting_key' => $key,
        ':setting_value' => $value,
    ]);
}

function cpeportal_schema_statements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS `cpeportal_categories` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `category_key` VARCHAR(100) NOT NULL UNIQUE,
            `category_name` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            `sort_order` INT DEFAULT 0,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `cpeportal_apps` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `cpeportal_settings` (
            `setting_key` VARCHAR(100) PRIMARY KEY,
            `setting_value` TEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function cpeportal_default_categories_seed(): array
{
    return [
        [
            'category_key' => 'quality_assurance',
            'category_name' => 'ประกันคุณภาพและรายงาน',
            'description' => 'ระบบสำหรับ AUN-QA, รายงานประเมิน, และหลักฐานเชิงคุณภาพ',
            'sort_order' => 1,
        ],
        [
            'category_key' => 'services',
            'category_name' => 'บริการและการติดตามงาน',
            'description' => 'ระบบบริการสำนักงานคณะและ app สนับสนุนในอนาคต',
            'sort_order' => 2,
        ],
    ];
}

function cpeportal_default_apps_seed(): array
{
    return [
        [
            'app_key' => 'aunqa_hub',
            'category_key' => 'quality_assurance',
            'app_name' => 'AUN-QA Hub',
            'app_description' => 'คัดเลือกรายวิชา ทวนสอบ ติดตาม PDCA และสรุปรอบประเมิน',
            'entry_url' => '/aunqa_php_portal/index.php',
            'admin_url' => '/aunqa_php_portal/verification_board.php',
            'icon_class' => 'bi-journal-check',
            'theme_color' => '#1d3557',
            'status_label' => 'พร้อมใช้งาน',
            'sort_order' => 1,
            'is_featured' => 1,
        ],
        [
            'app_key' => 'office_feedback',
            'category_key' => 'services',
            'app_name' => 'Office Feedback',
            'app_description' => 'แบบประเมินบริการสำนักงานคณะ พร้อม dashboard, รายงาน, และ SAR assistant',
            'entry_url' => '/office_feedback/index.php',
            'admin_url' => '/office_feedback/admin/index.php',
            'icon_class' => 'bi-emoji-smile',
            'theme_color' => '#6c4b2a',
            'status_label' => 'พร้อมใช้งาน',
            'sort_order' => 2,
            'is_featured' => 1,
        ],
        [
            'app_key' => 'project_management',
            'category_key' => 'services',
            'app_name' => 'Project Management',
            'app_description' => 'พื้นที่สำรองสำหรับระบบติดตามความก้าวหน้าโครงงานของนักศึกษาและอาจารย์ที่ปรึกษา',
            'entry_url' => '#',
            'admin_url' => '',
            'icon_class' => 'bi-kanban',
            'theme_color' => '#495057',
            'status_label' => 'วางแผนไว้',
            'sort_order' => 3,
            'is_featured' => 0,
        ],
        [
            'app_key' => 'grader_app',
            'category_key' => 'services',
            'app_name' => 'Grader App',
            'app_description' => 'ระบบ scaffold สำหรับตรวจแบบฝึกหัดเขียนโปรแกรม แยก web, queue, และ worker ออกจากกัน',
            'entry_url' => '/grader_app/index.php',
            'admin_url' => '/grader_app/admin/index.php',
            'icon_class' => 'bi-code-square',
            'theme_color' => '#1b5e8f',
            'status_label' => 'กำลังพัฒนา',
            'sort_order' => 4,
            'is_featured' => 0,
        ],
    ];
}

function cpeportal_seed_categories(PDO $pdo): void
{
    if (!cpeportal_table_exists($pdo, 'cpeportal_categories')) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO cpeportal_categories (category_key, category_name, description, sort_order, is_active)
        VALUES (:category_key, :category_name, :description, :sort_order, 1)
        ON DUPLICATE KEY UPDATE
            category_name = VALUES(category_name),
            description = VALUES(description),
            sort_order = VALUES(sort_order)
    ");

    foreach (cpeportal_default_categories_seed() as $row) {
        $stmt->execute([
            ':category_key' => $row['category_key'],
            ':category_name' => $row['category_name'],
            ':description' => $row['description'],
            ':sort_order' => $row['sort_order'],
        ]);
    }
}

function cpeportal_seed_apps(PDO $pdo): void
{
    if (!cpeportal_table_exists($pdo, 'cpeportal_apps') || !cpeportal_table_exists($pdo, 'cpeportal_categories')) {
        return;
    }

    $categoryMap = [];
    foreach ($pdo->query("SELECT id, category_key FROM cpeportal_categories") as $row) {
        $categoryMap[$row['category_key']] = (int) $row['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO cpeportal_apps
            (app_key, category_id, app_name, app_description, entry_url, admin_url, icon_class, theme_color, status_label, sort_order, is_active, is_featured)
        VALUES
            (:app_key, :category_id, :app_name, :app_description, :entry_url, :admin_url, :icon_class, :theme_color, :status_label, :sort_order, 1, :is_featured)
        ON DUPLICATE KEY UPDATE
            category_id = VALUES(category_id),
            app_name = VALUES(app_name),
            app_description = VALUES(app_description),
            entry_url = VALUES(entry_url),
            admin_url = VALUES(admin_url),
            icon_class = VALUES(icon_class),
            theme_color = VALUES(theme_color),
            status_label = VALUES(status_label),
            sort_order = VALUES(sort_order),
            is_featured = VALUES(is_featured)
    ");

    foreach (cpeportal_default_apps_seed() as $row) {
        $stmt->execute([
            ':app_key' => $row['app_key'],
            ':category_id' => $categoryMap[$row['category_key']] ?? null,
            ':app_name' => $row['app_name'],
            ':app_description' => $row['app_description'],
            ':entry_url' => $row['entry_url'],
            ':admin_url' => $row['admin_url'],
            ':icon_class' => $row['icon_class'],
            ':theme_color' => $row['theme_color'],
            ':status_label' => $row['status_label'],
            ':sort_order' => $row['sort_order'],
            ':is_featured' => $row['is_featured'],
        ]);
    }
}

function cpeportal_seed_settings(PDO $pdo): void
{
    if (!cpeportal_table_exists($pdo, 'cpeportal_settings')) {
        return;
    }

    $defaults = [
        'cpeportal_brand_name' => 'CPE RBRU Apps',
        'cpeportal_brand_tagline' => 'ศูนย์รวมระบบดิจิทัลของสาขาวิศวกรรมคอมพิวเตอร์',
    ];

    foreach ($defaults as $key => $value) {
        cpeportal_setting_set($pdo, $key, $value);
    }
}

function cpeportal_ensure_schema(PDO $pdo): void
{
    cpeapp_schema_ensure(
        $pdo,
        'cpe_portal',
        cpeportal_schema_statements(),
        [
            'cpeportal_seed_categories',
            'cpeportal_seed_apps',
            'cpeportal_seed_settings',
        ]
    );
}

function cpeportal_bootstrap_state(): array
{
    return cpeapp_bootstrap_state(
        function () {
            return cpeportal_pdo();
        },
        'cpeportal_ensure_schema'
    );
}
