<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/../ots/constant.php';

// Indítsuk a session-t a session_handler.php előtt, és frissítsük a last active time-ot,
// hogy a 10 perces OTS timeout ne üsse ki a GC_LOGIN_COOKIE-t miközben a revízort használjuk (60 perces timeout)
if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION[GN_LAST_ACTIVE] = time();

require_once __DIR__ . '/../ots/session_handler.php';

if (!isset($_SESSION[GC_LOGIN_COOKIE])) {
    $is_post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($is_post) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['status' => 'SESSION_EXPIRED', 'message' => 'A munkamenet lejárt.']);
    } else {
        header('Location: login.php');
    }
    exit;
}

// Load common auth helpers and user context
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/session.php';
if (is_file(__DIR__ . '/lib/announcement.php')) {
    require_once __DIR__ . '/lib/announcement.php';
}
// populate accessible churches for the session
build_user_context_from_ots();
$accessible_church_ids = get_accessible_church_ids();

$session_remaining = ensure_revizor_session_timeout();
ensure_revizor_csrf_token();

$conn = get_revizor_conn();
$ots_db = get_ots_conn();

log_activity('page_view', ['page' => 'reconciliation']);

// Custom patterns CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'custom_patterns') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    header('Content-Type: application/json');
    $sub = $_POST['sub'] ?? '';

    if ($sub === 'list') {
        $church_id = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;
        if ($church_id <= 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Invalid church_id']);
            exit;
        }
        // admin or revizor of that church can list patterns
        require_church_access($church_id);
        $stmt_cp = $conn->prepare("SELECT id, church_id, bank_pattern, ots_pattern, label FROM custom_patterns WHERE church_id = ? ORDER BY id");
        if ($stmt_cp) {
            $stmt_cp->bind_param('i', $church_id);
            $stmt_cp->execute();
            $res = $stmt_cp->get_result();
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Lekérdezési hiba']);
            exit;
        }
        $items = [];
        while ($r = $res->fetch_assoc()) {
            $items[] = $r;
        }
        echo json_encode(['status' => 'OK', 'items' => $items]);
        exit;
    }

    if ($sub === 'add') {
        $church_id = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;
        $bank_pattern = trim($_POST['bank_pattern'] ?? '');
        $ots_pattern = trim($_POST['ots_pattern'] ?? '');
        $label = trim($_POST['label'] ?? '');
        // only admin can add custom patterns
        if (!is_admin()) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Only admin can add custom patterns']);
            exit;
        }
        if ($church_id <= 0 || empty($bank_pattern) || empty($ots_pattern)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'church_id, bank_pattern and ots_pattern required']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO custom_patterns (church_id, bank_pattern, ots_pattern, label) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $church_id, $bank_pattern, $ots_pattern, $label);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'OK', 'id' => $stmt->insert_id]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Mentés sikertelen']);
        }
        exit;
    }

    if ($sub === 'edit') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $bank_pattern = trim($_POST['bank_pattern'] ?? '');
        $ots_pattern = trim($_POST['ots_pattern'] ?? '');
        $label = trim($_POST['label'] ?? '');
        // only admin can edit
        if (!is_admin()) { echo json_encode(['status' => 'ERROR', 'message' => 'Only admin can edit']); exit; }
        if ($id <= 0 || empty($bank_pattern) || empty($ots_pattern)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'id, bank_pattern and ots_pattern required']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE custom_patterns SET bank_pattern=?, ots_pattern=?, label=? WHERE id=?");
        $stmt->bind_param("sssi", $bank_pattern, $ots_pattern, $label, $id);
        $stmt->execute();
        echo json_encode(['status' => 'OK']);
        exit;
    }

    if ($sub === 'delete') {
        // only admin can delete
        if (!is_admin()) { echo json_encode(['status' => 'ERROR', 'message' => 'Only admin can delete']); exit; }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'id required']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM custom_patterns WHERE id = ?");
        if ($stmt) { $stmt->bind_param('i', $id); $stmt->execute(); }
        echo json_encode(['status' => 'OK']);
        exit;
    }

    echo json_encode(['status' => 'ERROR', 'message' => 'Unknown sub action']);
    exit;
}

// BIZTONSÁGI JAVÍTÁS: Mezők hozzáadása a részletes adatokhoz, ha még nem léteznek (MySQL 8 kompatibilis módon)
$existing_columns = [];
$columns_res = $conn->query("SHOW COLUMNS FROM bank_reconciliation");
if ($columns_res) {
    while ($col_row = $columns_res->fetch_assoc()) {
        $existing_columns[] = $col_row['Field'];
    }
}

if (!in_array('bank_init_name', $existing_columns)) {
    $conn->query("ALTER TABLE bank_reconciliation 
        ADD COLUMN bank_init_name VARCHAR(150),
        ADD COLUMN bank_init_acc VARCHAR(50),
        ADD COLUMN bank_ben_name VARCHAR(150),
        ADD COLUMN bank_ben_acc VARCHAR(50)");
}

// BIZTONSÁGI JAVÍTÁS: Módosítjuk a status oszlopot, hogy minden új státuszt (pl. CSUSZAS, OSSZEVONT) el tudjon menteni hiba nélkül!
$conn->query("CREATE TABLE IF NOT EXISTS bank_reconciliation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    row_hash VARCHAR(32) DEFAULT NULL,
    church_id INT NOT NULL,
    bank_date DATE DEFAULT NULL,
    bank_amount DECIMAL(12,2) DEFAULT NULL,
    bank_desc TEXT DEFAULT NULL,
    bank_ext_acc VARCHAR(50) DEFAULT NULL,
    bank_ext_name VARCHAR(255) DEFAULT NULL,
    bank_ext_ref VARCHAR(100) DEFAULT NULL,
    bank_init_name VARCHAR(255) DEFAULT NULL,
    bank_init_acc VARCHAR(50) DEFAULT NULL,
    bank_ben_name VARCHAR(255) DEFAULT NULL,
    bank_ben_acc VARCHAR(50) DEFAULT NULL,
    ots_date DATE DEFAULT NULL,
    ots_doc VARCHAR(50) DEFAULT NULL,
    ots_record_id INT DEFAULT NULL,
    ots_amount DECIMAL(12,2) DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'UNCHECKED',
    comment TEXT DEFAULT NULL,
    updated_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_row_hash (row_hash),
    INDEX idx_church_id (church_id),
    INDEX idx_status (status),
    INDEX idx_ots_record_id (ots_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$col_check = $conn->query("SHOW COLUMNS FROM bank_reconciliation LIKE 'status'");
if ($col_check) {
    $col_def = $col_check->fetch_assoc();
    // Only ALTER if the type is not already VARCHAR(20)
    if ($col_def && !str_starts_with($col_def['Type'], 'varchar(20)')) {
        $conn->query("ALTER TABLE bank_reconciliation MODIFY COLUMN status VARCHAR(20) DEFAULT 'UNCHECKED'");
    }
}

// Segédtáblák auto-létrehozása
$conn->query("CREATE TABLE IF NOT EXISTS church_bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    church_id INT NOT NULL,
    bank_account VARCHAR(50) NOT NULL,
    bank_account_clean VARCHAR(50) DEFAULT '',
    bank_name VARCHAR(100) DEFAULT '',
    account_type VARCHAR(20) DEFAULT 'CHECKING',
    skip_import TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_church_account (church_id, bank_account),
    INDEX idx_account_clean (bank_account_clean)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS provider_keywords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_keyword VARCHAR(100) NOT NULL,
    ots_keyword VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_provider (bank_keyword, ots_keyword)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS custom_patterns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    church_id INT NOT NULL,
    bank_pattern VARCHAR(255) NOT NULL,
    ots_pattern VARCHAR(255) NOT NULL,
    label VARCHAR(100) DEFAULT '',
    UNIQUE KEY uq_church_pattern (church_id, bank_pattern(100), ots_pattern(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS bank_reconciliation_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reconciliation_id INT NOT NULL,
    record_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    INDEX idx_reconciliation (reconciliation_id),
    FOREIGN KEY (reconciliation_id) REFERENCES bank_reconciliation(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS audit_checklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_reconciliation_id INT NOT NULL,
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
    bank_in_ots_ok TINYINT(1) DEFAULT 0,
    notes TEXT DEFAULT NULL,
    UNIQUE KEY uk_bank_rec (bank_reconciliation_id),
    FOREIGN KEY (bank_reconciliation_id) REFERENCES bank_reconciliation(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// ALTER for existing tables that lack the new columns
$ac_new_cols = ['bank_in_ots_ok','signature_auditor','stamp_ok','tithe_source_asked'];
foreach ($ac_new_cols as $ac_col) {
    $ac_cols = $conn->query("SHOW COLUMNS FROM audit_checklist LIKE '$ac_col'");
    if (!$ac_cols || $ac_cols->num_rows === 0) {
        $conn->query("ALTER TABLE audit_checklist ADD COLUMN $ac_col TINYINT(1) DEFAULT 0");
    }
}

// Auto-match log tábla
$conn->query("CREATE TABLE IF NOT EXISTS auto_match_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    church_id INT DEFAULT NULL,
    mode VARCHAR(20) DEFAULT 'progressive',
    total_unchecked INT DEFAULT 0,
    matched INT DEFAULT 0,
    details JSON DEFAULT NULL,
    elapsed_sec DECIMAL(6,2) DEFAULT 0,
    run_by VARCHAR(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// OTS Kiadás típusok meghatározása (az előjel helyes számításához)
$exp_types = [];
@include_once(__DIR__ . "/../constant.php");
if (defined('GN_TRANSACTION_TYPE_PAYMENT')) $exp_types[] = GN_TRANSACTION_TYPE_PAYMENT;
if (defined('GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE')) $exp_types[] = GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE;
if (defined('GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION')) $exp_types[] = GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION;

if (empty($exp_types)) {
    $tt_res = $ots_db->query("SELECT id, NAME FROM TRANSACTION_TYPE");
    if ($tt_res) {
        while($tt = $tt_res->fetch_assoc()) {
            $name = mb_strtolower($tt['NAME'], 'UTF-8');
            if (strpos($name, 'kiadás') !== false || strpos($name, 'kifizetés') !== false || strpos($name, 'költség') !== false || strpos($name, 'levonás') !== false) {
                $exp_types[] = $tt['id'];
            }
        }
    }
}
if (empty($exp_types)) { $exp_types = [-1]; }
$exp_types_str = implode(',', array_map('intval', array_filter($exp_types, 'is_numeric')));
if (empty($exp_types_str)) { $exp_types_str = '-1'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'UNCHECKED';
    $allowed_statuses = ['UNCHECKED', 'OK', 'HIANY', 'ELTERES', 'CSUSZAS', 'OSSZEVONT'];
    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'UNCHECKED';
    }
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
    $ots_doc_input = isset($_POST['ots_doc']) ? trim($_POST['ots_doc']) : '';
    $comment = mb_substr($comment, 0, 1000, 'UTF-8');
    $ots_doc_input = mb_substr($ots_doc_input, 0, 50, 'UTF-8');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo "CSRF token mismatch";
        exit;
    }
    $user = $_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen';

    if ($id > 0) {
        // Gyülekezet-hozzáférés ellenőrzése minden mentési ág előtt (IDOR védelem)
        $c_id = 0;
        $stmt_ch = $conn->prepare("SELECT church_id FROM bank_reconciliation WHERE id = ?");
        if ($stmt_ch) {
            $stmt_ch->bind_param('i', $id);
            $stmt_ch->execute();
            $rr = $stmt_ch->get_result();
            if ($rr && ($r = $rr->fetch_assoc())) {
                $c_id = intval($r['church_id']);
            }
        }
        if ($c_id > 0) {
            require_church_access($c_id);
        }

        if ($status === 'UNCHECKED' && empty($ots_doc_input)) {
            // Ha visszaállítják Feldolgozatlanra és nincs bizonylatszám, töröljük az OTS adatokat (Tiszta lap)
            $upd = $conn->prepare("UPDATE bank_reconciliation SET status=?, comment=?, updated_by=?, ots_date=NULL, ots_doc='', ots_amount=NULL WHERE id=?");
            if ($upd) { $upd->bind_param('sssi', $status, $comment, $user, $id); $upd->execute(); }
        } else {
            if (!empty($ots_doc_input)) {
                // Kézi bizonylatszám párosítás

                // Megkeressük az OTS-ben a bizonylatot (akár bank, akár pénztár)
                $stmt_ots = $ots_db->prepare("SELECT DATE(MAX(DATETIME)) as ots_date, SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)) as ots_amount FROM TRANSACTIONS T WHERE CHURCH_ID = ? AND CASH_DOCUMENT_NUMBER = ? GROUP BY RECORD_ID LIMIT 1");
                if ($stmt_ots) {
                    $stmt_ots->bind_param('is', $c_id, $ots_doc_input);
                    $stmt_ots->execute();
                    $ots_res = $stmt_ots->get_result();
                } else {
                    echo "Lekérdezési hiba";
                    exit;
                }

                if ($ots_res && $ots_res->num_rows > 0) {
                    $ots_data = $ots_res->fetch_assoc();
                    $o_date = $ots_data['ots_date'];
                    $o_amt = $ots_data['ots_amount'];
                    if ($status === 'UNCHECKED') { $status = 'OK'; }
                    $upd = $conn->prepare("UPDATE bank_reconciliation SET status=?, comment=?, updated_by=?, ots_date=?, ots_doc=?, ots_amount=? WHERE id=?");
                    if ($upd) { $upd->bind_param('ssssdii', $status, $comment, $user, $o_date, $ots_doc_input, $o_amt, $id); $upd->execute(); }
                } else {
                    $upd = $conn->prepare("UPDATE bank_reconciliation SET status=?, comment=?, updated_by=?, ots_doc=? WHERE id=?");
                    if ($upd) { $upd->bind_param('ssssi', $status, $comment, $user, $ots_doc_input, $id); $upd->execute(); }
                }
            } else {
                $upd = $conn->prepare("UPDATE bank_reconciliation SET status=?, comment=?, updated_by=? WHERE id=?");
                if ($upd) { $upd->bind_param('sssi', $status, $comment, $user, $id); $upd->execute(); }
            }
        }
        echo "OK";
    }
    exit;
}

// KÉZI NYOMOZÁS KONKRÉT ÖSSZEGRE AZ OTS-BEN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_ots_amount') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    header('Content-Type: application/json');
    $amount = abs(floatval($_POST['amount']));
    // restrict to accessible churches for non-admin users
    if (!is_admin()) {
        $allowed = get_accessible_church_ids();
        if (empty($allowed)) { echo json_encode(['status'=>'ERROR','message'=>'No accessible churches']); exit; }
        $allowed = array_values(array_filter(array_map('intval', $allowed), function ($v) { return $v > 0; }));
        if (empty($allowed)) { echo json_encode(['status'=>'ERROR','message'=>'No accessible churches']); exit; }
        $church_placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $church_where = "AND T.CHURCH_ID IN ($church_placeholders)";
    } else {
        $church_where = '';
    }

    // Felhasznált (már párosított) OTS tételek jelölése
    $used_map = [];
    $stmt_used = $conn->query("SELECT br.ots_record_id AS rid, br.church_id AS cid, br.id AS bid FROM bank_reconciliation br WHERE br.ots_record_id IS NOT NULL AND br.ots_record_id <> 0");
    if ($stmt_used) {
        while ($u = $stmt_used->fetch_assoc()) { $used_map[(int)$u['cid']][(int)$u['rid']][] = (int)$u['bid']; }
    }
    $stmt_used2 = $conn->query("SELECT bi.record_id AS rid, br.church_id AS cid, bi.reconciliation_id AS bid FROM bank_reconciliation_items bi JOIN bank_reconciliation br ON bi.reconciliation_id = br.id");
    if ($stmt_used2) {
        while ($u = $stmt_used2->fetch_assoc()) { $used_map[(int)$u['cid']][(int)$u['rid']][] = (int)$u['bid']; }
    }

    $sql = "SELECT T.CHURCH_ID, T.RECORD_ID, DATE(MAX(T.DATETIME)) as ots_date, T.CASH_DOCUMENT_NUMBER as ots_doc, 
                    SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)) as total_amount, T.VIA_BANK,
                    TRIM(CONCAT(
                        IFNULL(CONCAT_WS(' ', MAX(p.NAME_PREFIX), MAX(p.NAME), MAX(p.NAME_SUFFIX)), ''), 
                        ' ', 
                        IFNULL(MAX(nt1.NAME), ''),
                        ' ',
                        IFNULL(MAX(nt2.NAME), '')
                   )) AS ots_desc
            FROM TRANSACTIONS T
            LEFT JOIN PERSONS p ON T.PERSON_ID = p.id
            LEFT JOIN NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
            LEFT JOIN NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
             WHERE (T.CASH_DOCUMENT_NUMBER != '' OR T.VIA_BANK <> 0) $church_where
             GROUP BY T.RECORD_ID, T.CHURCH_ID, T.CASH_DOCUMENT_NUMBER, T.VIA_BANK
             HAVING ABS(SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT))) = ?
             ORDER BY ots_date DESC LIMIT 25";
            
    $stmt = $ots_db->prepare($sql);
    if ($stmt) {
        if (!is_admin()) {
            $types = str_repeat('i', count($allowed)) . 'd';
            $params = array_merge($allowed, [$amount]);
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param("d", $amount);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $results = [];
        while($row = $res->fetch_assoc()) {
            $row['church_name'] = $church_names_map[$row['CHURCH_ID']] ?? null;
            $rid_int = (int)$row['RECORD_ID'];
            $cid_int = (int)$row['CHURCH_ID'];
            if (isset($used_map[$cid_int][$rid_int])) {
                $bank_ids = array_unique($used_map[$cid_int][$rid_int]);
                sort($bank_ids);
                $row['_used'] = true;
                $row['_used_count'] = count($bank_ids);
                $row['_used_bank_ids'] = implode(',', $bank_ids);
            }
            $results[] = $row;
        }
        echo json_encode(['status' => 'OK', 'data' => $results]);
    } else {
        echo json_encode(['status' => 'ERROR']);
    }
    exit;
}

// TÖMEGES JÓVÁHAGYÁS (Látható CSÚSZÁS tételek OK-ra állítása)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_approve') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    header('Content-Type: application/json');
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    if (is_array($ids) && count($ids) > 0) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; })));
        if (empty($ids)) {
            echo json_encode(['status' => 'ERROR']);
            exit;
        }
        $user = $_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen';
        // Scope check: ensure all records belong to accessible churches for non-admins
        if (!is_admin()) {
            $ids_placeholders = implode(',', array_fill(0, count($ids), '?'));
            $chk = $conn->prepare("SELECT DISTINCT church_id FROM bank_reconciliation WHERE id IN ($ids_placeholders)");
            $allowed = get_accessible_church_ids();
            if (!$chk) {
                echo json_encode(['status' => 'ERROR', 'message' => 'Lekérdezési hiba']);
                exit;
            }
            $types = str_repeat('i', count($ids));
            $chk->bind_param($types, ...$ids);
            $chk->execute();
            $chk_res = $chk->get_result();
            while ($rowchk = $chk_res->fetch_assoc()) {
                if (!in_array(intval($rowchk['church_id']), $allowed, true)) {
                    echo json_encode(['status' => 'ERROR', 'message' => 'Forbidden: some records are outside your scope']);
                    exit;
                }
            }
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE bank_reconciliation SET status = 'OK', updated_by = ? WHERE id IN ($placeholders) AND status = 'CSUSZAS'";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $types = 's' . str_repeat('i', count($ids));
            $params = array_merge([$user], array_map('intval', $ids));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            echo json_encode(['status' => 'OK', 'count' => $conn->affected_rows]);
            exit;
        }
    }
    echo json_encode(['status' => 'ERROR']);
    exit;
}

