# Teaching schedule sync

Open GitHub → Actions → **🤖 RBRU Schedule Auto-Sync** → **Run workflow**. Use `main`. Leave `semester` empty to use REG's current semester; enter a semester only when its selector is available on the REG page. The workflow also runs daily at 19:00 UTC (02:00 Thailand time).

Repository secrets `RBRU_USERNAME` and `RBRU_PASSWORD` hold the existing REG account credentials. Do not put credentials in source files, logs, or screenshots.

The bot supports the current REG login form and teaching-summary table, plus the older page format. A login failure, missing/invalid teaching rows, or failed git push must fail the workflow. The existing `schedule.json` is replaced only after validating a complete result. Successful data includes `meta.updated_at` in Bangkok time and the source URL.

The live Courses page and repository version use `assets/js/schedule-source.js`: fetch both the university copy and GitHub `main`, ignore unsuccessful or malformed responses, and select the valid result with the latest `meta.updated_at`. If one source is unavailable, use the other. Thus a successful Actions update reaches the university page without an FTP upload of every new schedule. The university HTML and loader still need deployment when their code changes.

Local checks:

```sh
python -m unittest discover -s scripts -p 'test_schedule_sync.py' -v
node scripts/test_schedule_source.cjs
```

When diagnosing a green run that did not change the schedule, inspect **Run Schedule Bot**, then **Commit and Push Changes**, and compare `schedule.json` metadata. The former script caught selector errors and exited successfully; it did not produce new data. Old copies without metadata should not replace a dated valid schedule.

Verified on 2026-11-02: the complete GitHub Actions run logged in to REG, parsed five current teaching rows, validated them, and committed schedule.json. Regression checks cover the optional eighth link column and day rowspans. The university Courses page and loader were deployed separately, preserving the existing course and lesson content.
