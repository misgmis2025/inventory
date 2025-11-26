<?php
// Align session settings with index.php to ensure continuity across requests
$__sess_path = ini_get('session.save_path');
if (!$__sess_path || !is_dir($__sess_path) || !is_writable($__sess_path)) {
    $__alt = __DIR__ . '/../tmp_sessions';
    if (!is_dir($__alt)) { @mkdir($__alt, 0777, true); }
    if (is_dir($__alt)) { @ini_set('session.save_path', $__alt); }
}
@ini_set('session.cookie_secure', '0');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');
@ini_set('session.cookie_path', '/');
@ini_set('session.use_strict_mode', '1');
session_start();
date_default_timezone_set('Asia/Manila');
// Initialize Mongo guard early so it's defined for all branches
$MONGO_FILLED = false;
if (!isset($_SESSION['username'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header("Location: index.php");
    } else if (!$MONGO_FILLED) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    }
    exit();
}

// Redirect regular users away from full inventory page to their own items view
// but allow specific JSON actions (e.g., item_by_serial) needed by scanners on user pages
$__getAct = isset($_GET['action']) ? (string)$_GET['action'] : '';
if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'user') {
    $allowUserActions = ['item_by_serial','check_serial'];
    if ($__getAct === '' || !in_array($__getAct, $allowUserActions, true)) {
        header('Location: user_items.php');
        exit();
    }
}

