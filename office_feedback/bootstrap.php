<?php

require_once dirname(__DIR__) . '/shared/config_helpers.php';
require_once dirname(__DIR__) . '/shared/schema_helpers.php';

function officefb_load_root_config(): void
{
    cpeapp_load_root_config();
}

function officefb_config(string $key, $default = null)
{
    return cpeapp_config($key, $default);
}

function officefb_required_config(string $key)
{
    return cpeapp_required_config($key);
}

function officefb_base_path(string $segment): string
{
    $defaults = [
        'kiosk' => '/office_feedback',
        'admin' => '/office_feedback/admin',
        'report' => '/office_feedback/report',
    ];

    $configKeys = [
        'kiosk' => 'OFFICEFB_PATH_KIOSK',
        'admin' => 'OFFICEFB_PATH_ADMIN',
        'report' => 'OFFICEFB_PATH_REPORT',
    ];

    $configured = officefb_config($configKeys[$segment] ?? '', $defaults[$segment] ?? '/');
    $path = '/' . trim((string) $configured, '/');

    return $path === '/' ? ($defaults[$segment] ?? '/') : $path;
}

function officefb_path(string $route, array $params = []): string
{
    $kioskBase = officefb_base_path('kiosk');
    $adminBase = officefb_base_path('admin');
    $reportBase = officefb_base_path('report');

    $routes = [
        'kiosk.root' => $kioskBase,
        'kiosk.home' => $kioskBase . '/kiosk.php',
        'kiosk.submit' => $kioskBase . '/submit_rating.php',
        'admin.home' => $adminBase . '/index.php',
        'admin.staff' => $adminBase . '/staff.php',
        'admin.topics' => $adminBase . '/topics.php',
        'admin.sar' => $adminBase . '/sar_assistant.php',
        'admin.logout' => $adminBase . '/index.php?action=logout',
        'report.home' => $reportBase . '/index.php',
    ];

    $path = $routes[$route] ?? '/';
    if (empty($params)) {
        return $path;
    }

    $query = http_build_query($params);
    if ($query === '') {
        return $path;
    }

    return $path . (strpos($path, '?') !== false ? '&' : '?') . $query;
}

function officefb_table_cache_reset()
{
    cpeapp_schema_reset_cache('office_feedback');
}

function officefb_pdo() {
    return cpeapp_pdo_from_root_config();
}

function officefb_table_exists($pdo, $table_name) {
    return cpeapp_schema_table_exists($pdo, (string) $table_name, 'office_feedback');
}

function officefb_column_exists($pdo, $table_name, $column_name)
{
    return cpeapp_schema_column_exists($pdo, (string) $table_name, (string) $column_name, 'office_feedback');
}

