# Office Feedback

โมดูลนี้เป็นแอป Office Feedback แบบ standalone สำหรับประเมินการให้บริการของเจ้าหน้าที่สำนักงานคณะ โดยออกแบบให้รองรับ tablet และการใช้งานแบบแตะเร็ว 1-2 คลิก

## Machine-readable Overview

```json
{
  "module": "office_feedback",
  "purpose": "Tablet-friendly kiosk for office service feedback collection",
  "audience": ["student", "visitor", "teacher", "staff"],
  "entrypoints": [
    {
      "file": "index.php",
      "purpose": "Redirect root access to kiosk.php"
    },
    {
      "file": "kiosk.php",
      "purpose": "Main public feedback kiosk UI"
    },
    {
      "file": "submit_rating.php",
      "purpose": "Persist kiosk rating submissions"
    }
  ],
  "schema_layers": [
    {
      "file": "bootstrap.php",
      "purpose": "Standalone bootstrap with module config, DB, schema ensure, seed defaults, and helper functions"
    },
    {
      "file": "db_schema.sql",
      "purpose": "Reference SQL schema for office feedback tables"
    }
  ],
  "database_prefixes": [
    "officefb_"
  ],
  "core_tables": [
    "officefb_staff",
    "officefb_topics",
    "officefb_devices",
    "officefb_ratings",
    "officefb_settings"
  ],
  "module_ai_settings": {
    "table": "officefb_settings",
    "keys": [
      "officefb_gemini_api_key",
      "officefb_gemini_api_model",
      "officefb_ai_auto_pass_threshold"
    ]
  },
  "major_features": [
    "full-screen kiosk UX",
    "staff selection",
    "4-level rating submission",
    "optional topic and comment capture",
    "auto return after thank-you state",
    "debounce and cooldown handling",
    "default staff/topic seed data"
  ],
  "submodules": [
    {
      "path": "admin/",
      "purpose": "Admin dashboard, CRUD management, and SAR assistant"
    },
    {
      "path": "report/",
      "purpose": "Public annual report for committees and QR sharing"
    }
  ],
  "integration_points": [
    "./admin/",
    "./report/",
    "../aunqa_php_portal/index.php"
  ]
}
```
