<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/app/Services/CsvParserService.php';

header('Content-Type: application/json');

$response = ["status" => "error", "message" => "Ismeretlen hiba."];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    try {
        $filePath = $_FILES['csv_file']['tmp_name'];
        $pdo = get_pdo_connection();

            // Betöltjük a kihagyandó (területi) bankszámlák listáját
            $skipAccounts = [];
            $stmt = $pdo->query("SELECT bank_account_number FROM church_bank_accounts WHERE skip_import = 1 OR church_id = 0");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $clean = preg_replace('/[^0-9]/', '', $r['bank_account_number']);
                if ($clean) $skipAccounts[$clean] = true;
            }

            // OTS-ből is lekérjük a területi gyülekezet bankszámláit (church_id = 76)
            try {
                $otsPdo = get_ots_pdo();
                $st = $otsPdo->query("SELECT BANK_ACCOUNT_NUMBER1, BANK_ACCOUNT_NUMBER2 FROM ots.churches WHERE id = 76");
                $tetAcc = $st->fetch(PDO::FETCH_ASSOC);
                if ($tetAcc) {
                    foreach ([$tetAcc['BANK_ACCOUNT_NUMBER1'], $tetAcc['BANK_ACCOUNT_NUMBER2']] as $acc) {
                        $clean = preg_replace('/[^0-9]/', '', $acc ?? '');
                        if ($clean) $skipAccounts[$clean] = true;
                    }
                }
            } catch (Exception $e) {
                // OTS esetleg nem elérhető
            }

            $parser = new CsvParserService($pdo, $skipAccounts);
            $total = $parser->importCsv($filePath);

            if ($total > 0) {
                // Import után töröljük a területi bankszámlákhoz tartozó tételeket
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

                // Auto-matching
                try {
                    require_once __DIR__ . '/app/Services/BankReconciliationService.php';
                    $engine = new BankReconciliationService($pdo);
                    $engine->runAutoMatching();
                } catch (Exception $e) {
                    $response = [
                        "status" => "success",
                        "message" => "$total tétel importálva (a párosítás átugorva: " . $e->getMessage() . ")"
                    ];
                    echo json_encode($response);
                    exit;
                }

                $response = ["status" => "success", "message" => "$total tétel importálva és párosítva."];
            } else {
                $response = ["status" => "error", "message" => "Sérült CSV fájl vagy nem megfelelő formátum."];
            }
    } catch (Exception $e) {
        $response = ["status" => "error", "message" => "PHP hiba: " . $e->getMessage()];
    }
    echo json_encode($response);
    exit;
}

echo json_encode([
    "status" => "error",
    "message" => "Nincs fájl. METHOD=" . $_SERVER['REQUEST_METHOD'],
    "files_keys" => array_keys($_FILES),
    "post_keys" => array_keys($_POST),
    "files_data" => isset($_FILES['csv_file']) ? $_FILES['csv_file'] : null
]);
