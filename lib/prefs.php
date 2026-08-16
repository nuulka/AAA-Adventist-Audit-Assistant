<?php
// Felhasználói preferenciák rövid távú (7 napos) megőrzése a revizor DB-ben.
// A session csak a munkamenet alatt él, ezért a kiválasztott gyülekezetet
// és a vizsgált hónapot (dátumtartományt) DB-ben tároljuk legalább 1 hétig.
require_once __DIR__ . '/bootstrap.php';

function ensure_user_prefs_table() {
    static $done = false;
    if ($done) return;
    $done = true;
    $conn = get_revizor_conn();
    $conn->query("CREATE TABLE IF NOT EXISTS user_prefs (
        user_id    INT NOT NULL COMMENT 'OTS GN_USER_ID',
        pref_key   VARCHAR(64) NOT NULL,
        pref_value VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME DEFAULT NULL,
        PRIMARY KEY (user_id, pref_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Mentett preferencia olvasása (7 napnál frissebb).
 * @return string|false üres, ha nincs, false ha lejárt/hiba
 */
function get_user_pref($key) {
    $uid = intval($_SESSION[GN_USER_ID] ?? 0);
    if ($uid <= 0) return '';
    try {
        ensure_user_prefs_table();
        $conn = get_revizor_conn();
        $stmt = $conn->prepare("SELECT pref_value FROM user_prefs WHERE user_id = ? AND pref_key = ? AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('is', $uid, $key);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $r = $res->fetch_assoc();
                return (string)$r['pref_value'];
            }
        }
    } catch (Throwable $e) {
        error_log('get_user_pref error: ' . $e->getMessage());
    }
    return '';
}

/** Preferencia mentése (upsert). */
function set_user_pref($key, $value) {
    $uid = intval($_SESSION[GN_USER_ID] ?? 0);
    if ($uid <= 0) return;
    $value = (string)$value;
    try {
        ensure_user_prefs_table();
        $conn = get_revizor_conn();
        $stmt = $conn->prepare("INSERT INTO user_prefs (user_id, pref_key, pref_value, updated_at)
                                VALUES (?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = NOW()");
        if ($stmt) {
            $stmt->bind_param('iss', $uid, $key, $value);
            $stmt->execute();
        }
    } catch (Throwable $e) {
        error_log('set_user_pref error: ' . $e->getMessage());
    }
}