// UTÓLAGOS AUTOMATIKUS PÁROSÍTÁS LOGIKA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'auto_match') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    header('Content-Type: application/json');
    // only admin may run auto-match
    if (!is_admin()) { echo json_encode(['status' => 'ERROR', 'message' => 'Only admin may run auto-match']); exit; }
    @set_time_limit(300);

    $mode = $_POST['match_mode'] ?? 'progressive';
    $custom_days = isset($_POST['custom_days']) ? intval($_POST['custom_days']) : 0;
    $filter_church_id = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;
    $all_churches = isset($_POST['all_churches']) && $_POST['all_churches'] === '1';

    if (!$all_churches && $filter_church_id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Előbb válassz ki egy gyülekezetet a szűrőben!']);
        exit;
    }

    // Segédtábla: transfers_to_conference utalás felismerése
    $month_query_tc = null;
    $month_stmt_tc = null;

    // Ha progresszív, akkor 4 körben fut le. Ha egyedi, csak 1 körben az adott nappal.
    $passes = ($mode === 'progressive') ? [0, 3, 6, 12, 35, 60, 'text'] : [$custom_days];

    if ($all_churches) {
        $um_stmt = $conn->prepare("SELECT id, church_id, bank_date, bank_amount, bank_desc, bank_ext_name, bank_ext_ref, bank_init_acc, bank_ext_acc FROM bank_reconciliation WHERE status = 'UNCHECKED'");
    } else {
        $um_stmt = $conn->prepare("SELECT id, church_id, bank_date, bank_amount, bank_desc, bank_ext_name, bank_ext_ref, bank_init_acc, bank_ext_acc FROM bank_reconciliation WHERE status = 'UNCHECKED' AND church_id = ?");
    }
    $unmatched = false;
    if ($um_stmt) {
        if (!$all_churches) { $um_stmt->bind_param('i', $filter_church_id); }
        $um_stmt->execute();
        $unmatched = $um_stmt->get_result();
    }
    $stats = ['pass_0' => 0, 'pass_3' => 0, 'pass_6' => 0, 'pass_12' => 0, 'pass_35' => 0, 'pass_60' => 0, 'pass_text' => 0, 'pass_tc' => 0, 'custom' => 0];
    $total_matched = 0;
    $total_records = $unmatched ? $unmatched->num_rows : 0;

    // Load provider keywords
    $provider_kws = [];
    $pk_res = $conn->query("SELECT bank_keyword, ots_keyword FROM provider_keywords");
    if ($pk_res) {
        while ($pk = $pk_res->fetch_assoc()) {
            $provider_kws[] = $pk;
        }
    }

    // progress file for frontend polling
    $progress_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revizor_progress_' . session_id() . '.json';
    @file_put_contents($progress_file, json_encode(['status'=>'RUNNING','matched'=>0,'total_unchecked'=>$total_records,'current_church'=>null,'processed_churches'=>0,'processed_records'=>0,'time_sec'=>0]));
    $processed_records = 0;
    $start_time = microtime(true);
    session_write_close(); // release session lock so match_progress.php polling can proceed

    // Load custom_patterns (church-specific)
    $custom_patterns_by_church = [];
    $cp_res = $conn->query("SELECT church_id, bank_pattern, ots_pattern, label FROM custom_patterns ORDER BY church_id, id");
    if ($cp_res) {
        while ($cp = $cp_res->fetch_assoc()) {
            $cid = $cp['church_id'];
            if (!isset($custom_patterns_by_church[$cid])) $custom_patterns_by_church[$cid] = [];
            $custom_patterns_by_church[$cid][] = $cp;
        }
    }

    // transfers_to_conference prepared query
    $tc_query = "SELECT tc.AMOUNT AS ots_amount,
                        CONCAT(tc.YEAR, '-', LPAD(tc.MONTH, 2, '0'), '-', LPAD(tc.DAY, 2, '0')) AS ots_date,
                        tc.CASH_DOCUMENT_NUMBER AS ots_doc,
                        tc.id AS tc_id,
                        CONCAT(tc.YEAR, '. ', tc.MONTH, '. havi konferencia utalás') AS ots_desc
                  FROM transfers_to_conference tc
                  WHERE tc.CHURCH_ID = ?
                    AND tc.VIA_BANK = 1
                    AND tc.AMOUNT = ABS(?)
                    AND CONCAT(tc.YEAR, '-', LPAD(tc.MONTH, 2, '0'), '-', LPAD(tc.DAY, 2, '0')) BETWEEN ? AND ?";
    $tc_stmt = $ots_db->prepare($tc_query);

    if ($unmatched && $unmatched->num_rows > 0) {
        while ($row = $unmatched->fetch_assoc()) {
            $id = $row['id']; $church_id = $row['church_id']; $bank_date = $row['bank_date']; 
            $bank_amount = $row['bank_amount']; $b_desc = $row['bank_desc']; $b_name = $row['bank_ext_name'];
            $bank_ext_ref = $row['bank_ext_ref'] ?? '';
            $bank_init_acc = $row['bank_init_acc'] ?? '';
            $bank_ext_acc = $row['bank_ext_acc'] ?? '';
            
            $used_ots_ids = [];
            $used_res = $conn->query("SELECT ots_record_id FROM bank_reconciliation WHERE ots_record_id IS NOT NULL UNION SELECT record_id FROM bank_reconciliation_items");
            if ($used_res) { while ($u = $used_res->fetch_assoc()) { $used_ots_ids[] = (int)($u['ots_record_id'] ?? $u['record_id']); } }
            $used_list = empty($used_ots_ids) ? '0' : implode(',', $used_ots_ids);

            foreach ($passes as $days) {
                if ($days === 'text') {
                    // SZÖVEGES KUTATÁS (Név, Közlemény, Határozati szám, Szolgáltatók) +/- 30 napban
                    $start_date = date('Y-m-d', strtotime("$bank_date -30 days"));
                    $end_date = date('Y-m-d', strtotime("$bank_date +30 days"));
                    
                    $ots_query = "SELECT RECORD_ID, MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date, 
                                   MAX(T.DECISION_NUMBER) AS ots_decision,
                                   SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)) as ots_amount,
                                   TRIM(CONCAT(
                                       IFNULL((SELECT CONCAT_WS(' ', NAME_PREFIX, NAME, NAME_SUFFIX) FROM PERSONS WHERE id = MAX(T.PERSON_ID)), ''), 
                                       ' ', 
                                       IFNULL((SELECT NAME FROM NAMES_OF_TRANSACTION WHERE id = MAX(T.NAME_ID)), ''),
                                       ' ',
                                       IFNULL((SELECT NAME FROM NAMES_OF_TRANSACTION WHERE id = MAX(T.NAME2_ID)), '')
                                   )) AS ots_desc
                                   FROM TRANSACTIONS T
                                   WHERE CHURCH_ID = ? AND DATETIME BETWEEN ? AND ? AND VIA_BANK <> 0 
                                   AND ABS(PERIOD_DIFF(EXTRACT(YEAR_MONTH FROM ?), EXTRACT(YEAR_MONTH FROM T.DATETIME))) <= 1
                                   AND T.RECORD_ID NOT IN ($used_list)
                                   GROUP BY RECORD_ID";
                    
                    $stmt_ots = $ots_db->prepare($ots_query);
                    if ($stmt_ots) {
                        $stmt_ots->bind_param("isss", $church_id, $start_date, $end_date, $bank_date);
                        $stmt_ots->execute();
                        $ots_result = $stmt_ots->get_result();
                        
                        $b_text = mb_strtoupper($b_desc . ' ' . $b_name, 'UTF-8');
                        $b_words = preg_split('/[\s,\.\-\/]+/u', $b_text, -1, PREG_SPLIT_NO_EMPTY);
                        
                        // Rezsi / közüzemi kulcsszó csoportok
                        $keyword_groups = [
                            'rezsi' => ['VÍZ', 'GÁZ', 'VILLANY', 'REZSI', 'KÖZÖS', 'MÉRŐ', 'FŰTÉS', 'ENERGIA', 'SZOLGÁLTATÓ', 'ÁRAM', 'GŐZ'],
                            'egyhaz' => ['ADOMÁNY', 'FELAJÁNLÁS', 'TÁMOGATÁS', 'TIZED', 'PERSELY', 'GYŰJTÉS', 'ALAPÍTVÁNY', 'MISSZIÓ'],
                            'berlet' => ['LAKÁSBÉRLET', 'BÉRLETI', 'ALBÉRLET', 'BÉRBEADÁS'],
                            'egyeb' => ['BIZTOSÍTÁS', 'TAGDÍJ', 'TANFOLYAM', 'TÁBOR', 'RÉSZVÉTELI']
                        ];
                        
                        $best_match = null;
                        $best_score = 0;
                        $text_score = 0;
                        $min_amt_diff = PHP_INT_MAX;
                        $same_amount_count = 0;
                        $is_large_amount = (abs($bank_amount) >= 100000);
                        
                        // --- T/A minta keresése (pl. "T:29200, A:3800 december Matlák Tímea") ---
                        $ta_matched = false;
                        if (preg_match('/(?:T|TIZED)\s*[:\.]\s*(\d+)\s*[,;\.\s]+\s*(?:A|ADOMÁNY|ADOMÁNY)\s*[:\.]\s*(\d+)/iu', $b_text, $ta_parts)) {
                            $t_val = (float)$ta_parts[1];
                            $a_val = (float)$ta_parts[2];
                            if (abs(($t_val + $a_val) - abs($bank_amount)) < 0.01) {
                                $after_pattern = substr($b_text, strpos($b_text, $ta_parts[0]) + strlen($ta_parts[0]));
                                $person_search = trim(preg_replace('/\s*(JANUÁR|FEBRUÁR|MÁRCIUS|ÁPRILIS|MÁJUS|JÚNIUS|JÚLIUS|AUGUSZTUS|SZEPTEMBER|OKTÓBER|NOVEMBER|DECEMBER)\s*$/iu', '', $after_pattern));
                                if (!empty($person_search)) {
                                    $like_name = '%' . $person_search . '%';
                                    $ta_q = "SELECT T.RECORD_ID,
                                                    SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)) as adj_amount,
                                                    MAX(T.DATETIME) AS ots_date,
                                                    MAX(T.CASH_DOCUMENT_NUMBER) AS ots_doc
                                             FROM TRANSACTIONS T
                                             JOIN PERSONS P ON T.PERSON_ID = P.id
                                             WHERE T.CHURCH_ID = ? AND T.DATETIME BETWEEN ? AND ? AND T.VIA_BANK <> 0
                                               AND T.RECORD_ID NOT IN ($used_list)
                                               AND UPPER(CONCAT_WS(' ', IFNULL(P.NAME_PREFIX,''), P.NAME, IFNULL(P.NAME_SUFFIX,''))) LIKE ?
                                               AND ABS(PERIOD_DIFF(EXTRACT(YEAR_MONTH FROM ?), EXTRACT(YEAR_MONTH FROM T.DATETIME))) <= 1
                                             GROUP BY T.RECORD_ID
                                             HAVING ABS(adj_amount - ?) < 0.01 OR ABS(adj_amount - ?) < 0.01";
                                    $ta_stmt = $ots_db->prepare($ta_q);
                                    if ($ta_stmt) {
                                        $ta_stmt->bind_param("issssdd", $church_id, $start_date, $end_date, $like_name, $bank_date, $t_val, $a_val);
                                        $ta_stmt->execute();
                                        $ta_res = $ta_stmt->get_result();
                                        $found_t = null; $found_a = null;
                                        while ($ta_row = $ta_res->fetch_assoc()) {
                                            $adj = (float)$ta_row['adj_amount'];
                                            if (abs($adj - $t_val) < 0.01) $found_t = $ta_row;
                                            if (abs($adj - $a_val) < 0.01) $found_a = $ta_row;
                                        }
                                        if ($found_t && $found_a) {
                                            $t_date = $found_t['ots_date'] ? substr($found_t['ots_date'], 0, 10) : null;
                                            $a_date = $found_a['ots_date'] ? substr($found_a['ots_date'], 0, 10) : null;
                                            $ots_date_only = $t_date && $a_date ? min($t_date, $a_date) : ($t_date ?? $a_date);
                                            $docs = array_filter([$found_t['ots_doc'] ?? '', $found_a['ots_doc'] ?? ''], function($v) { return $v !== '' && $v !== '0000'; });
                                            $ots_doc_clean = !empty($docs) ? implode(', ', array_unique($docs)) : '';
                                            
                                            $ins_item = $conn->prepare("INSERT INTO bank_reconciliation_items (reconciliation_id, record_id, amount) VALUES (?, ?, ?)");
                                            if ($ins_item) {
                                                $ins_item->bind_param("iid", $id, $found_t['RECORD_ID'], $t_val);
                                                $ins_item->execute();
                                                $ins_item->bind_param("iid", $id, $found_a['RECORD_ID'], $a_val);
                                                $ins_item->execute();
                                            }
                                            
                                            $new_status = 'OSSZEVONT';
                                            $comment = "[Auto: T/A minta - {$t_val} Ft tized + {$a_val} Ft adomany]";
                                            $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
                                            $upd_stmt->bind_param("ssidssi", $ots_date_only, $ots_doc_clean, $found_t['RECORD_ID'], $bank_amount, $new_status, $comment, $id);
                                            $upd_stmt->execute();
                                            
                                            if ($mode === 'progressive') { $stats['pass_text']++; } else { $stats['custom']++; }
                                            $total_matched++;
                                            $ta_matched = true;
                                            break; // break foreach $passes
                                        }
                                    }
                                }
                            }
                        }
                        
                        if ($ta_matched) { $processed_records++; continue 2; } // skip to next bank row
                        
                        if ($ots_result && $ots_result->num_rows > 0) {
                            while ($ots_row = $ots_result->fetch_assoc()) {
                                $ots_desc = mb_strtoupper($ots_row['ots_desc'], 'UTF-8');
                                $ots_dec = mb_strtoupper(trim($ots_row['ots_decision'] ?? ''), 'UTF-8');
                                $text_score = 0;
                                
                                // 1. Alap szóegyezés (min 4 karakter)
                                foreach ($b_words as $word) {
                                    if (mb_strlen($word, 'UTF-8') >= 4 && mb_strpos($ots_desc, $word) !== false) {
                                        $text_score++;
                                    }
                                }
                                
                                // 2. Rövid kulcsszavak keresése (3+ karakter, pl. VÍZ, GÁZ, DÍJ)
                                foreach ($b_words as $word) {
                                    if (mb_strlen($word, 'UTF-8') >= 3 && mb_strlen($word, 'UTF-8') < 4) {
                                        // 3 betűs szó: pontos találat kell (nem része egy hosszabb szónak)
                                        if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $ots_desc)) {
                                            $text_score++;
                                        }
                                    }
                                }
                                
                                // 3. Kulcsszó csoport egyezés (+2 pont csoportonként, ha banki szöveg és OTS is tartalmazza)
                                foreach ($keyword_groups as $group_name => $kws) {
                                    $b_has = false;
                                    $o_has = false;
                                    foreach ($kws as $kw) {
                                        if (mb_strpos($b_text, $kw) !== false) $b_has = true;
                                        if (mb_strpos($ots_desc, $kw) !== false) $o_has = true;
                                    }
                                    if ($b_has && $o_has) {
                                        $text_score += 2;
                                    }
                                }
                                
                                // 4. Rezsi spec: OTS Határozati szám "R" betűvel kezdődik
                                if (preg_match('/^R/u', $ots_dec)) {
                                    // Rezsi jellegű OTS tétel — nézzük, hogy a banki szövegben van-e rezsi kulcsszó
                                    foreach ($keyword_groups['rezsi'] as $kw) {
                                        if (mb_strpos($b_text, $kw) !== false) {
                                            $text_score += 3; // Erős jelzés: banki rezsi szöveg + OTS rezsi határozati szám
                                            break;
                                        }
                                    }
                                    // Ha a banki összeg tipikus rezsi összeg (pl. havi díj 5000-200000 között), extra pont
                                    if (abs($bank_amount) >= 5000 && abs($bank_amount) <= 200000) {
                                        $text_score += 1;
                                    }
                                }
                                
                                // 5. Közművek, szolgáltatók és adóhivatal specifikus egyezés (+3 pont)
                                if (preg_match('/(MVM|EON|NKM|TELEKOM|VODAFONE|YETTEL|DIGI|F[ŐO]GÁZ|VÍZM[ŰU]VEK|MÁK|NAV|CIGAM|NHKV|MIVÍZ|ALFÖLDVÍZ)/u', $b_text, $m)) {
                                    if (mb_strpos($ots_desc, $m[1]) !== false) {
                                        $text_score += 3;
                                    }
                                }
                                
                                // 6. Dinamikus provider_keywords tábla használata
                                foreach ($provider_kws as $pk) {
                                    if (mb_stripos($b_text, $pk['bank_keyword'], 0, 'UTF-8') !== false) {
                                        $ots_kws = explode(',', $pk['ots_keyword']);
                                        foreach ($ots_kws as $okw) {
                                            $okw = trim($okw);
                                            if (!empty($okw) && mb_stripos($ots_desc, $okw, 0, 'UTF-8') !== false) {
                                                $text_score += 2;
                                                break;
                                            }
                                        }
                                    }
                                }

                                // 7. Church-specific custom_patterns
                                if (isset($custom_patterns_by_church[$church_id])) {
                                    foreach ($custom_patterns_by_church[$church_id] as $cp) {
                                        if (mb_stripos($b_text, $cp['bank_pattern'], 0, 'UTF-8') !== false
                                            && mb_stripos($ots_desc, $cp['ots_pattern'], 0, 'UTF-8') !== false) {
                                            $text_score += 3;
                                        }
                                    }
                                }
                                
                                $score = $text_score;
                                $amt_diff = abs(round((float)$bank_amount - (float)$ots_row['ots_amount'], 2));
                                if ($amt_diff < 1) {
                                    $score += 2;
                                    $same_amount_count++;
                                } else {
                                    // Eltérő összeg soha nem párosítható automatikusan
                                    continue;
                                }
                                
                                // Dátum irány ellenőrzés (két irány):
                                // Bank-first (a,b,f,k): bank_date <= ots_date — ha ots_date < bank_date → skip
                                // OTS-first (c,g): ots_date <= bank_date — ha ots_date > bank_date → skip
                                if (!empty($bank_date) && !empty($best_match['ots_date'])) {
                                    $ots_dt = substr($best_match['ots_date'], 0, 10);
                                    $b_desc_lower = mb_strtolower($b_desc, 'UTF-8');
                                    // Bank-first: költségek, rezsi, tized/adakozás, kamat
                                    $is_bank_first = (mb_strpos($b_desc_lower, 'beszedés') !== false || mb_strpos($b_desc_lower, 'beszed') !== false
                                        || mb_strpos($b_desc_lower, 'jutalék') !== false || mb_strpos($b_desc_lower, 'kezelési') !== false
                                        || mb_strpos($b_desc_lower, 'szolgáltatási') !== false
                                        || mb_strpos($b_desc_lower, 'könyvelés') !== false
                                        || mb_strpos($b_desc_lower, 'tized') !== false
                                        || mb_strpos($b_desc_lower, 'adomány') !== false || mb_strpos($b_desc_lower, 'adak') !== false
                                        || mb_strpos($b_desc_lower, 'kamat') !== false);
                                    // OTS-first: AT havi zárás (kedvezményezett = TET számla) vagy készpénz befizetés
                                    $clean_ext_acc = preg_replace('/[^0-9]/', '', $bank_ext_acc);
                                    $b_name_lower = mb_strtolower($b_name ?? '', 'UTF-8');
                                    $is_ots_first = ($bank_amount < 0 && in_array($clean_ext_acc, ['1178400922224138', '104003395049575053561009']))
                                        || ($bank_amount > 0 && (mb_strpos($b_desc_lower, 'készpénz befizetés') !== false || mb_strpos($b_name_lower, 'készpénz befizetés') !== false));
                                    if ($is_bank_first && $ots_dt < $bank_date) {
                                        continue; // Bank-first: OTS nem lehet korábbi
                                    }
                                    if ($is_ots_first && $ots_dt > $bank_date) {
                                        continue; // OTS-first: OTS nem lehet későbbi
                                    }
                                }
                                
                                if (($text_score > 0 || $is_large_amount) && $score >= 2) {
                                    if ($score > $best_score || ($score == $best_score && $amt_diff < $min_amt_diff)) {
                                        $best_score = $score;
                                        $best_match = $ots_row;
                                        $min_amt_diff = $amt_diff;
                                    }
                                }
                            }
                        }
                        
                        if ($best_match) {
                            $ots_date_only = $best_match['ots_date'] ? substr($best_match['ots_date'], 0, 10) : null;
                            $ots_doc_clean = $best_match['ots_doc'] ?? '';
                            if ($ots_doc_clean === '0000') $ots_doc_clean = '';
                            $ots_amt = $best_match['ots_amount'];
                            
                            $extra_info = "";
                            $comment = "";
                            
                            if ($ots_date_only === $bank_date) {
                                $new_status = 'OK';
                                $comment = '[Auto: 100% egyezés, 0 nap (szöveges találat)]';
                            } else {
                                $new_status = 'CSUSZAS';
                                if ($text_score == 0 && $is_large_amount) {
                                    $extra_info = "összeg OK, nagy összegű egyedi tétel szöveges egyezés nélkül";
                                } else if ($same_amount_count > 1) {
                                    $extra_info = "összeg OK, $same_amount_count db azonos összegből pontozva (név alapján)";
                                } else {
                                    $extra_info = "összeg OK, egyetlen ilyen összeg 30 napon belül";
                                }
                                $comment = "[Auto: Szöveges, $extra_info]";
                            }
                            
                            $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
                            $upd_stmt->bind_param("ssidssi", $ots_date_only, $ots_doc_clean, $best_match['RECORD_ID'], $ots_amt, $new_status, $comment, $id);
                            $upd_stmt->execute();
                            
                            if ($mode === 'progressive') { $stats['pass_text']++; } else { $stats['custom']++; }
                            $total_matched++;
                            break;
                        }
                    }
                } else {
                    $start_date = date('Y-m-d', strtotime("$bank_date -$days days"));
                    $end_date = date('Y-m-d', strtotime("$bank_date +$days days"));
                    
$ots_query = "SELECT RECORD_ID, MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date 
                              FROM TRANSACTIONS T WHERE CHURCH_ID = ? AND DATETIME BETWEEN ? AND ?
                              AND VIA_BANK <> 0 AND ABS(PERIOD_DIFF(EXTRACT(YEAR_MONTH FROM ?), EXTRACT(YEAR_MONTH FROM T.DATETIME))) <= 1
                              AND T.RECORD_ID NOT IN ($used_list)
                              GROUP BY RECORD_ID HAVING SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)) = ?";
                                    
                    $stmt_ots = $ots_db->prepare($ots_query);
                    $matched_ots = false;
                    if ($stmt_ots) {
                        $stmt_ots->bind_param("isssd", $church_id, $start_date, $end_date, $bank_date, $bank_amount);
                        $stmt_ots->execute();
                        $ots_result = $stmt_ots->get_result();
                        
                        if ($ots_result && $ots_result->num_rows === 1) {
                            $ots_row = $ots_result->fetch_assoc();
                            $ots_date_only = $ots_row['ots_date'] ? substr($ots_row['ots_date'], 0, 10) : null;
                            $ots_doc_clean = $ots_row['ots_doc'] ?? '';
                            if ($ots_doc_clean === '0000') $ots_doc_clean = '';
                            
                            // Dátum irány ellenőrzés (két irány):
                            $b_desc_lower2 = mb_strtolower($b_desc, 'UTF-8');
                            $is_bank_first2 = (mb_strpos($b_desc_lower2, 'beszedés') !== false || mb_strpos($b_desc_lower2, 'beszed') !== false
                                || mb_strpos($b_desc_lower2, 'jutalék') !== false || mb_strpos($b_desc_lower2, 'kezelési') !== false
                                || mb_strpos($b_desc_lower2, 'szolgáltatási') !== false
                                || mb_strpos($b_desc_lower2, 'könyvelés') !== false
                                || mb_strpos($b_desc_lower2, 'tized') !== false
                                || mb_strpos($b_desc_lower2, 'adomány') !== false || mb_strpos($b_desc_lower2, 'adak') !== false
                                || mb_strpos($b_desc_lower2, 'kamat') !== false);
                            $clean_ext_acc2 = preg_replace('/[^0-9]/', '', $bank_ext_acc);
                            $b_name_lower2 = mb_strtolower($b_name ?? '', 'UTF-8');
                            $is_ots_first2 = ($bank_amount < 0 && in_array($clean_ext_acc2, ['1178400922224138', '104003395049575053561009']))
                                || ($bank_amount > 0 && (mb_strpos($b_desc_lower2, 'készpénz befizetés') !== false || mb_strpos($b_name_lower2, 'készpénz befizetés') !== false));
                            $skip_date = false;
                            if (!empty($ots_date_only) && !empty($bank_date)) {
                                if ($is_bank_first2 && $ots_date_only < $bank_date) $skip_date = true;
                                if ($is_ots_first2 && $ots_date_only > $bank_date) $skip_date = true;
                            }
                            if ($skip_date) {
                                // Dátum irány sérülés: nem párosítjuk
                            } else {
                            
                            // 40 napos duplikátumszűrő: ha ugyanaz az összeg + hasonló közlemény más napon is előfordul 40 napon belül
                            $is_duplicate = false;
                            if ($days === 0) {
                                $b_desc_prefix = mb_substr($b_desc, 0, 80, 'UTF-8');
                                $dup_q = $conn->prepare("SELECT COUNT(*) as cnt FROM bank_reconciliation WHERE church_id = ? AND bank_amount = ? AND bank_date BETWEEN ? AND ? AND id != ? AND status != 'UNCHECKED' AND LEFT(bank_desc, 80) = ?");
                                if ($dup_q) {
                                    $dup_start = date('Y-m-d', strtotime("$bank_date -40 days"));
                                    $dup_end = date('Y-m-d', strtotime("$bank_date +40 days"));
                                    $dup_q->bind_param("idssis", $church_id, $bank_amount, $dup_start, $dup_end, $id, $b_desc_prefix);
                                    $dup_q->execute();
                                    $dup_res = $dup_q->get_result();
                                    if ($dup_res && $dup_res->fetch_assoc()['cnt'] > 0) $is_duplicate = true;
                                }
                            }
                            
                            $new_status = ($days === 0 && !$is_duplicate) ? 'OK' : 'CSUSZAS';
                            $comment = ($days === 0 && !$is_duplicate) ? '[Auto: 100% egyezés, 0 nap]' : "[Auto: $days nap eltérésen belül csak ez az egyetlen találat volt.]";
                            
                            $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
                            $upd_stmt->bind_param("ssidssi", $ots_date_only, $ots_doc_clean, $ots_row['RECORD_ID'], $bank_amount, $new_status, $comment, $id);
                            $upd_stmt->execute();
                            
                            if ($mode === 'progressive') { $stats["pass_$days"]++; } else { $stats['custom']++; }
                            $total_matched++;
                            $matched_ots = true;
                            break;
                            } // end date order check else
                        }
                    }
                    
                    // --- Same-month fallback round 0: ha a nap nem egyezik, de a hónap és összeg igen ---
                    if (!$matched_ots && $days === 0 && (float)$bank_amount != 0) {
                        $bank_month = date('Y-m', strtotime($bank_date));
                        $month_q = "SELECT RECORD_ID, MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date 
                                    FROM TRANSACTIONS T WHERE CHURCH_ID = ? 
                                    AND DATE_FORMAT(T.DATETIME, '%Y-%m') = ?
                                    AND VIA_BANK <> 0
                                    AND T.RECORD_ID NOT IN ($used_list)
                                    GROUP BY RECORD_ID HAVING SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)) = ?";
                        $month_stmt = $ots_db->prepare($month_q);
                        if ($month_stmt) {
                            $month_stmt->bind_param("isd", $church_id, $bank_month, $bank_amount);
                            $month_stmt->execute();
                            $month_res = $month_stmt->get_result();
                            if ($month_res && $month_res->num_rows === 1) {
                                $m_row = $month_res->fetch_assoc();
                                $ots_date_only = $m_row['ots_date'] ? substr($m_row['ots_date'], 0, 10) : null;
                                $ots_doc_clean = $m_row['ots_doc'] ?? '';
                                if ($ots_doc_clean === '0000') $ots_doc_clean = '';
                                $new_status = 'OK';
                                $comment = '[Auto: 100% egyezés, azonos hónap]';
                                
                                // 40 napos duplikátumszűrő itt is (közleményt is vizsgál)
                                $b_desc_prefix = mb_substr($b_desc, 0, 80, 'UTF-8');
                                $dup_q = $conn->prepare("SELECT COUNT(*) as cnt FROM bank_reconciliation WHERE church_id = ? AND bank_amount = ? AND bank_date BETWEEN ? AND ? AND id != ? AND status != 'UNCHECKED' AND LEFT(bank_desc, 80) = ?");
                                if ($dup_q) {
                                    $dup_start = date('Y-m-d', strtotime("$bank_date -40 days"));
                                    $dup_end = date('Y-m-d', strtotime("$bank_date +40 days"));
                                    $dup_q->bind_param("idssis", $church_id, $bank_amount, $dup_start, $dup_end, $id, $b_desc_prefix);
                                    $dup_q->execute();
                                    $dup_res = $dup_q->get_result();
                                    if ($dup_res && $dup_res->fetch_assoc()['cnt'] > 0) {
                                        $new_status = 'CSUSZAS';
                                        $comment = '[Auto: azonos hónap, de duplikátum 40 napon belül]';
                                    }
                                }
                                
                                $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
                                $upd_stmt->bind_param("ssidssi", $ots_date_only, $ots_doc_clean, $m_row['RECORD_ID'], $bank_amount, $new_status, $comment, $id);
                                $upd_stmt->execute();
                                if ($mode === 'progressive') { $stats["pass_$days"]++; } else { $stats['custom']++; }
                                $total_matched++;
                                $matched_ots = true;
                                break;
                            }
                        }
                    }
                    
                    // --- transfers_to_conference keresése ---
                    if (!$matched_ots && $tc_stmt && in_array($days, [3, 6, 12, 35, 60])) {
                        $tc_start = date('Y-m-d', strtotime("$bank_date -$days days"));
                        $tc_end = date('Y-m-d', strtotime("$bank_date +$days days"));
                        $tc_stmt->bind_param("idss", $church_id, $bank_amount, $tc_start, $tc_end);
                        $tc_stmt->execute();
                        $tc_res = $tc_stmt->get_result();
                        if ($tc_res && $tc_res->num_rows === 1) {
                            $tc_row = $tc_res->fetch_assoc();
                            $ots_date_only = $tc_row['ots_date'] ?? null;
                            $ots_doc_clean = $tc_row['ots_doc'] ?? '';
                            if ($ots_doc_clean === '0000') $ots_doc_clean = '';
                            $ots_amt = $tc_row['ots_amount'];
                            
                            // Ellenőrizzük, hogy a banki számlaszám szerepel-e a gyülekezet ismert számlái között
                            // Kimenő utalásnál a kezdeményező számlája = gyülekezeté, bejövőnél a kedvezményezetté
                            $acc_to_check = $bank_amount < 0 ? $bank_init_acc : $bank_ext_acc;
                            $bank_acc_clean = preg_replace('/[^0-9]/', '', $acc_to_check);
                            $acc_ok = false;
                            if (!empty($bank_acc_clean)) {
                                $acc_check = $conn->prepare("SELECT COUNT(*) as cnt FROM church_bank_accounts WHERE church_id = ? AND bank_account_clean = ?");
                                if ($acc_check) {
                                    $acc_check->bind_param("is", $church_id, $bank_acc_clean);
                                    $acc_check->execute();
                                    $acc_res = $acc_check->get_result();
                                    if ($acc_res && ($acc_r = $acc_res->fetch_assoc()) && $acc_r['cnt'] > 0) $acc_ok = true;
                                }
                            }
                            
                            // Ha a bankszámla nem egyezik, de van transfers_to_conference találat, akkor is felvesszük (de lehet false match)
                            // Erősebb jelzés: a banki közlemény tartalmazza a gyülekezet nevét, év-hónap, adomány/zárás/elszámolás szavakat
                            $b_text_upper = mb_strtoupper($b_desc . ' ' . $b_name, 'UTF-8');
                            $has_pattern = preg_match('/\d{4}\./u', $b_text_upper) && preg_match('/(ADOMÁNY|ZÁRÁS|ELSZÁMOLÁS|ADOMÁNY|KONFERENCIA|TET)/u', $b_text_upper);
                            
                            $new_status = $acc_ok ? 'OK' : 'CSUSZAS';
                            $comment = "[Auto: Konferencia utalás, " . ($acc_ok ? 'számla egyezik' : 'számla nem egyezik') . ", $days nap]";
                            if (!$acc_ok && !$has_pattern) {
                                // Ha se számla, se közlemény minta nem egyezik, akkor csak CSUSZAS marad
                            }
                            
                            $tc_record_id = -1 * (int)$tc_row['tc_id'];
                            $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
                            $upd_stmt->bind_param("ssidssi", $ots_date_only, $ots_doc_clean, $tc_record_id, $ots_amt, $new_status, $comment, $id);
                            $upd_stmt->execute();
                            
                            if ($mode === 'progressive') { $stats['pass_tc']++; } else { $stats['custom']++; }
                            $total_matched++;
                            break;
                        }
                    }
                }
            }
            // update progress every record
            $processed_records++;
            if ($processed_records % 20 === 0 || $processed_records === $total_records) {
                @file_put_contents($progress_file, json_encode(['status'=>'RUNNING','matched'=>$total_matched,'total_unchecked'=>$total_records,'current_church'=>null,'processed_churches'=>0,'processed_records'=>$processed_records,'time_sec'=>round(microtime(true)-$start_time,2)]));
            }
        }
    }
    $elapsed = round(microtime(true) - $start_time, 2);
    @file_put_contents($progress_file, json_encode(['status'=>'DONE','matched'=>$total_matched,'total_unchecked'=>$total_records,'current_church'=>null,'processed_churches'=>0,'processed_records'=>$processed_records,'time_sec'=>$elapsed]));
    register_shutdown_function(function() use ($progress_file){ if (file_exists($progress_file)) @unlink($progress_file); });

    // Log mentése
    $log_user = $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'unknown');
    $log_church = $all_churches ? null : $filter_church_id;
    $log_stmt = $conn->prepare("INSERT INTO auto_match_logs (church_id, mode, total_unchecked, matched, details, elapsed_sec, run_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $log_details = json_encode($stats);
    $log_stmt->bind_param("isiids", $log_church, $mode, $total_records, $total_matched, $log_details, $elapsed, $log_user);
    $log_stmt->execute();
    $log_id = $log_stmt->insert_id;

    echo json_encode(['status' => 'OK', 'matched' => $total_matched, 'total' => $total_records, 'details' => $stats, 'log_id' => $log_id]);
    exit;
}

// AJAX: Auto-match napló lekérése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_auto_match_log') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']); exit;
    }
    $limit = isset($_POST['limit']) ? min(intval($_POST['limit']), 50) : 20;
    $log_filter = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;
    $check = @$conn->query("SHOW TABLES LIKE 'auto_match_logs'");
    if (!$check || $check->num_rows === 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS auto_match_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            run_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            church_id INT DEFAULT NULL,
            mode VARCHAR(20) DEFAULT 'progressive',
            total_unchecked INT DEFAULT 0,
            matched INT DEFAULT 0,
            details JSON DEFAULT NULL,
            elapsed_sec DECIMAL(6,2) DEFAULT 0,
            run_by VARCHAR(100) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $sql = "SELECT l.* FROM auto_match_logs l";
    $params = []; $types = '';
    if ($log_filter > 0) { $sql .= " WHERE l.church_id = ?"; $params[] = $log_filter; $types = 'i'; }
    $sql .= " ORDER BY l.run_at DESC LIMIT $limit";
    $rows = [];
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) { $stmt->bind_param($types, ...$params); $stmt->execute(); $res = $stmt->get_result(); if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC); }
    } else {
        $res = $conn->query($sql);
        if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
    }
    // Gyülekezet nevek betöltése az OTS DB-ből
    $church_names = [];
    if (!empty($ots_db)) {
        $cn_res = $ots_db->query("SELECT ID, NAME FROM churches WHERE ID > 0");
        if ($cn_res) { while ($cn = $cn_res->fetch_assoc()) { $church_names[(int)$cn['ID']] = $cn['NAME']; } }
    }
    foreach ($rows as &$r) {
        $cid = (int)($r['church_id'] ?? 0);
        $r['church_name'] = $cid > 0 ? ($church_names[$cid] ?? "#$cid") : 'Minden';
    }
    echo json_encode(['status' => 'OK', 'data' => $rows]);
    exit;
}

