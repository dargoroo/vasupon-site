#!/bin/bash

echo "========================================"
echo " 🤖 ระบบรัน Bot เจาะข้อมูลนักศึกษาและเกรด"
echo "========================================"

# 1. ติดตั้ง Library ที่จำเป็น (ถ้ายังไม่มี)
echo "📦 1. กำลังจัดการ Dependencies (Playwright, BeautifulSoup)..."
pip3 install playwright beautifulsoup4
playwright install chromium

# 2. รัน Script ของ Python 
echo "🚀 2. กำลังรัน Script Automation..."
python3 scripts/scrape_grades_bot.py

echo "✅ สิ้นสุดการทำงาน!"
