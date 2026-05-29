<?php

require_once dirname(__DIR__) . '/bootstrap.php';

$pdo = null;
$moduleId = 0;
$actorUserId = 0;
$ownerUserId = 0;
$teacherPrompt = '';
$promptLength = 0;

try {
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    graderapp_json_response([
        'ok' => false,
        'error' => 'Method not allowed',
    ], 405);
}

$state = graderapp_bootstrap_state();
$pdo = $state['pdo'];

if (!$state['ok'] || !$pdo) {
    graderapp_json_response([
        'ok' => false,
        'error' => 'ยังไม่พร้อมใช้งานฐานข้อมูลของ Grader App',
    ], 500);
}

if (!graderapp_is_authenticated()) {
    graderapp_json_response([
        'ok' => false,
        'error' => 'กรุณาเข้าสู่ระบบก่อนใช้งาน AI draft',
    ], 401);
}

$currentUser = graderapp_current_user($pdo);
if (!$currentUser) {
    graderapp_json_response([
        'ok' => false,
        'error' => 'ไม่พบข้อมูลผู้ใช้ที่ login อยู่',
    ], 401);
}

if (!function_exists('curl_init')) {
    graderapp_json_response([
        'ok' => false,
        'error' => 'Server ยังไม่เปิดใช้งาน cURL จึงเรียก AI draft ไม่ได้',
    ], 500);
}

$payload = graderapp_json_input();
$moduleId = (int) ($payload['module_id'] ?? 0);
$teacherPrompt = trim((string) ($payload['teacher_prompt'] ?? ''));
$promptLength = function_exists('mb_strlen') ? mb_strlen($teacherPrompt) : strlen($teacherPrompt);
$returnTo = trim((string) ($payload['return_to'] ?? ''));
$draftDefaultsPayload = isset($payload['draft_defaults']) && is_array($payload['draft_defaults'])
    ? $payload['draft_defaults']
    : [];

$draftDefaults = [
    'language' => trim((string) ($draftDefaultsPayload['language'] ?? 'python')) ?: 'python',
    'sort_order_start' => max(0, (int) ($draftDefaultsPayload['sort_order_start'] ?? 0)),
    'time_limit_sec' => max(0.1, (float) ($draftDefaultsPayload['time_limit_sec'] ?? 2)),
    'memory_limit_mb' => max(16, (int) ($draftDefaultsPayload['memory_limit_mb'] ?? 128)),
    'max_score' => max(1, (int) ($draftDefaultsPayload['max_score'] ?? 100)),
    'visibility' => in_array((string) ($draftDefaultsPayload['visibility'] ?? 'draft'), ['draft', 'published', 'archived'], true)
        ? (string) ($draftDefaultsPayload['visibility'] ?? 'draft')
        : 'draft',
];

$normalizeThaiNumberToken = static function (string $value): int {
    $normalized = strtr($value, [
        '๐' => '0',
        '๑' => '1',
        '๒' => '2',
        '๓' => '3',
        '๔' => '4',
        '๕' => '5',
        '๖' => '6',
        '๗' => '7',
        '๘' => '8',
        '๙' => '9',
    ]);
    return (int) $normalized;
};

$inferProblemCount = static function (string $text) use ($normalizeThaiNumberToken): ?int {
    if (preg_match('/(?:จำนวน|ทั้งหมด|รวม)\s*([0-9๐-๙]+)\s*ข้อ/u', $text, $matches)) {
        return max(1, $normalizeThaiNumberToken((string) ($matches[1] ?? '0')));
    }

    if (preg_match_all('/([0-9๐-๙]+)\s*(?:ข้อ|โจทย์|ปัญหา)(?!\s*(?:test\s*cases?|เคส|ชุดทดสอบ))/iu', $text, $matches) && !empty($matches[1])) {
        $counts = array_map(
            static fn($value): int => max(0, $normalizeThaiNumberToken((string) $value)),
            $matches[1]
        );
        $counts = array_values(array_filter($counts, static fn(int $value): bool => $value > 0));
        if (count($counts) > 1) {
            return max(1, array_sum($counts));
        }
        if (count($counts) === 1) {
            return max(1, (int) $counts[0]);
        }
    }

    if (preg_match('/([0-9๐-๙]+)\s*(?:problems?|questions?)/iu', $text, $matches)) {
        return max(1, $normalizeThaiNumberToken((string) ($matches[1] ?? '0')));
    }
    return null;
};

