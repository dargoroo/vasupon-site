# grader_worker

โฟลเดอร์นี้เป็น worker สำหรับระบบ grader ที่แยกจากเว็บหลัก ใช้ดึง job จาก `grader_app` แล้วส่งผลกลับผ่าน API

## แนวคิด

- `grader_app` อยู่ฝั่ง web + database
- `grader_worker` อยู่ฝั่งเครื่องที่รันงานหนัก เช่น `rbruai2`
- worker นี้ stateless และย้ายไป host ใหม่ได้ง่าย

## ไฟล์สำคัญ

- `worker.py`
  - polling + heartbeat + claim + docker sandbox + report
- `.env.example`
  - ตัวอย่างค่าที่ต้องตั้งบนเครื่อง worker
- `deploy/systemd/grader-worker.service`
  - ตัวอย่าง service สำหรับ Ubuntu
- `deploy/systemd/grader-worker@.service`
  - template service สำหรับ scale เป็นหลาย workers ในอนาคต
- `deploy/nginx/reverse-proxy-example.conf`
  - ตัวอย่าง reverse proxy ถ้าจะรวมหลาย app บน `rbruai2`
- `deploy/rbruai2-cleanup-example.sh`
  - ตัวอย่าง cleanup ระบบ Laravel grader เก่าก่อน rollout ตัวใหม่
- `deploy/rbruai2-rollout.md`
  - checklist ขึ้นระบบบน worker host

## วิธีใช้

1. สร้าง environment บน worker host
2. ตั้งค่า env ให้ตรงกับ `grader_app`
3. ให้เครื่องนั้นมี `python3` และ `docker`
4. ทดสอบ `docker run --rm python:3.11-alpine python3 -V`
5. รัน `python3 worker.py`
6. ถ้าทำงานปกติค่อยผูกกับ systemd

## หมายเหตุ

ตอนนี้ `run_submission()` รัน Python ผ่าน Docker จริงแล้ว โดย:
- สร้างไฟล์ `main.py` ชั่วคราว
- mount เข้า container แบบ read-only
- รันด้วย `--network none`
- จำกัด `cpu` และ `memory`
- ใช้ `--read-only`, `--cap-drop ALL`, `--security-opt no-new-privileges`
- ใช้ stdin ตรงเข้า process ใน container
- ตรวจ `timeout` ต่อ test case
- ใช้ `GRADERAPP_WORKSPACE_ROOT` สำหรับ bind mount แทน temp dir ใน namespace ปิด เพื่อให้ Docker daemon มองเห็นไฟล์งานได้

รอบถัดไปที่ควรทำ:
- แยก runner ต่อภาษา
- บันทึก artifact / stderr / compile log ให้ละเอียดขึ้น
- เพิ่ม monitor endpoint ถ้าต้องการดูสถานะ worker ผ่านเว็บ

## Capacity Plan เบื้องต้น

สำหรับ `rbruai2` ที่มี RAM 4GB และ CPU 4 cores แนะนำดังนี้:

- `1 worker`
  - เบาสุดและเสถียรสุดสำหรับ production รอบแรก
  - เหมาะกับช่วงเริ่มต้นที่ยังดู pattern การส่งงานจริง
  - ถ้าโจทย์ demo มี 4 test cases และแต่ละ case timeout ที่ประมาณ 2.5 วินาที งานที่แย่สุดจะใช้เวลาราว 10 วินาทีต่อ submission
- `2 workers`
  - ใช้เมื่อเริ่มเห็น queue รอนานจริง
  - throughput โดยรวมจะดีขึ้นใกล้เคียง 2 เท่าในงานที่ CPU ไม่ชนหนักมาก
  - ยังอยู่ในขอบเขตที่เครื่อง 4GB พอรับได้ ถ้าใช้ limit ปัจจุบัน (`0.50 CPU`, `128MB`)
- `3+ workers`
  - ยังไม่แนะนำบนเครื่องนี้
  - เสี่ยงเกิดการแข่งขัน CPU, timeout เพิ่ม, และ swap สูงขึ้น

ภาพรวมคร่าว ๆ:

- `1 worker`
  - งานแบบผ่านปกติของโจทย์เล็กจะอยู่ราว 1 วินาทีหรือต่ำกว่า
  - งาน timeout หนักสุดจะอยู่ราว 10 วินาทีต่อ submission
  - ถ้ามี 100 submissions ที่แย่สุดพร้อมกัน คิวอาจยาวประมาณ 16-17 นาที
- `2 workers`
  - คิว worst-case จะลดลงประมาณครึ่งหนึ่ง
  - เหมาะกว่าเมื่อเริ่มมีห้องเรียนใหญ่หรือส่งพร้อมกันช่วงท้ายคาบ

คำแนะนำเชิงปฏิบัติ:

- เริ่มด้วย `1 worker`
- เปิด `2 workers` เมื่อเห็น queue เริ่มยาวจริง
- ใช้ `stale job requeue` และ cleanup container ที่มีอยู่แล้วเป็น safety net
- อย่าเพิ่ม concurrency ก่อนมีหน้า monitor หรือ metric อย่างน้อยใน admin

## Machine-readable Overview

```json
{
  "module": "grader_worker",
  "purpose": "External stateless worker for claiming grader jobs and reporting results back to grader_app",
  "entrypoint": "grader_worker/worker.py",
  "dependencies": [
    "python3 stdlib",
    "docker"
  ],
  "recommended_concurrency": {
    "rbruai2_4gb_ram": {
      "default": 1,
      "burst_max": 2,
      "not_recommended": 3
    }
  },
  "required_env": [
    "GRADERAPP_BASE_URL",
    "GRADERAPP_WORKER_SHARED_TOKEN",
    "GRADERAPP_WORKER_NAME",
    "GRADERAPP_WORKER_HOST",
    "GRADERAPP_RUNNER_TARGET",
    "GRADERAPP_POLL_SECONDS",
    "GRADERAPP_DOCKER_BIN",
    "GRADERAPP_PYTHON_IMAGE",
    "GRADERAPP_DOCKER_MEMORY_MB",
    "GRADERAPP_DOCKER_PIDS_LIMIT",
    "GRADERAPP_DOCKER_TMPFS_MB",
    "GRADERAPP_WORKSPACE_ROOT"
  ],
  "current_state": "docker-backed python worker",
  "next_steps": [
    "add per-language runners",
    "add structured stderr and artifact persistence"
  ]
}
```
