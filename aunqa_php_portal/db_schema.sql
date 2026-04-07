-- ก๊อปปี้ไปรันใน phpMyAdmin > เลือก Database (vasupon_p) > ไปที่แท็บ SQL > กด Go

-- สร้างตารางหลักสูตร (ที่ขุดจาก TQF)
CREATE TABLE IF NOT EXISTS `aunqa_curriculum_courses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `year` VARCHAR(10) NOT NULL,
  `semester` VARCHAR(2) NOT NULL,
  `course_code` VARCHAR(20) NOT NULL,
  `course_name` VARCHAR(255) NOT NULL,
  `faculty` VARCHAR(100) DEFAULT 'วิศวกรรมคอมพิวเตอร์',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- สร้างตารางเก็บผลการเรียนนักศึกษา ที่บอทส่งมา
CREATE TABLE IF NOT EXISTS `aunqa_grades` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_code` VARCHAR(20) NOT NULL,
  `course_name` VARCHAR(255) NOT NULL,
  `term` VARCHAR(2) NOT NULL,
  `year` VARCHAR(10) NOT NULL,
  
  `student_id` VARCHAR(20) NOT NULL,
  `student_name` VARCHAR(150) NOT NULL,
  `grade_mode` VARCHAR(10),
  `status` VARCHAR(50),
  `total_score` DECIMAL(5,2),
  `cal_grade` VARCHAR(10),
  `final_grade` VARCHAR(10),
  
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 📝 ตารางเก็บบันทึกรายวิชาที่ถูกเลือกเพื่อทวนสอบ
CREATE TABLE IF NOT EXISTS `aunqa_verification_records` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `year` VARCHAR(10) NOT NULL,
  `semester` VARCHAR(2) NOT NULL,
  `course_code` VARCHAR(20) NOT NULL,
  `course_name` VARCHAR(255) NOT NULL,
  `instructor` VARCHAR(255),
  `tqf3_link` VARCHAR(500) DEFAULT '',
  `tqf5_link` VARCHAR(500) DEFAULT '',
  `verification_status` VARCHAR(50) DEFAULT 'รอรับเอกสาร',
  `seed_batch_token` VARCHAR(64) NULL,
  `seed_source` VARCHAR(50) DEFAULT '',
  `selected_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 📊 ตารางประเมินผลการทวนสอบรายวิชาของกรรมการ (Checklist ร่องรอยหลักฐาน)
CREATE TABLE IF NOT EXISTS `aunqa_verification_checklists` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `verification_id` INT NOT NULL,  -- อ้างอิง id จาก aunqa_verification_records
  `check_clo_verb` TINYINT(1) DEFAULT 0, -- CLO เป็นคำกริยาตาม bloom taxonomy?
  `check_clo_plo_map` TINYINT(1) DEFAULT 0, -- CLO ตอบ PLO ครบ?
  `check_class_activity` TINYINT(1) DEFAULT 0, -- กิจกรรมในชั้นเรียนตอบ CLO?
  `score_bloom` DECIMAL(5,2) DEFAULT 0.00, -- % ความแม่นยำ Bloom
  `score_plo` DECIMAL(5,2) DEFAULT 0.00, -- % ความครบถ้วน PLO
  `score_activity` DECIMAL(5,2) DEFAULT 0.00, -- % ความสอดคล้องกิจกรรม
  `reviewer_strength` TEXT, -- 1. จุดเด่นของรายวิชา
  `reviewer_improvement` TEXT, -- 2. จุดที่ควรพัฒนา
  `pdca_followup` TEXT, -- ผลการติดตามรอบที่แล้ว (PDCA)
  `pdca_status` ENUM('not_started','in_progress','partially_resolved','resolved','carried_forward') DEFAULT 'not_started',
  `pdca_resolution_percent` DECIMAL(5,2) DEFAULT 0.00,
  `pdca_last_year_summary` TEXT NULL,
  `pdca_current_action` TEXT NULL,
  `pdca_evidence_note` TEXT NULL,
  `reviewed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 💡 ตารางเก็บการประเมินแยกรายข้อ หมวด 1: Bloom's Taxonomy
