<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../ots/constant.php';

if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION[GN_LAST_ACTIVE] = time();

require_once __DIR__ . '/../../ots/session_handler.php';

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
build_user_context_from_ots();

header('Content-Type: application/json');

if (!isset($_SESSION[GC_LOGIN_COOKIE])) {
    http_response_code(401);
    echo json_encode(['error' => 'nincs session']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'csak POST']);
    exit;
}

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'csrf']);
    exit;
}

$cid = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;
if ($cid <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ervenytelen church_id']);
    exit;
}

$accessible = get_accessible_church_ids();
if (!is_admin() && (empty($accessible) || !in_array($cid, $accessible, true))) {
    http_response_code(403);
    echo json_encode(['error' => 'nincs jogosultsag']);
    exit;
}

set_selected_church_session($cid);
$_SESSION[GN_CHURCH_ID] = $cid;
echo json_encode(['ok' => true, 'church_id' => $cid, 'church_name' => $_SESSION['revizor_selected_church_name'] ?? '']);
