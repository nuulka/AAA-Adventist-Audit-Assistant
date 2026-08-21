# AGENTS.md — Revizor Project Conventions

## Project Overview
Church audit application for Seventh-day Adventist churches in Hungary. PHP + MySQL + vanilla JS.

## Database

### OTS Database (external, READ-ONLY)
- Table names are ALWAYS UPPERCASE: `TRANSACTIONS`, `PERSONS`, `CHURCHES`, `NAMES_OF_TRANSACTION`, `TRANSACTION_TYPE`, `FUNDS`, `USERS`, `ROLES`, `TRANSFERS_TO_CONFERENCE`
- Connection: `$ots_db` (defined in `reconciliation.php` line ~45)
- Connection params from `config/app.php` — user `ots_ro`, password `ots_2024_ro`
- MySQL CLI: `D:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe`
- Credentials: user `revizor_rw`, password `revizor_2024_rw`, host `127.0.0.1`, db `revizor_db`

### Revizor Database (local, READ/WRITE)
- Table names are lowercase: `bank_reconciliation`, `bank_reconciliation_items`, `auto_match_logs`, `bank_keywords`, `churches`, etc.
- Connection: `$conn` (mysqli)

### Critical SQL Rules
- **NEVER** use lowercase for OTS table names — MySQL on Linux is case-sensitive (`lower_case_table_names=0`)
- Always use `$has_tc_table` guard before any `TRANSFERS_TO_CONFERENCE` query (production OTS DB may or may not have it)
- `$exp_types` from `constant.php`: TYPE 7,9,20 → `adjusted_amount = -1*AMOUNT`
- `adjusted_amount` formula: `IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)`
- `bank_reconciliation.ots_record_id` is INT; TC records stored as negative (e.g., -6493)
- `bank_reconciliation_items` stores OTS record pairings (separate from `bank_reconciliation.ots_record_id`)

## Project Scope
- **User works ONLY with church_id=43 (Miskolc A)**. All other church data is irrelevant.
- "Készpénz befizetés" text is in `bank_ext_name` field (NOT `bank_desc`)
- Church 43 own account: K&H `104027645049575053561009`
- TET conference accounts: OTP `1178400922224138`, K&H `104003395049575053561009`

## Bank-OTS Date Rule System

| Oldal | Kategória | Azonosítás | Dátum szabály |
|---|---|---|---|
| Kiadás (-) | a) Bank költségek | beszedési díj, pénztári jutalék, átutalás jutalék, könyvelési díj | bank ≤ OTS |
| Kiadás (-) | b) Rezsi/szolgáltatói | rezsi, szolgáltatási, csoportos beszedés | bank ≤ OTS |
| Kiadás (-) | c) AT havi zárás (TET) | kedvezményezett = TET számla | OTS ≤ bank |
| Kiadás (-) | d) Vásárlások | — | nincs szabály |
| Kiadás (-) | e) Egyéb | — | nincs szabály |
| Bevétel (+) | f) Tized/adakozás | TIZED, T\d+A\d+, adomány, adak* | bank ≤ OTS |
| Bevétel (+) | g) Készpénz befizetés | bank_ext_name contains "Készpénz befizetés" | OTS ≤ bank |
| Bevétel (+) | h) Egyéb | — | nincs szabály |
| Bevétel (+) | k) Kamat | kamat | bank ≤ OTS |

### Date Detection Keywords in Code
**Bank-first (bank ≤ OTS)**: `$is_bank_first` checks both `$b_desc_lower` (bank_desc) AND `$b_name_lower` (bank_ext_name):
- a) Költségek: `beszedés`, `beszed`, `jutalék`, `kezelési`, `szolgáltatási`, `könyvelés`
- b) Rezsi: `villanys`, `gáz/gaz`, `víz`, `fűtés/futes`, `rezsi`, `szolgáltat/szolgaltat`, `csop. beszed`, `mvm`, `eon`, `nkm`, `főgáz/fogaz`, `telem`, `nhkv`, `mivíz`, `alföld` (regex)
- f) Tized/adakozás: `tized`, `adomány`, `adak`
- k) Kamat: `kamat`

**OTS-first (OTS ≤ bank)**: `$is_ots_first` checks:
- c) AT havi zárás: `$bank_amount < 0 && bank_ext_acc IN TET accounts`
- g) Készpénz befizetés: `bank_desc OR bank_ext_name contains "készpénz befizetés"`

## Code Conventions
- PHP 8.1+ — always check for `mysqli_sql_exception` on missing tables (returns exception, not false)
- Always use `ob_start()`/`ob_end_clean()` in AJAX handlers to capture PHP warnings
- Always wrap AJAX logic in `try/catch(Throwable)` returning JSON error on exception
- All `r.json()` calls in JS must use `r.text()` + try-catch `JSON.parse()` for resilience
- Session expiry: POST → return `401 + {"status":"SESSION_EXPIRED"}` JSON, GET → `Location:` redirect
- MySQL `bind_param` type string length MUST match the number of bind variables exactly

## Git
- Remote: `https://github.com/nuulka/AAA-Adventist-Audit-Assistant.git` (origin/main)
- Commit style: short imperative messages, e.g. `fix: bind_param type string mismatch`
