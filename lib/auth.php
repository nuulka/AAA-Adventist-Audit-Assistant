<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/prefs.php';
// OTS constants live outside the revizor directory (../ots). From lib/ we need to go up two levels.
require_once __DIR__ . '/../../ots/constant.php';

// Dev mode toggle – superadmin can switch between admin and regular user view.
// State changes must be POST + CSRF protected.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dev_toggle']) && is_superadmin()) {
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        http_response_code(403);
        echo 'CSRF token mismatch';
        exit;
    }
    if (!empty($_SESSION['revizor_dev_mode'])) {
        unset($_SESSION['revizor_dev_mode']);
    } else {
        $_SESSION['revizor_dev_mode'] = true;
    }
    $redirect = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: $redirect");
    exit;
}

function require_login() {
    if (!isset($_SESSION[GC_LOGIN_COOKIE])) {
        header('Location: /revizor/login.php');
        exit;
    }
}

function is_admin() {
    // Dev mode: superadmin simulates regular user
    if (!empty($_SESSION['revizor_dev_mode'])) return false;
    if (is_superadmin()) return true;
    $rights = isset($_SESSION[GN_USER_RIGHTS]) ? intval($_SESSION[GN_USER_RIGHTS]) : 0;
    return ($rights & SDA_L_CONFERENCE_ROLES) != 0;
}

function is_revizor() {
    $rights = isset($_SESSION[GN_USER_RIGHTS]) ? intval($_SESSION[GN_USER_RIGHTS]) : 0;
    return ($rights & SDA_L_AUDITOR) != 0;
}

function is_superadmin() {
    $cfg = load_app_config();
    $super_id = isset($cfg['superadmin_user_id']) ? intval($cfg['superadmin_user_id']) : 0;
    if ($super_id <= 0) return false;
    $userId = isset($_SESSION[GN_USER_ID]) ? intval($_SESSION[GN_USER_ID]) : 0;
    return $userId > 0 && $userId === $super_id;
}

function render_dev_toggle() {
    if (!is_superadmin()) return;
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $active = !empty($_SESSION['revizor_dev_mode']);
    $label = $active ? '🛠️ Adminisztrátori nézet' : '👤 Felhasználói nézet';
    $class = $active ? 'btn-outline-secondary' : 'btn-outline-warning';
    $title = $active ? 'Vissza adminisztrátori nézetre' : 'Felhasználói nézet megtekintése (fejlesztői teszt)';
    $csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
    echo '<form method="POST" class="d-inline m-0">';
    echo '<input type="hidden" name="csrf_token" value="' . $csrf . '">';
    echo '<button type="submit" name="dev_toggle" value="1" class="btn btn-sm ' . $class . '" title="' . $title . '">' . $label . '</button>';
    echo '</form>';
}

function get_user_role_label() {
    if (is_superadmin() && !empty($_SESSION['revizor_dev_mode'])) return '👤 Felhasználói nézet';
    if (is_superadmin()) return '🛠️ Admin / Fejlesztő';
    if (is_admin()) return '👑 Adminisztrátor';
    if (is_revizor()) return '🔍 Revizor';
    return '👤 Felhasználó';
}

function render_user_badge() {
    $name = isset($_SESSION[GC_USER_FULL_NAME]) ? $_SESSION[GC_USER_FULL_NAME] : 'Ismeretlen';
    $role = get_user_role_label();
    echo '<span class="badge bg-light text-dark border me-1 px-2 py-1" style="font-size:0.8rem;">' . htmlspecialchars($name) . ' – ' . $role . '</span>';
}

function render_church_badge() {
    $church_name = $_SESSION['revizor_selected_church_name'] ?? '';
    $church_id = $_SESSION['revizor_selected_church'] ?? 0;
    if (empty($church_name) && empty($church_id)) return;
    if ($church_id <= 0) return;
    echo '<span class="badge bg-light text-dark border me-1 px-2 py-1" style="font-size:0.8rem;">';
    echo '🏛 ' . htmlspecialchars($church_name);
    echo ' <a href="select-church.php?change=1" class="text-decoration-none ms-1" title="Gyülekezet váltása" style="color:inherit;">🔄</a>';
    echo '</span>';
}

function get_accessible_church_ids() {
    // If already populated in session, return it
    if (isset($_SESSION['revizor_accessible_churches']) && is_array($_SESSION['revizor_accessible_churches'])) {
        return array_map('intval', $_SESSION['revizor_accessible_churches']);
    }
    // Admins have access to all churches -> return null to indicate no restriction
    if (is_admin()) return null;
    // Otherwise, try to build from OTS roles now
    build_user_context_from_ots();
    if (isset($_SESSION['revizor_accessible_churches']) && is_array($_SESSION['revizor_accessible_churches'])) {
        return array_map('intval', $_SESSION['revizor_accessible_churches']);
    }
    // No access
    return [];
}

