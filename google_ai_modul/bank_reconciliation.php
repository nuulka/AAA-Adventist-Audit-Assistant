<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/app/Services/ReconciliationStatus.php';
require_once __DIR__ . '/app/Services/CsvParserService.php';

// Session ellenőrzés: ha nincs kiválasztott gyülekezet, átirányítás
if (!isset($_SESSION['selected_church_id']) || empty($_SESSION['selected_church_id'])) {
    header('Location: select_church.php');
    exit;
}
$selectedChurchId = (int)$_SESSION['selected_church_id'];
$selectedChurchName = $_SESSION['selected_church_name'] ?? '';

$pdo = get_pdo_connection();
$message = '';
$messageType = '';

// --- POST műveletek ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['action']) && $_POST['action'] === 'import' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        try {
            // Betöltjük a kihagyandó (területi egyház) bankszámlák listáját
            $skipAccounts = [];
            $stmt = $pdo->query("SELECT bank_account_number FROM church_bank_accounts WHERE skip_import = 1 OR church_id = 0");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $clean = preg_replace('/[^0-9]/', '', $r['bank_account_number']);
                if ($clean) $skipAccounts[$clean] = true;
            }
            // Területi gyülekezet (id=76) bankszámlái az OTS-ből
            try {
                $st = get_ots_pdo()->query("SELECT BANK_ACCOUNT_NUMBER1, BANK_ACCOUNT_NUMBER2 FROM ots.churches WHERE id = 76");
                $tetAcc = $st->fetch(PDO::FETCH_ASSOC);
                if ($tetAcc) {
                    foreach ([$tetAcc['BANK_ACCOUNT_NUMBER1'], $tetAcc['BANK_ACCOUNT_NUMBER2']] as $acc) {
                        $clean = preg_replace('/[^0-9]/', '', $acc ?? '');
                        if ($clean) $skipAccounts[$clean] = true;
                    }
                }
            } catch (Exception $e) {}

            $filePath = $_FILES['csv_file']['tmp_name'];
            $parser = new CsvParserService($pdo);
            $total = $parser->importCsv($filePath);
                if ($total > 0) {
                    // Területi számlák tételeinek törlése
                    if (!empty($skipAccounts)) {
                        $ors = [];
                        foreach ($skipAccounts as $cleanAcc => $_) {
                            $ors[] = "REPLACE(REPLACE(REPLACE(bank_account, '-', ''), ' ', ''), '_', '') = '$cleanAcc'";
                        }
                        $where = implode(' OR ', $ors);
                        $stmt = $pdo->query("SELECT COUNT(*) FROM bank_statements WHERE $where");
                        $skipped = (int)$stmt->fetchColumn();
                        if ($skipped > 0) {
                            $pdo->exec("DELETE FROM bank_statements WHERE $where");
                            $total -= $skipped;
                        }
                    }
                    try {
                        require_once __DIR__ . '/app/Services/BankReconciliationService.php';
                        (new BankReconciliationService($pdo))->runAutoMatching();
                        $message = "$total tétel importálva, automatikus párosítás kész.";
                    } catch (Exception $e) {
                        $message = "$total tétel importálva (párosítás átugorva: " . $e->getMessage() . ")";
                    }
                    $messageType = 'success';
                } else {
                    $message = 'A CSV feldolgozása nem hozott új rekordokat.';
                    $messageType = 'warning';
                }
        } catch (Exception $e) {
            $message = 'PHP hiba: ' . $e->getMessage();
            $messageType = 'error';
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_status' && isset($_POST['bank_id'])) {
        try {
            $stmt = $pdo->prepare("UPDATE bank_statements SET status = ?, comment = ? WHERE id = ?");
            $stmt->execute([$_POST['status'], $_POST['comment'] ?? '', (int)$_POST['bank_id']]);
            $message = 'Banki tétel frissítve.';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Hiba: ' . $e->getMessage();
            $messageType = 'error';
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'manual_match' && isset($_POST['bank_id']) && !empty($_POST['ots_ids'])) {
        try {
            $bankId = (int)$_POST['bank_id'];
            $otsIds = array_map('intval', $_POST['ots_ids']);
            $pdo->beginTransaction();
            $status = count($otsIds) > 1 ? 'MANY_TO_ONE' : 'MATCHED';
            $stmt1 = $pdo->prepare("INSERT INTO bank_reconciliation (bank_statement_id, ots_record_id) VALUES (?, ?)");
            foreach ($otsIds as $oid) $stmt1->execute([$bankId, $oid]);
            $pdo->prepare("UPDATE bank_statements SET status = ? WHERE id = ?")->execute([$status, $bankId]);
            $pdo->commit();
            $message = count($otsIds) . ' OTS tétel sikeresen párosítva.';
            $messageType = 'success';
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = 'Hiba: ' . $e->getMessage();
            $messageType = 'error';
        }
    }

    header('Location: bank_reconciliation.php?' . http_build_query(
        $message ? ['msg' => $message, 'type' => $messageType] : []
    ));
    exit;
}

if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['type'] ?? 'info';
}

// --- Adatok lekérése ---

// Church ID → név térkép (OTS churches táblából)
$allChurches = [];
$churchIdNameMap = [];
$otsError = '';
try {
    $otsPdo = get_ots_pdo();
    $allChurches = $otsPdo->query("SELECT id, NAME, BANK_ACCOUNT_NUMBER1, BANK_ACCOUNT_NUMBER2 FROM ots.churches")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allChurches as $ch) {
        $churchIdNameMap[(int)$ch['id']] = $ch['NAME'];
    }
} catch (Exception $e) {
    $allChurches = [];
    $otsError = $e->getMessage();
}

// Church-számla térkép (OTS + church_bank_accounts)
$churchAccountMap = [];
try {
    // 1. OTS churches — BANK_ACCOUNT_NUMBER1/2
    foreach ($allChurches as $ch) {
        foreach ([$ch['BANK_ACCOUNT_NUMBER1'], $ch['BANK_ACCOUNT_NUMBER2']] as $acc) {
            if ($acc) {
                $clean = preg_replace('/[^0-9]/', '', $acc);
                if ($clean) $churchAccountMap[$clean] = ['church_id' => $ch['id'], 'church_name' => $ch['NAME']];
            }
        }
    }
    // 2. church_bank_accounts — kiegészítés/felülírás
    $localMap = $pdo->query("SELECT church_id, bank_account_number FROM church_bank_accounts WHERE church_id > 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($localMap as $m) {
        $clean = preg_replace('/[^0-9]/', '', $m['bank_account_number']);
        if ($clean) {
            $chName = $churchIdNameMap[(int)$m['church_id']] ?? '';
            $churchAccountMap[$clean] = ['church_id' => $m['church_id'], 'church_name' => $chName];
        }
    }
} catch (Exception $e) {}

// Banki tételek — csak a kiválasztott gyülekezethez tartozók
$bankPage = max(1, (int)($_GET['bank_page'] ?? 1));
$perPage = isset($_GET['per_page']) && $_GET['per_page'] === 'all' ? 0 : 200;
$showAll = ($perPage === 0);

// Szűrő paraméterek (URL-ből)
$fDate = trim($_GET['f_date'] ?? '');
$fAmt = trim($_GET['f_amt'] ?? '');
$fDesc = trim($_GET['f_desc'] ?? '');
$fStatus = trim($_GET['f_status'] ?? '');
$fComment = trim($_GET['f_comment'] ?? '');
$hasFilter = ($fDate || $fAmt || $fDesc || $fStatus || $fComment);