// AJAX OTS részletek lekérése a modálhoz — minden OTS TRANSACTIONS adatot visszaad
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_ots_details') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    $church_id = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;
    $ots_doc = isset($_POST['ots_doc']) ? mb_substr(trim($_POST['ots_doc']), 0, 50, 'UTF-8') : '';
    $church_name = isset($_POST['church_name']) ? mb_substr(trim($_POST['church_name']), 0, 100, 'UTF-8') : '';
    $bank_date = isset($_POST['bank_date']) ? trim($_POST['bank_date']) : '';
    $bank_date_dt = DateTime::createFromFormat('Y-m-d', $bank_date);
    $bank_date = ($bank_date_dt instanceof DateTime && $bank_date_dt->format('Y-m-d') === $bank_date) ? $bank_date : '';
    $bank_amount = isset($_POST['bank_amount']) ? floatval($_POST['bank_amount']) : 0;
    $bank_desc = isset($_POST['bank_desc']) ? trim($_POST['bank_desc']) : '';
    $bank_ext_name = isset($_POST['bank_ext_name']) ? trim($_POST['bank_ext_name']) : '';
    $unmatched_search = isset($_POST['unmatched_search']) && $_POST['unmatched_search'] === '1';
    $bank_rec_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    if ($church_id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó paraméterek']);
        exit;
    }

    // require access to this church
    require_church_access($church_id);

    try {
    // Ha a banki tételnek már van meglévő párosítása, azt adjuk vissza
    if (!$unmatched_search && $bank_rec_id > 0) {
        $existing = [];
        // Több tételes párosítás (bank_reconciliation_items) — elsőbbséget élvez
        $stmt_multi = $conn->prepare("SELECT record_id, amount FROM bank_reconciliation_items WHERE reconciliation_id = ?");
        if ($stmt_multi) {
            $stmt_multi->bind_param('i', $bank_rec_id);
            $stmt_multi->execute();
            $multi_res = $stmt_multi->get_result();
            while ($m = $multi_res->fetch_assoc()) {
                $existing[] = $m;
            }
        }
        // Egyedi párosítás (bank_reconciliation.ots_record_id) — csak ha nincs items
        if (empty($existing)) {
            $stmt_ex = $conn->prepare("SELECT ots_record_id, ots_date, ots_amount, ots_doc FROM bank_reconciliation WHERE id = ? AND ots_record_id IS NOT NULL AND ots_record_id <> 0");
            if ($stmt_ex) {
                $stmt_ex->bind_param('i', $bank_rec_id);
                $stmt_ex->execute();
                $ex_res = $stmt_ex->get_result();
                if ($ex_res && $ex_res->num_rows > 0) {
                    $ex_row = $ex_res->fetch_assoc();
                    $existing[] = $ex_row;
                }
            }
        }
        // Fallback: ha ots_record_id=NULL de ots_amount/ots_date be van állítva (régi auto_match TC rekordok)
        if (empty($existing)) {
            $stmt_fb = $conn->prepare("SELECT ots_amount, ots_date, ots_doc FROM bank_reconciliation WHERE id = ? AND ots_amount IS NOT NULL AND ots_amount != 0 AND ots_date IS NOT NULL");
            if ($stmt_fb) {
                $stmt_fb->bind_param('i', $bank_rec_id);
                $stmt_fb->execute();
                $fb_res = $stmt_fb->get_result();
                if ($fb_res && $fb_res->num_rows > 0) {
                    $fb_row = $fb_res->fetch_assoc();
                    $fb_ots_amount = abs(floatval($fb_row['ots_amount']));
                    $fb_ots_date = $fb_row['ots_date'];
                    // TRANSFERS_TO_CONFERENCE keresés: CHURCH_ID + AMOUNT egyezés ±70 napban
                    $tc_fb = $ots_db->prepare("SELECT tc.id, tc.AMOUNT, tc.YEAR, tc.MONTH, tc.DAY, tc.VIA_BANK,
                        CONCAT(tc.YEAR, '-', LPAD(tc.MONTH,2,'0'), '-', LPAD(tc.DAY,2,'0')) AS tc_date
                        FROM TRANSFERS_TO_CONFERENCE tc
                        WHERE tc.CHURCH_ID = ? AND tc.AMOUNT = ? AND tc.VIA_BANK = 1
                        AND CONCAT(tc.YEAR, '-', LPAD(tc.MONTH,2,'0'), '-', LPAD(tc.DAY,2,'0'))
                            BETWEEN DATE_SUB(?, INTERVAL 70 DAY) AND DATE_ADD(?, INTERVAL 70 DAY)
                        ORDER BY ABS(DATEDIFF(CONCAT(tc.YEAR, '-', LPAD(tc.MONTH,2,'0'), '-', LPAD(tc.DAY,2,'0')), ?)) ASC
                        LIMIT 1");
                    if ($tc_fb) {
                        $tc_fb->bind_param('idsss', $church_id, $fb_ots_amount, $fb_ots_date, $fb_ots_date, $fb_ots_date);
                        $tc_fb->execute();
                        $tc_fb_res = $tc_fb->get_result();
                        if ($tc_fb_res && $tc_fb_res->num_rows > 0) {
                            $tc_fb_row = $tc_fb_res->fetch_assoc();
                            $existing[] = ['ots_record_id' => -1 * (int)$tc_fb_row['id']];
                        }
                    }
                }
            }
        }
        if (!empty($existing)) {
            // OTS adatok lekérése a meglévő párosításokhoz
            $record_ids = [];
            $tc_ids = [];
            foreach ($existing as $ex) {
                $rid = $ex['ots_record_id'] ?? $ex['record_id'] ?? 0;
                if ($rid > 0) {
                    $record_ids[] = $rid;
                } elseif ($rid < 0) {
                    $tc_ids[] = abs($rid);
                }
            }
            $ex_rows = [];
            // TRANSACTIONS rekordok
            if (!empty($record_ids)) {
                $id_list = implode(',', array_fill(0, count($record_ids), '?'));
                $adjusted_amount_sql = "IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)";
                $base_joins = "FROM TRANSACTIONS T
                         LEFT JOIN PERSONS p ON T.PERSON_ID = p.id
                         LEFT JOIN NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
                         LEFT JOIN NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
                         LEFT JOIN TRANSACTION_TYPE tt ON T.TYPE = tt.id
                         LEFT JOIN USERS u ON T.EDITED_BY = u.id
                         LEFT JOIN FUNDS funds ON T.FUND_ID = funds.id";
                $sql_ex = "SELECT T.*,
                       $adjusted_amount_sql AS adjusted_amount,
                       TRIM(CONCAT(
                           IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''),
                           ' ', IFNULL(nt1.NAME, ''), ' ', IFNULL(nt2.NAME, '')
                       )) AS ots_desc_full,
                       tt.NAME AS ots_type_name,
                       u.NAME AS ots_editor_name,
                       funds.NAME AS fund_name
                 $base_joins
                  WHERE T.RECORD_ID IN ($id_list)
                  ORDER BY T.DATETIME ASC";
                $stmt_ex = $ots_db->prepare($sql_ex);
                $result_ex = false;
                if ($stmt_ex) {
                    $types = str_repeat('i', count($record_ids));
                    $stmt_ex->bind_param($types, ...$record_ids);
                    $stmt_ex->execute();
                    $result_ex = $stmt_ex->get_result();
                }
                if ($result_ex) {
                    while ($r = $result_ex->fetch_assoc()) {
                        $r['_text_score'] = 0;
                        $ex_rows[] = $r;
                    }
                }
            }
            // TRANSFERS_TO_CONFERENCE rekordok
            if (!empty($tc_ids)) {
                $tc_list = implode(',', array_fill(0, count($tc_ids), '?'));
                $tc_sql = "SELECT tc.id AS TC_ID, tc.YEAR, tc.MONTH, tc.DAY, tc.AMOUNT, tc.VIA_BANK,
                                  tc.CASH_DOCUMENT_NUMBER, tc.MODIFIED,
                                  CONCAT(tc.YEAR, '-', LPAD(tc.MONTH,2,'0'), '-', LPAD(tc.DAY,2,'0')) AS DATETIME,
                                  (-1 * tc.AMOUNT) AS adjusted_amount,
                                  CONCAT('Egyházterületnek elutalt (', tc.YEAR, '.', LPAD(tc.MONTH,2,'0'), ' havi, ',
                                         CASE tc.VIA_BANK WHEN 1 THEN 'Bank' ELSE 'KP' END, ')') AS ots_desc_full,
                                  'TransfToConf' AS ots_type_name,
                                  '-' AS ots_editor_name,
                                  '' AS fund_name
                           FROM TRANSFERS_TO_CONFERENCE tc
                           WHERE tc.id IN ($tc_list)";
                $tc_stmt = $ots_db->prepare($tc_sql);
                if ($tc_stmt) {
                    $types = str_repeat('i', count($tc_ids));
                    $tc_stmt->bind_param($types, ...$tc_ids);
                    $tc_stmt->execute();
                    $tc_res = $tc_stmt->get_result();
                    if ($tc_res) {
                        while ($tc = $tc_res->fetch_assoc()) {
                            $tc['RECORD_ID'] = -1 * (int)$tc['TC_ID'];
                            $tc['_text_score'] = 0;
                            $ex_rows[] = $tc;
                        }
                    }
                }
            }
            if (!empty($ex_rows)) {
                echo json_encode(['status' => 'OK', 'data' => $ex_rows, 'church_name' => $church_name, 'ots_doc' => $ots_doc, 'bank_date' => $bank_date, 'bank_amount' => $bank_amount, 'unmatched_search' => false, 'from_existing' => true]);
                exit;
            }
        }
    }

    // Irány szűrés: negatív banki összeg → csak negatív OTS tétel, pozitív → pozitív
    $sign_having = '';
    if ($bank_amount < 0) {
        $sign_having = ' AND adjusted_amount < 0';
    } elseif ($bank_amount > 0) {
        $sign_having = ' AND adjusted_amount > 0';
    }

    $adjusted_amount_sql = "IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)";

    $base_joins = "FROM TRANSACTIONS T
             LEFT JOIN PERSONS p ON T.PERSON_ID = p.id
             LEFT JOIN NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
             LEFT JOIN NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
             LEFT JOIN TRANSACTION_TYPE tt ON T.TYPE = tt.id
             LEFT JOIN USERS u ON T.EDITED_BY = u.id
             LEFT JOIN FUNDS funds ON T.FUND_ID = funds.id";

    $sign = $bank_amount >= 0 ? '>=' : '<';

    // Felhasznált OTS rekordok lekérése (jelöléshez) — mindkét keresési módban (unmatched + aggregation) kell
    $used_map = [];
    $stmt_used = $conn->prepare("SELECT br.ots_record_id AS rid, br.id AS bid FROM bank_reconciliation br WHERE br.ots_record_id IS NOT NULL AND br.ots_record_id <> 0 AND br.church_id = ?");
    if ($stmt_used) {
        $stmt_used->bind_param('i', $church_id);
        $stmt_used->execute();
        $used_res = $stmt_used->get_result();
        while ($u = $used_res->fetch_assoc()) {
            $used_map[(int)$u['rid']][] = (int)$u['bid'];
        }
    }
    $stmt_used2 = $conn->prepare("SELECT bi.record_id AS rid, bi.reconciliation_id AS bid FROM bank_reconciliation_items bi");
    if ($stmt_used2) {
        $stmt_used2->execute();
        $used_res2 = $stmt_used2->get_result();
        while ($u = $used_res2->fetch_assoc()) {
            $used_map[(int)$u['rid']][] = (int)$u['bid'];
        }
    }

    if ($unmatched_search) {
        // Párosítatlan keresés: minden OTS tétel a gyülekezetre +/- 70 napban, ami még nincs felhasználva
        // Banki költségeknél (pl. "Csoportos beszedés díja") nincs értelme a banki dátum előtti OTS tételt keresni
        $fee_keywords = ['díj', 'költség', 'jutalék', 'banki', 'kezelési', 'szolgáltatási', 'könyvelés', 'kamat'];
        $is_fee = false;
        $fee_search_text = mb_strtolower($bank_desc . ' ' . $bank_ext_name, 'UTF-8');
        foreach ($fee_keywords as $kw) {
            if (mb_strpos($fee_search_text, $kw) !== false) {
                $is_fee = true;
                break;
            }
        }
        $start_date = !empty($bank_date) ? date('Y-m-d', strtotime($is_fee ? $bank_date : "$bank_date -70 days")) : '1970-01-01';
        $end_date = !empty($bank_date) ? date('Y-m-d', strtotime("$bank_date +70 days")) : date('Y-m-d', strtotime('+70 days'));

        $not_in_clause = '';
        $not_in_params = [];

        $sql = "SELECT T.*,
                       $adjusted_amount_sql AS adjusted_amount,
                       TRIM(CONCAT(
                           IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''),
                           ' ',
                           IFNULL(nt1.NAME, ''),
                           ' ',
                           IFNULL(nt2.NAME, '')
                       )) AS ots_desc_full,
                       tt.NAME AS ots_type_name,
                       u.NAME AS ots_editor_name,
                       funds.NAME AS fund_name
                 $base_joins
                  WHERE T.CHURCH_ID = ?
                    AND T.DATETIME BETWEEN ? AND ?
                    AND T.DATETIME >= DATE_SUB(?, INTERVAL 45 DAY)
                    $not_in_clause
                 GROUP BY T.RECORD_ID
                 HAVING ABS(adjusted_amount) > 0 $sign_having
                 ORDER BY ABS(DATEDIFF(T.DATETIME, ?)) ASC, T.DATETIME ASC";
        $stmt = $ots_db->prepare($sql);
        if ($stmt) {
            $bind_types = 'isss';
            $bind_params = [$church_id, $start_date, $end_date, $bank_date];
            if (!empty($not_in_params)) {
                $bind_types .= str_repeat('i', count($not_in_params));
                $bind_params = array_merge($bind_params, $not_in_params);
            }
            $bind_types .= 's';
            $bind_params[] = $bank_date;
            $stmt->bind_param($bind_types, ...$bind_params);
            $stmt->execute();
            $result = $stmt->get_result();
        }
    } else {
        if (empty($bank_amount)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó összeg']);
            exit;
        }
        $date_filter = '';
        if (!empty($bank_date)) {
            $start_date = date('Y-m-d', strtotime("$bank_date -60 days"));
            $end_date = date('Y-m-d', strtotime("$bank_date +60 days"));
            $date_filter = "AND T2.DATETIME BETWEEN ? AND ? AND T2.DATETIME >= DATE_SUB(?, INTERVAL 45 DAY)";
        }

        // Ugyanazzal a logikával keresünk, mint az auto-match: RECORD_ID-csoportok SUM-ja egyezik a banki összeggel
        $adjusted_amount_sql_inner = str_replace('T.', 'T2.', $adjusted_amount_sql);
        $record_ids_sql = "SELECT T2.RECORD_ID
                 FROM TRANSACTIONS T2
                 WHERE T2.CHURCH_ID = ?
                 AND $adjusted_amount_sql_inner $sign 0
                 $date_filter
                 GROUP BY T2.RECORD_ID
                 HAVING ABS(SUM($adjusted_amount_sql_inner) - ?) < 0.01";

        $order_sql = "ORDER BY T.DATETIME ASC";
        if (!empty($bank_date)) {
            $order_sql = "ORDER BY 
                ABS(DATEDIFF(T.DATETIME, ?)) ASC,
                T.DATETIME ASC";
        }

        $sql = "SELECT T.*,
                       $adjusted_amount_sql AS adjusted_amount,
                       TRIM(CONCAT(
                           IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''),
                           ' ',
                           IFNULL(nt1.NAME, ''),
                           ' ',
                           IFNULL(nt2.NAME, '')
                       )) AS ots_desc_full,
                       tt.NAME AS ots_type_name,
                       u.NAME AS ots_editor_name,
                       funds.NAME AS fund_name
                 $base_joins
                 WHERE T.RECORD_ID IN ($record_ids_sql)
                 $order_sql";
        $stmt = $ots_db->prepare($sql);
        if ($stmt) {
            $record_params = [$church_id];
            $record_types = 'i';
            if (!empty($bank_date)) {
                $record_params[] = $start_date;
                $record_params[] = $end_date;
                $record_params[] = $bank_date;
                $record_types .= 'sss';
            }
            $record_params[] = $bank_amount;
            $record_types .= 'd';
            if (!empty($bank_date)) {
                $record_params[] = $bank_date;
                $record_types .= 's';
            }
            $stmt->bind_param($record_types, ...$record_params);
            $stmt->execute();
            $result = $stmt->get_result();
        }
    }

    $rows = [];
    $bank_text = mb_strtoupper($bank_desc . ' ' . $bank_ext_name, 'UTF-8');
    $b_words = [];
    if (!empty(trim($bank_text))) {
        $b_words = preg_split('/[\s,\.\-\/]+/u', $bank_text, -1, PREG_SPLIT_NO_EMPTY);
    }

    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $ots_text = mb_strtoupper($r['ots_desc_full'] ?? '', 'UTF-8');
            $score = 0;
            foreach ($b_words as $word) {
                if (mb_strlen($word, 'UTF-8') >= 4 && mb_strpos($ots_text, $word) !== false) {
                    $score++;
                }
            }
            $r['_text_score'] = $score;
            $rid_int = (int)$r['RECORD_ID'];
            if (isset($used_map[$rid_int])) {
                $bank_ids = array_unique($used_map[$rid_int]);
                sort($bank_ids);
                $r['_used'] = true;
                $r['_used_count'] = count($bank_ids);
                $r['_used_bank_ids'] = implode(',', $bank_ids);
            }
            $rows[] = $r;
        }
    }

    // TRANSFERS_TO_CONFERENCE keresés — havi átutalások (pl. "Egyházterületnek elutalt")
    if ($unmatched_search && !empty($bank_date)) {
        $tc_sql = "SELECT tc.id AS TC_ID, tc.YEAR, tc.MONTH, tc.DAY, tc.AMOUNT, tc.VIA_BANK,
                          tc.CASH_DOCUMENT_NUMBER, tc.MODIFIED,
                          CONCAT(tc.YEAR, '-', LPAD(tc.MONTH,2,'0'), '-', LPAD(tc.DAY,2,'0')) AS DATETIME,
                          (-1 * tc.AMOUNT) AS adjusted_amount,
                          CONCAT('Egyházterületnek elutalt (', tc.YEAR, '.', LPAD(tc.MONTH,2,'0'), ' havi, ',
                                 CASE tc.VIA_BANK WHEN 1 THEN 'Bank' ELSE 'KP' END, ')') AS ots_desc_full,
                          'TransfToConf' AS ots_type_name,
                          '-' AS ots_editor_name,
                          '' AS fund_name
                   FROM TRANSFERS_TO_CONFERENCE tc
                   WHERE tc.CHURCH_ID = ?
                     AND CONCAT(tc.YEAR, '-', LPAD(tc.MONTH,2,'0'), '-', LPAD(tc.DAY,2,'0')) BETWEEN ? AND ?
                     AND tc.AMOUNT > 0";
        $tc_stmt = $ots_db->prepare($tc_sql);
        if ($tc_stmt) {
            $tc_stmt->bind_param('iss', $church_id, $start_date, $end_date);
            $tc_stmt->execute();
            $tc_res = $tc_stmt->get_result();
            if ($tc_res) {
                while ($tc = $tc_res->fetch_assoc()) {
                    $tc_rid = -1 * (int)$tc['TC_ID'];
                    $tc_text = mb_strtoupper($tc['ots_desc_full'] ?? '', 'UTF-8');
                    $score = 0;
                    foreach ($b_words as $word) {
                        if (mb_strlen($word, 'UTF-8') >= 4 && mb_strpos($tc_text, $word) !== false) {
                            $score++;
                        }
                    }
                    $tc['_text_score'] = $score;
                    $tc['RECORD_ID'] = $tc_rid;
                    $tc['CASH_DOCUMENT_NUMBER'] = $tc['CASH_DOCUMENT_NUMBER'] ?? '';
                    if (isset($used_map[$tc_rid])) {
                        $bank_ids = array_unique($used_map[$tc_rid]);
                        sort($bank_ids);
                        $tc['_used'] = true;
                        $tc['_used_count'] = count($bank_ids);
                        $tc['_used_bank_ids'] = implode(',', $bank_ids);
                    }
                    $rows[] = $tc;
                }
            }
        }
    }

    // Rendezés: text score DESC, majd dátum diff ASC (csak normál módban, unmatched-nél már rendezve van)
    if (!$unmatched_search && !empty($bank_date)) {
        usort($rows, function ($a, $b) use ($bank_date) {
            $diff_a = abs(strtotime(($a['DATETIME'] ?? '')) - strtotime($bank_date));
            $diff_b = abs(strtotime(($b['DATETIME'] ?? '')) - strtotime($bank_date));
            if ($a['_text_score'] !== $b['_text_score']) {
                return $b['_text_score'] - $a['_text_score'];
            }
            return $diff_a - $diff_b;
        });
    }

    echo json_encode(['status' => 'OK', 'data' => $rows, 'church_name' => $church_name, 'ots_doc' => $ots_doc, 'bank_date' => $bank_date, 'bank_amount' => $bank_amount, 'unmatched_search' => $unmatched_search]);
    exit;
    } catch (Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'OTS kapcsolati hiba: ' . $e->getMessage()]);
        exit;
    }
}

// AJAX — szöveges aggregációs keresés: a banki közlemény szavaival keres OTS tételeket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ots_aggregation_search') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    $church_id = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;
    $bank_desc = isset($_POST['bank_desc']) ? trim($_POST['bank_desc']) : '';
    $bank_ext_name = isset($_POST['bank_ext_name']) ? trim($_POST['bank_ext_name']) : '';
    $bank_date = isset($_POST['bank_date']) ? trim($_POST['bank_date']) : '';
    $bank_date_dt = DateTime::createFromFormat('Y-m-d', $bank_date);
    $bank_date = ($bank_date_dt instanceof DateTime && $bank_date_dt->format('Y-m-d') === $bank_date) ? $bank_date : '';
    $bank_amount = isset($_POST['bank_amount']) ? floatval($_POST['bank_amount']) : 0;
    $custom_keywords = isset($_POST['custom_keywords']) ? json_decode($_POST['custom_keywords'], true) : null;

    if ($church_id <= 0 || (empty($bank_desc) && empty($bank_ext_name))) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó paraméter']);
        exit;
    }

    // scope check
    require_church_access($church_id);

    // Kulcsszavak kinyerése a banki közleményből (vagy egyéni kulcsszavak használata)
    if ($custom_keywords && is_array($custom_keywords) && count($custom_keywords) > 0) {
        $keywords = array_filter($custom_keywords, function($kw) {
            return mb_strlen(trim($kw), 'UTF-8') >= 1;
        });
        $keywords = array_values($keywords);
    } else {
        $search_text = $bank_desc . ' ' . $bank_ext_name;
        $words = preg_split('/[\s,\.\-\/\(\)\[\]":;!?\+]+/u', $search_text, -1, PREG_SPLIT_NO_EMPTY);
        $keywords = [];
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w, 'UTF-8') >= 3) {
                $keywords[] = $w;
            }
        }
    }
    if (empty($keywords)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nincs értékelhető kulcsszó a közleményben']);
        exit;
    }

    // Banki közlemény szavai a kliensoldali autosuggesthez
    $bank_words = [];
    $word_list = preg_split('/[\s,\.\-\/\(\)\[\]":;!?\+]+/u', ($bank_desc . ' ' . $bank_ext_name), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($word_list as $w) {
        $w = trim($w);
        if (mb_strlen($w, 'UTF-8') >= 1) {
            $bank_words[] = $w;
        }
    }
    $bank_words = array_unique($bank_words);
    sort($bank_words);

    $result = false;

    // Felhasznált OTS rekordok lekérése (jelöléshez) — a párosított tételeket is mutatjuk, 🔒 jelzéssel
    $used_map = [];
    $stmt_used = $conn->prepare("SELECT br.ots_record_id AS rid, br.id AS bid FROM bank_reconciliation br WHERE br.ots_record_id IS NOT NULL AND br.ots_record_id <> 0 AND br.church_id = ?");
    if ($stmt_used) {
        $stmt_used->bind_param('i', $church_id);
        $stmt_used->execute();
        $used_res = $stmt_used->get_result();
        while ($u = $used_res->fetch_assoc()) {
            $used_map[(int)$u['rid']][] = (int)$u['bid'];
        }
    }
    $stmt_used2 = $conn->prepare("SELECT bi.record_id AS rid, bi.reconciliation_id AS bid FROM bank_reconciliation_items bi");
    if ($stmt_used2) {
        $stmt_used2->execute();
        $used_res2 = $stmt_used2->get_result();
        while ($u = $used_res2->fetch_assoc()) {
            $used_map[(int)$u['rid']][] = (int)$u['bid'];
        }
    }

    // LIKE feltételek építése — bármelyik kulcsszó előfordul a leírásban.
    // FIGYELEM: MySQL 8.0.x-ben a PREPARED STATEMENT + OR-láncú LIKE + GROUP BY
    // ismert hibája miatt a prepare()-vel futtatott változat 2+ kulcsszónál csendben
    // 0 sort ad vissza. Ezért escape-elt literálokkal, query()-vel futtatjuk.
    $like_literals = [];
    foreach ($keywords as $kw) {
        $esc_kw = $ots_db->real_escape_string($kw);
        $like_literals[] = "(p.NAME LIKE '%$esc_kw%' OR p.NAME_PREFIX LIKE '%$esc_kw%' OR p.NAME_SUFFIX LIKE '%$esc_kw%' OR nt1.NAME LIKE '%$esc_kw%' OR nt2.NAME LIKE '%$esc_kw%' OR funds.NAME LIKE '%$esc_kw%')";
    }
    $like_where = '(' . implode(' OR ', $like_literals) . ')';

    // Dátumablak: ±90 nap (banki költségnél csak +90 nap)
    $fee_keywords = ['díj', 'költség', 'jutalék', 'banki', 'kezelési', 'szolgáltatási', 'könyvelés', 'kamat'];
    $is_fee = false;
    $fee_search_text = mb_strtolower($bank_desc . ' ' . $bank_ext_name, 'UTF-8');
    foreach ($fee_keywords as $kw) {
        if (mb_strpos($fee_search_text, $kw) !== false) {
            $is_fee = true;
            break;
        }
    }
    $start_date = !empty($bank_date) ? date('Y-m-d', strtotime($is_fee ? $bank_date : "$bank_date -90 days")) : '1970-01-01';
    $end_date = !empty($bank_date) ? date('Y-m-d', strtotime("$bank_date +90 days")) : date('Y-m-d', strtotime('+90 days'));

    $adjusted_amount_sql = "IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)";

    // Irány szűrés: negatív banki összeg → csak negatív OTS tétel, pozitív → pozitív
    $sign_having = '';
    if ($bank_amount < 0) {
        $sign_having = ' AND adjusted_amount < 0';
    } elseif ($bank_amount > 0) {
        $sign_having = ' AND adjusted_amount > 0';
    }

    $base_joins = "FROM TRANSACTIONS T
             LEFT JOIN PERSONS p ON T.PERSON_ID = p.id
             LEFT JOIN NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
             LEFT JOIN NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
             LEFT JOIN TRANSACTION_TYPE tt ON T.TYPE = tt.id
             LEFT JOIN USERS u ON T.EDITED_BY = u.id
             LEFT JOIN FUNDS funds ON T.FUND_ID = funds.id";

    $sql = "SELECT T.*,
                    $adjusted_amount_sql AS adjusted_amount,
                    TRIM(CONCAT(
                        IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''),
                        ' ', IFNULL(nt1.NAME, ''), ' ', IFNULL(nt2.NAME, '')
                   )) AS ots_desc_full,
                   tt.NAME AS ots_type_name,
                   u.NAME AS ots_editor_name,
                   funds.NAME AS fund_name,
               T.CHURCH_ID
               $base_joins
               WHERE T.CHURCH_ID = $church_id
                 AND T.DATETIME BETWEEN '$start_date' AND '$end_date'
                 AND $like_where
               GROUP BY T.RECORD_ID
               HAVING ABS(adjusted_amount) > 0 $sign_having
               ORDER BY T.DATETIME DESC
               LIMIT 100";

    // query()-vel futtatjuk (lásd fent: prepared + OR-láncú LIKE bug)
    $result = $ots_db->query($sql);
    if ($ots_db->errno) { $result = false; }
    $rows = [];
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $r['church_name'] = $church_names_map[$r['CHURCH_ID']] ?? null;
            $rid_int = (int)$r['RECORD_ID'];
            if (isset($used_map[$rid_int])) {
                $bank_ids = array_unique($used_map[$rid_int]);
                sort($bank_ids);
                $r['_used'] = true;
                $r['_used_count'] = count($bank_ids);
                $r['_used_bank_ids'] = implode(',', $bank_ids);
            }
            // Számoljuk a találati pontszámot
            $ots_text = mb_strtoupper(($r['ots_desc_full'] ?? '') . ' ' . ($r['fund_name'] ?? ''), 'UTF-8');
            $score = 0;
            foreach ($keywords as $kw) {
                if (mb_stripos($ots_text, $kw) !== false) {
                    $score++;
                }
            }
            $r['_text_score'] = $score;
            $r['_source'] = 'aggregation';
            $rows[] = $r;
        }
        // TRANSFERS_TO_CONFERENCE szöveges keresés — a leírás tartalmazza a kulcsszavakat
        if (!empty($bank_date)) {
            $tc_like_parts = [];
            foreach ($keywords as $kw) {
                $esc_kw = $ots_db->real_escape_string($kw);
                $tc_like_parts[] = "(CONCAT(tc.YEAR, '.', LPAD(tc.MONTH,2,'0'), ' havi') LIKE '%$esc_kw%')";
            }
            // Alapértelmezett kulcsszó: "Egyházterületnek" vagy "átutal" — mindig keressük
            $tc_base_kw = ['egyházterületnek', 'elutalt', 'átvezetés', 'transf'];
            $tc_always_match = false;
            foreach ($tc_base_kw as $bk) {
                foreach ($keywords as $kw) {
                    if (mb_stripos($kw, $bk) !== false || mb_stripos($bk, $kw) !== false) {
                        $tc_always_match = true;
                        break 2;
                    }
                }
            }
            if ($tc_always_match || !empty($tc_like_parts)) {
                $tc_where = !empty($tc_like_parts) ? implode(' OR ', $tc_like_parts) : '1=1';
                $tc_sql = "SELECT tc.id AS TC_ID, tc.YEAR, tc.MONTH, tc.DAY, tc.AMOUNT, tc.VIA_BANK,
                                  tc.CASH_DOCUMENT_NUMBER, tc.MODIFIED,
                                  CONCAT(tc.YEAR, '-', LPAD(tc.MONTH,2,'0'), '-', LPAD(tc.DAY,2,'0')) AS DATETIME,
                                  (-1 * tc.AMOUNT) AS adjusted_amount,
                                  CONCAT('Egyházterületnek elutalt (', tc.YEAR, '.', LPAD(tc.MONTH,2,'0'), ' havi, ',
                                         CASE tc.VIA_BANK WHEN 1 THEN 'Bank' ELSE 'KP' END, ')') AS ots_desc_full,
                                  'TransfToConf' AS ots_type_name,
                                  '-' AS ots_editor_name,
                                  '' AS fund_name,
                                  tc.CHURCH_ID
                           FROM TRANSFERS_TO_CONFERENCE tc
                           WHERE tc.CHURCH_ID = $church_id
                             AND CONCAT(tc.YEAR, '-', LPAD(tc.MONTH,2,'0'), '-', LPAD(tc.DAY,2,'0')) BETWEEN '$start_date' AND '$end_date'
                             AND tc.AMOUNT > 0
                             AND ($tc_where)
                           ORDER BY tc.YEAR DESC, tc.MONTH DESC
                           LIMIT 20";
                $tc_result = $ots_db->query($tc_sql);
                if ($tc_result && !$ots_db->errno) {
                    while ($tc = $tc_result->fetch_assoc()) {
                        $tc_rid = -1 * (int)$tc['TC_ID'];
                        $tc['RECORD_ID'] = $tc_rid;
                        $tc_text = mb_strtoupper($tc['ots_desc_full'] ?? '', 'UTF-8');
                        $score = 0;
                        foreach ($keywords as $kw) {
                            if (mb_stripos($tc_text, $kw) !== false) {
                                $score++;
                            }
                        }
                        $tc['_text_score'] = $score;
                        $tc['_source'] = 'aggregation';
                        if (isset($used_map[$tc_rid])) {
                            $bank_ids = array_unique($used_map[$tc_rid]);
                            sort($bank_ids);
                            $tc['_used'] = true;
                            $tc['_used_count'] = count($bank_ids);
                            $tc['_used_bank_ids'] = implode(',', $bank_ids);
                        }
                        $rows[] = $tc;
                    }
                }
            }
        }
        // Rendezés pontszám szerint csökkenően
        usort($rows, function ($a, $b) {
            return ($b['_text_score'] ?? 0) - ($a['_text_score'] ?? 0);
        });
    }

    echo json_encode(['status' => 'OK', 'data' => $rows, 'church_name' => ($rows[0]['church_name'] ?? ''), 'keywords' => $keywords, 'bank_words' => $bank_words]);
    exit;
}

// AJAX — OTS tételhez tartozó párosítatlan banki tételek keresése (fordított irány)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ots_find_bank_pairs') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    $church_id = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;
    $ots_date = isset($_POST['ots_date']) ? trim($_POST['ots_date']) : '';
    $ots_amount = isset($_POST['ots_amount']) ? floatval($_POST['ots_amount']) : 0;

    if ($church_id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó gyülekezet']);
        exit;
    }
    // scope check
    require_church_access($church_id);

    $start_date = !empty($ots_date) ? date('Y-m-d', strtotime("$ots_date -90 days")) : '1970-01-01';
    $end_date = !empty($ots_date) ? date('Y-m-d', strtotime("$ots_date +90 days")) : date('Y-m-d', strtotime('+90 days'));
    $neg_amount = -1 * abs($ots_amount);
    $pos_amount = abs($ots_amount);

    $sql = "SELECT id, bank_date, bank_amount, bank_desc, bank_ext_name, bank_init_name, 
                   bank_init_acc, bank_ben_name, bank_ben_acc, bank_ext_ref, comment
            FROM bank_reconciliation 
            WHERE church_id = ? 
              AND status = 'UNCHECKED'
              AND bank_date BETWEEN ? AND ?
            ORDER BY 
              CASE WHEN bank_amount = ? OR bank_amount = ? THEN 0 ELSE 1 END,
              ABS(bank_amount - ?) ASC,
              ABS(DATEDIFF(bank_date, ?)) ASC
            LIMIT 50";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('issddds', $church_id, $start_date, $end_date, $neg_amount, $pos_amount, $pos_amount, $ots_date);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = false;
    }
    $rows = [];
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $rows, 'ots_amount' => $ots_amount, 'ots_date' => $ots_date]);
    exit;
}

// AJAX — fordított párosítás mentése: kiválasztott banki tételek párosítása egy OTS tételhez
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_reverse_match') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    $ots_record_id = isset($_POST['ots_record_id']) ? intval($_POST['ots_record_id']) : 0;
    $ots_amount = isset($_POST['ots_amount']) ? floatval($_POST['ots_amount']) : 0;
    $ots_date = isset($_POST['ots_date']) ? trim($_POST['ots_date']) : '';
    $ots_date_dt = DateTime::createFromFormat('Y-m-d', $ots_date);
    $ots_date = ($ots_date_dt instanceof DateTime && $ots_date_dt->format('Y-m-d') === $ots_date) ? $ots_date : '';
    $church_id = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;
    $bank_ids = isset($_POST['bank_ids']) ? json_decode($_POST['bank_ids'], true) : [];

    if ($ots_record_id == 0 || empty($bank_ids) || $church_id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó paraméter']);
        exit;
    }

    // scope check
    require_church_access($church_id);

    // OTS adatok lekérése az összeg pontosításhoz
    $actual_ots_amount = $ots_amount;
    if ($ots_record_id > 0) {
        // TRANSACTIONS rekord
        $ots_check = $ots_db->prepare("SELECT adjusted_amount FROM (
            SELECT SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)) AS adjusted_amount
            FROM TRANSACTIONS T WHERE T.RECORD_ID = ? AND T.CHURCH_ID = ?
        ) sub");
        if ($ots_check) {
            $ots_check->bind_param('ii', $ots_record_id, $church_id);
            $ots_check->execute();
            $ots_check_res = $ots_check->get_result();
            if ($ots_check_res && $ots_check_res->num_rows > 0) {
                $row = $ots_check_res->fetch_assoc();
                $actual_ots_amount = $row['adjusted_amount'];
            }
        }
    } elseif ($ots_record_id < 0) {
        // TRANSFERS_TO_CONFERENCE rekord
        $tc_id = abs($ots_record_id);
        $tc_check = $ots_db->prepare("SELECT -1 * AMOUNT AS adjusted_amount FROM TRANSFERS_TO_CONFERENCE WHERE id = ?");
        if ($tc_check) {
            $tc_check->bind_param('i', $tc_id);
            $tc_check->execute();
            $tc_res = $tc_check->get_result();
            if ($tc_res && $tc_res->num_rows > 0) {
                $row = $tc_res->fetch_assoc();
                $actual_ots_amount = $row['adjusted_amount'];
            }
        }
    }

    $user = $_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen';
    $success_count = 0;

    foreach ($bank_ids as $bank_id) {
        $bank_id = intval($bank_id);
        if ($bank_id <= 0) continue;

        $comment = "[Reverse: OTS #{$ots_record_id} - {$actual_ots_amount} Ft]";
        $status = 'CSUSZAS';
        $ots_date_only = !empty($ots_date) ? substr($ots_date, 0, 10) : null;

        // Ha az összeg egyezik, OK státusz
        $bank_row_stmt = $conn->prepare("SELECT bank_amount FROM bank_reconciliation WHERE id = ? AND church_id = ?");
        if ($bank_row_stmt) {
            $bank_row_stmt->bind_param('ii', $bank_id, $church_id);
            $bank_row_stmt->execute();
            $bank_row = $bank_row_stmt->get_result();
        } else {
            $bank_row = false;
        }
        if ($bank_row && $bank_row->num_rows > 0) {
            $b = $bank_row->fetch_assoc();
            if (!empty($ots_date_only) && abs($b['bank_amount'] - $actual_ots_amount) < 0.01) {
                $status = 'OK';
                $comment = "[Reverse: 100% egyezés]";
            }
        }

        $doc_value = '';
        if ($ots_record_id > 0) {
            $doc_stmt = $ots_db->prepare("SELECT CASH_DOCUMENT_NUMBER FROM TRANSACTIONS WHERE RECORD_ID = ? LIMIT 1");
            if ($doc_stmt) {
                $doc_stmt->bind_param('i', $ots_record_id);
                $doc_stmt->execute();
                $doc_res = $doc_stmt->get_result();
                if ($doc_res && ($doc_row = $doc_res->fetch_assoc())) {
                    $doc_value = $doc_row['CASH_DOCUMENT_NUMBER'] ?? '';
                }
            }
        } elseif ($ots_record_id < 0) {
            $tc_doc_id = abs($ots_record_id);
            $tc_doc_stmt = $ots_db->prepare("SELECT CASH_DOCUMENT_NUMBER FROM TRANSFERS_TO_CONFERENCE WHERE id = ?");
            if ($tc_doc_stmt) {
                $tc_doc_stmt->bind_param('i', $tc_doc_id);
                $tc_doc_stmt->execute();
                $tc_doc_res = $tc_doc_stmt->get_result();
                if ($tc_doc_res && ($tc_doc_row = $tc_doc_res->fetch_assoc())) {
                    $doc_value = $tc_doc_row['CASH_DOCUMENT_NUMBER'] ?? '';
                }
            }
        }
        $sql = "UPDATE bank_reconciliation SET ots_record_id = ?, ots_amount = ?, ots_date = ?, ots_doc = ?, status = ?, comment = ?, updated_by = ? WHERE id = ? AND church_id = ?";
        $upd_stmt = $conn->prepare($sql);
        if ($upd_stmt) {
            $ots_date_final = $ots_date_only ?: null;
            $upd_stmt->bind_param('idsssssii', $ots_record_id, $actual_ots_amount, $ots_date_final, $doc_value, $status, $comment, $user, $bank_id, $church_id);
            $upd_stmt->execute();
            $success_count++;
        }
    }

    if ($success_count > 0) {
        echo json_encode(['status' => 'OK', 'message' => "$success_count banki tétel párosítva az OTS #{$ots_record_id} tételhez."]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Egyetlen banki tételt sem sikerült párosítani.']);
    }
    exit;
}

