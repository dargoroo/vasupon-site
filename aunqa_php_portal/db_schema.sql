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
  `activity_name` VARCHAR(255) NOT NULL,
  `target_clo` VARCHAR(100),
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

