<?php
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../db/mongo.php';
header('Content-Type: text/plain');
try {
    $db = get_mongo_db();

    // inventory_items indexes
    $items = $db->selectCollection('inventory_items');
    $items->createIndex(['id' => 1], ['unique' => false]);
    $items->createIndex(['category' => 1]);
    $items->createIndex(['status' => 1]);
    $items->createIndex(['item_name' => 1]);

    // categories indexes
    $cats = $db->selectCollection('categories');
    $cats->createIndex(['name' => 1], ['unique' => true]);

    // users indexes
    $users = $db->selectCollection('users');
    $users->createIndex(['username' => 1], ['unique' => true]);

    // user_borrows indexes
    $borrows = $db->selectCollection('user_borrows');
    $borrows->createIndex(['username' => 1, 'borrowed_at' => -1]);
    $borrows->createIndex(['model_id' => 1]);

    echo "Indexes created.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage();
}