$inferProblemBreakdown = static function (string $text) use ($normalizeThaiNumberToken): array {
    if (!preg_match_all('/([^,;\n\r]+?)\s*([0-9๐-๙]+)\s*(?:ข้อ|โจทย์|ปัญหา)(?!\s*(?:test\s*cases?|เคส|ชุดทดสอบ))/iu', $text, $matches, PREG_SET_ORDER)) {
        return [];
    }

    $items = [];
    foreach ($matches as $match) {
        $count = max(0, $normalizeThaiNumberToken((string) ($match[2] ?? '0')));
        if ($count <= 0) {
            continue;
        }

        $topic = trim((string) ($match[1] ?? ''));
        $topic = preg_replace('/^(?:และ|กับ|,|;|\s)+/u', '', $topic) ?? $topic;
        $topic = preg_replace('/^(?:อยากได้|ขอ|สร้าง|ให้|โจทย์|เรื่อง)\s*/u', '', $topic) ?? $topic;
        $topic = trim($topic);
        if ($topic === '') {
            $topic = 'หัวข้อที่อาจารย์ระบุ';
        }

        $items[] = [
            'topic' => $topic,
            'count' => $count,
        ];
    }

    return $items;
};

$inferCaseCount = static function (string $text) use ($normalizeThaiNumberToken): ?int {
    if (preg_match('/(?:มี|อย่างน้อย|รวม)\s*([0-9๐-๙]+)\s*test\s*cases?/iu', $text, $matches)) {
        return max(1, $normalizeThaiNumberToken((string) ($matches[1] ?? '0')));
    }
    if (preg_match('/(?:มี|อย่างน้อย|รวม)\s*([0-9๐-๙]+)\s*(?:เคส|ชุดทดสอบ)/u', $text, $matches)) {
        return max(1, $normalizeThaiNumberToken((string) ($matches[1] ?? '0')));
    }
    if (preg_match('/([0-9๐-๙]+)\s*test\s*cases?/iu', $text, $matches)) {
        return max(1, $normalizeThaiNumberToken((string) ($matches[1] ?? '0')));
    }
    if (preg_match('/([0-9๐-๙]+)\s*(?:เคส|ชุดทดสอบ)/u', $text, $matches)) {
        return max(1, $normalizeThaiNumberToken((string) ($matches[1] ?? '0')));
    }
    return null;
};

$requestedProblemCount = $inferProblemCount($teacherPrompt);
$requestedCaseCount = $inferCaseCount($teacherPrompt);
$requestedProblemBreakdown = $inferProblemBreakdown($teacherPrompt);
if ($requestedProblemCount === null && count($requestedProblemBreakdown) > 1) {
    $requestedProblemCount = array_sum(array_map(static fn(array $item): int => (int) ($item['count'] ?? 0), $requestedProblemBreakdown));
}

if ($moduleId <= 0) {
    graderapp_json_response([
        'ok' => false,
        'error' => 'กรุณาเลือก Session ก่อนให้ AI ช่วยร่างโจทย์',
    ], 422);
}

if ($teacherPrompt === '') {
    graderapp_json_response([
        'ok' => false,
        'error' => 'กรุณากรอกข้อความบอก AI ก่อน',
    ], 422);
}

