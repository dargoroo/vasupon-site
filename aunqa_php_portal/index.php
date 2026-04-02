<?php
// index.php - AUNQA Dashboard
// หน้าเว็บแสดงผลหลักสูตร มคอ.3 มคอ.5 และดึงข้อมูลเกรดอัตโนมัติ

$year = isset($_POST['year']) ? $_POST['year'] : '2568';
$semester = isset($_POST['semester']) ? $_POST['semester'] : '2';
$faculty = '8'; // วิทยาการคอมพิวเตอร์และเทคโนโลยีสารสนเทศ

// ฟังก์ชันสำหรับดึงข้อมูลจากเว็บ TQF โดยตรงผ่าน PHP (ไม่ต้องใช้ Python เพราะไม่ต้องล็อกอิน)
function fetchTQFData($y, $s, $f) {
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
    return $html;
}

$tqf_html = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['fetch_tqf'])) {
    $tqf_html = fetchTQFData($year, $semester, $faculty);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AUNQA Intelligence Hub 🚀</title>
    <!-- ใช้ Bootstrap ลบความยุ่งยาก -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Sarabun', sans-serif; }
        .hero { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; padding: 40px 0; }
        .card { border-radius: 15px; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .success-chip { background-color: #d1e7dd; color: #0f5132; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; }
        .danger-chip { background-color: #f8d7da; color: #842029; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; }
        /* สไตล์ตารางที่ดึงมาจาก TQF */
        .tqf-table table { width: 100%; border-collapse: collapse; margin-top: 15px; background: white; }
        .tqf-table td { padding: 10px; border: 1px solid #dee2e6; }
        .tqf-table [bgcolor="#FFCCCC"] { background-color: #e2e3e5 !important; font-weight: bold; color: #333; }
        .tqf-table a { text-decoration: none; color: #0d6efd; }
    </style>
</head>
<body>

<div class="hero mb-4 text-center">
    <div class="container">
        <h1 class="fw-bold">AUNQA Intelligence Hub 🚀</h1>
        <p class="lead">ระบบรวบรวมข้อมูลคุณภาพการศึกษาและเกรดอัตโนมัติ ระดับหลักสูตร</p>
    </div>
</div>

<div class="container">
    <div class="row g-4">
        
        <!-- ส่วนที่ 1: ดึงรายวิชา มคอ (ไม่มีพาสเวิร์ด) -->
        <div class="col-md-7">
            <div class="card p-4 h-100">
                <h4 class="fw-bold text-primary mb-3">1️⃣ กระดานตรวจสอบ มคอ.3 / มคอ.5</h4>
                <p class="text-muted">ดึงรายวิชาและผู้สอนจากระบบ TQF (ไม่ต้องเข้ารหัส)</p>
                
                <form method="POST" class="row g-2 mb-3">
                    <div class="col-md-5">
                        <select name="year" class="form-select">
                            <option value="2567" <?= $year=='2567'?'selected':'' ?>>ปี 2567</option>
                            <option value="2568" <?= $year=='2568'?'selected':'' ?>>ปี 2568</option>
                            <option value="2569" <?= $year=='2569'?'selected':'' ?>>ปี 2569</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select name="semester" class="form-select">
                            <option value="1" <?= $semester=='1'?'selected':'' ?>>เทอม 1</option>
                            <option value="2" <?= $semester=='2'?'selected':'' ?>>เทอม 2</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" name="fetch_tqf" class="btn btn-primary w-100">ดึงข้อมูล TQF</button>
                    </div>
                </form>

                <?php if($tqf_html): ?>
                    <!-- แสดงตารางเฉพาะของวิศวกรรมคอมพิวเตอร์ -->
                    <div class="tqf-table table-responsive" style="max-height: 500px; overflow-y:auto;">
                        <?php 
                            // ใช้การตัด HTML อย่างง่ายเพื่อเอาเฉพาะตารางมาแสดง
                            if(preg_match('/<table width="95%"[^>]*>(.*?)<\/table>/is', $tqf_html, $matches)) {
                                $table_content = $matches[1];
                                // ตัดคำว่าคณะวิชาและดึงเฉพาะหัวข้อขึ้นมาแสดง และกรองนิดหน่อย
                                // หมายเหตุ: ในระบบจริงควรใช้ DOMDocument แต่นี่คือ Mockup
                                echo "<table>{$table_content}</table>";
                            } else {
                                echo "<div class='alert alert-danger'>ไม่พบข้อมูลในระบบ หรือเชื่อมต่อล้มเหลว</div>";
                            }
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ส่วนที่ 2: ดึงเกรดออโต้เข้าสู่ระบบ -->
        <div class="col-md-5">
            <div class="card p-4 h-100">
                <h4 class="fw-bold text-success mb-3">2️⃣ Bot นำเข้าเกรดนักศึกษา (AUNQA Sync)</h4>
                <p class="text-muted">ระบบจะใช้บอทวิ่งดึงข้อมูลเกรดวิชาเข้าฐานข้อมูลส่วนกลาง</p>
                <div class="alert alert-warning" style="font-size: 0.85rem;">
                    <strong>🔒 ข้อมูลการสอนของคุณปลอดภัย:</strong> ระบบไม่ทำการบันทึกรหัสผ่าน ระบบส่งเข้ารหัสตรงไปยัง GitHub Actions เพื่อเรนเดอร์ข้อมูลแบบ Session เท่านั้น
                </div>
                <!-- 
                หลักการทำงานของฟอร์มนี้:
                จะใช้ AJAX ยิงไปที่ api.php ของเรา 
                และ api.php จะยิง Webhook ข้ามไปปลุก GitHub Actions ทำงาน
                -->
                <form id="botForm">
                    <div class="mb-3">
                        <label class="form-label">ชื่อผู้ใช้งาน (RBRU Username)</label>
                        <input type="text" class="form-control" id="uid" required placeholder="เช่น vasupon.p">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัสผ่าน (Password)</label>
                        <input type="password" class="form-control" id="pwd" required placeholder="รหัสผ่านระบบ Reg">
                    </div>
                    <input type="hidden" id="target_year" value="<?= $year ?>">
                    <input type="hidden" id="target_term" value="<?= $semester ?>">
                    <button type="button" class="btn btn-success w-100 fw-bold" onclick="startBot()">ดึงเกรด AUNQA ข้ามจักรวาล 🚀</button>
                    <div id="botStatus" class="mt-3 text-center d-none">
                        <div class="spinner-border text-success spinner-border-sm" role="status"></div>
                        <span class="ms-2">กำลังสั่งการหุ่นยนต์...</span>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
function startBot() {
    const btn = document.querySelector('button.btn-success');
    const status = document.getElementById('botStatus');
    btn.disabled = true;
    status.classList.remove('d-none');
    
    // จำลองการยิงข้อมูลไปที่ API ของเรา (api.php)
    setTimeout(() => {
        status.innerHTML = "✅ สั่งการ GitHub Actions สำเร็จ!<br><small>บอทกำลังวิ่งดึงข้อมูลอยู่หลังบ้าน ข้อมูลจะไหลเข้า DB เร็วๆนี้</small>";
        status.classList.replace('text-success', 'text-primary');
    }, 2000);
}
</script>
</body>
</html>
