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

// Csak revizor (SDA_L_AUDITOR) vagy admin használhatja a végpontot
if (!is_admin() && !is_revizor()) {
    header('Content-Type: application/json'); echo json_encode(['error' => 'Nincs jogosultságod']); exit;
}

$conn = get_revizor_conn();
if ($conn->connect_error) { header('Content-Type: application/json'); echo json_encode(['error' => 'DB hiba']); exit; }
$conn->set_charset("utf8mb4");

// Segédfüggvények: szombati bizonylat-csoport (3 tétel) + önellenőrzés heti adatainak kinyerése.
// Ugyanazt a heti besorolást követi, mint az OTS Időszaki pénztárjelentője (hónap n-edik szombatja).
function dc_saturday_count_in_month($year, $month) {
    $first = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $last = clone $first;
    $first->modify('first saturday of this month');
    $last->modify('last saturday of this month');
    return $first->diff($last)->days < 23 ? 4 : 5;
}

function dc_nth_saturday($year, $month, $n) {
    static $ordinals = array('first', 'second', 'third', 'fourth', 'fifth');
    $d = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $d->modify($ordinals[$n - 1] . ' saturday of this month');
    return $d->format('Y-m-d');
}

function dc_sabbath_week_index($datetime_str) {
    $dt = DateTime::createFromFormat('Y-m-d', substr((string)$datetime_str, 0, 10));
    if (!$dt || (int)$dt->format('w') !== 6) return null;
    $year = (int)$dt->format('Y');
    $month = (int)$dt->format('n');
    $cnt = dc_saturday_count_in_month($year, $month);
    for ($i = 1; $i <= $cnt; $i++) {
        if (dc_nth_saturday($year, $month, $i) === $dt->format('Y-m-d')) {
            return array('year' => $year, 'month' => $month, 'week' => $i, 'date' => $dt->format('Y-m-d'));
        }
    }
    return null;
}

// Belső átvezetés felismerése a megnevezés alapján: ilyen tételről nem készül
// kiadási/bevételi pénztárbizonylat (csak alapok közötti átcsoportosítás).
function dc_is_transfer($desc) {
    $d = mb_strtolower(trim((string)$desc));
    if ($d === '') { return false; }
    return (bool)preg_match('/(átvezet|alaphoz|alapra|alapba|alapokba)/u', $d);
}

