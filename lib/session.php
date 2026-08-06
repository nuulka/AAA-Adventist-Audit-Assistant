<?php

function ensure_revizor_session_started() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function ensure_revizor_csrf_token() {
    ensure_revizor_session_started();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function ensure_revizor_session_timeout() {
    ensure_revizor_session_started();
    if (!defined('REVIZOR_SESSION_DURATION')) {
        define('REVIZOR_SESSION_DURATION', 1200);
    }
    if (!isset($_SESSION['revizor_expires_at'])) {
        $_SESSION['revizor_expires_at'] = time() + REVIZOR_SESSION_DURATION;
    }
    if (time() >= $_SESSION['revizor_expires_at']) {
        session_destroy();
        header('Location: login.php');
        exit;
    }
    return $_SESSION['revizor_expires_at'] - time();
}

function refresh_revizor_session_timeout() {
    ensure_revizor_session_started();
    if (!defined('REVIZOR_SESSION_DURATION')) {
        define('REVIZOR_SESSION_DURATION', 1200);
    }
    $_SESSION['revizor_expires_at'] = time() + REVIZOR_SESSION_DURATION;
    $_SESSION[GN_LAST_ACTIVE] = time();
    return $_SESSION['revizor_expires_at'] - time();
}

// Activity log helper — always available
require_once __DIR__ . '/activity_log.php';
