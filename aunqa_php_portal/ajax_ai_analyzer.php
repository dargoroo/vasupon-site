<?php
// ajax_ai_analyzer.php - รับไฟล์ TQF มคอ.3 / 5 และยิงให้ AI ประมวลผล (Pure PHP Version สำหรับเว็บโฮสติ้ง)
header('Content-Type: application/json');
require_once __DIR__ . '/bootstrap.php';

try {
    $pdo = app_pdo();
    try {
        $pdo->exec("ALTER TABLE aunqa_verification_activities MODIFY activity_name TEXT NOT NULL");
        $pdo->exec("ALTER TABLE aunqa_verification_activities MODIFY target_clo TEXT NULL");
    } catch (PDOException $e) {
    }
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
            $data = preg_replace('/<w:p\b/u', "\n<w:p", $data);
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

function classify_docx_candidate($filename, $path = '') {
    $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));

    if ($extension === 'pdf') {
        return ['ok' => false, 'kind' => 'pdf', 'message' => 'ไฟล์เป็น PDF'];
    }

    if ($extension === 'doc') {
        return ['ok' => false, 'kind' => 'doc', 'message' => 'ไฟล์เป็น Word รุ่นเก่า (.doc)'];
    }

    if ($extension !== 'docx') {
        return ['ok' => false, 'kind' => 'unknown', 'message' => 'ไฟล์ไม่ใช่ .docx'];
    }

    if ($path !== '' && file_exists($path)) {
        $zip = new ZipArchive;
        $res = $zip->open($path);
        if ($res !== true) {
            return ['ok' => false, 'kind' => 'broken_docx', 'message' => 'ไฟล์ .docx เปิดไม่ได้หรือไฟล์เสีย'];
        }

        $hasDocumentXml = $zip->locateName('word/document.xml') !== false;
        $zip->close();

        if (!$hasDocumentXml) {
            return ['ok' => false, 'kind' => 'broken_docx', 'message' => 'ไฟล์ .docx ไม่มีโครงสร้างเอกสารที่อ่านได้'];
        }
    }

    return ['ok' => true, 'kind' => 'docx', 'message' => 'ok'];
}

function first_non_empty_value($source, $keys, $default = '') {
    if (!is_array($source)) {
        return $default;
    }

    foreach ($keys as $key) {
        if (!isset($source[$key])) {
            continue;
        }

        $value = $source[$key];
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value !== '' && $value !== null) {
            return $value;
        }
    }

    return $default;
}

function clamp_score_percent($value) {
    $value = (float) $value;
    if ($value < 0) {
        return 0.0;
    }
    if ($value > 100) {
        return 100.0;
    }
    return $value;
}

function derive_bloom_score($items) {
    if (!is_array($items) || count($items) === 0) {
        return 0.0;
    }

    $total = 0;
    $passed = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $total++;
        $passed += (isset($item['is_appropriate']) && (int) $item['is_appropriate'] === 1) ? 1 : 0;
    }

    if ($total === 0) {
        return 0.0;
    }

    return round(($passed / $total) * 100, 2);
}

function derive_average_percent_score($items, $key) {
    if (!is_array($items) || count($items) === 0) {
        return 0.0;
    }

    $sum = 0.0;
    $count = 0;
    foreach ($items as $item) {
        if (!is_array($item) || !isset($item[$key])) {
            continue;
        }

        $sum += clamp_score_percent($item[$key]);
        $count++;
    }

    if ($count === 0) {
        return 0.0;
    }

    return round($sum / $count, 2);
}

