<?php
// verification.php - ระบบจัดการทวนสอบและคัดเลือกรายวิชาทวนสอบ
$year = isset($_POST['year']) ? $_POST['year'] : '2568';
$faculty = '8'; // วิทยาการคอมพิวเตอร์และเทคโนโลยีสารสนเทศ

// ฟังก์ชันดึงและคัดกรองข้อมูลเฉพาะวิศวกรรมคอมพิวเตอร์
function fetchComputerEngineeringCourses($y, $s, $f) {
    $url = 'https://tqf.rbru.ac.th/staff_reportByGroupIntruc.php';
    $data = http_build_query([
        'lstacadyear' => $y,
        'lstsemester' => $s,
        'lstperiod' => '1',
        'lstfac' => $f,
        'Submit' => 'ดูข้อมูล'
    ]);
    
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => $data,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ];
    $context  = stream_context_create($options);
    $html = @file_get_contents($url, false, $context);
    
    if(!$html) return [];
    
    // แปลง HTML เป็น DOM
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    // โหลด HTML (Hack สำหรับ encoding thai)
    $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    @$dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    // หา DataTable
    $tables = $xpath->query('//table[contains(@class, "DataTable")]');
    $courses = [];
    
    if($tables->length > 0) {
        $table = $tables->item(0);
        $rows = $xpath->query('.//tr', $table);
        $is_ce_group = false;
        
        foreach($rows as $row) {
            $tds = $xpath->query('.//td', $row);
            
            // เช็คว่าเป็น Header ของกลุ่มวิชาหรือไม่
            // อาจจะพิจารณาจาก: มีคอลัมน์เดียว, หรือมี colspan >= 5, หรือมีข้อความ "วิศวกรรมคอมพิวเตอร์" เด่นๆ
            $is_header_row = false;
            $groupText = "";
            
            if($tds->length == 1) {
                $is_header_row = true;
                $groupText = trim($tds->item(0)->nodeValue);
            } else if ($tds->length > 0) {
                $colspan = $tds->item(0)->getAttribute('colspan');
                if((int)$colspan >= 4) {
                    $is_header_row = true;
                    $groupText = trim($tds->item(0)->nodeValue);
                }
            }

            if($is_header_row) {
                if(mb_strpos($groupText, 'วิศวกรรมคอมพิวเตอร์') !== false) {
                    $is_ce_group = true;
                } else {
                    $is_ce_group = false;
                }
            } else if($tds->length >= 5 && $is_ce_group) {
                // ข้อมูลรายวิชา
                $course_raw = trim($tds->item(0)->nodeValue);
                // ขจัด space นำหน้า
                $course_raw = preg_replace('/^\s+/', '', str_replace('&nbsp;', ' ', $course_raw));
                
                // แยก รหัสวิชา กับ ชื่อวิชา (รูปแบบ: 9062081 - Computer Programming)
                $course_parts = explode(' - ', $course_raw, 2);
                $course_code = trim($course_parts[0]);
                $course_name = isset($course_parts[1]) ? trim($course_parts[1]) : '';
                
                // เช็คสถานะ มคอ
                $tqf3 = trim($tds->item(2)->nodeValue);
                $tqf5 = trim($tds->item(3)->nodeValue);
                $instructor = trim($tds->item(4)->nodeValue);
                
                // ตรวจสอบว่ามี Link ไหม (เพื่อเอาไปทำปุ่ม)
                $tqf3_link = "";
                $tqf3_nodes = $xpath->query('.//a', $tds->item(2));
                if($tqf3_nodes->length > 0) $tqf3_link = $tqf3_nodes->item(0)->getAttribute('href');
                
                $tqf5_link = "";
                $tqf5_nodes = $xpath->query('.//a', $tds->item(3));
                if($tqf5_nodes->length > 0) $tqf5_link = $tqf5_nodes->item(0)->getAttribute('href');

                // ข้ามถ้าเป็นหัวตาราง
                if($course_code == 'หลักสูตรรายวิชา') continue;

                $courses[] = [
                    'code' => $course_code,
                    'name' => $course_name,
                    'instructor' => $instructor,
                    'tqf3' => $tqf3,
                    'tqf5' => $tqf5,
                    'tqf3_link' => $tqf3_link ? 'https://tqf.rbru.ac.th/'.$tqf3_link : '',
                    'tqf5_link' => $tqf5_link ? 'https://tqf.rbru.ac.th/'.$tqf5_link : '',
                    'semester' => $s
                ];
            }
        }
    }
    return $courses;
}

$all_courses = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['fetch_annual'])) {
    // ดึง 3 เทอม (ใช้เวลาโหลดเล็กน้อย)
    $t1 = fetchComputerEngineeringCourses($year, '1', $faculty);
    $t2 = fetchComputerEngineeringCourses($year, '2', $faculty);
    $t3 = fetchComputerEngineeringCourses($year, '3', $faculty);
    $all_courses = array_merge($t1, $t2, $t3);
}

