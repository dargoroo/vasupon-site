<?php

require_once __DIR__ . '/auth.php';

$state = cpeportal_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && $pdo;
$error_message = $state['error'] ?? '';
$flash = cpeportal_admin_consume_flash();
$login_error = '';
$category_form = [
    'id' => 0,
    'category_key' => '',
    'category_name' => '',
    'description' => '',
    'sort_order' => 0,
    'is_active' => 1,
];
$app_form = [
    'id' => 0,
    'app_key' => '',
    'category_id' => '',
    'app_name' => '',
    'app_description' => '',
    'entry_url' => '',
    'admin_url' => '',
    'icon_class' => 'bi-grid-1x2-fill',
    'theme_color' => '#1f4f7b',
    'status_label' => 'พร้อมใช้งาน',
    'sort_order' => 0,
    'is_active' => 1,
    'is_featured' => 0,
];

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    cpeportal_admin_logout();
    header('Location: ' . cpeportal_path('portal.admin'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (cpeportal_admin_login($username, $password, $pdo)) {
        cpeportal_admin_flash('success', 'เข้าสู่ระบบ Portal Admin เรียบร้อยแล้ว');
        header('Location: ' . cpeportal_path('portal.admin'));
        exit;
    }
    $login_error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
}

if ($db_ready && cpeportal_admin_is_authenticated() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'save_branding') {
            cpeportal_setting_set($pdo, 'cpeportal_brand_name', trim((string) ($_POST['brand_name'] ?? 'CPE RBRU Apps')));
            cpeportal_setting_set($pdo, 'cpeportal_brand_tagline', trim((string) ($_POST['brand_tagline'] ?? '')));
            cpeportal_admin_flash('success', 'บันทึกข้อมูลหน้า Portal เรียบร้อยแล้ว');
            header('Location: ' . cpeportal_path('portal.admin'));
            exit;
        }

        if ($_POST['action'] === 'save_category') {
            $id = (int) ($_POST['category_id'] ?? 0);
            $categoryKey = trim((string) ($_POST['category_key'] ?? ''));
            $categoryName = trim((string) ($_POST['category_name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($categoryKey === '' || $categoryName === '') {
                throw new RuntimeException('กรุณากรอก category key และชื่อหมวดให้ครบ');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE cpeportal_categories
                    SET category_key = :category_key,
                        category_name = :category_name,
                        description = :description,
                        sort_order = :sort_order,
                        is_active = :is_active
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':category_key' => $categoryKey,
                    ':category_name' => $categoryName,
                    ':description' => $description,
                    ':sort_order' => $sortOrder,
                    ':is_active' => $isActive,
                    ':id' => $id,
                ]);
                cpeportal_admin_flash('success', 'อัปเดตหมวดเรียบร้อยแล้ว');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO cpeportal_categories (category_key, category_name, description, sort_order, is_active)
                    VALUES (:category_key, :category_name, :description, :sort_order, :is_active)
                ");
                $stmt->execute([
                    ':category_key' => $categoryKey,
                    ':category_name' => $categoryName,
                    ':description' => $description,
                    ':sort_order' => $sortOrder,
                    ':is_active' => $isActive,
                ]);
                cpeportal_admin_flash('success', 'เพิ่มหมวดใหม่เรียบร้อยแล้ว');
            }

            header('Location: ' . cpeportal_path('portal.admin'));
            exit;
        }

        if ($_POST['action'] === 'delete_category') {
            $id = (int) ($_POST['category_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ไม่พบหมวดที่ต้องการลบ');
            }

            $stmt = $pdo->prepare("DELETE FROM cpeportal_categories WHERE id = :id");
            $stmt->execute([':id' => $id]);
            cpeportal_admin_flash('success', 'ลบหมวดเรียบร้อยแล้ว');
            header('Location: ' . cpeportal_path('portal.admin'));
            exit;
        }

        if ($_POST['action'] === 'save_app') {
            $id = (int) ($_POST['app_id'] ?? 0);
            $appKey = trim((string) ($_POST['app_key'] ?? ''));
            $categoryId = trim((string) ($_POST['category_id'] ?? ''));
            $appName = trim((string) ($_POST['app_name'] ?? ''));
            $appDescription = trim((string) ($_POST['app_description'] ?? ''));
            $entryUrl = trim((string) ($_POST['entry_url'] ?? ''));
            $adminUrl = trim((string) ($_POST['admin_url'] ?? ''));
            $iconClass = trim((string) ($_POST['icon_class'] ?? 'bi-grid-1x2-fill'));
            $themeColor = trim((string) ($_POST['theme_color'] ?? '#1f4f7b'));
            $statusLabel = trim((string) ($_POST['status_label'] ?? 'พร้อมใช้งาน'));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $isFeatured = isset($_POST['is_featured']) ? 1 : 0;

            if ($appKey === '' || $appName === '' || $entryUrl === '') {
                throw new RuntimeException('กรุณากรอก app key, ชื่อ app, และ entry URL ให้ครบ');
            }

            $categoryValue = $categoryId === '' ? null : (int) $categoryId;

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE cpeportal_apps
                    SET app_key = :app_key,
                        category_id = :category_id,
                        app_name = :app_name,
                        app_description = :app_description,
                        entry_url = :entry_url,
                        admin_url = :admin_url,
                        icon_class = :icon_class,
                        theme_color = :theme_color,
                        status_label = :status_label,
                        sort_order = :sort_order,
                        is_active = :is_active,
                        is_featured = :is_featured
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':app_key' => $appKey,
                    ':category_id' => $categoryValue,
                    ':app_name' => $appName,
                    ':app_description' => $appDescription,
                    ':entry_url' => $entryUrl,
                    ':admin_url' => $adminUrl,
                    ':icon_class' => $iconClass,
                    ':theme_color' => $themeColor,
                    ':status_label' => $statusLabel,
                    ':sort_order' => $sortOrder,
                    ':is_active' => $isActive,
                    ':is_featured' => $isFeatured,
                    ':id' => $id,
                ]);
                cpeportal_admin_flash('success', 'อัปเดต app เรียบร้อยแล้ว');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO cpeportal_apps
                        (app_key, category_id, app_name, app_description, entry_url, admin_url, icon_class, theme_color, status_label, sort_order, is_active, is_featured)
                    VALUES
                        (:app_key, :category_id, :app_name, :app_description, :entry_url, :admin_url, :icon_class, :theme_color, :status_label, :sort_order, :is_active, :is_featured)
                ");
                $stmt->execute([
                    ':app_key' => $appKey,
                    ':category_id' => $categoryValue,
                    ':app_name' => $appName,
                    ':app_description' => $appDescription,
                    ':entry_url' => $entryUrl,
                    ':admin_url' => $adminUrl,
                    ':icon_class' => $iconClass,
                    ':theme_color' => $themeColor,
                    ':status_label' => $statusLabel,
                    ':sort_order' => $sortOrder,
                    ':is_active' => $isActive,
                    ':is_featured' => $isFeatured,
                ]);
                cpeportal_admin_flash('success', 'เพิ่ม app ใหม่เรียบร้อยแล้ว');
            }

            header('Location: ' . cpeportal_path('portal.admin'));
            exit;
        }

        if ($_POST['action'] === 'delete_app') {
            $id = (int) ($_POST['app_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ไม่พบ app ที่ต้องการลบ');
            }

            $stmt = $pdo->prepare("DELETE FROM cpeportal_apps WHERE id = :id");
            $stmt->execute([':id' => $id]);
            cpeportal_admin_flash('success', 'ลบ app เรียบร้อยแล้ว');
            header('Location: ' . cpeportal_path('portal.admin'));
            exit;
        }
    } catch (Throwable $e) {
        cpeportal_admin_flash('danger', $e->getMessage());
        header('Location: ' . cpeportal_path('portal.admin'));
        exit;
    }
}

