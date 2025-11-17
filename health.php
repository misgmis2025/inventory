<?php
http_response_code(200);
header('Content-Type: text/plain');
// Minimal runtime check without touching Mongo
// Confirms PHP executes, sessions work, and filesystem is writable
try {
  @ini_set('log_errors', '1');
  @ini_set('error_log', '/proc/self/fd/2');
  session_start();
  $_SESSION['__health'] = 'ok';
  $sess = session_id();
} catch (Throwable $e) {
  $sess = 'session-failed:' . $e->getMessage();
}
$paths = [
  __DIR__ . '/tmp_sessions',
  __DIR__ . '/../tmp_sessions',
];
$w = [];
foreach ($paths as $p) {
  $ok = is_dir($p) && is_writable($p);
  $w[] = ($ok ? 'writable ' : 'not-writable ') . $p;
}
echo "OK\nPHP=" . PHP_VERSION . "\nSess=" . $sess . "\n" . implode("\n", $w) . "\n";