// AJAX — kiválasztott OTS sor(ok) párosítása a banki tételhez a modálból
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_ots_match') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $bank_date = isset($_POST['bank_date']) ? trim($_POST['bank_date']) : '';
    $bank_date_dt = DateTime::createFromFormat('Y-m-d', $bank_date);
    $bank_date = ($bank_date_dt instanceof DateTime && $bank_date_dt->format('Y-m-d') === $bank_date) ? $bank_date : '';
    $bank_amount = isset($_POST['bank_amount']) ? floatval($_POST['bank_amount']) : 0;
    $mode = isset($_POST['mode']) ? $_POST['mode'] : 'single';

    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó paraméterek']);
        exit;
    }

    // ensure user may modify this bank_reconciliation record
    $stmt_ch2 = $conn->prepare("SELECT church_id FROM bank_reconciliation WHERE id = ?");
    if ($stmt_ch2) {
        $stmt_ch2->bind_param('i', $id);
        $stmt_ch2->execute();
        $resch = $stmt_ch2->get_result();
        if (!$resch || $resch->num_rows === 0) { echo json_encode(['status'=>'ERROR','message'=>'Record not found']); exit; }
        $row = $resch->fetch_assoc();
        require_church_access(intval($row['church_id']));
    }

    // Védelem: a cél OTS tétel(ek) nem lehetnek már más banki tételhez párosítva
    $candidate_ids = [];
    if ($mode === 'multi' && isset($_POST['record_ids']) && is_array($_POST['record_ids'])) {
        foreach ($_POST['record_ids'] as $rid_post) {
            $rid_int = intval($rid_post);
            if ($rid_int != 0) { $candidate_ids[] = $rid_int; }
        }
    } elseif ($mode === 'single' && isset($_POST['ots_record_id']) && intval($_POST['ots_record_id']) != 0) {
        $candidate_ids[] = intval($_POST['ots_record_id']);
    }
    $candidate_ids = array_values(array_unique($candidate_ids));
    $blocked = [];
    if (!empty($candidate_ids)) {
        $cand_list = implode(',', array_fill(0, count($candidate_ids), '?'));
        $types = str_repeat('i', count($candidate_ids));
        // 1) ots_record_id mezőn történő hivatkozások
        $used_check = $conn->prepare("SELECT br.ots_record_id AS rid, br.id AS bid FROM bank_reconciliation br WHERE br.ots_record_id IN ($cand_list) AND br.id <> ?");
        if ($used_check) {
            $params = array_merge($candidate_ids, [$id]);
            $used_check->bind_param($types . 'i', ...$params);
            $used_check->execute();
            $used_res = $used_check->get_result();
            while ($u = $used_res->fetch_assoc()) {
                $blocked[(int)$u['rid']] = (int)$u['bid'];
            }
        }
        // 2) items táblában történő hivatkozások
        $used_check2 = $conn->prepare("SELECT bi.record_id AS rid, bi.reconciliation_id AS bid FROM bank_reconciliation_items bi WHERE bi.record_id IN ($cand_list)");
        if ($used_check2) {
            $used_check2->bind_param($types, ...$candidate_ids);
            $used_check2->execute();
            $used_res2 = $used_check2->get_result();
            while ($u = $used_res2->fetch_assoc()) {
                $blocked[(int)$u['rid']] = (int)$u['bid'];
            }
        }
        if (!empty($blocked)) {
            $list_str = implode(', ', array_map(function ($rid, $bid) { return "#$rid (banki: #$bid)"; }, array_keys($blocked), $blocked));
            echo json_encode(['status' => 'ERROR', 'message' => 'A kiválasztott OTS tétel(ek) már párosítva vannak: ' . $list_str]);
            exit;
        }
    }

    // Töröljük a korábbi items rekordokat
    $del_it = $conn->prepare("DELETE FROM bank_reconciliation_items WHERE reconciliation_id = ?");
    if ($del_it) { $del_it->bind_param('i', $id); $del_it->execute(); }

    if ($mode === 'multi' && isset($_POST['record_ids']) && is_array($_POST['record_ids'])) {
        // --- TÖBB OTS TÉTEL PÁROSÍTÁSA ---
        $record_ids = array_map('intval', $_POST['record_ids']);
        $record_ids = array_filter($record_ids, fn($v) => $v != 0);
        if (empty($record_ids)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Nincs kiválasztva OTS tétel']);
            exit;
        }

        // Szétválasztás: TRANSACTIONS (pozitív) vs TRANSFERS_TO_CONFERENCE (negatív)
        $tx_ids = array_filter($record_ids, fn($v) => $v > 0);
        $tc_ids = array_map('abs', array_filter($record_ids, fn($v) => $v < 0));

        $total_ots_amount = 0;
        $dates = [];
        $item_data = [];
        $docs = [];

        // TRANSACTIONS rekordok lekérdezése
        if (!empty($tx_ids)) {
            $rid_list = implode(',', array_fill(0, count($tx_ids), '?'));
            $items_stmt = $ots_db->prepare("SELECT RECORD_ID, AMOUNT, TYPE, DATETIME, CASH_DOCUMENT_NUMBER FROM TRANSACTIONS WHERE RECORD_ID IN ($rid_list)");
            if ($items_stmt) {
                $types = str_repeat('i', count($tx_ids));
                $items_stmt->bind_param($types, ...array_values($tx_ids));
                $items_stmt->execute();
                $items_res = $items_stmt->get_result();
                if ($items_res) {
                    while ($item = $items_res->fetch_assoc()) {
                        $adj = in_array($item['TYPE'], $exp_types) ? -1 * $item['AMOUNT'] : $item['AMOUNT'];
                        $total_ots_amount += $adj;
                        $dates[] = $item['DATETIME'];
                        $docs[] = $item['CASH_DOCUMENT_NUMBER'];
                        $item_data[] = $item;
                    }
                }
            }
        }

        // TRANSFERS_TO_CONFERENCE rekordok lekérdezése
        if (!empty($tc_ids)) {
            $tc_list = implode(',', array_fill(0, count($tc_ids), '?'));
            $tc_stmt = $ots_db->prepare("SELECT id AS RECORD_ID, AMOUNT, CONCAT(YEAR, '-', LPAD(MONTH,2,'0'), '-', LPAD(DAY,2,'0')) AS DATETIME, CASH_DOCUMENT_NUMBER FROM TRANSFERS_TO_CONFERENCE WHERE id IN ($tc_list)");
            if ($tc_stmt) {
                $types = str_repeat('i', count($tc_ids));
                $tc_stmt->bind_param($types, ...array_values($tc_ids));
                $tc_stmt->execute();
                $tc_res = $tc_stmt->get_result();
                if ($tc_res) {
                    while ($tc = $tc_res->fetch_assoc()) {
                        $total_ots_amount += (-1 * $tc['AMOUNT']);
                        $dates[] = $tc['DATETIME'];
                        $docs[] = $tc['CASH_DOCUMENT_NUMBER'];
                        $tc['RECORD_ID'] = -1 * (int)$tc['RECORD_ID'];
                        $item_data[] = $tc;
                    }
                }
            }
        }

        $ots_date_only = !empty($dates) ? substr(min($dates), 0, 10) : null;
        $ots_doc = implode(', ', array_unique(array_filter($docs, function($v) { return $v !== '' && $v !== '0000'; })));
        if (empty($ots_doc)) $ots_doc = implode(', ', $record_ids);
        $ots_amount = $total_ots_amount;

        $status = abs($ots_amount - $bank_amount) < 0.01 ? 'OSSZEVONT' : 'ELTERES';
        $comment = "[Több OTS tétel párosítva: " . count($record_ids) . " db, összeg: " . number_format($ots_amount, 2, ',', ' ') . " Ft]";

        // Items beszúrása
        $stmt_item = $conn->prepare("INSERT INTO bank_reconciliation_items (reconciliation_id, record_id, amount) VALUES (?, ?, ?)");
        if ($stmt_item) {
            foreach ($item_data as $it) {
                $adj = in_array($it['TYPE'], $exp_types) ? -1 * $it['AMOUNT'] : $it['AMOUNT'];
                $stmt_item->bind_param("iid", $id, $it['RECORD_ID'], $adj);
                $stmt_item->execute();
            }
        }

        $upd = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_amount=?, status=?, comment=? WHERE id=?");
        if ($upd) {
            $upd->bind_param("ssdssi", $ots_date_only, $ots_doc, $ots_amount, $status, $comment, $id);
            $upd->execute();
            log_activity('save_ots_match', ['bank_reconciliation' => $id, 'church_id' => intval($row['church_id']), 'record_ids' => array_values($record_ids), 'status' => $status]);
            echo json_encode(['status' => 'OK', 'message' => 'Több OTS tétel párosítva. Státusz: ' . $status]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Lekérdezési hiba']);
        }
    } else {
        // --- EGY OTS TÉTEL PÁROSÍTÁSA (eredeti működés) ---
        $ots_doc = isset($_POST['ots_doc']) ? mb_substr(trim($_POST['ots_doc']), 0, 50, 'UTF-8') : '';
        $ots_record_id = isset($_POST['ots_record_id']) ? intval($_POST['ots_record_id']) : 0;
        $ots_date = isset($_POST['ots_date']) ? trim($_POST['ots_date']) : '';
        $ots_date_dt = DateTime::createFromFormat('Y-m-d', $ots_date);
        $ots_date = ($ots_date_dt instanceof DateTime && $ots_date_dt->format('Y-m-d') === $ots_date) ? $ots_date : '';
        $ots_amount = isset($_POST['ots_amount']) ? floatval($_POST['ots_amount']) : 0;

        if (empty($ots_record_id)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Nincs kiválasztva OTS tétel']);
            exit;
        }

        $status = 'CSUSZAS';
        $comment = "[Manual: modálból párosítva]";
        $ots_date_only = !empty($ots_date) ? substr($ots_date, 0, 10) : null;
        if (!empty($bank_date) && $ots_date_only === $bank_date && abs($ots_amount - $bank_amount) < 0.01) {
            $status = 'OK';
            $comment = "[Manual: 100% egyezés, 0 nap]";
        } elseif (abs($ots_amount - $bank_amount) < 0.01) {
            $status = 'CSUSZAS';
            $comment = "[Manual: összeg egyezik, dátum eltérés]";
        } else {
            $status = 'ELTERES';
            $comment = "[Manual: eltérő összeg, kézi párosítás]";
        }

        // RECORD_ID meghatározása
        $record_id = isset($_POST['ots_record_id']) ? intval($_POST['ots_record_id']) : 0;
        if ($record_id == 0 && !empty($ots_doc)) {
            $rid_church_id = 0;
            $rid_stmt = $conn->prepare("SELECT church_id FROM bank_reconciliation WHERE id = ?");
            if ($rid_stmt) { $rid_stmt->bind_param('i', $id); $rid_stmt->execute(); $rch = $rid_stmt->get_result(); if ($rch && $rr = $rch->fetch_assoc()) { $rid_church_id = (int)$rr['church_id']; } }
            if ($rid_church_id > 0) {
                $stmt_r = $ots_db->prepare("SELECT RECORD_ID, AMOUNT, TYPE FROM TRANSACTIONS WHERE CASH_DOCUMENT_NUMBER = ? AND CHURCH_ID = ? LIMIT 1");
                if ($stmt_r) {
                    $stmt_r->bind_param('si', $ots_doc, $rid_church_id);
                    $stmt_r->execute();
                    $rid_res = $stmt_r->get_result();
                    if ($rid_res && $rid_res->num_rows > 0) {
                        $rid_row = $rid_res->fetch_assoc();
                        $record_id = $rid_row['RECORD_ID'];
                    }
                }
            }
        }
        if ($record_id > 0) {
            // TRANSACTIONS — Insert individual OTS items (handles TYPE=1 multi-item record_ids)
            $church_id_item = intval($row['church_id']);
            $its_sql = "SELECT id, AMOUNT, TYPE, CASH_DOCUMENT_NUMBER, DATETIME FROM TRANSACTIONS WHERE RECORD_ID = ? AND CHURCH_ID = ? ORDER BY id";
            $its_st = $ots_db->prepare($its_sql);
            $item_dates = [];
            $item_docs = [];
            if ($its_st) {
                $its_st->bind_param("ii", $record_id, $church_id_item);
                $its_st->execute();
                $its_res = $its_st->get_result();
                $stmt_item = $conn->prepare("INSERT INTO bank_reconciliation_items (reconciliation_id, record_id, amount) VALUES (?, ?, ?)");
                if ($stmt_item) {
                    while ($it = $its_res->fetch_assoc()) {
                        $adj = in_array((int)$it['TYPE'], $exp_types) ? -1.0 * (float)$it['AMOUNT'] : (float)$it['AMOUNT'];
                        $stmt_item->bind_param("iid", $id, $record_id, $adj);
                        $stmt_item->execute();
                        $item_dates[] = $it['DATETIME'];
                        if (!empty($it['CASH_DOCUMENT_NUMBER']) && $it['CASH_DOCUMENT_NUMBER'] !== '0000') {
                            $item_docs[] = $it['CASH_DOCUMENT_NUMBER'];
                        }
                    }
                }
            }
            // update ots_date / ots_doc from individual items
            $earliest_date = !empty($item_dates) ? substr(min($item_dates), 0, 10) : $ots_date_only;
            $doc_list = !empty($item_docs) ? implode(', ', array_unique($item_docs)) : ((string)$record_id);
            if ($earliest_date !== null) $ots_date_only = $earliest_date;
            if (!empty($doc_list)) $ots_doc = $doc_list;
        } elseif ($record_id < 0) {
            // TRANSFERS_TO_CONFERENCE — negatív record_id
            $tc_id = abs($record_id);
            $tc_item = $ots_db->prepare("SELECT id, AMOUNT, CONCAT(YEAR, '-', LPAD(MONTH,2,'0'), '-', LPAD(DAY,2,'0')) AS DATETIME, CASH_DOCUMENT_NUMBER FROM TRANSFERS_TO_CONFERENCE WHERE id = ?");
            if ($tc_item) {
                $tc_item->bind_param("i", $tc_id);
                $tc_item->execute();
                $tc_res = $tc_item->get_result();
                if ($tc_res && $tc_row = $tc_res->fetch_assoc()) {
                    $item_dates = [$tc_row['DATETIME']];
                    $item_docs = [];
                    if (!empty($tc_row['CASH_DOCUMENT_NUMBER']) && $tc_row['CASH_DOCUMENT_NUMBER'] !== '0000') {
                        $item_docs[] = $tc_row['CASH_DOCUMENT_NUMBER'];
                    }
                    $stmt_item = $conn->prepare("INSERT INTO bank_reconciliation_items (reconciliation_id, record_id, amount) VALUES (?, ?, ?)");
                    if ($stmt_item) {
                        $adj = -1.0 * (float)$tc_row['AMOUNT'];
                        $stmt_item->bind_param("iid", $id, $record_id, $adj);
                        $stmt_item->execute();
                    }
                    $earliest_date = substr($tc_row['DATETIME'], 0, 10);
                    $doc_list = !empty($item_docs) ? implode(', ', $item_docs) : "TC-{$tc_id}";
                    $ots_date_only = $earliest_date;
                    $ots_doc = $doc_list;
                }
            }
        }

        $upd = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
        if ($upd) {
            $upd->bind_param("ssidssi", $ots_date_only, $ots_doc, $record_id, $ots_amount, $status, $comment, $id);
            $upd->execute();
            log_activity('save_ots_match', ['bank_reconciliation' => $id, 'church_id' => intval($row['church_id']), 'ots_record_id' => $record_id, 'ots_doc' => $ots_doc, 'status' => $status]);
            echo json_encode(['status' => 'OK', 'message' => 'Párosítás mentve. Státusz: ' . $status]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Lekérdezési hiba']);
        }
    }
    exit;
}

// PÁROSÍTÁS BONTÁSA (unpair) — törli a hozzárendelt items rekordokat,
// és a banki tételt visszaállítja [Feldolgozatlan] állapotba.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unpair_bank') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó paraméterek']);
        exit;
    }

    // Gyülekezet-hozzáférés ellenőrzése (IDOR védelem)
    $row = ['church_id' => 0];
    $stmt_ch2 = $conn->prepare("SELECT church_id FROM bank_reconciliation WHERE id = ?");
    if ($stmt_ch2) {
        $stmt_ch2->bind_param('i', $id);
        $stmt_ch2->execute();
        $resch = $stmt_ch2->get_result();
        if (!$resch || $resch->num_rows === 0) { echo json_encode(['status' => 'ERROR', 'message' => 'Record not found']); exit; }
        $row = $resch->fetch_assoc();
        require_church_access(intval($row['church_id']));
    }

    // A hozzárendelt OTS tételek törlése és a banki tétel visszaállítása
    $del_it = $conn->prepare("DELETE FROM bank_reconciliation_items WHERE reconciliation_id = ?");
    if ($del_it) { $del_it->bind_param('i', $id); $del_it->execute(); }

    $upd = $conn->prepare("UPDATE bank_reconciliation SET ots_record_id=NULL, ots_date=NULL, ots_doc='', ots_amount=NULL, status='UNCHECKED', comment='[Párosítás bontva]' WHERE id=?");
    if ($upd) {
        $upd->bind_param('i', $id);
        $upd->execute();
        log_activity('unmatch', ['bank_reconciliation' => $id, 'church_id' => intval($row['church_id'])]);
        echo json_encode(['status' => 'OK', 'message' => 'Párosítás bontva, a tétel ismét [Feldolgozatlan]']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Lekérdezési hiba']);
    }
    exit;
}

// LAPOZÁS ÉS SZŰRÉS INICIALIZÁLÁSA
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$allowed_limits = [50, 100, 500, 999999];
$limit = isset($_GET['limit']) && in_array(intval($_GET['limit']), $allowed_limits) ? intval($_GET['limit']) : 50;
if ($limit >= 999999) { $page = 1; $offset = 0; } else { $offset = ($page - 1) * $limit; }

$selected_church_name = is_admin() ? (isset($_GET['church_filter']) ? trim($_GET['church_filter']) : '') : '';
$selected_church_id = -1;
if (!is_admin()) {
    $selected_church_id = require_selected_church('reconciliation.php');
    $selected_church_name = $_SESSION['revizor_selected_church_name'] ?? '';
} elseif (empty($selected_church_name) && !empty($_SESSION['revizor_selected_church'])) {
    // Ha nincs GET param, de van session-ben tárolt gyülekezet, használjuk azt.
    // Régebbi munkamenetekben előfordulhat, hogy csak az ID van meg, a név nem.
    $selected_church_id = intval($_SESSION['revizor_selected_church']);
    if (!empty($_SESSION['revizor_selected_church_name'])) {
        $selected_church_name = $_SESSION['revizor_selected_church_name'];
    } elseif ($selected_church_id > 0) {
        set_selected_church_session($selected_church_id);
        $selected_church_name = $_SESSION['revizor_selected_church_name'] ?? '';
    }
} elseif (empty($selected_church_name)) {
    // Session üres: próbáljuk a 7 napos preferenciát
    $pref_church_id = get_selected_church_id();
    if ($pref_church_id > 0) {
        $selected_church_id = $pref_church_id;
        $selected_church_name = $_SESSION['revizor_selected_church_name'] ?? '';
    }
}
$auto_bank_id = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;

// Betöltjük a számlaszám térképet, hogy tudjuk, kinek van bankszámlája
$mapped_ids = [];
$cba_res = $conn->query("SELECT DISTINCT church_id FROM church_bank_accounts WHERE church_id > 0");
if ($cba_res) {
    while ($cba = $cba_res->fetch_assoc()) {
        $mapped_ids[] = (int)$cba['church_id'];
    }
}
$mapped_ids_str = !empty($mapped_ids) ? implode(',', $mapped_ids) : "0";

// Gyülekezeti lista és szűrési paraméterek meghatározása
$churches = [];
$church_names_map = [];
$church_filter_ids_array = [];
if (is_admin()) {
    $church_filter_ids = $mapped_ids_str;
    $church_filter_ids_array = $mapped_ids;
} elseif (empty($accessible_church_ids)) {
    $church_filter_ids = '0';
} else {
    $church_filter_ids_array = array_values(array_intersect(array_map('intval', $mapped_ids), array_map('intval', $accessible_church_ids)));
    $church_filter_ids = implode(',', $church_filter_ids_array);
    if ($church_filter_ids === '') {
        $church_filter_ids = '0';
    }
}
$churches_query = false;
if (!empty($church_filter_ids_array)) {
    $church_placeholders = implode(',', array_fill(0, count($church_filter_ids_array), '?'));
    $churches_sql = "SELECT id, name FROM CHURCHES WHERE id IN ($church_placeholders) AND name IS NOT NULL AND name != '' ORDER BY name ASC";
    $churches_stmt = $ots_db->prepare($churches_sql);
    if ($churches_stmt) {
        $church_types = str_repeat('i', count($church_filter_ids_array));
        $churches_stmt->bind_param($church_types, ...$church_filter_ids_array);
        $churches_stmt->execute();
        $churches_query = $churches_stmt->get_result();
    }
}
if ($churches_query && $churches_query->num_rows > 0) {
    while ($c_row = $churches_query->fetch_assoc()) {
        $churches[] = $c_row['name'];
        $church_names_map[$c_row['id']] = $c_row['name'];
        if ($c_row['name'] === $selected_church_name) {
            $selected_church_id = $c_row['id'];
        }
    }
} else {
    // Fallback: konfigból
    $cfg = load_app_config();
    if (!empty($cfg['churches']) && is_array($cfg['churches'])) {
        foreach ($cfg['churches'] as $id => $name) {
            if (in_array($id, $church_filter_ids_array)) {
                $churches[] = $name;
                $church_names_map[$id] = $name;
            }
        }
    }
}

// Aktív gyülekezet szinkronizálása a session-nel (admin): amit a szűrőben kiválasztunk,
// az érvényes legyen a többi oldalon is, amíg át nem állítjuk.
if (is_admin() && $selected_church_id > 0) {
    set_selected_church_session($selected_church_id);
} elseif (is_admin() && isset($_GET['church_filter']) && trim($_GET['church_filter']) === '' && $_GET['church_filter'] === '') {
    unset($_SESSION['revizor_selected_church'], $_SESSION['revizor_selected_church_name']);
}

$where_parts = [];
$where_params = [];
$where_types = '';
if ($selected_church_id !== -1) {
    $where_parts[] = 'b.church_id = ?';
    $where_params[] = $selected_church_id;
    $where_types .= 'i';
} elseif (is_admin()) {
    if (empty($mapped_ids)) {
        $where_parts[] = '1=0';
    } else {
        $mapped_placeholders = implode(',', array_fill(0, count($mapped_ids), '?'));
        $where_parts[] = "b.church_id IN ($mapped_placeholders)";
        foreach ($mapped_ids as $mid) {
            $where_params[] = $mid;
            $where_types .= 'i';
        }
    }
} elseif (empty($church_filter_ids_array)) {
    $where_parts[] = '1=0';
} else {
    $filter_placeholders = implode(',', array_fill(0, count($church_filter_ids_array), '?'));
    $where_parts[] = "b.church_id IN ($filter_placeholders)";
    foreach ($church_filter_ids_array as $fid) {
        $where_params[] = $fid;
        $where_types .= 'i';
    }
}
$where_sql = $where_parts ? ' WHERE ' . implode(' AND ', $where_parts) : '';
$url_params = !empty($selected_church_name) ? "church_filter=" . urlencode($selected_church_name) : "";

// Teljes rekordszám lekérése a lapozáshoz
$count_sql = "SELECT COUNT(*) as cnt FROM bank_reconciliation b $where_sql";
$count_res = false;
if (!empty($where_params)) {
    $count_stmt = $conn->prepare($count_sql);
    if ($count_stmt) {
        $count_stmt->bind_param($where_types, ...$where_params);
        $count_stmt->execute();
        $count_res = $count_stmt->get_result();
    }
} else {
    $count_res = $conn->query($count_sql);
}
$total_db_rows = ($count_res) ? $count_res->fetch_assoc()['cnt'] : 0;
$total_pages = $limit > 0 ? ceil($total_db_rows / $limit) : 1;

// A főtáblát kiegészítjük az OTS rendszer valós idejű adataival — kétlépéses lekérdezés a gyorsaságért
$bank_sql = "SELECT 
                                b.*, 
                                items.item_count,
                                items.item_amounts
                            FROM bank_reconciliation b
                            LEFT JOIN (
                                SELECT reconciliation_id, COUNT(*) as item_count,
                                       GROUP_CONCAT(CAST(amount AS CHAR) SEPARATOR ' + ') as item_amounts
                                FROM bank_reconciliation_items
                                GROUP BY reconciliation_id
                            ) items ON b.id = items.reconciliation_id
                            $where_sql
                            ORDER BY b.bank_date ASC";
$bank_sql .= " LIMIT ? OFFSET ?";
$bank_query = false;
if (!empty($where_params)) {
    $bank_stmt = $conn->prepare($bank_sql);
    if ($bank_stmt) {
        $bank_params = array_merge($where_params, [$limit, $offset]);
        $bank_types = $where_types . 'ii';
        $bank_stmt->bind_param($bank_types, ...$bank_params);
        $bank_stmt->execute();
        $bank_query = $bank_stmt->get_result();
    }
} else {
    $bank_stmt = $conn->prepare($bank_sql);
    if ($bank_stmt) {
        $bank_stmt->bind_param('ii', $limit, $offset);
        $bank_stmt->execute();
        $bank_query = $bank_stmt->get_result();
    }
}
$rows = [];
if ($bank_query) {
    while ($row = $bank_query->fetch_assoc()) {
        // Church name feloldása a konfigból (ha nincs meg az OTS JOIN)
        $row['church_name'] = $church_names_map[$row['church_id']] ?? null;
        $rows[] = $row;
    }
}
$total_rows = count($rows);

// Ha bank_id van az URL-ben, biztosítjuk, hogy az a rekord is benne legyen az eredmények között (még ha más oldalon van is)
$auto_bank_row = null;
if ($auto_bank_id > 0) {
    $found = false;
    foreach ($rows as $r) {
        if (intval($r['id']) === $auto_bank_id) { $found = true; break; }
    }
    if (!$found) {
        $ab_stmt = $conn->prepare("SELECT * FROM bank_reconciliation WHERE id = ?");
        if ($ab_stmt) {
            $ab_stmt->bind_param('i', $auto_bank_id);
            $ab_stmt->execute();
            $ab_res = $ab_stmt->get_result();
            if ($ab_res && $ab_row = $ab_res->fetch_assoc()) {
                if (require_church_access(intval($ab_row['church_id']))) {
                    $ab_row['church_name'] = $church_names_map[$ab_row['church_id']] ?? null;
                    $auto_bank_row = $ab_row;
                    // Beszúrjuk az első helyre
                    array_unshift($rows, $ab_row);
                }
            }
        }
    }
}

// OTS adatok külön lekérdezése azokhoz a sorokhoz, ahol van ots_record_id
$ots_ids = [];
$tc_ots_ids = []; // sorindex => record_id lista TC párosításhoz
foreach ($rows as $idx => $row) {
    $rid = $row['ots_record_id'] ?? null;
    if (!empty($rid) && $rid > 0) {
        $ots_ids[(int)$rid] = $idx;
    }
    // TC párosított sorok: nincs ots_record_id, de van bank_reconciliation_items
    if (empty($rid) && !empty($row['item_count']) && $row['item_count'] > 0) {
        $tc_ots_ids[$idx] = [];
    }
}

// TC items lekérése
if (!empty($tc_ots_ids)) {
    $tc_ids = array_keys($tc_ots_ids);
    $tc_placeholders = implode(',', array_fill(0, count($tc_ids), '?'));
    $tc_items_sql = "SELECT reconciliation_id, record_id, amount FROM bank_reconciliation_items WHERE reconciliation_id IN ($tc_placeholders) ORDER BY id";
    $tc_items_stmt = $conn->prepare($tc_items_sql);
    $tc_items_res = false;
    if ($tc_items_stmt) {
        $tc_types = str_repeat('i', count($tc_ids));
        $tc_items_stmt->bind_param($tc_types, ...$tc_ids);
        $tc_items_stmt->execute();
        $tc_items_res = $tc_items_stmt->get_result();
    }
    if ($tc_items_res) {
        while ($tc = $tc_items_res->fetch_assoc()) {
            $idx = $tc['reconciliation_id'];
            if (isset($tc_ots_ids[$idx])) {
                $tc_ots_ids[$idx][] = (int)$tc['record_id'];
                $ots_ids[(int)$tc['record_id']] = $idx; // reuse for the fetch
            }
        }
    }
}

if (!empty($ots_ids)) {
    $ots_record_ids = array_keys($ots_ids);
    $id_list = implode(',', array_fill(0, count($ots_record_ids), '?'));
    
    $ots_sql = "
        SELECT t.RECORD_ID,
               t.DECISION_NUMBER AS ots_decision, t.PERSON_ID, t.TYPE,
               t.CASH_DOCUMENT_NUMBER AS ots_doc,
               TRIM(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX)) AS person_name,
               nt1.NAME AS nt1_name, nt2.NAME AS nt2_name,
               tt.NAME AS ots_type,
               u.NAME AS ots_editor,
               funds.NAME AS fund_name
        FROM TRANSACTIONS t
        LEFT JOIN PERSONS p ON t.PERSON_ID = p.id
        LEFT JOIN NAMES_OF_TRANSACTION nt1 ON t.NAME_ID = nt1.id
        LEFT JOIN NAMES_OF_TRANSACTION nt2 ON t.NAME2_ID = nt2.id
        LEFT JOIN TRANSACTION_TYPE tt ON t.TYPE = tt.id
        LEFT JOIN USERS u ON t.EDITED_BY = u.id
        LEFT JOIN FUNDS funds ON t.FUND_ID = funds.id
        WHERE t.RECORD_ID IN ($id_list)
    ";
    $ots_stmt = $ots_db->prepare($ots_sql);
    $ots_result = false;
    if ($ots_stmt) {
        $ots_types = str_repeat('i', count($ots_record_ids));
        $ots_stmt->bind_param($ots_types, ...$ots_record_ids);
        $ots_stmt->execute();
        $ots_result = $ots_stmt->get_result();
    }
    
    $ots_map = [];
    if ($ots_result) {
        while ($o = $ots_result->fetch_assoc()) {
            $ots_map[(int)$o['RECORD_ID']] = $o;
        }
    }
    
    foreach ($ots_ids as $rid => $idx) {
        if (isset($ots_map[$rid])) {
            $o = $ots_map[$rid];
            // TC többszörös rekordoknál csak az elsőt használjuk
            if (isset($rows[$idx]['ots_desc_full'])) continue;
            $desc = trim($o['person_name'] . ' ' . ($o['nt1_name'] ?? '') . ' ' . ($o['nt2_name'] ?? ''));
            if (empty($desc)) {
                $parts = [];
                if (!empty($o['fund_name'])) $parts[] = $o['fund_name'];
                if (!empty($o['ots_doc'])) $parts[] = $o['ots_doc'];
                if (!empty($o['ots_decision']) && $o['ots_decision'] !== '0' && $o['ots_decision'] !== '-') $parts[] = $o['ots_decision'];
                $desc = implode(' - ', $parts);
            }
            $rows[$idx]['ots_desc_full'] = $desc;
            $rows[$idx]['ots_decision'] = $o['ots_decision'];
            $rows[$idx]['ots_type'] = $o['ots_type'];
            $rows[$idx]['ots_editor'] = $o['ots_editor'];
        }
    }
}

// Alapértelmezett értékek azokhoz a sorokhoz, ahol nincs OTS találat
foreach ($rows as &$row) {
    if (!isset($row['ots_desc_full'])) $row['ots_desc_full'] = null;
    if (!isset($row['ots_decision'])) $row['ots_decision'] = null;
    if (!isset($row['ots_type'])) $row['ots_type'] = null;
    if (!isset($row['ots_editor'])) $row['ots_editor'] = null;
}
unset($row);
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>Revizor Asszisztens 1.0 – Bankegyeztetés</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding: 2px; padding-bottom: 45px; }
        .table-container { background: white; padding: 5px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        
        .table-responsive-scroll {
            max-height: 82vh;
            overflow-y: auto;
            overflow-x: auto;
            border: 1px solid #dee2e6;
        }
        
        #sortableTable {
            table-layout: auto;
            width: auto;
            min-width: 100%;
        }

        .truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .main-header th {
            position: sticky; top: 0; z-index: 10;
            background-color: #212529 !important; color: white !important;
            padding: 4px 10px; font-size: 13px; text-align: center;
        }
        
        .sub-header th {
            position: sticky; top: 29px; z-index: 10;
            background-color: #e9ecef !important; color: #212529 !important;
            cursor: pointer; user-select: none; padding: 4px 6px; font-size: 12px;
            text-align: center; vertical-align: top;
        }
        
        .sub-header th:hover { background-color: #dee2e6 !important; }
        .bg-bank { background-color: #f1f3f5; }
        .bg-ots { background-color: #ffffff; }
        .clickable-amount { cursor: pointer; text-decoration: underline; text-decoration-style: dotted; }
        .clickable-amount:hover { color: #0d6efd !important; background-color: #e9ecef; }
        .status-unchecked { color: #6c757d; font-style: italic; }
        #sortableTable.table-compact td,
        #sortableTable.table-compact th { padding: 2px 3px !important; }
        #sortableTable.table-compact { font-size: 11px; }
        .sort-asc::after { content: " ↑"; font-size: 10px; color: #0d6efd; }
        .sort-desc::after { content: " ↓"; font-size: 10px; color: #0d6efd; }

        .resize-handle {
            position: absolute; right: 0; top: 0; bottom: 0;
            width: 5px; cursor: col-resize; z-index: 5;
        }
        .resize-handle:hover,
        .resize-handle:active { background-color: #0d6efd; }

        .status-bar-fixed {
            position: fixed; bottom: 0; left: 0; width: 100%;
            background-color: #212529; color: #f8f9fa;
            padding: 4px 20px; font-size: 12px; font-weight: 500;
            box-shadow: 0 -2px 5px rgba(0,0,0,0.1); z-index: 1030;
            display: flex; justify-content: space-between; align-items: center;
        }

        .filter-input {
            width: 100%; display: block; margin-top: 4px; padding: 2px 4px;
            font-size: 11px; font-weight: normal; border: 1px solid #ccc; border-radius: 3px;
        }
        .filter-input:focus { border-color: #0d6efd; outline: 0; box-shadow: 0 0 3px rgba(13,110,253,0.3); }
        .info-dot { font-size: 10px; color: #0d6efd; vertical-align: super; margin-left: 2px; }

        .checklist-item { padding: 6px 0; border-bottom: 1px solid #eee; }
        .checklist-item:last-child { border-bottom: none; }

        /* Keresőmező design igazítása az új feltöltés gomb stílusához */
        .church-search-box {
            width: 280px;
            height: 31px;
            font-size: 13px;
            padding: 0 10px;
            border-radius: 4px;
            border: 1px solid #0d6efd;
            color: #0d6efd;
            font-weight: 500;
            background-color: #ffffff;
        }
        .church-search-box::placeholder { color: #6c757d; font-weight: normal; }
        .church-search-box:focus { outline: none; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); }

        /* Dinamikus Statisztika Buborék (Hover Tooltip) */
        .custom-tooltip-container {
            position: relative; display: inline-block; cursor: help; background-color: rgba(255,255,255,0.1); padding: 2px 8px; border-radius: 4px;
        }
        .custom-tooltip-container:hover { background-color: rgba(255,255,255,0.2); }
        .custom-tooltip-text {
            visibility: hidden; width: 220px; background-color: #343a40; color: #fff; text-align: left;
            border-radius: 6px; padding: 10px; position: absolute; z-index: 1050;
            bottom: 130%; left: 50%; margin-left: -110px; opacity: 0; transition: opacity 0.2s;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3); font-size: 13px; line-height: 1.6; border: 1px solid #495057;
        }
        .custom-tooltip-container:hover .custom-tooltip-text { visibility: visible; opacity: 1; }
        .custom-tooltip-text::after {
            content: ""; position: absolute; top: 100%; left: 50%; margin-left: -5px;
            border-width: 5px; border-style: solid; border-color: #343a40 transparent transparent transparent;
        }
        .stat-row { display: flex; justify-content: space-between; }
        #perPageLoadingOverlay.show { display: flex !important; }

        /* === Részletek modal – párhuzamos nézet === */
        #combinedDetailsModal .modal-body {
            max-height: 78vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        #combinedDetailsModal .parallel-row {
            display: flex;
            flex: 1 1 auto;
            overflow: hidden;
            min-height: 0;
        }
        #combinedDetailsModal .parallel-col {
            flex: 1 1 50%;
            width: 50%;
            overflow-y: auto;
            min-height: 0;
        }
        #combinedDetailsModal .accordion-button {
            padding: 0.4rem 0.75rem;
        }
        #combinedDetailsModal .accordion-button .badge {
            font-size: 0.75rem;
        }
        #combinedDetailsModal .accordion-body {
            padding: 0;
        }
        #combinedDetailsModal .accordion-body table th,
        #combinedDetailsModal .accordion-body table td {
            padding: 0.25rem 0.5rem;
            line-height: 1.4;
        }
        #combinedDetailsModal .accordion-body table {
            margin-bottom: 0;
        }
        #combinedDetailsModal h2.accordion-header {
            margin: 0;
        }
        #combinedDetailsModal .accordion-item {
            margin-bottom: 1px;
        }
        #combinedDetailsModal .comment-bar {
            flex-shrink: 0;
        }
    </style>
