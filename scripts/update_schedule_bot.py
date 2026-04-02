import asyncio
import os
import json
from playwright.async_api import async_playwright
from bs4 import BeautifulSoup

# ==========================================
# 🤖 BOT ตั้งโต๊ะทำงาน: ดึงตารางเรียนอัตโนมัติ
# ==========================================
# ⚠️ ข้อควรระวัง: ห้ามใส่รหัสผ่านลงในไฟล์นี้ตรงๆ หากไฟล์นี้ถูกนำขึ้น GitHub
# แนะนำให้ใช้ตัวแปร Environment (.env) แต่สำหรับการทดสอบในเครื่อง ให้แก้บรรทัดล่างได้เลย

USERNAME = os.environ.get("RBRU_USERNAME", "")
PASSWORD = os.environ.get("RBRU_PASSWORD", "")

# URL สำหรับเข้าหน้า Login
LOGIN_URL = "https://reg.rbru.ac.th/registrar/login.asp" 

async def main():
    if USERNAME == "รหัสนักศึกษา/อาจารย์":
        print("🛑 กรุณาตั้งค่า Username และ Password ในไฟล์ก่อนรัน หรือกำหนดใน Environment Variables")
        # return # เอาคอมเมนต์ออกถ้านำไปใช้จริง
        
    print("🚀 เริ่มการทำงานของ Browser Automation...")
    
    async with async_playwright() as p:
        # headless=False จะเห็น Browser เปิดขึ้นมาทำตามสเต็ป (เหมาะสำหรับตอนเขียน/ทดสอบ)
        # headless=True เพื่อให้ซ่อนการทำงานไว้เบื้องหลัง (เหมาะสำหรับตอนเอาไปรันบน Server สคริปต์จะไม่หน้าต่างเด้ง)
        browser = await p.chromium.launch(headless=False) 
        page = await browser.new_page()

        print("🌐 กำลังเข้าสู่หน้า Login...")
        await page.goto(LOGIN_URL)

        # ================== 1. จำลองการ Login ==================
        print("✍️ กำลังจำลองการพิมพ์ Username / Password...")
        # หมายเหตุ: ชื่อ (name) ของช่อง input อาจตัองใช้เครื่องมือ Inspect เพื่อดูชื่อที่แท้จริง
        # สมมติว่าช่องกรอกชื่อผู้ใช้มี name="Username" และรหัสผ่าน name="Password" (คุณต้องเปลี่ยนค่าตามจริงของเว็บ REG)
        try:
            await page.fill("input[name='f_uid']", USERNAME)
            await page.fill("input[name='f_pwd']", PASSWORD)
            
            print("🖱️ กำลังคลิกปุ่มเข้าสู่ระบบ...")
            await page.click("input[type='SUBMIT']")
            
            # รอให้หน้าเว็บโหลดหลังจาก Login เสร็จไปหน้าหลัก
            await page.wait_for_load_state("networkidle")
        except Exception as e:
            print("⚠️ หาช่องกรอก Login ไม่เจอ (รบกวนตรวจสอบชื่อ selector ของปุ่ม/ช่องกรอกอีกครั้ง)")
            print(f"Error: {e}")

        # ================== 2. การเลือกระบบอาจารย์ และคลิกเมนู ==================
        print("👨‍🏫 กำลังเลือกระบบสำหรับอาจารย์...")
        try:
            # เลือกระบบอาจารย์ (Radio button)
            await page.check("input[name='role'][value='101']")
            
            print("🖱️ กำลังคลิกปุ่ม 'เลือก' เพื่อเข้าสู่ระบบอาจารย์...")
            # พอเลือก Radio แล้ว ต้องคลิกปุ่ม Submit ที่เขียนว่า "เลือก"
            await page.click("input[type='SUBMIT']")
            
            # รอโหลดหน้าต่างถัดไป
            await page.wait_for_load_state("networkidle")
            
            print("📍 กำลังคลิกไปตามเมนูเพื่อหา 'ภาระการสอน'...")
            # ค้นหาจากลิงก์ปลายทาง (duty_teach.asp) แทนเพื่อกันการพิมพ์ผิดหรือเป็นรูปภาพ
            await page.locator("a[href*='duty_teach.asp']").first.click()
            await page.wait_for_load_state("networkidle")
            
            print("📍 กำลังคลิกไปหา 'ตารางสอนอาจารย์'...")
            # ค้นหาจากลิงก์ปลายทาง (teach_ttable.asp)
            await page.locator("a[href*='teach_ttable.asp']").first.click()
            await page.wait_for_load_state("networkidle")
            
            print("📅 กำลังพยายามเลือกภาคเรียนที่ 2...")
            # หาลิงก์ที่มีคำว่า semester=2 อยู่ใน href
            semester_link = page.locator("a[href*='semester=2']")
            if await semester_link.count() > 0:
                await semester_link.first.click()
                await page.wait_for_load_state("networkidle")
            else:
                print("ℹ️ อัปเดต: ไม่พบปุ่มเลือกเทอม 2 (อาจจะอยู่ในเทอม 2 อยู่แล้ว หรือปุ่มอาจซ่อนอยู่)")
            
            print("✅ เดินทางมาถึงหน้าตารางสอนภาคเรียนเป้าหมายสำเร็จแล้ว!")
        except Exception as e:
            print(f"⚠️ หาปุ่มเมนูไม่เจอ (รบกวนตรวจสอบชื่อปุ่มอีกครั้ง): {e}")
            await browser.close()
            return
            
        # ================== 3. ดูดข้อมูล HTML และแปลงเป็น JSON ==================
        print("🧲 กำลังดึงข้อมูลตารางในหน้าเว็บ...")
        html_content = await page.content()
        soup = BeautifulSoup(html_content, 'html.parser')
        
        # สมมติว่าตารางข้อมูลเรามี border="1" (เหมือนที่เคยเขียนในไฟล์เช็ค html)
        schedule_table = soup.find('table', {'border': '1'})
        
        if schedule_table:
            print("🎉 พบตารางสอน! กำลังเตรียมข้อมูล...")
            # ตรงนี้คุณสามารถนำ โค้ดของ parse_table.py หรือ build_schedule.py มาใส่
            # เพื่อสกัดค่าในตารางออกมาได้เลย
            
            # ในที่นี้คือ Mock ข้อมูลตัวอย่าง หลังจากดึงมาแล้ว
            scraped_data = [
                {"day": "จันทร์", "time": "09:00 - 12:00", "subject": "Automated Systems"},
                {"day": "พุธ", "time": "13:00 - 16:00", "subject": "Web Development"}
            ]
            
            # บันทึกลงไฟล์ schedule.json ที่ใช้สำหรับเว็บ Portfolio
            output_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'schedule.json')
            
            with open(output_path, 'w', encoding='utf-8') as f:
                json.dump(scraped_data, f, ensure_ascii=False, indent=2)
                
            print(f"💾 อัพเดทข้อมูลลงไฟล์สำเร็จ: {output_path}")
            print("✨ เว็บไซต์ Portfolio จะเปลี่ยนไปใช้ตารางเวอร์ชันใหม่ทันที!")
            
        else:
            print("❌ ไม่พบตารางเรียนในหน้านี้ (อาจต้องแก้ไขการหาตารางใน BeautifulSoup)")

        await browser.close()
        print("👋 ปิดโปรแกรม Automation")

if __name__ == "__main__":
    asyncio.run(main())
