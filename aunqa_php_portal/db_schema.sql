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
