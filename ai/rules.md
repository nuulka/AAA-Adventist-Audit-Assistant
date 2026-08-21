# Revizor Asszisztens 1.0 — AI Rules

## General
- Never edit files outside `D:\laragon\www\revizor\`
- Never write to OTS DB (read-only via `ots.*` prefix)
- Prefer prepared statements over `$conn->query()` for user input
- Add CSRF token protection to all state-changing POST actions
- Use `htmlspecialchars()` for user data in HTML output
- Always lint PHP with `php -l` after edits

## Code Style
- Procedural PHP with MySQLi, no classes/namespaces
- English variable names, Hungarian comments (existing convention)
- Use `intval()` for integer IDs from `$_GET`/`$_POST`
- UTF-8 everywhere (DB charset: `utf8mb4`, HTML: `charset=utf-8`)
- No Bootstrap on WebIX pages (inline CSS instead)

## File Conventions
- New files: English names (e.g., `search.php`, not `kereso.php`)
- Development helpers go to `_dev/`, not in root
- Temp files go to `tmp/`

## Matching Logic Reference
- Expense type IDs: 7, 9, 20 (negate in SUM)
- Progressive passes: [0, 3, 6, 12, 35, 60] days + text pass
- Text pass scoring: keywords (+2), shared words (+1, max 3), provider_keywords (+2), custom_patterns (+3)
- Duplicate filter: 40-day window, same amount + same desc prefix (80 chars)
- OTS records excluded via: `RECORD_ID NOT IN (SELECT ots_record_id FROM bank_reconciliation UNION SELECT record_id FROM bank_reconciliation_items)`

## Auto-Match Flow
1. Upload: ±5 day, exact amount, PERIOD_DIFF month check
2. Progressive pass 0 (0 day): exact date match, 40-day dedup
3. Pass 3–60: widening date window
4. Text pass: keyword scoring, min score 2

## Date Rule Enforcement (is_bank_first / is_ots_first)
- Checked in BOTH text pass and numeric passes (3,6,12,35,60)
- `is_bank_first`: checks bank_desc AND bank_ext_name for keywords (see below)
- `is_ots_first`: checks bank_amount < 0 AND bank_ext_acc IN TET accounts, OR készpénz befizetés in desc/ext_name
- bank_first violations: OTS date < bank date → SKIP
- ots_first violations: OTS date > bank date → SKIP
- **Bank-first keywords** (must match in bank_desc or bank_ext_name):
  - a) Költségek: `beszedés`, `beszed`, `jutalék`, `kezelési`, `szolgáltatási`, `könyvelés`
  - b) Rezsi: `villanys`, `gáz`, `víz`, `fűtés`, `rezsi`, `mvm`, `eon`, `nkm`, `főgáz`, `telem`, `nhkv`, `mivíz`, `alföld` (regex)
  - f) Tized/adakozás: `tized`, `adomány`, `adak`
  - k) Kamat: `kamat`

## Security Requirements
- Every POST handler validates `$_POST['csrf_token']` against `$_SESSION['csrf_token']`
- SQL parameters via `bind_param()`, never concatenated
- No password storage
- Session timeout check on every page load
