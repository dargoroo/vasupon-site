<?php

require_once __DIR__ . '/auth.php';

officefb_admin_require_auth();

$state = officefb_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && officefb_table_exists($pdo, 'officefb_staff');
$error_message = $state['error'];

if (!$db_ready) {
    officefb_admin_flash('danger', 'ระบบยังไม่สามารถเตรียมตาราง officefb_staff อัตโนมัติได้ กรุณาตรวจสอบ config.php หรือสิทธิ์ฐานข้อมูล');
    header('Location: ' . officefb_path('admin.home'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'save_staff') {
            $staff_id = isset($_POST['staff_id']) ? (int) $_POST['staff_id'] : 0;
            $full_name = trim((string) ($_POST['full_name'] ?? ''));
            $position_name = trim((string) ($_POST['position_name'] ?? ''));
            $photo_url = trim((string) ($_POST['photo_url'] ?? ''));
            $department_name = trim((string) ($_POST['department_name'] ?? 'สำนักงานคณะ'));
            $service_area = trim((string) ($_POST['service_area'] ?? ''));
            $display_order = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($full_name === '' || $position_name === '') {
                throw new RuntimeException('กรุณากรอกชื่อและตำแหน่งของเจ้าหน้าที่ให้ครบ');
            }

            if ($staff_id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE officefb_staff
                    SET full_name = :full_name,
                        position_name = :position_name,
                        photo_url = :photo_url,
                        department_name = :department_name,
                        service_area = :service_area,
                        display_order = :display_order,
                        is_active = :is_active
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':full_name' => $full_name,
                    ':position_name' => $position_name,
                    ':photo_url' => $photo_url,
                    ':department_name' => $department_name,
                    ':service_area' => $service_area,
                    ':display_order' => $display_order,
                    ':is_active' => $is_active,
                    ':id' => $staff_id,
                ]);
                officefb_admin_flash('success', 'อัปเดตรายชื่อเจ้าหน้าที่เรียบร้อยแล้ว');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO officefb_staff
                        (full_name, position_name, photo_url, department_name, service_area, display_order, is_active)
                    VALUES
                        (:full_name, :position_name, :photo_url, :department_name, :service_area, :display_order, :is_active)
                ");
                $stmt->execute([
                    ':full_name' => $full_name,
                    ':position_name' => $position_name,
                    ':photo_url' => $photo_url,
                    ':department_name' => $department_name,
                    ':service_area' => $service_area,
                    ':display_order' => $display_order,
                    ':is_active' => $is_active,
                ]);
                officefb_admin_flash('success', 'เพิ่มเจ้าหน้าที่ใหม่เรียบร้อยแล้ว');
            }
        } elseif ($action === 'delete_staff') {
            $staff_id = isset($_POST['staff_id']) ? (int) $_POST['staff_id'] : 0;
            if ($staff_id <= 0) {
                throw new RuntimeException('ไม่พบรหัสเจ้าหน้าที่ที่ต้องการลบ');
            }

            $stmt = $pdo->prepare("DELETE FROM officefb_staff WHERE id = :id");
            $stmt->execute([':id' => $staff_id]);
            officefb_admin_flash('success', 'ลบรายชื่อเจ้าหน้าที่เรียบร้อยแล้ว');
        } elseif ($action === 'toggle_active') {
            $staff_id = isset($_POST['staff_id']) ? (int) $_POST['staff_id'] : 0;
            if ($staff_id <= 0) {
                throw new RuntimeException('ไม่พบรหัสเจ้าหน้าที่ที่ต้องการเปลี่ยนสถานะ');
            }

            $stmt = $pdo->prepare("
                UPDATE officefb_staff
                SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
                WHERE id = :id
            ");
            $stmt->execute([':id' => $staff_id]);
            officefb_admin_flash('success', 'เปลี่ยนสถานะการแสดงผลบน kiosk เรียบร้อยแล้ว');
        } elseif ($action === 'seed_default_staff') {
            $seed_rows = officefb_default_staff_seed();
            $stmt = $pdo->prepare("
                SELECT id
                FROM officefb_staff
                WHERE full_name = :full_name
                LIMIT 1
            ");
            $insertStmt = $pdo->prepare("
                INSERT INTO officefb_staff
                    (full_name, position_name, photo_url, department_name, service_area, display_order, is_active)
                VALUES
                    (:full_name, :position_name, :photo_url, :department_name, :service_area, :display_order, :is_active)
            ");
            $updateStmt = $pdo->prepare("
                UPDATE officefb_staff
                SET position_name = :position_name,
                    photo_url = :photo_url,
                    department_name = :department_name,
                    service_area = :service_area,
                    display_order = :display_order,
                    is_active = :is_active
                WHERE id = :id
            ");

            $inserted = 0;
            $updated = 0;

            foreach ($seed_rows as $row) {
                $stmt->execute([':full_name' => $row['full_name']]);
                $existingId = (int) $stmt->fetchColumn();

                if ($existingId > 0) {
                    $updateStmt->execute([
                        ':position_name' => $row['position_name'],
                        ':photo_url' => $row['photo_url'],
                        ':department_name' => $row['department_name'],
                        ':service_area' => $row['service_area'],
                        ':display_order' => $row['display_order'],
                        ':is_active' => $row['is_active'],
                        ':id' => $existingId,
                    ]);
                    $updated++;
                } else {
                    $insertStmt->execute([
                        ':full_name' => $row['full_name'],
                        ':position_name' => $row['position_name'],
                        ':photo_url' => $row['photo_url'],
                        ':department_name' => $row['department_name'],
                        ':service_area' => $row['service_area'],
                        ':display_order' => $row['display_order'],
                        ':is_active' => $row['is_active'],
                    ]);
                    $inserted++;
                }
            }

            officefb_admin_flash('success', "เติมข้อมูลเจ้าหน้าที่มาตรฐานของคณะแล้ว (เพิ่ม {$inserted} / อัปเดต {$updated})");
        } elseif ($action === 'reset_default_staff') {
            $seed_rows = officefb_default_staff_seed();
            $seed_names = array_map(function ($row) {
                return $row['full_name'];
            }, $seed_rows);

            if (empty($seed_names)) {
                throw new RuntimeException('ไม่พบข้อมูลมาตรฐานสำหรับรีเซ็ต');
            }

            $placeholders = implode(', ', array_fill(0, count($seed_names), '?'));
            $deleteStmt = $pdo->prepare("
                DELETE FROM officefb_staff
                WHERE department_name = 'สำนักงานคณะ'
                  AND full_name IN ($placeholders)
            ");
            $deleteStmt->execute($seed_names);
            $deleted = $deleteStmt->rowCount();

            $insertStmt = $pdo->prepare("
                INSERT INTO officefb_staff
                    (full_name, position_name, photo_url, department_name, service_area, display_order, is_active)
                VALUES
                    (:full_name, :position_name, :photo_url, :department_name, :service_area, :display_order, :is_active)
            ");

            foreach ($seed_rows as $row) {
                $insertStmt->execute([
                    ':full_name' => $row['full_name'],
                    ':position_name' => $row['position_name'],
                    ':photo_url' => $row['photo_url'],
                    ':department_name' => $row['department_name'],
                    ':service_area' => $row['service_area'],
                    ':display_order' => $row['display_order'],
                    ':is_active' => $row['is_active'],
                ]);
            }

            officefb_admin_flash('success', "รีเซ็ตชุดมาตรฐานของสำนักงานคณะแล้ว (ลบ {$deleted} และเติมใหม่ " . count($seed_rows) . " รายการ)");
        } elseif ($action === 'reset_default_topics') {
            if (!officefb_table_exists($pdo, 'officefb_topics')) {
                throw new RuntimeException('ไม่พบตาราง officefb_topics สำหรับรีเซ็ตหัวข้อบริการ');
            }

            $deleted = $pdo->exec("DELETE FROM officefb_topics");
            $seed_topics = officefb_default_topics_seed();
            $insertStmt = $pdo->prepare("
                INSERT INTO officefb_topics
                    (topic_name, display_order, is_active)
                VALUES
                    (:topic_name, :display_order, :is_active)
            ");

            foreach ($seed_topics as $topic) {
                $insertStmt->execute([
                    ':topic_name' => $topic['topic_name'],
                    ':display_order' => $topic['display_order'],
                    ':is_active' => $topic['is_active'],
                ]);
            }

            officefb_admin_flash('success', "รีเซ็ตหัวข้อบริการมาตรฐานแล้ว (ลบ {$deleted} และเติมใหม่ " . count($seed_topics) . " รายการ)");
        }
    } catch (Throwable $e) {
        officefb_admin_flash('danger', $e->getMessage());
    }

    header('Location: ' . officefb_path('admin.staff'));
    exit;
}

$flash = officefb_admin_consume_flash();
$staff_rows = [];
$editing = null;
$editing_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

try {
    $stmt = $pdo->query("
        SELECT id, full_name, position_name, photo_url, department_name, service_area, display_order, is_active
        FROM officefb_staff
        ORDER BY display_order ASC, id ASC
    ");
    $staff_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($editing_id > 0) {
        foreach ($staff_rows as $row) {
            if ((int) $row['id'] === $editing_id) {
                $editing = $row;
                break;
            }
        }
    }
} catch (Throwable $e) {
    $error_message = $e->getMessage();
}

if ($editing === null) {
    $editing = [
        'id' => 0,
        'full_name' => '',
        'position_name' => '',
        'photo_url' => '',
        'department_name' => 'สำนักงานคณะ',
        'service_area' => '',
        'display_order' => count($staff_rows) + 1,
        'is_active' => 1,
    ];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการรายชื่อเจ้าหน้าที่</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(180deg, #f6f0e8 0%, #f9fbff 100%);
            font-family: "Sarabun", system-ui, sans-serif;
            color: #2d241b;
        }
        .hero {
            background: linear-gradient(135deg, #473018 0%, #8d5b2a 100%);
            color: white;
            border-radius: 0 0 28px 28px;
            padding: 2rem 0 1.5rem;
            margin-bottom: 1.5rem;
        }
        .panel-card {
            border: 0;
            border-radius: 24px;
            box-shadow: 0 18px 40px rgba(59, 34, 17, 0.09);
        }
        .staff-preview {
            width: 88px;
            height: 88px;
            border-radius: 22px;
            object-fit: cover;
            background: #ead8c4;
        }
        .status-chip {
            border-radius: 999px;
            padding: .35rem .7rem;
            font-weight: 700;
            font-size: .9rem;
        }
    </style>
</head>
<body>
    <div class="hero">
        <div class="container d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <div class="small text-uppercase fw-bold opacity-75 mb-2">Office Feedback Admin</div>
                <h1 class="fw-bold mb-2">จัดการรายชื่อเจ้าหน้าที่</h1>
                <p class="mb-0 opacity-75">เพิ่ม แก้ไข ลำดับ และสถานะการแสดงผลของเจ้าหน้าที่บน kiosk</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="<?= htmlspecialchars(officefb_path('admin.home')) ?>" class="btn btn-light rounded-pill px-4">
                    <i class="bi bi-speedometer2"></i> กลับ Dashboard
                </a>
                <a href="<?= htmlspecialchars(officefb_path('admin.topics')) ?>" class="btn btn-outline-light rounded-pill px-4">
                    <i class="bi bi-card-checklist"></i> จัดการหัวข้อบริการ
                </a>
                <a href="<?= htmlspecialchars(officefb_path('kiosk.home')) ?>" class="btn btn-outline-light rounded-pill px-4">
                    <i class="bi bi-tablet-landscape"></i> ดูหน้า Kiosk
                </a>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> panel-card p-3">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message !== ''): ?>
            <div class="alert alert-danger panel-card p-3"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card panel-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="fw-bold mb-0"><?= (int) $editing['id'] > 0 ? 'แก้ไขเจ้าหน้าที่' : 'เพิ่มเจ้าหน้าที่ใหม่' ?></h3>
                        <?php if ((int) $editing['id'] > 0): ?>
                            <a href="<?= htmlspecialchars(officefb_path('admin.staff')) ?>" class="btn btn-outline-secondary rounded-pill">
                                <i class="bi bi-plus-circle"></i> ฟอร์มใหม่
                            </a>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="save_staff">
                        <input type="hidden" name="staff_id" value="<?= (int) $editing['id'] ?>">

                        <div class="col-12">
                            <label class="form-label fw-bold">ชื่อ-นามสกุล</label>
                            <input type="text" class="form-control" name="full_name" value="<?= htmlspecialchars($editing['full_name']) ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">ตำแหน่ง</label>
                            <input type="text" class="form-control" name="position_name" value="<?= htmlspecialchars($editing['position_name']) ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">ลิงก์รูปภาพ</label>
                            <input type="text" class="form-control" name="photo_url" value="<?= htmlspecialchars($editing['photo_url']) ?>" placeholder="/imgs/staff.jpg หรือ https://...">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">หน่วยงาน</label>
                            <input type="text" class="form-control" name="department_name" value="<?= htmlspecialchars($editing['department_name']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">ลำดับการแสดงผล</label>
                            <input type="number" class="form-control" name="display_order" value="<?= (int) $editing['display_order'] ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">ขอบเขตงานบริการ / service area</label>
                            <input type="text" class="form-control" name="service_area" value="<?= htmlspecialchars($editing['service_area']) ?>" placeholder="เช่น งานธุรการทั่วไป / งานเอกสาร / งานบริการนักศึกษา">
                        </div>

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= (int) $editing['is_active'] === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="is_active">เปิดให้แสดงบนหน้า kiosk</label>
                            </div>
                        </div>

                        <div class="col-12 d-grid">
                            <button type="submit" class="btn btn-dark btn-lg rounded-pill fw-bold">
                                <i class="bi bi-save2-fill"></i> บันทึกรายชื่อเจ้าหน้าที่
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card panel-card p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <h3 class="fw-bold mb-2">ชุดข้อมูลมาตรฐานของคณะ</h3>
                            <div class="text-muted">อ้างอิงรายชื่อและตำแหน่งจากหน้า Faculty and Staff ของคณะ เพื่อช่วยตั้งต้นระบบให้ตรงข้อมูลจริงเร็วขึ้น</div>
                            <div class="small text-muted mt-2">รูปเริ่มต้นจะชี้ไปที่โดเมน `www.csit.rbru.ac.th/faculty-staff/imgs/...` และยังแก้รายบุคคลได้ภายหลัง</div>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="seed_default_staff">
                            <button type="submit" class="btn btn-warning rounded-pill fw-bold" onclick="return confirm('ต้องการเติมหรืออัปเดตรายชื่อเจ้าหน้าที่ชุดมาตรฐานของคณะใช่หรือไม่');">
                                <i class="bi bi-stars"></i> เติม/อัปเดตชุดมาตรฐาน
                            </button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="action" value="reset_default_staff">
                            <button type="submit" class="btn btn-outline-danger rounded-pill fw-bold" onclick="return confirm('ต้องการรีเซ็ตชุดข้อมูลเจ้าหน้าที่มาตรฐานทั้งหมดใช่หรือไม่ ระบบจะลบเฉพาะรายชื่อมาตรฐานของสำนักงานคณะแล้วเติมใหม่ทันที');">
                                <i class="bi bi-arrow-clockwise"></i> รีเซ็ตข้อมูลเจ้าหน้าที่มาตรฐานทั้งหมด
                            </button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="action" value="reset_default_topics">
                            <button type="submit" class="btn btn-outline-secondary rounded-pill fw-bold" onclick="return confirm('ต้องการรีเซ็ตหัวข้อบริการมาตรฐานทั้งหมดใช่หรือไม่ ระบบจะลบหัวข้อเดิมใน officefb_topics แล้วเติมใหม่ทันที');">
                                <i class="bi bi-card-checklist"></i> รีเซ็ตหัวข้อบริการมาตรฐาน
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card panel-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="fw-bold mb-0">รายชื่อปัจจุบัน</h3>
                        <span class="text-muted small">ทั้งหมด <?= count($staff_rows) ?> รายการ</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>เจ้าหน้าที่</th>
                                    <th>ลำดับ</th>
                                    <th>สถานะ</th>
                                    <th class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($staff_rows)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีรายชื่อเจ้าหน้าที่</td></tr>
                                <?php else: ?>
                                    <?php foreach ($staff_rows as $row): ?>
                                        <?php
                                        $photo_url = trim((string) $row['photo_url']);
                                        if ($photo_url === '') {
                                            $photo_url = 'https://placehold.co/300x300/ead8c4/6a3f14?text=Staff';
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex gap-3 align-items-center">
                                                    <img
                                                        src="<?= htmlspecialchars($photo_url) ?>"
                                                        alt="<?= htmlspecialchars($row['full_name']) ?>"
                                                        class="staff-preview"
                                                        onerror="this.onerror=null;this.src='https://placehold.co/300x300/ead8c4/6a3f14?text=Staff';"
                                                    >
                                                    <div>
                                                        <div class="fw-bold"><?= htmlspecialchars($row['full_name']) ?></div>
                                                        <div class="small text-muted"><?= htmlspecialchars($row['position_name']) ?></div>
                                                        <div class="small text-muted"><?= htmlspecialchars($row['department_name']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?= (int) $row['display_order'] ?></td>
                                            <td>
                                                <?php if ((int) $row['is_active'] === 1): ?>
                                                    <span class="badge bg-success status-chip">กำลังแสดงบน kiosk</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary status-chip">ซ่อนจาก kiosk</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                    <a href="<?= htmlspecialchars(officefb_path('admin.staff', ['edit' => (int) $row['id']])) ?>" class="btn btn-outline-primary btn-sm rounded-pill">
                                                        <i class="bi bi-pencil-square"></i> แก้ไข
                                                    </a>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="staff_id" value="<?= (int) $row['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-dark btn-sm rounded-pill">
                                                            <i class="bi bi-arrow-repeat"></i> สลับสถานะ
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('ต้องการลบเจ้าหน้าที่คนนี้ออกจากระบบใช่หรือไม่');">
                                                        <input type="hidden" name="action" value="delete_staff">
                                                        <input type="hidden" name="staff_id" value="<?= (int) $row['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill">
                                                            <i class="bi bi-trash3"></i> ลบ
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
