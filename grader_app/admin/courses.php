<?php

require_once __DIR__ . '/auth.php';

$state = graderapp_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && $pdo;
$error_message = $state['error'] ?? '';

graderapp_admin_require_auth();

$flash = graderapp_admin_consume_flash();
$courseForm = [
    'id' => 0,
    'course_code' => '',
    'course_name' => '',
    'academic_year' => (string) ((int) date('Y') + 543),
    'semester' => '1',
    'owner_user_id' => '',
    'join_code' => '',
    'status' => 'draft',
];
$teachers = [];
$courses = [];

if ($db_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_course') {
            $id = (int) ($_POST['course_id'] ?? 0);
            $payload = [
                ':course_code' => trim((string) ($_POST['course_code'] ?? '')),
                ':course_name' => trim((string) ($_POST['course_name'] ?? '')),
                ':academic_year' => trim((string) ($_POST['academic_year'] ?? '')),
                ':semester' => trim((string) ($_POST['semester'] ?? '')),
                ':owner_user_id' => ($_POST['owner_user_id'] ?? '') === '' ? null : (int) $_POST['owner_user_id'],
                ':join_code' => trim((string) ($_POST['join_code'] ?? '')),
                ':status' => trim((string) ($_POST['status'] ?? 'draft')),
            ];

            if ($payload[':course_code'] === '' || $payload[':course_name'] === '') {
                throw new RuntimeException('กรุณากรอกรหัสวิชาและชื่อวิชาให้ครบ');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE grader_courses
                    SET course_code = :course_code,
                        course_name = :course_name,
                        academic_year = :academic_year,
                        semester = :semester,
                        owner_user_id = :owner_user_id,
                        join_code = :join_code,
                        status = :status
                    WHERE id = :id
                ");
                $payload[':id'] = $id;
                $stmt->execute($payload);
                graderapp_admin_flash('success', 'อัปเดตรายวิชาเรียบร้อยแล้ว');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO grader_courses
                        (course_code, course_name, academic_year, semester, owner_user_id, join_code, status)
                    VALUES
                        (:course_code, :course_name, :academic_year, :semester, :owner_user_id, :join_code, :status)
                ");
                $stmt->execute($payload);
                graderapp_admin_flash('success', 'เพิ่มรายวิชาใหม่เรียบร้อยแล้ว');
            }

            header('Location: ' . graderapp_path('grader.admin.courses'));
            exit;
        }

        if ($action === 'delete_course') {
            $id = (int) ($_POST['course_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ไม่พบรายวิชาที่ต้องการลบ');
            }

            $stmt = $pdo->prepare("DELETE FROM grader_courses WHERE id = :id");
            $stmt->execute([':id' => $id]);
            graderapp_admin_flash('success', 'ลบรายวิชาเรียบร้อยแล้ว');
            header('Location: ' . graderapp_path('grader.admin.courses'));
            exit;
        }
    } catch (Throwable $e) {
        graderapp_admin_flash('danger', $e->getMessage());
        header('Location: ' . graderapp_path('grader.admin.courses'));
        exit;
    }
}

