import asyncio
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import AsyncMock, patch

import update_schedule_bot as bot


SUMMARY = '''<table><tr><th>วัน</th><th>เวลา</th><th>รหัสวิชา</th><th>หน่วยกิต</th><th>ชื่อวิชา</th><th>SEC</th><th>ห้อง</th></tr>
<tr><td rowspan="2">อังคาร</td><td>08:30 - 12:30</td><td>9061021</td><td>3</td><td>โครงสร้างข้อมูลและอัลกอริทึม<br>Data Structures</td><td>1</td><td>434</td></tr>
<tr><td>13:30 - 17:30</td><td>9061021</td><td>3</td><td>โครงสร้างข้อมูลและอัลกอริทึม</td><td>2</td><td>435</td></tr></table>'''


class ScheduleTests(unittest.TestCase):
    def test_current_summary_and_rowspan(self):
        data = bot.parse_schedule_html(SUMMARY, "https://reg.rbru.ac.th/registrar/teachttable")
        bot.validate_schedule(data)
        first, second = data["courses"]
        self.assertEqual(first["course_name"], "โครงสร้างข้อมูลและอัลกอริทึม")
        self.assertEqual((first["section"], first["room"]), ("1", "434"))
        self.assertEqual((second["day"], second["time"], second["section"]), ("อังคาร", "13:30 - 17:30", "2"))
        self.assertTrue(data["meta"]["updated_at"].endswith("+07:00"))

    def test_invalid_data_keeps_previous_file(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "schedule.json"
            path.write_text("previous good schedule")
            for data in [bot.parse_schedule_html("<form>เข้าสู่ระบบ</form>"), {"courses": [], "exams": [{}]}]:
                with self.assertRaises(ValueError):
                    bot.save_schedule(data, path)
                self.assertEqual(path.read_text(), "previous good schedule")

    def test_rejects_legacy_shifted_course_columns(self):
        data = bot.parse_schedule_html(SUMMARY)
        data["courses"][0]["course_name"] = ", 1"
        with self.assertRaises(ValueError):
            bot.validate_schedule(data)

    def test_valid_save_replaces_complete_file(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "schedule.json"
            data = bot.parse_schedule_html(SUMMARY)
            bot.save_schedule(data, path)
            self.assertEqual(json.loads(path.read_text()), data)
            self.assertFalse(path.with_suffix(".json.tmp").exists())

    def test_login_failure_is_not_reported_as_success(self):
        with patch.dict(bot.os.environ, {"RBRU_USERNAME": "test", "RBRU_PASSWORD": "test"}), patch("sys.argv", ["bot"]), patch.object(bot, "fetch_schedule_html", new=AsyncMock(side_effect=RuntimeError("Sign-in failed"))):
            with self.assertRaisesRegex(RuntimeError, "Sign-in failed"):
                asyncio.run(bot.main())

    def test_missing_credentials_fail_before_browser(self):
        with patch.dict(bot.os.environ, {}, clear=True), patch("sys.argv", ["bot"]), patch.object(bot, "fetch_schedule_html", new=AsyncMock()) as fetch:
            with self.assertRaises(SystemExit):
                asyncio.run(bot.main())
            fetch.assert_not_called()


if __name__ == "__main__":
    unittest.main()
