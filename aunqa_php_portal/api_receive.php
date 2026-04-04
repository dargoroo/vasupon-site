<?php
// api_receive.php - รับข้อมูลการขุดเจาะจากบอท GitHub Actions แล้วยัดลง Database
header('Content-Type: application/json');

// อนุญาตให้รับแบบ POST เท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

// รับค่า JSON ส่งมาจากสคริปต์ Python
$jsonStr = file_get_contents('php://input');
$data = json_decode($jsonStr, true);

if (!$data || !isset($data['students'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid payload"]);
    exit;
}

// การเตรียมเชื่อมต่อ Database ของคุณ
// ⚠️ สำคัญมาก: ห้ามใส่รหัสบรรทัดนี้ลงไฟล์ตรงๆ ถ้านำขึ้น Github
// แนะนำให้ย้ายตัวแปรเหล่านี้ไปไว้ในไฟล์ config.php แล้ว include เข้ามาแทน
$host = 'localhost';
$dbname = 'vasupon_p';
$username = getenv('DB_USER') ?: 'your_db_username';
$password = getenv('DB_PASS') ?: 'your_db_password';

require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8", $DB_USER, $DB_PASS);
    // ... (โค้ดเดิมด้านล่าง) ...

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // เริ่มเก็บข้อมูลจาก Bot ที่เรียงไว้
    $total_inserted = 0;

    // สคริปต์ Python สามารถแนบรายวิชา Whitelist มาด้วย 
    // หรือคุณจะ Query เช็คจากผังตารางของ TQF ก็ได้

    $stmt = $pdo->prepare("INSERT INTO aunqa_grades (student_id, student_name, grade_mode, status, total_score, cal_grade, final_grade, course_code, course_name, term, year) 
                           VALUES (:id, :name, :mode, :status, :score, :cal, :final, :ccode, :cname, :term, :year)");

    foreach ($data['students'] as $stu) {
        $stmt->execute([
            ':id' => $stu['student_id'],
            ':name' => $stu['name'],
            ':mode' => $stu['grade_mode'],
            ':status' => $stu['status'],
            ':score' => $stu['total_score'],
            ':cal' => $stu['cal_grade'],
            ':final' => $stu['final_grade'],
            ':ccode' => $data['course_code'], // ข้อมูลรายวิชาที่บอทแนบมา
            ':cname' => $data['course_name'],
            ':term' => $data['term'],
            ':year' => $data['year']
        ]);
        $total_inserted++;
    }

    echo json_encode(["status" => "success", "inserted" => $total_inserted]);

}
catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>