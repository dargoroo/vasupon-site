# Grader Web Exercises

This note describes the first HTML/CSS/Canvas exercise mode for `grader_app`.

## What It Supports

- Course -> Session -> Problem organization already exists in `grader_app`.
- The AI draft endpoint can generate `html` or `web` problems when the teacher sets the draft language to `html` or `web`.
- The worker can grade static web checks without storing server secrets in Git.

## Test Case Format

For `html` and `web` problems, each test case stores an empty `stdin_text` and a JSON string in `expected_stdout`.

```json
{
  "checks": [
    {
      "type": "tag_exists",
      "value": "canvas",
      "message": "ต้องมี canvas"
    },
    {
      "type": "selector_exists",
      "value": "#scene",
      "message": "ต้องมี id scene"
    },
    {
      "type": "css_contains",
      "value": "display: flex",
      "message": "ต้องจัด layout ด้วย flex"
    },
    {
      "type": "js_contains",
      "value": "getContext",
      "message": "ต้องเรียกใช้ CanvasRenderingContext2D"
    },
    {
      "type": "regex",
      "value": "fillRect\\s*\\(",
      "message": "ต้องวาดสี่เหลี่ยมด้วย fillRect"
    }
  ]
}
```

Supported check types:

- `contains_text`
- `not_contains_text`
- `tag_exists`
- `selector_exists`
- `attribute_equals`
- `css_contains`
- `js_contains`
- `canvas_uses`
- `regex`

## Limits

This mode is intentionally lightweight. It checks submitted HTML source semantically and statically; it does not render a browser, inspect computed CSS, or compare canvas pixels yet. For production visual grading, the next step is a Playwright-based worker profile that opens the HTML in a sandboxed browser and evaluates DOM/computed style/canvas output.

## Security Notes

- Keep real `config.php`, `.env*`, upload folders, SQLite files, and API keys out of Git.
- Use template files such as `config.vasupon-p.template.php` for deploy documentation.
- If credentials were shared in chat or committed accidentally, rotate them before publishing.