$filterParts = [];
$filterParts[] = "(b.church_id = $selectedChurchId OR b.church_id IS NULL)";
if ($fDate) $filterParts[] = "b.value_date LIKE " . $pdo->quote("%$fDate%");
if ($fAmt) $filterParts[] = "REPLACE(REPLACE(CAST(b.amount AS CHAR), ' ', ''), '.', '') LIKE " . $pdo->quote("%" . preg_replace('/[^0-9\-]/', '', $fAmt) . "%");
if ($fDesc) $filterParts[] = "(b.description LIKE " . $pdo->quote("%$fDesc%") . " OR b.beneficiary_name LIKE " . $pdo->quote("%$fDesc%") . ")";
if ($fStatus) {
    $statusMap = ['ellenőrizetlen' => 'UNCHECKED', 'ok' => 'MATCHED', 'csúszás' => 'TIMING_DIFFERENCE', 'hiány' => 'MISSING_DOCUMENT', 'eltérés' => 'AMOUNT_MISMATCH', 'összevont' => 'MANY_TO_ONE', 'szétbontott' => 'ONE_TO_MANY'];
    $statusKey = $statusMap[strtolower($fStatus)] ?? strtoupper($fStatus);
    $filterParts[] = "b.status = " . $pdo->quote($statusKey);
}
if ($fComment) $filterParts[] = "b.comment LIKE " . $pdo->quote("%$fComment%");

$sqlWhere = !empty($filterParts) ? 'WHERE ' . implode(' AND ', $filterParts) : '';

// Ha van szűrő, automatikusan betöltünk minden tételt
if ($hasFilter) {
    $showAll = true;
    $perPage = 0;
}

// Lapozás
$filteredTotal = 0;
$bankRows = [];
$bankTotalPages = 1;
try {
    $filteredTotal = (int)$pdo->query("SELECT COUNT(*) FROM bank_statements b $sqlWhere")->fetchColumn();
    if ($showAll || $filteredTotal <= 200) {
        $bankRows = $pdo->query("SELECT b.* FROM bank_statements b $sqlWhere ORDER BY b.value_date DESC")->fetchAll(PDO::FETCH_ASSOC);
        $perPage = $filteredTotal;
    } else {
        $bankOffset = ($bankPage - 1) * $perPage;
        $bankRows = $pdo->query("SELECT b.* FROM bank_statements b $sqlWhere ORDER BY b.value_date DESC LIMIT $perPage OFFSET $bankOffset")->fetchAll(PDO::FETCH_ASSOC);
        $bankTotalPages = max(1, (int)ceil($filteredTotal / $perPage));
    }
} catch (Exception $e) {
    $message = 'Hiba: ' . $e->getMessage();
    $messageType = 'error';
}

// Párosítási kapcsolatok + OTS adatok lekérése
$matchMap = []; // bank_statement_id -> [ [ots_id, ots_date, ots_amount, ots_doc, ...], ... ]
try {
    $sql = "SELECT br.bank_statement_id, br.ots_record_id,
                   T.DATETIME as ots_date,
                   T.CASH_DOCUMENT_NUMBER as ots_doc,
                   T.CHURCH_ID as ots_church_id,
                   SUM(CASE WHEN T.TYPE IN (20, 9) THEN -1 * T.AMOUNT ELSE T.AMOUNT END) as ots_amount,
                   MAX(notn.NAME) as ots_name,
                   MAX(p.NAME) as ots_person_name,
                   MAX(f.NAME) as ots_fund_name,
                   MAX(tt.NAME) as ots_type_name,
                   MIN(T.TYPE) as ots_first_type,
                   MAX(u.NAME) as ots_editor_name,
                   MAX(T.VIA_BANK) as ots_via_bank
            FROM bank_reconciliation br
            JOIN ots.TRANSACTIONS T ON T.RECORD_ID = br.ots_record_id
            LEFT JOIN ots.names_of_transaction notn ON T.NAME_ID = notn.id
            LEFT JOIN ots.PERSONS p ON T.PERSON_ID = p.id
            LEFT JOIN ots.funds f ON T.FUND_ID = f.id
            LEFT JOIN ots.TRANSACTION_TYPE tt ON T.TYPE = tt.id
            LEFT JOIN ots.USERS u ON T.EDITED_BY = u.id
            WHERE T.CHURCH_ID = $selectedChurchId
            GROUP BY br.bank_statement_id, br.ots_record_id, T.DATETIME, T.CASH_DOCUMENT_NUMBER, T.CHURCH_ID
            ORDER BY br.bank_statement_id";
    $matchRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($matchRows as $m) {
        $matchMap[$m['bank_statement_id']][] = $m;
    }
} catch (Exception $e) {
    // OTS esetleg nem elérhető
}

// Párosítatlan + párosított OTS tételek (a revizor PDO-n keresztül)
$otsRows = [];
try {
    $sql = "SELECT T.RECORD_ID, T.DATETIME, T.CASH_DOCUMENT_NUMBER,
                   SUM(CASE WHEN T.TYPE IN (20, 9) THEN -1 * T.AMOUNT ELSE T.AMOUNT END) as computed_amount,
                   T.CHURCH_ID,
                   MIN(T.TYPE) as first_type,
                   COUNT(*) as tx_count,
                   MAX(T.NAME_ID) as name_id,
                   MAX(notn.NAME) as tx_name,
                   MAX(notn.NAME_INDEX) as tx_name_index,
                   MAX(TRIM(CONCAT(IFNULL(p.NAME_PREFIX, ''), ' ', IFNULL(p.NAME, ''), ' ', IFNULL(p.NAME_SUFFIX, '')))) as person_name,
                   MAX(f.NAME) as fund_name,
                   MAX(tt.NAME) as type_name,
                   MAX(u.NAME) as editor_name,
                   MAX(T.VIA_BANK) as via_bank,
                   br.bank_statement_id
            FROM ots.TRANSACTIONS T
            LEFT JOIN ots.names_of_transaction notn ON T.NAME_ID = notn.id
            LEFT JOIN ots.PERSONS p ON T.PERSON_ID = p.id
            LEFT JOIN ots.funds f ON T.FUND_ID = f.id
            LEFT JOIN ots.TRANSACTION_TYPE tt ON T.TYPE = tt.id
            LEFT JOIN ots.USERS u ON T.EDITED_BY = u.id
            LEFT JOIN bank_reconciliation br ON T.RECORD_ID = br.ots_record_id
            WHERE T.VIA_BANK <> 0 AND T.CHURCH_ID = $selectedChurchId
            GROUP BY T.RECORD_ID, T.DATETIME, T.CASH_DOCUMENT_NUMBER, T.CHURCH_ID, br.bank_statement_id
            ORDER BY T.DATETIME DESC";
    $otsRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("OTS query failed: " . $e->getMessage());
    $otsRows = [];
}
// Szétválasztás: párosítatlan és párosított
$otsUnmatched = array_values(array_filter($otsRows, function($r) { return empty($r['bank_statement_id']); }));
$otsMatched = array_values(array_filter($otsRows, function($r) { return !empty($r['bank_statement_id']); }));