// ลบวิชาซ้ำซ้อนในกรณีเปิดหลายเซคชัน ให้รวมไว้ (วิเคราะห์ว่าเอาแค่ชุดวิชาหลัก)
$unique_courses = [];
foreach($all_courses as $c) {
    if(!isset($unique_courses[$c['code']])) {
        $unique_courses[$c['code']] = $c;
    }
}
$all_courses = array_values($unique_courses);
$total_unique = count($all_courses);

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AUNQA Verification Hub 📑</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Sarabun', sans-serif; }
        .hero { background: linear-gradient(135deg, #4b6cb7 0%, #182848 100%); color: white; padding: 30px 0; border-radius: 0 0 20px 20px; margin-bottom: 30px;}
        .card { border-radius: 12px; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: 0.3s; }
        .nav-link { color: #555; font-weight: 500; }
        .nav-link.active { font-weight: bold; color: #1e3c72; border-bottom: 3px solid #1e3c72; }
        
        .progress-box {
            background: white; border-radius: 12px; padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            position: sticky; top: 20px; z-index: 100;
        }
        
        .course-row:hover { background-color: #f8f9fa; }
        .success-chip { background-color: #d1e7dd; color: #0f5132; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; text-decoration: none;}
        .danger-chip { background-color: #f8d7da; color: #842029; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-white py-3 shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold text-primary" href="index.php"><i class="bi bi-rocket-takeoff"></i> AUNQA Hub</a>
        <div class="navbar-nav">
            <a class="nav-link" href="index.php">ติดตาม มคอ (Dashboard)</a>
            <a class="nav-link active" href="verification.php">กระดานคัดเลือกทวนสอบ (Verification)</a>
            <a class="nav-link" href="verification_board.php">ประเมินและทวนสอบผล (Tracking)</a>
        </div>
    </div>
</nav>

<div class="hero text-center">
    <div class="container">
        <h2 class="fw-bold">ระบบบริหารจัดการทวนสอบผลสัมฤทธิ์ 📑</h2>
        <p class="lead mb-0">ดึงและคัดกรองเฉพาะรายวิชาสาขา "วิศวกรรมคอมพิวเตอร์" อัตโนมัติ</p>
    </div>
</div>

<div class="container pb-5">
    
    <div class="row mb-4">
        <div class="col-12">
            <div class="card p-4">
                <form method="POST" class="row g-3 align-items-center">
                    <div class="col-auto">
                        <label class="col-form-label fw-bold">เลือกปีการศึกษาสำหรับการทวนสอบ: </label>
                    </div>
                    <div class="col-md-3">
                        <select name="year" class="form-select">
                            <option value="2567" <?= $year=='2567'?'selected':'' ?>>ปีการศึกษา 2567</option>
                            <option value="2568" <?= $year=='2568'?'selected':'' ?>>ปีการศึกษา 2568</option>
                            <option value="2569" <?= $year=='2569'?'selected':'' ?>>ปีการศึกษา 2569</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" name="fetch_annual" class="btn btn-primary fw-bold">
                            <i class="bi bi-cloud-download"></i> รวบรวมข้อมูลทุกเทอม (1-3)
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['fetch_annual'])): ?>
    
    <div class="row">
        <!-- Sidebar: ตัวนับเป้าหมาย (Progress Tracking) -->
        <div class="col-md-3">
            <div class="progress-box">
                <h5 class="fw-bold mb-3"><i class="bi bi-pie-chart-fill text-primary"></i> เป้าหมาย 25%</h5>
                <p class="text-muted small">ตามเกณฑ์ กรรมการจะต้องทวนสอบอย่างน้อย 25% ของรายวิชาที่เปิดสอนทั้งปี</p>
                
                <h2 class="fw-bold text-center" id="percentDisplay" style="color: #dc3545;">0%</h2>
                <div class="progress mb-3" style="height: 20px;">
                    <div id="progressBar" class="progress-bar bg-danger progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                </div>
                
                <div class="d-flex justify-content-between text-muted small fw-bold">
                    <span>เลือกไว้: <span id="selectedCount">0</span> วิชา</span>
                    <span>เปิดสอนทั้งหมด: <?= $total_unique ?> วิชา</span>
                </div>
                
                <hr>
                
                <button type="button" class="btn btn-outline-primary w-100 mb-2" onclick="randomSelect()">
                    <i class="bi bi-dice-5"></i> สุ่มเลือกแบบอัตโนมัติ (25%)
                </button>
                <form id="saveVerificationForm" action="verification_board.php" method="POST">
                    <!-- รายการที่ถูกเลือกจะถูก append ที่นี่ผ่าน JS -->
                    <input type="hidden" name="action" value="save_selection">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <button type="submit" class="btn btn-success w-100 fw-bold" id="btnSave" disabled>
                        <i class="bi bi-send-check"></i> บันทึกเข้าระบบทวนสอบ
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Main Area: ตารางรายวิชา -->
        <div class="col-md-9">
            <?php if($total_unique > 0): ?>
            <div class="card p-0 overflow-hidden">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width: 60px;">เลือก</th>
                            <th class="text-center">เทอม</th>
                            <th>รหัส</th>
                            <th>วิชา</th>
                            <th>ผู้สอน</th>
                            <th class="text-center">สถานะ มคอ.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($all_courses as $index => $course): 
                            $has_tqf3 = (strpos($course['tqf3'], 'ส่งแล้ว') !== false);
                            $has_tqf5 = (strpos($course['tqf5'], 'ส่งแล้ว') !== false);
                            // JSON Encode ข้อมูลเก็บไว้เป็น Data attribute
                            $course_json = htmlspecialchars(json_encode([
                                'code' => $course['code'],
                                'name' => $course['name'],
                                'instructor' => $course['instructor'],
                                'semester' => $course['semester'],
                                'tqf3_link' => $course['tqf3_link'],
                                'tqf5_link' => $course['tqf5_link']
                            ]));
                        ?>
                        <tr class="course-row">
                            <td class="text-center">
                                <input class="form-check-input course-checkbox" type="checkbox" style="transform: scale(1.3);" 
                                    data-course='<?= $course_json ?>' onchange="updateProgress()">
                            </td>
                            <td class="text-center fw-bold text-primary"><?= $course['semester'] ?></td>
                            <td><span class="badge bg-secondary"><?= $course['code'] ?></span></td>
                            <td class="fw-medium"><?= $course['name'] ?></td>
                            <td class="small text-muted"><?= $course['instructor'] ?></td>
                            <td class="text-center">
                                <?php if($has_tqf3): ?>
                                    <a href="<?= $course['tqf3_link'] ?>" target="_blank" class="success-chip me-1"><i class="bi bi-file-earmark-check"></i> 3</a>
                                <?php else: ?>
                                    <span class="danger-chip me-1"><i class="bi bi-x"></i> 3</span>
                                <?php endif; ?>
                                
                                <?php if($has_tqf5): ?>
                                    <a href="<?= $course['tqf5_link'] ?>" target="_blank" class="success-chip"><i class="bi bi-file-earmark-check"></i> 5</a>
                                <?php else: ?>
                                    <span class="danger-chip"><i class="bi bi-x"></i> 5</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> ไม่พบข้อมูลรายวิชาของวิศวกรรมคอมพิวเตอร์ ในปีการศึกษา <?= $year ?> หรือระบบฐานข้อมูลปลายทางผิดพลาด
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<script>
    const totalCourses = <?= isset($total_unique) ? $total_unique : 0 ?>;
    
    function updateProgress() {
        if(totalCourses === 0) return;
        
        const checkboxes = document.querySelectorAll('.course-checkbox:checked');
        const selectedCount = checkboxes.length;
        const percent = Math.round((selectedCount / totalCourses) * 100);
        
        const percentDisplay = document.getElementById('percentDisplay');
        const progressBar = document.getElementById('progressBar');
        const countDisplay = document.getElementById('selectedCount');
        const btnSave = document.getElementById('btnSave');
        
        countDisplay.textContent = selectedCount;
        percentDisplay.textContent = percent + "%";
        progressBar.style.width = percent + "%";
        
        // อัปเดตสีตามเงื่อนไข
        if (percent >= 25) {
            percentDisplay.style.color = "#198754"; // สีเขียว Success
            progressBar.className = "progress-bar bg-success progress-bar-striped progress-bar-animated";
            btnSave.disabled = false;
        } else {
            percentDisplay.style.color = "#dc3545"; // สีแดง Danger
            progressBar.className = "progress-bar bg-danger progress-bar-striped progress-bar-animated";
            btnSave.disabled = true;
        }
        
        // เคลียร์ค่า input เก่าออกจากฟอร์ม
        const form = document.getElementById('saveVerificationForm');
        document.querySelectorAll('.course-input-data').forEach(e => e.remove());
        
        // แอดค่าใหม่เข้าฟอร์ม
        checkboxes.forEach((cb, index) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `selected_courses[${index}]`;
            input.value = cb.getAttribute('data-course');
            input.className = 'course-input-data';
            form.appendChild(input);
        });
    }
    
    function randomSelect() {
        const checkboxes = Array.from(document.querySelectorAll('.course-checkbox'));
        checkboxes.forEach(cb => cb.checked = false); // เคลียร์ทั้งหมดก่อน
        
        const targetCount = Math.ceil(totalCourses * 0.25);
        if(targetCount === 0) return;
        
        // สุ่มแบบง่ายๆ
        const shuffled = checkboxes.sort(() => 0.5 - Math.random());
        for(let i=0; i<targetCount; i++) {
            shuffled[i].checked = true;
        }
        
        updateProgress();
    }
</script>
</body>
</html>
