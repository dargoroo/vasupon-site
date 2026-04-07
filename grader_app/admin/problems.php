<?php

require_once __DIR__ . '/auth.php';

$state = graderapp_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && $pdo;
$error_message = $state['error'] ?? '';

graderapp_admin_require_auth();

$flash = graderapp_admin_consume_flash();
$problemForm = [
    'id' => 0,
    'module_id' => '',
    'title' => '',
    'slug' => '',
    'description_md' => '',
    'starter_code' => '',
    'language' => 'python',
    'time_limit_sec' => '2.00',
    'memory_limit_mb' => '128',
    'max_score' => '100',
    'visibility' => 'draft',
    'sort_order' => 0,
];
$modules = [];
$problems = [];

if ($db_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_problem') {
            $id = (int) ($_POST['problem_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $slug = trim((string) ($_POST['slug'] ?? ''));
            $slug = $slug !== '' ? graderapp_slugify($slug) : graderapp_slugify($title);

            $payload = [
                ':module_id' => (int) ($_POST['module_id'] ?? 0),
                ':title' => $title,
                ':slug' => $slug,
                ':description_md' => trim((string) ($_POST['description_md'] ?? '')),
                ':starter_code' => (string) ($_POST['starter_code'] ?? ''),
                ':language' => trim((string) ($_POST['language'] ?? 'python')),
                ':time_limit_sec' => (float) ($_POST['time_limit_sec'] ?? 2),
                ':memory_limit_mb' => (int) ($_POST['memory_limit_mb'] ?? 128),
                ':max_score' => (int) ($_POST['max_score'] ?? 100),
                ':visibility' => trim((string) ($_POST['visibility'] ?? 'draft')),
                ':sort_order' => (int) ($_POST['sort_order'] ?? 0),
            ];

            if ($payload[':module_id'] <= 0 || $payload[':title'] === '') {
                throw new RuntimeException('กรุณาเลือกโมดูลและกรอกชื่อโจทย์');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE grader_problems
                    SET module_id = :module_id,
                        title = :title,
                        slug = :slug,
                        description_md = :description_md,
                        starter_code = :starter_code,
                        language = :language,
                        time_limit_sec = :time_limit_sec,
                        memory_limit_mb = :memory_limit_mb,
                        max_score = :max_score,
                        visibility = :visibility,
                        sort_order = :sort_order
                    WHERE id = :id
                ");
                $payload[':id'] = $id;
                $stmt->execute($payload);
                graderapp_admin_flash('success', 'อัปเดตโจทย์เรียบร้อยแล้ว');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO grader_problems
                        (module_id, title, slug, description_md, starter_code, language, time_limit_sec, memory_limit_mb, max_score, visibility, sort_order)
                    VALUES
                        (:module_id, :title, :slug, :description_md, :starter_code, :language, :time_limit_sec, :memory_limit_mb, :max_score, :visibility, :sort_order)
                ");
                $stmt->execute($payload);
                graderapp_admin_flash('success', 'เพิ่มโจทย์ใหม่เรียบร้อยแล้ว');
            }

            header('Location: ' . graderapp_path('grader.admin.problems'));
            exit;
        }

        if ($action === 'delete_problem') {
            $id = (int) ($_POST['problem_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ไม่พบโจทย์ที่ต้องการลบ');
            }

            $stmt = $pdo->prepare("DELETE FROM grader_problems WHERE id = :id");
            $stmt->execute([':id' => $id]);
            graderapp_admin_flash('success', 'ลบโจทย์เรียบร้อยแล้ว');
            header('Location: ' . graderapp_path('grader.admin.problems'));
            exit;
        }
    } catch (Throwable $e) {
        graderapp_admin_flash('danger', $e->getMessage());
        header('Location: ' . graderapp_path('grader.admin.problems'));
        exit;
    }
}

