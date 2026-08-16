<?php
require_once __DIR__ . '/bootstrap.php';

/**
 * Belépéskori üzenő: az admin ír, minden bejelentkezett felhasználó
 * egy popupban látja (munkamenetenként egyszer), OK gombbal zárható.
 */

function ensure_announcement_table() {
    $conn = get_revizor_conn();
    $conn->query("CREATE TABLE IF NOT EXISTS app_announcement (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message TEXT NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NOT NULL DEFAULT 0,
        created_by_name VARCHAR(100) DEFAULT '',
        created_at DATETIME DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Az aktív (frissített) üzenet, ha van ilyen. */
function get_active_announcement() {
    try {
        ensure_announcement_table();
        $conn = get_revizor_conn();
        $stmt = $conn->prepare("SELECT * FROM app_announcement WHERE active = 1 ORDER BY updated_at DESC, id DESC LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) return $res->fetch_assoc();
        }
    } catch (Throwable $e) {
        error_log('Announcement error: ' . $e->getMessage());
    }
    return null;
}

/** Üzenet mentése (mindig új sor, a legutóbbi az aktív). */
function save_announcement($message, $active = 1) {
    try {
        ensure_announcement_table();
        $conn = get_revizor_conn();
        $name = $_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen';
        $uid = intval($_SESSION[GN_USER_ID] ?? 0);
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT INTO app_announcement (message, active, created_by, created_by_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('siisss', $message, $active, $uid, $name, $now, $now);
            $stmt->execute();
            return true;
        }
    } catch (Throwable $e) {
        error_log('Announcement error: ' . $e->getMessage());
    }
    return false;
}

/** A popup overlay HTML-je + JS-e (önálló, nem igényel Bootstrap JS-t). */
function render_announcement_modal() {
    $ann = get_active_announcement();
    if (!$ann) return;
    $msg = trim((string)$ann['message']);
    if ($msg === '') return;

    $version = md5((int)$ann['id'] . '|' . (string)$ann['updated_at']);
    $name = htmlspecialchars((string)($ann['created_by_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $date = htmlspecialchars(substr((string)($ann['updated_at'] ?? ''), 0, 16), ENT_QUOTES, 'UTF-8');
    $message_html = nl2br(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'));
    ?>
    <div id="announcementOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;">
        <div style="background:#fff;max-width:580px;width:100%;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.3);overflow:hidden;font-size:15px;">
            <div style="background:#0d6efd;color:#fff;padding:12px 20px;font-weight:bold;font-size:16px;">📢 Fontos üzenet</div>
            <div style="padding:20px;white-space:pre-wrap;max-height:60vh;overflow-y:auto;line-height:1.5;"><?= $message_html ?></div>
            <div style="padding:12px 20px;background:#f8f9fa;border-top:1px solid #e9ecef;text-align:right;">
                <small style="color:#6c757d;margin-right:10px;">Írta: <?= $name ?> · <?= $date ?></small>
                <button id="announcementOkBtn" style="background:#0d6efd;color:#fff;border:0;border-radius:6px;padding:8px 28px;font-weight:bold;cursor:pointer;">OK</button>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var version = '<?= $version ?>';
        var overlay = document.getElementById('announcementOverlay');
        if (!overlay) return;
        function close() {
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            try { sessionStorage.setItem('revizor_announcement_seen', version); } catch (e) {}
        }
        try {
            if (sessionStorage.getItem('revizor_announcement_seen') === version) {
                close();
                return;
            }
        } catch (e) {}
        var ok = document.getElementById('announcementOkBtn');
        if (ok) ok.addEventListener('click', close);
        overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });
    })();
    </script>
    <?php
}