$moduleStmt = $pdo->prepare("
    SELECT
        m.id,
        m.title,
        m.description,
        m.sort_order,
        m.default_problem_score,
        m.default_runtime_profile_id,
        c.id AS course_id,
        c.owner_user_id,
        c.course_code,
        c.course_name
    FROM grader_modules m
    INNER JOIN grader_courses c ON c.id = m.course_id
    WHERE m.id = :id
    LIMIT 1
");
$moduleStmt->execute([':id' => $moduleId]);
$module = $moduleStmt->fetch(PDO::FETCH_ASSOC);

if (!$module) {
    graderapp_json_response([
        'ok' => false,
        'error' => 'ไม่พบ Session ที่ต้องการใช้งาน',
    ], 404);
}

$moduleDefaultProblemScore = max(1, (int) ($module['default_problem_score'] ?? 100));
if (!array_key_exists('max_score', $draftDefaultsPayload) || (int) ($draftDefaultsPayload['max_score'] ?? 0) <= 0) {
    $draftDefaults['max_score'] = $moduleDefaultProblemScore;
}
$moduleDefaultRuntimeProfileId = graderapp_resolve_runtime_profile_id(
    $pdo,
    $module['default_runtime_profile_id'] ?? '',
    graderapp_default_runtime_profile_id($pdo)
);
$draftDefaults['runtime_profile_id'] = graderapp_resolve_runtime_profile_id(
    $pdo,
    $draftDefaultsPayload['runtime_profile_id'] ?? $draftDefaultsPayload['runtime_profile'] ?? $draftDefaultsPayload['runtime_profile_key'] ?? '',
    $moduleDefaultRuntimeProfileId
);

$ownerUserId = (int) ($module['owner_user_id'] ?? 0);
if ($ownerUserId <= 0) {
    graderapp_json_response([
        'ok' => false,
        'error' => 'Session นี้ยังไม่มีเจ้าของวิชา จึงยังระบุ AI key รายคนไม่ได้',
    ], 422);
}

$actorUserId = (int) $currentUser['id'];
if ($actorUserId !== $ownerUserId) {
    graderapp_ai_log_usage($pdo, [
        'actor_user_id' => $actorUserId,
        'owner_user_id' => $ownerUserId,
        'course_id' => (int) $module['course_id'],
        'module_id' => $moduleId,
        'provider' => 'gemini',
        'model_name' => '',
        'request_type' => 'problem_draft',
        'prompt_chars' => $promptLength,
        'response_status' => 'forbidden',
        'error_message' => 'Only the course owner can use AI draft for this session',
    ]);
    graderapp_json_response([
        'ok' => false,
        'error' => 'AI draft ใช้ได้เฉพาะเจ้าของวิชาหรือ Session นี้เท่านั้น',
    ], 403);
}

$aiSettings = graderapp_ai_settings_for_user($pdo, $ownerUserId);
if ($aiSettings['provider'] !== 'gemini') {
    graderapp_ai_log_usage($pdo, [
        'actor_user_id' => $actorUserId,
        'owner_user_id' => $ownerUserId,
        'course_id' => (int) $module['course_id'],
        'module_id' => $moduleId,
        'provider' => (string) $aiSettings['provider'],
        'model_name' => (string) $aiSettings['api_model'],
        'request_type' => 'problem_draft',
        'prompt_chars' => $promptLength,
        'response_status' => 'unsupported_provider',
        'error_message' => 'Only Gemini is supported',
    ]);
    graderapp_json_response([
        'ok' => false,
        'error' => 'ตอนนี้รองรับ AI provider แบบ Gemini เท่านั้น',
    ], 422);
}

if ($aiSettings['api_key'] === '') {
    $redirectUrl = graderapp_path('grader.ai.settings');
    if ($returnTo !== '') {
        $redirectUrl = graderapp_path('grader.ai.settings', ['return_to' => $returnTo]);
    }
    graderapp_ai_log_usage($pdo, [
        'actor_user_id' => $actorUserId,
        'owner_user_id' => $ownerUserId,
        'course_id' => (int) $module['course_id'],
        'module_id' => $moduleId,
        'provider' => (string) $aiSettings['provider'],
        'model_name' => (string) $aiSettings['api_model'],
        'request_type' => 'problem_draft',
        'prompt_chars' => $promptLength,
        'response_status' => 'missing_api_key',
        'error_message' => 'Owner has no personal Gemini API key configured',
    ]);
    graderapp_json_response([
        'ok' => false,
        'error' => 'เจ้าของวิชานี้ยังไม่ได้ตั้งค่า Gemini API Key ของตัวเอง',
        'redirect_url' => $redirectUrl,
    ]);
}

$rateLimit = graderapp_ai_rate_limit_config();
$usageCount = graderapp_ai_usage_count($pdo, $actorUserId, (int) $rateLimit['window_seconds'], 'problem_draft');
if ($usageCount >= (int) $rateLimit['max_requests']) {
    graderapp_ai_log_usage($pdo, [
        'actor_user_id' => $actorUserId,
        'owner_user_id' => $ownerUserId,
        'course_id' => (int) $module['course_id'],
        'module_id' => $moduleId,
        'provider' => (string) $aiSettings['provider'],
        'model_name' => (string) $aiSettings['api_model'],
        'request_type' => 'problem_draft',
        'prompt_chars' => $promptLength,
        'response_status' => 'rate_limited',
        'error_message' => 'Rate limit exceeded',
    ]);
    graderapp_json_response([
        'ok' => false,
        'error' => sprintf(
            'เรียก AI draft ครบโควตาแล้ว (%d ครั้งต่อ %d นาที) กรุณารอสักครู่แล้วลองใหม่',
            (int) $rateLimit['max_requests'],
            (int) floor((int) $rateLimit['window_seconds'] / 60)
        ),
    ], 429);
}

$context = [
    'course_code' => (string) $module['course_code'],
    'course_name' => (string) $module['course_name'],
    'module_title' => (string) $module['title'],
    'module_description' => (string) $module['description'],
    'module_sort_order' => (int) $module['sort_order'],
    'teacher_prompt' => $teacherPrompt,
    'default_language' => $draftDefaults['language'],
    'default_sort_order_start' => $draftDefaults['sort_order_start'],
    'default_time_limit_sec' => $draftDefaults['time_limit_sec'],
    'default_memory_limit_mb' => $draftDefaults['memory_limit_mb'],
    'default_max_score' => $draftDefaults['max_score'],
    'default_visibility' => $draftDefaults['visibility'],
    'default_runtime_profile_id' => $draftDefaults['runtime_profile_id'],
    'requested_problem_count' => $requestedProblemCount,
    'requested_problem_breakdown' => $requestedProblemBreakdown,
    'requested_case_count' => $requestedCaseCount,
];

$problemBreakdownPrompt = '';
if (!empty($context['requested_problem_breakdown'])) {
    $breakdownLines = [];
    foreach ($context['requested_problem_breakdown'] as $breakdownIndex => $breakdownItem) {
        $topic = trim((string) ($breakdownItem['topic'] ?? 'หัวข้อที่อาจารย์ระบุ'));
        $count = max(1, (int) ($breakdownItem['count'] ?? 1));
        $breakdownLines[] = sprintf('%d. %s = %d ข้อ', $breakdownIndex + 1, $topic, $count);
    }
    $problemBreakdownPrompt = "\nระบบตรวจพบรายการโจทย์ย่อยจากข้อความอาจารย์:\n" . implode("\n", $breakdownLines) . "\nให้สร้างแยกเป็นคนละ object ใน problems ตามรายการนี้ ห้ามรวมหลายรายการเป็นโจทย์เดียว";
}

$webExercisePrompt = '';
if (in_array(strtolower((string) $context['default_language']), ['html', 'web'], true)) {
    $webExercisePrompt = <<<WEBPROMPT

ข้อกำหนดเพิ่มเติมสำหรับโจทย์ HTML/CSS/Canvas:
- ให้ starter_code เป็นไฟล์ HTML ครบชุดเดียวที่มี <!doctype html>, <html>, <head>, <style>, <body> และ <script> เมื่อเหมาะสม
- สำหรับ Canvas ให้กำหนด <canvas id="..."> และใช้ JavaScript canvas API เช่น getContext("2d"), fillRect, beginPath, arc, fillText ตามโจทย์
- test_cases ของโจทย์ภาษา html/web จะไม่ใช่ stdin/stdout แบบโปรแกรมทั่วไป แต่ expected_stdout ต้องเป็น JSON string สำหรับ static web checks
- รูปแบบ expected_stdout ของแต่ละ test case ต้องเป็น JSON string แบบนี้:
  {"checks":[
    {"type":"tag_exists","value":"canvas","message":"ต้องมี canvas"},
    {"type":"selector_exists","value":"#scene","message":"ต้องมี id scene"},
    {"type":"css_contains","value":"display: flex","message":"ต้องจัด layout ด้วย flex"},
    {"type":"js_contains","value":"getContext","message":"ต้องเรียกใช้ CanvasRenderingContext2D"},
    {"type":"regex","value":"fillRect\\s*\\(","message":"ต้องวาดสี่เหลี่ยมด้วย fillRect"}
  ]}
- check type ที่ระบบรองรับ: contains_text, not_contains_text, tag_exists, selector_exists, attribute_equals, css_contains, js_contains, canvas_uses, regex
- stdin_text สำหรับ html/web ให้เว้นว่างได้
- ห้ามใส่ placeholder เช่น "input" หรือ "output" ใน test case ของ html/web
WEBPROMPT;
}

$prompt = <<<PROMPT
คุณคือ AI Agent ที่ช่วยอาจารย์ร่างโจทย์เขียนโปรแกรมสำหรับระบบ Grader
บริบทของ Session:
- รายวิชา: {$context['course_code']} {$context['course_name']}
- Session: {$context['module_title']}
- คำอธิบาย Session: {$context['module_description']}

ความต้องการจากอาจารย์:
{$context['teacher_prompt']}
{$problemBreakdownPrompt}

ค่าเริ่มต้นที่อาจารย์ตั้งไว้สำหรับโจทย์ชุดนี้:
- ภาษา: {$context['default_language']}
- Time limit: {$context['default_time_limit_sec']} วินาที
- Memory: {$context['default_memory_limit_mb']} MB
- คะแนนเต็ม: {$context['default_max_score']}
- การแสดงผล: {$context['default_visibility']}
- Runtime profile id: {$context['default_runtime_profile_id']}
- ลำดับเริ่มต้นของข้อแรก: {$context['default_sort_order_start']}

กรุณาร่าง "โจทย์ 1 ข้อหรือหลายข้อ" ตามที่อาจารย์ระบุให้พร้อมกรอกฟอร์ม โดยตอบกลับเป็น JSON เท่านั้น ห้ามมี markdown code fence และห้ามมีข้อความอื่นนำหน้า/ตามหลัง

รูปแบบ JSON ต้องเป็น:
{
  "problems": [
    {
      "title": "ชื่อโจทย์",
      "slug": "slug-kebab-case",
      "description_md": "คำอธิบายโจทย์แบบ Markdown หรือข้อความธรรมดา",
      "starter_code": "starter code ถ้ามี",
      "language": "{$context['default_language']}",
      "time_limit_sec": {$context['default_time_limit_sec']},
      "memory_limit_mb": {$context['default_memory_limit_mb']},
      "max_score": {$context['default_max_score']},
      "visibility": "{$context['default_visibility']}",
      "runtime_profile_id": {$context['default_runtime_profile_id']},
      "sort_order": 0,
      "test_cases": [
        {
          "case_type": "sample",
          "stdin_text": "input",
          "expected_stdout": "output",
          "score_weight": 0,
          "sort_order": 1
        }
      ]
    }
  ]
}

หลักเกณฑ์:
- ต้องมีอย่างน้อย 1 ปัญหาใน problems
- ทุกปัญหาต้องมี test_cases อย่างน้อย 1 ชุด
- ทุกปัญหาต้องมีอย่างน้อย 1 ชุดเป็น sample
- ใช้ field names ตามตัวอย่างนี้เท่านั้น
- ถ้าเป็นโจทย์พื้นฐานให้เขียนโจทย์สั้น ชัด และเหมาะกับการสอน
- starter_code ถ้าไม่จำเป็นให้ส่งเป็นสตริงว่าง
- พยายามใช้ค่าเริ่มต้นที่อาจารย์ตั้งไว้ให้ครบทุกข้อ เว้นแต่โจทย์นั้นจำเป็นต้องต่างออกไป
- runtime_profile_id ให้ใช้ค่าตามตัวอย่าง หากไม่แน่ใจห้ามเดา id อื่น
- ถ้าไม่ได้ระบุ sort_order มาเอง ให้เรียงต่อเนื่องโดยเริ่มจาก {$context['default_sort_order_start']}
- ถ้าอาจารย์ระบุจำนวนข้อไว้ชัดเจน ต้องสร้างให้ครบตามจำนวนนั้น ห้ามลดเอง
- ถ้าระบบตรวจพบรายการโจทย์ย่อยด้านบน ให้ problems มีจำนวน object เท่ากับผลรวมรายการนั้น และ title/description ของแต่ละ object ต้องตรงกับหัวข้อย่อยนั้น
- ถ้าอาจารย์เขียนรายการย่อยหลายรายการ เช่น "หัวข้อ A 1 ข้อ, หัวข้อ B 1 ข้อ, หัวข้อ C 1 ข้อ" ให้สร้างแยกเป็นโจทย์คนละรายการใน problems ไม่ใช่รวม requirement ทั้งหมดไว้ในโจทย์เดียว
- ถ้าอาจารย์ระบุจำนวน test case ต่อข้อไว้ชัดเจน ต้องสร้างให้ครบอย่างน้อยตามจำนวนนั้น
- test case ต้องมีข้อมูล stdin_text และ expected_stdout ที่ใช้ตรวจได้จริง ไม่ใช่ placeholder ว่าง
{$webExercisePrompt}
PROMPT;

if ($context['requested_problem_count']) {
    $prompt .= "\n- อาจารย์ระบุชัดเจนว่าต้องการ {$context['requested_problem_count']} ข้อ ดังนั้น problems ต้องมี exactly {$context['requested_problem_count']} รายการ";
}

if ($context['requested_case_count']) {
    if ((int) $context['requested_case_count'] === 1) {
        $prompt .= "\n- อาจารย์ระบุชัดเจนว่าต้องการ exactly 1 test case ต่อข้อ ดังนั้นแต่ละปัญหาต้องมี test_cases เพียง 1 ชุด และชุดนั้นต้องเป็น sample";
    } else {
        $prompt .= "\n- อาจารย์ระบุชัดเจนว่าต้องการอย่างน้อย {$context['requested_case_count']} test cases ต่อข้อ ดังนั้นแต่ละปัญหาต้องมี test_cases อย่างน้อย {$context['requested_case_count']} ชุด";
    }
}

$problemItemsSchema = [
    'type' => 'OBJECT',
    'properties' => [
        'title' => ['type' => 'STRING'],
        'slug' => ['type' => 'STRING'],
        'description_md' => ['type' => 'STRING'],
        'starter_code' => ['type' => 'STRING'],
        'language' => ['type' => 'STRING'],
        'time_limit_sec' => ['type' => 'NUMBER'],
        'memory_limit_mb' => ['type' => 'INTEGER'],
        'max_score' => ['type' => 'INTEGER'],
        'visibility' => ['type' => 'STRING', 'enum' => ['draft', 'published', 'archived']],
        'runtime_profile_id' => ['type' => 'INTEGER'],
        'sort_order' => ['type' => 'INTEGER'],
        'test_cases' => [
            'type' => 'ARRAY',
            'minItems' => max(1, (int) ($requestedCaseCount ?? 1)),
            'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'case_type' => ['type' => 'STRING', 'enum' => ['sample', 'hidden']],
                    'stdin_text' => ['type' => 'STRING'],
                    'expected_stdout' => ['type' => 'STRING'],
                    'score_weight' => ['type' => 'INTEGER'],
                    'sort_order' => ['type' => 'INTEGER'],
                ],
                'required' => ['case_type', 'stdin_text', 'expected_stdout', 'score_weight', 'sort_order'],
            ],
        ],
    ],
    'required' => ['title', 'slug', 'description_md', 'starter_code', 'language', 'time_limit_sec', 'memory_limit_mb', 'max_score', 'visibility', 'runtime_profile_id', 'sort_order', 'test_cases'],
];
$problemsArraySchema = [
    'type' => 'ARRAY',
    'minItems' => max(1, (int) ($requestedProblemCount ?? 1)),
    'items' => $problemItemsSchema,
];
if ($requestedProblemCount !== null && $requestedProblemCount > 0) {
    $problemsArraySchema['maxItems'] = (int) $requestedProblemCount;
}

