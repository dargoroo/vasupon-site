<?php

require_once __DIR__ . '/bootstrap.php';

function graderapp_admin_adjust_hex(string $hex, int $offset): string
{
    $hex = ltrim(graderapp_normalize_theme_color($hex), '#');
    $parts = str_split($hex, 2);
    $rgb = array_map('hexdec', $parts);

    foreach ($rgb as $index => $channel) {
        $rgb[$index] = max(0, min(255, $channel + $offset));
    }

    return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
}

function graderapp_admin_course_gradient(string $baseColor): array
{
    $base = graderapp_normalize_theme_color($baseColor);
    return [
        graderapp_admin_adjust_hex($base, -16),
        graderapp_admin_adjust_hex($base, 30),
    ];
}

$state = graderapp_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && $pdo;
$error_message = $state['error'] ?? '';
$noticeType = trim((string) ($_GET['notice_type'] ?? ''));
$noticeMessage = trim((string) ($_GET['notice_message'] ?? ''));
$courseForm = [
    'id' => 0,
    'course_code' => '',
    'course_name' => '',
    'academic_year' => (string) ((int) date('Y') + 543),
    'semester' => '1',
    'owner_user_id' => '',
    'join_code' => graderapp_generate_join_code(),
    'theme_color' => '#185b86',
    'status' => 'draft',
];
$teachers = [];
$courses = [];
$activeCourses = [];
$archivedCourses = [];
$currentUser = null;
$selectedCourse = null;
$selectedModules = [];
$selectedProblems = [];

if ($db_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_course') {
            $id = (int) ($_POST['course_id'] ?? 0);
            $payload = [
                ':course_code' => strtoupper(trim((string) ($_POST['course_code'] ?? ''))),
                ':course_name' => trim((string) ($_POST['course_name'] ?? '')),
                ':academic_year' => trim((string) ($_POST['academic_year'] ?? '')),
                ':semester' => trim((string) ($_POST['semester'] ?? '')),
                ':owner_user_id' => ($_POST['owner_user_id'] ?? '') === '' ? null : (int) $_POST['owner_user_id'],
                ':join_code' => strtoupper(trim((string) ($_POST['join_code'] ?? ''))),
                ':theme_color' => graderapp_normalize_theme_color((string) ($_POST['theme_color'] ?? '#185b86')),
                ':status' => trim((string) ($_POST['status'] ?? 'draft')),
            ];

            if ($payload[':course_code'] === '' || $payload[':course_name'] === '') {
                throw new RuntimeException('กรุณากรอกรหัสวิชาและชื่อวิชาให้ครบ');
            }

            if ($payload[':join_code'] === '') {
                $payload[':join_code'] = graderapp_generate_join_code();
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
                        theme_color = :theme_color,
                        status = :status
                    WHERE id = :id
                ");
                $payload[':id'] = $id;
                $stmt->execute($payload);
                header('Location: ' . graderapp_path('grader.classroom', [
                    'edit' => $id,
                    'notice_type' => 'success',
                    'notice_message' => 'อัปเดตรายวิชาเรียบร้อยแล้ว',
                ]));
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO grader_courses
                    (course_code, course_name, academic_year, semester, owner_user_id, join_code, theme_color, status)
                VALUES
                    (:course_code, :course_name, :academic_year, :semester, :owner_user_id, :join_code, :theme_color, :status)
            ");
            $stmt->execute($payload);
            $newCourseId = (int) $pdo->lastInsertId();
            header('Location: ' . graderapp_path('grader.classroom', [
                'edit' => $newCourseId,
                'notice_type' => 'success',
                'notice_message' => 'เพิ่มรายวิชาใหม่เรียบร้อยแล้ว',
            ]));
            exit;
        }

        if ($action === 'delete_course') {
            $id = (int) ($_POST['course_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ไม่พบรายวิชาที่ต้องการลบ');
            }

            $stmt = $pdo->prepare("DELETE FROM grader_courses WHERE id = :id");
            $stmt->execute([':id' => $id]);
            header('Location: ' . graderapp_path('grader.classroom', [
                'notice_type' => 'success',
                'notice_message' => 'ลบรายวิชาเรียบร้อยแล้ว',
            ]));
            exit;
        }
    } catch (Throwable $e) {
        header('Location: ' . graderapp_path('grader.classroom', [
            'notice_type' => 'danger',
            'notice_message' => $e->getMessage(),
        ]));
        exit;
    }
}

