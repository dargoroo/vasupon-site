# rbruai2 rollout notes

เอกสารนี้ใช้เป็นคู่มือขึ้น worker ใหม่บน `rbruai2.rbru.ac.th` หรือ host ตัวถัดไป โดยตั้งใจให้ไม่ต้องพึ่ง Laravel grader ตัวเก่าอีก

## เป้าหมาย

- web + api + database อยู่ฝั่ง `vasupon-p.rbru.ac.th`
- worker + docker sandbox อยู่ฝั่ง `rbruai2.rbru.ac.th`
- worker เป็น stateless process ย้ายเครื่องได้ง่าย

## สิ่งที่ควรมีบน worker host

- Ubuntu
- `python3`
- `docker`
- สิทธิ์รัน docker สำหรับ user ที่ใช้รัน service
- outbound network ไปหา `https://vasupon-p.rbru.ac.th/grader_app`

## โฟลเดอร์ที่แนะนำ

```text
/opt/cpe_rbru/grader_worker
```

## ขั้นตอน rollout

1. copy โฟลเดอร์ `grader_worker/` ไปที่ host
2. คัดลอก `.env.example` เป็น `.env`
3. ใส่ค่าจริง เช่น
   - `GRADERAPP_BASE_URL`
   - `GRADERAPP_WORKER_SHARED_TOKEN`
   - `GRADERAPP_WORKER_NAME`
   - `GRADERAPP_WORKER_HOST`
4. ทดสอบรันด้วยมือ
   - `python3 worker.py`
5. ติดตั้ง systemd service จาก `deploy/systemd/grader-worker.service`
6. ถ้าจะมีหน้า monitor หรือ app อื่นบนเครื่องเดียวกัน ค่อยใช้ตัวอย่าง nginx ใน `deploy/nginx/reverse-proxy-example.conf`

## การลบระบบเก่า

มีตัวอย่างสคริปต์ที่:
- หยุด service เก่า
- ลบ container เก่า
- ย้ายโฟลเดอร์ Laravel grader เก่าไปเป็น backup

ไฟล์:

- `deploy/rbruai2-cleanup-example.sh`

ก่อนรันต้องแก้ค่า:
- `OLD_LARAVEL_ROOT`
- `OLD_SERVICE_NAME`
- `OLD_CONTAINER_MATCH`

## หมายเหตุเรื่อง reverse proxy

worker ตัวนี้ไม่จำเป็นต้องมี nginx เพื่อทำงาน เพราะมัน polling API ออกไปเอง

ควรใช้ nginx บน `rbruai2` เมื่อ:
- อยากรวมหลาย app บนเครื่องเดียว
- อยากมีหน้า monitor/health
- อยากเก็บ path เช่น `/grader-admin/`, `/worker-monitor/`, `/apps/...`

## การย้ายเครื่องในอนาคต

ถ้าย้ายจาก `rbruai2` ไป host ใหม่:
- ย้ายโฟลเดอร์ `grader_worker`
- ปรับ `.env`
- start service ใหม่
- อัปเดต `GRADERAPP_DEFAULT_WORKER_HOST` ใน `config.php` ถ้าต้องการ

web และ DB ไม่ต้องรื้อใหม่
