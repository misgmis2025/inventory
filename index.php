<?php
// Thin entrypoint for platforms expecting index.php at web root.
// Delegate to the real app index located in inventory/inventory/inventory/index.php
require __DIR__ . '/inventory/inventory/inventory/index.php';
