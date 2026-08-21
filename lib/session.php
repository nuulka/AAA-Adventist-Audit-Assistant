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
        $is_post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        if ($is_post) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['status' => 'SESSION_EXPIRED', 'message' => 'A munkamenet lejárt. Kérjük, jelentkezzen be újra.']);
        } else {
            header('Location: login.php');
        }
        session_destroy();
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