</head>
<body>

<div class="container-fluid table-container">
    
    <div class="d-flex align-items-center gap-2 mb-2">
        <h5 class="m-0 text-nowrap">🕵️‍♂️ Revizor Asszisztens 1.0 <small class="text-muted fw-normal ms-1">Bankegyeztetés</small></h5>
        <a href="index.php" class="btn btn-outline-secondary btn-sm text-nowrap">🏠 Kezdőlap</a>
        <form method="GET" action="reconciliation.php" class="d-flex gap-1 align-items-center">
            <input type="hidden" name="limit" value="<?php echo $limit; ?>">
            <input type="hidden" id="currentChurchId" value="<?php echo $selected_church_id; ?>">
            <?php if (is_admin()): ?>
            <input list="churchesList" name="church_filter" id="churchSelect" class="form-control church-search-box" placeholder="Válassz gyülekezetet..." value="<?php echo htmlspecialchars($selected_church_name); ?>" onchange="showPerPageLoading(this.form)" autocomplete="off" style="height:31px;">
            <datalist id="churchesList">
                <?php foreach ($churches as $church): ?>
                    <option value="<?php echo htmlspecialchars($church); ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <?php if($selected_church_id !== -1): ?><a href="reconciliation.php?church_filter=" class="btn btn-sm btn-outline-danger text-nowrap" title="Szűrés törlése">✕</a><?php endif; ?>
            <?php else: ?>
            <span class="form-control bg-light" style="height:31px;width:auto;display:inline-block;border:1px solid #dee2e6;padding:2px 8px;border-radius:4px;line-height:26px;">
                🏛 <?php echo htmlspecialchars($selected_church_name ?: '#' . $selected_church_id); ?>
            </span>
            <?php endif; ?>
        </form>
        <nav class="navbar navbar-light p-0 ms-auto position-relative">
            <?php render_user_badge(); ?>
            <?php render_dev_toggle(); ?>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#actionMenu" aria-label="Menü">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse position-absolute end-0 top-100" id="actionMenu" style="z-index:1040; min-width:max-content;">
                <div class="d-flex bg-white border rounded shadow-sm p-1" onclick="var p=document.getElementById('actionMenu'); if(p) p.classList.remove('show');">
                    <button class="btn btn-outline-secondary btn-sm fw-bold" id="fontSizeBtn" onclick="event.stopPropagation(); toggleFontSize()" title="Betűméret váltása (kicsi/nagy)">🔍−</button>
                    <button class="btn btn-outline-secondary btn-sm fw-bold" onclick="exportTableToCSV()">📥 Export</button>
                    <button class="btn btn-outline-info btn-sm fw-bold" onclick="bulkApproveCsuszas()">✅ Csúszások OK</button>
                    <button class="btn btn-outline-success btn-sm fw-bold" onclick="requireAdminThen(function(){ new bootstrap.Modal(document.getElementById('autoMatchModal')).show(); })">🤖 Auto Párosítás</button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="requireAdminThen(function(){ loadAutoMatchLog(); new bootstrap.Modal(document.getElementById('autoMatchLogModal')).show(); })" title="Auto-match futási napló">📋 Log</button>
                    <button class="btn btn-outline-warning btn-sm fw-bold" onclick="openCustomPatterns()">🔧 Kulcsszavak</button>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm">Kilépés</a>
                </div>
            </div>
        </nav>
    </div>
    
    <div class="table-responsive-scroll">
        <table class="table table-bordered align-middle m-0" id="sortableTable">
            <colgroup id="colGroup">
                <col><col><col><col><col><col><col><col><col><col><col><col>
            </colgroup>
            <thead>
                <tr class="main-header">
                    <th style="background-color: #495057 !important;">ADMIN</th>
                    <th colspan="3">BANKI ADATOK (Fix)</th>
                    <th colspan="5" style="background-color: #495057 !important;">KÖNYVELÉS / OTS (Fix)</th>
                    <th colspan="3" style="background-color: #0d6efd !important;">REVIZOR INTÉZKEDÉS</th>
                </tr>
                <tr class="sub-header">
                    <th onclick="sortTable(0, 'string')">ID / Gyülekezet <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    <th onclick="sortTable(1, 'date')">Dátum <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    
                    <th onclick="sortTable(2, 'amount')" 
                        data-bs-toggle="tooltip" 
                        data-bs-placement="top" 
                        data-bs-html="true"
                        title="<b>Összeg szűrési tippek:</b><br>- kimenő<br>+ bejövő<br>-tól [szóköz] -ig">
                        Összeg <span class="info-dot">ℹ</span>
                        <input type="text" class="filter-input" placeholder="Kimenő, bejövő..." onclick="event.stopPropagation();" onkeyup="filterTable()">
                    </th>
                    
                    <th>Közlemény <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    <th onclick="sortTable(4, 'date')">OTS Dátum <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    <th>Bizonylat <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    
                    <th onclick="sortTable(6, 'string')">OTS Leírás <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    
                    <th onclick="sortTable(7, 'amount')"
                        data-bs-toggle="tooltip" 
                        data-bs-placement="top" 
                        data-bs-html="true"
                        title="<b>Összeg szűrési tippek:</b><br>- kimenő<br>+ bejövő<br>-tól [szóköz] -ig">
                        OTS Összeg <span class="info-dot">ℹ</span>
                        <input type="text" class="filter-input" placeholder="Kimenő, bejövő..." onclick="event.stopPropagation();" onkeyup="filterTable()">
                    </th>
                    
                    <th onclick="sortTable(8, 'string')">OTS RID <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    
                    <th onclick="sortTable(9, 'string')">Státusz 
                        <select class="filter-input" onclick="event.stopPropagation();" onchange="filterTable()">
                            <option value="">Mind</option>
                            <option value="[Feldolgozatlan]">[Feldolgozatlan]</option>
                            <option value="[OK]">[OK]</option>
                            <option value="[HIÁNY]">[HIÁNY]</option>
                            <option value="[ELTÉRÉS]">[ELTÉRÉS]</option>
                            <option value="[IDŐ CSÚSZÁS]">[IDŐ CSÚSZÁS]</option>
                            <option value="[ÖSSZEVONT]">[ÖSSZEVONT]</option>
                        </select>
                    </th>
                    <th>Megjegyzés rovat <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    <th>Akció</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): ?>
                    <?php 
                        // Load provider keywords once before rendering rows
                        static $provider_kws = null;
                        if ($provider_kws === null) {
                            $provider_kws = [];
                            $kw_res = $conn->query("SELECT bank_keyword, ots_keyword FROM provider_keywords ORDER BY id");
                            if ($kw_res) {
                                while ($kw = $kw_res->fetch_assoc()) {
                                    $provider_kws[] = $kw;
                                }
                            }
                        }
                    ?>
                    <?php foreach($rows as $row): ?>
                    <?php 
                        // Tudástár alkalmazása menet közben a megjelenítéshez (ha a DB-ben még a régi lenne)
                        $known_accounts = [
                            '1178400922224138' => 'TET OTP (Főszámla)',
                            '117840092222413800000000' => 'TET OTP (Főszámla)',
                            '104003395049575053561009' => 'TET K&H (Főszámla)',
                            '104003395049575053561030' => 'TET Építési Kápolna Alap',
                            '104003395049575053561054' => 'TET Műtéti Támogatás',
                            '104027645049575053561009' => 'MiskolcA Gyülekezet',
                        ];
                        $clean_acc = preg_replace('/[^0-9]/', '', $row['bank_ext_acc'] ?? '');
                        if (isset($known_accounts[$clean_acc])) {
                            $existing_name = trim($row['bank_ext_name'] ?? '');
                            $row['bank_ext_name'] = $known_accounts[$clean_acc] . ($existing_name && strpos($existing_name, $known_accounts[$clean_acc]) === false ? " ($existing_name)" : "");
                        }

                        // Ha a közlemény üres, akkor vizuálisan kicseréljük a Partner (célszámla) nevére
                        if (empty(trim($row['bank_desc'] ?? ''))) {
                            $row['bank_desc'] = $row['bank_ext_name'] ?? '';
                        }
                        // Ellenőrizzük, hogy van-e 100%-os egyezés írásvédelme
                        $is_locked = (strpos($row['comment'] ?? '', '[Auto: 100% egyezés, 0 nap]') !== false);

                        // --- Szöveges egyezés keresése megjelenítéshez ---
                        $text_matches_display = '';
                        $bank_text = mb_strtolower(trim(($row['bank_desc'] ?? '') . ' ' . ($row['bank_ext_name'] ?? '')), 'UTF-8');
                        $ots_text = mb_strtolower(trim($row['ots_desc_full'] ?? ''), 'UTF-8');

                        // Show item count if multiple items matched
                        if (!empty($row['item_count']) && $row['item_count'] > 1) {
                            $text_matches_display .= '<div class="small text-secondary">Több tételes: <strong>' . (int)$row['item_count'] . ' db</strong></div>';
                        }

                        if ($bank_text !== '' && $ots_text !== '') {
                            // accent normalization map
                            $accent_map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ö'=>'o','ő'=>'o','ú'=>'u','ü'=>'u','ű'=>'u'];
                            $norm = function($s) use ($accent_map) { return strtr($s, $accent_map); };

                            // exact matching (with accents)
                            $bank_words = preg_split('/[^\p{L}0-9]+/u', $bank_text);
                            $ots_words = preg_split('/[^\p{L}0-9]+/u', $ots_text);
                            $bank_flt = [];
                            foreach ($bank_words as $w) { $w = trim($w); if (mb_strlen($w,'UTF-8') >= 4 && !ctype_digit($w)) $bank_flt[] = $w; }
                            $ots_flt = [];
                            foreach ($ots_words as $w) { $w = trim($w); if (mb_strlen($w,'UTF-8') >= 4 && !ctype_digit($w)) $ots_flt[] = $w; }
                            $common = array_values(array_unique(array_intersect($bank_flt, $ots_flt)));

                            // accent-insensitive matching as fallback
                            $common_norm = [];
                            if (empty($common)) {
                                $bank_norm = array_map($norm, $bank_flt);
                                $ots_norm = array_map($norm, $ots_flt);
                                $common_norm = array_values(array_unique(array_intersect($bank_norm, $ots_norm)));
                            }

                            if (!empty($common)) {
                                $text_matches_display .= '<div class="small text-info">Szöveg egyezés: <strong>' . htmlspecialchars(implode(', ', array_slice($common, 0, 6))) . '</strong></div>';
                            } elseif (!empty($common_norm)) {
                                $text_matches_display .= '<div class="small text-info">Szöveg egyezés (ékezet nélkül): <strong>' . htmlspecialchars(implode(', ', array_slice($common_norm, 0, 6))) . '</strong></div>';
                            }

                            // provider keyword pairs
                            $kw_hits = [];
                            foreach ($provider_kws as $pk) {
                                $bk = mb_strtolower(trim($pk['bank_keyword']), 'UTF-8');
                                $ok = mb_strtolower(trim($pk['ots_keyword']), 'UTF-8');
                                if ($bk === '' || $ok === '') continue;
                                if (mb_strpos($bank_text, $bk) !== false && mb_strpos($ots_text, $ok) !== false) {
                                    $kw_hits[] = $pk['bank_keyword'] . ' ↔ ' . $pk['ots_keyword'];
                                }
                            }
                            if (!empty($kw_hits)) {
                                $kw_hits = array_slice(array_unique($kw_hits), 0, 5);
                                $text_matches_display .= '<div class="small text-primary">Kulcsszó párok: <strong>' . htmlspecialchars(implode(', ', $kw_hits)) . '</strong></div>';
                            }
                        }
                    ?>
                    <tr id="row-<?php echo $row['id']; ?>" class="data-row" data-status="<?php echo $row['status']; ?>" data-church="<?php echo htmlspecialchars($row['church_name'] ?? ''); ?>" data-church-id="<?php echo $row['church_id']; ?>">
                        <td class="bg-light text-muted" style="font-size: 11px;" data-val="<?php echo htmlspecialchars($row['church_name'] ?? ''); ?>">
                            <strong>ID: <?php echo $row['church_id']; ?></strong> / <strong>BR: <?php echo $row['id']; ?></strong><br>
                            <?php echo htmlspecialchars($row['church_name'] ?? 'ISMERETLEN'); ?>
                        </td>
                        <td class="bg-bank text-center text-nowrap" style="font-size:11px; white-space:nowrap;" data-val="<?php echo $row['bank_date']; ?>"><?php echo $row['bank_date']; ?></td>
                        
                        <td class="bg-bank text-end fw-bold clickable-amount small text-nowrap <?php echo $row['bank_amount'] < 0 ? 'text-danger' : 'text-success'; ?>"
                            data-val="<?php echo $row['bank_amount']; ?>" onclick="mutatKombinaltReszleteket(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                            <?php echo number_format($row['bank_amount'], 0, ',', ' '); ?> Ft
                        </td>
                        
                        <td class="bg-bank text-muted truncate" style="max-width: 180px;" title="<?php echo htmlspecialchars($row['bank_desc'] ?? ''); ?>" data-val="<?php echo htmlspecialchars($row['bank_desc'] ?? ''); ?>" data-partner="<?php echo htmlspecialchars($row['bank_ext_name'] ?? ''); ?>" data-ref="<?php echo htmlspecialchars($row['bank_ext_ref'] ?? ''); ?>">
                            <small><?php echo htmlspecialchars($row['bank_desc'] ?? ''); ?></small>
                        </td>
                        <?php 
                            $tooltip_attr = "";
                            if (!empty($row['ots_date']) && $row['ots_date'] !== '-') {
                                try {
                                    $b_date = new DateTime($row['bank_date']);
                                    $o_date = new DateTime($row['ots_date']);
                                    $diff = $b_date->diff($o_date);
                                    $days = (int)$diff->format('%R%a');
                                    $diff_text = ($days == 0) ? "0 nap (pontos egyezés)" : abs($days) . " nap eltérés";
                                    $tooltip_attr = 'data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="<b>Banki dátum:</b> ' . htmlspecialchars($row['bank_date']) . '<br><b>Eltérés:</b> ' . $diff_text . '" style="cursor:help; border-bottom:1px dotted #000;"';
                                } catch (Exception $e) {}
                            }
                        ?>
                        <td class="bg-ots text-center" style="font-size:11px; white-space:nowrap;" data-val="<?php echo $row['ots_date'] ?? '-'; ?>"><span <?php echo $tooltip_attr; ?>><?php echo $row['ots_date'] ?? '-'; ?></span></td>
                        <td class="bg-ots text-center" data-val="<?php echo htmlspecialchars($row['ots_doc'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if ($row['status'] === 'UNCHECKED' || empty($row['ots_doc'])): ?>
                                <input type="text" id="manual-doc-<?php echo $row['id']; ?>" class="form-control form-control-sm text-center px-1" style="width: 70px; margin: 0 auto;" value="<?php echo htmlspecialchars($row['ots_doc'] ?? ''); ?>" placeholder="Biz.szám" title="Kézi bizonylatszám megadása">
                            <?php else: ?>
                                <?php echo htmlspecialchars($row['ots_doc']); ?>
                            <?php endif; ?>
                        </td>
                        <td class="bg-ots text-muted truncate" style="max-width: 180px;" title="<?php echo htmlspecialchars($row['ots_desc_full'] ?? ''); ?>" data-val="<?php echo htmlspecialchars($row['ots_desc_full'] ?? ''); ?>">
                            <small><?php echo htmlspecialchars($row['ots_desc_full'] ?? ''); ?></small>
                        </td>
                        <td class="bg-ots text-end small text-nowrap <?php echo !empty($row['ots_amount']) ? 'clickable-amount fw-bold ' . ($row['ots_amount'] < 0 ? 'text-danger' : 'text-success') : 'clickable-amount text-muted fw-light'; ?>" style="max-width:120px; <?php echo empty($row['ots_amount']) ? 'cursor:pointer;' : ''; ?>" data-val="<?php echo $row['ots_amount'] ?? 0; ?>" onclick="mutatKombinaltReszleteket(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                            <?php if (!empty($row['item_count']) && $row['item_count'] > 1): ?>
                                <span title="<?php echo htmlspecialchars($row['item_amounts'] . ' = ' . number_format($row['ots_amount'], 0, ',', ' ') . ' Ft'); ?>">
                                    <?php echo htmlspecialchars($row['item_amounts']); ?> = <?php echo number_format($row['ots_amount'], 0, ',', ' '); ?> Ft
                                </span>
                            <?php else: ?>
                                <?php echo $row['ots_amount'] ? number_format($row['ots_amount'], 0, ',', ' ') . ' Ft' : '-'; ?>
                            <?php endif; ?>
                        </td>
                        
                        <td class="bg-ots text-center small">
                            <?php if (!empty($row['ots_record_id'])): ?>
                                <?php
                                    $rid = (int)$row['ots_record_id'];
                                    $rid_display = $rid < 0 ? 'TC#' . abs($rid) : '#' . $rid;
                                ?>
                                <span class="badge bg-dark" title="OTS Record ID"><?php echo $rid_display; ?></span>
                            <?php elseif (!empty($row['ots_amount']) && !empty($row['ots_date'])): ?>
                                <span class="badge bg-warning text-dark" title="Párosítva (régi rekord, ID nélkül)">?</span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        
                        <td>
                            <select id="status-<?php echo $row['id']; ?>" class="form-select form-select-sm fw-bold <?php echo $row['status'] == 'UNCHECKED' ? 'text-secondary bg-light' : ''; ?>" onchange="updateRowStatusData(<?php echo $row['id']; ?>, this.value)" <?php echo $is_locked ? 'disabled' : ''; ?>>
                                <option value="UNCHECKED" class="status-unchecked" <?php if($row['status'] == 'UNCHECKED') echo 'selected'; ?>>[Feldolgozatlan]</option>
                                <option value="OK" class="text-success" <?php if($row['status'] == 'OK') echo 'selected'; ?>>[OK]</option>
                                <option value="HIANY" class="text-danger" <?php if($row['status'] == 'HIANY') echo 'selected'; ?>>[HIÁNY]</option>
                                <option value="ELTERES" class="text-warning" <?php if($row['status'] == 'ELTERES') echo 'selected'; ?>>[ELTÉRÉS]</option>
                                <option value="CSUSZAS" class="text-info" <?php if($row['status'] == 'CSUSZAS') echo 'selected'; ?>>[IDŐ CSÚSZÁS]</option>
                                <option value="OSSZEVONT" class="text-primary" <?php if($row['status'] == 'OSSZEVONT') echo 'selected'; ?>>[ÖSSZEVONT]</option>
                            </select>
                        </td>
                        <td title="<?php echo htmlspecialchars($row['comment'] ?? ''); ?>">
                            <input type="text" id="comment-<?php echo $row['id']; ?>" class="form-control form-control-sm <?php echo $is_locked ? 'bg-light' : ''; ?>" style="font-size:11px;" value="<?php echo htmlspecialchars($row['comment'] ?? ''); ?>" <?php echo $is_locked ? 'readonly' : ''; ?>>
                            <?php if (!empty($text_matches_display)) { echo $text_matches_display; } ?>
                        </td>
                        <td class="text-center text-nowrap" style="font-size:11px;">
                            <?php if ($is_locked): ?>
                                <span class="text-muted small">🔒</span>
                            <?php else: ?>
                                <button class="btn btn-success btn-sm py-0 px-1" style="font-size:11px;" onclick="saveData(<?php echo $row['id']; ?>)" title="Státusz + Megjegyzés mentése">💾</button>
                            <?php endif; ?>
                            <?php if ($row['status'] !== 'UNCHECKED'): ?>
                                <button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:11px;" onclick="unpairBank(<?php echo $row['id']; ?>)" title="Párosítás bontása">✕</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="text-center text-danger">Nincs adat!</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="status-bar-fixed">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <div>📊 <span id="counter-visible"><?php echo $total_rows; ?></span> / <span id="counter-total"><?php echo $total_db_rows; ?></span> rekord</div>

        <form method="GET" action="reconciliation.php" class="d-flex align-items-center gap-1">
            <span class="small" style="font-size:11px;">Sor/Oldal:</span>
            <input type="hidden" name="p" value="1">
            <?php if ($selected_church_name): ?>
            <input type="hidden" name="church_filter" value="<?php echo htmlspecialchars($selected_church_name); ?>">
            <?php endif; ?>
            <select name="limit" class="form-select form-select-sm" style="width:75px; font-size:11px;" onchange="showPerPageLoading(this.form)">
                <option value="50"<?php echo $limit==50?' selected':''; ?>>50</option>
                <option value="100"<?php echo $limit==100?' selected':''; ?>>100</option>
                <option value="500"<?php echo $limit==500?' selected':''; ?>>500</option>
                <option value="999999"<?php echo $limit==999999?' selected':''; ?>>Összes</option>
            </select>
        </form>

        <?php if ($total_pages > 1 && $limit < 999999): $qs = ($selected_church_name ? 'church_filter='.urlencode($selected_church_name).'&' : '').'limit='.$limit; ?>
        <nav>
            <ul class="pagination pagination-sm mb-0" style="font-size:11px;">
                <li class="page-item<?php echo $page<=1?' disabled':''; ?>">
                    <a class="page-link" href="?p=1&<?php echo $qs; ?>">«</a>
                </li>
                <li class="page-item<?php echo $page<=1?' disabled':''; ?>">
                    <a class="page-link" href="?p=<?php echo max(1,$page-1); ?>&<?php echo $qs; ?>">‹</a>
                </li>
                <?php
                $start_p = max(1, $page-2);
                $end_p = min($total_pages, $page+2);
                if ($start_p > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                for ($i = $start_p; $i <= $end_p; $i++):
                ?>
                <li class="page-item<?php echo $i==$page?' active':''; ?>">
                    <a class="page-link" href="?p=<?php echo $i; ?>&<?php echo $qs; ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor;
                if ($end_p < $total_pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; ?>
                <li class="page-item<?php echo $page>=$total_pages?' disabled':''; ?>">
                    <a class="page-link" href="?p=<?php echo min($total_pages,$page+1); ?>&<?php echo $qs; ?>">›</a>
                </li>
                <li class="page-item<?php echo $page>=$total_pages?' disabled':''; ?>">
                    <a class="page-link" href="?p=<?php echo $total_pages; ?>&<?php echo $qs; ?>">»</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>

        <div class="custom-tooltip-container">
            📈 Kész (OK): <span class="text-success fw-bold" id="stats-ok">0</span> | Hátravan: <span class="text-warning fw-bold" id="stats-unchecked">0</span> <span class="info-dot">ℹ</span>
            <div class="custom-tooltip-text">
                <div class="fw-bold mb-1 border-bottom border-secondary pb-1 text-center">Statisztika (Látható tételek)</div>
                <div class="stat-row"><span class="text-success">[OK]:</span> <span class="fw-bold" id="bubble-ok">0</span></div>
                <div class="stat-row"><span class="text-light">[Feldolgozatlan]:</span> <span class="fw-bold" id="bubble-unchecked">0</span></div>
                <div class="stat-row"><span class="text-danger">[HIÁNY]:</span> <span class="fw-bold" id="bubble-hiany">0</span></div>
                <div class="stat-row"><span class="text-warning">[ELTÉRÉS]:</span> <span class="fw-bold" id="bubble-elteres">0</span></div>
                <div class="stat-row"><span class="text-info">[IDŐ CSÚSZÁS]:</span> <span class="fw-bold" id="bubble-csuszas">0</span></div>
                <div class="stat-row"><span class="text-primary">[ÖSSZEVONT]:</span> <span class="fw-bold" id="bubble-osszevont">0</span></div>
            </div>
        </div>
    </div>
    <div class="text-muted" style="font-size: 11px;">Minden Bankos Egyeztető Modul v3.6</div>
</div>

<!-- KOMBINÁLT ÖSSZEHASONLÍTÓ MODAL -->
<div class="modal fade" id="combinedDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">
          <button class="btn btn-sm btn-outline-light me-2" onclick="prevRow(event)" title="Előző tétel">◀</button>
          🏦 Banki és 🏛 OTS Könyvelési Részletek (Összehasonlítás)
          <small id="modalRowCounter" class="ms-2 badge bg-light text-dark">1/1</small>
          <button class="btn btn-sm btn-outline-light ms-2" onclick="nextRow(event)" title="Következő tétel">▶</button>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="parallel-row">
          <!-- Bank Side -->
          <div class="parallel-col p-3 border-end">
            <h6 class="text-primary mb-2 border-bottom pb-1"><strong>Banki Adatok</strong> <small class="text-muted fw-normal" id="cb_bank_id_label"></small></h6>
            <div id="bankDefaultView">
            <table class="table table-sm table-striped table-bordered">
              <tr id="bankSummaryRow" style="background:#e9ecef;"><th colspan="2" style="padding:0.5rem 0.75rem; line-height:1.4; white-space:nowrap;">
                <span id="cb_bank_label" class="fw-bold me-2">🏦 Banki tétel</span>
                <span id="cb_bank_date_sm" class="badge bg-secondary me-2"></span>
                <span id="cb_bank_amount_sm" class="fw-bold me-2"></span>
                <small id="cb_bank_desc_sm" class="text-muted text-truncate" style="max-width:180px; display:inline-block; vertical-align:middle;"></small>
              </th></tr>
              <tr><th style="width: 35%;">Gyülekezet Neve:</th><td id="cb_church_name">-</td></tr>
              <tr><th>Dátum:</th><td id="cb_date">-</td></tr>
              <tr><th>Összeg:</th><td id="cb_amount" class="fw-bold">-</td></tr>
              <tr><th>Közlemény:</th><td id="cb_desc">-</td></tr>
              <tr class="table-info"><th>Kezdeményező neve:</th><td id="cb_init_name">-</td></tr>
              <tr class="table-info"><th>Kezdeményező számla:</th><td id="cb_init_acc">-</td></tr>
              <tr class="table-light"><th>Kedvezményezett neve:</th><td id="cb_ben_name">-</td></tr>
              <tr class="table-light"><th>Kedvezményezett számla:</th><td id="cb_ben_acc">-</td></tr>
              <tr><th>Tranzakció ID:</th><td id="cb_ext_ref">-</td></tr>
              <tr class="table-success"><th>Revizor párosította/elfogadta:</th><td id="cb_updated_by">-</td></tr>
            </table>
            </div>
            <div id="bankPairsLeftPanel" style="display:none;">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <strong class="small">📋 Párosítatlan banki tételek</strong>
                <button class="btn btn-outline-secondary btn-sm py-0" onclick="closeBankPairsLeft()" type="button">✕ Vissza</button>
              </div>
              <div class="small text-muted mb-1" id="bankPairLeftInfo"></div>
              <div id="bankPairLeftContent" style="max-height:55vh; overflow-y:auto;"></div>
            </div>
          </div>
          <!-- OTS Side -->
          <div class="parallel-col p-3 bg-light">
            <div class="d-flex justify-content-between align-items-center mb-2 border-bottom pb-1">
                <h6 class="text-secondary m-0"><strong>🧾 OTS Könyvelési Adatok</strong> <small class="text-muted fw-normal" id="cb_ots_id_label"></small></h6>
                <div class="d-flex gap-1">
                    <button id="toggleMatchModeBtn" class="btn btn-outline-secondary btn-sm" onclick="toggleMatchMode()" type="button" style="display:none;">☐ Több tételes párosítás</button>
                    <button id="aggregationSearchBtn" class="btn btn-outline-info btn-sm" onclick="aggregationSearch()" type="button" style="display:none;">🔍 Keresés szöveg alapján</button>
                </div>
            </div>
            <div id="unmatchedFilterBar" class="d-flex gap-1 mb-1 align-items-center" style="display:none;">
                <input type="text" id="unmatchedFilterText" class="form-control form-control-sm" style="width:140px;" placeholder="Szöveg szűrés..." oninput="filterUnmatched()">
                <input type="number" id="unmatchedFilterAmount" class="form-control form-control-sm" style="width:120px;" placeholder="Összeg szűrés..." oninput="filterUnmatched()">
                <select id="unmatchedSortBy" class="form-select form-select-sm" style="width:auto;" onchange="filterUnmatched()">
                    <option value="date_asc">📅 Dátum ↑</option>
                    <option value="date_desc">📅 Dátum ↓</option>
                    <option value="amount_asc">💰 Összeg ↑</option>
                    <option value="amount_desc">💰 Összeg ↓</option>
                </select>
            </div>
            <div id="c_ots_content">
                <!-- Dinamikusan generált táblázat helye -->
                <div class="alert alert-info mt-3 mb-0 text-center py-2"><small>További részletekért keresd meg a fenti bizonylatszámot az OTS rendszerben.</small></div>
            </div>
            <div id="c_ots_empty" class="alert alert-warning text-center mt-4" style="display:none;">
                <strong>[Feldolgozatlan]</strong><br>Ehhez a banki tételhez még nem lett párosítva OTS könyvelési adat!
                <div class="mt-2">
                    <button class="btn btn-outline-info btn-sm" onclick="aggregationSearch()" type="button">🔍 Keresés szöveg alapján</button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="loadUnmatched()" type="button">📋 Minden OTS tétel</button>
                </div>
            </div>
          </div>
        </div>
        <div class="comment-bar border-top bg-light p-1 px-2 d-flex justify-content-between align-items-center text-muted" style="flex-shrink:0;">
            <small><strong>Státusz:</strong> <span id="c_status" class="fw-bold">-</span> &middot; <strong>Megjegyzés:</strong> <span id="c_comment" class="fst-italic">-</span></small>
            <div class="d-flex align-items-center gap-2">
                <button id="unpairModalBtn" class="btn btn-outline-danger btn-sm py-0" onclick="unpairBank(_currentViewingData ? _currentViewingData.id : 0)" type="button" style="display:none;" title="Párosítás bontása — nem párosítottá teszi a tételt">✕ Párosítás bontása</button>
                <button class="btn btn-outline-primary btn-sm py-0" onclick="openAudit(_currentViewingData ? _currentViewingData.id : 0, 'bank')" type="button">🔍 Ellenőrzés</button>
            </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Audit modal (banki ellenőrző lista) -->
<div class="modal fade" id="auditModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <div class="w-100 d-flex align-items-center flex-wrap gap-2">
                    <h6 class="modal-title mb-0" id="auditModalTitle">📋 Ellenőrző lista</h6>
                    <div class="flex-grow-1"></div>
                    <div class="d-flex align-items-center gap-2">
                        <label class="small text-white-50 mb-0" for="auditInspectorName">Ellenőr neve:</label>
                        <input type="text" name="inspector_name" id="auditInspectorName" class="form-control form-control-sm" style="min-width:170px" value="<?php echo htmlspecialchars($_SESSION[GC_USER_FULL_NAME] ?? ''); ?>">
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
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="row">
                        <!-- 📄 Papír dokumentumok -->
                        <div class="col-md-4">
                            <div class="audit-panel paper-col">
                            <h6 class="border-bottom pb-1">📄 Papír dokumentumok</h6>
                            <div class="checklist-item" data-req="bank_expense">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="invoice_ok" value="1" id="chk_invoice_ok">
                                    <label class="form-check-label" for="chk_invoice_ok">Számla megvan</label>
                                </div>
                            </div>
                            <div class="checklist-item" data-req="bank_always">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="supporting_doc_ok" value="1" id="chk_supporting_doc_ok">
                                    <label class="form-check-label" for="chk_supporting_doc_ok">Egyéb melléklet (szerződés, stb.)</label>
                                </div>
                            </div>
                            </div>
                        </div>
                        <!-- 🖥️ OTS-ben ellenőrizni -->
                        <div class="col-md-4">
                            <div class="audit-panel ots-col">
                            <h6 class="border-bottom pb-1">🖥️ OTS-ben ellenőrizni</h6>
                            <div class="checklist-item" data-req="bank_always">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="bank_in_ots_ok" value="1" id="chk_bank_in_ots_ok">
                                    <label class="form-check-label" for="chk_bank_in_ots_ok">Banki tétel OTS-ben szerepel</label>
                                </div>
                            </div>
                            <div class="checklist-item" data-req="bank_always">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="fund_designation_ok" value="1" id="chk_fund_designation_ok">
                                    <label class="form-check-label" for="chk_fund_designation_ok">Pénzalap megjelölés helyes</label>
                                </div>
                            </div>
                            <div class="checklist-item" data-req="bank_expense">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="decision_number_ok" value="1" id="chk_decision_number_ok">
                                    <label class="form-check-label" for="chk_decision_number_ok">Határozat száma (ha releváns)</label>
                                </div>
                            </div>
                            </div>
                        </div>
                        <!-- 🏦 Bankszámlakivonaton ellenőrizni -->
                        <div class="col-md-4">
                            <div class="audit-panel stmt-col">
                            <h6 class="border-bottom pb-1">🏦 Bankszámlakivonaton ellenőrizni</h6>
                            <div class="checklist-item" data-req="bank_always">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="bank_stmt_ok" value="1" id="chk_bank_stmt_ok">
                                    <label class="form-check-label" for="chk_bank_stmt_ok">Banki kivonaton szerepel a tétel</label>
                                </div>
                            </div>
                            <div class="checklist-item" data-req="bank_always">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="amount_ok" value="1" id="chk_amount_ok">
                                    <label class="form-check-label" for="chk_amount_ok">Összeg egyezik a banki kivonattal</label>
                                </div>
                            </div>
                            <div class="checklist-item" data-req="bank_always">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="description_ok" value="1" id="chk_description_ok">
                                    <label class="form-check-label" for="chk_description_ok">Közlemény / megnevezés pontos</label>
                                </div>
                            </div>
                            </div>
                        </div>
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

<!-- AUTO MATCH MODAL -->
<div class="modal fade" id="autoMatchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title">🤖 Utólagos Automatikus Párosítás</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3">Ezzel a funkcióval a még <strong>[Feldolgozatlan]</strong> tételekre kereshetsz rá az OTS adatbázisban, hogy neked már csak a problémás tételekkel kelljen foglalkoznod.</p>
        
        <div class="form-check mb-3 p-3 bg-light border rounded">
          <input class="form-check-input" type="radio" name="matchMode" id="modeProgressive" value="progressive" checked>
          <label class="form-check-label fw-bold" for="modeProgressive">
            Progresszív mód (Ajánlott)
            <span id="last-progressive" class="ms-2 badge bg-white text-dark border fw-normal" style="display:none; font-size: 10px;">Legutóbb: -</span>
          </label>
          <div class="text-muted small mt-1">Először 100%-os (0 nap) egyezéseket keres (ezek írásvédelmet kapnak). Utána a maradékot próbálja 3, 6, 12, 35, majd 60 nap csúszásos toleranciával, a végén pedig egy <strong>intelligens szöveges keresővel (Nagy összegek, MVM, Közlemény, stb.)</strong> párosítani.</div>
        </div>
        
        <div class="form-check mb-2 p-3 bg-light border rounded">
          <input class="form-check-input" type="radio" name="matchMode" id="modeCustom" value="custom">
          <label class="form-check-label fw-bold" for="modeCustom">
            Egyedi nap tolerancia (Engedmény)
            <span id="last-custom" class="ms-2 badge bg-white text-dark border fw-normal" style="display:none; font-size: 10px;">Legutóbb: -</span>
          </label>
          <div class="d-flex align-items-center mt-2">
            <input type="number" id="customDays" class="form-control form-control-sm me-2" value="5" min="0" max="30" style="width: 70px;"> <span class="small text-muted">nap csúszás engedélyezése</span>
          </div>
        </div>
        
        <div class="form-check mb-2 p-3 bg-light border rounded">
          <input class="form-check-input" type="radio" name="matchMode" id="modeSearch" value="search">
          <label class="form-check-label fw-bold" for="modeSearch">
            🔎 Kézi nyomozás konkrét összegre az OTS-ben
            <span id="last-search" class="ms-2 badge bg-white text-dark border fw-normal" style="display:none; font-size: 10px;">Legutóbb: -</span>
          </label>
          <div class="d-flex align-items-center mt-2">
            <input type="number" id="searchAmount" class="form-control form-control-sm me-2" placeholder="pl. 4986" style="width: 120px;"> <span class="small text-muted">Ft keresése az adatbázisban</span>
          </div>
        </div>

        <div class="form-check mt-3 p-2 bg-info bg-opacity-10 border border-info rounded">
          <input class="form-check-input" type="checkbox" id="allChurchesMatch" value="1">
          <label class="form-check-label fw-bold" for="allChurchesMatch">
            🌍 Minden gyülekezetre (a szűrőt figyelmen kívül hagyja)
          </label>
          <div class="text-muted small mt-1">Az összes gyülekezet [Feldolgozatlan] tételeit feldolgozza. Figyelem: hosszabb ideig tarthat!</div>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between align-items-center">
        <div id="autoMatchLoader" class="text-success fw-bold" style="display:none; font-size:14px;">
            <span class="spinner-border spinner-border-sm me-1"></span> Keresés folyamatban...
            <span id="autoMatchTimer" class="ms-2 badge bg-secondary">0.0s</span>
        </div>
        <div>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
            <button type="button" class="btn btn-success fw-bold" onclick="runAutoMatch()" id="btnRunMatch">🚀 Futtatás</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Auto-Match Log Modal -->
<div class="modal fade" id="autoMatchLogModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-secondary text-white">
        <h5 class="modal-title">📋 Auto-match futási napló</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-2" style="max-height: 60vh; overflow-y: auto;">
        <div id="autoMatchLogContent" class="text-muted small">Betöltés...</div>
      </div>
    </div>
  </div>
</div>

<!-- Custom Patterns Modal -->
<div class="modal fade" id="customPatternsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title">🔧 Keyword párok kezelése</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Itt adhatsz meg gyülekezet-specifikus banki ↔ OTS kulcsszó párokat. Ezek +3 pontot adnak a szöveges párosításnál, ha a banki közlemény tartalmazza a <strong>Banki kulcsszót</strong> ÉS az OTS leírás tartalmazza a <strong>OTS kulcsszót</strong>.</p>
        <div class="mb-3">
          <label class="form-label fw-bold">Gyülekezet</label>
          <select id="cpChurchSelect" class="form-select" onchange="loadCustomPatterns()">
            <option value="">-- Válassz gyülekezetet --</option>
          </select>
        </div>
        <div id="cpContent" style="display:none;">
          <table class="table table-sm table-bordered">
            <thead>
              <tr>
                <th style="width:40%">Banki kulcsszó</th>
                <th style="width:40%">OTS kulcsszó</th>
                <th style="width:15%">Címke</th>
                <th style="width:5%"></th>
              </tr>
            </thead>
            <tbody id="cpTableBody"></tbody>
          </table>
          <div class="d-flex gap-2 mb-2">
            <input type="text" id="cpNewBank" class="form-control form-control-sm" placeholder="Banki kulcsszó">
            <input type="text" id="cpNewOts" class="form-control form-control-sm" placeholder="OTS kulcsszó">
            <input type="text" id="cpNewLabel" class="form-control form-control-sm" placeholder="Címke (opcionális)">
            <button class="btn btn-sm btn-success" onclick="addCustomPattern()">+ Hozzáad</button>
          </div>
        </div>
        <div id="cpEmpty" class="text-muted text-center py-3">Előbb válassz ki egy gyülekezetet.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bezár</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
var CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
var IS_ADMIN = <?= is_admin() ? 'true' : 'false' ?>;
var AUTO_BANK_ID = <?= $auto_bank_id ?>;
function checkSession(r) { if (r && r.status === 'SESSION_EXPIRED') { alert(r.message || 'A munkamenet lejárt.'); window.location.href = 'login.php'; return false; } return true; }
const _origFetch = window.fetch;
window.fetch = function(url, opts) {
    return _origFetch.apply(this, arguments).then(function(res) {
        if (res.status === 401) { alert('A munkamenet lejárt.'); window.location.href = 'login.php'; return res; }
        return res;
    });
};
var CHURCH_NAME_TO_ID = <?php 
    $name_to_id = [];
    if (isset($church_names_map)) {
        foreach ($church_names_map as $id => $name) {
            $name_to_id[$name] = $id;
        }
    }
    echo json_encode($name_to_id);
?>;

function showAdminOnlyModal() {
    document.getElementById('adminOnlyModalTrigger').click();
}

// Szabványos audit modal (banki ellenőrző lista) — a document_check-el azonos design
var auditModal = null;

function htmlspecialchars(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// A TAB billentyű függőlegesen (lefelé) navigáljon az ellenőrző listában:
// az egyes oszlopok checkboxait egymás alatt, oszloponként haladva rendeljük sorba a tabindex alapján.
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
    var others = container.querySelectorAll('textarea, input[type="text"], select, button');
    for (var j = 0; j < others.length; j++) {
        var o = others[j];
        if (o.disabled || o.type === 'hidden') continue;
        var r = o.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        if (!o.hasAttribute('tabindex')) o.setAttribute('tabindex', n++);
    }
}

// Összevont könyvelés (több banki tétel → 1 OTS) megjelenítése
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

// Szombati bizonylat-csoport megjelenítése (banki tételeknél jellemzően nem releváns, rejtve marad)
function renderSabbathGroup(containerId, data) {
    var el = document.getElementById(containerId);
    if (!el) return;
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
        return '<a href="#" class="sabbath-amount-link" title="Összeg ellenőrzése" onclick="event.preventDefault(); openAudit(' + recId + ', \'cash\');">' + txt + '</a>';
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
// (eltérő pénzalap/összeg), az egyetlen (véletlenszerű) összeg helyett a teljes csoportot mutatjuk.
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

function openAudit(id, type) {
    type = type || 'bank';
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

        var fields = ['date_filled','amount_ok','description_ok','signature_treasurer','signature_receiver','signature_authorizer','signature_auditor','signature_bookkeeper','signature_issuer','signature_payer','amount_in_words_ok','stamp_ok','invoice_ok','tithe_card_ok','tithe_source_asked','receipt_number_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok','bank_in_ots_ok','bank_stmt_ok'];
        fields.forEach(function(f) {
            var cb = document.getElementById('chk_' + f);
            if (cb) cb.checked = data.audit && data.audit[f] == 1;
        });
        document.querySelector('[name="inspector_name"]').value = data.audit ? data.audit.inspector_name : '<?php echo htmlspecialchars($_SESSION[GC_USER_FULL_NAME] ?? '', ENT_QUOTES, 'UTF-8'); ?>';
        document.querySelector('[name="notes"]').value = data.audit ? data.audit.notes : '';

        var isExpense = Number(data.bank_amount || 0) < 0;
        var isTitheAsk = type === 'bank' && Number(data.tithe_ask) === 1;
        var titleEl = document.getElementById('auditModalTitle');
        if (titleEl) titleEl.textContent = isExpense ? '📋 Kiadási banki tétel ellenőrzés' : '📋 Bevételi banki tétel ellenőrzés';
        document.querySelectorAll('#auditModal .checklist-item[data-req]').forEach(function(el) {
            var req = el.getAttribute('data-req');
            var visible = true;
            if (req === 'bank_expense') visible = isExpense;
            else if (req === 'tithe_ask') visible = isTitheAsk;
            el.style.display = visible ? '' : 'none';
            el.querySelectorAll('input').forEach(function(i) { i.disabled = !visible; });
        });

        // Ha a banki tétel párosítva van OTS-ben, a "Banki tétel OTS-ben szerepel" pipa automatikusan
        if (_currentViewingData && (_currentViewingData.ots_record_id || _currentViewingData.item_count > 0)) {
            var bankInOts = document.getElementById('chk_bank_in_ots_ok');
            if (bankInOts) bankInOts.checked = true;
        }

        auditModal.show();
    })
    .catch(function() {
        alert('Hiba az adatok betöltésekor');
    });
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
        });
    }
});

