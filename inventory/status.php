<?php
header('Content-Type: application/json');

// Basic session diagnostics
$resp = [
  'session' => [
    'status' => session_status(),
    'save_path' => ini_get('session.save_path'),
    'id' => null,
    'username' => null,
    'usertype' => null,
  ],
  'env' => [
    'MONGODB_URI' => getenv('MONGODB_URI') ? true : false,
    'MONGODB_DB' => getenv('MONGODB_DB') ?: null,
  ],
  'db' => [
    'ok' => false,
    'error' => null,
    'dbName' => null,
  ],
];

// Ensure a writable session path similar to index.php
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
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$resp['session']['status'] = session_status();
$resp['session']['id'] = session_id();
$resp['session']['username'] = $_SESSION['username'] ?? null;
$resp['session']['usertype'] = $_SESSION['usertype'] ?? null;

// Optional: set a test session value if requested
if (isset($_GET['set']) && $_GET['set'] === '1') {
  $_SESSION['__probe'] = 'ok';
  @session_write_close();
}

// DB ping
try {
  require_once __DIR__ . '/../vendor/autoload.php';
  require_once __DIR__ . '/db/mongo.php';
  $db = get_mongo_db();
  $db->command(['ping' => 1]);
  $resp['db']['ok'] = true;
  $resp['db']['dbName'] = $db->getDatabaseName();
} catch (Throwable $e) {
  $resp['db']['ok'] = false;
  $resp['db']['error'] = $e->getMessage();
}

echo json_encode($resp, JSON_PRETTY_PRINT);
