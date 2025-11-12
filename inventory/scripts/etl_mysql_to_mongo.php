<?php
// ETL: Copy data from MySQL -> MongoDB
// Usage examples:
//   http://localhost/inventory/inventory/scripts/etl_mysql_to_mongo.php?dry=1&limit=100
//   http://localhost/inventory/inventory/scripts/etl_mysql_to_mongo.php

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../db/mongo.php';

header('Content-Type: text/plain');

define('DRY_RUN', isset($_GET['dry']) && $_GET['dry'] == '1');
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 0; // 0 = no limit

// --- MySQL connection (adjust if your creds differ) ---
$mysqlHost = 'localhost';
$mysqlUser = 'root';
$mysqlPass = '';
$mysqlDb   = 'inventory_system';

$mysqli = @new mysqli($mysqlHost, $mysqlUser, $mysqlPass, $mysqlDb);
if ($mysqli->connect_error) {
    http_response_code(500);
    echo 'ERROR: MySQL connect failed: ' . $mysqli->connect_error . "\n";
    exit;
}
$mysqli->set_charset('utf8mb4');

function dt_to_utc($val) {
    if ($val === null || $val === '' || $val === '0000-00-00 00:00:00') return null;
    $ts = strtotime((string)$val);
    if ($ts === false) return null;
    return new MongoDB\BSON\UTCDateTime($ts * 1000);
}