function officefb_schema_statements()
{
    return [
        "CREATE TABLE IF NOT EXISTS `officefb_staff` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `full_name` VARCHAR(255) NOT NULL,
            `position_name` VARCHAR(255) NOT NULL,
            `photo_url` VARCHAR(500) DEFAULT '',
            `department_name` VARCHAR(255) DEFAULT 'สำนักงานคณะ',
            `service_area` VARCHAR(255) DEFAULT '',
            `display_order` INT DEFAULT 0,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `officefb_topics` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `topic_name` VARCHAR(255) NOT NULL,
            `display_order` INT DEFAULT 0,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `officefb_settings` (
            `setting_key` VARCHAR(100) PRIMARY KEY,
            `setting_value` TEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `officefb_devices` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `device_code` VARCHAR(100) NOT NULL UNIQUE,
            `device_name` VARCHAR(255) DEFAULT '',
            `location_name` VARCHAR(255) DEFAULT '',
            `is_active` TINYINT(1) DEFAULT 1,
            `last_seen_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `officefb_ratings` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `staff_id` INT NOT NULL,
            `device_id` INT NULL,
            `device_token` VARCHAR(100) DEFAULT '',
            `rating_score` TINYINT NOT NULL,
            `rating_label` VARCHAR(50) NOT NULL,
            `service_topic` VARCHAR(255) DEFAULT '',
            `comment_text` TEXT NULL,
            `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `submitted_date` DATE NOT NULL,
            `submitted_hour` TINYINT NOT NULL,
            `academic_year` VARCHAR(10) DEFAULT '',
            `semester` VARCHAR(2) DEFAULT '',
            KEY `idx_officefb_staff_date` (`staff_id`, `submitted_date`),
            KEY `idx_officefb_submitted_at` (`submitted_at`),
            KEY `idx_officefb_device_token` (`device_token`),
            CONSTRAINT `fk_officefb_ratings_staff`
                FOREIGN KEY (`staff_id`) REFERENCES `officefb_staff`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_officefb_ratings_device`
                FOREIGN KEY (`device_id`) REFERENCES `officefb_devices`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function officefb_seed_topics($pdo)
{
    if (!officefb_table_exists($pdo, 'officefb_topics')) {
        return;
    }

    $count = (int) $pdo->query("SELECT COUNT(*) FROM officefb_topics")->fetchColumn();
    if ($count > 0) {
        return;
    }

    $topics = officefb_default_topics_seed();

    $stmt = $pdo->prepare("
        INSERT INTO officefb_topics (topic_name, display_order, is_active)
        VALUES (:topic_name, :display_order, 1)
    ");

    foreach ($topics as $topic) {
        $stmt->execute([
            ':topic_name' => $topic['topic_name'],
            ':display_order' => $topic['display_order'],
        ]);
    }
}

function officefb_seed_default_settings($pdo)
{
    if (!officefb_table_exists($pdo, 'officefb_settings')) {
        return;
    }

    $defaults = [
        'officefb_ai_provider' => 'gemini',
        'officefb_gemini_api_model' => 'gemini-2.5-flash',
        'officefb_ai_auto_pass_threshold' => '80',
    ];

    $stmt = $pdo->prepare("
        INSERT INTO officefb_settings (setting_key, setting_value)
        VALUES (:setting_key, :setting_value)
        ON DUPLICATE KEY UPDATE setting_value = setting_value
    ");

    foreach ($defaults as $key => $value) {
        $stmt->execute([
            ':setting_key' => $key,
            ':setting_value' => $value,
        ]);
    }
}

function officefb_seed_staff($pdo)
{
    if (!officefb_table_exists($pdo, 'officefb_staff')) {
        return;
    }

    $count = (int) $pdo->query("SELECT COUNT(*) FROM officefb_staff")->fetchColumn();
    if ($count > 0) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO officefb_staff
            (full_name, position_name, photo_url, department_name, service_area, display_order, is_active)
        VALUES
            (:full_name, :position_name, :photo_url, :department_name, :service_area, :display_order, :is_active)
    ");

    foreach (officefb_default_staff_seed() as $row) {
        $stmt->execute([
            ':full_name' => $row['full_name'],
            ':position_name' => $row['position_name'],
            ':photo_url' => $row['photo_url'],
            ':department_name' => $row['department_name'],
            ':service_area' => $row['service_area'],
            ':display_order' => $row['display_order'],
            ':is_active' => $row['is_active'],
        ]);
    }
}

function officefb_ensure_schema($pdo)
{
    cpeapp_schema_ensure(
        $pdo,
        'office_feedback',
        officefb_schema_statements(),
        [
            'officefb_seed_default_settings',
            'officefb_seed_topics',
            'officefb_seed_staff',
        ]
    );
}

function officefb_bootstrap_state() {
    return cpeapp_bootstrap_state(
        function () {
            return officefb_pdo();
        },
        'officefb_ensure_schema'
    );
}

function officefb_setting_get($pdo, $setting_key, $default = null)
{
    if (!officefb_table_exists($pdo, 'officefb_settings')) {
        return $default;
    }

    $stmt = $pdo->prepare("
        SELECT setting_value
        FROM officefb_settings
        WHERE setting_key = :setting_key
        LIMIT 1
    ");
    $stmt->execute([':setting_key' => $setting_key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? $value : $default;
}

function officefb_setting_set($pdo, $setting_key, $setting_value)
{
    if (!officefb_table_exists($pdo, 'officefb_settings')) {
        return false;
    }

    $stmt = $pdo->prepare("
        INSERT INTO officefb_settings (setting_key, setting_value)
        VALUES (:setting_key, :setting_value)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    return $stmt->execute([
        ':setting_key' => $setting_key,
        ':setting_value' => $setting_value,
    ]);
}

function officefb_setting_delete($pdo, $setting_key)
{
    if (!officefb_table_exists($pdo, 'officefb_settings')) {
        return false;
    }

    $stmt = $pdo->prepare("
        DELETE FROM officefb_settings
        WHERE setting_key = :setting_key
    ");

    return $stmt->execute([
        ':setting_key' => $setting_key,
    ]);
}

function officefb_rating_meta($score) {
    $map = [
        4 => ['label' => 'Excellent', 'thai' => 'ยอดเยี่ยม', 'color' => '#2d6a4f', 'emoji' => '🤩'],
        3 => ['label' => 'Good', 'thai' => 'ดี', 'color' => '#40916c', 'emoji' => '🙂'],
        2 => ['label' => 'Poor', 'thai' => 'ควรปรับปรุง', 'color' => '#f4a261', 'emoji' => '😐'],
        1 => ['label' => 'Very Poor', 'thai' => 'ไม่พึงพอใจ', 'color' => '#d62828', 'emoji' => '🙁'],
    ];

    return isset($map[$score]) ? $map[$score] : $map[3];
}

function officefb_academic_period() {
    $month = (int) date('n');
    $year = (int) date('Y') + 543;

    if ($month >= 6 && $month <= 10) {
        return ['academic_year' => (string) $year, 'semester' => '1'];
    }
    if ($month >= 11 || $month <= 3) {
        if ($month <= 3) {
            $year -= 1;
        }
        return ['academic_year' => (string) $year, 'semester' => '2'];
    }

    return ['academic_year' => (string) $year, 'semester' => '3'];
}

function officefb_default_staff_seed()
{
    $base = 'https://www.csit.rbru.ac.th/faculty-staff';

    return [
        [
            'full_name' => 'นางมาลีวัลย์ สีจาง',
            'position_name' => 'รักษาการหัวหน้าสำนักงาน',
            'photo_url' => $base . '/imgs/maleewan.jpg',
            'department_name' => 'สำนักงานคณะ',
            'service_area' => 'งานบริหารสำนักงานคณะและประสานงานส่วนกลาง',
            'display_order' => 1,
            'is_active' => 1,
        ],
        [
            'full_name' => 'นางสาวชมจันทร์ ลีลาภรณ์',
            'position_name' => 'นักวิชาการ',
            'photo_url' => $base . '/imgs/chomcha.jpg',
            'department_name' => 'สำนักงานคณะ',
            'service_area' => 'งานบริการนักศึกษาและงานวิชาการ',
            'display_order' => 2,
            'is_active' => 1,
        ],
        [
            'full_name' => 'นายอรรณพชัย วรรณเลิศยศ',
            'position_name' => 'นักวิชาการ',
            'photo_url' => $base . '/imgs/unnopchai.jpg',
            'department_name' => 'สำนักงานคณะ',
            'service_area' => 'งานเอกสารและประสานงานบริการ',
            'display_order' => 3,
            'is_active' => 1,
        ],
        [
            'full_name' => 'นางสาวกนกวรรณ ทรัพย์เจริญ',
            'position_name' => 'นักวิชาการ',
            'photo_url' => $base . '/imgs/kanonwan.jpg',
            'department_name' => 'สำนักงานคณะ',
            'service_area' => 'งานธุรการและงานสนับสนุนสำนักงาน',
            'display_order' => 4,
            'is_active' => 1,
        ],
    ];
}

function officefb_default_topics_seed()
{
    return [
        [
            'topic_name' => 'การให้คำแนะนำและข้อมูล',
            'display_order' => 1,
            'is_active' => 1,
        ],
        [
            'topic_name' => 'ความรวดเร็วในการให้บริการ',
            'display_order' => 2,
            'is_active' => 1,
        ],
        [
            'topic_name' => 'ความสุภาพและการต้อนรับ',
            'display_order' => 3,
            'is_active' => 1,
        ],
        [
            'topic_name' => 'ความชัดเจนของขั้นตอนและเอกสาร',
            'display_order' => 4,
            'is_active' => 1,
        ],
        [
            'topic_name' => 'การติดตามงานและการประสานงาน',
            'display_order' => 5,
            'is_active' => 1,
        ],
    ];
}
