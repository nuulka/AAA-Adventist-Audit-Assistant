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

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/session.php';
build_user_context_from_ots();

$session_remaining = ensure_revizor_session_timeout();
ensure_revizor_csrf_token();

$accessible = get_accessible_church_ids();
$ots = get_ots_conn();

/** Csak relatív (biztonságos) átirányítást engedélyez */
function safe_redirect(string $url): string {
    $parsed = parse_url($url);
    if ($parsed === false) return 'index.php';
    // Ha van scheme vagy host, eldobjuk – csak relatív URL-t engedünk
    if (isset($parsed['scheme']) || isset($parsed['host'])) return 'index.php';
    // Csak a megengedett fájlokra irányíthatunk
    $allowed = ['index.php', 'help.php', 'upload.php', 'document_check.php', 'search.php', 'reconciliation.php', 'select-church.php', 'all_transactions/all_transactions_multi.php', 'match_progress.php'];
    $path = $parsed['path'] ?? '';
    $path = ltrim($path, '/');
    return in_array($path, $allowed, true) ? $url : 'index.php';
}

// Mentés
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['church_id'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        http_response_code(400);
        echo 'CSRF token mismatch';
        exit;
    }
    $cid = intval($_POST['church_id']);
    if ($cid > 0 && (is_admin() || (is_array($accessible) && in_array($cid, $accessible, true)))) {
        set_selected_church_session($cid);
    }
    $redirect = isset($_POST['redirect']) ? safe_redirect($_POST['redirect']) : 'index.php';
    header("Location: $redirect");
    exit;
}

// Gyülekezet lista lekérése
$churches = [];
$churches_by_id = [];
$table_candidates = ['CHURCHES', 'churches'];

// 1. Konfigból (app.local.php) próbáljuk
$cfg = load_app_config();
if (!empty($cfg['churches']) && is_array($cfg['churches'])) {
    $churches_by_id = $cfg['churches'];
    foreach ($churches_by_id as $id => $name) {
        $churches[] = ['id' => $id, 'name' => $name];
    }
}

// 2. Ha konfig üres, próbáljuk OTS-ből
if (empty($churches)) {
    if (is_admin()) {
        // Admin: minden gyülekezet lekérése (táblanév kis/nagybetű próbálgatással)
        foreach ($table_candidates as $tbl) {
            $all = $ots->query("SELECT id, name FROM $tbl WHERE name IS NOT NULL AND name != '' ORDER BY name ASC");
            if ($all && $all->num_rows > 0) {
                while ($r = $all->fetch_assoc()) {
                    $churches[] = $r;
                    $churches_by_id[$r['id']] = $r['name'];
                }
                break;
            }
        }
    } elseif (is_array($accessible) && count($accessible) > 0) {
        // Nem admin: csak a hozzárendelt gyülekezetek
        $placeholders = implode(',', array_fill(0, count($accessible), '?'));
        $types = str_repeat('i', count($accessible));
        foreach ($table_candidates as $tbl) {
            $stmt = $ots->prepare("SELECT id, name FROM $tbl WHERE id IN ($placeholders) AND name IS NOT NULL AND name != '' ORDER BY name ASC");
            if ($stmt) {
                $stmt->bind_param($types, ...$accessible);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $churches[] = $r;
                    $churches_by_id[$r['id']] = $r['name'];
                }
                if (!empty($churches)) break;
            }
        }
    }
}

