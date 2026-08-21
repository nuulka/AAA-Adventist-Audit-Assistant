# Revizor Asszisztens 1.0 — AI Context

## Project Overview
OTS bank reconciliation automation tool. Matches bank CSV statements against OTS accounting records.

## Tech Stack
- PHP 8.3 procedural (no composer/PDO/ORM/MVC)
- MySQL 8.0.30 (two DBs: `revizor_db` + `ots` read-only) — NO `IF NOT EXISTS` for ALTER TABLE
- `bootstrap@5.3.0` UI (except WebIX pages use inline CSS)
- Locally hosted on Laragon (Windows)

## Key Rules
- Only edit `D:\laragon\www\revizor\` (OTS is read-only at `D:\laragon\www\ots\`)
- Native MySQLi, no abstractions
- `CASH_DOCUMENT_NUMBER` is unreliable — use `ots_record_id` for joins
- Expense sign: `IF(TYPE IN (7,9,20), -1 * AMOUNT, AMOUNT)` — also TYPE 9 is expense
- For matching: group by RECORD_ID, use `ABS(SUM(adjusted_amount) - ?) < 0.01`
- Bank amounts are signed (positive=income, negative=expense)
- TET accounts: `church_id = 0 → continue` on upload
- URLs: `localhost/revizor/`, `localhost/ots/`
- MySQL 8.0.30 does NOT support `ALTER TABLE ADD COLUMN IF NOT EXISTS` — use `SHOW COLUMNS FROM ... LIKE` instead

## Database Structure
- **OTS DB**: `TRANSACTIONS` (434k records, 73 churches), `churches`, `PERSONS`, `NAMES_OF_TRANSACTION`, `TRANSACTION_TYPE`, `funds`, `USERS`, `transfers_to_conference`, `church_bank_accounts`, `provider_keywords`
- **Revizor DB**: `bank_reconciliation`, `bank_reconciliation_items`, `church_bank_accounts`, `provider_keywords`, `custom_patterns`, `audit_checklist`

## Session
- OTS timeout: 10 min, Revizor: 20 min
- Shared PHP session (same `PHPSESSID` cookie)
- AJAX session ping via `session_ping.php`
- `session_write_close()` before long-running auto_match to avoid lock contention

## Key Files
- `index.php` — Dashboard/menu
- `reconciliation.php` — Main reconciliation UI (table, navbar modals, auto-match, document check embed, combined details modal)
- `upload.php` — CSV upload + progressive matching (passes: 0,3,6,12,35,60,text)
- `search.php` — Server-side transaction search with reverse OTS→Bank lookup
- `document_check.php` — Standalone document audit checklist page
- `document_check_get.php` — AJAX JSON endpoint for audit data by bank_reconciliation_id
- `login.php` / `logout.php` — Auth
- `help.php` — User guide
- `session_ping.php` — AJAX session extender
- `all_transactions/all_transactions_multi.php` — WebIX OTS query tool
- `print.html` — Print view

## Matching Logic
- **Progressive passes**: [0, 3, 6, 12, 35, 60] days + text pass
  - Pass 0: exact date match, 40-day dedup, write-protected with "[Auto: 100% egyezés, 0 nap]"
  - Passes 3–60: widening date window
- **Text pass**: keyword scoring (shared words +1 max 3, provider_keywords +2, custom_patterns +3), min score 2
- **transfers_to_conference**: multi-item grouping by `conference_id` via `bank_reconciliation_items`, matched with `ABS(?)` for signed amounts, ±45 day window
- **Expense type IDs**: 7, 9, 20 (negate in SUM)
- **Duplicate filter**: 40-day window, same amount + same desc prefix (80 chars)
- **OTS exclusion**: `RECORD_ID NOT IN (SELECT ots_record_id FROM bank_reconciliation UNION SELECT record_id FROM bank_reconciliation_items)`
- **Dátum sanity check**: progressive passes skip if date diff > 30 days; unmatched search popup allows up to 45 days (legacy), warns at 46–70 with confirmation
- **Date rule enforcement**: `is_bank_first` (bank ≤ OTS) checks both bank_desc AND bank_ext_name for rezsi/provider keywords (MVM, EON, villanyszámla, gáz, víz, etc.); `is_ots_first` (OTS ≤ bank) checks TET accounts + készpénz befizetés

## UI Features
- **Navbar**: Title row 1 (left), Kezdőlap + church autocomplete filter row 2 (left), right-side hamburger menu with all action buttons
- **Combined details modal**: Parallel view (bank left, OTS right), accordion OTS results, prev/next row navigation, multi-item matching, text-based aggregation search
- **Auto Match modal**: Progressive/custom/search modes, church scope checkbox, real-time progress, timer
- **Document check modal**: Embedded in reconciliation.php, 14-point audit checklist (same as document_check.php), binds to `bank_reconciliation.id`
- **Dynamic stats**: Status counter bubbles, hover tooltips with per-status counts

## Security
- CSRF token validation on all POST actions
- `require_church_access()` scope check on all church-scoped operations
- Admin-only actions gated via `requireAdminThen()` JS + server-side `is_admin()`

## Git
- Branch: `main`
- Remote: `origin/main`
- English filenames for new files
- Development helpers moved to `_dev/`

## Known Issues & Fixes
- **NO `ALTER TABLE ADD COLUMN IF NOT EXISTS`** in MySQL 8.0.30 — use `SHOW COLUMNS FROM` check instead
- **TDZ (Temporal Dead Zone)** in JS: `finishTimer` function used before `const` declaration — ensure functions are hoisted or declared before use
- **Session lock**: long-running auto_match blocks other AJAX — call `session_write_close()` before heavy loops
- **Progress file**: auto-match writes progress to `tmp/auto_match_progress_{church_id}.json` for real-time UI updates
- **Search.php**: `GROUP BY` + `HAVING` on aliased expression requires proper subquery aliasing
- **Production OTS DB**: `transfers_to_conference` table exists but verify with `$has_tc_table` guard (case-sensitive table names on Linux: `TRANSFERS_TO_CONFERENCE` not `transfers_to_conference`)
- **bind_param type string**: length MUST match number of bind variables exactly — PHP 8.1 throws `mysqli_sql_exception` on mismatch
- **JSON columns**: MySQL JSON type strict on production — use TEXT for flexible columns (e.g. `auto_match_logs.details`)