$categoryCount = 0;
$appCount = 0;
$featuredCount = 0;
$appRows = [];
$categoryRows = [];
$brandName = 'CPE RBRU Apps';
$brandTagline = 'ศูนย์รวมระบบดิจิทัลของสาขาวิศวกรรมคอมพิวเตอร์';

if ($db_ready && cpeportal_admin_is_authenticated()) {
    $brandName = (string) cpeportal_setting_get($pdo, 'cpeportal_brand_name', $brandName);
    $brandTagline = (string) cpeportal_setting_get($pdo, 'cpeportal_brand_tagline', $brandTagline);
    $categoryCount = (int) $pdo->query("SELECT COUNT(*) FROM cpeportal_categories WHERE is_active = 1")->fetchColumn();
    $appCount = (int) $pdo->query("SELECT COUNT(*) FROM cpeportal_apps WHERE is_active = 1")->fetchColumn();
    $featuredCount = (int) $pdo->query("SELECT COUNT(*) FROM cpeportal_apps WHERE is_active = 1 AND is_featured = 1")->fetchColumn();

    $stmtCategories = $pdo->query("
        SELECT id, category_key, category_name, description, sort_order, is_active
        FROM cpeportal_categories
        ORDER BY sort_order ASC, id ASC
    ");
    $categoryRows = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT a.id, a.app_key, a.category_id, a.app_name, a.app_description, a.entry_url, a.admin_url,
               a.icon_class, a.theme_color, a.status_label, a.sort_order, a.is_active, a.is_featured, c.category_name
        FROM cpeportal_apps a
        LEFT JOIN cpeportal_categories c ON c.id = a.category_id
        ORDER BY a.sort_order ASC, a.id ASC
    ");
    $appRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['edit_category'])) {
        $editId = (int) $_GET['edit_category'];
        foreach ($categoryRows as $row) {
            if ((int) $row['id'] === $editId) {
                $category_form = [
                    'id' => (int) $row['id'],
                    'category_key' => (string) $row['category_key'],
                    'category_name' => (string) $row['category_name'],
                    'description' => (string) $row['description'],
                    'sort_order' => (int) $row['sort_order'],
                    'is_active' => (int) $row['is_active'],
                ];
                break;
            }
        }
    }

    if (isset($_GET['edit_app'])) {
        $editId = (int) $_GET['edit_app'];
        foreach ($appRows as $row) {
            if ((int) $row['id'] === $editId) {
                $app_form = [
                    'id' => (int) $row['id'],
                    'app_key' => (string) $row['app_key'],
                    'category_id' => $row['category_id'] === null ? '' : (string) $row['category_id'],
                    'app_name' => (string) $row['app_name'],
                    'app_description' => (string) $row['app_description'],
                    'entry_url' => (string) $row['entry_url'],
                    'admin_url' => (string) $row['admin_url'],
                    'icon_class' => (string) $row['icon_class'],
                    'theme_color' => (string) $row['theme_color'],
                    'status_label' => (string) $row['status_label'],
                    'sort_order' => (int) $row['sort_order'],
                    'is_active' => (int) $row['is_active'],
                    'is_featured' => (int) $row['is_featured'],
                ];
                break;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CPE Portal Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f4f1eb; color: #1e1e1e; }
        .shell { max-width: 1180px; margin: 0 auto; padding: 32px 20px 56px; }
        .panel { background: rgba(255,255,255,0.94); border: 1px solid rgba(30,30,30,0.08); border-radius: 28px; box-shadow: 0 18px 45px rgba(34,39,46,0.08); }
        .hero { padding: 28px; margin-bottom: 24px; }
        .metric { padding: 22px; border-radius: 22px; background: #fff; border: 1px solid rgba(30,30,30,0.08); height: 100%; }
        .metric .value { font-size: 2rem; font-weight: 800; }
        .table-wrap, .form-wrap { padding: 24px; }
        .section-title { font-size: 1.35rem; font-weight: 800; }
        .sticky-panel { position: sticky; top: 20px; }
    </style>
</head>
<body>
<div class="shell">
    <div class="panel hero">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <div class="text-uppercase text-secondary fw-bold small">Portal Admin Scaffold</div>
                <h1 class="fw-bold mb-2">จัดการ CPE RBRU Apps</h1>
                <div class="text-muted">ตัวอย่าง app กลางสำหรับรวมหลายระบบ โดยใช้ shared config/schema pattern เดียวกับโมดูลอื่น</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="<?= htmlspecialchars(cpeportal_path('portal.home')) ?>" class="btn btn-outline-secondary rounded-pill px-4">
                    <i class="bi bi-grid-1x2-fill"></i> เปิด Portal
                </a>
                <?php if (cpeportal_admin_is_authenticated()): ?>
                    <a href="<?= htmlspecialchars(cpeportal_path('portal.logout')) ?>" class="btn btn-dark rounded-pill px-4">
                        <i class="bi bi-box-arrow-right"></i> ออกจากระบบ
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$db_ready): ?>
        <div class="alert alert-danger panel p-4 border-0">
            <h2 class="h4 fw-bold mb-2"><i class="bi bi-exclamation-triangle-fill"></i> CPE Portal ยังไม่พร้อมใช้งาน</h2>
            <div class="text-muted">กรุณาตรวจสอบ `config.php` หรือการเชื่อมฐานข้อมูลของโมดูลนี้</div>
            <?php if ($error_message !== ''): ?>
                <div class="small mt-3"><code><?= htmlspecialchars($error_message) ?></code></div>
            <?php endif; ?>
        </div>
    <?php elseif (!cpeportal_admin_is_authenticated()): ?>
        <div class="panel p-4 mx-auto" style="max-width: 520px;">
            <h2 class="fw-bold mb-2">เข้าสู่ระบบ Portal Admin</h2>
            <div class="text-muted mb-4">ใช้สำหรับจัดการ app launcher กลางของสาขา</div>
            <?php if ($login_error !== ''): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($login_error) ?></div>
            <?php endif; ?>
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="login">
                <div class="col-12">
                    <label class="form-label fw-bold">Username</label>
                    <input type="text" class="form-control" name="username" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold">Password</label>
                    <input type="password" class="form-control" name="password" required>
                </div>
                <div class="col-12 d-grid">
                    <button type="submit" class="btn btn-dark rounded-pill fw-bold py-2">เข้าสู่ระบบ</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-md-4"><div class="metric"><div class="text-muted">หมวดที่เปิดใช้งาน</div><div class="value"><?= $categoryCount ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="text-muted">แอปที่เปิดใช้งาน</div><div class="value"><?= $appCount ?></div></div></div>
            <div class="col-md-4"><div class="metric"><div class="text-muted">แอปเด่นบนหน้าแรก</div><div class="value"><?= $featuredCount ?></div></div></div>
        </div>

        <div class="panel form-wrap mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="section-title mb-1">ข้อมูลหน้า Portal</h2>
                    <div class="text-muted">ตั้งชื่อหน้า launcher กลางและคำอธิบายสั้นสำหรับผู้ใช้ทุกกลุ่ม</div>
                </div>
                <span class="badge text-bg-dark rounded-pill px-3 py-2">Portal Branding</span>
            </div>
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="save_branding">
                <div class="col-md-4">
                    <label class="form-label fw-bold">ชื่อ Portal</label>
                    <input type="text" class="form-control" name="brand_name" value="<?= htmlspecialchars($brandName) ?>" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-bold">คำอธิบายสั้น</label>
                    <input type="text" class="form-control" name="brand_tagline" value="<?= htmlspecialchars($brandTagline) ?>">
                </div>
                <div class="col-12 d-flex justify-content-end">
                    <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold">
                        <i class="bi bi-save2-fill"></i> บันทึกข้อมูลหน้า Portal
                    </button>
                </div>
            </form>
        </div>

        <div class="row g-4">
            <div class="col-xl-5">
                <div class="sticky-panel d-grid gap-4">
                    <div class="panel form-wrap">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div>
                                <h2 class="section-title mb-1"><?= $category_form['id'] > 0 ? 'แก้ไขหมวด' : 'เพิ่มหมวดใหม่' ?></h2>
                                <div class="text-muted">ใช้ category key แบบสั้นและไม่ซ้ำ เช่น `quality_assurance`</div>
                            </div>
                            <?php if ($category_form['id'] > 0): ?>
                                <a href="<?= htmlspecialchars(cpeportal_path('portal.admin')) ?>" class="btn btn-outline-secondary rounded-pill px-3">ล้างฟอร์ม</a>
                            <?php endif; ?>
                        </div>
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="action" value="save_category">
                            <input type="hidden" name="category_id" value="<?= (int) $category_form['id'] ?>">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Category Key</label>
                                <input type="text" class="form-control" name="category_key" value="<?= htmlspecialchars($category_form['category_key']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ชื่อหมวด</label>
                                <input type="text" class="form-control" name="category_name" value="<?= htmlspecialchars($category_form['category_name']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">คำอธิบาย</label>
                                <textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($category_form['description']) ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ลำดับ</label>
                                <input type="number" class="form-control" name="sort_order" value="<?= (int) $category_form['sort_order'] ?>">
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check form-switch fs-5">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="category_active" <?= (int) $category_form['is_active'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="category_active">เปิดใช้งาน</label>
                                </div>
                            </div>
                            <div class="col-12 d-flex justify-content-end">
                                <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold">
                                    <i class="bi bi-folder-plus"></i> <?= $category_form['id'] > 0 ? 'บันทึกการแก้ไขหมวด' : 'เพิ่มหมวด' ?>
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="panel form-wrap">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div>
                                <h2 class="section-title mb-1"><?= $app_form['id'] > 0 ? 'แก้ไข app' : 'เพิ่ม app ใหม่' ?></h2>
                                <div class="text-muted">ใช้สำหรับลงทะเบียน app ที่จะขึ้นบนหน้า CPE Portal กลาง</div>
                            </div>
                            <?php if ($app_form['id'] > 0): ?>
                                <a href="<?= htmlspecialchars(cpeportal_path('portal.admin')) ?>" class="btn btn-outline-secondary rounded-pill px-3">ล้างฟอร์ม</a>
                            <?php endif; ?>
                        </div>
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="action" value="save_app">
                            <input type="hidden" name="app_id" value="<?= (int) $app_form['id'] ?>">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">App Key</label>
                                <input type="text" class="form-control" name="app_key" value="<?= htmlspecialchars($app_form['app_key']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">หมวด</label>
                                <select class="form-select" name="category_id">
                                    <option value="">ไม่ผูกหมวด</option>
                                    <?php foreach ($categoryRows as $category): ?>
                                        <option value="<?= (int) $category['id'] ?>" <?= (string) $app_form['category_id'] === (string) $category['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($category['category_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ชื่อ app</label>
                                <input type="text" class="form-control" name="app_name" value="<?= htmlspecialchars($app_form['app_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">สถานะ</label>
                                <input type="text" class="form-control" name="status_label" value="<?= htmlspecialchars($app_form['status_label']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">คำอธิบาย</label>
                                <textarea class="form-control" name="app_description" rows="3"><?= htmlspecialchars($app_form['app_description']) ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Entry URL</label>
                                <input type="text" class="form-control" name="entry_url" value="<?= htmlspecialchars($app_form['entry_url']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Admin URL</label>
                                <input type="text" class="form-control" name="admin_url" value="<?= htmlspecialchars($app_form['admin_url']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Bootstrap Icon</label>
                                <input type="text" class="form-control" name="icon_class" value="<?= htmlspecialchars($app_form['icon_class']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">สีหลัก</label>
                                <input type="color" class="form-control form-control-color" name="theme_color" value="<?= htmlspecialchars($app_form['theme_color']) ?>" title="เลือกสีหลัก">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">ลำดับ</label>
                                <input type="number" class="form-control" name="sort_order" value="<?= (int) $app_form['sort_order'] ?>">
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch fs-5">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="app_active" <?= (int) $app_form['is_active'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="app_active">เปิดใช้งาน</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch fs-5">
                                    <input class="form-check-input" type="checkbox" name="is_featured" id="app_featured" <?= (int) $app_form['is_featured'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="app_featured">แสดงเป็น app เด่น</label>
                                </div>
                            </div>
                            <div class="col-12 d-flex justify-content-end">
                                <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold">
                                    <i class="bi bi-window-stack"></i> <?= $app_form['id'] > 0 ? 'บันทึกการแก้ไข app' : 'เพิ่ม app' ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xl-7 d-grid gap-4">
                <div class="panel table-wrap">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h2 class="section-title mb-1">หมวดทั้งหมด</h2>
                            <div class="text-muted">จัดกลุ่ม app ให้เป็นระเบียบ เช่น ประกันคุณภาพ, บริการ, การเรียนการสอน</div>
                        </div>
                        <span class="badge text-bg-dark rounded-pill px-3 py-2"><?= count($categoryRows) ?> หมวด</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>หมวด</th>
                                <th>Key</th>
                                <th>ลำดับ</th>
                                <th>สถานะ</th>
                                <th class="text-end">จัดการ</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($categoryRows as $row): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($row['category_name']) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars((string) $row['description']) ?></div>
                                    </td>
                                    <td><code><?= htmlspecialchars($row['category_key']) ?></code></td>
                                    <td><?= (int) $row['sort_order'] ?></td>
                                    <td>
                                        <span class="badge <?= (int) $row['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                            <?= (int) $row['is_active'] === 1 ? 'เปิดใช้งาน' : 'ปิดอยู่' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-2">
                                            <a href="<?= htmlspecialchars(cpeportal_path('portal.admin', ['edit_category' => (int) $row['id']])) ?>" class="btn btn-sm btn-outline-dark rounded-pill">แก้ไข</a>
                                            <form method="POST" onsubmit="return confirm('ต้องการลบหมวดนี้ใช่หรือไม่');">
                                                <input type="hidden" name="action" value="delete_category">
                                                <input type="hidden" name="category_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">ลบ</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel table-wrap">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h2 class="section-title mb-1">รายการ app</h2>
                            <div class="text-muted">เพิ่ม แก้ไข ลบ และควบคุมสถานะของ app ที่จะขึ้นบนหน้า portal กลาง</div>
                        </div>
                        <span class="badge text-bg-dark rounded-pill px-3 py-2"><?= count($appRows) ?> app</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>App</th>
                                <th>หมวด</th>
                                <th>สถานะ</th>
                                <th>ลำดับ</th>
                                <th class="text-end">จัดการ</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($appRows as $row): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-start gap-3">
                                            <span class="rounded-4 d-inline-flex align-items-center justify-content-center text-white" style="width:44px;height:44px;background: <?= htmlspecialchars($row['theme_color']) ?>;">
                                                <i class="bi <?= htmlspecialchars($row['icon_class']) ?>"></i>
                                            </span>
                                            <div>
                                                <div class="fw-bold"><?= htmlspecialchars($row['app_name']) ?></div>
                                                <div class="small text-muted"><?= htmlspecialchars((string) $row['app_description']) ?></div>
                                                <div class="small mt-1">
                                                    <code><?= htmlspecialchars($row['app_key']) ?></code>
                                                    <?php if ((int) $row['is_featured'] === 1): ?>
                                                        <span class="badge text-bg-warning ms-2">featured</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars((string) $row['category_name']) ?></td>
                                    <td>
                                        <span class="badge <?= (int) $row['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                            <?= htmlspecialchars($row['status_label']) ?>
                                        </span>
                                    </td>
                                    <td><?= (int) $row['sort_order'] ?></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-2">
                                            <a href="<?= htmlspecialchars(cpeportal_path('portal.admin', ['edit_app' => (int) $row['id']])) ?>" class="btn btn-sm btn-outline-dark rounded-pill">แก้ไข</a>
                                            <?php if ((string) $row['entry_url'] !== '' && (string) $row['entry_url'] !== '#'): ?>
                                                <a href="<?= htmlspecialchars($row['entry_url']) ?>" class="btn btn-sm btn-outline-secondary rounded-pill">เปิด</a>
                                            <?php endif; ?>
                                            <form method="POST" onsubmit="return confirm('ต้องการลบ app นี้ใช่หรือไม่');">
                                                <input type="hidden" name="action" value="delete_app">
                                                <input type="hidden" name="app_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">ลบ</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
