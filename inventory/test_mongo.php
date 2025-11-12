<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/mongo.php';
header('Content-Type: text/plain');
try {
    $db = get_mongo_db();
    echo "OK: connected to DB '" . $db->getDatabaseName() . "'\n";
    $names = [];
    foreach ($db->listCollections() as $c) { $names[] = $c->getName(); }
    echo "collections: " . implode(', ', $names) . "\n";
    $items = $db->selectCollection('inventory_items')->countDocuments([]);
    $cats = $db->selectCollection('categories')->countDocuments([]);
    $borrows = $db->selectCollection('user_borrows')->countDocuments([]);
    echo "inventory_items count: $items\n";
    echo "categories count: $cats\n";
    echo "user_borrows count: $borrows\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage();
}
