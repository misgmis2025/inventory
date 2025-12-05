<?php
// MongoDB connection helper
// Usage: $db = get_mongo_db();

// Try multiple vendor locations to support both nested layout and promoted web root layout
$__autoloads = [
    __DIR__ . '/vendor/autoload.php',            // when db/ lives at web root (after Docker promotion)
    __DIR__ . '/../vendor/autoload.php',         // common one-level-up
    __DIR__ . '/../../vendor/autoload.php',      // inventory/inventory/vendor (nested)
    __DIR__ . '/../../../vendor/autoload.php',   // inventory/vendor (project root)
];
foreach ($__autoloads as $__a) {
    if (file_exists($__a)) { require_once $__a; break; }
}

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

if (!function_exists('get_mongo_db')) {
    function get_mongo_db(): MongoDB\Database {
        static $cached = null;
        if ($cached instanceof MongoDB\Database) {
            return $cached;
        }

        // Prefer env vars
        $uri = getenv('MONGODB_URI') ?: '';
        $dbName = getenv('MONGODB_DB') ?: '';

        // Fallback to local config file if present
        $configPath = __DIR__ . '/../config/mongo.php';
        if ((empty($uri) || empty($dbName)) && file_exists($configPath)) {
            $cfg = require $configPath; // returns ['uri' => '...', 'db' => '...']
            if (is_array($cfg)) {
                $uri = $uri ?: ($cfg['uri'] ?? '');
                $dbName = $dbName ?: ($cfg['db'] ?? '');
            }
        }

        // Fallback to config.php constants if defined
        if (empty($uri) || empty($dbName)) {
            $constCfg = __DIR__ . '/../config.php';
            if (file_exists($constCfg)) {
                require_once $constCfg; // may define MONGO_URI, MONGO_DB
                if (defined('MONGO_URI') && empty($uri)) { $uri = MONGO_URI; }
                if (defined('MONGO_DB') && empty($dbName)) { $dbName = MONGO_DB; }
            }
        }

        if (empty($uri) || empty($dbName)) {
            throw new RuntimeException('MongoDB config missing. Set MONGODB_URI and MONGODB_DB env vars or create inventory/config/mongo.php');
        }
        
        // Ensure MongoDB uses UTC for all date operations
        $options = [
            'typeMap' => [
                'root' => 'array',
                'document' => 'array',
                'array' => 'array'
            ]
        ];
        
        $client = new Client($uri, [
            'retryWrites' => true,
            'w' => 'majority',
            'typeMap' => [
                'root' => 'array',
                'document' => 'array',
                'array' => 'array'
            ]
        ]);
        
        try {
            // Set up MongoDB to use UTC for all date operations
            $command = new MongoDB\Driver\Command(['setParameter' => 1, 'timeZoneInfo' => ['timezone' => 'UTC']]);
            $client->getManager()->executeCommand('admin', $command);
        } catch (Exception $e) {
            error_log('MongoDB timezone setting warning: ' . $e->getMessage());
            // Continue even if timezone setting fails
        }
        
        $cached = $client->selectDatabase($dbName);
        try {
            inventory_ensure_indexes($cached);
        } catch (\Throwable $e) {
            error_log('Mongo index ensure warning (runtime): ' . $e->getMessage());
        }
        return $cached;
    }
}

if (!function_exists('inventory_ensure_indexes')) {
    function inventory_ensure_indexes(MongoDB\Database $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $items = $db->selectCollection('inventory_items');
            $items->createIndex(['id' => 1], ['name' => 'inv_items_id', 'unique' => true]);
            $items->createIndex(['serial_no' => 1], ['name' => 'inv_items_serial']);
            $items->createIndex(['status' => 1, 'category' => 1], ['name' => 'inv_items_status_cat']);
            $items->createIndex(['status' => 1, 'quantity' => 1], ['name' => 'inv_items_status_qty']);
            $items->createIndex(['category' => 1], ['name' => 'inv_items_category']);
            $items->createIndex(['date_acquired' => 1], ['name' => 'inv_items_date']);
            $items->createIndex(['created_at' => 1], ['name' => 'inv_items_created']);
            $items->createIndex(['item_name' => 1], ['name' => 'inv_items_name']);
            $items->createIndex(['model' => 1], ['name' => 'inv_items_model']);
            $items->createIndex(['location' => 1], ['name' => 'inv_items_location']);

            $er = $db->selectCollection('equipment_requests');
            $er->createIndex(['username' => 1, 'created_at' => -1], ['name' => 'er_user_created']);
            $er->createIndex(['username' => 1, 'type' => 1, 'status' => 1, 'reserved_from' => 1], ['name' => 'er_user_reservations']);
            $er->createIndex(['reserved_model_id' => 1, 'type' => 1, 'status' => 1], ['name' => 'er_reserved_model']);

            $ub = $db->selectCollection('user_borrows');
            $ub->createIndex(['status' => 1], ['name' => 'ub_status']);
            $ub->createIndex(['username' => 1, 'status' => 1], ['name' => 'ub_user_status']);
            $ub->createIndex(['model_id' => 1, 'status' => 1], ['name' => 'ub_model_status']);

            $bc = $db->selectCollection('borrowable_catalog');
            $bc->createIndex(['active' => 1], ['name' => 'bc_active']);

            $rq = $db->selectCollection('returned_queue');
            $rq->createIndex(['processed_at' => 1], ['name' => 'rq_processed']);
            $rq->createIndex(['model_id' => 1], ['name' => 'rq_model']);

            $rh = $db->selectCollection('returned_hold');
            $rh->createIndex(['category' => 1, 'model_name' => 1], ['name' => 'rh_cat_model']);

            $users = $db->selectCollection('users');
            $users->createIndex(['username' => 1], ['name' => 'users_username', 'unique' => true]);
            $users->createIndex(['usertype' => 1], ['name' => 'users_usertype']);

            $scans = $db->selectCollection('inventory_scans');
            $scans->createIndex(['id' => 1], ['name' => 'scans_id', 'unique' => true]);
        } catch (\Throwable $e) {
            error_log('Mongo index ensure warning: ' . $e->getMessage());
        }
    }
}
