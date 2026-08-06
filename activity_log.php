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

// Only admin can view activity log
if (!is_admin()) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Forbidden';
    exit;
}

$session_remaining = ensure_revizor_session_timeout();
ensure_revizor_csrf_token();

// Cleanup is state-changing, so it must be explicit POST + CSRF.
$retention_months = 3;
$deleted = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_logs'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        http_response_code(400);
        echo 'CSRF token mismatch';
        exit;
    }
    $retention_months = isset($_POST['retention_months']) ? max(1, min(60, intval($_POST['retention_months']))) : 3;
    $deleted = cleanup_activity_log($retention_months);
}

$conn = get_revizor_conn();

// Filters
$filter_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$filter_action = isset($_GET['action']) ? trim($_GET['action']) : '';
$filter_ip = isset($_GET['ip']) ? trim($_GET['ip']) : '';
$filter_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 100;
$offset = ($page - 1) * $per_page;

$where = [];
$params = [];
$types = '';

if ($filter_user > 0) {
    $where[] = 'user_id = ?';
    $params[] = $filter_user;
    $types .= 'i';
}
if ($filter_action !== '') {
    $where[] = 'action = ?';
    $params[] = $filter_action;
    $types .= 's';
}
if ($filter_ip !== '') {
    $where[] = 'ip_address LIKE ?';
    $params[] = "%$filter_ip%";
    $types .= 's';
}
if ($filter_date_from !== '') {
    $where[] = 'created_at >= ?';
    $params[] = $filter_date_from . ' 00:00:00';
    $types .= 's';
}
if ($filter_date_to !== '') {
    $where[] = 'created_at <= ?';
    $params[] = $filter_date_to . ' 23:59:59';
    $types .= 's';
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
$total_stmt = $conn->prepare("SELECT COUNT(*) FROM user_activity_log $where_sql");
if ($total_stmt && !empty($params)) {
    $total_stmt->bind_param($types, ...$params);
    $total_stmt->execute();
    $total = (int)$total_stmt->get_result()->fetch_row()[0];
} else {
    $total_res = $conn->query("SELECT COUNT(*) FROM user_activity_log $where_sql");
    $total = $total_res ? (int)$total_res->fetch_row()[0] : 0;
}

$log_stmt = $conn->prepare("SELECT * FROM user_activity_log $where_sql ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
if ($log_stmt && !empty($params)) {
    $log_stmt->bind_param($types, ...$params);
    $log_stmt->execute();
    $logs = $log_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $log_res = $conn->query("SELECT * FROM user_activity_log $where_sql ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
    $logs = $log_res ? $log_res->fetch_all(MYSQLI_ASSOC) : [];
}

// Get distinct users and actions for filter dropdowns
$users = $conn->query("SELECT DISTINCT user_id, user_name FROM user_activity_log ORDER BY user_name");
$actions = $conn->query("SELECT DISTINCT action FROM user_activity_log ORDER BY action");
$total_pages = max(1, ceil($total / $per_page));
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AAA – Használati napló</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; }
        .filter-row { background: white; border-radius: 8px; padding: 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .log-table { font-size: 0.85rem; }
        .log-table td, .log-table th { padding: 6px 10px; vertical-align: middle; }
        .details-pre { font-size: 0.78rem; max-height: 60px; overflow-y: auto; margin: 0; white-space: pre-wrap; }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 px-3 py-2 bg-white rounded border shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">Kezdőlap</a>
            <span class="fw-bold">AAA – Használati napló</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted small">Megőrzés: <?= $retention_months ?> hónap</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <form method="POST" class="d-flex align-items-center gap-1 m-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="number" name="retention_months" value="3" min="1" max="60" class="form-control form-control-sm" style="width:75px;" title="Megőrzés hónapban">
                <button type="submit" name="cleanup_logs" value="1" class="btn btn-outline-warning btn-sm" onclick="return confirm('Törlöd a megadott hónapnál régebbi naplókat?')">Régi naplók törlése</button>
            </form>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Kilépés</a>
        </div>
    </div>

    <?php if ($deleted > 0): ?>
    <div class="alert alert-info py-2 small"><?= $deleted ?> db <?= $retention_months ?> hónapnál régebbi naplóbejegyzés törölve.</div>
    <?php endif; ?>

    <form method="GET" class="filter-row row g-2 mb-3 align-items-end">
        <div class="col-auto">
            <label class="form-label small mb-0">Felhasználó</label>
            <select name="user_id" class="form-select form-select-sm" style="width:180px;">
                <option value="0">Mind</option>
                <?php if ($users): foreach ($users as $u): ?>
                <option value="<?= $u['user_id'] ?>" <?= $filter_user === (int)$u['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['user_name']) ?> (<?= $u['user_id'] ?>)</option>
                <?php endforeach; endif; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-0">Művelet</label>
            <select name="action" class="form-select form-select-sm" style="width:150px;">
                <option value="">Mind</option>
                <?php if ($actions): foreach ($actions as $a): ?>
                <option value="<?= htmlspecialchars($a['action']) ?>" <?= $filter_action === $a['action'] ? 'selected' : '' ?>><?= htmlspecialchars($a['action']) ?></option>
                <?php endforeach; endif; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-0">IP cím</label>
            <input type="text" name="ip" class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($filter_ip) ?>" placeholder="pl. 192.168">
        </div>
        <div class="col-auto">
            <label class="form-label small mb-0">Dátum tól</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date_from) ?>">
        </div>
        <div class="col-auto">
            <label class="form-label small mb-0">Dátum ig</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date_to) ?>">
        </div>
        <div class="col-auto">
            <label class="form-label small mb-0">&nbsp;</label>
            <button type="submit" class="btn btn-sm btn-primary d-block">Szűrés</button>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-0">&nbsp;</label>
            <a href="activity_log.php" class="btn btn-sm btn-outline-secondary d-block">Törlés</a>
        </div>
    </form>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <small class="text-muted"><?= $total ?> találat (<?= $per_page ?> / oldal)</small>
        <div>
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-sm btn-outline-primary">Előző</a>
            <?php endif; ?>
            <span class="mx-2 small"><?= $page ?> / <?= $total_pages ?></span>
            <?php if ($page < $total_pages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-sm btn-outline-primary">Következő</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover log-table bg-white rounded shadow-sm">
            <thead class="table-light">
                <tr>
                    <th>Idő</th>
                    <th>Felhasználó</th>
                    <th>Művelet</th>
                    <th>IP cím</th>
                    <th>Részletek</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Nincs naplóbejegyzés.</td></tr>
                <?php else: foreach ($logs as $log): ?>
                <tr>
                    <td class="text-nowrap small"><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= htmlspecialchars($log['user_name']) ?> <small class="text-muted">(#<?= (int)$log['user_id'] ?>)</small></td>
                    <td><span class="badge bg-<?= $log['action'] === 'login' ? 'success' : ($log['action'] === 'logout' ? 'secondary' : ($log['action'] === 'page_view' ? 'info' : ($log['action'] === 'audit_save' ? 'warning' : 'primary'))) ?>"><?= htmlspecialchars($log['action']) ?></span></td>
                    <td class="text-muted small"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                    <td style="max-width:300px;">
                        <?php if ($log['details'] && $log['details'] !== 'null'): ?>
                        <pre class="details-pre"><?= htmlspecialchars(json_encode(json_decode($log['details'], true) ?: $log['details'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
