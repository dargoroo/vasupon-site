import os
import json
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright

# ⚠️ ข้อควรระวัง: ห้ามใส่รหัสผ่านลงในไฟล์นี้ตรงๆ นำไปใช้จริงควรดึงจาก Environment Variables (.env)
USERNAME = os.environ.get("RBRU_USERNAME", "")
PASSWORD = os.environ.get("RBRU_PASSWORD", "")

LOGIN_URL = "https://reg.rbru.ac.th/registrar/login.asp" 

def run():
    print("========================================")
    print(" 🤖 ระบบรัน Bot เจาะข้อมูลนักศึกษาและเกรด")
    print("========================================")
    
    if not USERNAME or not PASSWORD:
        print("❌ ข้อผิดพลาด: ไม่พบ Username หรือ Password ใน Environment Variables")
        print("กรุณาตั้งค่า RBRU_USERNAME และ RBRU_PASSWORD ก่อนรัน")
        return

    with sync_playwright() as p:
        print("🚀 เริ่มการทำงานของ Browser Automation...")
        browser = p.chromium.launch(headless=True)
        context = browser.new_context()
        page = context.new_page()
        
        try:
            print("🌐 กำลังเข้าสู่หน้า Login...")
            page.goto(LOGIN_URL)
            page.fill("input[name='f_uid']", USERNAME)
            page.fill("input[name='f_pwd']", PASSWORD)
            
            print("🖱️ กำลังคลิกปุ่มเข้าสู่ระบบ...")
            page.keyboard.press("Enter")
            page.wait_for_load_state('networkidle')
            
            print("👨‍🏫 กำลังเลือกระบบสำหรับอาจารย์...")
            page.wait_for_selector("input[name='role'][value='101']", timeout=10000)
            page.check("input[name='role'][value='101']")
            
            buttons = page.locator("input[type='submit']")
            if buttons.count() > 1:
                buttons.nth(1).click()
            else:
                buttons.first.click()
            page.wait_for_load_state('networkidle')
            
            print("📍 กำลังทะลุเข้าสู่หน้า 'บันทึกเกรด'...")
            # Navigate to the grade entry main menu
            page.goto("https://reg.rbru.ac.th/registrar/grade_entry_0.asp")
            page.wait_for_load_state('networkidle')
            
            html_content = page.content()
            soup = BeautifulSoup(html_content, 'html.parser')
            
            scraped_data = {"academic_year": "", "courses": []}
            
            # Find the academic year if possible
            # <font><b>ปีการศึกษา </b> 2568</font>
            year_tag = soup.find('b', string=lambda s: s and 'ปีการศึกษา' in s)
            if year_tag and year_tag.parent.find_next_sibling('font'):
                scraped_data["academic_year"] = year_tag.parent.find_next_sibling('font').text.strip()

            # 4. ค้นหาวิชาต่างๆ และลิงก์ไปหน้ากรอกคะแนน
            courses_list = []
            
            for tr in soup.find_all('tr', {'bgcolor': ['#FFFFE0', '#F0F0E0', '#FFFFFF', '#F0F0F0', '#F4F4C0']}):
                cols = tr.find_all('td')
                if len(cols) >= 5:
                    a_tag = tr.find('a', href=lambda x: x and 'enrollpoint.asp' in x)
                    if a_tag:
                        # Sometimes href is absolute, sometimes relative
                        href = a_tag.get('href')
                        full_url = href if href.startswith('http') else ("https://reg.rbru.ac.th/registrar/" + href.lstrip('/'))
                        
                        course_code = cols[0].text.strip()
                        course_name = cols[1].text.strip()
                        section = cols[2].text.strip() if len(cols) > 2 else ""
                        
                        # Avoid duplicates
                        if not any(c['href'] == full_url for c in courses_list):
                            courses_list.append({
                                "course_code": course_code,
                                "course_name": course_name,
                                "section": section,
                                "href": full_url
                            })
            
            if not courses_list:
                print("❌ ไม่พบลิงก์รายวิชาในหน้าบันทึกเกรด อาจจะยังไม่เปิดระบบ หรือยังไม่ถึงช่วงประเมิน")
            else:
                print(f"✅ พบรายวิชาทั้งหมด {len(courses_list)} กลุ่มเรียน! กำลังตะลุยดึงข้อมูลทีละวิชา...")
                
                for idx, course in enumerate(courses_list):
                    print(f"   [{idx+1}/{len(courses_list)}] กำลังดึงข้อมูล นศ. วิชา: {course['course_code']} (Sec {course['section']})")
                    page.goto(course['href'])
                    page.wait_for_load_state('networkidle')
                    
                    class_html = page.content()
                    class_soup = BeautifulSoup(class_html, 'html.parser')
                    
                    student_roster = []
                    
                    # ค้นหาแถวนักศึกษา
                    student_rows = class_soup.find_all('tr', {'bgcolor': ['#F0F0FF', '#E0E0E0']})
                    
                    for row in student_rows:
                        cols = row.find_all('td')
                        if len(cols) >= 12:
                            student_id = cols[1].text.strip()
                            student_name = cols[2].text.strip()
                            grade_mode = cols[3].text.strip()
                            status = cols[4].text.strip()
                            
                            # ดึงเกรดรวมและเกรดตัด (ตามที่ปรากฏใน HTML snippet 14 คอลัมน์)
                            t_score = cols[8].text.strip() if len(cols) >= 14 else ""
                            grade = cols[-3].text.strip() if len(cols) >= 14 else ""
                            final_result = cols[-1].text.strip() if len(cols) >= 14 else cols[-1].text.strip()
                            
                            student_roster.append({
                                "student_id": student_id,
                                "name": student_name,
                                "grade_mode": grade_mode,
                                "status": status,
                                "total_score": t_score,
                                "cal_grade": grade,
                                "final_grade": final_result
                            })
                    
                    course["student_count"] = len(student_roster)
                    course["students"] = student_roster
                    scraped_data["courses"].append(course)
                    
            # 5. บันทึกผลลัพธ์ลง JSON
            output_dir = os.path.dirname(os.path.abspath(__file__))
            parent_dir = os.path.dirname(output_dir)
            json_path = os.path.join(parent_dir, "grades.json")
            
            with open(json_path, 'w', encoding='utf-8') as f:
                json.dump(scraped_data, f, ensure_ascii=False, indent=2)
                
            total_students = sum(c.get('student_count', 0) for c in scraped_data['courses'])
            print(f"💾 อัพเดทข้อมูลเรียบร้อย! ส่งมอบไฟล์: grades.json")
            print(f"🎉 สำเร็จ! ดึงข้อมูลรวมทั้งสิ้น {total_students} รายชื่อ")
            
        except Exception as e:
            print("❌ เกิดข้อผิดพลาดระหว่างทำงาน:")
            print(e)
            
            debug_path = os.path.join(os.getcwd(), 'debug_grades_page.html')
            with open(debug_path, 'w', encoding='utf-8') as f:
                f.write(page.content())
            print(f"บันทึกไฟล์ debug_grades_page.html ไว้ให้ตรวจสอบแล้ว")

        finally:
            print("👋 ปิดเชื่อมต่อ Browser")
            browser.close()

if __name__ == "__main__":
    run()
