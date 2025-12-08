<?php
// Force sessions to a local writable folder to avoid C:\xampp\tmp permission issues
$__alt = __DIR__ . '/../tmp_sessions';
if (!is_dir($__alt)) { @mkdir($__alt, 0777, true); }
@session_save_path($__alt);
@ini_set('session.save_path', $__alt);

// Start session to destroy it cleanly
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = [];
// Remove session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', !empty($params['secure']), !empty($params['httponly']));
}
@session_destroy();

// If this logout was triggered due to a disabled account, set a short-lived cookie
// so index.php can reliably show the penalty message even if the query is lost.
$isDisabledLogout = (isset($_GET['disabled']) && $_GET['disabled'] === '1');
if ($isDisabledLogout) {
    @setcookie('inventory_disabled', '1', time() + 600, '/');
}

$redir = 'index.php';
if ($isDisabledLogout) {
    $redir .= '?disabled=1';
}
header('Location: ' . $redir);
exit();
