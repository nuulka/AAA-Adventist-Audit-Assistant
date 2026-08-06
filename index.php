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

// load auth helpers and build context
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/session.php';
build_user_context_from_ots();
require_selected_church('index.php');

$session_remaining = ensure_revizor_session_timeout();
ensure_revizor_csrf_token();
log_activity('page_view', ['page' => 'index']);
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🕵️ Revizor Asszisztens 1.0 - Nyitó</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); transition: transform .15s; }
        .card:hover { transform: translateY(-2px); }
        .card-link { text-decoration: none; color: inherit; }
        .icon-circle { width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; }
    </style>
</head>
<body>
<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4 px-3 py-2 bg-white rounded border shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold">🕵️ Revizor Asszisztens 1.0</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted">Nyitó</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <a href="help.php" class="btn btn-outline-primary btn-sm">❓ Súgó</a>
            <?php render_dev_toggle(); ?>
            <?php render_church_badge(); ?>
            <?php render_user_badge(); ?>
            <a href="logout.php" class="btn btn-outline-danger btn-sm ms-1">Kilépés</a>
        </div>
    </div>

    <div class="text-center mb-4">
        <h1 class="display-6 fw-bold">🕵️ Revizor Asszisztens 1.0</h1>
        <p class="text-muted lead">Bankegyeztető és bizonylat ellenőrző rendszer</p>
        <p class="text-muted small">Bejelentkezve: <strong><?php echo htmlspecialchars($_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen'); ?></strong></p>
    </div>

    <div class="row g-4 justify-content-center">
        <div class="col-md-4">
            <a href="reconciliation.php" class="card-link">
                <div class="card p-4 h-100">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="icon-circle bg-primary bg-opacity-10 text-primary">🏦</div>
                        <div>
                            <h5 class="mb-0">Bankegyeztetés</h5>
                            <small class="text-muted">Fő egyeztető felület</small>
                        </div>
                    </div>
                    <p class="text-muted small mb-0">Banki kivonatok és OTS tételek összehasonlítása, automatikus párosítás, státuszkezelés.</p>
                </div>
            </a>
        </div>
        <?php if (is_admin()): ?>
        <div class="col-md-4">
            <a href="upload.php" class="card-link">
                <div class="card p-4 h-100">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="icon-circle bg-success bg-opacity-10 text-success">📥</div>
                        <div>
                            <h5 class="mb-0">Feltöltés</h5>
                            <small class="text-muted">CSV import &amp; párosítás</small>
                        </div>
                    </div>
                    <p class="text-muted small mb-0">Banki CSV fájlok feltöltése, automatikus párosítás OTS tételekkel.</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="activity_log.php" class="card-link">
                <div class="card p-4 h-100">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="icon-circle bg-danger bg-opacity-10 text-danger">📋</div>
                        <div>
                            <h5 class="mb-0">Használati Napló</h5>
                            <small class="text-muted">Admin naplózás</small>
                        </div>
                    </div>
                    <p class="text-muted small mb-0">Felhasználói tevékenységek naplózása: be-/kijelentkezés, oldalmegtekintések, módosítások.</p>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <div class="col-md-4">
            <a href="all_transactions/all_transactions_multi.php" class="card-link">
                <div class="card p-4 h-100">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="icon-circle bg-info bg-opacity-10 text-info">📤</div>
                        <div>
                            <h5 class="mb-0">OTS Letöltő</h5>
                            <small class="text-muted">Tranzakciók kezelése</small>
                        </div>
                    </div>
                    <p class="text-muted small mb-0">OTS tranzakciók letöltése, feltöltése és szerkesztése.</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="search.php" class="card-link">
                <div class="card p-4 h-100">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="icon-circle bg-secondary bg-opacity-10 text-secondary">🔍</div>
                        <div>
                            <h5 class="mb-0">Tranzakció Kereső</h5>
                            <small class="text-muted">Keresés bankban &amp; OTS-ben</small>
                        </div>
                    </div>
                    <p class="text-muted small mb-0">Célzott keresés a banki és OTS tranzakciókban összeg, dátum, szöveg alapján, szerver oldali lekérdezéssel.</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="help.php" class="card-link">
                <div class="card p-4 h-100">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="icon-circle bg-warning bg-opacity-10 text-warning">❓</div>
                        <div>
                            <h5 class="mb-0">Segítség</h5>
                            <small class="text-muted">Használati útmutató</small>
                        </div>
                    </div>
                    <p class="text-muted small mb-0">Rendszer használata, funkciók leírása, gyakori kérdések.</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="document_check.php" class="card-link">
                <div class="card p-4 h-100">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="icon-circle bg-success bg-opacity-10 text-success">📋</div>
                        <div>
                            <h5 class="mb-0">Bizonylat Ellenőrzés</h5>
                            <small class="text-muted">Ellenőrző lista</small>
                        </div>
                    </div>
                    <p class="text-muted small mb-0">Banki tételek dokumentum ellenőrzése: bizonylatok, aláírások, mellékletek megléte.</p>
                </div>
            </a>
        </div>
    </div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

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
var CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
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
</script>

</body>
</html>
