<?php
$common_audit_fields = ['date_filled','amount_ok','description_ok','signature_treasurer','signature_receiver','signature_authorizer','signature_auditor','signature_bookkeeper','signature_issuer','signature_payer','amount_in_words_ok','stamp_ok','receipt_number_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok'];
$bank_audit_fields = array_merge($common_audit_fields, ['invoice_ok','tithe_card_ok','bank_in_ots_ok']);
$cash_audit_fields = array_merge($common_audit_fields, ['invoice_ok','tithe_card_ok','description_ok_ots','decision_number_ok_ots']);
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../ots/constant.php';
if (session_status() != PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION[GN_LAST_ACTIVE] = time();
require_once __DIR__ . '/../ots/session_handler.php';
if (!isset($_SESSION[GC_LOGIN_COOKIE])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/lib/bootstrap.php';
$conn = get_revizor_conn();
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/session.php';
if (is_file(__DIR__ . '/lib/announcement.php')) {
    require_once __DIR__ . '/lib/announcement.php';
}
// ensure user context built
build_user_context_from_ots();
$accessible_church_ids = get_accessible_church_ids();
$session_remaining = ensure_revizor_session_timeout();
ensure_revizor_csrf_token();

// Csak revizor (SDA_L_AUDITOR) vagy admin (konferenciai szerep / szuperadmin) használhatja az ellenőrzőlistát
if (!is_admin() && !is_revizor()) {
    http_response_code(403);
    echo 'Nincs jogosultságod a bizonylat-ellenőrzéshez.';
    exit;
}

log_activity('page_view', ['page' => 'document_check']);

if (is_admin()) {
    if (isset($_GET['church_id'])) {
        $church_id = intval($_GET['church_id']);
        if ($church_id > 0) {
            set_selected_church_session($church_id);
        } else {
            unset($_SESSION['revizor_selected_church'], $_SESSION['revizor_selected_church_name']);
        }
    } else {
        $church_id = get_selected_church_id();
    }
} else {
    $church_id = require_selected_church('document_check.php');
}
if ($church_id > 0) {
    require_church_access($church_id);
}
$type = isset($_GET['type']) && $_GET['type'] === 'cash' ? 'cash' : 'bank';

// Költség típusok meghatározása (OTS kiadások, amiket negatív előjellel kell kezelni)
$exp_types = [];
if (defined('GN_TRANSACTION_TYPE_PAYMENT')) $exp_types[] = GN_TRANSACTION_TYPE_PAYMENT;
if (defined('GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE')) $exp_types[] = GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE;
if (defined('GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION')) $exp_types[] = GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION;
if (empty($exp_types)) {
    $ots_db_tmp = get_ots_conn();
    $tt_res = $ots_db_tmp->query("SELECT id FROM TRANSACTION_TYPE WHERE debit = 1");
    if ($tt_res) { while ($tt = $tt_res->fetch_assoc()) { $exp_types[] = $tt['id']; } }
}
if (empty($exp_types)) { $exp_types = [-1]; }
$exp_types_str = implode(',', array_map('intval', array_filter($exp_types, 'is_numeric')));
if (empty($exp_types_str)) { $exp_types_str = '-1'; }

// ots_cash_audit tábla létrehozása
$conn->query("CREATE TABLE IF NOT EXISTS ots_cash_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ots_record_id INT NOT NULL,
    church_id INT NOT NULL DEFAULT 0,
    inspector_name VARCHAR(100) DEFAULT '',
    checked_at DATETIME DEFAULT NULL,
    cash_voucher_ok TINYINT(1) DEFAULT 0,
    date_filled TINYINT(1) DEFAULT 0,
    amount_ok TINYINT(1) DEFAULT 0,
    description_ok TINYINT(1) DEFAULT 0,
    signature_treasurer TINYINT(1) DEFAULT 0,
    signature_receiver TINYINT(1) DEFAULT 0,
    signature_authorizer TINYINT(1) DEFAULT 0,
    signature_auditor TINYINT(1) DEFAULT 0,
    signature_bookkeeper TINYINT(1) DEFAULT 0,
    signature_issuer TINYINT(1) DEFAULT 0,
    signature_payer TINYINT(1) DEFAULT 0,
    amount_in_words_ok TINYINT(1) DEFAULT 0,
    stamp_ok TINYINT(1) DEFAULT 0,
    invoice_ok TINYINT(1) DEFAULT 0,
    tithe_card_ok TINYINT(1) DEFAULT 0,
    receipt_number_ok TINYINT(1) DEFAULT 0,
    decision_number_ok TINYINT(1) DEFAULT 0,
    description_ok_ots TINYINT(1) DEFAULT 0,
    decision_number_ok_ots TINYINT(1) DEFAULT 0,
    fund_designation_ok TINYINT(1) DEFAULT 0,
    supporting_doc_ok TINYINT(1) DEFAULT 0,
    notes TEXT DEFAULT NULL,
    UNIQUE KEY uk_ots_record (ots_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// ALTER for existing tables that lack the new columns
$cah_new_cols = ['signature_auditor','stamp_ok','signature_bookkeeper','signature_issuer','signature_payer','amount_in_words_ok','description_ok_ots','decision_number_ok_ots'];
foreach ($cah_new_cols as $cah_col) {
    $cah_col_res = $conn->query("SHOW COLUMNS FROM ots_cash_audit LIKE '" . $cah_col . "'");
    if (!$cah_col_res || $cah_col_res->num_rows === 0) {
        $conn->query("ALTER TABLE ots_cash_audit ADD COLUMN $cah_col TINYINT(1) DEFAULT 0");
    }
}
$ac_new_cols = ['signature_auditor','stamp_ok','tithe_source_asked','bank_stmt_ok'];
foreach ($ac_new_cols as $ac_col) {
    $ac_col_res = $conn->query("SHOW COLUMNS FROM audit_checklist LIKE '" . $ac_col . "'");
    if (!$ac_col_res || $ac_col_res->num_rows === 0) {
        $conn->query("ALTER TABLE audit_checklist ADD COLUMN $ac_col TINYINT(1) DEFAULT 0");
    }
}
function normalize_doccheck_date($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if ($dt instanceof DateTime && $dt->format('Y-m-d') === $value) {
        return $value;
    }
    return '';
}

$date_from = normalize_doccheck_date($_GET['date_from'] ?? '');
$date_to = normalize_doccheck_date($_GET['date_to'] ?? '');

// A vizsgált hónap megőrzése 1 hétig (session lejártáig is): ha a szűrőben
// megadjuk, elmentjük; ha nincs megadva, az utolsó beállítást használjuk.
if (isset($_GET['date_from']) || isset($_GET['date_to'])) {
    set_user_pref('dc_date_from', $date_from);
    set_user_pref('dc_date_to', $date_to);
} else {
    $saved_from = normalize_doccheck_date(get_user_pref('dc_date_from'));
    $saved_to = normalize_doccheck_date(get_user_pref('dc_date_to'));
    if ($saved_from !== '' || $saved_to !== '') {
        $date_from = $saved_from;
        $date_to = $saved_to;
    }
}
$amount_min = isset($_GET['amount_min']) && $_GET['amount_min'] !== '' ? floatval($_GET['amount_min']) : null;
$amount_max = isset($_GET['amount_max']) && $_GET['amount_max'] !== '' ? floatval($_GET['amount_max']) : null;
$direction = isset($_GET['direction']) && in_array($_GET['direction'], ['income', 'expense'], true) ? $_GET['direction'] : '';
$search_desc = isset($_GET['search_desc']) ? trim((string)$_GET['search_desc']) : '';
$f_notes = isset($_GET['notes']) ? (string)$_GET['notes'] : '';
if (!in_array($f_notes, ['', 'yes', 'no'], true)) { $f_notes = ''; }
$search_notes = isset($_GET['search_notes']) ? trim((string)$_GET['search_notes']) : '';
$pending_only = isset($_GET['pending']) && $_GET['pending'] === '1';

// Audit-állapot segédfüggvény: visszaadja a [t_audit_fields, ok_count, total_audit, is_expense, is_tithe] értékeket
function dc_row_audit_state($r, $type, $common_fields) {    $is_expense = (float)$r['bank_amount'] < 0;
    $is_tithe = (int)($r['ots_type'] ?? 0) === GN_TRANSACTION_TYPE_INCOME;
    $is_transfer = $type === 'cash' && (int)($r['is_transfer'] ?? 0) === 1;
    if ($type === 'bank') {
        $t_audit_fields = ['bank_stmt_ok', 'amount_ok', 'description_ok', 'bank_in_ots_ok', 'fund_designation_ok', 'supporting_doc_ok'];
        if ($is_expense) { $t_audit_fields[] = 'decision_number_ok'; $t_audit_fields[] = 'invoice_ok'; }
        if ((int)($r['tithe_ask'] ?? 0) === 1) { $t_audit_fields[] = 'tithe_source_asked'; }
    } else {
        if ($is_transfer) {
            // Belső átvezetés: a papír pénztárbizonylat nem releváns,
            // csak az OTS-oldali ellenőrzések számítanak a megfelelésbe.
            $t_audit_fields = ['amount_ok', 'description_ok_ots', 'receipt_number_ok', 'fund_designation_ok', 'decision_number_ok_ots'];
        } elseif ($is_tithe) {
            // Tizedcédula jellegű bevétel: a Tizedcédula blokk (3) + az OTS blokk (4) látható pipái
            $t_audit_fields = ['signature_auditor', 'signature_treasurer', 'amount_ok',
                'description_ok_ots', 'receipt_number_ok', 'fund_designation_ok', 'decision_number_ok_ots'];
        } else {
            $t_audit_fields = $common_fields;
            $t_audit_fields[] = 'description_ok_ots';
            $t_audit_fields[] = 'decision_number_ok_ots';
        }
    }
    $ok_count = 0;
    if (!empty($r['audit_id'])) {
        foreach ($t_audit_fields as $f) {
            if ((int)($r[$f] ?? 0) === 1) $ok_count++;
        }
    }
    return [$t_audit_fields, $ok_count, count($t_audit_fields), $is_expense, $is_tithe];
}

// Közlemény fallback készpénzes bevételnél: ha nincs megjeleníthető adat
// (névtelen kosár / tizedcédula nélküli tétel), a típus alapján adunk szöveget.
function dc_cash_desc_fallback($ots_type) {
    switch ((int)$ots_type) {
        case GN_TRANSACTION_TYPE_SABBATH_SCHOOL:
            return 'Szombatiskolai kosár';
        case GN_TRANSACTION_TYPE_SATURDAY_MORNING:
            return 'Szombat de. kosár';
        case GN_TRANSACTION_TYPE_SPECIAL_TARGET:
            return 'Adakozási naptár';
        case GN_TRANSACTION_TYPE_INCOME:
            return 'Tizedcédula';
        default:
            return '';
    }
}
function dc_display_desc($r, $type) {
    $desc = trim((string)($r['bank_desc'] ?? ''));
    if ($type === 'cash' && $desc === '' && (float)$r['bank_amount'] >= 0) {
        $fallback = dc_cash_desc_fallback($r['ots_type'] ?? 0);
        if ($fallback !== '') { $desc = $fallback; }
    }
    return $desc === '' ? '-' : mb_substr($desc, 0, 60);
}

// Belső átvezetés felismerése a megnevezés alapján: ilyen tételről nem készül
// kiadási/bevételi pénztárbizonylat (csak alapok közötti átcsoportosítás).
function dc_is_transfer($desc) {
    $d = mb_strtolower(trim((string)$desc));
    if ($d === '') { return false; }
    return (bool)preg_match('/(átvezet|alaphoz|alapra|alapba|alapokba)/u', $d);
}

// AJAX: audit checklist mentése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_audit') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']); exit;
    }
    $bank_rec_id = intval($_POST['bank_reconciliation_id'] ?? 0);
    if ($bank_rec_id <= 0) { echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó ID']); exit; }
    // scope check - use prepared
    $stmt = $conn->prepare("SELECT church_id FROM bank_reconciliation WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $bank_rec_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc() ?? null;
        require_church_access(intval($row['church_id'] ?? 0));
    } else {
        require_church_access(0); // will fail
    }
    $fields = ['cash_voucher_ok','date_filled','amount_ok','description_ok','signature_treasurer','signature_receiver','signature_authorizer','signature_auditor','stamp_ok','invoice_ok','tithe_card_ok','receipt_number_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok','bank_in_ots_ok','tithe_source_asked','bank_stmt_ok'];
    $inspector = mb_substr(trim((string)($_POST['inspector_name'] ?? $_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen')), 0, 100, 'UTF-8');
    $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 1000, 'UTF-8');
    $checked_at = date('Y-m-d H:i:s');
    $field_placeholders = implode(',', array_fill(0, count($fields), '?'));
    $set_placeholders = implode(',', array_map(function($f) { return "$f = VALUES($f)"; }, $fields));
    $sql = "INSERT INTO audit_checklist (bank_reconciliation_id, inspector_name, checked_at, " . implode(',', $fields) . ", notes)
            VALUES (?, ?, ?, $field_placeholders, ?)
            ON DUPLICATE KEY UPDATE inspector_name = VALUES(inspector_name), checked_at = VALUES(checked_at), notes = VALUES(notes), $set_placeholders";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = str_repeat('i', 1) . 'ss' . str_repeat('i', count($fields)) . 's';
        $params = [$bank_rec_id, $inspector, $checked_at];
        foreach ($fields as $f) {
            $params[] = isset($_POST[$f]) && $_POST[$f] === '1' ? 1 : 0;
        }
        $params[] = $notes;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
    }
    log_activity('audit_save', ['bank_reconciliation_id' => $bank_rec_id, 'inspector' => $inspector]);
    echo json_encode(['status' => 'OK', 'message' => 'Ellenőrzési lista mentve.']);
    exit;
}

// AJAX: készpénz audit checklist mentése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_cash_audit') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']); exit;
    }
    $ots_record_id = intval($_POST['ots_record_id'] ?? 0);
    if ($ots_record_id <= 0) { echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó ID']); exit; }
    // scope check
    $ots_db_tmp2 = get_ots_conn();
    $stmt_scope = $ots_db_tmp2->prepare("SELECT CHURCH_ID FROM TRANSACTIONS WHERE RECORD_ID = ?");
    if ($stmt_scope) {
        $stmt_scope->bind_param('i', $ots_record_id);
        $stmt_scope->execute();
        $scope_res = $stmt_scope->get_result();
        $scope_row = $scope_res->fetch_assoc() ?? null;
        require_church_access(intval($scope_row['CHURCH_ID'] ?? 0));
    } else {
        require_church_access(0);
    }
    $caf_fields = ['date_filled','amount_ok','description_ok','description_ok_ots','signature_treasurer','signature_receiver','signature_authorizer','signature_auditor','signature_bookkeeper','signature_issuer','signature_payer','amount_in_words_ok','stamp_ok','invoice_ok','tithe_card_ok','receipt_number_ok','decision_number_ok','decision_number_ok_ots','fund_designation_ok','supporting_doc_ok'];
    $inspector = mb_substr(trim((string)($_POST['inspector_name'] ?? $_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen')), 0, 100, 'UTF-8');
    $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 1000, 'UTF-8');
    $checked_at = date('Y-m-d H:i:s');
    $church_id_ca = intval($scope_row['CHURCH_ID'] ?? 0);
    $field_placeholders = implode(',', array_fill(0, count($caf_fields), '?'));
    $set_placeholders = implode(',', array_map(function($f) { return "$f = VALUES($f)"; }, $caf_fields));
    $sql = "INSERT INTO ots_cash_audit (ots_record_id, church_id, inspector_name, checked_at, " . implode(',', $caf_fields) . ", notes)
            VALUES (?, ?, ?, ?, $field_placeholders, ?)
            ON DUPLICATE KEY UPDATE church_id = VALUES(church_id), inspector_name = VALUES(inspector_name), checked_at = VALUES(checked_at), notes = VALUES(notes), $set_placeholders";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = 'iiss' . str_repeat('i', count($caf_fields)) . 's';
        $params = [$ots_record_id, $church_id_ca, $inspector, $checked_at];
        foreach ($caf_fields as $f) {
            $params[] = isset($_POST[$f]) && $_POST[$f] === '1' ? 1 : 0;
        }
        $params[] = $notes;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
    }
    log_activity('audit_save', ['ots_cash_audit' => $ots_record_id, 'inspector' => $inspector]);
    echo json_encode(['status' => 'OK', 'message' => 'Készpénz ellenőrzési lista mentve.']);
    exit;
}

// Gyülekezet lista (csak adminoknak a dropdown-hoz)
$churches = [];
$ots_db = get_ots_conn();
if (is_admin()) {
    $c_res = $ots_db->query("SELECT id, name FROM CHURCHES WHERE name IS NOT NULL AND name != '' ORDER BY name ASC");
    if ($c_res && $c_res->num_rows > 0) { 
        while ($c = $c_res->fetch_assoc()) { $churches[] = $c; }
    } else {
        $cfg = load_app_config();
        if (!empty($cfg['churches']) && is_array($cfg['churches'])) {
            foreach ($cfg['churches'] as $id => $name) {
                $churches[] = ['id' => $id, 'name' => $name];
            }
        }
    }
} elseif ($church_id > 0) {
    // Nem admin: az aktuális gyülekezet nevét betöltjük a megjelenítéshez
    $cfg = load_app_config();
    if (!empty($cfg['churches'][$church_id])) {
        $churches[] = ['id' => $church_id, 'name' => $cfg['churches'][$church_id]];
    } else {
        $c_res = $ots_db->prepare("SELECT id, name FROM CHURCHES WHERE id = ?");
        if ($c_res) {
            $c_res->bind_param('i', $church_id);
            $c_res->execute();
            $c_r = $c_res->get_result();
            if ($c_r) { while ($c = $c_r->fetch_assoc()) { $churches[] = $c; } }
        }
    }
}

// Church name map a már betöltött churches tömbből
$dc_church_names = [];
foreach ($churches as $dc_ch) {
    $dc_church_names[$dc_ch['id']] = $dc_ch['name'];
}

$rows = [];
$total_count = 0;
$checked_count = 0;

if ($type === 'cash') {
    // Készpénz tételek lekérdezése OTS-ből
    $cash_clauses = ["T.VIA_BANK = 0"];
    $cash_params = [];
    $cash_types = '';

    if ($church_id > 0) {
        $cash_clauses[] = 'T.CHURCH_ID = ?';
        $cash_params[] = $church_id;
        $cash_types .= 'i';
    } elseif (!is_admin()) {
        if (empty($accessible_church_ids)) {
            $cash_clauses[] = '1=0';
        } else {
            append_int_in_clause($cash_clauses, $cash_params, $cash_types, 'T.CHURCH_ID', $accessible_church_ids);
        }
    }

    if ($date_from) { $cash_clauses[] = 'T.DATETIME >= ?'; $cash_params[] = $date_from; $cash_types .= 's'; }
    if ($date_to) { $cash_clauses[] = 'T.DATETIME <= ?'; $cash_params[] = $date_to . ' 23:59:59'; $cash_types .= 's'; }

    $adj_sql = "IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)";
    $desc_sql = "TRIM(CONCAT(IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''), ' ', IFNULL(nt1.NAME, ''), ' ', IFNULL(nt2.NAME, '')))";
    if ($amount_min !== null) { $cash_clauses[] = "ABS($adj_sql) >= ?"; $cash_params[] = $amount_min; $cash_types .= 'd'; }
    if ($amount_max !== null) { $cash_clauses[] = "ABS($adj_sql) <= ?"; $cash_params[] = $amount_max; $cash_types .= 'd'; }
    if ($direction === 'income') { $cash_clauses[] = "$adj_sql >= 0"; }
    if ($direction === 'expense') { $cash_clauses[] = "$adj_sql < 0"; }
    if ($search_desc !== '') { $cash_clauses[] = "$desc_sql LIKE ?"; $cash_params[] = '%' . $search_desc . '%'; $cash_types .= 's'; }

    $cash_where = implode(' AND ', $cash_clauses);

    $cash_sql = "SELECT T.RECORD_ID, T.CHURCH_ID, T.TYPE AS ots_type, T.DATETIME AS bank_date, T.VIA_BANK,
                        $adj_sql AS bank_amount, T.CASH_DOCUMENT_NUMBER, T.FUND_ID,
                        $desc_sql AS bank_desc,
                        funds.NAME AS fund_name
                 FROM TRANSACTIONS T
                 LEFT JOIN PERSONS p ON T.PERSON_ID = p.id
                 LEFT JOIN NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
                 LEFT JOIN NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
                 LEFT JOIN FUNDS funds ON T.FUND_ID = funds.id
                 WHERE $cash_where
                 ORDER BY T.DATETIME DESC
                 LIMIT 2000";

    $cash_result = null;
    if (!empty($cash_params)) {
        $stmt = $ots_db->prepare($cash_sql);
        if ($stmt) { $stmt->bind_param($cash_types, ...$cash_params); $stmt->execute(); $cash_result = $stmt->get_result(); }
    } else {
        $cash_result = $ots_db->query($cash_sql);
    }

    $cash_rows = [];
    $record_ids = [];
    $raw_groups = [];
    if ($cash_result) {
        while ($r = $cash_result->fetch_assoc()) {
            $raw_groups[$r['RECORD_ID']][] = $r;
            $record_ids[] = $r['RECORD_ID'];
        }
    }
    // Egy RECORD_ID-hoz több TRANSACTIONS sor tartozhat (több pénzalap, eltérő összeg).
    // A listában egy sor jelenik meg: az összeg a csoport TELJES összege, hogy a listában
    // és a rákattintás utáni részletben mindig ugyanaz látszódjon (nem csak az egyik tétel).
    foreach ($raw_groups as $rid => $group) {
        $sum = 0.0;
        $funds = [];
        foreach ($group as $gr) {
            $sum += (float)$gr['bank_amount'];
            if (!empty($gr['fund_name']) && !in_array($gr['fund_name'], $funds, true)) { $funds[] = $gr['fund_name']; }
        }
        usort($group, function($a, $b) { return abs((float)$b['bank_amount']) <=> abs((float)$a['bank_amount']); });
        $r = $group[0];
        $r['id'] = $r['RECORD_ID'];
        $r['bank_amount'] = $sum;
        $r['amount_count'] = count($group);
        $r['_funds'] = $funds;
        if (trim((string)$r['bank_desc']) === '' && !empty($funds)) {
            $r['bank_desc'] = implode(', ', $funds);
        }
        $r['is_transfer'] = dc_is_transfer($r['bank_desc']) ? 1 : 0;
        $r['church_name'] = $dc_church_names[$r['CHURCH_ID']] ?? null;
        $r['status'] = '-';
        $cash_rows[$rid] = $r;
        $total_count++;
    }

    // Audit adatok betöltése
    if (!empty($record_ids)) {
        $id_ph = implode(',', array_fill(0, count($record_ids), '?'));
        $audit_types = str_repeat('i', count($record_ids));
        $audit_sql = "SELECT * FROM ots_cash_audit WHERE ots_record_id IN ($id_ph)";
        $stmt_audit = $conn->prepare($audit_sql);
        if ($stmt_audit) {
            $stmt_audit->bind_param($audit_types, ...$record_ids);
            $stmt_audit->execute();
            $audit_res = $stmt_audit->get_result();
            while ($a = $audit_res->fetch_assoc()) {
                $rid = $a['ots_record_id'];
                if (isset($cash_rows[$rid])) {
                    $cash_rows[$rid]['audit_id'] = $a['id'];
                    $cash_rows[$rid]['inspector_name'] = $a['inspector_name'];
                    $cash_rows[$rid]['checked_at'] = $a['checked_at'];
                    foreach ($cash_audit_fields as $f) {
                        $cash_rows[$rid][$f] = $a[$f];
                    }
                    $cash_rows[$rid]['notes'] = $a['notes'];
                    $checked_count++;
                }
            }
        }
    }

    $rows = array_values($cash_rows);

} else {
    // Banki tételek lekérdezése
    $clauses = ['br.church_id > 0'];
    $params = [];
    $types = '';
    if ($church_id > 0) {
        $clauses[] = 'br.church_id = ?';
        $params[] = $church_id;
        $types .= 'i';
    } elseif (!is_admin()) {
        if (empty($accessible_church_ids)) {
            $clauses[] = '1=0';
        } else {
            append_int_in_clause($clauses, $params, $types, 'br.church_id', $accessible_church_ids);
        }
    }
    if ($date_from) { $clauses[] = 'br.bank_date >= ?'; $params[] = $date_from; $types .= 's'; }
    if ($date_to) { $clauses[] = 'br.bank_date <= ?'; $params[] = $date_to; $types .= 's'; }
    if ($amount_min !== null) { $clauses[] = 'ABS(br.bank_amount) >= ?'; $params[] = $amount_min; $types .= 'd'; }
    if ($amount_max !== null) { $clauses[] = 'ABS(br.bank_amount) <= ?'; $params[] = $amount_max; $types .= 'd'; }
    if ($direction === 'income') { $clauses[] = 'br.bank_amount >= 0'; }
    if ($direction === 'expense') { $clauses[] = 'br.bank_amount < 0'; }
    if ($search_desc !== '') { $clauses[] = 'br.bank_desc LIKE ?'; $params[] = '%' . $search_desc . '%'; $types .= 's'; }
    $where_sql = implode(' AND ', $clauses);

    $sql = "SELECT br.*,
                   ac.id AS audit_id, ac.inspector_name, ac.checked_at,
                   ac.cash_voucher_ok, ac.date_filled, ac.amount_ok, ac.description_ok,
                   ac.signature_treasurer, ac.signature_receiver, ac.signature_authorizer,
                   ac.signature_auditor, ac.stamp_ok,
                   ac.invoice_ok, ac.tithe_card_ok, ac.receipt_number_ok, ac.decision_number_ok,
                    ac.fund_designation_ok, ac.supporting_doc_ok, ac.bank_in_ots_ok, ac.tithe_source_asked, ac.bank_stmt_ok, ac.notes
            FROM bank_reconciliation br
            LEFT JOIN audit_checklist ac ON br.id = ac.bank_reconciliation_id
            WHERE $where_sql
            ORDER BY br.bank_date DESC
            LIMIT 2000";
    $result = null;
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) { $stmt->bind_param($types, ...$params); $stmt->execute(); $result = $stmt->get_result(); }
    } else {
        $result = $conn->query($sql);
    }

    // Tizedcédula-jelleg meghatározása külön, batch-elt lekérdezésekkel az OTS kapcsolaton.
    // Élesben az OTS adatbázis másik szerveren is lehet, ezért a revizor kapcsolaton nem használható `ots.` prefix.
    $br_t_has_ask = [];    // reconciliation_id => 1 ha van kért, nem online tized
    $br_t_has_tithe = [];  // reconciliation_id => 1 ha van tized-jellegű tétel
    $t_o_online_map = [];  // ots_record_id => max(VIA_ONLINE_GIVING)
    if ($result) {
        $rows_tmp = [];
        $rec_ids_all = [];
        $ots_ids_all = [];
        while ($r = $result->fetch_assoc()) {
            $r['church_name'] = $dc_church_names[$r['church_id']] ?? null;
            $rows_tmp[] = $r;
            $rec_ids_all[] = (int)$r['id'];
            if (!empty($r['ots_record_id'])) { $ots_ids_all[] = (int)$r['ots_record_id']; }
        }
        $tithe_type = GN_TRANSACTION_TYPE_INCOME;

        // reconciliation_id => record_id-k (revizor kapcsolat)
        $rec_records = [];
        if (!empty($rec_ids_all)) {
            $r_ph = implode(',', array_fill(0, count($rec_ids_all), '?'));
            $r_types = str_repeat('i', count($rec_ids_all));
            $stmt_items = $conn->prepare("SELECT reconciliation_id, record_id FROM bank_reconciliation_items WHERE reconciliation_id IN ($r_ph)");
            if ($stmt_items) {
                $stmt_items->bind_param($r_types, ...$rec_ids_all);
                $stmt_items->execute();
                $item_res = $stmt_items->get_result();
                while ($it = $item_res->fetch_assoc()) {
                    $rec_records[(int)$it['reconciliation_id']][] = (int)$it['record_id'];
                }
            }
        }
        // record_id-k tized-jellege (OTS kapcsolat)
        if (!empty($rec_records)) {
            $all_record_ids = [];
            foreach ($rec_records as $rids) { foreach ($rids as $rid) { $all_record_ids[$rid] = true; } }
            $all_record_ids = array_keys($all_record_ids);
            $o_ph = implode(',', array_fill(0, count($all_record_ids), '?'));
            $o_types = 'ii' . str_repeat('i', count($all_record_ids));
            $stmt_ots = $ots_db->prepare("SELECT RECORD_ID,
                        MAX(CASE WHEN TYPE = ? AND VIA_ONLINE_GIVING = 0 THEN 1 ELSE 0 END) AS has_ask,
                        MAX(CASE WHEN TYPE = ? THEN 1 ELSE 0 END) AS has_tithe
                    FROM TRANSACTIONS WHERE RECORD_ID IN ($o_ph) GROUP BY RECORD_ID");
            if ($stmt_ots) {
                $bind_params = [$tithe_type, $tithe_type];
                foreach ($all_record_ids as $rid) { $bind_params[] = $rid; }
                $stmt_ots->bind_param($o_types, ...$bind_params);
                $stmt_ots->execute();
                $t_res = $stmt_ots->get_result();
                while ($o = $t_res->fetch_assoc()) {
                    $rid = (int)$o['RECORD_ID'];
                    if ((int)$o['has_ask'] === 1) {
                        foreach ($rec_records as $rec_id => $rids) { if (in_array($rid, $rids, true)) { $br_t_has_ask[$rec_id] = 1; } }
                    }
                    if ((int)$o['has_tithe'] === 1) {
                        foreach ($rec_records as $rec_id => $rids) { if (in_array($rid, $rids, true)) { $br_t_has_tithe[$rec_id] = 1; } }
                    }
                }
            }
        }
        // ots_record_id online jellege (OTS kapcsolat)
        if (!empty($ots_ids_all)) {
            $o_ph = implode(',', array_fill(0, count($ots_ids_all), '?'));
            $o_types = str_repeat('i', count($ots_ids_all)) . 'i';
            $stmt_o = $ots_db->prepare("SELECT RECORD_ID, MAX(VIA_ONLINE_GIVING) AS v FROM TRANSACTIONS WHERE RECORD_ID IN ($o_ph) AND TYPE = ? GROUP BY RECORD_ID");
            if ($stmt_o) {
                $bind_params = [];
                foreach ($ots_ids_all as $rid) { $bind_params[] = $rid; }
                $bind_params[] = $tithe_type;
                $stmt_o->bind_param($o_types, ...$bind_params);
                $stmt_o->execute();
                $o_res = $stmt_o->get_result();
                while ($o = $o_res->fetch_assoc()) {
                    $t_o_online_map[(int)$o['RECORD_ID']] = $o['v'];
                }
            }
        }
        // Összevont könyvelés: több banki tétel → ugyanaz az OTS tétel
        $agg_map = [];
        if (!empty($ots_ids_all)) {
            $o_ph = implode(',', array_fill(0, count($ots_ids_all), '?'));
            $o_types = str_repeat('i', count($ots_ids_all));
            $stmt_agg = $conn->prepare("SELECT id, church_id, ots_record_id, bank_date, bank_amount, bank_desc FROM bank_reconciliation WHERE ots_record_id IN ($o_ph) ORDER BY bank_date, id");
            if ($stmt_agg) {
                $stmt_agg->bind_param($o_types, ...$ots_ids_all);
                $stmt_agg->execute();
                $agg_res = $stmt_agg->get_result();
                while ($ag = $agg_res->fetch_assoc()) {
                    $agg_map[(int)$ag['ots_record_id']][] = $ag;
                }
            }
        }
        foreach ($rows_tmp as $r) {
            $rec_id = (int)$r['id'];
            $ots_rid = !empty($r['ots_record_id']) ? (int)$r['ots_record_id'] : 0;
            $online_v = $ots_rid ? ($t_o_online_map[$ots_rid] ?? null) : null;
            $has_single_tithe = ($online_v !== null && $online_v !== '');
            $has_single_ask = $has_single_tithe && (int)$online_v !== 1;
            $has_t = (($br_t_has_tithe[$rec_id] ?? 0) === 1) || $has_single_tithe;
            $has_ask = (($br_t_has_ask[$rec_id] ?? 0) === 1) || $has_single_ask;
            $r['tithe_ask'] = ($has_t && $has_ask) ? 1 : 0;
            $r['agg_count'] = 0;
            $r['agg_group'] = [];
            $r['agg_title'] = '';
            if ($ots_rid && !empty($agg_map[$ots_rid]) && count($agg_map[$ots_rid]) > 1) {
                $r['agg_count'] = count($agg_map[$ots_rid]);
                $r['agg_group'] = $agg_map[$ots_rid];
                $r['agg_title'] = implode("\n", array_map(function($m) {
                    $l = ($m['bank_date'] ?? '?') . ' ' . number_format((float)$m['bank_amount'], 0, ',', ' ') . ' Ft';
                    if (!empty($m['bank_desc'])) { $l .= ' — ' . mb_substr($m['bank_desc'], 0, 80); }
                    return $l;
                }, $agg_map[$ots_rid]));
            }
            $rows[] = $r;
            $total_count++;
            if ($r['audit_id']) { $checked_count++; }
        }
    }
}

// Félbehagyott (nem 100%-osan kitöltött) ellenőrzések meghatározása
$pending_rows = [];
foreach ($rows as $r) {
    list($t_fields, $t_ok, $t_total) = dc_row_audit_state($r, $type, $common_audit_fields);
    if (!empty($r['audit_id']) && $t_ok < $t_total) {
        $pending_rows[] = $r;
    }
}
if ($pending_only) {
    $rows = $pending_rows;
    $total_count = count($rows);
    $checked_count = 0;
}

// Megjegyzés szűrő: van/nincs megjegyzés + szöveg keresés a megjegyzésben
if ($f_notes !== '' || $search_notes !== '') {
    $filtered = [];
    foreach ($rows as $r) {
        $n = trim((string)($r['notes'] ?? ''));
        $has_n = $n !== '';
        if ($f_notes === 'yes' && !$has_n) { continue; }
        if ($f_notes === 'no' && $has_n) { continue; }
        if ($search_notes !== '' && !(mb_stripos($n, $search_notes) !== false)) { continue; }
        $filtered[] = $r;
    }
    $rows = $filtered;
    $total_count = count($rows);
    $checked_count = 0;
    foreach ($rows as $r) { if (!empty($r['audit_id'])) { $checked_count++; } }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>🕵️ Revizor Asszisztens 1.0 – Bizonylat Ellenőrzés (<?= $type === 'cash' ? 'Készpénz' : 'Banki' ?>)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; padding: 15px; font-size: 14px; }
        .card { box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .stat-card { text-align: center; padding: 15px; border-radius: 8px; }
        .stat-card h3 { margin: 0; font-size: 28px; font-weight: 700; }
        .stat-card small { color: #6c757d; }
        .amount-clickable { cursor: pointer; }
        .amount-clickable:hover { background: #e2e6ea !important; }
        #docDetailModal { z-index: 1060; }
        #docDetailModal .modal-body { max-height: 75vh; overflow-y: auto; }
        #docDetailModal.offset-from-audit .modal-dialog { margin: 1.75rem 2rem 1.75rem auto; width: 46%; }
        #auditModal.stack-on-docdetail { z-index: 1070; }
        #auditModal.stack-on-docdetail .modal-dialog { margin: 1.75rem auto 1.75rem 2rem; width: 48%; }
        #docDetailModal .detail-col { padding: 15px; }
        #docDetailModal .detail-col h6 { border-bottom: 1px solid #dee2e6; padding-bottom: 6px; }
        #docDetailModal .detail-table th { width: 35%; white-space: nowrap; }
        #docDetailModal .detail-table td { word-break: break-word; }
        .dd-accordion-body { padding: 0 !important; }
        .dd-accordion-body table { margin: 0; }
        .checklist-item { padding: 6px 0; border-bottom: 1px solid #eee; }
        .checklist-item:last-child { border-bottom: none; }
        .progress-thin { height: 6px; margin-top: 4px; }
        .audit-yes { color: #198754; }
        .audit-no { color: #dc3545; }
        .audit-na { color: #6c757d; }
        .summary-badge { font-size: 11px; padding: 2px 6px; }
        .sort-asc::after { content: " ▲"; font-size: 10px; }
        .sort-desc::after { content: " ▼"; font-size: 10px; }
        th[onclick] { cursor: pointer; user-select: none; }
        th[onclick]:hover { background: #d0d5dd !important; }
        .table-responsive { overflow-x: visible; }
        .table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #fff;
            box-shadow: inset 0 -1px 0 #dee2e6;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <!-- Fejléc -->
    <div class="d-flex justify-content-between align-items-center mb-3 px-3 py-2 bg-white rounded border shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">🏠 Kezdőlap</a>
            <span class="fw-bold">🕵️ Revizor Asszisztens 1.0</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted">Bizonylat Ellenőrzés — <?= $type === 'cash' ? 'Készpénz' : 'Banki' ?></span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <a href="help.php" class="btn btn-outline-primary btn-sm">❓ Súgó</a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Kilépés</a>
        </div>
    </div>

    <!-- Statisztika -->
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="stat-card bg-light border">
                <h3 class="text-primary"><?= $total_count ?></h3>
                <small>Összes tétel</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-light border">
                <h3 class="text-success"><?= $checked_count ?></h3>
                <small>Ellenőrzött</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-light border">
                <h3 class="<?= $total_count > 0 ? ($checked_count / $total_count * 100 >= 80 ? 'text-success' : 'text-warning') : 'text-muted' ?>">
                    <?= $total_count > 0 ? number_format($checked_count / $total_count * 100, 0) : 0 ?>%
                </h3>
                <small>Készültség</small>
                <div class="progress progress-thin"><div class="progress-bar <?= $checked_count / max(1, $total_count) * 100 >= 80 ? 'bg-success' : 'bg-warning' ?>" style="width:<?= $total_count > 0 ? ($checked_count / $total_count * 100) : 0 ?>%"></div></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-light border">
                <h3 class="text-muted"><?= $total_count - $checked_count ?></h3>
                <small>Nem ellenőrzött</small>
            </div>
        </div>
    </div>

    <!-- Szűrők -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="<?= $type ?>">
                <div class="col-auto">
                    <label class="small mb-0">Gyülekezet</label>
                    <?php if (is_admin()): ?>
                    <select name="church_id" class="form-select form-select-sm" style="width:200px;">
                        <option value="0" <?= $church_id === 0 ? 'selected' : '' ?>>Összes</option>
                        <?php foreach ($churches as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $church_id === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name'] ?? '#' . $c['id']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="hidden" name="church_id" value="<?= $church_id ?>">
                    <span class="form-control form-control-sm bg-light" style="width:200px;display:inline-block;border:1px solid #dee2e6;padding:4px 8px;border-radius:4px;">
                        🏛 <?= htmlspecialchars($churches[0]['name'] ?? '#' . $church_id) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="col-auto">
                    <label class="small mb-0">Dátum tól</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= $date_from ?>" style="width:150px;">
                </div>
                <div class="col-auto">
                    <label class="small mb-0">Dátum ig</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= $date_to ?>" style="width:150px;">
                </div>
                <div class="col-auto">
                    <label class="small mb-0">Összeg min (Ft)</label>
                    <input type="number" name="amount_min" class="form-control form-control-sm" value="<?= $amount_min !== null ? $amount_min : '' ?>" style="width:130px;" step="1">
                </div>
                <div class="col-auto">
                    <label class="small mb-0">Összeg max (Ft)</label>
                    <input type="number" name="amount_max" class="form-control form-control-sm" value="<?= $amount_max !== null ? $amount_max : '' ?>" style="width:130px;" step="1">
                </div>
                <div class="col-auto">
                    <label class="small mb-0">Típus</label>
                    <select name="direction" class="form-select form-select-sm" style="width:120px;">
                        <option value="">Mind</option>
                        <option value="income" <?= $direction === 'income' ? 'selected' : '' ?>>Bevétel</option>
                        <option value="expense" <?= $direction === 'expense' ? 'selected' : '' ?>>Kiadás</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="small mb-0">Közlemény</label>
                    <input type="text" name="search_desc" class="form-control form-control-sm" value="<?= htmlspecialchars($search_desc) ?>" style="width:200px;" placeholder="Keresés a közleményben...">
                </div>
                <div class="col-auto">
                    <label class="small mb-0">Állapot</label>
                    <select name="pending" class="form-select form-select-sm" style="width:140px;">
                        <option value="">Mind</option>
                        <option value="1" <?= $pending_only ? 'selected' : '' ?>>⏸ Félbehagyott</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="small mb-0">Megjegyzés</label>
                    <select name="notes" class="form-select form-select-sm" style="width:150px;">
                        <option value="">Mind</option>
                        <option value="yes" <?= $f_notes === 'yes' ? 'selected' : '' ?>>📝 Van megjegyzés</option>
                        <option value="no" <?= $f_notes === 'no' ? 'selected' : '' ?>>Nincs megjegyzés</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="small mb-0">Megjegyzés szöveg</label>
                    <input type="text" name="search_notes" class="form-control form-control-sm" value="<?= htmlspecialchars($search_notes) ?>" style="width:180px;" placeholder="Keresés a megjegyzésben...">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">🔎 Szűrés</button>
                    <a href="document_check.php" class="btn btn-outline-secondary btn-sm">✕</a>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="btn-group btn-group-sm" role="group">
            <?php
                $bank_params = $_GET;
                unset($bank_params['type']);
                $cash_params = $_GET;
                $cash_params['type'] = 'cash';
            ?>
            <a href="document_check.php<?= !empty($bank_params) ? '?' . http_build_query($bank_params) : '' ?>" class="btn <?= $type === 'bank' ? 'btn-primary' : 'btn-outline-primary' ?>">🏦 Banki</a>
            <a href="document_check.php?<?= http_build_query($cash_params) ?>" class="btn <?= $type === 'cash' ? 'btn-primary' : 'btn-outline-primary' ?>">💰 Készpénz</a>
        </div>
        <small class="text-muted"><?= $total_count ?> találat (max. 2000 — szűkítsd a szűrőket ha többet keresel)</small>
    </div>

    <!-- Táblázat -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-bordered mb-0" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="string">Gyülekezet</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="date">Dátum</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="number" style="text-align:right;">Összeg</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="string">Közlemény</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="string">Státusz</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="string">Ellenőrizte</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="date">Ell. idő</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="number">Megfelelés</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="string">Megjegyzés</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $idx = 1; foreach ($rows as $r): 
                            list($t_audit_fields, $ok_count, $total_audit, $is_expense, $is_tithe) = dc_row_audit_state($r, $type, $common_audit_fields);
                        ?>
                        <tr class="<?= !empty($r['audit_id']) ? ($ok_count === $total_audit ? 'table-success' : 'table-warning') : '' ?>">
                            <td><?= $idx++ ?></td>
                            <td><?= htmlspecialchars($r['church_name'] ?? '-') ?></td>
                            <td><?= $r['bank_date'] ? mb_substr($r['bank_date'], 0, 10) : '-' ?></td>
                            <td style="text-align:right;" class="amount-clickable <?= (float)$r['bank_amount'] < 0 ? 'text-danger' : 'text-success' ?> fw-bold" data-sort-value="<?= (float)$r['bank_amount'] ?>" onclick="showDocDetail(<?= $r['id'] ?>, '<?= $type ?>')"><?= number_format((float)$r['bank_amount'], 0, ',', ' ') ?> Ft<?php if (!empty($r['amount_count']) && $r['amount_count'] > 1): ?> <span class="badge bg-secondary align-middle" title="Egy rekordhoz több pénzalap tartozik (részletek a kattintásnál)"><?= (int)$r['amount_count'] ?> tétel</span><?php endif; ?><?php if (!empty($r['agg_count']) && $r['agg_count'] > 1): ?> <span class="badge bg-info align-middle" title="<?= htmlspecialchars($r['agg_title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">🔗 <?= (int)$r['agg_count'] ?> banki → 1 OTS</span><?php endif; ?></td>
                            <td><?= htmlspecialchars(dc_display_desc($r, $type)) ?></td>
                            <td><span class="badge bg-<?= $type === 'cash' ? 'info' : ($r['status'] === 'OK' ? 'success' : ($r['status'] === 'UNCHECKED' ? 'secondary' : 'warning')) ?>"><?= $type === 'cash' ? 'KÉSZPÉNZ' : htmlspecialchars($r['status'] ?? 'UNCHECKED', ENT_QUOTES, 'UTF-8') ?></span><?php if ($type === 'cash' && !empty($r['is_transfer'])): ?> <span class="badge bg-secondary" title="Belső átvezetés a pénztáron belül – papír pénztárbizonylat nem készül">🔁 ÁTVEZETÉS</span><?php endif; ?></td>
                            <td><?= htmlspecialchars($r['inspector_name'] ?? '-') ?></td>
                            <td><?= !empty($r['checked_at']) ? substr($r['checked_at'], 0, 10) : '-' ?></td>
                            <td>
                                <?php if (!empty($r['audit_id'])): ?>
                                    <span class="fw-bold <?= $ok_count === $total_audit ? 'text-success' : 'text-warning' ?>" data-sort-value="<?= $total_audit > 0 ? $ok_count / $total_audit : 0 ?>"><?= $ok_count ?>/<?= $total_audit ?></span>
                                    <div class="progress progress-thin"><div class="progress-bar <?= $ok_count === $total_audit ? 'bg-success' : 'bg-warning' ?>" style="width:<?= $total_audit > 0 ? ($ok_count / $total_audit * 100) : 0 ?>%"></div></div>
                                <?php else: ?>
                                    <span class="text-muted" data-sort-value="-1">-</span>
                                <?php endif; ?>
                            </td>
                            <td data-sort-value="<?= trim((string)($r['notes'] ?? '')) !== '' ? '1' : '0' ?>">
                                <?php $dc_note = trim((string)($r['notes'] ?? '')); if ($dc_note !== ''): ?>
                                    <span class="text-primary small" title="<?= htmlspecialchars($dc_note, ENT_QUOTES, 'UTF-8') ?>">📝 <?= htmlspecialchars(mb_substr($dc_note, 0, 40)) ?><?= mb_strlen($dc_note) > 40 ? '…' : '' ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><button class="btn btn-outline-primary btn-sm py-0 px-1" onclick="openAudit(<?= $r['id'] ?>, '<?= $type ?>')" title="Ellenőrzés">🔍</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="11" class="text-center text-muted py-3">Nincs találat</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Részletes információ modal -->
<div class="modal fade" id="docDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="ddTitle">📄 Részletes információk</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="ddDoublePanel" class="row g-0" style="display:none;">
          <div class="col-md-6 detail-col border-end">
            <h6 class="text-primary"><strong>🏦 Banki adatok</strong></h6>
            <div id="ddBankContent"></div>
          </div>
          <div class="col-md-6 detail-col bg-light">
            <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-1">
              <h6 class="text-secondary mb-0"><strong>🧾 OTS könyvelési adatok</strong></h6>
              <button class="btn btn-outline-primary btn-sm py-0" type="button" id="ddAuditToggleBtn2" onclick="openDdAudit()">🔍 ELLENŐRZÉS</button>
            </div>
            <div id="ddOtsContent"></div>
          </div>
        </div>
        <div id="ddSinglePanel" class="row g-0" style="display:none;">
          <div class="col-12 detail-col bg-light">
            <div id="ddSabbathGroup"></div>
            <div id="ddAmountGroup"></div>
            <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-1">
              <h6 class="text-secondary mb-0"><strong>🧾 OTS könyvelési adatok</strong></h6>
              <button class="btn btn-outline-primary btn-sm py-0" type="button" id="ddAuditToggleBtn1" onclick="openDdAudit()">🔍 ELLENŐRZÉS</button>
            </div>
            <div id="ddOtsSingleContent"></div>
          </div>
        </div>
        <div id="ddLoading" class="text-center py-5">
          <span class="spinner-border spinner-border-sm me-2"></span>Adatok betöltése...
        </div>
        <div id="ddError" class="alert alert-danger text-center m-3" style="display:none;"></div>
      </div>
    </div>
  </div>
</div>

<!-- Audit modal -->
<div class="modal fade" id="auditModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <div class="w-100 d-flex align-items-center flex-wrap gap-2">
                    <h6 class="modal-title mb-0" id="auditModalTitle">📋 Ellenőrző lista</h6>
                    <div class="flex-grow-1"></div>
                    <div class="d-flex align-items-center gap-2">
                        <label class="small text-white-50 mb-0" for="auditInspectorName">Ellenőr neve:</label>
                        <input type="text" name="inspector_name" id="auditInspectorName" class="form-control form-control-sm" style="min-width:170px" value="<?= htmlspecialchars($_SESSION[GC_USER_FULL_NAME] ?? '') ?>">
                    </div>
                    <button class="btn btn-link btn-sm p-0 text-white text-decoration-none d-inline-flex align-items-center gap-1" type="button" data-bs-toggle="collapse" data-bs-target="#auditHelpText" aria-expanded="true" aria-controls="auditHelpText">
                        <span id="auditHelpArrow" style="transition: transform .2s; display:inline-block; transform: rotate(180deg);">▼</span> 💡 Használati útmutató
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body">
                <div id="auditBankInfo" class="mb-2 p-2 bg-light rounded small"></div>
                <div id="auditSabbathGroup"></div>
                <div id="auditAmountGroup"></div>
                <div id="auditAggGroup"></div>
                <div class="collapse show mb-2" id="auditHelpText">
                    <div class="p-2 bg-info-subtle text-dark rounded small d-flex justify-content-between align-items-center gap-2" style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#auditHelpText" aria-expanded="true" aria-controls="auditHelpText" title="Kattints a becsukáshoz">
                        <span class="text-primary">▲</span>
                        <span class="text-center">Pipával jelezzük, hogy rendben van. Tehát ha hibás, hiányzik, <b>akkor ne pipáld ki</b>. Akkor tegyél pipát, ha megvan, rendben van, vagy szükségtelen.</span>
                        <span class="text-primary">▲</span>
                    </div>
                </div>
                <form id="auditForm">
                    <input type="hidden" name="bank_reconciliation_id" id="auditBankRecId" value="">
                    <input type="hidden" name="ots_record_id" id="auditOtsRecId" value="">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="row">
                        <?php if ($type === 'bank') { ?>
                        <!-- 📄 Papír dokumentumok -->
                        <div class="col-md-4">
                            <div class="audit-panel paper-col">
                            <h6 class="border-bottom pb-1">📄 Papír dokumentumok</h6>
                            <?php
                            $bank_paper_items = [
                                'invoice_ok' => ['Számla megvan', 'bank_expense'],
                                'supporting_doc_ok' => ['Egyéb melléklet (szerződés, stb.)', 'bank_always'],
                            ];
                            foreach ($bank_paper_items as $key => $item): ?>
                            <div class="checklist-item" data-req="<?= $item[1] ?>">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" value="1" id="chk_<?= $key ?>">
                                    <label class="form-check-label" for="chk_<?= $key ?>"><?= $item[0] ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                        <!-- 🖥️ OTS-ben ellenőrizni -->
                        <div class="col-md-4">
                            <div class="audit-panel ots-col">
                            <h6 class="border-bottom pb-1">🖥️ OTS-ben ellenőrizni</h6>
                            <?php
                            $bank_ots_items = [
                                'bank_in_ots_ok' => ['Banki tétel OTS-ben szerepel', 'bank_always'],
                                'fund_designation_ok' => ['Pénzalap megjelölés helyes', 'bank_always'],
                                'decision_number_ok' => ['Határozat száma (ha releváns)', 'bank_expense'],
                            ];
                            foreach ($bank_ots_items as $key => $item): ?>
                            <div class="checklist-item" data-req="<?= $item[1] ?>">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" value="1" id="chk_<?= $key ?>">
                                    <label class="form-check-label" for="chk_<?= $key ?>"><?= $item[0] ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                        <!-- 🏦 Bankszámlakivonaton ellenőrizni -->
                        <div class="col-md-4">
                            <div class="audit-panel stmt-col">
                            <h6 class="border-bottom pb-1">🏦 Bankszámlakivonaton ellenőrizni</h6>
                            <?php
                            $bank_stmt_items = [
                                'bank_stmt_ok' => ['Banki kivonaton szerepel a tétel', 'bank_always'],
                                'amount_ok' => ['Összeg egyezik a banki kivonattal', 'bank_always'],
                                'description_ok' => ['Közlemény / megnevezés pontos', 'bank_always'],
                            ];
                            foreach ($bank_stmt_items as $key => $item): ?>
                            <div class="checklist-item" data-req="<?= $item[1] ?>">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" value="1" id="chk_<?= $key ?>">
                                    <label class="form-check-label" for="chk_<?= $key ?>"><?= $item[0] ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                        <?php } else { ?>
                        <!-- 📄 Papír bizonylaton ellenőrizni -->
                        <div class="col-md-6">
                            <div class="audit-panel paper-col" id="cashPaperBlock">
                            <h6 class="border-bottom pb-1">📄 Papír bizonylaton ellenőrizni</h6>                            <?php
                            $cash_paper_rows = [
                                ['date_filled', 'Dátum', 'common', 'stamp_ok', 'Bélyegző/gyülekezet neve', 'common'],
                                ['signature_issuer', 'Kiállító', 'common', 'signature_receiver', 'Befizető neve', 'common'],
                                ['signature_auditor', 'Ellenőr', 'common', 'amount_in_words_ok', 'Összeg számmal és betűvel is pontosan kitöltve', 'common'],
                                ['signature_authorizer', 'Utalványozó', 'common', 'description_ok', 'Megnevezés pontos', 'common'],
                                ['signature_bookkeeper', 'Könyvelő', 'common', 'decision_number_ok', 'Határozat száma (ha releváns)', 'common'],
                                ['signature_treasurer', 'Pénztáros', 'common', 'supporting_doc_ok', 'Mellékletek (tizedcédulák, számlák, szerződések, stb.)', 'common'],
                                [null, null, null, 'signature_payer', 'Befizető aláírása', 'common'],
                                [null, null, null, 'tithe_card_ok', 'Mellékletek (tizedcédula esetén)', 'tithe'],
                            ];
                            ?>
                            <div class="paper-sig-grid">
                                <?php foreach ($cash_paper_rows as $row): ?>
                                <div class="paper-sig-row">
                                    <?php if ($row[0]): ?>
                                    <div class="checklist-item" data-req="<?= $row[2] ?>">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="<?= $row[0] ?>" value="1" id="chk_<?= $row[0] ?>">
                                            <label class="form-check-label" for="chk_<?= $row[0] ?>" id="lbl_<?= $row[0] ?>"><?= $row[1] ?></label>
                                        </div>
                                    </div>
                                    <?php else: ?><div></div><?php endif; ?>
                                    <?php if ($row[3]): ?>
                                    <div class="checklist-item" data-req="<?= $row[5] ?>">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="<?= $row[3] ?>" value="1" id="chk_<?= $row[3] ?>">
                                            <label class="form-check-label" for="chk_<?= $row[3] ?>" id="lbl_<?= $row[3] ?>"><?= $row[4] ?></label>
                                        </div>
                                    </div>
                                    <?php else: ?><div></div><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="checklist-item" data-req="expense">
                                <small class="text-muted fst-italic">Számlán ellenőrizni a Vevő nevét címét, ahol csak az alábbi név és két cím egyike fogadható el: Hetednapi Adventista Egyház 4029 Debrecen Fazekas Mihály u.7. 2119 Pécel Ráday u. 12. Ha nem így szerepel, a megjegyzésben tüntesd fel!</small>
                            </div>
                            </div>
                            <!-- 📄 Tizedcédula (tizedcédula jellegű bevétel esetén) -->
                            <div class="audit-panel paper-col" id="titheCardBlock" style="display:none;">
                            <h6 class="border-bottom pb-1">📄 Tizedcédula</h6>
                            <div class="checklist-item" data-req="tithe">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="signature_auditor" value="1" id="chk_tithe_signature_auditor">
                                    <label class="form-check-label" for="chk_tithe_signature_auditor">Ellenőr aláírta</label>
                                </div>
                            </div>
                            <div class="checklist-item" data-req="tithe">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="signature_treasurer" value="1" id="chk_tithe_signature_treasurer">
                                    <label class="form-check-label" for="chk_tithe_signature_treasurer">Pénztáros aláírta</label>
                                </div>
                            </div>
                            <div class="checklist-item" data-req="tithe">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="amount_ok" value="1" id="chk_tithe_amount_ok">
                                    <label class="form-check-label" for="chk_tithe_amount_ok">Összeg egyezik az OTS-ben szereplővel</label>
                                </div>
                            </div>
                            </div>
                            <!-- 🔁 Belső átvezetés (átvezetésnél a papír pénztárbizonylat nem releváns) -->
                            <div class="audit-panel paper-col" id="transferInfoBlock" style="display:none;">
                            <h6 class="border-bottom pb-1">🔁 Belső átvezetés</h6>
                            <div class="alert alert-info mb-1 small">
                                Ez a tétel <strong>belső átvezetés</strong> a pénztáron belül (pl. alapok közötti átcsoportosítás),
                                ezért <strong>nem készül róla kiadási/bevételi pénztárbizonylat</strong>, és nincs partnernév.
                                A papír-bizonylat ellenőrzése <strong>nem releváns</strong>.
                            </div>
                            <div class="text-muted small">A releváns ellenőrzéseket a jobb oldali <strong>🖥️ OTS-ben ellenőrizni</strong>
                            panelen végezd el: összeg, megnevezés, pénzalap-megjelölés, valamint a határozat-szám, ha van.</div>
                            </div>
                        </div>
                        <!-- 🖥️ OTS-ben ellenőrizni -->
                        <div class="col-md-6">
                            <div class="audit-panel ots-col">
                            <h6 class="border-bottom pb-1">🖥️ OTS-ben ellenőrizni</h6>
                            <?php
                            $cash_ots_items = [
                                'amount_ok' => ['Összeg pontos', 'not_tithe'],
                                'description_ok_ots' => ['Megnevezés pontos', 'common'],
                                'receipt_number_ok' => ['Bizonylatszám helyesen szerepel', 'common'],
                                'fund_designation_ok' => ['Pénzalap megjelölés helyes', 'common'],
                                'decision_number_ok_ots' => ['Határozat száma (ha releváns)', 'common'],
                            ];
                            foreach ($cash_ots_items as $key => $item): ?>
                            <div class="checklist-item" data-req="<?= $item[1] ?>">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" value="1" id="chk_<?= $key ?>">
                                    <label class="form-check-label" for="chk_<?= $key ?>"><?= $item[0] ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                    <div class="mt-2 p-2 rounded bg-warning-subtle border">
                        <div class="checklist-item" data-req="tithe_ask">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="tithe_source_asked" value="1" id="chk_tithe_source_asked">
                                <label class="form-check-label" for="chk_tithe_source_asked"><small class="text-danger fw-bold">🔎 Kérdezd meg a pénztárost: milyen dokumentum alapján írta be a tizedcédula jellegű összegeket? (a/ banki közlemény, b/ internetes üzenet, c/ szóbeli, d/ egyéb → írd be a megjegyzésbe)</small></label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label small fw-bold">Megjegyzés</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <span id="auditSaveMsg" class="small me-2"></span>
                <button class="btn btn-success btn-sm" onclick="saveAudit()">💾 Mentés</button>
            </div>
        </div>
    </div>
</div>

<style>
#auditModal .modal-body { padding-top: .5rem; }
#auditModal .checklist-item { margin-bottom: 2px; }
#auditModal .checklist-item .form-check-label { font-size: .85rem; }
#auditModal .checklist-item .form-check { min-height: 1.3rem; margin-bottom: 0; }
#auditModal h6 { margin-bottom: .35rem; }
#auditModal .form-check-input { margin-top: .15rem; }
#auditModal .paper-sig-grid { display: flex; flex-direction: column; }
#auditModal .paper-sig-row { display: grid; grid-template-columns: 1fr 1fr; column-gap: 1rem; }
#auditModal .row > div[class*="col-"] { display: flex; flex-direction: column; }
#auditModal .audit-panel {
    border-radius: .5rem;
    padding: .5rem .75rem;
    height: 100%;
}
#auditModal .ots-col {
    background: #eaf2fb;
    border: 1px solid #d5e3f4;
}
#auditModal .paper-col {
    background: #fdf9ee;
    border: 1px solid #f0e4cb;
}
#auditModal .stmt-col {
    background: #f4f6f8;
    border: 1px solid #e2e7ec;
}
.sabbath-amount-link {
    color: #0d6efd;
    text-decoration: underline;
    cursor: pointer;
    font-weight: 600;
}
.sabbath-amount-link:hover { color: #0a58ca; }
</style>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
var CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
var CURRENT_TYPE = '<?= $type ?>';
var auditModal = null;
document.addEventListener("DOMContentLoaded", function() {
    auditModal = new bootstrap.Modal(document.getElementById('auditModal'));
    var helpText = document.getElementById('auditHelpText');
    var helpArrow = document.getElementById('auditHelpArrow');
    if (helpText && helpArrow) {
        helpText.addEventListener('show.bs.collapse', function() { helpArrow.style.transform = 'rotate(180deg)'; });
        helpText.addEventListener('hide.bs.collapse', function() { helpArrow.style.transform = 'rotate(0deg)'; });
    }
        var auditModalEl = document.getElementById('auditModal');
    if (auditModalEl) {
        auditModalEl.addEventListener('shown.bs.modal', function() {
            applyVerticalTabOrder(document.getElementById('auditForm'));
            syncStackedModals();
        });
        auditModalEl.addEventListener('hidden.bs.modal', function() {
            syncStackedModals();
        });
    }
});

var _auditData = {};

// A TAB billentyű függőlegesen (lefelé) navigáljon az ellenőrző listában:
// az egyes oszlopok (papír bal / papír jobb / OTS / stb.) checkboxait egymás
// alatt, oszloponként haladva rendeljük sorba a tabindex alapján.
function applyVerticalTabOrder(container) {
    if (!container) return;
    var items = [];
    var controls = container.querySelectorAll('input[type="checkbox"]');
    for (var i = 0; i < controls.length; i++) {
        var c = controls[i];
        if (c.disabled || c.type === 'hidden') continue;
        var rect = c.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) continue;
        items.push({ el: c, x: rect.left, y: rect.top });
    }
    if (items.length < 2) return;
    var cols = [];
    items.forEach(function(it) {
        for (var k = 0; k < cols.length; k++) {
            if (Math.abs(cols[k].x - it.x) < 60) {
                cols[k].items.push(it);
                return;
            }
        }
        cols.push({ x: it.x, items: [it] });
    });
    cols.sort(function(a, b) { return a.x - b.x; });
    cols.forEach(function(col) {
        col.items.sort(function(a, b) { return a.y - b.y; });
    });
    var n = 1;
    cols.forEach(function(col) {
        col.items.forEach(function(it) { it.el.setAttribute('tabindex', n++); });
    });
    // A checkboxok után a többi látható mező (megjegyzés, mentés) következzen
    var others = container.querySelectorAll('textarea, input[type="text"], select, button');
    for (var j = 0; j < others.length; j++) {
        var o = others[j];
        if (o.disabled || o.type === 'hidden') continue;
        var r = o.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        if (!o.hasAttribute('tabindex')) o.setAttribute('tabindex', n++);
    }
}

// A részlet (docDetail) modal ELLENŐRZÉS gombja a szabványos audit modal-t nyitja meg
var _ddAuditCtx = null;

function openDdAudit() {
    if (!_ddAuditCtx) return;
    openAudit(_ddAuditCtx.kind === 'cash' ? _ddAuditCtx.ots_record_id : _ddAuditCtx.bank_reconciliation_id, _ddAuditCtx.kind);
}

function openAudit(id, type) {
    type = type || CURRENT_TYPE;
    document.getElementById('auditBankRecId').value = '';
    document.getElementById('auditOtsRecId').value = '';

    var fetchUrl;
    if (type === 'cash') {
        document.getElementById('auditOtsRecId').value = id;
        fetchUrl = 'document_check_get.php?ots_record_id=' + id + '&type=cash';
    } else {
        document.getElementById('auditBankRecId').value = id;
        fetchUrl = 'document_check_get.php?bank_reconciliation_id=' + id;
    }
    
    // Adatok betöltése
    fetch(fetchUrl)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var infoChips = [];
        infoChips.push('<strong>' + htmlspecialchars(data.church_name || '-') + '</strong>');
        infoChips.push(htmlspecialchars(data.bank_date || '-'));
        infoChips.push('<span class="fw-bold ' + (Number(data.bank_amount || 0) < 0 ? 'text-danger' : 'text-success') + '">' + Number(data.bank_amount || 0).toLocaleString('hu-HU') + ' Ft</span>');
        infoChips.push('<span class="badge bg-' + (type === 'cash' ? 'info' : (data.status === 'OK' ? 'success' : (data.status === 'UNCHECKED' ? 'secondary' : 'warning'))) + '">' + (type === 'cash' ? 'KÉSZPÉNZ' : htmlspecialchars(data.status || 'UNCHECKED')) + '</span>');
        if (data.ots_type_name) infoChips.push('<span class="badge bg-secondary">' + htmlspecialchars(data.ots_type_name) + '</span>');
        if (data.ots_doc) infoChips.push('Bsz.: <strong>' + htmlspecialchars(data.ots_doc) + '</strong>');
        if (data.DECISION_NUMBER) infoChips.push('Hat.: ' + htmlspecialchars(data.DECISION_NUMBER));
        if (data.fund_name) infoChips.push('Alap: ' + htmlspecialchars(data.fund_name));
        if (data.ots_editor_name) infoChips.push('Rögzítette: ' + htmlspecialchars(data.ots_editor_name));
        if (data.bank_date) infoChips.push('Mód.: ' + htmlspecialchars((data.MODIFIED || '').length >= 16 ? data.MODIFIED.substring(0, 16) : (data.MODIFIED || '-')));
        var infoHtml = '<div class="d-flex flex-wrap align-items-center column-gap-2 row-gap-1">';
        for (var i = 0; i < infoChips.length; i++) {
            if (i > 0) infoHtml += '<span class="text-muted">·</span>';
            infoHtml += '<span>' + infoChips[i] + '</span>';
        }
        infoHtml += '</div>';
        if (data.bank_desc) {
            infoHtml += '<div class="mt-1"><span class="text-muted">Megnevezés / Partner:</span> <span class="text-truncate d-inline-block align-bottom" style="max-width:520px;">' + htmlspecialchars(data.bank_desc) + '</span></div>';
        }
        document.getElementById('auditBankInfo').innerHTML = infoHtml;

        renderSabbathGroup('auditSabbathGroup', data);
        renderAmountGroup('auditAmountGroup', data);
        renderAggGroupBlock('auditAggGroup', data);
        
        // Checkboxes beállítása
        var fields = ['date_filled','amount_ok','description_ok','signature_treasurer','signature_receiver','signature_authorizer','signature_auditor','signature_bookkeeper','signature_issuer','signature_payer','amount_in_words_ok','stamp_ok','invoice_ok','tithe_card_ok','tithe_source_asked','receipt_number_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok','bank_in_ots_ok','bank_stmt_ok'];
        fields.forEach(function(f) {
            var cb = document.getElementById('chk_' + f);
            if (cb) cb.checked = data.audit && data.audit[f] == 1;
        });
        // A bizonylatos (papír) és az OTS oldal Megnevezés / Határozat száma jelölői
        // egymástól függetlenek, külön oszlopban tárolódnak (description_ok_ots, decision_number_ok_ots)
        ['description_ok_ots','decision_number_ok_ots'].forEach(function(f) {
            var cb = document.getElementById('chk_' + f);
            if (cb) cb.checked = data.audit && data.audit[f] == 1;
        });
        // Tizedcédula blokk jelölői
        ['signature_auditor','signature_treasurer','amount_ok'].forEach(function(f) {
            var cb = document.getElementById('chk_tithe_' + f);
            if (cb) cb.checked = data.audit && data.audit[f] == 1;
        });
        document.getElementById('auditInspectorName').value = data.audit ? data.audit.inspector_name : '<?= htmlspecialchars($_SESSION[GC_USER_FULL_NAME] ?? '', ENT_QUOTES, 'UTF-8') ?>';
        document.querySelector('#auditForm [name="notes"]').value = data.audit ? data.audit.notes : '';

        // Dinamikus ellenőrző lista a tétel típusa szerint (bevétel / kiadás / tizedcédula / átvezetés / banki tizedcédula-feladat)
        var isExpense = Number(data.bank_amount || 0) < 0;
        var isTransfer = type === 'cash' && Number(data.is_transfer || 0) === 1;
        var titleEl = document.getElementById('auditModalTitle');
        if (titleEl) {
            if (type === 'cash') {
                if (isTransfer) titleEl.textContent = '🔁 Átvezetés ellenőrzése';
                else titleEl.textContent = isExpense ? '📋 Kiadási pénztárbizonylat ellenőrzés' : '📋 Bevételi pénztárbizonylat ellenőrzés';
            } else {
                titleEl.textContent = '📋 Ellenőrző lista';
            }
        }
        var isTithe = type === 'cash' && !isTransfer && Number(data.ots_type) === 1;
        var isTitheAsk = type === 'bank' && Number(data.tithe_ask) === 1;
        // Kiadási bizonylaton a pénzt átvevő szerepel (nem befizető)
        if (type === 'cash') {
            var payerLabels = {
                'signature_receiver': isExpense ? 'Átvevő neve' : 'Befizető neve',
                'signature_payer': isExpense ? 'Átvevő aláírása' : 'Befizető aláírása'
            };
            Object.keys(payerLabels).forEach(function(k) {
                var lb = document.getElementById('lbl_' + k);
                if (lb) lb.textContent = payerLabels[k];
            });
        }
        document.querySelectorAll('.checklist-item[data-req]').forEach(function(el) {
            var req = el.getAttribute('data-req');
            var visible = true;
            if (req === 'expense') visible = isExpense && !isTransfer;
            else if (req === 'tithe') visible = isTithe;
            else if (req === 'tithe_ask') visible = isTitheAsk;
            else if (req === 'bank_expense') visible = isExpense;
            else if (req === 'not_tithe') visible = !isTithe;
            el.style.display = visible ? '' : 'none';
            el.querySelectorAll('input').forEach(function(i) { i.disabled = !visible; });
        });

        // Belső átvezetés: a papír pénztárbizonylat blokk helyett infó jelenik meg;
        // tizedcédula jellegű bevételnél a papír bizonylat blokk helyett a Tizedcédula blokk.
        var paperBlock = document.getElementById('cashPaperBlock');
        var titheBlock = document.getElementById('titheCardBlock');
        var transferBlock = document.getElementById('transferInfoBlock');
        var paperPanelVisible = type === 'cash' && !isTransfer;
        if (paperBlock) {
            var paperVisible = paperPanelVisible && !isTithe;
            paperBlock.style.display = paperVisible ? '' : 'none';
            paperBlock.querySelectorAll('input').forEach(function(i) { i.disabled = !paperVisible; });
        }
        if (titheBlock) {
            var titheVisible = paperPanelVisible && isTithe;
            titheBlock.style.display = titheVisible ? '' : 'none';
            titheBlock.querySelectorAll('input').forEach(function(i) { i.disabled = !titheVisible; });
        }
        if (transferBlock) {
            transferBlock.style.display = (type === 'cash' && isTransfer) ? '' : 'none';
        }

        syncStackedModals();
        auditModal.show();
    })
    .catch(function() {
        alert('Hiba az adatok betöltésekor');
    });
}

var docDetailModal = null;
document.addEventListener("DOMContentLoaded", function() {
    docDetailModal = new bootstrap.Modal(document.getElementById('docDetailModal'));
    document.getElementById('docDetailModal').addEventListener('shown.bs.modal', function() {
        docDetailModalShown();
    });
    document.getElementById('docDetailModal').addEventListener('hidden.bs.modal', function() {
        syncStackedModals();
    });
    // Deep-link a használati naplóból: megnyitja a hivatkozott tétel ellenőrző listáját
    var qp = new URLSearchParams(window.location.search);
    var bankId = qp.get('bank_reconciliation_id');
    var cashId = qp.get('ots_record_id');
    if (bankId) { openAudit(parseInt(bankId, 10), 'bank'); }
    else if (cashId) { openAudit(parseInt(cashId, 10), 'cash'); }
});

function showDocDetail(id, type) {
    type = type || CURRENT_TYPE;
    document.getElementById('ddLoading').style.display = 'block';
    document.getElementById('ddDoublePanel').style.display = 'none';
    document.getElementById('ddSinglePanel').style.display = 'none';
    document.getElementById('ddError').style.display = 'none';
    _ddAuditCtx = null;

    var fetchUrl;
    if (type === 'cash') {
        fetchUrl = 'document_check_get.php?ots_record_id=' + id + '&type=cash';
    } else {
        fetchUrl = 'document_check_get.php?bank_reconciliation_id=' + id + '&detail=1';
    }

    fetch(fetchUrl)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        document.getElementById('ddLoading').style.display = 'none';
        if (data.error) {
            document.getElementById('ddError').textContent = data.error;
            document.getElementById('ddError').style.display = 'block';
            return;
        }
        document.getElementById('ddTitle').textContent = '📄 ' + (data.church_name || '') + ' — ' + (data.bank_date || '') + ' — ' + Number(data.bank_amount).toLocaleString('hu-HU') + ' Ft';

        if (type === 'cash') {
            // Készpénz: OTS mezőnevekre transzformáljuk
            renderSabbathGroup('ddSabbathGroup', data);
            renderAmountGroup('ddAmountGroup', data);
            var toCashTx = function(src) {
                return {
                    DATETIME: src.date || (data.bank_date ? data.bank_date.substring(0, 10) : '-'),
                    adjusted_amount: src.amount || 0,
                    AMOUNT: src.amount || 0,
                    ots_desc_full: src.desc || src.fund_name || '-',
                    CASH_DOCUMENT_NUMBER: src.doc || data.ots_doc || '-',
                    DECISION_NUMBER: '-',
                    ots_type_name: src.type_name || data.ots_type_name || '-',
                    VIA_BANK: 0,
                    MODIFIED: '',
                    ots_editor_name: data.ots_editor_name || '-',
                    fund_name: src.fund_name || '-',
                    RECORD_ID: src.rec_id || data.RECORD_ID || data.id || 0,
                    CHURCH_ID: data.CHURCH_ID || 0
                };
            };
            var txList;
            if (data.amount_group && data.amount_group.length > 1) {
                // Több pénzalapos rekord: minden tétel külön accordion sorban
                txList = data.amount_group.map(toCashTx);
            } else {
                txList = [toCashTx({ date: data.bank_date, amount: data.bank_amount, desc: data.bank_desc, doc: data.ots_doc, type_name: data.ots_type_name, fund_name: data.fund_name })];
            }
            document.getElementById('ddOtsSingleContent').innerHTML = renderOtsDetailTable(txList);
            document.getElementById('ddSinglePanel').style.display = 'block';
            _ddAuditCtx = { kind: 'cash', ots_record_id: data.RECORD_ID || data.id || 0, audit: data.audit || null, ots_type: data.ots_type || 0 };
        } else if (data.ots_data && data.ots_data.length > 0) {
            var otsHtml = renderOtsDetailTable(data.ots_data);
            if (data.is_bank) {
                document.getElementById('ddBankContent').innerHTML = renderBankDetailTable(data);
                document.getElementById('ddOtsContent').innerHTML = otsHtml;
                document.getElementById('ddDoublePanel').style.display = 'flex';
            } else {
                document.getElementById('ddOtsSingleContent').innerHTML = otsHtml;
                document.getElementById('ddSinglePanel').style.display = 'block';
            }
            _ddAuditCtx = { kind: 'bank', bank_reconciliation_id: data.id || 0, audit: data.audit || null, tithe_ask: data.tithe_ask || 0 };
        } else {
            document.getElementById('ddBankContent').innerHTML = renderBankDetailTable(data);
            document.getElementById('ddDoublePanel').style.display = 'flex';
            document.getElementById('ddOtsContent').innerHTML = '<div class="alert alert-secondary m-2">Nincs hozzárendelt OTS könyvelési tétel.</div>';
            _ddAuditCtx = { kind: 'bank', bank_reconciliation_id: data.id || 0, audit: data.audit || null, tithe_ask: data.tithe_ask || 0 };
        }
        docDetailModal.show();
    })
    .catch(function() {
        document.getElementById('ddLoading').style.display = 'none';
        document.getElementById('ddError').textContent = 'Hiba az adatok betöltésekor.';
        document.getElementById('ddError').style.display = 'block';
    });
}

function docDetailModalShown() {
    syncStackedModals();
}

// Ha az ellenőrző lista (auditModal) a részletek (docDetailModal) tetején nyílik meg,
// a két modalt egymás mellé helyezi: az audit balra, a részlet jobbra tolva.
// Az audit magasabb z-indexű, így a háttere nem takarja el a részleteket.
function syncStackedModals() {
    var ddEl = document.getElementById('docDetailModal');
    var auditEl = document.getElementById('auditModal');
    var ddOpen = !!(ddEl && ddEl.classList.contains('show'));
    var auditOpen = !!(auditEl && auditEl.classList.contains('show'));
    if (ddEl) ddEl.classList.toggle('offset-from-audit', ddOpen && auditOpen);
    if (auditEl) auditEl.classList.toggle('stack-on-docdetail', ddOpen && auditOpen);
}

function renderAggGroup(data) {
    if (!data || Number(data.agg_count || 0) < 2) return '';
    var group = data.agg_group || [];
    var currentId = Number(data.id || 0);
    var canLink = typeof showDocDetail === 'function';
    var parts = [];
    for (var i = 0; i < group.length; i++) {
        var m = group[i];
        var mid = Number(m.id || 0);
        var label = (m.bank_date ? String(m.bank_date).substring(0, 10) : '?') + ' · ' + Number(m.bank_amount || 0).toLocaleString('hu-HU') + ' Ft';
        var tip = htmlspecialchars(m.bank_desc || '');
        if (mid === currentId) {
            parts.push('<span class="badge bg-info" title="Ez az aktuális tétel">' + label + ' (ez)</span>');
        } else if (canLink) {
            parts.push('<a href="javascript:void(0)" onclick="showDocDetail(' + mid + ', \'bank\')" class="badge bg-info text-decoration-none" style="cursor:pointer;" title="' + tip + '">' + label + '</a>');
        } else {
            parts.push('<span class="badge bg-info" title="' + tip + '">' + label + '</span>');
        }
    }
    return '<span class="badge bg-info-subtle text-info-emphasis border border-info" title="Összevont könyvelés: több banki tétel kapcsolódik ugyanahhoz az OTS tételhez">🔗 ' + data.agg_count + ' banki → 1 OTS</span> ' + parts.join(' ');
}

function renderAggGroupBlock(containerId, data) {
    var el = document.getElementById(containerId);
    if (!el) return;
    if (Number(data.agg_count || 0) < 2) { el.innerHTML = ''; return; }
    el.innerHTML = '<div class="mb-2 p-2 bg-warning-subtle rounded small"><span class="fw-bold">🔗 Összevont könyvelés:</span> ' + renderAggGroup(data) + '</div>';
}

function renderBankDetailTable(data) {
    var amt = Number(data.bank_amount || 0);
    var amtClass = amt < 0 ? 'text-danger' : 'text-success';
    var desc = (data.bank_desc || '-');
    var initName = data.bank_ext_name || '-';
    var initAcc = data.bank_ext_acc || '-';
    var benName = data.bank_ben_name || data.bank_ext_name || '-';
    var benAcc = data.bank_ben_acc || '-';
    var extRef = data.bank_ext_ref || '-';
    var txCode = data.bank_tx_code || '-';
    var stmtDate = data.bank_stmt_date || '-';

    var html = '<table class="table table-sm table-striped table-bordered detail-table">';
    html += '<tr><th>Gyülekezet:</th><td>' + htmlspecialchars(data.church_name || '-') + '</td></tr>';
    html += '<tr><th>Dátum:</th><td>' + htmlspecialchars(data.bank_date || '-') + '</td></tr>';
    html += '<tr><th>Összeg:</th><td class="fw-bold ' + amtClass + '">' + amt.toLocaleString('hu-HU') + ' Ft</td></tr>';
    html += '<tr><th>Közlemény:</th><td>' + htmlspecialchars(desc) + '</td></tr>';
    html += '<tr class="table-info"><th>Kezdeményező neve:</th><td>' + htmlspecialchars(initName) + '</td></tr>';
    html += '<tr class="table-info"><th>Kezdeményező számla:</th><td>' + htmlspecialchars(initAcc) + '</td></tr>';
    html += '<tr class="table-light"><th>Kedvezményezett neve:</th><td>' + htmlspecialchars(benName) + '</td></tr>';
    html += '<tr class="table-light"><th>Kedvezményezett számla:</th><td>' + htmlspecialchars(benAcc) + '</td></tr>';
    html += '<tr><th>Tranzakció azonosító:</th><td>' + htmlspecialchars(extRef) + '</td></tr>';
    html += '<tr><th>Tranzakció kód:</th><td>' + htmlspecialchars(txCode) + '</td></tr>';
    html += '<tr><th>Banki kivonat dátuma:</th><td>' + htmlspecialchars(stmtDate) + '</td></tr>';
    html += '<tr><th>Állapot:</th><td><span class="badge bg-' + (data.status === 'OK' ? 'success' : (data.status === 'UNCHECKED' ? 'secondary' : 'warning')) + '">' + htmlspecialchars(data.status || 'UNCHECKED') + '</span></td></tr>';
    if (data.updated_by) {
        html += '<tr><th>Ellenőrizte / elfogadta:</th><td>' + htmlspecialchars(data.updated_by) + '</td></tr>';
    }
    if (data.agg_count > 1) {
        html += '<tr class="table-warning"><th>Összevont könyvelés:</th><td>' + renderAggGroup(data) + '</td></tr>';
    }
    html += '</table>';
    return html;
}

function renderOtsDetailTable(otsData) {
    if (!otsData || otsData.length === 0) return '<div class="alert alert-warning m-2">Nincs OTS könyvelési adat.</div>';
    var html = '<div class="accordion" id="ddOtsAccordion">';
    otsData.forEach(function(tx, idx) {
        var txId = 'dd-tx-' + idx;
        var otsDate = tx.DATETIME ? tx.DATETIME.substring(0, 10) : '-';
        var adjAmount = Number(tx.adjusted_amount || tx.AMOUNT || 0);
        var otsAmount = adjAmount.toLocaleString('hu-HU') + ' Ft';
        var otsDesc = tx.ots_desc_full || '-';
        var collapsed = idx > 0;

        html += '<div class="accordion-item">' +
            '<h2 class="accordion-header">' +
                '<button class="accordion-button ' + (collapsed ? 'collapsed' : '') + '" type="button" data-bs-toggle="collapse" data-bs-target="#' + txId + '">' +
                '<span class="fw-bold me-2">#' + (idx + 1) + '</span>' +
                '<span class="badge bg-secondary me-2">' + otsDate + '</span>' +
                '<span class="' + (adjAmount < 0 ? 'text-danger' : 'text-success') + ' fw-bold me-2">' + otsAmount + '</span>' +
                '<small class="text-muted text-truncate" style="max-width:200px;">' + htmlspecialchars(otsDesc) + '</small>' +
            '</button></h2>' +
            '<div id="' + txId + '" class="accordion-collapse collapse ' + (collapsed ? '' : 'show') + '" data-bs-parent="#ddOtsAccordion">' +
                '<div class="accordion-body p-0 dd-accordion-body">' +
                    '<table class="table table-sm table-striped table-bordered detail-table">';

        if (Number(tx.TYPE) === 1 && Number(tx.VIA_ONLINE_GIVING) === 1) {
            html += '<tr><th>Tizedcédula:</th><td><span class="badge bg-info">🌐 Online tizedcédula (adakozom.tetkapu.hu)</span></td></tr>';
        }

        var keys = ['DATETIME', 'adjusted_amount', 'ots_desc_full', 'CASH_DOCUMENT_NUMBER', 'DECISION_NUMBER', 'ots_type_name', 'VIA_BANK', 'MODIFIED'];
        var labels = {'DATETIME': 'Dátum', 'adjusted_amount': 'Összeg', 'ots_desc_full': 'Partner / Megjegyzés', 'CASH_DOCUMENT_NUMBER': 'Bizonylatszám', 'DECISION_NUMBER': 'Határozati szám', 'ots_type_name': 'Típus', 'VIA_BANK': 'Banki tranzakció', 'MODIFIED': 'Módosítás ideje'};

        keys.forEach(function(k) {
            if (k in tx && tx[k] !== null && tx[k] !== undefined) {
                var val = tx[k];
                var displayVal = val;
                var style = '';
                if (k === 'adjusted_amount') {
                    displayVal = Number(val).toLocaleString('hu-HU') + ' Ft';
                    style = val < 0 ? 'class="fw-bold text-danger"' : 'class="fw-bold text-success"';
                } else if (k === 'VIA_BANK') {
                    displayVal = val == 1 ? '✅ Igen' : '❌ Nem, hanem készpénzes';
                } else if (k === 'DATETIME' || k === 'MODIFIED') {
                    displayVal = val.length >= 16 ? val.substring(0, 16) : val;
                }
                html += '<tr><th>' + htmlspecialchars(labels[k] || k) + ':</th><td ' + style + '>' + htmlspecialchars(displayVal) + '</td></tr>';
            }
        });

        if (tx.ots_editor_name || tx.EDITED_BY) {
            html += '<tr><th>Rögzítette:</th><td>' + htmlspecialchars(tx.ots_editor_name || '-') + (tx.EDITED_BY ? ' <span class="text-muted small">(' + htmlspecialchars(tx.EDITED_BY) + ')</span>' : '') + '</td></tr>';
        }
        if (tx.fund_name || tx.FUND_ID) {
            html += '<tr><th>Alap:</th><td>' + htmlspecialchars(tx.fund_name || tx.FUND_ID) + '</td></tr>';
        }

        html += '</table></div></div></div>';
    });
    html += '</div>';

    // Összegzés ha több tétel
    if (otsData.length > 1) {
        var sum = 0;
        otsData.forEach(function(tx) { sum += Number(tx.adjusted_amount || tx.AMOUNT || 0); });
        html += '<div class="text-center fw-bold py-1 border-top bg-light">Összesen: <span class="' + (sum < 0 ? 'text-danger' : 'text-success') + '">' + sum.toLocaleString('hu-HU') + ' Ft</span></div>';
    }

    return html;
}

function htmlspecialchars(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// Szombati bizonylat-csoport megjelenítése: a papír bizonylat 3 sorának adatai,
// ugyanazokból a forrásokból, mint az időszaki pénztárjelentő (kosár: TRANSACTIONS, tized: önellenőrzés).
function renderSabbathGroup(containerId, data) {
    var el = document.getElementById(containerId);
    if (!el) return;
    // Csak a szombati csoport sorainál jelenik meg (partnernév nélküli kosár/tized)
    // — a neves tizedcéduláknál rejtve marad
    if (Number(data.bank_amount || 0) < 0 || Number(data.show_sabbath_group) !== 1) {
        el.style.display = 'none';
        el.innerHTML = '';
        return;
    }
    var sg = data.sabbath_group;
    if (!sg) { el.style.display = 'none'; el.innerHTML = ''; return; }
    var fm = function(v) { return Number(v || 0).toLocaleString('hu-HU'); };
    var on = sg.onellenorzes;
    var docNum = (on && on.doc_number) ? on.doc_number : '';
    var ownDocNum = data.ots_doc || '';

    var html = '<div class="mb-2 p-2 rounded border small" style="background:#fff8e1;">';
    html += '<h6 class="mb-1"><strong>🧾 Szombati bizonylat-csoport</strong> <span class="text-muted">(' + htmlspecialchars(sg.date) + ' — a hónap ' + sg.week + '. szombatja)</span></h6>';
    html += '<table class="table table-sm table-bordered mb-1" style="font-size:12px;">';
    html += '<thead class="table-warning"><tr><th>Bizonylat sora</th><th class="text-end">Összeg</th><th></th></tr></thead><tbody>';
    var currentType = Number(data.ots_type || 0);
    var magnifier = function(recId, typeId) {
        if (!recId || currentType === typeId) return '';
        return '<a href="#" class="btn btn-outline-primary btn-sm py-0 px-1" title="Összeg ellenőrzése" onclick="event.preventDefault(); openAudit(' + recId + ', \'cash\');">🔍</a>';
    };
    var amtLink = function(recId, typeId, amount) {
        var txt = fm(amount) + ' Ft';
        if (!recId || currentType === typeId) return txt;
        return '<a href="#" class="sabbath-amount-link" title="Összeg részletei" onclick="event.preventDefault(); showDocDetail(' + recId + ', \'cash\');">' + txt + '</a>';
    };
    html += '<tr><td>🪙 Szombat délelőtti kosár</td><td class="text-end">' + amtLink(sg.saturday_morning_rec_id, <?= GN_TRANSACTION_TYPE_SATURDAY_MORNING ?>, sg.saturday_morning) + '</td><td class="text-end">' + magnifier(sg.saturday_morning_rec_id, <?= GN_TRANSACTION_TYPE_SATURDAY_MORNING ?>) + '</td></tr>';
    html += '<tr><td>📖 Szombatiskolai kosár</td><td class="text-end">' + amtLink(sg.sabbath_school_rec_id, <?= GN_TRANSACTION_TYPE_SABBATH_SCHOOL ?>, sg.sabbath_school) + '</td><td class="text-end">' + magnifier(sg.sabbath_school_rec_id, <?= GN_TRANSACTION_TYPE_SABBATH_SCHOOL ?>) + '</td></tr>';
    if (Number(sg.special_target || 0) > 0) {
        html += '<tr><td>📅 Adakozási naptár' + (sg.special_target_purpose ? ' (' + htmlspecialchars(sg.special_target_purpose) + ')' : '') + '</td><td class="text-end">' + amtLink(sg.special_target_rec_id, <?= GN_TRANSACTION_TYPE_SPECIAL_TARGET ?>, sg.special_target) + '</td><td class="text-end">' + magnifier(sg.special_target_rec_id, <?= GN_TRANSACTION_TYPE_SPECIAL_TARGET ?>) + '</td></tr>';
    }
    html += '<tr><td>✉️ Adakozás tizedcéduláról (időszaki pénztárjelentő szerint)</td><td class="text-end">' + fm(sg.tithe_envelope) + ' Ft</td><td></td></tr>';
    html += '<tr><td class="fw-bold">Összesen</td><td class="text-end fw-bold">' + fm(Number(sg.saturday_morning || 0) + Number(sg.sabbath_school || 0) + Number(sg.special_target || 0) + Number(sg.tithe_envelope || 0)) + ' Ft</td><td></td></tr>';
    html += '</tbody></table>';
    if (!on) {
        html += '<div class="text-muted">Önellenőrzés (heti tized) nincs rögzítve erre a hétre.</div>';
    }
    if (docNum !== '' || ownDocNum !== '') {
        html += '<div><strong>Bizonylatszám:</strong> ' + htmlspecialchars(docNum || ownDocNum || '') + (ownDocNum && docNum && ownDocNum !== docNum ? ' <span class="text-muted">(tétel: ' + htmlspecialchars(ownDocNum) + ')</span>' : '') + '</div>';
    }
    html += '</div>';
    el.innerHTML = html;
    el.style.display = 'block';
}

// Több pénzalapos tétel megjelenítése: ha egy RECORD_ID-hoz több TRANSACTIONS sor tartozik
// (eltérő pénzalap/összeg), akkor az eddigi egyetlen (véletlenszerű) összeg helyett
// a teljes csoportot mutatjuk, az összesítővel együtt.
function renderAmountGroup(containerId, data) {
    var el = document.getElementById(containerId);
    if (!el) return;
    if (Number(data.show_amount_group) !== 1 || !data.amount_group || !data.amount_group.length) {
        el.style.display = 'none';
        el.innerHTML = '';
        return;
    }
    var fm = function(v) { return Number(v || 0).toLocaleString('hu-HU'); };
    var html = '<div class="mb-2 p-2 rounded border small" style="background:#e8f5e9;">';
    html += '<h6 class="mb-1"><strong>🧮 Több pénzalap egy rekordban</strong> <span class="text-muted">(' + htmlspecialchars(data.bank_date || '') + ' — ' + data.amount_group.length + ' tétel)</span></h6>';
    html += '<table class="table table-sm table-bordered mb-1" style="font-size:12px;">';
    html += '<thead class="table-success"><tr><th>Pénzalap / megnevezés</th><th class="text-end">Összeg</th></tr></thead><tbody>';
    data.amount_group.forEach(function(g) {
        var label = g.fund_name || g.desc || g.type_name || '—';
        var cls = Number(g.amount || 0) < 0 ? 'text-danger' : 'text-success';
        html += '<tr><td>' + htmlspecialchars(label) + '</td><td class="text-end ' + cls + ' fw-bold">' + fm(g.amount) + ' Ft</td></tr>';
    });
    html += '<tr><td class="fw-bold">Összesen</td><td class="text-end fw-bold ' + (Number(data.bank_amount || 0) < 0 ? 'text-danger' : 'text-success') + '">' + fm(data.bank_amount) + ' Ft</td></tr>';
    html += '</tbody></table>';
    html += '<div class="text-muted">Egy papír bizonylathoz több pénzalap tartozik — a fenti tételeket együtt ellenőrizd.</div>';
    html += '</div>';
    el.innerHTML = html;
    el.style.display = 'block';
}

function saveAudit() {
    var form = document.getElementById('auditForm');
    var data = new FormData(form);
    var isCash = document.getElementById('auditOtsRecId').value !== '';
    data.append('action', isCash ? 'save_cash_audit' : 'save_audit');
    data.append('csrf_token', CSRF_TOKEN);
    
    document.getElementById('auditSaveMsg').innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    fetch('document_check.php', { method: 'POST', body: data })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.status === 'OK') {
            document.getElementById('auditSaveMsg').innerHTML = '<span class="text-success">✓ Mentve</span>';
            setTimeout(function() { window.location.reload(); }, 600);
        } else {
            document.getElementById('auditSaveMsg').innerHTML = '<span class="text-danger">✗ ' + result.message + '</span>';
        }
    })
    .catch(function() {
        document.getElementById('auditSaveMsg').innerHTML = '<span class="text-danger">✗ Hiba</span>';
    });
}

function sortAuditTable(th) {
    var tbody = th.closest('table').querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var col = Array.from(th.parentNode.children).indexOf(th);
    var type = th.getAttribute('data-sort-type') || 'string';
    var asc = !th.classList.contains('sort-asc');
    
    // Reset arrows
    th.parentNode.querySelectorAll('th').forEach(function(h) { h.classList.remove('sort-asc', 'sort-desc'); });
    th.classList.add(asc ? 'sort-asc' : 'sort-desc');
    
    // Exclude footer rows (e.g. "Nincs találat")
    var dataRows = rows.filter(function(r) { return r.querySelector('td'); });
    var nonData = rows.filter(function(r) { return !r.querySelector('td'); });
    
    dataRows.sort(function(a, b) {
        var ac = a.querySelectorAll('td')[col];
        var bc = b.querySelectorAll('td')[col];
        if (!ac || !bc) return 0;
        var va = ac.textContent.trim();
        var vb = bc.textContent.trim();
        
        // Check data-sort-value
        if (ac.querySelector('[data-sort-value]')) va = ac.querySelector('[data-sort-value]').getAttribute('data-sort-value');
        if (bc.querySelector('[data-sort-value]')) vb = bc.querySelector('[data-sort-value]').getAttribute('data-sort-value');
        
        if (type === 'number') {
            var na = parseFloat(va.replace(/[^\d,.-]/g, '').replace(',', '.')) || 0;
            var nb = parseFloat(vb.replace(/[^\d,.-]/g, '').replace(',', '.')) || 0;
            return asc ? na - nb : nb - na;
        } else if (type === 'date') {
            return asc ? va.localeCompare(vb) : vb.localeCompare(va);
        } else {
            return asc ? va.localeCompare(vb) : vb.localeCompare(va);
        }
    });
    
    tbody.innerHTML = '';
    dataRows.forEach(function(r) { tbody.appendChild(r); });
    nonData.forEach(function(r) { tbody.appendChild(r); });
}

// Szűrő beállítások mentése localStorage-ba
(function() {
    // Ha nincsenek GET paraméterek, töltsük a mentettekből
    if (window.location.search.length === 0) {
        var saved = localStorage.getItem('audit_filters');
        if (saved) {
            try {
                var f = JSON.parse(saved);
                var form = document.querySelector('form');
                if (form) {
                    if (f.church_id) form.church_id.value = f.church_id;
                    if (f.date_from) form.date_from.value = f.date_from;
                    if (f.date_to) form.date_to.value = f.date_to;
                    if (f.amount_min) form.amount_min.value = f.amount_min;
                    if (f.amount_max) form.amount_max.value = f.amount_max;
                }
            } catch(e) {}
        }
    }
    // Form submit-kor mentsük el
    document.querySelector('form')?.addEventListener('submit', function() {
        localStorage.setItem('audit_filters', JSON.stringify({
            church_id: this.church_id.value,
            date_from: this.date_from.value,
            date_to: this.date_to.value,
            amount_min: this.amount_min.value,
            amount_max: this.amount_max.value
        }));
    });
})();

// Session kezelés
let sessionRemaining = <?= max(0, $session_remaining) ?>;
let sessionWarningShown = false;
let sessionExtending = false;

function updateSessionDisplay() {
    if (sessionRemaining <= 0) {
        document.getElementById('sessionCountdown').textContent = '0:00';
        window.location.href = 'logout.php';
        return;
    }
    const mins = Math.floor(sessionRemaining / 60);
    const secs = sessionRemaining % 60;
    document.getElementById('sessionCountdown').textContent = mins + ':' + String(secs).padStart(2, '0');
}

function extendSession() {
    if (sessionExtending) return;
    sessionExtending = true;
    fetch('session_ping.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: 'action=keepalive&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(r => r.json())
    .then(data => {
        if (data.remaining) {
            sessionRemaining = data.remaining;
            updateSessionDisplay();
            if (sessionWarningShown) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('sessionWarningModal'));
                if (modal) modal.hide();
                sessionWarningShown = false;
            }
        }
    })
    .catch(() => {})
    .finally(() => { sessionExtending = false; });
}

setInterval(extendSession, 30000);

setInterval(() => {
    sessionRemaining--;
    updateSessionDisplay();
    if (sessionRemaining < 120 && !sessionWarningShown) {
        sessionWarningShown = true;
        const modal = new bootstrap.Modal(document.getElementById('sessionWarningModal'));
        modal.show();
    }
    if (sessionRemaining <= 0) {
        window.location.href = 'logout.php';
    }
}, 1000);
</script>
<!-- Session lejárat figyelmeztető modal -->
<div class="modal fade" id="sessionWarningModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-warning">
            <div class="modal-header bg-warning text-dark">
                <h6 class="modal-title">⏰ Session lejár</h6>
            </div>
            <div class="modal-body text-center">
                <p class="mb-2">A munkamenet lejár:</p>
                <div class="display-6 fw-bold text-danger mb-2" id="sessionCountdown">--</div>
                <p class="small text-muted">Kattints a hosszabbításra, hogy ne veszítsd el a munkádat.</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button class="btn btn-warning fw-bold" onclick="extendSession()">🔄 Hosszabbítás</button>
            </div>
        </div>
    </div>
</div>

<?php if (function_exists('render_announcement_modal')) render_announcement_modal(); ?>

</body>
</html>
