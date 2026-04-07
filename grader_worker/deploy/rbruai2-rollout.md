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

ถ้าใช้ home directory ของ user ก็สามารถใช้แนวนี้ได้เช่นกัน:

```text
/home/elephant/cpe_rbru/vasupon-site/grader_worker
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

## Capacity Plan สำหรับ rbruai2

เครื่อง `rbruai2` ที่มี RAM 4GB / 4 vCPU ควรเริ่มที่:

- `1 worker service`
  - เบาและเสถียรที่สุด
  - เหมาะกับ production รอบแรก

เมื่อเริ่มมีโหลดมากขึ้น:

- ขยับเป็น `2 workers`
  - แนะนำให้ใช้ template service `deploy/systemd/grader-worker@.service`
  - เช่น `grader-worker@1.service` และ `grader-worker@2.service`

ยังไม่แนะนำ:

- `3 workers` ขึ้นไปบนเครื่องนี้
  - จะเริ่มเสี่ยง CPU contention และ swap

## Template Service สำหรับอนาคต

ใน repo มีไฟล์:

- `deploy/systemd/grader-worker@.service`

ตัวอย่างการเปิดใช้เมื่อพร้อม scale:

```bash
sudo cp deploy/systemd/grader-worker@.service /etc/systemd/system/grader-worker@.service
sudo systemctl daemon-reload
sudo systemctl enable --now grader-worker@1
sudo systemctl enable --now grader-worker@2
```

ข้อดีคือ:

- restart ทีละ worker ได้
- scale จาก 1 เป็น 2 ง่าย
- worker name จะกลายเป็น `rbruai2-worker-1`, `rbruai2-worker-2` โดยอัตโนมัติ

ถ้ายังไม่ต้องการ scale ตอนนี้ ให้ใช้ `grader-worker.service` เดิมต่อไปได้เลย

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
