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
    header('Location: login.php');
    exit;
}

// Access control: only admin can use upload interface
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/session.php';
if (is_file(__DIR__ . '/lib/announcement.php')) {
    require_once __DIR__ . '/lib/announcement.php';
}
$is_admin = is_admin();


// Revizor Asszisztens session timeout — 20 perc, OTS 10 perces idejét felülírja
$session_remaining = ensure_revizor_session_timeout();
ensure_revizor_csrf_token();

log_activity('page_view', ['page' => 'upload']);

$conn = get_revizor_conn();
$ots_db = get_ots_conn();

$church_options = [];
$church_result = get_ots_conn()->query("SELECT id, name FROM CHURCHES ORDER BY name ASC");
if ($church_result && $church_result->num_rows > 0) {
    while ($row = $church_result->fetch_assoc()) {
        $church_options[] = $row;
    }
} else {
    // Fallback: konfigból (app.local.php)
    $cfg = load_app_config();
    if (!empty($cfg['churches']) && is_array($cfg['churches'])) {
        foreach ($cfg['churches'] as $id => $name) {
            $church_options[] = ['id' => $id, 'name' => $name];
        }
    }
}

// Név kereső térkép építése a church_id alapján
$church_names = [];
foreach ($church_options as $c) {
    $church_names[$c['id']] = $c['name'];
}

$church_account_map = [];
$skip_account_map = [];
$account_config_rows = [];

// Gyülekezeti bankszámlák betöltése az adatbázisból
$cba_result = $conn->query("SELECT id, church_id, bank_account, bank_account_clean, bank_name, skip_import FROM church_bank_accounts WHERE bank_account_clean IS NOT NULL AND bank_account_clean != '' ORDER BY church_id ASC, bank_account_clean ASC");
if ($cba_result) {
    while ($cba = $cba_result->fetch_assoc()) {
        $cid = (int)$cba['church_id'];
        $clean = $cba['bank_account_clean'];
        $account_config_rows[] = $cba;
        if (!empty($cba['skip_import'])) {
            $skip_account_map[$clean] = true;
        }
        if ($cid > 0) {
            $church_account_map[$clean] = $cid;
        }
    }
}

// Segédfüggvény az emberbarát név feloldásához a számlaszám alapján
$resolveFriendlyName = function($acc, $defaultName) use ($church_account_map, $church_names) {
    $cleanAcc = preg_replace('/[^0-9]/', '', $acc);
    if (isset($church_account_map[$cleanAcc])) {
        $cid = $church_account_map[$cleanAcc];
        return $church_names[$cid] ?? $defaultName;
    }
    return $defaultName;
};

// Segédfüggvény a CSV-ben előforduló, esetleg tudományos jelölésben lévő számlaszám feloldásához
$resolveCsvAccount = function($raw, $church_account_map) {
    $raw = trim($raw, " \"'");
    if ($raw === '') return 0;

    // 1. Közvetlen egyezés – szokásos tisztítással (kötőjelek, szóközök eltávolítása)
    $clean = preg_replace('/[^0-9]/', '', $raw);
    if (isset($church_account_map[$clean])) return $church_account_map[$clean];

    // 2. Tudományos jelölés: "1,04E+23" vagy "1.04E+23" – string-alapú feldolgozás
    if (preg_match('/^([0-9.,]+)E[+-]\d+$/i', $raw, $m)) {
        $num = str_replace(',', '.', $m[1]); // "1.04"
        if (preg_match('/^(\d*)\.?(\d*)$/', $num, $n)) {
            $combined = $n[1] . $n[2];
            $dec_cnt = strlen($n[2]);
            $exp = (int)substr($raw, strpos(strtoupper($raw), 'E') + 1);
            $zeros = $exp - $dec_cnt;
            if ($zeros >= 0) {
                $full = $combined . str_repeat('0', $zeros);
                if (isset($church_account_map[$full])) return $church_account_map[$full];
            }
        }
    }

    // 3. "104003000000000000000000,00" – vesszős decimális, integer rész kell
    if (preg_match('/^(\d+),\d+$/', $raw, $m)) {
        $int_part = $m[1];
        if (isset($church_account_map[$int_part])) return $church_account_map[$int_part];
    }

    // 4. Visszafejtés: ismert számlák float reprezentációjával próbálunk egyezni
    //    Pl. ha a CSV-ben "104003000000000000000000,00" van, de a valós számla
    //    "104003395049575053561009", akkor float-on keresztül ugyanazt a torzított
    //    értéket kapjuk.
    $raw_digits = $clean;
    $raw_digits_short = mb_substr($raw_digits, 0, 20); // első 20 jegy elegendő az összehasonlításhoz
    foreach ($church_account_map as $known => $cid) {
        $float_val = (float)$known;
        // Teljes számként
        $as_full = sprintf('%.0f', $float_val);
        if ($as_full === $raw_digits) return $cid;
        // Vesszős decimálissal
        if ($as_full . ',00' === $raw) return $cid;
        // Rövidített összehasonlítás (első 20 jegy)
        if (mb_substr($as_full, 0, 20) === $raw_digits_short) return $cid;
        // Tudományos jelölés, több pontossági szinttel
        $upper_raw = strtoupper($raw);
        for ($prec = 2; $prec <= 10; $prec++) {
            $sci = sprintf("%.{$prec}E", $float_val);
            if ($sci === $upper_raw) return $cid;
            $sci_hu = str_replace('.', ',', $sci);
            if ($sci_hu === $upper_raw) return $cid;
        }
    }

    return 0;
};

$validateAccountNumbers = function($rows_data) {
    $invalid_rows = [];
    foreach ($rows_data as $idx => $rd) {
        $acc = isset($rd['acc']) ? preg_replace('/[^0-9]/', '', $rd['acc']) : '';
        if (strlen($acc) < 8) {
            $invalid_rows[] = [
                'line' => $rd['line'] ?? ($idx + 1),
                'raw' => $rd['raw_acc'] ?? '',
                'digits' => strlen($acc),
                'date' => $rd['date'] ?? '',
                'amount' => $rd['amount'] ?? ''
            ];
        }
    }
    return $invalid_rows;
};

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

// Segédtábla a több OTS tételes párosításhoz
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