if ($db_ready) {
    $currentUserStmt = $pdo->query("
        SELECT id, full_name, email, role
        FROM grader_users
        WHERE is_active = 1
        ORDER BY id ASC
        LIMIT 1
    ");
    $currentUser = $currentUserStmt->fetch(PDO::FETCH_ASSOC) ?: null;

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
                    'theme_color' => graderapp_normalize_theme_color((string) ($found['theme_color'] ?? '#185b86')),
                    'status' => (string) $found['status'],
                ];
            }
        }
    }

    if ($currentUser) {
        $stmt = $pdo->prepare("
            SELECT c.*,
                   u.full_name AS owner_name,
                   COUNT(DISTINCT m.id) AS module_count,
                   COUNT(DISTINCT p.id) AS problem_count,
                   MAX(CASE WHEN c.owner_user_id = :user_id THEN 1 ELSE 0 END) AS is_owner,
                   MAX(CASE WHEN e.user_id = :user_id THEN 1 ELSE 0 END) AS is_member
            FROM grader_courses c
            LEFT JOIN grader_users u ON u.id = c.owner_user_id
            LEFT JOIN grader_modules m ON m.course_id = c.id
            LEFT JOIN grader_problems p ON p.module_id = m.id
            LEFT JOIN grader_course_enrollments e ON e.course_id = c.id
            WHERE c.owner_user_id = :user_id
               OR e.user_id = :user_id
            GROUP BY c.id
            ORDER BY c.status = 'archived', c.updated_at DESC, c.id DESC
        ");
        $stmt->execute([':user_id' => (int) $currentUser['id']]);
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $courses = $pdo->query("
            SELECT c.*, u.full_name AS owner_name,
                   COUNT(DISTINCT m.id) AS module_count,
                   COUNT(DISTINCT p.id) AS problem_count,
                   1 AS is_owner,
                   1 AS is_member
            FROM grader_courses c
            LEFT JOIN grader_users u ON u.id = c.owner_user_id
            LEFT JOIN grader_modules m ON m.course_id = c.id
            LEFT JOIN grader_problems p ON p.module_id = m.id
            GROUP BY c.id
            ORDER BY c.status = 'archived', c.updated_at DESC, c.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($courses as $course) {
        if ((string) $course['status'] === 'archived') {
            $archivedCourses[] = $course;
        } else {
            $activeCourses[] = $course;
        }
    }

    if ($courseForm['id'] > 0) {
        foreach ($courses as $course) {
            if ((int) $course['id'] === (int) $courseForm['id']) {
                $selectedCourse = $course;
                break;
            }
        }
    }

    if (!$selectedCourse && $activeCourses) {
        $selectedCourse = $activeCourses[0];
    }

    if ($selectedCourse) {
        $moduleStmt = $pdo->prepare("
            SELECT id, title, description, sort_order, is_active
            FROM grader_modules
            WHERE course_id = :course_id
            ORDER BY sort_order ASC, id ASC
        ");
        $moduleStmt->execute([':course_id' => (int) $selectedCourse['id']]);
        $selectedModules = $moduleStmt->fetchAll(PDO::FETCH_ASSOC);

        $problemStmt = $pdo->prepare("
            SELECT p.id, p.module_id, p.title, p.language, p.visibility, p.max_score, p.time_limit_sec, p.memory_limit_mb,
                   p.sort_order, m.title AS module_title
            FROM grader_problems p
            INNER JOIN grader_modules m ON m.id = p.module_id
            WHERE m.course_id = :course_id
            ORDER BY m.sort_order ASC, p.sort_order ASC, p.id ASC
        ");
        $problemStmt->execute([':course_id' => (int) $selectedCourse['id']]);
        $selectedProblems = $problemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$selectedGradient = graderapp_admin_course_gradient((string) ($courseForm['theme_color'] ?? '#185b86'));
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
        body {
            font-family: "Sarabun", system-ui, sans-serif;
            background: #f4f7fb;
            color: #16263a;
            overflow: hidden;
        }
        .workspace {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }
        .sidebar {
            width: 320px;
            background: #ffffff;
            border-right: 1px solid #e5ebf3;
            display: flex;
            flex-direction: column;
            transition: transform .22s ease, width .22s ease, border-right-width .22s ease;
            z-index: 20;
            overflow: hidden;
        }
        .sidebar.collapsed {
            width: 0;
            border-right-width: 0;
        }
        .sidebar.collapsed .sidebar-copy,
        .sidebar.collapsed .sidebar-mark,
        .sidebar.collapsed .sidebar-section-title,
        .sidebar.collapsed .sidebar-link-text,
        .sidebar.collapsed .sidebar-bottom-label,
        .sidebar.collapsed .sidebar-meta {
            display: none;
        }
        .sidebar-header {
            padding: 1rem 1rem .8rem;
            display: flex;
            align-items: center;
            gap: .85rem;
        }
        .workspace-toggle {
            width: 2.8rem;
            height: 2.8rem;
            border: 0;
            border-radius: 14px;
            background: #edf3fb;
            color: #24456f;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
        }
        .sidebar-mark {
            width: 2.8rem;
            height: 2.8rem;
            border-radius: 14px;
            background: linear-gradient(135deg, #173b6d 0%, #2c73bf 100%);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .sidebar-scroll {
            padding: .35rem .8rem 1rem;
            overflow-y: auto;
            flex: 1;
        }
        .sidebar-section-title {
            padding: .7rem .65rem .45rem;
            color: #74839a;
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
        }
        .sidebar-link {
            display: flex;
            align-items: center;
            gap: .8rem;
            padding: .75rem .8rem;
            border-radius: 18px;
            text-decoration: none;
            color: #213958;
            margin-bottom: .25rem;
        }
        .sidebar-link:hover {
            background: #f3f7fc;
            color: #16395f;
        }
        .sidebar-link.active {
            background: #edf4ff;
            color: #184a88;
        }
        .sidebar-link-icon {
            width: 2.35rem;
            height: 2.35rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #fff;
            flex-shrink: 0;
        }
        .sidebar-link-text {
            min-width: 0;
        }
        .sidebar-link-title {
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-meta {
            color: #74839a;
            font-size: .86rem;
        }
        .sidebar-bottom {
            padding: .8rem;
            border-top: 1px solid #e5ebf3;
            display: grid;
            gap: .35rem;
        }
        .sidebar-bottom-link {
            display: flex;
            align-items: center;
            gap: .8rem;
            text-decoration: none;
            color: #24405f;
            padding: .8rem .9rem;
            border-radius: 18px;
        }
        .sidebar-bottom-link:hover {
            background: #f3f7fc;
        }
        .content {
            flex: 1;
            min-width: 0;
            overflow-y: auto;
        }
        .content-shell {
            max-width: 1480px;
            margin: 0 auto;
            padding: 1.2rem 1.4rem 2rem;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding-bottom: 1rem;
        }
        .topbar-start {
            display: flex;
            align-items: center;
            gap: .9rem;
            min-width: 0;
        }
        .crumbs {
            display: inline-flex;
            align-items: center;
            gap: .8rem;
            flex-wrap: wrap;
            color: #4f6078;
            font-size: 1.05rem;
            font-weight: 600;
        }
        .crumb-separator {
            color: #91a0b5;
        }
        .course-tabs {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin: 1rem 0 1.35rem;
            padding: 0 1rem;
            border-bottom: 1px solid #dfe6ef;
            overflow-x: auto;
            white-space: nowrap;
        }
        .course-tab {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: 1rem 0 .95rem;
            color: #445469;
            text-decoration: none;
            font-weight: 700;
            position: relative;
        }
        .course-tab.active {
            color: #1f5fcc;
        }
        .course-tab.active::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: -1px;
            height: 4px;
            border-radius: 999px 999px 0 0;
            background: #1f5fcc;
        }
        .course-tab-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.2rem;
            height: 2.2rem;
            border-radius: 999px;
            background: #d7efdc;
            color: #2b6c3b;
            font-size: .95rem;
            font-weight: 700;
        }
        .surface-card {
            background: #fff;
            border-radius: 28px;
            box-shadow: 0 18px 46px rgba(17,39,67,.08);
        }
        .hero-banner {
            min-height: 210px;
            border-radius: 30px;
            color: #fff;
            padding: 1.9rem 2rem;
            position: relative;
            overflow: hidden;
        }
        .hero-banner::after {
            content: "";
            position: absolute;
            right: -70px;
            top: -34px;
            width: 280px;
            height: 280px;
            border-radius: 60px;
            background: rgba(255,255,255,.14);
            transform: rotate(16deg);
        }
        .hero-title {
            font-size: clamp(2rem, 3vw, 3.3rem);
            font-weight: 700;
            line-height: 1.04;
            position: relative;
            z-index: 1;
        }
        .hero-subtitle {
            position: relative;
            z-index: 1;
            opacity: .84;
        }
        .hero-actions {
            position: relative;
            z-index: 1;
        }
        .hero-btn {
            border-radius: 999px;
            padding: .8rem 1.25rem;
            font-weight: 700;
            border: 0;
        }
        .hero-btn-light {
            background: #fff;
            color: #1d4f8c;
        }
        .stream-card {
            padding: 1.4rem;
        }
        .stat-pill {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            border-radius: 999px;
            padding: .38rem .72rem;
            background: #eef4fb;
            color: #24456f;
            font-weight: 700;
            font-size: .9rem;
        }
        .quick-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: .75rem;
            margin: 1.2rem 0;
        }
        .quick-item {
            border: 1px solid #e6edf6;
            border-radius: 22px;
            padding: 1rem;
            background: #fff;
        }
        .announce-actions {
            display: flex;
            align-items: center;
            gap: .9rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .announce-button {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            border-radius: 999px;
            padding: .9rem 1.4rem;
            background: #cae9ff;
            color: #0c4f94;
            font-weight: 700;
            text-decoration: none;
        }
        .announce-link {
            color: #1c56b4;
            font-weight: 700;
            text-decoration: none;
        }
        .stream-list {
            display: grid;
            gap: 1rem;
        }
        .stream-post {
            border-radius: 24px;
            background: #f5f8fc;
            border: 1px solid #e1eaf4;
            padding: 1.1rem 1.2rem;
        }
        .stream-post-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: .85rem;
        }
        .stream-post-avatar {
            width: 3rem;
            height: 3rem;
            border-radius: 999px;
            background: #3d73f2;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            flex-shrink: 0;
        }
        .stream-post-meta {
            display: flex;
            align-items: flex-start;
            gap: .85rem;
        }
        .problem-list {
            display: grid;
            gap: .75rem;
            margin-top: .9rem;
        }
        .problem-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .95rem 1rem;
            border-radius: 18px;
            background: #fff;
            border: 1px solid #dfebf8;
            text-decoration: none;
            color: inherit;
        }
        .problem-item:hover {
            border-color: #b7d3f6;
            background: #fbfdff;
        }
        .problem-title {
            font-weight: 700;
        }
        .problem-meta {
            display: flex;
            flex-wrap: wrap;
            gap: .45rem;
            margin-top: .3rem;
        }
        .stream-empty {
            border-radius: 24px;
            border: 1px dashed #d4deeb;
            background: #fbfdff;
            color: #718198;
            padding: 1.15rem;
        }
        .settings-modal .modal-content {
            border: 0;
            border-radius: 28px;
            box-shadow: 0 22px 46px rgba(13, 27, 48, 0.18);
        }
        .settings-modal .modal-header,
        .settings-modal .modal-footer {
            border: 0;
        }
        .form-control,
        .form-select {
            border-radius: 16px;
            border-color: #d6e0ec;
            min-height: 48px;
        }
        .form-control-color {
            min-width: 100%;
            padding: .35rem;
        }
        .action-row {
            display: flex;
            gap: .7rem;
            flex-wrap: wrap;
        }
        .btn-rounded {
            border-radius: 999px;
            padding: .72rem 1.15rem;
            font-weight: 700;
        }
        @media (max-width: 991.98px) {
            body {
                overflow: auto;
            }
            .workspace {
                display: block;
            }
            .sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                height: 100vh;
                transform: translateX(-100%);
                box-shadow: 18px 0 40px rgba(13, 27, 48, 0.15);
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .content {
                overflow: visible;
            }
        }
    </style>
</head>
<body>
    <div class="workspace">
        <aside class="sidebar" id="primarySidebar">
            <div class="sidebar-header">
                <div class="sidebar-mark"><i class="bi bi-mortarboard-fill"></i></div>
                <div class="sidebar-copy">
                    <div class="fw-bold fs-5">Grader Classroom</div>
                    <div class="text-secondary small"><?= htmlspecialchars($currentUser['full_name'] ?? 'Course owner') ?></div>
                </div>
            </div>

            <div class="sidebar-scroll">
                <div class="sidebar-section-title">วิชาของฉัน</div>
                <?php foreach ($activeCourses as $course): ?>
                    <?php
                    $color = graderapp_normalize_theme_color((string) ($course['theme_color'] ?? '#185b86'));
                    $label = mb_substr((string) $course['course_code'], 0, 1);
                    ?>
                    <a href="<?= htmlspecialchars(graderapp_path('grader.classroom', ['edit' => (int) $course['id']])) ?>" class="sidebar-link<?= (int) $course['id'] === (int) $courseForm['id'] ? ' active' : '' ?>">
                        <span class="sidebar-link-icon" style="background: <?= htmlspecialchars($color) ?>;"><?= htmlspecialchars($label) ?></span>
                        <span class="sidebar-link-text">
                            <span class="sidebar-link-title"><?= htmlspecialchars((string) $course['course_name']) ?></span>
                            <span class="sidebar-meta"><?= htmlspecialchars((string) $course['course_code']) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>

                <?php if (!$activeCourses): ?>
                    <div class="px-3 py-2 text-secondary small">ยังไม่มีวิชาที่เปิดใช้งาน</div>
                <?php endif; ?>

                <div class="sidebar-section-title">วิชาในคลัง</div>
                <?php foreach ($archivedCourses as $course): ?>
                    <?php
                    $color = graderapp_normalize_theme_color((string) ($course['theme_color'] ?? '#185b86'));
                    $label = mb_substr((string) $course['course_code'], 0, 1);
                    ?>
                    <a href="<?= htmlspecialchars(graderapp_path('grader.classroom', ['edit' => (int) $course['id']])) ?>" class="sidebar-link<?= (int) $course['id'] === (int) $courseForm['id'] ? ' active' : '' ?>">
                        <span class="sidebar-link-icon" style="background: <?= htmlspecialchars($color) ?>; opacity:.82;"><?= htmlspecialchars($label) ?></span>
                        <span class="sidebar-link-text">
                            <span class="sidebar-link-title"><?= htmlspecialchars((string) $course['course_name']) ?></span>
                            <span class="sidebar-meta">archived</span>
                        </span>
                    </a>
                <?php endforeach; ?>

                <?php if (!$archivedCourses): ?>
                    <div class="px-3 py-2 text-secondary small">ยังไม่มีวิชาที่เก็บไว้</div>
                <?php endif; ?>
            </div>

            <div class="sidebar-bottom">
                <a href="<?= htmlspecialchars(graderapp_path('grader.admin')) ?>" class="sidebar-bottom-link">
                    <i class="bi bi-gear"></i>
                    <span class="sidebar-bottom-label">การตั้งค่า</span>
                </a>
                <a href="<?= htmlspecialchars(graderapp_path('grader.classroom')) ?>#archivedVault" class="sidebar-bottom-link">
                    <i class="bi bi-archive"></i>
                    <span class="sidebar-bottom-label">ดูวิชาที่เก็บไว้ในคลัง</span>
                </a>
            </div>
        </aside>

        <main class="content">
            <div class="content-shell">
                <div class="topbar">
                    <div class="topbar-start">
                        <button class="workspace-toggle" type="button" id="sidebarToggle" aria-label="Toggle sidebar">
                            <i class="bi bi-layout-sidebar-inset"></i>
                        </button>
                        <div class="crumbs">
                            <span>Classroom</span>
                            <span class="crumb-separator">></span>
                            <span><?= htmlspecialchars($courseForm['course_name'] !== '' ? $courseForm['course_name'] : 'สร้างวิชาใหม่') ?></span>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= htmlspecialchars(graderapp_path('grader.dashboard')) ?>" class="btn btn-outline-secondary btn-rounded">
                            <i class="bi bi-grid"></i> Course Hub
                        </a>
                        <button type="button" class="btn btn-dark btn-rounded" data-bs-toggle="modal" data-bs-target="#courseSettingsModal">
                            <i class="bi bi-gear-fill"></i> ตั้งค่าวิชา
                        </button>
                    </div>
                </div>

                <nav class="course-tabs" aria-label="Course sections">
                    <a href="#" class="course-tab active">ฟอรั่ม</a>
                    <a href="#" class="course-tab">งานของชั้นเรียน</a>
                    <a href="#" class="course-tab">บุคคล</a>
                    <a href="#" class="course-tab">คะแนน</a>
                    <a href="#" class="course-tab">การวิเคราะห์ <span class="course-tab-badge">ใหม่</span></a>
                </nav>

                <?php if ($noticeMessage !== '' && in_array($noticeType, ['success', 'danger'], true)): ?>
                    <div class="alert alert-<?= htmlspecialchars($noticeType) ?> rounded-4 border-0 shadow-sm"><?= htmlspecialchars($noticeMessage) ?></div>
                <?php endif; ?>
                <?php if (!$db_ready): ?>
                    <div class="alert alert-danger rounded-4 border-0 shadow-sm"><code><?= htmlspecialchars($error_message) ?></code></div>
                <?php else: ?>
                    <section class="hero-banner surface-card" style="background: linear-gradient(135deg, <?= htmlspecialchars($selectedGradient[0]) ?> 0%, <?= htmlspecialchars($selectedGradient[1]) ?> 100%);">
                        <div class="hero-subtitle mb-2"><?= htmlspecialchars((string) ($courseForm['course_code'] !== '' ? $courseForm['course_code'] : 'New Course')) ?></div>
                        <div class="hero-title mb-3"><?= htmlspecialchars((string) ($courseForm['course_name'] !== '' ? $courseForm['course_name'] : 'ตั้งค่ารายวิชา')) ?></div>
                        <div class="hero-subtitle mb-4"><?= htmlspecialchars((string) (($courseForm['academic_year'] ?: '-') . ' / ภาคเรียน ' . ($courseForm['semester'] ?: '-'))) ?></div>
                        <div class="hero-actions d-flex gap-2 flex-wrap">
                            <button type="button" class="hero-btn hero-btn-light" data-bs-toggle="modal" data-bs-target="#courseSettingsModal">
                                <i class="bi bi-pencil-square"></i> กำหนดเอง
                            </button>
                        </div>
                    </section>

                    <div class="quick-list">
                        <div class="quick-item">
                            <div class="fw-bold mb-2">ลิงก์ด่วน</div>
                            <div class="text-secondary small mb-3">ใช้งานวิชานี้ได้จากปุ่มด้านล่าง</div>
                            <button type="button" class="btn btn-outline-primary btn-rounded w-100 mb-2" data-copy-value="<?= htmlspecialchars((!empty($_SERVER['HTTP_HOST']) ? (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']) : 'http://localhost:8080') . graderapp_path('grader.dashboard', ['join' => $courseForm['join_code']])) ?>">
                                <i class="bi bi-link-45deg"></i> คัดลอกลิงก์เชิญ
                            </button>
                            <a href="<?= htmlspecialchars(graderapp_path('grader.admin.modules')) ?>" class="btn btn-outline-secondary btn-rounded w-100">
                                <i class="bi bi-collection-fill"></i> จัดการบทเรียน
                            </a>
                        </div>
                        <div class="quick-item">
                            <div class="fw-bold mb-2">ภาพรวม</div>
                            <div class="d-flex gap-2 flex-wrap">
                                <span class="stat-pill"><?= htmlspecialchars((string) ($courseForm['course_code'] !== '' ? $courseForm['course_code'] : 'NEW')) ?></span>
                                <span class="stat-pill"><i class="bi bi-collection"></i> <?= count($selectedModules) ?> บท</span>
                                <span class="stat-pill"><i class="bi bi-code-slash"></i> <?= count($selectedProblems) ?> โจทย์</span>
                                <span class="stat-pill"><?= htmlspecialchars((string) $courseForm['status']) ?></span>
                            </div>
                        </div>
                        <div class="quick-item">
                            <div class="fw-bold mb-2">เจ้าของวิชา</div>
                            <div class="text-secondary small"><?= htmlspecialchars((string) ($selectedCourse['owner_name'] ?? '-')) ?></div>
                        </div>
                        <div class="quick-item" id="archivedVault">
                            <div class="fw-bold mb-2">วิชาในคลัง</div>
                            <div class="text-secondary small mb-2"><?= count($archivedCourses) ?> วิชา</div>
                            <?php foreach (array_slice($archivedCourses, 0, 4) as $course): ?>
                                <a href="<?= htmlspecialchars(graderapp_path('grader.classroom', ['edit' => (int) $course['id']])) ?>" class="d-block text-decoration-none text-dark small py-1">
                                    <?= htmlspecialchars((string) $course['course_name']) ?>
                                </a>
                            <?php endforeach; ?>
                            <?php if (!$archivedCourses): ?>
                                <div class="small text-secondary">ยังไม่มีวิชาที่เก็บไว้</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <section class="surface-card stream-card">
                            <div class="announce-actions">
                                <a href="#" class="announce-button">
                                    <i class="bi bi-pencil"></i> ประกาศใหม่
                                </a>
                                <a href="#" class="announce-link">
                                    <i class="bi bi-arrow-left-right"></i> รีโพสต์
                                </a>
                            </div>

                            <div class="stream-list">
                                <article class="stream-post">
                                    <div class="stream-post-header">
                                        <div class="stream-post-meta">
                                            <div class="stream-post-avatar"><?= htmlspecialchars(mb_substr((string) ($currentUser['full_name'] ?? 'A'), 0, 1)) ?></div>
                                            <div>
                                                <div class="fw-bold"><?= htmlspecialchars((string) ($currentUser['full_name'] ?? 'อาจารย์ผู้สอน')) ?></div>
                                                <div class="text-secondary small">กระดานประกาศของวิชานี้</div>
                                            </div>
                                        </div>
                                        <span class="text-secondary small"><?= htmlspecialchars((string) (($courseForm['academic_year'] ?: '-') . ' / ภาคเรียน ' . ($courseForm['semester'] ?: '-'))) ?></span>
                                    </div>
                                    <div>พื้นที่นี้ใช้สำหรับประกาศข่าวสาร วาง session และพาผู้เรียนเข้าโจทย์ของแต่ละบท</div>
                                </article>

                                <?php if ($selectedModules): ?>
                                    <?php foreach ($selectedModules as $module): ?>
                                        <?php
                                        $moduleProblems = array_values(array_filter(
                                            $selectedProblems,
                                            static fn(array $problem): bool => (int) $problem['module_id'] === (int) $module['id']
                                        ));
                                        ?>
                                        <article class="stream-post">
                                            <div class="stream-post-header">
                                                <div class="stream-post-meta">
                                                    <div class="stream-post-avatar" style="background:#5a84f1;"><?= htmlspecialchars((string) max(1, (int) $module['sort_order'])) ?></div>
                                                    <div>
                                                        <div class="fw-bold"><?= htmlspecialchars((string) $module['title']) ?></div>
                                                        <div class="text-secondary small"><?= htmlspecialchars((string) ($module['description'] ?: 'Session สำหรับโจทย์ในบทนี้')) ?></div>
                                                    </div>
                                                </div>
                                                <span class="text-secondary small"><?= count($moduleProblems) ?> โจทย์</span>
                                            </div>
                                            <?php if ($moduleProblems): ?>
                                                <div class="problem-list">
                                                    <?php foreach ($moduleProblems as $problem): ?>
                                                        <a href="<?= htmlspecialchars(graderapp_path('grader.problem', ['id' => (int) $problem['id']])) ?>" class="problem-item">
                                                            <div>
                                                                <div class="problem-title"><?= htmlspecialchars((string) $problem['title']) ?></div>
                                                                <div class="problem-meta">
                                                                    <span class="stat-pill"><?= htmlspecialchars((string) $problem['language']) ?></span>
                                                                    <span class="stat-pill"><?= htmlspecialchars((string) $problem['visibility']) ?></span>
                                                                    <span class="stat-pill"><?= (int) $problem['max_score'] ?> คะแนน</span>
                                                                </div>
                                                            </div>
                                                            <span class="text-secondary"><i class="bi bi-chevron-right"></i></span>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="stream-empty">ยังไม่มีโจทย์ใน session นี้</div>
                                            <?php endif; ?>
                                        </article>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="stream-empty">ยังไม่มีบทเรียนหรือโจทย์ในวิชานี้</div>
                                <?php endif; ?>
                            </div>
                    </section>

                    <div class="modal fade settings-modal" id="courseSettingsModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-xl">
                            <div class="modal-content">
                                <form method="POST" class="row g-0" id="courseEditorForm">
                                    <div class="modal-header px-4 pt-4 pb-2">
                                        <div>
                                            <h2 class="h3 fw-bold mb-1"><?= $courseForm['id'] > 0 ? 'แก้ไขวิชา' : 'สร้างวิชาใหม่' ?></h2>
                                            <div class="text-secondary">แก้ไขชื่อ รหัส สีการ์ด สถานะ และลิงก์เชิญของรายวิชา</div>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body px-4 pb-2">
                                        <input type="hidden" name="action" value="save_course">
                                        <input type="hidden" name="course_id" value="<?= (int) $courseForm['id'] ?>">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold">รหัสวิชา</label>
                                                <input type="text" name="course_code" class="form-control" value="<?= htmlspecialchars($courseForm['course_code']) ?>" required>
                                            </div>
                                            <div class="col-md-8">
                                                <label class="form-label fw-bold">ชื่อวิชา</label>
                                                <input type="text" name="course_name" class="form-control" value="<?= htmlspecialchars($courseForm['course_name']) ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold">ปีการศึกษา</label>
                                                <input type="text" name="academic_year" class="form-control" value="<?= htmlspecialchars($courseForm['academic_year']) ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold">ภาคเรียน</label>
                                                <input type="text" name="semester" class="form-control" value="<?= htmlspecialchars($courseForm['semester']) ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold">สีการ์ด</label>
                                                <input type="color" name="theme_color" class="form-control form-control-color" value="<?= htmlspecialchars($courseForm['theme_color']) ?>">
                                            </div>
                                            <div class="col-lg-7">
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
                                            <div class="col-lg-5">
                                                <label class="form-label fw-bold">สถานะ</label>
                                                <select name="status" class="form-select">
                                                    <?php foreach (['draft', 'published', 'archived'] as $status): ?>
                                                        <option value="<?= $status ?>" <?= $courseForm['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-lg-8">
                                                <label class="form-label fw-bold">Join Code</label>
                                                <input type="text" name="join_code" class="form-control" value="<?= htmlspecialchars($courseForm['join_code']) ?>">
                                            </div>
                                            <div class="col-lg-4">
                                                <label class="form-label fw-bold">ลิงก์เชิญ</label>
                                                <input
                                                    type="text"
                                                    class="form-control"
                                                    readonly
                                                    value="<?= htmlspecialchars((!empty($_SERVER['HTTP_HOST']) ? (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']) : 'http://localhost:8080') . graderapp_path('grader.dashboard', ['join' => $courseForm['join_code']])) ?>"
                                                >
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer px-4 pb-4 action-row">
                                        <button type="submit" class="btn btn-dark btn-rounded">
                                            <i class="bi bi-save-fill"></i> บันทึก
                                        </button>
                                        <a href="<?= htmlspecialchars(graderapp_path('grader.classroom')) ?>" class="btn btn-outline-secondary btn-rounded">วิชาใหม่</a>
                                        <?php if ($courseForm['id'] > 0): ?>
                                            <button type="submit" form="deleteCourseForm" class="btn btn-outline-danger btn-rounded">ลบวิชา</button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <?php if ($courseForm['id'] > 0): ?>
                        <form method="POST" id="deleteCourseForm" onsubmit="return confirm('ลบรายวิชานี้ใช่หรือไม่');">
                            <input type="hidden" name="action" value="delete_course">
                            <input type="hidden" name="course_id" value="<?= (int) $courseForm['id'] ?>">
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebar = document.getElementById('primarySidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');

        if (sidebar && sidebarToggle) {
            sidebarToggle.addEventListener('click', function () {
                if (window.innerWidth <= 991) {
                    sidebar.classList.toggle('open');
                    return;
                }

                sidebar.classList.toggle('collapsed');
            });
        }

        document.querySelectorAll('[data-copy-value]').forEach(function (button) {
            button.addEventListener('click', async function () {
                const value = this.getAttribute('data-copy-value') || '';
                if (!value) {
                    return;
                }

                try {
                    await navigator.clipboard.writeText(value);
                    const original = this.innerHTML;
                    this.innerHTML = '<i class="bi bi-check2"></i> คัดลอกแล้ว';
                    setTimeout(() => {
                        this.innerHTML = original;
                    }, 1400);
                } catch (error) {
                    window.prompt('คัดลอกลิงก์เชิญ', value);
                }
            });
        });
    </script>
</body>
</html>
