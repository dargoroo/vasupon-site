<?php
// ajax_ai_analyzer.php - รับไฟล์ TQF มคอ.3 / 5 และยิงให้ AI ประมวลผล (Pure PHP Version สำหรับเว็บโฮสติ้ง)
header('Content-Type: application/json');
require_once '../config.php';

$db_host = isset($DB_HOST) ? $DB_HOST : 'localhost';
$db_name = isset($DB_NAME) ? $DB_NAME : 'your_db_name';
$db_user = isset($DB_USER) ? $DB_USER : 'your_db_user';
$db_pass = getenv('DB_PASS') ?: 'your_db_password';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// ฟังก์ชันสกัดอักษรจากไฟล์ docx แบบ Pure PHP 
function read_docx($filename) {
    if(!$filename || !file_exists($filename)) {
        return "ERROR_READ_DOCX: File does not exist at path $filename";
    }
    
    $zip = new ZipArchive;
    $res = $zip->open($filename);
    if ($res === true) {
        if (($index = $zip->locateName('word/document.xml')) !== false) {
            $data = $zip->getFromIndex($index);
            $zip->close();
            // เติม newline ก่อนขึ้นพารากราฟใหม่ ตัวอักษรจะได้ไม่ติดกัน
            $data = str_replace(array('<w:p>', '<w:p '), "\n<w:p ", $data);
            // เอา tags xml ออก ให้เหลือแต่ text
            $text = strip_tags($data);
            return trim($text);
        } else {
            $zip->close();
            return "ERROR_READ_DOCX: Cannot find word/document.xml inside ZIP";
        }
    } else {
        return "ERROR_READ_DOCX: ZipArchive open failed with code $res";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_deep_details') {
    $vid = $_GET['verification_id'];
    
    try {
        $stmtB = $pdo->prepare("SELECT * FROM aunqa_verification_bloom WHERE verification_id = :v");
        $stmtB->execute([':v' => $vid]);
        $bloom = $stmtB->fetchAll(PDO::FETCH_ASSOC);
        
        $stmtP = $pdo->prepare("SELECT * FROM aunqa_verification_plo_coverage WHERE verification_id = :v");
        $stmtP->execute([':v' => $vid]);
        $plo = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        
        $stmtA = $pdo->prepare("SELECT * FROM aunqa_verification_activities WHERE verification_id = :v");
        $stmtA->execute([':v' => $vid]);
        $activities = $stmtA->fetchAll(PDO::FETCH_ASSOC);
        
        // global scores are in checklist table
        $stmtChk = $pdo->prepare("SELECT score_bloom, score_plo, score_activity FROM aunqa_verification_checklists WHERE verification_id = :v");
        $stmtChk->execute([':v' => $vid]);
        $scores = $stmtChk->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'bloom_analysis' => $bloom,
            'plo_coverage' => $plo,
            'activity_mapping' => $activities,
            'global_scores' => (empty($scores) ? ['score_bloom'=>0, 'score_plo'=>0, 'score_activity'=>0] : $scores)
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage() . '. Did you run the updated db_schema.sql?'
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'run_ai') {
    $vid = $_POST['verification_id'];
    
    // ดึง API Key
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM aunqa_settings WHERE setting_key IN ('gemini_api_key', 'gemini_api_model')");
    $settings = [];
    while($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $api_key = isset($settings['gemini_api_key']) ? $settings['gemini_api_key'] : 'mock';
    $api_model = isset($settings['gemini_api_model']) ? $settings['gemini_api_model'] : 'gemini-2.5-flash';

    // ใช้ System Temp Directory แทนเพื่อหลีกเลี่ยงปัญหา Permission (CHMOD) บนโฮสติ้ง
    $upload_dir = rtrim(sys_get_temp_dir(), '/\\') . '/';
    
    $tqf3_path = "";
    $tqf5_path = "";
    $debug_log = [];
    
    if (isset($_FILES['tqf3_file']) && $_FILES['tqf3_file']['error'] == 0) {
        if(preg_match('/\.(doc|pdf)$/i', $_FILES['tqf3_file']['name'], $matches)) {
            $ext = strtolower($matches[1]);
            echo json_encode(['error' => "ขออภัย ระบบรองรับเฉพาะไฟล์ .docx เท่านั้น พบว่าไฟล์ มคอ.3 เป็น .$ext โปรดแปลงไฟล์เป็น .docx (Word สมัยใหม่) ก่อนอัปโหลดครับ"]);
            exit;
        }
        $tqf3_path = $upload_dir . 't3_' . time() . '_' . basename($_FILES['tqf3_file']['name']);
        if(!move_uploaded_file($_FILES['tqf3_file']['tmp_name'], $tqf3_path)) {
            $debug_log[] = "move_uploaded_file failed for TQF3. Path: $tqf3_path";
            $tqf3_path = "";
        }
    } else if (isset($_POST['tqf3_url']) && !empty($_POST['tqf3_url'])) {
        $url = str_replace(' ', '%20', $_POST['tqf3_url']);
        if(preg_match('/\.(doc|pdf)$/i', $url, $matches)) {
            $ext = strtolower($matches[1]);
            echo json_encode(['error' => "ขออภัย ลิงก์ มคอ.3 ในระบบเป็นไฟล์ .$ext (อ่านไม่ได้) โปรดดาวน์โหลดไฟล์มาแปลงเป็น .docx แล้วใช้ปุ่มอัปโหลดด้วยตนเองครับ"]);
            exit;
        }
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $data = @file_get_contents($url, false, $context);
        if($data) {
            $tqf3_path = $upload_dir . 't3_net_' . time() . '.docx';
            if(!file_put_contents($tqf3_path, $data)) {
                 $debug_log[] = "file_put_contents failed for TQF3 net. Path: $tqf3_path";
                 $tqf3_path = "";
            }
        } else {
             $debug_log[] = "file_get_contents failed for TQF3 URL: $url";
        }
    }
    
    if (isset($_FILES['tqf5_file']) && $_FILES['tqf5_file']['error'] == 0) {
        $tqf5_path = $upload_dir . 't5_' . time() . '_' . basename($_FILES['tqf5_file']['name']);
        if(!move_uploaded_file($_FILES['tqf5_file']['tmp_name'], $tqf5_path)) {
            $debug_log[] = "move_uploaded_file failed for TQF5";
            $tqf5_path = "";
        }
    } else if (isset($_POST['tqf5_url']) && !empty($_POST['tqf5_url']) && $_POST['tqf5_url'] !== 'undefined') {
        $url = str_replace(' ', '%20', $_POST['tqf5_url']);
        // ไม่สั่ง block กรณีเป็น .doc/.pdf สำหรับ มคอ.5 แล้ว ปล่อยผ่านไปให้ AI ข้ามไปเลย
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $data = @file_get_contents($url, false, $context);
        if($data) {
            $tqf5_path = $upload_dir . 't5_net_' . time() . '.docx';
            if(!file_put_contents($tqf5_path, $data)) {
                 $debug_log[] = "file_put_contents failed for TQF5 net";
                 $tqf5_path = "";
            }
        } else {
             $debug_log[] = "file_get_contents failed for TQF5 URL: $url";
        }
    }
    
    if (empty($tqf3_path)) {
        echo json_encode(['error' => 'การดึงไฟล์ มคอ.3 ล้มเหลว โปรดตรวจสอบลิงก์หรือการอนุญาตเขียนไฟล์ (' . implode(', ', $debug_log).')']);
        exit;
    }
    if ((isset($_POST['tqf5_url']) && !empty($_POST['tqf5_url']) && $_POST['tqf5_url'] !== 'undefined') || (isset($_FILES['tqf5_file']) && $_FILES['tqf5_file']['error'] == 0)) {
        if (empty($tqf5_path)) {
            echo json_encode(['error' => 'พบว่ามีไฟล์ มคอ.5 แนบมา แต่ระบบไม่สามารถดึงไฟล์ได้ โปรดลองอีกครั้ง (' . implode(', ', $debug_log).')']);
            exit;
        }
    }

    if ($api_key === 'mock' || $api_key === '') {
        $mockResult = [
            "check_clo_verb" => 1,
            "check_clo_plo_map" => 1,
            "check_class_activity" => 1,
            "reviewer_comment" => "Mock AI: ทำงานด้วยโหมดจำลอง อ่านไฟล์ ".basename($tqf3_path)." เรียบร้อยแล้ว (ไม่มีการหัก API)",
            "clo_details" => [
                ["clo_code" => "CLO1", "clo_text" => "ประยุกต์ใช้งาน", "bloom_verb" => "ประยุกต์", "bloom_level" => "Apply", "mapped_plos" => "PLO1", "activities" => "Project"]
            ],
            "clo_plo_matrix" => [
                ["clo_code" => "CLO1", "plo_code" => "PLO1", "weight_percentage" => 100.0]
            ]
        ];
        if(file_exists($tqf3_path)) unlink($tqf3_path);
        if(file_exists($tqf5_path)) unlink($tqf5_path);
        echo json_encode(['success' => true, 'data' => $mockResult]);
        exit;
    }

    // Extract Texts แบบตัดคำให้ไม่ยาวเกินไปเพื่อประหยัด Token (Gemini รับได้หลักล้าน แต่กันไว้)
    $tqf3_text = mb_substr(read_docx($tqf3_path), 0, 30000); 
    $tqf5_text = $tqf5_path ? mb_substr(read_docx($tqf5_path), 0, 30000) : "ไม่มีไฟล์ มคอ.5 แนบมา";

    if(empty(trim($tqf3_text)) || strpos($tqf3_text, "ERROR_READ_DOCX") !== false) {
         $sig = @file_get_contents($tqf3_path, false, null, 0, 50);
         echo json_encode(['error' => 'การแกะอักษร มคอ.3 ล้มเหลว (อ่านได้ว่างเปล่า หรือเกิด Error) ลายเซ็นไฟล์: ' . htmlspecialchars($sig) . ' | Error: ' . $tqf3_text]);
         if(file_exists($tqf3_path)) unlink($tqf3_path);
         if(file_exists($tqf5_path)) unlink($tqf5_path);
         exit;
    }

    // Clean up
    if(file_exists($tqf3_path)) unlink($tqf3_path);
    if(file_exists($tqf5_path)) unlink($tqf5_path);

    // Prompt Template
    $prompt = "
    คุณคือผู้เชี่ยวชาญด้านการประกันคุณภาพการศึกษา (AUN-QA) และวิศวกรรมคอมพิวเตอร์
    กรุณาวิเคราะห์เอกสารความยาวด้านล่างซึ่งประกอบด้วย มคอ.3 (Course Specification) และ มคอ.5 (Course Report)
    
    งานของคุณคือ:
    1. ประเมินว่า Course Learning Outcomes (CLO) ใช้คำกริยาแสดงการกระทำตาม Bloom's Taxonomy หรือไม่ (แกน Bloom)
    2. ประเมินว่า CLO และ เนื้อหา ครอบคลุม Program Learning Outcomes (PLO) ที่กำหนดมาทั้งหมดหรือไม่ (แกน PLO-Centric)
    3. ประเมินว่ากิจกรรมการเรียนการสอน ตอบโจทย์ CLO แต่ละตัวหรือไม่ (แกน Activity)
    
    *คำแนะนำสำคัญ*: หากพบว่า PLO ไหนไม่มี CLO มารองรับ หรือกิจกรรมใดไม่สอดคล้อง ให้ระบุ Warning/Suggestion ให้ชัดเจน เพื่อให้อาจารย์ไปแก้ المคอ. ได้ถูกจุด

    *** บังคับ: ส่งผลลัพธ์เป็น JSON เท่านั้น โครงสร้างตามนี้ (ห้ามมี ```json หรือข้อความอื่น) ***
    {
      \"score_bloom\": 85.0,
      \"score_plo\": 70.0,
      \"score_activity\": 90.0,
      \"check_clo_verb\": 1,
      \"check_clo_plo_map\": 1,
      \"check_class_activity\": 1,
      \"reviewer_strength\": \"1. จุดเด่น (Strengths): ...\",
      \"reviewer_improvement\": \"2. จุดที่ควรพัฒนา (Areas for Improvement): ...\",
      \"bloom_analysis\": [
         {\"clo_code\": \"CLO1\", \"clo_text\": \"อธิบาย...\", \"bloom_verb\": \"อธิบาย\", \"bloom_level\": \"Understand\", \"is_appropriate\": 1, \"suggestion\": \"...\"}
      ],
      \"plo_coverage\": [
         {\"plo_code\": \"PLO1\", \"plo_text\": \"...\", \"contributing_clos\": \"CLO1, CLO2\", \"coverage_percent\": 100.0, \"suggestion\": \"...\"}
      ],
      \"activity_mapping\": [
         {\"activity_name\": \"บรรยาย\", \"target_clo\": \"CLO1, CLO2\", \"contribution_percent\": 80.0, \"suggestion\": \"...\"}
      ]
    }
    ";
    
    $content = "--- ข้อมูล มคอ.3 ---\n$tqf3_text\n\n--- ข้อมูล มคอ.5 ---\n$tqf5_text";

    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($api_model) . ":generateContent?key=" . $api_key;
    
    $payload = [
        "contents" => [
            ["parts" => [
                ["text" => $prompt],
                ["text" => $content]
            ]]
        ],
        "generationConfig" => [
            "temperature" => 0.2,
            "responseMimeType" => "application/json"
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    // Disable SSL verify just in case server is old
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    
    $response = curl_exec($ch);
    
    if ($response === false) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        echo json_encode(['error' => 'Failed to connect to Gemini API. Curl Error: ' . $error_msg]);
        exit;
    }
    curl_close($ch);

    $ai_result = json_decode($response, true);
    
    // ดักจับ Error จากฝั่ง Google API โดยตรง (เช่น โควต้าเต็ม 429 หรือ Key ผิด)
    if(isset($ai_result['error'])) {
        $errCode = isset($ai_result['error']['code']) ? $ai_result['error']['code'] : 'Unknown';
        $errMsg = isset($ai_result['error']['message']) ? $ai_result['error']['message'] : '';
        
        if($errCode == 429) {
            $html = "<strong>โควต้าการใช้งาน AI (Free Tier) ของคุณเต็มแล้ว! ⏳</strong><br>" .
                    "กรุณารอสักครู่ (ประมาณ 1 นาที) แล้วกดปุ่มวิเคราะห์ใหม่ หรือถ้าโควต้ารายวันเต็มแล้ว ให้ทำการเปลี่ยน API Key เพื่อใช้งานต่อ<br>" .
                    "<button type='button' class='btn btn-sm btn-outline-danger fw-bold mt-2' data-bs-dismiss='modal' data-bs-toggle='modal' data-bs-target='#settingsModal'><i class='bi bi-key-fill'></i> คลิกเพื่อเปลี่ยน API Key</button>";
            echo json_encode(['error' => $html]);
            exit;
        } else {
            echo json_encode(['error' => "<strong>การเชื่อมต่อ AI ล้มเหลว (Code $errCode):</strong> $errMsg"]);
            exit;
        }
    }

    if(isset($ai_result['candidates'][0]['content']['parts'][0]['text'])) {
        $json_str = $ai_result['candidates'][0]['content']['parts'][0]['text'];
        // Remove code block ticks if AI insists on returning them
        $json_str = str_replace('```json', '', $json_str);
        $json_str = str_replace('```', '', $json_str);
        $json_str = trim($json_str);
        
        $result = json_decode($json_str, true);
        
        if ($result && !isset($result['error'])) {
            // Save to DB
            // Save to DB
            try {
                $stmtUpdateCL = $pdo->prepare("UPDATE aunqa_verification_checklists SET check_clo_verb=:c1, check_clo_plo_map=:c2, check_class_activity=:c3, reviewer_strength=:str, reviewer_improvement=:imp, score_bloom=:s1, score_plo=:s2, score_activity=:s3 WHERE verification_id=:vid");
                $stmtUpdateCL->execute([
                    ':c1' => isset($result['check_clo_verb']) ? $result['check_clo_verb'] : 0,
                    ':c2' => isset($result['check_clo_plo_map']) ? $result['check_clo_plo_map'] : 0,
                    ':c3' => isset($result['check_class_activity']) ? $result['check_class_activity'] : 0,
                    ':str' => isset($result['reviewer_strength']) ? $result['reviewer_strength'] : '',
                    ':imp' => isset($result['reviewer_improvement']) ? $result['reviewer_improvement'] : '',
                    ':s1' => isset($result['score_bloom']) ? $result['score_bloom'] : 0,
                    ':s2' => isset($result['score_plo']) ? $result['score_plo'] : 0,
                    ':s3' => isset($result['score_activity']) ? $result['score_activity'] : 0,
                    ':vid' => $vid
                ]);
                
                $pdo->prepare("DELETE FROM aunqa_verification_bloom WHERE verification_id=:vid")->execute([':vid' => $vid]);
                $pdo->prepare("DELETE FROM aunqa_verification_plo_coverage WHERE verification_id=:vid")->execute([':vid' => $vid]);
                $pdo->prepare("DELETE FROM aunqa_verification_activities WHERE verification_id=:vid")->execute([':vid' => $vid]);
                
                if(isset($result['bloom_analysis']) && is_array($result['bloom_analysis'])) {
                    $stmtBloom = $pdo->prepare("INSERT INTO aunqa_verification_bloom (verification_id, clo_code, clo_text, bloom_verb, bloom_level, is_appropriate, suggestion) VALUES (:vid, :code, :text, :verb, :lvl, :appr, :sugg)");
                    foreach($result['bloom_analysis'] as $c) {
                        $stmtBloom->execute([
                            ':vid' => $vid,
                            ':code' => isset($c['clo_code']) ? $c['clo_code'] : '-',
                            ':text' => isset($c['clo_text']) ? $c['clo_text'] : '-',
                            ':verb' => isset($c['bloom_verb']) ? $c['bloom_verb'] : '-',
                            ':lvl' => isset($c['bloom_level']) ? $c['bloom_level'] : '-',
                            ':appr' => isset($c['is_appropriate']) ? $c['is_appropriate'] : 1,
                            ':sugg' => isset($c['suggestion']) ? $c['suggestion'] : '',
                        ]);
                    }
                }
                if(isset($result['plo_coverage']) && is_array($result['plo_coverage'])) {
                    $stmtPLO = $pdo->prepare("INSERT INTO aunqa_verification_plo_coverage (verification_id, plo_code, plo_text, contributing_clos, coverage_percent, suggestion) VALUES (:vid, :code, :text, :clos, :pct, :sugg)");
                    foreach($result['plo_coverage'] as $p) {
                        $stmtPLO->execute([
                            ':vid' => $vid,
                            ':code' => isset($p['plo_code']) ? $p['plo_code'] : '-',
                            ':text' => isset($p['plo_text']) ? $p['plo_text'] : '-',
                            ':clos' => isset($p['contributing_clos']) ? $p['contributing_clos'] : '-',
                            ':pct' => isset($p['coverage_percent']) ? $p['coverage_percent'] : 0,
                            ':sugg' => isset($p['suggestion']) ? $p['suggestion'] : '',
                        ]);
                    }
                }
                if(isset($result['activity_mapping']) && is_array($result['activity_mapping'])) {
                    $stmtAct = $pdo->prepare("INSERT INTO aunqa_verification_activities (verification_id, activity_name, target_clo, contribution_percent, suggestion) VALUES (:vid, :name, :clo, :pct, :sugg)");
                    foreach($result['activity_mapping'] as $a) {
                        $stmtAct->execute([
                            ':vid' => $vid,
                            ':name' => isset($a['activity_name']) ? $a['activity_name'] : '-',
                            ':clo' => isset($a['target_clo']) ? $a['target_clo'] : '-',
                            ':pct' => isset($a['contribution_percent']) ? $a['contribution_percent'] : 0,
                            ':sugg' => isset($a['suggestion']) ? $a['suggestion'] : '',
                        ]);
                    }
                }
                
                echo json_encode([
                    'success' => true, 
                    'data' => $result,
                    'debug_info' => [
                        'tqf3_len' => mb_strlen($tqf3_text),
                        'tqf3_preview' => mb_substr($tqf3_text, 0, 100)
                    ]
                ]);
            } catch (PDOException $e) {
                echo json_encode(['error' => 'Database update logic failed. Has db_schema.sql been imported correctly? Error: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['error' => 'Invalid JSON logic from AI', 'raw' => $json_str]);
        }
    } else {
        echo json_encode(['error' => 'Bad output from Gemini API', 'raw' => $response]);
    }
}
?>