if ($db_ready) {
    $modules = $pdo->query("
        SELECT m.id, m.title, c.course_code, c.course_name
        FROM grader_modules m
        INNER JOIN grader_courses c ON c.id = m.course_id
        ORDER BY c.course_code ASC, m.sort_order ASC, m.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['edit'])) {
        $editId = (int) $_GET['edit'];
        if ($editId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM grader_problems WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $editId]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $problemForm = [
                    'id' => (int) $found['id'],
                    'module_id' => (string) $found['module_id'],
                    'title' => (string) $found['title'],
                    'slug' => (string) $found['slug'],
                    'description_md' => (string) $found['description_md'],
                    'starter_code' => (string) $found['starter_code'],
                    'language' => (string) $found['language'],
                    'time_limit_sec' => (string) $found['time_limit_sec'],
                    'memory_limit_mb' => (string) $found['memory_limit_mb'],
                    'max_score' => (string) $found['max_score'],
                    'visibility' => (string) $found['visibility'],
                    'sort_order' => (int) $found['sort_order'],
                ];
            }
        }
    }

    $problems = $pdo->query("
        SELECT p.*, m.title AS module_title, c.course_code,
               (SELECT COUNT(*) FROM grader_test_cases tc WHERE tc.problem_id = p.id) AS test_case_count
        FROM grader_problems p
        INNER JOIN grader_modules m ON m.id = p.module_id
        INNER JOIN grader_courses c ON c.id = m.course_id
        ORDER BY c.course_code ASC, m.sort_order ASC, p.sort_order ASC, p.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grader Admin - Problems</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { font-family: "Sarabun", system-ui, sans-serif; background: #f5f8fc; color: #16263a; }
        .panel-card { border: 0; border-radius: 28px; box-shadow: 0 18px 46px rgba(17,39,67,.08); }
        .nav-pills .nav-link { border-radius: 999px; font-weight: 700; }
        textarea.code-box { min-height: 160px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    </style>
</head>
<body>
    <div class="container py-4 py-lg-5">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
            <div>
                <h1 class="fw-bold mb-1">จัดการโจทย์</h1>
                <div class="text-secondary">ตั้งค่าโจทย์ ลำดับการแสดงผล ภาษา เวลา หน่วยความจำ และ starter code สำหรับ student</div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= htmlspecialchars(graderapp_path('grader.admin')) ?>" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                    <i class="bi bi-arrow-left"></i> กลับ Dashboard
                </a>
            </div>
        </div>

        <ul class="nav nav-pills gap-2 mb-4">
            <li class="nav-item"><a class="nav-link text-dark bg-white" href="<?= htmlspecialchars(graderapp_path('grader.admin.courses')) ?>">Courses</a></li>
            <li class="nav-item"><a class="nav-link text-dark bg-white" href="<?= htmlspecialchars(graderapp_path('grader.admin.modules')) ?>">Modules</a></li>
            <li class="nav-item"><a class="nav-link active" href="<?= htmlspecialchars(graderapp_path('grader.admin.problems')) ?>">Problems</a></li>
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
                    <h2 class="h4 fw-bold mb-3"><?= $problemForm['id'] > 0 ? 'แก้ไขโจทย์' : 'เพิ่มโจทย์ใหม่' ?></h2>
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="save_problem">
                        <input type="hidden" name="problem_id" value="<?= (int) $problemForm['id'] ?>">
                        <div class="col-12">
                            <label class="form-label fw-bold">โมดูล</label>
                            <select name="module_id" class="form-select" required>
                                <option value="">เลือกโมดูล</option>
                                <?php foreach ($modules as $module): ?>
                                    <option value="<?= (int) $module['id'] ?>" <?= $problemForm['module_id'] === (string) $module['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($module['course_code'] . ' • ' . $module['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">ชื่อโจทย์</label>
                            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($problemForm['title']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Slug</label>
                            <input type="text" name="slug" class="form-control" value="<?= htmlspecialchars($problemForm['slug']) ?>" placeholder="เว้นว่างได้ ระบบจะสร้างให้จากชื่อโจทย์">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">คำอธิบายโจทย์ (Markdown)</label>
                            <textarea name="description_md" rows="5" class="form-control"><?= htmlspecialchars($problemForm['description_md']) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Starter Code</label>
                            <textarea name="starter_code" rows="7" class="form-control code-box"><?= htmlspecialchars($problemForm['starter_code']) ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">ภาษา</label>
                            <input type="text" name="language" class="form-control" value="<?= htmlspecialchars($problemForm['language']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Time Limit (sec)</label>
                            <input type="number" step="0.01" name="time_limit_sec" class="form-control" value="<?= htmlspecialchars($problemForm['time_limit_sec']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Memory (MB)</label>
                            <input type="number" name="memory_limit_mb" class="form-control" value="<?= htmlspecialchars($problemForm['memory_limit_mb']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">คะแนนเต็ม</label>
                            <input type="number" name="max_score" class="form-control" value="<?= htmlspecialchars($problemForm['max_score']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">การเผยแพร่</label>
                            <select name="visibility" class="form-select">
                                <?php foreach (['draft', 'published', 'archived'] as $visibility): ?>
                                    <option value="<?= $visibility ?>" <?= $problemForm['visibility'] === $visibility ? 'selected' : '' ?>><?= htmlspecialchars($visibility) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">ลำดับ</label>
                            <input type="number" name="sort_order" class="form-control" value="<?= (int) $problemForm['sort_order'] ?>">
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold">
                                <i class="bi bi-save-fill"></i> บันทึก
                            </button>
                            <a href="<?= htmlspecialchars(graderapp_path('grader.admin.problems')) ?>" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">ล้างฟอร์ม</a>
                        </div>
                    </form>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="panel-card p-4 bg-white">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h4 fw-bold mb-0">รายการโจทย์</h2>
                        <span class="badge text-bg-light"><?= count($problems) ?> รายการ</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>โจทย์</th>
                                    <th>วิชา/โมดูล</th>
                                    <th>ภาษา</th>
                                    <th>สถานะ</th>
                                    <th class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($problems as $problem): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($problem['title']) ?></div>
                                            <div class="text-secondary small"><?= htmlspecialchars($problem['slug']) ?> • <?= (int) $problem['test_case_count'] ?> test cases</div>
                                        </td>
                                        <td><?= htmlspecialchars($problem['course_code'] . ' • ' . $problem['module_title']) ?></td>
                                        <td><?= htmlspecialchars($problem['language']) ?></td>
                                        <td><span class="badge text-bg-light"><?= htmlspecialchars($problem['visibility']) ?></span></td>
                                        <td class="text-end">
                                            <a href="<?= htmlspecialchars(graderapp_path('grader.admin.problems', ['edit' => (int) $problem['id']])) ?>" class="btn btn-sm btn-outline-secondary rounded-pill">แก้ไข</a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('ลบโจทย์นี้ใช่หรือไม่');">
                                                <input type="hidden" name="action" value="delete_problem">
                                                <input type="hidden" name="problem_id" value="<?= (int) $problem['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">ลบ</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$problems): ?>
                                    <tr><td colspan="5" class="text-secondary">ยังไม่มีโจทย์ในระบบ</td></tr>
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