$url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($aiSettings['api_model']) . ":generateContent?key=" . $aiSettings['api_key'];
$requestPayload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt],
            ],
        ],
    ],
    'generationConfig' => [
        'temperature' => 0.4,
        'responseMimeType' => 'application/json',
        'responseSchema' => [
            'type' => 'OBJECT',
            'properties' => [
                'problems' => $problemsArraySchema,
            ],
            'required' => ['problems'],
        ],
    ],
];
$requestBody = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
$response = false;
$decoded = null;
$curlNetworkError = '';
$providerMessage = '';
$providerHttpCode = 0;
$maxAttempts = 3;
$retryDelaysUs = [700000, 1500000];

for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    $response = curl_exec($ch);
    $providerHttpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($response === false) {
        $curlNetworkError = 'ไม่สามารถเชื่อมต่อ Gemini API ได้: ' . curl_error($ch);
        curl_close($ch);

        if ($attempt < ($maxAttempts - 1)) {
            usleep($retryDelaysUs[$attempt] ?? 1000000);
            continue;
        }

        graderapp_ai_log_usage($pdo, [
            'actor_user_id' => $actorUserId,
            'owner_user_id' => $ownerUserId,
            'course_id' => (int) $module['course_id'],
            'module_id' => $moduleId,
            'provider' => (string) $aiSettings['provider'],
            'model_name' => (string) $aiSettings['api_model'],
            'request_type' => 'problem_draft',
            'prompt_chars' => $promptLength,
            'response_status' => 'network_error',
            'error_message' => $curlNetworkError,
        ]);
        graderapp_json_response([
            'ok' => false,
            'error' => $curlNetworkError,
        ], 502);
    }

    curl_close($ch);
    $decoded = json_decode((string) $response, true);

    if (!isset($decoded['error'])) {
        break;
    }

    $providerMessage = trim((string) ($decoded['error']['message'] ?? 'Unknown error'));
    $normalizedProviderMessage = strtolower($providerMessage);
    $isRetryableProviderError =
        in_array($providerHttpCode, [429, 500, 502, 503, 504], true)
        || str_contains($normalizedProviderMessage, 'high demand')
        || str_contains($normalizedProviderMessage, 'try again later')
        || str_contains($normalizedProviderMessage, 'temporar')
        || str_contains($normalizedProviderMessage, 'unavailable');

    if ($isRetryableProviderError && $attempt < ($maxAttempts - 1)) {
        usleep($retryDelaysUs[$attempt] ?? 1000000);
        continue;
    }

    $redirectUrl = '';
    if ($returnTo !== '') {
        $redirectUrl = graderapp_path('grader.ai.settings', ['return_to' => $returnTo]);
    } else {
        $redirectUrl = graderapp_path('grader.ai.settings');
    }

    if (str_contains($normalizedProviderMessage, 'quota exceeded') || str_contains($normalizedProviderMessage, 'billing') || str_contains($normalizedProviderMessage, 'free_tier')) {
        $providerMessage = sprintf(
            'โควตาของ %s ในบัญชีนี้ยังไม่พอสำหรับ AI draft ตอนนี้ ลองเปลี่ยนไปใช้รุ่น Flash หรือเช็ก plan / billing / quota ของ Gemini ก่อน',
            (string) $aiSettings['api_model']
        );
    } elseif (str_contains($normalizedProviderMessage, 'high demand') || str_contains($normalizedProviderMessage, 'try again later')) {
        $providerMessage = 'Gemini กำลังมีโหลดสูงชั่วคราว ลองใหม่อีกครั้งได้ในอีกสักครู่ หรือแบ่งคำสั่งให้ AI ร่างทีละ 3-5 ข้อแทน 10 ข้อพร้อม 4 test case ต่อข้อ และถ้ายังเจอบ่อยให้ลองเปลี่ยนโมเดลในหน้า AI Settings';
    } else {
        $providerMessage = 'Gemini API Error: ' . $providerMessage;
    }

    graderapp_ai_log_usage($pdo, [
        'actor_user_id' => $actorUserId,
        'owner_user_id' => $ownerUserId,
        'course_id' => (int) $module['course_id'],
        'module_id' => $moduleId,
        'provider' => (string) $aiSettings['provider'],
        'model_name' => (string) $aiSettings['api_model'],
        'request_type' => 'problem_draft',
        'prompt_chars' => $promptLength,
        'response_status' => 'provider_error',
        'error_message' => $providerMessage,
    ]);
    graderapp_json_response([
        'ok' => false,
        'error' => $providerMessage,
        'redirect_url' => $redirectUrl,
    ], in_array($providerHttpCode, [429, 503], true) ? 503 : 502);
}

