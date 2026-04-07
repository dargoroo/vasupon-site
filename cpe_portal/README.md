# CPE Portal

โมดูลนี้เป็น portal กลางของสาขา สำหรับรวม app หลายตัวให้อยู่ใน launcher เดียว และใช้เป็นตัวอย่างมาตรฐานของ app ใหม่ในระบบ

## Machine-readable Overview

```json
{
  "module": "cpe_portal",
  "purpose": "Standalone portal launcher for CPE RBRU Apps with category/app metadata and admin scaffold",
  "entrypoints": [
    {
      "file": "index.php",
      "purpose": "Public app launcher page"
    },
    {
      "file": "admin/index.php",
      "purpose": "Admin scaffold for portal management"
    }
  ],
  "schema_layers": [
    {
      "file": "bootstrap.php",
      "purpose": "Standalone bootstrap, config wrappers, path helpers, schema ensure, and default seed data"
    },
    {
      "file": "db_schema.sql",
      "purpose": "Reference SQL schema for cpe_portal tables"
    }
  ],
  "database_prefixes": [
    "cpeportal_"
  ],
  "core_tables": [
    "cpeportal_categories",
    "cpeportal_apps",
    "cpeportal_settings"
  ],
  "shared_dependencies": [
    "../shared/config_helpers.php",
    "../shared/schema_helpers.php"
  ],
  "major_features": [
    "category-based app launcher",
    "featured apps section",
    "portal admin scaffold",
    "default seed data for current apps",
    "standalone module bootstrap pattern"
  ]
}
```
