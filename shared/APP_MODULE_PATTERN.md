# CPE RBRU App Module Pattern

เอกสารนี้เป็นมาตรฐานกลางสำหรับสร้าง app ใหม่ในรีโปนี้ โดยตั้งใจให้แต่ละ app:
- deploy ได้ด้วยตัวเอง
- มี schema ของตัวเอง
- ไม่ต้องพึ่ง phpMyAdmin เป็นวิธีหลัก
- ลดการผูกกันข้ามโมดูลให้เหลือน้อยที่สุด

## โครงสร้างที่แนะนำ

```text
your_app/
  README.md
  bootstrap.php
  db_schema.sql
  index.php
  admin/
    index.php
  report/
    index.php
  tools/
    ...
```

## หลักการสำคัญ

1. `bootstrap.php` ของแต่ละ app ต้อง standalone
- โหลด `config.php` เอง
- ต่อฐานข้อมูลเอง
- เรียก shared helper เฉพาะของกลางจริง ๆ เช่น `shared/schema_helpers.php`
- ห้าม `require` bootstrap ของ app อื่น

2. แต่ละ app ต้องมี table prefix ของตัวเอง
- เช่น `aunqa_`
- เช่น `officefb_`
- app ใหม่ควรตั้ง prefix ใหม่ทันที

3. settings ของแต่ละ app ต้องเก็บแยก
- เช่น `officefb_settings`
- ถ้าต้องใช้ AI ให้เก็บ key/model/threshold ใน settings ของ app นั้น
- ไม่ควรแชร์ settings table ข้าม app โดยไม่จำเป็น

4. schema ต้องสร้างผ่าน PHP ได้
- มี `*_ensure_schema()`
- มี `*_bootstrap_state()`
- optional: มี `db_schema.sql` เป็น reference หรือ backup

5. README ของแต่ละ app ต้องมี JSON overview
- เพื่อให้คนและ AI เข้าใจโครงสร้างได้เร็ว

6. ใช้ shared helper กลางเท่าที่จำเป็น
- `shared/config_helpers.php` สำหรับโหลด `config.php` และสร้าง PDO จาก root config
- `shared/schema_helpers.php` สำหรับ schema ensure/check
- app ควรมี wrapper ของตัวเอง เช่น `yourapp_config()` หรือ `yourapp_pdo()` เพื่อให้ API ของโมดูลยังอ่านง่าย

## Checklist สำหรับ app ใหม่

- มี `bootstrap.php`
- มี `README.md`
- มี table prefix เฉพาะ
- มี `*_ensure_schema()`
- มี `*_bootstrap_state()`
- มี settings table ของตัวเองถ้าต้องเก็บ config runtime
- ใช้ path helper ของตัวเองถ้ามี submodule เช่น `admin/`, `report/`, `api/`
- มีหน้าหลังบ้านแยกจากหน้าสาธารณะชัดเจน
- ใช้ `shared/config_helpers.php` และ `shared/schema_helpers.php` เป็นฐานก่อนเขียน helper ใหม่

## ตัวอย่าง pattern helper

```php
function yourapp_load_root_config(): void {}
function yourapp_config(string $key, $default = null) {}
function yourapp_required_config(string $key) {}
function yourapp_pdo(): PDO {}
function yourapp_ensure_schema(PDO $pdo): void {}
function yourapp_bootstrap_state(): array {}
```

## Machine-readable Overview

```json
{
  "document": "shared/APP_MODULE_PATTERN.md",
  "purpose": "Standard app architecture pattern for standalone modules in CPE RBRU Apps",
  "requirements": [
    "standalone bootstrap per app",
    "module-specific database prefix",
    "module-specific settings storage",
    "php-based schema ensure",
    "README with machine-readable overview",
    "shared config/schema helper usage where appropriate"
  ],
  "recommended_structure": [
    "README.md",
    "bootstrap.php",
    "db_schema.sql",
    "index.php",
    "admin/",
    "report/",
    "tools/"
  ],
  "anti_patterns": [
    "requiring another app bootstrap directly",
    "sharing settings table across unrelated apps",
    "depending on phpMyAdmin as the main install flow"
  ]
}
```
