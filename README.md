# CPE RBRU Apps System Map

รีโปนี้เป็นชุดระบบเว็บของสาขาวิศวกรรมคอมพิวเตอร์ RBRU ที่ค่อย ๆ เติบโตจาก AUN-QA ไปสู่ portal กลางและแอปย่อยหลายตัว

เอกสารนี้มีไว้เป็นแผนที่ภาพรวมสำหรับ:
- ทีมพัฒนา
- ผู้ดูแลระบบ
- AI assistant ที่ต้องการเข้าใจโครงสร้างโปรเจกต์ก่อนช่วยต่อยอด

## โครงภาพรวม

- `aunqa_php_portal/`
  - ระบบ AUN-QA หลัก
  - คัดเลือกรายวิชา, ทวนสอบ, Dashboard, PDCA, AI analysis
- `office_feedback/`
  - App หลักของ Office Feedback
  - ครอบทั้ง kiosk, admin, และรายงานสาธารณะ
- `grader_app/`
  - App scaffold สำหรับระบบ grader
  - ออกแบบให้แยก web/db ออกจาก worker/docker runner
- `grader_worker/`
  - Worker scaffold สำหรับ claim/report grading jobs
  - ตั้งใจให้ย้ายไป host ไหนก็ได้ในอนาคต
- `cpe_portal/`
  - Portal กลางสำหรับรวม app หลายตัวของสาขา
  - เป็นตัวอย่าง app ถัดไปที่ใช้ pattern standalone เต็มรูปแบบ
- `shared/`
  - โค้ดกลางที่ใช้ร่วมกัน เช่น schema helpers
  - แนวมาตรฐานสำหรับ app ใหม่อยู่ใน `shared/APP_MODULE_PATTERN.md`
- `docker/`, `docker-compose.yml`
  - local development stack

## Machine-readable Overview