$conn->query("CREATE TABLE IF NOT EXISTS bank_reconciliation_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reconciliation_id INT NOT NULL,
    record_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    INDEX idx_reconciliation (reconciliation_id),
    FOREIGN KEY (reconciliation_id) REFERENCES bank_reconciliation(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS upload_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    file_size INT DEFAULT 0,
    row_count INT DEFAULT 0,
    matched_count INT DEFAULT 0,
    skipped_count INT DEFAULT 0,
    duplicate_count INT DEFAULT 0,
    church_id INT DEFAULT NULL,
    church_name VARCHAR(255) DEFAULT NULL,
    uploaded_by VARCHAR(100) DEFAULT NULL,
    upload_type VARCHAR(20) DEFAULT 'single' COMMENT 'single or multi',
    warning TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function insert_ots_items($conn, $reconciliation_id, $record_id, $church_id, $exp_types, $extra_where = '') {
    $w = trim($extra_where);
    $w_clause = ($w !== '') ? " AND $w" : '';
    $ots_db = get_ots_conn();
    $sql = "SELECT id, AMOUNT, TYPE, CASH_DOCUMENT_NUMBER, DATETIME FROM TRANSACTIONS WHERE RECORD_ID = ? AND CHURCH_ID = ?$w_clause ORDER BY id";
    $st = $ots_db->prepare($sql);
    $st->bind_param("ii", $record_id, $church_id);
    $st->execute();
    $res = $st->get_result();
    $dates = [];
    $docs = [];
    $any = false;
    if ($res && $res->num_rows > 0) {
        $ins = $conn->prepare("INSERT INTO bank_reconciliation_items (reconciliation_id, record_id, amount) VALUES (?, ?, ?)");
        while ($it = $res->fetch_assoc()) {
            $adj = in_array((int)$it['TYPE'], $exp_types) ? -1.0 * (float)$it['AMOUNT'] : (float)$it['AMOUNT'];
            $ins->bind_param("iid", $reconciliation_id, $record_id, $adj);
            $ins->execute();
            $any = true;
            $dates[] = $it['DATETIME'];
            if (!empty($it['CASH_DOCUMENT_NUMBER']) && $it['CASH_DOCUMENT_NUMBER'] !== '0000') {
                $docs[] = $it['CASH_DOCUMENT_NUMBER'];
            }
        }
    }
    if (!$any) {
        $ins = $conn->prepare("INSERT INTO bank_reconciliation_items (reconciliation_id, record_id, amount) VALUES (?, ?, ?)");
        $zero = 0.0;
        $ins->bind_param("iid", $reconciliation_id, $record_id, $zero);
        $ins->execute();
    }
    return [
        'ots_date' => !empty($dates) ? substr(min($dates), 0, 10) : null,
        'ots_doc' => !empty($docs) ? implode(', ', array_unique($docs)) : (string)$record_id
    ];
}

$message = "";
if (isset($_GET['clear_msg'])) {
    unset($_SESSION['last_upload_msg']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['last_upload_msg'])) {
    $message = $_SESSION['last_upload_msg'];
}

if (isset($_GET['saved']) && $_GET['saved'] === 'skip') {
    $message = "<div class='alert alert-success'>✅ Figyelmen kívül hagyás beállítások mentve.</div>";
    unset($_SESSION['last_upload_msg']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>CSRF token mismatch!</div>";
    } else {
    if (isset($_POST['save_skip_config'])) {
        if (!$is_admin) {
            $message = "<div class='alert alert-danger'>Nincs jogosultság a beállításhoz.</div>";
        } else {
            $selected_ids = [];
            if (isset($_POST['skip_import']) && is_array($_POST['skip_import'])) {
                foreach ($_POST['skip_import'] as $sid) {
                    $sid = intval($sid);
                    if ($sid > 0) { $selected_ids[] = $sid; }
                }
            }

            $conn->begin_transaction();
            try {
                $conn->query("UPDATE church_bank_accounts SET skip_import = 0");
                if (!empty($selected_ids)) {
                    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                    $stmt_skip = $conn->prepare("UPDATE church_bank_accounts SET skip_import = 1 WHERE id IN ($placeholders)");
                    if ($stmt_skip) {
                        $types = str_repeat('i', count($selected_ids));
                        $stmt_skip->bind_param($types, ...$selected_ids);
                        $stmt_skip->execute();
                    }
                }
                $conn->commit();
                header('Location: upload.php?saved=skip');
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $message = "<div class='alert alert-danger'>Mentési hiba: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
    // --- EGYEDI GYÜLEKEZET FELTÖLTÉS ---
    if (isset($_POST['single_upload']) && isset($_FILES['bank_file'])) {
    if (!$is_admin) {
        $message = "<div class='alert alert-danger'>Csak adminisztrátor tölthet fel kézzel.</div>";
        $errors_found = true;
    } else {
    // Mivel datalist-et használunk, a beírt szövegből (pl: "Kiskunhalas (ID: 12)") kibányásszuk az ID-t
    $church_input = $_POST['church_search'] ?? '';
    preg_match('/\(ID:\s*(\d+)\)/', $church_input, $matches);
    $church_id = isset($matches[1]) ? intval($matches[1]) : 0;
    
    $file = $_FILES['bank_file'];

    if ($church_id === 0) {
        $message = "<div class='alert alert-danger'>Hiba: Érvénytelen vagy nem választott gyülekezet!</div>";
    } elseif ($file['error'] === UPLOAD_ERR_OK) {
        $file_content = file_get_contents($file['tmp_name']);
        
        if (substr($file_content, 0, 3) == pack("CCC", 0xef, 0xbb, 0xbf)) { $file_content = substr($file_content, 3); }
        if (!mb_check_encoding($file_content, 'UTF-8')) { $file_content = iconv('CP1250', 'UTF-8//IGNORE', $file_content); }

        $lines = explode("\n", str_replace("\r", "", $file_content));
        if (count($lines) > 1) {
            $separator = ";";
            $header = str_getcsv(trim($lines[0]), $separator);
            $header = array_map(function($val) { return trim($val, " \t\n\r\0\x0B\""); }, $header);
            
            $idx_date = array_search('Értéknap', $header);
            $idx_stmt_date = array_search('Kivonat dátuma', $header);
            $idx_amount = array_search('Összeg', $header);
            $idx_desc = array_search('Közlemény', $header);
            $idx_partner_name = array_search('Kedvezményezett neve', $header);
            $idx_partner_acc = array_search('Kedvezményezett számlaszáma', $header);
            $idx_init_name = array_search('Kezdeményező neve', $header);
            $idx_init_acc = array_search('Kezdeményező számlaszáma', $header);
            $idx_tx_id = array_search('Tranzakcióazonosító', $header);
            $idx_tx_code = array_search('Tranzakciós kód', $header);
            $idx_tx_code_iso = array_search('Tranzakciós kód (ISO)', $header);

            if ($idx_date === FALSE || $idx_amount === FALSE) {
                $message = "<div class='alert alert-danger'>Hiba: Hibás fájlstruktúra!</div>";
            } else {
                // --- Számlaszám-ellenőrzés a feltöltés előtt ---
                $force = isset($_POST['force_upload']) && $_POST['force_upload'] === '1';
                $invalid_accounts = [];
                for ($i = 1; $i < count($lines); $i++) {
                    if (empty(trim($lines[$i]))) continue;
                    $row = str_getcsv($lines[$i], $separator);
                    if (!isset($row[$idx_date]) || empty(trim($row[$idx_date]))) continue;
                    $raw_amount = str_replace([' ', "\xA0", 'Ft', 'HUF'], '', trim($row[$idx_amount] ?? '', " \""));
                    $amount = floatval(str_replace(',', '.', $raw_amount));
                    $is_incoming = ($amount > 0);
                    $init_acc_raw = trim($row[$idx_init_acc] ?? '', " \"'");
                    $ben_acc_raw = trim($row[$idx_partner_acc] ?? '', " \"'");
                    $p_acc = $is_incoming ? $init_acc_raw : $ben_acc_raw;
                    $clean = preg_replace('/[^0-9]/', '', $p_acc);
                    if (strlen($clean) > 0 && strlen($clean) < 8) {
                        $invalid_accounts[] = [
                            'line' => $i,
                            'raw' => mb_substr($p_acc, 0, 40),
                            'digits' => strlen($clean),
                            'date' => trim($row[$idx_date], " \""),
                            'amount' => number_format($amount, 0, ',', ' ')
                        ];
                    }
                }
                $bad_ratio = count($invalid_accounts) / max(1, count($lines) - 1);
                if ($bad_ratio > 0.3 && !$force) {
                    $bad_list = '';
                    foreach (array_slice($invalid_accounts, 0, 20) as $inv) {
                        $bad_list .= "{$inv['line']}. sor: '" . htmlspecialchars($inv['raw'], ENT_QUOTES, 'UTF-8') . "' (" . intval($inv['digits']) . " számjegy), " . htmlspecialchars($inv['date'], ENT_QUOTES, 'UTF-8') . ", " . htmlspecialchars((string)$inv['amount'], ENT_QUOTES, 'UTF-8') . " Ft<br>";
                    }
                    if (count($invalid_accounts) > 20) {
                        $bad_list .= '... és további ' . (count($invalid_accounts) - 20) . ' sor<br>';
                    }
                    $message = "<div class='alert alert-warning'><strong>⚠️ Figyelmeztetés: érvénytelen bankszámlaszámok</strong><br>
                        A CSV fájlban <strong>" . count($invalid_accounts) . "</strong> sorban (" . round($bad_ratio*100) . "%) a bankszámlaszám 8 számjegynél rövidebb.<br>
                        Ilyen adatokkal a párosító funkció nem működik megfelelően.<br><br>
                        <strong>Érvénytelen számlaszámok (első 20):</strong><br>$bad_list<br>
                        <form method='POST' enctype='multipart/form-data' style='display:inline'>
                            <input type='hidden' name='csrf_token' value='" . htmlspecialchars($_SESSION['csrf_token']) . "'>
                            <input type='hidden' name='single_upload' value='1'>
                            <input type='hidden' name='church_search' value='" . htmlspecialchars($church_input, ENT_QUOTES, 'UTF-8') . "'>
                            <input type='hidden' name='force_upload' value='1'>
                            <input type='hidden' name='MAX_FILE_SIZE' value='" . (50*1024*1024) . "'>
                            <button type='submit' class='btn btn-warning'>Mégis feltöltöm</button>
                        </form>
                        <a href='upload.php' class='btn btn-secondary'>Mégse</a>
                    </div>";
                } else {
                $inserted_rows = 0;
                $skipped_rows = 0;
                $auto_matched = 0;
                $duplicate_count = 0;
                $seen_in_file = [];
                $conn->begin_transaction();

                try {
                    for ($i = 1; $i < count($lines); $i++) {
                        if (empty(trim($lines[$i]))) continue;
                        $row = str_getcsv($lines[$i], $separator);
                        if (!isset($row[$idx_date]) || empty(trim($row[$idx_date]))) continue;

                        $raw_date = trim($row[$idx_date], " \"");
                        $bank_date = (preg_match('/^\d{8}$/', $raw_date)) 
                            ? substr($raw_date, 0, 4) . '-' . substr($raw_date, 4, 2) . '-' . substr($raw_date, 6, 2)
                            : date('Y-m-d', strtotime(str_replace(['.', '/'], '-', rtrim($raw_date, '.'))));
                        $raw_stmt_date = isset($row[$idx_stmt_date]) ? trim($row[$idx_stmt_date], " \"") : '';
                        $bank_stmt_date = (preg_match('/^\d{8}$/', $raw_stmt_date))
                            ? substr($raw_stmt_date, 0, 4) . '-' . substr($raw_stmt_date, 4, 2) . '-' . substr($raw_stmt_date, 6, 2)
                            : (!empty($raw_stmt_date) ? date('Y-m-d', strtotime(str_replace(['.', '/'], '-', rtrim($raw_stmt_date, '.')))) : '');

                        $clean_amount = str_replace([' ', "\xA0", 'Ft', 'HUF'], '', trim($row[$idx_amount], " \""));
                        $bank_amount = floatval(str_replace(',', '.', $clean_amount));

                        // Partner azonosítása az irány alapján (Bejövő: Kezdeményező, Kimenő: Kedvezményezett)
                        $is_incoming = ($bank_amount > 0);
                        
                        // Részletes adatok és emberbarát nevek feloldása
                        $init_acc_raw = trim($row[$idx_init_acc] ?? '', " \"'");
                        $ben_acc_raw = trim($row[$idx_partner_acc] ?? '', " \"'");
                        
                        $init_name_raw = $resolveFriendlyName($init_acc_raw, trim($row[$idx_init_name] ?? '', " \""));
                        $ben_name_raw = $resolveFriendlyName($ben_acc_raw, trim($row[$idx_partner_name] ?? '', " \""));

                        $p_name = $is_incoming ? $init_name_raw : $ben_name_raw;
                        $p_acc = $is_incoming ? $init_acc_raw : $ben_acc_raw;

                        $p_acc = trim($p_acc, " \"'");
                        if (strpos(strtoupper($p_acc), 'E+') !== FALSE) { $p_acc = sprintf("%.0f", floatval(str_replace(',', '.', $p_acc))); }
                        $init_acc_raw = trim($init_acc_raw, " \"'");
                        if (strpos(strtoupper($init_acc_raw), 'E+') !== FALSE) { $init_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $init_acc_raw))); }
                        $ben_acc_raw = trim($ben_acc_raw, " \"'");
                        if (strpos(strtoupper($ben_acc_raw), 'E+') !== FALSE) { $ben_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $ben_acc_raw))); }

                        $bank_desc = isset($row[$idx_desc]) ? trim($row[$idx_desc], " \"") : '';
                        $bank_ext_name = trim($p_name, " \"");
                        
                        $clean_acc = preg_replace('/[^0-9]/', '', $p_acc);
                        if (empty($bank_desc)) {
                            $bank_desc = $bank_ext_name;
                        }
                        
                        $bank_ext_acc = $p_acc;
                        $bank_ext_ref = isset($row[$idx_tx_id]) ? trim($row[$idx_tx_id], " \"") : '';
                        if (strpos(strtoupper($bank_ext_ref), 'E+') !== FALSE) { $bank_ext_ref = sprintf("%.0f", floatval(str_replace(',', '.', $bank_ext_ref))); }
                        $bank_tx_code = isset($row[$idx_tx_code]) ? trim($row[$idx_tx_code], " \"") : '';
                        $bank_tx_code_iso = isset($row[$idx_tx_code_iso]) ? trim($row[$idx_tx_code_iso], " \"") : '';

                        $base_fingerprint = $church_id . '_' . $bank_date . '_' . $bank_amount . '_' . $bank_desc . '_' . $bank_ext_acc . '_' . $bank_ext_ref . '_' . $bank_tx_code . '_' . $bank_tx_code_iso . '_' . $bank_stmt_date;
                        $row_hash = md5($base_fingerprint);

                        try {
                            $stmt = $conn->prepare("INSERT INTO bank_reconciliation (row_hash, church_id, bank_date, bank_amount, bank_desc, bank_ext_acc, bank_ext_name, bank_ext_ref, status, bank_init_name, bank_init_acc, bank_ben_name, bank_ben_acc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'UNCHECKED', ?, ?, ?, ?)");
                            $stmt->bind_param("sisdssssssss", $row_hash, $church_id, $bank_date, $bank_amount, $bank_desc, $bank_ext_acc, $bank_ext_name, $bank_ext_ref, $init_name_raw, $init_acc_raw, $ben_name_raw, $ben_acc_raw);
                            $stmt->execute();
                        } catch (mysqli_sql_exception $e) {
                            if ($e->getCode() === 1062) { $skipped_rows++; $duplicate_count++; continue; }
                            throw $e;
                        }

                        $new_id = $conn->insert_id;
                        $inserted_rows++;

                        // --- AUTO-MATCH ALGORITMUS KEZDETE ---
                        try {
                            // Az OTS TRANSACTIONS táblájában keresünk egy egyező banki tételt (+/- 5 nap)
                            $ots_query = "SELECT MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date 
                                           FROM TRANSACTIONS T
                                           WHERE CHURCH_ID = ? 
                                             AND DATETIME BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND DATE_ADD(?, INTERVAL 5 DAY)
                                             AND VIA_BANK <> 0
                                             AND ABS(PERIOD_DIFF(EXTRACT(YEAR_MONTH FROM ?), EXTRACT(YEAR_MONTH FROM T.DATETIME))) <= 1
                                           GROUP BY RECORD_ID 
                                           HAVING SUM(IF(T.TYPE IN ($exp_types_str), -1 * AMOUNT, AMOUNT)) = ?";
                            
                            $stmt_ots = $ots_db->prepare($ots_query);
                            if ($stmt_ots) {
                                $stmt_ots->bind_param("isssd", $church_id, $bank_date, $bank_date, $bank_date, $bank_amount);
                                $stmt_ots->execute();
                                $ots_result = $stmt_ots->get_result();
                                
                                 // Ha pontosan egy találat van, akkor biztosak lehetünk az egyezésben
                                if ($ots_result && $ots_result->num_rows === 1) {
                                    $ots_row = $ots_result->fetch_assoc();
                                    $ots_date_only = $ots_row['ots_date'] ? substr($ots_row['ots_date'], 0, 10) : null;
                                    $ots_doc_clean = $ots_row['ots_doc'] ?? '';
                                    if ($ots_doc_clean === '0000') $ots_doc_clean = '';
                                    $ots_record_id = $ots_row['RECORD_ID'] ?? 0;
                                    
                                     // Insert individual OTS items (handles TYPE=1 multi-item record_ids)
                                    if ($ots_record_id > 0) {
                                        $item_info = insert_ots_items($conn, $new_id, $ots_record_id, $church_id, $exp_types, "VIA_BANK <> 0");
                                        if ($item_info['ots_date'] !== null) $ots_date_only = $item_info['ots_date'];
                                        if ($item_info['ots_doc'] !== null) $ots_doc_clean = $item_info['ots_doc'];
                                    }
                                    
                                    $new_status = ($ots_date_only === $bank_date) ? 'OK' : 'CSUSZAS';
                                    $comment = ($ots_date_only === $bank_date) ? '[Auto: 100% egyezés, 0 nap]' : '[Auto: +/- 5 nap csúszás, egyetlen találat]';
                                    
                                    $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
                                    $upd_stmt->bind_param("ssidssi", $ots_date_only, $ots_doc_clean, $ots_record_id, $bank_amount, $new_status, $comment, $new_id);
                                    $upd_stmt->execute();
                                    $auto_matched++;
                                } elseif (!$ots_result || $ots_result->num_rows !== 1) {
                                    // Nem talált OTS-t, próbáljuk a transfers_to_conference-t
                                    $tc_stmt = $ots_db->prepare("SELECT AMOUNT, CONCAT(YEAR, '-', LPAD(MONTH, 2, '0'), '-', LPAD(DAY, 2, '0')) AS ots_date, CASH_DOCUMENT_NUMBER AS ots_doc FROM transfers_to_conference WHERE CHURCH_ID = ? AND VIA_BANK = 1 AND AMOUNT = ABS(?) AND CONCAT(YEAR, '-', LPAD(MONTH, 2, '0'), '-', LPAD(DAY, 2, '0')) BETWEEN DATE_SUB(?, INTERVAL 45 DAY) AND DATE_ADD(?, INTERVAL 45 DAY) LIMIT 1");
                                    if ($tc_stmt) {
                                        $tc_stmt->bind_param("idss", $church_id, $bank_amount, $bank_date, $bank_date);
                                        $tc_stmt->execute();
                                        $tc_res = $tc_stmt->get_result();
                                        if ($tc_res && $tc_res->num_rows === 1) {
                                            $tc_row = $tc_res->fetch_assoc();
                                            $ots_date_only = $tc_row['ots_date'] ?? null;
                                            $ots_doc_clean = $tc_row['ots_doc'] ?? '';
                                            if ($ots_doc_clean === '0000') $ots_doc_clean = '';
                                            $new_status = 'CSUSZAS';
                                            $comment = '[Auto: Konferencia utalás, feltöltéskor]';
                                            
                                            $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_amount=?, status=?, comment=? WHERE id=?");
                                            $upd_stmt->bind_param("ssdssi", $ots_date_only, $ots_doc_clean, $bank_amount, $new_status, $comment, $new_id);
                                            $upd_stmt->execute();
                                            $auto_matched++;
                                        }
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            // Néma kivételkezelés: ha hiba lenne, simán feltölti UNCHECKED-ként
                        }
                        // --- AUTO-MATCH VÉGE ---
                    }
                    $conn->commit();
                    $church_name = isset($church_names[$church_id]) ? $church_names[$church_id] : '';
                    $ul_stmt = $conn->prepare("INSERT INTO upload_log (filename, file_size, row_count, matched_count, skipped_count, duplicate_count, church_id, church_name, uploaded_by, upload_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'single')");
                    if ($ul_stmt) {
                        $fname = $file['name']; $fsize = $file['size'];
                        $ul_stmt->bind_param("siiiiisss", $fname, $fsize, $inserted_rows, $auto_matched, $skipped_rows, $duplicate_count, $church_id, $church_name, $_SESSION[GC_USER_FULL_NAME]);
                        $ul_stmt->execute();
                    }
                    $message = "<div class='alert alert-success'>Beolvasva: <strong>$inserted_rows</strong>, Átugorva: <strong>$skipped_rows</strong> (Duplikált: $duplicate_count) tétel. Automatikusan párosítva (OK): <strong>$auto_matched</strong>.</div>";
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "<div class='alert alert-danger'>Hiba történt a feldolgozás során.</div>";
                }
            }
        }
    }
    }
    }
    }
    // --- TÖBB GYÜLEKEZETES AUTOMATIKUS FELTÖLTÉS ---
    elseif (isset($_POST['multi_upload']) && isset($_FILES['multi_bank_file'])) {
        $file = $_FILES['multi_bank_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['csv', 'txt'])) {
            $file_content = file_get_contents($file['tmp_name']);
            if (substr($file_content, 0, 3) == pack("CCC", 0xef, 0xbb, 0xbf)) { $file_content = substr($file_content, 3); }
            if (!mb_check_encoding($file_content, 'UTF-8')) { $file_content = iconv('CP1250', 'UTF-8//IGNORE', $file_content); }

            $lines = explode("\n", str_replace("\r", "", $file_content));
            if (count($lines) > 1) {
                $separator = ";";
                $header = str_getcsv(trim($lines[0]), $separator);
                $header = array_map(function($val) { return trim($val, " \t\n\r\0\x0B\""); }, $header);
                
                $idx_date = array_search('Értéknap', $header);
                $idx_stmt_date = array_search('Kivonat dátuma', $header);
                $idx_amount = array_search('Összeg', $header);
                $idx_desc = array_search('Közlemény', $header);
                $idx_partner_name = array_search('Kedvezményezett neve', $header);
                $idx_partner_acc = array_search('Kedvezményezett számlaszáma', $header);
                $idx_init_name = array_search('Kezdeményező neve', $header);
                $idx_init_acc = array_search('Kezdeményező számlaszáma', $header);
    $idx_tx_id = array_search('Tranzakcióazonosító', $header);
    $idx_tx_code = array_search('Tranzakciós kód', $header);
    $idx_tx_code_iso = array_search('Tranzakciós kód (ISO)', $header);
                $idx_own_acc = array_search('Számlaszám', $header); // K&H specifikus saját számla

                if ($idx_date === FALSE || $idx_amount === FALSE) {
                    $message = "<div class='alert alert-danger'>Hiba: Hibás fájlstruktúra!</div>";
                } else {
                    $inserted_rows = 0; $skipped_rows = 0; $auto_matched = 0; $duplicate_count = 0; $seen_in_file = [];
                    $conn->begin_transaction();
                    try {
                        for ($i = 1; $i < count($lines); $i++) {
                            if (empty(trim($lines[$i]))) continue;
                            $row = str_getcsv($lines[$i], $separator);
                            if (!isset($row[$idx_date]) || empty(trim($row[$idx_date]))) continue;

                            $raw_date = trim($row[$idx_date], " \"");
                            $bank_date = (preg_match('/^\d{8}$/', $raw_date)) 
                                ? substr($raw_date, 0, 4) . '-' . substr($raw_date, 4, 2) . '-' . substr($raw_date, 6, 2)
                                : date('Y-m-d', strtotime(str_replace(['.', '/'], '-', rtrim($raw_date, '.'))));
                            $raw_stmt_date = isset($row[$idx_stmt_date]) ? trim($row[$idx_stmt_date], " \"") : '';
                            $bank_stmt_date = (preg_match('/^\d{8}$/', $raw_stmt_date))
                                ? substr($raw_stmt_date, 0, 4) . '-' . substr($raw_stmt_date, 4, 2) . '-' . substr($raw_stmt_date, 6, 2)
                                : (!empty($raw_stmt_date) ? date('Y-m-d', strtotime(str_replace(['.', '/'], '-', rtrim($raw_stmt_date, '.')))) : '');

                            $bank_amount = floatval(str_replace(',', '.', str_replace([' ', "\xA0", 'Ft'], '', $row[$idx_amount])));

                            $is_incoming = ($bank_amount > 0);
                            
                            // Számlaszámok kinyerése
                            $init_acc_raw = trim($row[$idx_init_acc] ?? '', " \"'");
                            $ben_acc_raw = trim($row[$idx_partner_acc] ?? '', " \"'");
                            $init_acc_raw_original = $init_acc_raw;
                            $ben_acc_raw_original = $ben_acc_raw;
                            if (strpos(strtoupper($init_acc_raw), 'E+') !== FALSE) { $init_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $init_acc_raw))); }
                            if (strpos(strtoupper($ben_acc_raw), 'E+') !== FALSE) { $ben_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $ben_acc_raw))); }

                            $lookup_acc_raw = $is_incoming ? $ben_acc_raw_original : $init_acc_raw_original;

                            $clean_lookup_acc = preg_replace('/[^0-9]/', '', $lookup_acc_raw);
                            $church_id = $resolveCsvAccount($lookup_acc_raw, $church_account_map);

                            // Ha az irányított keresés nem talált gyülekezetet, próbálkozzunk a saját számla oszloppal is
                            if ($church_id === 0 && $idx_own_acc !== FALSE) {
                                $own_acc_raw = $row[$idx_own_acc] ?? '';
                                $own_acc = preg_replace('/[^0-9]/', '', $own_acc_raw);
                                $church_id = $resolveCsvAccount($own_acc_raw, $church_account_map);
                            }

                            if ($church_id === 0) { continue; }

                            $bank_desc = trim($row[$idx_desc] ?? '', " \"");
                            
                            // Emberbarát nevek feloldása
                            $init_name_raw = $resolveFriendlyName($init_acc_raw, trim($row[$idx_init_name] ?? '', " \""));
                            $ben_name_raw = $resolveFriendlyName($ben_acc_raw, trim($row[$idx_partner_name] ?? '', " \""));

                            $p_name = $is_incoming ? $init_name_raw : $ben_name_raw;
                            $p_acc = $is_incoming ? $init_acc_raw : $ben_acc_raw;

                            if (strpos(strtoupper($p_acc), 'E+') !== FALSE) { $p_acc = sprintf("%.0f", floatval(str_replace(',', '.', $p_acc))); }
                            
                            $bank_ext_name = trim($p_name, " \"");
                            $bank_ext_acc = $p_acc;
                            $bank_ext_ref = ($idx_tx_id !== FALSE) ? trim($row[$idx_tx_id] ?? '', " \"") : '';
                            if (strpos(strtoupper($bank_ext_ref), 'E+') !== FALSE) { $bank_ext_ref = sprintf("%.0f", floatval(str_replace(',', '.', $bank_ext_ref))); }
                            $bank_tx_code = ($idx_tx_code !== FALSE) ? trim($row[$idx_tx_code] ?? '', " \"") : '';
                            $bank_tx_code_iso = ($idx_tx_code_iso !== FALSE) ? trim($row[$idx_tx_code_iso] ?? '', " \"") : '';

            $base_fingerprint = $church_id . '_' . $bank_date . '_' . $bank_amount . '_' . $bank_desc . '_' . $bank_ext_acc . '_' . $bank_ext_ref . '_' . $bank_tx_code . '_' . $bank_tx_code_iso . '_' . $bank_stmt_date;
                            $row_hash = md5($base_fingerprint);

                            try {
                                $stmt = $conn->prepare("INSERT INTO bank_reconciliation (row_hash, church_id, bank_date, bank_amount, bank_desc, bank_ext_acc, bank_ext_name, bank_ext_ref, status, bank_init_name, bank_init_acc, bank_ben_name, bank_ben_acc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'UNCHECKED', ?, ?, ?, ?)");
                                $stmt->bind_param("sisdssssssss", $row_hash, $church_id, $bank_date, $bank_amount, $bank_desc, $bank_ext_acc, $bank_ext_name, $bank_ext_ref, $init_name_raw, $init_acc_raw, $ben_name_raw, $ben_acc_raw);
                                $stmt->execute();
                                $new_id = $conn->insert_id;
                                $inserted_rows++;

                                // Automatikus párosítás
                                $ots_query = "SELECT MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date, RECORD_ID FROM TRANSACTIONS WHERE CHURCH_ID = ? AND DATETIME BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND DATE_ADD(?, INTERVAL 5 DAY) AND VIA_BANK <> 0 GROUP BY RECORD_ID HAVING SUM(IF(TYPE IN ($exp_types_str), -1 * AMOUNT, AMOUNT)) = ?";
                                $stmt_ots = $ots_db->prepare($ots_query);
                                if ($stmt_ots) {
                                    $stmt_ots->bind_param("issd", $church_id, $bank_date, $bank_date, $bank_amount);
                                    $stmt_ots->execute();
                                    $ots_result = $stmt_ots->get_result();
                                    if ($ots_result && $ots_result->num_rows === 1) {
                                        $ots_row = $ots_result->fetch_assoc();
                                        $ots_date_val = $ots_row['ots_date'] ? substr($ots_row['ots_date'], 0, 10) : null;
                                        $ots_doc_final = $ots_row['ots_doc'] ?? '';
                                        if ($ots_doc_final === '0000') $ots_doc_final = '';
                                        $ots_record_id = $ots_row['RECORD_ID'] ?? null;
                                        // Insert individual OTS items (handles TYPE=1 multi-item record_ids)
                                        if (!empty($ots_record_id)) {
                                            $item_info = insert_ots_items($conn, $new_id, $ots_record_id, $church_id, $exp_types, "VIA_BANK <> 0");
                                            if ($item_info['ots_date'] !== null) $ots_date_val = $item_info['ots_date'];
                                            if ($item_info['ots_doc'] !== null) $ots_doc_final = $item_info['ots_doc'];
                                        }
                                        $new_status = ($ots_date_val == $bank_date) ? 'OK' : 'CSUSZAS';
                                        $upd = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=? WHERE id=?");
                                        $upd->bind_param("ssidsi", $ots_date_val, $ots_doc_final, $ots_record_id, $bank_amount, $new_status, $new_id);
                                        $upd->execute();
                                        $auto_matched++;
                                    }
                                }
                            } catch (mysqli_sql_exception $e) {
                                if ($e->getCode() === 1062) { $skipped_rows++; $duplicate_count++; continue; }
                                throw $e;
                            }
                        }
                        $conn->commit();
                        $ul_stmt = $conn->prepare("INSERT INTO upload_log (filename, file_size, row_count, matched_count, skipped_count, duplicate_count, uploaded_by, upload_type, warning) VALUES (?, ?, ?, ?, ?, ?, ?, 'multi', ?)");
                        if ($ul_stmt) {
                            $fname = $file['name']; $fsize = $file['size'];
                            $mul_warn = '';
                            $ul_stmt->bind_param("siiiiiss", $fname, $fsize, $inserted_rows, $auto_matched, $skipped_rows, $duplicate_count, $_SESSION[GC_USER_FULL_NAME], $mul_warn);
                            $ul_stmt->execute();
                        }
                        $message = "<div class='alert alert-success'>Többes feltöltés kész. Beolvasva: <strong>$inserted_rows</strong> tétel.<br><small>Már korábban feltöltött (duplikált): $duplicate_count.</small></div>";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "<div class='alert alert-danger'>Hiba történt a feldolgozás során.</div>";
                    }
            }
        }
        }
    }
    }
    } // CSRF else block vége
}

