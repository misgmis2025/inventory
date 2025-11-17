<?php
// Shim autoloader for nested app: delegate to repo-root Composer autoload
$paths = [
    __DIR__ . '/../../vendor/autoload.php',  // repo root vendor
    __DIR__ . '/../vendor/autoload.php',     // sibling (in case of different layout)
    __DIR__ . '/vendor/autoload.php',        // local (unlikely)
];
foreach ($paths as $p) {
    if (file_exists($p)) { require $p; return; }
}
// Last resort: do nothing to avoid fatal, but emit a log
@error_log('[autoload-shim] Could not locate Composer autoload.php in expected locations.');