// Prime GET filter variables before Mongo GET path uses them
$search_q = trim($_GET['q'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_category = trim($_GET['category'] ?? '');
$filter_condition = trim($_GET['condition'] ?? '');
$filter_supply = trim($_GET['supply'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? ''); 
$cat_id_raw = trim($_GET['cat_id'] ?? '');
$location_search_raw = trim($_GET['loc'] ?? '');
$model_id_search_raw = trim($_GET['mid'] ?? '');
if ($model_id_search_raw !== '') {
    if (preg_match('/(\d{1,})/', $model_id_search_raw, $mm)) { $modelIdSearch = (int)$mm[1]; }
    else { $modelIdSearch = (int)$model_id_search_raw; }
} else { $modelIdSearch = 0; }

// Early Mongo GET handlers to avoid MySQL dependency
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $act = $_GET['action'];
    if ($act === 'check_serial') {
        header('Content-Type: application/json');
        $sid = trim($_GET['sid'] ?? '');
        $exclude = intval($_GET['exclude_id'] ?? 0);
        $exists = false;
        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            require_once __DIR__ . '/db/mongo.php';
            $db = get_mongo_db();
            $itemsCol = $db->selectCollection('inventory_items');
            if ($sid !== '') {
                $query = ['serial_no' => $sid];
                if ($exclude > 0) { $query['id'] = ['$ne' => $exclude]; }
                $exists = $itemsCol->countDocuments($query) > 0;
            }
        } catch (Throwable $e) {
            // No MySQL fallback in production; leave $exists=false
        }
        echo json_encode(['exists'=>$exists]);
        exit();
    }
    if ($act === 'item_by_serial') {
        header('Content-Type: application/json');
        $sid = trim($_GET['sid'] ?? '');
        $result = null;
        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            require_once __DIR__ . '/db/mongo.php';
            $db = get_mongo_db();
            $itemsCol = $db->selectCollection('inventory_items');
            $borrowsCol = $db->selectCollection('user_borrows');
            $reqCol = $db->selectCollection('equipment_requests');
            $usersCol = $db->selectCollection('users');
            $doc = null;
            if ($sid !== '') {
                $doc = $itemsCol->findOne(['serial_no' => $sid]);
                if (!$doc && ctype_digit($sid)) {
                    $doc = $itemsCol->findOne(['id' => (int)$sid]);
                }
            }
            if ($doc) {
                $mid = (int)($doc['id'] ?? 0);
                $result = [
                    'id' => $mid,
                    'item_name' => (string)($doc['item_name'] ?? ''),
                    'model' => (string)($doc['model'] ?? ''),
                    'category' => (string)($doc['category'] ?? ''),
                    'status' => (string)($doc['status'] ?? 'Available'),
                    'location' => (string)($doc['location'] ?? ''),
                    'remarks' => (string)($doc['remarks'] ?? ''),
                    'serial_no' => (string)($doc['serial_no'] ?? ''),
                ];
                // Borrow info (active)
                try {
                    $borrow = $borrowsCol->findOne(['model_id' => $mid, 'status' => 'Borrowed']);
                    if ($borrow) {
                        $u = (string)($borrow['username'] ?? '');
                        $full = '';
                        if ($u !== '') {
                            $ud = $usersCol->findOne(['username' => $u], ['projection' => ['full_name' => 1]]);
                            if ($ud && isset($ud['full_name']) && trim((string)$ud['full_name']) !== '') { $full = (string)$ud['full_name']; }
                        }
                        $result['borrowed_by_username'] = $u;
                        $result['borrowed_by_full_name'] = $full;
                        $result['expected_return_at'] = (string)($borrow['expected_return_at'] ?? '');
                    }
                } catch (Throwable $_e) {}
                // Upcoming reservation for this specific unit (if any)
                try {
                    $nowStr = date('Y-m-d H:i:s');
                    $res = $reqCol->findOne([
                        'type' => 'reservation',
                        'status' => 'Approved',
                        'reserved_model_id' => $mid,
                        '$or' => [ ['reserved_to' => ['$gte' => $nowStr]], ['reserved_from' => ['$gte' => $nowStr]] ]
                    ]);
                    if ($res) {
                        $ru = (string)($res['username'] ?? '');
                        $rfull = '';
                        if ($ru !== '') {
                            $rd = $usersCol->findOne(['username' => $ru], ['projection' => ['full_name' => 1]]);
                            if ($rd && isset($rd['full_name']) && trim((string)$rd['full_name']) !== '') { $rfull = (string)$rd['full_name']; }
                        }
                        $result['reservation_by_username'] = $ru;
                        $result['reservation_by_full_name'] = $rfull;
                        $result['reserved_from'] = (string)($res['reserved_from'] ?? '');
                        $result['reserved_to'] = (string)($res['reserved_to'] ?? '');
                        // If not currently borrowed, reflect derived status as Reserved for display
                        if (empty($result['borrowed_by_username'])) {
                            $result['status'] = 'Reserved';
                        }
                    }
                } catch (Throwable $_e2) {}
            }
        } catch (Throwable $e) {
            // No MySQL fallback here to avoid initializing connection in early GET path
        }
        echo json_encode(['success' => (bool)$result, 'item' => $result]);
        exit();
    }
    if ($act === 'delete' && isset($_GET['id'])) {
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/db/mongo.php';
        try {
            $db = get_mongo_db();
            // Require admin session and password
            if (!isset($_SESSION['username']) || !isset($_SESSION['usertype']) || $_SESSION['usertype'] !== 'admin') { header('Location: inventory.php?error=auth'); exit(); }
            $inputPw = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : ((isset($_GET['admin_password']) ? (string)$_GET['admin_password'] : ''));
            if ($inputPw === '') { header('Location: inventory.php?error=pw_required'); exit(); }
            $users = $db->selectCollection('users');
            $u = $users->findOne(['username' => (string)$_SESSION['username']], ['projection'=>['password'=>1,'password_hash'=>1]]);
            $ok = false;
            $storedHash = $u && isset($u['password_hash']) ? (string)$u['password_hash'] : '';
            $storedPlain = $u && isset($u['password']) ? (string)$u['password'] : '';
            if ($storedHash !== '') { $ok = function_exists('password_verify') ? password_verify($inputPw, $storedHash) : ($inputPw === $storedHash); }
            if (!$ok && $storedPlain !== '') { $ok = (function_exists('password_verify') ? password_verify($inputPw, $storedPlain) : ($inputPw === $storedPlain)) || ($inputPw === $storedPlain); }
            if (!$ok) { header('Location: inventory.php?error=badpw'); exit(); }

            $scans = $db->selectCollection('inventory_scans');
            $id = intval($_GET['id']);
            if ($id > 0) { $scans->deleteOne(['id' => $id]); }
            header("Location: inventory.php?deleted=1");
            exit();
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Database unavailable';
            exit();
        }
    }
    if ($act === 'delete_item' && isset($_GET['item_id']) && isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin') {
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/db/mongo.php';
        try {
            $db = get_mongo_db();
            // Require admin password
            if (!isset($_SESSION['username'])) { header('Location: inventory.php?error=auth'); exit(); }
            $inputPw = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : ((isset($_GET['admin_password']) ? (string)$_GET['admin_password'] : ''));
            if ($inputPw === '') { header('Location: inventory.php?error=pw_required'); exit(); }
            $users = $db->selectCollection('users');
            $u = $users->findOne(['username' => (string)$_SESSION['username']], ['projection'=>['password'=>1,'password_hash'=>1]]);
            $ok = false;
            $storedHash = $u && isset($u['password_hash']) ? (string)$u['password_hash'] : '';
            $storedPlain = $u && isset($u['password']) ? (string)$u['password'] : '';
            if ($storedHash !== '') { $ok = function_exists('password_verify') ? password_verify($inputPw, $storedHash) : ($inputPw === $storedHash); }
            if (!$ok && $storedPlain !== '') { $ok = (function_exists('password_verify') ? password_verify($inputPw, $storedPlain) : ($inputPw === $storedPlain)) || ($inputPw === $storedPlain); }
            if (!$ok) { header('Location: inventory.php?error=badpw'); exit(); }

            $itemsCol = $db->selectCollection('inventory_items');
            $delLogCol = $db->selectCollection('inventory_delete_log');
            $itemId = intval($_GET['item_id']);
            $reason = trim($_GET['reason'] ?? '');
            if ($itemId > 0) {
                $row = $itemsCol->findOne(['id'=>$itemId]);
                if ($row) {
                    $stDel = (string)($row['status'] ?? '');
                    if (strcasecmp($stDel, 'In Use') === 0) { header('Location: inventory.php?error=delete_in_use'); exit(); }
                    $last = $delLogCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
                    $nextId = ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
                    $delLogCol->insertOne([
                        'id' => $nextId,
                        'item_id' => $itemId,
                        'deleted_by' => $_SESSION['username'],
                        'deleted_at' => date('Y-m-d H:i:s'),
                        'reason' => $reason !== '' ? $reason : 'Deleted',
                        'item_name' => (string)($row['item_name'] ?? ''),
                        'model' => (string)($row['model'] ?? ''),
                        'category' => (string)($row['category'] ?? ''),
                        'quantity' => (int)($row['quantity'] ?? 1),
                        'status' => (string)($row['status'] ?? ''),
                        'serial_no' => (string)($row['serial_no'] ?? ''),
                    ]);
                    $itemsCol->deleteOne(['id'=>$itemId]);
                    header('Location: inventory.php?deleted_item=1');
                    exit();
                }
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Database unavailable';
            exit();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($contentType, 'application/json') !== false;
    if ($isJson) {
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/db/mongo.php';
        header('Content-Type: application/json');
        try {
            $db = get_mongo_db();
            $itemsCol = $db->selectCollection('inventory_items');
            $scansCol = $db->selectCollection('inventory_scans');
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            if (!$data || empty($data['item_name'])) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid payload']); exit; }
            $item_name = trim($data['item_name'] ?? '');
            $status = trim($data['status'] ?? '');
            $form_type = trim($data['form_type'] ?? '');
            $room = trim($data['room'] ?? '');
            $generated_date = trim($data['generated_date'] ?? '');
            $scanned_by = $_SESSION['username'];
            $model_id = intval($data['model_id'] ?? 0);
            if ($model_id <= 0 && $item_name !== '') {
                $doc = $itemsCol->findOne(['item_name' => $item_name], ['sort'=>['id'=>1], 'projection'=>['id'=>1]]);
                if ($doc && isset($doc['id'])) { $model_id = (int)$doc['id']; }
            }
            $now = date('Y-m-d H:i:s');
            if ($model_id > 0) {
                $scansCol->updateOne(
                    ['model_id' => $model_id],
                    ['$set' => [
                        'model_id' => $model_id,
                        'item_name' => $item_name,
                        'status' => $status,
                        'form_type' => $form_type,
                        'room' => $room,
                        'generated_date' => $generated_date,
                        'scanned_by' => $scanned_by,
                        'raw_qr' => $raw,
                        'scanned_at' => $now,
                    ]],
                    ['upsert' => true]
                );
                echo json_encode(['success' => true, 'model_id' => $model_id]);
            } else {
                $last = $scansCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
                $nextId = ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
                $scansCol->insertOne([
                    'id' => $nextId,
                    'item_name' => $item_name,
                    'status' => $status,
                    'form_type' => $form_type,
                    'room' => $room,
                    'generated_date' => $generated_date,
                    'scanned_by' => $scanned_by,
                    'raw_qr' => $raw,
                    'scanned_at' => $now,
                ]);
                echo json_encode(['success' => true, 'id' => $nextId]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Database unavailable';
            exit();
        }
        exit();
    }
    // Handle admin form submits via MongoDB only
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/db/mongo.php';
    try {
        $db = get_mongo_db();
        $itemsCol = $db->selectCollection('inventory_items');
        $delLogCol = $db->selectCollection('inventory_delete_log');
        $bmCol = $db->selectCollection('borrowable_catalog');
        $nextId = function($col) use ($db) {
            $last = $db->selectCollection($col)->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
            return ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
        };

        // Admin form submit to create a new inventory item
        if (isset($_POST['create_item']) && isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin') {
            $item_name = trim($_POST['item_name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $quantity = max(0, intval($_POST['quantity'] ?? 1));
            $location = trim($_POST['location'] ?? '');
            if ($location === '') { $location = 'MIS Office'; }
            $condition = trim($_POST['condition'] ?? '');
            $status = trim($_POST['status'] ?? 'Available');
            $date_acquired = trim($_POST['date_acquired'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');
            $serial_no = trim($_POST['serial_no'] ?? '');
            if ($item_name === '' && $model !== '') { $item_name = $model; }
            if ($item_name !== '' && $quantity > 0) {
                for ($i=0; $i < $quantity; $i++) {
                    $id = $nextId('inventory_items');
                    $itemsCol->insertOne([
                        'id' => $id,
                        'item_name' => $item_name,
                        'category' => $category,
                        'model' => $model,
                        'quantity' => 1,
                        'location' => $location,
                        'condition' => $condition,
                        'status' => $status,
                        'date_acquired' => $date_acquired,
                        'remarks' => $remarks,
                        'serial_no' => $serial_no,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
            header('Location: inventory.php?item_created=1');
            exit();
        }

        // Admin form submit to update an inventory item
        if (isset($_POST['update_item']) && isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin') {
            $id_edit = intval($_POST['id'] ?? 0);
            $item_name = trim($_POST['item_name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $quantity = max(0, intval($_POST['quantity'] ?? 1));
            $location = trim($_POST['location'] ?? '');
            $status = trim($_POST['status'] ?? 'Available');
            $date_acquired = trim($_POST['date_acquired'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');
            $serial_no = trim($_POST['serial_no'] ?? '');
            if ($item_name === '' && $model !== '') { $item_name = $model; }
            // Uniqueness check for serial_no (Mongo)
            if ($serial_no !== '') {
                $dup = $itemsCol->countDocuments(['serial_no'=>$serial_no,'id'=>['$ne'=>$id_edit]]) > 0;
                if ($dup) { http_response_code(400); echo 'Serial ID already exists for another item.'; exit(); }
            }
            if ($id_edit > 0 && $item_name !== '') {
                $set = [
                    'item_name' => $item_name,
                    'category' => $category,
                    'model' => $model,
                    'quantity' => $quantity,
                    'location' => $location,
                    'status' => $status,
                    'remarks' => $remarks,
                    'serial_no' => $serial_no,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($date_acquired !== '') { $set['date_acquired'] = $date_acquired; }
                // Fetch previous item for change detection
                $prev = $itemsCol->findOne(['id'=>$id_edit]);
                // Server-side guard: block edits while item is In Use
                $prevStatus = $prev && isset($prev['status']) ? (string)$prev['status'] : '';
                if ($prevStatus === 'In Use') {
                    header('Location: inventory.php?edit_blocked=in_use');
                    exit();
                }
                // Server-side guard: disallow setting status to 'In Use' via Inventory edit (use Borrow Requests)
                if (strcasecmp($status, 'In Use') === 0) {
                    header('Location: inventory.php?edit_blocked=set_in_use_forbidden');
                    exit();
                }
                // Server-side allowed status transitions
                $allowed = [];
                if ($prevStatus === 'Available' || $prevStatus === 'Returned' || $prevStatus === 'Reserved') {
                    $allowed = ['Lost','Under Maintenance','Out of Order'];
                } elseif ($prevStatus === 'Lost' || $prevStatus === 'Under Maintenance') {
                    $allowed = ['Available'];
                } elseif ($prevStatus === 'Out of Order') {
                    $allowed = ['Available','Lost','Under Maintenance'];
                }
                if (!empty($allowed)) {
                    $ok = in_array($status, $allowed, true) || strcasecmp($status, $prevStatus) === 0;
                    if (!$ok) {
                        header('Location: inventory.php?edit_blocked=invalid_transition');
                        exit();
                    }
                }
                $oldStatus = $prevStatus;
                $itemsCol->updateOne(['id'=>$id_edit], ['$set'=>$set]);
                // If status changed into a lifecycle state, log into lost_damaged_log
                $lifecycle = ['Lost','Under Maintenance','Found','Fixed','Permanently Lost','Disposed'];
                if ($oldStatus !== $status && in_array($status, $lifecycle, true)) {
                    try {
                        $ldCol = $db->selectCollection('lost_damaged_log');
                        $ubCol = $db->selectCollection('user_borrows');
                        $usersCol = $db->selectCollection('users');
                        $now = date('Y-m-d H:i:s');
                        // Determine affected user: last borrower overlapping now, else latest <= now
                        $affected = '';
                        $q1 = [
                            'model_id' => $id_edit,
                            'borrowed_at' => ['$lte' => $now],
                            '$or' => [ ['returned_at'=>null], ['returned_at'=>''], ['returned_at' => ['$gte' => $now]] ]
                        ];
                        $opt = ['sort' => ['borrowed_at' => -1, 'id' => -1], 'limit' => 1];
                        foreach ($ubCol->find($q1, $opt) as $br) { $u = (string)($br['username'] ?? ''); if ($u!=='') { $affected = $u; break; } }
                        if ($affected === '') {
                            $q2 = ['model_id' => $id_edit, 'borrowed_at' => ['$lte' => $now]];
                            foreach ($ubCol->find($q2, $opt) as $br) { $u = (string)($br['username'] ?? ''); if ($u!=='') { $affected = $u; break; } }
                        }
                        if ($affected === '' && $prev) { $affected = (string)($prev['last_borrower_username'] ?? ($prev['last_borrower'] ?? '')); }
                        $last = $ldCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
                        $nextId = ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
                        $ldCol->insertOne([
                            'id' => $nextId,
                            'model_id' => $id_edit,
                            'action' => $status,
                            'source' => 'manual_edit',
                            'marked_by' => (string)($_SESSION['username'] ?? ''),
                            'affected_username' => $affected,
                            'created_at' => $now,
                            'serial_no' => (string)$serial_no,
                            'model_key' => $model !== '' ? $model : $item_name,
                            'category' => $category !== '' ? $category : 'Uncategorized',
                            'location' => $location,
                            'notes' => $remarks,
                        ]);
                    } catch (Throwable $_eLog) { }
                }
            }
            header('Location: inventory.php?item_updated=1');
            exit();
        }

        // Admin bulk delete selected inventory items
        if (isset($_POST['bulk_delete']) && isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin') {
            // Verify admin password
            $inputPw = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
            if ($inputPw === '') { header('Location: inventory.php?error=pw_required'); exit(); }
            $users = $db->selectCollection('users');
            $u = $users->findOne(['username' => (string)$_SESSION['username']], ['projection'=>['password'=>1,'password_hash'=>1]]);
            $ok = false;
            $storedHash = $u && isset($u['password_hash']) ? (string)$u['password_hash'] : '';
            $storedPlain = $u && isset($u['password']) ? (string)$u['password'] : '';
            if ($storedHash !== '') { $ok = function_exists('password_verify') ? password_verify($inputPw, $storedHash) : ($inputPw === $storedHash); }
            if (!$ok && $storedPlain !== '') { $ok = (function_exists('password_verify') ? password_verify($inputPw, $storedPlain) : ($inputPw === $storedPlain)) || ($inputPw === $storedPlain); }
            if (!$ok) { header('Location: inventory.php?error=badpw'); exit(); }

            $ids = $_POST['selected_ids'] ?? [];
            $deletedCount = 0;
            if (is_array($ids) && !empty($ids)) {
                $reason = trim($_POST['delete_reason'] ?? '');
                if ($reason === '') { header('Location: inventory.php?error=reason_required'); exit(); }
                $by = $_SESSION['username'] ?? 'system';
                foreach ($ids as $rawId) {
                    $itemId = intval($rawId);
                    if ($itemId <= 0) { continue; }
                    $row = $itemsCol->findOne(['id'=>$itemId]);
                    if ($row) {
                        $stDel = (string)($row['status'] ?? '');
                        if (strcasecmp($stDel, 'In Use') === 0) { continue; }
                        $delLogCol->insertOne([
                            'id' => $nextId('inventory_delete_log'),
                            'item_id' => $itemId,
                            'deleted_by' => $by,
                            'deleted_at' => date('Y-m-d H:i:s'),
                            'reason' => $reason,
                            'item_name' => (string)($row['item_name'] ?? ''),
                            'model' => (string)($row['model'] ?? ''),
                            'category' => trim((string)($row['category'] ?? '')) !== '' ? (string)$row['category'] : 'Uncategorized',
                            'quantity' => (int)($row['quantity'] ?? 1),
                            'status' => (string)($row['status'] ?? ''),
                            'serial_no' => (string)($row['serial_no'] ?? ''),
                        ]);
                    }
                    $res = $itemsCol->deleteOne(['id'=>$itemId]);
                    if ($res->getDeletedCount() > 0) {
                        $deletedCount++;
                        // If this deletion causes the model's total quantity to drop to zero, clean up borrowables and pending requests
                        try {
                            $cat = trim((string)($row['category'] ?? '')) !== '' ? (string)$row['category'] : 'Uncategorized';
                            $modelKey = (string)($row['model'] ?? ($row['item_name'] ?? ''));
                            if ($modelKey !== '') {
                                // Compute remaining total quantity for this (category, model)
                                $agg = $itemsCol->aggregate([
                                  ['$match' => [ 'category' => $cat, '$or' => [ ['model'=>$modelKey], ['item_name'=>$modelKey] ] ]],
                                  ['$project' => ['q' => ['$ifNull' => ['$quantity', 1]]]],
                                  ['$group' => ['_id' => null, 'total' => ['$sum' => '$q']]],
                                ]);
                                $remTotal = 0; foreach ($agg as $r0) { $remTotal = (int)($r0->total ?? 0); break; }
                                if ($remTotal <= 0) {
                                    // Remove from borrowable_catalog so it disappears from Borrowable List
                                    $bcCol = $db->selectCollection('borrowable_catalog');
                                    $bcCol->deleteOne(['category'=>$cat,'model_name'=>$modelKey]);
                                    // Auto-reject any pending requests for this model
                                    $erCol = $db->selectCollection('equipment_requests');
                                    $erCol->updateMany(['item_name'=>$modelKey,'status'=>'Pending'], ['$set'=>['status'=>'Rejected','rejected_at'=>date('Y-m-d H:i:s')]]);
                                }
                            }
                        } catch (Throwable $e2) { /* ignore */ }
                    }
                }
            }
            header('Location: inventory.php?bulk_deleted=' . $deletedCount);
            exit();
        }

        // Admin single delete one inventory item (POST), mirrors bulk deletion
        if (isset($_POST['single_delete']) && isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin') {
            // Verify admin password
            $inputPw = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
            if ($inputPw === '') { header('Location: inventory.php?error=pw_required'); exit(); }
            $users = $db->selectCollection('users');
            $u = $users->findOne(['username' => (string)$_SESSION['username']], ['projection'=>['password'=>1,'password_hash'=>1]]);
            $ok = false;
            $storedHash = $u && isset($u['password_hash']) ? (string)$u['password_hash'] : '';
            $storedPlain = $u && isset($u['password']) ? (string)$u['password'] : '';
            if ($storedHash !== '') { $ok = function_exists('password_verify') ? password_verify($inputPw, $storedHash) : ($inputPw === $storedHash); }
            if (!$ok && $storedPlain !== '') { $ok = (function_exists('password_verify') ? password_verify($inputPw, $storedPlain) : ($inputPw === $storedPlain)) || ($inputPw === $storedPlain); }
            if (!$ok) { header('Location: inventory.php?error=badpw'); exit(); }

            $itemId = intval($_POST['item_id'] ?? 0);
            if ($itemId > 0) {
                $reason = trim($_POST['delete_reason'] ?? '');
                if ($reason === '') { header('Location: inventory.php?error=reason_required'); exit(); }
                $by = $_SESSION['username'] ?? 'system';
                $row = $itemsCol->findOne(['id'=>$itemId]);
                if ($row) {
                    $stDel = (string)($row['status'] ?? '');
                    if (strcasecmp($stDel, 'In Use') === 0) { header('Location: inventory.php?error=delete_in_use'); exit(); }
                    // Log snapshot before delete (same structure as bulk)
                    $delLogCol->insertOne([
                        'id' => $nextId('inventory_delete_log'),
                        'item_id' => $itemId,
                        'deleted_by' => $by,
                        'deleted_at' => date('Y-m-d H:i:s'),
                        'reason' => $reason,
                        'item_name' => (string)($row['item_name'] ?? ''),
                        'model' => (string)($row['model'] ?? ''),
                        'category' => trim((string)($row['category'] ?? '')) !== '' ? (string)$row['category'] : 'Uncategorized',
                        'quantity' => (int)($row['quantity'] ?? 1),
                        'status' => (string)($row['status'] ?? ''),
                        'serial_no' => (string)($row['serial_no'] ?? ''),
                    ]);
                }
                $res = $itemsCol->deleteOne(['id'=>$itemId]);
                if ($res && $res->getDeletedCount() > 0) {
                    // Same cleanup behavior as bulk when model total reaches zero
                    try {
                        $cat = trim((string)($row['category'] ?? '')) !== '' ? (string)$row['category'] : 'Uncategorized';
                        $modelKey = (string)($row['model'] ?? ($row['item_name'] ?? ''));
                        if ($modelKey !== '') {
                            $agg = $itemsCol->aggregate([
                                ['$match' => [ 'category' => $cat, '$or' => [ ['model'=>$modelKey], ['item_name'=>$modelKey] ] ]],
                                ['$project' => ['q' => ['$ifNull' => ['$quantity', 1]]]],
                                ['$group' => ['_id' => null, 'total' => ['$sum' => '$q']]],
                            ]);
                            $remTotal = 0; foreach ($agg as $r0) { $remTotal = (int)($r0->total ?? 0); break; }
                            if ($remTotal <= 0) {
                                $bcCol = $db->selectCollection('borrowable_catalog');
                                $bcCol->deleteOne(['category'=>$cat,'model_name'=>$modelKey]);
                                $erCol = $db->selectCollection('equipment_requests');
                                $erCol->updateMany(['item_name'=>$modelKey,'status'=>'Pending'], ['$set'=>['status'=>'Rejected','rejected_at'=>date('Y-m-d H:i:s')]]);
                            }
                        }
                    } catch (Throwable $_e3) { }
                }
            }
            header('Location: inventory.php?deleted_item=1');
            exit();
        }

        // Admin: add or remove borrowable model entries
        if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin') {
            if (isset($_POST['bm_add'])) {
                $bm_cat = trim($_POST['bm_category'] ?? '');
                $bm_model = trim($_POST['bm_model'] ?? '');
                if ($bm_cat !== '' && $bm_model !== '') {
                    $bmCol->updateOne(
                        ['model_name'=>$bm_model,'category'=>$bm_cat],
                        ['$set'=>['model_name'=>$bm_model,'category'=>$bm_cat,'active'=>1,'created_at'=>date('Y-m-d H:i:s')]],
                        ['upsert'=>true]
                    );
                }
                header('Location: inventory.php?bm=added');
                exit();
            }
            if (isset($_POST['bm_add_all'])) {
                $bm_cat = trim($_POST['bm_category'] ?? '');
                if ($bm_cat !== '') {
                    $cur = $itemsCol->distinct('model', ['category'=>$bm_cat, 'status'=>'Available']);
                    foreach ($cur as $mn) {
                        if (!$mn) { continue; }
                        $bmCol->updateOne(['model_name'=>$mn,'category'=>$bm_cat], ['$set'=>['model_name'=>$mn,'category'=>$bm_cat,'active'=>1,'created_at'=>date('Y-m-d H:i:s')]], ['upsert'=>true]);
                    }
                }
                header('Location: inventory.php?bm=added_all');
                exit();
            }
            if (isset($_POST['bm_remove'])) {
                $bm_cat = trim($_POST['bm_category'] ?? '');
                $bm_model = trim($_POST['bm_model'] ?? '');
                if ($bm_cat !== '' && $bm_model !== '') {
                    $bmCol->updateOne(['model_name'=>$bm_model,'category'=>$bm_cat], ['$set'=>['active'=>0]]);
                }
                header('Location: inventory.php?bm=removed');
                exit();
            }
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Database unavailable';
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/db/mongo.php';
    try {
        $db = get_mongo_db();
        $isAdmin = (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin');
        // Initialize variables expected by template
        $record = null;
        $list = [];
        $items = [];
        $categoryOptions = [];
        $deleteHistory = [];

        // Scans view
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $scansCol = $db->selectCollection('inventory_scans');
        if ($id > 0) {
            $rec = $scansCol->findOne(['id' => $id]);
            if (!$rec) { $rec = $scansCol->findOne(['model_id' => $id]); }
            $record = $rec ? array_map(function($v){ return is_object($v)? (string)$v : $v; }, (array)$rec) : null;
        } else {
            $cur = $scansCol->find([], ['sort' => ['scanned_at' => -1, 'id' => -1], 'limit' => 50]);
            foreach ($cur as $doc) { $list[] = (array)$doc; }
        }

        // Items listing with filters
        $itemsCol = $db->selectCollection('inventory_items');
        $match = [];
        if ($search_q !== '') {
            $match['$or'] = [
                ['item_name' => ['$regex' => $search_q, '$options' => 'i']],
                ['serial_no' => ['$regex' => $search_q, '$options' => 'i']],
                ['model' => ['$regex' => $search_q, '$options' => 'i']],
            ];
        }
        if ($filter_status !== '') { $match['status'] = $filter_status; }
        if ($filter_category !== '') { $match['category'] = $filter_category; }
        if ($filter_condition !== '') { $match['condition'] = $filter_condition; }
        if ($filter_supply !== '') {
            if ($filter_supply === 'low') { $match['quantity'] = ['$lt' => 10]; }
            elseif ($filter_supply === 'average') { $match['quantity'] = ['$gt' => 10, '$lt' => 50]; }
            elseif ($filter_supply === 'high') { $match['quantity'] = ['$gt' => 50]; }
        }
        if ($date_from !== '' && $date_to !== '') { $match['date_acquired'] = ['$gte' => $date_from, '$lte' => $date_to]; }
        elseif ($date_from !== '') { $match['date_acquired'] = ['$gte' => $date_from]; }
        elseif ($date_to !== '') { $match['date_acquired'] = ['$lte' => $date_to]; }

        // Always exclude Permanently Lost and Disposed from inventory listing/search
        $excluded = ['Permanently Lost','Disposed'];
        if (!isset($match['status'])) {
            $match['status'] = ['$nin' => $excluded];
        } else {
            // If a specific status filter is set, still do not allow excluded statuses to appear
            if (is_string($match['status'])) {
                if (in_array($match['status'], $excluded, true)) { $match['status'] = ['$nin' => $excluded]; }
            } elseif (is_array($match['status']) && isset($match['status']['$in']) && is_array($match['status']['$in'])) {
                $match['status']['$in'] = array_values(array_filter($match['status']['$in'], function($s) use ($excluded){ return !in_array((string)$s, $excluded, true); }));
                if (empty($match['status']['$in'])) { $match['status'] = ['$nin'=>$excluded]; }
            }
        }
        $cursor = $itemsCol->find($match, ['sort' => ['created_at' => -1, 'id' => -1]]);
        $reqCol = $db->selectCollection('equipment_requests');
        $nowStr = date('Y-m-d H:i:s');
        foreach ($cursor as $doc) {
            $status = (string)($doc['status'] ?? '');
            $mid = intval($doc['id'] ?? 0);
            // Derive Reserved status for display if not in use and has an approved reservation for this unit
            if ($mid > 0 && $status !== 'In Use' && $status !== 'Borrowed') {
                try {
                    $hasRes = $reqCol->findOne([
                        'type' => 'reservation',
                        'status' => 'Approved',
                        'reserved_model_id' => $mid,
                        '$or' => [ ['reserved_to' => ['$gte' => $nowStr]], ['reserved_from' => ['$gte' => $nowStr]] ]
                    ], ['projection'=>['_id'=>1]]);
                    if ($hasRes) { $status = 'Reserved'; }
                } catch (Throwable $e) { /* ignore overlay on error */ }
            }
            $items[] = [
                'id' => intval($doc['id'] ?? 0),
                'item_name' => (string)($doc['item_name'] ?? ''),
                'category' => (string)($doc['category'] ?? ''),
                'model' => (string)($doc['model'] ?? ''),
                'quantity' => intval($doc['quantity'] ?? 1),
                'location' => (string)($doc['location'] ?? ''),
                'condition' => (string)($doc['condition'] ?? ''),
                'status' => $status,
                'date_acquired' => (string)($doc['date_acquired'] ?? ''),
                'remarks' => (string)($doc['remarks'] ?? ''),
                'serial_no' => (string)($doc['serial_no'] ?? ''),
                'created_at' => (string)($doc['created_at'] ?? ''),
            ];
        }

        $sid_raw = trim($_GET['sid'] ?? '');
        $mid_raw = trim($_GET['mid'] ?? '');
        $serial_id_search_raw = ($sid_raw !== '') ? $sid_raw : $mid_raw;
        if ($serial_id_search_raw !== '') {
            $groups = preg_split('/\s*,\s*/', $serial_id_search_raw);
            $tokenGroups = [];
            foreach ($groups as $g) {
                $g = trim($g);
                if ($g === '') continue;
                $tokens = preg_split('/\s+/', $g);
                $needles = [];
                foreach ($tokens as $t) { $t = trim($t); if ($t !== '') { $needles[] = strtolower($t); } }
                if (!empty($needles)) { $tokenGroups[] = $needles; }
            }
            if (!empty($tokenGroups)) {
                if ($sid_raw !== '') {
                    // Strict serial-only filtering when sid is used (word-boundary, case-insensitive)
                    $items = array_values(array_filter($items, function($row) use ($tokenGroups) {
                        $serial = (string)($row['serial_no'] ?? '');
                        foreach ($tokenGroups as $grp) {
                            $all = true;
                            foreach ($grp as $n) {
                                if ($n === '') { continue; }
                                $pat = '/(?<![A-Za-z0-9])' . preg_quote($n, '/') . '(?![A-Za-z0-9])/i';
                                if (!preg_match($pat, $serial)) { $all = false; break; }
                            }
                            if ($all) { return true; }
                        }
                        return false;
                    }));
                } else {
                    // Model/name assisted filtering when only mid is used
                    $items = array_values(array_filter($items, function($row) use ($tokenGroups) {
                        $serial = strtolower((string)($row['serial_no'] ?? ''));
                        $name = strtolower((string)($row['item_name'] ?? ''));
                        $hay = $serial . ' ' . $name;
                        foreach ($tokenGroups as $grp) {
                            $all = true;
                            foreach ($grp as $n) { if ($n !== '' && strpos($hay, $n) === false) { $all = false; break; } }
                            if ($all) { return true; }
                        }
                        return false;
                    }));
                }
            }
        }

        $location_search_raw = trim($_GET['loc'] ?? '');
        if ($location_search_raw !== '') {
            $locGroups = [];
            $groups = preg_split('/\s*,\s*/', strtolower($location_search_raw));
            foreach ($groups as $g) {
                $g = trim($g); if ($g === '') continue;
                $tokens = preg_split('/\s+/', $g);
                $needles = [];
                foreach ($tokens as $t) { $t = trim($t); if ($t !== '') { $needles[] = $t; } }
                if (!empty($needles)) { $locGroups[] = $needles; }
            }
            if (!empty($locGroups)) {
                $items = array_values(array_filter($items, function($row) use ($locGroups) {
                    $loc = (string)($row['location'] ?? '');
                    foreach ($locGroups as $grp) {
                        $all = true;
                        foreach ($grp as $n) {
                            if ($n === '') { continue; }
                            $pat = '/(?<![A-Za-z0-9])' . preg_quote($n, '/') . '(?![A-Za-z0-9])/i';
                            if (!preg_match($pat, $loc)) { $all = false; break; }
                        }
                        if ($all) { return true; }
                    }
                    return false;
                }));
            }
        }

        // Categories for selects
        $catCol = $db->selectCollection('categories');
        $catCur = $catCol->find([], ['sort' => ['name' => 1], 'projection' => ['name' => 1]]);
        foreach ($catCur as $c) { if (!empty($c['name'])) { $categoryOptions[] = (string)$c['name']; } }
        if (empty($categoryOptions)) {
            $tmp = [];
            foreach ($items as $it) { $nm = trim($it['category'] ?? '') !== '' ? $it['category'] : 'Uncategorized'; $tmp[$nm] = true; }
            $categoryOptions = array_keys($tmp);
            natcasesort($categoryOptions); $categoryOptions = array_values($categoryOptions);
        }

        // Deletion history (enrich with serial_no when missing)
        $delCol = $db->selectCollection('inventory_delete_log');
        $itemsCol = $db->selectCollection('inventory_items');
        $delCur = $delCol->find([], ['sort' => ['deleted_at' => -1, 'id' => -1], 'limit' => 100]);
        foreach ($delCur as $d) {
            $row = (array)$d;
            if (empty($row['serial_no']) && !empty($row['item_id'])) {
                try {
                    $ii = $itemsCol->findOne(['id' => (int)$row['item_id']], ['projection' => ['serial_no' => 1]]);
                    if ($ii && isset($ii['serial_no'])) { $row['serial_no'] = (string)$ii['serial_no']; }
                } catch (Throwable $_e) {}
            }
            $deleteHistory[] = $row;
        }

        // Model ID category mapping for $modelIdSearch
        $modelIdSearchCategory = '';
        $modelIdSearchCatId = '';
        if ($modelIdSearch > 0) {
            $doc = $itemsCol->findOne(['id'=>$modelIdSearch], ['projection'=>['category'=>1]]);
            if ($doc) { $modelIdSearchCategory = trim((string)($doc['category'] ?? '')) ?: 'Uncategorized'; }
        }

        $MONGO_FILLED = true;
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Database unavailable';
    }
}

// GET view
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$record = null;
$list = [];
// Items listing (with optional search/filter)
if (!$MONGO_FILLED) { $items = []; }
$search_q = trim($_GET['q'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
// New filters for admin
$filter_category = trim($_GET['category'] ?? '');
$filter_condition = trim($_GET['condition'] ?? '');
$filter_supply = trim($_GET['supply'] ?? ''); // low, average, high
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
// Admin category ID/name and location searches (group-sensitive)
$cat_id_raw = trim($_GET['cat_id'] ?? '');
$location_search_raw = trim($_GET['loc'] ?? '');
    // Category: collect CAT IDs and name groups (commas=OR, spaces/slashes=AND)
    $catIdFilters = [];
    $catNameGroups = [];
    if ($cat_id_raw !== '') {
        $groups = preg_split('/\s*,\s*/', strtolower($cat_id_raw));
        foreach ($groups as $g) {
            $g = trim($g);
            if ($g === '') continue;
            $tokens = preg_split('/[\/\\\s]+/', $g);
            $groupNeedles = [];
            foreach ($tokens as $p) {
                $p = trim($p);
                if ($p === '') continue;
                if (preg_match('/^(?:cat-)?(\d{1,})$/', $p, $m)) {
                    $num = intval($m[1]);
                    if ($num > 0) { $catIdFilters[] = sprintf('CAT-%03d', $num); }
                    continue;
                }
                $groupNeedles[] = $p;
            }
            if (!empty($groupNeedles)) { $catNameGroups[] = $groupNeedles; }
        }
    }
    // Location: name groups (commas=OR, spaces=AND)
    $locGroups = [];
    if ($location_search_raw !== '') {
        $lgroups = preg_split('/\s*,\s*/', strtolower($location_search_raw));
        foreach ($lgroups as $g) {
            $g = trim($g);
            if ($g === '') continue;
            $tokens = preg_split('/\s+/', $g);
            $needles = [];
            foreach ($tokens as $t) { $t = trim($t); if ($t !== '') { $needles[] = $t; } }
            if (!empty($needles)) { $locGroups[] = $needles; }
        }
    }

// Admin model ID search (e.g., 37)
$model_id_search_raw = trim($_GET['mid'] ?? '');
// Extract numeric part so inputs like "ID-37" work
if ($model_id_search_raw !== '') {
    if (preg_match('/(\d{1,})/', $model_id_search_raw, $mm)) {
        $modelIdSearch = intval($mm[1]);
    } else {
        $modelIdSearch = intval($model_id_search_raw);
    }
} else {
    $modelIdSearch = 0;
}

if (!$MONGO_FILLED) {
    if ($id > 0) {
        $rs = $conn->prepare("SELECT id, model_id, item_name, status, form_type, room, generated_date, scanned_by, scanned_at FROM inventory_scans WHERE id = ?");
        $rs->bind_param("i", $id);
        $rs->execute();
        $record = $rs->get_result()->fetch_assoc();
        $rs->close();
    } else {
        $qres = $conn->query("SELECT id, model_id, item_name, status, form_type, room, generated_date, scanned_by, scanned_at FROM inventory_scans ORDER BY scanned_at DESC, id DESC LIMIT 50");
        if ($qres) {
            while ($row = $qres->fetch_assoc()) { $list[] = $row; }
            $qres->close();
        }
    }
}

// Load inventory items for display
// Determine role once for conditional behavior (e.g., admin searches by ID)
$isAdmin = (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin');

$sql = "SELECT id, item_name, category, model, quantity, location, `condition`, status, date_acquired, remarks, created_at FROM inventory_items WHERE 1";
$params = [];
$types = '';
if ($search_q !== '') {
    if ($isAdmin) {
        // Admin: search by exact ID
        $sql .= " AND id = ?";
        $params[] = intval($search_q);
        $types .= 'i';
    } else {
        // Users: search by item name
        $sql .= " AND item_name LIKE ?";
        $params[] = "%$search_q%";
        $types .= 's';
    }
}
if ($filter_status !== '') { $sql .= " AND status = ?"; $params[] = $filter_status; $types .= 's'; }
if ($filter_category !== '') { $sql .= " AND category = ?"; $params[] = $filter_category; $types .= 's'; }
if ($filter_condition !== '') { $sql .= " AND `condition` = ?"; $params[] = $filter_condition; $types .= 's'; }
// Supply buckets based on quantity
if ($filter_supply !== '') {
    if ($filter_supply === 'low') { $sql .= " AND quantity < 10"; }
    elseif ($filter_supply === 'average') { $sql .= " AND quantity > 10 AND quantity < 50"; }
    elseif ($filter_supply === 'high') { $sql .= " AND quantity > 50"; }
}
// Date acquired filter (separate form can supply these)
if ($date_from !== '' && $date_to !== '') { $sql .= " AND date_acquired BETWEEN ? AND ?"; $params[] = $date_from; $params[] = $date_to; $types .= 'ss'; }
elseif ($date_from !== '') { $sql .= " AND date_acquired >= ?"; $params[] = $date_from; $types .= 's'; }
elseif ($date_to !== '') { $sql .= " AND date_acquired <= ?"; $params[] = $date_to; $types .= 's'; }
$hasExtraFilters = (
    ($search_q !== '') ||
    ($filter_status !== '') ||
    ($filter_category !== '') ||
    ($filter_condition !== '') ||
    ($filter_supply !== '') ||
    ($date_from !== '' || $date_to !== '') ||
    ($cat_id_raw !== '') ||
    ($model_id_search_raw !== '') ||
    ($location_search_raw !== '')
);
$sql .= " ORDER BY created_at DESC, id DESC";
if (!$MONGO_FILLED && $types !== '') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $items[] = $row; }
    $stmt->close();
} else if (!$MONGO_FILLED) {
    $res = $conn->query($sql);
    if ($res) { while ($row = $res->fetch_assoc()) { $items[] = $row; } }
}

// Apply additional admin search filters: CAT-ID/Category, Model ID or Name, and Location
// Build category name => CAT-XXX mapping from current $items
$catNamesTmp = [];
foreach ($items as $giTmp) {
    $catTmp = trim($giTmp['category'] ?? '') !== '' ? $giTmp['category'] : 'Uncategorized';
    $catNamesTmp[$catTmp] = true;
}
$catNamesArr = array_keys($catNamesTmp);
natcasesort($catNamesArr);
$catNamesArr = array_values($catNamesArr);
$catIdByName = [];
for ($i = 0; $i < count($catNamesArr); $i++) {
    $catIdByName[$catNamesArr[$i]] = sprintf('CAT-%03d', $i + 1);
}

// Filter by CAT-IDs and/or category name groups
if (!empty($catIdFilters) || !empty($catNameGroups)) {
    $items = array_values(array_filter($items, function($row) use ($catIdByName, $catIdFilters, $catNameGroups) {
        $cat = trim($row['category'] ?? '') !== '' ? $row['category'] : 'Uncategorized';
        $cid = $catIdByName[$cat] ?? '';
        if (!empty($catIdFilters) && in_array($cid, $catIdFilters, true)) { return true; }
        if (!empty($catNameGroups)) {
            foreach ($catNameGroups as $grp) {
                $all = true;
                foreach ($grp as $needle) {
                    $needle = trim($needle);
                    if ($needle === '') { continue; }
                    $pat = '/(?<![A-Za-z0-9])' . preg_quote($needle, '/') . '(?![A-Za-z0-9])/i';
                    if (!preg_match($pat, (string)$cat)) { $all = false; break; }
                }
                if ($all) { return true; }
            }
        }
        return false;
    }));
}

// Filter by Model IDs and/or Name groups: commas separate groups (OR), spaces separate words (AND within a group)
if ($model_id_search_raw !== '') {
    $idSet = [];
    $nameGroups = [];
    $groups = preg_split('/\s*,\s*/', $model_id_search_raw);
    foreach ($groups as $g) {
        $g = trim($g);
        if ($g === '') continue;
        $tokens = preg_split('/\s+/', $g);
        $groupNeedles = [];
        foreach ($tokens as $t) {
            $t = trim($t);
            if ($t === '') continue;
            if (preg_match('/^\d+$/', $t)) { $idSet[intval($t)] = true; }
            else { $groupNeedles[] = strtolower($t); }
        }
        if (!empty($groupNeedles)) { $nameGroups[] = $groupNeedles; }
    }
    $items = array_values(array_filter($items, function($row) use ($idSet, $nameGroups) {
        // ID match
        if (!empty($idSet)) {
            $rid = intval($row['id']);
            if (isset($idSet[$rid])) { return true; }
        }
        // Name group match: any group matches if all its tokens are present
        if (!empty($nameGroups)) {
            $nm = strtolower((string)($row['item_name'] ?? ''));
            foreach ($nameGroups as $grp) {
                $all = true;
                foreach ($grp as $n) { if ($n !== '' && strpos($nm, $n) === false) { $all = false; break; } }
                if ($all) { return true; }
            }
        }
        return false;
    }));
}

// Filter by Location groups (commas=OR, spaces=AND)
if (!empty($locGroups)) {
    $items = array_values(array_filter($items, function($row) use ($locGroups) {
        $loc = (string)($row['location'] ?? '');
        foreach ($locGroups as $grp) {
            $all = true;
            foreach ($grp as $n) {
                if ($n === '') { continue; }
                $pat = '/(?<![A-Za-z0-9])' . preg_quote($n, '/') . '(?![A-Za-z0-9])/i';
                if (!preg_match($pat, $loc)) { $all = false; break; }
            }
            if ($all) { return true; }
        }
        return false;
    }));
}

// Load categories for selects (admin modals)
if (!$MONGO_FILLED) {
    $categoryOptions = [];
    $cres = $conn->query("SELECT id, name FROM categories ORDER BY name");
    if ($cres) {
        while ($r = $cres->fetch_assoc()) { $categoryOptions[] = $r['name']; }
        $cres->close();
    }
}

// Fetch recent deletion history (last 100)
if (!$MONGO_FILLED) {
    $deleteHistory = [];
    if ($r = $conn->query("SELECT id, item_id, deleted_by, deleted_at, reason, item_name, model, category, quantity, status, serial_no
                           FROM inventory_delete_log
                           ORDER BY deleted_at DESC, id DESC LIMIT 100")) {
        while ($row = $r->fetch_assoc()) { $deleteHistory[] = $row; }
        $r->close();
    }
    // Enrich with serial_no using inventory_items when not present
    foreach ($deleteHistory as &$dh) {
        if (empty($dh['serial_no']) && !empty($dh['item_id'])) {
            $iid = (int)$dh['item_id'];
            $q = $conn->prepare("SELECT serial_no FROM inventory_items WHERE id = ? LIMIT 1");
            if ($q) { $q->bind_param('i', $iid); $q->execute(); $res = $q->get_result(); $row = $res ? $res->fetch_assoc() : null; $q->close(); if ($row && !empty($row['serial_no'])) { $dh['serial_no'] = $row['serial_no']; } }
        }
    }
    unset($dh);
}

// Build a stable global Category => CAT-XXX map from categories table (sorted by name)
// This ensures CAT-IDs are consistent across filters/searches
$stableCatIdMap = [];
if (!empty($categoryOptions)) {
    $idx = 1;
    foreach ($categoryOptions as $nm) {
        $stableCatIdMap[$nm] = sprintf('CAT-%03d', $idx++);
    }
    // Include 'Uncategorized' as a trailing entry if needed
    if (!isset($stableCatIdMap['Uncategorized'])) {
        $stableCatIdMap['Uncategorized'] = sprintf('CAT-%03d', $idx++);
    }
}

// Prepare mapping Category => CAT-XXX based on current items list (stable, sorted)
$catIdByNameSearch = [];
if (!empty($items)) {
    $catNamesTmp = [];
    foreach ($items as $giTmp) {
        $catTmp = trim($giTmp['category'] ?? '') !== '' ? $giTmp['category'] : 'Uncategorized';
        $catNamesTmp[$catTmp] = true;
    }
    $catNamesArr = array_keys($catNamesTmp);
    natcasesort($catNamesArr);
    $catNamesArr = array_values($catNamesArr);
    for ($i = 0; $i < count($catNamesArr); $i++) {
        $catIdByNameSearch[$catNamesArr[$i]] = sprintf('CAT-%03d', $i + 1);
    }
}

// If a Model ID search is requested, resolve its category and CAT-XXX label
$modelIdSearchCategory = '';
$modelIdSearchCatId = '';
if (!$MONGO_FILLED && $modelIdSearch > 0) {
    $stmt = $conn->prepare("SELECT category FROM inventory_items WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $modelIdSearch);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $modelIdSearchCategory = trim($row['category'] ?? '');
                if ($modelIdSearchCategory === '') { $modelIdSearchCategory = 'Uncategorized'; }
                // Prefer global stable map when available for consistent CAT-ID
                if (!empty($stableCatIdMap) && isset($stableCatIdMap[$modelIdSearchCategory])) {
                    $modelIdSearchCatId = $stableCatIdMap[$modelIdSearchCategory];
                } elseif (isset($catIdByNameSearch[$modelIdSearchCategory])) {
                    $modelIdSearchCatId = $catIdByNameSearch[$modelIdSearchCategory];
                }
            }
        }
        $stmt->close();
    }
}

// Admin Model Table search (mtq): filter by CAT-ID, category, item/model, location, or condition
$mt_search = trim($_GET['mtq'] ?? '');
if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin' && $mt_search !== '') {
    // Use global stable map if available; otherwise build from current $items
    $stableMap = !empty($stableCatIdMap) ? $stableCatIdMap : ($catIdByNameSearch ?? []);
    if (empty($stableMap)) {
        $tmp = [];
        foreach ($items as $row) {
            $cname = trim($row['category'] ?? '') !== '' ? $row['category'] : 'Uncategorized';
            $tmp[$cname] = true;
        }
        $names = array_keys($tmp);
        natcasesort($names);
        $names = array_values($names);
        foreach ($names as $i => $nm) { $stableMap[$nm] = sprintf('CAT-%03d', $i + 1); }
    }

    // Normalize query: split by commas/slashes/spaces to support multiple tokens
    $tokens = preg_split('/[\/,\s]+/', $mt_search);
    $tokens = array_values(array_filter(array_map('trim', $tokens), function($t){ return $t !== ''; }));
    $tokensLower = array_map('strtolower', $tokens);

    // Pre-resolve any CAT IDs from tokens (like 1, 001, cat-1, CAT-001)
    $wantedCatIds = [];
    foreach ($tokensLower as $tk) {
        if (preg_match('/(\d{1,})/', $tk, $m)) {
            $num = intval($m[1]);
            if ($num > 0) { $wantedCatIds[] = sprintf('CAT-%03d', $num); }
        } elseif (preg_match('/^cat-\d{1,}$/', $tk)) {
            $wantedCatIds[] = strtoupper($tk);
        }
    }

    // Filtering function across category id/name, item name, location, condition
    $items = array_values(array_filter($items, function($row) use ($tokensLower, $wantedCatIds, $stableMap) {
        $cat = trim($row['category'] ?? '') !== '' ? $row['category'] : 'Uncategorized';
        $cid = $stableMap[$cat] ?? '';
        $name = strtolower((string)($row['item_name'] ?? ''));
        $loc = strtolower((string)($row['location'] ?? ''));
        $cond = strtolower((string)($row['condition'] ?? ''));
        $catLower = strtolower($cat);

        // If any CAT-ID token matches exactly
        if (!empty($wantedCatIds) && in_array($cid, $wantedCatIds, true)) { return true; }

        // Otherwise, every token must match at least one field (AND across tokens, OR across fields)
        foreach ($tokensLower as $tk) {
            $ok = false;
            if ($tk === '') { continue; }
            if ($cid !== '' && strpos(strtolower($cid), $tk) !== false) { $ok = true; }
            elseif (strpos($catLower, $tk) !== false) { $ok = true; }
            elseif (strpos($name, $tk) !== false) { $ok = true; }
            elseif (strpos($loc, $tk) !== false) { $ok = true; }
            elseif (strpos($cond, $tk) !== false) { $ok = true; }
            if (!$ok) { return false; }
        }
        return true;
    }));
}

?>
<!DOCTYPE html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Inventory</title>
	<link href="css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
	<link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__.'/css/style.css'); ?>">
	<link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" />
	<style>
	/* Extra small button for Select All in selection mode */
	.btn-xxs { padding: 0.1rem 0.3rem; font-size: 0.7rem; line-height: 1; }

  /* Serial ID indicators in Edit modal */
  .sid-input-wrap { position: relative; }
  .sid-indicator { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none; }

	/* Darker, more visible selection checkboxes in Model Table */
	#bulkDeleteForm .form-check-input {
		border-color: #343a40; /* dark gray border */
		width: 1.1rem;
		height: 1.1rem;
		accent-color: #0d6efd; /* bootstrap primary blue */
	}
	#bulkDeleteForm .form-check-input:checked {
		background-color: #0d6efd; /* primary blue */
		border-color: #0a58ca; /* darker blue border */
	}
	#bulkDeleteForm .form-check-input:focus {
		box-shadow: 0 0 0 .2rem rgba(13,110,253,.25);
		border-color: #0d6efd;
	}
	#bulkDeleteForm .form-check-input:focus {
		box-shadow: 0 0 0 .2rem rgba(13,110,253,.25);
		border-color: #0d6efd;
	}

	/* Reserve select column width to avoid table shifting when toggling selection mode */
	#bulkDeleteForm .select-col { width: 32px; }
	#bulkDeleteForm .select-col .row-select { visibility: hidden; }
	#bulkDeleteForm.selection-on .select-col .row-select { visibility: visible; }

	/* Hide scrollbars but keep scroll functionality */
	.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
	.no-scrollbar::-webkit-scrollbar { display: none; }

	/* Stabilize column layout */
	#bulkDeleteForm table { table-layout: fixed; }

	/* Make Model Table headers fit on one line (no ellipsis) and adaptive */
    #bulkDeleteForm table thead th,
    #bulkDeleteForm table tbody td {
        white-space: nowrap; /* keep single line */
        vertical-align: middle;
    }
    /* Responsive font sizes with clamp for better fit */
    #bulkDeleteForm table thead th { font-size: clamp(10px, 0.85vw, 0.95rem); }
    #bulkDeleteForm table tbody td { font-size: clamp(10px, 0.9vw, 0.95rem); }
    /* Tighter padding to gain width */
    #bulkDeleteForm table th, #bulkDeleteForm table td { padding: 0.45rem 0.45rem; }
    @media (max-width: 1200px) {
        #bulkDeleteForm table th, #bulkDeleteForm table td { padding: 0.4rem 0.4rem; }
    }
    @media (max-width: 992px) {
        #bulkDeleteForm table th, #bulkDeleteForm table td { padding: 0.35rem 0.35rem; }
    }

	/* Keep sidebar fixed and non-scrollable; let page content scroll */
	html, body { height: 100%; }
	body { overflow: hidden; }
	#sidebar-wrapper {
		position: sticky;
		top: 0;
		height: 100vh;
		overflow: hidden; /* navigation not scrollable */
	}
  	#page-content-wrapper {
  		flex: 1 1 auto;
  		height: 100vh;
  		overflow: auto; /* tables/content scroll here */
  	}
    /* Desktop: show sidebar and hide hamburger */
    @media (min-width: 769px){
      #sidebar-wrapper{ display:block !important; }
      .mobile-menu-toggle{ display:none !important; }
    }
  	/* On small screens, keep existing toggle behavior and move sidebar off-canvas */
  	@media (max-width: 768px) {
  		body { overflow: auto; }
  		/* Make content use full width */
  		#page-content-wrapper { height: auto; overflow: visible; width: 100%; margin-left: 0; }
  		/* Off-canvas sidebar to avoid squeezing content */
  		#sidebar-wrapper { position: fixed; left: -250px; top: 0; height: 100vh; width: 250px; z-index: 1000; transition: left 0.3s ease; }
  		#sidebar-wrapper.active { left: 0; }
  		/* Ensure the toggle is accessible */
  		.mobile-menu-toggle { display: block; position: fixed; top: 1rem; left: 1rem; z-index: 1001; }
  		/* Model Table: avoid stacked text by using horizontal scroll */
  		#bulkDeleteForm .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  		#bulkDeleteForm table { table-layout: auto; min-width: 720px; }
  		#bulkDeleteForm table thead th,
  		#bulkDeleteForm table tbody td { white-space: nowrap; word-break: normal; }
  		#bulkDeleteForm table th, #bulkDeleteForm table td { padding: 0.4rem 0.4rem; }
  	}

    @media (max-width: 576px) {
        /* Extra small screens: tighten spacing a bit more */
        #bulkDeleteForm table { min-width: 680px; }
        #bulkDeleteForm table th, #bulkDeleteForm table td { padding: 0.35rem 0.35rem; font-size: 0.9rem; }
    }
	</style>
</head>

<body class="allow-mobile">
	<!-- Mobile Menu Toggle Button -->
	<button class="mobile-menu-toggle d-md-none" onclick="toggleSidebar()">
		<i class="bi bi-list"></i>
	</button>
    <div class="d-flex">
        <div class="bg-light border-end no-print" id="sidebar-wrapper">
           <div class="sidebar-heading py-4 fs-4 fw-bold border-bottom d-flex align-items-center justify-content-center">
	               <img src="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" alt="ECA Logo" class="brand-logo me-2" />
	               <span>ECA MIS-GMIS</span>
           </div>
            <div class="list-group list-group-flush my-3">
                <?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
                    <a href="admin_dashboard.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-speedometer2 me-2"></i>Dashboard
                    </a>
                    <a href="inventory.php" class="list-group-item list-group-item-action bg-transparent fw-bold">
                        <i class="bi bi-box-seam me-2"></i>Inventory
                    </a>
                    <a href="inventory_print.php<?php echo (!empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''); ?>" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-printer me-2"></i>Print Inventory
                    </a>
                    
                    <a href="generate_qr.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-qr-code me-2"></i>Add Item/Generate QR
                    </a>
                    <a href="qr_scanner.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-camera me-2"></i>QR Scanner
                    </a>
                    <a href="admin_borrow_center.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-clipboard-check me-2"></i>Borrow Requests
                    </a>
                    <a href="user_management.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-people me-2"></i>User Management
                    </a>
                <?php elseif (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'user'): ?>
                    <a href="user_dashboard.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-speedometer2 me-2"></i>Dashboard
                    </a>
                    <a href="user_request.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-clipboard-plus me-2"></i>Request to Borrow
                    </a>
                    <a href="user_items.php" class="list-group-item list-group-item-action bg-transparent fw-bold">
                        <i class="bi bi-collection me-2"></i>My Items
                    </a>
                    <a href="qr_scanner.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-camera me-2"></i>QR Scanner
                    </a>
                <?php endif; ?>
                <a href="change_password.php" class="list-group-item list-group-item-action bg-transparent">
                    <i class="bi bi-key me-2"></i>Change Password
                </a>
                <a href="logout.php" class="list-group-item list-group-item-action bg-transparent" onclick="return confirm('Are you sure you want to logout?');">
                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                </a>
            </div>
		</div>

		<div class="p-4" id="page-content-wrapper">
			<div class="page-header d-flex justify-content-between align-items-center">
				<h2 class="page-title mb-0">
					<i class="bi bi-box-seam me-2"></i>Inventory Management
				</h2>
				<div class="d-flex align-items-center gap-3">
					<?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
						<!-- Add New Item button removed: handled in Generate QR section -->
						<!-- Admin Search button to open modal with multi-field search -->
						<button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#searchModal">
							<i class="bi bi-search me-1"></i>Search
						</button>
					<?php else: ?>
						<a href="qr_scanner.php" class="btn btn-success">
							<i class="bi bi-camera me-2"></i>Scan QR
						</a>
					<?php endif; ?>
					<form method="GET" class="d-flex align-items-center gap-2 ms-auto">
						<?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
							<!-- Unified Filter dropdown for Admin (search bar removed) -->
							<div class="dropdown">
								<button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
									<i class="bi bi-funnel me-1"></i>Filter
								</button>
								<div class="dropdown-menu p-3 filter-menu" style="min-width: 280px; max-width: 92vw;">
									<!-- Keep original selects unchanged, just moved inside dropdown -->
									<div class="mb-2">
										<label class="form-label mb-1">Status</label>
										<select name="status" class="form-select">
											<option value="">Status</option>
											<?php
												$statuses = ['Available','In Use','Reserved','Lost','Maintenance','Out of Order'];
												foreach ($statuses as $st) {
													$sel = ($filter_status ?? '') === $st ? 'selected' : '';
													echo '<option value="'.htmlspecialchars($st).'" '.$sel.'>'.htmlspecialchars($st).'</option>';
												}
											?>
										</select>
									</div>
									<div class="mb-2">
										<label class="form-label mb-1">Category</label>
										<select name="category" class="form-select">
											<option value="">Category</option>
											<?php foreach ($categoryOptions as $cat): ?>
												<option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($filter_category ?? '') === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="mb-2">
										<label class="form-label mb-1">Condition</label>
										<select name="condition" class="form-select">
											<option value="">Condition</option>
											<?php foreach (["Good","Damaged","Need replacement"] as $cond): ?>
												<option value="<?php echo htmlspecialchars($cond); ?>" <?php echo ($filter_condition ?? '') === $cond ? 'selected' : ''; ?>><?php echo htmlspecialchars($cond); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="mb-2">
										<label class="form-label mb-1">Supply</label>
										<select name="supply" class="form-select">
											<option value="">Supply</option>
											<option value="low" <?php echo ($filter_supply ?? '') === 'low' ? 'selected' : ''; ?>>Low (&lt; 10)</option>
											<option value="average" <?php echo ($filter_supply ?? '') === 'average' ? 'selected' : ''; ?>>Average (&gt; 10 and &lt; 50)</option>
											<option value="high" <?php echo ($filter_supply ?? '') === 'high' ? 'selected' : ''; ?>>High (&gt; 50)</option>
										</select>
									</div>
									<!-- Date Range (merged from separate form) -->
									<hr class="my-2" />
									<div class="mb-2">
										<label class="form-label mb-1">From</label>
										<input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from ?? ''); ?>" />
									</div>
									<div class="mb-2">
										<label class="form-label mb-1">To</label>
										<input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to ?? ''); ?>" />
									</div>
									<div class="d-flex gap-2">
										<button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2 me-1"></i>Apply</button>
										<a href="inventory.php" class="btn btn-outline-secondary w-100">Reset</a>
									</div>
								</div>
								</div>
								<!-- Preserve multi-field search values when applying main filters -->
								<input type="hidden" name="mid" value="<?php echo htmlspecialchars($model_id_search_raw ?? ''); ?>" />
								<input type="hidden" name="cat_id" value="<?php echo htmlspecialchars($cat_id_raw ?? ''); ?>" />
								<input type="hidden" name="loc" value="<?php echo htmlspecialchars($location_search_raw ?? ''); ?>" />
							<!-- date_from/date_to are inside the dropdown now -->
						<?php else: ?>
							<input type="text" name="q" class="form-control" placeholder="Search Item..." value="<?php echo htmlspecialchars($search_q ?? ''); ?>" />
							<button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
						<?php endif; ?>
					</form>

					<?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
						<div class="position-relative" id="adminBellWrap">
							<button class="btn btn-light position-relative" type="button" id="adminBellBtn" title="Notifications">
								<i class="bi bi-bell" style="font-size:1.2rem;"></i>
								<span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle d-none" id="adminBellDot"></span>
							</button>
							<div class="dropdown-menu dropdown-menu-end shadow" id="adminBellDropdown" style="min-width: 320px; max-height: 360px; overflow:auto;">
								<div class="px-3 py-2 border-bottom fw-bold small">Pending Borrow Requests</div>
								<div id="adminNotifList" class="list-group list-group-flush small"></div>
								<div class="text-center small text-muted py-2 d-none" id="adminNotifEmpty"></div>
								<div class="border-top p-2 text-center">
									<a href="admin_borrow_center.php" class="btn btn-sm btn-outline-primary">Go to Borrow Requests</a>
								</div>
							</div>
						</div>
					<?php endif; ?>

					<?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
					<?php endif; ?>
				</div>
			</div>



			<?php if ($id > 0 && $record): ?>
                
				<div class="card">
                    
                        
						<div class="row g-3">
							<div class="col-md-6">
                                
								<div><strong>Item Name:</strong> <?php echo htmlspecialchars($record['item_name']); ?></div>
								<div><strong>Status:</strong> <?php echo htmlspecialchars($record['status']); ?></div>
								<div><strong>Form Type:</strong> <?php echo htmlspecialchars($record['form_type']); ?></div>
							</div>
							<div class="col-md-6">
								<div><strong>Room:</strong> <?php echo htmlspecialchars($record['room']); ?></div>
								<div><strong>Generated Date:</strong> <?php echo htmlspecialchars($record['generated_date'] ? date('Y-m-d h:i A', strtotime($record['generated_date'])) : ''); ?></div>
								<div><strong>Scanned At:</strong> <?php echo htmlspecialchars($record['scanned_at'] ? date('Y-m-d h:i A', strtotime($record['scanned_at'])) : ''); ?></div>
							</div>
                            
                            
						</div>
                        <?php if ($id > 0): ?>
	<div class="mt-3">
		<a href="inventory.php" class="btn btn-outline-secondary">
			<i class="bi bi-arrow-left me-2"></i>Back to Inventory
		</a>
	</div>
<?php endif; ?>
						<div class="mt-3"><small class="text-muted">Scanned by: <?php echo htmlspecialchars($record['scanned_by']); ?></small></div>
					</div>
                    
                    
				</div>
			<?php else: ?>
				<?php if (!isset($_SESSION['usertype']) || $_SESSION['usertype'] !== 'admin'): ?>
				<div class="card mb-4">
					<div class="card-header d-flex justify-content-between align-items-center">
						<h5 class="mb-0"><i class="bi bi-boxes me-2"></i>Inventory Items</h5>
					</div>
					<div class="card-body p-0">
						<?php if (empty($items)): ?>
							<div class="p-4 text-muted">No items found.</div>
						<?php else: ?>
							<?php
							// Group items by category and compute totals
							$grouped = [];
							$totals = [];
							foreach ($items as $gi) {
								$cat = trim($gi['category'] ?? '') !== '' ? $gi['category'] : 'Uncategorized';
								$grouped[$cat][] = $gi;
								$totals[$cat] = ($totals[$cat] ?? 0) + (int)($gi['quantity'] ?? 0);
							}
							// Build a stable Category ID mapping (CAT-001, CAT-002, ...) sorted by category name
							$catNames = array_keys($grouped);
							natcasesort($catNames);
							$catNames = array_values($catNames);
							$catIdByName = [];
							for ($i = 0; $i < count($catNames); $i++) {
								$catIdByName[$catNames[$i]] = sprintf('CAT-%03d', $i + 1);
							}
							// If admin provided cat_id filter(s), keep only matching categories
							if (!empty($catIdFilters)) {
								$catNames = array_values(array_filter($catNames, function($name) use ($catIdByName, $catIdFilters) {
									return in_array($catIdByName[$name], $catIdFilters, true);
								}));
							}
							?>
							<div class="table-responsive">
								<table class="table table-striped table-hover mb-0">
									<thead class="table-light">
										<tr>
											<th>Category ID</th>
											<th>Category</th>
											<th>All Qty</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($catNames as $idx => $cat): $catId = $catIdByName[$cat]; $collapseId = 'cat_collapse_'.($idx + 1); $rows = $grouped[$cat]; ?>
										<tr class="table-primary" role="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="false" style="cursor:pointer;">
											<td><?php echo htmlspecialchars($catId); ?></td>
											<td><?php echo htmlspecialchars($cat); ?></td>
											<td><?php echo htmlspecialchars($totals[$cat] ?? 0); ?></td>
										</tr>
										<tr class="collapse" id="<?php echo $collapseId; ?>">
											<td colspan="3">
												<div class="p-2 bg-light border">
													<div class="table-responsive" style="overflow: visible;">
														<table class="table table-sm table-bordered mb-0">
															<thead>
																<tr>
																	<th>Serial ID</th>
																	<th>Location</th>
																	<th>Remarks</th>
																	<th>Status</th>
																	<th>Date Acquired</th>
																	<?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
																		<th style="width: 220px;">Actions</th>
																	<?php endif; ?>
																</tr>
															</thead>
															<tbody>
																<?php foreach ($rows as $it): ?>
																<tr>
																	<td><?php echo htmlspecialchars((string)($it['serial_no'] ?? '')); ?></td>
																	<td><?php echo htmlspecialchars($it['location']); ?></td>
																	<td><?php echo htmlspecialchars($it['remarks']); ?></td>
																	<td><?php echo htmlspecialchars($it['status']); ?></td>
																	<td><?php echo htmlspecialchars($it['date_acquired']); ?></td>
																	<?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
																	<td>
																		<?php if (!preg_match('/^in\s*use$/i', (string)($it['status'] ?? ''))): ?>
																		<button type="button" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#editItemModal"
																			data-id="<?php echo htmlspecialchars($it['id']); ?>"
																			data-item_name="<?php echo htmlspecialchars($it['item_name']); ?>"
																			data-model="<?php echo htmlspecialchars($it['model'] ?? ''); ?>"
																			data-category="<?php echo htmlspecialchars($it['category']); ?>"
																			data-quantity="<?php echo htmlspecialchars($it['quantity']); ?>"
																			data-location="<?php echo htmlspecialchars($it['location']); ?>"
																			data-serial_no="<?php echo htmlspecialchars((string)($it['serial_no'] ?? '')); ?>"
																			data-remarks="<?php echo htmlspecialchars($it['remarks']); ?>"
																			data-status="<?php echo htmlspecialchars($it['status']); ?>"
																			data-date_acquired="<?php echo htmlspecialchars($it['date_acquired']); ?>">Edit</button>
																		<?php endif; ?>
																		<a class="btn btn-sm btn-outline-danger" href="#" onclick="return (function(){
                                                                            // Open Admin Password modal directly and mark pending single-delete id
                                                                            window.__pendingDeleteSingle = '<?php echo htmlspecialchars($it['id']); ?>';
                                                                            try {
                                                                                var apm = document.getElementById('adminPwModal');
                                                                                if (apm && window.bootstrap && bootstrap.Modal) {
                                                                                    var m = bootstrap.Modal.getOrCreateInstance(apm);
                                                                                    m.show();
                                                                                    return false;
                                                                                }
                                                                            } catch(_) {}
                                                                            if (window.openAdminPwPrompt) { openAdminPwPrompt(function(pw, reason){}); }
                                                                            return false;
                                                                        })();">Delete</a>
																	</td>
																	<?php endif; ?>
																</tr>
																<?php endforeach; ?>
															</tbody>
														</table>
													</div>
												</div>
											</td>
										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<!-- Bulk Delete Confirmation Modal -->
				<div class="modal fade" id="confirmBulkDeleteModal" tabindex="-1" aria-labelledby="confirmBulkDeleteLabel" aria-hidden="true">
				  <div class="modal-dialog">
				    <div class="modal-content">
				      <div class="modal-header">
				        <h5 class="modal-title" id="confirmBulkDeleteLabel">Confirm Delete</h5>
				        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				      </div>
				      <div class="modal-body">
				        <p>You're about to delete <strong><span id="selectedCountSpan">0</span></strong> selected item(s). This action cannot be undone.</p>
				      </div>
				      <div class="modal-footer">
				        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
				        <button type="button" class="btn btn-danger" id="confirmBulkDeleteBtn"><i class="bi bi-trash me-1"></i>Delete</button>
				      </div>
				    </div>
				  </div>
				</div>

				<!-- Single Item Delete Confirmation Modal -->
				<div class="modal fade" id="confirmSingleDeleteModal" tabindex="-1" aria-labelledby="confirmSingleDeleteLabel" aria-hidden="true">
				  <div class="modal-dialog">
				    <div class="modal-content">
				      <div class="modal-header">
				        <h5 class="modal-title" id="confirmSingleDeleteLabel">Confirm Delete</h5>
				        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				      </div>
				      <div class="modal-body">
				        <p>You're about to delete <strong id="singleDeleteItemName">this item</strong>. This action cannot be undone.</p>
				      </div>
				      <div class="modal-footer">
				        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
				        <button type="button" class="btn btn-danger" id="confirmSingleDeleteBtn"><i class="bi bi-trash me-1"></i>Delete</button>
				      </div>
				    </div>
				  </div>
				</div>

					<?php endif; ?>

				<?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
				<?php if (isset($_GET['bulk_deleted'])): $bd = intval($_GET['bulk_deleted']); ?>
				<div class="alert alert-<?php echo $bd > 0 ? 'success' : 'warning'; ?> alert-dismissible fade show mt-3" role="alert">
					<?php if ($bd > 0): ?>
						<i class="bi bi-check-circle me-2"></i><?php echo $bd; ?> item(s) deleted successfully.
					<?php else: ?>
						<i class="bi bi-exclamation-triangle me-2"></i>No items were deleted. Please select at least one item.
					<?php endif; ?>
					<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
				</div>
				<?php endif; ?>
				<?php if (isset($_GET['deleted_item'])): ?>
				<div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
					<i class="bi bi-check-circle me-2"></i>Item deleted successfully.
					<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
				</div>
				<?php endif; ?>
				<?php if (isset($_GET['error'])): $err = (string)$_GET['error']; ?>
					<?php if ($err === 'badpw'): ?>
						<div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
							<i class="bi bi-shield-x me-2"></i>Incorrect admin password. Please try again.
							<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
						</div>
					<?php elseif ($err === 'pw_required'): ?>
						<div class="alert alert-warning alert-dismissible fade show mt-3" role="alert">
							<i class="bi bi-exclamation-triangle me-2"></i>Admin password is required to delete.
							<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
						</div>
					<?php elseif ($err === 'reason_required'): ?>
						<div class="alert alert-warning alert-dismissible fade show mt-3" role="alert">
							<i class="bi bi-exclamation-triangle me-2"></i>Reason is required to delete.
							<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
						</div>
					<?php endif; ?>
				<?php endif; ?>
				<!-- Model Table: full details per item/model -->
				<div class="card mt-4">
					<div class="card-header d-flex justify-content-between align-items-center">
						<h5 class="mb-0"><i class="bi bi-list-check me-2"></i>Model Table</h5>
						<!-- Model Table search bar removed -->
					</div>
					<div class="card-body">
						<form method="POST" id="bulkDeleteForm" class="d-flex flex-column gap-2">
							<input type="hidden" name="bulk_delete" value="1" />
							<input type="hidden" name="delete_reason" id="deleteReasonField" value="" />
							<div class="d-flex justify-content-end gap-2">
								<button id="toggleSelectBtn" class="btn btn-sm btn-outline-primary" type="button" onclick="toggleSelectionMode()">
									<i class="bi bi-check2-square me-1"></i>Select
								</button>
								<button id="selectAllTopBtn" class="btn btn-sm btn-outline-primary d-none" type="button">Select All</button>
								<button id="openBulkDeleteModalBtn" class="btn btn-sm btn-danger d-none" type="button" disabled data-bs-toggle="modal" data-bs-target="#confirmBulkDeleteModal">
									<i class="bi bi-trash me-1"></i>Delete
								</button>
								<button id="openDeleteHistoryBtn" class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#deleteHistoryModal">
									<i class="bi bi-clock-history me-1"></i>History
								</button>
							</div>
							<div class="table-responsive">
								<table class="table table-striped table-hover mb-0">
									<colgroup>
										<col style="width:60px;" />
									</colgroup>
									<thead class="table-light">
										<tr>
											<th style="width: 60px;"></th>
											<th>Item Name</th>
											<th>Category ID</th>
											<th>Category</th>
											<th>Quantity</th>
											<th>Location</th>
											<th>Remarks</th>
											<th>Status</th>
											<th>Date Acquired</th>
											<th style="width: 110px;">Actions</th>
										</tr>
									</thead>
									<tbody>
									<?php
									// Build local grouping by Category, then by Item/Model within Category
									$mt_grouped = [];
									$mt_catNames = [];
									if (!empty($items)) {
										foreach ($items as $gi) {
											$catName = trim($gi['category'] ?? '') !== '' ? $gi['category'] : 'Uncategorized';
											$modelName = trim($gi['item_name'] ?? '');
											if ($modelName === '') { $modelName = '(Unnamed Model)'; }
											$mt_grouped[$catName][$modelName][] = $gi;
										}
										$mt_catNames = array_keys($mt_grouped);
										natcasesort($mt_catNames);
										$mt_catNames = array_values($mt_catNames);
										$mt_catIdByName = [];
										for ($i = 0; $i < count($mt_catNames); $i++) {
											$mt_catIdByName[$mt_catNames[$i]] = sprintf('CAT-%03d', $i + 1);
										}
									}
									?>
									<?php if (!empty($mt_catNames)): ?>
										<?php foreach ($mt_catNames as $cat): $catId = (!empty($stableCatIdMap) && isset($stableCatIdMap[$cat])) ? $stableCatIdMap[$cat] : ($mt_catIdByName[$cat] ?? ''); $models = $mt_grouped[$cat] ?? []; ?>
											<?php
											// Sort models naturally by name within category
											$modelNames = array_keys($models);
											natcasesort($modelNames);
											$modelNames = array_values($modelNames);
											?>
											<?php foreach ($modelNames as $modelName): $rows = $models[$modelName]; ?>
												<?php
												// Aggregate quantity and unify fields; if mixed values, show 'Multiple'
												$sumQty = 0;
												$locVal = null; $remarksVal = null; $statVal = null; $dateVal = null;
												$locSame = true; $remarksSame = true; $statSame = true; $dateSame = true;
												$rowCount = is_array($rows) ? count($rows) : 0;
												foreach ($rows as $itx) {
													$sumQty += intval($itx['quantity'] ?? 0);
													if ($locVal === null) { $locVal = (string)($itx['location'] ?? ''); } elseif ($locVal !== (string)($itx['location'] ?? '')) { $locSame = false; }
													if ($remarksVal === null) { $remarksVal = (string)($itx['remarks'] ?? ''); } elseif ($remarksVal !== (string)($itx['remarks'] ?? '')) { $remarksSame = false; }
													if ($statVal === null) { $statVal = (string)($itx['status'] ?? ''); } elseif ($statVal !== (string)($itx['status'] ?? '')) { $statSame = false; }
													if ($dateVal === null) { $dateVal = (string)($itx['date_acquired'] ?? ''); } elseif ($dateVal !== (string)($itx['date_acquired'] ?? '')) { $dateSame = false; }
												}
												$locShow = $locSame ? $locVal : 'Multiple';
												$remarksShow = $remarksSame ? $remarksVal : 'Multiple';
												$statShow = $statSame ? $statVal : 'Multiple';
												$dateShow = $dateSame ? $dateVal : 'Multiple';
												$collapseId = 'mtgrp_' . md5($cat . '|' . $modelName);
												$childIds = array_map(function($x){ return (int)($x['id'] ?? 0); }, $rows);
												$childIds = array_values(array_filter($childIds, function($v){ return $v > 0; }));
												$dataIds = implode(',', $childIds);
												?>
												            <tr class="table-primary">
              <td style="width:60px;">
                <button class="btn btn-xxs btn-outline-secondary me-1" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="false" aria-controls="<?php echo $collapseId; ?>">
                  <i class="bi bi-caret-down"></i>
                </button>
                <input type="checkbox" class="form-check-input group-select d-none" data-ids="<?php echo htmlspecialchars($dataIds); ?>" title="Select all items in this model">
              </td>
              <?php $modelDisp = trim((string)($rows[0]['model'] ?? '')); if ($modelDisp === '') { $modelDisp = (string)$modelName; } ?>
              <td><?php echo htmlspecialchars($modelDisp); ?></td>
              <td><?php echo htmlspecialchars($catId); ?></td>
              <td><?php echo htmlspecialchars($cat); ?></td>
              <td><?php echo htmlspecialchars($sumQty); ?></td>
              <td><?php echo htmlspecialchars($locShow); ?></td>
              <td><?php echo htmlspecialchars($remarksShow); ?></td>
              <td><?php echo htmlspecialchars($statShow); ?></td>
              <td><?php echo htmlspecialchars($dateShow); ?></td>
              <td class="text-end">
               	<!-- Keep actions minimal on group row: none to avoid ambiguity -->
              </td>
            </tr>
            <tr class="collapse" id="<?php echo $collapseId; ?>">
              <td colspan="10" class="p-0">
                <div class="table-responsive<?php echo ($rowCount > 10 ? ' no-scrollbar' : ''); ?>" style="<?php echo ($rowCount > 10 ? 'max-height: 400px; overflow-y: auto;' : ''); ?>">
                  <table class="table table-sm mb-0">
																<thead>
																<tr class="table-light">
																	<th class="select-col" style="width:32px;"></th>
																	<th>Serial ID</th>
																	<th>Item Name</th>
																	<th>Category ID</th>
																	<th>Category</th>
																	<th>Quantity</th>
																	<th>Location</th>
																	<th>Remarks</th>
																	<th>Status</th>
																	<th>Date Acquired</th>
																	<th style="width:60px;">Actions</th>
																</tr>
															</thead>
																<tbody>
																	<?php foreach ($rows as $it): ?>
																	<tr>
																		<td class="select-col">
																			<?php $st = (string)($it['status'] ?? ''); if (strcasecmp($st,'In Use') !== 0): ?>
																				<input class="form-check-input row-select" type="checkbox" name="selected_ids[]" value="<?php echo htmlspecialchars($it['id']); ?>">
																			<?php else: ?>
																				<!-- In Use: no checkbox -->
																			<?php endif; ?>
																		</td>
																		<td><?php echo htmlspecialchars((string)($it['serial_no'] ?? '')); ?></td>
															<?php $itModel = trim((string)($it['model'] ?? '')); if ($itModel === '') { $itModel = (string)($it['item_name'] ?? ''); } ?>
															<td><?php echo htmlspecialchars($itModel); ?></td>
															<td><?php echo htmlspecialchars($catId); ?></td>
															<td><?php echo htmlspecialchars($cat); ?></td>
															<td><?php echo htmlspecialchars($it['quantity']); ?></td>
															<td><?php echo htmlspecialchars($it['location']); ?></td>
															<td><?php echo htmlspecialchars($it['remarks']); ?></td>
															<td><?php echo htmlspecialchars($it['status']); ?></td>
															<td><?php echo htmlspecialchars($it['date_acquired']); ?></td>
																		<td class="text-end">
																			<button type="button" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#actionsModal"
																				data-id="<?php echo htmlspecialchars($it['id']); ?>"
																				data-item_name="<?php echo htmlspecialchars($it['item_name']); ?>"
																				data-model="<?php echo htmlspecialchars($it['model'] ?? ''); ?>"
																				data-category="<?php echo htmlspecialchars($it['category']); ?>"
																				data-quantity="<?php echo htmlspecialchars($it['quantity']); ?>"
																				data-location="<?php echo htmlspecialchars($it['location']); ?>"
																				data-condition="<?php echo htmlspecialchars($it['condition']); ?>"
																				data-status="<?php echo htmlspecialchars($it['status']); ?>"
																				data-date_acquired="<?php echo htmlspecialchars($it['date_acquired']); ?>"
																				data-remarks="<?php echo htmlspecialchars($it['remarks']); ?>"
																				data-serial_no="<?php echo htmlspecialchars((string)($it['serial_no'] ?? '')); ?>">Actions</button>
																		</td>
																	</tr>
																	<?php endforeach; ?>
																</tbody>
															</table>
														</div>
													</td>
												</tr>
											<?php endforeach; ?>
										<?php endforeach; ?>
									<?php else: ?>
										<tr>
											<td colspan="11" class="text-center text-muted">No items found.</td>
										</tr>
									<?php endif; ?>
								</tbody>
								</table>
							</div>
						</form>
					</div>
				</div>

				<!-- Deletion History Modal -->
				<div class="modal fade" id="deleteHistoryModal" tabindex="-1" aria-labelledby="deleteHistoryLabel" aria-hidden="true">
                  <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title" id="deleteHistoryLabel">Deletion History</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <div class="table-responsive">
                          <table class="table table-striped table-hover mb-0">
                            <thead class="table-light">
                              <tr>
                                <th>Status</th>
                                <th>Serial ID</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Date Deleted</th>
                                <th>Deleted By</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php if (empty($deleteHistory)): ?>
                                <tr><td colspan="6" class="text-center text-muted">No deletions logged yet.</td></tr>
                              <?php else: foreach ($deleteHistory as $dh): ?>
                                <tr>
                                  <td><?php echo htmlspecialchars($dh['status'] ?? ''); ?></td>
                                  <td><?php echo htmlspecialchars($dh['serial_no'] ?? ''); ?></td>
                                  <td><?php echo htmlspecialchars($dh['item_name'] ?? ($dh['model'] ?? '')); ?></td>
                                  <td><?php echo htmlspecialchars($dh['category'] ?? ''); ?></td>
                                  <td><?php echo htmlspecialchars(isset($dh['deleted_at']) && $dh['deleted_at'] ? date('Y-m-d h:i A', strtotime($dh['deleted_at'])) : ''); ?></td>
                                  <td><?php echo htmlspecialchars($dh['deleted_by'] ?? ''); ?></td>
                                </tr>
                              <?php endforeach; endif; ?>
                            </tbody>
                          </table>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                      </div>
                    </div>
                  </div>
                </div>

				<script>
				function updateBulkBtnState() {
                    var checks = document.querySelectorAll('#bulkDeleteForm .row-select:checked');
                    var btn = document.getElementById('openBulkDeleteModalBtn');
                    if (btn) btn.disabled = checks.length === 0;
                    var span = document.getElementById('selectedCountSpan');
                    if (span) span.textContent = checks.length;
                }
      function updateSelectAllButton() {
                    var all = document.querySelectorAll('#bulkDeleteForm .row-select');
                    var checked = document.querySelectorAll('#bulkDeleteForm .row-select:checked');
                    var btn = document.getElementById('selectAllTopBtn');
                    if (!btn) return;
                    if (all.length === 0) { btn.textContent = 'Select All'; btn.disabled = true; return; }
                    btn.disabled = false;
                    btn.textContent = (checked.length === all.length && all.length > 0) ? 'Unselect All' : 'Select All';
                }
				var selectionMode = false;
                function setSelectionMode(on) {
                    var form = document.getElementById('bulkDeleteForm');
                    if (form) { form.classList.toggle('selection-on', on); }
                    if (!on) {
                        var cbs = document.querySelectorAll('#bulkDeleteForm .row-select');
                        cbs.forEach(function(cb){ cb.checked = false; });
                    }
                    // Show/hide group-level checkboxes
                    document.querySelectorAll('#bulkDeleteForm .group-select').forEach(function(el){
                        if (on) { el.classList.remove('d-none'); } else { el.classList.add('d-none'); el.indeterminate = false; el.checked = false; }
                    });
                    updateBulkBtnState();
                    updateSelectAllButton();
                    // Show/hide the top Select All button in selection mode
                    var sab = document.getElementById('selectAllTopBtn');
                    if (sab) { sab.classList.toggle('d-none', !on); }
                    // Show/hide the Delete button in selection mode
                    var del = document.getElementById('openBulkDeleteModalBtn');
                    if (del) { del.classList.toggle('d-none', !on); del.disabled = true; }
                    var tbtn = document.getElementById('toggleSelectBtn');
                    if (tbtn) {
                        tbtn.innerHTML = on ? '<i class="bi bi-x-square me-1"></i>Cancel' : '<i class="bi bi-check2-square me-1"></i>Select';
                    }
                }
				function toggleSelectionMode(){ selectionMode = !selectionMode; setSelectionMode(selectionMode); }
                document.addEventListener('DOMContentLoaded', function(){
                    var form = document.getElementById('bulkDeleteForm');
                    if (form) {
                        form.addEventListener('change', function(e){
                            if (e.target && e.target.classList.contains('row-select')) {
                                updateBulkBtnState();
                                updateSelectAllButton();
                                updateGroupCheckboxState();
                            }
                        });
                    }
                    // Update group checkbox (checked/indeterminate) from children
                    function updateOneGroupState(g){
                        var ids = (g.getAttribute('data-ids') || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
                        if (ids.length === 0) { g.indeterminate = false; g.checked = false; return; }
                        var total = 0, checked = 0;
                        ids.forEach(function(id){
                            var cb = document.querySelector('#bulkDeleteForm .row-select[value="' + CSS.escape(id) + '"]');
                            if (cb) { total++; if (cb.checked) { checked++; } }
                        });
                        if (total === 0) { g.indeterminate = false; g.checked = false; return; }
                        if (checked === 0) { g.indeterminate = false; g.checked = false; }
                        else if (checked === total) { g.indeterminate = false; g.checked = true; }
                        else { g.indeterminate = true; g.checked = false; }
                    }
                    function updateGroupCheckboxState(){
                        document.querySelectorAll('#bulkDeleteForm .group-select').forEach(function(g){ updateOneGroupState(g); });
                    }
                    // Group checkbox change -> apply to children
                    document.addEventListener('change', function(e){
                        if (e.target && e.target.classList.contains('group-select')) {
                            var g = e.target;
                            var ids = (g.getAttribute('data-ids') || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
                            var want = g.checked; // when user clicks, treat as all on/off
                            ids.forEach(function(id){
                                var cb = document.querySelector('#bulkDeleteForm .row-select[value="' + CSS.escape(id) + '"]');
                                if (cb) { cb.checked = want; }
                            });
                            updateBulkBtnState();
                            updateSelectAllButton();
                            updateGroupCheckboxState();
                        }
                    });
                    var selectAllBtn = document.getElementById('selectAllTopBtn');
                    if (selectAllBtn) {
                        selectAllBtn.addEventListener('click', function(){
                            var all = document.querySelectorAll('#bulkDeleteForm .row-select');
                            var checked = document.querySelectorAll('#bulkDeleteForm .row-select:checked');
                            var checkAll = !(checked.length === all.length && all.length > 0);
                            all.forEach(function(cb){ cb.checked = checkAll; });
                            updateBulkBtnState();
                            updateSelectAllButton();
                            updateGroupCheckboxState();
                        });
                    }
                    // ensure selection mode starts hidden
                    setSelectionMode(false);
					// Wire Delete confirmation: push reason and submit
					var confirmBtn = document.getElementById('confirmBulkDeleteBtn');
					if (confirmBtn) {
						confirmBtn.addEventListener('click', function(){
							var hiddenReason = document.getElementById('deleteReasonField');
							var form = document.getElementById('bulkDeleteForm');
							if (form) { 
								if (window.openAdminPwPrompt) {
									openAdminPwPrompt(function(pw, reason){
										if (!pw) return;
										if (hiddenReason) { hiddenReason.value = reason || ''; }
										var hiddenPw = document.createElement('input');
										hiddenPw.type = 'hidden';
										hiddenPw.name = 'admin_password';
										hiddenPw.value = pw;
										form.appendChild(hiddenPw);
										form.submit();
									});
								}
							}
							// Fallback without Bootstrap: collect reason via admin modal callback only (no native confirm/prompt)
							if (window.openAdminPwPrompt) { openAdminPwPrompt(function(pw, reason){}); }
							return false;
						});
					}
					// Ensure Delete button opens modal even if data attributes fail
					var openDelBtn = document.getElementById('openBulkDeleteModalBtn');
					if (openDelBtn) {
						openDelBtn.addEventListener('click', function(e){
							var selected = document.querySelectorAll('#bulkDeleteForm .row-select:checked').length;
							if (selected === 0) { return; }
							var mEl = document.getElementById('confirmBulkDeleteModal');
							if (mEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
								var m = bootstrap.Modal.getOrCreateInstance(mEl);
								m.show();
							} else {
								// Fallback without Bootstrap: collect reason via admin modal callback only (no native confirm/prompt)
								var hiddenReason = document.getElementById('deleteReasonField');
								var form = document.getElementById('bulkDeleteForm');
								if (form && window.openAdminPwPrompt) {
									openAdminPwPrompt(function(pw, reason){
										if (!pw) return;
										if (hiddenReason) { hiddenReason.value = reason || ''; }
										var hiddenPw = document.createElement('input');
										hiddenPw.type = 'hidden';
										hiddenPw.name = 'admin_password';
										hiddenPw.value = pw;
										form.appendChild(hiddenPw);
										form.submit();
									});
								}
							}
						});
					}
					// Keep selected count accurate when modal opens
					var delModal = document.getElementById('confirmBulkDeleteModal');
					if (delModal) {
						delModal.addEventListener('shown.bs.modal', function(){ updateBulkBtnState(); });
					}
				});
				</script>

	<?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
	<!-- Search Modal for Admin -->
	<div class="modal fade" id="searchModal" tabindex="-1" aria-labelledby="searchModalLabel" aria-hidden="true">
	  <div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
		  <div class="modal-header">
			<h5 class="modal-title" id="searchModalLabel"><i class="bi bi-search me-2"></i>Search Inventory</h5>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
		  </div>
		  <form method="GET">
			<div class="modal-body">
			  <div class="mb-3">
				<label class="form-label">Serial ID or Name</label>
				<input type="text" name="sid" class="form-control" value="<?php echo htmlspecialchars($_GET['sid'] ?? ($model_id_search_raw ?? '')); ?>" />
			  </div>
			  <div class="mb-3">
				<label class="form-label">CAT-ID or Category Name</label>
				<input type="text" name="cat_id" class="form-control" value="<?php echo htmlspecialchars($cat_id_raw ?? ''); ?>" />
			  </div>
			  <div class="mb-3">
				<label class="form-label">Location</label>
				<input type="text" name="loc" class="form-control" value="<?php echo htmlspecialchars($location_search_raw ?? ''); ?>" />
			  </div>
			  <input type="hidden" name="q" value="<?php echo htmlspecialchars($search_q ?? ''); ?>" />
			  <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status ?? ''); ?>" />
			  <input type="hidden" name="category" value="<?php echo htmlspecialchars($filter_category ?? ''); ?>" />
			  <input type="hidden" name="condition" value="<?php echo htmlspecialchars($filter_condition ?? ''); ?>" />
			  <input type="hidden" name="supply" value="<?php echo htmlspecialchars($filter_supply ?? ''); ?>" />
			  <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from ?? ''); ?>" />
			  <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to ?? ''); ?>" />
			</div>
			<div class="modal-footer">
			  <a href="inventory.php" class="btn btn-outline-secondary">Reset</a>
			  <button type="submit" class="btn btn-primary">Apply</button>
			</div>
		  </form>
		</div>
	  </div>
	</div>
	<?php endif; ?>
				<?php endif; ?>

				<?php if (!isset($_SESSION['usertype']) || $_SESSION['usertype'] !== 'admin'): ?>
				<?php if (empty($list)): ?>
					<div class="alert alert-info">No scans yet. Scan a QR to record and view details here.</div>
				<?php else: ?>
					<div class="card">
						<div class="card-header">
							<h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Recent Scans</h5>
                            
						</div>
						<div class="card-body p-0">
							<div class="table-responsive table-scroll-5">
								<table class="table table-striped table-hover mb-0">
									<thead class="table-light">
										<tr>
											<th>Serial ID</th>
											<th>Model Type</th>
											<th>Status</th>
											<th>Form Type</th>
											<th>Room</th>
											<th>Generated Date</th>
											<th>Scanned By</th>
											<th>Scanned At</th>
											<th></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($list as $row): ?>
											<tr>
												<td><?php echo htmlspecialchars((isset($row['model_id']) && (int)$row['model_id'] > 0) ? $row['model_id'] : ''); ?></td>
												<td><?php echo htmlspecialchars($row['item_name']); ?></td>
												<td><?php echo htmlspecialchars($row['status']); ?></td>
												<td><?php echo htmlspecialchars($row['form_type']); ?></td>
												<td><?php echo htmlspecialchars($row['room']); ?></td>
												<td><?php echo htmlspecialchars($row['generated_date'] ? date('Y-m-d h:i A', strtotime($row['generated_date'])) : ''); ?></td>
												<td><?php echo htmlspecialchars($row['scanned_by']); ?></td>
												<td><?php echo htmlspecialchars($row['scanned_at'] ? date('Y-m-d h:i A', strtotime($row['scanned_at'])) : ''); ?></td>
												<td>
									<?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
										<button type="button" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#scanViewModal"
											data-id="<?php echo htmlspecialchars($row['id']); ?>"
											data-model_id="<?php echo htmlspecialchars($row['model_id']); ?>"
											data-item_name="<?php echo htmlspecialchars($row['item_name']); ?>"
											data-status="<?php echo htmlspecialchars($row['status']); ?>"
											data-form_type="<?php echo htmlspecialchars($row['form_type']); ?>"
											data-room="<?php echo htmlspecialchars($row['room']); ?>"
											data-generated_date="<?php echo htmlspecialchars($row['generated_date']); ?>"
											data-scanned_by="<?php echo htmlspecialchars($row['scanned_by']); ?>"
											data-scanned_at="<?php echo htmlspecialchars($row['scanned_at']); ?>
										">View</button>
										<a class="btn btn-sm btn-outline-danger" href="inventory.php?action=delete&id=<?php echo urlencode($row['id']); ?>" onclick="return (function(el){ if(!confirm('Delete this record?')) return false; if (window.openAdminPwPrompt) { openAdminPwPrompt(function(pw){ if(!pw) return; if (window.__postWithAdminPassword) { __postWithAdminPassword(el.href, pw); } else { window.location.href = el.href + '&admin_password=' + encodeURIComponent(pw); } }); } return false; })(this);">Delete</a>
									<?php else: ?>
										<a class="btn btn-sm btn-outline-primary" href="inventory.php?id=<?php echo urlencode($row['id']); ?>">View</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				
				</div>
			</div>
		</div>

				<?php endif; ?>
		<?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin' && $modelIdSearch > 0): ?>
			<?php if ($modelIdSearchCatId !== ''): ?>
				<div class="alert alert-info d-flex align-items-center" role="alert">
					<i class="bi bi-info-circle me-2"></i>
					<span>Model ID <strong><?php echo htmlspecialchars($modelIdSearch); ?></strong> is in <strong><?php echo htmlspecialchars($modelIdSearchCatId); ?></strong><?php echo $modelIdSearchCategory ? ' (Category: '.htmlspecialchars($modelIdSearchCategory).')' : ''; ?>.</span>
				</div>
			<?php else: ?>
				<div class="alert alert-warning d-flex align-items-center" role="alert">
					<i class="bi bi-exclamation-triangle me-2"></i>
					<span>No category mapping found for Model ID <strong><?php echo htmlspecialchars($modelIdSearch); ?></strong>.</span>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
	<?php endif; ?>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
	<script>
	function toggleSidebar() {
		const sidebar = document.getElementById('sidebar-wrapper');
		sidebar.classList.toggle('active');
		if (window.innerWidth <= 768) {
			document.body.classList.toggle('sidebar-open', sidebar.classList.contains('active'));
		}
	}
	document.addEventListener('click', function(event) {
		const sidebar = document.getElementById('sidebar-wrapper');
		const toggleBtn = document.querySelector('.mobile-menu-toggle');
		if (window.innerWidth <= 768) {
			if (sidebar && toggleBtn && !sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
				sidebar.classList.remove('active');
				document.body.classList.remove('sidebar-open');
			}
		}
	});
	</script>

	<script>
	// Admin bell: same behavior as admin_dashboard
	(function(){
		const bellWrap = document.getElementById('adminBellWrap');
		const bellBtn = document.getElementById('adminBellBtn');
		const bellDot = document.getElementById('adminBellDot');
		const dropdown = document.getElementById('adminBellDropdown');
		const listEl = document.getElementById('adminNotifList');
		const emptyEl = document.getElementById('adminNotifEmpty');
		if (bellWrap) { bellWrap.classList.remove('d-none'); }

		if (bellBtn && dropdown) {
			bellBtn.addEventListener('click', function(e){
				e.stopPropagation();
				dropdown.classList.toggle('show');
				// Mobile: center the dropdown
				if (window.innerWidth <= 768) {
					dropdown.style.position = 'fixed';
					dropdown.style.top = '12%';
					dropdown.style.left = '50%';
					dropdown.style.transform = 'translateX(-50%)';
					dropdown.style.right = 'auto';
					dropdown.style.maxWidth = '92vw';
				} else {
					// Desktop: align to the bell button (right edge)
					dropdown.style.position = 'absolute';
					dropdown.style.transform = 'none';
					dropdown.style.top = (bellBtn.offsetTop + bellBtn.offsetHeight + 6) + 'px';
					dropdown.style.left = (bellBtn.offsetLeft - (dropdown.offsetWidth - bellBtn.offsetWidth)) + 'px';
				}
			});
			document.addEventListener('click', function(){ dropdown.classList.remove('show'); });
		}

		let toastWrap = document.getElementById('adminToastWrap');
		if (!toastWrap) {
			toastWrap = document.createElement('div');
			toastWrap.id = 'adminToastWrap';
			toastWrap.style.position = 'fixed';
			toastWrap.style.right = '16px';
			toastWrap.style.bottom = '16px';
			toastWrap.style.zIndex = '1080';
			document.body.appendChild(toastWrap);
		}
		function showToast(msg){
			const el = document.createElement('div');
			el.className = 'alert alert-info shadow-sm border-0';
			el.style.minWidth = '280px';
			el.style.maxWidth = '360px';
			el.innerHTML = '<i class="bi bi-bell me-2"></i>' + String(msg||'');
			toastWrap.appendChild(el);
			setTimeout(()=>{ try { el.remove(); } catch(_){} }, 5000);
		}

		let audioCtx = null;
		function playBeep(){
			try {
				if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
				const o = audioCtx.createOscillator();
				const g = audioCtx.createGain();
				o.type = 'sine'; o.frequency.value = 880;
				g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
				g.gain.exponentialRampToValueAtTime(0.2, audioCtx.currentTime + 0.02);
				g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.22);
				o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime + 0.25);
			} catch(_){}
		}

		let baselineIds = new Set();
		let initialized = false;
		let fetching = false;
		function renderCombined(pending, recent){
			const rows = [];
			(pending||[]).forEach(function(r){
				const id = parseInt(r.id||0,10);
				const user = String(r.username||'');
				const nm = String(r.item_name||'');
				const qty = parseInt(r.quantity||1,10);
				const when = String(r.created_at||'');
				rows.push('<a href="admin_borrow_center.php" class="list-group-item list-group-item-action">'
					+ '<div class="d-flex w-100 justify-content-between">'
					+   '<strong>#'+id+'</strong>'
					+   '<small class="text-muted">'+escapeHtml(when)+'</small>'
					+ '</div>'
					+ '<div class="mb-0">'+escapeHtml(user)+' requests '+escapeHtml(nm)+' <span class="badge bg-secondary">x'+qty+'</span></div>'
					+ '</a>');
			});
			if ((recent||[]).length){
				rows.push('<div class="list-group-item"><div class="d-flex justify-content-between align-items-center"><span class="small text-muted">Processed</span><button type="button" class="btn btn-sm btn-outline-secondary" id="admClearAllBtn">Clear All</button></div></div>');
				(recent||[]).forEach(function(r){
					const id = parseInt(r.id||0,10);
					const nm = String(r.item_name||'');
					const st = String(r.status||'');
					const when = String(r.processed_at||'');
					const bcls = (st==='Approved') ? 'badge bg-success' : 'badge bg-danger';
					rows.push('<div class="list-group-item d-flex justify-content-between align-items-start">'
					  + '<div class="me-2">'
					  +   '<div class="d-flex w-100 justify-content-between"><strong>#'+id+' '+escapeHtml(nm)+'</strong><small class="text-muted">'+escapeHtml(when)+'</small></div>'
					  +   '<div class="small">Status: <span class="'+bcls+'">'+escapeHtml(st)+'</span></div>'
					  + '</div>'
					  + '<div><button type="button" class="btn btn-sm btn-outline-secondary adm-clear-one" data-id="'+id+'">Clear</button></div>'
					  + '</div>');
				});
			}
			if (listEl) listEl.innerHTML = rows.join('');
			if (emptyEl) emptyEl.style.display = rows.length ? 'none' : '';
		}
		document.addEventListener('click', function(ev){
			const one = ev.target && ev.target.closest && ev.target.closest('.adm-clear-one');
			if (one){ const rid = parseInt(one.getAttribute('data-id')||'0',10)||0; if (!rid) return; const fd = new FormData(); fd.append('request_id', String(rid)); fetch('admin_borrow_center.php?action=admin_notif_clear', { method:'POST', body: fd }).then(r=>r.json()).then(()=>{ poll(); }).catch(()=>{}); return; }
			if (ev.target && ev.target.id === 'admClearAllBtn'){ const fd=new FormData(); fd.append('limit','300'); fetch('admin_borrow_center.php?action=admin_notif_clear_all', { method:'POST', body: fd }).then(r=>r.json()).then(()=>{ poll(); }).catch(()=>{}); }
		});
		function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }

		function poll(){
			if (fetching) return; fetching = true;
			fetch('admin_borrow_center.php?action=admin_notifications')
				.then(r=>r.json())
				.then(d=>{
					const pending = (d && Array.isArray(d.pending)) ? d.pending : [];
					const recent = (d && Array.isArray(d.recent)) ? d.recent : [];
					if (bellDot) bellDot.classList.toggle('d-none', pending.length===0);
					try {
						const navLink = document.querySelector('a[href="admin_borrow_center.php"]');
						if (navLink) {
							let dot = navLink.querySelector('.nav-borrow-dot');
							const shouldShow = pending.length > 0;
							if (shouldShow) {
								if (!dot) {
									dot = document.createElement('span');
									dot.className = 'nav-borrow-dot ms-2 d-inline-block rounded-circle';
									dot.style.width = '8px';
									dot.style.height = '8px';
									dot.style.backgroundColor = '#dc3545';
									dot.style.verticalAlign = 'middle';
									dot.style.display = 'inline-block';
									navLink.appendChild(dot);
								} else {
									dot.style.display = 'inline-block';
								}
							} else if (dot) {
								dot.style.display = 'none';
							}
						}
					} catch(_){}
					renderCombined(pending, recent);
					const currIds = new Set(pending.map(it=>parseInt(it.id||0,10)));
					if (!initialized) {
						baselineIds = currIds;
						initialized = true;
					} else {
						let hasNew = false;
						currIds.forEach(id=>{ if (!baselineIds.has(id)) { hasNew = true; } });
						if (hasNew) {
							pending.forEach(it=>{ const id=parseInt(it.id||0,10); if(!baselineIds.has(id)){ showToast('New request: '+(it.username||'')+' → '+(it.item_name||'')+' (x'+(it.quantity||1)+')'); } });
							playBeep();
						}
						baselineIds = currIds;
					}
				})
				.catch(()=>{})
				.finally(()=>{ fetching = false; });
		}
		poll();
		setInterval(()=>{ if (document.visibilityState === 'visible') poll(); }, 1000);
	})();
	</script>

    <?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
    <!-- Add New Item Modal -->
    <div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content">
          <input type="hidden" name="create_item" value="1" />
          <input type="hidden" name="item_name" id="add_item_name_hidden" />
          <div class="modal-header">
            <h5 class="modal-title" id="addItemModalLabel"><i class="bi bi-plus-lg me-2"></i>Add New Item</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-bold">Category *</label>
                <select name="category" id="add_category" class="form-select" required>
                  <option value="">Select Category</option>
                  <?php foreach ($categoryOptions as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold">Serial Number *</label>
                <input type="text" name="model" id="add_model_input" class="form-control" placeholder="Enter serial number" required />
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold">Quantity</label>
                <input type="number" name="quantity" class="form-control" value="1" min="0" />
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold">Location</label>
                <input type="text" name="location" class="form-control" placeholder="Room 101" />
              </div>
              
              <div class="col-md-3">
                <label class="form-label fw-bold">Status</label>
                <select name="status" class="form-select">
                  <option value="Available">Available</option>
                  <option value="Under Maintenance">Under Maintenance</option>
                  <option value="Out of Order">Out of Order</option>
                  <option value="Lost">Lost</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-bold">Date Acquired</label>
                <input type="date" name="date_acquired" class="form-control" />
              </div>
              <div class="col-12">
                <label class="form-label fw-bold">Remarks</label>
                <textarea name="remarks" class="form-control" rows="3" placeholder="Notes, supplier, warranty, etc."></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-2"></i>Save Item</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit Item Modal -->
    <div class="modal fade" id="editItemModal" tabindex="-1" aria-labelledby="editItemModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content">
          <input type="hidden" name="update_item" value="1" />
          <input type="hidden" name="id" id="edit_id" />
          <input type="hidden" name="item_name" id="edit_item_name_hidden" />
          <div class="modal-header">
            <h5 class="modal-title" id="editItemModalLabel"><i class="bi bi-pencil-square me-2"></i>Edit Item</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <!-- Category hidden (not editable in modal) -->
              <input type="hidden" name="category" id="edit_category_hidden" />
              <div class="col-md-6">
                <label class="form-label fw-bold">Item Name</label>
                <input type="text" name="model" id="edit_model_input" class="form-control" placeholder="Enter model" required />
              </div>
              <!-- Quantity hidden (not editable in modal) -->
              <input type="hidden" name="quantity" id="edit_quantity_hidden" />
              <div class="col-md-3">
                <label class="form-label fw-bold">Location</label>
                <input type="text" name="location" id="edit_location" class="form-control" />
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold">Serial ID</label>
                <div class="sid-input-wrap">
                  <input type="text" name="serial_no" id="edit_serial_no" class="form-control" />
                  <i class="sid-indicator bi" id="edit_sid_icon" style="display:none;"></i>
                </div>
                <div class="small" id="edit_sid_msg"></div>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold">Status</label>
                <select name="status" id="edit_status" class="form-select">
                  <option value="Available">Available</option>
                  <option value="Under Maintenance">Under Maintenance</option>
                  <option value="Out of Order">Out of Order</option>
                  <option value="Lost">Lost</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-bold">Date Acquired</label>
                <input type="date" name="date_acquired" id="edit_date_acquired" class="form-control" />
              </div>
              <div class="col-12">
                <label class="form-label fw-bold">Remarks</label>
                <textarea name="remarks" id="edit_remarks" class="form-control" rows="3"></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-2"></i>Save Changes</button>
          </div>
        </form>
      </div>
    </div>

    <script>
    // Simple sync: keep hidden item_name in sync with manual model input
    function syncHiddenNameFromInput(inputEl, hiddenEl) {
      if (!inputEl || !hiddenEl) return;
      hiddenEl.value = (inputEl.value || '').trim();
    }

    // Populate Edit Item modal when opened
    document.addEventListener('DOMContentLoaded', function() {
      var editModal = document.getElementById('editItemModal');
      if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
          var button = event.relatedTarget;
          if (!button) return;
          document.getElementById('edit_id').value = button.getAttribute('data-id') || '';
          var catVal = button.getAttribute('data-category') || '';
          var catHidden = document.getElementById('edit_category_hidden');
          if (catHidden) catHidden.value = catVal;
          var modelInput = document.getElementById('edit_model_input');
          var hiddenEl = document.getElementById('edit_item_name_hidden');
          function dataAttr(btn, key){
            return btn.getAttribute('data-' + key) || btn.getAttribute('data-' + key.replace(/_/g,'-')) || btn.getAttribute('data-' + key.replace(/-/g,'_')) || '';
          }
          var modelVal = button.getAttribute('data-model') || dataAttr(button, 'item_name') || '';
          if (modelInput) modelInput.value = modelVal;
          // Sync item_name to model for rename behavior
          if (hiddenEl) hiddenEl.value = modelVal;
          var qVal = button.getAttribute('data-quantity') || 1;
          var qHidden = document.getElementById('edit_quantity_hidden');
          if (qHidden) qHidden.value = qVal;
          document.getElementById('edit_location').value = button.getAttribute('data-location') || '';
          document.getElementById('edit_serial_no').value = dataAttr(button, 'serial_no') || '';
          
          var currentStatus = button.getAttribute('data-status') || 'Available';
          document.getElementById('edit_status').value = currentStatus;
          // Enforce allowed status transitions in UI by disabling options
          try {
            var sel = document.getElementById('edit_status');
            if (sel) {
              // Reset: enable all first
              Array.prototype.forEach.call(sel.options, function(op){ op.disabled = false; });
              var cs = currentStatus;
              // Treat Reserved like Available for transitions
              if (cs === 'Reserved' || cs === 'Returned') { cs = 'Available'; }
              var allow = null;
              if (cs === 'Available') {
                allow = ['Lost','Under Maintenance','Out of Order'];
              } else if (cs === 'Lost' || cs === 'Under Maintenance') {
                allow = ['Available'];
              } else if (cs === 'Out of Order') {
                allow = ['Available','Lost','Under Maintenance'];
              }
              if (allow) {
                Array.prototype.forEach.call(sel.options, function(op){
                  var v = (op.value||'');
                  var same = (v === currentStatus);
                  var ok = allow.indexOf(v) !== -1 || same;
                  op.disabled = !ok;
                });
              }
            }
          } catch(e){}
          var dt = dataAttr(button, 'date_acquired') || '';
          document.getElementById('edit_date_acquired').value = dt ? dt.substring(0,10) : '';
          document.getElementById('edit_remarks').value = button.getAttribute('data-remarks') || '';
          // If In Use, disable fields and save button, show alert
          var warn = document.getElementById('edit_inuse_alert');
          var saveBtn = editModal.querySelector('.modal-footer button.btn-primary');
          var disable = (currentStatus === 'In Use');
          ['edit_model_input','edit_location','edit_serial_no','edit_status','edit_date_acquired','edit_remarks'].forEach(function(id){ var el=document.getElementById(id); if (el) { el.disabled = disable; }});
          if (saveBtn) saveBtn.disabled = disable;
          if (warn) warn.classList.toggle('d-none', !disable);
        });

        // Keep item_name synced with model while editing (renaming)
        var editModel = document.getElementById('edit_model_input');
        var editNameHidden = document.getElementById('edit_item_name_hidden');
        if (editModel && editNameHidden) {
          editModel.addEventListener('input', function(){ editNameHidden.value = (editModel.value||'').trim(); });
        }

        // Live SID validation with indicator and submit guard
        var sidInput = document.getElementById('edit_serial_no');
        var sidIcon = document.getElementById('edit_sid_icon');
        var sidMsg = document.getElementById('edit_sid_msg');
        var editForm = editModal.querySelector('form');
        var hasDup = false;
        function setSidState(state, text){
          if (!sidIcon || !sidMsg) return;
          if (state === 'ok'){ sidIcon.className = 'sid-indicator bi bi-check-circle text-success'; sidIcon.style.display='inline-block'; sidMsg.textContent = text||''; sidMsg.className='small text-success'; hasDup=false; }
          else if (state === 'bad'){ sidIcon.className = 'sid-indicator bi bi-x-circle text-danger'; sidIcon.style.display='inline-block'; sidMsg.textContent = text||'Serial ID already exists.'; sidMsg.className='small text-danger'; hasDup=true; }
          else { sidIcon.style.display='none'; sidMsg.textContent=''; hasDup=false; }
        }
        function debounce(fn, wait){ let t; return function(){ clearTimeout(t); var ctx=this,args=arguments; t=setTimeout(function(){ fn.apply(ctx,args); }, wait); }; }
        var runCheck = debounce(function(){
          if (!sidInput) return; var val = (sidInput.value||'').trim(); if (val===''){ setSidState('clear'); return; }
          var idEl = document.getElementById('edit_id'); var ex = idEl ? (idEl.value||'') : '';
          fetch('inventory.php?action=check_serial&sid=' + encodeURIComponent(val) + '&exclude_id=' + encodeURIComponent(ex))
            .then(function(r){ if(!r.ok) throw new Error(); return r.json(); })
            .then(function(d){ if (d && d.exists) setSidState('bad','Serial already exists in inventory.'); else setSidState('ok','Serial available.'); })
            .catch(function(){ setSidState('clear'); });
        }, 250);
        if (sidInput){ sidInput.addEventListener('input', runCheck); }
        if (editForm){ editForm.addEventListener('submit', function(e){ if (hasDup){ e.preventDefault(); e.stopPropagation(); } }); }
      }
      // Add modal wiring: sync on input
      var addModelInput = document.getElementById('add_model_input');
      var addHidden = document.getElementById('add_item_name_hidden');
      if (addModelInput) {
        addModelInput.addEventListener('input', function(){ syncHiddenNameFromInput(addModelInput, addHidden); });
        // initialize on load
        syncHiddenNameFromInput(addModelInput, addHidden);
      }

      // Edit modal: do not sync item_name from model input; keep original item_name intact
    });
    </script>
    </div>
    
    <!-- Scan View Modal (Admin) -->
    <div class="modal fade" id="scanViewModal" tabindex="-1" aria-labelledby="scanViewModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="scanViewModalLabel"><i class="bi bi-eye me-2"></i>Scan Details</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6">
                <div><strong>Serial ID:</strong> <span id="sv_model_id"></span></div>
                <div><strong>Model Type:</strong> <span id="sv_item_name"></span></div>
                <div><strong>Status:</strong> <span id="sv_status"></span></div>
                <div><strong>Form Type:</strong> <span id="sv_form_type"></span></div>
              </div>
              <div class="col-md-6">
                <div><strong>Room:</strong> <span id="sv_room"></span></div>
                <div><strong>Generated Date:</strong> <span id="sv_generated_date"></span></div>
                <div><strong>Scanned At:</strong> <span id="sv_scanned_at"></span></div>
                <div><strong>Scanned By:</strong> <span id="sv_scanned_by"></span></div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>
    <!-- Hidden GET form for reliable navigation with admin_password -->
    <form id="adminPwNavForm" method="GET" action="" style="display:none;">
      <input type="hidden" name="admin_password" id="adminPwNavInput" value="" />
    </form>

    <script>
    // Populate Scan View Modal with row data
    document.addEventListener('DOMContentLoaded', function() {
      var scanModal = document.getElementById('scanViewModal');
      if (scanModal) {
        scanModal.addEventListener('show.bs.modal', function (event) {
          var button = event.relatedTarget;
          if (!button) return;
          function setText(id, val){ var el = document.getElementById(id); if (el) el.textContent = val || ''; }
          var id = button.getAttribute('data-id') || '';
          setText('sv_model_id', button.getAttribute('data-model_id') || '');
          setText('sv_item_name', button.getAttribute('data-item_name') || '');
          setText('sv_status', button.getAttribute('data-status') || '');
          setText('sv_form_type', button.getAttribute('data-form_type') || '');
          setText('sv_room', button.getAttribute('data-room') || '');
          setText('sv_generated_date', button.getAttribute('data-generated_date') || '');
          setText('sv_scanned_at', button.getAttribute('data-scanned_at') || '');
          setText('sv_scanned_by', button.getAttribute('data-scanned_by') || '');
          var link = document.getElementById('sv_view_full');
          if (link) link.href = 'inventory.php?id=' + encodeURIComponent(id);
        });
      }
    });
    </script>

  <!-- Actions Modal (replaces row dropdown) -->
  <div class="modal fade" id="actionsModal" tabindex="-1" aria-labelledby="actionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="actionsModalLabel"><i class="bi bi-three-dots-vertical me-2"></i>Actions</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body d-grid gap-2">
          <button type="button" id="am_edit_btn" class="btn btn-outline-primary"><i class="bi bi-pencil-square me-2"></i>Edit</button>
          <button type="button" id="am_qr_btn" class="btn btn-outline-secondary"><i class="bi bi-qr-code me-2"></i>QR</button>
          <button type="button" id="am_delete_btn" class="btn btn-outline-danger"><i class="bi bi-trash me-2"></i>Delete</button>
        </div>
      </div>
    </div>
  </div>

  <script>
  // Wire Actions modal to trigger existing Edit and QR modals, or navigate to Delete
  document.addEventListener('DOMContentLoaded', function(){
    var actionsModal = document.getElementById('actionsModal');
    if (!actionsModal) return;
    var currentData = {};
    actionsModal.addEventListener('show.bs.modal', function(event){
      var btn = event.relatedTarget || null;
      function ga(attr){ try{ return (btn && btn.getAttribute(attr)) ? btn.getAttribute(attr) : ''; }catch(_){ return ''; } }
      currentData = {
        id: ga('data-id'),
        item_name: ga('data-item_name'),
        model: ga('data-model'),
        category: ga('data-category'),
        quantity: ga('data-quantity'),
        location: ga('data-location'),
        status: ga('data-status'),
        date_acquired: ga('data-date_acquired'),
        remarks: ga('data-remarks'),
        serial_no: ga('data-serial_no')
      };
      var title = document.getElementById('actionsModalLabel');
      if (title) title.innerHTML = '<i class="bi bi-three-dots-vertical me-2"></i>Actions - ' + (currentData.item_name || ('ID ' + currentData.id));
      // Hide Edit and Delete for items In Use
      var norm = String(currentData.status || '').trim().toLowerCase().replace(/\s+/g,' ');
      var inUse = (norm === 'in use');
      var delEl = document.getElementById('am_delete_btn');
      if (delEl) { delEl.classList.toggle('d-none', inUse); delEl.disabled = inUse; }
      var editEl = document.getElementById('am_edit_btn');
      if (editEl) { editEl.classList.toggle('d-none', inUse); editEl.disabled = inUse; }
    });

    function openModalWithData(targetId){
      // Create a temporary hidden button with data attributes to preserve relatedTarget behavior
      var tmp = document.createElement('button');
      tmp.type = 'button';
      tmp.setAttribute('data-bs-toggle', 'modal');
      tmp.setAttribute('data-bs-target', targetId);
      // inject datasets expected by the target modal handlers
      for (var k in currentData) { if (Object.prototype.hasOwnProperty.call(currentData, k)) {
        tmp.setAttribute('data-' + k.replace(/_/g,'-'), currentData[k]);
      }}
      tmp.style.display = 'none';
      document.body.appendChild(tmp);
      tmp.click();
      // cleanup shortly after
      setTimeout(function(){ try{ document.body.removeChild(tmp); }catch(e){} }, 500);
    }

    var editBtn = document.getElementById('am_edit_btn');
    if (editBtn) editBtn.addEventListener('click', function(){
      var inst = bootstrap.Modal.getInstance(actionsModal); if (inst) inst.hide();
      setTimeout(function(){ openModalWithData('#editItemModal'); }, 150);
    });

    var qrBtn = document.getElementById('am_qr_btn');
    if (qrBtn) qrBtn.addEventListener('click', function(){
      var inst = bootstrap.Modal.getInstance(actionsModal); if (inst) inst.hide();
      setTimeout(function(){ openModalWithData('#qrPreviewModal'); }, 150);
    });

    var delBtn = document.getElementById('am_delete_btn');
    if (delBtn) delBtn.addEventListener('click', function(){
      var inst = bootstrap.Modal.getInstance(actionsModal); if (inst) inst.hide();
      setTimeout(function(){
        if (!currentData.id) return;
        // Set pending single-delete id and open Admin Password modal directly
        window.__pendingDeleteSingle = String(currentData.id);
        try {
          var apm = document.getElementById('adminPwModal');
          if (apm && window.bootstrap && bootstrap.Modal) {
            var m = bootstrap.Modal.getOrCreateInstance(apm);
            m.show();
            return;
          }
        } catch(_) {}
        // Fallback to prompt
        if (window.openAdminPwPrompt) { openAdminPwPrompt(function(pw, reason){ /* callback handled in admin modal */ }); }
      }, 150);
    });

    // Confirm deletion from Single Delete modal
    var singleConfirmBtn = document.getElementById('confirmSingleDeleteBtn');
    if (singleConfirmBtn) singleConfirmBtn.addEventListener('click', function(){
      var mEl = document.getElementById('confirmSingleDeleteModal');
      var id = mEl ? (mEl.getAttribute('data-item-id') || '') : '';
      if (!id) return;
      if (window.openAdminPwPrompt) {
        openAdminPwPrompt(function(pw, reason){
          if (!pw) return;
          var f = document.createElement('form');
          f.method = 'POST';
          f.action = 'inventory.php';
          function add(name, value){ var i = document.createElement('input'); i.type='hidden'; i.name=name; i.value=String(value||''); f.appendChild(i); }
          add('single_delete', '1');
          add('item_id', id);
          add('delete_reason', reason || '');
          add('admin_password', pw);
          document.body.appendChild(f);
          f.submit();
        });
      }
    });
  });
  </script>

    <!-- QR Preview Modal -->
    <div class="modal fade" id="qrPreviewModal" tabindex="-1" aria-labelledby="qrPreviewModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="qrPreviewModalLabel"><i class="bi bi-qr-code me-2"></i>QR Code</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body text-center">
            <img id="qrPreviewImg" src="" alt="QR" style="max-width: 100%; height: auto;" />
          </div>
          <div class="modal-footer">
            <a id="qrDownloadLink" class="btn btn-success" href="#" download>
              <i class="bi bi-download me-1"></i>Download
            </a>
            
          </div>
        </div>
      </div>
    </div>

    <!-- Admin Password Prompt Modal -->
    <div class="modal fade" id="adminPwModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-shield-lock me-2"></i>Admin Authentication</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form id="adminPwForm">
            <div class="modal-body">
              <div class="mb-3">
                <label for="adminPwInput" class="form-label">Enter admin password to confirm delete</label>
                <input id="adminPwInput" type="password" class="form-control" autocomplete="current-password" required />
              </div>
              <div class="mb-2">
                <label for="adminReasonInput" class="form-label">Reason (required)</label>
                <input id="adminReasonInput" type="text" class="form-control" placeholder="Enter reason for deletion" required />
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Confirm</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
    // Reusable masked admin password prompt
    (function(){
      var cb = null;
      var modalEl = document.getElementById('adminPwModal');
      var formEl = document.getElementById('adminPwForm');
      var inputEl = document.getElementById('adminPwInput');
      var reasonEl = document.getElementById('adminReasonInput');
      var modal;
      function ensure(){ if (!modal && window.bootstrap && modalEl) { modal = new bootstrap.Modal(modalEl, {backdrop:'static'}); } }
      window.openAdminPwPrompt = function(next){
        cb = (typeof next === 'function') ? next : null;
        // Fallback to prompt if Bootstrap/modal not available
        if (!window.bootstrap || !modalEl) {
          var pw = window.prompt('Enter admin password to confirm delete');
          if (cb && pw) { var tmp = cb; cb=null; tmp(pw, ''); }
          return;
        }
        ensure();
        if (!modal) {
          var pw2 = window.prompt('Enter admin password to confirm delete');
          if (cb && pw2) { var t2 = cb; cb=null; t2(pw2, ''); }
          return;
        }
        if (inputEl) { inputEl.value = ''; }
        if (reasonEl) { reasonEl.value = ''; }
        modal.show();
        setTimeout(function(){ try{ inputEl && inputEl.focus(); }catch(_){} }, 150);
      };
      // Helper to append admin_password and navigate via GET (matches server GET handlers)
      window.__postWithAdminPassword = function(url, pw){
        try {
          var f = document.getElementById('adminPwNavForm');
          var i = document.getElementById('adminPwNavInput');
          if (f && i) { f.action = url; i.value = pw; f.submit(); return; }
        } catch(_) {}
        var sep = (url.indexOf('?')>=0 ? '&' : '?');
        window.location.href = url + sep + 'admin_password=' + encodeURIComponent(pw);
      };
      if (formEl) {
        formEl.addEventListener('submit', function(e){
          e.preventDefault();
          var val = inputEl ? String(inputEl.value||'') : '';
          if (!val) { if (inputEl) inputEl.focus(); return; }
          if (modal) { modal.hide(); }
          var fn = cb; cb = null;
          var reason = reasonEl ? String(reasonEl.value||'') : '';
          if (!reason) { if (reasonEl) reasonEl.focus(); return; }
          if (typeof fn === 'function') {
            fn(val, reason);
          } else if (window.__pendingDeleteSingle) {
            try {
              var id = String(window.__pendingDeleteSingle || '');
              window.__pendingDeleteSingle = '';
              if (id) {
                var f = document.createElement('form');
                f.method = 'POST';
                f.action = 'inventory.php';
                function add(n,v){ var i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=String(v||''); f.appendChild(i); }
                add('single_delete','1');
                add('item_id', id);
                add('delete_reason', reason || '');
                add('admin_password', val);
                document.body.appendChild(f);
                f.submit();
                return;
              }
            } catch(_) {}
          }
          if (inputEl) { inputEl.value = ''; }
          if (reasonEl) { reasonEl.value = ''; }
        });
      }
      if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function(){ if (inputEl) inputEl.value=''; cb=null; });
      }
    })();
    </script>

    <script>
    // Populate QR Preview modal image from item id
    document.addEventListener('DOMContentLoaded', function() {
      var qrModal = document.getElementById('qrPreviewModal');
      if (qrModal) {
        qrModal.addEventListener('show.bs.modal', function (event) {
          var button = event.relatedTarget;
          if (!button) return;
          var id = button.getAttribute('data-id');
          var name = button.getAttribute('data-item_name') || 'QR Code';
          var img = document.getElementById('qrPreviewImg');
          var link = document.getElementById('qrDownloadLink');
          var title = document.getElementById('qrPreviewModalLabel');
          if (title) title.innerHTML = '<i class="bi bi-qr-code me-2"></i>QR Code - ' + name;
          var base = 'qr_image.php?id=' + encodeURIComponent(id);
          var src = base; // plain for preview
          if (img) img.src = src + '&_=' + Date.now(); // bust cache for preview
          if (link) {
            var dl = base + '&label=1';
            link.href = dl;
            link.setAttribute('download', (name.replace(/[^a-z0-9\-_]+/gi,'_') || 'qr') + '.png');
          }
          var printBtn = document.getElementById('qrPrintBtn');
          if (printBtn) {
            printBtn.onclick = function(){
              var printWin = window.open('', '_blank', 'width=900,height=700');
              var imgSrc = base + '&label=1&_=' + Date.now();
              var safeName = name.replace(/</g,'&lt;').replace(/>/g,'&gt;');
              var html = '\n<!DOCTYPE html>\n<html><head><meta charset="UTF-8"><title>Print QR - ' + safeName + '</title>\n'
                + '<style>body{font-family:Arial,Helvetica,sans-serif;margin:0} .wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh} .qr img{max-width:80mm;width:80mm;height:auto} .label{margin-top:10mm;font-size:18pt;font-weight:bold;text-align:center;word-break:break-word} @page{size:A4;margin:12mm} @media print{.noprint{display:none}}</style>'
                + '</head><body>\n<div class="wrap">\n<div class="qr"><img id="qrImg" src="' + imgSrc + '" alt="QR" /></div>\n'
                + '<div class="label">' + safeName + '</div>\n'
                + '<div class="noprint" style="margin-top:12mm;text-align:center"><button onclick="window.print()">Print</button></div>\n'
                + '</div>\n<script>\n(function(){\n  var img=document.getElementById("qrImg");\n  function doPrint(){ try{ window.focus(); window.print(); }catch(e){} }\n  if (img && img.complete){ setTimeout(doPrint, 150); }\n  else if (img){ img.addEventListener("load", function(){ setTimeout(doPrint, 150); }); }\n})();\n<\/script>\n</body></html>';
              printWin.document.open();
              printWin.document.write(html);
              printWin.document.close();
              // Fallback safety in case load event misses
              setTimeout(function(){ try{ printWin.focus(); printWin.print(); }catch(e){} }, 1500);
            };
          }
        });
        qrModal.addEventListener('hidden.bs.modal', function () {
          var img = document.getElementById('qrPreviewImg');
          if (img) img.src = '';
        });
      }
    });
    </script>
    <?php endif; ?>
  <script>
    // Global admin notifications: user verified returns (toast + beep)
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        try {
          var toastWrap = document.getElementById('adminToastWrap');
          if (!toastWrap) {
            toastWrap = document.createElement('div'); toastWrap.id = 'adminToastWrap';
            toastWrap.style.position='fixed'; toastWrap.style.right='16px'; toastWrap.style.bottom='16px'; toastWrap.style.zIndex='1080';
            document.body.appendChild(toastWrap);
          }
          function showToast(msg, cls){ var el=document.createElement('div'); el.className='alert '+(cls||'alert-info')+' shadow-sm border-0'; el.style.minWidth='280px'; el.style.maxWidth='360px'; el.innerHTML='<i class="bi bi-bell me-2"></i>'+String(msg||''); toastWrap.appendChild(el); setTimeout(function(){ try{ el.remove(); }catch(_){ } }, 5000); }
          function playBeep(){ try{ var ctx = new (window.AudioContext||window.webkitAudioContext)(); var o = ctx.createOscillator(); var g = ctx.createGain(); o.type='triangle'; o.frequency.setValueAtTime(880, ctx.currentTime); g.gain.setValueAtTime(0.0001, ctx.currentTime); o.connect(g); g.connect(ctx.destination); o.start(); g.gain.exponentialRampToValueAtTime(0.1, ctx.currentTime+0.01); g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime+0.4); o.stop(ctx.currentTime+0.45); } catch(_e){} }
          var baseVerif = new Set(); var initFeed=false; var feeding=false;
          function pollVerif(){ if (feeding) return; feeding=true;
            fetch('admin_borrow_center.php?action=returnship_feed')
              .then(function(r){ return r.json(); })
              .then(function(d){ var list = (d && d.ok && Array.isArray(d.verifications)) ? d.verifications : []; var ids = new Set(list.map(function(v){ return parseInt(v.id||0,10); }).filter(function(n){ return n>0; })); if (!initFeed){ baseVerif = ids; initFeed=true; return; } var ding=false; list.forEach(function(v){ var id=parseInt(v.id||0,10); if (!baseVerif.has(id)){ ding=true; var name=String(v.model_name||''); var sn=String(v.qr_serial_no||''); var loc=String(v.location||''); showToast('User verified return for '+(name?name+' ':'')+(sn?('['+sn+']'):'')+(loc?(' @ '+loc):''), 'alert-info'); } }); if (ding) playBeep(); baseVerif = ids; })
              .catch(function(){})
              .finally(function(){ feeding=false; });
          }
          pollVerif(); setInterval(function(){ if (document.visibilityState==='visible') pollVerif(); }, 2000);
        } catch(_e){}
      });
    })();
  </script>
  </body>
  </html>
