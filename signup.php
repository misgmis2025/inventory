<?php
// Thin entrypoint for platforms expecting signup.php at web root.
// Delegate to the real app signup by probing common nested locations.
$candidates = [
  __DIR__ . '/inventory/inventory/inventory/signup.php',
  __DIR__ . '/inventory/inventory/signup.php',
  __DIR__ . '/inventory/signup.php',
];
foreach ($candidates as $p) {
  if (file_exists($p)) { require $p; return; }
}
http_response_code(500);
echo 'Signup entrypoint not found. Checked: ' . implode(', ', $candidates);
