<?php
declare(strict_types=1);

// Toggle to use MongoDB instead of MySQL for reads where supported
const USE_MONGO = true;

// MongoDB connection settings (Docker-friendly defaults; env is preferred elsewhere)
// Note: db/mongo.php reads env (MONGODB_URI, MONGODB_DB) first and falls back to these constants.
const MONGO_URI = 'mongodb://mongo:27017';
const MONGO_DB  = 'inventory_system';
