import argparse
import asyncio
import json
import os
import re
from datetime import datetime, timezone, timedelta
from pathlib import Path
from urllib.parse import urlparse

from bs4 import BeautifulSoup
from playwright.async_api import async_playwright


LOGIN_URL = "https://reg.rbru.ac.th/registrar/login"
LEGACY_LOGIN_URL = "https://reg.rbru.ac.th/registrar/login.asp"
TEACH_TABLE_URL = "https://reg.rbru.ac.th/registrar/teachttable"
LEGACY_DUTY_TEACH_URL = "https://reg.rbru.ac.th/registrar/duty_teach.asp"
TIMEZONE_BANGKOK = timezone(timedelta(hours=7))
DEFAULT_OUTPUT = Path(__file__).resolve().parents[1] / "schedule.json"
DEFAULT_DEBUG_HTML = Path(__file__).resolve().parents[1] / "debug_page.html"


def env_bool(name: str, default: bool) -> bool:
    raw = os.environ.get(name)
    if raw is None:
        return default
    return raw.strip().lower() not in {"0", "false", "no", "off"}


def normalize_space(value: str) -> str:
    return re.sub(r"\s+", " ", (value or "").replace("\xa0", " ")).strip()


def sanitize_link_title(raw_title: str, raw_url: str) -> str:
    raw_title = normalize_space(raw_title)
    raw_url = (raw_url or "").strip()
    combined = f"{raw_title} {raw_url}".lower()

    if "classroom" in combined:
        return "classroom"
    if "line" in combined:
        return "line"

    # Avoid publishing full URLs or invite codes in schedule.json.
    if raw_title.startswith(("http://", "https://")):
        parsed = urlparse(raw_title)
        return parsed.netloc or "link"

    return raw_title[:80] if raw_title else "link"


def parse_course_cell(td, day_name: str, cumulative_cols: int, colspan: int) -> dict:
    links = [
        {
            "title": sanitize_link_title(a.get_text(" "), a.get("href")),
            "url": a.get("href"),
        }
        for a in td.find_all("a")
        if a.get("href") and "class_info" not in a.get("href")
    ]

    for br in td.find_all("br"):
        br.replace_with("\n")

    clean_text = td.get_text("\n")
    lines = [
        normalize_space(line)
        for line in clean_text.split("\n")
        if normalize_space(line) not in {"", "|", ","}
    ]

    course_code = ""
    section = ""
    course_name = ""
    room = ""
    if lines:
        first_parts = [part.strip() for part in lines[0].split(",")]
        course_code = first_parts[0].replace(",", "").strip()
        section = first_parts[1].strip() if len(first_parts) > 1 else ""
    if len(lines) >= 2:
        course_name = lines[1]
    if len(lines) >= 3:
        room = lines[2]

    # REG table uses 5-minute slots beginning at 08:00.
    start_min = 8 * 60 + cumulative_cols * 5
    end_min = start_min + colspan * 5
    time_str = f"{start_min // 60:02d}:{start_min % 60:02d} - {end_min // 60:02d}:{end_min % 60:02d}"

    return {
        "day": day_name,
        "time": time_str,
        "course_code": course_code,
        "course_name": course_name,
        "section": section,
        "room": room,
        "links": links,
    }


def parse_summary_course_table(soup: BeautifulSoup) -> list[dict]:
    courses = []
    day_names = {"จันทร์", "อังคาร", "พุธ", "พฤหัสบดี", "ศุกร์", "เสาร์", "อาทิตย์"}
    current_day = ""

    for table in soup.find_all("table"):
        rows = table.find_all("tr")
        if not rows:
            continue

        header = [normalize_space(cell.get_text(" ")) for cell in rows[0].find_all(["th", "td"])]
        if header[:7] != ["วัน", "เวลา", "รหัสวิชา", "หน่วยกิต", "ชื่อวิชา", "SEC", "ห้อง"]:
            continue

        for row in rows[1:]:
            cells = row.find_all(["th", "td"])
            values = [normalize_space(cell.get_text(" ")) for cell in cells]
            if not values or values == [""]:
                continue

            if values[0] in day_names:
                current_day = values[0]
                values = values[1:]
            if len(values) < 6 or current_day == "":
                continue

            name_lines = [
                normalize_space(line)
                for line in cells[-3].get_text("\n").split("\n")
                if normalize_space(line)
            ]
            course_name = name_lines[0] if name_lines else values[3]

            courses.append(
                {
                    "day": current_day,
                    "time": values[0],
                    "course_code": values[1],
                    "course_name": course_name,
                    "credits": values[2],
                    "section": values[4],
                    "room": values[5],
                    "links": [],
                }
            )
        break

    return courses