$rawText = (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
if ($rawText === '') {
    graderapp_ai_log_usage($pdo, [
        'actor_user_id' => $actorUserId,
        'owner_user_id' => $ownerUserId,
        'course_id' => (int) $module['course_id'],
        'module_id' => $moduleId,
        'provider' => (string) $aiSettings['provider'],
        'model_name' => (string) $aiSettings['api_model'],
        'request_type' => 'problem_draft',
        'prompt_chars' => $promptLength,
        'response_status' => 'empty_response',
        'error_message' => 'AI returned empty response text',
    ]);
    graderapp_json_response([
        'ok' => false,
        'error' => 'AI ไม่ได้ส่งผลลัพธ์กลับมาในรูปแบบที่คาดไว้',
    ], 502);
}

$rawText = trim(str_replace(['```json', '```'], '', $rawText));
$decodedPayload = json_decode($rawText, true);
if (!is_array($decodedPayload)) {
    graderapp_ai_log_usage($pdo, [
        'actor_user_id' => $actorUserId,
        'owner_user_id' => $ownerUserId,
        'course_id' => (int) $module['course_id'],
        'module_id' => $moduleId,
        'provider' => (string) $aiSettings['provider'],
        'model_name' => (string) $aiSettings['api_model'],
        'request_type' => 'problem_draft',
        'prompt_chars' => $promptLength,
        'response_status' => 'invalid_json',
        'error_message' => 'AI response could not be decoded as JSON',
    ]);
    graderapp_json_response([
        'ok' => false,
        'error' => 'AI ตอบกลับมาในรูปแบบที่แปลง JSON ไม่ได้',
    ], 502);
}

$rawProblems = [];
if (isset($decodedPayload['problems']) && is_array($decodedPayload['problems'])) {
    $rawProblems = $decodedPayload['problems'];
} elseif (isset($decodedPayload['title'])) {
    $rawProblems = [$decodedPayload];
}

if ($requestedProblemCount !== null && $requestedProblemCount > 0 && count($rawProblems) > $requestedProblemCount) {
    $rawProblems = array_slice($rawProblems, 0, $requestedProblemCount);
}

if (!$rawProblems) {
    graderapp_ai_log_usage($pdo, [
        'actor_user_id' => $actorUserId,
        'owner_user_id' => $ownerUserId,
        'course_id' => (int) $module['course_id'],
        'module_id' => $moduleId,
        'provider' => (string) $aiSettings['provider'],
        'model_name' => (string) $aiSettings['api_model'],
        'request_type' => 'problem_draft',
        'prompt_chars' => $promptLength,
        'response_status' => 'invalid_payload',
        'error_message' => 'AI payload has no usable problems array',
    ]);
    graderapp_json_response([
        'ok' => false,
        'error' => 'AI ยังไม่ได้สร้างรายการโจทย์ที่ใช้ได้',
    ], 502);
}

$drafts = [];
foreach ($rawProblems as $problemIndex => $problem) {
    if (!is_array($problem)) {
        continue;
    }

    $title = trim((string) ($problem['title'] ?? ''));
    if ($title === '') {
        continue;
    }

    $testCases = [];
    $rawCases = isset($problem['test_cases']) && is_array($problem['test_cases']) ? $problem['test_cases'] : [];
    foreach ($rawCases as $index => $case) {
        if (!is_array($case)) {
            continue;
        }

        $stdinText = (string) ($case['stdin_text'] ?? $case['input'] ?? '');
        $expectedOutput = (string) ($case['expected_stdout'] ?? $case['expected_output'] ?? $case['output'] ?? '');
        if (trim($stdinText) === '' && trim($expectedOutput) === '') {
            continue;
        }

        $caseType = (string) ($case['case_type'] ?? 'sample');
        if (!in_array($caseType, ['sample', 'hidden'], true)) {
            $caseType = 'sample';
        }

        $testCases[] = [
            'case_type' => $caseType,
            'stdin_text' => $stdinText,
            'expected_stdout' => $expectedOutput,
            'score_weight' => (int) ($case['score_weight'] ?? 0),
            'sort_order' => (int) ($case['sort_order'] ?? ($index + 1)),
        ];
    }

    if ($requestedCaseCount !== null && $requestedCaseCount > 0) {
        if ($requestedCaseCount === 1) {
            $firstCase = $testCases[0] ?? [
                'case_type' => 'sample',
                'stdin_text' => '',
                'expected_stdout' => '',
                'score_weight' => 0,
                'sort_order' => 1,
            ];
            $firstCase['case_type'] = 'sample';
            $firstCase['sort_order'] = 1;
            $testCases = [$firstCase];
        } elseif (count($testCases) > $requestedCaseCount) {
            $testCases = array_slice($testCases, 0, $requestedCaseCount);
        }
    }

    if (!$testCases) {
        $testCases[] = [
            'case_type' => 'sample',
            'stdin_text' => '',
            'expected_stdout' => '',
            'score_weight' => 0,
            'sort_order' => 1,
        ];
    }

    $hasSampleCase = false;
    foreach ($testCases as $caseIndex => $testCase) {
        $testCases[$caseIndex]['sort_order'] = $caseIndex + 1;
        if (($testCase['case_type'] ?? 'sample') === 'sample') {
            $hasSampleCase = true;
        }
    }
    if (!$hasSampleCase && isset($testCases[0])) {
        $testCases[0]['case_type'] = 'sample';
    }

    $drafts[] = [
        'title' => $title,
        'slug' => graderapp_slugify((string) ($problem['slug'] ?? $title)),
        'description_md' => (string) ($problem['description_md'] ?? $problem['description'] ?? ''),
        'starter_code' => (string) ($problem['starter_code'] ?? ''),
        'language' => trim((string) ($problem['language'] ?? $draftDefaults['language'])) ?: $draftDefaults['language'],
        'runtime_profile_id' => graderapp_resolve_runtime_profile_id(
            $pdo,
            $problem['runtime_profile_id'] ?? $problem['runtime_profile'] ?? $problem['runtime_profile_key'] ?? '',
            (int) $draftDefaults['runtime_profile_id']
        ),
        'time_limit_sec' => max(0.1, (float) ($problem['time_limit_sec'] ?? $problem['time_limit'] ?? $draftDefaults['time_limit_sec'])),
        'memory_limit_mb' => max(16, (int) ($problem['memory_limit_mb'] ?? $problem['memory_limit'] ?? $draftDefaults['memory_limit_mb'])),
        'max_score' => max(1, (int) ($problem['max_score'] ?? $draftDefaults['max_score'])),
        'visibility' => in_array((string) ($problem['visibility'] ?? $draftDefaults['visibility']), ['draft', 'published', 'archived'], true)
            ? (string) ($problem['visibility'] ?? $draftDefaults['visibility'])
            : $draftDefaults['visibility'],
        'sort_order' => max(0, (int) ($problem['sort_order'] ?? ($draftDefaults['sort_order_start'] + $problemIndex))),
        'test_cases' => $testCases,
    ];
}

if (!$drafts) {
    graderapp_ai_log_usage($pdo, [
        'actor_user_id' => $actorUserId,
        'owner_user_id' => $ownerUserId,
        'course_id' => (int) $module['course_id'],
        'module_id' => $moduleId,
        'provider' => (string) $aiSettings['provider'],
        'model_name' => (string) $aiSettings['api_model'],
        'request_type' => 'problem_draft',
        'prompt_chars' => $promptLength,
        'response_status' => 'invalid_payload',
        'error_message' => 'AI payload has no usable problem entries',
    ]);
    graderapp_json_response([
        'ok' => false,
        'error' => 'AI ยังไม่ได้สร้างโจทย์ที่นำมาใช้ต่อได้',
    ], 502);
}

if ($requestedProblemCount !== null && $requestedProblemCount > 0 && count($drafts) < $requestedProblemCount) {
    $shortageMessage = sprintf(
        'AI ร่างมาให้ %d ข้อ แต่คำสั่งระบุว่าต้องการ %d ข้อ กรุณากดร่างใหม่อีกครั้ง ระบบได้บังคับ schema ให้แยกหัวข้อย่อยแล้ว',
        count($drafts),
        $requestedProblemCount
    );
    graderapp_ai_log_usage($pdo, [
        'actor_user_id' => $actorUserId,
        'owner_user_id' => $ownerUserId,
        'course_id' => (int) $module['course_id'],
        'module_id' => $moduleId,
        'provider' => (string) $aiSettings['provider'],
        'model_name' => (string) $aiSettings['api_model'],
        'request_type' => 'problem_draft',
        'prompt_chars' => $promptLength,
        'response_status' => 'count_mismatch',
        'error_message' => $shortageMessage,
    ]);
    graderapp_json_response([
        'ok' => false,
        'error' => $shortageMessage,
    ], 502);
}

graderapp_ai_log_usage($pdo, [
    'actor_user_id' => $actorUserId,
    'owner_user_id' => $ownerUserId,
    'course_id' => (int) $module['course_id'],
    'module_id' => $moduleId,
    'provider' => (string) $aiSettings['provider'],
    'model_name' => (string) $aiSettings['api_model'],
    'request_type' => 'problem_draft',
    'prompt_chars' => $promptLength,
    'response_status' => 'success',
    'error_message' => null,
]);

graderapp_json_response([
    'ok' => true,
    'draft' => $drafts[0],
    'drafts' => $drafts,
]);
} catch (Throwable $error) {
    if ($pdo instanceof PDO) {
        graderapp_ai_log_usage($pdo, [
            'actor_user_id' => $actorUserId,
            'owner_user_id' => $ownerUserId,
            'course_id' => 0,
            'module_id' => $moduleId,
            'provider' => 'gemini',
            'model_name' => '',
            'request_type' => 'problem_draft',
            'prompt_chars' => $promptLength,
            'response_status' => 'fatal_error',
            'error_message' => $error->getMessage(),
        ]);
    }

    graderapp_json_response([
        'ok' => false,
        'error' => 'ระบบยังร่างโจทย์ให้ไม่ได้ในตอนนี้: ' . $error->getMessage(),
    ], 500);
}
