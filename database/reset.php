<?php
/**
 * Revizor Banki adatok törlése
 * Admin-only: TRUNCATE only the imported bank transaction tables,
 * keeps configuration (custom_patterns, church_bank_accounts, provider_keywords, audit_checklist).
 */

$data_tables = ['bank_reconciliation', 'bank_reconciliation_items'];

if (php_sapi_name() === 'cli') {
    echo "=== Revizor banki adatok törlése ===\n";
    echo "A következő táblák ÜRÍTÉSE: " . implode(', ', $data_tables) . "\n";
    echo "Konfigurációs táblák (custom_patterns, church_bank_accounts, stb.) MEGMARADNAK.\n";
    echo "Folytatod? (igen/nem): ";
    $handle = fopen('php://stdin', 'r');
    $answer = trim(fgets($handle));
    if ($answer !== 'igen') { echo "Megszakítva.\n"; exit; }
    require_once __DIR__ . '/../lib/bootstrap.php';
    $conn = get_revizor_conn();
} else {
    require_once __DIR__ . '/../../ots/constant.php';
    session_start();
    $_SESSION[GN_LAST_ACTIVE] = time();
    require_once __DIR__ . '/../../ots/session_handler.php';
    if (!isset($_SESSION[GC_LOGIN_COOKIE])) { header('Location: ../login.php'); exit; }
    require_once __DIR__ . '/../lib/bootstrap.php';
    require_once __DIR__ . '/../lib/auth.php';
    if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    if (!is_admin()) {
        http_response_code(403);
        ?><!DOCTYPE html><html lang="hu"><head><meta charset="UTF-8"><title>Nincs jogosultság</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        </head><body class="container py-5">
        <div class="text-center py-5">
            <div class="display-1 text-danger mb-3">🚫</div>
            <h3 class="fw-bold mb-2">Ehhez nincs jogosultságod.</h3>
            <p class="text-muted lead mb-4">Ezt a funkciót csak az adminisztrátor használhatja.</p>
            <a href="../index.php" class="btn btn-primary">← Vissza a kezdőlapra</a>
        </div></body></html><?php
        exit;
    }

    $conn = get_revizor_conn();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
        // proceed
    } else {
        ?>
        <!DOCTYPE html><html lang="hu"><head><meta charset="UTF-8"><title>Banki adatok törlése</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        </head><body class="container py-5">
        <div class="card border-warning"><div class="card-header bg-warning text-dark">⚠️ Banki adatok törlése</div>
        <div class="card-body">
        <p class="fw-bold">Ez a művelet KITÖRLI a feltöltött banki tranzakciókat az alábbi táblákból:</p>
        <ul><li><code>bank_reconciliation</code></li><li><code>bank_reconciliation_items</code></li></ul>
        <p class="text-muted">Konfigurációs adatok (custom_patterns, church_bank_accounts, provider_keywords, audit_checklist) <strong>megmaradnak</strong>.</p>
        <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <button type="submit" name="confirm" value="1" class="btn btn-warning"
        onclick="return confirm('Biztosan törlöd az összes banki rekordot?')">Igen, ürítsd ki a banki adatokat</button>
        <a href="../index.php" class="btn btn-secondary ms-2">Mégsem</a></form>
        </div></div></body></html>
        <?php
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        http_response_code(400);
        echo 'CSRF token mismatch';
        exit;
    }
}

$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$count = 0;
foreach ($data_tables as $table) {
    $conn->query("TRUNCATE TABLE `$table`");
    if ($conn->error) {
        $msg = "HIBA: $table - " . $conn->error;
        if (php_sapi_name() === 'cli') { echo "  $msg\n"; }
        else { $errors[] = $msg; }
    } else {
        $count++;
        if (php_sapi_name() === 'cli') { echo "  TRUNCATED: $table\n"; }
    }
}
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

if (php_sapi_name() === 'cli') {
    echo "Kész. $count tábla kiürítve.\n";
} else {
    ?>
    <!DOCTYPE html><html lang="hu"><head><meta charset="UTF-8"><title>Kész</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head><body class="container py-5">
    <div class="card border-success"><div class="card-header bg-success text-white">✅ Kész</div>
    <div class="card-body">
    <p><?= $count ?> tábla kiürítve: <code>bank_reconciliation</code>, <code>bank_reconciliation_items</code>.</p>
    <p class="text-muted small">Konfigurációs adatok megmaradtak.</p>
    <a href="../index.php" class="btn btn-primary">Vissza a főoldalra</a>
    </div></div></body></html>
    <?php
}