function dc_build_sabbath_group($ots_db, $church_id, $datetime_str) {
    $wk = dc_sabbath_week_index($datetime_str);
    if ($wk === null) return null;
    $day_from = $wk['date'] . ' 00:00:00';
    $day_to = $wk['date'] . ' 23:59:59';

    // Az adott szombati nap készpénz tételei típusonként (ez a papír bizonylat 3 sora)
    $by_type = array();
    $rec_id_by_type = array();
    $stmt = $ots_db->prepare(
        "SELECT TYPE, IFNULL(SUM(AMOUNT), 0) AS TOTAL, MIN(RECORD_ID) AS REC_ID
         FROM TRANSACTIONS
         WHERE CHURCH_ID = ? AND DATETIME BETWEEN ? AND ? AND VIA_BANK = 0 AND IFNULL(VIA_ONLINE_GIVING, 0) = 0 AND AMOUNT > 0
         GROUP BY TYPE");
    if ($stmt) {
        $stmt->bind_param('iss', $church_id, $day_from, $day_to);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($g = $res->fetch_assoc()) {
            $by_type[(int)$g['TYPE']] = (float)$g['TOTAL'];
            $rec_id_by_type[(int)$g['TYPE']] = (int)$g['REC_ID'];
        }
    }

    // Önellenőrzés: heti tizedboríték összeg és bizonylatszám (MONTHLY_CHURCH_DATA)
    $mcd = null;
    $mcd_stmt = $ots_db->prepare(
        "SELECT TITHE_WEEK" . (int)$wk['week'] . " AS TITHE_WEEK,
                TITHE_BANK_WEEK" . (int)$wk['week'] . " AS TITHE_BANK_WEEK,
                TITHE_CASH_DOCUMENT_NUMBER_WEEK" . (int)$wk['week'] . " AS DOC_NUM
         FROM MONTHLY_CHURCH_DATA
         WHERE CHURCH_ID = ? AND YEAR = ? AND MONTH = ?
         LIMIT 1");
    if ($mcd_stmt) {
        $mcd_stmt->bind_param('iii', $church_id, $wk['year'], $wk['month']);
        $mcd_stmt->execute();
        $mcd_res = $mcd_stmt->get_result();
        if ($mcd_res && $mcd_res->num_rows > 0) { $mcd = $mcd_res->fetch_assoc(); }
    }

    // A tized sor az időszaki pénztárjelentő forrásából jön (önellenőrzés TITHE_WEEK),
    // mint a periodic_report_dt.php "Adakozás tizedcéduláról" sora; a kosár sorok a TRANSACTIONS-ból.
    $tithe_from_on = $mcd ? (float)$mcd['TITHE_WEEK'] : null;

    // Adakozási naptár (special target): a szombat délelőtti adakozás automatikus átkönyvelésekor
    // a "Szombat de." kosár helyett ezzel a típussal jelenik meg az adott napra megjelölt cél.
    // Ugyanaz a forrás, mint a periodic_report_dt.php "adakozási naptár" sora.
    $special_target = $by_type[GN_TRANSACTION_TYPE_SPECIAL_TARGET] ?? 0;
    $special_target_purpose = '';
    if ($special_target > 0) {
        $stc_stmt = $ots_db->prepare("SELECT PURPOSE FROM SPECIAL_TARGET_CALENDAR WHERE D_DATE = ? LIMIT 1");
        if ($stc_stmt) {
            $stc_stmt->bind_param('s', $wk['date']);
            $stc_stmt->execute();
            $stc_res = $stc_stmt->get_result();
            if ($stc_res && $stc_res->num_rows > 0) {
                $stc_row = $stc_res->fetch_assoc();
                $special_target_purpose = (string)($stc_row['PURPOSE'] ?? '');
            }
        }
    }

    $group = array(
        'date' => $wk['date'],
        'week' => $wk['week'],
        'saturday_morning' => $by_type[GN_TRANSACTION_TYPE_SATURDAY_MORNING] ?? 0,
        'sabbath_school' => $by_type[GN_TRANSACTION_TYPE_SABBATH_SCHOOL] ?? 0,
        'special_target' => $special_target,
        'special_target_purpose' => $special_target_purpose,
        'tithe_envelope' => $tithe_from_on !== null ? $tithe_from_on : ($by_type[GN_TRANSACTION_TYPE_INCOME] ?? 0),
        'tithe_cash_transactions' => $by_type[GN_TRANSACTION_TYPE_INCOME] ?? 0,
        'onellenorzes' => null,
    );
    // A kosár sorok reprezentatív OTS rekord-azonosítója (a nagyító link célja)
    if (isset($rec_id_by_type[GN_TRANSACTION_TYPE_SATURDAY_MORNING])) {
        $group['saturday_morning_rec_id'] = $rec_id_by_type[GN_TRANSACTION_TYPE_SATURDAY_MORNING];
    }
    if (isset($rec_id_by_type[GN_TRANSACTION_TYPE_SABBATH_SCHOOL])) {
        $group['sabbath_school_rec_id'] = $rec_id_by_type[GN_TRANSACTION_TYPE_SABBATH_SCHOOL];
    }
    if (isset($rec_id_by_type[GN_TRANSACTION_TYPE_SPECIAL_TARGET])) {
        $group['special_target_rec_id'] = $rec_id_by_type[GN_TRANSACTION_TYPE_SPECIAL_TARGET];
    }
    if ($mcd) {
        $group['onellenorzes'] = array(
            'tithe_week' => (float)$mcd['TITHE_WEEK'],
            'tithe_bank_week' => (float)$mcd['TITHE_BANK_WEEK'],
            'doc_number' => (string)$mcd['DOC_NUM'],
        );
    }
    return $group;
}

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

    // OTS rekord lekérése (készpénz): a RECORD_ID-hoz tartozó ÖSSZES sor.
    // Egy rekord több pénzalapra széthúzva is megjelenhet (pl. kiadás több alapról),
    // ezért nem az egyik sort, hanem a teljes csoportot adjuk vissza.
    $adj_sql = "IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)";
    $stmt_ots = $ots_db->prepare("SELECT T.RECORD_ID, T.CHURCH_ID, T.VIA_BANK, T.TYPE AS ots_type, $adj_sql AS bank_amount,
            T.PERSON_ID AS ots_person_id,
            TRIM(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX)) AS ots_person_name,
            T.DATETIME AS bank_date, T.CASH_DOCUMENT_NUMBER AS ots_doc,
            T.DECISION_NUMBER, T.MODIFIED, T.EDITED_BY, T.FUND_ID,
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
     WHERE T.RECORD_ID = ?
     ORDER BY T.TYPE, T.FUND_ID");
    if (!$stmt_ots) { header('Content-Type: application/json'); echo json_encode(['error' => 'DB hiba']); exit; }
    $stmt_ots->bind_param('i', $ots_record_id);
    $stmt_ots->execute();
    $ots_res = $stmt_ots->get_result();
    if ($ots_res->num_rows === 0) { header('Content-Type: application/json'); echo json_encode(['error' => 'Nem található']); exit; }
    $group_rows = [];
    $total_amount = 0.0;
    while ($gr = $ots_res->fetch_assoc()) {
        $group_rows[] = $gr;
        $total_amount += (float)$gr['bank_amount'];
    }
    // Elsődleges sor: a legnagyobb |összeg| (a fejléc és a részlet ehhez kötődik),
    // a bank_amount viszont a teljes csoport összege, hogy a lista és a részlet egyezzen.
    usort($group_rows, function($a, $b) { return abs((float)$b['bank_amount']) <=> abs((float)$a['bank_amount']); });
    $row = $group_rows[0];
    $row['bank_amount'] = $total_amount;
    $row['amount_count'] = count($group_rows);
    $row['show_amount_group'] = count($group_rows) > 1 ? 1 : 0;
    $row['amount_group'] = array_map(function($gr) {
        return array(
            'rec_id' => (int)$gr['RECORD_ID'],
            'fund_id' => isset($gr['FUND_ID']) ? (int)$gr['FUND_ID'] : null,
            'fund_name' => $gr['fund_name'],
            'type_name' => $gr['ots_type_name'],
            'desc' => $gr['bank_desc'],
            'doc' => $gr['ots_doc'],
            'date' => mb_substr((string)$gr['bank_date'], 0, 10),
            'amount' => (float)$gr['bank_amount'],
        );
    }, $group_rows);
    // Üres megnevezésnél a pénzalapok listája (több tétel esetén)
    if (trim((string)$row['bank_desc']) === '') {
        $funds = array();
        foreach ($group_rows as $gr) {
            if (!empty($gr['fund_name']) && !in_array($gr['fund_name'], $funds, true)) { $funds[] = $gr['fund_name']; }
        }
        if (!empty($funds)) { $row['bank_desc'] = implode(', ', $funds); }
    }
    $row['is_transfer'] = dc_is_transfer($row['bank_desc']) ? 1 : 0;
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

    // Szombati bizonylat-csoport: az adott nap 3 tételének összege + önellenőrzés heti adatai
    $row['sabbath_group'] = dc_build_sabbath_group($ots_db, $church_id, $row['bank_date']);

    // A szombat-csoport panel a papír bizonylat soraihoz (kosár, szombatiskola, tized)
    // kötődik: szombati készpénztétel, aminek nincs valódi partnernelve.
    // A neves tizedcédulák (pl. Dantos János) nem jelenítik meg a csoportot.
    $row['show_sabbath_group'] = 0;
    $person_name = mb_strtolower(trim((string)($row['ots_person_name'] ?? '')));
    $anon_names = array('névtelen', 'nevtelen', 'anonim', 'név nélkül', 'név nélkül anonim', 'névtelenül');
    $no_real_partner = empty($row['ots_person_id']) || in_array($person_name, $anon_names, true);
    if ((float)$row['bank_amount'] > 0
        && !empty($row['sabbath_group'])
        && in_array((int)$row['ots_type'], array(GN_TRANSACTION_TYPE_INCOME, GN_TRANSACTION_TYPE_SABBATH_SCHOOL, GN_TRANSACTION_TYPE_SATURDAY_MORNING, GN_TRANSACTION_TYPE_SPECIAL_TARGET), true)
        && $no_real_partner) {
        $row['show_sabbath_group'] = 1;
    }

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

// Tizedcédula-jelleg meghatározása (az audit modálhoz is szükséges, detail nélkül is)
$ots_db = get_ots_conn();
$tithe_related = false;   // van legalább egy tizedcédulás (TYPE=1) OTS tranzakció
$tithe_all_online = true; // az összes tizedcédulás tranzakció online-e
$rec_ids = [];
$stmt_items2 = $conn->prepare("SELECT record_id FROM bank_reconciliation_items WHERE reconciliation_id = ?");
if ($stmt_items2) {
    $stmt_items2->bind_param('i', $bank_id);
    $stmt_items2->execute();
    $items_res2 = $stmt_items2->get_result();
    while ($it = $items_res2->fetch_assoc()) { $rec_ids[] = intval($it['record_id']); }
}
if (empty($rec_ids) && !empty($row['ots_record_id'])) { $rec_ids[] = intval($row['ots_record_id']); }
if (!empty($rec_ids)) {
    $id_ph = implode(',', array_fill(0, count($rec_ids), '?'));
    $t_stmt = $ots_db->prepare("SELECT TYPE, VIA_ONLINE_GIVING FROM TRANSACTIONS WHERE RECORD_ID IN ($id_ph)");
    if ($t_stmt) {
        $t_types = str_repeat('i', count($rec_ids));
        $t_stmt->bind_param($t_types, ...$rec_ids);
        $t_stmt->execute();
        $t_res = $t_stmt->get_result();
        $tithe_any = false;
        while ($o = $t_res->fetch_assoc()) {
            if ((int)$o['TYPE'] === GN_TRANSACTION_TYPE_INCOME) {
                $tithe_any = true;
                if ((int)$o['VIA_ONLINE_GIVING'] !== 1) { $tithe_all_online = false; }
            }
        }
        $tithe_related = $tithe_any;
        if (!$tithe_any) { $tithe_all_online = false; }
    }
}
$row['tithe_ask'] = ($tithe_related && !$tithe_all_online) ? 1 : 0;
$row['tithe_online'] = ($tithe_related && $tithe_all_online) ? 1 : 0;

// Összevont könyvelés: több banki tétel → ugyanaz az OTS tétel
$row['agg_count'] = 0;
$row['agg_group'] = [];
if (!empty($row['ots_record_id'])) {
    $stmt_agg = $conn->prepare("SELECT id, bank_date, bank_amount, bank_desc FROM bank_reconciliation WHERE ots_record_id = ? AND church_id = ? ORDER BY bank_date, id");
    if ($stmt_agg) {
        $stmt_agg->bind_param('ii', $row['ots_record_id'], $church_id);
        $stmt_agg->execute();
        $agg_res = $stmt_agg->get_result();
        while ($ag = $agg_res->fetch_assoc()) { $row['agg_group'][] = $ag; }
        $row['agg_count'] = count($row['agg_group']);
    }
}

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
