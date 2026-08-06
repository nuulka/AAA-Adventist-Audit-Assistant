<?php
// Google AI Modul — Diagnosztikai oldal
error_reporting(0);
ini_set('display_errors', 0);

session_start();
if (empty($_SESSION['selected_church_id'])) {
    echo "<h2>Hozzáférés megtagadva</h2><p>Jelentkezz be a Google AI Modulba.</p>";
    exit;
}

echo "<h2>Google AI Modul — Diagnosztika</h2>";

// 1. Könyvtárstruktúra ellenőrzése
echo "<h3>1. Fájlrendszer</h3>";
$files = [
    'db_connect.php',
    'import_bank_statement.php',
    'update_reconciliation.php',
    'api_manual_match.php',
    'api_get_bank_statements.php',
    'api_get_unmatched_ots.php',
    'api_learn_rule.php',
    'bank_reconciliation.php',
    'app/Services/ReconciliationStatus.php',
    'app/Services/CsvParserService.php',
    'app/Services/BankReconciliationService.php',
    'app/Services/AdvancedReconciliationService.php',
    'public/js/bank_reconciliation.js',
    'database/schema.sql',
];
echo "<ul>";
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    $exists = file_exists($path);
    $status = $exists ? "OK" : "<b>HIÁNYZIK</b>";
    $size = $exists ? " (" . filesize($path) . " bájt)" : "";
    echo "<li>$f — $status$size</li>";
}
echo "</ul>";

// 2. Config betöltés
echo "<h3>2. Config</h3>";
$cfgFile = __DIR__ . '/../config/app.php';
if (file_exists($cfgFile)) {
    echo "config/app.php betölthető: OK<br>";
    $cfg = include $cfgFile;
    echo "revizor DB: " . ($cfg['db']['revizor']['host'] ?? '?') . " / " . ($cfg['db']['revizor']['name'] ?? '?') . "<br>";
    echo "ots DB: " . ($cfg['db']['ots']['host'] ?? '?') . " / " . ($cfg['db']['ots']['name'] ?? '?') . "<br>";
} else {
    echo "config/app.php: NEM TALÁLHATÓ — a szülő projekt config mappája nem elérhető<br>";
}

// 3. Adatbázis kapcsolat
echo "<h3>3. Adatbázis kapcsolatok</h3>";
try {
    require_once __DIR__ . '/db_connect.php';
    $pdo = get_pdo_connection();
    echo "test_google_revizor_db kapcsolat: OK<br>";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Táblák: " . implode(', ', $tables) . "<br>";
} catch (Exception $e) {
    echo "test_google_revizor_db: HIBA — " . $e->getMessage() . "<br>";
}

try {
    $otsPdo = get_ots_pdo();
    echo "ots DB kapcsolat: OK<br>";
    $otsTables = $otsPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "OTS táblák: " . count($otsTables) . " db<br>";
} catch (Exception $e) {
    echo "ots DB: HIBA — " . $e->getMessage() . "<br>";
}

// 4. Webix elérhetőség
echo "<h3>4. Webix CDN</h3>";
$webixUrl = "https://cdn.jsdelivr.net/npm/webix@10.2.0/webix.min.js";
$headers = @get_headers($webixUrl);
if ($headers && strpos($headers[0], '200') !== false) {
    echo "Webix CDN elérhető: OK<br>";
} else {
    echo "Webix CDN: NEM ELÉRHETŐ (internet kell hozzá)<br>";
}

echo "<hr>";
echo "<a href='bank_reconciliation.php'>Tovább a modulhoz</a>";
