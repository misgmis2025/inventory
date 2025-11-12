<?php
session_start();
// Simple guard: require admin session or localhost
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1']);
$isAdmin = isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin';
if (!$isLocal && !$isAdmin) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../db/mongo.php';

try {
    $db = get_mongo_db();
    $cats = $db->selectCollection('categories');
    $items = $db->selectCollection('inventory_items');
    $borrows = $db->selectCollection('user_borrows');

    // Seed categories if empty
    if ($cats->countDocuments([]) === 0) {
        $cats->insertMany([
            ['name' => 'Laptops'],
            ['name' => 'Projectors'],
            ['name' => 'Printers'],
            ['name' => 'Accessories'],
        ]);
    }

    // Seed inventory items if empty
    if ($items->countDocuments([]) === 0) {
        $now = date('Y-m-d');
        $items->insertMany([
            [
                'id' => 101,
                'item_name' => 'Dell Latitude 5400',
                'category' => 'Laptops',
                'quantity' => 8,
                'location' => 'IT Room',
                'condition' => 'Good',
                'status' => 'Available',
                'date_acquired' => $now,
            ],
            [
                'id' => 102,
                'item_name' => 'Epson EB-X06 Projector',
                'category' => 'Projectors',
                'quantity' => 2,
                'location' => 'AV Cabinet',
                'condition' => 'Good',
                'status' => 'In Use',
                'date_acquired' => $now,
            ],
            [
                'id' => 103,
                'item_name' => 'HP LaserJet Pro M404',
                'category' => 'Printers',
                'quantity' => 1,
                'location' => 'Admin Office',
                'condition' => 'Good',
                'status' => 'Maintenance',
                'date_acquired' => $now,
            ],
            [
                'id' => 104,
                'item_name' => 'USB-C Hub',
                'category' => 'Accessories',
                'quantity' => 55,
                'location' => 'IT Storeroom',
                'condition' => 'Good',
                'status' => 'Available',
                'date_acquired' => $now,
            ],
        ]);
    }

    // Seed a bit of borrow history if empty
    if ($borrows->countDocuments([]) === 0) {
        $borrows->insertMany([
            [
                'username' => 'palo',
                'model_id' => 101,
                'borrowed_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
                'returned_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'status' => 'Returned',
            ],
            [
                'username' => 'palo',
                'model_id' => 102,
                'borrowed_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'returned_at' => null,
                'status' => 'Borrowed',
            ],
        ]);
    }

    header('Content-Type: text/plain');
    echo "Seed complete.\n";
    echo "categories: " . $cats->countDocuments([]) . "\n";
    echo "inventory_items: " . $items->countDocuments([]) . "\n";
    echo "user_borrows: " . $borrows->countDocuments([]) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'ERROR: ' . $e->getMessage();
}
