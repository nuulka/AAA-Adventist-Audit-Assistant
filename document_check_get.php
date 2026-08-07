<?php
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../ots/constant.php';
if (session_status() != PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION[GN_LAST_ACTIVE] = time();
require_once __DIR__ . '/../ots/session_handler.php';
if (!isset($_SESSION[GC_LOGIN_COOKIE])) { header('Content-Type: application/json'); echo json_encode(['error' => 'Nincs bejelentkezve']); exit; }

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

$conn = get_revizor_conn();
if ($conn->connect_error) { header('Content-Type: application/json'); echo json_encode(['error' => 'DB hiba']); exit; }
$conn->set_charset("utf8mb4");

$type = isset($_GET['type']) && $_GET['type'] === 'cash' ? 'cash' : 'bank';

if ($type === 'cash') {
    $ots_record_id = intval($_GET['ots_record_id'] ?? 0);
    if ($ots_record_id <= 0) { header('Content-Type: application/json'); echo json_encode(['error' => 'Hibás ID']); exit; }

    $ots_db = get_ots_conn();

    // Költség típusok meghatározása
    $exp_types = [];
    if (defined('GN_TRANSACTION_TYPE_PAYMENT')) $exp_types[] = GN_TRANSACTION_TYPE_PAYMENT;
    if (defined('GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE')) $exp_types[] = GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE;
    if (defined('GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION')) $exp_types[] = GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION;
    if (empty($exp_types)) {
        $tt_res = $ots_db->query("SELECT id FROM TRANSACTION_TYPE WHERE debit = 1");
        if ($tt_res) { while ($tt = $tt_res->fetch_assoc()) { $exp_types[] = $tt['id']; } }
    }
    if (empty($exp_types)) { $exp_types = [-1]; }
    $exp_types_str = implode(',', array_map('intval', array_filter($exp_types, 'is_numeric')));
    if (empty($exp_types_str)) { $exp_types_str = '-1'; }

    // OTS rekord lekérése (készpénz)
    $adj_sql = "IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)";
    $stmt_ots = $ots_db->prepare("SELECT T.RECORD_ID, T.CHURCH_ID, T.VIA_BANK, T.TYPE AS ots_type, $adj_sql AS bank_amount,
            T.DATETIME AS bank_date, T.CASH_DOCUMENT_NUMBER AS ots_doc,
            TRIM(CONCAT(
                IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''),
                ' ', IFNULL(nt1.NAME, ''), ' ', IFNULL(nt2.NAME, '')
            )) AS bank_desc,
            tt.NAME AS ots_type_name,
            u.NAME AS ots_editor_name,
            funds.NAME AS fund_name
     FROM TRANSACTIONS T
     LEFT JOIN PERSONS p ON T.PERSON_ID = p.id
     LEFT JOIN NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
     LEFT JOIN NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
     LEFT JOIN TRANSACTION_TYPE tt ON T.TYPE = tt.id
     LEFT JOIN USERS u ON T.EDITED_BY = u.id
     LEFT JOIN FUNDS funds ON T.FUND_ID = funds.id
     WHERE T.RECORD_ID = ?");
    if (!$stmt_ots) { header('Content-Type: application/json'); echo json_encode(['error' => 'DB hiba']); exit; }
    $stmt_ots->bind_param('i', $ots_record_id);
    $stmt_ots->execute();
    $ots_res = $stmt_ots->get_result();
    if ($ots_res->num_rows === 0) { header('Content-Type: application/json'); echo json_encode(['error' => 'Nem található']); exit; }
    $row = $ots_res->fetch_assoc();
    $church_id = intval($row['CHURCH_ID'] ?? 0);
    $row['id'] = $row['RECORD_ID'];
    $row['status'] = '-';

    $cfg = load_app_config();
    $row['church_name'] = (!empty($cfg['churches'][$church_id])) ? $cfg['churches'][$church_id] : null;
    require_church_access($church_id);

    // ots_cash_audit adatok lekérése
    $audit = null;
    $stmt_ac = $conn->prepare("SELECT * FROM ots_cash_audit WHERE ots_record_id = ?");
    if ($stmt_ac) {
        $stmt_ac->bind_param('i', $ots_record_id);
        $stmt_ac->execute();
        $audit_res = $stmt_ac->get_result();
        if ($audit_res && $audit_res->num_rows > 0) { $audit = $audit_res->fetch_assoc(); }
    }
    $row['audit'] = $audit;

    header('Content-Type: application/json');
    echo json_encode($row);
    exit;
}

$bank_id = intval($_GET['bank_reconciliation_id'] ?? 0);
if ($bank_id <= 0) { header('Content-Type: application/json'); echo json_encode(['error' => 'Hibás ID']); exit; }

// Bank rekord lekérése
$stmt = $conn->prepare("SELECT br.*
        FROM bank_reconciliation br
        WHERE br.id = ?");
if (!$stmt) { header('Content-Type: application/json'); echo json_encode(['error' => 'DB hiba']); exit; }
$stmt->bind_param('i', $bank_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) { header('Content-Type: application/json'); echo json_encode(['error' => 'Nem található']); exit; }
$row = $res->fetch_assoc();
// Church name feloldása konfigból
$cfg = load_app_config();
$church_id = intval($row['church_id'] ?? 0);
$row['church_name'] = (!empty($cfg['churches'][$church_id])) ? $cfg['churches'][$church_id] : null;
// scope check: ensure user can access this record's church
require_church_access($church_id);

// Audit adatok lekérése
$audit = null;
$stmt_ac = $conn->prepare("SELECT * FROM audit_checklist WHERE bank_reconciliation_id = ?");
if ($stmt_ac) {
    $stmt_ac->bind_param('i', $bank_id);
    $stmt_ac->execute();
    $audit_res = $stmt_ac->get_result();
    if ($audit_res && $audit_res->num_rows > 0) { $audit = $audit_res->fetch_assoc(); }
}

$row['audit'] = $audit;

// Ha részletes adatok kellenek (OTS tranzakciók)
if (isset($_GET['detail'])) {
    $ots_data = null;
    $is_bank = false;
    $ots_db = get_ots_conn();

    // 1. Több tételes párosítás (bank_reconciliation_items)
    $record_ids = [];
    $stmt_items = $conn->prepare("SELECT record_id FROM bank_reconciliation_items WHERE reconciliation_id = ?");
    if ($stmt_items) {
        $stmt_items->bind_param('i', $bank_id);
        $stmt_items->execute();
        $items_res = $stmt_items->get_result();
        while ($it = $items_res->fetch_assoc()) {
            $record_ids[] = intval($it['record_id']);
        }
    }

    // 2. Egyedi párosítás (bank_reconciliation.ots_record_id)
    if (empty($record_ids) && !empty($row['ots_record_id'])) {
        $record_ids[] = intval($row['ots_record_id']);
    }

    if (!empty($record_ids)) {
        $exp_types_str = '6,7,9,10';
        $id_placeholders = implode(',', array_fill(0, count($record_ids), '?'));
        $sql_ots = "SELECT T.*,
                           IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT) AS adjusted_amount,
                           TRIM(CONCAT(
                               IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''),
                               ' ', IFNULL(nt1.NAME, ''), ' ', IFNULL(nt2.NAME, '')
                           )) AS ots_desc_full,
                           tt.NAME AS ots_type_name,
                           u.NAME AS ots_editor_name,
                           funds.NAME AS fund_name
                    FROM TRANSACTIONS T
                    LEFT JOIN PERSONS p ON T.PERSON_ID = p.id
                    LEFT JOIN NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
                    LEFT JOIN NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
                    LEFT JOIN TRANSACTION_TYPE tt ON T.TYPE = tt.id
                    LEFT JOIN USERS u ON T.EDITED_BY = u.id
                    LEFT JOIN FUNDS funds ON T.FUND_ID = funds.id
                    WHERE T.RECORD_ID IN ($id_placeholders)
                    ORDER BY T.DATETIME ASC";
        $stmt_ots = $ots_db->prepare($sql_ots);
        if ($stmt_ots) {
            $types = str_repeat('i', count($record_ids));
            $stmt_ots->bind_param($types, ...$record_ids);
            $stmt_ots->execute();
            $ots_res = $stmt_ots->get_result();
            $ots_data = [];
            while ($o = $ots_res->fetch_assoc()) {
                if ($o['VIA_BANK'] == 1) $is_bank = true;
                $ots_data[] = $o;
            }
            // Ellenőrizzük, hogy az OTS tételek összege megegyezik-e a banki összeggel
            // Ha nem, akkor hibás párosítás — ne mutassuk az OTS panelt
            $sum_ots = 0.0;
            foreach ($ots_data as $o) {
                $sum_ots += floatval($o['adjusted_amount']);
            }
            $bank_amt = floatval($row['bank_amount']);
            if (abs(abs($sum_ots) - abs($bank_amt)) > 1.0) {
                $ots_data = null;
            }
        }
    }
    $row['ots_data'] = $ots_data;
    $row['is_bank'] = $is_bank;
}

header('Content-Type: application/json');
echo json_encode($row);