def parse_exam_table(soup: BeautifulSoup) -> list[dict]:
    exams = []
    for table in soup.find_all("table"):
        rows = table.find_all("tr")
        if not rows:
            continue

        header = [normalize_space(cell.get_text(" ")) for cell in rows[0].find_all(["th", "td"])]
        if header[:5] == ["รหัสวิชา", "รายวิชา", "SEC", "สอบกลางภาค", "สอบปลายภาค"]:
            for row in rows[1:]:
                values = [normalize_space(cell.get_text(" ")) for cell in row.find_all(["th", "td"])]
                if not values or "ไม่พบข้อมูล" in values[0]:
                    continue
                exams.append(
                    {
                        "course_code": values[0] if len(values) > 0 else "",
                        "course_name": values[1] if len(values) > 1 else "",
                        "section": values[2] if len(values) > 2 else "",
                        "midterm": values[3] if len(values) > 3 else "",
                        "final": values[4] if len(values) > 4 else "",
                    }
                )
            return exams

    return exams


def parse_schedule_html(html_content: str, source_url: str = "") -> dict:
    soup = BeautifulSoup(html_content, "html.parser")
    scraped_data = {
        "meta": {
            "source": "reg.rbru.ac.th/registrar/teachttable",
            "source_url": source_url,
            "updated_at": datetime.now(TIMEZONE_BANGKOK).isoformat(timespec="seconds"),
        },
        "courses": [],
        "exams": [],
    }

    summary_courses = parse_summary_course_table(soup)
    if summary_courses:
        scraped_data["courses"] = summary_courses
        scraped_data["exams"] = parse_exam_table(soup)
        return scraped_data

    schedule_table = None
    for table in soup.find_all("table"):
        text_content = table.get_text(" ")
        if "Day/Time" in text_content or "วัน/เวลา" in text_content or "8:00-9:00" in text_content:
            schedule_table = table
            break

    if schedule_table:
        for row in schedule_table.find_all("tr"):
            day_cell = row.find("td", {"bgcolor": ["#A0A0A0", "#C05050"]})
            if not day_cell:
                continue

            day_name = normalize_space(day_cell.get_text(" "))
            cumulative_cols = 0
            for td in row.find_all("td", recursive=False)[1:]:
                colspan = int(td.get("colspan", 1))
                if str(td.get("bgcolor", "")).upper() == "#C0D0FF":
                    scraped_data["courses"].append(
                        parse_course_cell(td, day_name, cumulative_cols, colspan)
                    )
                cumulative_cols += colspan

    for tr in soup.find_all("tr", {"valign": "TOP"}):
        cols = tr.find_all("td")
        if len(cols) != 5:
            continue
        first_col = cols[0].get_text(" ")
        if "(C)" not in first_col and "เวลา" not in first_col and "ห้อง" not in first_col:
            continue

        for br in cols[0].find_all("br"):
            br.replace_with("\n")
        date_room = [
            normalize_space(line)
            for line in cols[0].get_text("\n").split("\n")
            if normalize_space(line)
        ]

        for br in cols[2].find_all("br"):
            br.replace_with("\n")
        name_lines = [
            normalize_space(line)
            for line in cols[2].get_text("\n").split("\n")
            if normalize_space(line)
        ]

        scraped_data["exams"].append(
            {
                "date": date_room[0] if len(date_room) > 0 else "",
                "time": date_room[1] if len(date_room) > 1 else "",
                "room": date_room[2] if len(date_room) > 2 else "",
                "course_code": normalize_space(cols[1].get_text(" ")),
                "course_name": name_lines[0] if name_lines else "",
                "section": normalize_space(cols[3].get_text(" ")),
                "proctors": normalize_space(cols[4].get_text(" ")),
            }
        )

    return scraped_data


async def fill_first(page, selectors: list[str], value: str) -> bool:
    for selector in selectors:
        locator = page.locator(selector).first
        if await locator.count() > 0:
            await locator.fill(value)
            return True
    return False


