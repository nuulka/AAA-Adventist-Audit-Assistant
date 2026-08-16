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
if (is_file(__DIR__ . '/lib/announcement.php')) {
    require_once __DIR__ . '/lib/announcement.php';
}
build_user_context_from_ots();
require_selected_church('admin_message.php');

if (!is_admin()) {
    log_activity('access_denied', ['page' => 'admin_message']);
    header('Location: index.php');
    exit;
}

$session_remaining = ensure_revizor_session_timeout();
ensure_revizor_csrf_token();
log_activity('page_view', ['page' => 'admin_message']);

$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted_csrf)) {
        $error = 'Érvénytelen CSRF token.';
    } elseif (!is_admin()) {
        $error = 'Nincs jogosultságod ehhez a művelethez.';
    } else {
        $message = trim((string)($_POST['message'] ?? ''));
        $active = !empty($_POST['active']) ? 1 : 0;
        if ($message === '') {
            $error = 'Az üzenet nem lehet üres.';
        } elseif (function_exists('save_announcement') && save_announcement($message, $active)) {
            log_activity('announcement_save', ['length' => mb_strlen($message), 'active' => $active]);
            $success = 'Az üzenet mentve.';
        } else {
            $error = 'Hiba a mentés során.';
        }
    }
}

$current = function_exists('get_active_announcement') ? get_active_announcement() : null;
$current_message = $current['message'] ?? '';
$current_active = !empty($current['active']);
$current_by = $current['created_by_name'] ?? '';
$current_at = $current['updated_at'] ?? '';
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>📢 Üzenő - Revizor Asszisztens</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
    </style>
</head>
<body>
<div class="container py-4" style="max-width: 720px;">

    <div class="d-flex justify-content-between align-items-center mb-4 px-3 py-2 bg-white rounded border shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold">🕵️ Revizor Asszisztens 1.0</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted">📢 Üzenő</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <a href="index.php" class="btn btn-outline-primary btn-sm">🏠 Nyitó</a>
            <?php render_user_badge(); ?>
            <a href="logout.php" class="btn btn-outline-danger btn-sm ms-1">Kilépés</a>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="mb-1">📢 Belépéskori üzenő</h5>
        <p class="text-muted small mb-0">Az itt mentett üzenet minden bejelentkezett felhasználónak felugrik (munkamenetenként egyszer), OK gombbal zárható. Csak adminisztrátor írhat.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card p-4">
        <form method="POST" action="admin_message.php">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="mb-3">
                <label class="form-label fw-bold" for="message">Üzenet</label>
                <textarea class="form-control" id="message" name="message" rows="6" placeholder="Írd ide az üzenetet, amit minden revizornak látnia kell..."><?= htmlspecialchars($current_message, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="active" name="active" value="1" <?= $current_active ? 'checked' : '' ?>>
                <label class="form-check-label" for="active">Aktív (megjelenjen a felugróban)</label>
            </div>
            <button type="submit" class="btn btn-primary">💾 Mentés</button>
            <a href="index.php" class="btn btn-outline-secondary">Mégse</a>
        </form>
    </div>

    <?php if ($current_message !== ''): ?>
        <div class="card p-4 mt-4">
            <h6 class="mb-2">Jelenlegi üzenet</h6>
            <div class="border rounded p-3 bg-light" style="white-space:pre-wrap;"><?= htmlspecialchars($current_message, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="small text-muted mt-2">
                Státusz: <?= $current_active ? '✅ aktív' : '⏸ inaktív' ?> ·
                Írta: <?= htmlspecialchars($current_by, ENT_QUOTES, 'UTF-8') ?> ·
                Utolsó frissítés: <?= htmlspecialchars($current_at, ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if (function_exists('render_announcement_modal')) render_announcement_modal(); ?>
</body>
</html>
