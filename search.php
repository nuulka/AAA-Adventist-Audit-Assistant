<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../ots/constant.php';

if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION[GN_LAST_ACTIVE] = time();

require_once __DIR__ . '/../ots/session_handler.php';

if (!isset($_SESSION[GC_LOGIN_COOKIE])) {
    header('Location: login.php');
    exit;
}

// load common auth helpers and build user context
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/session.php';
build_user_context_from_ots();
$accessible_church_ids = get_accessible_church_ids();

$session_remaining = ensure_revizor_session_timeout();

log_activity('page_view', ['page' => 'search']);

$conn = get_revizor_conn();
$ots_db = get_ots_conn();

// Kiadás típusok meghatározása
$exp_types = [];
@include_once(__DIR__ . "/../constant.php");
if (defined('GN_TRANSACTION_TYPE_PAYMENT')) $exp_types[] = GN_TRANSACTION_TYPE_PAYMENT;
if (defined('GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE')) $exp_types[] = GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE;
if (defined('GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION')) $exp_types[] = GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION;
if (empty($exp_types)) {
    $tt_res = $ots_db->query("SELECT id, NAME FROM TRANSACTION_TYPE");
    if ($tt_res) {
        while ($tt = $tt_res->fetch_assoc()) {
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

// Church list - for admin dropdown
$churches = [];
if (is_admin()) {
    $ch_res = $ots_db->query("SELECT id, name FROM CHURCHES WHERE name IS NOT NULL AND name != '' ORDER BY name ASC");
    if ($ch_res && $ch_res->num_rows > 0) {
        while ($ch = $ch_res->fetch_assoc()) { $churches[] = $ch; }
    } else {
        // Fallback: konfigból
        $cfg = load_app_config();
        if (!empty($cfg['churches']) && is_array($cfg['churches'])) {
            foreach ($cfg['churches'] as $id => $name) {
                $churches[] = ['id' => $id, 'name' => $name];
            }
        }
    }
}

// Church name map a már betöltött listából
$search_church_names = [];
foreach ($churches as $sc) {
    $search_church_names[$sc['id']] = $sc['name'];
}

// Search params
$source = isset($_GET['source']) ? $_GET['source'] : 'bank';
$source_whitelist = ['bank', 'ots', 'both'];
if (!in_array($source, $source_whitelist, true)) {
    $source = 'bank';
}
if (is_admin()) {
    $church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
} else {
    $church_id = require_selected_church('search.php');
}
// if a church is requested, ensure the user has access
if ($church_id > 0) {
    require_church_access($church_id);
}
$amount_min = isset($_GET['amount_min']) && $_GET['amount_min'] !== '' ? floatval($_GET['amount_min']) : null;
$amount_max = isset($_GET['amount_max']) && $_GET['amount_max'] !== '' ? floatval($_GET['amount_max']) : null;
$date_from_input = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to_input = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$description = isset($_GET['description']) ? trim($_GET['description']) : '';
$doc_number = isset($_GET['doc_number']) ? trim($_GET['doc_number']) : '';
$flow = isset($_GET['flow']) ? $_GET['flow'] : 'bank';
$flow_whitelist = ['bank', 'cash', 'both'];
if (!in_array($flow, $flow_whitelist, true)) {
    $flow = 'bank';
}
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : 'all';
$status_whitelist = ['all', 'matched', 'unmatched'];
if (!in_array($status_filter, $status_whitelist, true)) {
    $status_filter = 'all';
}
$transfer_search = isset($_GET['transfer']) && $_GET['transfer'] === '1';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = $transfer_search ? 99999 : 50;
$offset = ($page - 1) * $per_page;
$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$exact_word = isset($_GET['exact_word']) && $_GET['exact_word'] === '1';

function normalize_search_date($value) {
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if ($dt instanceof DateTime && $dt->format('Y-m-d') === $value) {
        return $value;
    }
    return '';
}

$date_from = normalize_search_date($date_from_input);
$date_to = normalize_search_date($date_to_input);
$has_search = $church_id > 0 || $amount_min !== null || $amount_max !== null || $date_from !== '' || $date_to !== '' || $description !== '' || $doc_number !== '' || $transfer_search;

$results = [];
$total = 0;
$query_time = 0;
$error_msg = '';

if ($has_search) {
try {
    $start_time = microtime(true);

    if (!$transfer_search && ($source === 'bank' || $source === 'both')) {
        $b_where = [];
        $b_params = [];
        $b_types = '';

        if ($church_id > 0) {
            $b_where[] = 'br.church_id = ?';
            $b_params[] = $church_id;
            $b_types .= 'i';
        } elseif (!is_admin()) {
            if (empty($accessible_church_ids)) {
                $b_where[] = '1=0';
            } else {
                append_int_in_clause($b_where, $b_params, $b_types, 'br.church_id', $accessible_church_ids);
            }
        }
        if ($amount_min !== null) {
            $b_where[] = 'br.bank_amount >= ?';
            $b_params[] = floatval($amount_min);
            $b_types .= 'd';
        }
        if ($amount_max !== null) {
            $b_where[] = 'br.bank_amount <= ?';
            $b_params[] = floatval($amount_max);
            $b_types .= 'd';
        }
        if ($date_from) {
            $b_where[] = 'br.bank_date >= ?';
            $b_params[] = $date_from;
            $b_types .= 's';
        }
        if ($date_to) {
            $b_where[] = 'br.bank_date <= ?';
            $b_params[] = $date_to;
            $b_types .= 's';
        }
        if ($description) {
            if ($exact_word) {
                $b_where[] = "(br.bank_desc REGEXP ? OR br.bank_ext_name REGEXP ? OR br.bank_init_name REGEXP ? OR br.bank_ben_name REGEXP ?)";
                $desc_re = preg_quote($description, '/');
                for ($i = 0; $i < 4; $i++) { $b_params[] = "[[:<:]]{$desc_re}[[:>:]]"; $b_types .= 's'; }
            } else {
                $b_where[] = "(br.bank_desc LIKE ? OR br.bank_ext_name LIKE ? OR br.bank_init_name LIKE ? OR br.bank_ben_name LIKE ?)";
                for ($i = 0; $i < 4; $i++) { $b_params[] = "%$description%"; $b_types .= 's'; }
            }
        }
        if ($doc_number) {
            $b_where[] = "(br.ots_doc LIKE ? OR br.bank_ext_ref LIKE ?)";
            $b_params[] = "%$doc_number%"; $b_types .= 's';
            $b_params[] = "%$doc_number%"; $b_types .= 's';
        }
        if ($status_filter === 'matched') {
            $b_where[] = "(br.ots_record_id IS NOT NULL OR br.id IN (SELECT reconciliation_id FROM bank_reconciliation_items))";
        } elseif ($status_filter === 'unmatched') {
            $b_where[] = "br.ots_record_id IS NULL AND br.id NOT IN (SELECT reconciliation_id FROM bank_reconciliation_items)";
        }

        $b_where_sql = $b_where ? 'WHERE ' . implode(' AND ', $b_where) : '';

        // Dedup subquery: collapse rows with same church_id, bank_date, bank_amount, bank_desc
        $dedup_sub = "INNER JOIN (SELECT MIN(id) AS dedup_id FROM bank_reconciliation GROUP BY church_id, bank_date, bank_amount, bank_desc) d ON br.id = d.dedup_id";

        // Count
        if (!empty($b_params)) {
            $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM bank_reconciliation br $dedup_sub $b_where_sql");
            if ($stmt) {
                $stmt->bind_param($b_types, ...$b_params);
                $stmt->execute();
                $count_res = $stmt->get_result();
            } else { $count_res = false; }
        } else {
            $count_res = $conn->query("SELECT COUNT(*) as cnt FROM bank_reconciliation br $dedup_sub $b_where_sql");
        }
        if ($count_res) {
            $total += intval($count_res->fetch_assoc()['cnt']);
        }

        if (!$export) {
            $b_sql = "SELECT br.* FROM bank_reconciliation br $dedup_sub $b_where_sql ORDER BY br.bank_date DESC LIMIT $per_page OFFSET $offset";
            } else {
            $b_sql = "SELECT br.* FROM bank_reconciliation br $dedup_sub $b_where_sql ORDER BY br.bank_date DESC";
        }
        if (!empty($b_params)) {
            $stmt = $conn->prepare($b_sql);
            if ($stmt) {
                $stmt->bind_param($b_types, ...$b_params);
                $stmt->execute();
                $b_res = $stmt->get_result();
            } else { $b_res = false; }
        } else {
            $b_res = $conn->query($b_sql);
        }
        $paired_bank_ids = [];
        $pb_res = $conn->query("SELECT DISTINCT reconciliation_id FROM bank_reconciliation_items");
        if ($pb_res) {
            while ($pb = $pb_res->fetch_assoc()) {
                $paired_bank_ids[] = intval($pb['reconciliation_id']);
            }
        }
        $paired_bank_map = array_flip($paired_bank_ids);
        if ($b_res) {
            while ($row = $b_res->fetch_assoc()) {
                $row['church_name'] = $search_church_names[$row['church_id']] ?? null;
                $row['_source'] = 'Bank';
                $row['_is_paired'] = !empty($row['ots_record_id']) || isset($paired_bank_map[intval($row['id'])]);
                $results[] = $row;
            }
        }
    }

    if (!$transfer_search && ($source === 'ots' || $source === 'both')) {
        $adjusted_sql = "IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)";

        $o_where = ["T.CHURCH_ID > 0"];
        $o_params = [];
        $o_types = '';

        if ($church_id > 0) {
            $o_where[] = 'T.CHURCH_ID = ?';
            $o_params[] = $church_id;
            $o_types .= 'i';
        } elseif (!is_admin()) {
            if (empty($accessible_church_ids)) {
                $o_where[] = '1=0';
            } else {
                append_int_in_clause($o_where, $o_params, $o_types, 'T.CHURCH_ID', $accessible_church_ids);
            }
        }
        if ($date_from) {
            $o_where[] = 'T.DATETIME >= ?';
            $o_params[] = $date_from;
            $o_types .= 's';
        }
        if ($date_to) {
            $o_where[] = 'T.DATETIME <= ?';
            $o_params[] = $date_to . ' 23:59:59';
            $o_types .= 's';
        }
        if ($doc_number) {
            $o_where[] = "(T.CASH_DOCUMENT_NUMBER LIKE ? OR T.DECISION_NUMBER LIKE ?)";
            $o_params[] = "%$doc_number%";
            $o_types .= 's';
            $o_params[] = "%$doc_number%";
            $o_types .= 's';
        }

        // Flow filter (VIA_BANK)
        if ($flow === 'bank') {
            $o_where[] = 'T.VIA_BANK <> 0';
        } elseif ($flow === 'cash') {
            $o_where[] = 'T.VIA_BANK = 0';
        }

        // When source=both, exclude already-paired OTS records to avoid duplicates
        if ($source === 'both') {
            $o_where[] = "(T.RECORD_ID NOT IN (SELECT ots_record_id FROM revizor_db.bank_reconciliation WHERE ots_record_id IS NOT NULL) AND T.RECORD_ID NOT IN (SELECT record_id FROM revizor_db.bank_reconciliation_items))";
        }

        // Amount filter on adjusted_amount
        $o_having = [];
        $o_having_params = [];
        $o_having_types = '';
        if ($amount_min !== null) {
            $o_having[] = 'adjusted_amount >= ?';
            $o_having_params[] = floatval($amount_min);
            $o_having_types .= 'd';
        }
        if ($amount_max !== null) {
            $o_having[] = 'adjusted_amount <= ?';
            $o_having_params[] = floatval($amount_max);
            $o_having_types .= 'd';
        }

        // Description filter (PERSONS.NAME, NAMES_OF_TRANSACTION.NAME)
        if ($description) {
            if ($exact_word) {
                $desc_re = '[[:<:]]' . preg_quote($description, '/') . '[[:>:]]';
                $o_where[] = "(p.NAME REGEXP ? OR p.NAME_PREFIX REGEXP ? OR p.NAME_SUFFIX REGEXP ? OR nt1.NAME REGEXP ? OR nt2.NAME REGEXP ? OR f.NAME REGEXP ?)";
                for ($i = 0; $i < 6; $i++) {
                    $o_params[] = $desc_re;
                    $o_types .= 's';
                }
            } else {
                $o_where[] = "(p.NAME LIKE ? OR p.NAME_PREFIX LIKE ? OR p.NAME_SUFFIX LIKE ? OR nt1.NAME LIKE ? OR nt2.NAME LIKE ? OR f.NAME LIKE ?)";
                for ($i = 0; $i < 6; $i++) {
                    $o_params[] = "%$description%";
                    $o_types .= 's';
                }
            }
        }

        $o_where_sql = implode(' AND ', $o_where);
        $o_having_sql = $o_having ? 'HAVING ' . implode(' AND ', $o_having) : '';

        $base_joins = "FROM TRANSACTIONS T
                 LEFT JOIN PERSONS p ON T.PERSON_ID = p.id
                 LEFT JOIN NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
                 LEFT JOIN NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
                 LEFT JOIN TRANSACTION_TYPE tt ON T.TYPE = tt.id
                 LEFT JOIN USERS u ON T.EDITED_BY = u.id
                 LEFT JOIN FUNDS f ON T.FUND_ID = f.id";

        // Count for OTS
        if ($source !== 'both') {
            $o_count_sql = "SELECT COUNT(*) as cnt FROM (SELECT T.RECORD_ID, $adjusted_sql AS adjusted_amount $base_joins WHERE $o_where_sql GROUP BY T.RECORD_ID $o_having_sql) sub";
            $count_params = array_merge($o_params, $o_having_params);
            $count_types = $o_types . $o_having_types;
            if (!empty($count_params)) {
                $stmt = $ots_db->prepare($o_count_sql);
                if ($stmt) {
                    $stmt->bind_param($count_types, ...$count_params);
                    $stmt->execute();
                    $count_res = $stmt->get_result();
                } else {
                    $count_res = false;
                }
            } else {
                $count_res = $ots_db->query($o_count_sql);
            }
            if ($count_res) {
                $total += intval($count_res->fetch_assoc()['cnt']);
            }
        }

        $select_cols = "T.RECORD_ID, T.CHURCH_ID, T.CASH_DOCUMENT_NUMBER, T.DECISION_NUMBER, T.DATETIME,
                        $adjusted_sql AS adjusted_amount,
                        TRIM(CONCAT(IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''),
                            ' ', IFNULL(nt1.NAME, ''), ' ', IFNULL(nt2.NAME, ''))) AS ots_desc_full,
                        tt.NAME AS ots_type_name, u.NAME AS ots_editor_name,
                        f.NAME AS fund_name, T.CHURCH_ID,
                        IF(T.VIA_BANK <> 0, 'Bank', 'Készpénz') AS flow_label,
                        T.VIA_BANK";

        if (!$export) {
            $o_sql = "SELECT $select_cols $base_joins WHERE $o_where_sql GROUP BY T.RECORD_ID $o_having_sql ORDER BY T.DATETIME DESC LIMIT $per_page OFFSET $offset";
        } else {
            $o_sql = "SELECT $select_cols $base_joins WHERE $o_where_sql GROUP BY T.RECORD_ID $o_having_sql ORDER BY T.DATETIME DESC";
        }
        $query_params = array_merge($o_params, $o_having_params);
        $query_types = $o_types . $o_having_types;
        if (!empty($query_params)) {
            $stmt = $ots_db->prepare($o_sql);
            if ($stmt) {
                $stmt->bind_param($query_types, ...$query_params);
                $stmt->execute();
                $o_res = $stmt->get_result();
            } else {
                $o_res = false;
            }
        } else {
            $o_res = $ots_db->query($o_sql);
        }
        // Párosított OTS record_id-k lekérése
        $paired_ots_ids = [];
        $pair_res = $conn->query("SELECT DISTINCT ots_record_id FROM bank_reconciliation WHERE ots_record_id IS NOT NULL UNION SELECT DISTINCT record_id FROM bank_reconciliation_items");
        if ($pair_res) {
            while ($p = $pair_res->fetch_assoc()) {
                $paired_ots_ids[] = $p['ots_record_id'] ?? $p['record_id'];
            }
        }
        $paired_map = array_flip($paired_ots_ids);

        if ($o_res) {
            while ($row = $o_res->fetch_assoc()) {
                $row['_source'] = 'OTS';
                $row['bank_amount'] = $row['adjusted_amount'];
                $row['bank_date'] = $row['DATETIME'] ? substr($row['DATETIME'], 0, 10) : '';
                $row['bank_desc'] = $row['ots_desc_full'];
                $row['status'] = '';
                $row['_is_paired'] = isset($paired_map[$row['RECORD_ID']]);
                $row['church_name'] = $search_church_names[$row['CHURCH_ID']] ?? null;
                $results[] = $row;
            }
        }
    }

    // Sort combined results by date desc
    if ($source === 'both') {
        usort($results, function ($a, $b) {
            $da = $a['bank_date'] ?? '';
            $db = $b['bank_date'] ?? '';
            return strcmp($db, $da);
        });
    }

    // === Transfer search: find bank-cash pairs with matching absolute amount ===
    if ($transfer_search) {
        $tr_where = ["1=1"];
        $tr_params = [];
        $tr_types = '';
        if ($church_id > 0) {
            $tr_where[] = 'T.CHURCH_ID = ?';
            $tr_params[] = $church_id;
            $tr_types .= 'i';
        } elseif (!is_admin()) {
            if (empty($accessible_church_ids)) {
                $tr_where[] = '1=0';
            } else {
                append_int_in_clause($tr_where, $tr_params, $tr_types, 'T.CHURCH_ID', $accessible_church_ids);
            }
        }
        if ($date_from) {
            $tr_where[] = 'T.DATETIME >= ?';
            $tr_params[] = $date_from;
            $tr_types .= 's';
        }
        if ($date_to) {
            $tr_where[] = 'T.DATETIME <= ?';
            $tr_params[] = $date_to . ' 23:59:59';
            $tr_types .= 's';
        }
        // Amount filter applied at bucket level below

        $tr_where_sql = implode(' AND ', $tr_where);
        $adj_sql = "IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)";

        // Find RECORD_ID groups that have both bank and cash transactions with matching ABS(adjusted_amount) and date
        $tr_group_sql = "SELECT T.RECORD_ID, T.CHURCH_ID, T.VIA_BANK, $adj_sql AS adjusted_amount, DATE(T.DATETIME) AS tx_date
                 FROM TRANSACTIONS T
                 WHERE $tr_where_sql
                 HAVING adjusted_amount <> 0";
        $tr_group_stmt = $ots_db->prepare($tr_group_sql);
        if ($tr_group_stmt) {
            if (!empty($tr_params)) {
                $tr_group_stmt->bind_param($tr_types, ...$tr_params);
            }
            $tr_group_stmt->execute();
            $tr_group_res = $tr_group_stmt->get_result();
        } else {
            $tr_group_res = false;
        }

        // Group by (church_id, ABS(adjusted_amount), tx_date) and find pairs
        $tr_buckets = [];
        if ($tr_group_res) {
            while ($tr = $tr_group_res->fetch_assoc()) {
                $key = intval($tr['CHURCH_ID']) . '_' . abs(floatval($tr['adjusted_amount'])) . '_' . ($tr['tx_date'] ?? '');
                if (!isset($tr_buckets[$key])) {
                    $tr_buckets[$key] = ['bank_ids' => [], 'cash_ids' => [], 'abs_amount' => abs(floatval($tr['adjusted_amount'])), 'church_id' => intval($tr['CHURCH_ID']), 'tx_date' => $tr['tx_date']];
                }
                if ($tr['VIA_BANK'] != 0) {
                    $tr_buckets[$key]['bank_ids'][] = intval($tr['RECORD_ID']);
                } else {
                    $tr_buckets[$key]['cash_ids'][] = intval($tr['RECORD_ID']);
                }
            }
        }

        // Filter to only buckets with both bank and cash, and apply amount filter
        $matched_record_ids = [];
        foreach ($tr_buckets as $bk => $bv) {
            if (!empty($bv['bank_ids']) && !empty($bv['cash_ids'])) {
                // Apply amount filter
                if ($amount_min !== null && $bv['abs_amount'] < abs(floatval($amount_min))) continue;
                if ($amount_max !== null && $bv['abs_amount'] > abs(floatval($amount_max))) continue;
                foreach ($bv['bank_ids'] as $bid) { $matched_record_ids[] = $bid; }
                foreach ($bv['cash_ids'] as $cid) { $matched_record_ids[] = $cid; }
            }
        }
        $matched_record_ids = array_unique($matched_record_ids);

        if (!empty($matched_record_ids)) {
            $id_list = implode(',', $matched_record_ids);
            $tr_detail_sql = "SELECT T.RECORD_ID, T.CHURCH_ID, T.VIA_BANK, $adj_sql AS adjusted_amount,
                    T.DATETIME, T.CASH_DOCUMENT_NUMBER, T.DECISION_NUMBER,
                    TRIM(CONCAT(
                        IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''),
                        ' ', IFNULL(nt1.NAME, ''), ' ', IFNULL(nt2.NAME, '')
                    )) AS ots_desc_full,
                    tt.NAME AS ots_type_name,
                    u.NAME AS ots_editor_name,
                    IF(T.VIA_BANK <> 0, 'Bank', 'Készpénz') AS flow_label
             FROM TRANSACTIONS T
             LEFT JOIN PERSONS p ON T.PERSON_ID = p.id
             LEFT JOIN NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
             LEFT JOIN NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
             LEFT JOIN TRANSACTION_TYPE tt ON T.TYPE = tt.id
             LEFT JOIN USERS u ON T.EDITED_BY = u.id
             WHERE T.RECORD_ID IN ($id_list)
             ORDER BY T.DATETIME ASC, T.RECORD_ID ASC";
            $tr_detail_res = $ots_db->query($tr_detail_sql);
            if ($tr_detail_res) {
                // Build lookup map for pair info
                $pair_map = [];
                foreach ($tr_buckets as $bv) {
                    if (!empty($bv['bank_ids']) && !empty($bv['cash_ids'])) {
                        foreach ($bv['bank_ids'] as $bid) { $pair_map[$bid] = $bv; }
                        foreach ($bv['cash_ids'] as $cid) { $pair_map[$cid] = $bv; }
                    }
                }
                while ($tr = $tr_detail_res->fetch_assoc()) {
                    $tr['_source'] = 'OTS';
                    $tr['bank_amount'] = $tr['adjusted_amount'];
                    $tr['bank_date'] = $tr['DATETIME'] ? substr($tr['DATETIME'], 0, 10) : '';
                    $tr['bank_desc'] = $tr['ots_desc_full'];
                    $tr['status'] = '';
                    $tr['_is_paired'] = false;
                    $tr['church_name'] = $search_church_names[$tr['CHURCH_ID']] ?? null;
                    // Add pair info
                    $rid = intval($tr['RECORD_ID']);
                    $tr['_transfer_abs_amount'] = $pair_map[$rid]['abs_amount'] ?? 0;
                    $tr['_transfer_partner_ids'] = ($tr['VIA_BANK'] != 0)
                        ? ($pair_map[$rid]['cash_ids'] ?? [])
                        : ($pair_map[$rid]['bank_ids'] ?? []);
                    $results[] = $tr;
                }
            }
        }

        // Sort transfer results by date, then pair
        usort($results, function ($a, $b) {
            $da = $a['bank_date'] ?? '';
            $db = $b['bank_date'] ?? '';
            if ($da !== $db) return strcmp($db ?: '', $da ?: '');
            $aa = abs(floatval($a['adjusted_amount'] ?? 0));
            $ab = abs(floatval($b['adjusted_amount'] ?? 0));
            return $ab - $aa;
        });

        $total = count($results);
    }

    $query_time = round((microtime(true) - $start_time) * 1000);
} catch (Exception $e) {
    $error_msg = 'Lekérdezési hiba: ' . $e->getMessage();
    $query_time = round((microtime(true) - $start_time) * 1000);
}
}

// === EXPORT CSV ===
if ($export && $has_search && !$transfer_search) {
    function export_csv_safe_cell($value) {
        $value = (string)$value;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value);
        if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
            $value = "'" . $value;
        }
        return $value;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tranzakcio_kereses.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM

    if ($source === 'bank' || $source === 'both') {
        fputcsv($out, array_map('export_csv_safe_cell', ['Forrás', 'Dátum', 'Összeg', 'Közlemény', 'Gyülekezet', 'Kezdeményező', 'Státusz', 'OTS Bizonylat', 'Banki azonosító']));
        foreach ($results as $r) {
            if ($r['_source'] !== 'Bank') continue;
            fputcsv($out, array_map('export_csv_safe_cell', [
                'Bank',
                $r['bank_date'] ?? '',
                number_format(floatval($r['bank_amount'] ?? 0), 0, ',', ' ') . ' Ft',
                $r['bank_desc'] ?? '',
                $r['church_name'] ?? '',
                $r['bank_ext_name'] ?? '',
                $r['status'] ?? '',
                $r['ots_doc'] ?? '',
                $r['bank_ext_ref'] ?? '',
            ]));
        }
    }
    if ($source === 'ots' || $source === 'both') {
        if ($source === 'both') {
            fputcsv($out, []);
        }
        fputcsv($out, array_map('export_csv_safe_cell', ['Forrás', 'Dátum', 'Összeg', 'Leírás', 'Gyülekezet', 'Forgalom', 'Típus', 'Bizonylatszám', 'Határozati szám']));
        foreach ($results as $r) {
            if ($r['_source'] !== 'OTS') continue;
            fputcsv($out, array_map('export_csv_safe_cell', [
                'OTS',
                $r['bank_date'] ?? '',
                number_format(floatval($r['adjusted_amount'] ?? 0), 0, ',', ' ') . ' Ft',
                $r['ots_desc_full'] ?? '',
                $r['church_name'] ?? '',
                $r['flow_label'] ?? '',
                $r['ots_type_name'] ?? '',
                $r['CASH_DOCUMENT_NUMBER'] ?? '',
                $r['DECISION_NUMBER'] ?? '',
            ]));
        }
    }
    fclose($out);
    exit;
}

