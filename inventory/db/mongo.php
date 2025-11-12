<?php
// MongoDB connection helper
// Usage: $db = get_mongo_db();

require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client;

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

        $client = new Client($uri, [
            'retryWrites' => true,
            'w' => 'majority',
        ]);
        $cached = $client->selectDatabase($dbName);
        return $cached;
    }
}

