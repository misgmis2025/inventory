<?php
// MongoDB connection helper
// Usage: $db = get_mongo_db();

// Set default timezone to UTC for database operations
date_default_timezone_set('UTC');

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
        return $cached;
    }
}

