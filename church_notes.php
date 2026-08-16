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

$session_remaining = ensure_revizor_session_timeout();
ensure_revizor_csrf_token();

$conn = get_revizor_conn();

// Gyülekezet-jegyzetek táblája
$conn->query("CREATE TABLE IF NOT EXISTS church_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    church_id INT NOT NULL,
    note TEXT NOT NULL,
    tags VARCHAR(500) NOT NULL DEFAULT '',
    created_by INT DEFAULT 0,
    created_by_name VARCHAR(100) DEFAULT '',
    created_at DATETIME DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,
    KEY idx_church (church_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Gyülekezet név feloldása konfigból + OTS-ből
function cn_church_name($id) {
    $cfg = load_app_config();
    if (!empty($cfg['churches'][$id])) return $cfg['churches'][$id];
    try {
        $ots = get_ots_conn();
        $stmt = $ots->prepare("SELECT name FROM CHURCHES WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($r = $res->fetch_assoc()) return $r['name'];
        }
    } catch (Throwable $e) {}
    return '#' . $id;
}

// Címke lista minden meglévő jegyzetből (a szűrőhöz)
function cn_all_tags($conn) {
    $tags = [];
    $res = $conn->query("SELECT tags FROM church_notes WHERE tags != ''");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            foreach (array_map('trim', explode(',', $r['tags'])) as $t) {
                if ($t !== '') $tags[$t] = true;
            }
        }
    }
    $list = array_keys($tags);
    sort($list, SORT_STRING);
    return $list;
}

$msg = '';
$is_admin = is_admin();
$accessible = get_accessible_church_ids(); // null = admin (minden)

// Jogosultság: admin minden gyülekezethez, egyébként csak az elérhetőekhez
function cn_scope_allowed($church_id, $is_admin, $accessible) {
    $church_id = intval($church_id);
    if ($church_id <= 0) return false;
    if ($is_admin) return true;
    return is_array($accessible) && in_array($church_id, $accessible, true);
}