function requireAdminThen(fn) {
    if (IS_ADMIN) { fn(); } else { showAdminOnlyModal(); }
}

document.addEventListener("DOMContentLoaded", function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl);
    });
    frissitSzamlalot();

    // Auto-open modal if bank_id URL param is present
    if (AUTO_BANK_ID > 0) {
        var targetRow = document.getElementById('row-' + AUTO_BANK_ID);
        if (targetRow) {
            targetRow.style.background = '#fff3cd';
            targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var clickableCell = targetRow.querySelector('.clickable-amount');
            if (clickableCell) {
                setTimeout(function() { clickableCell.click(); }, 100);
            }
        }
    }
});

function filterTable() {
    var ol = document.getElementById('perPageLoadingOverlay');
    var olH5 = ol.querySelector('h5');
    var olP = ol.querySelector('p');
    var origH5 = olH5.textContent;
    var origP = olP.textContent;
    olH5.textContent = 'Szűrés folyamatban...';
    olP.textContent = 'Kérem várjon, amíg a szűrés elkészül.';
    ol.classList.add('show');

    setTimeout(function() {
    const table = document.getElementById("sortableTable");
    const inputs = table.querySelectorAll(".filter-input");
    const rows = table.querySelectorAll("tbody .data-row");
    
    const selectedChurch = document.getElementById("churchSelect").value.trim().toLowerCase();

    rows.forEach(row => {
        let shouldShow = true;

        if (selectedChurch !== "") {
            const rowChurch = (row.getAttribute("data-church") || "").trim().toLowerCase();
            const rowId = (row.getAttribute("data-church-id") || "").trim().toLowerCase();
            // Ha a választott gyülekezet neve vagy ID-ja nem tartalmazza a keresett szöveget
            if (!rowChurch.includes(selectedChurch) && !rowId.includes(selectedChurch)) {
                shouldShow = false;
            }
        }

        if (shouldShow) {
            // A colIndex most már elcsúszott az új ADMIN oszlop miatt, figyelni kell az eltolásra!
            inputs.forEach((input, inputIdx) => {
                const query = input.value.trim();
                if (query === "") return;

                let colIndex = inputIdx; // Mivel minden oszlopnak van inputja, az indexek stimmelnek
                let cellValue = "";
                if (colIndex === 9) { // Státusz oszlop (select)
                    const select = row.children[colIndex].querySelector("select");
                    cellValue = select.options[select.selectedIndex].text;
                } else if (colIndex === 10) { // Megjegyzés oszlop (input)
                    cellValue = row.children[colIndex].querySelector("input").value;
                } else {
                    cellValue = row.children[colIndex].textContent || ""; // textContent sokkal gyorsabb, mint az innerText!
                }

                if (colIndex === 2 || colIndex === 7) { // Összeg oszlopok
                    let numValue = parseFloat(cellValue.replace(/[^0-9.-]/g, ''));
                    if (isNaN(numValue)) numValue = 0;

                    if (query === "-") {
                        if (numValue >= 0) shouldShow = false;
                    } 
                    else if (query === "+") {
                        if (numValue <= 0) shouldShow = false;
                    } 
                    else {
                        const parts = query.split(/\s+/);

                        if (parts.length === 2) {
                            let val1 = parseFloat(parts[0]);
                            let val2 = parseFloat(parts[1]);

                            if (!isNaN(val1) && !isNaN(val2)) {
                                let min = Math.min(val1, val2);
                                let max = Math.max(val1, val2);

                                if (numValue < min || numValue > max) shouldShow = false;
                            } else {
                                shouldShow = false;
                            }
                        } else {
                            let cleanQuery = query.replace(/[^0-9.-]/g, '');
                            if (cleanQuery !== "") {
                                let queryNum = parseFloat(cleanQuery);
                                if (!isNaN(queryNum) && numValue !== queryNum) {
                                    shouldShow = false;
                                }
                            }
                        }
                    }
                } 
                else {
                    let searchableText = cellValue.toLowerCase();
                    
                    const partnerName = row.children[colIndex].getAttribute("data-partner");
                    if (partnerName) searchableText += " " + partnerName.toLowerCase();
                    
                    const refData = row.children[colIndex].getAttribute("data-ref");
                    if (refData) searchableText += " " + refData.toLowerCase();
                    
                    // ÉS logika: minden szónak benne kell lennie (nem kell egymás után állnia)
                    const words = query.toLowerCase().split(/\s+/).filter(w => w.length > 0);
                    for (let w = 0; w < words.length; w++) {
                        if (!searchableText.includes(words[w])) { shouldShow = false; break; }
                    }
                }
            });
        }

        row.style.display = shouldShow ? "" : "none";
    });

    frissitSzamlalot();

    // show "nincs adat" message if every row is hidden
    var anyVisible = Array.from(document.querySelectorAll('tbody .data-row')).some(function(r) { return r.style.display !== 'none'; });
    var msgEl = document.getElementById('filterEmptyMsg');
    if (!anyVisible && document.querySelectorAll('tbody .data-row').length > 0) {
        if (!msgEl) {
            msgEl = document.createElement('div');
            msgEl.id = 'filterEmptyMsg';
            msgEl.className = 'alert alert-warning text-center my-2';
            msgEl.textContent = 'A beállított szűrő alapján nincs megjeleníthető adat.';
            document.querySelector('.table-responsive-scroll').prepend(msgEl);
        }
    } else if (msgEl) {
        msgEl.remove();
    }

    document.getElementById('perPageLoadingOverlay').classList.remove('show');
    olH5.textContent = origH5;
    olP.textContent = origP;
    }, 30);
}

function updateRowStatusData(rowId, newStatus) {
    document.getElementById('row-' + rowId).setAttribute('data-status', newStatus);
    filterTable();
}

function frissitSzamlalot() {
    const rows = document.querySelectorAll('.data-row');
    const totalRows = rows.length;
    let visibleRows = 0;
    let stats = { 'UNCHECKED': 0, 'OK': 0, 'HIANY': 0, 'ELTERES': 0, 'CSUSZAS': 0, 'OSSZEVONT': 0 };

    rows.forEach(row => {
        if (row.style.display !== 'none') {
            visibleRows++;
            let s = row.getAttribute('data-status');
            if (stats[s] !== undefined) stats[s]++;
        }
    });

    document.getElementById('counter-visible').innerText = visibleRows;
    document.getElementById('counter-total').innerText = totalRows;

    document.getElementById('stats-ok').innerText = stats['OK'];
    document.getElementById('stats-unchecked').innerText = stats['UNCHECKED'];
    
    document.getElementById('bubble-ok').innerText = stats['OK'];
    document.getElementById('bubble-unchecked').innerText = stats['UNCHECKED'];
    document.getElementById('bubble-hiany').innerText = stats['HIANY'];
    document.getElementById('bubble-elteres').innerText = stats['ELTERES'];
    document.getElementById('bubble-csuszas').innerText = stats['CSUSZAS'];
    document.getElementById('bubble-osszevont').innerText = stats['OSSZEVONT'];
}

let currentSortCol = -1; let sortAscending = true;
function sortTable(colIndex, type) {
    const table = document.getElementById("sortableTable"); const tbody = table.querySelector("tbody"); const rows = Array.from(tbody.querySelectorAll(".data-row"));
    const headers = table.querySelectorAll(".sub-header th"); headers.forEach(h => h.classList.remove("sort-asc", "sort-desc"));
    if (currentSortCol === colIndex) { sortAscending = !sortAscending; } else { sortAscending = true; currentSortCol = colIndex; }
    headers[colIndex].classList.add(sortAscending ? "sort-asc" : "sort-desc");
    rows.sort((a, b) => {
        let valA = a.children[colIndex].getAttribute("data-val") || a.children[colIndex].innerText || ""; 
        let valB = b.children[colIndex].getAttribute("data-val") || b.children[colIndex].innerText || "";
        if (type === 'amount') { return sortAscending ? parseFloat(valA) - parseFloat(valB) : parseFloat(valB) - parseFloat(valA); }
        else if (type === 'date') { return sortAscending ? new Date(valA) - new Date(valB) : new Date(valB) - new Date(valA); }
        else { return sortAscending ? valA.localeCompare(valB) : valB.localeCompare(valA); }
    });
    rows.forEach(row => tbody.appendChild(row));
    frissitSzamlalot();
}

function saveData(rowId) {
    var statusValue = document.getElementById('status-' + rowId).value;
    var commentValue = document.getElementById('comment-' + rowId).value;
    var docInput = document.getElementById('manual-doc-' + rowId);
    var docValue = docInput ? docInput.value : '';
    
    var data = new FormData(); data.append('action', 'save'); data.append('id', rowId); data.append('status', statusValue); data.append('comment', commentValue); data.append('ots_doc', docValue); data.append('csrf_token', CSRF_TOKEN);
    fetch('reconciliation.php', { method: 'POST', body: data }).then(response => response.text()).then(text => { 
        if(text.trim() === "OK") { 
            if (statusValue === 'UNCHECKED' || docValue !== '') { window.location.reload(); } else { filterTable(); }
        } 
    });
}

// Ugrás a másik banki tételhez a táblában (🔒 badge kattintás)
function ugrjBankra(ids) {
    if (!ids) return;
    var firstId = ids.split(',')[0].trim();
    var row = document.getElementById('row-' + firstId);
    if (!row) {
        alert('BR#' + firstId + ' nem található a táblázatban (lehet, hogy más gyülekezetnél van).');
        return;
    }
    // Modal bezárása
    var modal = bootstrap.Modal.getInstance(document.getElementById('combinedDetailsModal'));
    if (modal) modal.hide();
    // Scroll + highlight
    setTimeout(function() {
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.style.outline = '3px solid #dc3545';
        row.style.outlineOffset = '-2px';
        setTimeout(function() { row.style.outline = ''; row.style.outlineOffset = ''; }, 3000);
    }, 300);
}

// Párosítás bontása: a banki tételt nem párosítottá ([Feldolgozatlan]) teszi,
// a hozzárendelt OTS tételek felszabadulnak (items törölve, ots adatok törölve).
function unpairBank(rowId) {
    rowId = parseInt(rowId) || 0;
    if (!rowId) { alert('Nincs kiválasztott tétel'); return; }
    if (!confirm('Biztosan bontod a párosítást?\nA banki tétel nem párosítottá ([Feldolgozatlan]) válik, és a hozzárendelt OTS tételek felszabadulnak.')) return;
    var data = new FormData();
    data.append('action', 'unpair_bank');
    data.append('id', rowId);
    data.append('csrf_token', CSRF_TOKEN);
    fetch('reconciliation.php', { method: 'POST', body: data })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.status === 'OK') { window.location.reload(); }
        else { alert('Hiba: ' + (res.message || 'ismeretlen hiba')); }
    })
    .catch(function() { alert('Hiba a szerver elérésében'); });
}

function frissitModalSzamlalot() {
    var allRows = document.querySelectorAll('#sortableTable tbody .data-row');
    var visible = [];
    allRows.forEach(function(r) {
        if (r.style.display !== 'none') visible.push(r);
    });
    var idx = -1;
    visible.forEach(function(r, i) {
        if (r.id === 'row-' + _currentViewingRowId) idx = i;
    });
    document.getElementById('modalRowCounter').textContent = (idx + 1) + '/' + visible.length;
}

function prevRow(e) {
    if (e) e.stopPropagation();
    if (!_currentViewingRowId) return;
    var currentEl = document.getElementById('row-' + _currentViewingRowId);
    if (!currentEl) return;
    var prev = currentEl.previousElementSibling;
    while (prev && (prev.style.display === 'none' || !prev.classList.contains('data-row'))) {
        prev = prev.previousElementSibling;
    }
    if (prev) {
        var bankCell = prev.querySelector('td.clickable-amount');
        if (bankCell && bankCell.onclick) {
            bankCell.onclick.call(bankCell);
        }
    }
}

function nextRow(e) {
    if (e) e.stopPropagation();
    if (!_currentViewingRowId) return;
    var currentEl = document.getElementById('row-' + _currentViewingRowId);
    if (!currentEl) return;
    var next = currentEl.nextElementSibling;
    while (next && (next.style.display === 'none' || !next.classList.contains('data-row'))) {
        next = next.nextElementSibling;
    }
    if (next) {
        var bankCell = next.querySelector('td.clickable-amount');
        if (bankCell && bankCell.onclick) {
            bankCell.onclick.call(bankCell);
        }
    }
}

var _currentViewingRowId = null;
var _currentViewingData = null;