$statusLabels = [
    'UNCHECKED' => 'Ellenőrizetlen',
    'MATCHED' => 'OK',
    'TIMING_DIFFERENCE' => 'Csúszás',
    'MISSING_DOCUMENT' => 'Hiány',
    'AMOUNT_MISMATCH' => 'Eltérés',
    'MANY_TO_ONE' => 'Összevont (N→1)',
    'ONE_TO_MANY' => 'Szétbontott (1→N)',
];
$statusOptions = array_keys($statusLabels);
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bankegyeztetés — Google AI Modul</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; padding: 10px; background: #f5f5f5; color: #222; }
        .container { max-width: 1800px; margin: 0 auto; padding-bottom: 4px; display:flex;flex-direction:column;min-height:calc(100vh - 20px); }

        h1 { font-size: 1.1rem; margin: 0; color: #1565c0; display: inline; }
        h1 span { font-size: 0.85rem; color: #666; font-weight: normal; }

        .message { padding: 6px 12px; margin-bottom: 6px; border-radius: 4px; font-size: 0.82rem; }
        .success { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .error { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }
        .warning { background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; }
        .info { background: #e3f2fd; color: #1565c0; border: 1px solid #90caf9; }

        .nav { display: inline; margin-left: 12px; font-size: 0.82rem; }
        .nav a { color: #1565c0; text-decoration: none; }
        .nav a:hover { text-decoration: underline; }

        .btn-primary { background: #1565c0; color: #fff; border: none; border-radius: 3px; cursor: pointer; }
        .btn-primary:hover { background: #0d47a1; }

        .panels { display: flex; align-items: stretch; }
        .panel { min-width: 0; display: flex; flex-direction: column; overflow: hidden; }
        .panel-left { flex: 0 0 50%; }
        .panel-right { flex: 1; }
        .panel h2 { font-size: 1rem; margin: 0 0 8px 0; padding: 8px 12px; background: #e3f2fd; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
        .panel .pagination-row { flex-shrink: 0; }

        .splitter { width: 6px; cursor: col-resize; background: #e0e0e0; flex-shrink: 0; position: relative; }
        .splitter:hover, .splitter.dragging { background: #1565c0; }
        .splitter::after { content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); width: 2px; height: 30px; background: #999; border-radius: 1px; }
        .splitter:hover::after, .splitter.dragging::after { background: #fff; }

        .table-wrap { background: #fff; border-radius: 6px; overflow: auto; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex: 1; }
        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        th { background: #f0f0f0; position: sticky; top: 0; z-index: 2; padding: 8px 6px; text-align: left; font-weight: 600; border-bottom: 2px solid #ccc; white-space: nowrap; cursor: pointer; user-select: none; }
        th:hover { background: #e0e0e0; }
        th .sort { font-size: 0.65rem; color: #999; margin-left: 3px; }
        th .sort.active { color: #1565c0; }
        td { padding: 6px; border-bottom: 1px solid #eee; vertical-align: middle; }
        tr:hover { background: #fafafa; }

        .income { color: #2e7d32; font-weight: bold; }
        .expense { color: #c62828; font-weight: bold; }

        .status-select { font-size: 0.8rem; padding: 2px 4px; border: 1px solid #ccc; border-radius: 3px; }
        .comment-input { font-size: 0.8rem; padding: 2px 4px; border: 1px solid #ccc; border-radius: 3px; width: 100px; }
        .btn-sm { font-size: 0.75rem; padding: 3px 8px; border: none; border-radius: 3px; cursor: pointer; background: #e0e0e0; }
        .btn-sm:hover { background: #bdbdbd; }
        .btn-match { background: #2e7d32; color: #fff; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem; margin-top: 8px; }
        .btn-match:hover { background: #1b5e20; }

        .radio-row { text-align: center; }
        .empty { padding: 20px; text-align: center; color: #999; font-style: italic; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 600; }
        .badge.UNCHECKED { background: #f5f5f5; color: #666; }
        .badge.MATCHED { background: #e8f5e9; color: #2e7d32; }
        .badge.TIMING_DIFFERENCE { background: #fff3e0; color: #e65100; }
        .badge.MISSING_DOCUMENT { background: #ffebee; color: #c62828; }
        .badge.AMOUNT_MISMATCH { background: #fce4ec; color: #c62828; }
        .badge.MANY_TO_ONE { background: #e8eaf6; color: #283593; }
        .badge.ONE_TO_MANY { background: #f3e5f5; color: #6a1b9a; }

        .ots-match { font-size: 0.78rem; color: #555; }
        .ots-match a { color: #1565c0; text-decoration: none; }
        .ots-match a:hover { text-decoration: underline; }

        .church-name { color: #1565c0; font-weight: 500; font-size: 0.8rem; }

        @media (max-width: 1100px) { .panels { flex-direction: column; } .splitter { display: none; } .panel-left { flex: none !important; width: 100% !important; } }
    </style>
</head>
<body>
<div id="loading-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.92);z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;font-family:sans-serif;">
    <div style="font-size:2.5rem;animation:spin 1s linear infinite;">⏳</div>
    <div style="margin-top:12px;font-size:1rem;color:#333;">Adatok betöltése...</div>
    <div style="margin-top:6px;font-size:0.8rem;color:#888;">Kérlek várj, amíg a tételek megjelennek.</div>
</div>
<style>@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}</style>
<div class="container">

    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:4px;">
        <h1>🧾 Bankegyeztetés</h1>
        <span style="font-size:0.82rem;color:#1565c0;font-weight:600;background:#e3f2fd;padding:2px 8px;border-radius:4px;"><?= htmlspecialchars($selectedChurchName) ?></span>
        <a href="select_church.php?change=1" style="font-size:0.75rem;color:#888;">váltás ↗</a>
        <div class="nav">
            <a href="index.php">Menü</a> | <a href="test.php">Diagnosztika</a>
        </div>
        <form method="POST" enctype="multipart/form-data" style="display:inline-flex;align-items:center;gap:4px;margin-left:auto;font-size:0.78rem;">
            <input type="hidden" name="action" value="import">
            <input type="file" name="csv_file" accept=".csv,.txt" required style="font-size:0.78rem;width:120px;padding:1px;">
            <button type="submit" class="btn-primary" style="padding:2px 8px;font-size:0.78rem;">Feltöltés</button>
        </form>
    </div>

    <?php if ($message): ?>
        <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($otsError): ?>
        <div class="message warning" style="background:#fff3e0;border-color:#f57c00;color:#e65100;">
            ⚠️ Az OTS adatbázis nem érhető el: <?= htmlspecialchars($otsError) ?>
            — Csak a helyi church_bank_accounts alapján tudok szűrni.
        </div>
    <?php endif; ?>

    <form method="POST" id="match-form" style="display:flex;flex-direction:column;flex:1;">
    <input type="hidden" name="action" value="manual_match">
    <div class="panels">

        <!-- BAL: Banki tranzakciók -->
        <div class="panel panel-left" id="bank-panel">
            <h2>
                <span>🏦 Banki tranzakciók (<?= $filteredTotal ?>)</span>
            </h2>
            <div class="pagination-row" style="margin-bottom:6px;font-size:0.8rem;color:#666;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <?php
                // Szűrő paraméterek megőrzése a lapozásban
                $filterParams = [];
                if ($fDate) $filterParams['f_date'] = $fDate;
                if ($fAmt) $filterParams['f_amt'] = $fAmt;
                if ($fDesc) $filterParams['f_desc'] = $fDesc;
                if ($fStatus) $filterParams['f_status'] = $fStatus;
                if ($fComment) $filterParams['f_comment'] = $fComment;
                $filterQs = !empty($filterParams) ? '&' . http_build_query($filterParams) : '';
                ?>
                <span>Oldal: <?= $bankPage ?> / <?= $bankTotalPages ?> (<?= $showAll ? $filteredTotal : min($perPage, $filteredTotal) ?> / <?= $filteredTotal ?> tétel)</span>
                <?php if (!$showAll && $bankPage > 1): ?>
                    <a href="?bank_page=<?= $bankPage - 1 ?><?= $filterQs ?>" style="color:#1565c0;" class="page-link">← Előző</a>
                <?php endif; ?>
                <?php if (!$showAll && $bankPage < $bankTotalPages): ?>
                    <a href="?bank_page=<?= $bankPage + 1 ?><?= $filterQs ?>" style="color:#1565c0;" class="page-link">Következő →</a>
                <?php endif; ?>
                <?php if ($showAll): ?>
                    <a href="?bank_page=1<?= $filterQs ?>" style="color:#1565c0;" class="page-link">← Lapozás (200/oldal)</a>
                <?php else: ?>
                    <a href="?per_page=all<?= $filterQs ?>" style="color:#e65100;font-weight:600;" class="page-link">⚡ Összes megjelenítése (<?= $filteredTotal ?>)</a>
                <?php endif; ?>
                <?php if ($hasFilter): ?>
                    <a href="?" style="color:#c62828;font-weight:600;" class="page-link">✕ Szűrő törlése</a>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table id="bank-table">
                    <thead>
                        <tr>
                            <th style="width:26px;" data-sort="false"></th>
                            <th style="width:75px;" data-col="0">Dátum <span class="sort">▼</span></th>
                            <th style="width:85px;" data-col="1">Összeg <span class="sort">▼</span></th>
                            <th>Közlemény / Partner <span class="sort">▼</span></th>
                            <th style="width:110px;" data-col="3" data-col-church="true">Gyülekezet <span class="sort">▼</span></th>
                            <th style="width:60px;" data-col="4">Státusz <span class="sort">▼</span></th>
                            <th style="width:200px;" data-col="5">OTS pár <span class="sort">▼</span></th>
                            <th style="width:120px;">Megjegyzés</th>
                            <th style="width:60px;">Művelet</th>
                        </tr>
                        <tr style="background:#f0f0f0;">
                            <th style="width:26px;"></th>
                            <th style="width:75px;"><input type="text" id="bank-filter-date" placeholder="Dátum..." style="font-size:0.75rem;padding:2px 4px;border:1px solid #ccc;border-radius:3px;width:100%;box-sizing:border-box;" onkeyup="filterBankTable()"></th>
                            <th style="width:85px;"><input type="text" id="bank-filter-amt" placeholder="Összeg..." style="font-size:0.75rem;padding:2px 4px;border:1px solid #ccc;border-radius:3px;width:100%;box-sizing:border-box;" onkeyup="filterBankTable()"></th>
                            <th><input type="text" id="bank-filter-desc" placeholder="Közlemény..." style="font-size:0.75rem;padding:2px 4px;border:1px solid #ccc;border-radius:3px;width:100%;box-sizing:border-box;" onkeyup="filterBankTable()"></th>
                            <th style="width:110px;" data-col-church></th>
                            <th style="width:60px;"><select id="bank-filter-status" style="font-size:0.75rem;padding:2px;border:1px solid #ccc;border-radius:3px;width:100%;" onchange="filterBankTable()"><option value="">Mind</option><?php foreach ($statusLabels as $label): ?><option value="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></th>
                            <th style="width:200px;"><input type="text" id="bank-filter-ots" placeholder="OTS..." style="font-size:0.75rem;padding:2px 4px;border:1px solid #ccc;border-radius:3px;width:100%;box-sizing:border-box;" onkeyup="filterBankTable()"></th>
                            <th style="width:120px;"><input type="text" id="bank-filter-comment" placeholder="Megjegyzés..." style="font-size:0.75rem;padding:2px 4px;border:1px solid #ccc;border-radius:3px;width:100%;box-sizing:border-box;" onkeyup="filterBankTable()"></th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bankRows)): ?>
                            <tr><td colspan="9" class="empty">Nincsenek banki tételek. Tölts fel egy CSV-t.</td></tr>
                        <?php else: ?>
                            <?php foreach ($bankRows as $r):
                                $amt = floatval($r['amount'] ?? 0);
                                $amtClass = $amt >= 0 ? 'income' : 'expense';
                                $fmtAmt = number_format(abs($amt), 0, ',', ' ') . ' Ft';
                                if ($amt < 0) $fmtAmt = '- ' . $fmtAmt;
                                $church = $r['church_name'] ?? '';
                                if (!$church && $r['church_id']) $church = '#' . $r['church_id'];
                                if (!$church) $church = '—';
                            ?>
                            <tr>
                                <td class="radio-row"><input type="radio" name="bank_id" value="<?= $r['id'] ?>" required></td>
                                <td><?= htmlspecialchars($r['value_date'] ?? '') ?></td>
                                <td class="<?= $amtClass ?>" style="cursor:pointer;text-decoration:underline dotted;" onclick="showDetail(<?= $r['id'] ?>)" title="Részletek"><?= $fmtAmt ?></td>
                                <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($r['description'] ?? '') ?>">
                                    <?= htmlspecialchars(mb_substr($r['description'] ?? '', 0, 60)) ?>
                                    <?php if ($r['beneficiary_name']): ?><br><small style="color:#888;"><?= htmlspecialchars(mb_substr($r['beneficiary_name'], 0, 30)) ?></small><?php endif; ?>
                                </td>
                                <td class="church-name"><?= htmlspecialchars($church) ?></td>
                                <td>
                                    <span class="badge <?= htmlspecialchars($r['status'] ?? 'UNCHECKED') ?>"><?= htmlspecialchars($statusLabels[$r['status']] ?? $r['status']) ?></span>
                                    <form method="POST" style="display:inline;margin-left:2px;">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="bank_id" value="<?= $r['id'] ?>">
                                        <select name="status" class="status-select" onchange="this.form.submit()">
                                            <?php foreach ($statusOptions as $s): ?>
                                                <option value="<?= $s ?>" <?= $s === $r['status'] ? 'selected' : '' ?>><?= $statusLabels[$s] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="ots-match">
                                    <?php if (isset($matchMap[$r['id']])): ?>
                                        <?php foreach ($matchMap[$r['id']] as $m):
                                            $mAmt = number_format(abs(floatval($m['ots_amount'])), 0, ',', ' ') . ' Ft';
                                        ?>
                                            <div style="margin:1px 0;">
                                                <a href="/revizor/all_transactions/all_transactions_multi.php?record_id=<?= $m['ots_record_id'] ?>&church_id=<?= $m['ots_church_id'] ?>" target="_blank" title="OTS #<?= $m['ots_record_id'] ?>">#<?= $m['ots_record_id'] ?></a>
                                                <?= $m['ots_date'] ?> — <?= $mAmt ?>
                                                <small style="color:#888;"><?= htmlspecialchars($m['ots_doc'] ?? '') ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span style="color:#bbb;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:flex;gap:2px;">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="bank_id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="status" value="<?= htmlspecialchars($r['status'] ?? 'UNCHECKED') ?>">
                                        <input type="text" name="comment" class="comment-input" value="<?= htmlspecialchars($r['comment'] ?? '') ?>" placeholder="jegyzet">
                                        <button type="submit" class="btn-sm" title="Mentés">💾</button>
                                    </form>
                                </td>
                                <td style="text-align:center;">
                                    <?php if (!isset($matchMap[$r['id']]) || empty($matchMap[$r['id']])): ?>
                                        <button type="button" class="btn-sm" style="background:#e8f5e9;color:#2e7d32;" onclick="selectBankForMatch(<?= $r['id'] ?>)" title="Párosítás OTS tétellel">🔗</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Splitter (only when OTS panel exists) -->
        <?php if (!empty($otsUnmatched) || !empty($otsMatched)): ?>
        <div class="splitter" id="panel-splitter"></div>
        <?php endif; ?>

        <!-- JOBB: OTS tételek -->
        <?php if (!empty($otsUnmatched) || !empty($otsMatched)): ?>
        <div class="panel panel-right" id="ots-panel">
            <h2>
                <span>📋 OTS tételek (párosítatlan: <?= count($otsUnmatched) ?>, párosított: <?= count($otsMatched) ?>)</span>
                <span style="display:flex;gap:4px;align-items:center;">
                    <input type="text" id="ots-filter" placeholder="Szűrés..." style="font-size:0.8rem;padding:3px 8px;border:1px solid #ccc;border-radius:3px;width:120px;" onkeyup="filterTable('ots-table', this.value)">
                </span>
            </h2>

            <?php if (!empty($otsUnmatched)): ?>
            <div style="margin-bottom:4px;font-size:0.78rem;color:#888;font-weight:600;">Párosítatlan tételek (kattints a banki sor 🔗 gombjára a párosításhoz)</div>
            <div class="table-wrap" style="margin-bottom:8px;">
                <table id="ots-table">
                    <thead>
                        <tr>
                            <th style="width:28px;" data-sort="false"><input type="checkbox" id="ots-check-all" onclick="toggleAll(this)"></th>
                            <th style="width:80px;" data-col="0">Dátum <span class="sort">▼</span></th>
                            <th style="width:70px;" data-col="1">Biz.szám <span class="sort">▼</span></th>
                            <th style="width:80px;" data-col="2">Összeg <span class="sort">▼</span></th>
                            <th data-col="3">Megnevezés <span class="sort">▼</span></th>
                            <th data-col="4" data-col-church="true">Gyülekezet <span class="sort">▼</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($otsUnmatched as $r):
                            $amt = floatval($r['computed_amount'] ?? 0);
                            $amtClass = $amt >= 0 ? 'income' : 'expense';
                            $fmtAmt = number_format(abs($amt), 0, ',', ' ') . ' Ft';
                            if ($amt < 0) $fmtAmt = '- ' . $fmtAmt;
                            $otsCh = $r['CHURCH_ID'] ?? 0;
                            $otsChurchName = '';
                            foreach ($allChurches as $ch) {
                                if ((int)$ch['id'] === (int)$otsCh) { $otsChurchName = $ch['NAME']; break; }
                            }
                            $txName = trim($r['tx_name'] ?? '');
                            $txNameIndex = $r['tx_name_index'] ?? '';
                            if ($txName) {
                                $txNameDisplay = $txName . ($txNameIndex ? " ($txNameIndex)" : '');
                            } else {
                                $personName = trim($r['person_name'] ?? '');
                                $fundName = trim($r['fund_name'] ?? '');
                                $parts = array_filter([$personName, $fundName]);
                                $txNameDisplay = implode(' — ', $parts) ?: '—';
                            }
                        ?>
                        <tr data-rid="<?= $r['RECORD_ID'] ?>">
                            <td><input type="checkbox" name="ots_ids[]" value="<?= $r['RECORD_ID'] ?>" class="ots-cb"></td>
                            <td><?= htmlspecialchars($r['DATETIME'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['CASH_DOCUMENT_NUMBER'] ?? '') ?></td>
                            <td class="<?= $amtClass ?>" style="cursor:pointer;text-decoration:underline;" onclick="showOtsDetail(<?= $r['RECORD_ID'] ?>)"><?= $fmtAmt ?></td>
                            <td style="font-size:0.8rem;color:#555;"><?= htmlspecialchars($txNameDisplay) ?></td>
                            <td class="church-name"><?= htmlspecialchars($otsChurchName ?: '#' . $otsCh) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($otsMatched)): ?>
            <div style="margin-bottom:4px;font-size:0.78rem;color:#1565c0;font-weight:600;">Párosított tételek</div>
            <div class="table-wrap">
                <table id="ots-matched-table">
                    <thead>
                        <tr>
                            <th style="width:80px;">Dátum</th>
                            <th style="width:70px;">Biz.szám</th>
                            <th style="width:80px;">Összeg</th>
                            <th>Megnevezés</th>
                            <th style="width:80px;">Bank #</th>
                            <th data-col-church="true">Gyülekezet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($otsMatched as $r):
                            $amt = floatval($r['computed_amount'] ?? 0);
                            $amtClass = $amt >= 0 ? 'income' : 'expense';
                            $fmtAmt = number_format(abs($amt), 0, ',', ' ') . ' Ft';
                            if ($amt < 0) $fmtAmt = '- ' . $fmtAmt;
                            $otsCh = $r['CHURCH_ID'] ?? 0;
                            $otsChurchName = '';
                            foreach ($allChurches as $ch) {
                                if ((int)$ch['id'] === (int)$otsCh) { $otsChurchName = $ch['NAME']; break; }
                            }
                            $txName = trim($r['tx_name'] ?? '');
                            $txNameIndex = $r['tx_name_index'] ?? '';
                            if ($txName) {
                                $txNameDisplay = $txName . ($txNameIndex ? " ($txNameIndex)" : '');
                            } else {
                                $personName = trim($r['person_name'] ?? '');
                                $fundName = trim($r['fund_name'] ?? '');
                                $parts = array_filter([$personName, $fundName]);
                                $txNameDisplay = implode(' — ', $parts) ?: '—';
                            }
                        ?>
                        <tr data-rid="<?= $r['RECORD_ID'] ?>" style="background:#e8f5e9;">
                            <td><?= htmlspecialchars($r['DATETIME'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['CASH_DOCUMENT_NUMBER'] ?? '') ?></td>
                            <td class="<?= $amtClass ?>" style="cursor:pointer;text-decoration:underline;" onclick="showOtsDetail(<?= $r['RECORD_ID'] ?>)"><?= $fmtAmt ?></td>
                            <td style="font-size:0.8rem;color:#555;"><?= htmlspecialchars($txNameDisplay) ?></td>
                            <td style="font-size:0.8rem;"><a href="#" onclick="showDetail(<?= $r['bank_statement_id'] ?>);return false;" style="color:#1565c0;">#<?= $r['bank_statement_id'] ?></a></td>
                            <td class="church-name"><?= htmlspecialchars($otsChurchName ?: '#' . $otsCh) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

    </div>

    </form>

</div>

<!-- Sticky alsó sáv -->
<div id="bottom-bar" style="position:fixed;bottom:0;left:0;right:0;z-index:100;background:#fff;border-top:2px solid #1565c0;padding:8px 20px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;box-shadow:0 -2px 8px rgba(0,0,0,0.15);">
    <button type="submit" form="match-form" class="btn-match" style="margin:0;">🔗 Párosítás</button>
    <span style="font-size:0.82rem;color:#666;">
        <?php if (empty($otsUnmatched)): ?>
            Nincsenek párosítatlan OTS tételek.
        <?php else: ?>
            1. 🔗 a banki soron → 2. OTS pipálás → 3. Párosítás gomb
        <?php endif; ?>
    </span>
</div>

<!-- Részlet modal -->
<div id="detail-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:200;background:rgba(0,0,0,0.4);overflow-y:auto;" onclick="if(event.target===this)closeDetail()">
    <div style="max-width:950px;margin:40px auto;background:#fff;border-radius:8px;padding:24px;box-shadow:0 4px 20px rgba(0,0,0,0.3);position:relative;">
        <button onclick="closeDetail()" style="position:absolute;top:12px;right:16px;font-size:1.4rem;border:none;background:none;cursor:pointer;color:#999;">&times;</button>
        <div id="detail-content"></div>
    </div>
</div>

<script>
// Banki adatok JSON-ban a részletablakhoz
var bankData = <?= json_encode($bankRows, JSON_UNESCAPED_UNICODE) ?>;
var matchData = <?= json_encode($matchMap, JSON_UNESCAPED_UNICODE) ?>;
var otsData = <?= json_encode($otsRows, JSON_UNESCAPED_UNICODE) ?>;
var filteredTotal = <?= $filteredTotal ?>;
var matchedCount = <?= count($matchMap) ?>;
var otsTotal = <?= count($otsUnmatched) ?>;

// Részletablak megnyitása — két oszlopos elrendezés
function showDetail(bankId) {
    var row = null;
    for (var i = 0; i < bankData.length; i++) {
        if (parseInt(bankData[i].id) === parseInt(bankId)) { row = bankData[i]; break; }
    }
    if (!row) return;
    var matches = matchData[bankId] || [];
    var amt = parseFloat(row.amount || 0);
    var amtClass = amt >= 0 ? 'income' : 'expense';
    var fmtAmt = Math.abs(amt).toLocaleString('hu-HU', {maximumFractionDigits:0}) + ' Ft';
    if (amt < 0) fmtAmt = '- ' + fmtAmt;

    var tdL = 'padding:5px 8px;border-bottom:1px solid #eee;font-size:0.85rem;';
    var tdW = 'padding:5px 8px;border-bottom:1px solid #eee;font-weight:600;font-size:0.85rem;width:120px;white-space:nowrap;';

    // BAL: Banki adatok
    var html = '<div style="display:flex;gap:20px;align-items:flex-start;">';
    html += '<div style="flex:1;min-width:0;">';
    html += '<h2 style="margin:0 0 10px 0;color:#1565c0;font-size:1rem;">🏦 Banki tranzakció #' + row.id + '</h2>';
    html += '<table style="width:100%;border-collapse:collapse;">';
    html += '<tr><td style="' + tdW + '">Összeg</td><td style="' + tdL + '" class="' + amtClass + '">' + fmtAmt + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Könyvelés</td><td style="' + tdL + '">' + (row.statement_date || '—') + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Értéknap</td><td style="' + tdL + '">' + (row.value_date || '—') + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Közlemény</td><td style="' + tdL + '">' + (row.description || '—') + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Kedvezményezett</td><td style="' + tdL + '">' + (row.beneficiary_name || '—') + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Megbízó</td><td style="' + tdL + '">' + (row.initiator_name || '—') + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Bankszámla</td><td style="' + tdL + '">' + (row.bank_account || '—') + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Tranzakció ID</td><td style="' + tdL + '">' + (row.bank_tx_id || '—') + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Gyülekezet</td><td style="' + tdL + 'color:#1565c0;">' + (row.church_name || '—') + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Státusz</td><td style="' + tdL + '">' + (row.status || 'UNCHECKED') + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Megjegyzés</td><td style="' + tdL + '">' + (row.comment || '—') + '</td></tr>';
    html += '</table>';
    html += '</div>';

    // JOBB: OTS párok
    html += '<div style="flex:1;min-width:0;border-left:2px solid #e0e0e0;padding-left:20px;">';
    html += '<h2 style="margin:0 0 10px 0;color:#2e7d32;font-size:1rem;">📋 OTS pár' + (matches.length > 1 ? 'ok' : '') + ' (' + matches.length + ')</h2>';
    if (matches.length === 0) {
        html += '<div style="color:#999;font-style:italic;font-size:0.85rem;">Nincs párosított OTS tétel.</div>';
    } else {
        for (var j = 0; j < matches.length; j++) {
            var m = matches[j];
            var mAmt = Math.abs(parseFloat(m.ots_amount || 0)).toLocaleString('hu-HU', {maximumFractionDigits:0}) + ' Ft';
            var mAmtClass = parseFloat(m.ots_amount || 0) >= 0 ? 'income' : 'expense';
            var otsTypeLabel = '';
            if (m.ots_first_type == 20 || m.ots_first_type == 9) otsTypeLabel = 'Kiadás';
            else if (m.ots_first_type == 10 || m.ots_first_type == 1) otsTypeLabel = 'Bevétel';
            else otsTypeLabel = m.ots_type_name || '';
            var otsName = m.ots_name || '';
            var otsPerson = (m.ots_person_name || '').trim();
            var otsFund = m.ots_fund_name || '';
            var otsDesc = otsName || [otsPerson, otsFund].filter(Boolean).join(' — ') || otsTypeLabel || '—';

            html += '<div style="margin-bottom:12px;padding:10px;background:#f8f9fa;border-radius:6px;border-left:3px solid #2e7d32;">';
            html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">';
            html += '<a href="/revizor/all_transactions/all_transactions_multi.php?record_id=' + m.ots_record_id + '&church_id=' + (m.ots_church_id || 0) + '" target="_blank" style="color:#2e7d32;font-weight:600;font-size:0.95rem;">OTS #' + m.ots_record_id + '</a>';
            html += '<span class="' + mAmtClass + '" style="font-weight:600;">' + mAmt + '</span>';
            html += '</div>';
            html += '<table style="width:100%;border-collapse:collapse;">';
            html += '<tr><td style="' + tdW + '">Dátum</td><td style="' + tdL + '">' + (m.ots_date || '—') + '</td></tr>';
            if (m.ots_doc) html += '<tr><td style="' + tdW + '">Bizonylatszám</td><td style="' + tdL + '">' + m.ots_doc + '</td></tr>';
            if (otsTypeLabel) html += '<tr><td style="' + tdW + '">Típus</td><td style="' + tdL + '">' + otsTypeLabel + '</td></tr>';
            if (otsDesc !== '—') html += '<tr><td style="' + tdW + '">Megnevezés</td><td style="' + tdL + '">' + otsDesc + '</td></tr>';
            html += '</table>';
            html += '</div>';
        }
    }
    html += '</div>';
    html += '</div>';

    document.getElementById('detail-content').innerHTML = html;
    document.getElementById('detail-modal').style.display = 'block';
}

function closeDetail() {
    document.getElementById('detail-modal').style.display = 'none';
}

// OTS részletablak — két oszlopos: bal OTS, jobb banki pár + tized cédula
function showOtsDetail(recordId) {
    var row = null;
    for (var i = 0; i < otsData.length; i++) {
        if (parseInt(otsData[i].RECORD_ID) === parseInt(recordId)) { row = otsData[i]; break; }
    }
    if (!row) return;
    var amt = parseFloat(row.computed_amount || 0);
    var amtClass = amt >= 0 ? 'income' : 'expense';
    var fmtAmt = Math.abs(amt).toLocaleString('hu-HU', {maximumFractionDigits:0}) + ' Ft';
    if (amt < 0) fmtAmt = '- ' + fmtAmt;

    var typeLabel = '';
    if (row.first_type == 20 || row.first_type == 9) typeLabel = 'Kiadás';
    else if (row.first_type == 10 || row.first_type == 1) typeLabel = 'Bevétel';
    else typeLabel = 'Típus: ' + (row.first_type || '—');

    var churchCell = document.querySelector('#ots-table tr[data-rid="' + recordId + '"] .church-name, #ots-matched-table tr[data-rid="' + recordId + '"] .church-name');
    var churchName = churchCell ? churchCell.textContent.trim() : '—';

    var txName = row.tx_name || '';
    var txNameIndex = row.tx_name_index || '';
    var personName = (row.person_name || '').trim();
    var fundName = row.fund_name || '';
    var typeName = row.type_name || '';
    var editorName = row.editor_name || '';
    var viaBank = row.via_bank || '';

    var tdL = 'padding:5px 8px;border-bottom:1px solid #eee;font-size:0.85rem;';
    var tdW = 'padding:5px 8px;border-bottom:1px solid #eee;font-weight:600;font-size:0.85rem;width:120px;white-space:nowrap;';

    // Keresett banki pár (reverse lookup a matchData-ból)
    var bankMatch = null;
    for (var bid in matchData) {
        for (var k = 0; k < matchData[bid].length; k++) {
            if (parseInt(matchData[bid][k].ots_record_id) === parseInt(recordId)) {
                bankMatch = matchData[bid][k];
                break;
            }
        }
        if (bankMatch) break;
    }

    // BAL: OTS adatok
    var html = '<div style="display:flex;gap:20px;align-items:flex-start;">';
    html += '<div style="flex:1;min-width:0;">';
    html += '<h2 style="margin:0 0 10px 0;color:#2e7d32;font-size:1rem;">📋 OTS tétel #' + recordId + '</h2>';
    html += '<table style="width:100%;border-collapse:collapse;">';
    html += '<tr><td style="' + tdW + '">Összeg</td><td style="' + tdL + '" class="' + amtClass + '">' + fmtAmt + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Dátum</td><td style="' + tdL + '">' + (row.DATETIME || '—') + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Bizonylatszám</td><td style="' + tdL + '">' + (row.CASH_DOCUMENT_NUMBER || '—') + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Típus</td><td style="' + tdL + '">' + (typeName || typeLabel) + '</td></tr>';
    if (txName) {
        html += '<tr><td style="' + tdW + '">Megnevezés</td><td style="' + tdL + '">' + txName;
        if (txNameIndex) html += ' <small style="color:#888;">(' + txNameIndex + ')</small>';
        html += '</td></tr>';
    } else {
        if (personName) html += '<tr><td style="' + tdW + '">Személy</td><td style="' + tdL + '">' + personName + '</td></tr>';
        if (fundName) html += '<tr><td style="' + tdW + '">Alap</td><td style="' + tdL + '">' + fundName + '</td></tr>';
    }
    if (editorName) html += '<tr><td style="' + tdW + '">Rögzítette</td><td style="' + tdL + '">' + editorName + '</td></tr>';
    if (viaBank) html += '<tr><td style="' + tdW + '">VIA Bank</td><td style="' + tdL + '"><code style="background:#e8f5e9;padding:2px 6px;border-radius:3px;font-size:0.82rem;">' + viaBank + '</code></td></tr>';
    html += '<tr><td style="' + tdW + '">Tranzakciók száma</td><td style="' + tdL + '">' + (row.tx_count || 1) + '</td></tr>';
    html += '<tr><td style="' + tdW + '">Gyülekezet</td><td style="' + tdL + 'color:#2e7d32;">' + churchName + '</td></tr>';
    html += '<tr><td style="' + tdW + '">OTS oldal</td><td style="' + tdL + '"><a href="/revizor/all_transactions/all_transactions_multi.php?record_id=' + recordId + '&church_id=' + row.CHURCH_ID + '" target="_blank" style="color:#2e7d32;">Megnyitás ↗</a></td></tr>';
    html += '</table>';
    html += '</div>';

    // JOBB: Párosított banki tétel
    html += '<div style="flex:1;min-width:0;border-left:2px solid #e0e0e0;padding-left:20px;">';
    html += '<h2 style="margin:0 0 10px 0;color:#1565c0;font-size:1rem;">🏦 Banki pár</h2>';
    if (bankMatch) {
        var bRow = null;
        for (var j = 0; j < bankData.length; j++) {
            if (parseInt(bankData[j].id) === parseInt(bankMatch.bank_statement_id)) { bRow = bankData[j]; break; }
        }
        if (bRow) {
            var bAmt = parseFloat(bRow.amount || 0);
            var bAmtClass = bAmt >= 0 ? 'income' : 'expense';
            var bFmtAmt = Math.abs(bAmt).toLocaleString('hu-HU', {maximumFractionDigits:0}) + ' Ft';
            if (bAmt < 0) bFmtAmt = '- ' + bFmtAmt;
            html += '<div style="margin-bottom:12px;padding:10px;background:#f8f9fa;border-radius:6px;border-left:3px solid #1565c0;">';
            html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">';
            html += '<a href="javascript:void(0)" onclick="closeDetail();setTimeout(function(){showDetail(' + bRow.id + ')},300);" style="color:#1565c0;font-weight:600;font-size:0.95rem;">Bank #' + bRow.id + '</a>';
            html += '<span class="' + bAmtClass + '" style="font-weight:600;">' + bFmtAmt + '</span>';
            html += '</div>';
            html += '<table style="width:100%;border-collapse:collapse;">';
            html += '<tr><td style="' + tdW + '">Könyvelés</td><td style="' + tdL + '">' + (bRow.statement_date || '—') + '</td></tr>';
            html += '<tr><td style="' + tdW + '">Értéknap</td><td style="' + tdL + '">' + (bRow.value_date || '—') + '</td></tr>';
            html += '<tr><td style="' + tdW + '">Közlemény</td><td style="' + tdL + '">' + (bRow.description || '—') + '</td></tr>';
            html += '<tr><td style="' + tdW + '">Kedvezményezett</td><td style="' + tdL + '">' + (bRow.beneficiary_name || '—') + '</td></tr>';
            html += '<tr><td style="' + tdW + '">Megbízó</td><td style="' + tdL + '">' + (bRow.initiator_name || '—') + '</td></tr>';
            html += '<tr><td style="' + tdW + '">Bankszámla</td><td style="' + tdL + '">' + (bRow.bank_account || '—') + '</td></tr>';
            html += '<tr><td style="' + tdW + '">Tranzakció ID</td><td style="' + tdL + '">' + (bRow.bank_tx_id || '—') + '</td></tr>';
            html += '<tr><td style="' + tdW + '">Gyülekezet</td><td style="' + tdL + 'color:#1565c0;">' + (bRow.church_name || '—') + '</td></tr>';
            html += '</table>';
            html += '</div>';
        }
    } else {
        html += '<div style="color:#999;font-style:italic;font-size:0.85rem;">Nincs párosított banki tétel.</div>';
    }
    html += '</div>';
    html += '</div>';

    // Tized cédula tételek (AJAX) — rejtve indul
    html += '<div id="cedula-loading" style="display:none;margin-top:16px;color:#888;font-size:0.85rem;"></div>';
    html += '<div id="cedula-content"></div>';

    document.getElementById('detail-content').innerHTML = html;
    document.getElementById('detail-modal').style.display = 'block';

    // AJAX: tized cédula lekérdezés
    var cedulaUrl = window.location.pathname.replace(/[^/]*$/, '') + 'ots_detail.php?record_id=' + recordId;
    fetch(cedulaUrl)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.items || data.items.length <= 1) {
                // 1 vagy 0 sor = nem tized cédula, semmit sem jelenítünk meg
                return;
            }

            var items = data.items;
            var summary = data.summary;
            var loadingEl = document.getElementById('cedula-loading');
            loadingEl.style.display = 'block';
            loadingEl.innerHTML = '';

            var cHtml = '<div style="margin-top:16px;border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;">';
            cHtml += '<div style="background:#fff3e0;padding:8px 12px;font-weight:600;font-size:0.9rem;color:#e65100;">📄 Tized cédula tételei (' + items.length + ' sor)</div>';
            cHtml += '<table style="width:100%;border-collapse:collapse;font-size:0.82rem;">';
            cHtml += '<thead><tr style="background:#f5f5f5;">';
            cHtml += '<th style="padding:6px 8px;text-align:left;border-bottom:2px solid #ddd;">#</th>';
            cHtml += '<th style="padding:6px 8px;text-align:left;border-bottom:2px solid #ddd;">Típus</th>';
            cHtml += '<th style="padding:6px 8px;text-align:left;border-bottom:2px solid #ddd;">Megnevezés</th>';
            cHtml += '<th style="padding:6px 8px;text-align:left;border-bottom:2px solid #ddd;">Személy</th>';
            cHtml += '<th style="padding:6px 8px;text-align:left;border-bottom:2px solid #ddd;">Alap</th>';
            cHtml += '<th style="padding:6px 8px;text-align:right;border-bottom:2px solid #ddd;">Összeg</th>';
            cHtml += '</tr></thead><tbody>';

            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                var itAmt = parseFloat(it.computed_amount || 0);
                var itAmtClass = itAmt >= 0 ? 'income' : 'expense';
                var itFmtAmt = Math.abs(itAmt).toLocaleString('hu-HU', {maximumFractionDigits:0}) + ' Ft';
                if (itAmt < 0) itFmtAmt = '- ' + itFmtAmt;

                var itTypeLabel = '';
                if (it.type_id == 20 || it.type_id == 9) itTypeLabel = 'Kiadás';
                else if (it.type_id == 10 || it.type_id == 1) itTypeLabel = 'Bevétel';
                else itTypeLabel = it.type_name || '';

                var itName = it.tx_name || '';
                var itNameIdx = it.tx_name_index || '';
                var itPerson = (it.person_name || '').trim();
                var itFund = it.fund_name || '';

                cHtml += '<tr style="background:' + (i % 2 === 0 ? '#fff' : '#fafafa') + ';">';
                cHtml += '<td style="padding:5px 8px;border-bottom:1px solid #eee;color:#888;">' + (i + 1) + '</td>';
                cHtml += '<td style="padding:5px 8px;border-bottom:1px solid #eee;">' + itTypeLabel + '</td>';
                cHtml += '<td style="padding:5px 8px;border-bottom:1px solid #eee;">' + itName;
                if (itNameIdx) cHtml += ' <small style="color:#888;">(' + itNameIdx + ')</small>';
                cHtml += '</td>';
                cHtml += '<td style="padding:5px 8px;border-bottom:1px solid #eee;">' + itPerson + '</td>';
                cHtml += '<td style="padding:5px 8px;border-bottom:1px solid #eee;">' + itFund + '</td>';
                cHtml += '<td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right;" class="' + itAmtClass + '">' + itFmtAmt + '</td>';
                cHtml += '</tr>';
            }

            // Összesítő sor
            var sAmt = summary.total_amount;
            var sAmtClass = sAmt >= 0 ? 'income' : 'expense';
            var sFmtAmt = Math.abs(sAmt).toLocaleString('hu-HU', {maximumFractionDigits:0}) + ' Ft';
            if (sAmt < 0) sFmtAmt = '- ' + sFmtAmt;
            cHtml += '<tr style="background:#e8f5e9;font-weight:600;">';
            cHtml += '<td colspan="5" style="padding:6px 8px;border-bottom:1px solid #ccc;text-align:right;">Összesen:</td>';
            cHtml += '<td style="padding:6px 8px;border-bottom:1px solid #ccc;text-align:right;" class="' + sAmtClass + '">' + sFmtAmt + '</td>';
            cHtml += '</tr>';

            cHtml += '</tbody></table></div>';
            document.getElementById('cedula-content').innerHTML = cHtml;
        })
        .catch(function(err) {
            // Csendesen kezeljük — nem tized cédula esetén nem jelenítünk meg semmit
        });
}

// Összes OTS checkbox kijelölése
function toggleAll(src) {
    document.querySelectorAll('.ots-cb').forEach(function(cb) { cb.checked = src.checked; });
}

// Banki táblázat szűrés — URL paraméterekkel, SQL szintű
function filterBankTable() {
    var params = new URLSearchParams(window.location.search);
    var d = document.getElementById('bank-filter-date')?.value || '';
    var a = document.getElementById('bank-filter-amt')?.value || '';
    var desc = document.getElementById('bank-filter-desc')?.value || '';
    var s = document.getElementById('bank-filter-status')?.value || '';
    var c = document.getElementById('bank-filter-comment')?.value || '';
    if (d) params.set('f_date', d); else params.delete('f_date');
    if (a) params.set('f_amt', a); else params.delete('f_amt');
    if (desc) params.set('f_desc', desc); else params.delete('f_desc');
    if (s) params.set('f_status', s); else params.delete('f_status');
    if (c) params.set('f_comment', c); else params.delete('f_comment');
    params.delete('bank_page');
    // Ha van szűrő, per_page=all; ha nincs, visszaalapértelmezett
    if (d || a || desc || s || c) {
        params.set('per_page', 'all');
    } else {
        params.delete('per_page');
    }
    window.location.search = params.toString();
}

// Szűrő értékek visszatöltése az oldal betöltésekor
function restoreFilters() {
    var params = new URLSearchParams(window.location.search);
    var fields = {f_date: 'bank-filter-date', f_amt: 'bank-filter-amt', f_desc: 'bank-filter-desc', f_status: 'bank-filter-status', f_comment: 'bank-filter-comment'};
    for (var key in fields) {
        var val = params.get(key);
        if (val) {
            var el = document.getElementById(fields[key]);
            if (el) el.value = val;
        }
    }
}

// Táblázat szűrés szöveg alapján (OTS)
function filterTable(tableId, val) {
    var tbody = document.getElementById(tableId).querySelector('tbody');
    if (!tbody) return;
    var rows = tbody.querySelectorAll('tr');
    var filter = val.toLowerCase();
    rows.forEach(function(row) {
        if (row.querySelector('.empty')) return;
        var text = row.textContent.toLowerCase();
        row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
    });
}

// Banki tétel kijelölése és görgetés az OTS panelhez
function selectBankForMatch(bankId) {
    var rb = document.querySelector('input[name="bank_id"][value="' + bankId + '"]');
    if (rb) rb.checked = true;
    var otsPanel = document.querySelector('#ots-panel');
    if (otsPanel) otsPanel.scrollIntoView();
}

// Gyülekezet oszlop elrejtése (már csak egy gyülekezet van)
document.querySelectorAll('th[data-col-church]').forEach(function(th) { th.style.display = 'none'; });
document.querySelectorAll('.church-name').forEach(function(td) { td.style.display = 'none'; });

// Címsorok frissítése
(function() {
    var bankTitle = document.querySelector('#bank-table')?.closest('.panel')?.querySelector('h2 span:first-child');
    var otsTitle = document.querySelector('#ots-panel')?.querySelector('h2 span:first-child');
    if (bankTitle) bankTitle.textContent = '🏦 Banki tranzakciók (' + filteredTotal + ', párosított: ' + matchedCount + ')';
    if (otsTitle) otsTitle.textContent = '📋 OTS tételek (párosítatlan: ' + otsTotal + ', párosított: ' + matchedCount + ')';
})();

window.addEventListener('DOMContentLoaded', function() {
    restoreFilters();
    resizePanels();
    var overlay = document.getElementById('loading-overlay');
    if (overlay) overlay.remove();
});
window.addEventListener('resize', resizePanels);

function resizePanels() {
    var bottomBar = document.getElementById('bottom-bar');
    var matchForm = document.getElementById('match-form');
    var panels = document.querySelector('.panels');
    if (!panels || !bottomBar || !matchForm) return;
    var topY = panels.getBoundingClientRect().top;
    var bottomY = bottomBar.getBoundingClientRect().top;
    var h = Math.max(150, bottomY - topY);
    panels.style.minHeight = h + 'px';
    document.querySelectorAll('.table-wrap').forEach(function(el) {
        el.style.maxHeight = 'none';
    });
}

// Oszlop szerinti rendezés
document.querySelectorAll('th[data-col]').forEach(function(th) {
    th.addEventListener('click', function() {
        var table = th.closest('table');
        var tbody = table.querySelector('tbody');
        var rows = Array.from(tbody.querySelectorAll('tr'));
        var colIdx = parseInt(th.getAttribute('data-col'));
        var isAsc = th.classList.toggle('asc');
        th.classList.remove('desc');
        if (!isAsc) th.classList.add('desc');

        // Sort indicator
        th.querySelector('.sort').textContent = isAsc ? '▲' : '▼';

        rows.sort(function(a, b) {
            var aCell = a.cells[colIdx] ? a.cells[colIdx].textContent.trim() : '';
            var bCell = b.cells[colIdx] ? b.cells[colIdx].textContent.trim() : '';
            var aVal = aCell, bVal = bCell;
            // Numeric sort ha szám
            var aNum = parseFloat(aVal.replace(/[^0-9,\-]/g, '').replace(',', '.'));
            var bNum = parseFloat(bVal.replace(/[^0-9,\-]/g, '').replace(',', '.'));
            if (!isNaN(aNum) && !isNaN(bNum)) { aVal = aNum; bVal = bNum; }
            if (aVal < bVal) return isAsc ? -1 : 1;
            if (aVal > bVal) return isAsc ? 1 : -1;
            return 0;
        });

        rows.forEach(function(row) { tbody.appendChild(row); });
    });
});

// Űrlap validáció
document.getElementById('match-form').addEventListener('submit', function(e) {
    if (!document.querySelector('input[name="bank_id"]:checked')) {
        e.preventDefault(); alert('Válassz ki egy banki tételt a bal oldali rádiógombbal!');
    } else if (document.querySelectorAll('.ots-cb:checked').length === 0) {
        e.preventDefault(); alert('Válassz ki legalább egy OTS tételt a jobb oldalon!');
    }
});

// Panel splitter
(function() {
    var splitter = document.getElementById('panel-splitter');
    var bankPanel = document.getElementById('bank-panel');
    if (!splitter || !bankPanel) return;
    var isDragging = false;

    splitter.addEventListener('mousedown', function(e) {
        isDragging = true;
        splitter.classList.add('dragging');
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        e.preventDefault();
    });

    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        var panels = document.querySelector('.panels');
        var rect = panels.getBoundingClientRect();
        var x = e.clientX - rect.left;
        var pct = Math.min(80, Math.max(20, (x / rect.width) * 100));
        bankPanel.style.flex = '0 0 ' + pct + '%';
        localStorage.setItem('revizor_splitter_pct', pct.toString());
    });

    document.addEventListener('mouseup', function() {
        if (!isDragging) return;
        isDragging = false;
        splitter.classList.remove('dragging');
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    });

    // Mentett pozíció visszaállítása
    var saved = localStorage.getItem('revizor_splitter_pct');
    if (saved) {
        var pct = Math.min(80, Math.max(20, parseFloat(saved)));
        bankPanel.style.flex = '0 0 ' + pct + '%';
    }
})();

// Escape billentyű bezárja a popupokat
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var detailModal = document.getElementById('detail-modal');
        if (detailModal && detailModal.style.display !== 'none') {
            closeDetail();
            e.preventDefault();
        }
    }
});
</script>

</body>
</html>
