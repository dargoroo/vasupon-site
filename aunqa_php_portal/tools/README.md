# AUNQA Tools

โฟลเดอร์นี้เก็บไฟล์สำหรับตรวจสอบระบบและวิเคราะห์ปัญหาเชิงเทคนิคของ AUNQA เท่านั้น
ไม่ใช่หน้าใช้งานหลักสำหรับผู้ใช้ทั่วไป และควรจำกัดการเข้าถึงบน server production

## ไฟล์ที่มี

### `db_test.php`
- ใช้ตรวจว่าระบบสามารถเชื่อมฐานข้อมูลได้หรือไม่
- เหมาะสำหรับเช็กปัญหา `config.php`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- ผลลัพธ์หลัก:
  - `DB OK`
  - `DB FAIL: ...`

### `db_host_probe.php`
- ใช้ทดลองเชื่อมต่อฐานข้อมูลผ่าน host หลายค่า
- เหมาะสำหรับกรณีไม่แน่ใจว่า server ควรใช้ `localhost`, `127.0.0.1` หรือ host อื่น
- แสดงผลเป็นราย host:
  - `[OK] ...`
  - `[FAIL] ...`

### `verification_board_probe.php`
- ใช้ตรวจความพร้อมของหน้า `verification_board.php`
- ตรวจทั้ง:
  - การโหลด `bootstrap.php`
  - การเชื่อมต่อฐานข้อมูล
  - การมีอยู่ของตาราง `aunqa_*`
  - คอลัมน์สำคัญที่หน้า board ใช้
  - query หลักของหน้า board
  - statement สำหรับลบรายการประเมิน

## แนวทางการใช้งาน

1. ถ้าหน้า AUNQA เปิดไม่ได้:
   - เริ่มจาก `db_test.php`
2. ถ้าเชื่อมฐานข้อมูลไม่ได้เพราะไม่แน่ใจเรื่อง host:
   - ใช้ `db_host_probe.php`
3. ถ้า `verification_board.php` เปิดได้ไม่ครบ, ลบไม่ได้, หรือ query พัง:
   - ใช้ `verification_board_probe.php`

## หมายเหตุ

- ไฟล์ในโฟลเดอร์นี้เป็นเครื่องมือสำหรับทีมพัฒนาและผู้ดูแลระบบ
- บน production ควรจำกัดการเข้าถึงเสมอ
- ปัจจุบันมี `index.php` และ `.htaccess` สำหรับช่วยป้องกันการเปิดโฟลเดอร์ตรง ๆ แล้ว

## Machine-readable Overview

```json
{
  "module": "aunqa_php_portal/tools",
  "purpose": "Developer and admin troubleshooting tools for AUNQA Portal",
  "audience": ["developer", "system_admin"],
  "production_access": "restricted",
  "tools": [
    {
      "file": "db_test.php",
      "purpose": "Check whether the app can connect to the configured database",
      "depends_on": ["../bootstrap.php", "config.php"],
      "outputs": ["DB OK", "DB FAIL: <message>"],
      "use_when": [
        "database connection is suspected to be broken",
        "config.php credentials may be wrong"
      ]
    },
    {
      "file": "db_host_probe.php",
      "purpose": "Probe multiple database hosts to identify the correct MySQL host",
      "depends_on": ["PDO"],
      "outputs": ["[OK] <host>", "[FAIL] <host> => <message>"],
      "use_when": [
        "DB_HOST is unknown",
        "localhost and 127.0.0.1 behave differently",
        "shared hosting database hostname is unclear"
      ]
    },
    {
      "file": "verification_board_probe.php",
      "purpose": "Validate schema and query readiness for verification_board.php",
      "depends_on": ["../bootstrap.php", "aunqa_* tables"],
      "checks": [
        "bootstrap load",
        "app_pdo connection",
        "required tables",
        "required columns",
        "main board query",
        "delete statement execution"
      ],
      "outputs": ["[OK] ...", "[FAIL] ..."],
      "use_when": [
        "verification_board.php returns errors",
        "board query fails",
        "delete action does not work",
        "schema migration status is uncertain"
      ]
    }
  ],
  "related_guards": [
    {
      "file": "index.php",
      "purpose": "Return 403 for direct folder access"
    },
    {
      "file": ".htaccess",
      "purpose": "Disable directory listing and deny direct access on Apache"
    }
  ]
}
```
