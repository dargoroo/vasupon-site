# AI Exercise Workflow

This site can host self-checking HTML worksheets without a database or login.

## Teacher Flow

1. Ask AI to generate a complete worksheet as one HTML file.
2. Make sure the worksheet has its own JavaScript answer checker.
3. Save the file in `ai_exercises/worksheets/`.
4. Add a new item to `ai_exercises/manifest.json`.
5. Upload `ai_exercises.html`, `ai_exercises/manifest.json`, and the worksheet file to the website.

## Student Flow

1. Open `courses.html`.
2. Click the Artificial Intelligence course card.
3. Choose a chapter and worksheet.
4. Complete the worksheet in the browser and use the built-in checker.

## Notes

- This first version does not store student scores on the server.
- It is ideal for practice, in-class activities, and low-friction self-checking assignments.
- For graded submissions later, connect the worksheet hub to the existing app platform at `https://cpe.rbru.ac.th/apps/`.
