<?php
// Lightweight status endpoint to support live disable/logout for user accounts
$__sess_path = ini_get('session.save_path');
if (!$__sess_path || !is_dir($__sess_path) || !is_writable($__sess_path)) {
    $__alt = __DIR__ . '/../tmp_sessions';
    if (!is_dir($__alt)) { @mkdir($__alt, 0777, true); }
    if (is_dir($__alt)) { @ini_set('session.save_path', $__alt); }
}
@ini_set('session.cookie_secure', '0');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');
@ini_set('session.cookie_path', '/');
@ini_set('session.use_strict_mode', '1');

if (!headers_sent()) {
    header('Content-Type: application/json');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo json_encode(['ok' => false, 'logged_out' => true]);
    exit;
}

$role = strtolower((string)($_SESSION['usertype'] ?? ''));
if ($role === 'admin') {
    echo json_encode(['ok' => true, 'disabled' => false, 'role' => 'admin']);
    exit;
}

$disabled = false;
try {
    require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $users = $db->selectCollection('users');
    $doc = $users->findOne(
        ['username' => (string)$_SESSION['username']],
        ['projection' => ['disabled' => 1]]
    );
    $disabled = $doc && !empty($doc['disabled']);
} catch (\Throwable $e) {
    echo json_encode(['ok' => true, 'disabled' => false]);
    exit;
}

echo json_encode(['ok' => true, 'disabled' => $disabled]);