function build_analysis_quality($context) {
    $score = 100;
    $reasons = [];

    $tqf5SourceKind = isset($context['tqf5_source_kind']) ? (string) $context['tqf5_source_kind'] : 'none';
    $warningsCount = isset($context['warnings_count']) ? (int) $context['warnings_count'] : 0;
    $cloCount = isset($context['clo_eval_count']) ? (int) $context['clo_eval_count'] : 0;
    $usedFallbackOnly = !empty($context['used_fallback_only']);

    if ($tqf5SourceKind === 'legacy_doc') {
        $score -= 35;
        $reasons[] = 'ใช้ไฟล์ มคอ.5 แบบ legacy .doc';
    } else if ($tqf5SourceKind === 'pdf') {
        $score -= 45;
        $reasons[] = 'มคอ.5 เป็น PDF ที่ระบบอ่านเชิงโครงสร้างได้จำกัด';
    } else if ($tqf5SourceKind === 'missing') {
        $score -= 40;
        $reasons[] = 'ไม่มีไฟล์ มคอ.5 ที่อ่านได้';
    }

    if ($usedFallbackOnly) {
        $score -= 15;
        $reasons[] = 'ระบบไม่พบข้อมูล CLO ในรูปแบบตารางหรือ JSON ที่ชัดเจน จึงต้องอ่านจากข้อความดิบทีละบรรทัดและเดาตำแหน่งคอลัมน์แทน ทำให้ความแม่นยำต่ำกว่าปกติ';
    }

    if ($cloCount === 0) {
        $score -= 20;
        $reasons[] = 'ไม่พบข้อมูลผลสัมฤทธิ์ราย CLO ที่ชัดเจน';
    } else if ($cloCount <= 2) {
        $score -= 10;
        $reasons[] = 'พบข้อมูลผลสัมฤทธิ์ราย CLO ค่อนข้างน้อย';
    }

    if ($warningsCount > 0) {
        $score -= min(20, $warningsCount * 5);
    }

    $score = max(5, min(100, $score));
    if ($score >= 80) {
        $label = 'สูง';
        $level = 'high';
    } else if ($score >= 60) {
        $label = 'กลาง';
        $level = 'medium';
    } else {
        $label = 'ต่ำ';
        $level = 'low';
    }

    if (empty($reasons)) {
        $reasons[] = 'มีเอกสารที่อ่านได้ครบและโครงสร้างข้อมูลค่อนข้างชัดเจน';
    }

    return [
        'score' => $score,
        'label' => $label,
        'level' => $level,
        'reasons' => $reasons
    ];
}

function normalize_clo_evaluations($result) {
    $candidate_keys = ['clo_evaluations', 'clo_eval', 'clo_results', 'clo_performance', 'clo_achievement'];
    $raw_items = [];

    foreach ($candidate_keys as $key) {
        if (isset($result[$key]) && is_array($result[$key])) {
            $raw_items = $result[$key];
            break;
        }
    }

    if (empty($raw_items) || !is_array($raw_items)) {
        return [];
    }

    $normalized = [];
    foreach ($raw_items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $normalized[] = [
            'clo_code' => (string) first_non_empty_value($item, ['clo_code', 'clo', 'code'], '-'),
            'clo_description' => (string) first_non_empty_value($item, ['clo_description', 'clo_text', 'description', 'detail'], ''),
            'target_percent' => (string) first_non_empty_value($item, ['target_percent', 'target', 'expected_percent', 'goal_percent'], ''),
            'actual_percent' => (string) first_non_empty_value($item, ['actual_percent', 'actual', 'achieved_percent', 'result_percent'], ''),
            'problem_found' => (string) first_non_empty_value($item, ['problem_found', 'problem', 'issue', 'issues', 'obstacle'], ''),
            'improvement_plan' => (string) first_non_empty_value($item, ['improvement_plan', 'cqi', 'improvement', 'plan', 'recommendation'], '')
        ];
    }

    return $normalized;
}

function cleanup_text_line($text) {
    $text = preg_replace('/\s+/u', ' ', (string) $text);
    return trim($text);
}

function extract_percent_like_values($line) {
    preg_match_all('/\b\d{1,3}(?:\.\d+)?\s*%|\b\d{1,3}(?:\.\d+)?\s*\/\s*\d{1,3}(?:\.\d+)?\b/u', $line, $matches);
    return isset($matches[0]) ? array_values(array_unique(array_map('trim', $matches[0]))) : [];
}