if ($db_ready) {
    $teachers = $pdo->query("
        SELECT id, full_name, email
        FROM grader_users
        WHERE role IN ('teacher', 'admin')
        ORDER BY full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['edit'])) {
        $editId = (int) $_GET['edit'];
        if ($editId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM grader_courses WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $editId]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $courseForm = [
                    'id' => (int) $found['id'],
                    'course_code' => (string) $found['course_code'],
                    'course_name' => (string) $found['course_name'],
                    'academic_year' => (string) $found['academic_year'],
                    'semester' => (string) $found['semester'],
                    'owner_user_id' => $found['owner_user_id'] === null ? '' : (string) $found['owner_user_id'],
                    'join_code' => (string) $found['join_code'],
                    'status' => (string) $found['status'],
                ];
            }
        }
    }

    $courses = $pdo->query("
        SELECT c.*, u.full_name AS owner_name
        FROM grader_courses c
        LEFT JOIN grader_users u ON u.id = c.owner_user_id
        ORDER BY c.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grader Admin - Courses</title>
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
                <h1 class="fw-bold mb-1">จัดการรายวิชา</h1>
                <div class="text-secondary">กำหนดรหัสวิชา ภาคเรียน เจ้าของรายวิชา และสถานะการเปิดใช้ใน grader</div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= htmlspecialchars(graderapp_path('grader.admin')) ?>" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                    <i class="bi bi-arrow-left"></i> กลับ Dashboard
                </a>
                <a href="<?= htmlspecialchars(graderapp_path('grader.admin.modules')) ?>" class="btn btn-dark rounded-pill px-4 fw-bold">
                    <i class="bi bi-collection-fill"></i> ไปหน้าโมดูล
                </a>
            </div>
        </div>

        <ul class="nav nav-pills gap-2 mb-4">
            <li class="nav-item"><a class="nav-link active" href="<?= htmlspecialchars(graderapp_path('grader.admin.courses')) ?>">Courses</a></li>
            <li class="nav-item"><a class="nav-link text-dark bg-white" href="<?= htmlspecialchars(graderapp_path('grader.admin.modules')) ?>">Modules</a></li>
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
                    <h2 class="h4 fw-bold mb-3"><?= $courseForm['id'] > 0 ? 'แก้ไขรายวิชา' : 'เพิ่มรายวิชาใหม่' ?></h2>
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="save_course">
                        <input type="hidden" name="course_id" value="<?= (int) $courseForm['id'] ?>">
                        <div class="col-md-5">
                            <label class="form-label fw-bold">รหัสวิชา</label>
                            <input type="text" name="course_code" class="form-control" value="<?= htmlspecialchars($courseForm['course_code']) ?>" required>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-bold">ชื่อวิชา</label>
                            <input type="text" name="course_name" class="form-control" value="<?= htmlspecialchars($courseForm['course_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ปีการศึกษา</label>
                            <input type="text" name="academic_year" class="form-control" value="<?= htmlspecialchars($courseForm['academic_year']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ภาคเรียน</label>
                            <input type="text" name="semester" class="form-control" value="<?= htmlspecialchars($courseForm['semester']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">อาจารย์เจ้าของวิชา</label>
                            <select name="owner_user_id" class="form-select">
                                <option value="">ยังไม่กำหนด</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?= (int) $teacher['id'] ?>" <?= $courseForm['owner_user_id'] === (string) $teacher['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($teacher['full_name'] . ' (' . $teacher['email'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Join Code</label>
                            <input type="text" name="join_code" class="form-control" value="<?= htmlspecialchars($courseForm['join_code']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">สถานะ</label>
                            <select name="status" class="form-select">
                                <?php foreach (['draft', 'published', 'archived'] as $status): ?>
                                    <option value="<?= $status ?>" <?= $courseForm['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold">
                                <i class="bi bi-save-fill"></i> บันทึก
                            </button>
                            <a href="<?= htmlspecialchars(graderapp_path('grader.admin.courses')) ?>" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">ล้างฟอร์ม</a>
                        </div>
                    </form>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="panel-card p-4 bg-white">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h4 fw-bold mb-0">รายการรายวิชา</h2>
                        <span class="badge text-bg-light"><?= count($courses) ?> รายการ</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>วิชา</th>
                                    <th>ภาคเรียน</th>
                                    <th>เจ้าของวิชา</th>
                                    <th>สถานะ</th>
                                    <th class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courses as $course): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($course['course_code']) ?></div>
                                            <div class="text-secondary small"><?= htmlspecialchars($course['course_name']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars(($course['academic_year'] ?: '-') . '/' . ($course['semester'] ?: '-')) ?></td>
                                        <td><?= htmlspecialchars($course['owner_name'] ?: '-') ?></td>
                                        <td><span class="badge text-bg-light"><?= htmlspecialchars($course['status']) ?></span></td>
                                        <td class="text-end">
                                            <a href="<?= htmlspecialchars(graderapp_path('grader.admin.courses', ['edit' => (int) $course['id']])) ?>" class="btn btn-sm btn-outline-secondary rounded-pill">แก้ไข</a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('ลบรายวิชานี้ใช่หรือไม่');">
                                                <input type="hidden" name="action" value="delete_course">
                                                <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">ลบ</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$courses): ?>
                                    <tr><td colspan="5" class="text-secondary">ยังไม่มีรายวิชาในระบบ</td></tr>
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