async def fetch_schedule_html(username: str, password: str, semester: str, headless: bool) -> tuple[str, str]:
    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(headless=headless)
        page = await browser.new_page()
        page.set_default_timeout(45000)

        await page.goto(LOGIN_URL, wait_until="load")
        await page.wait_for_timeout(3000)
        username_filled = await fill_first(
            page,
            [
                "input[placeholder='รหัสประจำตัว']",
                "input[name='f_uid']",
                "input[type='text']",
            ],
            username,
        )
        password_filled = await fill_first(
            page,
            [
                "input[placeholder='รหัสผ่าน']",
                "input[name='f_pwd']",
                "input[type='password']",
            ],
            password,
        )
        if not username_filled or not password_filled:
            await page.goto(LEGACY_LOGIN_URL, wait_until="load")
            await page.wait_for_timeout(2000)
            username_filled = await fill_first(page, ["input[name='f_uid']", "input[type='text']"], username)
            password_filled = await fill_first(page, ["input[name='f_pwd']", "input[type='password']"], password)
        if not username_filled or not password_filled:
            raise RuntimeError("ไม่พบช่อง username/password ของ REG")

        submit_button = page.get_by_role("button", name="เข้าสู่ระบบ").first
        if await submit_button.count() > 0:
            await submit_button.click()
        else:
            await page.click("input[type='SUBMIT']")
        await page.wait_for_timeout(4000)

        role_selector = "input[name='role'][value='101']"
        if await page.locator(role_selector).count() > 0:
            await page.check(role_selector)
            await page.click("input[type='SUBMIT']")
            await page.wait_for_timeout(3000)
        elif await page.locator("#instructor").count() > 0:
            await page.check("#instructor")
            role_submit = page.get_by_role("button", name="เข้าสู่ระบบ").first
            if await role_submit.count() > 0:
                await role_submit.click()
            await page.wait_for_timeout(4000)

        if "/registrar/instructor" in page.url or "/registrar/changerole" in page.url or "/registrar/home" in page.url:
            await page.goto(TEACH_TABLE_URL, wait_until="load")
            await page.wait_for_timeout(6000)
        else:
            await page.goto(LEGACY_DUTY_TEACH_URL, wait_until="load")
            await page.wait_for_timeout(2000)
            teach_table_link = page.locator("a[href*='teach_ttable.asp']").first
            if await teach_table_link.count() == 0:
                await page.goto(TEACH_TABLE_URL, wait_until="load")
            else:
                await teach_table_link.click()
            await page.wait_for_timeout(5000)

        if semester:
            semester_link = page.locator(f"a[href*='semester={semester}']").first
            if await semester_link.count() > 0:
                await semester_link.click()
                await page.wait_for_timeout(5000)

        html_content = await page.content()
        current_url = page.url
        await browser.close()
        return html_content, current_url


async def main() -> None:
    parser = argparse.ArgumentParser(description="Update teaching schedule from REG RBRU.")
    parser.add_argument("--output", default=str(DEFAULT_OUTPUT), help="Path to write schedule JSON.")
    parser.add_argument("--debug-html", default=str(DEFAULT_DEBUG_HTML), help="Path to write debug HTML on failure.")
    parser.add_argument("--semester", default=os.environ.get("RBRU_SEMESTER", ""), help="Semester to select, e.g. 1, 2, 3.")
    parser.add_argument("--headless", action="store_true", default=env_bool("RBRU_HEADLESS", True), help="Run browser headless.")
    parser.add_argument("--show-browser", action="store_false", dest="headless", help="Show browser for local debugging.")
    args = parser.parse_args()

    username = os.environ.get("RBRU_USERNAME", "").strip()
    password = os.environ.get("RBRU_PASSWORD", "").strip()
    if not username or not password:
        raise SystemExit("Missing RBRU_USERNAME or RBRU_PASSWORD environment variable.")

    html_content, source_url = await fetch_schedule_html(
        username=username,
        password=password,
        semester=args.semester,
        headless=args.headless,
    )
    scraped_data = parse_schedule_html(html_content, source_url=source_url)

    if not scraped_data["courses"] and not scraped_data["exams"]:
        debug_path = Path(args.debug_html)
        debug_path.write_text(html_content, encoding="utf-8")
        raise SystemExit(f"No schedule or exam data found. Debug HTML saved to {debug_path}")

    output_path = Path(args.output)
    output_path.write_text(
        json.dumps(scraped_data, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(
        f"Updated {output_path}: "
        f"{len(scraped_data['courses'])} courses, {len(scraped_data['exams'])} exams"
    )


if __name__ == "__main__":
    asyncio.run(main())