function require_church_access($church_id) {
    if (is_admin()) return true;
    $allowed = get_accessible_church_ids();
    if (empty($allowed)) {
        // no access
        header('HTTP/1.1 403 Forbidden');
        echo 'Forbidden';
        exit;
    }
    if (!in_array(intval($church_id), $allowed, true)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Forbidden';
        exit;
    }
    $selected = intval($_SESSION['revizor_selected_church'] ?? 0);
    if ($selected <= 0) {
        $selected = require_selected_church(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    }
    if (intval($church_id) !== $selected) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Forbidden';
        exit;
    }
    return true;
}

/**
 * A kiválasztott gyülekezet ID: session, majd (hiány esetén) a 7 napos prefs.
 * Adminoknál is használható (a require_selected_church csak nem-adminoknál).
 */
function get_selected_church_id() {
    $sel = intval($_SESSION['revizor_selected_church'] ?? 0);
    if ($sel > 0) return $sel;
    $pref = intval(get_user_pref('selected_church'));
    if ($pref > 0) {
        set_selected_church_session($pref);
        return $pref;
    }
    return 0;
}

function set_selected_church_session($church_id) {
    $church_id = intval($church_id);
    if ($church_id <= 0) return;
    $_SESSION['revizor_selected_church'] = $church_id;
    set_user_pref('selected_church', (string)$church_id);
    // Először konfigból próbáljuk a nevet
    $cfg = load_app_config();
    if (!empty($cfg['churches'][$church_id])) {
        $_SESSION['revizor_selected_church_name'] = $cfg['churches'][$church_id];
        return;
    }
    // Ha nincs konfigban, OTS-ből
    try {
        $ots = get_ots_conn();
        $stmt = $ots->prepare("SELECT name FROM CHURCHES WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $church_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $_SESSION['revizor_selected_church_name'] = $row['name'];
            }
        }
    } catch (Throwable $e) {
        error_log('set_selected_church_session failed: ' . $e->getMessage());
    }
}

function require_selected_church($redirect = 'index.php') {
    if (is_admin()) return 0;
    $allowed = get_accessible_church_ids();
    if (empty($allowed)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Forbidden';
        exit;
    }

    $selected = intval($_SESSION['revizor_selected_church'] ?? 0);
    if ($selected <= 0) {
        // Session nincs beállítva, de talán a 7 napos preferencia megvan
        $pref = intval(get_user_pref('selected_church'));
        if ($pref > 0 && in_array($pref, $allowed, true)) {
            $selected = $pref;
            set_selected_church_session($selected);
        }
    }
    if ($selected > 0 && in_array($selected, $allowed, true)) {
        if (empty($_SESSION['revizor_selected_church_name'])) {
            set_selected_church_session($selected);
        }
        return $selected;
    }

    unset($_SESSION['revizor_selected_church'], $_SESSION['revizor_selected_church_name']);
    if (count($allowed) === 1) {
        set_selected_church_session($allowed[0]);
        return intval($allowed[0]);
    }

    header('Location: select-church.php?redirect=' . urlencode($redirect));
    exit;
}

function append_int_in_clause(array &$clauses, array &$params, string &$types, string $column, array $values) {
    $values = array_values(array_filter(array_map('intval', $values), function ($v) {
        return $v > 0;
    }));
    if (empty($values)) {
        $clauses[] = '1=0';
        return;
    }
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    $clauses[] = "$column IN ($placeholders)";
    foreach ($values as $value) {
        $params[] = $value;
        $types .= 'i';
    }
}

function build_user_context_from_ots() {
    // populate session accessible church ids from ots.ROLES
    if (!isset($_SESSION[GN_USER_ID])) return;
    try {
        $userId = intval($_SESSION[GN_USER_ID]);
        $ots = get_ots_conn();
        $stmt = $ots->prepare("SELECT CHURCH_ID FROM ROLES WHERE USER_ID = ? AND VALID_FROM <= NOW() AND (VALID_TO IS NULL OR VALID_TO >= NOW())");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $list = [];
            while ($r = $res->fetch_assoc()) { $list[] = intval($r['CHURCH_ID']); }
            $_SESSION['revizor_accessible_churches'] = $list;
        }
    } catch (Throwable $e) {
        error_log('build_user_context_from_ots failed: ' . $e->getMessage());
    }
}

?>
