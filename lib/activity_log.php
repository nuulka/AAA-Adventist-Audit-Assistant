<?php
require_once __DIR__ . '/bootstrap.php';

/**
 * Naplózási segédfüggvények a Revizor Asszisztenshez.
 * Használat: log_activity('login', ['role' => 'admin']);
 */

/** Tábla létrehozása, ha még nem létezik */
function ensure_activity_log_table() {
    $conn = get_revizor_conn();
    $conn->query("CREATE TABLE IF NOT EXISTS user_activity_log (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL COMMENT 'OTS GN_USER_ID',
        user_name   VARCHAR(100) DEFAULT NULL,
        ip_address  VARCHAR(45) DEFAULT NULL,
        action      VARCHAR(50) NOT NULL COMMENT 'login / logout / page_view / audit_save / status_change / upload / search / error',
        details     JSON DEFAULT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_action (action),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Egy naplóbejegyzés rögzítése */
function log_activity(string $action, array $details = []) {
    $userId = (int)($_SESSION[GN_USER_ID] ?? 0);
    $userName = $_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($forwarded[0]);
    }
    $detailsJson = !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;
    try {
        ensure_activity_log_table();
        $conn = get_revizor_conn();
        $stmt = $conn->prepare("INSERT INTO user_activity_log (user_id, user_name, ip_address, action, details) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('issss', $userId, $userName, $ip, $action, $detailsJson);
            $stmt->execute();
        }
    } catch (Throwable $e) {
        error_log('Activity log error: ' . $e->getMessage());
    }
}

/** Régi naplóbejegyzések törlése (default: 3 hónapnál régebbiek) */
function cleanup_activity_log($months = 3) {
    try {
        ensure_activity_log_table();
        $conn = get_revizor_conn();
        $stmt = $conn->prepare("DELETE FROM user_activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? MONTH)");
        if ($stmt) {
            $stmt->bind_param('i', $months);
            $stmt->execute();
            return $stmt->affected_rows;
        }
    } catch (Throwable $e) {
        error_log('Activity log cleanup error: ' . $e->getMessage());
    }
    return 0;
}
