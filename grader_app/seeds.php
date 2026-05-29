<?php

function graderapp_seed_default_settings(PDO $pdo): void
{
    if (!graderapp_table_exists($pdo, 'grader_settings')) {
        return;
    }

    $defaults = [
        'grader_title' => 'CPE Grader',
        'grader_tagline' => 'ระบบตรวจแบบฝึกหัดเขียนโปรแกรมสำหรับนักศึกษาและอาจารย์',
        'grader_default_language' => 'python',
        'grader_demo_enabled' => '1',
        'grader_queue_poll_seconds' => '5',
        'grader_stale_job_seconds' => '90',
        'grader_runner_target_default' => graderapp_config('GRADERAPP_RUNNER_TARGET_DEFAULT', 'rbruai2'),
        'grader_worker_endpoint' => graderapp_config('GRADERAPP_WORKER_ENDPOINT', 'https://rbruai2.rbru.ac.th'),
    ];

    foreach ($defaults as $key => $value) {
        graderapp_setting_set($pdo, $key, (string) $value);
    }
}

function graderapp_seed_default_worker(PDO $pdo): void
{
    if (!graderapp_table_exists($pdo, 'grader_workers')) {
        return;
    }

    $workerName = (string) graderapp_config('GRADERAPP_DEFAULT_WORKER_NAME', 'rbruai2-worker');
    $workerHost = (string) graderapp_config('GRADERAPP_DEFAULT_WORKER_HOST', 'rbruai2.rbru.ac.th');

    $stmt = $pdo->prepare("
        INSERT INTO grader_workers (worker_name, worker_host, is_active, capabilities_json)
        VALUES (:worker_name, :worker_host, 1, :capabilities_json)
        ON DUPLICATE KEY UPDATE
            worker_host = VALUES(worker_host),
            capabilities_json = VALUES(capabilities_json)
    ");

    $stmt->execute([
        ':worker_name' => $workerName,
        ':worker_host' => $workerHost,
        ':capabilities_json' => json_encode([
            'languages' => ['python', 'html', 'web'],
            'supportsDocker' => true,
            'supportsStaticWebChecks' => true,
            'supportsQueuePolling' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function graderapp_seed_runtime_profiles(PDO $pdo): void
{
    if (
        !graderapp_table_exists($pdo, 'grader_runtime_profiles')
        || !graderapp_table_exists($pdo, 'grader_runtime_profile_packages')
    ) {
        return;
    }

    $profiles = [
        'python-basic' => [
            'display_name' => 'Python Basic',
            'language' => 'python',
            'description' => 'Python สำหรับโจทย์พื้นฐาน ใช้ standard library เป็นหลัก เหมาะกับ input/output, string, list, dict, algorithm และโจทย์ทั่วไป',
            'status' => 'active',
            'runner_target' => 'default',
            'sort_order' => 10,
            'packages' => [
                ['python', 'worker image', 'runtime', 'Python runtime ใน Docker worker'],
                ['standard-library', 'included', 'stdlib', 'math, json, collections, heapq, itertools และไลบรารีมาตรฐานของ Python'],
            ],
        ],
        'python-data' => [
            'display_name' => 'Python Data',
            'language' => 'python',
            'description' => 'Profile สำหรับโจทย์ที่ต้องใช้ package ด้านข้อมูล เช่น numpy หรือ pandas ต้องเปิดใช้หลัง admin build worker image ที่รองรับแล้ว',
            'status' => 'disabled',
            'runner_target' => 'default',
            'sort_order' => 20,
            'packages' => [
                ['numpy', 'pending approval', 'pip', 'เตรียมไว้สำหรับโจทย์ array/numeric หลัง admin อนุมัติและ build image'],
                ['pandas', 'pending approval', 'pip', 'เตรียมไว้สำหรับโจทย์ data frame หลัง admin อนุมัติและ build image'],
            ],
        ],
        'web-static' => [
            'display_name' => 'HTML/CSS/Canvas Static Checks',
            'language' => 'html',
            'description' => 'Profile สำหรับแบบฝึกหัด HTML, CSS และ Canvas ที่ตรวจโครงสร้าง tag, selector, style snippet และ JavaScript/canvas API แบบ static เพื่อลดภาระตรวจงานเบื้องต้น',
            'status' => 'active',
            'runner_target' => 'default',
            'sort_order' => 30,
            'packages' => [
                ['html.parser', 'included', 'stdlib', 'ตรวจ tag, attribute, id และ class ด้วย Python standard library'],
                ['static-check-json', 'included', 'worker', 'อ่าน test case เป็น JSON checks เช่น selector_exists, css_contains, js_contains, regex'],
            ],
        ],
    ];

    $profileStmt = $pdo->prepare("
        INSERT INTO grader_runtime_profiles
            (profile_key, display_name, language, description, status, runner_target, sort_order)
        VALUES
            (:profile_key, :display_name, :language, :description, :status, :runner_target, :sort_order)
        ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            language = VALUES(language),
            description = VALUES(description),
            status = VALUES(status),
            runner_target = VALUES(runner_target),
            sort_order = VALUES(sort_order)
    ");
    $packageStmt = $pdo->prepare("
        INSERT INTO grader_runtime_profile_packages
            (runtime_profile_id, package_name, package_version, package_manager, notes, sort_order)
        VALUES
            (:runtime_profile_id, :package_name, :package_version, :package_manager, :notes, :sort_order)
        ON DUPLICATE KEY UPDATE
            package_version = VALUES(package_version),
            package_manager = VALUES(package_manager),
            notes = VALUES(notes),
            sort_order = VALUES(sort_order)
    ");

    foreach ($profiles as $key => $profile) {
        $profileStmt->execute([
            ':profile_key' => $key,
            ':display_name' => $profile['display_name'],
            ':language' => $profile['language'],
            ':description' => $profile['description'],
            ':status' => $profile['status'],
            ':runner_target' => $profile['runner_target'],
            ':sort_order' => $profile['sort_order'],
        ]);

        $idStmt = $pdo->prepare("SELECT id FROM grader_runtime_profiles WHERE profile_key = :profile_key LIMIT 1");
        $idStmt->execute([':profile_key' => $key]);
        $profileId = (int) $idStmt->fetchColumn();
        if ($profileId <= 0) {
            continue;
        }

        foreach ($profile['packages'] as $index => $package) {
            $packageStmt->execute([
                ':runtime_profile_id' => $profileId,
                ':package_name' => $package[0],
                ':package_version' => $package[1],
                ':package_manager' => $package[2],
                ':notes' => $package[3],
                ':sort_order' => $index + 1,
            ]);
        }
    }
}

function graderapp_seed_demo_data(PDO $pdo): void
{
    if (
        !graderapp_table_exists($pdo, 'grader_users')
        || !graderapp_table_exists($pdo, 'grader_courses')
        || !graderapp_table_exists($pdo, 'grader_modules')
        || !graderapp_table_exists($pdo, 'grader_problems')
        || !graderapp_table_exists($pdo, 'grader_test_cases')
    ) {
        return;
    }

    $courseCount = (int) $pdo->query("SELECT COUNT(*) FROM grader_courses")->fetchColumn();
    if ($courseCount > 0) {
        return;
    }

    $userStmt = $pdo->prepare("
        INSERT INTO grader_users (email, full_name, role, student_code, is_active)
        VALUES (:email, :full_name, :role, :student_code, 1)
    ");
    $userStmt->execute([
        ':email' => 'teacher@example.com',
        ':full_name' => 'อาจารย์ตัวอย่าง',
        ':role' => 'teacher',
        ':student_code' => '',
    ]);
    $teacherId = (int) $pdo->lastInsertId();

    $userStmt->execute([
        ':email' => 'student@example.com',
        ':full_name' => 'นักศึกษาตัวอย่าง',
        ':role' => 'student',
        ':student_code' => '67000001',
    ]);
    $studentId = (int) $pdo->lastInsertId();

    $courseStmt = $pdo->prepare("
        INSERT INTO grader_courses (course_code, course_name, academic_year, semester, owner_user_id, join_code, status)
        VALUES (:course_code, :course_name, :academic_year, :semester, :owner_user_id, :join_code, :status)
    ");
    $courseStmt->execute([
        ':course_code' => 'CPE101',
        ':course_name' => 'Programming Fundamentals',
        ':academic_year' => (string) ((int) date('Y') + 543),
        ':semester' => '1',
        ':owner_user_id' => $teacherId,
        ':join_code' => 'CPE101-DEMO',
        ':status' => 'published',
    ]);
    $courseId = (int) $pdo->lastInsertId();

    $enrollStmt = $pdo->prepare("
        INSERT INTO grader_course_enrollments (course_id, user_id, role_in_course)
        VALUES (:course_id, :user_id, :role_in_course)
    ");
    $enrollStmt->execute([
        ':course_id' => $courseId,
        ':user_id' => $studentId,
        ':role_in_course' => 'student',
    ]);

    $moduleStmt = $pdo->prepare("
        INSERT INTO grader_modules (course_id, title, description, announcement_md, sort_order, is_active)
        VALUES (:course_id, :title, :description, :announcement_md, :sort_order, 1)
    ");
    $moduleStmt->execute([
        ':course_id' => $courseId,
        ':title' => 'บทที่ 1 พื้นฐานการรับข้อมูล',
        ':description' => 'โจทย์ตัวอย่างสำหรับทดสอบ flow การสร้าง course/module/problem ตั้งแต่เริ่มระบบ',
        ':announcement_md' => 'พื้นที่นี้ใช้สำหรับประกาศข่าวสาร วาง session และพาผู้เรียนเข้าโจทย์ของแต่ละบท',
        ':sort_order' => 1,
    ]);
    $moduleId = (int) $pdo->lastInsertId();

    $problemStmt = $pdo->prepare("
        INSERT INTO grader_problems
            (module_id, title, slug, description_md, starter_code, language, time_limit_sec, memory_limit_mb, max_score, visibility, sort_order, created_by)
        VALUES
            (:module_id, :title, :slug, :description_md, :starter_code, :language, :time_limit_sec, :memory_limit_mb, :max_score, :visibility, :sort_order, :created_by)
    ");
    $problemStmt->execute([
        ':module_id' => $moduleId,
        ':title' => 'ผลบวกของจำนวนเต็มสองจำนวน',
        ':slug' => 'sum-two-integers-demo',
        ':description_md' => "รับจำนวนเต็ม 2 จำนวนจาก stdin แล้วแสดงผลรวมออกทาง stdout",
        ':starter_code' => "a = int(input())\nb = int(input())\nprint(a + b)\n",
        ':language' => 'python',
        ':time_limit_sec' => 2.00,
        ':memory_limit_mb' => 128,
        ':max_score' => 100,
        ':visibility' => 'published',
        ':sort_order' => 1,
        ':created_by' => $teacherId,
    ]);
    $problemId = (int) $pdo->lastInsertId();

    $testCaseStmt = $pdo->prepare("
        INSERT INTO grader_test_cases (problem_id, case_type, stdin_text, expected_stdout, score_weight, sort_order)
        VALUES (:problem_id, :case_type, :stdin_text, :expected_stdout, :score_weight, :sort_order)
    ");

    $testCases = [
        ['sample', "1\n2\n", "3\n", 20, 1],
        ['sample', "10\n15\n", "25\n", 20, 2],
        ['hidden', "50\n70\n", "120\n", 30, 3],
        ['hidden', "-1\n5\n", "4\n", 30, 4],
    ];

    foreach ($testCases as [$caseType, $stdin, $expected, $weight, $sort]) {
        $testCaseStmt->execute([
            ':problem_id' => $problemId,
            ':case_type' => $caseType,
            ':stdin_text' => $stdin,
            ':expected_stdout' => $expected,
            ':score_weight' => $weight,
            ':sort_order' => $sort,
        ]);
    }
}