function extract_clo_evaluations_from_text($text) {
    $text = (string) $text;
    if ($text === '') {
        return [];
    }

    $lines = preg_split('/\R/u', $text);
    $results = [];
    $current_index = -1;

    foreach ($lines as $line) {
        $line = cleanup_text_line($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/\b(CLO\s*[-_:]?\s*\d+[A-Za-z]?|ผลลัพธ์การเรียนรู้\s*ที่\s*\d+)\b/ui', $line, $match)) {
            $cloCode = strtoupper(preg_replace('/\s+/u', '', $match[1]));
            $cloCode = str_replace(['ผลลัพธ์การเรียนรู้ที่', 'ผลลัพธ์การเรียนรู้'], 'CLO', $cloCode);

            $row = [
                'clo_code' => $cloCode,
                'clo_description' => '',
                'target_percent' => '',
                'actual_percent' => '',
                'problem_found' => '',
                'improvement_plan' => '',
                '_debug_source' => 'fallback_text_parser',
                '_debug_lines' => [$line]
            ];

            $parts = preg_split('/\s{2,}|[|]/u', $line);
            if (count($parts) > 1) {
                $row['clo_description'] = cleanup_text_line($parts[1]);
            }

            $values = extract_percent_like_values($line);
            if (isset($values[0])) $row['target_percent'] = $values[0];
            if (isset($values[1])) $row['actual_percent'] = $values[1];

            if (preg_match('/(?:ปัญหา|อุปสรรค|ข้อสังเกต)\s*[:：-]?\s*(.+?)(?:แผน|CQI|ปรับปรุง|$)/ui', $line, $m)) {
                $row['problem_found'] = cleanup_text_line($m[1]);
            }
            if (preg_match('/(?:แผนปรับปรุง|ปรับปรุง|CQI|ข้อเสนอแนะ)\s*[:：-]?\s*(.+)$/ui', $line, $m)) {
                $row['improvement_plan'] = cleanup_text_line($m[1]);
            }

            $results[] = $row;
            $current_index = count($results) - 1;
            continue;
        }

        if ($current_index < 0) {
            continue;
        }

        $values = extract_percent_like_values($line);
        if ($results[$current_index]['target_percent'] === '' && isset($values[0])) {
            $results[$current_index]['target_percent'] = $values[0];
        }
        if ($results[$current_index]['actual_percent'] === '') {
            if (isset($values[1])) {
                $results[$current_index]['actual_percent'] = $values[1];
            } else if (isset($values[0]) && $results[$current_index]['target_percent'] !== $values[0]) {
                $results[$current_index]['actual_percent'] = $values[0];
            }
        }

        if ($results[$current_index]['problem_found'] === '' && preg_match('/(?:ปัญหา|อุปสรรค|ข้อสังเกต)\s*[:：-]?\s*(.+)$/ui', $line, $m)) {
            $results[$current_index]['problem_found'] = cleanup_text_line($m[1]);
            $results[$current_index]['_debug_lines'][] = $line;
            continue;
        }

        if ($results[$current_index]['improvement_plan'] === '' && preg_match('/(?:แผนปรับปรุง|ปรับปรุง|CQI|ข้อเสนอแนะ)\s*[:：-]?\s*(.+)$/ui', $line, $m)) {
            $results[$current_index]['improvement_plan'] = cleanup_text_line($m[1]);
            $results[$current_index]['_debug_lines'][] = $line;
            continue;
        }

        if ($results[$current_index]['clo_description'] === '' && !preg_match('/^(?:เป้าหมาย|ผลจริง|ปัญหา|อุปสรรค|แผน|CQI)/ui', $line)) {
            $results[$current_index]['clo_description'] = $line;
            $results[$current_index]['_debug_lines'][] = $line;
        }
    }

    $deduped = [];
    foreach ($results as $row) {
        if ($row['clo_code'] === '-') {
            continue;
        }
        $signature = implode('|', [$row['clo_code'], $row['target_percent'], $row['actual_percent'], $row['problem_found'], $row['improvement_plan']]);
        $deduped[$signature] = $row;
    }

    return array_values($deduped);
}

function merge_clo_evaluations($primary, $fallback) {
    $merged = [];

    foreach (array_merge((array) $primary, (array) $fallback) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $code = isset($item['clo_code']) ? trim((string) $item['clo_code']) : '-';
        if ($code === '') {
            $code = '-';
        }

        if (!isset($merged[$code])) {
            $merged[$code] = [
                'clo_code' => $code,
                'clo_description' => '',
                'target_percent' => '',
                'actual_percent' => '',
                'problem_found' => '',
                'improvement_plan' => '',
                '_debug_sources' => [],
                '_debug_lines' => []
            ];
        }

        $source = isset($item['_debug_source']) ? $item['_debug_source'] : 'ai_json';
        if (!in_array($source, $merged[$code]['_debug_sources'], true)) {
            $merged[$code]['_debug_sources'][] = $source;
        }

        if (isset($item['_debug_lines']) && is_array($item['_debug_lines'])) {
            foreach ($item['_debug_lines'] as $debugLine) {
                $debugLine = cleanup_text_line($debugLine);
                if ($debugLine !== '' && !in_array($debugLine, $merged[$code]['_debug_lines'], true)) {
                    $merged[$code]['_debug_lines'][] = $debugLine;
                }
            }
        }

        foreach (['clo_description', 'target_percent', 'actual_percent', 'problem_found', 'improvement_plan'] as $field) {
            if ($merged[$code][$field] === '' && !empty($item[$field])) {
                $merged[$code][$field] = cleanup_text_line($item[$field]);
            }
        }
    }

    return array_values($merged);
}

