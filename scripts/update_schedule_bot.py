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
            
            # รอโหลดหน้าต่างถัดไป (หน้าแบ่ง Frame)
            await page.wait_for_load_state("networkidle")
            
            print("📍 กำลังทะลุเข้าสู่หน้า 'ภาระการสอน' โดยตรง...")
            # ปัญหาคือหน้าระบบอาจารย์ใช้โครงสร้าง <Frame> ซ้อนกัน ทำให้บอทมองไม่เห็นปุ่ม
            # วิธีแก้คือ "กระโดดข้าม" (Bypass) ไปที่ URL ของเนื้อหาตรงๆ เลย
            await page.goto("https://reg.rbru.ac.th/registrar/duty_teach.asp")
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
        
        # 1. ค้นหาตารางเรียน (หาแบบยืดหยุ่นสุดๆ)
        schedule_table = None
        for tbl in soup.find_all('table'):
            text_content = tbl.get_text()
            if 'Day/Time' in text_content or '8:00-9:00' in text_content:
                schedule_table = tbl
                break
        
        scraped_data = {"courses": [], "exams": []}
        found_data = False
        
        if schedule_table:
            print("🎉 พบตารางสอน! กำลังแกะข้อมูลคาบเรียน...")
            found_data = True
            # ไม่เช็ค bgcolor ขาว เพราะบางครั้งเป็น #F0F0F0 ลูปหา <tr> ทั้งหมดเลย
            for row in schedule_table.find_all('tr'):
                # หาชื่อวัน (เช็คจากสีหัวข้อฝั่งซ้าย)
                day_cell = row.find('td', {'bgcolor': ['#A0A0A0', '#C05050']})
                if not day_cell: continue
                day_name = day_cell.text.strip()
                
                cumulative_cols = 0
                # ลูปผ่านกล่องเวลาต่างๆ ในวันนั้น
                for td in row.find_all('td', recursive=False)[1:]: # ข้ามช่องวัน
                    colspan = int(td.get('colspan', 1))
                    
                    # ถ้าเป็นสีฟ้า แสดงว่ามีวิชาเรียน
                    if td.get('bgcolor') == '#C0D0FF':
                        links = [{"title": a.text.strip(), "url": a.get('href')} for a in td.find_all('a') if a.get('href') and 'class_info' not in a.get('href')]
                        
                        # แยกข้อความทีละบรรทัด (เอาแท็ก <br> มาตัดคำ)
                        for br in td.find_all('br'):
                            br.replace_with('\n')
                        
                        clean_text = td.get_text('\n')
                        lines = [ln.strip() for ln in clean_text.split('\n') if ln.strip() and ln.strip() != '|' and ln.strip() != ',']
                        
                        course_code, sec, course_name, room = "", "", "", ""
                        if len(lines) >= 3:
                            first_line = lines[0].split(',')
                            course_code = first_line[0].replace(',', '').strip()
                            sec = first_line[1].strip() if len(first_line) > 1 else ""
                            course_name = lines[1].strip()
                            room = lines[2].strip()
                            
                        # คำนวณเวลา (เริ่ม 08:00, 1 colspan = 5 นาที)
                        start_min = 8 * 60 + (cumulative_cols * 5)
                        end_min = start_min + (colspan * 5)
                        time_str = f"{start_min//60:02d}:{start_min%60:02d} - {end_min//60:02d}:{end_min%60:02d}"
                        
                        scraped_data["courses"].append({
                            "day": day_name,
                            "time": time_str,
                            "course_code": course_code,
                            "course_name": course_name,
                            "section": sec,
                            "room": room,
                            "links": links
                        })
                    cumulative_cols += colspan
        
        # 2. ค้นหาตารางคุมสอบ (ถ้ามี)
        # ใช้วิธีหา <tr> ที่มี valign="TOP" และมีข้อมูล 5 ช่อง ซึ่งเป็นแพทเทิร์นตารางสอบของมหาลัย
        for tr in soup.find_all('tr', {'valign': 'TOP'}):
            cols = tr.find_all('td')
            if len(cols) == 5:
                # ลองเช็คว่าเป็นตารางสอบจริงไหม (ช่องแรกมักมีคำว่าเวลา หรือวันที่)
                if '(C)' in cols[0].text or 'เวลา' in cols[0].text or 'ห้อง' in cols[0].text:
                    if not found_data:
                        print("📝 พบตารางคุมสอบ! กำลังแกะข้อมูล...")
                    found_data = True
                    for br in cols[0].find_all('br'): br.replace_with('\n')
                    dt_room = [l.strip() for l in cols[0].get_text('\n').split('\n') if l.strip()]
                    
                    for br in cols[2].find_all('br'): br.replace_with('\n')
                    name_lines = [l.strip() for l in cols[2].get_text('\n').split('\n') if l.strip()]
                    
                    scraped_data["exams"].append({
                        "date": dt_room[0] if len(dt_room) > 0 else "",
                        "time": dt_room[1] if len(dt_room) > 1 else "",
                        "room": dt_room[2] if len(dt_room) > 2 else "",
                        "course_code": cols[1].text.strip(),
                        "course_name": name_lines[0] if name_lines else "",
                        "section": cols[3].text.strip(),
                        "proctors": cols[4].text.strip().replace('\n', ' ')
                    })
                        
        if found_data:
            # ดึง path ขึ้นไปจากแฟ้ม scripts/ แล้วเซฟ schedule.json
            output_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'schedule.json')
            with open(output_path, 'w', encoding='utf-8') as f:
                json.dump(scraped_data, f, ensure_ascii=False, indent=2)
            print(f"💾 อัพเดทข้อมูลลงไฟล์สำเร็จ: {output_path}")
            print("✨ โครงสร้าง JSON พร้อมนำไปใช้ขึ้นเว็บแล้ว!")
        else:
            print("❌ ไม่พบตารางเรียนหรือตารางคุมสอบใดๆ ในหน้านี้")
            # ถ้าหาไม่เจอ ให้เซฟไฟล์ html เอาไว้ดูว่าหน้าเว็บหน้าตาเป็นยังไง
            debug_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'debug_page.html')
            with open(debug_path, 'w', encoding='utf-8') as f:
                f.write(html_content)
            print(f"🐛 บันทึกหน้าเว็บตอนที่หาไม่เจอไว้ที่: {debug_path}")
            print("   -> ลองรบกวนดับเบิลคลิกเปิดไฟล์นี้ในคอมดูครับ ว่ามันค้างอยู่หน้าไหน หรือตารางเรียนหน้าตาเป็นยังไง")

        await browser.close()
        print("👋 ปิดโปรแกรม Automation")

if __name__ == "__main__":
    asyncio.run(main())
