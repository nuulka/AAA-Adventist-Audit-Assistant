<?php
require_once __DIR__ . '/../ots/constant.php';

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// AJAX session check endpoint — must run before session_destroy
if (isset($_GET['check'])) {
    header('Content-Type: application/json');
    if (session_status() != PHP_SESSION_ACTIVE) { session_start(); }
    require_once __DIR__ . '/../ots/session_handler.php';
    echo json_encode(['logged_in' => isset($_SESSION[GC_LOGIN_COOKIE])]);
    exit;
}

if (session_status() != PHP_SESSION_ACTIVE) { session_start(); }
$just_logged_in = isset($_GET['ots_ready']) && isset($_SESSION[GC_LOGIN_COOKIE]);
if ($just_logged_in) {
    // ensure session handler has populated OTS session vars, then build revizor user context
    try {
        require_once __DIR__ . '/../ots/session_handler.php';
        require_once __DIR__ . '/lib/bootstrap.php';
        require_once __DIR__ . '/lib/activity_log.php';
        require_once __DIR__ . '/lib/auth.php';
        // populate accessible churches from OTS ROLES
        build_user_context_from_ots();
        // set app role into session
        if (is_admin()) {
            $_SESSION['revizor_app_role'] = 'admin';
        } elseif (is_revizor()) {
            $_SESSION['revizor_app_role'] = 'revizor';
        } else {
            $_SESSION['revizor_app_role'] = 'user';
        }
        // if user has exactly one accessible church, set selected
        $acs = get_accessible_church_ids();
        if (is_array($acs) && count($acs) === 1) {
            set_selected_church_session($acs[0]);
        } elseif (is_array($acs) && count($acs) > 1) {
            // multiple churches — redirect to selection page
            session_regenerate_id(true);
            header('Location: select-church.php?redirect=index.php');
            exit;
        }
        $role = $_SESSION['revizor_app_role'] ?? 'unknown';
        log_activity('login', ['role' => $role, 'churches' => is_array($acs) ? count($acs) : 0]);
        session_regenerate_id(true);
    } catch (Throwable $e) {
        // ignore, fall back to default redirect
    }
    header('Location: index.php');
    exit;
}
$session_expired = isset($_SESSION[GC_LOGIN_COOKIE]) || isset($_SESSION['revizor_app_role']);
session_destroy();
?><!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>🕵️ Revizor Asszisztens 1.0 - Bejelentkezés</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { max-width: 420px; width: 100%; background: white; padding: 40px; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); text-align: center; }
        .logo { font-size: 48px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo">🏦</div>
        <h4 class="mb-1">🕵️ Revizor Asszisztens 1.0</h4>
        <p class="text-muted small mb-4">Bankegyeztető és bizonylat ellenőrző rendszer</p>
        <hr>
        <p class="mb-3">Jelentkezz be az OTS rendszerbe!<?php if ($session_expired): ?> A munkameneted lejárt.<?php endif; ?></p>
        <ol class="text-start small text-muted mb-4">
            <li>Kattints a <strong>Belépés az OTS-be</strong> gombra</li>
            <li>Jelentkezz be az OTS felületén (felugró ablakban nyílik meg)</li>
            <li>Ha sikeres a belépés, automatikusan átirányítunk a revizorba</li>
        </ol>
        <p class="text-danger small mb-3" id="popupBlockedMsg" style="display:none;">⚠️ A felugró ablakot blokkolta a böngésző. Engedélyezd a felugró ablakokat erre az oldalra, vagy használd a lenti linket.</p>
        <div class="d-grid gap-2">
            <button class="btn btn-primary btn-lg" onclick="openOtsLogin()">🔑 Belépés az OTS-be</button>
            <a href="index.php" class="btn btn-outline-success btn-sm" id="fallbackContinueBtn" style="display:none;">✅ Már beléptem, folytatom</a>
        </div>
        <div id="loginStatus" class="mt-3 text-muted small"></div>
    </div>
    <script>
    var otsPopup = null;

    function openOtsLogin() {
        otsPopup = window.open('../ots/index.php', 'otsLogin', 'width=1200,height=800,menubar=no,toolbar=yes,scrollbars=yes');
        if (!otsPopup || otsPopup.closed || typeof otsPopup.closed === 'undefined') {
            document.getElementById('popupBlockedMsg').style.display = 'block';
            document.getElementById('fallbackContinueBtn').style.display = 'block';
            return;
        }
        document.getElementById('loginStatus').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>OTS belépés figyelése...';
        pollSession();
    }

    function pollSession() {
        fetch('login.php?check=1')
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.logged_in) {
                document.getElementById('loginStatus').innerHTML = '✅ Sikeres belépés! Átirányítás...';
                if (otsPopup && !otsPopup.closed) { try { otsPopup.close(); } catch(e) {} }
                window.location.href = 'login.php?ots_ready=1';
            } else {
                setTimeout(pollSession, 1500);
            }
        })
        .catch(function() {
            setTimeout(pollSession, 2000);
        });
    }

    // Fallback: mutassuk a "Beléptem" gombot 30 mp után is
    setTimeout(function() {
        document.getElementById('fallbackContinueBtn').style.display = 'block';
    }, 30000);
    </script>
</body>
</html>