// Save last upload result to session so it survives page refresh
if (!empty($message)) {
    $_SESSION['last_upload_msg'] = $message;
}

//
// AJAX multi-upload handler (insert only, no auto-match)
//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'multi_upload_ajax') {
    // only admin allowed for multi-upload
    if (!is_admin()) { echo json_encode(['status'=>'ERROR','message'=>'Only admin allowed']); exit; }
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF mismatch']);
        exit;
    }
    if (!isset($_FILES['multi_bank_file'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nincs fájl']);
        exit;
    }
    $file = $_FILES['multi_bank_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Fájl hiba: ' . $file['error']]);
        exit;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Csak CSV vagy TXT fájl tölthető fel!']);
        exit;
    }
    $start_upload = microtime(true);
    $file_content = file_get_contents($file['tmp_name']);
    if (substr($file_content, 0, 3) == pack("CCC", 0xef, 0xbb, 0xbf)) { $file_content = substr($file_content, 3); }
    if (!mb_check_encoding($file_content, 'UTF-8')) { $file_content = iconv('CP1250', 'UTF-8//IGNORE', $file_content); }
    $lines = explode("\n", str_replace("\r", "", $file_content));
    if (count($lines) <= 1) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Üres fájl']);
        exit;
    }
    $separator = ";";
    $header = str_getcsv(trim($lines[0]), $separator);
    $header = array_map(function($v) { return trim($v, " \t\n\r\0\x0B\""); }, $header);
    $idx_date = array_search('Értéknap', $header);
    $idx_stmt_date = array_search('Kivonat dátuma', $header);
    $idx_amount = array_search('Összeg', $header);
    $idx_desc = array_search('Közlemény', $header);
    $idx_partner_name = array_search('Kedvezményezett neve', $header);
    $idx_partner_acc = array_search('Kedvezményezett számlaszáma', $header);
    $idx_init_name = array_search('Kezdeményező neve', $header);
    $idx_init_acc = array_search('Kezdeményező számlaszáma', $header);
    $idx_tx_id = array_search('Tranzakcióazonosító', $header);
    $idx_tx_code = array_search('Tranzakciós kód', $header);
    $idx_tx_code_iso = array_search('Tranzakciós kód (ISO)', $header);
    $idx_own_acc = array_search('Számlaszám', $header);
    if ($idx_date === FALSE || $idx_amount === FALSE) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hibás fájlstruktúra']);
        exit;
    }
    $inserted = 0; $skipped = 0; $duplicate = 0; $seen_in_file = [];
    $skipped_config = 0; $skipped_unmapped = 0;
    $dup_details = [];
    $conn->begin_transaction();
    try {
        for ($i = 1; $i < count($lines); $i++) {
            if (empty(trim($lines[$i]))) continue;
            $row = str_getcsv($lines[$i], $separator);
            if (!isset($row[$idx_date]) || empty(trim($row[$idx_date]))) continue;
            $raw_date = trim($row[$idx_date], " \"");
            $bank_date = (preg_match('/^\d{8}$/', $raw_date))
                ? substr($raw_date, 0, 4) . '-' . substr($raw_date, 4, 2) . '-' . substr($raw_date, 6, 2)
                : date('Y-m-d', strtotime(str_replace(['.', '/'], '-', rtrim($raw_date, '.'))));
            $raw_stmt_date = isset($row[$idx_stmt_date]) ? trim($row[$idx_stmt_date], " \"") : '';
            $bank_stmt_date = (preg_match('/^\d{8}$/', $raw_stmt_date))
                ? substr($raw_stmt_date, 0, 4) . '-' . substr($raw_stmt_date, 4, 2) . '-' . substr($raw_stmt_date, 6, 2)
                : (!empty($raw_stmt_date) ? date('Y-m-d', strtotime(str_replace(['.', '/'], '-', rtrim($raw_stmt_date, '.')))) : '');
            $bank_amount = floatval(str_replace(',', '.', str_replace([' ', "\xA0", 'Ft'], '', $row[$idx_amount])));
            $is_incoming = ($bank_amount > 0);
            $init_acc_raw = trim($row[$idx_init_acc] ?? '', " \"'");
            $ben_acc_raw = trim($row[$idx_partner_acc] ?? '', " \"'");
            // Nyers értékek mentése a CSV-számla feloldáshoz (a float konverzió előtt)
            $init_acc_raw_original = $init_acc_raw;
            $ben_acc_raw_original = $ben_acc_raw;
            if (strpos(strtoupper($init_acc_raw), 'E+') !== FALSE) { $init_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $init_acc_raw))); }
            if (strpos(strtoupper($ben_acc_raw), 'E+') !== FALSE) { $ben_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $ben_acc_raw))); }
            $lookup_acc_raw = $is_incoming ? $ben_acc_raw_original : $init_acc_raw_original;
            $clean_lookup_acc = preg_replace('/[^0-9]/', '', $lookup_acc_raw);
            $church_id = $resolveCsvAccount($lookup_acc_raw, $church_account_map);
            $own_acc = '';
            if ($idx_own_acc !== FALSE) {
                $own_acc_raw = $row[$idx_own_acc] ?? '';
                $own_acc = preg_replace('/[^0-9]/', '', $own_acc_raw);
                if ($church_id === 0) {
                    $church_id = $resolveCsvAccount($own_acc_raw, $church_account_map);
                }
            }
            $is_config_skip = (!empty($clean_lookup_acc) && isset($skip_account_map[$clean_lookup_acc])) || (!empty($own_acc) && isset($skip_account_map[$own_acc]));
            if ($is_config_skip) {
                $skipped++;
                $skipped_config++;
                continue;
            }
            if ($church_id === 0) {
                if ($skipped_unmapped < 3) {
                    $col_count = count($row);
                    $raw_own = $row[$idx_own_acc] ?? 'N/A';
                    $raw_ben = $row[$idx_partner_acc] ?? 'N/A';
                    $raw_init = $row[$idx_init_acc] ?? 'N/A';
                    $log_line = "ROW=" . ($i+1) . " cols=$col_count amount={$row[$idx_amount]} incoming=" . ($is_incoming?'1':'0');
                    $log_line .= " szamlaszam=[$raw_own] ben=[$raw_ben] init=[$raw_init]";
                    error_log("[REVIZOR_DEBUG] $log_line");
                }
                $skipped++;
                $skipped_unmapped++;
                continue;
            }
            $bank_desc = trim($row[$idx_desc] ?? '', " \"");
            $init_name_raw = $resolveFriendlyName($init_acc_raw, trim($row[$idx_init_name] ?? '', " \""));
            $ben_name_raw = $resolveFriendlyName($ben_acc_raw, trim($row[$idx_partner_name] ?? '', " \""));
            $p_name = $is_incoming ? $init_name_raw : $ben_name_raw;
            $p_acc = $is_incoming ? $init_acc_raw : $ben_acc_raw;
            if (strpos(strtoupper($p_acc), 'E+') !== FALSE) { $p_acc = sprintf("%.0f", floatval(str_replace(',', '.', $p_acc))); }
            $bank_ext_name = trim($p_name, " \"");
            $bank_ext_acc = $p_acc;
            $bank_ext_ref = ($idx_tx_id !== FALSE) ? trim($row[$idx_tx_id] ?? '', " \"") : '';
            if (strpos(strtoupper($bank_ext_ref), 'E+') !== FALSE) { $bank_ext_ref = sprintf("%.0f", floatval(str_replace(',', '.', $bank_ext_ref))); }
            $bank_tx_code = ($idx_tx_code !== FALSE) ? trim($row[$idx_tx_code] ?? '', " \"") : '';
            $bank_tx_code_iso = ($idx_tx_code_iso !== FALSE) ? trim($row[$idx_tx_code_iso] ?? '', " \"") : '';
            $base_fingerprint = $church_id . '_' . $bank_date . '_' . $bank_amount . '_' . $bank_desc . '_' . $bank_ext_acc . '_' . $bank_ext_ref . '_' . $bank_tx_code . '_' . $bank_tx_code_iso . '_' . $bank_stmt_date;
            $row_hash = md5($base_fingerprint);
            try {
                $stmt = $conn->prepare("INSERT INTO bank_reconciliation (row_hash, church_id, bank_date, bank_amount, bank_desc, bank_ext_acc, bank_ext_name, bank_ext_ref, status, bank_init_name, bank_init_acc, bank_ben_name, bank_ben_acc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'UNCHECKED', ?, ?, ?, ?)");
                $stmt->bind_param("sisdssssssss", $row_hash, $church_id, $bank_date, $bank_amount, $bank_desc, $bank_ext_acc, $bank_ext_name, $bank_ext_ref, $init_name_raw, $init_acc_raw, $ben_name_raw, $ben_acc_raw);
                $stmt->execute();
                $inserted++;
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1062) {
                    $skipped++; $duplicate++;
                    $dup_line = $i + 1;
                    // lekérjük a meglévő rekordot az adatbázisból
                    $existing = null;
                    $eq = $conn->prepare("SELECT bank_date, bank_amount, bank_desc, bank_ext_acc, bank_ext_name, bank_ext_ref, bank_init_name, bank_init_acc, bank_ben_name, bank_ben_acc FROM bank_reconciliation WHERE row_hash = ? LIMIT 1");
                    if ($eq) { $eq->bind_param('s', $row_hash); $eq->execute(); $er = $eq->get_result(); if ($er) $existing = $er->fetch_assoc(); }
                    $incoming = [
                        'bank_date' => $bank_date,
                        'bank_amount' => $bank_amount,
                        'bank_desc' => $bank_desc,
                        'bank_ext_acc' => $bank_ext_acc,
                        'bank_ext_name' => $bank_ext_name,
                        'bank_ext_ref' => $bank_ext_ref,
                        'bank_init_name' => $init_name_raw,
                        'bank_init_acc' => $init_acc_raw,
                        'bank_ben_name' => $ben_name_raw,
                        'bank_ben_acc' => $ben_acc_raw,
                    ];
                    $dup_details[] = ['line' => $dup_line, 'incoming' => $incoming, 'existing' => $existing];
                    continue;
                }
                throw $e;
            }
        }
        $conn->commit();
        $elapsed = round(microtime(true) - $start_upload, 2);
            $ul_stmt = $conn->prepare("INSERT INTO upload_log (filename, file_size, row_count, matched_count, skipped_count, duplicate_count, uploaded_by, upload_type, warning) VALUES (?, ?, ?, ?, ?, ?, ?, 'multi_ajax', ?)");
        if ($ul_stmt) {
            $fname = $file['name'];
            $fsize = $file['size'];
            $matched_ajax = 0;
            $mul_warn = '';
            if ($skipped_config > 0 && $skipped_unmapped > 0) {
                $mul_warn = 'Config: ' . $skipped_config . ', unmapped: ' . $skipped_unmapped;
            } elseif ($skipped_config > 0) {
                $mul_warn = 'Config skip: ' . $skipped_config;
            } elseif ($skipped_unmapped > 0) {
                $mul_warn = 'Unmapped accounts: ' . $skipped_unmapped;
            }
            $ul_user = $_SESSION[GC_USER_FULL_NAME] ?? '';
            $ul_stmt->bind_param("siiiiiss", $fname, $fsize, $inserted, $matched_ajax, $skipped, $duplicate, $ul_user, $mul_warn);
            $ul_stmt->execute();
        }
        echo json_encode([
            'status' => 'OK',
            'inserted' => $inserted,
            'skipped' => $skipped,
            'duplicate' => $duplicate,
            'skipped_config' => $skipped_config,
            'skipped_unmapped' => $skipped_unmapped,
            'time_sec' => $elapsed,
            'dup_details' => $dup_details
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

//
// AJAX progressive match pass handler
//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'progressive_match_pass') {
    // only admin allowed
    if (!is_admin()) { echo json_encode(['status'=>'ERROR','message'=>'Only admin allowed']); exit; }
    session_write_close();
    header('Content-Type: application/json');
    try {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
            echo json_encode(['status' => 'ERROR', 'message' => 'CSRF mismatch']);
            exit;
        }
        $pass_index = isset($_POST['pass_index']) ? intval($_POST['pass_index']) : 0;
        $pass_days = [0, 3, 6, 12, 35, 60];
        $pass_names = ['0 napos', '3 napos', '6 napos', '12 napos', '35 napos', '60 napos', 'Szöveges'];
        $start_pass = microtime(true);
        $total_unchecked = 0;
        $unc_res = $conn->query("SELECT COUNT(*) AS cnt FROM bank_reconciliation WHERE status = 'UNCHECKED'");
        if ($unc_res) {
            $total_unchecked = (int)$unc_res->fetch_assoc()['cnt'];
        }
        $matched = 0;
        // progress file for frontend polling (shows per-church progress)
        $progress_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revizor_progress_' . session_id() . '.json';
        // initialize progress
        @file_put_contents($progress_file, json_encode(['status'=>'RUNNING','matched'=>0,'total_unchecked'=>$total_unchecked,'current_church'=>null,'processed_churches'=>0,'processed_records'=>0,'time_sec'=>0]));
        if ($pass_index < 6) {
        $days = $pass_days[$pass_index];
        $church_res = $conn->query("SELECT DISTINCT church_id FROM bank_reconciliation WHERE status = 'UNCHECKED'");
        while ($c = $church_res->fetch_assoc()) {
            $cid = $c['church_id'];
            $processed_churches = isset($processed_churches) ? $processed_churches : 0;
            $processed_records = isset($processed_records) ? $processed_records : 0;
            // update progress: current church
            @file_put_contents($progress_file, json_encode(['status'=>'RUNNING','matched'=>$matched,'total_unchecked'=>$total_unchecked,'current_church'=>$cid,'processed_churches'=>$processed_churches,'processed_records'=>$processed_records,'time_sec'=>round(microtime(true)-$start_pass,2)]));
                $stmt_recs = $conn->prepare("SELECT id, bank_date, bank_amount FROM bank_reconciliation WHERE church_id = ? AND status = 'UNCHECKED'");
                if ($stmt_recs) {
                    $stmt_recs->bind_param('i', $cid);
                    $stmt_recs->execute();
                    $rec_res = $stmt_recs->get_result();
                } else {
                    $rec_res = false;
                }
                while ($rec = $rec_res->fetch_assoc()) {
                $used_ots_ids = [];
                $used_res = $conn->query("SELECT ots_record_id FROM bank_reconciliation WHERE ots_record_id IS NOT NULL UNION SELECT record_id FROM bank_reconciliation_items");
                if ($used_res) { while ($u = $used_res->fetch_assoc()) { $used_ots_ids[] = (int)($u['ots_record_id'] ?? $u['record_id']); } }
                $used_list = empty($used_ots_ids) ? '0' : implode(',', $used_ots_ids);
                $ots_query = "SELECT MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date, RECORD_ID FROM TRANSACTIONS WHERE CHURCH_ID = ? AND DATETIME BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY) AND VIA_BANK <> 0 AND RECORD_ID NOT IN ($used_list) GROUP BY RECORD_ID HAVING SUM(IF(TYPE IN ($exp_types_str), -1 * AMOUNT, AMOUNT)) = ?";
                $stmt = $ots_db->prepare($ots_query);
                if ($stmt) {
                    $stmt->bind_param("isisid", $cid, $rec['bank_date'], $days, $rec['bank_date'], $days, $rec['bank_amount']);
                    $stmt->execute();
                    $ots_res = $stmt->get_result();
                        if ($ots_res && $ots_res->num_rows === 1) {
                        $ots_row = $ots_res->fetch_assoc();
                        $ots_date = $ots_row['ots_date'] ? substr($ots_row['ots_date'], 0, 10) : null;
                        $ots_doc = $ots_row['ots_doc'] ?? '';
                        if ($ots_doc === '0000') $ots_doc = '';
                        $rid = $ots_row['RECORD_ID'] ?? 0;
                        // Insert individual OTS items (handles TYPE=1 multi-item record_ids)
                        if ($rid > 0) {
                            $item_info = insert_ots_items($conn, $rec['id'], $rid, $cid, $exp_types, "VIA_BANK <> 0");
                            if ($item_info['ots_date'] !== null) $ots_date = $item_info['ots_date'];
                            if ($item_info['ots_doc'] !== null) $ots_doc = $item_info['ots_doc'];
                        }
                        // Sanity check: OTS must not be more than 30 days BEFORE bank date
                        if ($ots_date !== null) {
                            $ots_ts = strtotime($ots_date);
                            $bank_ts = strtotime($rec['bank_date']);
                            if ($ots_ts && $bank_ts && ($bank_ts - $ots_ts) > 30 * 86400) {
                                continue; // OTS too far before bank, skip this record
                            }
                        }
                        $ns = ($ots_date == $rec['bank_date']) ? 'OK' : 'CSUSZAS';
                        $cm = ($days == 0) ? '[Auto: 0 napos]' : "[Auto: {$days} napos]";
                        $upd = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
                        $upd->bind_param("ssidssi", $ots_date, $ots_doc, $rid, $rec['bank_amount'], $ns, $cm, $rec['id']);
                        $upd->execute();
                                    $matched++;
                                }
                        // update per-record progress every 50 records
                        $processed_records++;
                        if (($processed_records % 50) === 0) {
                            @file_put_contents($progress_file, json_encode(['status'=>'RUNNING','matched'=>$matched,'total_unchecked'=>$total_unchecked,'current_church'=>$cid,'processed_churches'=>$processed_churches,'processed_records'=>$processed_records,'time_sec'=>round(microtime(true)-$start_pass,2)]));
                        }
                }
                // finished a church
                $processed_churches++;
                @file_put_contents($progress_file, json_encode(['status'=>'RUNNING','matched'=>$matched,'total_unchecked'=>$total_unchecked,'current_church'=>null,'processed_churches'=>$processed_churches,'processed_records'=>$processed_records,'time_sec'=>round(microtime(true)-$start_pass,2)]));
            }
        }
    } else {
        $kw_res = $conn->query("SELECT bank_keyword, ots_keyword FROM provider_keywords ORDER BY id");
        $kws = [];
        while ($kw = $kw_res->fetch_assoc()) {
            $kws[] = ['b' => mb_strtolower($kw['bank_keyword'], 'UTF-8'), 'o' => mb_strtolower($kw['ots_keyword'], 'UTF-8')];
        }

        // Load custom_patterns by church
        $custom_patterns_map = [];
        $cp_res = $conn->query("SELECT church_id, bank_pattern, ots_pattern FROM custom_patterns ORDER BY church_id, id");
        if ($cp_res) {
            while ($cp = $cp_res->fetch_assoc()) {
                $cid = $cp['church_id'];
                if (!isset($custom_patterns_map[$cid])) $custom_patterns_map[$cid] = [];
                $custom_patterns_map[$cid][] = [
                    'b' => mb_strtolower($cp['bank_pattern'], 'UTF-8'),
                    'o' => mb_strtolower($cp['ots_pattern'], 'UTF-8')
                ];
            }
        }

        $church_res = $conn->query("SELECT DISTINCT church_id FROM bank_reconciliation WHERE status = 'UNCHECKED'");
        while ($c = $church_res->fetch_assoc()) {
            $cid = $c['church_id'];
                $stmt_recs2 = $conn->prepare("SELECT id, bank_date, bank_amount, bank_desc FROM bank_reconciliation WHERE church_id = ? AND status = 'UNCHECKED'");
                if ($stmt_recs2) {
                    $stmt_recs2->bind_param('i', $cid);
                    $stmt_recs2->execute();
                    $rec_res = $stmt_recs2->get_result();
                } else {
                    $rec_res = false;
                }
            while ($rec = $rec_res->fetch_assoc()) {
                $bd = mb_strtolower(trim($rec['bank_desc'] ?? ''), 'UTF-8');
                if (empty($bd)) continue;
                $used_ots_ids = [];
                $used_res = $conn->query("SELECT ots_record_id FROM bank_reconciliation WHERE ots_record_id IS NOT NULL UNION SELECT record_id FROM bank_reconciliation_items");
                if ($used_res) { while ($u = $used_res->fetch_assoc()) { $used_ots_ids[] = (int)($u['ots_record_id'] ?? $u['record_id']); } }
                $used_list = empty($used_ots_ids) ? '0' : implode(',', $used_ots_ids);
                $os = $ots_db->prepare("SELECT t.RECORD_ID, MAX(t.DATETIME) AS ots_date, MAX(t.CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(COALESCE(notn.NAME, '')) AS ots_reason FROM TRANSACTIONS t LEFT JOIN NAMES_OF_TRANSACTION notn ON t.NAME_ID = notn.id WHERE t.CHURCH_ID = ? AND t.VIA_BANK <> 0 AND t.DATETIME BETWEEN DATE_SUB(?, INTERVAL 90 DAY) AND DATE_ADD(?, INTERVAL 90 DAY) AND t.RECORD_ID NOT IN ($used_list) GROUP BY t.RECORD_ID HAVING SUM(IF(t.TYPE IN ($exp_types_str), -1 * t.AMOUNT, t.AMOUNT)) = ?");
                if (!$os) continue;
                $os->bind_param("issd", $cid, $rec['bank_date'], $rec['bank_date'], $rec['bank_amount']);
                $os->execute();
                $or = $os->get_result();
                $best_score = 0; $best_ots = null;
                while ($o = $or->fetch_assoc()) {
                    $reason = mb_strtolower(trim($o['ots_reason'] ?? ''), 'UTF-8');
                    $score = 0;
                    if (preg_match('/\d{6,}/', $bd)) $score += 2;
                    foreach ($kws as $kw) {
                        if (strpos($bd, $kw['b']) !== false && strpos($reason, $kw['o']) !== false) $score += 2;
                    }
                    // Church-specific custom_patterns
                    if (isset($custom_patterns_map[$cid])) {
                        foreach ($custom_patterns_map[$cid] as $cp) {
                            if (strpos($bd, $cp['b']) !== false && strpos($reason, $cp['o']) !== false) $score += 3;
                        }
                    }
                    $bw = preg_split('/\s+/', $bd);
                    $ow = preg_split('/\s+/', $reason);
                    $wm = 0;
                    foreach ($bw as $w) {
                        if (mb_strlen($w, 'UTF-8') < 4) continue;
                        if (in_array($w, $ow)) $wm++;
                    }
                    $score += min($wm, 3);
                    if ($score > $best_score) { $best_score = $score; $best_ots = $o; }
                }
                $min_score = 10;
                if ($best_ots && $best_score >= $min_score) {
                    $ots_date = $best_ots['ots_date'] ? substr($best_ots['ots_date'], 0, 10) : null;
                    $ots_doc = $best_ots['ots_doc'] ?? '';
                    if ($ots_doc === '0000') $ots_doc = '';
                    $rid = $best_ots['RECORD_ID'] ?? 0;
                    // Insert individual OTS items (handles TYPE=1 multi-item record_ids)
                    if ($rid > 0) {
                        $item_info = insert_ots_items($conn, $rec['id'], $rid, $cid, $exp_types);
                        if ($item_info['ots_date'] !== null) $ots_date = $item_info['ots_date'];
                        if ($item_info['ots_doc'] !== null) $ots_doc = $item_info['ots_doc'];
                    }
                    // Sanity check: OTS must not be more than 30 days BEFORE bank date
                    if ($ots_date !== null) {
                        $ots_ts = strtotime($ots_date);
                        $bank_ts = strtotime($rec['bank_date']);
                        if ($ots_ts && $bank_ts && ($bank_ts - $ots_ts) > 30 * 86400) {
                            continue; // OTS too far before bank, skip this record
                        }
                    }
                    $ns = ($ots_date == $rec['bank_date']) ? 'OK' : 'CSUSZAS';
                    $cm = "[Auto: Szöveges, pont:{$best_score}]";
                    $upd = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
                    $upd->bind_param("ssidssi", $ots_date, $ots_doc, $rid, $rec['bank_amount'], $ns, $cm, $rec['id']);
                    $upd->execute();
                    $matched++;
                }
            }
        }
    }
    $elapsed = round(microtime(true) - $start_pass, 2);
    // final progress write and remove file after small delay
    @file_put_contents($progress_file, json_encode(['status'=>'DONE','matched'=>$matched,'total_unchecked'=>$total_unchecked,'current_church'=>null,'processed_churches'=>isset($processed_churches)?$processed_churches:0,'processed_records'=>isset($processed_records)?$processed_records:0,'time_sec'=>$elapsed]));
    // give client the final result
    $response = ['status' => 'OK', 'pass_name' => $pass_names[$pass_index], 'matched' => $matched, 'total_unchecked' => $total_unchecked, 'time_sec' => $elapsed];
    // Include overall summary stats after the last pass
    if ($pass_index >= 6) {
        $summary_stmt = $conn->query("SELECT COUNT(*) AS total, SUM(status = 'OK') AS ok_count, SUM(status = 'CSUSZAS') AS csuszas_count, SUM(status = 'UNCHECKED') AS unchecked_count FROM bank_reconciliation");
        if ($summary_stmt) {
            $response['summary'] = $summary_stmt->fetch_assoc();
        }
    }
    echo json_encode($response);
    // cleanup progress file after short time (client will poll once more)
    register_shutdown_function(function() use ($progress_file){ if (file_exists($progress_file)) @unlink($progress_file); });
    exit;
    } catch (Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>🕵️ Revizor Asszisztens 1.0 – Feltöltés</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding: 40px; }
        .upload-card { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .accordion-wrapper { max-width: 700px; margin: 0 auto; }
        /* Loading overlay */
        #loadingOverlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;
        }
        #loadingOverlay.show { display: flex; }
        #loadingBox {
            background: white; padding: 40px; border-radius: 12px; text-align: center;
            box-shadow: 0 8px 30px rgba(0,0,0,0.3); min-width: 300px;
        }
        #loadingTimer { font-size: 36px; font-weight: 700; color: #0d6efd; margin: 10px 0; }
    </style>