function mutatKombinaltReszleteket(adatok) {
    try {
    window._unmatchedTransactionsOriginal = null;
    _currentViewingRowId = adatok.id || null;
    _currentViewingData = adatok;
    frissitModalSzamlalot();
    // BR ID megjelenítése
    var brId = adatok.id || 0;
    document.getElementById('cb_bank_id_label').textContent = brId > 0 ? '(BR: ' + brId + ')' : '';
    document.getElementById('cb_ots_id_label').textContent = brId > 0 ? '(BR: ' + brId + ')' : '';
    // Bank adatok
    document.getElementById('cb_church_name').textContent = adatok.church_name ? adatok.church_name : '-';
    document.getElementById('cb_date').textContent = adatok.bank_date;
    document.getElementById('unmatchedFilterText').value = '';
    document.getElementById('unmatchedFilterAmount').value = '';
    let bankAmtEl = document.getElementById('cb_amount');
    bankAmtEl.textContent = Number(adatok.bank_amount).toLocaleString('hu-HU') + ' Ft';
    bankAmtEl.className = adatok.bank_amount < 0 ? 'fw-bold text-danger' : 'fw-bold text-success';
    
    document.getElementById('cb_desc').textContent = adatok.bank_desc ? adatok.bank_desc : '-';
    document.getElementById('cb_init_name').textContent = adatok.bank_init_name ? adatok.bank_init_name : '-';
    document.getElementById('cb_init_acc').textContent = adatok.bank_init_acc ? adatok.bank_init_acc : '-';
    document.getElementById('cb_ben_name').textContent = adatok.bank_ben_name ? adatok.bank_ben_name : '-';
    document.getElementById('cb_ben_acc').textContent = adatok.bank_ben_acc ? adatok.bank_ben_acc : '-';
    document.getElementById('cb_ext_ref').textContent = adatok.bank_ext_ref ? adatok.bank_ext_ref : '-';
    document.getElementById('cb_updated_by').textContent = adatok.updated_by ? adatok.updated_by : '-';

    document.getElementById('cb_bank_date_sm').textContent = adatok.bank_date || '';
    document.getElementById('cb_bank_amount_sm').textContent = Number(adatok.bank_amount).toLocaleString('hu-HU') + ' Ft';
    document.getElementById('cb_bank_amount_sm').className = adatok.bank_amount < 0 ? 'fw-bold ms-2 text-danger' : 'fw-bold ms-2 text-success';
    document.getElementById('cb_bank_desc_sm').textContent = '';
    if (adatok.bank_desc) {
        const shortDesc = adatok.bank_desc.length > 50 ? adatok.bank_desc.substring(0, 50) + '…' : adatok.bank_desc;
        document.getElementById('cb_bank_desc_sm').textContent = shortDesc;
    }

    document.getElementById('c_comment').textContent = adatok.comment ? adatok.comment : '-';
    // Státusz megjelenítése
    const statusEl = document.getElementById('c_status');
    if (adatok.status) {
        statusEl.textContent = adatok.status;
        statusEl.className = 'fw-bold';
        if (adatok.status === 'OK') statusEl.className += ' text-success';
        else if (adatok.status === 'CSUSZAS') statusEl.className += ' text-warning';
        else statusEl.className += ' text-muted';
    } else {
        statusEl.textContent = '-';
        statusEl.className = 'fw-bold text-muted';
    }

    // Párosítás bontása gomb csak párosított tételeknél
    const unpairBtn = document.getElementById('unpairModalBtn');
    if (unpairBtn) unpairBtn.style.display = (adatok.status && adatok.status !== 'UNCHECKED') ? '' : 'none';

    // OTS adatok lekérése AJAX-szal
    const otsContainer = document.getElementById('c_ots_content');
    document.getElementById('c_ots_empty').style.display = 'none';
    document.getElementById('toggleMatchModeBtn').style.display = 'none';
    document.getElementById('aggregationSearchBtn').style.display = 'none';

    otsContainer.style.display = 'block';
    otsContainer.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>OTS adatok betöltése...</div>';
    new bootstrap.Modal(document.getElementById('combinedDetailsModal')).show();

    const data = new FormData();
    data.append('action', 'get_ots_details');
    data.append('id', adatok.id || 0);
    data.append('church_id', adatok.church_id || 0);
    data.append('ots_doc', adatok.ots_doc || '');
    data.append('church_name', adatok.church_name || '');
    data.append('bank_date', adatok.bank_date || '');
    data.append('bank_amount', adatok.bank_amount || 0);
    data.append('bank_desc', adatok.bank_desc || '');
    data.append('bank_ext_name', adatok.bank_ext_name || '');
    data.append('csrf_token', CSRF_TOKEN);

    fetch('reconciliation.php', { method: 'POST', body: data })
    .then(res => res.json())
    .then(result => {
        if (result.status === 'ERROR') {
            otsContainer.innerHTML = '<div class="alert alert-warning">' +
                '<strong>Hiba:</strong> ' + escapeHtml(result.message || 'Ismeretlen hiba') + '<br>' +
                '<button class="btn btn-outline-primary btn-sm mt-2" onclick="mutatKombinaltReszleteket(' + JSON.stringify(adatok).replace(/"/g, '&quot;') + ')">🔄 Újra próbálom</button>' +
                '</div>';
            return;
        }
        if (result.status !== 'OK' || !result.data || result.data.length === 0) {
            otsContainer.style.display = 'none';
            document.getElementById('c_ots_empty').style.display = 'block';
            return;
        }
        if (!result.from_existing && !result.unmatched_search && result.data.length > 0) {
            const allUsed = result.data.every(tx => tx._used === true || tx._used === 1 || (tx._used_count || 0) > 0);
            if (allUsed) {
                loadUnmatched();
                return;
            }
        }
        renderOtsResults(result, adatok);
    })
    .catch(err => {
        otsContainer.innerHTML = '<div class="alert alert-warning">' +
            '<strong>Hiba az OTS adatok betöltésekor.</strong><br>' +
            '<small class="text-muted">Lehet, hogy megszakadt a kapcsolat az OTS rendszerrel.</small><br>' +
            '<button class="btn btn-outline-primary btn-sm mt-2" onclick="mutatKombinaltReszleteket(' + JSON.stringify(adatok).replace(/"/g, '&quot;') + ')">🔄 Újra próbálom</button>' +
            '</div>';
    });
    } catch (e) { console.error("Hiba a részletek megjelenítésekor:", e); }
}

function frissitMultiOsszegzo() {
    const checked = document.querySelectorAll('#otsAccordion .checkbox-input:checked');
    const bankAmtRaw = document.getElementById('cb_amount').textContent;
    const bankAmt = parseFloat(bankAmtRaw.replace(/\s/g, '').replace('Ft', '')) || 0;
    let sum = 0;
    checked.forEach(cb => sum += parseFloat(cb.getAttribute('data-amount')) || 0);
    document.getElementById('multiCount').textContent = checked.length;
    document.getElementById('multiSumAmount').textContent = sum.toLocaleString('hu-HU') + ' Ft';
    const diff = Math.abs(sum - bankAmt);
    const diffEl = document.getElementById('multiSumDiff');
    if (diff < 0.01 && checked.length > 0) {
        diffEl.innerHTML = '<span class="badge bg-success fs-6">✓ Egyezik</span>';
    } else if (checked.length > 0) {
        diffEl.innerHTML = `<span class="badge bg-danger fs-6">✗ Eltérés: ${diff.toLocaleString('hu-HU')} Ft</span>`;
    } else {
        diffEl.innerHTML = '';
    }
}

let isMultiMode = false;
function toggleMatchMode() {
    isMultiMode = !isMultiMode;
    document.querySelectorAll('#otsAccordion .radio-input').forEach(r => r.style.display = isMultiMode ? 'none' : '');
    document.querySelectorAll('#otsAccordion .checkbox-input').forEach(c => {
        c.style.display = isMultiMode ? '' : 'none';
        c.checked = false;
    });
    document.getElementById('multiSumBar').style.display = isMultiMode ? 'block' : 'none';
    document.getElementById('toggleMatchModeBtn').innerHTML = isMultiMode ? '☑ Egyedi párosítás' : '☐ Több tételes párosítás';
    frissitMultiOsszegzo();
}

function loadUnmatched() {
    var adatok = _currentViewingData;
    if (!adatok) return;
    var otsContainer = document.getElementById('c_ots_content');
    document.getElementById('c_ots_empty').style.display = 'none';
    otsContainer.style.display = 'block';
    otsContainer.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Párosítatlan OTS tételek betöltése...</div>';
    var data = new FormData();
    data.append('action', 'get_ots_details');
    data.append('church_id', adatok.church_id || 0);
    data.append('ots_doc', adatok.ots_doc || '');
    data.append('church_name', adatok.church_name || '');
    data.append('bank_date', adatok.bank_date || '');
    data.append('bank_amount', adatok.bank_amount || 0);
    data.append('bank_desc', adatok.bank_desc || '');
    data.append('bank_ext_name', adatok.bank_ext_name || '');
    data.append('csrf_token', CSRF_TOKEN);
    data.append('unmatched_search', '1');
    fetch('reconciliation.php', { method: 'POST', body: data })
    .then(function(res) { return res.json(); })
    .then(function(result) {
        if (result.status !== 'OK' || !result.data || result.data.length === 0) {
            otsContainer.style.display = 'none';
            document.getElementById('c_ots_empty').style.display = 'block';
            return;
        }
        renderOtsResults(result, adatok);
    })
    .catch(function() {
        otsContainer.innerHTML = '<div class="alert alert-danger text-center">Hiba történt.</div>';
    });
}

function renderOtsResults(result, adatok, keywords) {
    const otsContainer = document.getElementById('c_ots_content');
    const transactions = result.data;
    const bankDate = adatok.bank_date;
    const bankAmt = Number(adatok.bank_amount);

    document.getElementById('toggleMatchModeBtn').style.display = result.from_existing ? 'none' : '';
    document.getElementById('aggregationSearchBtn').style.display = (!result.from_existing && result.unmatched_search) ? '' : 'none';

    let html = '';
    if (result.unmatched_search) {
        document.getElementById('unmatchedFilterBar').style.display = 'flex';
        html += '<div class="alert alert-info text-center py-1 small mb-1">🔍 OTS tételek a banki dátum körüli ±70 napban — a már párosítottak 🔒 jelzéssel látszanak, azok nem választhatók!</div>';
    } else {
        document.getElementById('unmatchedFilterBar').style.display = 'none';
    }
    if (result.from_existing) {
        html += '<div class="alert alert-success text-center py-1 small mb-1">✅ Már párosított OTS tételek — a meglévő párosítás alább látható</div>';
    }
    html += '<div class="accordion" id="otsAccordion">';

    transactions.forEach(function(tx, idx) {
        const txId = 'tx-' + idx;
        const isFirst = idx === 0;
        const otsDate = tx.DATETIME ? tx.DATETIME.substring(0, 10) : '-';
        const adjAmount = Number(tx.adjusted_amount || tx.AMOUNT || 0);
        const otsAmount = adjAmount.toLocaleString('hu-HU') + ' Ft';
        const otsDesc = tx.ots_desc_full || '-';
        const recordId = tx.RECORD_ID || '';
        const recordIdAttr = escapeAttr(recordId);
        const docAttr = escapeAttr(tx.CASH_DOCUMENT_NUMBER || '');
        const dateAttr = escapeAttr(tx.DATETIME || '');

        const isExactMatch = otsDate === bankDate && Math.abs(adjAmount - bankAmt) < 0.01;
        const isAmountMatch = Math.abs(adjAmount - bankAmt) < 0.01;

        // Meglévő párosításnál az első tételt se bontsuk ki (több tételes tizedcédula)
        const collapsed = result.from_existing ? true : !isFirst;
        const isUsed = !result.from_existing && (tx._used === true || tx._used === 1 || (tx._used_count || 0) > 0);

        html += '<div class="accordion-item ' + (isExactMatch ? 'border-success' : isAmountMatch ? 'border-warning' : '') + '">' +
            '<h2 class="accordion-header">' +
                    '<button class="accordion-button ' + (collapsed ? 'collapsed' : '') + '" type="button" data-bs-toggle="collapse" data-bs-target="#' + txId + '" aria-expanded="' + (!collapsed) + '">' +
                    (result.from_existing ?
                    '<span class="badge bg-success me-1 small" style="font-size:10px; padding:2px 4px;">✅ Párosított</span>' :
                    (isUsed ?
                    '<span class="badge bg-danger me-1 small" style="font-size:10px; padding:2px 4px; cursor:pointer;" title="Banki tételek: #' + escapeAttr(tx._used_bank_ids || '') + '" onclick="event.stopPropagation(); ugrjBankra(\'' + escapeAttr(tx._used_bank_ids || '') + '\')">🔒 ' + (tx._used_bank_ids || '?') + '</span>' :
                    '<input type="radio" name="otsSelect" class="form-check-input me-2 radio-input" value="' + idx + '" data-doc="' + docAttr + '" data-record-id="' + recordIdAttr + '" data-date="' + dateAttr + '" data-amount="' + adjAmount + '" ' + (isExactMatch || isFirst ? 'checked' : '') + ' onclick="event.stopPropagation();">')) +
                    ((result.from_existing || isUsed) ? '' :
                    '<input type="checkbox" class="form-check-input me-2 checkbox-input" data-doc="' + docAttr + '" data-record-id="' + recordIdAttr + '" data-date="' + dateAttr + '" data-amount="' + adjAmount + '" style="display:none;" onchange="event.stopPropagation(); frissitMultiOsszegzo();">') +
                    '<span class="fw-bold me-2">#' + (idx + 1) + '</span>' +
                    (isExactMatch ? '<span class="badge bg-success me-1 small" style="font-size:10px; padding:2px 4px;">Egyezés</span>' : isAmountMatch ? '<span class="badge bg-warning text-dark me-1 small" style="font-size:10px; padding:2px 4px;">Összeg egyezik</span>' : '') +
                    (tx.ots_type_name === 'TransfToConf' ? '<span class="badge bg-info text-dark me-1 small" style="font-size:10px; padding:2px 4px;">TC</span>' : '') +
                    '<span class="badge bg-secondary me-1 small" style="font-size:10px; padding:2px 4px;">' + otsDate + '</span>' +
                    '<span class="' + (adjAmount < 0 ? 'text-danger' : 'text-success') + ' fw-bold me-2 small">' + otsAmount + '</span>' +
                    '<small class="text-muted text-truncate" style="max-width: 200px;">' + escapeHtml(otsDesc) + '</small>' +
                '</button>' +
            '</h2>' +
            '<div id="' + txId + '" class="accordion-collapse collapse ' + (collapsed ? '' : 'show') + '" data-bs-parent="#otsAccordion">' +
                '<div class="accordion-body p-0">' +
                    '<table class="table table-sm table-striped table-bordered m-0">';

        var columnOrder = ['DATETIME', 'adjusted_amount', 'ots_desc_full',
            'CASH_DOCUMENT_NUMBER', 'DECISION_NUMBER', 'ots_type_name',
            'MODIFIED', 'VIA_BANK', 'PERSON_ID',
            'NAME_ID', 'NAME2_ID', 'RECORD_ID',
            'IBAN', 'ACCOUNT_NUMBER'];

        var huLabels = {
            'DATETIME': 'Dátum / Időpont',
            'adjusted_amount': 'Összeg',
            'ots_desc_full': 'Partner / Megjegyzés',
            'CASH_DOCUMENT_NUMBER': 'Bizonylatszám',
            'DECISION_NUMBER': 'Határozati szám',
            'ots_type_name': 'Típus',
            'MODIFIED': 'Módosítás ideje',
            'VIA_BANK': 'VIA Bank kód',
            'PERSON_ID': 'Személy ID',
            'NAME_ID': 'Tranzakció név ID',
            'NAME2_ID': 'Tranzakció név2 ID',
            'IBAN': 'IBAN',
            'ACCOUNT_NUMBER': 'Számlaszám'
        };

        columnOrder.forEach(function(key) {
            if (key in tx && tx[key] !== null && tx[key] !== undefined) {
                var val = tx[key];
                if (val === '' || val === null || val === undefined) val = '-';
                var formattedVal = val;
                var style = '';
                if (key === 'adjusted_amount') {
                    formattedVal = Number(val).toLocaleString('hu-HU') + ' Ft';
                    style = val < 0 ? 'class="fw-bold text-danger"' : 'class="fw-bold text-success"';
                } else if (key === 'DATETIME' || key === 'MODIFIED') {
                    formattedVal = val.length >= 16 ? val.substring(0, 16) : val;
                }
                var label = huLabels[key] || key;
                html += '<tr><th style="width: 35%;">' + escapeHtml(label) + ':</th><td ' + style + '>' + escapeHtml(formattedVal) + '</td></tr>';
            }
        });

        if (tx.ots_editor_name || tx.EDITED_BY) {
            var editorName = tx.ots_editor_name || '-';
            var editorId = tx.EDITED_BY ? ' <span class="text-muted small">(' + escapeHtml(tx.EDITED_BY) + ')</span>' : '';
            html += '<tr><th style="width: 35%;">Rögzítette:</th><td>' + escapeHtml(editorName) + editorId + '</td></tr>';
        }

        if (tx.FUND_ID) {
            var fundInfo = tx.FUND_ID;
            if (tx.fund_name) fundInfo += ' (' + tx.fund_name + ')';
            html += '<tr><th>Alap:</th><td>' + escapeHtml(fundInfo) + '</td></tr>';
        }

        var hiddenKeys = ['ots_editor_name', 'EDITED_BY', 'FUND_ID', 'fund_name', 'AMOUNT', 'CHURCH_ID', 'TYPE'];
        Object.keys(tx).forEach(function(key) {
            if (!columnOrder.includes(key) && !hiddenKeys.includes(key) && !key.startsWith('ots_') && key.charAt(0) !== '_') {
                var val = tx[key];
                if (val === null || val === undefined || val === '') val = '-';
                html += '<tr><th>' + escapeHtml(key) + ':</th><td>' + escapeHtml(val) + '</td></tr>';
            }
        });

        html += '</table>' +
            '<div class="text-center py-1 border-top">' +
                '<button class="btn btn-outline-info btn-sm" onclick="event.stopPropagation(); findBankPairs(' + (parseInt(recordId, 10) || 0) + ', ' + adjAmount + ', \'' + escapeJsString(otsDate) + '\', ' + (parseInt(adatok.church_id, 10) || 0) + ')" type="button">🔍 Banki párok keresése</button>' +
            '</div></div></div></div>';
    });

    html += '</div>';

    // Összegzés sor meglévő párosításoknál (több tételes tizedcédula)
    if (result.from_existing && transactions.length > 1) {
        var sum = 0;
        transactions.forEach(function(tx) { sum += Number(tx.adjusted_amount || tx.AMOUNT || 0); });
        html += '<div class="text-center fw-bold py-1 border-top bg-light">' +
            'Összesen: <span class="' + (sum < 0 ? 'text-danger' : 'text-success') + '">' + sum.toLocaleString('hu-HU') + ' Ft</span>' +
        '</div>';
    }

    html += '<div id="multiSumBar" class="text-center fw-bold py-1" style="display:none;">' +
        'Kiválasztva: <span id="multiCount">0</span> tétel, ' +
        'összesen: <span id="multiSumAmount">0 Ft</span>' +
        '<span id="multiSumDiff" class="ms-1"></span>' +
    '</div>';
    if (!result.from_existing) {
        html += '<div class="text-center pt-2 pb-1 border-top bg-light" style="position:sticky; bottom:0;">' +
            '<button class="btn btn-primary btn-sm fw-bold me-2" onclick="saveOtsMatch(' + adatok.id + ', ' + bankAmt + ')">' +
                '✓ Kiválasztott párosítása' +
            '</button>' +
            '<div id="otsSaveMsg" class="mt-1"></div>' +
        '</div>';
    }
    otsContainer.innerHTML = html;

    if (result.unmatched_search) {
        window._unmatchedTransactions = transactions.slice();
        window._unmatchedResult = result;
        window._unmatchedAdatok = adatok;
        if (!window._unmatchedTransactionsOriginal) {
            window._unmatchedTransactionsOriginal = transactions.slice();
        }
    }

    document.querySelectorAll('#otsAccordion .radio-input').forEach(function(r) {
        r.addEventListener('click', function(e) {
            document.querySelectorAll('#otsAccordion .radio-input').forEach(function(x) { x.checked = false; });
            this.checked = true;
        });
    });
}

function filterUnmatched() {
    if (!window._unmatchedTransactions || !window._unmatchedAdatok) return;
    var filterText = document.getElementById('unmatchedFilterText');
    var filterVal = document.getElementById('unmatchedFilterAmount');
    var sortVal = document.getElementById('unmatchedSortBy');
    if (!filterText || !filterVal || !sortVal) return;
    var textQuery = filterText.value.trim().toLowerCase();
    var targetAmount = parseFloat(filterVal.value);
    var sortMode = sortVal.value;

    var data = (window._unmatchedTransactionsOriginal || window._unmatchedTransactions).slice();

    if (textQuery.length > 0) {
        data = data.filter(function(tx) {
            var desc = (tx.ots_desc_full || '').toLowerCase();
            var fund = (tx.fund_name || '').toLowerCase();
            var type = (tx.ots_type_name || '').toLowerCase();
            var doc = (tx.CASH_DOCUMENT_NUMBER || '').toLowerCase();
            return desc.indexOf(textQuery) !== -1 ||
                   fund.indexOf(textQuery) !== -1 ||
                   type.indexOf(textQuery) !== -1 ||
                   doc.indexOf(textQuery) !== -1;
        });
    }

    if (!isNaN(targetAmount)) {
        data = data.filter(function(tx) {
            return Math.abs(Number(tx.adjusted_amount || tx.AMOUNT || 0) - targetAmount) < 0.01;
        });
    }

    data.sort(function(a, b) {
        var aAmt = Number(a.adjusted_amount || a.AMOUNT || 0);
        var bAmt = Number(b.adjusted_amount || b.AMOUNT || 0);
        var aDate = a.DATETIME || '';
        var bDate = b.DATETIME || '';
        switch (sortMode) {
            case 'amount_asc': return aAmt - bAmt;
            case 'amount_desc': return bAmt - aAmt;
            case 'date_asc': return aDate < bDate ? -1 : aDate > bDate ? 1 : 0;
            case 'date_desc': return bDate < aDate ? -1 : bDate > aDate ? 1 : 0;
            default: return 0;
        }
    });

    var newResult = {
        data: data,
        unmatched_search: true,
        from_existing: false
    };
    renderOtsResults(newResult, window._unmatchedAdatok);
}

var _aggCurrentKeywords = [];
var _aggBankWords = [];

function _aggRenderChips(keywords) {
    var h = '<div class="d-flex flex-wrap gap-1 mb-2" id="aggChips">';
    keywords.forEach(function(kw, i) {
        h += '<span class="badge bg-info text-dark d-inline-flex align-items-center gap-1" style="font-size:0.85rem;">' +
            _aggEs(kw) +
            ' <a href="#" onclick="event.preventDefault(); _aggRemoveKeyword(' + i + '); return false;" style="color:inherit;text-decoration:none;font-weight:bold;cursor:pointer;">&times;</a>' +
        '</span>';
    });
    h += '</div>';
    return h;
}

function _aggEs(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function _aggRemoveKeyword(idx) {
    _aggCurrentKeywords.splice(idx, 1);
    document.getElementById('aggChips').outerHTML = _aggRenderChips(_aggCurrentKeywords);
}

function _aggAddKeyword() {
    var input = document.getElementById('aggNewKwInput');
    var kw = input.value.trim();
    if (kw.length === 0) return;
    _aggCurrentKeywords.push(kw);
    input.value = '';
    document.getElementById('aggChips').outerHTML = _aggRenderChips(_aggCurrentKeywords);
}

function _aggRunSearch() {
    aggregationSearch(_aggCurrentKeywords);
}

function _aggBackToUnmatched() {
    _aggCurrentKeywords = [];
    _aggBankWords = [];
    loadUnmatched();
}

function _aggRenderEditor(keywords, bankWords) {
    var h = '<div class="keyword-editor border rounded p-2 mb-2 bg-light">';
    h += '<label class="small fw-bold mb-1">Kulcsszavak:</label>';
    h += _aggRenderChips(keywords);
    h += '<div class="input-group input-group-sm">';
    h += '<input type="text" id="aggNewKwInput" class="form-control" list="aggKwSuggest" placeholder="Új kulcsszó…" onkeydown="if(event.key===\'Enter\'){event.preventDefault();_aggAddKeyword();}">';
    h += '<datalist id="aggKwSuggest">';
    bankWords.forEach(function(w) {
        if (w.length >= 1) h += '<option value="' + _aggEs(w) + '">';
    });
    h += '</datalist>';
    h += '<button class="btn btn-outline-secondary" onclick="_aggAddKeyword()">+ Hozzáad</button>';
    h += '<button class="btn btn-primary" onclick="_aggRunSearch()">🔍 Keresés</button>';
    h += '</div></div>';
    return h;
}

function aggregationSearch(customKeywords) {
    var adatok = _currentViewingData;
    if (!adatok) return;

    var otsContainer = document.getElementById('c_ots_content');
    var otsEmpty = document.getElementById('c_ots_empty');
    otsEmpty.style.display = 'none';
    otsContainer.style.display = 'block';
    document.getElementById('unmatchedFilterBar').style.display = 'none';
    otsContainer.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Szöveges keresés a kulcsszavak alapján...</div>';

    var data = new FormData();
    data.append('action', 'ots_aggregation_search');
    data.append('church_id', adatok.church_id || 0);
    data.append('bank_desc', adatok.bank_desc || '');
    data.append('bank_ext_name', adatok.bank_ext_name || '');
    data.append('bank_date', adatok.bank_date || '');
    data.append('bank_amount', adatok.bank_amount || 0);
    data.append('csrf_token', CSRF_TOKEN);
    if (customKeywords && customKeywords.length > 0) {
        data.append('custom_keywords', JSON.stringify(customKeywords));
    }

    fetch('reconciliation.php', { method: 'POST', body: data })
    .then(function(res) { return res.json(); })
    .then(function(result) {
        var keywords = result.keywords || [];
        var bankWords = result.bank_words || [];

        if (result.status !== 'OK' || !result.data || result.data.length === 0) {
            _aggCurrentKeywords = keywords;
            _aggBankWords = bankWords;
            otsContainer.style.display = 'none';
            otsEmpty.style.display = 'block';
            otsEmpty.innerHTML = '<strong>[Nincs találat]</strong><br>Egyetlen OTS tétel sem tartalmazza a közlemény kulcsszavait.' +
                '<div class="mt-2 mb-2"><button class="btn btn-outline-secondary btn-sm" onclick="_aggBackToUnmatched()">← Vissza az OTS listához</button></div>' +
                _aggRenderEditor(keywords, bankWords);
            return;
        }
        var transactions = result.data;
        _aggCurrentKeywords = keywords;
        _aggBankWords = bankWords;
        var bankAmt = Number(adatok.bank_amount);
        var bankDate = adatok.bank_date || '';

        document.getElementById('toggleMatchModeBtn').style.display = '';
    document.getElementById('aggregationSearchBtn').style.display = 'none';
    document.getElementById('unmatchedFilterBar').style.display = 'none';

        var html = '';
        html += '<div class="d-flex justify-content-between align-items-center mb-1">' +
            '<button class="btn btn-outline-secondary btn-sm" onclick="_aggBackToUnmatched()">← Vissza</button>' +
            '<span class="alert alert-info text-center py-1 small mb-0 flex-grow-1 ms-2">🔍 Szöveges keresés találatai — ' + transactions.length + ' db (már párosítottak 🔒 jelzéssel)</span>' +
        '</div>';
        html += _aggRenderEditor(keywords, bankWords);
        html += '<div class="accordion" id="otsAccordion">';

        transactions.forEach(function(tx, idx) {
            var txId = 'tx-agg-' + idx;
            var isFirst = idx === 0;
            var otsDate = tx.DATETIME ? tx.DATETIME.substring(0, 10) : '-';
            var adjAmount = Number(tx.adjusted_amount || tx.AMOUNT || 0);
            var otsAmount = adjAmount.toLocaleString('hu-HU') + ' Ft';
            var otsDesc = tx.ots_desc_full || '-';
            var recordId = tx.RECORD_ID || '';

            var isAmountMatch = Math.abs(adjAmount - bankAmt) < 0.01;
            var isUsed = tx._used === true || tx._used === 1 || (tx._used_count || 0) > 0;

            html += '<div class="accordion-item ' + (isAmountMatch ? 'border-warning' : '') + '">' +
                '<h2 class="accordion-header">' +
                    '<button class="accordion-button ' + (isFirst ? '' : 'collapsed') + '" type="button" data-bs-toggle="collapse" data-bs-target="#' + txId + '" aria-expanded="' + isFirst + '">' +
                        (isUsed ?
                        '<span class="badge bg-danger me-1 small" style="font-size:10px; padding:2px 4px;" title="Banki tételek: #' + escapeAttr(tx._used_bank_ids || '') + '">🔒' + (tx._used_count ? ' (' + tx._used_count + ')' : '') + '</span>' :
                        '<input type="radio" name="otsSelect" class="form-check-input me-2 radio-input" value="' + idx + '" data-doc="' + (tx.CASH_DOCUMENT_NUMBER || '') + '" data-record-id="' + recordId + '" data-date="' + (tx.DATETIME || '') + '" data-amount="' + adjAmount + '" ' + (isFirst ? 'checked' : '') + ' onclick="event.stopPropagation();">') +
                        (isUsed ? '' :
                        '<input type="checkbox" class="form-check-input me-2 checkbox-input" data-doc="' + (tx.CASH_DOCUMENT_NUMBER || '') + '" data-record-id="' + recordId + '" data-date="' + (tx.DATETIME || '') + '" data-amount="' + adjAmount + '" style="display:none;" onchange="event.stopPropagation(); frissitMultiOsszegzo();">') +
                        '<span class="fw-bold me-2">#' + (idx + 1) + '</span>' +
                        (isAmountMatch ? '<span class="badge bg-warning text-dark me-1 small" style="font-size:10px; padding:2px 4px;">Összeg egyezik</span>' : '') +
                        '<span class="badge bg-info text-dark me-1 small" style="font-size:10px; padding:2px 4px;">' + tx._text_score + '/' + result.keywords.length + '</span>' +
                        '<span class="badge bg-secondary me-1 small" style="font-size:10px; padding:2px 4px;">' + otsDate + '</span>' +
                        '<span class="' + (adjAmount < 0 ? 'text-danger' : 'text-success') + ' fw-bold me-2 small">' + otsAmount + '</span>' +
                        '<small class="text-muted text-truncate" style="max-width: 200px;">' + otsDesc + '</small>' +
                    '</button>' +
                '</h2>' +
                '<div id="' + txId + '" class="accordion-collapse collapse ' + (isFirst ? 'show' : '') + '" data-bs-parent="#otsAccordion">' +
                    '<div class="accordion-body p-0">' +
                        '<table class="table table-sm table-striped table-bordered m-0">';

            var columnOrder = ['DATETIME', 'adjusted_amount', 'ots_desc_full',
                'CASH_DOCUMENT_NUMBER', 'DECISION_NUMBER', 'ots_type_name',
                'MODIFIED', 'VIA_BANK', 'PERSON_ID',
                'NAME_ID', 'NAME2_ID', 'RECORD_ID',
                'IBAN', 'ACCOUNT_NUMBER'];

            var huLabels = {
                'DATETIME': 'Dátum / Időpont',
                'adjusted_amount': 'Összeg',
                'ots_desc_full': 'Partner / Megjegyzés',
                'CASH_DOCUMENT_NUMBER': 'Bizonylatszám',
                'DECISION_NUMBER': 'Határozati szám',
                'ots_type_name': 'Típus',
                'MODIFIED': 'Módosítás ideje',
                'VIA_BANK': 'VIA Bank kód',
                'PERSON_ID': 'Személy ID',
                'NAME_ID': 'Tranzakció név ID',
                'NAME2_ID': 'Tranzakció név2 ID',
                'IBAN': 'IBAN',
                'ACCOUNT_NUMBER': 'Számlaszám'
            };

            columnOrder.forEach(function(key) {
                if (key in tx && tx[key] !== null && tx[key] !== undefined) {
                    var val = tx[key];
                    if (val === '' || val === null || val === undefined) val = '-';
                    var formattedVal = val;
                    var style = '';
                    if (key === 'adjusted_amount') {
                        formattedVal = Number(val).toLocaleString('hu-HU') + ' Ft';
                        style = val < 0 ? 'class="fw-bold text-danger"' : 'class="fw-bold text-success"';
                    } else if (key === 'DATETIME' || key === 'MODIFIED') {
                        formattedVal = val.length >= 16 ? val.substring(0, 16) : val;
                    }
                    var label = huLabels[key] || key;
                    html += '<tr><th style="width: 35%;">' + label + ':</th><td ' + style + '>' + formattedVal + '</td></tr>';
                }
            });

            if (tx.ots_editor_name || tx.EDITED_BY) {
                var editorName = tx.ots_editor_name || '-';
                var editorId = tx.EDITED_BY ? ' <span class="text-muted small">(' + tx.EDITED_BY + ')</span>' : '';
                html += '<tr><th style="width: 35%;">Rögzítette:</th><td>' + editorName + editorId + '</td></tr>';
            }

            if (tx.FUND_ID) {
                var fundInfo = tx.FUND_ID;
                if (tx.fund_name) fundInfo += ' (' + tx.fund_name + ')';
                html += '<tr><th>Alap:</th><td>' + fundInfo + '</td></tr>';
            }

        var hiddenKeys = ['ots_editor_name', 'EDITED_BY', 'FUND_ID', 'fund_name', 'AMOUNT', 'CHURCH_ID', 'TYPE', 'YEAR', 'MONTH', 'DAY', 'TC_ID'];
            Object.keys(tx).forEach(function(key) {
                if (!columnOrder.includes(key) && !hiddenKeys.includes(key) && !key.startsWith('ots_') && key.charAt(0) !== '_') {
                    var val = tx[key];
                    if (val === null || val === undefined || val === '') val = '-';
                    html += '<tr><th>' + key + ':</th><td>' + val + '</td></tr>';
                }
            });

            html += '</table>' +
                '<div class="text-center py-1 border-top">' +
                    '<button class="btn btn-outline-info btn-sm" onclick="event.stopPropagation(); findBankPairs(' + recordId + ', ' + adjAmount + ', \'' + (tx.DATETIME ? tx.DATETIME.substring(0, 10) : '') + '\', ' + adatok.church_id + ')" type="button">🔍 Banki párok keresése</button>' +
                '</div></div></div></div>';
        });

        html += '</div>';
        html += '<div id="multiSumBar" class="alert alert-secondary text-center py-1 small mt-1" style="display:none;">' +
            'Több tétel kiválasztva — összeg: <span id="multiTotalAmt">0</span> Ft</div>';
        html += '<div class="text-center mt-2">' +
            '<button class="btn btn-success btn-sm" onclick="saveOtsMatch(' + adatok.id + ', ' + bankAmt + ')" type="button">💾 Párosítás</button>' +
            ' <span id="otsSaveMsg"></span></div>';

        otsContainer.innerHTML = html;
        if (document.querySelector('#otsAccordion .radio-input')) {
            document.querySelector('#otsAccordion .radio-input').checked = true;
        }
    })
    .catch(function() {
        otsContainer.innerHTML = '<div class="alert alert-danger text-center">Hiba történt a keresés során.</div>';
    });
}

var _currentOtsPairing = null;

function findBankPairs(otsRecordId, otsAmount, otsDate, churchId) {
    var leftPanel = document.getElementById('bankPairsLeftPanel');
    var defaultView = document.getElementById('bankDefaultView');
    var content = document.getElementById('bankPairLeftContent');
    var info = document.getElementById('bankPairLeftInfo');

    _currentOtsPairing = { recordId: otsRecordId, amount: otsAmount, date: otsDate, churchId: churchId };

    defaultView.style.display = 'none';
    leftPanel.style.display = 'block';
    content.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span>Banki tételek keresése...</div>';
    info.innerHTML = 'OTS cél: <strong>' + Math.abs(otsAmount).toLocaleString('hu-HU') + ' Ft</strong> | ' + (otsDate || '') + ' | Record #' + otsRecordId;

    var data = new FormData();
    data.append('action', 'ots_find_bank_pairs');
    data.append('church_id', churchId);
    data.append('ots_date', otsDate);
    data.append('ots_amount', otsAmount);
    data.append('csrf_token', CSRF_TOKEN);

    fetch('reconciliation.php', { method: 'POST', body: data })
    .then(function(res) { return res.json(); })
    .then(function(result) {
        if (result.status !== 'OK' || !result.data || result.data.length === 0) {
            content.innerHTML = '<div class="alert alert-warning small py-2 text-center">Nincs párosítatlan banki tétel ebben az időablakban.</div>';
            return;
        }
        var bankItems = result.data;
        content.dataset.otsAmount = otsAmount;
        renderBankPairTable(bankItems, otsAmount);
    })
    .catch(function() {
        content.innerHTML = '<div class="alert alert-danger small py-2 text-center">Hiba történt.</div>';
    });
}

function renderBankPairTable(bankItems, otsAmount) {
    var content = document.getElementById('bankPairLeftContent');
    var sortKey = content.dataset.sortKey || 'bank_date';
    var sortDir = content.dataset.sortDir || 'asc';

    // Rendezés
    bankItems.sort(function(a, b) {
        var va, vb;
        if (sortKey === 'bank_amount') {
            va = Math.abs(Number(a.bank_amount || 0));
            vb = Math.abs(Number(b.bank_amount || 0));
        } else {
            va = a.bank_date || '';
            vb = b.bank_date || '';
        }
        if (va < vb) return sortDir === 'asc' ? -1 : 1;
        if (va > vb) return sortDir === 'asc' ? 1 : -1;
        return 0;
    });

    var html = '<table class="table table-sm table-bordered m-0 small" style="font-size:12px;">';
    html += '<thead><tr class="table-dark">' +
        '<th style="width:30px;"><input type="checkbox" id="bankPairSelectAll" onchange="toggleAllBankPairs(this)"></th>' +
        '<th class="sortable" onclick="sortBankPairs(\'bank_date\')" style="cursor:pointer;">📅 Dátum ' + (sortKey === 'bank_date' ? (sortDir === 'asc' ? '▲' : '▼') : '') + '</th>' +
        '<th class="sortable text-end" onclick="sortBankPairs(\'bank_amount\')" style="cursor:pointer;">💰 Összeg ' + (sortKey === 'bank_amount' ? (sortDir === 'asc' ? '▲' : '▼') : '') + '</th>' +
        '<th>Leírás</th>' +
    '</tr></thead><tbody>';

    bankItems.forEach(function(item) {
        var itemDate = item.bank_date || '-';
        var itemAmt = Number(item.bank_amount || 0);
        var itemDesc = (item.bank_desc || '').substring(0, 55) + ((item.bank_desc || '').length > 55 ? '…' : '');
        var isExact = Math.abs(Math.abs(itemAmt) - Math.abs(otsAmount)) < 0.01;
        html += '<tr class="' + (isExact ? 'table-success' : '') + '">' +
            '<td><input type="checkbox" class="bank-pair-cb" data-bank-id="' + item.id + '" data-bank-amount="' + itemAmt + '" ' + (isExact ? 'checked' : '') + ' onchange="updateLeftBankPairSum()"></td>' +
            '<td>' + itemDate + '</td>' +
            '<td class="text-end ' + (itemAmt < 0 ? 'text-danger' : 'text-success') + ' fw-bold">' + itemAmt.toLocaleString('hu-HU') + ' Ft</td>' +
            '<td class="text-muted">' + itemDesc + '</td>' +
        '</tr>';
    });

    html += '</tbody></table>';
    html += '<div class="d-flex justify-content-between align-items-center p-1 border-top bg-light small">' +
        '<span>Kiválasztva: <strong id="leftBankPairSum">0</strong> Ft' +
        ' | cél: ' + Math.abs(otsAmount).toLocaleString('hu-HU') + ' Ft' +
        ' | eltérés: <strong id="leftBankPairDiff" class="text-success">0</strong> Ft</span>' +
        '<button class="btn btn-success btn-sm py-0" onclick="saveReverseMatchLeft()" type="button">💾 Párosítás</button>' +
    '</div>';

    content.innerHTML = html;
    updateLeftBankPairSum();
}

function sortBankPairs(key) {
    var content = document.getElementById('bankPairLeftContent');
    var currentKey = content.dataset.sortKey || '';
    var currentDir = content.dataset.sortDir || 'asc';
    if (currentKey === key) {
        content.dataset.sortDir = currentDir === 'asc' ? 'desc' : 'asc';
    } else {
        content.dataset.sortKey = key;
        content.dataset.sortDir = 'asc';
    }
    var otsAmount = Number(content.dataset.otsAmount) || 0;
    var bankItems = [];
    content.querySelectorAll('.bank-pair-cb').forEach(function(cb) {
        bankItems.push({
            id: cb.getAttribute('data-bank-id'),
            bank_amount: cb.getAttribute('data-bank-amount'),
            bank_date: cb.closest('tr').querySelector('td:nth-child(2)').textContent
        });
    });
    renderBankPairTable(bankItems, otsAmount);
}

function toggleAllBankPairs(sender) {
    document.querySelectorAll('#bankPairLeftContent .bank-pair-cb').forEach(function(cb) {
        cb.checked = sender.checked;
    });
    updateLeftBankPairSum();
}

function updateLeftBankPairSum() {
    var sum = 0;
    var target = 0;
    var content = document.getElementById('bankPairLeftContent');
    if (content) target = Math.abs(Number(content.dataset.otsAmount)) || 0;

    content.querySelectorAll('.bank-pair-cb:checked').forEach(function(cb) {
        sum += Number(cb.getAttribute('data-bank-amount')) || 0;
    });
    document.getElementById('leftBankPairSum').textContent = sum.toLocaleString('hu-HU');
    var diff = Math.abs(Math.abs(sum) - target);
    var diffEl = document.getElementById('leftBankPairDiff');
    diffEl.textContent = diff.toLocaleString('hu-HU');
    diffEl.className = diff < 1 ? 'text-success fw-bold' : diff < 100 ? 'text-warning fw-bold' : 'text-danger fw-bold';
}

function closeBankPairsLeft() {
    document.getElementById('bankPairsLeftPanel').style.display = 'none';
    document.getElementById('bankDefaultView').style.display = 'block';
    _currentOtsPairing = null;
}

function saveReverseMatchLeft() {
    if (!_currentOtsPairing) return;
    var checked = document.querySelectorAll('#bankPairLeftContent .bank-pair-cb:checked');
    if (checked.length === 0) {
        alert('Kérlek pipálj ki legalább egy banki tételt!');
        return;
    }
    var bankIds = [];
    checked.forEach(function(cb) { bankIds.push(cb.getAttribute('data-bank-id')); });

    var otsRecordId = _currentOtsPairing.recordId;
    var otsAmount = _currentOtsPairing.amount;
    var churchId = _currentOtsPairing.churchId;
    var otsDate = _currentOtsPairing.date;

    var data = new FormData();
    data.append('action', 'save_reverse_match');
    data.append('ots_record_id', otsRecordId);
    data.append('ots_amount', otsAmount);
    data.append('church_id', churchId);
    data.append('ots_date', otsDate);
    data.append('bank_ids', JSON.stringify(bankIds));
    data.append('csrf_token', CSRF_TOKEN);

    document.getElementById('leftBankPairDiff').textContent = '⏳';
    fetch('reconciliation.php', { method: 'POST', body: data })
    .then(function(res) { return res.json(); })
    .then(function(result) {
        if (result.status === 'OK') {
            alert('✅ ' + result.message);
            window.location.reload();
        } else {
            alert('❌ ' + result.message);
        }
    })
    .catch(function() {
        alert('❌ Hálózati hiba');
    });
}

function saveReverseMatch(otsRecordId, otsAmount, churchId, otsDate) {
    var checked = document.querySelectorAll('#bankPairs-' + otsRecordId + ' .bank-pair-cb:checked, #bankPairs-agg-' + otsRecordId + ' .bank-pair-cb:checked');
    if (checked.length === 0) {
        alert('Kérlek pipálj ki legalább egy banki tételt!');
        return;
    }
    var bankIds = [];
    checked.forEach(function(cb) { bankIds.push(cb.getAttribute('data-bank-id')); });

    var data = new FormData();
    data.append('action', 'save_reverse_match');
    data.append('ots_record_id', otsRecordId);
    data.append('ots_amount', otsAmount);
    data.append('church_id', churchId);
    data.append('ots_date', otsDate);
    data.append('bank_ids', JSON.stringify(bankIds));
    data.append('csrf_token', CSRF_TOKEN);

    fetch('reconciliation.php', { method: 'POST', body: data })
    .then(function(res) { return res.json(); })
    .then(function(result) {
        if (result.status === 'OK') {
            alert('✅ ' + result.message);
            window.location.reload();
        } else {
            alert('❌ ' + result.message);
        }
    })
    .catch(function() {
        alert('❌ Hálózati hiba');
    });
}

function saveOtsMatch(bankRecordId, bankAmount) {
    if (isMultiMode) {
        // TÖBB OTS TÉTEL PÁROSÍTÁSA
        const checked = document.querySelectorAll('#otsAccordion .checkbox-input:checked');
        if (checked.length === 0) { alert('Kérlek pipálj ki legalább egy OTS tételt!'); return; }
        
        let totalAmt = 0;
        const recordIds = [];
        checked.forEach(cb => {
            recordIds.push(cb.getAttribute('data-record-id'));
            totalAmt += parseFloat(cb.getAttribute('data-amount')) || 0;
        });
        
        const bankDate = document.getElementById('cb_date').textContent;
        
        const data = new FormData();
        data.append('action', 'save_ots_match');
        data.append('id', bankRecordId);
        data.append('mode', 'multi');
        recordIds.forEach(rid => data.append('record_ids[]', rid));
        data.append('bank_date', bankDate);
        data.append('bank_amount', bankAmount || document.getElementById('cb_amount').textContent.replace(/\s/g, '').replace('Ft', ''));
        data.append('csrf_token', CSRF_TOKEN);
        
        document.getElementById('otsSaveMsg').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Mentés...';
        fetch('reconciliation.php', { method: 'POST', body: data })
        .then(res => res.json())
        .then(result => {
            if (result.status === 'OK') {
                document.getElementById('otsSaveMsg').innerHTML = '<span class="text-success fw-bold">✓ ' + result.message + '</span>';
                setTimeout(() => { window.location.reload(); }, 800);
            } else {
                document.getElementById('otsSaveMsg').innerHTML = '<span class="text-danger fw-bold">✗ ' + result.message + '</span>';
            }
        })
        .catch(() => {
            document.getElementById('otsSaveMsg').innerHTML = '<span class="text-danger fw-bold">✗ Hálózati hiba</span>';
        });
    } else {
        // EGY OTS TÉTEL PÁROSÍTÁSA (eredeti működés)
        const selected = document.querySelector('input[name="otsSelect"].radio-input:checked');
        if (!selected) { alert('Kérlek válassz ki egy OTS tételt!'); return; }
        
        const otsDoc = selected.getAttribute('data-doc') || '';
        const otsRecordId = selected.getAttribute('data-record-id') || '';
        const otsDate = selected.getAttribute('data-date');
        const otsAmount = selected.getAttribute('data-amount');
        const bankDate = document.getElementById('cb_date').textContent;
        
        const data = new FormData();
        data.append('action', 'save_ots_match');
        data.append('id', bankRecordId);
        data.append('mode', 'single');
        data.append('ots_doc', otsDoc);
        data.append('ots_record_id', otsRecordId);
        data.append('ots_date', otsDate);
        data.append('ots_amount', otsAmount);
        data.append('bank_date', bankDate);
        data.append('bank_amount', bankAmount || document.getElementById('cb_amount').textContent.replace(/\s/g, '').replace('Ft', ''));
        data.append('csrf_token', CSRF_TOKEN);
        
        document.getElementById('otsSaveMsg').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Mentés...';
        fetch('reconciliation.php', { method: 'POST', body: data })
        .then(res => res.json())
        .then(result => {
            if (result.status === 'OK') {
                document.getElementById('otsSaveMsg').innerHTML = '<span class="text-success fw-bold">✓ ' + result.message + '</span>';
                setTimeout(() => { window.location.reload(); }, 800);
            } else {
                document.getElementById('otsSaveMsg').innerHTML = '<span class="text-danger fw-bold">✗ ' + result.message + '</span>';
            }
        })
        .catch(() => {
            document.getElementById('otsSaveMsg').innerHTML = '<span class="text-danger fw-bold">✗ Hálózati hiba</span>';
        });
    }
}

function runAutoMatch() {
    const mode = document.querySelector('input[name="matchMode"]:checked').value;
    const customDays = document.getElementById('customDays').value;
    const allChurches = document.getElementById('allChurchesMatch').checked;

    const churchId = document.getElementById('currentChurchId').value;
    if (!allChurches && (!churchId || churchId === '-1')) {
        alert('Előbb válassz ki egy gyülekezetet a szűrőben, vagy kapcsold be a "Minden gyülekezetre" opciót!');
        return;
    }

    const btn = document.getElementById('btnRunMatch');
    const loader = document.getElementById('autoMatchLoader');
    const timerEl = document.getElementById('autoMatchTimer');
    
    btn.disabled = true; 
    loader.style.display = 'block';
    timerEl.innerText = "0.0s";

    let startTime = Date.now();
    let timerInterval = setInterval(() => {
        timerEl.innerText = ((Date.now() - startTime) / 1000).toFixed(1) + 's';
    }, 100);

    const finishTimer = () => {
        const finalTime = timerEl.innerText;
        clearInterval(timerInterval);
        btn.disabled = false; 
        loader.style.display = 'none';
        
        const targetId = 'last-' + mode;
        const targetEl = document.getElementById(targetId);
        if (targetEl) {
            targetEl.innerText = 'Legutóbb: ' + finalTime;
            targetEl.style.display = 'inline-block';
        }
    };

    if (mode === 'search') {
        const amount = document.getElementById('searchAmount').value;
        if (!amount) { alert('Kérlek add meg a keresett összeget!'); finishTimer(); return; }
        
        const data = new FormData();
        data.append('action', 'search_ots_amount');
        data.append('amount', amount);
        data.append('csrf_token', CSRF_TOKEN);
        
        fetch('reconciliation.php', { method: 'POST', body: data })
        .then(res => res.json())
        .then(result => {
            if (result.status === 'OK') {
                if (result.data.length === 0) {
                    alert(`Nincs találat az OTS-ben a(z) ${amount} Ft összegre (Bankos tételként).`);
                } else {
                    let msg = `🔎 TALÁLATOK A(Z) ${amount} FT ÖSSZEGRE:\n\n`;
                    result.data.forEach(r => {
                        let type = r.VIA_BANK != 0 ? '🏦 BANK' : '💵 KÉSZPÉNZ';
                        let lock = r._used ? ' 🔒 MÁR PÁROSÍTVA' : '';
                        msg += `[${type}] 🏛 ${r.church_name} | 📅 ${r.ots_date} | 📄 Biz: ${r.ots_doc}${lock}\n`;
                        msg += `📝 ${r.ots_desc}\n\n`;
                    });
                    alert(msg);
                }
            } else { alert('Hiba a keresés során!'); }
        })
        .finally(() => { finishTimer(); });
        return;
    }
    
    const data = new FormData();
    data.append('action', 'auto_match'); data.append('match_mode', mode); data.append('custom_days', customDays); data.append('church_id', churchId); data.append('csrf_token', CSRF_TOKEN);
    if (allChurches) data.append('all_churches', '1');

    // Create a small status UI element
    let statusEl = document.getElementById('match-progress-status');
    if (!statusEl) {
        statusEl = document.createElement('div');
        statusEl.id = 'match-progress-status';
        statusEl.style.position = 'fixed';
        statusEl.style.right = '16px';
        statusEl.style.bottom = '16px';
        statusEl.style.zIndex = 9999;
        statusEl.style.padding = '10px 14px';
        statusEl.style.background = 'rgba(0,0,0,0.75)';
        statusEl.style.color = '#fff';
        statusEl.style.borderRadius = '6px';
        statusEl.style.fontFamily = 'monospace';
        statusEl.innerText = 'Párosítás: indítás...';
        document.body.appendChild(statusEl);
    }

    // start polling progress endpoint
    let poll = true;
    const pollInterval = 1000;
    const poller = setInterval(() => {
        fetch('match_progress.php').then(r => r.text()).then(txt => { try { return JSON.parse(txt); } catch(e) { return null; } }).then(js => {
            if (!js) return;
            if (js.status === 'NONE') { statusEl.innerText = 'Nincs futó párosítás.'; return; }
            if (js.status === 'RUNNING') {
                statusEl.innerText = `Feldolgozás: gyülekezet: ${js.current_church || '-'}; feld.: ${js.processed_records || 0}; feld.: ${js.processed_churches || 0}; párosítva: ${js.matched || 0}; össz: ${js.total_unchecked || 0}; ${js.time_sec || 0}s`;
            } else if (js.status === 'DONE') {
                statusEl.innerText = `Kész. párosított: ${js.matched || 0}; futási idő: ${js.time_sec || 0}s`;
                // remove after a short delay
                setTimeout(() => { if (statusEl) statusEl.remove(); }, 3000);
                clearInterval(poller);
            }
        }).catch(()=>{});
    }, pollInterval);

    fetch('reconciliation.php', { method: 'POST', body: data })
    .then(r => r.text())
    .then(txt => { try { return JSON.parse(txt); } catch(e) { return {status:'ERROR',message:'Invalid JSON'}; } })
    .then(result => {
        if (!checkSession(result)) return;
        if (result.status === 'OK') {
            let scope = allChurches ? '🌍 Minden gyülekezetre' : '🏛 Kiválasztott gyülekezetre';
            let msg = `🎉 ${scope} kész! ${result.total} feldolgozatlan tételből ${result.matched} db-ot sikerült automatikusan párosítani az OTS-el.\n\n`;
            if (mode === 'progressive') {
                msg += `Részletek:\n`;
                msg += `🔒 0 napos (Írásvédett): ${result.details.pass_0} db\n`;
                msg += `⏱️ 3 napos: ${result.details.pass_3} db\n`;
                msg += `⏱️ 6 napos: ${result.details.pass_6} db\n`;
                msg += `⏱️ 12 napos: ${result.details.pass_12} db`;
                msg += `\n⏱️ 35 napos: ${result.details.pass_35} db`;
                msg += `\n⏱️ 60 napos: ${result.details.pass_60} db`;
                msg += `\n🔎 Szöveges (Név/Közlemény): ${result.details.pass_text} db`;
            } else {
                msg += `Az egyedi ${customDays} napos ráhagyással: ${result.details.custom} db.`;
            }
            alert(msg);
            // stop polling and remove status element
            try { clearInterval(poller); } catch(e){}
            const el = document.getElementById('match-progress-status'); if (el) el.remove();
            window.location.reload();
        } else {
            alert('Hiba történt a futtatás során!');
        }
    })
    .finally(() => { finishTimer(); });
}

function bulkApproveCsuszas() {
    const tableContainer = document.querySelector('.table-responsive-scroll');
    if (!tableContainer) return;
    
    const containerRect = tableContainer.getBoundingClientRect();
    const headerHeight = tableContainer.querySelector('thead').getBoundingClientRect().height || 60;
    const visibleTop = containerRect.top + headerHeight; // A rögzített fejléc alatti ténylegesen látható rész
    
    const rows = document.querySelectorAll('.data-row');
    let idsToApprove = [];
    
    rows.forEach(row => {
        if (row.style.display !== 'none' && row.getAttribute('data-status') === 'CSUSZAS') {
            const rect = row.getBoundingClientRect();
            // Csak akkor vesszük fel, ha a sor fizikailag látszik az éppen görgetett képernyőrészen
            if (rect.top <= containerRect.bottom && rect.bottom >= visibleTop) {
                const id = row.id.replace('row-', '');
                idsToApprove.push(id);
            }
        }
    });
    
    if (idsToApprove.length === 0) {
        alert('A jelenleg a képernyőn (viewportban) LÁTHATÓ sorok között nincs [IDŐ CSÚSZÁS] állapotú tétel!');
        return;
    }
    
    if (!confirm(`Biztosan jóváhagysz (OK-ra állítasz) ${idsToApprove.length} db, JELENLEG A KÉPERNYŐN LÁTHATÓ [IDŐ CSÚSZÁS] tételt?`)) {
        return;
    }
    
    const data = new FormData();
    data.append('action', 'bulk_approve');
    data.append('ids', JSON.stringify(idsToApprove));
    data.append('csrf_token', CSRF_TOKEN);
    
    fetch('reconciliation.php', { method: 'POST', body: data })
    .then(res => res.json())
    .then(result => {
        if (result.status === 'OK') {
            window.location.reload();
        } else { alert('Hiba történt a tömeges jóváhagyás során!'); }
    })
    .catch(err => alert('Hiba történt a kérés során: ' + err));
}

var _fontSizeSmall = false;
function toggleFontSize() {
    _fontSizeSmall = !_fontSizeSmall;
    var table = document.getElementById('sortableTable');
    if (!table) return;
    table.classList.toggle('table-compact', _fontSizeSmall);
    var btn = document.getElementById('fontSizeBtn');
    if (btn) btn.textContent = _fontSizeSmall ? '🔍+' : '🔍−';
}

function exportTableToCSV() {
    let csv = [];
    csv.push('\uFEFF'); // UTF-8 BOM kódolás a hibátlan magyar ékezetekhez az Excelben

    const rows = document.querySelectorAll("#sortableTable tr");
    
    for (let i = 0; i < rows.length; i++) {
        let row = rows[i];
        
        // Kihagyjuk a rejtett sorokat és a dupla fejléc legfelső sorát
        if (row.style.display === 'none' || row.classList.contains('main-header')) continue;
        
        let rowData = [];
        let cols = row.querySelectorAll("td, th");
        
        // Az utolsó oszlopot (Akció gombok) kihagyjuk
        for (let j = 0; j < cols.length - 1; j++) {
            let col = cols[j];
            let text = "";
            
            if (row.classList.contains('data-row')) {
                if (j === 4) { let input = col.querySelector('input'); text = input ? input.value : col.innerText; }
                else if (j === 7) { let select = col.querySelector('select'); text = select ? select.options[select.selectedIndex].text : col.innerText; }
                else if (j === 8) { let input = col.querySelector('input'); text = input ? input.value : col.innerText; }
                else { text = col.innerText; }
            } else if (row.classList.contains('sub-header')) {
                let clone = col.cloneNode(true);
                clone.querySelectorAll('input, span, select').forEach(el => el.remove());
                text = clone.innerText.trim();
            }
            
            text = text.replace(/"/g, '""').trim(); // Excel idézőjel escape
            rowData.push('"' + text + '"');
        }
        if(rowData.length > 0) csv.push(rowData.join(";"));
    }

    let csvFile = new Blob([csv.join("\n")], {type: "text/csv;charset=utf-8;"});
    let downloadLink = document.createElement("a");
    downloadLink.download = "Bankegyezteto_Export_" + new Date().toISOString().slice(0,10) + ".csv";
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// --- PER-PAGE LOADING OVERLAY ---
function showPerPageLoading(form) {
    document.getElementById('perPageLoadingOverlay').classList.add('show');
    var start = Date.now();
    setInterval(function() {
        document.getElementById('perPageTimer').innerText = ((Date.now() - start) / 1000).toFixed(1) + 's';
    }, 100);
    form.submit();
}

// --- AUTO-MATCH LOG ---
function loadAutoMatchLog() {
    const el = document.getElementById('autoMatchLogContent');
    if (!el) return;
    el.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Betöltés...';
    const data = new FormData();
    data.append('action', 'get_auto_match_log');
    data.append('limit', '30');
    data.append('csrf_token', CSRF_TOKEN);
    const churchId = document.querySelector('input[name="church_filter"]')?.value || document.querySelector('select[name="church_filter"]')?.value || 0;
    if (churchId) data.append('church_id', churchId);
    fetch('reconciliation.php', { method: 'POST', body: data })
    .then(r => r.text())
    .then(txt => { try { return JSON.parse(txt); } catch(e) { return {status:'ERROR',message:'Invalid JSON'}; } })
    .then(res => {
        if (!checkSession(res)) return;
        if (res.status !== 'OK' || !res.data.length) {
            el.innerHTML = '<div class="text-muted text-center py-3">Még nincs auto-match futás.</div>';
            return;
        }
        let html = '<table class="table table-sm table-hover align-middle mb-0" style="font-size: 12px;">';
        html += '<thead><tr class="table-light"><th>Dátum</th><th>Gyülekezet</th><th>Mód</th><th>Feldolgozatlan</th><th>Párosítva</th><th>Idő</th><th>Futtatta</th></tr></thead><tbody>';
        res.data.forEach(r => {
            const dt = r.run_at ? new Date(r.run_at).toLocaleString('hu-HU') : '-';
            const ch = r.church_name || (r.church_id ? '#' + r.church_id : '🌍 Minden');
            const mode = r.mode === 'progressive' ? 'Progresszív' : (r.mode === 'custom' ? 'Egyedi' : r.mode);
            const pct = r.total_unchecked > 0 ? Math.round(r.matched / r.total_unchecked * 100) : 0;
            html += `<tr style="cursor:pointer" onclick="showLogDetail(${r.id})" title="Részletek megtekintése">`;
            html += `<td class="text-nowrap">${dt}</td>`;
            html += `<td>${ch}</td>`;
            html += `<td>${mode}</td>`;
            html += `<td>${r.total_unchecked}</td>`;
            html += `<td><span class="fw-bold">${r.matched}</span> <span class="text-muted">(${pct}%)</span></td>`;
            html += `<td>${r.elapsed_sec}s</td>`;
            html += `<td>${r.run_by || '-'}</td>`;
            html += '</tr>';
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    })
    .catch(err => { el.innerHTML = '<div class="text-danger">Hiba: ' + err.message + '</div>'; });
}
function showLogDetail(logId) {
    const data = new FormData();
    data.append('action', 'get_auto_match_log');
    data.append('limit', '1');
    data.append('csrf_token', CSRF_TOKEN);
    fetch('reconciliation.php', { method: 'POST', body: data })
    .then(r => r.text())
    .then(txt => { try { return JSON.parse(txt); } catch(e) { return {status:'ERROR',message:'Invalid JSON'}; } })
    .then(res => {
        if (!checkSession(res)) return;
        if (res.status !== 'OK') return;
        const r = res.data.find(d => d.id == logId);
        if (!r) return;
        const details = typeof r.details === 'string' ? JSON.parse(r.details) : r.details;
        let html = '<div class="p-3">';
        html += `<h6>Futás: ${r.run_at ? new Date(r.run_at).toLocaleString('hu-HU') : '#' + r.id}</h6>`;
        html += `<table class="table table-sm" style="font-size: 12px;">`;
        html += `<tr><td>Gyülekezet</td><td>${r.church_name || (r.church_id ? '#' + r.church_id : 'Minden')}</td></tr>`;
        html += `<tr><td>Mód</td><td>${r.mode}</td></tr>`;
        html += `<tr><td>Feldolgozatlan</td><td>${r.total_unchecked}</td></tr>`;
        html += `<tr><td>Párosítva</td><td class="fw-bold text-success">${r.matched}</td></tr>`;
        html += `<tr><td>Időtartam</td><td>${r.elapsed_sec}s</td></tr>`;
        html += `<tr><td>Futtatta</td><td>${r.run_by || '-'}</td></tr>`;
        html += '</table>';
        if (details) {
            html += '<h6>Részletek:</h6><ul class="list-unstyled" style="font-size:12px;">';
            if (details.pass_0) html += `<li>🔒 0 nap (írásvédett): <strong>${details.pass_0}</strong></li>`;
            if (details.pass_3) html += `<li>⏱️ 3 nap: ${details.pass_3}</li>`;
            if (details.pass_6) html += `<li>⏱️ 6 nap: ${details.pass_6}</li>`;
            if (details.pass_12) html += `<li>⏱️ 12 nap: ${details.pass_12}</li>`;
            if (details.pass_35) html += `<li>⏱️ 35 nap: ${details.pass_35}</li>`;
            if (details.pass_60) html += `<li>⏱️ 60 nap: ${details.pass_60}</li>`;
            if (details.pass_text) html += `<li>🔎 Szöveges: ${details.pass_text}</li>`;
            if (details.pass_tc) html += `<li>🏢 TC: ${details.pass_tc}</li>`;
            if (details.custom) html += `<li>🎯 Egyedi: ${details.custom}</li>`;
            html += '</ul>';
        }
        html += '</div>';
        document.getElementById('autoMatchLogContent').innerHTML = html;
    });
}

// --- CUSTOM PATTERNS ---
var cpEditId = null;

function openCustomPatterns() {
    cpEditId = null;
    var churchSelect = document.getElementById('cpChurchSelect');
    churchSelect.innerHTML = '<option value="">-- Válassz gyülekezetet --</option>';
    document.getElementById('cpContent').style.display = 'none';
    document.getElementById('cpEmpty').style.display = 'block';
    document.getElementById('cpTableBody').innerHTML = '';
    document.getElementById('cpNewBank').value = '';
    document.getElementById('cpNewOts').value = '';
    document.getElementById('cpNewLabel').value = '';

    var mainSelect = document.getElementById('churchSelect');
    var mainValue = mainSelect ? mainSelect.value : '';
    var churchOptions = document.querySelectorAll('#churchesList option');
    churchOptions.forEach(function(opt) {
        var val = opt.value;
        var option = document.createElement('option');
        option.value = val;
        option.textContent = val;
        if (val === mainValue) option.selected = true;
        churchSelect.appendChild(option);
    });

    new bootstrap.Modal(document.getElementById('customPatternsModal')).show();
    if (mainValue) loadCustomPatterns();
}

function getSelectedChurchId() {
    var name = document.getElementById('cpChurchSelect').value;
    return CHURCH_NAME_TO_ID[name] || 0;
}

function getChurchNameToId(name) {
    return CHURCH_NAME_TO_ID[name] || 0;
}

function loadCustomPatterns() {
    var churchName = document.getElementById('cpChurchSelect').value;
    if (!churchName) {
        document.getElementById('cpContent').style.display = 'none';
        document.getElementById('cpEmpty').style.display = 'block';
        return;
    }
    var churchId = getChurchNameToId(churchName);

    fetch('reconciliation.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=custom_patterns&sub=list&csrf_token=' + CSRF_TOKEN + '&church_id=' + churchId
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'OK') {
            document.getElementById('cpContent').style.display = 'block';
            document.getElementById('cpEmpty').style.display = 'none';
            var tbody = document.getElementById('cpTableBody');
            tbody.innerHTML = '';
            data.items.forEach(function(item) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>' + escapeHtml(item.bank_pattern) + '</td>' +
                    '<td>' + escapeHtml(item.ots_pattern) + '</td>' +
                    '<td>' + escapeHtml(item.label || '') + '</td>' +
                    '<td class="text-nowrap">' +
                    '<button class="btn btn-sm btn-outline-primary py-0 px-1" onclick="editCustomPattern(' + item.id + ',\'' + escapeJsString(item.bank_pattern) + '\',\'' + escapeJsString(item.ots_pattern) + '\',\'' + escapeJsString(item.label || '') + '\')">&#9998;</button> ' +
                    '<button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="deleteCustomPattern(' + item.id + ')">&#10005;</button></td>';
                tbody.appendChild(tr);
            });
        }
    });
}

function addCustomPattern() {
    var churchName = document.getElementById('cpChurchSelect').value;
    var churchId = getChurchNameToId(churchName);
    var bank = document.getElementById('cpNewBank').value.trim();
    var ots = document.getElementById('cpNewOts').value.trim();
    var label = document.getElementById('cpNewLabel').value.trim();
    if (!bank || !ots) { alert('Banki és OTS kulcsszó megadása kötelező!'); return; }

    if (cpEditId) {
        fetch('reconciliation.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=custom_patterns&sub=edit&csrf_token=' + CSRF_TOKEN + '&id=' + cpEditId + '&bank_pattern=' + encodeURIComponent(bank) + '&ots_pattern=' + encodeURIComponent(ots) + '&label=' + encodeURIComponent(label)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 'OK') {
                cpEditId = null;
                document.getElementById('cpNewBank').value = '';
                document.getElementById('cpNewOts').value = '';
                document.getElementById('cpNewLabel').value = '';
                loadCustomPatterns();
            } else {
                alert('Hiba: ' + (data.message || 'ismeretlen'));
            }
        });
    } else {
        fetch('reconciliation.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=custom_patterns&sub=add&csrf_token=' + CSRF_TOKEN + '&church_id=' + churchId + '&bank_pattern=' + encodeURIComponent(bank) + '&ots_pattern=' + encodeURIComponent(ots) + '&label=' + encodeURIComponent(label)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 'OK') {
                document.getElementById('cpNewBank').value = '';
                document.getElementById('cpNewOts').value = '';
                loadCustomPatterns();
            } else {
                alert('Hiba: ' + (data.message || 'ismeretlen'));
            }
        });
    }
}