function build_clo_debug_entries($merged, $fallback_only) {
    $entries = [];

    foreach ((array) $merged as $item) {
        if (!is_array($item)) {
            continue;
        }

        $entries[] = [
            'clo_code' => isset($item['clo_code']) ? $item['clo_code'] : '-',
            'sources' => isset($item['_debug_sources']) ? array_values($item['_debug_sources']) : [],
            'lines' => isset($item['_debug_lines']) ? array_values($item['_debug_lines']) : [],
            'parsed' => [
                'target_percent' => isset($item['target_percent']) ? $item['target_percent'] : '',
                'actual_percent' => isset($item['actual_percent']) ? $item['actual_percent'] : '',
                'problem_found' => isset($item['problem_found']) ? $item['problem_found'] : '',
                'improvement_plan' => isset($item['improvement_plan']) ? $item['improvement_plan'] : ''
            ]
        ];
    }

    return [
        'merged_count' => count($entries),
        'fallback_candidates' => array_values((array) $fallback_only),
        'entries' => $entries
    ];
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

        $stmtCLO = $pdo->prepare("SELECT * FROM aunqa_clo_evaluations WHERE verification_id = :v");
        $stmtCLO->execute([':v' => $vid]);
        $clo_evals = $stmtCLO->fetchAll(PDO::FETCH_ASSOC);
        
        // global scores are in checklist table
        $stmtChk = $pdo->prepare("SELECT score_bloom, score_plo, score_activity, pdca_status, pdca_resolution_percent, pdca_last_year_summary, pdca_current_action, pdca_evidence_note FROM aunqa_verification_checklists WHERE verification_id = :v");
        $stmtChk->execute([':v' => $vid]);
        $scores = $stmtChk->fetch(PDO::FETCH_ASSOC);

        $stmtPdca = $pdo->prepare("SELECT id, issue_category, category_confidence, category_reason, category_inferred_by, issue_title, issue_detail, current_status, resolution_percent FROM aunqa_pdca_issues WHERE verification_id = :v ORDER BY updated_at DESC, id DESC");
        $stmtPdca->execute([':v' => $vid]);
        $pdcaIssues = $stmtPdca->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'bloom_analysis' => $bloom,
            'plo_coverage' => $plo,
            'activity_mapping' => $activities,
            'clo_evals' => $clo_evals,
            'global_scores' => (empty($scores) ? ['score_bloom'=>0, 'score_plo'=>0, 'score_activity'=>0] : $scores),
            'pdca_summary' => (empty($scores) ? ['pdca_status'=>'not_started', 'pdca_resolution_percent'=>0, 'pdca_last_year_summary'=>'', 'pdca_current_action'=>'', 'pdca_evidence_note'=>''] : $scores),
            'pdca_issues' => $pdcaIssues
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage() . '. Did you run the updated db_schema.sql?'
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_available_models') {
    $api_key = '';
    try {
        $stmt = $pdo->query("SELECT setting_value FROM aunqa_settings WHERE setting_key = 'gemini_api_key'");
        if ($stmt) {
            $apiKeyRow = $stmt->fetch();
            $api_key = $apiKeyRow ? $apiKeyRow['setting_value'] : '';
        }
    } catch (PDOException $e) { $api_key = ''; }

    if (empty($api_key) || $api_key === 'mock') {
         echo json_encode(['success' => true, 'models' => [['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash (Mock)']]]);
         exit;
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $api_key;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
         echo json_encode(['success' => false, 'error' => 'HTTP '. $httpCode . ' (' . $response . ')', 'models' => [['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash (Fallback)']]]);
         exit;
    }

    $data = json_decode($response, true);
    $generate_models = [];

    if (isset($data['models'])) {
        foreach($data['models'] as $m) {
            if (strpos($m['name'], 'generateContent') !== false || strpos($m['supportedGenerationMethods'][0] ?? '', 'generateContent') !== false) {
                 if (strpos($m['name'], 'gemini-1.5') !== false || strpos($m['name'], 'gemini-2.0') !== false || strpos($m['name'], 'gemini-2.5') !== false || strpos($m['name'], 'gemini-3.') !== false) {
                     $generate_models[] = $m;
                 }
            }
        }
    }

    if (empty($generate_models)) {
         echo json_encode(['success' => true, 'models' => [['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash (Default)']]]);
         exit;
    }

    echo json_encode(['success' => true, 'models' => $generate_models]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'run_ai') {
    $vid = $_POST['verification_id'];
    
    // ดึง API Key
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM aunqa_settings WHERE setting_key IN ('gemini_api_key', 'gemini_api_model', 'ai_auto_pass_threshold')");
    $settings = [];
    while($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $api_key = isset($settings['gemini_api_key']) ? $settings['gemini_api_key'] : 'mock';
    $api_model = isset($settings['gemini_api_model']) ? $settings['gemini_api_model'] : 'gemini-2.5-flash';
    $auto_pass_threshold = isset($settings['ai_auto_pass_threshold']) ? (int) $settings['ai_auto_pass_threshold'] : 80;
    if ($auto_pass_threshold < 0) $auto_pass_threshold = 0;
    if ($auto_pass_threshold > 100) $auto_pass_threshold = 100;

    // ใช้ System Temp Directory แทนเพื่อหลีกเลี่ยงปัญหา Permission (CHMOD) บนโฮสติ้ง
    $upload_dir = rtrim(sys_get_temp_dir(), '/\\') . '/';
    
    $tqf3_path = "";
    $tqf5_path = "";
    $debug_log = [];
    $warnings = [];
    $tqf5_source_kind = 'missing';
    
    if (isset($_FILES['tqf3_file']) && $_FILES['tqf3_file']['error'] == 0) {
        $fileCheck = classify_docx_candidate($_FILES['tqf3_file']['name'], $_FILES['tqf3_file']['tmp_name']);
        if(!$fileCheck['ok']) {
            echo json_encode(['error' => "ขออัย ระบบรองรับเฉพาะไฟล์ .docx สำหรับ มคอ.3 เท่านั้น: {$fileCheck['message']} โปรดแปลงไฟล์เป็น .docx แล้วอัปโหลดใหม่ครับ"]);
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
            echo json_encode(['error' => "ขออัย ลิงก์ มคอ.3 ในระบบเป็นไฟล์ .$ext (อ่านไม่ได้) โปรดดาวน์โหลดไฟล์มาแปลงเป็น .docx แล้วใช้ปุ่มอัปโหลดด้วยตนเองครับ"]);
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
        } else {
            $fileCheck = classify_docx_candidate($_FILES['tqf5_file']['name'], $tqf5_path);
            if(!$fileCheck['ok']) {
                if ($fileCheck['kind'] === 'doc') {
                    $tqf5_source_kind = 'legacy_doc';
                } else if ($fileCheck['kind'] === 'pdf') {
                    $tqf5_source_kind = 'pdf';
                }
                $warnings[] = "มคอ.5 ถูกข้ามอัตโนมัติ: {$fileCheck['message']} กรุณาใช้ .docx หากต้องการผลวิเคราะห์เชิงลึกระดับ CLO";
                if(file_exists($tqf5_path)) unlink($tqf5_path);
                $tqf5_path = "";
            } else {
                $tqf5_source_kind = 'docx';
            }
        }
    } else if (isset($_POST['tqf5_url']) && !empty($_POST['tqf5_url']) && $_POST['tqf5_url'] !== 'undefined') {
        $url = str_replace(' ', '%20', $_POST['tqf5_url']);
        if(preg_match('/\.(doc|pdf)$/i', $url, $matches)) {
            $ext = strtolower($matches[1]);
            $tqf5_source_kind = $ext === 'doc' ? 'legacy_doc' : 'pdf';
            $warnings[] = "มคอ.5 จากลิงก์ระบบเป็น .$ext ระบบจะข้ามไฟล์นี้อัตโนมัติเพื่อป้องกันการวิเคราะห์ล้มเหลว";
        } else {
            $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $data = @file_get_contents($url, false, $context);
            if($data) {
                $tqf5_path = $upload_dir . 't5_net_' . time() . '.docx';
                if(!file_put_contents($tqf5_path, $data)) {
                     $debug_log[] = "file_put_contents failed for TQF5 net";
                     $tqf5_path = "";
                } else {
                    $fileCheck = classify_docx_candidate(basename($url), $tqf5_path);
                    if(!$fileCheck['ok']) {
                        if ($fileCheck['kind'] === 'doc') {
                            $tqf5_source_kind = 'legacy_doc';
                        } else if ($fileCheck['kind'] === 'pdf') {
                            $tqf5_source_kind = 'pdf';
                        }
                        $warnings[] = "มคอ.5 จากลิงก์ระบบถูกข้ามอัตโนมัติ: {$fileCheck['message']}";
                        if(file_exists($tqf5_path)) unlink($tqf5_path);
                        $tqf5_path = "";
                    } else {
                        $tqf5_source_kind = 'docx';
                    }
                }
            } else {
                 $debug_log[] = "file_get_contents failed for TQF5 URL: $url";
            }
        }
    }
    
    if (empty($tqf3_path)) {
        echo json_encode(['error' => 'การดึงไฟล์ มคอ.3 ล้มเหลว โปรดตรวจสอบลิงก์หรือการอนุญาตเขียนไฟล์ (' . implode(', ', $debug_log).')']);
        exit;
    }
    if ((isset($_POST['tqf5_url']) && !empty($_POST['tqf5_url']) && $_POST['tqf5_url'] !== 'undefined') || (isset($_FILES['tqf5_file']) && $_FILES['tqf5_file']['error'] == 0)) {
        if (empty($tqf5_path)) {
            if (empty($warnings)) {
                $warnings[] = 'พบว่ามีไฟล์ มคอ.5 แนบมา แต่ระบบไม่สามารถอ่านได้ จึงข้ามการวิเคราะห์ มคอ.5 เพื่อป้องกันระบบล้มเหลว';
            }
        }
    }

    if ($api_key === 'mock' || $api_key === '') {
        $mockResult = [
            "check_clo_verb" => 1,
            "check_clo_plo_map" => 1,
            "check_class_activity" => 1,
            "score_bloom" => 100.0,
            "score_plo" => 100.0,
            "score_activity" => 100.0,
            "reviewer_comment" => "Mock AI: ทำงานด้วยโหมดจำลอง อ่านไฟล์ ".basename($tqf3_path)." เรียบร้อยแล้ว (ไม่มีการหัก API)",
            "clo_details" => [
                ["clo_code" => "CLO1", "clo_text" => "ประยุกต์ใช้งาน", "bloom_verb" => "ประยุกต์", "bloom_level" => "Apply", "mapped_plos" => "PLO1", "activities" => "Project"]
            ],
            "clo_plo_matrix" => [
                ["clo_code" => "CLO1", "plo_code" => "PLO1", "weight_percentage" => 100.0]
            ],
            "clo_evaluations" => [
                [
                    "clo_code" => "CLO1",
                    "clo_description" => "ประยุกต์ใช้งานความรู้เพื่อพัฒนาโครงงาน",
                    "target_percent" => "70%",
                    "actual_percent" => "82%",
                    "problem_found" => "นักศึกษาบางส่วนยังสรุปเหตุผลเชิงเทคนิคได้ไม่ชัดเจน",
                    "improvement_plan" => "เพิ่ม rubric และกิจกรรม formative feedback ก่อนส่งงานใหญ่"
                ]
            ]
        ];
        if(file_exists($tqf3_path)) unlink($tqf3_path);
        if(file_exists($tqf5_path)) unlink($tqf5_path);
        echo json_encode(['success' => true, 'data' => $mockResult, 'warnings' => $warnings]);
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

    if ($tqf5_path && (empty(trim($tqf5_text)) || strpos($tqf5_text, "ERROR_READ_DOCX") !== false)) {
        $warnings[] = 'ระบบข้ามการอ่าน มคอ.5 อัตโนมัติ เนื่องจากไฟล์ไม่ใช่ .docx ที่อ่านได้หรือไฟล์เสีย';
        $tqf5_text = "ไม่มีไฟล์ มคอ.5 แนบมาที่อ่านได้";
        if ($tqf5_source_kind === 'docx') {
            $tqf5_source_kind = 'missing';
        }
    }

    // Clean up
    if(file_exists($tqf3_path)) unlink($tqf3_path);
    if(file_exists($tqf5_path)) unlink($tqf5_path);

    // Prompt Template
    $prompt = "
    คุณคือผู้เชี่ยวชาญด้านการประกันคุณาพการศึกษา (AUN-QA) และวิศวกรรมคอมพิวเตอร์
    กรุณาวิเคราะห์เอกสารความยาวด้านล่างซึ่งประกอบด้วย มคอ.3 (Course Specification) และ มคอ.5 (Course Report)
    
    งานของคุณคือ:
    1. ประเมินว่า Course Learning Outcomes (CLO) ใช้คำกริยาแสดงการกระทำตาม Bloom's Taxonomy หรือไม่ (แกน Bloom)
    2. ประเมินว่า CLO และ เนื้อหา ครอบคลุม Program Learning Outcomes (PLO) ที่กำหนดมาทั้งหมดหรือไม่ (แกน PLO-Centric)
    3. ประเมินว่ากิจกรรมการเรียนการสอน ตอบโจทย์ CLO แต่ละตัวหรือไม่ (แกน Activity)
    4. ถ้าใน มคอ.5 มีข้อมูลผลสัมฤทธิ์ราย CLO, ค่าเป้าหมาย, ค่าที่ทำได้จริง, ปัญหา/อุปสรรค หรือแผนปรับปรุง ให้สรุปแยกเป็นรายข้อใน clo_evaluations
    
    *คำแนะนำสำคัญ*: หากพบว่า PLO ไหนไม่มี CLO มารองรับ หรือกิจกรรมใดไม่สอดคล้อง ให้ระบุ Warning/Suggestion ให้ชัดเจน เพื่อให้อาจารย์ไปแก้ มคอ. ได้ถูกจุด
    *คำแนะนำสำคัญสำหรับข้อ 4*: พยายามสกัดข้อมูลจาก มคอ.5 ให้มากที่สุด แม้หัวตารางจะไม่ได้ใช้คำว่า CLO ตรงตัวแต่ถ้าสื่อว่าเป็นผลสัมฤทธิ์รายผลลัพธ์การเรียนรู้ก็ให้นับรวม ถ้าไม่มีข้อมูลจริง ๆ ให้ส่ง clo_evaluations เป็น [] เท่านั้น ห้ามเดา
    *บังคับเรื่องชื่อคีย์*: ต้องส่ง key ชื่อ clo_evaluations เท่านั้นสำหรับตารางข้อ 4 ห้ามเปลี่ยนชื่อ key
    *รูปแบบค่า*: target_percent และ actual_percent ให้ส่งเป็นข้อความสั้น ๆ เช่น 70% หรือ 18/25 ไม่ต้องอธิบายยาวในช่องนี้
    *Heuristic ที่ต้องใช้ก่อนสรุปข้อ 4*:
    - มองหาคำที่มีความหมายใกล้เคียงกับ Target/Actual เช่น ค่าเป้าหมาย, เกณฑ์, คาดหวัง, ผลจริง, ผลที่ทำได้, ร้อยละ, ระดับการบรรลุ
    - มองหาคอลัมน์ปัญหาในชื่ออื่น เช่น ปัญหา, อุปสรรค, สาเหตุ, ประเด็นที่พบ, ข้อสังเกต
    - มองหาคอลัมน์แผนในชื่ออื่น เช่น CQI, แนวทางพัฒนา, การปรับปรุง, แผนแก้ไข, ข้อเสนอแนะ
    - ถ้าตารางไม่มีหัวชัดเจน แต่มีบรรทัดที่ขึ้นต้นด้วย CLO1, CLO2, CLO3 หรือ ผลลัพธ์การเรียนรู้ที่ 1, 2, 3 ให้ถือว่าเป็นแถวข้อมูล
    - ถ้ามีตัวเลข 2 ค่าใกล้รหัส CLO ให้ตีความค่าแรกเป็น target_percent และค่าถัดไปเป็น actual_percent ก่อน
    - ถ้าพบเฉพาะ actual หรือเฉพาะ target ให้กรอกเท่าที่พบและปล่อยอีกช่องเป็นค่าว่าง
    - ห้ามละทิ้งข้อมูลเพียงเพราะหัวตารางไม่มาตรฐาน
    *กฎเพื่อลดความสวิงของผลลัพธ์*:
    - หากข้อมูลต้นทางเหมือนเดิม ให้ตอบผลลัพธ์เดิมให้มากที่สุด ห้ามสุ่มหรือปรับคะแนนขึ้นลงโดยไม่มีหลักฐานใหม่
    - ใช้เฉพาะข้อความที่พบชัดในเอกสาร ห้ามเดาความหมายจากบริบทกว้างเกินไป
    - ถ้าหลักฐานไม่พอ ให้ระบุ suggestion ว่า \"ไม่พบข้อมูลชัดเจนในเอกสาร\" แทนการเดา
    - ค่าในช่องที่ไม่พบจริง ให้ส่งเป็นค่าว่าง \"\" ไม่ใช่ข้อความอื่น
    *กติกาการคิดคะแนนแบบตายตัว*:
    - score_bloom ให้สอดคล้องกับสัดส่วน CLO ที่ is_appropriate = 1 ใน bloom_analysis
    - score_plo ให้สอดคล้องกับค่า coverage_percent โดยรวมใน plo_coverage
    - score_activity ให้สอดคล้องกับค่า contribution_percent โดยรวมใน activity_mapping
    - อย่าตั้ง score สูงหรือต่ำแบบกว้างเกินหลักฐานที่ส่งในตารางรายข้อ

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
      ],
      \"clo_evaluations\": [
         {\"clo_code\": \"CLO1\", \"clo_description\": \"...\", \"target_percent\": \"70%\", \"actual_percent\": \"65%\", \"problem_found\": \"...\", \"improvement_plan\": \"...\"}
      ]
    }
    ";
    
    $content = "--- ข้อมูล มคอ.3 ---\n$tqf3_text\n\n--- ข้อมูล มคอ.5 ---\n$tqf5_text";

    $api_model = str_replace('models/', '', $api_model);
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($api_model) . ":generateContent?key=" . $api_key;
    
    $payload = [
        "contents" => [
            ["parts" => [
                ["text" => $prompt],
                ["text" => $content]
            ]]
        ],
        "generationConfig" => [
            "temperature" => 0.0,
            "responseMimeType" => "application/json"
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    $json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    if(!$json_payload) { echo json_encode(['error' => 'API payload compile failed: ' . json_last_error_msg()]); exit; }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
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
            $ai_clo_evaluations = normalize_clo_evaluations($result);
            $result['clo_evaluations'] = $ai_clo_evaluations;
            $fallback_clo_evaluations = extract_clo_evaluations_from_text($tqf5_text);
            $result['clo_evaluations'] = merge_clo_evaluations($result['clo_evaluations'], $fallback_clo_evaluations);
            $clo_debug_info = build_clo_debug_entries($result['clo_evaluations'], $fallback_clo_evaluations);
            $analysis_quality = build_analysis_quality([
                'tqf5_source_kind' => $tqf5_source_kind,
                'warnings_count' => count($warnings),
                'clo_eval_count' => count($result['clo_evaluations']),
                'used_fallback_only' => empty($ai_clo_evaluations) && !empty($fallback_clo_evaluations)
            ]);
            $scoreBloom = derive_bloom_score(isset($result['bloom_analysis']) ? $result['bloom_analysis'] : []);
            $scorePlo = derive_average_percent_score(isset($result['plo_coverage']) ? $result['plo_coverage'] : [], 'coverage_percent');
            $scoreActivity = derive_average_percent_score(isset($result['activity_mapping']) ? $result['activity_mapping'] : [], 'contribution_percent');
            $result['score_bloom'] = $scoreBloom;
            $result['score_plo'] = $scorePlo;
            $result['score_activity'] = $scoreActivity;
            $result['check_clo_verb'] = $scoreBloom >= $auto_pass_threshold ? 1 : 0;
            $result['check_clo_plo_map'] = $scorePlo >= $auto_pass_threshold ? 1 : 0;
            $result['check_class_activity'] = $scoreActivity >= $auto_pass_threshold ? 1 : 0;

            // Save to DB
            // Save to DB
            try {
                $stmtUpdateCL = $pdo->prepare("UPDATE aunqa_verification_checklists SET check_clo_verb=:c1, check_clo_plo_map=:c2, check_class_activity=:c3, reviewer_strength=:str, reviewer_improvement=:imp, score_bloom=:s1, score_plo=:s2, score_activity=:s3, pdca_followup=:pdc WHERE verification_id=:vid");
                $stmtUpdateCL->execute([
                    ':c1' => $result['check_clo_verb'],
                    ':c2' => $result['check_clo_plo_map'],
                    ':c3' => $result['check_class_activity'],
                    ':str' => isset($result['reviewer_strength']) ? $result['reviewer_strength'] : '',
                    ':imp' => isset($result['reviewer_improvement']) ? $result['reviewer_improvement'] : '',
                    ':s1' => $scoreBloom,
                    ':s2' => $scorePlo,
                    ':s3' => $scoreActivity,
                    ':pdc' => isset($result['pdca_followup']) ? $result['pdca_followup'] : '',
                    ':vid' => $vid
                ]);
                
                $pdo->prepare("DELETE FROM aunqa_verification_bloom WHERE verification_id=:vid")->execute([':vid' => $vid]);
                $pdo->prepare("DELETE FROM aunqa_verification_plo_coverage WHERE verification_id=:vid")->execute([':vid' => $vid]);
                $pdo->prepare("DELETE FROM aunqa_clo_evaluations WHERE verification_id=:vid")->execute([':vid' => $vid]);
                if(isset($result['clo_evaluations']) && is_array($result['clo_evaluations'])) {
                    $stmtCLO = $pdo->prepare("INSERT INTO aunqa_clo_evaluations (verification_id, clo_code, clo_description, target_percent, actual_percent, problem_found, improvement_plan) VALUES (:vid, :code, :desc, :tgt, :act, :prob, :imp)");
                    foreach($result['clo_evaluations'] as $ce) {
                        $stmtCLO->execute([
                            ':vid' => $vid,
                            ':code' => isset($ce['clo_code']) ? $ce['clo_code'] : '-',
                            ':desc' => isset($ce['clo_description']) ? $ce['clo_description'] : '',
                            ':tgt' => isset($ce['target_percent']) ? (string)$ce['target_percent'] : '',
                            ':act' => isset($ce['actual_percent']) ? (string)$ce['actual_percent'] : '',
                            ':prob' => isset($ce['problem_found']) ? $ce['problem_found'] : '',
                            ':imp' => isset($ce['improvement_plan']) ? $ce['improvement_plan'] : ''
                        ]);
                    }
                }

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
                    'warnings' => $warnings,
                    'debug_info' => [
                        'auto_pass_threshold' => $auto_pass_threshold,
                        'analysis_quality' => $analysis_quality,
                        'tqf5_source_kind' => $tqf5_source_kind,
                        'tqf3_len' => mb_strlen($tqf3_text),
                        'tqf3_preview' => mb_substr($tqf3_text, 0, 100),
                        'clo_parser' => $clo_debug_info
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
