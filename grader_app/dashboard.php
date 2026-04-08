<?php

require_once __DIR__ . '/bootstrap.php';

function graderapp_dashboard_adjust_hex(string $hex, int $offset): string
{
    $hex = ltrim(graderapp_normalize_theme_color($hex), '#');
    $parts = str_split($hex, 2);
    $rgb = array_map('hexdec', $parts);

    foreach ($rgb as $index => $channel) {
        $rgb[$index] = max(0, min(255, $channel + $offset));
    }

    return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
}

function graderapp_dashboard_gradient(string $baseColor): array
{
    $base = graderapp_normalize_theme_color($baseColor);
    return [
        graderapp_dashboard_adjust_hex($base, -12),
        graderapp_dashboard_adjust_hex($base, 34),
    ];
}

function graderapp_dashboard_notice(string $type, string $message): string
{
    return graderapp_path('grader.dashboard', [
        'notice_type' => $type,
        'notice_message' => $message,
    ]);
}

function graderapp_dashboard_invite_url(array $course): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . graderapp_path('grader.dashboard', [
        'join' => (string) ($course['join_code'] ?? ''),
    ]);
}

function graderapp_dashboard_unique_course_code(PDO $pdo, string $courseCode): string
{
    $base = strtoupper(trim($courseCode));
    $candidate = $base . '-COPY';
    $counter = 2;

    while (true) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM grader_courses WHERE course_code = :course_code");
        $stmt->execute([':course_code' => $candidate]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }

        $candidate = $base . '-COPY' . $counter;
        $counter++;
    }
}

function graderapp_dashboard_unique_problem_slug(PDO $pdo, string $slugBase): string
{
    $candidate = graderapp_slugify($slugBase);
    $counter = 2;

    while (true) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM grader_problems WHERE slug = :slug");
        $stmt->execute([':slug' => $candidate]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }

        $candidate = graderapp_slugify($slugBase . '-' . $counter);
        $counter++;
    }
}

