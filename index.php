<?php
// Thin entrypoint for platforms expecting index.php at web root.
// If an /inventory/index.php exists, redirect there so relative redirects (admin_dashboard.php, etc.) work correctly.
$inventoryIndex = __DIR__ . '/inventory/index.php';
if (file_exists($inventoryIndex)) {
  header('Location: /inventory/index.php');
  exit;
}

// Fallback: delegate to the real app index by probing common nested locations.
$candidates = [
  __DIR__ . '/inventory/inventory/inventory/index.php',
  __DIR__ . '/inventory/inventory/index.php',
  __DIR__ . '/inventory/index.php',
];
foreach ($candidates as $p) {
  if (file_exists($p)) { require $p; return; }
}
http_response_code(500);
echo 'App entrypoint not found. Checked: ' . implode(', ', $candidates);