// Műveletek (mind POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        http_response_code(400);
        $msg = 'Érvénytelen CSRF token.';
    } elseif (isset($_POST['add_note'])) {
        $church_id = intval($_POST['church_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        $tags_raw = trim((string)($_POST['tags'] ?? ''));
        if (!cn_scope_allowed($church_id, $is_admin, $accessible)) {
            $msg = 'Nincs jogosultságod ehhez a gyülekezethez.';
        } elseif ($note === '') {
            $msg = 'A jegyzet nem lehet üres.';
        } else {
            $tags = array_slice(array_values(array_filter(array_map('trim', explode(',', $tags_raw)))), 0, 20);
            $tags_str = implode(', ', $tags);
            $note_s = mb_substr($note, 0, 2000, 'UTF-8');
            $inspector = $_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen';
            $uid = intval($_SESSION[GN_USER_ID] ?? 0);
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO church_notes (church_id, note, tags, created_by, created_by_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('ississs', $church_id, $note_s, $tags_str, $uid, $inspector, $now, $now);
                $stmt->execute();
                log_activity('church_note_add', ['church_id' => $church_id, 'note_id' => $conn->insert_id]);
                $msg = 'Jegyzet hozzáadva.';
            }
        }
    } elseif (isset($_POST['update_note'])) {
        $note_id = intval($_POST['note_id'] ?? 0);
        $church_id = intval($_POST['church_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        $tags_raw = trim((string)($_POST['tags'] ?? ''));
        if (!cn_scope_allowed($church_id, $is_admin, $accessible)) {
            $msg = 'Nincs jogosultságod ehhez a gyülekezethez.';
        } elseif ($note === '') {
            $msg = 'A jegyzet nem lehet üres.';
        } else {
            $tags = array_slice(array_values(array_filter(array_map('trim', explode(',', $tags_raw)))), 0, 20);
            $tags_str = implode(', ', $tags);
            $note_s = mb_substr($note, 0, 2000, 'UTF-8');
            $stmt = $conn->prepare("UPDATE church_notes SET church_id = ?, note = ?, tags = ?, updated_at = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('isssi', $church_id, $note_s, $tags_str, date('Y-m-d H:i:s'), $note_id);
                $stmt->execute();
                log_activity('church_note_update', ['note_id' => $note_id]);
                $msg = 'Jegyzet módosítva.';
            }
        }
    } elseif (isset($_POST['delete_note'])) {
        $note_id = intval($_POST['note_id'] ?? 0);
        $stmt = $conn->prepare("SELECT church_id FROM church_notes WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $note_id);
            $stmt->execute();
            $nrow = $stmt->get_result()->fetch_assoc() ?? null;
            if ($nrow && cn_scope_allowed($nrow['church_id'], $is_admin, $accessible)) {
                $del = $conn->prepare("DELETE FROM church_notes WHERE id = ?");
                if ($del) { $del->bind_param('i', $note_id); $del->execute(); }
                log_activity('church_note_delete', ['note_id' => $note_id]);
                $msg = 'Jegyzet törölve.';
            } else {
                $msg = 'Nincs jogosultságod ehhez a gyülekezethez.';
            }
        }
    } else {
        $msg = 'Ismeretlen művelet.';
    }
    // POST után visszairányítás, hogy ne történjen újra elküldés frissítéskor
    $redirect = strtok($_SERVER['REQUEST_URI'], '?');
    if (!empty($_GET)) { $redirect .= '?' . http_build_query($_GET); }
    if ($msg !== '') { $redirect .= (strpos($redirect, '?') !== false ? '&' : '?') . 'msg=' . urlencode($msg); }
    header('Location: ' . $redirect);
    exit;
}

if (isset($_GET['msg'])) {
    $msg = (string)$_GET['msg'];
}

// Szűrők
if (isset($_GET['church_id'])) {
    $f_church = intval($_GET['church_id']);
    if ($f_church > 0) {
        set_selected_church_session($f_church);
    }
} else {
    $f_church = get_selected_church_id();
}
$f_tag = isset($_GET['tag']) ? trim((string)$_GET['tag']) : '';
$f_q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// Gyülekezet listája (admin: összes, egyébként csak az elérhetőek)
$church_list = [];
if ($is_admin) {
    $cfg = load_app_config();
    if (!empty($cfg['churches']) && is_array($cfg['churches'])) {
        foreach ($cfg['churches'] as $cid => $cname) { $church_list[$cid] = $cname; }
    }
    $c_res = $conn->query("SELECT DISTINCT church_id FROM church_notes ORDER BY church_id");
    if ($c_res) {
        while ($cr = $c_res->fetch_assoc()) {
            $cid = intval($cr['church_id']);
            if (!isset($church_list[$cid])) { $church_list[$cid] = cn_church_name($cid); }
        }
    }
    $ots = get_ots_conn();
    $co = $ots->query("SELECT id, name FROM CHURCHES WHERE name IS NOT NULL AND name != '' ORDER BY name ASC");
    if ($co) {
        while ($c = $co->fetch_assoc()) { $church_list[(int)$c['id']] = $c['name']; }
    }
} else {
    foreach ((array)$accessible as $cid) { $church_list[$cid] = cn_church_name($cid); }
}
asort($church_list, SORT_STRING);

if ($f_church !== 0 && !cn_scope_allowed($f_church, $is_admin, $accessible)) { $f_church = 0; }

// Mélylink: note_id esetén az adott jegyzet gyülekezetére állítjuk a szűrőt
$f_note_id = isset($_GET['note_id']) ? intval($_GET['note_id']) : 0;
if ($f_note_id > 0) {
    $nstmt = $conn->prepare("SELECT church_id FROM church_notes WHERE id = ?");
    if ($nstmt) {
        $nstmt->bind_param('i', $f_note_id);
        $nstmt->execute();
        $nrow = $nstmt->get_result()->fetch_assoc();
        if ($nrow && cn_scope_allowed((int)$nrow['church_id'], $is_admin, $accessible)) {
            $f_church = (int)$nrow['church_id'];
        } else {
            $f_note_id = 0;
        }
    } else {
        $f_note_id = 0;
    }
}

// Lekérdezés
$where = [];
$params = [];
$types = '';
if ($f_church !== 0) {
    $where[] = 'church_id = ?'; $params[] = $f_church; $types .= 'i';
} elseif (!$is_admin && is_array($accessible)) {
    if (empty($accessible)) {
        $where[] = '1=0';
    } else {
        append_int_in_clause($where, $params, $types, 'church_id', $accessible);
    }
}
if ($f_tag !== '') {
    $where[] = 'tags LIKE ?'; $params[] = '%' . $f_tag . '%'; $types .= 's';
}
if ($f_q !== '') {
    $where[] = 'note LIKE ?'; $params[] = '%' . $f_q . '%'; $types .= 's';
}
$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $conn->prepare("SELECT * FROM church_notes $where_sql ORDER BY updated_at DESC, id DESC LIMIT 1000");
$notes = [];
if ($stmt) {
    if (!empty($params)) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$all_tags = cn_all_tags($conn);

$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>📝 Jegyzetek – Revizor Asszisztens</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; padding: 15px; font-size: 14px; }
        .tag-badge { display: inline-block; font-size: 12px; padding: 2px 8px; border-radius: 10px; background: #e7f1ff; color: #0d6efd; border: 1px solid #cfe2ff; text-decoration: none; }
        .tag-badge:hover { background: #d0e4ff; }
        .note-card { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .note-card .note-text { white-space: pre-wrap; }
        .edit-row { background: #f0f7ff; }
        #tagCounter { font-size: 12px; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3 px-3 py-2 bg-white rounded border shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">🏠 Kezdőlap</a>
            <span class="fw-bold">📝 Gyülekezeti jegyzetek</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted small">Gyülekezethez kötött, tételhez nem – tag-ekkel kereshető</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <?php render_dev_toggle(); ?>
            <?php render_church_badge(); ?>
            <a href="help.php" class="btn btn-outline-primary btn-sm">❓ Súgó</a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Kilépés</a>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
    <div class="alert alert-info py-2 small"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Új jegyzet -->
    <div class="card mb-3">
        <div class="card-header py-2 bg-primary-subtle fw-bold">✏️ Új jegyzet</div>
        <div class="card-body py-2">
            <form method="POST" class="row g-2 align-items-start" onsubmit="return checkTagCount(this)">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <div class="col-auto">
                    <label class="form-label small mb-1">Gyülekezet</label>
                    <select name="church_id" class="form-select form-select-sm" style="width:220px;" required>
                        <option value="">Válassz...</option>
                        <?php foreach ($church_list as $cid => $cname): ?>
                        <option value="<?= $cid ?>" <?= $f_church > 0 && $f_church === $cid ? 'selected' : '' ?>><?= htmlspecialchars($cname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col">
                    <label class="form-label small mb-1">Jegyzet (egy mondat, max 2000 karakter)</label>
                    <textarea name="note" class="form-control form-control-sm" rows="2" maxlength="2000" required></textarea>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Címkék (max 20, vesszővel elválasztva)</label>
                    <input type="text" name="tags" class="form-control form-control-sm" style="width:300px;" placeholder="pl. ügyirat, hiány, utánajárás" data-tag-input>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">&nbsp;</label>
                    <button type="submit" name="add_note" value="1" class="btn btn-primary btn-sm d-block">➕ Hozzáadás</button>
                </div>
            </form>
            <div class="small text-muted mt-1" data-tag-counter></div>
        </div>
    </div>

    <!-- Keresés / szűrők -->
    <form method="GET" class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-0">Keresés a jegyzetben</label>
                    <input type="text" name="q" class="form-control form-control-sm" style="width:260px;" value="<?= htmlspecialchars($f_q) ?>" placeholder="Szöveg a jegyzetben...">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Gyülekezet</label>
                    <select name="church_id" class="form-select form-select-sm" style="width:220px;">
                        <option value="0">Összes</option>
                        <?php foreach ($church_list as $cid => $cname): ?>
                        <option value="<?= $cid ?>" <?= $f_church === $cid ? 'selected' : '' ?>><?= htmlspecialchars($cname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Címke</label>
                    <select name="tag" class="form-select form-select-sm" style="width:200px;">
                        <option value="">Mind</option>
                        <?php foreach ($all_tags as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>" <?= $f_tag === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">🔎 Szűrés</button>
                    <a href="church_notes.php" class="btn btn-outline-secondary btn-sm">✕</a>
                </div>
            </div>
            <?php if (!empty($all_tags)): ?>
            <div class="mt-2 d-flex flex-wrap align-items-center gap-1">
                <span class="small text-muted">Címkék:</span>
                <?php foreach ($all_tags as $t): ?>
                <a class="tag-badge" href="church_notes.php?tag=<?= urlencode($t) ?>">#<?= htmlspecialchars($t) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </form>

    <!-- Jegyzetek listája -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <small class="text-muted"><?= count($notes) ?> jegyzet</small>
    </div>

    <?php if (empty($notes)): ?>
    <div class="alert alert-light text-center">Nincs jegyzet a szűrésnek megfelelően.</div>
    <?php else: foreach ($notes as $n): ?>
    <?php
        $nid = (int)$n['id'];
        $n_church = (int)$n['church_id'];
        $n_tags = array_filter(array_map('trim', explode(',', (string)$n['tags'])));
    ?>
    <div class="note-card mb-2 p-3" id="note-<?= $nid ?>">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="flex-grow-1">
                <div class="note-text"><?= nl2br(htmlspecialchars($n['note'], ENT_QUOTES, 'UTF-8')) ?></div>
                <div class="mt-2 d-flex flex-wrap align-items-center gap-1">
                    <span class="badge bg-light border text-dark">🏛 <?= htmlspecialchars(cn_church_name($n_church)) ?></span>
                    <?php foreach ($n_tags as $t): ?>
                    <a class="tag-badge" href="church_notes.php?tag=<?= urlencode($t) ?>">#<?= htmlspecialchars($t) ?></a>
                    <?php endforeach; ?>
                    <span class="text-muted small ms-auto"><?= htmlspecialchars($n['created_by_name'] ?: ('#' . $n['created_by'])) ?> · <?= htmlspecialchars(mb_substr((string)$n['updated_at'], 0, 16)) ?></span>
                </div>
            </div>
            <div class="d-flex gap-1 flex-shrink-0">
                <button class="btn btn-outline-primary btn-sm py-0 px-2" type="button" onclick="toggleEdit(<?= $nid ?>)">✏️</button>
                <form method="POST" class="m-0" onsubmit="return confirm('Biztosan törlöd ezt a jegyzetet?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="note_id" value="<?= $nid ?>">
                    <button class="btn btn-outline-danger btn-sm py-0 px-2" name="delete_note" value="1">🗑️</button>
                </form>
            </div>
        </div>
        <div class="edit-row rounded p-2 mt-2" id="edit-<?= $nid ?>" style="display:none;">
            <form method="POST" class="row g-2 align-items-start" onsubmit="return checkTagCount(this)">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="note_id" value="<?= $nid ?>">
                <div class="col-auto">
                    <select name="church_id" class="form-select form-select-sm" style="width:200px;">
                        <?php foreach ($church_list as $cid => $cname): ?>
                        <option value="<?= $cid ?>" <?= $n_church === $cid ? 'selected' : '' ?>><?= htmlspecialchars($cname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col">
                    <textarea name="note" class="form-control form-control-sm" rows="2" maxlength="2000" required><?= htmlspecialchars($n['note'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="col-auto">
                    <input type="text" name="tags" class="form-control form-control-sm" style="width:280px;" value="<?= htmlspecialchars(implode(', ', $n_tags)) ?>" placeholder="Címkék (max 20, vesszővel)" data-tag-input>
                </div>
                <div class="col-auto">
                    <button type="submit" name="update_note" value="1" class="btn btn-success btn-sm">💾 Mentés</button>
                </div>
            </form>
            <div class="small text-muted mt-1" data-tag-counter></div>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<script>
// Címke számláló + max 20 limit
document.querySelectorAll('[data-tag-input]').forEach(function(input) {
    function updateCounter() {
        var box = input.closest('form').querySelector('[data-tag-counter]');
        if (!box) return;
        var tags = input.value.split(',').map(function(t) { return t.trim(); }).filter(Boolean);
        box.textContent = tags.length + '/20 címke';
        if (tags.length >= 20) {
            box.classList.add('text-danger');
            box.classList.remove('text-muted');
        } else {
            box.classList.remove('text-danger');
            box.classList.add('text-muted');
        }
    }
    input.addEventListener('input', updateCounter);
    updateCounter();
});

function checkTagCount(form) {
    var input = form.querySelector('[data-tag-input]');
    if (!input) return true;
    var tags = input.value.split(',').map(function(t) { return t.trim(); }).filter(Boolean);
    if (tags.length > 20) {
        alert('Maximum 20 címke adható meg (most: ' + tags.length + ').');
        return false;
    }
    return true;
}

function toggleEdit(id) {
    var el = document.getElementById('edit-' + id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// Mélylink: note_id esetén görgetés + kiemelés
(function() {
    var nid = parseInt(new URLSearchParams(location.search).get('note_id') || '0', 10);
    if (nid > 0) {
        var el = document.getElementById('note-' + nid);
        if (el) {
            el.scrollIntoView({ block: 'center' });
            el.style.boxShadow = '0 0 0 3px #ffc107';
            setTimeout(function() {
                el.style.transition = 'box-shadow 1.5s';
                el.style.boxShadow = '';
            }, 2500);
        }
    }
})();
</script>

<?php if (function_exists('render_announcement_modal')) render_announcement_modal(); ?>

</body>
</html>
