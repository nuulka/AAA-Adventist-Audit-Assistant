<?php
$common_audit_fields = ['cash_voucher_ok','date_filled','amount_ok','description_ok','signature_treasurer','signature_receiver','signature_authorizer','signature_auditor','stamp_ok','receipt_number_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok'];
$bank_audit_fields = array_merge($common_audit_fields, ['invoice_ok','tithe_card_ok','bank_in_ots_ok']);
$cash_audit_fields = array_merge($common_audit_fields, ['invoice_ok','tithe_card_ok']);
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
// ensure user context built
build_user_context_from_ots();
$accessible_church_ids = get_accessible_church_ids();
$session_remaining = ensure_revizor_session_timeout();
ensure_revizor_csrf_token();

log_activity('page_view', ['page' => 'document_check']);

if (is_admin()) {
    $church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
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
    stamp_ok TINYINT(1) DEFAULT 0,
    invoice_ok TINYINT(1) DEFAULT 0,
    tithe_card_ok TINYINT(1) DEFAULT 0,
    receipt_number_ok TINYINT(1) DEFAULT 0,
    decision_number_ok TINYINT(1) DEFAULT 0,
    fund_designation_ok TINYINT(1) DEFAULT 0,
    supporting_doc_ok TINYINT(1) DEFAULT 0,
    notes TEXT DEFAULT NULL,
    UNIQUE KEY uk_ots_record (ots_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// ALTER for existing tables that lack the new columns
$cah_new_cols = ['signature_auditor','stamp_ok'];
foreach ($cah_new_cols as $cah_col) {
    $cah_col_res = $conn->query("SHOW COLUMNS FROM ots_cash_audit LIKE '" . $cah_col . "'");
    if (!$cah_col_res || $cah_col_res->num_rows === 0) {
        $conn->query("ALTER TABLE ots_cash_audit ADD COLUMN $cah_col TINYINT(1) DEFAULT 0");
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
$amount_min = isset($_GET['amount_min']) && $_GET['amount_min'] !== '' ? floatval($_GET['amount_min']) : null;
$amount_max = isset($_GET['amount_max']) && $_GET['amount_max'] !== '' ? floatval($_GET['amount_max']) : null;

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
    $fields = ['cash_voucher_ok','date_filled','amount_ok','description_ok','signature_treasurer','signature_receiver','signature_authorizer','signature_auditor','stamp_ok','invoice_ok','tithe_card_ok','receipt_number_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok','bank_in_ots_ok'];
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
    $caf_fields = ['cash_voucher_ok','date_filled','amount_ok','description_ok','signature_treasurer','signature_receiver','signature_authorizer','signature_auditor','stamp_ok','invoice_ok','tithe_card_ok','receipt_number_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok'];
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
    if ($amount_min !== null) { $cash_clauses[] = "ABS($adj_sql) >= ?"; $cash_params[] = $amount_min; $cash_types .= 'd'; }
    if ($amount_max !== null) { $cash_clauses[] = "ABS($adj_sql) <= ?"; $cash_params[] = $amount_max; $cash_types .= 'd'; }

    $cash_where = implode(' AND ', $cash_clauses);

    $cash_sql = "SELECT T.RECORD_ID, T.CHURCH_ID, T.DATETIME AS bank_date, T.VIA_BANK,
                        $adj_sql AS bank_amount, T.CASH_DOCUMENT_NUMBER,
                        TRIM(CONCAT(
                            IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''),
                            ' ', IFNULL(nt1.NAME, ''), ' ', IFNULL(nt2.NAME, '')
                        )) AS bank_desc
                 FROM TRANSACTIONS T
                 LEFT JOIN PERSONS p ON T.PERSON_ID = p.id
                 LEFT JOIN NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
                 LEFT JOIN NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
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
    if ($cash_result) {
        while ($r = $cash_result->fetch_assoc()) {
            $r['id'] = $r['RECORD_ID'];
            $r['church_name'] = $dc_church_names[$r['CHURCH_ID']] ?? null;
            $r['status'] = '-';
            $record_ids[] = $r['RECORD_ID'];
            $cash_rows[$r['RECORD_ID']] = $r;
            $total_count++;
        }
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
    $where_sql = implode(' AND ', $clauses);

    $sql = "SELECT br.*,
                   ac.id AS audit_id, ac.inspector_name, ac.checked_at,
                   ac.cash_voucher_ok, ac.date_filled, ac.amount_ok, ac.description_ok,
                   ac.signature_treasurer, ac.signature_receiver, ac.signature_authorizer,
                   ac.signature_auditor, ac.stamp_ok,
                   ac.invoice_ok, ac.tithe_card_ok, ac.receipt_number_ok, ac.decision_number_ok,
                    ac.fund_designation_ok, ac.supporting_doc_ok, ac.bank_in_ots_ok, ac.notes
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
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $r['church_name'] = $dc_church_names[$r['church_id']] ?? null;
            $rows[] = $r;
            $total_count++;
            if ($r['audit_id']) { $checked_count++; }
        }
    }
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
        #docDetailModal .modal-body { max-height: 75vh; overflow-y: auto; }
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
                        <option value="0">Összes</option>
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
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $idx = 1; foreach ($rows as $r): 
                            $is_expense = (float)$r['bank_amount'] < 0;
                            $t_audit_fields = $common_audit_fields;
                            if ($type === 'bank') $t_audit_fields[] = 'bank_in_ots_ok';
                            $t_audit_fields[] = $is_expense ? 'invoice_ok' : 'tithe_card_ok';
                            $ok_count = 0;
                            $total_audit = count($t_audit_fields);
                            if ($r['audit_id']) {
                                foreach ($t_audit_fields as $f) { if ((int)$r[$f] === 1) $ok_count++; }
                            }
                        ?>
                        <tr class="<?= $r['audit_id'] ? ($ok_count === $total_audit ? 'table-success' : 'table-warning') : '' ?>">
                            <td><?= $idx++ ?></td>
                            <td><?= htmlspecialchars($r['church_name'] ?? '-') ?></td>
                            <td><?= $r['bank_date'] ? mb_substr($r['bank_date'], 0, 10) : '-' ?></td>
                            <td style="text-align:right;" class="amount-clickable <?= (float)$r['bank_amount'] < 0 ? 'text-danger' : 'text-success' ?> fw-bold" onclick="showDocDetail(<?= $r['id'] ?>, '<?= $type ?>')"><?= number_format((float)$r['bank_amount'], 0, ',', ' ') ?> Ft</td>
                            <td><?= htmlspecialchars(mb_substr($r['bank_desc'] ?? '-', 0, 60)) ?></td>
                            <td><span class="badge bg-<?= $type === 'cash' ? 'info' : ($r['status'] === 'OK' ? 'success' : ($r['status'] === 'UNCHECKED' ? 'secondary' : 'warning')) ?>"><?= $type === 'cash' ? 'KÉSZPÉNZ' : ($r['status'] ?? 'UNCHECKED') ?></span></td>
                            <td><?= htmlspecialchars($r['inspector_name'] ?? '-') ?></td>
                            <td><?= $r['checked_at'] ? substr($r['checked_at'], 0, 10) : '-' ?></td>
                            <td>
                                <?php if ($r['audit_id']): ?>
                                    <span class="fw-bold <?= $ok_count === $total_audit ? 'text-success' : 'text-warning' ?>" data-sort-value="<?= $total_audit > 0 ? $ok_count / $total_audit : 0 ?>"><?= $ok_count ?>/<?= $total_audit ?></span>
                                    <div class="progress progress-thin"><div class="progress-bar <?= $ok_count === $total_audit ? 'bg-success' : 'bg-warning' ?>" style="width:<?= $total_audit > 0 ? ($ok_count / $total_audit * 100) : 0 ?>%"></div></div>
                                <?php else: ?>
                                    <span class="text-muted" data-sort-value="-1">-</span>
                                <?php endif; ?>
                            </td>
                            <td><button class="btn btn-outline-primary btn-sm py-0 px-1" onclick="openAudit(<?= $r['id'] ?>, '<?= $type ?>')" title="Ellenőrzés">🔍</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-3">Nincs találat</td></tr>
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
            <h6 class="text-secondary"><strong>🧾 OTS könyvelési adatok</strong></h6>
            <div id="ddOtsContent"></div>
          </div>
        </div>
        <div id="ddSinglePanel" class="row g-0" style="display:none;">
          <div class="col-12 detail-col bg-light">
            <h6 class="text-secondary"><strong>🧾 OTS könyvelési adatok</strong></h6>
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
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title">📋 Ellenőrző lista</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="auditBankInfo" class="mb-3 p-2 bg-light rounded small"></div>
                <form id="auditForm">
                    <input type="hidden" name="bank_reconciliation_id" id="auditBankRecId" value="">
                    <input type="hidden" name="ots_record_id" id="auditOtsRecId" value="">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-1">💰 Pénztári bizonylatok</h6>
                            <?php 
                            $left_items = [
                                'cash_voucher_ok' => ['Pénztárbizonylat rendben', 'common'],
                                'date_filled' => ['Dátum kitöltve', 'common'],
                                'amount_ok' => ['Összeg pontos', 'common'],
                                'description_ok' => ['Megnevezés pontos', 'common'],
                                'receipt_number_ok' => ['Bizonylatszám szerepel', 'common'],
                                'decision_number_ok' => ['Határozati szám (ha releváns)', 'common'],
                                'bank_in_ots_ok' => ['Banki tétel OTS-ben szerepel', 'bank_only'],
                            ];
                            foreach ($left_items as $key => $item):
                                $is_bank_only = $item[1] === 'bank_only';
                                $data_req = $is_bank_only ? 'common' : $item[1];
                            ?>
                            <div class="checklist-item <?= $is_bank_only ? 'bank-only-item' : '' ?>" data-req="<?= $data_req ?>">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" value="1" id="chk_<?= $key ?>">
                                    <label class="form-check-label" for="chk_<?= $key ?>"><?= $item[0] ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-1">✍️ Aláírások / Mellékletek</h6>
                            <?php 
                            $right_items = [
                                'signature_treasurer' => ['Pénztáros aláírás', 'common'],
                                'signature_receiver' => ['<span id="lbl_signature_receiver">Felvevő aláírása</span>', 'common'],
                                'signature_authorizer' => ['Utalványozó/engedélyező', 'common'],
                                'signature_auditor' => ['Ellenőr aláírása', 'common'],
                                'stamp_ok' => ['Kiállító bélyegzője / gyülekezet neve', 'common'],
                                'invoice_ok' => ['Számla megvan', 'expense'],
                                'tithe_card_ok' => ['Tizedcédula megvan', 'income'],
                                'fund_designation_ok' => ['Alap megjelölés helyes', 'common'],
                                'supporting_doc_ok' => ['Egyéb melléklet (szerződés, stb.)', 'common'],
                            ];
                            foreach ($right_items as $key => $item): ?>
                            <div class="checklist-item" data-req="<?= $item[1] ?>">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" value="1" id="chk_<?= $key ?>">
                                    <label class="form-check-label" for="chk_<?= $key ?>"><?= $item[0] ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label small fw-bold">Ellenőr neve</label>
                        <input type="text" name="inspector_name" class="form-control form-control-sm" value="<?= htmlspecialchars($_SESSION[GC_USER_FULL_NAME] ?? '') ?>">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
var CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
var CURRENT_TYPE = '<?= $type ?>';
var auditModal = null;
document.addEventListener("DOMContentLoaded", function() {
    auditModal = new bootstrap.Modal(document.getElementById('auditModal'));
});

var _auditData = {};

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
    
    // Bank-only checkbox (bank_in_ots_ok) mutatása/elrejtése
    var bankOnlyItems = document.querySelectorAll('.bank-only-item');
    bankOnlyItems.forEach(function(el) {
        el.style.display = type === 'cash' ? 'none' : '';
    });

    // Adatok betöltése
    fetch(fetchUrl)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        document.getElementById('auditBankInfo').innerHTML = '<strong>' + htmlspecialchars(data.church_name || '-') + '</strong> &middot; ' + htmlspecialchars(data.bank_date || '-') + ' &middot; ' + Number(data.bank_amount).toLocaleString('hu-HU') + ' Ft<br><small>' + htmlspecialchars(data.bank_desc || '') + '</small> &middot; <span class="badge bg-' + (type === 'cash' ? 'info' : (data.status === 'OK' ? 'success' : (data.status === 'UNCHECKED' ? 'secondary' : 'warning'))) + '">' + (type === 'cash' ? 'KÉSZPÉNZ' : htmlspecialchars(data.status || 'UNCHECKED')) + '</span>';
        
        // Checkboxes beállítása
        var fields = ['cash_voucher_ok','date_filled','amount_ok','description_ok','signature_treasurer','signature_receiver','signature_authorizer','signature_auditor','stamp_ok','invoice_ok','tithe_card_ok','receipt_number_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok','bank_in_ots_ok'];
        fields.forEach(function(f) {
            var cb = document.getElementById('chk_' + f);
            if (cb) cb.checked = data.audit && data.audit[f] == 1;
        });
        document.querySelector('[name="inspector_name"]').value = data.audit ? data.audit.inspector_name : '<?= htmlspecialchars($_SESSION[GC_USER_FULL_NAME] ?? '', ENT_QUOTES, 'UTF-8') ?>';
        document.querySelector('[name="notes"]').value = data.audit ? data.audit.notes : '';

        // Dinamikus ellenőrző lista a tétel típusa szerint (bevétel / kiadás)
        var isExpense = Number(data.bank_amount || 0) < 0;
        document.querySelectorAll('.checklist-item[data-req]').forEach(function(el) {
            var req = el.getAttribute('data-req');
            if (req === 'expense') el.style.display = isExpense ? '' : 'none';
            else if (req === 'income') el.style.display = isExpense ? 'none' : '';
        });
        var lblReceiver = document.getElementById('lbl_signature_receiver');
        if (lblReceiver) lblReceiver.textContent = isExpense ? 'Felvevő aláírása' : 'Befizető aláírása';

        auditModal.show();
    })
    .catch(function() {
        alert('Hiba az adatok betöltésekor');
    });
}

