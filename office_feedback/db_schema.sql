CREATE TABLE IF NOT EXISTS `officefb_staff` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `officefb_topics` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `topic_name` VARCHAR(255) NOT NULL,
  `display_order` INT DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `officefb_devices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `device_code` VARCHAR(100) NOT NULL UNIQUE,
  `device_name` VARCHAR(255) DEFAULT '',
  `location_name` VARCHAR(255) DEFAULT '',
  `is_active` TINYINT(1) DEFAULT 1,
  `last_seen_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `officefb_ratings` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `officefb_topics` (`topic_name`, `display_order`)
SELECT * FROM (
  SELECT 'การให้คำแนะนำและข้อมูล', 1
  UNION ALL
  SELECT 'ความรวดเร็วในการให้บริการ', 2
  UNION ALL
  SELECT 'ความสุภาพและการต้อนรับ', 3
  UNION ALL
  SELECT 'ความชัดเจนของขั้นตอนและเอกสาร', 4
  UNION ALL
  SELECT 'การติดตามงานและการประสานงาน', 5
) AS `seed_topics`
WHERE NOT EXISTS (SELECT 1 FROM `officefb_topics` LIMIT 1);

-- ตัวอย่างข้อมูลบุคลากรเริ่มต้น
-- ปรับแก้ photo_url ให้ตรงกับตำแหน่งจริงบน server ได้ภายหลัง
INSERT INTO `officefb_staff` (`full_name`, `position_name`, `photo_url`, `display_order`)
SELECT * FROM (
  SELECT 'นางมาลีวัลย์ สีจาง', 'รักษาการหัวหน้าสำนักงาน', 'https://www.csit.rbru.ac.th/faculty-staff/imgs/maleewan.jpg', 1
  UNION ALL
  SELECT 'นางสาวชมจันทร์ ลีลาภรณ์', 'นักวิชาการ', 'https://www.csit.rbru.ac.th/faculty-staff/imgs/chomcha.jpg', 2
  UNION ALL
  SELECT 'นายอรรณพชัย วรรณเลิศยศ', 'นักวิชาการ', 'https://www.csit.rbru.ac.th/faculty-staff/imgs/unnopchai.jpg', 3
  UNION ALL
  SELECT 'นางสาวกนกวรรณ ทรัพย์เจริญ', 'นักวิชาการ', 'https://www.csit.rbru.ac.th/faculty-staff/imgs/kanonwan.jpg', 4
) AS `seed_staff`
WHERE NOT EXISTS (SELECT 1 FROM `officefb_staff` LIMIT 1);