CREATE TABLE IF NOT EXISTS `aunqa_verification_bloom` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `verification_id` INT NOT NULL,
  `clo_code` VARCHAR(20) NOT NULL,
  `clo_text` TEXT NOT NULL,
  `bloom_verb` VARCHAR(100),
  `bloom_level` VARCHAR(100),
  `is_appropriate` TINYINT(1) DEFAULT 1,
  `suggestion` TEXT,
  `human_override` TINYINT(1) DEFAULT 0,
  FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 📈 ตารางเก็บการประเมินแยกรายข้อ หมวด 2: PLO Coverage (PLO เป็นตัวตั้ง)
CREATE TABLE IF NOT EXISTS `aunqa_verification_plo_coverage` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `verification_id` INT NOT NULL,
  `plo_code` VARCHAR(20) NOT NULL,
  `plo_text` TEXT NOT NULL,
  `contributing_clos` TEXT,
  `coverage_percent` DECIMAL(5,2) DEFAULT 0.00,
  `suggestion` TEXT,
  FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 📚 ตารางเก็บการประเมินแยกรายข้อ หมวด 3: กิจกรรมการเรียนการสอน
CREATE TABLE IF NOT EXISTS `aunqa_verification_activities` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `verification_id` INT NOT NULL,
  `activity_name` TEXT NOT NULL,
  `target_clo` TEXT,
  `contribution_percent` DECIMAL(5,2) DEFAULT 0.00,
  `suggestion` TEXT,
  FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ⚙️ ตารางการตั้งค่าระบบ (สำหรับเก็บ API Key กลาง)
CREATE TABLE IF NOT EXISTS `aunqa_settings` (
  `setting_key` VARCHAR(50) PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 🎯 ตารางเก็บผลสัมฤทธิ์รายระดับ CLO (จาก มคอ.5) และการรับรองจากกรรมการ
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 🧩 ตารางเก็บ issue PDCA รายประเด็น
-- ใช้ได้แม้ยังไม่มีประเด็นจากปีก่อน เพราะ previous_issue_id อนุญาต NULL
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
  CONSTRAINT `fk_pdca_issue_verification`
    FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pdca_issue_previous`
    FOREIGN KEY (`previous_issue_id`) REFERENCES `aunqa_pdca_issues`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 🛠️ ตารางเก็บ action plan / follow-up ตามวงจร PDCA
-- แยกออกจาก issue เพื่อให้หนึ่งปัญหามีได้หลาย action และรองรับการติดตามข้ามปี
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
  CONSTRAINT `fk_pdca_action_issue`
    FOREIGN KEY (`pdca_issue_id`) REFERENCES `aunqa_pdca_issues`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 🔗 ตารางเชื่อม issue เดิมกับรอบประเมินใหม่
-- ช่วยทำ dashboard แนวโน้มหลายปี โดยไม่บังคับว่าทุกรายวิชาต้องมี issue จากปีก่อน
CREATE TABLE IF NOT EXISTS `aunqa_pdca_links` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pdca_issue_id` INT NOT NULL,
  `verification_id` INT NOT NULL,
  `link_type` ENUM('followup_review','recurred','resolved_in_course','partial_improvement','manual_reference') DEFAULT 'followup_review',
  `committee_note` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pdca_link_issue` (`pdca_issue_id`),
  KEY `idx_pdca_link_verification` (`verification_id`),
  CONSTRAINT `fk_pdca_link_issue`
    FOREIGN KEY (`pdca_issue_id`) REFERENCES `aunqa_pdca_issues`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pdca_link_verification`
    FOREIGN KEY (`verification_id`) REFERENCES `aunqa_verification_records`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