```json
{
  "repository": "vasupon-site",
  "system_name": "CPE RBRU Apps",
  "purpose": "Department-level web applications for AUN-QA, office feedback, and future expandable academic tools",
  "shared_layers": [
    {
      "path": "shared/config_helpers.php",
      "purpose": "Shared root-config and PDO helpers for standalone app bootstraps"
    },
    {
      "path": "shared/schema_helpers.php",
      "purpose": "Shared schema ensure/check helpers for app modules"
    },
    {
      "path": "config.php",
      "purpose": "Environment-specific runtime configuration; not intended for Git"
    },
    {
      "path": "config.example.php",
      "purpose": "Template for required configuration keys"
    }
  ],
  "modules": [
    {
      "name": "aunqa_php_portal",
      "type": "core_app",
      "purpose": "AUN-QA verification, tracking, annual dashboard, and AI-assisted analysis",
      "entrypoints": [
        "aunqa_php_portal/index.php",
        "aunqa_php_portal/verification.php",
        "aunqa_php_portal/verification_board.php",
        "aunqa_php_portal/verification_dashboard.php"
      ],
      "schema_files": [
        "aunqa_php_portal/bootstrap.php",
        "aunqa_php_portal/schema.php",
        "aunqa_php_portal/migrations.php",
        "aunqa_php_portal/seeds.php",
        "aunqa_php_portal/db_schema.sql"
      ],
      "shared_dependencies": [
        "shared/config_helpers.php",
        "shared/schema_helpers.php"
      ],
      "debug_tools": [
        "aunqa_php_portal/tools/db_test.php",
        "aunqa_php_portal/tools/db_host_probe.php",
        "aunqa_php_portal/tools/verification_board_probe.php"
      ],
      "database_prefixes": ["aunqa_"]
    },
    {
      "name": "office_feedback",
      "type": "compound_app",
      "purpose": "Office service feedback app containing kiosk, admin, and public report modules",
      "entrypoints": [
        "office_feedback/index.php",
        "office_feedback/kiosk.php",
        "office_feedback/submit_rating.php",
        "office_feedback/admin/index.php",
        "office_feedback/report/index.php"
      ],
      "schema_files": [
        "office_feedback/bootstrap.php",
        "office_feedback/db_schema.sql"
      ],
      "shared_dependencies": [
        "shared/config_helpers.php",
        "shared/schema_helpers.php"
      ],
      "database_prefixes": ["officefb_"],
      "submodules": [
        {
          "name": "kiosk",
          "entrypoints": [
            "office_feedback/index.php",
            "office_feedback/kiosk.php",
            "office_feedback/submit_rating.php"
          ]
        },
        {
          "name": "admin",
          "entrypoints": [
            "office_feedback/admin/index.php",
            "office_feedback/admin/staff.php",
            "office_feedback/admin/topics.php",
            "office_feedback/admin/sar_assistant.php"
          ]
        },
        {
          "name": "report",
          "entrypoints": [
            "office_feedback/report/index.php"
          ]
        }
      ]
    },
    {
      "name": "cpe_portal",
      "type": "launcher_app",
      "purpose": "Central app launcher and metadata registry for CPE RBRU apps",
      "entrypoints": [
        "cpe_portal/index.php",
        "cpe_portal/admin/index.php"
      ],
      "schema_files": [
        "cpe_portal/bootstrap.php",
        "cpe_portal/db_schema.sql"
      ],
      "shared_dependencies": [
        "shared/config_helpers.php",
        "shared/schema_helpers.php"
      ],
      "database_prefixes": ["cpeportal_"]
    },
    {
      "name": "grader_app",
      "type": "standalone_app",
      "purpose": "Programming exercise grader scaffold with database-backed queue and external worker support",
      "entrypoints": [
        "grader_app/index.php",
        "grader_app/admin/index.php"
      ],
      "schema_files": [
        "grader_app/bootstrap.php",
        "grader_app/schema.php",
        "grader_app/migrations.php",
        "grader_app/seeds.php",
        "grader_app/db_schema.sql"
      ],
      "shared_dependencies": [
        "shared/config_helpers.php",
        "shared/schema_helpers.php"
      ],
      "database_prefixes": ["grader_"]
    },
    {
      "name": "grader_worker",
      "type": "worker_component",
      "purpose": "External stateless worker for polling, claiming, and reporting grading jobs",
      "entrypoints": [
        "grader_worker/worker.py"
      ],
      "shared_dependencies": [],
      "database_prefixes": []
    }
  ],
  "aunqa_ai_configuration": {
    "table": "aunqa_settings",
    "keys": [
      "gemini_api_key",
      "gemini_api_model",
      "ai_auto_pass_threshold"
    ],
    "used_by": [
      "aunqa_php_portal/verification_board.php",
      "aunqa_php_portal/ajax_ai_analyzer.php"
    ]
  },
  "office_feedback_ai_configuration": {
    "table": "officefb_settings",
    "keys": [
      "officefb_gemini_api_key",
      "officefb_gemini_api_model",
      "officefb_ai_auto_pass_threshold"
    ],
    "used_by": [
      "office_feedback/admin/sar_assistant.php"
    ]
  },
  "grader_configuration": {
    "table": "grader_settings",
    "keys": [
      "grader_title",
      "grader_tagline",
      "grader_worker_endpoint",
      "grader_runner_target_default"
    ],
    "used_by": [
      "grader_app/index.php",
      "grader_app/admin/index.php"
    ]
  },
  "development_stack": {
    "docker_compose": "docker-compose.yml",
    "typical_local_base_url": "http://localhost:8080",
    "notes": [
      "Local Docker DB host often differs from production",
      "Modules should prefer PHP-based schema ensure over manual phpMyAdmin steps"
    ]
  },
  "expansion_direction": {
    "planned_portal": "CPE RBRU Apps portal / app launcher",
    "future_modules": [
      "grader app with external worker execution",
      "project management for students and advisors",
      "advisor tracking",
      "department-wide reports and committee dashboards"
    ],
    "recommended_pattern": [
      "separate module folders",
      "module-specific table prefixes",
      "shared schema helper usage",
      "README machine-readable overviews per module"
    ]
  }
}
```

## แนวทางต่อยอดที่ควรรักษา

- แยกแต่ละระบบเป็นโฟลเดอร์ของตัวเอง
- ใช้ prefix ตารางแยกกันชัดเจน
- ให้แต่ละระบบมี PHP schema installer/ensure ของตัวเอง
- อย่าฝากการ deploy ไว้กับ phpMyAdmin เป็นวิธีหลัก
- เพิ่ม README ระดับโมดูลทุกครั้งเมื่อมี app ใหม่
- ใช้มาตรฐานใน `shared/APP_MODULE_PATTERN.md` เมื่อเริ่ม app ใหม่