// === HTML ===
$source_options = ['bank' => 'Bank', 'ots' => 'OTS', 'both' => 'Mindkettő'];
$flow_options = ['bank' => 'Bank', 'cash' => 'Készpénz', 'both' => 'Mindkettő'];
$status_options = ['all' => 'Mind', 'matched' => 'Párosított', 'unmatched' => 'Párosítatlan'];

$total_pages = $total > 0 ? ceil($total / $per_page) : 0;
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>🕵️ Revizor Asszisztens 1.0 – Tranzakció Kereső</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; padding: 15px; font-size: 14px; }
        .card { box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        .table th { white-space: nowrap; background: #e9ecef; }
        .result-count { font-size: 13px; }
        .status-OK { color: #198754; font-weight: bold; }
        .status-UNCHECKED { color: #6c757d; }
        .status-HIÁNY, .status-ELTÉRÉS { color: #dc3545; }
        .status-ÖSSZEVONT { color: #0d6efd; }
        @media print { .card-header .btn, .pagination { display: none; } }
        .sort-asc::after { content: " ▲"; font-size: 10px; }
        .sort-desc::after { content: " ▼"; font-size: 10px; }
        th[onclick] { cursor: pointer; user-select: none; }
        th[onclick]:hover { background: #d0d5dd !important; }
    </style>
</head>
<body>

<div class="container-fluid" style="max-width:1400px;">

    <div class="d-flex justify-content-between align-items-center mb-3 px-3 py-2 bg-white rounded border shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">🏠 Kezdőlap</a>
            <span class="fw-bold">🕵️ Revizor Asszisztens 1.0</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted">Tranzakció Kereső</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <a href="help.php" class="btn btn-outline-primary btn-sm">❓ Súgó</a>
            <?php render_dev_toggle(); ?>
            <?php render_user_badge(); ?>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Kilépés</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-dark text-white py-2">
            <h5 class="mb-0">🔍 Tranzakció Kereső</h5>
        </div>
    <div class="card-body">
        <form method="GET" action="search.php" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-0">Forrás</label>
                <select name="source" class="form-select form-select-sm">
                    <?php foreach ($source_options as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $source === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-0">Gyülekezet</label>
                <?php if (is_admin()): ?>
                <select name="church_id" class="form-select form-select-sm">
                    <option value="0">Összes</option>
                    <?php foreach ($churches as $ch): ?>
                    <option value="<?= $ch['id'] ?>" <?= $church_id === intval($ch['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="hidden" name="church_id" value="<?= $church_id ?>">
                <span class="form-control form-control-sm bg-light" style="width:100%;display:inline-block;border:1px solid #dee2e6;padding:4px 8px;border-radius:4px;">
                    🏛 <?php
                        $ch_name = '';
                        if ($church_id > 0) {
                            // Konfigból próbáljuk
                            $cfg = load_app_config();
                            if (!empty($cfg['churches'][$church_id])) {
                                $ch_name = $cfg['churches'][$church_id];
                            } else {
                                $nstmt = $ots_db->prepare("SELECT name FROM CHURCHES WHERE id = ?");
                                if ($nstmt) { $nstmt->bind_param('i', $church_id); $nstmt->execute(); $nres = $nstmt->get_result(); if ($nres && $nr = $nres->fetch_assoc()) { $ch_name = $nr['name']; } }
                            }
                        }
                        echo htmlspecialchars($ch_name ?: '#' . $church_id);
                    ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Összeg (min)</label>
                <input type="number" name="amount_min" class="form-control form-control-sm" value="<?= $amount_min !== null ? htmlspecialchars($amount_min) : '' ?>" step="1" placeholder="pl. 1000">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Összeg (max)</label>
                <input type="number" name="amount_max" class="form-control form-control-sm" value="<?= $amount_max !== null ? htmlspecialchars($amount_max) : '' ?>" step="1" placeholder="pl. 500000">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-0">Közlemény / Leírás</label>
                <div class="d-flex gap-1">
                    <input type="text" name="description" class="form-control form-control-sm" value="<?= htmlspecialchars($description) ?>" placeholder="pl. tized, adomány" style="flex:1;">
                    <div class="form-check form-check-inline d-flex align-items-center mt-1">
                        <input class="form-check-input" type="checkbox" id="exact_word" name="exact_word" value="1" <?= $exact_word ? 'checked' : '' ?> style="margin-top:0;">
                        <label class="form-check-label small text-nowrap ms-1" for="exact_word" title="Csak önálló szóként keres (pl. 'könyv' nem talál 'könyvelési'-re)">🔤</label>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Dátum tól</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Dátum ig</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Bizonylatszám</label>
                <input type="text" name="doc_number" class="form-control form-control-sm" value="<?= htmlspecialchars($doc_number) ?>" placeholder="OTS bizonylat">
            </div>
            <div class="col-md-2" id="flow_col">
                <label class="form-label small mb-0">Forgalom (OTS)</label>
                <select name="flow" class="form-select form-select-sm">
                    <?php foreach ($flow_options as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $flow === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2" id="status_col">
                <label class="form-label small mb-0">Státusz (Bank)</label>
                <select name="status_filter" class="form-select form-select-sm">
                    <?php foreach ($status_options as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $status_filter === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end pb-1">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="transfer" id="transfer_chk" value="1" <?= $transfer_search ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="transfer_chk" title="Keresés a banki és készpénzes párok között azonos abszolút összeg alapján">🔁 Átvezetés</label>
                </div>
            </div>
            <div class="col-md-12 mt-2">
                <button type="submit" class="btn btn-primary btn-sm">🔎 Keresés</button>
                <a href="search.php" class="btn btn-outline-secondary btn-sm">✕ Szűrők törlése</a>
                <?php if ($has_search && $total > 0): ?>
                <a href="search.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv', 'page' => 1])) ?>" class="btn btn-success btn-sm">📥 Excel export</a>
                <?php endif; ?>
                <span class="text-muted small ms-2" id="query_info"></span>
            </div>
        </form>
    </div>
</div>

<script>
function updateFilterVisibility() {
    var src = document.querySelector('[name="source"]').value;
    var tr = document.getElementById('transfer_chk').checked;
    document.getElementById('flow_col').style.display = (src === 'bank' || tr) ? 'none' : '';
    document.getElementById('status_col').style.display = (src === 'ots' || tr) ? 'none' : '';
    document.querySelector('[name="source"]').closest('.col-md-2').style.display = tr ? 'none' : '';
}
document.querySelector('[name="source"]').addEventListener('change', updateFilterVisibility);
document.getElementById('transfer_chk').addEventListener('change', updateFilterVisibility);
updateFilterVisibility();
document.getElementById('query_info').textContent = '<?= $has_search ? ($error_msg ? "Hiba" : "Lekérdezés ideje: {$query_time} ms") : "" ?>';
</script>

<?php if ($has_search): ?>
<div class="card">
    <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
        <span class="fw-bold result-count">Találatok: <span class="text-primary"><?= number_format($total, 0, ',', ' ') ?></span> db<?= $total_pages > 0 ? " ({$page}/{$total_pages} oldal)" : '' ?></span>
    </div>
    <div class="card-body p-0">
        <?php if ($error_msg): ?>
        <div class="alert alert-danger m-3"><?= htmlspecialchars($error_msg) ?></div>
        <?php elseif (empty($results)): ?>
        <div class="alert alert-warning m-3">Nincs találat a megadott feltételekkel.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-bordered mb-0" style="font-size:13px;">
                <thead>
                    <tr>
                        <th onclick="sortTableBy(this)">#</th>
                        <th></th>
                        <?php if ($church_id === 0): ?><th onclick="sortTableBy(this)" data-sort-type="string">Gyülekezet</th><?php endif; ?>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Forrás</th>
                        <th onclick="sortTableBy(this)" data-sort-type="date">Dátum</th>
                        <th onclick="sortTableBy(this)" data-sort-type="number" style="text-align:right;">Összeg</th>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Közlemény / Leírás</th>
                        <?php if ($source === 'bank' || $source === 'both' || $transfer_search): ?>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Státusz</th>
                        <th onclick="sortTableBy(this)" data-sort-type="string">OTS bizonylat</th>
                        <?php endif; ?>
                        <?php if ($source === 'ots' || $source === 'both' || $transfer_search): ?>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Forgalom</th>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Típus</th>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Bizonylatszám</th>
                        <?php endif; ?>
                        <?php if ($transfer_search): ?>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Pár</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = $offset + 1; ?>
                    <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td>
                            <?php if ($r['_source'] === 'Bank'): ?>
                                <a href="reconciliation.php?bank_id=<?= intval($r['id']) ?>" class="btn btn-outline-primary btn-sm py-0 px-1" title="Egyeztetés">⚡</a>
                            <?php elseif ($r['_source'] === 'OTS'): ?>
                                <a href="all_transactions/all_transactions_multi.php?record_id=<?= intval($r['RECORD_ID']) ?>&church_id=<?= intval($r['CHURCH_ID']) ?>" class="btn btn-outline-secondary btn-sm py-0 px-1" target="_blank" title="OTS megnyitása">🔗</a>
                            <?php endif; ?>
                        </td>
                        <?php if ($church_id === 0): ?><td><?= htmlspecialchars($r['church_name'] ?? '-') ?></td><?php endif; ?>
                        <td>
                            <span class="badge bg-<?= $r['_source'] === 'Bank' ? 'primary' : 'secondary' ?>"><?= $r['_source'] ?></span>
                            <?php if ($r['_source'] === 'OTS'): ?>
                                <?php if ($r['_is_paired'] ?? false): ?>
                                    <span class="badge bg-success ms-1" title="Párosítva">✅</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted ms-1" title="Nincs párosítva">⚪</span>
                                <?php endif; ?>
                            <?php elseif ($r['_source'] === 'Bank'): ?>
                                <?php if ($r['_is_paired']): ?>
                                    <span class="badge bg-success ms-1" title="<?= !empty($r['ots_record_id']) ? 'OTS #' . intval($r['ots_record_id']) . ' párosítva' : 'Párosítva (több tétel)' ?>">✅</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted ms-1" title="Nincs párosítva">⚪</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['bank_date'] ?? '') ?></td>
                        <td style="text-align:right; white-space:nowrap;" class="<?= (floatval($r['bank_amount'] ?? 0) < 0) ? 'text-danger' : 'text-success' ?> fw-bold">
                            <?= number_format(floatval($r['bank_amount'] ?? 0), 0, ',', ' ') ?> Ft
                        </td>
                        <td><?= htmlspecialchars(mb_substr($r['bank_desc'] ?? '-', 0, 120)) ?></td>

                        <?php if ($source === 'bank' || $source === 'both' || $transfer_search): ?>
                        <td>
                            <?php if ($r['_source'] === 'Bank'): ?>
                                <?php
                                $st = $r['status'] ?? 'UNCHECKED';
                                $st_labels = ['OK' => 'OK', 'UNCHECKED' => '☐', 'HIÁNY' => 'HIÁNY', 'ELTÉRÉS' => 'ELT', 'ÖSSZEVONT' => 'ÖV', 'CSUSZAS' => 'CSUSZAS'];
                                $st_class = 'status-' . $st;
                                ?>
                                <span class="<?= $st_class ?>"><?= $st_labels[$st] ?? $st ?></span>
                                <?php if (!empty($r['ots_date']) && ($st === 'OK' || $st === 'CSUSZAS')): ?>
                                    <br><small class="text-muted">OTS: <?= $r['ots_date'] ?><br><?= number_format(floatval($r['ots_amount'] ?? 0), 0, ',', ' ') ?> Ft</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['_source'] === 'Bank'): ?>
                                <?php if ($r['_is_paired']): ?>
                                    <?php if (!empty($r['ots_record_id'])): ?>
                                        <a href="all_transactions/all_transactions_multi.php?record_id=<?= intval($r['ots_record_id']) ?>&church_id=<?= intval($r['church_id']) ?>" target="_blank" class="text-decoration-none">
                                            #<?= intval($r['ots_record_id']) ?>
                                        </a>
                                        <?php if (!empty($r['ots_doc'])): ?>
                                            <small class="text-muted d-block"><?= htmlspecialchars($r['ots_doc']) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">✅ Több tétel</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>

                        <?php if ($source === 'ots' || $source === 'both' || $transfer_search): ?>
                        <td>
                            <?php if ($r['_source'] === 'OTS'): ?>
                                <?= htmlspecialchars($r['flow_label'] ?? '-') ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['_source'] === 'OTS'): ?>
                                <?= htmlspecialchars($r['ots_type_name'] ?? '-') ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['_source'] === 'OTS'): ?>
                                <?= htmlspecialchars($r['CASH_DOCUMENT_NUMBER'] ?? '-') ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($transfer_search): ?>
                        <td>
                            <?php
                            $partner_ids = $r['_transfer_partner_ids'] ?? [];
                            if (!empty($partner_ids) && is_array($partner_ids)):
                            ?>
                                <a href="all_transactions/all_transactions_multi.php?record_id=<?= intval($partner_ids[0]) ?>&church_id=<?= intval($r['CHURCH_ID']) ?>" target="_blank" class="text-decoration-none" title="Partner: <?= htmlspecialchars(implode(', ', $partner_ids)) ?>">
                                    🔗 #<?= htmlspecialchars(implode(', ', $partner_ids)) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-center py-2">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">«</a>
                    </li>
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹</a>
                    </li>
                    <?php
                    $start_p = max(1, $page - 2);
                    $end_p = min($total_pages, $page + 2);
                    if ($start_p > 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <?php for ($i = $start_p; $i <= $end_p; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($end_p < $total_pages): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">›</a>
                    </li>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">»</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

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
// Táblázat rendezés
function sortTableBy(el) {
    var table = el.closest('table');
    var tbody = table.querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var col = Array.from(el.parentNode.children).indexOf(el);
    var type = el.getAttribute('data-sort-type') || 'string';
    var dir = el.getAttribute('data-sort-dir') || 'asc';

    rows.sort(function(a, b) {
        var va = a.children[col].textContent.trim();
        var vb = b.children[col].textContent.trim();
        if (type === 'number') {
            va = parseFloat(va.replace(/\s/g, '').replace('Ft', '').replace(',', '.')) || 0;
            vb = parseFloat(vb.replace(/\s/g, '').replace('Ft', '').replace(',', '.')) || 0;
        } else if (type === 'date') {
            va = va.replace(/\./g, '-');
            vb = vb.replace(/\./g, '-');
        }
        if (va < vb) return dir === 'asc' ? -1 : 1;
        if (va > vb) return dir === 'asc' ? 1 : -1;
        return 0;
    });

    el.setAttribute('data-sort-dir', dir === 'asc' ? 'desc' : 'asc');

    // Feltöltés új sorrendben
    rows.forEach(function(row) { tbody.appendChild(row); });

    // Nyilak frissítése
    el.closest('tr').querySelectorAll('th').forEach(function(th) {
        th.classList.remove('sort-asc', 'sort-desc');
    });
    el.classList.add(dir === 'asc' ? 'sort-asc' : 'sort-desc');
}

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

// Poll server every 30s → extends session automatically
setInterval(extendSession, 30000);

// Countdown update every second
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

</body>
</html>
<?php
$conn->close();
$ots_db->close();
?>
