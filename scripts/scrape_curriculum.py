import requests
from bs4 import BeautifulSoup
import json
import os
import urllib3

# Disable SSL warnings for internal university sites
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

def get_computer_engineering_courses(year, semester):
    url = "https://tqf.rbru.ac.th/staff_reportByGroupIntruc.php"
    payload = {
        'lstacadyear': str(year),
        'lstsemester': str(semester),
        'lstperiod': '1', # ภาคปกติ
        'lstfac': '8',    # วท.บ. / วศ.บ. (คณะคอมพิวเตอร์)
        'Submit': 'ดูข้อมูล'
    }

    try:
        print(f"Fetching curriculum data for {year}/{semester}...")
        response = requests.post(url, data=payload, verify=False)
        response.raise_for_status()
    except requests.exceptions.RequestException as e:
        print(f"Error fetching data: {e}")
        return []

    soup = BeautifulSoup(response.content, 'html.parser')
    
    courses = {}
    is_target_curriculum = False
    
    # Iterate through all table rows
    rows = soup.find_all('tr')
    for row in rows:
        # Check if this row is a curriculum header (pink background)
        header_cell = row.find('td', bgcolor='#FFCCCC')
        if header_cell:
            text = header_cell.get_text(strip=True)
            # Toggle parsing flag based on curriculum name
            if 'วิศวกรรมคอมพิวเตอร์' in text:
                is_target_curriculum = True
            else:
                is_target_curriculum = False
            continue
            
        # If we are inside the CE curriculum block, extract the subjects
        if is_target_curriculum:
            cols = row.find_all('td', bgcolor='#FFFFFF')
            if cols:
                subject_text = cols[0].get_text(strip=True)
                if ' - ' in subject_text:
                    parts = subject_text.split(' - ', 1)
                    course_code = parts[0].strip()
                    course_name = parts[1].strip()
                    
                    # Store in dictionary to keep list unique (same course in diff cohorts)
                    courses[course_code] = course_name

    # Convert to list of dicts
    output = []
    for code, name in sorted(courses.items()):
        output.append({
            "course_code": code,
            "course_name": name
        })
        
    return output

if __name__ == "__main__":
    year = "2568"
    semester = "2"
    
    courses = get_computer_engineering_courses(year, semester)
    
    output_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "curriculum_courses.json")
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump({
            "curriculum": "Computer Engineering",
            "year": year, 
            "semester": semester, 
            "total_courses": len(courses),
            "courses": courses
        }, f, ensure_ascii=False, indent=2)
        
    print(f"\n✅ Successfully extracted {len(courses)} unique courses for Computer Engineering.")
    print(f"✅ Data saved to: {output_path}")
    
    # Print a sample of what we got
    print("\nSample courses extracted:")
    for c in courses[:5]:
        print(f"  - {c['course_code']}: {c['course_name']}")
    if len(courses) > 5:
        print("  - ...")