try {
    $db = get_mongo_db();

    $cxUsers    = $db->selectCollection('users');
    $cxCats     = $db->selectCollection('categories');
    $cxItems    = $db->selectCollection('inventory_items');
    $cxBorrows  = $db->selectCollection('user_borrows');

    echo 'DRY_RUN=' . (DRY_RUN ? '1' : '0') . ", LIMIT=" . $limit . "\n";

    // --- Users ---
    if ($res = $mysqli->query('SELECT * FROM users' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'legacyId'      => isset($row['id']) ? (int)$row['id'] : null,
                'username'      => (string)($row['username'] ?? ''),
                'full_name'     => (string)($row['full_name'] ?? ''),
                'role'          => (string)($row['usertype'] ?? ($row['role'] ?? '')),
                'email'         => (string)($row['email'] ?? ''),
                'password_hash' => (string)($row['password'] ?? ($row['password_hash'] ?? '')),
                'created_at'    => dt_to_utc($row['created_at'] ?? null),
                'updated_at'    => dt_to_utc($row['updated_at'] ?? null),
            ];
            if (!DRY_RUN) {
                $cxUsers->updateOne(
                    ['username' => $doc['username']],
                    ['$set' => $doc],
                    ['upsert' => true]
                );
            }
            $n++;
        }
        echo "users: processed $n\n";
        $res->close();
    }

    // --- Categories ---
    if ($res = $mysqli->query('SELECT * FROM categories' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $name = (string)($row['name'] ?? '');
            if ($name === '') continue;
            $doc = [
                'name' => $name,
            ];
            if (!DRY_RUN) {
                $cxCats->updateOne(
                    ['name' => $name],
                    ['$set' => $doc],
                    ['upsert' => true]
                );
            }
            $n++;
        }
        echo "categories: processed $n\n";
        $res->close();
    }

    // --- Inventory Items ---
    if ($res = $mysqli->query('SELECT * FROM inventory_items' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'            => isset($row['id']) ? (int)$row['id'] : null,
                'item_name'     => (string)($row['item_name'] ?? ($row['model'] ?? '')),
                'category'      => (string)($row['category'] ?? ''),
                'quantity'      => isset($row['quantity']) ? (int)$row['quantity'] : null,
                'location'      => (string)($row['location'] ?? ''),
                'condition'     => (string)($row['condition'] ?? ''),
                'status'        => (string)($row['status'] ?? ''),
                'date_acquired' => (string)($row['date_acquired'] ?? ''), // keep as string for now
                'created_at'    => dt_to_utc($row['created_at'] ?? null),
                'updated_at'    => dt_to_utc($row['updated_at'] ?? null),
                'legacyId'      => isset($row['id']) ? (int)$row['id'] : null,
            ];
            if (!DRY_RUN) {
                $cxItems->updateOne(
                    ['id' => $doc['id']],
                    ['$set' => $doc],
                    ['upsert' => true]
                );
            }
            $n++;
        }
        echo "inventory_items: processed $n\n";
        $res->close();
    }

    // --- User Borrows ---
    if ($res = $mysqli->query('SELECT * FROM user_borrows' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'legacyId'    => isset($row['id']) ? (int)$row['id'] : null,
                'username'    => (string)($row['username'] ?? ''),
                'model_id'    => isset($row['model_id']) ? (int)$row['model_id'] : null,
                'borrowed_at' => dt_to_utc($row['borrowed_at'] ?? null),
                'returned_at' => dt_to_utc($row['returned_at'] ?? null),
                'status'      => (string)($row['status'] ?? ''),
            ];
            if (!DRY_RUN) {
                $cxBorrows->updateOne(
                    ['legacyId' => $doc['legacyId']],
                    ['$set' => $doc],
                    ['upsert' => true]
                );
            }
            $n++;
        }
        echo "user_borrows: processed $n\n";
        $res->close();
    }

    // --- Borrowable Catalog ---
    $cxBC = $db->selectCollection('borrowable_catalog');
    if ($res = $mysqli->query('SELECT * FROM borrowable_catalog' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'             => isset($row['id']) ? (int)$row['id'] : null,
                'model_name'     => (string)($row['model_name'] ?? ''),
                'category'       => (string)($row['category'] ?? ''),
                'active'         => isset($row['active']) ? (int)$row['active'] : 0,
                'per_user_limit' => isset($row['per_user_limit']) ? (int)$row['per_user_limit'] : null,
                'global_limit'   => isset($row['global_limit']) ? (int)$row['global_limit'] : null,
                'created_at'     => dt_to_utc($row['created_at'] ?? null),
            ];
            if (!DRY_RUN) {
                $cxBC->updateOne(['id' => $doc['id']], ['$set' => $doc], ['upsert' => true]);
            }
            $n++;
        }
        echo "borrowable_catalog: processed $n\n";
        $res->close();
    }

    // --- Borrowable Models ---
    $cxBM = $db->selectCollection('borrowable_models');
    if ($res = $mysqli->query('SELECT * FROM borrowable_models' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'           => isset($row['id']) ? (int)$row['id'] : null,
                'model_name'   => (string)($row['model_name'] ?? ''),
                'category'     => (string)($row['category'] ?? ''),
                'active'       => isset($row['active']) ? (int)$row['active'] : 0,
                'pool_qty'     => isset($row['pool_qty']) ? (int)$row['pool_qty'] : 0,
                'borrow_limit' => isset($row['borrow_limit']) ? (int)$row['borrow_limit'] : 1,
                'created_at'   => dt_to_utc($row['created_at'] ?? null),
            ];
            if (!DRY_RUN) { $cxBM->updateOne(['id' => $doc['id']], ['$set' => $doc], ['upsert' => true]); }
            $n++;
        }
        echo "borrowable_models: processed $n\n";
        $res->close();
    }

    // --- Equipment Requests ---
    $cxReq = $db->selectCollection('equipment_requests');
    if ($res = $mysqli->query('SELECT * FROM equipment_requests' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'          => isset($row['id']) ? (int)$row['id'] : null,
                'username'    => (string)($row['username'] ?? ''),
                'item_name'   => (string)($row['item_name'] ?? ''),
                'quantity'    => isset($row['quantity']) ? (int)$row['quantity'] : 1,
                'details'     => (string)($row['details'] ?? ''),
                'status'      => (string)($row['status'] ?? ''),
                'created_at'  => dt_to_utc($row['created_at'] ?? null),
                'approved_at' => dt_to_utc($row['approved_at'] ?? null),
                'rejected_at' => dt_to_utc($row['rejected_at'] ?? null),
                'borrowed_at' => dt_to_utc($row['borrowed_at'] ?? null),
                'returned_at' => dt_to_utc($row['returned_at'] ?? null),
            ];
            if (!DRY_RUN) { $cxReq->updateOne(['id' => $doc['id']], ['$set' => $doc], ['upsert' => true]); }
            $n++;
        }
        echo "equipment_requests: processed $n\n";
        $res->close();
    }

    // --- Inventory Delete Log ---
    $cxDel = $db->selectCollection('inventory_delete_log');
    if ($res = $mysqli->query('SELECT * FROM inventory_delete_log' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'         => isset($row['id']) ? (int)$row['id'] : null,
                'item_id'    => isset($row['item_id']) ? (int)$row['item_id'] : null,
                'deleted_by' => (string)($row['deleted_by'] ?? ''),
                'deleted_at' => dt_to_utc($row['deleted_at'] ?? null),
                'reason'     => (string)($row['reason'] ?? ''),
                'item_name'  => (string)($row['item_name'] ?? ''),
                'model'      => (string)($row['model'] ?? ''),
                'category'   => (string)($row['category'] ?? ''),
                'quantity'   => isset($row['quantity']) ? (int)$row['quantity'] : null,
                'status'     => (string)($row['status'] ?? ''),
            ];
            if (!DRY_RUN) { $cxDel->updateOne(['id' => $doc['id']], ['$set' => $doc], ['upsert' => true]); }
            $n++;
        }
        echo "inventory_delete_log: processed $n\n";
        $res->close();
    }

    // --- Inventory Scans ---
    $cxScans = $db->selectCollection('inventory_scans');
    if ($res = $mysqli->query('SELECT * FROM inventory_scans' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'            => isset($row['id']) ? (int)$row['id'] : null,
                'model_id'      => isset($row['model_id']) ? (int)$row['model_id'] : null,
                'item_name'     => (string)($row['item_name'] ?? ''),
                'status'        => (string)($row['status'] ?? ''),
                'form_type'     => (string)($row['form_type'] ?? ''),
                'room'          => (string)($row['room'] ?? ''),
                'generated_date'=> dt_to_utc($row['generated_date'] ?? null),
                'scanned_at'    => dt_to_utc($row['scanned_at'] ?? null),
                'scanned_by'    => (string)($row['scanned_by'] ?? ''),
                'raw_qr'        => (string)($row['raw_qr'] ?? ''),
            ];
            if (!DRY_RUN) { $cxScans->updateOne(['id' => $doc['id']], ['$set' => $doc], ['upsert' => true]); }
            $n++;
        }
        echo "inventory_scans: processed $n\n";
        $res->close();
    }

    // --- Lost/Damaged Log ---
    $cxLD = $db->selectCollection('lost_damaged_log');
    if ($res = $mysqli->query('SELECT * FROM lost_damaged_log' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'         => isset($row['id']) ? (int)$row['id'] : null,
                'model_id'   => isset($row['model_id']) ? (int)$row['model_id'] : null,
                'username'   => (string)($row['username'] ?? ''),
                'action'     => (string)($row['action'] ?? ''),
                'noted_at'   => dt_to_utc($row['noted_at'] ?? null),
                'created_at' => dt_to_utc($row['created_at'] ?? null),
                'notes'      => (string)($row['notes'] ?? ''),
            ];
            if (!DRY_RUN) { $cxLD->updateOne(['id' => $doc['id']], ['$set' => $doc], ['upsert' => true]); }
            $n++;
        }
        echo "lost_damaged_log: processed $n\n";
        $res->close();
    }

    // --- Models ---
    $cxModels = $db->selectCollection('models');
    if ($res = $mysqli->query('SELECT * FROM models' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'          => isset($row['id']) ? (int)$row['id'] : null,
                'category_id' => isset($row['category_id']) ? (int)$row['category_id'] : null,
                'name'        => (string)($row['name'] ?? ''),
                'created_at'  => dt_to_utc($row['created_at'] ?? null),
            ];
            if (!DRY_RUN) { $cxModels->updateOne(['id' => $doc['id']], ['$set' => $doc], ['upsert' => true]); }
            $n++;
        }
        echo "models: processed $n\n";
        $res->close();
    }

    // --- Notifications ---
    $cxNotif = $db->selectCollection('notifications');
    if ($res = $mysqli->query('SELECT * FROM notifications' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'         => isset($row['id']) ? (int)$row['id'] : null,
                'type'       => (string)($row['type'] ?? ''),
                'username'   => (string)($row['username'] ?? ''),
                'request_id' => isset($row['request_id']) ? (int)$row['request_id'] : null,
                'audience'   => (string)($row['audience'] ?? ''),
                'seen'       => isset($row['seen']) ? (int)$row['seen'] : 0,
                'created_at' => dt_to_utc($row['created_at'] ?? null),
            ];
            if (!DRY_RUN) { $cxNotif->updateOne(['id' => $doc['id']], ['$set' => $doc], ['upsert' => true]); }
            $n++;
        }
        echo "notifications: processed $n\n";
        $res->close();
    }

    // --- Request Allocations ---
    $cxRA = $db->selectCollection('request_allocations');
    if ($res = $mysqli->query('SELECT * FROM request_allocations' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'           => isset($row['id']) ? (int)$row['id'] : null,
                'request_id'   => isset($row['request_id']) ? (int)$row['request_id'] : null,
                'borrow_id'    => isset($row['borrow_id']) ? (int)$row['borrow_id'] : null,
                'allocated_at' => dt_to_utc($row['allocated_at'] ?? null),
            ];
            if (!DRY_RUN) { $cxRA->updateOne(['id' => $doc['id']], ['$set' => $doc], ['upsert' => true]); }
            $n++;
        }
        echo "request_allocations: processed $n\n";
        $res->close();
    }

    // --- Returned Hold ---
    $cxRH = $db->selectCollection('returned_hold');
    if ($res = $mysqli->query('SELECT * FROM returned_hold' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'         => isset($row['id']) ? (int)$row['id'] : null,
                'model_id'   => isset($row['model_id']) ? (int)$row['model_id'] : null,
                'model_name' => (string)($row['model_name'] ?? ''),
                'category'   => (string)($row['category'] ?? ''),
                'source_qid' => isset($row['source_qid']) ? (int)$row['source_qid'] : null,
                'held_at'    => dt_to_utc($row['held_at'] ?? null),
            ];
            if (!DRY_RUN) { $cxRH->updateOne(['id' => $doc['id']], ['$set' => $doc], ['upsert' => true]); }
            $n++;
        }
        echo "returned_hold: processed $n\n";
        $res->close();
    }

    // --- Returned Queue ---
    $cxRQ = $db->selectCollection('returned_queue');
    if ($res = $mysqli->query('SELECT * FROM returned_queue' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'id'           => isset($row['id']) ? (int)$row['id'] : null,
                'model_id'     => isset($row['model_id']) ? (int)$row['model_id'] : null,
                'source'       => (string)($row['source'] ?? ''),
                'queued_at'    => dt_to_utc($row['queued_at'] ?? null),
                'processed_at' => dt_to_utc($row['processed_at'] ?? null),
                'processed_by' => (string)($row['processed_by'] ?? ''),
                'action'       => (string)($row['action'] ?? ''),
                'notes'        => (string)($row['notes'] ?? ''),
            ];
            if (!DRY_RUN) { $cxRQ->updateOne(['id' => $doc['id']], ['$set' => $doc], ['upsert' => true]); }
            $n++;
        }
        echo "returned_queue: processed $n\n";
        $res->close();
    }

    // --- User Limits ---
    $cxUL = $db->selectCollection('user_limits');
    if ($res = $mysqli->query('SELECT * FROM user_limits' . ($limit ? ' LIMIT ' . $limit : ''))) {
        $n = 0; while ($row = $res->fetch_assoc()) {
            $doc = [
                'username'   => (string)($row['username'] ?? ''),
                'max_active' => isset($row['max_active']) ? (int)$row['max_active'] : 3,
            ];
            if (!DRY_RUN) { $cxUL->updateOne(['username' => $doc['username']], ['$set' => $doc], ['upsert' => true]); }
            $n++;
        }
        echo "user_limits: processed $n\n";
        $res->close();
    }

    echo "Done.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