// Ha nincs lekérhető gyülekezet, hibaüzenet — ne irányítsunk vissza index.php-ba (végtelen loop)
if (empty($churches)) {
    $err_msg = 'Nem sikerült betölteni a gyülekezeti listát. Az OTS adatbázis kapcsolat nem elérhető, vagy a felhasználónak nincs hozzárendelve gyülekezet.';
    if (!empty($accessible) && is_array($accessible)) {
        $err_msg .= ' (OTS user_id: ' . intval($_SESSION[GN_USER_ID] ?? 0) . ', elérhető ID-k: ' . implode(',', $accessible) . ')';
    }
    $err_msg .= ' | MySQL error: ' . ($ots->error ?? 'n/a') . ' | próbált táblák: ' . implode(', ', $table_candidates);
    ?><!DOCTYPE html><html lang="hu"><head><meta charset="UTF-8"><title>Hiba – Revizor</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body><div class="container mt-5"><div class="alert alert-danger"><h5>⚠️ Hiba</h5><p><?= htmlspecialchars($err_msg) ?></p><a href="logout.php" class="btn btn-outline-secondary">Kijelentkezés</a></div></div></body></html><?php
    exit;
}

$redirect_to = isset($_GET['redirect']) ? safe_redirect($_GET['redirect']) : 'index.php';
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🕵️ Revizor - Gyülekezet választás</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { border: none; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); max-width: 500px; width: 100%; }
        .church-btn { display: block; width: 100%; padding: 12px 16px; border: 1px solid #dee2e6; border-radius: 8px; background: white; text-align: left; font-size: 1rem; transition: all .15s; cursor: pointer; }
        .church-btn:hover { border-color: #0d6efd; background: #f0f4ff; }
        .church-btn:focus { outline: 2px solid #0d6efd; outline-offset: 2px; }
    </style>
</head>
<body>
    <div class="card p-4">
        <div class="text-center mb-3">
            <div style="font-size:48px;">🏛️</div>
            <h4 class="mb-1">Válassz gyülekezetet</h4>
            <p class="text-muted small mb-0">Melyik gyülekezettel szeretnél dolgozni? <kbd class="bg-light text-dark border px-1" style="font-size:11px;border-radius:3px;">Betű</kbd> billentyűvel ugrás</p>
        </div>
        <form method="POST" id="churchForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect_to); ?>">
            <div class="d-flex flex-column gap-2 mb-2" id="churchList">
                <?php foreach ($churches as $c): ?>
                <button type="submit" name="church_id" value="<?php echo $c['id']; ?>" class="church-btn"
                    data-name="<?php echo htmlspecialchars($c['name']); ?>"
                    <?php if (isset($_SESSION['revizor_selected_church']) && $_SESSION['revizor_selected_church'] == $c['id']) echo 'style="border-color:#0d6efd;background:#e7f1ff;font-weight:600;"'; ?>>
                    🏛 <?php echo htmlspecialchars($c['name']); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
    <script>
    var lastKeyTime = 0;
    var keyBuffer = '';
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey || e.altKey || e.metaKey) return;
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        var key = e.key;
        if (key.length !== 1) return;
        var now = Date.now();
        if (now - lastKeyTime > 800) keyBuffer = '';
        lastKeyTime = now;
        keyBuffer += key.toLowerCase();
        var buttons = document.querySelectorAll('#churchList .church-btn');
        var found = null;
        var bestIndex = -1;
        if (keyBuffer.length === 1) {
            for (var i = 0; i < buttons.length; i++) {
                var name = buttons[i].getAttribute('data-name') || '';
                if (name.charAt(0).toLowerCase() === key) { found = buttons[i]; bestIndex = i; break; }
            }
        } else {
            for (var i = 0; i < buttons.length; i++) {
                var name = buttons[i].getAttribute('data-name') || '';
                if (name.toLowerCase().substring(0, keyBuffer.length) === keyBuffer) { found = buttons[i]; bestIndex = i; break; }
            }
        }
        if (found) {
            e.preventDefault();
            found.scrollIntoView({ behavior: 'smooth', block: 'center' });
            found.focus({ preventScroll: true });
            document.querySelectorAll('.church-btn').forEach(function(b) { b.style.borderColor = '#dee2e6'; b.style.background = 'white'; });
            found.style.borderColor = '#0d6efd';
            found.style.background = '#f0f4ff';
        }
    });
    </script>
</body>
</html>
