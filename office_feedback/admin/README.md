# Office Feedback Admin

โมดูลนี้เป็นหลังบ้านสำหรับจัดการรายชื่อเจ้าหน้าที่ หัวข้อบริการ ดู dashboard สรุปผล และให้ AI ช่วยร่างข้อความรายงานประกอบ SAR

## Machine-readable Overview

```json
{
  "module": "office_feedback/admin",
  "purpose": "Admin dashboard, CRUD management, reporting, and SAR drafting for office feedback",
  "audience": ["admin", "committee", "staff_manager"],
  "entrypoints": [
    {
      "file": "index.php",
      "purpose": "Admin dashboard, annual filters, charts, password/security modal"
    },
    {
      "file": "staff.php",
      "purpose": "CRUD management for office staff shown on kiosk"
    },
    {
      "file": "topics.php",
      "purpose": "CRUD management for service topics shown on kiosk"
    },
    {
      "file": "sar_assistant.php",
      "purpose": "AI-assisted SAR drafting from annual office feedback statistics"
    }
  ],
  "auth": {
    "file": "auth.php",
    "purpose": "Session auth for office feedback admin",
    "username_source": "config.php",
    "password_sources": [
      "officefb_settings password override hash",
      "config.php fallback"
    ]
  },
  "dependencies": [
    "../bootstrap.php",
    "../report/index.php"
  ],
  "major_features": [
    "annual/day/month filters",
    "staff summary and rating volume comparison",
    "chart dashboard",
    "staff CRUD",
    "topic CRUD",
    "default data reset actions",
    "AI settings modal using officefb_settings",
    "SAR draft generation with deep links back to public report"
  ],
  "shared_ai_settings": {
    "table": "officefb_settings",
    "keys": [
      "officefb_gemini_api_key",
      "officefb_gemini_api_model",
      "officefb_ai_auto_pass_threshold"
    ]
  }
}
```
