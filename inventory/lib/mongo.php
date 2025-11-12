<?php
declare(strict_types=1);

use MongoDB\Client;
use MongoDB\Database;

if (!function_exists('mongo_db')) {
    /**
     * Returns a MongoDB Database instance or null if the extension is missing or connection fails.
     */
    function mongo_db(): ?Database {
        if (!extension_loaded('mongodb')) {
            return null;
        }
        // Load config constants if present
        if (!defined('MONGO_URI')) {
            $cfg = __DIR__ . '/../config.php';
            if (is_file($cfg)) { require_once $cfg; }
        }
        $uri = defined('MONGO_URI') ? MONGO_URI : 'mongodb://localhost:27017';
        $dbName = defined('MONGO_DB') ? MONGO_DB : 'inventory_system';
        try {
            $client = new Client($uri, [], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
            return $client->selectDatabase($dbName);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