var docDetailModal = null;
document.addEventListener("DOMContentLoaded", function() {
    docDetailModal = new bootstrap.Modal(document.getElementById('docDetailModal'));
});

function showDocDetail(id, type) {
    type = type || CURRENT_TYPE;
    document.getElementById('ddLoading').style.display = 'block';
    document.getElementById('ddDoublePanel').style.display = 'none';
    document.getElementById('ddSinglePanel').style.display = 'none';
    document.getElementById('ddError').style.display = 'none';

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
            var cashTx = {
                DATETIME: data.bank_date || (data.DATETIME ? data.DATETIME.substring(0, 10) : '-'),
                adjusted_amount: data.bank_amount || 0,
                AMOUNT: data.bank_amount || 0,
                ots_desc_full: data.bank_desc || '-',
                CASH_DOCUMENT_NUMBER: data.ots_doc || '-',
                DECISION_NUMBER: '-',
                ots_type_name: data.ots_type_name || '-',
                VIA_BANK: 0,
                MODIFIED: '',
                ots_editor_name: data.ots_editor_name || '-',
                fund_name: data.fund_name || '-',
                RECORD_ID: data.RECORD_ID || data.id || 0,
                CHURCH_ID: data.CHURCH_ID || 0
            };
            document.getElementById('ddOtsSingleContent').innerHTML = renderOtsDetailTable([cashTx]);
            document.getElementById('ddSinglePanel').style.display = 'block';
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
        } else {
            document.getElementById('ddBankContent').innerHTML = renderBankDetailTable(data);
            document.getElementById('ddDoublePanel').style.display = 'flex';
            document.getElementById('ddOtsContent').innerHTML = '<div class="alert alert-secondary m-2">Nincs hozzárendelt OTS könyvelési tétel.</div>';
        }
        docDetailModal.show();
    })
    .catch(function() {
        document.getElementById('ddLoading').style.display = 'none';
        document.getElementById('ddError').textContent = 'Hiba az adatok betöltésekor.';
        document.getElementById('ddError').style.display = 'block';
    });
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
                    displayVal = val == 1 ? '✅ Igen' : '❌ Nem (készpénz)';
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
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
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
</body>
</html>
