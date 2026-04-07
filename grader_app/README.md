# grader_app

Grader app scaffold สำหรับระบบตรวจแบบฝึกหัดเขียนโปรแกรมของสาขา โดยออกแบบให้:
- deploy ได้แบบ standalone
- สร้าง schema ผ่าน PHP ตั้งแต่เปิดระบบครั้งแรก
- แยก `web/api/db` ออกจาก `worker/docker runner`
- ย้ายเครื่องรันงานจาก `rbruai2` ไป host อื่นในอนาคตได้ง่าย

## โครงสร้าง

```text
grader_app/
  README.md
  bootstrap.php
  schema.php
  migrations.php
  seeds.php
  db_schema.sql
  index.php
  api/
    submit.php
    status.php
    worker_claim.php
    worker_report.php
    worker_heartbeat.php
  admin/
    auth.php
    index.php
```

## จุดเริ่มใช้งาน

- หน้า public: `/grader_app/index.php`
- หลังบ้าน: `/grader_app/admin/index.php`

## แนวคิดหลัก

1. `grader_app` เก็บข้อมูลหลักของ:
- course
- module
- problem
- test case
- submission
- grading job
- worker registry

2. worker ภายนอกจะอ่าน/claim งานจาก `grader_jobs`

3. ระบบสร้างตาราง `grader_*` อัตโนมัติเมื่อเชื่อม DB ได้

## Config ที่ใช้

- `GRADERAPP_ADMIN_USERNAME`
- `GRADERAPP_ADMIN_PASSWORD`
- `GRADERAPP_PATH_ROOT`
- `GRADERAPP_PATH_ADMIN`
- `GRADERAPP_PATH_API`
- `GRADERAPP_WORKER_ENDPOINT`
- `GRADERAPP_RUNNER_TARGET_DEFAULT`
- `GRADERAPP_WORKER_SHARED_TOKEN`
- `GRADERAPP_DEFAULT_WORKER_NAME`
- `GRADERAPP_DEFAULT_WORKER_HOST`

## Machine-readable Overview

```json
{
  "module": "grader_app",
  "purpose": "Programming exercise grader app with PHP-based schema installer and external worker support",
  "entrypoints": {
    "public": "/grader_app/index.php",
    "admin": "/grader_app/admin/index.php",
    "api": [
      "/grader_app/api/submit.php",
      "/grader_app/api/status.php",
      "/grader_app/api/worker_claim.php",
      "/grader_app/api/worker_report.php",
      "/grader_app/api/worker_heartbeat.php"
    ]
  },
  "bootstrap": "grader_app/bootstrap.php",
  "schema_files": [
    "grader_app/schema.php",
    "grader_app/migrations.php",
    "grader_app/seeds.php"
  ],
  "table_prefix": "grader_",
  "settings_table": "grader_settings",
  "core_tables": [
    "grader_users",
    "grader_courses",
    "grader_course_enrollments",
    "grader_modules",
    "grader_problems",
    "grader_test_cases",
    "grader_submissions",
    "grader_submission_results",
    "grader_jobs",
    "grader_workers",
    "grader_problem_assets",
    "grader_settings"
  ],
  "runtime_model": {
    "web": "vasupon-p",
    "db": "vasupon-p",
    "worker": "rbruai2 or future host",
    "queue": "database-backed"
  },
  "worker_auth": {
    "method": "shared token",
    "config_key": "GRADERAPP_WORKER_SHARED_TOKEN"
  },
  "dependencies": [
    "shared/config_helpers.php",
    "shared/schema_helpers.php"
  ],
  "notes": [
    "no phpMyAdmin import required for initial install",
    "schema is created by PHP ensure logic",
    "worker host can change without redesigning schema"
  ]
}
```
