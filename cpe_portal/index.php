<?php

require_once __DIR__ . '/bootstrap.php';

$state = cpeportal_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok']
    && $pdo
    && cpeportal_table_exists($pdo, 'cpeportal_categories')
    && cpeportal_table_exists($pdo, 'cpeportal_apps');
$error_message = $state['error'] ?? '';

$brand_name = 'CPE RBRU Apps';
$brand_tagline = 'ศูนย์รวมระบบดิจิทัลของสาขาวิศวกรรมคอมพิวเตอร์';
$categories = [];
$featured_apps = [];
$category_count = 0;
$app_count = 0;

function cpeportal_anchor_slug(string $text): string
{
    $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower(trim($text)));
    $slug = trim((string) $slug, '-');
    return $slug !== '' ? $slug : 'section';
}

if ($db_ready) {
    $brand_name = (string) cpeportal_setting_get($pdo, 'cpeportal_brand_name', $brand_name);
    $brand_tagline = (string) cpeportal_setting_get($pdo, 'cpeportal_brand_tagline', $brand_tagline);

    $stmtCategories = $pdo->query("
        SELECT c.id, c.category_name, c.description,
               a.id AS app_id, a.app_name, a.app_description, a.entry_url, a.admin_url, a.icon_class, a.theme_color, a.status_label, a.is_featured
        FROM cpeportal_categories c
        LEFT JOIN cpeportal_apps a
          ON a.category_id = c.id
         AND a.is_active = 1
        WHERE c.is_active = 1
        ORDER BY c.sort_order ASC, c.id ASC, a.sort_order ASC, a.id ASC
    ");

    foreach ($stmtCategories->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $categoryId = (int) $row['id'];
        if (!isset($categories[$categoryId])) {
            $categories[$categoryId] = [
                'category_name' => $row['category_name'],
                'description' => $row['description'],
                'apps' => [],
                'anchor' => 'category-' . $categoryId . '-' . cpeportal_anchor_slug((string) $row['category_name']),
            ];
        }

        if (!empty($row['app_id'])) {
            $app = [
                'app_name' => $row['app_name'],
                'app_description' => $row['app_description'],
                'entry_url' => $row['entry_url'],
                'admin_url' => $row['admin_url'],
                'icon_class' => $row['icon_class'],
                'theme_color' => $row['theme_color'],
                'status_label' => $row['status_label'],
                'is_featured' => (int) $row['is_featured'] === 1,
            ];
            $categories[$categoryId]['apps'][] = $app;
            if ($app['is_featured']) {
                $featured_apps[] = $app;
            }
            $app_count++;
        }
    }

    $category_count = count($categories);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($brand_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --portal-bg: #f6f5f1;
            --portal-text: #1d1d1f;
            --portal-muted: #667085;
            --portal-card: #ffffff;
            --portal-line: rgba(29, 29, 31, 0.08);
            --portal-accent: #123a63;
            --portal-navy: #16324f;
            --portal-gold: #8f5b1c;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background:
                radial-gradient(circle at top right, rgba(187, 160, 124, 0.24), transparent 28%),
                radial-gradient(circle at bottom left, rgba(18, 58, 99, 0.12), transparent 30%),
                var(--portal-bg);
            color: var(--portal-text);
        }

        .page-shell {
            max-width: 1320px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }

        .topbar {
            position: sticky;
            top: 16px;
            z-index: 20;
            padding: 14px 18px;
            margin-bottom: 18px;
            display: grid;
            grid-template-columns: auto minmax(240px, 360px) 1fr auto;
            gap: 12px;
            align-items: center;
        }

        .topbar-brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .brand-mark {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(18, 58, 99, 0.12), rgba(143, 91, 28, 0.18));
            color: var(--portal-accent);
            font-size: 1.15rem;
        }

        .sidebar-title {
            font-size: 1rem;
            font-weight: 800;
            margin-bottom: 0;
            line-height: 1.1;
        }

        .sidebar-subtitle {
            color: var(--portal-muted);
            font-size: 0.78rem;
            margin-bottom: 0;
            line-height: 1.2;
        }

        .topbar-group {
            min-width: 0;
        }

        .sidebar-group-label {
            display: none;
        }

        .sidebar-search {
            position: relative;
            margin-bottom: 0;
            min-width: 0;
        }

        .sidebar-search .form-control {
            border-radius: 999px;
            padding: 10px 14px 10px 40px;
            border-color: rgba(22, 50, 79, 0.12);
            box-shadow: none;
            background: rgba(255,255,255,0.82);
        }

        .sidebar-search .bi-search {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--portal-muted);
        }

        .sidebar-nav {
            display: flex;
            gap: 6px;
            flex-wrap: nowrap;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: thin;
            padding-bottom: 2px;
            min-width: 0;
        }

        .sidebar-nav::-webkit-scrollbar {
            height: 6px;
        }

        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(22, 50, 79, 0.16);
            border-radius: 999px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            text-decoration: none;
            color: var(--portal-text);
            background: transparent;
            border: 1px solid rgba(22, 50, 79, 0.06);
            font-weight: 700;
            font-size: 0.88rem;
            line-height: 1.2;
            white-space: nowrap;
            flex: 0 0 auto;
        }

        .sidebar-link:hover {
            background: rgba(22, 50, 79, 0.05);
            color: var(--portal-accent);
        }

        .sidebar-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
            padding: 0 7px;
            border-radius: 999px;
            background: rgba(22, 50, 79, 0.08);
            color: var(--portal-accent);
            font-size: 0.75rem;
            font-weight: 800;
        }

        .sidebar-actions {
            display: flex;
            justify-content: flex-end;
            align-items: flex-start;
        }

        .quick-menu {
            position: relative;
        }

        .quick-menu summary {
            list-style: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 999px;
            background: #1f2937;
            color: #fff;
            font-weight: 800;
            cursor: pointer;
            border: none;
            user-select: none;
        }

        .quick-menu summary::-webkit-details-marker {
            display: none;
        }

        .quick-menu[open] summary {
            background: #111827;
        }

        .quick-menu-panel {
            position: absolute;
            right: 0;
            top: calc(100% + 10px);
            width: 260px;
            background: #fff;
            border: 1px solid var(--portal-line);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(28, 41, 56, 0.12);
            padding: 12px;
        }

        .quick-menu-label {
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--portal-muted);
            padding: 6px 8px 10px;
        }

        .quick-menu-link {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            text-decoration: none;
            color: var(--portal-text);
            border-radius: 14px;
            padding: 11px 12px;
            font-weight: 700;
        }

        .quick-menu-link:hover {
            background: rgba(22, 50, 79, 0.06);
            color: var(--portal-accent);
        }

        .topbar-categories {
            align-self: center;
        }

        .hero-panel {
            background:
                radial-gradient(circle at top right, rgba(143, 91, 28, 0.18), transparent 30%),
                linear-gradient(135deg, rgba(255,255,255,0.97), rgba(247,241,233,0.94));
            border: 1px solid var(--portal-line);
            border-radius: 28px;
            padding: 24px 26px;
            box-shadow: 0 18px 42px rgba(28, 41, 56, 0.07);
            margin-bottom: 22px;
            overflow: hidden;
        }

        .hero-badge,
        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 999px;
            background: rgba(18, 58, 99, 0.08);
            color: var(--portal-accent);
            font-weight: 700;
            font-size: 0.95rem;
        }

        .hero-title {
            font-size: clamp(2rem, 3.3vw, 3.2rem);
            line-height: 1.02;
            font-weight: 800;
            margin: 12px 0 8px;
        }

        .hero-subtitle {
            max-width: 680px;
            color: var(--portal-muted);
            font-size: 0.98rem;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 20px;
            align-items: end;
        }

        .hero-kpis {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
            align-items: center;
        }

        .hero-kpi {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 16px;
            background: rgba(22, 50, 79, 0.05);
            border: 1px solid rgba(22, 50, 79, 0.07);
            min-width: 124px;
        }

        .hero-kpi-value {
            font-size: 1.15rem;
            font-weight: 800;
            line-height: 1;
            color: var(--portal-navy);
        }

        .hero-kpi-label {
            color: var(--portal-muted);
            font-size: 0.84rem;
            line-height: 1.15;
        }

        .portal-card {
            background: var(--portal-card);
            border: 1px solid var(--portal-line);
            border-radius: 22px;
            box-shadow: 0 14px 34px rgba(28, 41, 56, 0.06);
        }

        .featured-card {
            height: 100%;
            padding: 18px;
        }

        .icon-wrap {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: #fff;
            margin-bottom: 16px;
        }

        .featured-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 4px;
        }

        .featured-card h2 {
            font-size: 1.1rem;
            margin-bottom: 0;
        }

        .section-card {
            padding: 18px 20px;
            margin-top: 16px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .section-title {
            font-size: 1.28rem;
            font-weight: 800;
            margin-bottom: 2px;
        }

        .section-meta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(22, 50, 79, 0.06);
            color: var(--portal-navy);
            font-weight: 700;
            font-size: 0.95rem;
        }

        .app-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            padding: 12px 0;
            border-top: 1px solid rgba(29, 29, 31, 0.08);
            align-items: start;
        }

        .app-row:first-child {
            border-top: none;
            padding-top: 8px;
        }

        .app-name {
            font-size: 1rem;
            font-weight: 800;
            margin-bottom: 0;
        }

        .app-description {
            display: none;
        }

        .app-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .app-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 11px;
            border-radius: 999px;
            background: rgba(22, 50, 79, 0.05);
            color: var(--portal-navy);
            font-size: 0.9rem;
            font-weight: 700;
        }

        .app-main {
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }

        .app-copy {
            min-width: 0;
        }

        .app-header-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 0;
        }

        .app-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
            padding-top: 0;
        }

        .app-info-trigger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 999px;
            background: rgba(22, 50, 79, 0.06);
            color: var(--portal-muted);
            font-size: 0.85rem;
            cursor: pointer;
            border: 0;
        }

        .app-actions .btn {
            padding: 0.45rem 0.95rem;
            font-size: 0.92rem;
        }

        .empty-state {
            color: var(--portal-muted);
            padding: 18px 0 8px;
        }

        .featured-section-title {
            font-size: 1.2rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        @media (max-width: 900px) {
            .topbar {
                position: static;
                grid-template-columns: 1fr;
            }

            .topbar-categories {
                grid-column: auto;
            }

            .hero-panel {
                padding: 24px;
            }

            .hero-grid {
                grid-template-columns: 1fr;
            }

            .hero-kpis {
                justify-content: flex-start;
            }

            .featured-row {
                grid-template-columns: 1fr;
            }

            .app-row {
                grid-template-columns: 1fr;
            }

            .app-actions {
                justify-content: flex-start;
                padding-top: 0;
            }

            .sidebar-actions {
                justify-content: flex-start;
            }

            .quick-menu-panel {
                position: static;
                width: 100%;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
<div class="page-shell">
        <section class="portal-card topbar">
            <div class="topbar-brand">
                <div class="brand-mark"><i class="bi bi-grid-1x2-fill"></i></div>
                <div>
                    <div class="sidebar-title"><?= htmlspecialchars($brand_name) ?></div>
                    <div class="sidebar-subtitle">Department app launcher</div>
                </div>
            </div>

            <div class="topbar-group">
                <div class="sidebar-search">
                    <i class="bi bi-search"></i>
                    <input type="text" class="form-control" id="sidebarSearchInput" placeholder="ค้นหาหมวดหรือระบบ">
                </div>
            </div>

            <div class="sidebar-actions">
                <details class="quick-menu">
                    <summary>
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                        <span>เข้าถึงเร็ว</span>
                        <i class="bi bi-chevron-down"></i>
                    </summary>
                    <div class="quick-menu-panel">
                        <div class="quick-menu-label">Quick Access</div>
                        <a href="<?= htmlspecialchars(cpeportal_path('portal.admin')) ?>" class="quick-menu-link">
                            <i class="bi bi-gear-fill"></i> จัดการ Portal
                        </a>
                        <a href="/aunqa_php_portal/index.php" class="quick-menu-link">
                            <i class="bi bi-journal-check"></i> AUN-QA Hub
                        </a>
                        <a href="/office_feedback/index.php" class="quick-menu-link">
                            <i class="bi bi-emoji-smile"></i> Office Feedback
                        </a>
                    </div>
                </details>
            </div>
            <nav class="sidebar-nav topbar-categories">
                <?php foreach ($categories as $category): ?>
                    <a class="sidebar-link" href="#<?= htmlspecialchars($category['anchor']) ?>" data-sidebar-label="<?= htmlspecialchars(mb_strtolower($category['category_name'] . ' ' . implode(' ', array_map(function ($app) { return $app['app_name']; }, $category['apps'])), 'UTF-8')) ?>">
                        <span><?= htmlspecialchars($category['category_name']) ?></span>
                        <span class="sidebar-chip"><?= count($category['apps']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </section>

        <main>
            <section class="hero-panel">
                <div class="hero-grid">
                    <div class="d-flex flex-column justify-content-between">
                        <div>
                            <div class="hero-badge"><i class="bi bi-grid-1x2-fill"></i> Department App Launcher</div>
                            <h1 class="hero-title"><?= htmlspecialchars($brand_name) ?></h1>
                            <p class="hero-subtitle mb-0"><?= htmlspecialchars($brand_tagline) ?></p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap mt-4">
                            <span class="status-chip"><i class="bi bi-diagram-3-fill"></i> รองรับการเพิ่ม app ใหม่แบบแยกโมดูล</span>
                            <span class="status-chip"><i class="bi bi-stars"></i> Featured Apps <?= count($featured_apps) ?></span>
                        </div>
                    </div>
                    <div class="hero-kpis">
                        <div class="hero-kpi">
                            <div class="hero-kpi-value"><?= (int) $category_count ?></div>
                            <div class="hero-kpi-label">หมวดใช้งาน</div>
                        </div>
                        <div class="hero-kpi">
                            <div class="hero-kpi-value"><?= (int) $app_count ?></div>
                            <div class="hero-kpi-label">ระบบทั้งหมด</div>
                        </div>
                        <div class="hero-kpi">
                            <div class="hero-kpi-value"><?= count($featured_apps) ?></div>
                            <div class="hero-kpi-label">ระบบเด่น</div>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (!$db_ready): ?>
                <div class="alert alert-danger portal-card p-4 border-0">
                    <h2 class="h4 fw-bold mb-2"><i class="bi bi-exclamation-triangle-fill"></i> Portal ยังไม่พร้อมใช้งาน</h2>
                    <div class="text-muted">กรุณาตรวจสอบ `config.php` หรือการเชื่อมฐานข้อมูลของโมดูล `cpe_portal`</div>
                    <?php if ($error_message !== ''): ?>
                        <div class="small mt-3"><code><?= htmlspecialchars($error_message) ?></code></div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php if (!empty($featured_apps)): ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                        <div>
                            <div class="featured-section-title">ระบบเด่นที่แนะนำให้ใช้งาน</div>
                            <div class="text-muted">คัดระบบหลักที่ผู้ใช้งานในสาขามักเข้าถึงบ่อยให้อยู่ส่วนบนของหน้า</div>
                        </div>
                        <div class="section-meta"><i class="bi bi-stars"></i> Featured Apps <?= count($featured_apps) ?></div>
                    </div>
                    <div class="featured-row">
                        <?php foreach ($featured_apps as $app): ?>
                            <div class="portal-card featured-card">
                                <div class="icon-wrap" style="background: <?= htmlspecialchars($app['theme_color']) ?>;">
                                    <i class="bi <?= htmlspecialchars($app['icon_class']) ?>"></i>
                                </div>
                                <div class="status-chip mb-3"><?= htmlspecialchars($app['status_label']) ?></div>
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <h2 class="fw-bold"><?= htmlspecialchars($app['app_name']) ?></h2>
                                    <?php if (!empty($app['app_description'])): ?>
                                        <button type="button" class="app-info-trigger" data-bs-toggle="tooltip" data-bs-placement="top" title="<?= htmlspecialchars($app['app_description']) ?>" aria-label="รายละเอียดของ <?= htmlspecialchars($app['app_name']) ?>"><i class="bi bi-info-lg"></i></button>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <a href="<?= htmlspecialchars($app['entry_url']) ?>" class="btn btn-dark rounded-pill px-4">เปิดใช้งาน</a>
                                    <?php if (!empty($app['admin_url']) && $app['admin_url'] !== '#'): ?>
                                        <a href="<?= htmlspecialchars($app['admin_url']) ?>" class="btn btn-outline-secondary rounded-pill px-4">จัดการ</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php foreach ($categories as $category): ?>
                    <section class="portal-card section-card" id="<?= htmlspecialchars($category['anchor']) ?>">
                        <div class="section-header">
                            <div>
                                <h2 class="section-title"><?= htmlspecialchars($category['category_name']) ?></h2>
                                <?php if (!empty($category['description'])): ?>
                                    <div class="small text-muted"><?= htmlspecialchars((string) $category['description']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="section-meta"><i class="bi bi-collection"></i> <?= count($category['apps']) ?> ระบบ</div>
                        </div>

                        <?php if (empty($category['apps'])): ?>
                            <div class="empty-state">ยังไม่มี app ที่เปิดใช้งานในหมวดนี้</div>
                        <?php else: ?>
                            <?php foreach ($category['apps'] as $app): ?>
                                <div class="app-row">
                                    <div class="app-main">
                                        <span class="icon-wrap mb-0" style="width:46px;height:46px;border-radius:14px;background: <?= htmlspecialchars($app['theme_color']) ?>;">
                                            <i class="bi <?= htmlspecialchars($app['icon_class']) ?>"></i>
                                        </span>
                                        <div class="app-copy">
                                            <div class="app-header-row">
                                                <div class="app-name"><?= htmlspecialchars($app['app_name']) ?></div>
                                                <?php if (!empty($app['app_description'])): ?>
                                                    <button type="button" class="app-info-trigger" data-bs-toggle="tooltip" data-bs-placement="top" title="<?= htmlspecialchars($app['app_description']) ?>" aria-label="รายละเอียดของ <?= htmlspecialchars($app['app_name']) ?>"><i class="bi bi-info-lg"></i></button>
                                                <?php endif; ?>
                                                <div class="status-chip"><?= htmlspecialchars($app['status_label']) ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="app-actions">
                                        <a href="<?= htmlspecialchars($app['entry_url']) ?>" class="btn btn-dark rounded-pill px-4">เปิดใช้งาน</a>
                                        <?php if (!empty($app['admin_url']) && $app['admin_url'] !== '#'): ?>
                                            <a href="<?= htmlspecialchars($app['admin_url']) ?>" class="btn btn-outline-secondary rounded-pill px-4">จัดการ</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
</body>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        const searchInput = document.getElementById('sidebarSearchInput');
        if (!searchInput) {
            return;
        }

        const links = Array.from(document.querySelectorAll('.sidebar-link'));
        const sections = Array.from(document.querySelectorAll('.section-card'));
        searchInput.addEventListener('input', function () {
            const query = this.value.trim().toLowerCase();
            links.forEach(function (link) {
                const haystack = (link.getAttribute('data-sidebar-label') || '').toLowerCase();
                link.style.display = query === '' || haystack.indexOf(query) !== -1 ? '' : 'none';
            });

            sections.forEach(function (section) {
                const title = (section.querySelector('.section-title')?.textContent || '').toLowerCase();
                const body = (section.textContent || '').toLowerCase();
                section.style.display = query === '' || title.indexOf(query) !== -1 || body.indexOf(query) !== -1 ? '' : 'none';
            });
        });
    })();

    (function () {
        if (typeof bootstrap === 'undefined') {
            return;
        }

        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
            new bootstrap.Tooltip(element);
        });
    })();
</script>
</html>