</head>
<body>
<script>var CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';</script>
<div id="loadingOverlay">
    <div id="loadingBox">
        <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;"></div>
        <h5 class="mb-2">Feldolgozás folyamatban...</h5>
        <p class="text-muted small mb-2">A banki fájl feldolgozása eltarthat néhány másodpercig.</p>
        <div id="loadingTimer">0.0s</div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Single upload form — existing loading overlay
    document.querySelectorAll('form:not(#multiUploadForm)').forEach(function(form) {
        form.addEventListener('submit', function() {
            document.getElementById('loadingOverlay').classList.add('show');
            var start = Date.now();
            setInterval(function() {
                document.getElementById('loadingTimer').innerText = ((Date.now() - start) / 1000).toFixed(1) + 's';
            }, 100);
        });
    });

    // Multi-upload AJAX flow
    document.getElementById('multiUploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var panel = document.getElementById('progressPanel');
        panel.style.display = 'block';
        panel.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary mb-2"></div><div>Fájl feltöltése...</div></div>';

        var formData = new FormData(this);
        formData.set('action', 'multi_upload_ajax');

        var uploadStart = Date.now();
        var uploadTimer = setInterval(function() {
            var el = document.getElementById('uploadTimerDisplay');
            if (el) el.innerText = ((Date.now() - uploadStart) / 1000).toFixed(1) + 's';
        }, 100);

        fetch('upload.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            clearInterval(uploadTimer);
            if (result.status !== 'OK') { panel.innerHTML = '<div class="alert alert-danger">' + result.message + '</div>'; return; }

            var skipInfo = result.skipped > 0 ? ' (' + result.skipped + ' kihagyva, ebből ' + result.duplicate + ' duplikált)' : '';
            var skipSummaryHtml = '';
            if (result.skipped_config && result.skipped_config > 0) {
                skipSummaryHtml += '<div class="small text-muted mt-1">Kihagyva (config szerint): <strong>' + result.skipped_config + ' db tétel</strong></div>';
            }
            if (result.skipped_unmapped && result.skipped_unmapped > 0) {
                skipSummaryHtml += '<div class="small text-warning mt-1">Nem azonosított számla: ' + result.skipped_unmapped + ' db (állítsd be a church_bank_accounts táblában)</div>';
            }
            var dupDetailHtml = '';
            if (result.duplicate > 0) {
                dupDetailHtml = '<div class="small text-muted mt-1">Duplikált (már az adatbázisban volt): <strong>' + result.duplicate + ' db</strong>';
                if (result.dup_details && result.dup_details.length > 0) {
                    dupDetailHtml += '<div class="mt-1" style="font-size:11px;max-height:300px;overflow-y:auto;">';
                    for (var d = 0; d < result.dup_details.length; d++) {
                        var dd = result.dup_details[d];
                        var inc = dd.incoming || {};
                        var ex = dd.existing || {};
                        dupDetailHtml += '<div class="border-bottom pb-1 mb-1"><strong>CSV sor #' + dd.line + '</strong>';
                        dupDetailHtml += '<table class="table table-sm table-borderless mb-0" style="font-size:11px;"><tr><th style="width:80px;">Mező</th><th>CSV (új)</th><th>Adatbázis (meglévő)</th></tr>';
                        var fields = [
                            {k:'bank_date',l:'Dátum'},{k:'bank_amount',l:'Összeg'},{k:'bank_desc',l:'Közlemény'},
                            {k:'bank_ext_name',l:'Partner név'},{k:'bank_ext_acc',l:'Partner számla'},
                            {k:'bank_ext_ref',l:'Azonosító'},{k:'bank_init_name',l:'Kezd. név'},
                            {k:'bank_init_acc',l:'Kezd. számla'},{k:'bank_ben_name',l:'Kedv. név'},
                            {k:'bank_ben_acc',l:'Kedv. számla'}
                        ];
                        for (var f = 0; f < fields.length; f++) {
                            var fk = fields[f].k, fl = fields[f].l;
                            var iv = inc[fk] !== undefined ? inc[fk] : '';
                            var ev = ex[fk] !== undefined ? ex[fk] : '';
                            if (fk === 'bank_amount') { iv = Number(iv).toLocaleString('hu-HU', {minimumFractionDigits:0}) + ' Ft'; ev = ev ? Number(ev).toLocaleString('hu-HU', {minimumFractionDigits:0}) + ' Ft' : ''; }
                            dupDetailHtml += '<tr><td class="text-muted">' + fl + '</td><td>' + (iv+'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</td><td>' + (ev+'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</td></tr>';
                        }
                        dupDetailHtml += '</table></div>';
                    }
                    dupDetailHtml += '</div>';
                }
                dupDetailHtml += '</div>';
            }
            panel.innerHTML = '<div class="alert alert-success mb-2">✅ Feltöltve: <strong>' + result.inserted + '</strong> sor' + skipInfo + ' — <strong>' + result.time_sec + ' mp</strong>' + skipSummaryHtml + dupDetailHtml + '</div>';

            if (result.inserted > 0) {
                panel.innerHTML += '<div class="text-center py-2" id="autoMatchStatus"><div class="spinner-border spinner-border-sm text-info me-2"></div>Automatikus párosítás indítása...</div>';
                return runProgressiveMatch();
            } else {
                panel.innerHTML += '<div class="alert alert-info text-center py-2 mb-0">ℹ️ Nincs új tétel, automatikus párosítás nem szükséges.</div>';
            }
        })
        .catch(function(err) {
            clearInterval(uploadTimer);
            panel.innerHTML = '<div class="alert alert-danger">Hiba: ' + err.message + '</div>';
        });
    });

    function runProgressiveMatch() {
        var panel = document.getElementById('progressPanel');
        var passNames = ['0 napos', '3 napos', '6 napos', '12 napos', '35 napos', '60 napos', 'Szöveges'];
        // Mutassuk, hogy már nem "indítás" hanem "folyamatban"
        var statusDiv = document.getElementById('autoMatchStatus');
        if (statusDiv) statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm text-info me-2"></div>Automatikus párosítás folyamatban...</div>';
        var totalMatched = 0;
        var passIndex = 0;
        var initialTotal = 0;
        var overallStart = Date.now();

        // Progress bar container
        var progressHtml = '<div class="mb-2"><div class="progress" style="height:18px;">' +
            '<div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div>' +
            '<div class="small text-muted mt-1" id="progressStats">Feldolgozás előkészítése...</div></div>';
        panel.insertAdjacentHTML('beforeend', progressHtml);

        function nextPass() {
            if (passIndex >= passNames.length) {
                var elapsed = ((Date.now() - overallStart) / 1000).toFixed(1);
                var speed = elapsed > 0 ? (totalMatched / elapsed).toFixed(1) : 0;
                document.getElementById('progressBar').style.width = '100%';
                document.getElementById('progressBar').classList.replace('progress-bar-animated', 'bg-success');
                document.getElementById('progressStats').innerHTML = '✅ Kész — <strong>' + totalMatched + '</strong> párosítás, <strong>' + elapsed + '</strong> mp (⌀' + speed + '/mp)';
                // Status szöveg frissítése: befejeződött
                var statusDiv = document.getElementById('autoMatchStatus');
                if (statusDiv) statusDiv.innerHTML = '✅ Automatikus párosítás befejeződött';
                // Add separator and summary after Szöveges pass
                panel.innerHTML += '<hr class="my-2">';
                panel.innerHTML += '<div class="small fw-bold mb-1">📊 Összesített státusz statisztika:</div>';
                if (window._lastSummary) {
                    var s = window._lastSummary;
                    panel.innerHTML += '<div class="small d-flex gap-3 flex-wrap">' +
                        '<span>📋 Összes tétel: <strong>' + Number(s.total).toLocaleString('hu-HU') + '</strong></span>' +
                        '<span class="text-success">✅ OK: <strong>' + Number(s.ok_count).toLocaleString('hu-HU') + '</strong></span>' +
                        '<span class="text-warning">⚠️ CSÚSZÁS: <strong>' + Number(s.csuszas_count).toLocaleString('hu-HU') + '</strong></span>' +
                        '<span class="text-danger">❌ Párosítatlan: <strong>' + Number(s.unchecked_count).toLocaleString('hu-HU') + '</strong></span>' +
                        '</div>';
                }
                panel.innerHTML += '<button class="btn btn-primary mt-2" onclick="document.getElementById(\'progressPanel\').style.display=\'none\'">Rendben</button>';
                return;
            }

            var data = new FormData();
            data.append('action', 'progressive_match_pass');
            data.append('pass_index', passIndex);
            data.append('csrf_token', CSRF_TOKEN);

            panel.innerHTML += '<div class="d-flex align-items-center gap-2 text-muted small" id="passRow' + passIndex + '"><div class="spinner-border spinner-border-sm"></div><span>' + passNames[passIndex] + '...</span></div>';

            fetch('upload.php', { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.status === 'OK') {
                    totalMatched += result.matched;
                    if (passIndex === 0) {
                        initialTotal = result.total_unchecked;
                    }
                    document.getElementById('passRow' + passIndex).innerHTML = '✅ ' + passNames[passIndex] + ': <strong>' + result.matched + '</strong> párosítás (' + result.time_sec + ' mp)';

                    // Store summary if present (last pass)
                    if (result.summary) {
                        window._lastSummary = result.summary;
                    }

                    // Update progress bar
                    var elapsed = (Date.now() - overallStart) / 1000;
                    var remainAfter = (result.total_unchecked !== undefined && passIndex < passNames.length - 1) ? result.total_unchecked : 0;
                    var processed = initialTotal > 0 ? initialTotal - remainAfter : totalMatched;
                    var pct = initialTotal > 0 ? Math.round(processed / initialTotal * 100) : 0;
                    var speed = elapsed > 0 ? (processed / elapsed).toFixed(1) : 0;
                    var remaining = initialTotal - processed;
                    var etaSec = speed > 0 ? (remaining / speed) : 0;
                    var etaStr = etaSec > 90 ? Math.ceil(etaSec / 60) + ' perc' : Math.ceil(etaSec) + ' mp';

                    document.getElementById('progressBar').style.width = Math.min(pct, 100) + '%';
                    document.getElementById('progressStats').innerHTML =
                        'Haladás: ' + processed + '/' + initialTotal + ' (' + pct + '%)' +
                        ' | ⌀' + speed + '/mp' +
                        ' | ~' + etaStr + ' hátra';
                } else {
                    var errMsg = result.message || 'ismeretlen hiba';
                    document.getElementById('passRow' + passIndex).innerHTML = '❌ ' + passNames[passIndex] + ': ' + errMsg;
                }
                passIndex++;
                nextPass();
            })
            .catch(function(err) {
                document.getElementById('passRow' + passIndex).innerHTML = '❌ ' + passNames[passIndex] + ': ' + err.message;
                passIndex++;
                nextPass();
            });
        }

        nextPass();
    }
});
</script>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3 px-3 py-2 bg-white rounded border shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">🏠 Kezdőlap</a>
            <span class="fw-bold">🕵️ Revizor Asszisztens 1.0</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted">Feltöltés</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <a href="help.php" class="btn btn-outline-primary btn-sm">❓ Súgó</a>
            <?php render_dev_toggle(); ?>
            <?php render_user_badge(); ?>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Kilépés</a>
        </div>
    </div>

    <?php
    // Feltöltési előzmények lekérdezése
    $ul_res = $conn->query("SELECT id, filename, file_size, row_count, matched_count, skipped_count, duplicate_count, church_name, uploaded_by, upload_type, warning, created_at FROM upload_log ORDER BY created_at DESC LIMIT 20");
    $upload_logs = [];
    if ($ul_res) { while ($ul = $ul_res->fetch_assoc()) { $upload_logs[] = $ul; } }
    ?>

    <?php if ($is_admin): ?>

    <div class="accordion-wrapper">
    <div class="accordion" id="uploadAccordion">

      <div class="accordion-item">
        <h2 class="accordion-header">
          <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseHistory" aria-expanded="false">
            📋 Feltöltési előzmények (20 utolsó)
          </button>
        </h2>
        <div id="collapseHistory" class="accordion-collapse collapse" data-bs-parent="#uploadAccordion">
          <div class="accordion-body p-2">
            <?php if (empty($upload_logs)): ?>
              <p class="small text-muted mb-0">Még nincs feltöltési előzmény.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm table-bordered small mb-0">
                  <thead>
                    <tr class="table-dark">
                      <th>Dátum</th><th>Fájl</th><th>Típus</th><th>Gyülekezet</th><th>Sorok</th><th>Párosítva</th><th>Duplikált</th><th>Feltöltő</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($upload_logs as $ul): ?>
                      <tr>
                        <td class="text-nowrap"><?= htmlspecialchars($ul['created_at']) ?></td>
                        <td title="<?= htmlspecialchars($ul['filename'] . ' (' . number_format($ul['file_size']) . ' bájt)') ?>"><?= htmlspecialchars(mb_substr($ul['filename'], 0, 40)) ?></td>
                        <td><?= $ul['upload_type'] === 'multi' || $ul['upload_type'] === 'multi_ajax' ? 'Többes' : 'Egyedi' ?></td>
                        <td><?= htmlspecialchars($ul['church_name'] ?: '-') ?></td>
                        <td><?= number_format($ul['row_count']) ?></td>
                        <td><?= number_format($ul['matched_count']) ?></td>
                        <td><?= number_format($ul['duplicate_count']) ?></td>
                        <td><?= htmlspecialchars($ul['uploaded_by'] ?: '-') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header">
          <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true">
            📥 Egyedi Gyülekezet Feltöltése
          </button>
        </h2>
        <div id="collapseOne" class="accordion-collapse collapse show" data-bs-parent="#uploadAccordion">
          <div class="accordion-body">
            <?php if ($message): ?>
            <div style="position:relative;">
              <?php echo $message; ?>
              <a href="?clear_msg=1" class="btn btn-sm btn-outline-secondary" style="position:absolute;top:8px;right:8px;">✕</a>
            </div>
            <?php endif; ?>
            <form action="upload.php" method="POST" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="single_upload" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold">1. Gyülekezet (Gépelj a kereséshez):</label>
                    <input list="churches" name="church_search" id="church_search" class="form-control" placeholder="Kezdj el gépelni egy nevet vagy ID-t..." required>
                    <datalist id="churches">
                        <?php foreach ($church_options as $church): ?>
                            <option value="<?php echo htmlspecialchars($church['name']); ?> (ID: <?php echo $church['id']; ?>)">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">2. K&H CSV Fájl:</label>
                    <input class="form-control" type="file" name="bank_file" accept=".csv,.txt" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Feltöltés</button>
            </form>
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false">
            📥 Automatikus (Több Gyülekezet) Feltöltés
          </button>
        </h2>
        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#uploadAccordion">
          <div class="accordion-body">
            <p class="small text-muted">A rendszer a számlaszámok alapján automatikusan azonosítja a gyülekezeteket az adatbázisban tárolt bankszámla-nyilvántartás segítségével.</p>
            <form id="multiUploadForm" action="upload.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold">K&H CSV Fájl:</label>
                    <input class="form-control" type="file" name="multi_bank_file" accept=".csv,.txt" required>
                </div>
                <button type="submit" class="btn btn-success w-100">Összesített Feltöltés</button>
            </form>
            <div id="multiProgress" class="mt-3" style="display:none;"></div>
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false">
            ⚙️ Kihagyandó bankszámlák (import)
          </button>
        </h2>
        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#uploadAccordion">
          <div class="accordion-body">
            <p class="small text-muted mb-3">Jelöld be azokat a számlákat, amelyeket az automatikus tömeges import mindig figyelmen kívül hagyjon.</p>
            <form action="upload.php" method="POST">
                <input type="hidden" name="save_skip_config" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="table-responsive" style="max-height:260px; overflow:auto; border:1px solid #e9ecef; border-radius:10px;">
                    <table class="table table-sm table-striped mb-0 align-middle">
                        <thead class="table-light" style="position:sticky; top:0; z-index:1;">
                            <tr>
                                <th style="width:120px;">Kihagyás</th>
                                <th style="width:120px;">Church ID</th>
                                <th>Gyülekezet</th>
                                <th style="width:220px;">Bankszámla</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($account_config_rows as $acc_row): ?>
                                <?php
                                    $cid = (int)$acc_row['church_id'];
                                    $church_label = $church_names[$cid] ?? ($cid === 0 ? 'TET / Intézményi' : 'Ismeretlen gyülekezet');
                                    $clean_acc = preg_replace('/[^0-9]/', '', $acc_row['bank_account_clean'] ?? '');
                                    $acc_mask = strlen($clean_acc) > 12
                                        ? substr($clean_acc, 0, 8) . '...' . substr($clean_acc, -4)
                                        : $clean_acc;
                                ?>
                                <tr>
                                    <td>
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" name="skip_import[]" value="<?php echo (int)$acc_row['id']; ?>" <?php echo !empty($acc_row['skip_import']) ? 'checked' : ''; ?>>
                                        </div>
                                    </td>
                                    <td><?php echo $cid; ?></td>
                                    <td><?php echo htmlspecialchars($church_label); ?></td>
                                    <td><code><?php echo htmlspecialchars($acc_mask); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 d-flex justify-content-end">
                    <button type="submit" class="btn btn-outline-primary btn-sm">Mentés</button>
                </div>
            </form>
          </div>
        </div>
      </div>

    </div>