function graderapp_dashboard_duplicate_course(PDO $pdo, array $course, int $ownerUserId): int
{
    $pdo->beginTransaction();

    try {
        $newCourseCode = graderapp_dashboard_unique_course_code($pdo, (string) $course['course_code']);
        $newCourseName = trim((string) $course['course_name']) . ' (คัดลอก)';

        $courseStmt = $pdo->prepare("
            INSERT INTO grader_courses
                (course_code, course_name, academic_year, semester, owner_user_id, join_code, theme_color, status)
            VALUES
                (:course_code, :course_name, :academic_year, :semester, :owner_user_id, :join_code, :theme_color, :status)
        ");
        $courseStmt->execute([
            ':course_code' => $newCourseCode,
            ':course_name' => $newCourseName,
            ':academic_year' => (string) $course['academic_year'],
            ':semester' => (string) $course['semester'],
            ':owner_user_id' => $ownerUserId,
            ':join_code' => graderapp_generate_join_code(),
            ':theme_color' => graderapp_normalize_theme_color((string) ($course['theme_color'] ?? '#185b86')),
            ':status' => 'draft',
        ]);

        $newCourseId = (int) $pdo->lastInsertId();
        $moduleMap = [];
        $problemMap = [];

        $modulesStmt = $pdo->prepare("
            SELECT id, title, description, sort_order, is_active
            FROM grader_modules
            WHERE course_id = :course_id
            ORDER BY sort_order ASC, id ASC
        ");
        $modulesStmt->execute([':course_id' => (int) $course['id']]);
        $modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);

        $insertModuleStmt = $pdo->prepare("
            INSERT INTO grader_modules
                (course_id, title, description, sort_order, is_active)
            VALUES
                (:course_id, :title, :description, :sort_order, :is_active)
        ");
        foreach ($modules as $module) {
            $insertModuleStmt->execute([
                ':course_id' => $newCourseId,
                ':title' => (string) $module['title'],
                ':description' => $module['description'],
                ':sort_order' => (int) $module['sort_order'],
                ':is_active' => (int) $module['is_active'],
            ]);
            $moduleMap[(int) $module['id']] = (int) $pdo->lastInsertId();
        }

        if ($moduleMap) {
            $placeholders = implode(',', array_fill(0, count($moduleMap), '?'));
            $problemsStmt = $pdo->prepare("
                SELECT id, module_id, title, slug, description_md, starter_code, language, time_limit_sec, memory_limit_mb,
                       max_score, visibility, sort_order, created_by
                FROM grader_problems
                WHERE module_id IN ({$placeholders})
                ORDER BY sort_order ASC, id ASC
            ");
            $problemsStmt->execute(array_keys($moduleMap));
            $problems = $problemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $insertProblemStmt = $pdo->prepare("
                INSERT INTO grader_problems
                    (module_id, title, slug, description_md, starter_code, language, time_limit_sec, memory_limit_mb,
                     max_score, visibility, sort_order, created_by)
                VALUES
                    (:module_id, :title, :slug, :description_md, :starter_code, :language, :time_limit_sec, :memory_limit_mb,
                     :max_score, :visibility, :sort_order, :created_by)
            ");

            foreach ($problems as $problem) {
                $newSlug = graderapp_dashboard_unique_problem_slug($pdo, (string) $problem['slug'] . '-copy');
                $insertProblemStmt->execute([
                    ':module_id' => $moduleMap[(int) $problem['module_id']],
                    ':title' => (string) $problem['title'],
                    ':slug' => $newSlug,
                    ':description_md' => $problem['description_md'],
                    ':starter_code' => $problem['starter_code'],
                    ':language' => (string) $problem['language'],
                    ':time_limit_sec' => $problem['time_limit_sec'],
                    ':memory_limit_mb' => (int) $problem['memory_limit_mb'],
                    ':max_score' => (int) $problem['max_score'],
                    ':visibility' => 'draft',
                    ':sort_order' => (int) $problem['sort_order'],
                    ':created_by' => $problem['created_by'] === null ? null : (int) $problem['created_by'],
                ]);
                $problemMap[(int) $problem['id']] = (int) $pdo->lastInsertId();
            }
        }

        if ($problemMap) {
            $casePlaceholders = implode(',', array_fill(0, count($problemMap), '?'));
            $casesStmt = $pdo->prepare("
                SELECT problem_id, case_type, stdin_text, expected_stdout, score_weight, sort_order
                FROM grader_test_cases
                WHERE problem_id IN ({$casePlaceholders})
                ORDER BY sort_order ASC, id ASC
            ");
            $casesStmt->execute(array_keys($problemMap));
            $cases = $casesStmt->fetchAll(PDO::FETCH_ASSOC);

            $insertCaseStmt = $pdo->prepare("
                INSERT INTO grader_test_cases
                    (problem_id, case_type, stdin_text, expected_stdout, score_weight, sort_order)
                VALUES
                    (:problem_id, :case_type, :stdin_text, :expected_stdout, :score_weight, :sort_order)
            ");

            foreach ($cases as $case) {
                $insertCaseStmt->execute([
                    ':problem_id' => $problemMap[(int) $case['problem_id']],
                    ':case_type' => (string) $case['case_type'],
                    ':stdin_text' => $case['stdin_text'],
                    ':expected_stdout' => $case['expected_stdout'],
                    ':score_weight' => (int) $case['score_weight'],
                    ':sort_order' => (int) $case['sort_order'],
                ]);
            }
        }

        $pdo->commit();
        return $newCourseId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$state = graderapp_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && $pdo;
$error_message = $state['error'] ?? '';
$currentUser = null;
$courses = [];
$courseCount = 0;
$createHref = graderapp_path('grader.classroom');
$noticeType = trim((string) ($_GET['notice_type'] ?? ''));
$noticeMessage = trim((string) ($_GET['notice_message'] ?? ''));

if ($db_ready) {
    try {
        $userStmt = $pdo->query("
            SELECT id, full_name, email, role
            FROM grader_users
            WHERE is_active = 1
            ORDER BY id ASC
            LIMIT 1
        ");
        $currentUser = $userStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($currentUser && isset($_GET['join'])) {
            $joinCode = strtoupper(trim((string) $_GET['join']));
            if ($joinCode !== '') {
                $joinStmt = $pdo->prepare("
                    SELECT id, owner_user_id, status
                    FROM grader_courses
                    WHERE join_code = :join_code
                    LIMIT 1
                ");
                $joinStmt->execute([':join_code' => $joinCode]);
                $joinCourse = $joinStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($joinCourse && (int) $joinCourse['owner_user_id'] !== (int) $currentUser['id'] && (string) $joinCourse['status'] !== 'archived') {
                    $enrollStmt = $pdo->prepare("
                        INSERT INTO grader_course_enrollments (course_id, user_id, role_in_course)
                        VALUES (:course_id, :user_id, 'student')
                        ON DUPLICATE KEY UPDATE role_in_course = role_in_course
                    ");
                    $enrollStmt->execute([
                        ':course_id' => (int) $joinCourse['id'],
                        ':user_id' => (int) $currentUser['id'],
                    ]);
                    header('Location: ' . graderapp_dashboard_notice('success', 'เข้าร่วมวิชาเรียบร้อยแล้ว'));
                    exit;
                }

                header('Location: ' . graderapp_path('grader.dashboard'));
                exit;
            }
        }

        if ($currentUser && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = trim((string) ($_POST['action'] ?? ''));
            $courseId = (int) ($_POST['course_id'] ?? 0);

            $courseStmt = $pdo->prepare("
                SELECT *
                FROM grader_courses
                WHERE id = :course_id
                  AND owner_user_id = :owner_user_id
                LIMIT 1
            ");
            $courseStmt->execute([
                ':course_id' => $courseId,
                ':owner_user_id' => (int) $currentUser['id'],
            ]);
            $ownedCourse = $courseStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($action !== '' && !$ownedCourse) {
                throw new RuntimeException('ไม่พบวิชาที่คุณมีสิทธิ์จัดการ');
            }

            if ($action === 'quick_update_course') {
                $payload = [
                    ':course_id' => $courseId,
                    ':course_code' => strtoupper(trim((string) ($_POST['course_code'] ?? ''))),
                    ':course_name' => trim((string) ($_POST['course_name'] ?? '')),
                    ':academic_year' => trim((string) ($_POST['academic_year'] ?? '')),
                    ':semester' => trim((string) ($_POST['semester'] ?? '')),
                    ':theme_color' => graderapp_normalize_theme_color((string) ($_POST['theme_color'] ?? '#185b86')),
                ];

                if ($payload[':course_code'] === '' || $payload[':course_name'] === '') {
                    throw new RuntimeException('กรุณากรอกรหัสวิชาและชื่อวิชาให้ครบ');
                }

                $updateStmt = $pdo->prepare("
                    UPDATE grader_courses
                    SET course_code = :course_code,
                        course_name = :course_name,
                        academic_year = :academic_year,
                        semester = :semester,
                        theme_color = :theme_color
                    WHERE id = :course_id
                ");
                $updateStmt->execute($payload);
                header('Location: ' . graderapp_dashboard_notice('success', 'อัปเดตวิชาเรียบร้อยแล้ว'));
                exit;
            }

            if ($action === 'toggle_archive_course') {
                $nextStatus = ((string) $ownedCourse['status'] === 'archived') ? 'published' : 'archived';
                $updateStmt = $pdo->prepare("UPDATE grader_courses SET status = :status WHERE id = :course_id");
                $updateStmt->execute([
                    ':status' => $nextStatus,
                    ':course_id' => $courseId,
                ]);
                $message = $nextStatus === 'archived' ? 'เก็บวิชาเรียบร้อยแล้ว' : 'เปิดวิชาอีกครั้งเรียบร้อยแล้ว';
                header('Location: ' . graderapp_dashboard_notice('success', $message));
                exit;
            }

            if ($action === 'duplicate_course') {
                graderapp_dashboard_duplicate_course($pdo, $ownedCourse, (int) $currentUser['id']);
                header('Location: ' . graderapp_dashboard_notice('success', 'คัดลอกวิชาเรียบร้อยแล้ว'));
                exit;
            }
        }

        if ($currentUser) {
            $stmt = $pdo->prepare("
                SELECT c.id, c.course_code, c.course_name, c.academic_year, c.semester, c.join_code, c.theme_color, c.status,
                       COUNT(DISTINCT m.id) AS module_count,
                       COUNT(DISTINCT p.id) AS problem_count,
                       MAX(CASE WHEN c.owner_user_id = :user_id THEN 1 ELSE 0 END) AS is_owner
                FROM grader_courses c
                LEFT JOIN grader_modules m ON m.course_id = c.id
                LEFT JOIN grader_problems p ON p.module_id = m.id AND p.visibility = 'published'
                LEFT JOIN grader_course_enrollments e ON e.course_id = c.id AND e.user_id = :user_id
                WHERE c.owner_user_id = :user_id
                   OR (e.user_id = :user_id AND c.status <> 'archived')
                GROUP BY c.id
                ORDER BY c.updated_at DESC, c.id DESC
            ");
            $stmt->execute([':user_id' => (int) $currentUser['id']]);
            $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $courseCount = count($courses);
    } catch (Throwable $e) {
        $db_ready = false;
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CPE Grader Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            font-family: "Sarabun", system-ui, sans-serif;
            background: #f6f8fb;
            color: #16263a;
        }
        .shell {
            max-width: 1320px;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 0 1.25rem;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: .85rem;
        }
        .brand-mark {
            width: 2.65rem;
            height: 2.65rem;
            border-radius: 16px;
            background: linear-gradient(135deg, #173b6d 0%, #2c73bf 100%);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
        }
        .hero-card {
            border: 0;
            border-radius: 30px;
            background: #fff;
            box-shadow: 0 16px 40px rgba(21, 38, 64, 0.06);
            padding: 1.4rem 1.6rem;
            margin-bottom: 1.4rem;
        }
        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            border-radius: 999px;
            padding: .38rem .82rem;
            background: #edf3fb;
            color: #23436f;
            font-weight: 700;
            font-size: .9rem;
        }
        .course-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(285px, 1fr));
            gap: 1.15rem;
        }
        .course-card,
        .create-card {
            border-radius: 22px;
            background: #fff;
            overflow: hidden;
            box-shadow: 0 14px 34px rgba(17, 39, 67, 0.08);
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .course-card:hover,
        .create-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 40px rgba(17, 39, 67, 0.11);
        }
        .course-main-link {
            display: block;
            color: inherit;
            text-decoration: none;
        }
        .course-banner {
            min-height: 118px;
            padding: 1rem 1rem 1.1rem;
            color: #fff;
            position: relative;
        }
        .course-banner::after {
            content: "";
            position: absolute;
            right: -18px;
            top: -20px;
            width: 96px;
            height: 96px;
            border-radius: 28px;
            background: rgba(255,255,255,.18);
            transform: rotate(12deg);
        }
        .course-body {
            padding: 1rem;
        }
        .course-footer {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: .5rem;
            padding: 0 1rem 1rem;
        }
        .course-card.is-archived {
            opacity: .86;
        }
        .course-code {
            font-size: .92rem;
            opacity: .92;
        }
        .course-title {
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.05;
            margin-bottom: .25rem;
            position: relative;
            z-index: 1;
        }
        .course-meta {
            font-size: .93rem;
            color: #667790;
            margin-bottom: .75rem;
        }
        .course-stats {
            display: flex;
            gap: .45rem;
            flex-wrap: wrap;
        }
        .course-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border-radius: 999px;
            padding: .28rem .62rem;
            background: #eff4fb;
            color: #24456f;
            font-size: .85rem;
            font-weight: 700;
        }
        .course-menu-toggle {
            width: 2.9rem;
            height: 2.9rem;
            border: 0;
            border-radius: 999px;
            background: #f2f5fa;
            color: #3a4d68;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .course-menu-toggle:hover,
        .course-menu-toggle:focus {
            background: #e7edf5;
            color: #1d3453;
        }
        .course-dropdown {
            min-width: 220px;
            border-radius: 18px;
            padding: .55rem;
            box-shadow: 0 18px 42px rgba(12, 28, 49, 0.16);
        }
        .course-dropdown .dropdown-item {
            border-radius: 12px;
            padding: .8rem .95rem;
            font-weight: 700;
        }
        .create-card {
            border: 1px dashed #cfd9e7;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            padding: 1.3rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 260px;
            text-decoration: none;
            color: inherit;
        }
        .create-icon {
            width: 3.25rem;
            height: 3.25rem;
            border-radius: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #edf3fb;
            color: #245089;
            font-size: 1.35rem;
        }
        .empty-state {
            border-radius: 24px;
            border: 1px dashed #d3deeb;
            background: #fcfdff;
            padding: 1.4rem;
            color: #72829a;
        }
        .modal-content {
            border: 0;
            border-radius: 28px;
            box-shadow: 0 22px 46px rgba(13, 27, 48, 0.18);
        }
        .modal-header,
        .modal-footer {
            border: 0;
        }
        @media (max-width: 767.98px) {
            .topbar {
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="container shell py-4">
        <div class="topbar">
            <div class="brand">
                <div class="brand-mark"><i class="bi bi-code-square"></i></div>
                <div>
                    <div class="fw-bold fs-4">CPE Grader</div>
                    <div class="text-secondary small">Course hub สำหรับทุกคนที่เข้าใช้ระบบ</div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= htmlspecialchars(graderapp_path('grader.home')) ?>" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                    <i class="bi bi-arrow-left"></i> กลับหน้าแรก
                </a>
                <a href="<?= htmlspecialchars($createHref) ?>" class="btn btn-dark rounded-pill px-4 fw-bold">
                    <i class="bi bi-plus-lg"></i> สร้างวิชา
                </a>
            </div>
        </div>

        <?php if ($noticeMessage !== '' && in_array($noticeType, ['success', 'danger'], true)): ?>
            <div class="alert alert-<?= htmlspecialchars($noticeType) ?> rounded-4 border-0 shadow-sm mb-4"><?= htmlspecialchars($noticeMessage) ?></div>
        <?php endif; ?>

        <?php if (!$db_ready): ?>
            <div class="alert alert-danger rounded-4 shadow-sm">
                <div class="fw-bold mb-1">ยังเชื่อมฐานข้อมูลไม่ได้</div>
                <?php if ($error_message !== ''): ?>
                    <div><code><?= htmlspecialchars($error_message) ?></code></div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="hero-card">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                    <div>
                        <div class="meta-pill mb-3"><i class="bi bi-person-badge-fill"></i> Course Hub</div>
                        <h1 class="fw-bold mb-2">My Courses</h1>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="meta-pill"><i class="bi bi-book"></i> <?= number_format($courseCount) ?> วิชา</span>
                        <span class="meta-pill"><i class="bi bi-person-plus-fill"></i> สร้างวิชาได้ทุกคน</span>
                    </div>
                </div>
            </div>

            <div class="course-grid">
                <a href="<?= htmlspecialchars($createHref) ?>" class="create-card">
                    <div>
                        <div class="create-icon mb-3"><i class="bi bi-plus-lg"></i></div>
                        <div class="fw-bold fs-4 mb-2">สร้างวิชาใหม่</div>
                    </div>
                    <div class="fw-bold text-dark">ไปยังหน้าจัดการรายวิชา <i class="bi bi-arrow-right"></i></div>
                </a>

                <?php foreach ($courses as $course): ?>
                    <?php
                    $gradient = graderapp_dashboard_gradient((string) ($course['theme_color'] ?? '#185b86'));
                    $isOwner = (int) $course['is_owner'] === 1;
                    $isArchived = (string) $course['status'] === 'archived';
                    $targetHref = $isOwner
                        ? graderapp_path('grader.classroom', ['edit' => (int) $course['id']])
                        : graderapp_path('grader.problem', ['id' => 1]);
                    ?>
                    <div class="course-card<?= $isArchived ? ' is-archived' : '' ?>">
                        <a href="<?= htmlspecialchars($targetHref) ?>" class="course-main-link">
                            <div class="course-banner" style="background: linear-gradient(135deg, <?= htmlspecialchars($gradient[0]) ?> 0%, <?= htmlspecialchars($gradient[1]) ?> 100%);">
                                <div class="course-code"><?= htmlspecialchars((string) $course['course_code']) ?></div>
                                <div class="course-title"><?= htmlspecialchars((string) $course['course_name']) ?></div>
                                <div class="small opacity-75"><?= htmlspecialchars((string) ($course['academic_year'] . ' / ภาคเรียน ' . $course['semester'])) ?></div>
                            </div>
                            <div class="course-body">
                                <div class="course-meta">
                                    <?= $isOwner ? 'จัดการบทเรียน ผู้ร่วมสอน และการตั้งค่าของวิชานี้ได้จากที่นี่' : 'เปิดวิชาเพื่อดูโจทย์ ส่งคำตอบ และติดตามผลการตรวจ'; ?>
                                </div>
                                <div class="course-stats">
                                    <span class="course-chip"><i class="bi bi-collection"></i> <?= (int) $course['module_count'] ?> บท</span>
                                    <span class="course-chip"><i class="bi bi-code-slash"></i> <?= (int) $course['problem_count'] ?> โจทย์</span>
                                    <span class="course-chip"><i class="bi bi-circle-fill" style="font-size:.45rem;"></i> <?= htmlspecialchars((string) $course['status']) ?></span>
                                    <span class="course-chip"><i class="bi bi-person-badge"></i> <?= $isOwner ? 'อาจารย์' : 'ผู้เรียน' ?></span>
                                </div>
                            </div>
                        </a>
                        <?php if ($isOwner): ?>
                            <div class="course-footer">
                                <div class="dropdown">
                                    <button class="course-menu-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="จัดการวิชา">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end course-dropdown">
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item js-course-edit"
                                                data-bs-toggle="modal"
                                                data-bs-target="#courseEditModal"
                                                data-course-id="<?= (int) $course['id'] ?>"
                                                data-course-code="<?= htmlspecialchars((string) $course['course_code']) ?>"
                                                data-course-name="<?= htmlspecialchars((string) $course['course_name']) ?>"
                                                data-course-year="<?= htmlspecialchars((string) $course['academic_year']) ?>"
                                                data-course-semester="<?= htmlspecialchars((string) $course['semester']) ?>"
                                                data-course-color="<?= htmlspecialchars(graderapp_normalize_theme_color((string) ($course['theme_color'] ?? '#185b86'))) ?>"
                                            >
                                                แก้ไข
                                            </button>
                                        </li>
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item js-copy-invite-link"
                                                data-invite-url="<?= htmlspecialchars(graderapp_dashboard_invite_url($course)) ?>"
                                            >
                                                คัดลอกลิงก์เชิญ
                                            </button>
                                        </li>
                                        <li>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="toggle_archive_course">
                                                <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                                <button type="submit" class="dropdown-item"><?= $isArchived ? 'เปิดวิชาอีกครั้ง' : 'เก็บวิชา' ?></button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="duplicate_course">
                                                <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                                <button type="submit" class="dropdown-item">คัดลอก</button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!$courses): ?>
                <div class="empty-state mt-4">
                    ยังไม่มีวิชาในระบบตอนนี้
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="courseEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header px-4 pt-4 pb-2">
                        <div>
                            <h2 class="h4 fw-bold mb-1">แก้ไขวิชา</h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-4 pb-2">
                        <input type="hidden" name="action" value="quick_update_course">
                        <input type="hidden" name="course_id" id="editCourseId" value="">
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label fw-bold">รหัสวิชา</label>
                                <input type="text" name="course_code" id="editCourseCode" class="form-control" required>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label fw-bold">ชื่อวิชา</label>
                                <input type="text" name="course_name" id="editCourseName" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ปีการศึกษา</label>
                                <input type="text" name="academic_year" id="editCourseYear" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ภาคเรียน</label>
                                <input type="text" name="semester" id="editCourseSemester" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">สีการ์ด</label>
                                <input type="color" name="theme_color" id="editCourseColor" class="form-control form-control-color" value="#185b86">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer px-4 pb-4">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.js-course-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('editCourseId').value = this.dataset.courseId || '';
                document.getElementById('editCourseCode').value = this.dataset.courseCode || '';
                document.getElementById('editCourseName').value = this.dataset.courseName || '';
                document.getElementById('editCourseYear').value = this.dataset.courseYear || '';
                document.getElementById('editCourseSemester').value = this.dataset.courseSemester || '';
                document.getElementById('editCourseColor').value = this.dataset.courseColor || '#185b86';
            });
        });

        document.querySelectorAll('.js-copy-invite-link').forEach(function (button) {
            button.addEventListener('click', async function () {
                const inviteUrl = this.dataset.inviteUrl || '';
                if (!inviteUrl) {
                    return;
                }

                try {
                    await navigator.clipboard.writeText(inviteUrl);
                    this.textContent = 'คัดลอกแล้ว';
                    setTimeout(() => {
                        this.textContent = 'คัดลอกลิงก์เชิญ';
                    }, 1400);
                } catch (error) {
                    window.prompt('คัดลอกลิงก์เชิญ', inviteUrl);
                }
            });
        });
    </script>
</body>
</html>
