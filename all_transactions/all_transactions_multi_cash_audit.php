<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../ots/constant.php';
if (session_status() != PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION[GN_LAST_ACTIVE] = time();
require_once __DIR__ . '/../../ots/session_handler.php';
if (!isset($_SESSION[GC_LOGIN_COOKIE])) { http_response_code(401); echo json_encode(['status' => 'ERROR', 'message' => 'Nincs bejelentkezve']); exit; }

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
build_user_context_from_ots();

$conn = get_revizor_conn();
$ots_db = get_ots_conn();

// Ensure table exists
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
    invoice_ok TINYINT(1) DEFAULT 0,
    tithe_card_ok TINYINT(1) DEFAULT 0,
    receipt_number_ok TINYINT(1) DEFAULT 0,
    decision_number_ok TINYINT(1) DEFAULT 0,
    fund_designation_ok TINYINT(1) DEFAULT 0,
    supporting_doc_ok TINYINT(1) DEFAULT 0,
    notes TEXT DEFAULT NULL,
    UNIQUE KEY uk_ots_record (ots_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    header('Content-Type: application/json');
    $record_id = isset($_GET['record_id']) ? intval($_GET['record_id']) : 0;
    if ($record_id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó record_id']);
        exit;
    }
    $stmt = $conn->prepare("SELECT * FROM ots_cash_audit WHERE ots_record_id = ?");
    if (!$stmt) {
        echo json_encode(['status' => 'ERROR', 'message' => 'DB hiba']);
        exit;
    }
    $stmt->bind_param('i', $record_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        echo json_encode(['status' => 'OK', 'data' => $row]);
    } else {
        echo json_encode(['status' => 'OK', 'data' => null]);
    }
    exit;
}

if ($method === 'POST') {
    header('Content-Type: application/json');
    $csrf = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$csrf)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    $record_id = isset($_POST['record_id']) ? intval($_POST['record_id']) : 0;
    if ($record_id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó record_id']);
        exit;
    }

    // Get church_id from OTS transaction
    $church_id = 0;
    $c_stmt = $ots_db->prepare("SELECT CHURCH_ID FROM TRANSACTIONS WHERE RECORD_ID = ? LIMIT 1");
    if ($c_stmt) {
        $c_stmt->bind_param('i', $record_id);
        $c_stmt->execute();
        $c_res = $c_stmt->get_result();
        if ($c_res && $c_row = $c_res->fetch_assoc()) {
            $church_id = intval($c_row['CHURCH_ID']);
        }
    }
    if ($church_id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tranzakció nem található']);
        exit;
    }
    require_church_access($church_id);

    $inspector_name = isset($_POST['inspector_name']) ? trim(mb_substr($_POST['inspector_name'], 0, 100, 'UTF-8')) : '';
    $notes = isset($_POST['notes']) ? trim(mb_substr($_POST['notes'], 0, 5000, 'UTF-8')) : '';

    $fields = ['cash_voucher_ok','date_filled','amount_ok','description_ok','receipt_number_ok','signature_treasurer','signature_receiver','signature_authorizer','invoice_ok','tithe_card_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok'];
    $set_parts = [];
    $set_types = '';
    $set_params = [];
    foreach ($fields as $f) {
        $v = isset($_POST[$f]) && $_POST[$f] === '1' ? 1 : 0;
        $set_parts[] = "$f = ?";
        $set_types .= 'i';
        $set_params[] = $v;
    }
    $set_sql = implode(', ', $set_parts);

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO ots_cash_audit (ots_record_id, church_id, inspector_name, checked_at, $set_sql, notes)
                            VALUES (?, ?, ?, ?, $set_sql, ?)
                            ON DUPLICATE KEY UPDATE
                            inspector_name = VALUES(inspector_name),
                            checked_at = VALUES(checked_at),
                            $set_sql,
                            notes = VALUES(notes)");
    if (!$stmt) {
        echo json_encode(['status' => 'ERROR', 'message' => 'DB hiba: ' . $conn->error]);
        exit;
    }

    $all_types = 'iis' . $set_types . 's' . $set_types;
    $all_params = [$record_id, $church_id, $inspector_name, $now];
    foreach ($set_params as $p) { $all_params[] = $p; }
    $all_params[] = $notes;
    foreach ($set_params as $p) { $all_params[] = $p; }

    $stmt->bind_param($all_types, ...$all_params);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'OK', 'message' => 'Mentve']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Mentési hiba: ' . $stmt->error]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'ERROR', 'message' => 'Nem támogatott metódus']);
