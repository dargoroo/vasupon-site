<?php

require_once __DIR__ . '/auth.php';

$state = graderapp_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && $pdo;
$error_message = $state['error'] ?? '';

graderapp_admin_require_auth();

$flash = graderapp_admin_consume_flash();
$moduleForm = [
    'id' => 0,
    'course_id' => '',
    'title' => '',
    'description' => '',
    'sort_order' => 0,
    'is_active' => 1,
];
$courses = [];
$modules = [];

if ($db_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_module') {
            $id = (int) ($_POST['module_id'] ?? 0);
            $payload = [
                ':course_id' => (int) ($_POST['course_id'] ?? 0),
                ':title' => trim((string) ($_POST['title'] ?? '')),
                ':description' => trim((string) ($_POST['description'] ?? '')),
                ':sort_order' => (int) ($_POST['sort_order'] ?? 0),
                ':is_active' => isset($_POST['is_active']) ? 1 : 0,
            ];

            if ($payload[':course_id'] <= 0 || $payload[':title'] === '') {
                throw new RuntimeException('กรุณาเลือกวิชาและกรอกชื่อโมดูล');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE grader_modules
                    SET course_id = :course_id,
                        title = :title,
                        description = :description,
                        sort_order = :sort_order,
                        is_active = :is_active
                    WHERE id = :id
                ");
                $payload[':id'] = $id;
                $stmt->execute($payload);
                graderapp_admin_flash('success', 'อัปเดตโมดูลเรียบร้อยแล้ว');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO grader_modules (course_id, title, description, sort_order, is_active)
                    VALUES (:course_id, :title, :description, :sort_order, :is_active)
                ");
                $stmt->execute($payload);
                graderapp_admin_flash('success', 'เพิ่มโมดูลใหม่เรียบร้อยแล้ว');
            }

            header('Location: ' . graderapp_path('grader.admin.modules'));
            exit;
        }

        if ($action === 'delete_module') {
            $id = (int) ($_POST['module_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ไม่พบโมดูลที่ต้องการลบ');
            }

            $stmt = $pdo->prepare("DELETE FROM grader_modules WHERE id = :id");
            $stmt->execute([':id' => $id]);
            graderapp_admin_flash('success', 'ลบโมดูลเรียบร้อยแล้ว');
            header('Location: ' . graderapp_path('grader.admin.modules'));
            exit;
        }
    } catch (Throwable $e) {
        graderapp_admin_flash('danger', $e->getMessage());
        header('Location: ' . graderapp_path('grader.admin.modules'));
        exit;
    }
}

