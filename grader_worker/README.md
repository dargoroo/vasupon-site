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
