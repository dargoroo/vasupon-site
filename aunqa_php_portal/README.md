# AUNQA Portal

โมดูลนี้เป็นระบบหลักสำหรับงาน AUN-QA ของสาขา ใช้สำหรับคัดเลือกรายวิชา ทวนสอบผลสัมฤทธิ์ วิเคราะห์เอกสารด้วย AI และสรุปผลรอบประเมิน

## Machine-readable Overview

```json
{
  "module": "aunqa_php_portal",
  "purpose": "AUN-QA course verification, tracking, dashboard, and AI-assisted analysis",
  "audience": ["teacher", "committee", "admin"],
  "entrypoints": [
    {
      "file": "index.php",
      "purpose": "AUNQA Hub landing page"
    },
    {
      "file": "verification.php",
      "purpose": "Course selection and verification intake"
    },
    {
      "file": "verification_board.php",
      "purpose": "Verification tracking board, AI settings, and per-course review"
    },
    {
      "file": "verification_dashboard.php",
      "purpose": "Annual summary dashboard and carry-forward PDCA workflow"
    }
  ],
  "ajax_endpoints": [
    {
      "file": "ajax_ai_analyzer.php",
      "purpose": "AI analysis, settings reads, and evaluation persistence"
    },
    {
      "file": "ajax_pdca_tracking.php",
      "purpose": "PDCA issue create/update/delete"
    },
    {
      "file": "ajax_save_clo_feedback.php",
      "purpose": "Save committee feedback for CLO evaluations"
    },
    {
      "file": "api_receive.php",
      "purpose": "Receive upstream data payloads into AUNQA tables"
    }
  ],
  "schema_layers": [
    {
      "file": "bootstrap.php",
      "purpose": "Config loading, PDO factory, shared schema helper wrappers"
    },
    {
      "file": "schema.php",
      "purpose": "Create-table statements and bootstrap state for AUNQA"
    },
    {
      "file": "migrations.php",
      "purpose": "Legacy ALTER statements for backward-compatible schema upgrades"
    },
    {
      "file": "seeds.php",
      "purpose": "Default system settings seed data"
    },
    {
      "file": "db_schema.sql",
      "purpose": "Reference SQL schema for manual import or inspection"
    }
  ],
  "shared_dependencies": [
    "../shared/schema_helpers.php",
    "../config.php"
  ],
  "database_prefixes": [
    "aunqa_"
  ],
  "ai_settings_table": {
    "table": "aunqa_settings",
    "keys": [
      "gemini_api_key",
      "gemini_api_model",
      "ai_auto_pass_threshold"
    ]
  },
  "major_features": [
    "course verification workflow",
    "CLO/PLO/activity review",
    "AI-assisted document analysis",
    "PDCA issue tracking",
    "annual dashboard reporting",
    "carry-forward seeding for next round"
  ],
  "diagnostics": {
    "tools_directory": "tools/",
    "purpose": "Restricted developer/admin probes and DB troubleshooting helpers"
  }
}
```