if ($db_ready) {
    $courses = $pdo->query("
        SELECT id, course_code, course_name
        FROM grader_courses
        ORDER BY course_code ASC, course_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['edit'])) {
        $editId = (int) $_GET['edit'];
        if ($editId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM grader_modules WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $editId]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $moduleForm = [
                    'id' => (int) $found['id'],
                    'course_id' => (string) $found['course_id'],
                    'title' => (string) $found['title'],
                    'description' => (string) $found['description'],
                    'sort_order' => (int) $found['sort_order'],
                    'is_active' => (int) $found['is_active'],
                ];
            }
        }
    }

    $modules = $pdo->query("
        SELECT m.*, c.course_code, c.course_name
        FROM grader_modules m
        INNER JOIN grader_courses c ON c.id = m.course_id
        ORDER BY c.course_code ASC, m.sort_order ASC, m.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grader Admin - Modules</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { font-family: "Sarabun", system-ui, sans-serif; background: #f5f8fc; color: #16263a; }
        .panel-card { border: 0; border-radius: 28px; box-shadow: 0 18px 46px rgba(17,39,67,.08); }
        .nav-pills .nav-link { border-radius: 999px; font-weight: 700; }
    </style>
</head>
<body>
    <div class="container py-4 py-lg-5">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
            <div>
                <h1 class="fw-bold mb-1">จัดการโมดูล</h1>
                <div class="text-secondary">สร้างโครงบทเรียนหรือ chapter ภายใต้รายวิชา ก่อนเชื่อมไปยังโจทย์แต่ละข้อ</div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= htmlspecialchars(graderapp_path('grader.admin')) ?>" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                    <i class="bi bi-arrow-left"></i> กลับ Dashboard
                </a>
                <a href="<?= htmlspecialchars(graderapp_path('grader.admin.problems')) ?>" class="btn btn-dark rounded-pill px-4 fw-bold">
                    <i class="bi bi-code-slash"></i> ไปหน้าโจทย์
                </a>
            </div>
        </div>

        <ul class="nav nav-pills gap-2 mb-4">
            <li class="nav-item"><a class="nav-link text-dark bg-white" href="<?= htmlspecialchars(graderapp_path('grader.admin.courses')) ?>">Courses</a></li>
            <li class="nav-item"><a class="nav-link active" href="<?= htmlspecialchars(graderapp_path('grader.admin.modules')) ?>">Modules</a></li>
            <li class="nav-item"><a class="nav-link text-dark bg-white" href="<?= htmlspecialchars(graderapp_path('grader.admin.problems')) ?>">Problems</a></li>
        </ul>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> rounded-4"><?= htmlspecialchars($flash['message']) ?></div>
        <?php endif; ?>
        <?php if (!$db_ready): ?>
            <div class="alert alert-danger rounded-4"><code><?= htmlspecialchars($error_message) ?></code></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="panel-card p-4 bg-white">
                    <h2 class="h4 fw-bold mb-3"><?= $moduleForm['id'] > 0 ? 'แก้ไขโมดูล' : 'เพิ่มโมดูลใหม่' ?></h2>
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="save_module">
                        <input type="hidden" name="module_id" value="<?= (int) $moduleForm['id'] ?>">
                        <div class="col-12">
                            <label class="form-label fw-bold">รายวิชา</label>
                            <select name="course_id" class="form-select" required>
                                <option value="">เลือกวิชา</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= (int) $course['id'] ?>" <?= $moduleForm['course_id'] === (string) $course['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($course['course_code'] . ' ' . $course['course_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">ชื่อโมดูล</label>
                            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($moduleForm['title']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">คำอธิบาย</label>
                            <textarea name="description" rows="4" class="form-control"><?= htmlspecialchars($moduleForm['description']) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ลำดับ</label>
                            <input type="number" name="sort_order" class="form-control" value="<?= (int) $moduleForm['sort_order'] ?>">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="moduleActive" name="is_active" <?= (int) $moduleForm['is_active'] === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="moduleActive">เปิดใช้งานโมดูล</label>
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold">
                                <i class="bi bi-save-fill"></i> บันทึก
                            </button>
                            <a href="<?= htmlspecialchars(graderapp_path('grader.admin.modules')) ?>" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">ล้างฟอร์ม</a>
                        </div>
                    </form>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="panel-card p-4 bg-white">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h4 fw-bold mb-0">รายการโมดูล</h2>
                        <span class="badge text-bg-light"><?= count($modules) ?> รายการ</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>โมดูล</th>
                                    <th>วิชา</th>
                                    <th>ลำดับ</th>
                                    <th>สถานะ</th>
                                    <th class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($modules as $module): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($module['title']) ?></div>
                                            <div class="text-secondary small"><?= htmlspecialchars($module['description'] ?: '-') ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($module['course_code'] . ' ' . $module['course_name']) ?></td>
                                        <td><?= (int) $module['sort_order'] ?></td>
                                        <td><span class="badge text-bg-light"><?= (int) $module['is_active'] === 1 ? 'active' : 'hidden' ?></span></td>
                                        <td class="text-end">
                                            <a href="<?= htmlspecialchars(graderapp_path('grader.admin.modules', ['edit' => (int) $module['id']])) ?>" class="btn btn-sm btn-outline-secondary rounded-pill">แก้ไข</a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('ลบโมดูลนี้ใช่หรือไม่');">
                                                <input type="hidden" name="action" value="delete_module">
                                                <input type="hidden" name="module_id" value="<?= (int) $module['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">ลบ</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$modules): ?>
                                    <tr><td colspan="5" class="text-secondary">ยังไม่มีโมดูลในระบบ</td></tr>
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