</div> <!-- accordion-wrapper -->

    <!-- Progress Panel -->
    <div id="progressPanel" class="mt-3 border rounded p-3 bg-light" style="display:none;"></div>

    <div class="mt-3 small text-end">
        <a href="#" onclick="if(confirm('Biztosan törlöd az összes banki rekordot?')){var f=document.createElement('form');f.method='POST';f.action='database/reset.php';var t1=document.createElement('input');t1.type='hidden';t1.name='csrf_token';t1.value='<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>';f.appendChild(t1);var t2=document.createElement('input');t2.type='hidden';t2.name='confirm';t2.value='1';f.appendChild(t2);document.body.appendChild(f);f.submit();}return false;" class="text-decoration-none text-muted">🧹 Adatbázis reset</a>
    </div>

    <?php else: ?>
        <div class="display-1 text-danger mb-3">🚫</div>
        <h3 class="fw-bold mb-2">Ehhez nincs jogosultságod.</h3>
        <p class="text-muted lead mb-4">Ezt a funkciót csak az adminisztrátor használhatja.</p>
        <a href="index.php" class="btn btn-primary">← Vissza a kezdőlapra</a>

    <?php endif; ?>
</div> <!-- container -->
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

// Feltöltési űrlapok státuszjelzése
document.querySelectorAll('form[action="upload.php"]').forEach(function(f) {
    f.addEventListener('submit', function() {
        var panel = document.getElementById('progressPanel');
        if (!panel) return;
        panel.style.display = 'block';
        var msg = 'Bankszámlaszámok ellenőrzése...';
        if (f.querySelector('[name="multi_upload"]')) msg = 'Fájl feldolgozása (több gyülekezet)...';
        else if (f.querySelector('[name="single_upload"]')) msg = 'Fájl feldolgozása (egyedi gyülekezet)...';
        panel.innerHTML = '<div class="py-3 text-center"><div class="spinner-border spinner-border-sm me-2"></div>' + msg + '</div>';
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php if (function_exists('render_announcement_modal')) render_announcement_modal(); ?>

</body>
</html>