function editCustomPattern(id, bank, ots, label) {
    cpEditId = id;
    document.getElementById('cpNewBank').value = bank;
    document.getElementById('cpNewOts').value = ots;
    document.getElementById('cpNewLabel').value = label;
    document.getElementById('cpNewBank').focus();
}

function deleteCustomPattern(id) {
    if (!confirm('Biztosan törlöd ezt a kulcsszó párt?')) return;
    fetch('reconciliation.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=custom_patterns&sub=delete&csrf_token=' + CSRF_TOKEN + '&id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'OK') loadCustomPatterns();
        else alert('Hiba: ' + (data.message || 'ismeretlen'));
    });
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function escapeAttr(str) {
    return escapeHtml(String(str)).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function escapeJsString(str) {
    return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, '\\n');
}

// --- SESSION COUNTDOWN ---
var sessionRemaining = <?php echo $session_remaining; ?>;
var sessionWarningShown = false;

function formatTime(sec) {
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ' perc ' + s + ' mp';
}
</script>

<!-- SESSION WARNING MODAL -->
<div class="modal fade" id="sessionWarnModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content border-warning">
      <div class="modal-header bg-warning text-dark">
        <h6 class="modal-title">⏰ Session lejár</h6>
      </div>
      <div class="modal-body text-center">
        <p class="mb-2">A munkamenet <strong>5 percen belül</strong> lejár!</p>
        <p class="text-muted small mb-0">Hátralévő idő: <strong id="sessionWarnTime">-</strong></p>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-warning fw-bold" onclick="extendSession()">🔁 Hosszabbítás +60 perc</button>
      </div>
    </div>
  </div>
</div>

<!-- ADMIN-ONLY MODAL TRIGGER (hidden, used by JS/Bootstrap data-bs-toggle) -->
<button id="adminOnlyModalTrigger" type="button" class="d-none" data-bs-toggle="modal" data-bs-target="#adminOnlyModal"></button>

<!-- ADMIN-ONLY MODAL -->
<div class="modal fade" id="adminOnlyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content border-danger">
      <div class="modal-header bg-danger text-white">
        <h6 class="modal-title">🚫 Jogosultság megtagadva</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center py-4">
        <p class="mb-1 fw-bold">Ehhez nincs jogosultságod.</p>
        <p class="text-muted small">Ezt a funkciót csak az adminisztrátor használhatja.</p>
      </div>
      <div class="modal-footer justify-content-center border-0 pt-0">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Bezárás</button>
      </div>
    </div>
  </div>
</div>

<!-- PER-PAGE LOADING OVERLAY -->
<div id="perPageLoadingOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
    <div style="background:white;padding:40px;border-radius:12px;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,0.3);min-width:300px;">
        <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem;"></div>
        <h5 class="mb-2">Betöltés folyamatban...</h5>
        <p class="text-muted small mb-2">Kérem várjon, amíg az adatok betöltődnek.</p>
        <div id="perPageTimer" style="font-size:36px;font-weight:700;color:#0d6efd;margin:10px 0;">0.0s</div>
    </div>
</div>

<!-- Session warning modal -->
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

<script>
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
    .then(r => {
        if (r.status === 401 || r.status === 403) { window.location.href = 'login.php'; return null; }
        return r.json();
    })
    .then(data => {
        if (!data) return;
        if (data.error || !data.logged_in) { window.location.href = 'login.php'; return; }
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
    .catch(() => { window.location.href = 'login.php'; })
    .finally(() => { sessionExtending = false; });
}

function updateSessionWarningDisplay() {
    var warnTime = document.getElementById('sessionWarnTime');
    if (warnTime) {
        warnTime.textContent = formatTime(sessionRemaining);
    }
}

setInterval(extendSession, 30000);

setInterval(() => {
    sessionRemaining--;
    updateSessionDisplay();
    if (sessionRemaining < 120 && !sessionWarningShown) {
        sessionWarningShown = true;
        updateSessionWarningDisplay();
        const modal = new bootstrap.Modal(document.getElementById('sessionWarningModal'));
        modal.show();
    }
    if (sessionWarningShown) {
        updateSessionWarningDisplay();
    }
    if (sessionRemaining <= 0) {
        window.location.href = 'logout.php';
    }
}, 1000);

// --- Oszlopátméretezés húzással + sessionStorage ---
(function() {
    var TABLE_ID = 'sortableTable';
    var STORAGE_KEY = 'revizor_col_widths_v2';
    var colGroup = document.getElementById('colGroup');
    var cols = colGroup ? colGroup.children : [];
    var headers = document.querySelectorAll('#' + TABLE_ID + ' .sub-header th');
    if (!colGroup || !cols.length || !headers.length) return;

    try {
        var saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY));
        if (saved && saved.length === cols.length) {
            for (var ci = 0; ci < cols.length; ci++) {
                if (saved[ci]) cols[ci].style.width = saved[ci];
            }
        }
    } catch (e) {}

    var dragState = null;

    function colResizeStart(e, colIndex) {
        e.preventDefault();
        var startX = e.clientX;
        var startWidth = cols[colIndex].offsetWidth;
        var tableWidth = colGroup.parentElement.offsetWidth;
        dragState = { colIndex: colIndex, startX: startX, startWidth: startWidth, tableWidth: tableWidth };
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
    }

    function colResizeMove(e) {
        if (!dragState) return;
        var dx = e.clientX - dragState.startX;
        var newPx = Math.max(30, dragState.startWidth + dx);
        var pct = (newPx / dragState.tableWidth * 100).toFixed(1);
        cols[dragState.colIndex].style.width = pct + '%';
    }

    function colResizeEnd() {
        if (!dragState) return;
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
        var widths = [];
        for (var si = 0; si < cols.length; si++) {
            widths.push(cols[si].style.width);
        }
        try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(widths)); } catch (e) {}
        dragState = null;
    }

    for (var hi = 0; hi < headers.length - 1; hi++) {
        var handle = document.createElement('div');
        handle.className = 'resize-handle';
        (function(idx) {
            handle.addEventListener('mousedown', function(e) { colResizeStart(e, idx); });
        })(hi);
        headers[hi].appendChild(handle);
    }

    document.addEventListener('mousemove', colResizeMove);
    document.addEventListener('mouseup', colResizeEnd);
})();
</script>

<?php if (function_exists('render_announcement_modal')) render_announcement_modal(); ?>

</body>
</html>
