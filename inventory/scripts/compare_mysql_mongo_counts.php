<?php
// Compare MySQL row counts vs MongoDB document counts for all mapped tables
// Run: http://localhost/inventory/inventory/scripts/compare_mysql_mongo_counts.php

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../db/mongo.php';

header('Content-Type: text/plain');

$mysqlHost = 'localhost';
$mysqlUser = 'root';
$mysqlPass = '';
$mysqlDb   = 'inventory_system';

$tables = [
  'users' => 'users',
  'categories' => 'categories',
  'inventory_items' => 'inventory_items',
  'user_borrows' => 'user_borrows',
  'borrowable_catalog' => 'borrowable_catalog',
  'borrowable_models' => 'borrowable_models',
  'equipment_requests' => 'equipment_requests',
  'inventory_delete_log' => 'inventory_delete_log',
  'inventory_scans' => 'inventory_scans',
  'lost_damaged_log' => 'lost_damaged_log',
  'models' => 'models',
  'notifications' => 'notifications',
  'request_allocations' => 'request_allocations',
  'returned_hold' => 'returned_hold',
  'returned_queue' => 'returned_queue',
  'user_limits' => 'user_limits',
];

try {
  $mysqli = @new mysqli($mysqlHost, $mysqlUser, $mysqlPass, $mysqlDb);
  if ($mysqli->connect_error) {
    throw new RuntimeException('MySQL connect failed: ' . $mysqli->connect_error);
  }
  $mysqli->set_charset('utf8mb4');

  $db = get_mongo_db();
  echo "Comparing counts (MySQL -> Mongo in DB '" . $db->getDatabaseName() . "')\n\n";

  $maxName = 0; foreach ($tables as $t => $c) { $maxName = max($maxName, strlen($t)); }

  foreach ($tables as $table => $collection) {
    // MySQL count
    $mysqlCount = 0;
    if ($res = $mysqli->query('SELECT COUNT(*) AS n FROM `' . $mysqli->real_escape_string($table) . '`')) {
      if ($row = $res->fetch_assoc()) { $mysqlCount = (int)$row['n']; }
      $res->close();
    }
    // Mongo count
    $mongoCount = 0;
    $col = $db->selectCollection($collection);
    $mongoCount = $col->countDocuments([]);

    printf("%-25s %10d  ->  %10d\n", $table, $mysqlCount, $mongoCount);
  }

  echo "\nDone.\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo 'ERROR: ' . $e->getMessage() . "\n";
}
