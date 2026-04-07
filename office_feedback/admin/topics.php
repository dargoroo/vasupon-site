<?php

require_once __DIR__ . '/auth.php';

officefb_admin_require_auth();

$state = officefb_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && officefb_table_exists($pdo, 'officefb_topics');
$error_message = $state['error'];

if (!$db_ready) {
    officefb_admin_flash('danger', 'ระบบยังไม่สามารถเตรียมตาราง officefb_topics อัตโนมัติได้ กรุณาตรวจสอบ config.php หรือสิทธิ์ฐานข้อมูล');
    header('Location: ' . officefb_path('admin.home'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'save_topic') {
            $topic_id = isset($_POST['topic_id']) ? (int) $_POST['topic_id'] : 0;
            $topic_name = trim((string) ($_POST['topic_name'] ?? ''));
            $display_order = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($topic_name === '') {
                throw new RuntimeException('กรุณากรอกชื่อหัวข้อบริการ');
            }

            if ($topic_id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE officefb_topics
                    SET topic_name = :topic_name,
                        display_order = :display_order,
                        is_active = :is_active
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':topic_name' => $topic_name,
                    ':display_order' => $display_order,
                    ':is_active' => $is_active,
                    ':id' => $topic_id,
                ]);
                officefb_admin_flash('success', 'อัปเดตหัวข้อบริการเรียบร้อยแล้ว');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO officefb_topics (topic_name, display_order, is_active)
                    VALUES (:topic_name, :display_order, :is_active)
                ");
                $stmt->execute([
                    ':topic_name' => $topic_name,
                    ':display_order' => $display_order,
                    ':is_active' => $is_active,
                ]);
                officefb_admin_flash('success', 'เพิ่มหัวข้อบริการใหม่เรียบร้อยแล้ว');
            }
        } elseif ($action === 'delete_topic') {
            $topic_id = isset($_POST['topic_id']) ? (int) $_POST['topic_id'] : 0;
            if ($topic_id <= 0) {
                throw new RuntimeException('ไม่พบรหัสหัวข้อบริการที่ต้องการลบ');
            }

            $stmt = $pdo->prepare("DELETE FROM officefb_topics WHERE id = :id");
            $stmt->execute([':id' => $topic_id]);
            officefb_admin_flash('success', 'ลบหัวข้อบริการเรียบร้อยแล้ว');
        } elseif ($action === 'toggle_active') {
            $topic_id = isset($_POST['topic_id']) ? (int) $_POST['topic_id'] : 0;
            if ($topic_id <= 0) {
                throw new RuntimeException('ไม่พบรหัสหัวข้อบริการที่ต้องการเปลี่ยนสถานะ');
            }

            $stmt = $pdo->prepare("
                UPDATE officefb_topics
                SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
                WHERE id = :id
            ");
            $stmt->execute([':id' => $topic_id]);
            officefb_admin_flash('success', 'เปลี่ยนสถานะหัวข้อบริการเรียบร้อยแล้ว');
        } elseif ($action === 'reset_default_topics') {
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

    header('Location: ' . officefb_path('admin.topics'));
    exit;
}

$flash = officefb_admin_consume_flash();
$topic_rows = [];
$editing = null;
$editing_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

try {
    $stmt = $pdo->query("
        SELECT id, topic_name, display_order, is_active
        FROM officefb_topics
        ORDER BY display_order ASC, id ASC
    ");
    $topic_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($editing_id > 0) {
        foreach ($topic_rows as $row) {
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
        'topic_name' => '',
        'display_order' => count($topic_rows) + 1,
        'is_active' => 1,
    ];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการหัวข้อบริการ</title>
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
        .status-chip {
            border-radius: 999px;
            padding: .35rem .7rem;
            font-weight: 700;
            font-size: .9rem;
        }
        .topic-name-cell {
            max-width: 0;
            white-space: normal;
            word-break: break-word;
            overflow-wrap: anywhere;
            line-height: 1.45;
        }
    </style>
</head>
<body>
    <div class="hero">
        <div class="container d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <div class="small text-uppercase fw-bold opacity-75 mb-2">Office Feedback Admin</div>
                <h1 class="fw-bold mb-2">จัดการหัวข้อบริการ</h1>
                <p class="mb-0 opacity-75">เพิ่ม แก้ไข ลำดับ ลบ และเปิด/ปิดหัวข้อที่จะแสดงเป็นตัวเลือกบน kiosk</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="<?= htmlspecialchars(officefb_path('admin.home')) ?>" class="btn btn-light rounded-pill px-4">
                    <i class="bi bi-speedometer2"></i> กลับ Dashboard
                </a>
                <a href="<?= htmlspecialchars(officefb_path('admin.staff')) ?>" class="btn btn-outline-light rounded-pill px-4">
                    <i class="bi bi-people-fill"></i> จัดการเจ้าหน้าที่
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
                        <h3 class="fw-bold mb-0"><?= (int) $editing['id'] > 0 ? 'แก้ไขหัวข้อบริการ' : 'เพิ่มหัวข้อบริการใหม่' ?></h3>
                        <?php if ((int) $editing['id'] > 0): ?>
                            <a href="<?= htmlspecialchars(officefb_path('admin.topics')) ?>" class="btn btn-outline-secondary rounded-pill">
                                <i class="bi bi-plus-circle"></i> ฟอร์มใหม่
                            </a>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="save_topic">
                        <input type="hidden" name="topic_id" value="<?= (int) $editing['id'] ?>">

                        <div class="col-12">
                            <label class="form-label fw-bold">ชื่อหัวข้อบริการ</label>
                            <input type="text" class="form-control" name="topic_name" value="<?= htmlspecialchars($editing['topic_name']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">ลำดับการแสดงผล</label>
                            <input type="number" class="form-control" name="display_order" value="<?= (int) $editing['display_order'] ?>">
                        </div>

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= (int) $editing['is_active'] === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="is_active">เปิดให้แสดงบน kiosk</label>
                            </div>
                        </div>

                        <div class="col-12 d-grid">
                            <button type="submit" class="btn btn-dark btn-lg rounded-pill fw-bold">
                                <i class="bi bi-save2-fill"></i> บันทึกหัวข้อบริการ
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card panel-card p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <h3 class="fw-bold mb-2">เครื่องมือมาตรฐาน</h3>
                            <div class="text-muted">ใช้รีเซ็ตกลับเป็นหัวข้อบริการชุดมาตรฐานของระบบเมื่อข้อมูลเพี้ยนหรือถูกแก้จนอยากเริ่มใหม่</div>
                        </div>
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
                        <h3 class="fw-bold mb-0">หัวข้อบริการปัจจุบัน</h3>
                        <span class="text-muted small">ทั้งหมด <?= count($topic_rows) ?> รายการ</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 56%;">หัวข้อบริการ</th>
                                    <th style="width: 10%;">ลำดับ</th>
                                    <th style="width: 16%;">สถานะ</th>
                                    <th class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topic_rows)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีหัวข้อบริการ</td></tr>
                                <?php else: ?>
                                    <?php foreach ($topic_rows as $row): ?>
                                        <tr>
                                            <td class="fw-bold topic-name-cell"><?= nl2br(htmlspecialchars($row['topic_name'])) ?></td>
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
                                                    <a href="<?= htmlspecialchars(officefb_path('admin.topics', ['edit' => (int) $row['id']])) ?>" class="btn btn-outline-primary btn-sm rounded-pill">
                                                        <i class="bi bi-pencil-square"></i> แก้ไข
                                                    </a>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="topic_id" value="<?= (int) $row['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-dark btn-sm rounded-pill">
                                                            <i class="bi bi-arrow-repeat"></i> สลับสถานะ
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('ต้องการลบหัวข้อบริการนี้ออกจากระบบใช่หรือไม่');">
                                                        <input type="hidden" name="action" value="delete_topic">
                                                        <input type="hidden" name="topic_id" value="<?= (int) $row['id'] ?>">
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
