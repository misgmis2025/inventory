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
if (!isset($_SESSION['username'])) { header('Location: index.php'); exit(); }
// Only regular users can access this page; redirect admins to admin borrow center
if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin') { header('Location: admin_borrow_center.php'); exit(); }
// Action routing must be defined before any endpoint usage
$__act = $_GET['action'] ?? '';

// Initialize Mongo connection early for endpoint handlers
$USED_MONGO = false; $mongo_db = null; $conn = null;
try {
  require_once __DIR__ . '/../vendor/autoload.php';
  require_once __DIR__ . '/db/mongo.php';
  $mongo_db = get_mongo_db();
  $USED_MONGO = true;
} catch (Throwable $e) { $USED_MONGO = false; }

// JSON: user clears one notification
if ($__act === 'user_notif_clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  try {
    if ($USED_MONGO && $mongo_db) {
      $col = $mongo_db->selectCollection('user_notif_clears');
      $uname = (string)($_SESSION['username'] ?? '');
      $key = trim((string)($_POST['key'] ?? ''));
      if ($uname !== '' && $key !== '') { $col->updateOne(['uname'=>$uname,'key'=>$key], ['$set'=>['uname'=>$uname,'key'=>$key,'created_at'=>date('Y-m-d H:i:s')]], ['upsert'=>true]); }
      echo json_encode(['ok'=>true]);
    } else { echo json_encode(['ok'=>false]); }
  } catch (Throwable $e) { echo json_encode(['ok'=>false]); }
  exit;
}

// JSON: user clears all current notifications (best-effort)
if ($__act === 'user_notif_clear_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  try {
    if ($USED_MONGO && $mongo_db) {
      $uname = (string)($_SESSION['username'] ?? ''); if ($uname === '') { echo json_encode(['ok'=>false]); exit; }
      // Build current keys from same sources as user_notifications
      $allocCol = $mongo_db->selectCollection('request_allocations');
      $ubCol = $mongo_db->selectCollection('user_borrows');
      $erCol = $mongo_db->selectCollection('equipment_requests');
      $iiCol = $mongo_db->selectCollection('inventory_items');
      $ldCol = $mongo_db->selectCollection('lost_damaged_log');
      $rsCol = $mongo_db->selectCollection('returnship_requests');
      $keys = [];
      try {
        $allocs = $allocCol->find([], ['sort'=>['id'=>-1], 'limit'=>300]);
        foreach ($allocs as $al) {
          $reqId = (int)($al['request_id'] ?? 0); $bid = (int)($al['borrow_id'] ?? 0);
          if ($reqId<=0 || $bid<=0) continue; $er = $erCol->findOne(['id'=>$reqId, 'username'=>$uname]); if (!$er) continue; $keys[] = 'approval:' . ((int)($al['id'] ?? 0));
        }
      } catch (Throwable $_a) {}
      // Current request rows (Approved/Rejected/Borrowed/Returned)
      try {
        $curER = $erCol->find(['username'=>$uname, 'status' => ['$in' => ['Approved','Rejected','Borrowed','Returned']]], ['projection'=>['id'=>1], 'sort'=>['id'=>-1], 'limit'=>300]);
        foreach ($curER as $erx) { $keys[] = 'req:' . ((int)($erx['id'] ?? 0)); }
      } catch (Throwable $_er) {}
      try {
        $logs = $ldCol->find(['username'=>$uname,'action'=>['$in'=>['Lost','Under Maintenance']]], ['sort'=>['id'=>-1], 'limit'=>300]);
        foreach ($logs as $l) { $keys[] = 'lost:' . ((int)($l['id'] ?? 0)); }
      } catch (Throwable $_l) {}
      try {
        $rsCur = $rsCol->find(['username'=>$uname, 'status' => ['$in' => ['Pending','Requested']]], ['sort'=>['id'=>-1], 'limit'=>100]);
        foreach ($rsCur as $rs) { $keys[] = 'returnship:' . ((int)($rs['id'] ?? 0)); }
      } catch (Throwable $_r) {}
      // decisions
      try {
        $decCol = $mongo_db->selectCollection('user_decisions'); // optional collection; ignore if missing
        // no-op if absent
      } catch (Throwable $_d) {}
      $col = $mongo_db->selectCollection('user_notif_clears');
      $now = date('Y-m-d H:i:s');
      $bulk = [];
      foreach (array_values(array_unique($keys)) as $k) { if ($k==='') continue; $bulk[] = ['updateOne'=>[['uname'=>$uname,'key'=>$k], ['$set'=>['uname'=>$uname,'key'=>$k,'created_at'=>$now]], ['upsert'=>true]]]; }
      if (!empty($bulk)) { try { $col->bulkWrite($bulk); } catch (Throwable $_bw) {} }
      echo json_encode(['ok'=>true]);
    } else { echo json_encode(['ok'=>false]); }
  } catch (Throwable $e) { echo json_encode(['ok'=>false]); }
  exit;
}

// JSON: reservation_start_hint (earliest start time for reservation on single-quantity items)
if ($__act === 'reservation_start_hint' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  $modelIn = trim((string)($_GET['model'] ?? ''));
  try {
    if ($modelIn === '') { echo json_encode(['earliest'=>'']); exit; }
    $nowStr = date('Y-m-d H:i:s');
    if ($USED_MONGO && $mongo_db) {
      $ii = $mongo_db->selectCollection('inventory_items');
      // total units for this model
      $sum = 0; try {
        $agg = $ii->aggregate([
          ['$match'=>['$or'=>[['model'=>$modelIn],['item_name'=>$modelIn]]]],
          ['$project'=>['q'=>['$ifNull'=>['$quantity',1]]]],
          ['$group'=>['_id'=>null,'sum'=>['$sum'=>'$q']]]
        ]);
        foreach ($agg as $r){ $sum = (int)($r->sum ?? 0); break; }
      } catch (Throwable $_) { $sum = 0; }
      if ($sum > 1) { echo json_encode(['earliest'=>'', 'single'=>false, 'available_now'=>false]); exit; }
      // Resolve a unit id for this model
      $unit = $ii->findOne(['$or'=>[['model'=>$modelIn],['item_name'=>$modelIn]]], ['sort'=>['id'=>1], 'projection'=>['id'=>1]]);
      $blockTs = 0;
      if ($unit && isset($unit['id'])) {
        try {
          $ub = $mongo_db->selectCollection('user_borrows');
          $borrow = $ub->findOne(['model_id'=>(int)$unit['id'], 'status'=>'Borrowed'], ['projection'=>['expected_return_at'=>1]]);
          if ($borrow && isset($borrow['expected_return_at'])) { $t = strtotime((string)$borrow['expected_return_at']); if ($t) $blockTs = max($blockTs, $t); }
        } catch (Throwable $_b) {}
      }
      try {
        $er = $mongo_db->selectCollection('equipment_requests');
        $activeRes = $er->findOne(['item_name'=>$modelIn, 'type'=>'reservation', 'status'=>'Approved', 'reserved_from'=>['$lte'=>$nowStr], 'reserved_to'=>['$gte'=>$nowStr]], ['sort'=>['reserved_to'=>-1], 'projection'=>['reserved_to'=>1]]);
        if ($activeRes && isset($activeRes['reserved_to'])) { $t = strtotime((string)$activeRes['reserved_to']); if ($t) $blockTs = max($blockTs, $t); }
      } catch (Throwable $_r) {}
      $earliest = $blockTs ? date('Y-m-d H:i', $blockTs + 5*60) : '';
      $availableNow = $blockTs ? false : true;
      echo json_encode(['earliest'=>$earliest, 'single'=>true, 'available_now'=>$availableNow]); exit;
    } elseif ($conn) {
      // total units
      $sum = 0; if ($st = $conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_items WHERE LOWER(TRIM(COALESCE(NULLIF(model,''), item_name)))=LOWER(TRIM(?))")) { $st->bind_param('s',$modelIn); $st->execute(); $st->bind_result($sum); $st->fetch(); $st->close(); }
      if ((int)$sum > 1) { echo json_encode(['earliest'=>'', 'single'=>false, 'available_now'=>false]); exit; }
      // resolve unit id
      $uid = 0; if ($st2 = $conn->prepare("SELECT id FROM inventory_items WHERE (model=? OR item_name=?) ORDER BY id ASC LIMIT 1")) { $st2->bind_param('ss',$modelIn,$modelIn); $st2->execute(); $st2->bind_result($uid); $st2->fetch(); $st2->close(); }
      $blockTs = 0;
      if ($uid) {
        $expStr = null; if ($st3 = $conn->prepare("SELECT expected_return_at FROM user_borrows WHERE model_id=? AND status='Borrowed' LIMIT 1")) { $st3->bind_param('i',$uid); $st3->execute(); $st3->bind_result($expStr); $st3->fetch(); $st3->close(); }
        if ($expStr) { $t = strtotime((string)$expStr); if ($t) $blockTs = max($blockTs, $t); }
      }
      $resStr = null; if ($st4 = $conn->prepare("SELECT MAX(reserved_to) FROM equipment_requests WHERE type='reservation' AND status='Approved' AND LOWER(TRIM(item_name))=LOWER(TRIM(?)) AND reserved_from <= NOW() AND reserved_to >= NOW()")) { $st4->bind_param('s',$modelIn); $st4->execute(); $st4->bind_result($resStr); $st4->fetch(); $st4->close(); }
      if ($resStr) { $t = strtotime((string)$resStr); if ($t) $blockTs = max($blockTs, $t); }
      $earliest = $blockTs ? date('Y-m-d H:i', $blockTs + 5*60) : '';
      $availableNow = $blockTs ? false : true;
      echo json_encode(['earliest'=>$earliest, 'single'=>true, 'available_now'=>$availableNow]); exit;
    }
  } catch (Throwable $_) { echo json_encode(['earliest'=>'']); }
  exit;
}

// JSON: my_overdue (active borrowed items past expected_return_at)
if ($__act === 'my_overdue' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  $now = date('Y-m-d H:i:s');
  $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 500)) : 200;
  if ($USED_MONGO && $mongo_db) {
    $rows = [];
    try {
      $ubCol = $mongo_db->selectCollection('user_borrows');
      $iiCol = $mongo_db->selectCollection('inventory_items');
      $erCol = $mongo_db->selectCollection('equipment_requests');
      $raCol = $mongo_db->selectCollection('request_allocations');
      // Active borrows for this user (not returned)
      $cur = $ubCol->find([
        'username' => (string)$_SESSION['username'],
        '$or' => [ ['returned_at' => null], ['returned_at' => ''] ]
      ], ['sort' => ['borrowed_at' => -1, 'id' => -1]]);
      foreach ($cur as $ub) {
        $mid = (int)($ub['model_id'] ?? 0);
        $ii = $mid>0 ? $iiCol->findOne(['id'=>$mid]) : null;
        $alloc = $raCol->findOne(['borrow_id' => (int)($ub['id'] ?? 0)], ['projection'=>['request_id'=>1]]);
        $reqId = (int)($alloc['request_id'] ?? 0);
        // Determine due date: reservation reserved_to > request expected_return_at > borrow expected_return_at
        $due = (string)($ub['expected_return_at'] ?? '');
        try {
          if (isset($ub['expected_return_at']) && $ub['expected_return_at'] instanceof MongoDB\BSON\UTCDateTime) {
            $dte = $ub['expected_return_at']->toDateTime();
            $dte->setTimezone(new DateTimeZone('Asia/Manila'));
            $due = $dte->format('Y-m-d H:i:s');
          }
        } catch (Throwable $_e0) {}
        if ($reqId > 0) {
          $req = $erCol->findOne(['id'=>$reqId], ['projection'=>['type'=>1,'reserved_to'=>1,'expected_return_at'=>1]]);
          if ($req) {
            $reqType = (string)($req['type'] ?? '');
            if (strcasecmp($reqType,'reservation')===0) {
              $rt = (string)($req['reserved_to'] ?? '');
              try { if (isset($req['reserved_to']) && $req['reserved_to'] instanceof MongoDB\BSON\UTCDateTime) { $dt2=$req['reserved_to']->toDateTime(); $dt2->setTimezone(new DateTimeZone('Asia/Manila')); $rt = $dt2->format('Y-m-d H:i:s'); } } catch (Throwable $_e1) {}
              if ($rt !== '') { $due = $rt; }
            } else {
              $rt2 = (string)($req['expected_return_at'] ?? '');
              try { if (isset($req['expected_return_at']) && $req['expected_return_at'] instanceof MongoDB\BSON\UTCDateTime) { $dt3=$req['expected_return_at']->toDateTime(); $dt3->setTimezone(new DateTimeZone('Asia/Manila')); $rt2 = $dt3->format('Y-m-d H:i:s'); } } catch (Throwable $_e2) {}
              if ($rt2 !== '') { $due = $rt2; }
            }
          }
        }
        if ($due === '') { continue; }
        if (!(strtotime($due) && strtotime($due) < strtotime($now))) { continue; }
        $dispModel = '';
        if ($ii) { $dispModel = (string)($ii['model'] ?? ''); if ($dispModel==='') { $dispModel = (string)($ii['item_name'] ?? ''); } }
        $days = max(0, (int)floor((strtotime($now) - strtotime($due)) / 86400));
        // Determine if this request was created via QR
        $isQr = false;
        if ($reqId > 0) {
          try { $reqDoc = $erCol->findOne(['id'=>$reqId], ['projection'=>['qr_serial_no'=>1]]); $isQr = ($reqDoc && isset($reqDoc['qr_serial_no']) && trim((string)$reqDoc['qr_serial_no'])!==''); } catch (Throwable $_rq) { $isQr=false; }
        }
        $rows[] = [
          'borrow_id' => (int)($ub['id'] ?? 0),
          'request_id' => $reqId,
          'model_id' => $mid,
          'model' => $dispModel,
          'category' => ($ii ? (string)($ii['category'] ?? '') : 'Uncategorized'),
          'borrowed_at' => (isset($ub['borrowed_at']) && $ub['borrowed_at'] instanceof MongoDB\BSON\UTCDateTime ? (function($x){ $dt=$x->toDateTime(); $dt->setTimezone(new DateTimeZone('Asia/Manila')); return $dt->format('Y-m-d H:i:s'); })($ub['borrowed_at']) : (string)($ub['borrowed_at'] ?? '')),
          'expected_return_at' => $due,
          'overdue_days' => $days,
          'type' => ($isQr ? 'QR' : 'Manual'),
        ];
        if (count($rows) >= $limit) { break; }
      }
    } catch (Throwable $e) { $rows = []; }
    echo json_encode(['overdue'=>$rows]);
  } else {
    $rows = [];
    if ($conn) {
      $sql = "SELECT ub.id AS borrow_id,
                     COALESCE(ra.request_id, (
                       SELECT er2.id FROM equipment_requests er2
                       WHERE er2.username = ub.username
                         AND (er2.item_name = COALESCE(NULLIF(ii.model,''), ii.item_name) OR er2.item_name = ii.item_name)
                       ORDER BY ABS(TIMESTAMPDIFF(SECOND, er2.created_at, ub.borrowed_at)) ASC, er2.id DESC
                       LIMIT 1
                     )) AS request_id,
                     ii.id AS model_id,
                     COALESCE(NULLIF(ii.model,''), ii.item_name) AS model,
                     COALESCE(NULLIF(ii.category,''),'Uncategorized') AS category,
                     ub.borrowed_at,
                     ub.expected_return_at,
                     (SELECT CASE WHEN COALESCE(NULLIF(er3.qr_serial_no,''),'')<>'' THEN 'QR' ELSE 'Manual' END
                        FROM equipment_requests er3
                        WHERE er3.id = COALESCE(ra.request_id, (
                          SELECT er4.id FROM equipment_requests er4
                          WHERE er4.username = ub.username
                            AND (er4.item_name = COALESCE(NULLIF(ii.model,''), ii.item_name) OR er4.item_name = ii.item_name)
                          ORDER BY ABS(TIMESTAMPDIFF(SECOND, er4.created_at, ub.borrowed_at)) ASC, er4.id DESC
                          LIMIT 1
                        )) LIMIT 1) AS type
              FROM user_borrows ub
              JOIN inventory_items ii ON ii.id = ub.model_id
              LEFT JOIN request_allocations ra ON ra.borrow_id = ub.id
              WHERE ub.username = ? AND ub.status = 'Borrowed' AND ub.expected_return_at IS NOT NULL AND ub.expected_return_at <> '' AND ub.expected_return_at < ?
              ORDER BY ub.expected_return_at ASC, ub.id DESC
              LIMIT " . (int)$limit;
      if ($st = $conn->prepare($sql)) {
        $st->bind_param('ss', $_SESSION['username'], $now);
        if ($st->execute()) { $res = $st->get_result(); while ($r = $res->fetch_assoc()) { 
          $due = (string)($r['expected_return_at'] ?? '');
          $r['overdue_days'] = ($due !== '') ? max(0, (int)floor((time() - strtotime($due))/86400)) : 0;
          $rows[] = $r;
        } }
        $st->close();
      }
    }
    echo json_encode(['overdue'=>$rows]);
  }
  exit;
}

// Ensure MySQL whitelist table exists early (for fallback mode)
if (!$USED_MONGO && isset($conn) && $conn) {
  @$conn->query("CREATE TABLE IF NOT EXISTS borrowable_units (
    id INT(11) NOT NULL AUTO_INCREMENT,
    model_id INT(11) NOT NULL,
    model_name VARCHAR(150) DEFAULT NULL,
    category VARCHAR(100) DEFAULT 'Uncategorized',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_model_id (model_id),
    KEY idx_model_cat (model_name, category)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

// Lightweight check: validate scanned serial matches the request's QR and the exact borrowed unit
if ($__act === 'returnship_check' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $reqId = (int)($_POST['request_id'] ?? 0);
  $serial = trim((string)($_POST['serial_no'] ?? ''));
  $borrowId = (int)($_POST['borrow_id'] ?? 0);
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    if ($reqId <= 0) { $reqId = (int)($data['request_id'] ?? 0); }
    if ($serial === '') { $serial = trim((string)($data['serial_no'] ?? '')); }
    if ($borrowId <= 0) { $borrowId = (int)($data['borrow_id'] ?? 0); }
  }
  if ($reqId <= 0 || $serial === '') { echo json_encode(['ok'=>false,'reason'=>'Missing parameters']); exit; }
  try {
    if ($USED_MONGO && $mongo_db) {
      $erCol = $mongo_db->selectCollection('equipment_requests');
      $iiCol = $mongo_db->selectCollection('inventory_items');
      $ubCol = $mongo_db->selectCollection('user_borrows');
      $raCol = $mongo_db->selectCollection('request_allocations');
      $req = $erCol->findOne(['id'=>$reqId, 'username'=>(string)$_SESSION['username']]);
      if (!$req) { echo json_encode(['ok'=>false,'reason'=>'Request not found']); exit; }
      $qrSerial = trim((string)($req['qr_serial_no'] ?? ''));
      // Resolve any allocation borrow IDs for this request
      $allocs = iterator_to_array($raCol->find(['request_id'=>$reqId], ['projection'=>['borrow_id'=>1]]));
      $borrowIds = array_values(array_filter(array_map(function($d){ return isset($d['borrow_id']) ? (int)$d['borrow_id'] : 0; }, $allocs)));
      if ($borrowId > 0) { $borrowIds = array_values(array_filter($borrowIds, function($v) use ($borrowId){ return (int)$v === $borrowId; })); }

      // Prefer exact borrow match by id + serial
      $criteriaBase = ['status'=>'Borrowed', 'username'=>(string)$_SESSION['username'], 'serial_no'=>$serial];
      $borrow = null;
      if ($borrowId > 0) {
        $tmp = $criteriaBase; $tmp['id'] = $borrowId; $borrow = $ubCol->findOne($tmp);
      }
      // Next, restrict to request allocations if available
      if (!$borrow && !empty($borrowIds)) {
        $borrow = $ubCol->findOne(['id'=>['$in'=>$borrowIds]] + $criteriaBase);
      }
      // Fallback: any current borrow by this user with this serial
      if (!$borrow) {
        $borrow = $ubCol->findOne($criteriaBase);
      }
      // Accept if either: there is a matching current borrow with this serial, OR the request QR serial matches
      if ($borrow || ($qrSerial !== '' && strcasecmp($qrSerial, $serial) === 0)) { echo json_encode(['ok'=>true]); exit; }
      // Provide a more specific reason if we can
      if ($qrSerial !== '' && strcasecmp($qrSerial, $serial) !== 0) { echo json_encode(['ok'=>false,'reason'=>'QR mismatch']); exit; }
      echo json_encode(['ok'=>false,'reason'=>'Item not currently borrowed']); exit;
    }
    echo json_encode(['ok'=>false,'reason'=>'DB unavailable']);
  } catch (Throwable $e) { echo json_encode(['ok'=>false,'reason'=>'Server error']); }
  exit;
}


// Auto-process user return via QR without admin approval
if ($__act === 'process_return' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $reqId = (int)($_POST['request_id'] ?? 0);
  $borrowId = (int)($_POST['borrow_id'] ?? 0);
  $serial = trim((string)($_POST['serial_no'] ?? ''));
  $location = trim((string)($_POST['location'] ?? ''));
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    if ($reqId <= 0) { $reqId = (int)($data['request_id'] ?? 0); }
    if ($borrowId <= 0) { $borrowId = (int)($data['borrow_id'] ?? 0); }
    if ($serial === '') { $serial = trim((string)($data['serial_no'] ?? '')); }
    if ($location === '') { $location = trim((string)($data['location'] ?? '')); }
  }
  if ($serial === '') { echo json_encode(['success'=>false,'error'=>'Missing serial']); exit; }
  try {
    if ($USED_MONGO && $mongo_db) {
      $erCol = $mongo_db->selectCollection('equipment_requests');
      $iiCol = $mongo_db->selectCollection('inventory_items');
      $ubCol = $mongo_db->selectCollection('user_borrows');
      $raCol = $mongo_db->selectCollection('request_allocations');
      $now = date('Y-m-d H:i:s');

      // Resolve inventory item by serial
      $item = $iiCol->findOne(['serial_no' => $serial]);
      $mid = $item && isset($item['id']) ? (int)$item['id'] : 0;

      // Find active borrow for this user
      $borrow = null;
      if ($borrowId > 0) {
        $borrow = $ubCol->findOne(['id' => $borrowId, 'username' => (string)$_SESSION['username'], 'status' => 'Borrowed']);
      }
      if (!$borrow && $mid > 0) {
        $borrow = $ubCol->findOne(['model_id' => $mid, 'username' => (string)$_SESSION['username'], 'status' => 'Borrowed']);
      }
      if (!$borrow) {
        // Fallback: most recent borrow by this user for this serial/model not yet returned
        $criteria = ['username'=>(string)$_SESSION['username'], 'status'=>'Borrowed'];
        if ($mid > 0) { $criteria['model_id'] = $mid; }
        $borrow = $ubCol->findOne($criteria, ['sort'=>['borrowed_at'=>-1, 'id'=>-1]]);
      }
      if (!$borrow) { echo json_encode(['success'=>false,'error'=>'Borrow record not found']); exit; }
      $bid = (int)($borrow['id'] ?? 0);

      // Mark borrow as returned
      $ubCol->updateOne(['id' => $bid], ['$set' => ['status' => 'Returned', 'returned_at' => $now]]);

      // Update inventory item to Available and set location
      if ($mid > 0) {
        $set = ['status' => 'Available', 'updated_at' => $now];
        if ($location !== '') { $set['location'] = $location; }
        $iiCol->updateOne(['id' => $mid], ['$set' => $set]);
      }

      // Update related request to Returned (if present)
      if ($reqId > 0) {
        $erCol->updateOne(['id' => $reqId, 'username' => (string)$_SESSION['username']], ['$set' => ['status' => 'Returned', 'returned_at' => $now]]);
        try { $rsCol = $mongo_db->selectCollection('returnship_requests'); $rsCol->updateMany(['request_id'=>$reqId], ['$set'=>['status'=>'Completed','completed_at'=>$now,'location'=>$location]]); } catch (Throwable $_) {}
      } else {
        // Derive request from allocation if missing
        try {
          $alloc = $raCol->findOne(['borrow_id' => $bid], ['projection'=>['request_id'=>1]]);
          $rid = (int)($alloc['request_id'] ?? 0);
          if ($rid > 0) { $erCol->updateOne(['id' => $rid], ['$set' => ['status' => 'Returned', 'returned_at' => $now]]); }
        } catch (Throwable $_a) {}
      }

      echo json_encode(['success' => true]);
    } else {
      echo json_encode(['success'=>false,'error'=>'DB unavailable']);
    }
  } catch (Throwable $e) {
    echo json_encode(['success'=>false,'error'=>'Server error']);
  }
  exit;
}

if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin') { header('Location: admin_borrow_center.php'); exit(); }

// Mongo-first connection (already initialized above)

// Fallback MySQL connection only if Mongo is not available

// JSON: user_notifications (approvals with model details, and lost/damaged marks)
if ($__act === 'user_notifications' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  $uname = $_SESSION['username'];
  if ($USED_MONGO && $mongo_db) {
    try {
      $allocCol = $mongo_db->selectCollection('request_allocations');
      $ubCol = $mongo_db->selectCollection('user_borrows');
      $erCol = $mongo_db->selectCollection('equipment_requests');
      $iiCol = $mongo_db->selectCollection('inventory_items');
      $rsCol = $mongo_db->selectCollection('returnship_requests');
      $approvals = [];
      $returnships = [];
      $decisions = [];
      $allocs = $allocCol->find([], ['sort'=>['id'=>-1], 'limit'=>300]);
      foreach ($allocs as $al) {
        $reqId = (int)($al['request_id'] ?? 0); $bid = (int)($al['borrow_id'] ?? 0);
        if ($reqId<=0 || $bid<=0) continue;
        $er = $erCol->findOne(['id'=>$reqId, 'username'=>$uname]); if (!$er) continue;
        $ub = $ubCol->findOne(['id'=>$bid]); if (!$ub) continue;
        $mid = (int)($ub['model_id'] ?? 0); $ii = $mid>0 ? $iiCol->findOne(['id'=>$mid]) : null;
        $approvals[] = [
          'alloc_id' => (int)($al['id'] ?? 0),
          'request_id' => $reqId,
          'model_id' => $mid,
          'model_name' => $ii ? (string)($ii['model'] ?? ($ii['item_name'] ?? '')) : '',
          'category' => $ii ? ((string)($ii['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized',
          'ts' => (string)($er['borrowed_at'] ?? ($er['approved_at'] ?? ($er['created_at'] ?? ''))),
        ];
      }
      $ldCol = $mongo_db->selectCollection('lost_damaged_log');
      $lostDamaged = [];
      $logs = $ldCol->find(['username'=>$uname,'action'=>['$in'=>['Lost','Under Maintenance']]], ['sort'=>['id'=>-1], 'limit'=>300]);
      foreach ($logs as $l) {
        $mid = (int)($l['model_id'] ?? 0); $ii = $mid>0 ? $iiCol->findOne(['id'=>$mid]) : null;
        $lostDamaged[] = [
          'log_id' => (int)($l['id'] ?? 0),
          'model_id' => $mid,
          'action' => (string)($l['action'] ?? ''),
          'model_name' => $ii ? (string)($ii['model'] ?? ($ii['item_name'] ?? '')) : '',
          'category' => $ii ? ((string)($ii['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized',
          'ts' => (string)($l['created_at'] ?? ''),
        ];
      }
      // Returnship requests initiated by admin for this user (pending/requested), include item status (In Use/Overdue)
      try {
        $rsCur = $rsCol->find(['username'=>$uname, 'status' => ['$in' => ['Pending','Requested']]], ['sort'=>['id'=>-1], 'limit'=>100]);
        $raCol = $mongo_db->selectCollection('request_allocations');
        foreach ($rsCur as $rs) {
          $rid = (int)($rs['request_id'] ?? 0);
          $itemStatus = 'In Use';
          if ($rid > 0) {
            try {
              $ra = $raCol->findOne(['request_id'=>$rid], ['projection'=>['borrow_id'=>1]]);
              if ($ra && isset($ra['borrow_id'])) {
                $ub = $ubCol->findOne(['id'=>(int)$ra['borrow_id']], ['projection'=>['expected_return_at'=>1,'status'=>1]]);
                if ($ub) {
                  $exp = isset($ub['expected_return_at']) ? strtotime((string)$ub['expected_return_at']) : null;
                  if ($exp && time() > $exp) { $itemStatus = 'Overdue'; } else { $itemStatus = 'In Use'; }
                }
              }
            } catch (Throwable $_j) { $itemStatus = 'In Use'; }
          }
          $returnships[] = [
            'id' => (int)($rs['id'] ?? 0),
            'request_id' => $rid,
            'model_name' => (string)($rs['model_name'] ?? ''),
            'qr_serial_no' => (string)($rs['qr_serial_no'] ?? ''),
            'status' => (string)($rs['status'] ?? ''),
            'item_status' => $itemStatus,
            'ts' => (string)($rs['verified_at'] ?? ($rs['created_at'] ?? '')),
          ];
        }
      } catch (Throwable $_rs) {}

      // Decisions: auto-assign and auto-cancel notifications
      try {
        $erUser = $erCol->find(['username'=>$uname, 'type'=>'reservation'], ['sort'=>['id'=>-1], 'limit'=>200]);
        foreach ($erUser as $erx) {
          $rid = (int)($erx['id'] ?? 0); if ($rid<=0) continue;
          $st = (string)($erx['status'] ?? '');
          // Auto-cancelled
          if ($st === 'Cancelled') {
            $msg = 'Your reservation #'.$rid.' was auto-cancelled: ' . ((string)($erx['cancelled_reason'] ?? 'Unavailable'));
            $decisions[] = [ 'id'=>$rid, 'status'=>'Cancelled', 'message'=>$msg, 'ts'=>(string)($erx['cancelled_at'] ?? ($erx['updated_at'] ?? '')) ];
          }
          // Auto-assigned serial edits under Approved
          if ($st === 'Approved' && isset($erx['edited_at']) && trim((string)$erx['edited_at'])!=='') {
            $serial = (string)($erx['reserved_serial_no'] ?? '');
            $note = (string)($erx['edit_note'] ?? '');
            $msg = $note !== '' ? ('Reservation #'.$rid.' updated: '.$note) : ('Reservation #'.$rid.' auto-assigned to ['.$serial.']');
            $decisions[] = [ 'id'=>$rid, 'status'=>'Edited', 'message'=>$msg, 'ts'=>(string)$erx['edited_at'] ];
          }
        }
      } catch (Throwable $_d) { /* ignore */ }
      // Apply user clears
      $clearedKeysOut = [];
      try {
        $clCol = $mongo_db->selectCollection('user_notif_clears');
        $clears = $clCol->find(['uname'=>$uname], ['projection'=>['key'=>1]]);
        $cleared = [];
        foreach ($clears as $c) { $k = (string)($c['key'] ?? ''); if ($k!=='') { $cleared[$k]=true; $clearedKeysOut[] = $k; } }
        if (!empty($approvals)) { $approvals = array_values(array_filter($approvals, function($a) use ($cleared){ $k='approval:'.((int)($a['alloc_id']??0)); return empty($cleared[$k]) && empty($cleared['req:'.$a['request_id']]); })); }
        if (!empty($lostDamaged)) { $lostDamaged = array_values(array_filter($lostDamaged, function($l) use ($cleared){ $k='lost:'.((int)($l['log_id']??0)); return empty($cleared[$k]); })); }
        if (!empty($returnships)) { $returnships = array_values(array_filter($returnships, function($r) use ($cleared){ $k='returnship:'.((int)($r['id']??0)); return empty($cleared[$k]); })); }
        // decisions array may be provided alongside via a joined endpoint later; keep passthrough
      } catch (Throwable $_cl) {}
      echo json_encode([
        'approvals' => $approvals,
        'lostDamaged' => $lostDamaged,
        'returnships' => $returnships,
        'decisions' => $decisions,
        'cleared_keys' => $clearedKeysOut,
      ]);
    } catch (Throwable $e) { echo json_encode(['approvals'=>[], 'lostDamaged'=>[]]); }
  } else {
    $approvals = []; $lostDamaged = [];
    if ($conn) {
      $sqlA = "SELECT ra.id AS alloc_id, er.id AS request_id, ii.id AS model_id, COALESCE(NULLIF(ii.model,''), ii.item_name) AS model_name, COALESCE(NULLIF(ii.category,''),'Uncategorized') AS category, COALESCE(er.borrowed_at, er.approved_at, er.created_at) AS ts FROM request_allocations ra JOIN user_borrows ub ON ub.id = ra.borrow_id JOIN equipment_requests er ON er.id = ra.request_id LEFT JOIN inventory_items ii ON ii.id = ub.model_id WHERE er.username = ? ORDER BY ra.id DESC LIMIT 100";
      if ($st = $conn->prepare($sqlA)) { $st->bind_param('s', $uname); if ($st->execute()) { $res = $st->get_result(); while ($r = $res->fetch_assoc()) { $approvals[] = $r; } } $st->close(); }
      $sqlL = "SELECT l.id AS log_id, l.model_id, l.action, COALESCE(NULLIF(ii.model,''), ii.item_name) AS model_name, COALESCE(NULLIF(ii.category,''),'Uncategorized') AS category, l.created_at AS ts FROM lost_damaged_log l LEFT JOIN inventory_items ii ON ii.id = l.model_id WHERE l.username = ? AND l.action IN ('Lost','Under Maintenance') ORDER BY l.id DESC LIMIT 100";
      if ($st2 = $conn->prepare($sqlL)) { $st2->bind_param('s', $uname); if ($st2->execute()) { $res2=$st2->get_result(); while($r2=$res2->fetch_assoc()){ $lostDamaged[]=$r2; } } $st2->close(); }
    }
    echo json_encode(['approvals' => $approvals, 'lostDamaged' => $lostDamaged, 'returnships' => []]);
  }
  exit;
}

// User QR return verification: user scans the QR of the item they are returning and provides a location
if ($__act === 'returnship_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $reqId = (int)($_POST['request_id'] ?? 0);
  $serial = trim((string)($_POST['serial_no'] ?? ''));
  $location = trim((string)($_POST['location'] ?? ''));
  $borrowId = (int)($_POST['borrow_id'] ?? 0);
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    if ($reqId <= 0) { $reqId = (int)($data['request_id'] ?? 0); }
    if ($serial === '') { $serial = trim((string)($data['serial_no'] ?? '')); }
    if ($location === '') { $location = trim((string)($data['location'] ?? '')); }
    if ($borrowId <= 0) { $borrowId = (int)($data['borrow_id'] ?? 0); }
  }
  if ($reqId <= 0 || $serial === '') { echo json_encode(['ok'=>false,'reason'=>'Missing parameters']); exit; }
  if ($location === '') { echo json_encode(['ok'=>false,'reason'=>'Location required']); exit; }
  try {
    if ($USED_MONGO && $mongo_db) {
      $erCol = $mongo_db->selectCollection('equipment_requests');
      $iiCol = $mongo_db->selectCollection('inventory_items');
      $ubCol = $mongo_db->selectCollection('user_borrows');
      $raCol = $mongo_db->selectCollection('request_allocations');
      $rsCol = $mongo_db->selectCollection('returnship_requests');
      $req = $erCol->findOne(['id'=>$reqId, 'username'=>(string)$_SESSION['username']]);
      if (!$req) { echo json_encode(['ok'=>false,'reason'=>'Request not found']); exit; }
      // Request must be a QR-based borrow and the serial must match the original QR
      $qrSerial = trim((string)($req['qr_serial_no'] ?? ''));
      if ($qrSerial === '' || strcasecmp($qrSerial, $serial) !== 0) { echo json_encode(['ok'=>false,'reason'=>'QR mismatch']); exit; }
      // Resolve model by serial and ensure there's an active borrow for this request
      $unit = $iiCol->findOne(['serial_no'=>$serial]);
      if (!$unit) { echo json_encode(['ok'=>false,'reason'=>'Serial not found']); exit; }
      $mid = (int)($unit['id'] ?? 0);
      // Resolve the exact borrow set for this request, optionally constrained by provided borrow_id
      $matchAlloc = ['request_id'=>$reqId];
      $allocs = iterator_to_array($raCol->find($matchAlloc, ['projection'=>['borrow_id'=>1]]));
      $borrowIds = array_values(array_filter(array_map(function($d){ return isset($d['borrow_id']) ? (int)$d['borrow_id'] : 0; }, $allocs)));
      if ($borrowId > 0) { $borrowIds = array_values(array_filter($borrowIds, function($v) use ($borrowId){ return (int)$v === $borrowId; })); }
      if (empty($borrowIds)) { echo json_encode(['ok'=>false,'reason'=>'No active borrow for request']); exit; }
      $borrow = $ubCol->findOne(['id'=>['$in'=>$borrowIds], 'status'=>'Borrowed', 'model_id'=>$mid, 'username'=>(string)$_SESSION['username']]);
      if (!$borrow) { echo json_encode(['ok'=>false,'reason'=>'Item not currently borrowed']); exit; }
      // Final strictness: ensure the borrowed unit's serial matches scanned serial exactly
      $borrowUnit = $iiCol->findOne(['id'=>(int)($borrow['model_id'] ?? 0)]);
      $borrowSerial = trim((string)($borrowUnit['serial_no'] ?? ''));
      if ($borrowSerial === '' || strcasecmp($borrowSerial, $serial) !== 0) { echo json_encode(['ok'=>false,'reason'=>'QR mismatch']); exit; }
      // Upsert returnship request with verification
      $now = date('Y-m-d H:i:s');
      $existing = $rsCol->findOne(['request_id'=>$reqId], ['sort'=>['id'=>-1]]);
      if ($existing) {
        $rsCol->updateOne(['id'=>(int)($existing['id'] ?? 0)], ['$set'=>[
          'status'=>'Requested', 'verified_at'=>$now, 'location'=>$location, 'model_id'=>$mid, 'verified_serial'=>$serial,
        ]]);
      } else {
        $last = $rsCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
        $nid = ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
        $rsCol->insertOne([
          'id'=>$nid,
          'request_id'=>$reqId,
          'username'=>(string)$_SESSION['username'],
          'model_id'=>$mid,
          'model_name'=>(string)($req['item_name'] ?? ''),
          'qr_serial_no'=>$qrSerial,
          'location'=>$location,
          'status'=>'Requested',
          'verified_at'=>$now,
          'verified_serial'=>$serial,
          'created_at'=>$now,
          'created_by'=>'user'
        ]);
      }
      echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false,'reason'=>'DB unavailable']);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'reason'=>'Server error']);
  }
  exit;
}

// JSON: my_borrowed (active borrowed items) matching user_items.php logic
if ($__act === 'my_borrowed' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 500)) : 200;
  if ($USED_MONGO && $mongo_db) {
    $rows = [];
    try {
      $ubCol = $mongo_db->selectCollection('user_borrows');
      $iiCol = $mongo_db->selectCollection('inventory_items');
      $erCol = $mongo_db->selectCollection('equipment_requests');
      $raCol = $mongo_db->selectCollection('request_allocations');
      $cur = $ubCol->find(['username'=>(string)$_SESSION['username'],'status'=>'Borrowed'], ['sort'=>['borrowed_at'=>-1,'id'=>-1], 'limit' => $limit]);
      foreach ($cur as $ub) {
        $mid = (int)($ub['model_id'] ?? 0); $ii = $mid>0 ? $iiCol->findOne(['id'=>$mid]) : null;
        $alloc = $raCol->findOne(['borrow_id' => (int)($ub['id'] ?? 0)], ['projection'=>['request_id'=>1]]);
        $reqId = (int)($alloc['request_id'] ?? 0);
        if ($reqId <= 0) {
          // Fallback: find nearest user's request
          $when = (string)($ub['borrowed_at'] ?? '');
          $req = null;
          if ($ii) {
            $cands = array_values(array_unique(array_filter([(string)($ii['model'] ?? ''),(string)($ii['item_name'] ?? '')])));
            if (!empty($cands)) {
              $req = $erCol->findOne([
                'username' => (string)$_SESSION['username'],
                'item_name' => ['$in' => $cands],
                'created_at' => ['$lte' => $when]
              ], ['sort' => ['created_at' => -1, 'id' => -1], 'projection' => ['id' => 1]]);
              if (!$req) {
                $req = $erCol->findOne([
                  'username' => (string)$_SESSION['username'],
                  'item_name' => ['$in' => $cands]
                ], ['sort' => ['created_at' => -1, 'id' => -1], 'projection' => ['id' => 1]]);
              }
            }
          }
          if (!$req) {
            // As a last resort, match by time only
            $req = $erCol->findOne([
              'username' => (string)$_SESSION['username'],
              'created_at' => ['$lte' => $when]
            ], ['sort' => ['created_at' => -1, 'id' => -1], 'projection' => ['id' => 1]]);
            if (!$req) {
              $req = $erCol->findOne([
                'username' => (string)$_SESSION['username']
              ], ['sort' => ['created_at' => -1, 'id' => -1], 'projection' => ['id' => 1]]);
            }
          }
          if ($req && isset($req['id'])) { $reqId = (int)$req['id']; }
        }
        // Ensure model name is populated (prefer model, fallback to item_name; if missing, use request.item_name)
        $dispModel = '';
        if ($ii) { $dispModel = (string)($ii['model'] ?? ''); if ($dispModel==='') { $dispModel = (string)($ii['item_name'] ?? ''); } }
        if ($dispModel === '' && $reqId > 0) {
          $reqDoc = $erCol->findOne(['id'=>$reqId], ['projection'=>['item_name'=>1]]);
          if ($reqDoc && isset($reqDoc['item_name'])) { $dispModel = (string)$reqDoc['item_name']; }
        }
        // Load request doc to get approved_at (and optionally approved_by if exists)
        $reqDoc = null; if ($reqId > 0) { $reqDoc = $erCol->findOne(['id'=>$reqId], ['projection'=>['approved_at'=>1,'approved_by'=>1,'item_name'=>1]]); }
        $approvedAt = '';
        try {
          if ($reqDoc && isset($reqDoc['approved_at']) && $reqDoc['approved_at'] instanceof MongoDB\BSON\UTCDateTime) { $dtA = $reqDoc['approved_at']->toDateTime(); $dtA->setTimezone(new DateTimeZone('Asia/Manila')); $approvedAt = $dtA->format('Y-m-d H:i:s'); }
          else if ($reqDoc && isset($reqDoc['approved_at'])) { $approvedAt = (string)$reqDoc['approved_at']; }
        } catch (Throwable $e3) { $approvedAt = (string)($reqDoc['approved_at'] ?? ''); }
        $approvedBy = $reqDoc ? (string)($reqDoc['approved_by'] ?? '') : '';
        // Determine type (QR vs Manual) from related request if available
        $reqType = '';
        if ($reqId > 0) {
          $req4 = $erCol->findOne(['id'=>$reqId], ['projection'=>['qr_serial_no'=>1]]);
          if ($req4 && isset($req4['qr_serial_no']) && trim((string)$req4['qr_serial_no'])!=='') { $reqType = 'QR'; } else { $reqType = 'Manual'; }
        }
        // Determine if a return verification is already pending for this request
        $returnPending = false;
        if ($reqId > 0) {
          try {
            $rsCol = $mongo_db->selectCollection('returnship_requests');
            $rp = $rsCol->findOne(['request_id'=>$reqId, 'status'=>'Requested'], ['projection'=>['id'=>1]]);
            $returnPending = $rp ? true : false;
          } catch (Throwable $e_rp) { $returnPending = false; }
        }
        $rows[] = [
          'borrow_id' => (int)($ub['id'] ?? 0),
          'request_id' => $reqId,
          'borrowed_at' => (isset($ub['borrowed_at']) && $ub['borrowed_at'] instanceof MongoDB\BSON\UTCDateTime ? (function($x){ $dt=$x->toDateTime(); $dt->setTimezone(new DateTimeZone('Asia/Manila')); return $dt->format('Y-m-d H:i:s'); })($ub['borrowed_at']) : (string)($ub['borrowed_at'] ?? '')),
          'approved_at' => $approvedAt,
          'approved_by' => $approvedBy,
          'model_id' => $mid,
          'item_name' => ($dispModel !== '' ? $dispModel : ''),
          'model' => ($dispModel !== '' ? $dispModel : ''),
          'model_display' => ($dispModel !== '' ? $dispModel : ''),
          'category' => ($ii ? (string)($ii['category'] ?? '') : 'Uncategorized'),
          'condition' => ($ii ? (string)($ii['condition'] ?? '') : ''),
          'type' => $reqType,
          'return_pending' => $returnPending,
        ];
      }
    } catch (Throwable $e) { $rows = []; }
    echo json_encode(['borrowed'=>$rows]);
  } else {
    $rows = [];
    if ($conn && ($st = $conn->prepare("SELECT 
      ub.id AS borrow_id,
      COALESCE(ra.request_id, (
        SELECT er2.id FROM equipment_requests er2
        WHERE er2.username = ub.username
          AND (er2.item_name = COALESCE(NULLIF(ii.model,''), ii.item_name) OR er2.item_name = ii.item_name)
        ORDER BY ABS(TIMESTAMPDIFF(SECOND, er2.created_at, ub.borrowed_at)) ASC, er2.id DESC
        LIMIT 1
      )) AS request_id,
      (SELECT er3.approved_at FROM equipment_requests er3 WHERE er3.id = COALESCE(ra.request_id, (
        SELECT er2.id FROM equipment_requests er2
        WHERE er2.username = ub.username
          AND (er2.item_name = COALESCE(NULLIF(ii.model,''), ii.item_name) OR er2.item_name = ii.item_name)
        ORDER BY ABS(TIMESTAMPDIFF(SECOND, er2.created_at, ub.borrowed_at)) ASC, er2.id DESC
        LIMIT 1
      )) LIMIT 1) AS approved_at,
      ub.borrowed_at,
      ii.id AS model_id, ii.item_name, ii.model, ii.category, ii.`condition`
    FROM user_borrows ub
    JOIN inventory_items ii ON ii.id = ub.model_id
    LEFT JOIN request_allocations ra ON ra.borrow_id = ub.id
    WHERE ub.username = ? AND ub.status = 'Borrowed'
    ORDER BY ub.borrowed_at DESC, ub.id DESC
    LIMIT ?"))) {
      $st->bind_param('si', $_SESSION['username'], $limit); if ($st->execute()) { $res=$st->get_result(); while($r=$res->fetch_assoc()){ $rows[]=$r; } } $st->close();
    }
    echo json_encode(['borrowed'=>$rows]);
  }
  exit;
}

// (Removed my_history JSON endpoint as Borrow History is no longer shown here)
// JSON: my_history (borrow history) for this user
if ($__act === 'my_history' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  if ($USED_MONGO && $mongo_db) {
    $rows = [];
    try {
      $ubCol=$mongo_db->selectCollection('user_borrows'); $iiCol=$mongo_db->selectCollection('inventory_items'); $ldCol=$mongo_db->selectCollection('lost_damaged_log'); $erCol=$mongo_db->selectCollection('equipment_requests');
      $cur = $ubCol->find(['username'=>(string)$_SESSION['username'],'status'=>['$ne'=>'Borrowed']], ['sort'=>['borrowed_at'=>-1,'id'=>-1], 'limit'=>200]);
      foreach ($cur as $ub) {
        $mid=(int)($ub['model_id']??0); $ii = $mid>0 ? $iiCol->findOne(['id'=>$mid]) : null;
        $log = $ldCol->findOne(['model_id'=>$mid, 'created_at'=>['$gte'=>(string)($ub['borrowed_at'] ?? '')]], ['sort'=>['id'=>-1]]);
        $req = $erCol->findOne(['username'=>(string)$_SESSION['username'],'item_name'=>['$in'=>array_values(array_unique(array_filter([(string)($ii['model'] ?? ''),(string)($ii['item_name'] ?? '')])))]], ['sort'=>['created_at'=>-1,'id'=>-1], 'projection'=>['id'=>1]]);
        $rows[] = [
          'borrow_id' => (int)($ub['id'] ?? 0),
          'request_id' => (int)($req['id'] ?? 0),
          'borrowed_at' => (isset($ub['borrowed_at']) && $ub['borrowed_at'] instanceof MongoDB\BSON\UTCDateTime ? (function($x){ $dt=$x->toDateTime(); $dt->setTimezone(new DateTimeZone('Asia/Manila')); return $dt->format('Y-m-d H:i:s'); })($ub['borrowed_at']) : (string)($ub['borrowed_at'] ?? '')),
          'returned_at' => (isset($ub['returned_at']) && $ub['returned_at'] instanceof MongoDB\BSON\UTCDateTime ? (function($x){ $dt=$x->toDateTime(); $dt->setTimezone(new DateTimeZone('Asia/Manila')); return $dt->format('Y-m-d H:i:s'); })($ub['returned_at']) : (string)($ub['returned_at'] ?? '')),
          'status' => (string)($ub['status'] ?? ''),
          'latest_action' => (string)($log['action'] ?? ''),
          'model_id' => $mid,
          'item_name' => $ii ? (string)($ii['item_name'] ?? '') : '',
          'model' => $ii ? (string)($ii['model'] ?? '') : '',
          'category' => $ii ? (string)($ii['category'] ?? '') : '',
        ];
      }
    } catch (Throwable $e) { $rows=[]; }
    echo json_encode(['history'=>$rows]);
  } else {
    $rows = [];
    $sql = "SELECT 
            ub.id AS borrow_id,
            (
              SELECT er2.id FROM equipment_requests er2
              WHERE er2.username = ub.username
                AND (er2.item_name = COALESCE(NULLIF(ii.model,''), ii.item_name) OR er2.item_name = ii.item_name)
              ORDER BY ABS(TIMESTAMPDIFF(SECOND, er2.created_at, ub.borrowed_at)) ASC, er2.id DESC
              LIMIT 1
            ) AS request_id,
            ub.borrowed_at, ub.returned_at, ub.status,
            (
              SELECT l.action
              FROM lost_damaged_log l
              WHERE l.model_id = ub.model_id
                AND l.created_at >= ub.borrowed_at
              ORDER BY l.id DESC
              LIMIT 1
            ) AS latest_action,
            ii.id AS model_id, ii.item_name, ii.model, ii.category
          FROM user_borrows ub
          LEFT JOIN inventory_items ii ON ii.id = ub.model_id
          WHERE ub.username = ? AND ub.status <> 'Borrowed'
          ORDER BY ub.borrowed_at DESC, ub.id DESC LIMIT 200";
    if ($conn && ($st = $conn->prepare($sql))) { $st->bind_param('s', $_SESSION['username']); if ($st->execute()) { $res=$st->get_result(); while($r=$res->fetch_assoc()){ $rows[]=$r; } } $st->close(); }
    echo json_encode(['history'=>$rows]);
  }
  exit;
}

// JSON: availability for a given model (normalized by model/item_name)
if ($__act === 'avail' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  $model = trim((string)($_GET['model'] ?? '')); $available = 0; $category = 'Uncategorized';
  $for = isset($_GET['for']) ? (string)$_GET['for'] : '';
  if ($USED_MONGO && $mongo_db) {
    try {
      if ($model !== '') {
        $ii = $mongo_db->selectCollection('inventory_items');
        $bm = $mongo_db->selectCollection('borrowable_catalog');
        $doc = $ii->findOne(['$or'=>[['model'=>$model],['item_name'=>$model]]], ['sort'=>['id'=>-1], 'projection'=>['model'=>1,'item_name'=>1,'category'=>1]]);
        $mkey = $doc ? (string)($doc['model'] ?? ($doc['item_name'] ?? $model)) : $model;
        $category = $doc ? ((string)($doc['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized';
        // Borrow limit (cap)
        $bmDoc = $bm->findOne(
          ['active'=>1,'model_name'=>$mkey,'category'=>$category],
          ['projection'=>['borrow_limit'=>1], 'collation'=>['locale'=>'en','strength'=>2]]
        );
        $limit = $bmDoc && isset($bmDoc['borrow_limit']) ? (int)$bmDoc['borrow_limit'] : 0;

        if ($for === 'reservation') {
          // Reservation: include in-use units; exclude items with status Lost/Under Maintenance
          $total = 0; $agg = $ii->aggregate([
            ['$match'=>['$or'=>[['model'=>$mkey],['item_name'=>$mkey]], 'category'=>$category, 'status'=>['$nin'=>['Lost','Under Maintenance']]]],
            ['$project'=>['q'=>['$ifNull'=>['$quantity',1]]]],
            ['$group'=>['_id'=>null,'sum'=>['$sum'=>'$q']]]
          ]); foreach ($agg as $r){ $total = (int)($r->sum ?? 0); break; }
          $base = max(0, $total);
          $available = ($limit > 0) ? max(0, min($limit, $base)) : $base;
        } else {
          // Immediate: only currently Available units
          $availNow = (int)$ii->countDocuments([
            'status'=>'Available',
            'quantity'=>['$gt'=>0],
            '$or'=>[['model'=>$mkey],['item_name'=>$mkey]],
            'category'=>$category
          ]);
          $available = ($limit > 0) ? max(0, min($limit, $availNow)) : max(0, $availNow);
        }
      }
    } catch (Throwable $e) { $available = 0; }
    echo json_encode(['available'=>(int)$available]);
  } else {
    if ($conn && $model !== '') {
      if ($st = $conn->prepare("SELECT COALESCE(NULLIF(model,''), item_name) AS m, COALESCE(NULLIF(category,''),'Uncategorized') AS c FROM inventory_items WHERE (model=? OR item_name=?) ORDER BY id DESC LIMIT 1")) { $st->bind_param('ss', $model, $model); $st->execute(); $st->bind_result($m,$c); if ($st->fetch()){ $model=(string)$m; $category=(string)$c; } $st->close(); }
      $limit = 0; if ($q2 = $conn->prepare("SELECT borrow_limit FROM borrowable_models WHERE active=1 AND LOWER(TRIM(model_name))=LOWER(TRIM(?)) AND LOWER(TRIM(category))=LOWER(TRIM(?))")) { $q2->bind_param('ss', $model, $category); $q2->execute(); $q2->bind_result($limit); $q2->fetch(); $q2->close(); }
      if ($for === 'reservation') {
        // Reservation: include in-use; exclude items with status Lost/Under Maintenance
        $sum = 0; if ($q = $conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_items WHERE LOWER(TRIM(COALESCE(NULLIF(model,''), item_name)))=LOWER(TRIM(?)) AND LOWER(TRIM(COALESCE(NULLIF(category,''),'Uncategorized')))=LOWER(TRIM(?)) AND (status <> 'Lost' AND status <> 'Under Maintenance')")) { $q->bind_param('ss', $model, $category); $q->execute(); $q->bind_result($sum); $q->fetch(); $q->close(); }
        $base = max(0, (int)$sum);
        $available = ($limit > 0) ? max(0, min((int)$limit, $base)) : $base;
      } else {
        // Immediate: only currently Available units
        $availNow = 0; if ($q = $conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_items WHERE LOWER(TRIM(COALESCE(NULLIF(model,''), item_name)))=LOWER(TRIM(?)) AND LOWER(TRIM(COALESCE(NULLIF(category,''),'Uncategorized')))=LOWER(TRIM(?)) AND status='Available' AND quantity>0")) { $q->bind_param('ss', $model, $category); $q->execute(); $q->bind_result($availNow); $q->fetch(); $q->close(); }
        $available = max(0, min((int)$limit, (int)$availNow));
      }
    }
    echo json_encode(['available'=>(int)$available]);
  }
  exit;
}

// JSON: single quantity timing hint (cutoff before upcoming reservation)
if ($__act === 'single_qty_hint' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  $modelIn = trim((string)($_GET['model'] ?? ''));
  $sidIn = trim((string)($_GET['sid'] ?? ''));
  try {
    $model = $modelIn;
    if ($USED_MONGO && $mongo_db) {
      $ii = $mongo_db->selectCollection('inventory_items');
      if ($model === '' && $sidIn !== '') {
        $u = $ii->findOne(['serial_no'=>$sidIn], ['projection'=>['model'=>1,'item_name'=>1]]);
        if ($u) { $model = (string)($u['model'] ?? ($u['item_name'] ?? '')); }
      }
      if ($model === '') { echo json_encode(['ok'=>false]); exit; }
      // total units for this model
      $agg = $ii->aggregate([
        ['$match'=>['$or'=>[['model'=>$model],['item_name'=>$model]]]],
        ['$project'=>['q'=>['$ifNull'=>['$quantity',1]]]],
        ['$group'=>['_id'=>null,'sum'=>['$sum'=>'$q']]]
      ]);
      $sum = 0; foreach ($agg as $r){ $sum = (int)($r->sum ?? 0); break; }
      $single = ($sum <= 1);
      $er = $mongo_db->selectCollection('equipment_requests');
      $up = $er->findOne(['item_name'=>$model,'type'=>'reservation','status'=>'Approved','reserved_from'=>['$gt'=>date('Y-m-d H:i:s')]], ['sort'=>['reserved_from'=>1], 'projection'=>['reserved_from'=>1]]);
      if ($up) {
        $rf = (string)($up['reserved_from'] ?? '');
        $ts = strtotime($rf); $cutoff = $ts ? date('Y-m-d H:i:s', $ts - 5*60) : '';
        echo json_encode(['ok'=>true,'single'=>$single,'hasUpcoming'=>true,'reserve_from'=>$rf,'cutoff'=>$cutoff]); exit;
      }
      echo json_encode(['ok'=>true,'single'=>$single,'hasUpcoming'=>false]); exit;
    } elseif ($conn) {
      if ($model === '') { echo json_encode(['ok'=>false]); exit; }
      $sum = 0; if ($st = $conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_items WHERE LOWER(TRIM(COALESCE(NULLIF(model,''), item_name)))=LOWER(TRIM(?))")) { $st->bind_param('s',$model); $st->execute(); $st->bind_result($sum); $st->fetch(); $st->close(); }
      $single = ((int)$sum <= 1);
      $rf = null; if ($st2 = $conn->prepare("SELECT MIN(reserved_from) FROM equipment_requests WHERE type='reservation' AND status='Approved' AND LOWER(TRIM(item_name))=LOWER(TRIM(?)) AND reserved_from > NOW()")) { $st2->bind_param('s',$model); $st2->execute(); $st2->bind_result($rf); $st2->fetch(); $st2->close(); }
      if ($rf) { $ts = strtotime((string)$rf); $cutoff = $ts ? date('Y-m-d H:i:s', $ts - 5*60) : ''; echo json_encode(['ok'=>true,'single'=>$single,'hasUpcoming'=>true,'reserve_from'=>(string)$rf,'cutoff'=>$cutoff]); exit; }
      echo json_encode(['ok'=>true,'single'=>$single,'hasUpcoming'=>false]); exit;
    }
  } catch (Throwable $_) { echo json_encode(['ok'=>false]); exit; }
}

// JSON: live catalog for categories, models, and availability
if ($__act === 'catalog' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  if ($USED_MONGO && $mongo_db) {
    try {
      $ii = $mongo_db->selectCollection('inventory_items');
      $bm = $mongo_db->selectCollection('borrowable_catalog');
      $for = isset($_GET['for']) ? (string)$_GET['for'] : '';
      // Auto-cancel overdue single-quantity reservations when current borrow is late
      try {
        $erCol = $mongo_db->selectCollection('equipment_requests');
        $ubCol = $mongo_db->selectCollection('user_borrows');
        $nowStr = date('Y-m-d H:i:s');
        $cur = $erCol->find(['type'=>'reservation','status'=>'Approved','reserved_from'=>['$lte'=>$nowStr]], ['projection'=>['id'=>1,'item_name'=>1]]);
        foreach ($cur as $row) {
          $mn = (string)($row['item_name'] ?? ''); if ($mn==='') continue;
          $aggTu = $ii->aggregate([
            ['$match'=>['$or'=>[['model'=>$mn],['item_name'=>$mn]]]],
            ['$project'=>['q'=>['$ifNull'=>['$quantity',1]]]],
            ['$group'=>['_id'=>null,'sum'=>['$sum'=>'$q']]]
          ]);
          $sum = 0; foreach ($aggTu as $r2){ $sum = (int)($r2->sum ?? 0); break; }
          if ($sum <= 1) {
            $unit = $ii->findOne(['$or'=>[['model'=>$mn],['item_name'=>$mn]]], ['sort'=>['id'=>1], 'projection'=>['id'=>1]]);
            if ($unit) {
              $bid = $ubCol->findOne(['model_id'=>(int)($unit['id'] ?? 0),'status'=>'Borrowed'], ['projection'=>['expected_return_at'=>1]]);
              if ($bid) {
                $exp = strtotime((string)($bid['expected_return_at'] ?? ''));
                if ($exp && time() > $exp) { $erCol->updateOne(['id'=>(int)($row['id'] ?? 0), 'status'=>'Approved'], ['$set'=>['status'=>'Cancelled','cancelled_at'=>$nowStr,'cancelled_by'=>'system']]); }
              }
            }
          }
        }
      } catch (Throwable $_) {}
      // Build borrow limit map for active models
      $borrowLimitMap = [];
      $bmCur = $bm->find(['active'=>1], ['projection'=>['model_name'=>1,'category'=>1,'borrow_limit'=>1]]);
      foreach ($bmCur as $b) {
        $cat = trim((string)($b['category'] ?? '')) ?: 'Uncategorized';
        $mod = trim((string)($b['model_name'] ?? ''));
        if ($mod==='') continue;
        if (!isset($borrowLimitMap[$cat])) $borrowLimitMap[$cat]=[];
        $borrowLimitMap[$cat][$mod] = (int)($b['borrow_limit'] ?? 0);
      }
      // Available counts by (category, model)
      $availCountsLive = [];
      $aggAvail = $ii->aggregate([
        ['$match'=>['status'=>'Available','quantity'=>['$gt'=>0]]],
        ['$project'=>[
          'category'=>['$ifNull'=>['$category','Uncategorized']],
          'model_key'=>['$ifNull'=>['$model','$item_name']],
          'q'=>['$ifNull'=>['$quantity',1]]
        ]],
        ['$group'=>['_id'=>['c'=>'$category','m'=>'$model_key'], 'avail'=>['$sum'=>'$q']]],
      ]);
      foreach ($aggAvail as $r) { $c=(string)($r->_id['c']??'Uncategorized'); $m=(string)($r->_id['m']??''); if ($m==='') continue; if (!isset($availCountsLive[$c])) $availCountsLive[$c]=[]; $availCountsLive[$c][$m]=(int)($r->avail??0); }
      // Consumed counts: active borrows + pending returned_queue + returned_hold
      $consumed = [];
      try {
        $ubCol = $mongo_db->selectCollection('user_borrows');
        $aggUb = $ubCol->aggregate([
          ['$match'=>['status'=>'Borrowed']],
          ['$lookup'=>['from'=>'inventory_items','localField'=>'model_id','foreignField'=>'id','as'=>'item']],
          ['$unwind'=>'$item'],
          ['$project'=>['c'=>['$ifNull'=>['$item.category','Uncategorized']], 'm'=>['$ifNull'=>['$item.model','$item.item_name']]]],
          ['$group'=>['_id'=>['c'=>'$c','m'=>'$m'],'cnt'=>['$sum'=>1]]]
        ]);
        foreach ($aggUb as $r) { $c=(string)($r->_id['c']??'Uncategorized'); $m=(string)($r->_id['m']??''); if ($m==='') continue; if (!isset($consumed[$c])) $consumed[$c]=[]; $consumed[$c][$m] = (int)($r->cnt??0) + (int)($consumed[$c][$m]??0); }
      } catch (Throwable $_) {}
      try {
        $rqCol = $mongo_db->selectCollection('returned_queue');
        $aggRq = $rqCol->aggregate([
          ['$match'=>['processed_at'=>['$exists'=>false]]],
          ['$lookup'=>['from'=>'inventory_items','localField'=>'model_id','foreignField'=>'id','as'=>'item']],
          ['$unwind'=>'$item'],
          ['$project'=>['c'=>['$ifNull'=>['$item.category','Uncategorized']], 'm'=>['$ifNull'=>['$item.model','$item.item_name']]]],
          ['$group'=>['_id'=>['c'=>'$c','m'=>'$m'],'cnt'=>['$sum'=>1]]]
        ]);
        foreach ($aggRq as $r) { $c=(string)($r->_id['c']??'Uncategorized'); $m=(string)($r->_id['m']??''); if ($m==='') continue; if (!isset($consumed[$c])) $consumed[$c]=[]; $consumed[$c][$m] = (int)($r->cnt??0) + (int)($consumed[$c][$m]??0); }
      } catch (Throwable $_) {}
      try {
        $rhCol = $mongo_db->selectCollection('returned_hold');
        $aggRh = $rhCol->aggregate([
          ['$project'=>['c'=>['$ifNull'=>['$category','Uncategorized']], 'm'=>['$ifNull'=>['$model_name','']], 'one'=>['$literal'=>1]]],
          ['$group'=>['_id'=>['c'=>'$c','m'=>'$m'],'cnt'=>['$sum'=>'$one']]]
        ]);
        foreach ($aggRh as $r) { $c=(string)($r->_id['c']??'Uncategorized'); $m=(string)($r->_id['m']??''); if ($m==='') continue; if (!isset($consumed[$c])) $consumed[$c]=[]; $consumed[$c][$m] = (int)($r->cnt??0) + (int)($consumed[$c][$m]??0); }
      } catch (Throwable $_) {}
      // Build capacity-constrained availability and category map
      $availableMapLive = [];
      $catOptionsLive = [];
      $modelMaxMapLive = [];
      foreach ($borrowLimitMap as $cat => $mods) {
        foreach ($mods as $mod => $limit) {
          $avail = (int)($availCountsLive[$cat][$mod] ?? 0);
          $cons = (int)($consumed[$cat][$mod] ?? 0);
          $cap = max(0, min($limit - $cons, $avail));
          if ($cap > 0) {
            if (!isset($availableMapLive[$cat])) { $availableMapLive[$cat] = []; $catOptionsLive[] = $cat; }
            $availableMapLive[$cat][mb_strtolower($mod)] = $mod;
          }
          $modelMaxMapLive[$mod] = $cap;
        }
      }
      // For reservation: include single-quantity models even if currently In Use (not Available)
      if ($for === 'reservation') {
        try {
          foreach ($borrowLimitMap as $cat => $mods) {
            foreach ($mods as $mod => $limit) {
              if (isset($availableMapLive[$cat]) && isset($availableMapLive[$cat][mb_strtolower($mod)])) continue;
              $aggTu = $ii->aggregate([
                ['$match'=>['$or'=>[['model'=>$mod],['item_name'=>$mod]], 'category'=>$cat]],
                ['$project'=>['q'=>['$ifNull'=>['$quantity',1]]]],
                ['$group'=>['_id'=>null,'sum'=>['$sum'=>'$q']]]
              ]);
              $sum = 0; foreach ($aggTu as $rr){ $sum = (int)($rr->sum ?? 0); break; }
              if ($sum <= 1) {
                if (!isset($availableMapLive[$cat])) { $availableMapLive[$cat] = []; $catOptionsLive[] = $cat; }
                $availableMapLive[$cat][mb_strtolower($mod)] = $mod;
                if (!isset($modelMaxMapLive[$mod])) { $modelMaxMapLive[$mod] = 1; }
              }
            }
          }
        } catch (Throwable $_) {}
      }
      if (!empty($catOptionsLive)) { natcasesort($catOptionsLive); $catOptionsLive = array_values(array_unique($catOptionsLive)); }
      echo json_encode(['categories'=>$catOptionsLive,'catModelMap'=>array_map(function($mods){ $vals=array_values($mods); natcasesort($vals); return array_values(array_unique($vals)); }, $availableMapLive),'modelMaxMap'=>$modelMaxMapLive]);
    } catch (Throwable $e) { echo json_encode(['categories'=>[], 'catModelMap'=>[], 'modelMaxMap'=>[]]); }
  } else {
    $availableMapLive = []; $catOptionsLive = [];
    if ($conn) {
      $for = isset($_GET['for']) ? (string)$_GET['for'] : '';
      // Borrow limit map (active only)
      $borrowLimitMap = [];
      if ($bl = $conn->query("SELECT category, model_name, borrow_limit FROM borrowable_models WHERE active=1")) {
        while ($r=$bl->fetch_assoc()) { $c=(string)$r['category']; $m=(string)$r['model_name']; if ($c==='') $c='Uncategorized'; if ($m==='') continue; if (!isset($borrowLimitMap[$c])) $borrowLimitMap[$c]=[]; $borrowLimitMap[$c][$m]=(int)($r['borrow_limit']??0); }
        $bl->close();
      }
      // Available counts
      $availCountsLive = [];
      if ($ra = $conn->query("SELECT COALESCE(NULLIF(category,''),'Uncategorized') AS category, COALESCE(NULLIF(model,''), item_name) AS model_name, SUM(quantity) AS avail FROM inventory_items WHERE status='Available' AND quantity > 0 GROUP BY category, model_name")) { while ($r=$ra->fetch_assoc()) { $c=(string)$r['category']; $m=(string)$r['model_name']; if (!isset($availCountsLive[$c])) { $availCountsLive[$c]=[]; } $availCountsLive[$c][$m]=(int)$r['avail']; } $ra->close(); }
      // Build constrained lists
      $modelMaxMapLive = [];
      foreach ($borrowLimitMap as $c => $mods) {
        foreach ($mods as $m => $limit) {
        $avail = (int)($availCountsLive[$c][$m] ?? 0);
        $cap = max(0, min((int)$limit, $avail));
        if ($cap > 0) { if (!isset($availableMapLive[$c])) { $availableMapLive[$c]=[]; $catOptionsLive[]=$c; } $availableMapLive[$c][mb_strtolower($m)]=$m; }
        $modelMaxMapLive[$m] = $cap;
      }
    }
      // For reservation: include single-quantity models even if currently In Use (not Available)
      if ($for === 'reservation') {
        foreach ($borrowLimitMap as $c => $mods) {
          foreach ($mods as $m => $limit) {
            if (isset($availableMapLive[$c]) && isset($availableMapLive[$c][mb_strtolower($m)])) continue;
            // Total units for this category+model
            $sum = 0; if ($st = $conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_items WHERE LOWER(TRIM(COALESCE(NULLIF(model,''), item_name)))=LOWER(TRIM(?)) AND LOWER(TRIM(COALESCE(NULLIF(category,''),'Uncategorized')))=LOWER(TRIM(?))")) { $st->bind_param('ss', $m, $c); $st->execute(); $st->bind_result($sum); $st->fetch(); $st->close(); }
            if ((int)$sum <= 1) {
              if (!isset($availableMapLive[$c])) { $availableMapLive[$c]=[]; $catOptionsLive[]=$c; }
              $availableMapLive[$c][mb_strtolower($m)] = $m;
              if (!isset($modelMaxMapLive[$m]) || (int)$modelMaxMapLive[$m] < 1) { $modelMaxMapLive[$m] = 1; }
            }
          }
        }
      }
    }
    if (!empty($catOptionsLive)) { natcasesort($catOptionsLive); $catOptionsLive = array_values(array_unique($catOptionsLive)); }
    echo json_encode(['categories'=>$catOptionsLive,'catModelMap'=>array_map(function($mods){ $vals=array_values($mods); natcasesort($vals); return array_values(array_unique($vals)); }, $availableMapLive),'modelMaxMap'=>$modelMaxMapLive]);
  }
  exit;
}

// JSON: my requests with live allocation counts
if ($__act === 'my_requests_status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  if ($USED_MONGO && $mongo_db) {
    $list = [];
    try {
      $erCol = $mongo_db->selectCollection('equipment_requests');
      $raCol = $mongo_db->selectCollection('request_allocations');
      $cur = $erCol->find(['username' => (string)$_SESSION['username']], ['sort' => ['created_at' => -1, 'id' => -1], 'limit' => 50]);
      $ids = [];
      foreach ($cur as $r) {
        // Build 12-hour Asia/Manila display time for created_at
        $createdLocal = '';
        try {
          if (isset($r['created_at']) && $r['created_at'] instanceof MongoDB\BSON\UTCDateTime) {
            $dt = $r['created_at']->toDateTime();
            $dt->setTimezone(new DateTimeZone('Asia/Manila'));
          } else {
            $raw = (string)($r['created_at'] ?? '');
            // Strings are stored as local; parse without shifting
            $dt = $raw !== '' ? new DateTime($raw, new DateTimeZone('Asia/Manila')) : new DateTime('now', new DateTimeZone('Asia/Manila'));
          }
          $createdLocal = $dt->format('h:i A m-d-y');
        } catch (Throwable $e2) { $createdLocal = (string)($r['created_at'] ?? ''); }
        $row = [
          'id' => (int)($r['id'] ?? 0),
          'item_name' => (string)($r['item_name'] ?? ''),
          'quantity' => (int)($r['quantity'] ?? 1),
          'status' => (string)($r['status'] ?? ''),
          'created_at' => (string)($r['created_at'] ?? ''),
          'created_at_display' => $createdLocal,
          'approved_at' => (isset($r['approved_at']) && $r['approved_at'] instanceof MongoDB\BSON\UTCDateTime ? (function($x){ $dt=$x->toDateTime(); $dt->setTimezone(new DateTimeZone('Asia/Manila')); return $dt->format('Y-m-d H:i:s'); })($r['approved_at']) : (string)($r['approved_at'] ?? '')),
          'approved_by' => (string)($r['approved_by'] ?? ''),
          'rejected_at' => (string)($r['rejected_at'] ?? ''),
          'rejected_by' => (string)($r['rejected_by'] ?? ''),
          'rejected_reason' => (string)($r['rejected_reason'] ?? ''),
          'cancelled_at' => (string)($r['cancelled_at'] ?? ''),
          'cancelled_by' => (string)($r['cancelled_by'] ?? ''),
          'cancelled_reason' => (string)($r['cancelled_reason'] ?? ''),
          'borrowed_at' => (isset($r['borrowed_at']) && $r['borrowed_at'] instanceof MongoDB\BSON\UTCDateTime ? (function($x){ $dt=$x->toDateTime(); $dt->setTimezone(new DateTimeZone('Asia/Manila')); return $dt->format('Y-m-d H:i:s'); })($r['borrowed_at']) : (string)($r['borrowed_at'] ?? '')),
          'returned_at' => (isset($r['returned_at']) && $r['returned_at'] instanceof MongoDB\BSON\UTCDateTime ? (function($x){ $dt=$x->toDateTime(); $dt->setTimezone(new DateTimeZone('Asia/Manila')); return $dt->format('Y-m-d H:i:s'); })($r['returned_at']) : (string)($r['returned_at'] ?? '')),
          'reserved_model_id' => (int)($r['reserved_model_id'] ?? 0),
          'reserved_serial_no' => (string)($r['reserved_serial_no'] ?? ''),
          'edited_at' => (string)($r['edited_at'] ?? ''),
          'edit_note' => (string)($r['edit_note'] ?? ''),
        ];
        $list[] = $row; $ids[] = $row['id'];
      }
      $alloc = [];
      if (!empty($ids)) {
        foreach ($ids as $rid) { $alloc[$rid] = (int)$raCol->countDocuments(['request_id' => $rid]); }
      }
      foreach ($list as &$r) { $rid = (int)$r['id']; $r['allocations'] = (int)($alloc[$rid] ?? 0); }
      unset($r);
    } catch (Throwable $e) {
      $list = [];
    }
    echo json_encode(['requests' => $list]);
  } else {
    $list = []; $alloc = [];
    if ($conn && ($ps = $conn->prepare("SELECT id, item_name, quantity, status, created_at, approved_at, approved_by, rejected_at, rejected_by, rejected_reason, borrowed_at, returned_at FROM equipment_requests WHERE username=? ORDER BY created_at DESC, id DESC LIMIT 50"))) {
      $ps->bind_param('s', $_SESSION['username']);
      if ($ps->execute()) {
        $res = $ps->get_result();
        while ($row = $res->fetch_assoc()) { $list[] = $row; }
      }
      $ps->close();
      if (!empty($list)) {
        $ids = array_map(fn($r) => (int)$r['id'], $list);
        $ids = array_filter($ids, fn($v) => $v > 0);
        if (!empty($ids)) {
          $in = implode(',', array_map('intval', $ids));
          if ($qr = $conn->query("SELECT request_id, COUNT(*) AS c FROM request_allocations WHERE request_id IN ($in) GROUP BY request_id")) {
            while ($ar = $qr->fetch_assoc()) { $alloc[(int)$ar['request_id']] = (int)$ar['c']; }
            $qr->close();
          }
        }
        foreach ($list as &$r) { $rid = (int)$r['id']; $r['allocations'] = (int)($alloc[$rid] ?? 0); }
        unset($r);
      }
    }
    echo json_encode(['requests' => $list]);
  }
  exit;
}

// QR validate handler (must run after DB connection)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'validate_qr') {
  header('Content-Type: application/json');
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true) ?: [];
  $modelId = (int)($data['model_id'] ?? 0);
  $modelNameIn = trim((string)($data['model'] ?? $data['item_name'] ?? ''));
  $categoryIn = trim((string)($data['category'] ?? ''));
  $qrSerialIn = trim((string)($data['qr_serial_no'] ?? ''));
  $modelName = $modelNameIn; $catNorm = ($categoryIn !== '' ? $categoryIn : 'Uncategorized');
  try {
    if ($USED_MONGO && $mongo_db) {
      // Normalize using Mongo inventory_items (prefer by id)
      $iiCol = $mongo_db->selectCollection('inventory_items');
      if ($modelId > 0) {
        $doc = $iiCol->findOne(['id' => $modelId], ['projection' => ['model'=>1,'item_name'=>1,'category'=>1]]);
        if ($doc) {
          $modelName = (string)($doc['model'] ?? ($doc['item_name'] ?? $modelName));
          $catNorm = (string)(($doc['category'] ?? '') ?: 'Uncategorized');
        }
      } elseif ($modelNameIn !== '') {
        $doc = $iiCol->findOne(['$or' => [['model'=>$modelNameIn], ['item_name'=>$modelNameIn]]], ['sort'=>['id'=>-1], 'projection'=>['model'=>1,'item_name'=>1,'category'=>1]]);
        if ($doc) {
          $modelName = (string)($doc['model'] ?? ($doc['item_name'] ?? $modelName));
          $catNorm = (string)(($doc['category'] ?? '') ?: 'Uncategorized');
        }
      }
      if ($qrSerialIn !== '') {
        // If a serial is provided, resolve exact unit and prefer its model/category
        $unit = $iiCol->findOne(['serial_no' => $qrSerialIn], ['projection'=>['id'=>1,'status'=>1,'model'=>1,'item_name'=>1,'category'=>1]]);
        if ($unit) {
          $modelId = (int)($unit['id'] ?? $modelId);
          $modelName = (string)($unit['model'] ?? ($unit['item_name'] ?? $modelName));
          $catNorm = (string)(($unit['category'] ?? '') ?: $catNorm);
        }
      }
      if ($modelName === '') { echo json_encode(['allowed'=>false,'reason'=>'Invalid QR','model_id'=>$modelId,'model'=>'','category'=>$catNorm]); exit; }
      // Check borrowable catalog
      $bmCol = $mongo_db->selectCollection('borrowable_catalog');
      $isBorrowable = (int)$bmCol->countDocuments(['active'=>1,'model_name'=>$modelName,'category'=>$catNorm]) > 0;
      if (!$isBorrowable) { echo json_encode(['allowed'=>false,'reason'=>'Item is not Available','model'=>$modelName,'category'=>$catNorm]); exit; }
      // Enforce serial whitelist (borrowable_units)
      $buCol = $mongo_db->selectCollection('borrowable_units');
      if ($qrSerialIn !== '') {
        // Serial provided: require that exact unit is whitelisted and Available
        $unit = $iiCol->findOne(['serial_no'=>$qrSerialIn], ['projection'=>['id'=>1,'status'=>1]]);
        if (!$unit) { echo json_encode(['allowed'=>false,'reason'=>'Invalid QR','model_id'=>$modelId,'model'=>$modelName,'category'=>$catNorm]); exit; }
        $uid = (int)($unit['id'] ?? 0);
        $whitelisted = (int)$buCol->countDocuments(['model_id' => ['$in' => [$uid, (string)$uid]]]) > 0;
        if (!$whitelisted) { echo json_encode(['allowed'=>false,'reason'=>'Item is not Available','model'=>$modelName,'category'=>$catNorm]); exit; }
        if (strcasecmp((string)($unit['status'] ?? ''), 'Available') !== 0) { echo json_encode(['allowed'=>false,'reason'=>'No available units','model'=>$modelName,'category'=>$catNorm]); exit; }
      } elseif ($modelId > 0) {
        $whitelisted = (int)$buCol->countDocuments(['model_id' => ['$in' => [(int)$modelId, (string)$modelId]]]) > 0;
        if (!$whitelisted) { echo json_encode(['allowed'=>false,'reason'=>'Item is not Available','model'=>$modelName,'category'=>$catNorm]); exit; }
      } else {
        // For model-only validation, ensure there is at least one Available unit that is whitelisted for this model/category
        $whIds = $buCol->distinct('model_id', ['model_name'=>$modelName,'category'=>$catNorm]);
        if (empty($whIds)) { echo json_encode(['allowed'=>false,'reason'=>'No available units','model'=>$modelName,'category'=>$catNorm]); exit; }
        $availWh = (int)$iiCol->countDocuments(['status'=>'Available','quantity'=>['$gt'=>0],'id'=>['$in'=>array_map('intval',$whIds)]]);
        if ($availWh <= 0) { echo json_encode(['allowed'=>false,'reason'=>'No available units','model'=>$modelName,'category'=>$catNorm]); exit; }
      }
      // Availability count
      $avail = (int)$iiCol->countDocuments(['status'=>'Available','quantity'=>['$gt'=>0],'category'=>$catNorm,'$or'=>[['model'=>$modelName], ['item_name'=>$modelName]]]);
      if ($avail <= 0) { echo json_encode(['allowed'=>false,'reason'=>'No available units','model'=>$modelName,'category'=>$catNorm]); exit; }
      // Specific unit in use
      if ($modelId > 0) {
        $ubCol = $mongo_db->selectCollection('user_borrows');
        $inUse = (int)$ubCol->countDocuments(['model_id'=>$modelId,'status'=>'Borrowed']);
        if ($inUse > 0) { echo json_encode(['allowed'=>false,'reason'=>'This unit is currently borrowed','model'=>$modelName,'category'=>$catNorm]); exit; }
      }
      echo json_encode(['allowed'=>true,'reason'=>'OK','model_id'=>$modelId,'model'=>$modelName,'category'=>$catNorm]); exit;
    } elseif (isset($conn) && $conn) {
      // MySQL fallback
      if ($modelId > 0) {
        if ($st = $conn->prepare("SELECT COALESCE(NULLIF(model,''), item_name) AS m, COALESCE(NULLIF(category,''),'Uncategorized') AS c FROM inventory_items WHERE id=? LIMIT 1")) { $st->bind_param('i', $modelId); $st->execute(); $st->bind_result($m,$c); if ($st->fetch()){ $modelName=(string)$m; $catNorm=(string)$c; } $st->close(); }
      } elseif ($modelNameIn !== '') {
        if ($st = $conn->prepare("SELECT COALESCE(NULLIF(model,''), item_name) AS m, COALESCE(NULLIF(category,''),'Uncategorized') AS c FROM inventory_items WHERE (model=? OR item_name=?) ORDER BY id DESC LIMIT 1")) { $st->bind_param('ss', $modelNameIn, $modelNameIn); $st->execute(); $st->bind_result($m,$c); if ($st->fetch()){ $modelName=(string)$m; $catNorm=(string)$c; } $st->close(); }
      }
      if ($modelName === '') { echo json_encode(['allowed'=>false,'reason'=>'Invalid QR','model_id'=>$modelId,'model'=>'','category'=>$catNorm]); exit; }
      $stmt = $conn->prepare("SELECT 1 FROM borrowable_models WHERE active=1 AND LOWER(TRIM(model_name))=LOWER(TRIM(?)) AND LOWER(TRIM(category))=LOWER(TRIM(?)) LIMIT 1");
      $isBorrowable = false; if ($stmt){ $stmt->bind_param('ss', $modelName, $catNorm); $stmt->execute(); $stmt->store_result(); $isBorrowable = $stmt->num_rows > 0; $stmt->close(); }
      if (!$isBorrowable) { echo json_encode(['allowed'=>false,'reason'=>'Item is not Available','model'=>$modelName,'category'=>$catNorm]); exit; }
      // Enforce whitelist in MySQL
      if ($modelId > 0) {
        $ok = false; if ($st2 = $conn->prepare("SELECT 1 FROM borrowable_units WHERE model_id=? LIMIT 1")) { $st2->bind_param('i',$modelId); $st2->execute(); $st2->store_result(); $ok = $st2->num_rows > 0; $st2->close(); }
        if (!$ok) { echo json_encode(['allowed'=>false,'reason'=>'Item is not Available','model'=>$modelName,'category'=>$catNorm]); exit; }
      } else {
        // Require at least one Available whitelisted unit for this model/category
        $wh = [];
        if ($st3 = $conn->prepare("SELECT model_id FROM borrowable_units WHERE LOWER(TRIM(model_name))=LOWER(TRIM(?)) AND LOWER(TRIM(category))=LOWER(TRIM(?))")) {
          $st3->bind_param('ss', $modelName, $catNorm); if ($st3->execute()) { $res=$st3->get_result(); while($r=$res->fetch_assoc()){ $wh[]=(int)$r['model_id']; } } $st3->close();
        }
        if (empty($wh)) { echo json_encode(['allowed'=>false,'reason'=>'No available units','model'=>$modelName,'category'=>$catNorm]); exit; }
        $in = implode(',', array_map('intval',$wh));
        $availWh = 0; if ($qwh = $conn->query("SELECT COUNT(*) AS c FROM inventory_items WHERE id IN ($in) AND status='Available' AND quantity>0")) { $row=$qwh->fetch_assoc(); $availWh=(int)$row['c']; $qwh->close(); }
        if ($availWh <= 0) { echo json_encode(['allowed'=>false,'reason'=>'No available units','model'=>$modelName,'category'=>$catNorm]); exit; }
      }
      $q = $conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_items WHERE LOWER(TRIM(COALESCE(NULLIF(model,''), item_name)))=LOWER(TRIM(?)) AND LOWER(TRIM(COALESCE(NULLIF(category,''),'Uncategorized')))=LOWER(TRIM(?)) AND status='Available' AND quantity>0");
      $avail = 0; if ($q){ $q->bind_param('ss', $modelName, $catNorm); $q->execute(); $q->bind_result($avail); $q->fetch(); $q->close(); }
      if ($avail <= 0) { echo json_encode(['allowed'=>false,'reason'=>'No available units','model'=>$modelName,'category'=>$catNorm]); exit; }
      if ($modelId > 0) { $inUse = 0; $s = $conn->prepare("SELECT COUNT(*) FROM user_borrows WHERE model_id=? AND status='Borrowed'"); if ($s){ $s->bind_param('i',$modelId); $s->execute(); $s->bind_result($inUse); $s->fetch(); $s->close(); } if ($inUse>0){ echo json_encode(['allowed'=>false,'reason'=>'This unit is currently borrowed','model'=>$modelName,'category'=>$catNorm]); exit; } }
      echo json_encode(['allowed'=>true,'reason'=>'OK','model_id'=>$modelId,'model'=>$modelName,'category'=>$catNorm]); exit;
    } else {
      echo json_encode(['allowed'=>false,'reason'=>'Database unavailable']); exit;
    }
  } catch (Throwable $e) {
    echo json_encode(['allowed'=>false,'reason'=>'Validation failed']); exit;
  }
}

// JSON: create a pending request from a QR scan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'create_from_qr') {
  header('Content-Type: application/json');
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true) ?: [];
  $modelIdIn = (int)($data['model_id'] ?? 0);
  $inputModel = trim((string)($data['model'] ?? $data['item_name'] ?? ''));
  $inputCategory = trim((string)($data['category'] ?? ''));
  $qrSerial = trim((string)($data['qr_serial_no'] ?? ''));
  $reqLocation = trim((string)($data['request_location'] ?? ''));
  $expectedRet = trim((string)($data['expected_return_at'] ?? ''));
  $username = isset($_SESSION['username']) ? (string)$_SESSION['username'] : '';
  if ($username === '') { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
  try {
    if ($USED_MONGO && $mongo_db) {
      $iiCol = $mongo_db->selectCollection('inventory_items');
      $bmCol = $mongo_db->selectCollection('borrowable_catalog');
      $erCol = $mongo_db->selectCollection('equipment_requests');

      // Normalize model name and category (prefer by id)
      $modelName = $inputModel; $catNorm = ($inputCategory !== '' ? $inputCategory : 'Uncategorized');
      if ($modelIdIn > 0) {
        $doc = $iiCol->findOne(['id'=>$modelIdIn], ['projection'=>['model'=>1,'item_name'=>1,'category'=>1]]);
        if ($doc) { $modelName = (string)($doc['model'] ?? ($doc['item_name'] ?? $modelName)); $catNorm = (string)(($doc['category'] ?? '') ?: 'Uncategorized'); }
      } elseif ($modelName !== '') {
        $doc = $iiCol->findOne(['$or'=>[['model'=>$modelName],['item_name'=>$modelName]]], ['sort'=>['id'=>-1], 'projection'=>['model'=>1,'item_name'=>1,'category'=>1]]);
        if ($doc) { $modelName = (string)($doc['model'] ?? ($doc['item_name'] ?? $modelName)); $catNorm = (string)(($doc['category'] ?? '') ?: 'Uncategorized'); }
      }
      if ($modelName === '') { echo json_encode(['success'=>false,'error'=>'Invalid QR']); exit; }
      // Ensure model is borrowable (if catalog exists)
      $isBorrowable = (int)$bmCol->countDocuments(['active'=>1,'model_name'=>$modelName,'category'=>$catNorm]) > 0;
      if (!$isBorrowable) { echo json_encode(['success'=>false,'error'=>'Item not borrowable']); exit; }

      // Enforce serial whitelist before creating the request
      $buCol = $mongo_db->selectCollection('borrowable_units');
      if ($qrSerial !== '') {
        // Resolve the specific unit by serial and require it to be whitelisted and Available
        $unit = $iiCol->findOne(['serial_no'=>$qrSerial], ['projection'=>['id'=>1,'status'=>1]]);
        if (!$unit) { echo json_encode(['success'=>false,'error'=>'Invalid QR']); exit; }
        $uid = (int)($unit['id'] ?? 0);
        $whitelisted = (int)$buCol->countDocuments(['model_id'=>$uid]) > 0;
        if (!$whitelisted) { echo json_encode(['success'=>false,'error'=>'Item not borrowable']); exit; }
        if (strcasecmp((string)($unit['status'] ?? ''), 'Available') !== 0) { echo json_encode(['success'=>false,'error'=>'No available units']); exit; }
      } else {
        // Model-only scan: require at least one Available unit that is whitelisted for this model/category
        $whIds = $buCol->distinct('model_id', ['model_name'=>$modelName,'category'=>$catNorm]);
        if (empty($whIds)) { echo json_encode(['success'=>false,'error'=>'No available units']); exit; }
        $availWh = (int)$iiCol->countDocuments(['status'=>'Available','quantity'=>['$gt'=>0],'id'=>['$in'=>array_map('intval',$whIds)]]);
        if ($availWh <= 0) { echo json_encode(['success'=>false,'error'=>'No available units']); exit; }
      }

      // Create pending request (quantity 1, immediate)
      $last = $erCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
      $nextId = ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
      // Validate expected return if provided
      $expectedNorm = '';
      if ($expectedRet !== '') {
        $ts = strtotime($expectedRet);
        if (!$ts || $ts <= time()) { echo json_encode(['success'=>false,'error'=>'Invalid expected return']); exit; }
        $expectedNorm = date('Y-m-d H:i:s', $ts);
      }
      $details = 'QR Scanned'.($modelIdIn>0? (' | Scanned Model ID: '.$modelIdIn):'').($qrSerial!==''? (' | Serial: '.$qrSerial):'').($expectedNorm!==''? (' | Expected Return: '.$expectedNorm):'');
      $doc = [
        'id' => $nextId,
        'username' => $username,
        'item_name' => $modelName,
        'quantity' => 1,
        'request_location' => $reqLocation,
        'details' => $details,
        'status' => 'Pending',
        'created_at' => date('Y-m-d H:i:s'),
        'type' => 'immediate',
      ];
      if ($qrSerial !== '') { $doc['qr_serial_no'] = $qrSerial; }
      if ($expectedNorm !== '') { $doc['expected_return_at'] = $expectedNorm; }
      $erCol->insertOne($doc);
      echo json_encode(['success'=>true,'request_id'=>$nextId]);
    } else if ($conn) {
      // MySQL fallback with borrowable check
      $modelName = $inputModel !== '' ? $inputModel : '';
      $catNorm = ($inputCategory !== '' ? $inputCategory : '');
      if ($modelIdIn > 0) {
        if ($st = $conn->prepare("SELECT COALESCE(NULLIF(model,''), item_name) AS m, COALESCE(NULLIF(category,''),'Uncategorized') AS c FROM inventory_items WHERE id=? LIMIT 1")) {
          $st->bind_param('i', $modelIdIn);
          if ($st->execute()) { $st->bind_result($m,$c); if ($st->fetch()) { $modelName = (string)$m; $catNorm = (string)$c; } }
          $st->close();
        }
      } elseif ($modelName !== '') {
        if ($st = $conn->prepare("SELECT COALESCE(NULLIF(category,''),'Uncategorized') AS c FROM inventory_items WHERE (model=? OR item_name=?) ORDER BY id DESC LIMIT 1")) {
          $st->bind_param('ss', $modelName, $modelName);
          if ($st->execute()) { $st->bind_result($c); if ($st->fetch()) { $catNorm = (string)$c; } }
          $st->close();
        }
      }
      if ($modelName === '') { echo json_encode(['success'=>false,'error'=>'Invalid QR']); exit; }
      if ($catNorm === '') { $catNorm = 'Uncategorized'; }
      // Enforce active borrowable list
      $isBorrowable = false;
      if ($chk = $conn->prepare("SELECT 1 FROM borrowable_models WHERE active=1 AND LOWER(TRIM(model_name))=LOWER(TRIM(?)) AND LOWER(TRIM(category))=LOWER(TRIM(?)) LIMIT 1")) {
        $chk->bind_param('ss', $modelName, $catNorm);
        $chk->execute();
        $chk->store_result();
        $isBorrowable = $chk->num_rows > 0;
        $chk->close();
      }
      if (!$isBorrowable) { echo json_encode(['success'=>false,'error'=>'Item not borrowable']); exit; }

      // Enforce whitelist (MySQL)
      if ($qrSerial !== '') {
        // Resolve unit by serial and require whitelisted + Available
        $uid = 0; $status = '';
        if ($st = $conn->prepare("SELECT id, status FROM inventory_items WHERE serial_no=? LIMIT 1")) { $st->bind_param('s',$qrSerial); if ($st->execute()) { $st->bind_result($uid,$status); $st->fetch(); } $st->close(); }
        if ($uid <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid QR']); exit; }
        $ok = false; if ($st2 = $conn->prepare("SELECT 1 FROM borrowable_units WHERE model_id=? LIMIT 1")) { $st2->bind_param('i',$uid); $st2->execute(); $st2->store_result(); $ok = $st2->num_rows > 0; $st2->close(); }
        if (!$ok) { echo json_encode(['success'=>false,'error'=>'Item not borrowable']); exit; }
        if (strcasecmp($status,'Available') !== 0) { echo json_encode(['success'=>false,'error'=>'No available units']); exit; }
      } else {
        // Model-only: require at least one Available whitelisted unit for this model/category
        $wh = [];
        if ($st3 = $conn->prepare("SELECT model_id FROM borrowable_units WHERE LOWER(TRIM(model_name))=LOWER(TRIM(?)) AND LOWER(TRIM(category))=LOWER(TRIM(?))")) { $st3->bind_param('ss',$modelName,$catNorm); if ($st3->execute()) { $res=$st3->get_result(); while($r=$res->fetch_assoc()){ $wh[]=(int)$r['model_id']; } } $st3->close(); }
        if (empty($wh)) { echo json_encode(['success'=>false,'error'=>'No available units']); exit; }
        $in = implode(',', array_map('intval',$wh));
        $availWh = 0; if ($qwh = $conn->query("SELECT COUNT(*) AS c FROM inventory_items WHERE id IN ($in) AND status='Available' AND quantity>0")) { $row=$qwh->fetch_assoc(); $availWh=(int)$row['c']; $qwh->close(); }
        if ($availWh <= 0) { echo json_encode(['success'=>false,'error'=>'No available units']); exit; }
      }

      // Validate expected return if present
      if ($expectedRet!==''){
        $ts = strtotime($expectedRet);
        if (!$ts || $ts <= time()) { echo json_encode(['success'=>false,'error'=>'Invalid expected return']); exit; }
      }

      $stmt = $conn->prepare("INSERT INTO equipment_requests (username, item_name, request_location, quantity, details, type) VALUES (?, ?, ?, 1, ?, 'immediate')");
      if ($stmt) {
        $detParts = ['QR Scanned']; if ($modelIdIn>0) $detParts[] = 'Scanned Model ID: '.$modelIdIn; if ($qrSerial!=='') $detParts[] = 'Serial: '.$qrSerial; if ($expectedRet!==''){ $detParts[] = 'Expected Return: '.date('Y-m-d H:i:s', strtotime($expectedRet)); }
        $det = implode(' | ', $detParts);
        $stmt->bind_param('ssss', $username, $modelName, $reqLocation, $det);
        if ($stmt->execute()) { echo json_encode(['success'=>true,'request_id'=>$stmt->insert_id]); }
        else { echo json_encode(['success'=>false,'error'=>'DB insert failed']); }
        $stmt->close();
      } else { echo json_encode(['success'=>false,'error'=>'DB unavailable']); }
    } else { echo json_encode(['success'=>false,'error'=>'Database unavailable']); }
  } catch (Throwable $e) {
    echo json_encode(['success'=>false,'error'=>'Server error']);
  }
  exit;
}

// Ensure core tables
if (!$USED_MONGO && $conn) $conn->query("CREATE TABLE IF NOT EXISTS equipment_requests (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  quantity INT(11) NOT NULL DEFAULT 1,
  details TEXT DEFAULT NULL,
  status ENUM('Pending','Approved','Rejected','Borrowed','Returned') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  rejected_at TIMESTAMP NULL DEFAULT NULL,
  borrowed_at TIMESTAMP NULL DEFAULT NULL,
  returned_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_username (username),
  KEY idx_status (status),
  KEY idx_item_name (item_name),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

// Ensure borrowable_units table for whitelist enforcement (MySQL fallback)
if (!$USED_MONGO && $conn) $conn->query("CREATE TABLE IF NOT EXISTS borrowable_units (
  id INT(11) NOT NULL AUTO_INCREMENT,
  model_id INT(11) NOT NULL,
  model_name VARCHAR(150) DEFAULT NULL,
  category VARCHAR(100) DEFAULT 'Uncategorized',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_model_id (model_id),
  KEY idx_model_cat (model_name, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
// Add request_location column if missing (ignore error if exists)
if (!$USED_MONGO && $conn) { @$conn->query("ALTER TABLE equipment_requests ADD COLUMN request_location VARCHAR(100) NULL AFTER item_name"); }

if (!$USED_MONGO && $conn) $conn->query("CREATE TABLE IF NOT EXISTS borrowable_models (
  id INT(11) NOT NULL AUTO_INCREMENT,
  model_name VARCHAR(150) NOT NULL,
  category VARCHAR(100) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  pool_qty INT(11) NOT NULL DEFAULT 0,
  borrow_limit INT(11) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_model_category (model_name, category),
  INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

$message = '';
$error = '';

// Build borrowable catalog
$availableMap = [];
$catOptions = [];
if ($USED_MONGO && $mongo_db) {
  try {
    $ii = $mongo_db->selectCollection('inventory_items');
    $bm = $mongo_db->selectCollection('borrowable_catalog');
    $bmCur = $bm->find(['active'=>1], ['projection'=>['model_name'=>1,'category'=>1]]);
    foreach ($bmCur as $b) {
      $cat = (string)($b['category'] ?? 'Uncategorized'); $mod = (string)($b['model_name'] ?? ''); if ($mod==='') continue;
      $has = $ii->countDocuments(['status'=>'Available','quantity'=>['$gt'=>0],'category'=>$cat,'$or'=>[['model'=>$mod],['item_name'=>$mod]]]);
      if ($has > 0) { if (!isset($availableMap[$cat])) { $availableMap[$cat]=[]; $catOptions[]=$cat; } $availableMap[$cat][mb_strtolower($mod)]=$mod; }
    }
    if (!empty($catOptions)) { natcasesort($catOptions); $catOptions = array_values(array_unique($catOptions)); }
  } catch (Throwable $e) { $availableMap=[]; $catOptions=[]; }
} elseif ($conn) {
  $sql = "SELECT DISTINCT COALESCE(NULLIF(ii.category,''),'Uncategorized') AS category, COALESCE(NULLIF(ii.model,''), ii.item_name) AS model_name FROM inventory_items ii INNER JOIN borrowable_models bm ON bm.active = 1 AND bm.model_name = COALESCE(NULLIF(ii.model,''), ii.item_name) AND bm.category = COALESCE(NULLIF(ii.category,''),'Uncategorized') WHERE ii.status='Available' AND ii.quantity > 0 ORDER BY category, model_name";
  if ($res = $conn->query($sql)) { while ($row=$res->fetch_assoc()) { $cat=$row['category']; $mod=$row['model_name']; if (!isset($availableMap[$cat])) { $availableMap[$cat]=[]; $catOptions[]=$cat; } if ($mod!=='') { $availableMap[$cat][mb_strtolower($mod)]=$mod; } } $res->close(); }
  if (!empty($catOptions)) { natcasesort($catOptions); $catOptions = array_values(array_unique($catOptions)); }
}

// Availability counts by (category, model)
$availCounts = [];
if ($USED_MONGO && $mongo_db) {
  try {
    $ii = $mongo_db->selectCollection('inventory_items');
    $agg = $ii->aggregate([
      ['$match'=>['status'=>'Available','quantity'=>['$gt'=>0]]],
      ['$project'=>['category'=>['$ifNull'=>['$category','Uncategorized']], 'model_key'=>['$ifNull'=>['$model','$item_name']], 'q'=>['$ifNull'=>['$quantity',1]]]],
      ['$group'=>['_id'=>['c'=>'$category','m'=>'$model_key'], 'avail'=>['$sum'=>'$q']]],
    ]);
    foreach ($agg as $r) { $c=(string)($r->_id['c']??'Uncategorized'); $m=(string)($r->_id['m']??''); if (!isset($availCounts[$c])) $availCounts[$c]=[]; $availCounts[$c][$m]=(int)($r->avail??0); }
  } catch (Throwable $e) { $availCounts=[]; }
} elseif ($conn) {
  if ($ra = $conn->query("SELECT COALESCE(NULLIF(category,''),'Uncategorized') AS category, COALESCE(NULLIF(model,''), item_name) AS model_name, SUM(quantity) AS avail FROM inventory_items WHERE status='Available' AND quantity > 0 GROUP BY category, model_name")) { while ($r=$ra->fetch_assoc()) { $c=(string)$r['category']; $m=(string)$r['model_name']; if (!isset($availCounts[$c])) { $availCounts[$c]=[]; } $availCounts[$c][$m]=(int)$r['avail']; } $ra->close(); }
}

// Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $sel_category = trim($_POST['category'] ?? '');
  $sel_model = trim($_POST['model'] ?? '');
  $quantity = max(1, intval($_POST['quantity'] ?? 1));
  $details = trim($_POST['details'] ?? '');
  $req_location = trim($_POST['req_location'] ?? '');
  $req_type = strtolower(trim($_POST['req_type'] ?? 'immediate'));
  $expected_return_at = trim($_POST['expected_return_at'] ?? '');
  $reserved_from = trim($_POST['reserved_from'] ?? '');
  $reserved_to = trim($_POST['reserved_to'] ?? '');
  
  // Ensure timezone is set for date validations
  date_default_timezone_set('Asia/Manila');

  // Require a specific model; do not fallback to category to avoid creating requests with non-existent model names
  $item_name = $sel_model;
  if ($item_name === '') {
    $error = 'Please select a specific Model.';
  } else {
    // Verify borrowable and available
    $cat = '';
    foreach ($availableMap as $c => $mods) { if (in_array($item_name, array_values($mods), true)) { $cat = $c; break; } }
    $isAllowed = ($cat !== '');
    // Reservation override: allow single-quantity items even if currently in use (cap quantity to 1)
    $reservationSingleOverride = false;
    if (!$isAllowed && $req_type === 'reservation') {
      try {
        if ($USED_MONGO && $mongo_db) {
          $iiCol = $mongo_db->selectCollection('inventory_items');
          $bmCol = $mongo_db->selectCollection('borrowable_catalog');
          $iiDoc = $iiCol->findOne(['$or' => [['model'=>$item_name], ['item_name'=>$item_name]]], ['sort'=>['id'=>-1], 'projection'=>['category'=>1,'model'=>1,'item_name'=>1,'quantity'=>1]]);
          $catNorm = $iiDoc ? ((string)($iiDoc['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized';
          $isBorrowable = (int)$bmCol->countDocuments(['active'=>1,'model_name'=>$item_name,'category'=>$catNorm]) > 0;
          if ($isBorrowable) {
            // total units
            $sum = 0; $agg = $iiCol->aggregate([
              ['$match'=>['$or'=>[['model'=>$item_name],['item_name'=>$item_name]]]],
              ['$project'=>['q'=>['$ifNull'=>['$quantity',1]]]],
              ['$group'=>['_id'=>null,'sum'=>['$sum'=>'$q']]]
            ]); foreach ($agg as $ar){ $sum = (int)($ar->sum ?? 0); break; }
            if ($sum <= 1) { $cat = $catNorm; $isAllowed = true; $reservationSingleOverride = true; }
          }
        } elseif ($conn) {
          // Resolve category and ensure model is active in borrowable_models
          $catNorm = 'Uncategorized'; if ($st = $conn->prepare("SELECT COALESCE(NULLIF(category,''),'Uncategorized') FROM inventory_items WHERE (model=? OR item_name=?) ORDER BY id DESC LIMIT 1")) { $st->bind_param('ss',$item_name,$item_name); $st->execute(); $st->bind_result($catNorm); $st->fetch(); $st->close(); }
          $isBorrowable = false; if ($st2 = $conn->prepare("SELECT 1 FROM borrowable_models WHERE active=1 AND LOWER(TRIM(model_name))=LOWER(TRIM(?)) AND LOWER(TRIM(category))=LOWER(TRIM(?)) LIMIT 1")) { $st2->bind_param('ss',$item_name,$catNorm); $st2->execute(); $st2->store_result(); $isBorrowable = $st2->num_rows > 0; $st2->close(); }
          if ($isBorrowable) {
            $sum = 0; if ($st3 = $conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_items WHERE LOWER(TRIM(COALESCE(NULLIF(model,''), item_name)))=LOWER(TRIM(?))")) { $st3->bind_param('s',$item_name); $st3->execute(); $st3->bind_result($sum); $st3->fetch(); $st3->close(); }
            if ((int)$sum <= 1) { $cat = $catNorm; $isAllowed = true; $reservationSingleOverride = true; }
          }
        }
      } catch (Throwable $_) { }
    }
    if (!$isAllowed) {
      $error = 'Selected item is not currently borrowable.';
    } else {
      $avail = (int)($availCounts[$cat][$item_name] ?? 0);
      $maxReq = $reservationSingleOverride ? 1 : max(0, $avail);
      if (!$reservationSingleOverride && $maxReq <= 0) {
        $error = 'Selected item is not available at the moment.';
      } elseif ($quantity > $maxReq) {
        $error = 'Requested quantity exceeds the available maximum of ' . $maxReq . '.';
      } else {
        // Validate request timing based on type
        $isReservation = ($req_type === 'reservation');
        $current_time = time();
        
        if ($isReservation) {
          // Validate reservation times
          $start_time = strtotime($reserved_from);
          $end_time = strtotime($reserved_to);
          
          if (empty($reserved_from) || empty($reserved_to)) {
            $error = 'Please provide both reservation start and end times.';
          } elseif (!$start_time || !$end_time) {
            $error = 'Please enter valid date/time values for reservation period.';
          } elseif ($start_time <= $current_time) {
            $error = 'Reservation start time must be in the future.';
          } elseif ($end_time <= $start_time) {
            $error = 'Reservation end time must be after start time.';
          } else {
            // Check for overlapping reservations and apply 5-min buffer rules
            if ($USED_MONGO && $mongo_db) {
              try {
                // total units for this model
                $sum = 0; try {
                  $agg = $mongo_db->inventory_items->aggregate([
                    ['$match'=>['$or'=>[['model'=>$item_name],['item_name'=>$item_name]]]],
                    ['$project'=>['q'=>['$ifNull'=>['$quantity',1]]]],
                    ['$group'=>['_id'=>null,'sum'=>['$sum'=>'$q']]]
                  ]);
                  foreach ($agg as $ar){ $sum = (int)($ar->sum ?? 0); break; }
                } catch (Throwable $_) { $sum = 0; }

                // Overlap with approved reservations applies only when effectively single-quantity
                if ($sum <= 1) {
                  $overlap = $mongo_db->equipment_requests->findOne([
                    'item_name' => $item_name,
                    'status' => 'Approved',
                    'type' => 'reservation',
                    '$or' => [
                      [
                        'reserved_from' => ['$lt' => date('Y-m-d H:i:s', $end_time)],
                        'reserved_to' => ['$gt' => date('Y-m-d H:i:s', $start_time)]
                      ]
                    ]
                  ]);
                  if ($overlap) {
                    $error = 'This item is already reserved for the selected time period.';
                  }
                }

                // If single-quantity and currently borrowed, require 5-min buffer after expected return
                if (!$error && $sum <= 1) {
                  $unit = $mongo_db->inventory_items->findOne(['$or'=>[['model'=>$item_name],['item_name'=>$item_name]]], ['sort'=>['id'=>1], 'projection'=>['id'=>1]]);
                  if ($unit) {
                    $ub = $mongo_db->user_borrows->findOne(['model_id'=>(int)($unit['id']??0), 'status'=>'Borrowed'], ['projection'=>['expected_return_at'=>1]]);
                    if ($ub && isset($ub['expected_return_at'])) {
                      $exp = strtotime((string)$ub['expected_return_at']);
                      if ($exp && $start_time < ($exp + 5*60)) {
                        $error = 'Reservation start must be at least 5 minutes after the current expected return time (' . date('M j, Y g:i A', $exp + 5*60) . ').';
                      }
                    }
                  }
                }
              } catch (Throwable $e) {
                // Log error but don't block the request
                error_log('Error checking reservation overlap: ' . $e->getMessage());
              }
            } elseif ($conn) {
              try {
                // Prepare times and compute total units for this model
                $rf = date('Y-m-d H:i:s', $start_time);
                $rt = date('Y-m-d H:i:s', $end_time);
                $sum = 0; if ($st2 = $conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_items WHERE LOWER(TRIM(COALESCE(NULLIF(model,''), item_name)))=LOWER(TRIM(?))")) { $st2->bind_param('s',$item_name); $st2->execute(); $st2->bind_result($sum); $st2->fetch(); $st2->close(); }

                // Overlap check applies only when effectively single-quantity
                if ((int)$sum <= 1) {
                  $hasOverlap = false;
                  if ($st = $conn->prepare("SELECT 1 FROM equipment_requests WHERE type='reservation' AND status='Approved' AND LOWER(TRIM(item_name))=LOWER(TRIM(?)) AND reserved_from < ? AND reserved_to > ? LIMIT 1")) {
                    $st->bind_param('sss', $item_name, $rt, $rf);
                    $st->execute(); $st->store_result(); $hasOverlap = $st->num_rows > 0; $st->close();
                  }
                  if ($hasOverlap) { $error = 'This item is already reserved for the selected time period.'; }

                  // If single-quantity and currently borrowed, enforce 5-min buffer after current expected return
                  if (!$error) {
                    // Resolve a unit id for this model
                    $uid = 0; if ($st3 = $conn->prepare("SELECT id FROM inventory_items WHERE (model=? OR item_name=?) ORDER BY id ASC LIMIT 1")) { $st3->bind_param('ss',$item_name,$item_name); $st3->execute(); $st3->bind_result($uid); $st3->fetch(); $st3->close(); }
                    if ($uid) {
                      $expStr = null; if ($st4 = $conn->prepare("SELECT expected_return_at FROM user_borrows WHERE model_id=? AND status='Borrowed' LIMIT 1")) { $st4->bind_param('i',$uid); $st4->execute(); $st4->bind_result($expStr); $st4->fetch(); $st4->close(); }
                      if ($expStr) { $exp = strtotime((string)$expStr); if ($exp && $start_time < ($exp + 5*60)) { $error = 'Reservation start must be at least 5 minutes after the current expected return time (' . date('M j, Y g:i A', $exp + 5*60) . ').'; } }
                    }
                  }
                }
              } catch (Throwable $_) { }
            }
          }
        } else {
          // Validate immediate borrow
          $return_time = strtotime($expected_return_at);
          
          if (empty($expected_return_at)) {
            $error = 'Please provide an expected return date and time.';
          } elseif (!$return_time) {
            $error = 'Please enter a valid return date and time.';
          } elseif ($return_time <= $current_time) {
            $error = 'Return time must be in the future.';
          } elseif (($return_time - $current_time) > (24 * 60 * 60)) {
            $error = 'Immediate borrow cannot exceed 24 hours.';
          } else {
            // Check for upcoming reservations (apply only when effectively single quantity)
            if ($USED_MONGO && $mongo_db) {
              try {
                // total units
                $sum = 0; try {
                  $agg = $mongo_db->inventory_items->aggregate([
                    ['$match'=>['$or'=>[['model'=>$item_name],['item_name'=>$item_name]]]],
                    ['$project'=>['q'=>['$ifNull'=>['$quantity',1]]]],
                    ['$group'=>['_id'=>null,'sum'=>['$sum'=>'$q']]]
                  ]);
                  foreach ($agg as $ar){ $sum = (int)($ar->sum ?? 0); break; }
                } catch (Throwable $_) { $sum = 0; }
                if ($sum <= 1) {
                  $upcoming_reservation = $mongo_db->equipment_requests->findOne([
                    'item_name' => $item_name,
                    'type' => 'reservation',
                    'status' => 'Approved',
                    'reserved_from' => [
                      '$gt' => date('Y-m-d H:i:s'),
                      '$lt' => date('Y-m-d H:i:s', $return_time + (5 * 60))
                    ]
                  ], ['sort'=>['reserved_from'=>1], 'projection'=>['reserved_from'=>1]]);
                  if ($upcoming_reservation && isset($upcoming_reservation['reserved_from'])) {
                    $reserve_time = strtotime((string)$upcoming_reservation['reserved_from']);
                    $cutoff_time = $reserve_time - (5 * 60);
                    if ($return_time > $cutoff_time) {
                      $error = 'This item has an upcoming reservation. Please return by ' . date('M j, Y g:i A', $cutoff_time) . ' (5 minutes before the next reservation).';
                    }
                  }
                }
              } catch (Throwable $e) {
                // Log error but don't block the request
                error_log('Error checking upcoming reservations: ' . $e->getMessage());
              }
            } elseif ($conn) {
              try {
                // total units
                $sum = 0; if ($st = $conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_items WHERE LOWER(TRIM(COALESCE(NULLIF(model,''), item_name)))=LOWER(TRIM(?))")) { $st->bind_param('s',$item_name); $st->execute(); $st->bind_result($sum); $st->fetch(); $st->close(); }
                if ((int)$sum <= 1) {
                  // check earliest upcoming reservation that would conflict
                  $upper = date('Y-m-d H:i:s', $return_time + 5*60);
                  $rf = null; if ($st2 = $conn->prepare("SELECT MIN(reserved_from) FROM equipment_requests WHERE type='reservation' AND status='Approved' AND LOWER(TRIM(item_name))=LOWER(TRIM(?)) AND reserved_from > NOW() AND reserved_from < ?")) { $st2->bind_param('ss',$item_name,$upper); $st2->execute(); $st2->bind_result($rf); $st2->fetch(); $st2->close(); }
                  if ($rf) { $ts = strtotime((string)$rf); $cut = $ts ? ($ts - 5*60) : 0; if ($cut && $return_time > $cut) { $error = 'This item has an upcoming reservation. Please return by ' . date('M j, Y g:i A', $cut) . ' (5 minutes before the next reservation).'; } }
                }
              } catch (Throwable $_) { /* ignore */ }
            }
          }
        }
        if (!$error) {
          if ($USED_MONGO && $mongo_db) {
            try {
              $er = $mongo_db->selectCollection('equipment_requests');
              $last = $er->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
              $nextId = ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
              // Prepare common document fields
              $doc = [
                'id' => $nextId,
                'username' => (string)$_SESSION['username'],
                'item_name' => $item_name,
                'quantity' => $quantity,
                'request_location' => $req_location,
                'details' => $details,
                'status' => 'Pending',
                'created_at' => date('Y-m-d H:i:s'),
                'type' => $req_type,
                'created_by' => (string)$_SESSION['username'],
                'created_at_utc' => new MongoDB\BSON\UTCDateTime(time() * 1000)
              ];
              
              // Add type-specific fields
              if ($isReservation) {
                $doc['reserved_from'] = date('Y-m-d H:i:s', $start_time);
                $doc['reserved_to'] = date('Y-m-d H:i:s', $end_time);
                $doc['reserved_from_utc'] = new MongoDB\BSON\UTCDateTime($start_time * 1000);
                $doc['reserved_to_utc'] = new MongoDB\BSON\UTCDateTime($end_time * 1000);
              } else {
                $doc['expected_return_at'] = date('Y-m-d H:i:s', $return_time);
                $doc['expected_return_at_utc'] = new MongoDB\BSON\UTCDateTime($return_time * 1000);
              }
              // If QR submission included a specific serial, keep it for admin approval UI
              $qr_serial = isset($_POST['qr_serial_no']) ? trim((string)$_POST['qr_serial_no']) : '';
              if ($qr_serial !== '') { $doc['qr_serial_no'] = $qr_serial; }
              if ($isReservation) { $doc['reserved_from'] = $reserved_from; $doc['reserved_to'] = $reserved_to; }
              else { $doc['expected_return_at'] = $expected_return_at; }
              $er->insertOne($doc);
              header('Location: user_request.php?submitted=1'); exit();
            } catch (Throwable $e) { $error = 'Failed to submit request.'; }
          } elseif ($conn) {
            $stmt = $conn->prepare("INSERT INTO equipment_requests (username, item_name, request_location, quantity, details, type, expected_return_at, reserved_from, reserved_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
              $typeVal = $isReservation ? 'reservation' : 'immediate';
              $exp = $isReservation ? null : $expected_return_at;
              $rf = $isReservation ? $reserved_from : null;
              $rt = $isReservation ? $reserved_to : null;
              $stmt->bind_param('sssisssss', $_SESSION['username'], $item_name, $req_location, $quantity, $details, $typeVal, $exp, $rf, $rt);
              if ($stmt->execute()) { header('Location: user_request.php?submitted=1'); exit(); } else { $error = 'Failed to submit request.'; }
              $stmt->close();
            }
          } else { $error = 'Database unavailable.'; }
        }
      }
    }
  }
}

// My requests list (last 50)
$my_requests = [];
if ($USED_MONGO && $mongo_db) {
  try {
    $er=$mongo_db->selectCollection('equipment_requests');
    $cur=$er->find(['username'=>(string)$_SESSION['username']], ['sort'=>['created_at'=>-1,'id'=>-1], 'limit'=>50]);
    foreach($cur as $r){
      $status = (string)($r['status']??'');
      $approved_by = (string)($r['approved_by']??'');
      $rejected_by = (string)($r['rejected_by']??'');
      if ($status === 'Rejected' && $rejected_by === '') { $approved_by = 'Auto Rejected'; }
      $my_requests[]=[
        'id'=>(int)($r['id']??0),
        'item_name'=>(string)($r['item_name']??''),
        'quantity'=>(int)($r['quantity']??1),
        'status'=>$status,
        'created_at'=>(string)($r['created_at']??''),
        'approved_at'=>(string)($r['approved_at']??''),
        'approved_by'=>$approved_by,
        'rejected_at'=>(string)($r['rejected_at']??''),
        'rejected_by'=>$rejected_by,
        'borrowed_at'=>(string)($r['borrowed_at']??''),
        'returned_at'=>(string)($r['returned_at']??'')
      ];
    }
  } catch (Throwable $e) { $my_requests=[]; }
} elseif ($conn && ($ps = $conn->prepare("SELECT id, item_name, quantity, status, created_at, approved_at, approved_by, rejected_at, rejected_by, borrowed_at, returned_at FROM equipment_requests WHERE username = ? ORDER BY created_at DESC, id DESC LIMIT 50"))) { 
  $ps->bind_param('s', $_SESSION['username']); 
  if ($ps->execute()) { 
    $res=$ps->get_result(); 
    while($row=$res->fetch_assoc()){ 
      $status = (string)$row['status'];
      $approver = (string)($row['approved_by'] ?? '');
      $rejector = (string)($row['rejected_by'] ?? '');
      if ($status === 'Rejected' && $rejector === '') { $approver = 'Auto Rejected'; }
      $my_requests[]=[
        'id'=>$row['id'],
        'item_name'=>$row['item_name'],
        'quantity'=>$row['quantity'],
        'status'=>$status,
        'created_at'=>$row['created_at'],
        'approved_at'=>$row['approved_at'],
        'approved_by'=>$approver,
        'rejected_at'=>$row['rejected_at'],
        'rejected_by'=>$rejector,
        'borrowed_at'=>$row['borrowed_at'],
        'returned_at'=>$row['returned_at']
      ]; 
    } 
  } 
  $ps->close(); 
}

// My borrowed items (active) — limit initial render to speed up first paint
$my_borrowed = [];
if (!$USED_MONGO && $conn) {
  $sqlBorrow = "SELECT ub.id AS borrow_id,
         (
           SELECT er2.id FROM equipment_requests er2
           WHERE er2.username = ub.username
             AND (er2.item_name = COALESCE(NULLIF(ii.model,''), ii.item_name) OR er2.item_name = ii.item_name)
           ORDER BY ABS(TIMESTAMPDIFF(SECOND, er2.created_at, ub.borrowed_at)) ASC, er2.id DESC
           LIMIT 1
         ) AS request_id,
         (SELECT er3.approved_at FROM equipment_requests er3
            WHERE er3.id = (
              SELECT er2.id FROM equipment_requests er2
              WHERE er2.username = ub.username
                AND (er2.item_name = COALESCE(NULLIF(ii.model,''), ii.item_name) OR er2.item_name = ii.item_name)
              ORDER BY ABS(TIMESTAMPDIFF(SECOND, er2.created_at, ub.borrowed_at)) ASC, er2.id DESC
              LIMIT 1
            )
          ) AS approved_at,
         (SELECT er4.qr_serial_no FROM equipment_requests er4
            WHERE er4.id = (
              SELECT er2.id FROM equipment_requests er2
              WHERE er2.username = ub.username
                AND (er2.item_name = COALESCE(NULLIF(ii.model,''), ii.item_name) OR er2.item_name = ii.item_name)
              ORDER BY ABS(TIMESTAMPDIFF(SECOND, er2.created_at, ub.borrowed_at)) ASC, er2.id DESC
              LIMIT 1
            )
          ) AS qr_serial_no,
         ub.borrowed_at,
         ii.id AS model_id, ii.item_name, ii.model, ii.category, ii.`condition`, ii.serial_no AS serial_no
       FROM user_borrows ub
       JOIN inventory_items ii ON ii.id = ub.model_id
       WHERE ub.username = ? AND ub.status = 'Borrowed'
       ORDER BY ub.borrowed_at DESC, ub.id DESC
       LIMIT 100";
  $bs = $conn->prepare($sqlBorrow);
  if ($bs) {
    $bs->bind_param('s', $_SESSION['username']);
    if ($bs->execute()) {
      $res = $bs->get_result();
      while ($row = $res->fetch_assoc()) { $my_borrowed[] = $row; }
    }
    $bs->close();
  }
} elseif ($USED_MONGO && $mongo_db) {
  try { 
    $ub=$mongo_db->selectCollection('user_borrows'); 
    $ii=$mongo_db->selectCollection('inventory_items'); 
    $ra=$mongo_db->selectCollection('request_allocations'); 
    $er=$mongo_db->selectCollection('equipment_requests'); 
    $cur=$ub->find(['username'=>(string)$_SESSION['username'],'status'=>'Borrowed'], ['sort'=>['borrowed_at'=>-1,'id'=>-1], 'limit'=>100]); 
    foreach($cur as $b){ 
      $mid=(int)($b['model_id']??0); 
      $itm=$mid>0?$ii->findOne(['id'=>$mid]):null; 
      $alloc=$ra->findOne(['borrow_id'=>(int)($b['id']??0)], ['projection'=>['request_id'=>1]]);
      $reqId=(int)($alloc['request_id']??0);
      if ($reqId<=0){
        $when = (string)($b['borrowed_at'] ?? '');
        $req = null;
        if ($itm){
          $cands = array_values(array_unique(array_filter([(string)($itm['model'] ?? ''),(string)($itm['item_name'] ?? '')])));
          if (!empty($cands)){
            $req = $er->findOne([
              'username'=>(string)$_SESSION['username'],
              'item_name'=>['$in'=>$cands],
              'created_at' => ['$lte' => $when]
            ], ['sort'=>['created_at'=>-1,'id'=>-1], 'projection'=>['id'=>1]]);
            if (!$req) {
              $req = $er->findOne([
                'username'=>(string)$_SESSION['username'],
                'item_name'=>['$in'=>$cands],
              ], ['sort'=>['created_at'=>-1,'id'=>-1], 'projection'=>['id'=>1]]);
            }
          }
        }
        if (!$req){
          $req = $er->findOne([
            'username'=>(string)$_SESSION['username'],
            'created_at' => ['$lte' => $when]
          ], ['sort'=>['created_at'=>-1,'id'=>-1], 'projection'=>['id'=>1]]);
          if (!$req) {
            $req = $er->findOne([
              'username'=>(string)$_SESSION['username']
            ], ['sort'=>['created_at'=>-1,'id'=>-1], 'projection'=>['id'=>1]]);
          }
        }
        if ($req && isset($req['id'])) { $reqId = (int)$req['id']; }
      }
      // Ensure model name is populated (prefer model, fallback to item_name; if missing, use request.item_name)
      $dispModel = '';
      if ($itm) { $dispModel = (string)($itm['model'] ?? ''); if ($dispModel==='') { $dispModel = (string)($itm['item_name'] ?? ''); } }
      if ($dispModel === '' && $reqId > 0) {
        $reqDoc = $er->findOne(['id'=>$reqId], ['projection'=>['item_name'=>1]]);
        if ($reqDoc && isset($reqDoc['item_name'])) { $dispModel = (string)$reqDoc['item_name']; }
      }
      // Determine QR vs Manual based on request's qr_serial_no
      $qrSer = '';
      if ($reqId > 0) { $rd = $er->findOne(['id'=>$reqId], ['projection'=>['qr_serial_no'=>1]]); if ($rd && isset($rd['qr_serial_no'])) { $qrSer = (string)$rd['qr_serial_no']; } }
      // Normalize times
      $ba = '';
      try {
        if (isset($b['borrowed_at']) && $b['borrowed_at'] instanceof MongoDB\BSON\UTCDateTime) {
          $dt = $b['borrowed_at']->toDateTime();
          $dt->setTimezone(new DateTimeZone('Asia/Manila'));
          $ba = $dt->format('Y-m-d H:i:s');
        } else { $ba = (string)($b['borrowed_at'] ?? ''); }
      } catch (Throwable $_) { $ba = (string)($b['borrowed_at'] ?? ''); }
      $apprAt = '';
      if ($reqId > 0) {
        try {
          $ap = $er->findOne(['id'=>$reqId], ['projection'=>['approved_at'=>1]]);
          if ($ap && isset($ap['approved_at'])) {
            if ($ap['approved_at'] instanceof MongoDB\BSON\UTCDateTime) { $d=$ap['approved_at']->toDateTime(); $d->setTimezone(new DateTimeZone('Asia/Manila')); $apprAt = $d->format('Y-m-d H:i:s'); }
            else { $apprAt = (string)$ap['approved_at']; }
          }
        } catch (Throwable $_a) { $apprAt = (string)($ap['approved_at'] ?? ''); }
      }
      $my_borrowed[]=[
        'borrow_id'=>(int)($b['id']??0),
        'request_id'=>$reqId,
        'model_id'=>$mid,
        'borrowed_at'=>$ba,
        'approved_at'=>$apprAt,
        'item_name'=>($dispModel!==''?$dispModel:''),
        'model'=>($dispModel!==''?$dispModel:''),
        'model_display'=>($dispModel!==''?$dispModel:''),
        'category'=>$itm?(string)($itm['category']??'Uncategorized'):'Uncategorized',
        'condition'=>$itm?(string)($itm['condition']??''):'',
        'serial_no'=>$itm?(string)($itm['serial_no']??''):'',
        'qr_serial_no'=>$qrSer,
        'status'=>'Borrowed'
      ]; 
    } 
  } catch (Throwable $e) { $my_borrowed=[]; }
}

// My borrow history (this user only) — cap to 150 on first load
$my_history = [];
if (!$USED_MONGO && $conn) {
  $sqlHistory = "SELECT ub.id AS borrow_id,
         COALESCE(ra.request_id, (
           SELECT er2.id FROM equipment_requests er2
           WHERE er2.username = ub.username
             AND (er2.item_name = COALESCE(NULLIF(ii.model,''), ii.item_name) OR er2.item_name = ii.item_name)
           ORDER BY ABS(TIMESTAMPDIFF(SECOND, er2.created_at, ub.borrowed_at)) ASC, er2.id DESC
           LIMIT 1
         )) AS request_id,
         ub.borrowed_at, ub.returned_at, ub.status,
         (
           SELECT l.action
           FROM lost_damaged_log l
           WHERE l.model_id = ub.model_id
             AND l.created_at >= ub.borrowed_at
           ORDER BY l.id DESC
           LIMIT 1
         ) AS latest_action,
         ii.id AS model_id, ii.item_name, ii.model, ii.category
       FROM user_borrows ub
       LEFT JOIN inventory_items ii ON ii.id = ub.model_id
       LEFT JOIN request_allocations ra ON ra.borrow_id = ub.id
       WHERE ub.username = ? AND ub.status <> 'Borrowed'
       ORDER BY ub.borrowed_at DESC, ub.id DESC LIMIT 150";
  $hs = $conn->prepare($sqlHistory);
  if ($hs) { $hs->bind_param('s', $_SESSION['username']); if ($hs->execute()) { $res = $hs->get_result(); while ($row = $res->fetch_assoc()) { $my_history[] = $row; } } $hs->close(); }
} elseif ($USED_MONGO && $mongo_db) {
  try { 
    $ub=$mongo_db->selectCollection('user_borrows'); 
    $ii=$mongo_db->selectCollection('inventory_items'); 
    $ld=$mongo_db->selectCollection('lost_damaged_log'); 
    $ra=$mongo_db->selectCollection('request_allocations'); 
    $er=$mongo_db->selectCollection('equipment_requests'); 
    $cur=$ub->find(['username'=>(string)$_SESSION['username'],'status'=>['$ne'=>'Borrowed']], ['sort'=>['borrowed_at'=>-1,'id'=>-1], 'limit'=>150]); 
    foreach($cur as $hv){ 
      $mid=(int)($hv['model_id']??0); 
      $itm=$mid>0?$ii->findOne(['id'=>$mid]):null; 
      $log=$ld->findOne(['model_id'=>$mid,'created_at'=>['$gte'=>(string)($hv['borrowed_at']??'')]], ['sort'=>['id'=>-1]]); 
      $alloc=$ra->findOne(['borrow_id'=>(int)($hv['id']??0)], ['projection'=>['request_id'=>1]]);
      $reqId=(int)($alloc['request_id']??0);
      if ($reqId<=0){
        $when = (string)($hv['borrowed_at'] ?? '');
        $req = null;
        if ($itm){
          $cands = array_values(array_unique(array_filter([(string)($itm['model'] ?? ''),(string)($itm['item_name'] ?? '')])));
          if (!empty($cands)){
            $req = $er->findOne([
              'username'=>(string)$_SESSION['username'],
              'item_name'=>['$in'=>$cands],
              'created_at' => ['$lte' => $when]
            ], ['sort'=>['created_at'=>-1,'id'=>-1], 'projection'=>['id'=>1]]);
            if (!$req) {
              $req = $er->findOne([
                'username'=>(string)$_SESSION['username'],
                'item_name'=>['$in'=>$cands]
              ], ['sort'=>['created_at'=>-1,'id'=>-1], 'projection'=>['id'=>1]]);
            }
          }
        }
        if (!$req){
          $req = $er->findOne([
            'username'=>(string)$_SESSION['username'],
            'created_at' => ['$lte' => $when]
          ], ['sort'=>['created_at'=>-1,'id'=>-1], 'projection'=>['id'=>1]]);
          if (!$req) {
            $req = $er->findOne([
              'username'=>(string)$_SESSION['username']
            ], ['sort'=>['created_at'=>-1,'id'=>-1], 'projection'=>['id'=>1]]);
          }
        }
        if ($req && isset($req['id'])) { $reqId = (int)$req['id']; }
      }
      $my_history[]=[
        'borrow_id'=>(int)($hv['id']??0),
        'request_id'=>$reqId,
        'borrowed_at'=>(string)($hv['borrowed_at']??''),
        'returned_at'=>(string)($hv['returned_at']??''),
        'status'=>(string)($hv['status']??''),
        'latest_action'=>(string)($log['action']??''),
        'model_id'=>$mid,
        'item_name'=>$itm?(string)($itm['item_name']??''):'',
        'model'=>$itm?(string)($itm['model']??''):'',
        'category'=>$itm?(string)($itm['category']??'Uncategorized'):'Uncategorized'
      ]; 
    } 
  } catch (Throwable $e) { $my_history=[]; }
}

// Build allocation map: request_id => count of allocations
$alloc_map = [];
if (!empty($my_requests)) {
  if ($USED_MONGO && $mongo_db) {
    try { $ra=$mongo_db->selectCollection('request_allocations'); foreach ($my_requests as $r){ $rid=(int)($r['id']??0); if ($rid>0){ $alloc_map[$rid]=(int)$ra->countDocuments(['request_id'=>$rid]); } } } catch (Throwable $e) {}
  } elseif ($conn) {
    $ids = array_map(fn($r)=> (int)$r['id'], $my_requests); $ids = array_filter($ids, fn($v)=> $v>0);
    if (!empty($ids)) { $in = implode(',', array_map('intval', $ids)); if ($qr = $conn->query("SELECT request_id, COUNT(*) AS c FROM request_allocations WHERE request_id IN ($in) GROUP BY request_id")) { while ($ar = $qr->fetch_assoc()) { $alloc_map[(int)$ar['request_id']] = (int)$ar['c']; } $qr->close(); } }
  }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Request to Borrow</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__.'/css/style.css'); ?>">
  <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
  <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" />
  <style>
    @media (min-width: 769px) {
      #sidebar-wrapper{ display:block !important; }
      .mobile-menu-toggle{ display:none !important; }
    }
    /* Page-scoped compact styles for the Submit Request panel */
    #submitReqCard.compact-card .card-body { padding: 0.75rem; }
    #submitReqCard.compact-card h5 { margin-bottom: 0.5rem; font-size: 0.95rem; }
    #submitReqCard .compact-form .form-label { display: block; margin-bottom: 0.2rem; font-size: 0.85rem; }
    #submitReqCard .compact-form .form-control,
    #submitReqCard .compact-form .form-select { padding: 0.4rem 0.6rem; font-size: 0.9rem; }
    #submitReqCard .compact-form .form-text { margin-top: 0.1rem; font-size: 0.75rem; }
    #submitReqCard .compact-form .btn-lg { padding: 0.5rem 0.9rem; font-size: 0.95rem; }
    /* New: limit input widths and center them so they don't look too wide */
    #submitReqCard .compact-form .form-control,
    #submitReqCard .compact-form .form-select,
    #submitReqCard .compact-form textarea { max-width: 520px; width: 100%; margin-left: 0; margin-right: 0; }
    /* Reduce vertical spacing between fields */
    #submitReqCard .compact-form .mb-3 { margin-bottom: 0.5rem !important; }
    /* Center the submit button and keep it compact */
    #submitReqCard .compact-form .d-grid { place-items: center; }
    #submitReqCard .compact-form .d-grid .btn { width: auto; }
    /* Center only the Details/Purpose field */
    #submitReqCard .compact-form .center-field { display: flex; flex-direction: column; align-items: center; }
    #submitReqCard .compact-form .center-field .form-label { text-align: center; width: 100%; }
    #submitReqCard .compact-form .center-field .form-control { margin-left: auto; margin-right: auto; }
    /* Disable resize handle on the Details/Purpose textarea */
    #submitReqCard .compact-form textarea { resize: none; }
    /* Allow ~15 rows before My Recent Requests scrolls */
    #recentRequestsCard .table-responsive { max-height: 680px; overflow-y: auto; }
    /* Compact the modal contents (smaller, not thinner) */
    #submitRequestModal .modal-dialog { max-width: 600px; }
    #submitRequestModal .modal-body { padding: 0.75rem 0.9rem; }
    #submitRequestModal .compact-form .form-label { margin-bottom: 0.2rem; font-size: 0.9rem; }
    #submitRequestModal .compact-form .mb-3 { margin-bottom: 0.5rem !important; }
    #submitRequestModal .compact-form .form-control,
    #submitRequestModal .compact-form .form-select { padding: 0.4rem 0.6rem; font-size: 0.95rem; }
    #submitRequestModal .compact-form .form-text { margin-top: 0.1rem; font-size: 0.8rem; }
    #submitRequestModal .compact-form .btn-lg { padding: 0.5rem 0.9rem; font-size: 0.95rem; }
    #submitRequestModal .compact-form textarea { resize: none; }
    /* Mobile header layout: center title, small buttons, wrap & fit notifications */
    @media (max-width: 768px) {
      .page-header { flex-direction: column; align-items: center; text-align: center; padding-top: 10px; padding-bottom: 10px; position: relative; }
      .page-title { text-align: center; }
      .page-header .d-flex.align-items-center.gap-3 { flex-wrap: wrap; justify-content: center; gap: 6px 8px; width: 100%; }
      #tableSwitcherBtn, #openSubmitTopBtn, #userQrBtn, #userBellBtn { padding: .25rem .5rem; font-size: .875rem; }
      /* Bell and QR side-by-side centered below header */
      #userBellWrap { position: static; order: 3; display: inline-flex; align-items: center; justify-content: center; margin-top: 0; z-index: auto; margin-right: 0 !important; }
      #userQrBtn { position: static; order: 3; display: inline-flex; align-items: center; }
      /* Equalize button heights and alignment */
      #userBellBtn, #userQrBtn { height: 36px; line-height: 1; }
      /* View and Submit buttons below the bell */
      .page-header .btn-group { order: 4; width: 100%; display: flex; justify-content: center; margin-top: 6px; }
      #openSubmitTopBtn { order: 4; }
      #userBellDropdown { min-width: 0 !important; width: 95vw !important; max-width: 95vw !important; }
      /* Hide left navigation and hamburger on mobile */
      #sidebar-wrapper{ display:none !important; }
      .mobile-menu-toggle{ display:none !important; }
    }
    #userBellModal{ display:none; position:fixed; inset:0; z-index:1095; align-items:center; justify-content:center; padding:16px; }
    #userBellBackdrop{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1094; }
    #userBellModal .ubm-box{ background:#fff; width:92vw; max-width:520px; max-height:80vh; border-radius:8px; overflow:hidden; box-shadow:0 10px 24px rgba(0,0,0,.25); display:flex; flex-direction:column; }
    #userBellModal .ubm-head{ padding:10px 12px; border-bottom:1px solid #e9ecef; display:flex; align-items:center; justify-content:space-between; font-weight:600; }
    #userBellModal .ubm-close{ background:transparent; border:0; font-size:20px; line-height:1; }
    #userBellModal .ubm-body{ padding:0; overflow:auto; }
  </style>
</head>
<body class="allow-mobile">
  <button class="mobile-menu-toggle d-md-none" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
  <div class="d-flex">
    <div class="bg-light border-end" id="sidebar-wrapper">
      <div class="sidebar-heading py-4 fs-4 fw-bold border-bottom d-flex align-items-center justify-content-center">
        <img src="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" alt="ECA Logo" class="brand-logo me-2" />
        <span>ECA MIS-GMIS</span>
      </div>
  
  <!-- User Overdue Items Modal (top-level) -->
  <div class="modal fade" id="userOverdueModal" tabindex="-1" aria-hidden="true" style="z-index:2002;">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>Overdue Items</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-0">
          <div class="list-group list-group-flush" id="overdueList">
            <div class="text-center small text-muted py-3" id="overdueEmpty">No overdue items.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
  <style>
    #userQrReturnModal #uqrSubmit.btn-primary:disabled,
    #userQrReturnModal #uqrSubmit.btn-primary.disabled {
      background-color: #0d6efd !important;
      border-color: #0d6efd !important;
      color: #fff !important;
      opacity: 1 !important;
    }
    /* Force blue look when a serial is verified, regardless of current btn-* class */
    #userQrReturnModal #uqrSubmit[data-serial]:disabled,
    #userQrReturnModal #uqrSubmit[data-serial].disabled {
      background-color: #0d6efd !important;
      border-color: #0d6efd !important;
      color: #fff !important;
      opacity: 1 !important;
    }
    /* QR Scan Modal (Request): align fields and buttons uniformly and mobile-first */
    #urQrScanModal .input-btn-row { align-items: stretch; }
    #urQrScanModal .input-btn-row > [class^="col-"],
    #urQrScanModal .input-btn-row > [class*=" col-"] { display: grid; }
    #urQrScanModal .input-btn-row .btn { height: 100%; }
    @media (max-width: 767.98px) {
      #urQrScanModal .input-btn-row .btn { height: auto; }
    }
  </style>
      <div class="list-group list-group-flush my-3">
        <a href="user_dashboard.php" class="list-group-item list-group-item-action bg-transparent"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
        <a href="user_request.php" class="list-group-item list-group-item-action bg-transparent fw-bold"><i class="bi bi-clipboard-plus me-2"></i>Request to Borrow</a>
        
        <a href="change_password.php" class="list-group-item list-group-item-action bg-transparent"><i class="bi bi-key me-2"></i>Change Password</a>
        <a href="logout.php" class="list-group-item list-group-item-action bg-transparent" onclick="return confirm('Are you sure you want to logout?');"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
      </div>
    </div>

    <!-- QR Scan Modal for Request page -->
    <div class="modal fade" id="urQrScanModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-qr-code-scan me-2"></i>Scan Item QR</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="urQrReader" style="max-width:520px;margin:0 auto;min-height:300px;background:#f8f9fa;display:flex;align-items:center;justify-content:center;color:#6c757d;font-size:0.9em;">
              <div class="text-center p-3">
                <i class="bi bi-qr-code" style="font-size:2em;opacity:0.3;display:block;margin-bottom:10px;"></i>
                <span>Camera feed will appear here after starting</span>
              </div>
            </div>
            <div class="mt-3">
              <div id="urQrStatus" class="small text-muted"></div>
              <div class="mb-2">
                <select id="urCameraSelect" class="form-select form-select-sm" style="max-width: 300px; margin: 0 auto 10px;">
                  <option value="">-- Select Camera --</option>
                </select>
              </div>
              <div class="d-flex align-items-center mt-2 gap-2">
                <button type="button" class="btn btn-primary" id="urQrStartBtn"><i class="bi bi-camera-video"></i> Start Camera</button>
                <button type="button" class="btn btn-outline-danger d-none" id="urQrStopBtn"><i class="bi bi-stop-circle"></i> Stop</button>
              </div>
              <div class="d-flex align-items-center mt-3 gap-2" id="urReqTypeToggleWrap">
                <div class="btn-group btn-group-sm" role="group" aria-label="Request type">
                  <button type="button" class="btn btn-outline-secondary active" id="urQrTypeImmediate" data-mode="immediate">Immediate</button>
                  <button type="button" class="btn btn-outline-secondary" id="urQrTypeReservation" data-mode="reservation">Reservation</button>
                </div>
              </div>
              <div class="d-flex align-items-center mt-2 gap-2">
                <button type="button" class="btn btn-success d-none" id="urQrRequestBtn">Borrow Item</button>
              </div>
              <div class="mt-2 row g-2 d-none" id="urReqLocWrap">
                <div class="col-12">
                  <input type="text" class="form-control" id="urReqLocation" placeholder="Enter location (room/area)" />
                </div>
                <div class="col-12">
                  <small class="text-muted">Location is required.</small>
                </div>
              </div>

              <div class="mt-2 row g-2 d-none" id="urExpectedWrap">
                <div class="col-12 col-md-6">
                  <label class="form-label fw-bold" for="urExpectedReturn">Expected Return</label>
                  <input type="datetime-local" id="urExpectedReturn" class="form-control" />
                </div>
                <div class="col-12">
                  <div class="row g-2 input-btn-row">
                    <div class="col-12 col-md-8"></div>
                    <div class="col-12 col-md-4 d-grid">
                      <button type="button" class="btn btn-outline-secondary h-100" id="urBorrowSubmit" disabled>Borrow Item</button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="mt-1"><small id="urExpectedHint" class="text-danger d-none"></small></div>

              <div class="mt-2 row g-2 d-none" id="urReserveWrap">
                <div class="col-12 col-md-6">
                  <label class="form-label fw-bold d-flex align-items-center gap-2" for="urResFrom">Reservation Start <small id="urResStartHint" class="text-info"></small></label>
                  <input type="datetime-local" id="urResFrom" class="form-control" />
                </div>
                <div class="col-12 col-md-6">
                  <label class="form-label fw-bold" for="urResTo">Reservation End</label>
                  <input type="datetime-local" id="urResTo" class="form-control" />
                </div>
                <div class="col-12">
                  <div class="row g-2 input-btn-row">
                    <div class="col-12 col-md-8"></div>
                    <div class="col-12 col-md-4 d-grid">
                      <button type="button" class="btn btn-outline-secondary h-100" id="urReserveSubmit" disabled>Reserve Item</button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card mt-3 d-none" id="urInfoCard">
                <div class="card-body">
                  <h6 class="mb-3"><i class="bi bi-info-circle me-2"></i>Scanned Item Information</h6>
                  <div class="row g-3">
                    <div class="col-md-6">
                      <div>
                        <span class="fw-bold">Item Name:</span>
                        <span id="urItemName"></span>
                      </div>
                      <div class="mt-2">
                        <span class="fw-bold">Status:</span>
                        <span class="badge" id="urStatusBadge"></span>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div>
                        <span class="fw-bold">Category:</span>
                        <span id="urCategory"></span>
                      </div>
                      <div class="mt-2">
                        <span class="fw-bold">Location:</span>
                        <span id="urLocation"></span>
                      </div>
                    </div>
                  </div>
                  <div class="row g-3 mt-2">
                    <div class="col-md-6" id="urBorrowedByWrap" style="display:none;">
                      <div>
                        <span class="fw-bold">Borrowed By:</span>
                        <span id="urBorrowedBy"></span>
                      </div>
                    </div>
                    <div class="col-md-6" id="urExpectedReturnWrap" style="display:none;">
                      <div>
                        <span class="fw-bold">Expected Return:</span>
                        <span id="urExpectedReturn"></span>
                      </div>
                    </div>
                  </div>
                  <div class="row g-3 mt-1">
                    <div class="col-md-6" id="urReservedByWrap" style="display:none;">
                      <div>
                        <span class="fw-bold">Reserved By:</span>
                        <span id="urReservedBy"></span>
                      </div>
                    </div>
                    <div class="col-md-6" id="urReservationEndsWrap" style="display:none;">
                      <div>
                        <span class="fw-bold">Reservation Ends:</span>
                        <span id="urReservationEnds"></span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
      
    </div>
    <div class="p-4" id="page-content-wrapper">
      <div class="page-header d-flex justify-content-between align-items-center">
        <h2 class="page-title mb-0 d-flex align-items-center gap-2"><i class="bi bi-clipboard-plus me-2"></i>Request to Borrow</h2>
        <div class="d-flex align-items-center gap-3">
          <div class="btn-group">
            <button id="tableSwitcherBtn" type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" aria-haspopup="true" role="button">
              View: My Recent Requests
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="z-index: 1070;">
              <li><a class="dropdown-item table-switch" href="#" data-section="section-recent">My Recent Requests</a></li>
              <li><a class="dropdown-item table-switch" href="#" data-section="section-overdue">Overdue Items</a></li>
              <li><a class="dropdown-item table-switch" href="#" data-section="section-borrowed">My Borrowed</a></li>
              <li><a class="dropdown-item table-switch" href="#" data-section="section-history">Borrow History</a></li>
            </ul>
          </div>
          <button class="btn btn-primary btn-sm" id="openSubmitTopBtn" data-bs-toggle="modal" data-bs-target="#submitRequestModal">
            <i class="bi bi-pencil-square me-1"></i> Submit Request
          </button>
          <button class="btn btn-light" id="userQrBtn" title="Scan QR" data-bs-toggle="modal" data-bs-target="#urQrScanModal">
            <i class="bi bi-qr-code-scan" style="font-size:1.2rem;"></i>
          </button>
          <div class="position-relative me-2" id="userBellWrap">
            <button type="button" class="btn btn-light position-relative" id="userBellBtn" title="Notifications">
              <i class="bi bi-bell" style="font-size:1.2rem;"></i>
              <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle d-none" id="userBellDot"></span>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow" id="userBellDropdown" style="min-width: 320px !important; max-width: 360px !important; width: auto !important; max-height: 360px; overflow:auto;">
              <div class="px-3 py-2 border-bottom fw-bold small">Request Updates</div>
              <div id="userNotifList" class="list-group list-group-flush small"></div>
              <div class="text-center small text-muted py-2" id="userNotifEmpty">No updates yet.</div>
              <div class="border-top p-2 text-center">
                <a href="user_request.php" class="btn btn-sm btn-outline-primary">Go to Requests</a>
              </div>
            </div>
          </div>
          <!-- Mobile Notifications Modal -->
          <div id="userBellBackdrop" aria-hidden="true"></div>
          <div id="userBellModal" role="dialog" aria-modal="true" aria-labelledby="ubmTitle">
            <div class="ubm-box">
              <div class="ubm-head">
                <div id="ubmTitle" class="small">Request Updates</div>
                <button type="button" class="ubm-close" id="ubmCloseBtn" aria-label="Close">&times;</button>
              </div>
              <div class="ubm-body">
                <div id="userNotifListM" class="list-group list-group-flush small"></div>
                <div class="text-center small text-muted py-2" id="userNotifEmptyM">No updates yet.</div>
                <div class="border-top p-2 text-center">
                  <a href="user_request.php" class="btn btn-sm btn-outline-primary">Go to Requests</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <script>
        document.addEventListener('DOMContentLoaded', function(){
          try {
            var url = new URL(window.location.href);
            if (url.searchParams.get('open_qr') === '1') {
              var el = document.getElementById('urQrScanModal');
              if (el && window.bootstrap && bootstrap.Modal) {
                var inst = bootstrap.Modal.getOrCreateInstance(el);
                inst.show();
              }
            }
          } catch(_) { }
        });
      </script>

      

      <!-- Submit Request Modal (moved out of hidden column so header button works) -->
      <div class="modal fade" id="submitRequestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Submit Request</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <form method="POST" id="borrowForm" class="compact-form">
                <div class="mb-3">
                  <label class="form-label fw-bold d-block">Request Type</label>
                  <input type="hidden" name="req_type" id="req_type" value="immediate" />
                  <ul class="nav nav-tabs" role="tablist" id="requestTypeTabs">
                    <li class="nav-item" role="presentation">
                      <button class="nav-link active" id="tabImmediate" data-bs-toggle="tab" data-bs-target="#paneImmediate" type="button" role="tab" aria-controls="paneImmediate" aria-selected="true" data-req-type="immediate">Immediate Borrow</button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="tabReservation" data-bs-toggle="tab" data-bs-target="#paneReservation" type="button" role="tab" aria-controls="paneReservation" aria-selected="false" data-req-type="reservation">Reservation</button>
                    </li>
                  </ul>
                </div>
                <div class="tab-content border border-top-0 rounded-bottom p-3">
                  <div class="row g-3 mb-2">
                    <div class="col-12">
                      <div class="mb-3">
                        <label class="form-label fw-bold" for="req_location">Location *</label>
                        <input type="text" id="req_location" name="req_location" class="form-control" placeholder="Room/Area" required />
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="mb-3">
                        <label class="form-label fw-bold" for="category">Category *</label>
                        <select id="category" name="category" class="form-select" required>
                          <option value="">Select Category</option>
                          <?php foreach ($catOptions as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="mb-3">
                        <label class="form-label fw-bold" for="model">Model *</label>
                        <select id="model" name="model" class="form-select" required>
                          <option value="">Select Model</option>
                        </select>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="mb-3">
                        <label class="form-label fw-bold" for="quantity">Quantity *</label>
                        <input type="number" id="quantity" name="quantity" class="form-control" min="1" value="1" required />
                        <div id="qtyAvailHint" class="form-text text-muted">Available: 0</div>
                      </div>
                    </div>
                  </div>
                  <div class="tab-content border-0 p-0">
                    <div class="tab-pane fade show active" id="paneImmediate" role="tabpanel" aria-labelledby="tabImmediate" tabindex="0">
                      
                      <div class="row g-3">
                        <div class="col-12">
                          <label class="form-label fw-bold" for="expected_return_at">Expected Return Time <span class="text-danger">*</span></label>
                          <input type="datetime-local" id="expected_return_at" name="expected_return_at" class="form-control" required 
                                 min="<?php echo date('Y-m-d\TH:i'); ?>" />
                          <div id="immediateReserveHint" class="form-text text-danger d-none"></div>
                        </div>
                      </div>
                    </div>
                    <div class="tab-pane fade" id="paneReservation" role="tabpanel" aria-labelledby="tabReservation" tabindex="0">
                      
                      <div class="row g-3">
                        <div class="col-12">
                          <label class="form-label fw-bold d-flex align-items-center gap-2" for="reserved_from">Start Time <span class="text-danger">*</span><small id="reservedStartHint" class="text-info"></small></label>
                          <input type="datetime-local" id="reserved_from" name="reserved_from" class="form-control" 
                                 min="<?php echo date('Y-m-d\TH:i'); ?>" />
                        </div>
                        <div class="col-12">
                          <label class="form-label fw-bold" for="reserved_to">End Time <span class="text-danger">*</span></label>
                          <input type="datetime-local" id="reserved_to" name="reserved_to" class="form-control" 
                                 min="<?php echo date('Y-m-d\TH:i', strtotime('+1 hour')); ?>" />
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="d-grid mt-4">
                  <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-send me-2"></i>Submit Request
                  </button>
                </div>
                <div id="formError" class="alert alert-danger mt-3 d-none" role="alert"></div>
              </form>
              <script>
                // Initialize form with current date/time values
                document.addEventListener('DOMContentLoaded', function() {
                  // Set default times
                  const now = new Date();
                  const defaultReturn = new Date(now);
                  defaultReturn.setHours(now.getHours() + 2); // Default to 2 hours from now
                  
                  // Format for datetime-local input
                  const formatDateTimeLocal = (date) => {
                    const pad = (n) => n < 10 ? '0' + n : n;
                    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
                  };
                  
                  // Set default values
                  document.getElementById('expected_return_at').value = formatDateTimeLocal(defaultReturn);
                  
                  // Set reservation start to next hour, end to 2 hours after that
                  const nextHour = new Date(now);
                  nextHour.setHours(nextHour.getHours() + 1, 0, 0, 0);
                  const twoHoursLater = new Date(nextHour);
                  twoHoursLater.setHours(twoHoursLater.getHours() + 2);
                  
                  document.getElementById('reserved_from').value = formatDateTimeLocal(nextHour);
                  document.getElementById('reserved_to').value = formatDateTimeLocal(twoHoursLater);
                  
                  // Handle tab changes to update the hidden req_type field
                  const tabs = document.querySelectorAll('#requestTypeTabs button[data-bs-toggle="tab"]');
                  tabs.forEach(tab => {
                    tab.addEventListener('click', function() {
                      const type = this.getAttribute('data-req-type');
                      document.getElementById('req_type').value = type;
                      // Reset validation
                      document.getElementById('formError').classList.add('d-none');
                      // Refresh catalog for reservation mode so single-qty in-use items appear
                      try { if (typeof refreshCatalog === 'function') setTimeout(refreshCatalog, 50); } catch(_){ }
                      // Update immediate hint if needed
                      try { if (typeof updateImmediateReserveHint === 'function') setTimeout(updateImmediateReserveHint, 80); } catch(_){ }
                    });
                  });
                });
                
                // Form validation helpers
                let reservationEarliestMin = '';
                function isFormValid(silent){
                  const form = document.getElementById('borrowForm');
                  const reqType = document.getElementById('req_type').value;
                  const errorEl = document.getElementById('formError');
                  if (!silent) errorEl.classList.add('d-none');
                  
                  // Common validations
                  const location = form.elements['req_location']?.value.trim();
                  const category = form.elements['category']?.value;
                  const model = form.elements['model']?.value;
                  const quantity = parseInt(form.elements['quantity']?.value) || 0;
                  
                  if (!location) {
                    if (!silent) showError('Please enter a location');
                    return false;
                  }
                  
                  if (!category) {
                    if (!silent) showError('Please select a category');
                    return false;
                  }
                  
                  if (!model) {
                    if (!silent) showError('Please select a model');
                    return false;
                  }
                  
                  if (quantity < 1) {
                    if (!silent) showError('Please enter a valid quantity');
                    return false;
                  }
                  
                  // Type-specific validations
                  if (reqType === 'immediate') {
                    const returnTime = new Date(form.elements['expected_return_at'].value);
                    const now = new Date();
                    const maxReturnTime = new Date(now);
                    maxReturnTime.setDate(maxReturnTime.getDate() + 1); // 24 hours max
                    
                    if (!returnTime || isNaN(returnTime.getTime())) {
                      if (!silent) showError('Please enter a valid return time');
                      return false;
                    }
                    
                    if (returnTime <= now) {
                      if (!silent) showError('Return time must be in the future');
                      return false;
                    }
                    
                    if (returnTime > maxReturnTime) {
                      if (!silent) showError('Immediate borrow cannot exceed 24 hours');
                      return false;
                    }
                  } else { // Reservation
                    const startTime = new Date(form.elements['reserved_from'].value);
                    const endTime = new Date(form.elements['reserved_to'].value);
                    const now = new Date();
                    
                    if (!startTime || isNaN(startTime.getTime())) {
                      if (!silent) showError('Please enter a valid start time');
                      return false;
                    }
                    
                    if (!endTime || isNaN(endTime.getTime())) {
                      if (!silent) showError('Please enter a valid end time');
                      return false;
                    }
                    
                    if (startTime <= now) {
                      if (!silent) showError('Reservation start time must be in the future');
                      return false;
                    }
                    
                    if (endTime <= startTime) {
                      if (!silent) showError('End time must be after start time');
                      return false;
                    }
                    
                    // Enforce earliest allowed start if we have a hint
                    try {
                      if (reservationEarliestMin) {
                        const m = new Date(reservationEarliestMin.replace(' ', 'T'));
                        if (m && !isNaN(m.getTime()) && startTime < m) {
                          if (!silent) showError('Start time must be at least 5 minutes after the current expected return or reservation end.');
                          return false;
                        }
                      }
                    } catch(_){ }
                  }
                  
                  return true;
                }
                function validateForm(){ return isFormValid(false); }
                function updateSubmitButton(){
                  const form = document.getElementById('borrowForm');
                  const btn = form ? form.querySelector('button[type="submit"]') : null;
                  if (!btn) return;
                  const ok = isFormValid(true);
                  btn.disabled = !ok;
                  if (ok) { btn.classList.add('btn-primary'); btn.classList.remove('btn-secondary','disabled'); }
                  else { btn.classList.add('btn-secondary'); btn.classList.remove('btn-primary'); }
                }
                
                function showError(message) {
                  const errorEl = document.getElementById('formError');
                  errorEl.textContent = message;
                  errorEl.classList.remove('d-none');
                  // Scroll to error
                  errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                (function(){
                  // Preserve Category/Model selections in modal even while page is polling
                  var modalEl = document.getElementById('submitRequestModal');
                  var catEl = document.getElementById('category');
                  var modelEl = document.getElementById('model');
                  if (!modalEl || !catEl || !modelEl) return;
                  function saveSel(){ try{ localStorage.setItem('ur_sel_cat', String(catEl.value||'')); localStorage.setItem('ur_sel_model', String(modelEl.value||'')); }catch(_){ } }
                  var restoring = false;
                  function restoreSel(){
                    if (restoring) return; restoring = true;
                    try {
                      var c = '';
                      try { c = localStorage.getItem('ur_sel_cat') || ''; } catch(_){ c=''; }
                      var m = '';
                      try { m = localStorage.getItem('ur_sel_model') || ''; } catch(_){ m=''; }
                      if (m && modelEl) {
                        if (c && catEl && catEl.value === c) {
                          for (var i=0,has=false;i<modelEl.options.length;i++){ if (modelEl.options[i].value===m){ has=true; break; } }
                          if (has) { modelEl.value = m; }
                        }
                      }
                    } finally { restoring = false; }
                  }
                  function updateModelEnabled(){
                    if (catEl && catEl.value) {
                      modelEl.disabled = false;
                    } else {
                      modelEl.disabled = true;
                    }
                  }
                  // Save whenever user changes selections
                  catEl.addEventListener('change', saveSel);
                  modelEl.addEventListener('change', saveSel);
                  catEl.addEventListener('change', updateModelEnabled);
                  // Restore on open
                  modalEl.addEventListener('shown.bs.modal', function(){ setTimeout(restoreSel, 50); updateModelEnabled(); });
                  // Prevent double submissions: lock form and disable submit button after first submit
                  try {
                    var form = modalEl.querySelector('form');
                    var submitBtn = form ? form.querySelector('button[type="submit"]') : null;
                    if (form) {
                      // Disable/enable on input changes
                      form.addEventListener('input', function(){ updateSubmitButton(); });
                      form.addEventListener('change', function(){ updateSubmitButton(); });
                      // Controlled submit to avoid stuck state
                      form.addEventListener('submit', function(e){
                        if (form.dataset.submitting === '1') { e.preventDefault(); return; }
                        const ok = validateForm();
                        if (!ok) { e.preventDefault(); updateSubmitButton(); return; }
                        form.dataset.submitting = '1';
                        if (submitBtn) { submitBtn.disabled = true; submitBtn.classList.add('disabled'); submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Submitting...'; }
                      });
                    }
                  } catch(_){ }
                })();
    (function(){
      const warnBtn = document.getElementById('overdueWarnBtn');
      const listWrap = document.getElementById('overdueList');
      const emptyEl = document.getElementById('overdueEmpty');
      const overdueModalEl = document.getElementById('userOverdueModal');
      if (!warnBtn || !listWrap || !emptyEl) return;
      function esc(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
      function buildRows(items){
        const rows=[];
        (items||[]).forEach(function(r){
          const rid = parseInt(r.request_id||0,10)||0;
          const bid = parseInt(r.borrow_id||0,10)||0;
          const model = esc(String(r.model||''));
          const cat = esc(String(r.category||'Uncategorized'));
          const due = esc(String(r.expected_return_at||''));
          const days = parseInt(r.overdue_days||0,10)||0;
          const type = String(r.type||'');
          let actions = '';
          if (type.toUpperCase()==='QR' && rid>0){
            actions = '<button type="button" class="btn btn-sm btn-outline-primary open-qr-return" data-reqid="'+rid+'" data-borrow_id="'+bid+'" data-model_name="'+model+'"><i class="bi bi-qr-code-scan"></i> Return via QR</button>';
          }
          rows.push('<div class="list-group-item">'
            + '<div class="d-flex w-100 justify-content-between"><strong>'+model+'</strong><span class="badge bg-danger">Overdue '+days+'d</span></div>'
            + '<div class="small text-muted">Category: '+cat+'</div>'
            + '<div class="small">Due: '+due+'</div>'
            + (actions? ('<div class="mt-2 text-end">'+actions+'</div>') : '')
            + '</div>');
        });
        listWrap.innerHTML = rows.join('');
        emptyEl.style.display = rows.length? 'none':'block';
      }
      function refreshOverdue(){
        fetch('user_request.php?action=my_overdue', { cache:'no-store' })
          .then(r=>r.json())
          .then(d=>{
            const list = (d && Array.isArray(d.overdue)) ? d.overdue : [];
            warnBtn.classList.toggle('d-none', list.length===0);
            if (list.length){ buildRows(list); }
          })
          .catch(()=>{});
      }
      warnBtn.addEventListener('click', function(){
        // Ensure custom bell overlay is closed before opening overdue modal
        try { const bb=document.getElementById('userBellBackdrop'); const bm=document.getElementById('userBellModal'); if (bb) bb.style.display='none'; if (bm) bm.style.display='none'; } catch(_){ }
        // Aggressive cleanup: close any other shown Bootstrap modals and backdrops
        try { document.querySelectorAll('.modal.show').forEach(function(m){ try{ bootstrap.Modal.getOrCreateInstance(m).hide(); }catch(__){} }); } catch(_){ }
        try { document.querySelectorAll('.modal-backdrop').forEach(function(el){ el.remove(); }); } catch(_){ }
        try { document.body.classList.remove('modal-open'); document.body.style.removeProperty('padding-right'); document.body.style.overflow=''; } catch(_){ }
        // Now show Overdue modal fresh
        try{ bootstrap.Modal.getOrCreateInstance(overdueModalEl).show(); }catch(_){ }
      });
      listWrap.addEventListener('click', function(ev){
        const a = ev.target && ev.target.closest? ev.target.closest('.open-qr-return'): null; if (!a) return;
        ev.preventDefault();
        const rid = a.getAttribute('data-reqid')||'';
        const bid = a.getAttribute('data-borrow_id')||'';
        const name = a.getAttribute('data-model_name')||'';
        // Wait for Overdue modal to fully hide before opening QR modal to avoid stacked backdrops
        try{
          const mdl = bootstrap.Modal.getOrCreateInstance(overdueModalEl);
          const onceHidden = function(){
            overdueModalEl.removeEventListener('hidden.bs.modal', onceHidden);
            try {
              const qrModal = document.getElementById('userQrReturnModal');
              if (qrModal){
                const tmp = document.createElement('button');
                tmp.type='button';
                tmp.setAttribute('data-bs-toggle','modal');
                tmp.setAttribute('data-bs-target','#userQrReturnModal');
                tmp.setAttribute('data-reqid', String(rid));
                tmp.setAttribute('data-borrow_id', String(bid));
                tmp.setAttribute('data-model_name', String(name));
                tmp.style.display='none';
                document.body.appendChild(tmp);
                tmp.click();
                setTimeout(()=>{ try{ tmp.remove(); }catch(_){ } }, 500);
              }
            } catch(_){ }
          };
          overdueModalEl.addEventListener('hidden.bs.modal', onceHidden);
          mdl.hide();
        }catch(_){ }
      });
      // Robust backdrop cleanup to avoid stuck dark overlay
      if (overdueModalEl){
        // On show, ensure no stray backdrops are covering the modal
        overdueModalEl.addEventListener('show.bs.modal', function(){
          try { document.querySelectorAll('.modal-backdrop').forEach(function(el){ el.remove(); }); } catch(_){ }
          try { document.body.classList.remove('modal-open'); document.body.style.removeProperty('padding-right'); document.body.style.overflow=''; } catch(_){ }
        });
        overdueModalEl.addEventListener('shown.bs.modal', function(){
          try { document.body.classList.add('modal-open'); } catch(_){ }
          // Keep Bootstrap backdrop behind this modal
          try { document.querySelectorAll('.modal-backdrop').forEach(function(el){ el.style.zIndex = '2000'; }); } catch(_){ }
        });
        overdueModalEl.addEventListener('hidden.bs.modal', function(){
          try { document.querySelectorAll('.modal-backdrop').forEach(function(el){ el.remove(); }); } catch(_){ }
          try { document.body.classList.remove('modal-open'); document.body.style.removeProperty('padding-right'); document.body.style.overflow=''; } catch(_){ }
          // Also ensure custom bell overlay is closed
          try { const bb=document.getElementById('userBellBackdrop'); const bm=document.getElementById('userBellModal'); if (bb) bb.style.display='none'; if (bm) bm.style.display='none'; } catch(_){ }
        });
      }

      // Global safety: when any Bootstrap modal hides, cleanup stale backdrops/body classes
      try{
        document.addEventListener('hidden.bs.modal', function(){
          try { document.querySelectorAll('.modal-backdrop').forEach(function(el){ el.remove(); }); } catch(_){ }
          try { if (!document.querySelector('.modal.show')){ document.body.classList.remove('modal-open'); document.body.style.removeProperty('padding-right'); document.body.style.overflow=''; } } catch(_){ }
        });
      }catch(_){ }
      refreshOverdue();
      setInterval(()=>{ if (document.visibilityState==='visible') refreshOverdue(); }, 15000);
    })();
              </script>
            </div>
          </div>
        </div>
      </div>

      <?php if (isset($_GET['submitted'])): ?><div class="alert alert-success alert-dismissible fade show">Request submitted successfully!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
      <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($message); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

      <div class="row">
        <div class="d-none">
          
  <script>
    (function(){
      try { if (window.__uqr_init_guard) { return; } window.__uqr_init_guard = true; return; } catch(_){ }
      function q(id){ return document.getElementById(id); }
      let scanner = null, scanning = false, lastSerial = '';
      let serialValid = false, currentCameraId = null;
      let currentReqId = 0, currentBorrowId = 0;
      
      // Set status message with optional timeout
      function setStatus(message, className = 'text-muted', timeout = 0) {
        const statusEl = q('uqrStatus');
        if (!statusEl) return;
        
        // Clear any existing timeouts
        if (statusEl.timeoutId) clearTimeout(statusEl.timeoutId);
        
        // Update status
        statusEl.textContent = message;
        statusEl.className = 'small ' + className;
        
        // Set timeout to clear message if specified
        if (timeout > 0) {
          statusEl.timeoutId = setTimeout(() => {
            statusEl.textContent = 'Ready to scan';
            statusEl.className = 'small text-muted';
          }, timeout);
        }
      }
      
      // Stop scanning and clean up
      async function stopScan() {
        if (!scanner || !scanning) return;
        
        try {
          await scanner.stop();
          scanning = false;
          
          // Update UI
          const startBtn = q('uqrStart');
          const stopBtn = q('uqrStop');
          const cameraSelect = q('uqrCamera');
          const refreshBtn = q('uqrRefreshCams');
          
          if (startBtn) startBtn.style.display = 'inline-block';
          if (stopBtn) stopBtn.style.display = 'none';
          if (cameraSelect) cameraSelect.disabled = false;
          if (refreshBtn) refreshBtn.disabled = false;
          
          setStatus('Scanner stopped', 'text-muted');
          
        } catch (err) {
          console.error('Error stopping scanner:', err);
          setStatus('Error stopping scanner: ' + (err.message || 'Unknown error'), 'text-danger');
        }
      }
      
      // Initialize the scanner with the selected camera
      async function startScan() {
        if (scanning) return;
        if (!currentCameraId) {
          try { const devs = await Html5Qrcode.getCameras(); if (devs && devs.length) { currentCameraId = devs[0].id; } } catch(_){ }
        }
        if (!currentCameraId) { setStatus('Please select a camera', 'text-warning'); return; }
        
        const readerEl = q('uqrReader');
        if (!readerEl) {
          setStatus('Scanner container not found', 'text-danger');
          return;
        }
        
        // Clear previous scanner if exists
        if (scanner) {
          try { await scanner.clear(); } 
          catch (e) { console.warn('Error clearing previous scanner:', e); }
        }
        
        // Initialize scanner
        scanner = new Html5Qrcode('uqrReader');
        
        try {
          // Start scanning
          await scanner.start(
            currentCameraId,
            { fps: 10, qrbox: { width: 250, height: 250 } },
            onScan,
            (errorMessage) => {
              // Handle specific error cases
              let displayMsg = errorMessage;
              if (errorMessage.includes('NotAllowedError') || errorMessage.includes('Permission denied')) {
                displayMsg = 'Camera access denied. Please allow camera access in your browser settings.';
              } else if (errorMessage.includes('NotFoundError')) {
                displayMsg = 'No camera found. Please connect a camera and try again.';
              } else if (errorMessage.includes('NotReadableError')) {
                displayMsg = 'Camera is already in use by another application.';
              } else if (errorMessage.includes('OverconstrainedError')) {
                displayMsg = 'Camera does not support the requested constraints.';
              }
              
              setStatus(displayMsg, 'text-danger');
            }
          );
          
          // Update UI
          scanning = true;
          const startBtn = q('uqrStart');
          const stopBtn = q('uqrStop');
          const cameraSelect = q('uqrCamera');
          const refreshBtn = q('uqrRefreshCams');
          
          if (startBtn) startBtn.style.display = 'none';
          if (stopBtn) stopBtn.style.display = 'inline-block';
          if (cameraSelect) cameraSelect.disabled = true;
          if (refreshBtn) refreshBtn.disabled = true;
          
          setStatus('Scanning for QR code...', 'text-primary');
          
        } catch (err) {
          console.error('Scanner error:', err);
          
          // Fallback: try facingMode environment
          try {
            await scanner.start(
              { facingMode: 'environment' },
              { fps: 10, qrbox: { width: 250, height: 250 } },
              onScan,
              ()=>{}
            );
            scanning = true;
            const startBtn = q('uqrStart'); const stopBtn = q('uqrStop'); const cameraSelect = q('uqrCamera'); const refreshBtn = q('uqrRefreshCams');
            if (startBtn) startBtn.style.display = 'none';
            if (stopBtn) stopBtn.style.display = 'inline-block';
            if (cameraSelect) cameraSelect.disabled = true;
            if (refreshBtn) refreshBtn.disabled = true;
            setStatus('Scanning for QR code...', 'text-primary');
          } catch (e2) {
            let errorMsg = 'Error starting camera: ' + ((err && err.message) || (e2 && e2.message) || 'Unknown error');
            setStatus(errorMsg, 'text-danger');
            
            // Reset UI
            const startBtn = q('uqrStart');
            const stopBtn = q('uqrStop');
            const cameraSelect = q('uqrCamera');
            const refreshBtn = q('uqrRefreshCams');
            
            if (startBtn) startBtn.style.display = 'inline-block';
            if (stopBtn) stopBtn.style.display = 'none';
            if (cameraSelect) cameraSelect.disabled = false;
            if (refreshBtn) refreshBtn.disabled = false;
          }
        }
      }
      
      // Handle scan results with strict serial validation against borrowed item
      async function onScan(decodedText) {
        if (!decodedText) return;
        try {
          await stopScan();
          // Extract serial robustly
          let serial = '';
          try {
            const data = JSON.parse(decodedText);
            if (data && typeof data === 'object') {
              serial = String(
                data.serial_no || data.serial || data.sn || data.sid ||
                (data.data && (data.data.serial_no || data.data.serial || data.data.sid)) ||
                ''
              ).trim();
            }
          } catch(_) {}
          if (!serial) {
            let s = String(decodedText||'').trim();
            try {
              if (/^https?:\/\//i.test(s)) {
                const u = new URL(s);
                const p = u.searchParams;
                serial = String(p.get('serial_no')||p.get('serial')||p.get('sn')||p.get('sid')||p.get('id')||'').trim();
                if (!serial) {
                  const parts = u.pathname.split('/').filter(Boolean);
                  if (parts.length) serial = parts[parts.length-1];
                }
              }
            } catch(_) {}
            if (!serial && /^\s*[\w\-]+\s*$/.test(s)) serial = s;
          }
          if (!serial) {
            setStatus('Invalid or missing serial in QR', 'text-danger', 2500);
            setTimeout(() => startScan(), 1200);
            return;
          }
          // Verify exact match against this request's borrowed item
          setStatus('Verifying serial...', 'text-muted');
          const body = 'request_id='+encodeURIComponent(currentReqId)+'&borrow_id='+encodeURIComponent(currentBorrowId||0)+'&serial_no='+encodeURIComponent(serial);
          const resp = await fetch('user_request.php?action=returnship_check', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
          const jr = await resp.json().catch(()=>({ok:false,reason:'Validation failed'}));
          if (!jr || !jr.ok) {
            setStatus('Wrong Serial ID', 'text-danger');
            const submitBtn = document.getElementById('uqrSubmit');
            const submitBtnGray = document.getElementById('uqrSubmitGray');
            if (submitBtn && submitBtnGray) {
              submitBtn.style.display = 'none';
              try { delete submitBtn.dataset.serial; } catch(_) {}
              submitBtnGray.style.display = '';
              submitBtnGray.disabled = true;
            }
            try { serialValid = false; } catch(_) {}
            setTimeout(() => startScan(), 1200);
            return;
          }
          // Success: enable submit (blue) but require location to enable click
          setStatus('Item verified: '+serial, 'text-success');
          const submitBtn = document.getElementById('uqrSubmit');
          const submitBtnGray = document.getElementById('uqrSubmitGray');
          const locInputEl = document.getElementById('uqrLoc');
          if (submitBtn && submitBtnGray) {
            submitBtn.dataset.serial = serial;
            submitBtn.style.display = '';
            submitBtn.disabled = false;
            submitBtnGray.style.display = 'none';
          }
          try { serialValid = true; } catch(_) {}
        } catch (err) {
          console.error('Scan processing error:', err);
          setStatus('Error processing QR code', 'text-danger', 2000);
          setTimeout(() => startScan(), 1200);
        }
      }
      
      // Populate camera selection dropdown
      async function populateCameraSelect() {
        const cameraSelect = q('uqrCamera');
        if (!cameraSelect) return;
        
        try {
          setStatus('Loading cameras...', 'text-muted');
          
          // Request camera permissions first
          await navigator.mediaDevices.getUserMedia({ video: true });
          
          const devices = await Html5Qrcode.getCameras();
          cameraSelect.innerHTML = '';
          
          if (devices.length === 0) {
            setStatus('No cameras found', 'text-warning');
            cameraSelect.innerHTML = '<option value="">No cameras found</option>';
            return;
          }
          
          // Add default option
          const defaultOption = document.createElement('option');
          defaultOption.value = '';
          defaultOption.textContent = 'Select camera...';
          cameraSelect.appendChild(defaultOption);
          
          // Add cameras to dropdown
          devices.forEach(device => {
            const option = document.createElement('option');
            option.value = device.id;
            option.textContent = device.label || `Camera ${cameraSelect.length}`;
            cameraSelect.appendChild(option);
          });
          
          // Restore saved camera preference
          if (userPrefs.cameraId) {
            const savedCam = Array.from(cameraSelect.options).find(
              opt => opt.value === userPrefs.cameraId
            );
            if (savedCam) {
              cameraSelect.value = savedCam.value;
              currentCameraId = savedCam.value;
            }
          }
          
          // If no camera selected and we have cameras, select first one
          if (!currentCameraId && devices.length > 0) {
            cameraSelect.value = devices[0].id;
            currentCameraId = devices[0].id;
          }
          
          setStatus('Ready to scan', 'text-muted');
          
        } catch (err) {
          console.error('Error getting cameras:', err);
          
          let errorMsg = 'Error accessing camera: ' + (err.message || 'Unknown error');
          if (err.name === 'NotAllowedError') {
            errorMsg = 'Camera access was denied. Please allow camera access to use the scanner.';
          } else if (err.name === 'NotFoundError') {
            errorMsg = 'No camera found. Please connect a camera and try again.';
          } else if (err.name === 'NotReadableError') {
            errorMsg = 'Camera is already in use by another application.';
          }
          
          setStatus(errorMsg, 'text-danger');
          cameraSelect.innerHTML = '<option value="">Error loading cameras</option>';
        }
      }
      
      // Initialize the modal
      document.addEventListener('DOMContentLoaded', function() {
        const mdl = q('userQrReturnModal');
        if (!mdl) return;
        
        const reqSpan = q('uqrReq');
        const modelSpan = q('uqrModel');
        const statusEl = q('uqrStatus');
        const locInput = q('uqrLoc');
        const startBtn = q('uqrStart');
        const stopBtn = q('uqrStop');
        const cameraSelect = q('uqrCamera');
        const refreshBtn = q('uqrRefreshCams');
        const submitBtn = q('uqrSubmit');
        const submitBtnGray = q('uqrSubmitGray');
        
        // Initialize camera selection
        populateCameraSelect();
        
        // Handle camera selection change
        if (cameraSelect) {
          cameraSelect.addEventListener('change', function() {
            if (this.value) {
              currentCameraId = this.value;
              userPrefs.cameraId = currentCameraId;
              saveUserPrefs();
              
              // If scanner is running with a different camera, restart it
              if (scanning && scanner) {
                stopScan().then(() => startScan());
              }
            }
          });
        }
        // When user types a location, enable the button if a valid serial was verified; keep blue style when verified
        if (locInput) {
          locInput.addEventListener('input', function(){
            if (!submitBtn || !submitBtnGray) return;
            const hasSerial = !!(submitBtn.dataset && submitBtn.dataset.serial);
            if (hasSerial) {
              submitBtn.style.display = '';
              submitBtn.disabled = false;
              submitBtnGray.style.display = 'none';
            } else {
              submitBtn.style.display = 'none';
              submitBtnGray.style.display = '';
              submitBtnGray.disabled = true;
            }
          });
        }
        
        // Handle refresh cameras button
        if (refreshBtn) {
          refreshBtn.addEventListener('click', populateCameraSelect);
        }
        
        // Handle start/stop buttons
        if (startBtn) {
          startBtn.addEventListener('click', startScan);
        }
        
        if (stopBtn) {
          stopBtn.addEventListener('click', stopScan);
        }
        
        // Handle form submission
        if (submitBtn) {
          submitBtn.addEventListener('click', async function() {
            if (!serialValid || !currentReqId) {
              setStatus('Please scan a valid QR code first', 'text-warning');
              return;
            }
            
            const location = locInput ? locInput.value.trim() : '';
            if (!location) {
              setStatus('Please enter a return location', 'text-warning');
              if (locInput) locInput.focus();
              return;
            }
            
            // Disable form controls during submission
            this.disabled = true;
            if (submitBtnGray) submitBtnGray.disabled = true;
            if (locInput) locInput.disabled = true;
            if (cameraSelect) cameraSelect.disabled = true;
            if (refreshBtn) refreshBtn.disabled = true;
            
            setStatus('Processing return...', 'text-info');
            
            try {
              const formData = new FormData();
              formData.append('request_id', currentReqId);
              formData.append('location', location);
              if (currentBorrowId) formData.append('borrow_id', currentBorrowId);
              
              const response = await fetch('user_request.php?action=process_return', {
                method: 'POST',
                body: formData
              });
              
              const result = await response.json();
              
              if (result && result.success) {
                setStatus('✓ Return processed successfully!', 'text-success');
                
                // Save the successful location for next time
                userPrefs.lastLocation = location;
                saveUserPrefs();
                
                // Close modal and refresh after delay
                setTimeout(() => {
                  const modal = bootstrap.Modal.getInstance(mdl);
                  if (modal) modal.hide();
                  window.location.reload();
                }, 1500);
                
              } else {
                throw new Error(result.error || 'Failed to process return');
              }
              
            } catch (err) {
              console.error('Return error:', err);
              setStatus('Error: ' + (err.message || 'Failed to process return'), 'text-danger');
              
              // Re-enable form controls
              this.disabled = false;
              if (submitBtnGray) submitBtnGray.disabled = true;
              if (locInput) locInput.disabled = false;
              if (cameraSelect) cameraSelect.disabled = false;
              if (refreshBtn) refreshBtn.disabled = false;
            }
          });
        }
        
        // Handle modal show/hide events
        mdl.addEventListener('show.bs.modal', function(event) {
          const button = event.relatedTarget;
          currentReqId = parseInt(button.getAttribute('data-reqid') || '0', 10);
          currentBorrowId = parseInt(button.getAttribute('data-borrow_id') || '0', 10);
          
          // Update UI
          if (reqSpan && button.hasAttribute('data-reqid')) {
            reqSpan.textContent = '#' + button.getAttribute('data-reqid');
          }
          
          if (modelSpan && button.hasAttribute('data-model_name')) {
            modelSpan.textContent = button.getAttribute('data-model_name');
          }
          
          // Reset form
          if (submitBtn && submitBtnGray) {
            try { delete submitBtn.dataset.serial; } catch(_) {}
            submitBtn.style.display = 'none';
            submitBtn.disabled = true;
            submitBtnGray.style.display = '';
            submitBtnGray.disabled = true;
          } else if (submitBtn) {
            try { delete submitBtn.dataset.serial; } catch(_) {}
            submitBtn.style.display = 'none';
            submitBtn.disabled = true;
          }
          serialValid = false;
          
          // Restore last used location
          if (locInput && userPrefs.lastLocation) {
            locInput.value = userPrefs.lastLocation;
          } else if (locInput) {
            locInput.value = '';
          }
          
          // Auto-start scanning if we have a camera
          if (currentCameraId) {
            setTimeout(() => startScan(), 300);
          } else {
            setStatus('Please select a camera', 'text-muted');
          }
        });
        
        // Clean up on modal hide
        mdl.addEventListener('hidden.bs.modal', function() {
          stopScan().catch(console.error);
          
          // Clear scanner
          if (scanner) { 
            scanner.clear().catch(console.error);
            scanner = null;
          }
          
          // Reset state
          currentReqId = 0;
          currentBorrowId = 0;
          serialValid = false;
          
          // Clear the scanner container
          const readerEl = q('uqrReader');
          if (readerEl) readerEl.innerHTML = '';
        });
        
        // Handle Enter key in location field
        if (locInput) {
          locInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              const submitBtn = q('uqrSubmit');
              if (submitBtn && !submitBtn.disabled) {
                submitBtn.click();
              } else if (startBtn && startBtn.style.display !== 'none') {
                startScan();
              }
            }
          });
        }
      });
            cameras = Array.isArray(devs) ? devs : [];
            if (camSel){
              camSel.innerHTML = '';
              if (!cameras.length){ const opt = document.createElement('option'); opt.value=''; opt.textContent='No cameras found'; camSel.appendChild(opt); selectedCamId=''; return; }
              cameras.forEach(function(d, idx){ const opt = document.createElement('option'); opt.value = d.id; opt.textContent = d.label || ('Camera '+(idx+1)); camSel.appendChild(opt); });
              // Try saved, then previously chosen, then back camera, then first
              let prefer = '';
              try { prefer = localStorage.getItem('uqr_camera') || ''; } catch(_){ prefer=''; }
              if (!prefer) prefer = selectedCamId || '';
              if (!prefer){ const back = cameras.find(c => /back|rear|environment/i.test(c.label||'')); if (back) prefer = back.id; }
              if (!prefer) prefer = cameras[0].id;
              camSel.value = prefer; selectedCamId = prefer;
            }
          }).catch(function(){ /* ignore */ });
        }
        function updateSubmitState(){
          const blue = document.getElementById('uqrSubmit');
          const gray = document.getElementById('uqrSubmitGray');
          if (blue && gray){
            if (serialValid){
              blue.style.display = '';
              blue.disabled = false;
              gray.style.display = 'none';
            } else {
              blue.style.display = 'none';
              gray.style.display = '';
              gray.disabled = true;
            }
          } else {
            // Fallback for environments without two-button structure
            if (submitBtn){ submitBtn.disabled = !serialValid; }
          }
        }
        function onScan(txt){
          try{
            let serial='';
            try { const o=JSON.parse(txt); if (o && typeof o==='object') { serial = String(o.serial_no||o.serial||o.sn||o.sid||'').trim(); } } catch(_){ }
            if (!serial){
              try {
                let s = String(txt||'').trim();
                if (/^https?:\/\//i.test(s)) { const u=new URL(s); const p=new URLSearchParams(u.search||''); serial = String(p.get('serial_no')||p.get('serial')||p.get('sn')||p.get('sid')||'').trim(); }
              } catch(_){ }
            }
            if (!serial && /^\s*[\w\-]+\s*$/.test(String(txt||''))) { serial = String(txt).trim(); }
            if (!serial){ setStatus('Invalid QR content','text-danger'); serialValid=false; updateSubmitState(); return; }
            lastSerial = serial;
            // Check with server if this serial is valid for this request+borrow before enabling Verify
            setStatus('Checking serial...','text-info');
            (function(){ const blue=document.getElementById('uqrSubmit'); const gray=document.getElementById('uqrSubmitGray'); if (blue&&gray){ blue.style.display='none'; gray.style.display=''; gray.disabled=true; } })();
            const bodyChk = 'request_id='+encodeURIComponent(currentReqId)+'&borrow_id='+encodeURIComponent(currentBorrowId)+'&serial_no='+encodeURIComponent(lastSerial);
            fetch('user_request.php?action=returnship_check', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: bodyChk })
              .then(r=>r.json())
              .then(function(resp){
                if (resp && resp.ok){ setStatus('Item verified: '+serial, 'text-success'); serialValid=true; stop(); updateSubmitState(); }
                else { setStatus('Wrong Serial ID','text-danger'); serialValid=false; updateSubmitState(); }
              })
              .catch(function(){ setStatus('Network error','text-danger'); (function(){ const blue=document.getElementById('uqrSubmit'); const gray=document.getElementById('uqrSubmitGray'); if (blue&&gray){ blue.style.display='none'; gray.style.display=''; gray.disabled=true; } })(); });
          } catch(_){ setStatus('Scan error','text-danger'); }
        }
        function mapStatusClass(s){ switch(String(s||'')){ case 'Available': return 'bg-success'; case 'In Use': return 'bg-primary'; case 'Maintenance': return 'bg-warning'; case 'Out of Order': return 'bg-danger'; case 'Reserved': return 'bg-info'; case 'Lost': return 'bg-danger'; case 'Damaged': return 'bg-danger'; default: return 'bg-secondary'; } }
        async function onScanBorrow(txt){ stopScan(); try{
          let modelId=0, modelName='', category=''; let serial = String(txt||'').trim();
          
          // Parse QR code data
          try { 
            const d = JSON.parse(txt); 
            if (d) { 
              if (!d.model_id && d.item_id) { 
                d.model_id = d.item_id; 
                delete d.item_id; 
              } 
              modelId = parseInt(d.model_id||0, 10) || 0; 
              modelName = String(d.model||'').trim() || String(d.item_name||'').trim(); 
              category = String(d.category||'').trim(); 
              serial = String(d.serial_no||d.serial||'').trim() || serial; 
            } 
          } catch(_) { 
            // If not JSON, try to extract serial from plain text
            serial = String(txt||'').trim();
          }
          
          if (!serial) { 
            setStatus('No serial number found in QR code.','text-danger'); 
            return; 
          }
          
          // First, verify the serial matches the borrowed item
          setStatus('Verifying serial...', 'text-muted');
          const verifyResponse = await fetch('user_request.php?action=returnship_check', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `request_id=${encodeURIComponent(currentReqId)}&borrow_id=${encodeURIComponent(currentBorrowId)}&serial_no=${encodeURIComponent(serial)}`
          });
          
          const verifyResult = await verifyResponse.json().catch(() => ({}));
          
          if (!verifyResult || !verifyResult.ok) {
            const errorMsg = verifyResult?.reason || 'This QR code does not match the borrowed item.';
            setStatus(errorMsg, 'text-danger');
            return;
          }
          
          // If we get here, the serial is valid for this return
          // Now get the item details for display
          setStatus('Looking up item details...','text-muted');
          const r = await fetch('inventory.php?action=item_by_serial&sid='+encodeURIComponent(serial), {cache:'no-store'});
          const jr = await r.json().catch(() => ({}));
          
          if (!jr || !jr.success || !jr.item) { 
            setStatus('Item details not found.','text-warning'); 
            // Continue with the data we have
            if (!modelId && modelName === '') {
              setStatus('Could not identify item.','text-danger');
              return;
            }
          } else {
            const it = jr.item; 
            modelId = parseInt(it.id||0, 10) || modelId;
            modelName = String(it.model||'').trim() || String(it.item_name||'').trim() || modelName;
            category = String(it.category||'').trim() || category || 'Uncategorized';
          }
          
          if (modelName === '') { 
            modelName = 'Unknown Item'; 
          }
          
          const payload = { 
            model_id: modelId, 
            model: modelName, 
            item_name: modelName, 
            category: category,
            qr_serial_no: serial
          };
          
          lastData = { 
            data: payload, 
            vr: { allowed: true, reason: 'OK' }, 
            serial_no: serial 
          };
          // Show info card basics
          document.getElementById('urItemName').textContent = modelName || '';
          badge.textContent = (vr && vr.status) ? vr.status : (vr.allowed ? 'Available' : 'Unavailable');
          badge.className = 'badge '+mapStatusClass(badge.textContent);
          document.getElementById('urCategory').textContent = category || '';
          document.getElementById('urLocation').textContent = '';
          document.getElementById('urBorrowedByWrap').style.display = 'none';
          document.getElementById('urExpectedReturnWrap').style.display = 'none';
          document.getElementById('urReservedByWrap').style.display = 'none';
          document.getElementById('urReservationEndsWrap').style.display = 'none';
          if (infoCard) infoCard.classList.remove('d-none');
          // Enrich from inventory by serial or id
          try{
            const q = modelId ? String(modelId) : serial;
            const ir = await fetch('inventory.php?action=item_by_serial&sid='+encodeURIComponent(q), {cache:'no-store'});
            const j = await ir.json().catch(()=>({success:false}));
            if (j && j.success && j.item){ const it=j.item; document.getElementById('urItemName').textContent = it.item_name || it.model || document.getElementById('urItemName').textContent; badge.textContent = it.status || badge.textContent; badge.className = 'badge '+mapStatusClass(badge.textContent); document.getElementById('urCategory').textContent = it.category || document.getElementById('urCategory').textContent; document.getElementById('urLocation').textContent = it.location || ''; const borrowedBy = it.borrowed_by_full_name || it.borrowed_by_username || ''; if (borrowedBy){ document.getElementById('urBorrowedBy').textContent = borrowedBy; document.getElementById('urBorrowedByWrap').style.display=''; const exp = it.expected_return_at || ''; document.getElementById('urExpectedReturn').textContent = exp; document.getElementById('urExpectedReturnWrap').style.display = exp ? '' : 'none'; } const reservedBy = it.reservation_by_full_name || it.reservation_by_username || ''; if (!borrowedBy && reservedBy){ document.getElementById('urReservedBy').textContent = reservedBy; document.getElementById('urReservedByWrap').style.display=''; const ends = it.reserved_to || ''; document.getElementById('urReservationEnds').textContent = ends; document.getElementById('urReservationEndsWrap').style.display = ends ? '' : 'none'; } }
          }catch(_){ }
          if (vr && vr.allowed){ setStatus('Item is available. Enter location and expected return to borrow.','text-success'); if (reqLocWrap) reqLocWrap.classList.remove('d-none'); if (expWrap) expWrap.classList.remove('d-none'); const recompute=()=>{ const hasLoc = !!(reqLocInput && reqLocInput.value && reqLocInput.value.trim()); const isFuture = (v)=>{ if(!v) return false; const d=new Date(v); return !isNaN(d.getTime()) && d.getTime()>Date.now(); }; const okExp = !!(expInput && isFuture(expInput.value)); if (borrowBtn) borrowBtn.disabled = !(hasLoc && okExp); }; if (reqLocInput) reqLocInput.oninput = recompute; if (expInput) expInput.addEventListener('input', recompute); recompute(); if (borrowBtn){ borrowBtn.onclick = submitBorrow; } } else { if (reqLocWrap) reqLocWrap.classList.add('d-none'); if (expWrap) expWrap.classList.add('d-none'); setStatus(vr && vr.reason ? vr.reason : 'Cannot borrow','text-danger'); }
        } catch(_){ setStatus('Invalid QR code data.','text-danger'); }
      }
      function submitBorrow(){ if (!lastData || !lastData.vr || !lastData.vr.allowed) return; const vr=lastData.vr; const src=lastData.data||{}; const loc = (reqLocInput && reqLocInput.value ? reqLocInput.value.trim() : ''); if (!loc){ setStatus('Request location is required.','text-danger'); return; } const exp = expInput ? String(expInput.value||'').trim() : ''; if (!exp){ setStatus('Please set Expected Return time.','text-danger'); return; } const form=document.createElement('form'); form.method='POST'; form.action='user_request.php'; const add=(n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=String(v); form.appendChild(i); }; add('category', vr.category || src.category || 'Uncategorized'); add('model', vr.model || (src.model||src.item_name||'')); add('quantity', 1); add('details', 'Requested via QR scan'); add('req_location', loc); add('req_type', 'immediate'); add('expected_return_at', exp); if (lastData && lastData.serial_no){ add('qr_serial_no', lastData.serial_no); } document.body.appendChild(form); form.submit(); }
      mdl.addEventListener('show.bs.modal', function(e){ const btn = e.relatedTarget; currentReqId = btn?.getAttribute('data-reqid')||''; currentBorrowId = btn?.getAttribute('data-borrow_id')||''; const mdlName = btn?.getAttribute('data-model_name')||''; reqSpan.textContent = currentReqId; modelSpan.textContent = mdlName; lastSerial=''; serialValid=false; (function(){ const blue=document.getElementById('uqrSubmit'); const gray=document.getElementById('uqrSubmitGray'); if (blue&&gray){ try{ delete blue.dataset.serial; }catch(_){ } blue.style.display='none'; blue.disabled=true; gray.style.display=''; gray.disabled=true; } })(); setStatus('Scan the item\'s QR.','text-muted'); if (readerDiv) readerDiv.innerHTML=''; if (locInput) locInput.value=''; });
      function cleanupBackdrops(){
        try { document.querySelectorAll('.modal-backdrop').forEach(function(el){ el.parentNode && el.parentNode.removeChild(el); }); } catch(_){ }
        try { document.body.classList.remove('modal-open'); document.body.style.removeProperty('padding-right'); } catch(_){ }
      }
      mdl.addEventListener('hidden.bs.modal', function(){ stop(); cleanupBackdrops(); });
      startBtn.addEventListener('click', startScan);
      stopBtn.addEventListener('click', stop);
      if (camSel){ camSel.addEventListener('change', function(){ selectedCamId = camSel.value || ''; try{ localStorage.setItem('uqr_camera', selectedCamId); }catch(_){ } if (scanning){ stop(); setTimeout(startScan, 200); } }); }
      if (refreshBtn){ refreshBtn.addEventListener('click', function(){ loadCameras(); }); }
      if (locInput){ locInput.addEventListener('input', updateSubmitState); }
      // Load cameras once DOM is ready and again on modal open
      loadCameras();
      mdl.addEventListener('shown.bs.modal', function(){ loadCameras(); });
      submitBtn.addEventListener('click', function(){
        if (!currentReqId || !lastSerial) { setStatus('Missing data','text-danger'); return; }
        const body = 'request_id='+encodeURIComponent(currentReqId)+'&borrow_id='+encodeURIComponent(currentBorrowId)+'&serial_no='+encodeURIComponent(lastSerial)+'&location='+encodeURIComponent(locInput?.value||'');
        submitBtn.disabled = true; (function(){ const gray=document.getElementById('uqrSubmitGray'); if (gray){ gray.disabled=true; } })(); setStatus('Verifying...','text-info');
        fetch('user_request.php?action=returnship_verify', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
          .then(r=>r.json())
          .then(function(resp){
            if (resp && resp.ok){
              setStatus('Verified. Admin can now approve your return.','text-success');
              submitBtn.disabled = true; submitBtn.className='btn btn-primary';
              try{
                // Stop camera/scanner, then hide modal; let hidden.bs.modal do the cleanup
                stop();
                if (window.bootstrap && mdl){
                  var inst = bootstrap.Modal.getOrCreateInstance(mdl);
                  inst.hide();
                  // Safety: ensure backdrop/body class are cleared shortly after hide
                  setTimeout(function(){
                    try { document.querySelectorAll('.modal-backdrop').forEach(function(el){ el.remove(); }); } catch(_){ }
                    try { document.body.classList.remove('modal-open'); document.body.style.removeProperty('padding-right'); } catch(_){ }
                    // Hide any Return via QR buttons for this request immediately
                    try {
                      var sel = 'button[data-bs-target="#userQrReturnModal"][data-reqid="'+ String(currentReqId) +'"]';
                      document.querySelectorAll(sel).forEach(function(btn){ btn.style.display='none'; btn.disabled = true; });
                    } catch(_){ }
                    // Refresh the page to fully restore scroll state and update tables
                    try { setTimeout(function(){ window.location.reload(); }, 150); } catch(_){ }
                  }, 300);
                } else {
                  // Non-bootstrap env fallback
                  cleanupBackdrops();
                  // Also hide buttons and refresh
                  try {
                    var sel2 = 'button[data-bs-target="#userQrReturnModal"][data-reqid="'+ String(currentReqId) +'"]';
                    document.querySelectorAll(sel2).forEach(function(btn){ btn.style.display='none'; btn.disabled = true; });
                  } catch(_){ }
                  try { setTimeout(function(){ window.location.reload(); }, 150); } catch(_){ }
                }
              }catch(_){ }
            } else {
              setStatus(resp && resp.reason ? resp.reason : 'Verification failed','text-danger');
              submitBtn.disabled = false; submitBtn.className='btn btn-secondary';
            }
          })
          .catch(function(){ setStatus('Network error','text-danger'); submitBtn.disabled = false; submitBtn.className='btn btn-secondary'; });
      });
    })();
  </script>
            </div>
        <div class="col-12">
          <div class="row" id="section-recent">
            <div class="col-12">
              <div id="recentRequestsCard" class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>My Recent Requests</strong></div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th>ID</th>
                          <th>Item</th>
                          <th>Qty</th>
                          <th>Status</th>
                          <th>Approved/Rejected by</th>
                          <th>Requested</th>
                        </tr>
                      </thead>
                      <tbody id="myReqTbody">
                        <?php if (empty($my_requests)): ?>
                          <tr><td colspan="6" class="text-center text-muted">No requests yet.</td></tr>
                        <?php else: ?>
                          <?php foreach ($my_requests as $rq): ?>
                            <tr>
                              <td><?php echo (int)$rq['id']; ?></td>
                              <td><?php echo htmlspecialchars($rq['item_name']); ?></td>
                              <td><?php echo (int)$rq['quantity']; ?></td>
                              <td><?php 
                                $st=(string)$rq['status']; 
                                $rid=(int)$rq['id'];
                                $qty=(int)$rq['quantity'];
                                $alloc = (int)($alloc_map[$rid] ?? 0);
                                if ($st==='Rejected') {
                                  if ($qty > 0 && $alloc > 0 && $alloc < $qty) {
                                    $rej = max(0, $qty - $alloc);
                                    $disp = $alloc . '/' . $qty . ' Approved, ' . $rej . '/' . $qty . ' Rejected';
                                  } else {
                                    $disp = 'Rejected';
                                  }
                                }
                                elseif ($alloc >= $qty || in_array($st,['Approved','Borrowed'],true)) { $disp = 'Approved'; }
                                elseif ($alloc > 0 && $qty > 1) { $disp = $alloc . '/' . $qty . ' Approved'; }
                                else { $disp = ($st!==''?$st:'Pending'); }
                                echo htmlspecialchars($disp);
                              ?></td>
                              <td><?php 
                                $name = '';
                                $st=(string)$rq['status'];
                                $ab = trim((string)($rq['approved_by'] ?? ''));
                                $rb = trim((string)($rq['rejected_by'] ?? ''));
                                if ($st==='Rejected') { $name = ($rb !== '' ? $rb : 'Auto Rejected'); }
                                elseif (in_array($st,['Approved','Borrowed','Returned'],true)) { $name = ($ab !== '' ? $ab : ''); }
                                echo htmlspecialchars($name);
                              ?></td>
                              <td><?php echo htmlspecialchars(date('h:i A m-d-y', strtotime($rq['created_at']))); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="row" id="section-overdue" style="display:none;">
            <div class="col-12">
              <div id="overdueCard" class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Overdue Items</strong></div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th>Request ID</th>
                          <th>Model Name</th>
                          <th>Category</th>
                          <th>Status</th>
                        </tr>
                      </thead>
                      <tbody id="myOverdueTbody">
                        <tr><td colspan="4" class="text-center text-muted">Loading...</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="row" id="section-borrowed" style="display:none;">
            <div class="col-12">
              <div id="borrowedCard" class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>My Borrowed</strong></div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th>Request ID</th>
                          <th>Type</th>
                          <th>Model Name</th>
                          <th>Status</th>
                          <th class="text-end">Actions</th>
                        </tr>
                      </thead>
                      <tbody id="myBorrowedTbody">
                        <?php if (empty($my_borrowed)): ?>
                          <tr><td colspan="5" class="text-center text-muted">No active borrowed items.</td></tr>
                        <?php else: foreach ($my_borrowed as $b): ?>
                          <tr class="borrowed-row" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#userBorrowedDetailsModal"
                              data-category="<?php echo htmlspecialchars(($b['category'] ?: 'Uncategorized')); ?>"
                              data-approved_at="<?php $appr=(string)($b['approved_at'] ?? ''); echo htmlspecialchars($appr !== '' ? date('h:i A m-d-y', strtotime($appr)) : ($b['borrowed_at'] ? date('h:i A m-d-y', strtotime($b['borrowed_at'])) : '')); ?>"
                              data-approved_by="<?php echo htmlspecialchars((string)($b['approved_by'] ?? '')); ?>">
                            <td><?php echo htmlspecialchars((string)((isset($b['request_id']) && (int)$b['request_id']>0) ? $b['request_id'] : ($b['borrow_id'] ?? ''))); ?></td>
                            <td><?php echo (isset($b['qr_serial_no']) && trim((string)$b['qr_serial_no']) !== '') ? 'QR' : 'Manual'; ?></td>
                            <td><?php echo htmlspecialchars(($b['model'] ?: ($b['item_name'] ?: ($b['model_display'] ?? ''))) ?? ''); ?></td>
                            <td><span class="badge bg-warning text-dark">Borrowed</span></td>
                            <td class="text-end">
                              <?php $isQr = false; if (isset($b['request_id'])) { try { $___r = $USED_MONGO && $mongo_db ? $mongo_db->selectCollection('equipment_requests')->findOne(['id'=>(int)$b['request_id']], ['projection'=>['qr_serial_no'=>1]]) : null; $isQr = $___r && isset($___r['qr_serial_no']) && trim((string)$___r['qr_serial_no'])!==''; } catch (Throwable $_) { $isQr=false; } } ?>
                              <?php if ($isQr): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary open-qr-return" data-bs-toggle="modal" data-bs-target="#userQrReturnModal" data-reqid="<?php echo (int)$b['request_id']; ?>" data-borrow_id="<?php echo (int)$b['borrow_id']; ?>" data-model_name="<?php echo htmlspecialchars(($b['model'] ?: ($b['item_name'] ?: ($b['model_display'] ?? ''))) ?? ''); ?>"><i class="bi bi-qr-code-scan"></i> Return via QR</button>
                              <?php else: ?>
                                —
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="row" id="section-history" style="display:none;">
            <div class="col-12">
              <div id="historyCard" class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Borrow History</strong></div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th>Request ID</th>
                          <th>Model Name</th>
                          <th>Borrowed At</th>
                          <th>Returned At</th>
                          <th>Category</th>
                          <th>Status</th>
                        </tr>
                      </thead>
                      <tbody id="myHistoryTbody">
                        <?php if (empty($my_history)): ?>
                          <tr><td colspan="6" class="text-center text-muted">No history yet.</td></tr>
                        <?php else: foreach ($my_history as $hv): ?>
                          <tr>
                            <td><?php echo htmlspecialchars((string)((isset($hv['request_id']) && (int)$hv['request_id']>0) ? $hv['request_id'] : ($hv['borrow_id'] ?? ''))); ?></td>
                            <td><?php echo htmlspecialchars(($hv['model'] ?: $hv['item_name']) ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($hv['borrowed_at'] ? date('h:i A m-d-y', strtotime($hv['borrowed_at'])) : ''); ?></td>
                            <td><?php echo htmlspecialchars($hv['returned_at'] ? date('h:i A m-d-y', strtotime($hv['returned_at'])) : ''); ?></td>
                            <td><?php echo htmlspecialchars(($hv['category'] ?: 'Uncategorized')); ?></td>
                            <td><?php 
                              $stRaw = (string)($hv['latest_action'] ?? ($hv['status'] ?? ''));
                              $stShow = ($stRaw === 'Under Maintenance') ? 'Damaged' : $stRaw;
                              if ($stRaw === 'Found' || $stRaw === 'Fixed') { $stShow = 'Returned'; }
                              $badge = 'secondary';
                              if ($stShow === 'Returned') { $badge = 'success'; }
                              elseif ($stShow === 'Lost') { $badge = 'danger'; }
                              elseif ($stShow === 'Damaged') { $badge = 'warning'; }
                              echo '<span class="badge bg-'.htmlspecialchars($badge).'">'.htmlspecialchars($stShow).'</span>';
                            ?></td>
                          </tr>
                        <?php endforeach; endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      
    </div>
  </div>

  <!-- User QR Return Modal (moved outside hidden column) -->
  <div class="modal fade" id="userQrReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-qr-code-scan me-2"></i>Return via QR</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>Request ID:</strong> <span id="uqrReq"></span></div>
          <div class="mb-2"><strong>Item:</strong> <span id="uqrModel"></span></div>
          <div class="mb-2"><small id="uqrStatus" class="text-muted">Scan the item's QR.</small></div>
          <div id="uqrReader" class="border rounded p-2 mb-2" style="max-width:360px; min-height:280px;"></div>
          <div class="mb-2 d-flex align-items-center gap-2">
            <label for="uqrCamera" class="form-label small mb-0">Camera</label>
            <select id="uqrCamera" class="form-select form-select-sm" style="max-width: 320px;"></select>
            <button type="button" id="uqrRefreshCams" class="btn btn-sm btn-outline-secondary">Refresh</button>
          </div>
          <div class="d-flex gap-2 mb-2">
            <button type="button" id="uqrStart" class="btn btn-success btn-sm"><i class="bi bi-camera-video"></i> Start</button>
            <button type="button" id="uqrStop" class="btn btn-danger btn-sm" style="display:none;"><i class="bi bi-stop-circle"></i> Stop</button>
          </div>
          <div class="mb-2">
            <input type="file" id="uqrImageFile" class="form-control form-control-sm" accept="image/*" capture="environment" />
          </div>
          <div class="mb-3">
            <label class="form-label small">Return Location</label>
            <input type="text" class="form-control" id="uqrLoc" placeholder="e.g. Storage Room A" required />
          </div>
          <div class="text-end">
            <button type="button" id="uqrSubmit" class="btn btn-primary" style="display:none;"><i class="bi bi-check2"></i> Verify Return</button>
            <button type="button" id="uqrSubmitGray" class="btn btn-secondary" disabled><i class="bi bi-check2"></i> Verify Return</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // User preferences for camera
    const USER_PREFS_KEY = 'qrReturnPrefs';
    let userPrefs = JSON.parse(localStorage.getItem(USER_PREFS_KEY) || '{}');
    
    // Save user preferences
    function saveUserPrefs() {
      localStorage.setItem(USER_PREFS_KEY, JSON.stringify(userPrefs));
    }
    
    // Initialize scanner when document is ready
    document.addEventListener('DOMContentLoaded', function() {
      initQRScanner();
    });
    
    // Main QR Scanner Initialization
    function initQRScanner() {
      const modal = document.getElementById('userQrReturnModal');
      if (!modal) return;
      
      // Elements
      const statusEl = document.getElementById('uqrStatus');
      const startBtn = document.getElementById('uqrStart');
      const stopBtn = document.getElementById('uqrStop');
      const cameraSelect = document.getElementById('uqrCamera');
      const refreshBtn = document.getElementById('uqrRefreshCams');
      const submitBtn = document.getElementById('uqrSubmit');
      const submitBtnGray = document.getElementById('uqrSubmitGray');
      const readerEl = document.getElementById('uqrReader');
      const locInput = document.getElementById('uqrLoc');
      
      let scanner = null;
      let scanning = false;
      let currentCameraId = userPrefs.cameraId || '';
      let currentReqId = 0;
      let currentBorrowId = 0;
      
      // Status message helper
      function setStatus(message, className = 'text-muted', timeout = 0) {
        if (!statusEl) return;
        statusEl.textContent = message;
        statusEl.className = 'small ' + className;
        
        if (timeout > 0) {
          setTimeout(() => {
            if (statusEl.textContent === message) {
              statusEl.textContent = 'Ready to scan';
              statusEl.className = 'small text-muted';
            }
          }, timeout);
        }
      }
      
      // Stop scanning
      async function stopScan() {
        if (!scanner || !scanning) return;
        
        try {
          await scanner.stop();
          scanning = false;
          
          if (startBtn) startBtn.style.display = 'inline-block';
          if (stopBtn) stopBtn.style.display = 'none';
          if (cameraSelect) cameraSelect.disabled = false;
          if (refreshBtn) refreshBtn.disabled = false;
          
          setStatus('Scanner stopped', 'text-muted');
          
        } catch (err) {
          console.error('Error stopping scanner:', err);
          setStatus('Error stopping scanner', 'text-danger');
        }
      }
      
      // Start scanning with selected camera
      // Enhanced scanner configuration
      const getScannerConfig = () => ({
        fps: 10,
        qrbox: { width: 250, height: 250 },
        aspectRatio: 1.0,
        // Enable experimental features for better barcode detection
        experimentalFeatures: {
          useBarCodeDetectorIfSupported: true,
          // Add more experimental features for better detection
          tryHarder: true,
          useAdaptiveThreshold: true,
          useHybridThresholding: true
        },
        // Support multiple formats with better compatibility
        formatsToSupport: [
          Html5QrcodeSupportedFormats.QR_CODE,
          Html5QrcodeSupportedFormats.UPC_A,
          Html5QrcodeSupportedFormats.UPC_E,
          Html5QrcodeSupportedFormats.UPC_EAN_EXTENSION,
          Html5QrcodeSupportedFormats.CODE_128,
          Html5QrcodeSupportedFormats.EAN_13,
          Html5QrcodeSupportedFormats.EAN_8,
          Html5QrcodeSupportedFormats.CODABAR,
          Html5QrcodeSupportedFormats.CODE_39,
          Html5QrcodeSupportedFormats.CODE_93,
          Html5QrcodeSupportedFormats.ITF,
          Html5QrcodeSupportedFormats.RSS_14,
          Html5QrcodeSupportedFormats.RSS_EXPANDED,
          Html5QrcodeSupportedFormats.PDF_417,
          Html5QrcodeSupportedFormats.AZTEC,
          Html5QrcodeSupportedFormats.DATA_MATRIX,
          Html5QrcodeSupportedFormats.MAXICODE
        ],
        // Add more robust scanning options
        useBarcodeDetectorIfSupported: true,
        showTorchButtonIfSupported: true,
        showZoomSliderIfSupported: true,
        defaultZoomValueIfSupported: 2,
        disableFlip: false,
        videoConstraints: {
          facingMode: 'environment',
          width: { min: 640, ideal: 1280, max: 1920 },
          height: { min: 480, ideal: 720, max: 1080 },
          // Add focus mode for better scanning
          focusMode: 'continuous',
          // Add torch mode for low light conditions
          torch: false
        }
      });

      // Enhanced error handler
      const handleScannerError = (errorMessage) => {
        console.log('Scanner error:', errorMessage);
        
        // Skip common non-critical errors
        if (errorMessage.includes('No MultiFormat Readers') || 
            errorMessage.includes('No barcode detected') ||
            errorMessage.includes('No QR code found')) {
          return; // These are normal during scanning
        }
        
        // Handle specific error cases
        let displayMsg = errorMessage;
        if (errorMessage.includes('NotAllowedError') || errorMessage.includes('Permission denied')) {
          displayMsg = 'Camera access denied. Please allow camera access in your browser settings.';
        } else if (errorMessage.includes('NotFoundError')) {
          displayMsg = 'No camera found. Please connect a camera and try again.';
        } else if (errorMessage.includes('NotReadableError')) {
          displayMsg = 'Camera is already in use by another application.';
        } else if (errorMessage.includes('OverconstrainedError')) {
          displayMsg = 'Camera does not support the requested constraints.';
        } else if (errorMessage.includes('Could not start video source')) {
          displayMsg = 'Could not access camera. It may be in use by another application.';
        }
        
        setStatus(displayMsg, 'text-danger');
      };

      async function handleReturnDecoded(decodedText){
        if (!decodedText) return;
        try{
          await stopScan();
          let serial = '';
          try {
            const data = JSON.parse(decodedText);
            if (data && typeof data === 'object') {
              serial = String(data.serial_no||data.serial||data.sn||data.sid||'').trim();
              if (!serial && data.data) serial = String(data.data.serial_no||data.data.serial||data.data.sid||'').trim();
            }
          } catch(_) {}
          if (!serial){
            let s=String(decodedText||'').trim();
            try{
              if (/^https?:\/\//i.test(s)){
                const u=new URL(s); const p=u.searchParams;
                serial = String(p.get('serial_no')||p.get('serial')||p.get('sn')||p.get('sid')||p.get('id')||'').trim();
                if (!serial){ const parts=u.pathname.split('/').filter(Boolean); if (parts.length) serial=parts[parts.length-1]; }
              }
            }catch(_){ }
            if (!serial && /^\s*[\w\-]+\s*$/.test(s)) serial = s;
          }
          if (!serial){ 
            setStatus('Invalid QR content','text-danger'); 
            setTimeout(()=>startScan(), 1000); 
            return; 
          }
          setStatus('Verifying serial...','text-muted');
          const body='request_id='+encodeURIComponent(currentReqId)+'&borrow_id='+encodeURIComponent(currentBorrowId||0)+'&serial_no='+encodeURIComponent(serial);
          const r=await fetch('user_request.php?action=returnship_check',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
          const jr=await r.json().catch(()=>({ok:false}));
          if (!jr || !jr.ok){
            setStatus(jr && jr.reason ? String(jr.reason) : 'Wrong Serial ID','text-danger');
            try{
              const sb=document.getElementById('uqrSubmit');
              const gb=document.getElementById('uqrSubmitGray');
              if (sb && gb){ 
                try{ delete sb.dataset.serial; }catch(_){ } 
                sb.style.display='none'; 
                gb.style.display=''; 
                gb.disabled=true; 
              }
            }catch(_){ }
            setTimeout(()=>startScan(), 1000);
            return;
          }
          setStatus('Item verified: '+serial,'text-success');
          try{
            const sb=document.getElementById('uqrSubmit');
            const gb=document.getElementById('uqrSubmitGray');
            if (sb && gb){ 
              sb.dataset.serial=serial; 
              sb.style.display=''; 
              sb.disabled=false; 
              gb.style.display='none'; 
              try{ sb.focus(); }catch(_){ } 
            }
          }catch(_){ }
        }catch(e){ 
          setStatus('Scan error','text-danger'); 
          setTimeout(()=>startScan(), 1000); 
        }
      }

      async function startScan() {
        if (scanning) {
          return;
        }
        
        if (!readerEl) {
          setStatus('Scanner container not found', 'text-danger');
          return;
        }
        
        // Clear previous scanner if exists
        if (scanner) {
          try { 
            await scanner.clear();
            scanner = null; // Ensure we create a new instance
          } catch (e) { 
            console.warn('Error clearing previous scanner:', e); 
          }
        }
        
        scanner = new Html5Qrcode('uqrReader');

        try {
          const config = getScannerConfig();
          
          // Clear any previous scanner instances
          if (scanner && scanner._html5Qrcode) {
            try {
              await scanner._html5Qrcode.clear();
            } catch (e) {
              console.warn('Error clearing previous scanner instance:', e);
            }
          }
          
          const onReturnScan = (txt) => { try{ handleReturnDecoded(txt); }catch(_){ } };
          let started = false;
          if (currentCameraId) {
            try {
              await scanner.start(currentCameraId, config, onReturnScan, handleScannerError);
              started = true;
            } catch(e1){
              started = false;
            }
          }
          if (!started) {
            await scanner.start({ facingMode: 'environment' }, config, onReturnScan, handleScannerError);
            started = true;
          }

          // Update UI state
          scanning = true;
          if (startBtn) startBtn.style.display = 'none';
          if (stopBtn) stopBtn.style.display = 'inline-block';
          if (cameraSelect) cameraSelect.disabled = true;
          if (refreshBtn) refreshBtn.disabled = true;
          
          setStatus('Scanning for QR code...', 'text-primary');
          try{ var v=document.querySelector('#uqrReader video'); if(v){ v.setAttribute('playsinline',''); v.setAttribute('webkit-playsinline',''); v.muted=true; } }catch(_){ }
          
        } catch (err) {
          console.error('Scanner initialization error:', err);
          handleScannerError(err.message || 'Failed to start scanner');
          
          // Reset UI state on error
          scanning = false;
          if (startBtn) startBtn.style.display = 'inline-block';
          if (stopBtn) stopBtn.style.display = 'none';
          if (cameraSelect) cameraSelect.disabled = false;
          if (refreshBtn) refreshBtn.disabled = false;
        }
      }
      
      // Enhanced scan success handler with better parsing and validation
      async function onScanSuccess(decodedText, decodedResult) {
        console.log('QR scan success:', { decodedText, decodedResult });
        
        // Add a small delay to prevent rapid multiple scans
        const now = Date.now();
        if (window.lastScanTime && (now - window.lastScanTime) < 1000) {
          console.log('Skipping rapid scan');
          return;
        }
        window.lastScanTime = now;
        
        if (!decodedText) {
          console.log('No text in QR code');
          setStatus('Empty QR code detected', 'text-warning', 2000);
          return;
        }
        
        try {
          console.log('Processing scan result...');
          
          // Stop scanning temporarily while we process
          await stopScan();
          
          // Process the scanned data with enhanced parsing
          let modelId = 0, modelName = '', category = '', serial = '';
          
          try {
            // Try to parse as JSON (handle both direct and URL-encoded JSON)
            let data = null;
            
            // First try direct JSON parse
            try {
              data = JSON.parse(decodedText);
            } catch (e1) {
              // If direct parse fails, try URL-decoding first
              try {
                const decoded = decodeURIComponent(decodedText);
                if (decoded !== decodedText) {
                  data = JSON.parse(decoded);
                }
              } catch (e2) {
                // If still not JSON, try to extract from URL parameters
                try {
                  const urlParams = new URLSearchParams(decodedText);
                  if (urlParams) {
                    data = Object.fromEntries(urlParams.entries());
                  }
                } catch (e3) {
                  // Not JSON or URL-encoded, will handle as plain text below
                }
              }
            }
            
            // Extract data from parsed object or use as plain text
            if (data && typeof data === 'object') {
              modelId = parseInt(
                data.model_id || data.item_id || data.id || 
                (data.data && (data.data.model_id || data.data.item_id || data.data.id)) || 0, 
              10);
              
              modelName = (
                data.model || data.name || data.item_name || 
                (data.data && (data.data.model || data.data.name || data.data.item_name)) ||
                ''
              ).trim();
              
              category = (
                data.category || 
                (data.data && data.data.category) ||
                ''
              ).trim();
              
              serial = (
                data.serial_no || data.serial || data.code || data.qr_code ||
                (data.data && (data.data.serial_no || data.data.serial || data.data.code)) ||
                ''
              ).trim();
            } 
            
            // If no serial found in JSON, use the full text as fallback
            if (!serial) {
              serial = String(decodedText || '').trim();
            }
            
            console.log('Parsed scan data:', { modelId, modelName, category, serial });
            
          } catch (e) {
            console.error('Error parsing scan data:', e);
            // Fallback to using raw text as serial
            serial = String(decodedText || '').trim();
            console.log('Using raw text as serial:', serial);
          }
          
          // Validate the scanned item against expected model if available
          const expectedModel = document.getElementById('uqrModel')?.textContent.trim() || '';
          
          if (expectedModel && modelName && expectedModel !== modelName) {
            const errorMsg = `Scanned item (${modelName}) does not match expected (${expectedModel})`;
            console.warn(errorMsg);
            setStatus(errorMsg, 'text-warning', 3000);
            // Restart scanning after delay
            setTimeout(() => startScan(), 2000);
            return;
          }
          
          // If we get here, the scan was successful
          const displayText = modelName || serial || 'Unknown Item';
          setStatus('✓ Item verified: ' + displayText, 'text-success');
          
          // Enable the submit button and store data
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.focus();
            
            // Store the data for form submission
            submitBtn.dataset.serial = serial || '';
            submitBtn.dataset.modelId = modelId || '';
            submitBtn.dataset.modelName = modelName || '';
            submitBtn.dataset.category = category || '';
            
            // Auto-fill location if empty
            if (locInput && !locInput.value.trim()) {
              locInput.value = 'Main Office'; // Default location
            }
            
            // Log successful scan for debugging
            console.log('Scan successful, ready for submission:', {
              serial,
              modelId,
              modelName,
              category,
              expectedModel
            });
          }
          
        } catch (err) {
          console.error('Error processing scan:', err);
          setStatus('Error: ' + (err.message || 'Failed to process QR code'), 'text-danger', 3000);
          // Restart scanning after error with delay
          setTimeout(() => startScan(), 2000);
        }
      }
      
      // Populate camera selection dropdown
      async function populateCameraSelect() {
        if (!cameraSelect) return;
        
        try {
          setStatus('Loading cameras...', 'text-muted');
          
          // Request camera permissions first
          await navigator.mediaDevices.getUserMedia({ video: true });
          
          const devices = await Html5Qrcode.getCameras();
          cameraSelect.innerHTML = '';
          
          if (devices.length === 0) {
            setStatus('No cameras found', 'text-warning');
            cameraSelect.innerHTML = '<option value="">No cameras found</option>';
            return;
          }
          
          // Add default option
          const defaultOption = document.createElement('option');
          defaultOption.value = '';
          defaultOption.textContent = 'Select camera...';
          cameraSelect.appendChild(defaultOption);
          
          // Add cameras to dropdown
          devices.forEach(device => {
            const option = document.createElement('option');
            option.value = device.id;
            option.textContent = device.label || `Camera ${cameraSelect.length}`;
            cameraSelect.appendChild(option);
          });
          
          // Restore saved camera preference
          if (userPrefs.cameraId) {
            const savedCam = Array.from(cameraSelect.options).find(
              opt => opt.value === userPrefs.cameraId
            );
            if (savedCam) {
              cameraSelect.value = savedCam.value;
              currentCameraId = savedCam.value;
            }
          }
          
          // If no camera selected and we have cameras, select first one
          if (!currentCameraId && devices.length > 0) {
            cameraSelect.value = devices[0].id;
            currentCameraId = devices[0].id;
          }
          
          setStatus('Ready to scan', 'text-muted');
          
        } catch (err) {
          console.error('Error getting cameras:', err);
          
          let errorMsg = 'Error accessing camera: ' + (err.message || 'Unknown error');
          if (err.name === 'NotAllowedError') {
            errorMsg = 'Camera access was denied. Please allow camera access to use the scanner.';
          } else if (err.name === 'NotFoundError') {
            errorMsg = 'No camera found. Please connect a camera and try again.';
          } else if (err.name === 'NotReadableError') {
            errorMsg = 'Camera is already in use by another application.';
          }
          
          setStatus(errorMsg, 'text-danger');
          cameraSelect.innerHTML = '<option value="">Error loading cameras</option>';
        }
      }
      
      // Event Listeners
      if (cameraSelect) {
        cameraSelect.addEventListener('change', function() {
          if (this.value) {
            currentCameraId = this.value;
            userPrefs.cameraId = currentCameraId;
            saveUserPrefs();
            
            // If scanner is running with a different camera, restart it
            if (scanning && scanner) {
              stopScan().then(() => startScan());
            }
          }
        });
      }
      
      if (refreshBtn) {
        refreshBtn.addEventListener('click', populateCameraSelect);
      }
      
      if (startBtn) {
        startBtn.addEventListener('click', startScan);
      }
      
      if (stopBtn) {
        stopBtn.addEventListener('click', stopScan);
      }
      (function(){ var img=document.getElementById('uqrImageFile'); if(!img) return; img.addEventListener('change', function(){ var f=this.files&&this.files[0]; if(!f){return;} try{ stopScan(); }catch(_){ } setStatus('Processing image...','text-info'); function tryScanSequence(file){ if (typeof Html5Qrcode !== 'undefined' && typeof Html5Qrcode.scanFile === 'function') { return Html5Qrcode.scanFile(file, true).catch(function(){ return Html5Qrcode.scanFile(file, false); }).catch(function(){ var inst = new Html5Qrcode('uqrReader'); return inst.scanFile(file, true).finally(function(){ try{inst.clear();}catch(e){} }); }); } var inst2 = new Html5Qrcode('uqrReader'); return inst2.scanFile(file, true).finally(function(){ try{inst2.clear();}catch(e){} }); } tryScanSequence(f).then(function(txt){ handleReturnDecoded(txt); }).catch(function(){ setStatus('No QR found in image.','text-danger'); }); }); })();
      
      if (submitBtn) {
        submitBtn.addEventListener('click', async function() {
          const serial = this.dataset.serial;
          if (!serial) {
            setStatus('Please scan a valid QR code first', 'text-warning');
            return;
          }
          
          const location = locInput ? locInput.value.trim() : '';
          if (!location) {
            setStatus('Please enter a return location', 'text-warning');
            if (locInput) locInput.focus();
            return;
          }
          
          // Disable form controls during submission
          this.disabled = true;
          if (locInput) locInput.disabled = true;
          if (cameraSelect) cameraSelect.disabled = true;
          if (refreshBtn) refreshBtn.disabled = true;
          
          setStatus('Processing return...', 'text-info');
          
          try {
            const formData = new FormData();
            formData.append('request_id', currentReqId);
            formData.append('borrow_id', currentBorrowId);
            formData.append('serial_no', serial);
            formData.append('location', location);
            
            const response = await fetch('user_request.php?action=process_return', {
              method: 'POST',
              body: formData
            });
            
            const result = await response.json();
            
            if (result && result.success) {
              setStatus('✓ Return processed successfully!', 'text-success');
              
              // Save the successful location for next time
              userPrefs.lastLocation = location;
              saveUserPrefs();
              
              // Close modal and refresh after delay
              setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(modal);
                if (modal) modal.hide();
                window.location.reload();
              }, 1500);
              
            } else {
              throw new Error(result.error || 'Failed to process return');
            }
            
          } catch (err) {
            console.error('Return error:', err);
            setStatus('Error: ' + (err.message || 'Failed to process return'), 'text-danger');
            
            // Re-enable form controls
            this.disabled = false;
            if (locInput) locInput.disabled = false;
            if (cameraSelect) cameraSelect.disabled = false;
            if (refreshBtn) refreshBtn.disabled = false;
          }
        });
      }
      
      // Handle modal show/hide events
      modal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        currentReqId = parseInt(button.getAttribute('data-reqid') || '0', 10);
        currentBorrowId = parseInt(button.getAttribute('data-borrow_id') || '0', 10);
        
        // Update UI
        const reqSpan = document.getElementById('uqrReq');
        const modelSpan = document.getElementById('uqrModel');
        
        if (reqSpan && button.hasAttribute('data-reqid')) {
          reqSpan.textContent = '#' + button.getAttribute('data-reqid');
        }
        
        if (modelSpan && button.hasAttribute('data-model_name')) {
          modelSpan.textContent = button.getAttribute('data-model_name');
        }
        
        // Reset form
        if (submitBtn && submitBtnGray) {
          try{ delete submitBtn.dataset.serial; }catch(_){ }
          submitBtn.style.display = 'none';
          submitBtn.disabled = true;
          submitBtnGray.style.display = '';
          submitBtnGray.disabled = true;
        } else if (submitBtn) {
          try{ delete submitBtn.dataset.serial; }catch(_){ }
          submitBtn.style.display = 'none';
          submitBtn.disabled = true;
        }
        
        // Restore last used location
        if (locInput) {
          locInput.value = userPrefs.lastLocation || '';
          locInput.disabled = false;
        }
        
        // Initialize cameras and start scanning
        populateCameraSelect().then(() => { setTimeout(() => startScan(), 300); });
      });
      
      // Clean up on modal hide
      modal.addEventListener('hidden.bs.modal', function() {
        stopScan().catch(console.error);
        
        // Clear scanner
        if (scanner) { 
          scanner.clear().catch(console.error);
          scanner = null;
        }
        
        // Clear the scanner container
        if (readerEl) readerEl.innerHTML = '';
      });
      
      // Handle Enter key in location field
      if (locInput) {
        locInput.addEventListener('keypress', function(e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (submitBtn && !submitBtn.disabled) {
              submitBtn.click();
            } else if (startBtn && startBtn.style.display !== 'none') {
              startScan();
            }
          }
        });
      }
    }
    
    // Sync the heights of the Submit Request and Recent Requests cards only
    function syncSubmitCardHeight(){
      try {
        const submitCard = document.getElementById('submitReqCard');
        const recentCard = document.getElementById('recentRequestsCard');
        if (!submitCard || !recentCard) return;
        [submitCard, recentCard].forEach(function(el){ if(el){ el.style.minHeight=''; el.style.maxHeight=''; }});
        const targetHeight = Math.max(submitCard.offsetHeight || 0, recentCard.offsetHeight || 0);
        [submitCard, recentCard].forEach(function(el){ if(el){ el.style.minHeight = targetHeight + 'px'; }});
      } catch(_){ }
    }
    // Catalog/options and availability state
    let catModelMap = <?php echo json_encode(array_map(function($mods){ $vals = array_values($mods); natcasesort($vals); return array_values(array_unique($vals)); }, $availableMap), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    const categorySelect = document.getElementById('category');
    const modelSelect = document.getElementById('model');
    const qtyInput = document.getElementById('quantity');
    let modelMaxMap = <?php
      $maxMap = [];
      foreach ($availableMap as $cat => $mods) {
        foreach (array_values($mods) as $m) { $avail = (int)($availCounts[$cat][$m] ?? 0); $maxMap[$m] = max(0, $avail); }
      }
      echo json_encode($maxMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
    ?>;
    const qtyAvailHint = document.getElementById('qtyAvailHint');
    let inFlightAvail = false;
    let availAbort = null;
    function updateAvailHint() {
      const selModel = modelSelect && modelSelect.value ? modelSelect.value : '';
      if (!selModel) { if (qtyAvailHint) qtyAvailHint.textContent = 'Available: 0'; if (qtyInput){ qtyInput.max='1'; if (parseInt(qtyInput.value||'1',10)<1) qtyInput.value='1'; } return; }
      // 1) Instant local estimate from modelMaxMap for immediate UX
      try {
        const localN = (modelMaxMap && Object.prototype.hasOwnProperty.call(modelMaxMap, selModel)) ? (parseInt(modelMaxMap[selModel]||0,10)||0) : 0;
        if (qtyAvailHint) qtyAvailHint.textContent = 'Available: ' + String(localN);
        if (qtyInput){ const clamp = Math.max(1, localN); qtyInput.max = String(clamp); if (parseInt(qtyInput.value||'1',10) > localN){ qtyInput.value = String(clamp); } }
      } catch(_){ }
      // 2) Refresh from server (cancel previous), but only when visible and not paused
      if (document.visibilityState !== 'visible') return;
      if (typeof __UR_POLLING_PAUSED__ !== 'undefined' && __UR_POLLING_PAUSED__) return;
      if (inFlightAvail && availAbort) { try { availAbort.abort(); } catch(_){ } }
      inFlightAvail = true; availAbort = new AbortController();
      var mode = 'immediate';
      try { var tEl=document.getElementById('req_type'); mode = (tEl && tEl.value) ? String(tEl.value) : 'immediate'; } catch(_){ mode='immediate'; }
      var url = 'user_request.php?action=avail&model=' + encodeURIComponent(selModel) + (mode==='reservation' ? '&for=reservation' : '');
      fetch(url, { signal: availAbort.signal, cache: 'no-store' })
        .then(r=>r.ok?r.json():Promise.reject())
        .then(d=>{ const n = (d && typeof d.available==='number') ? d.available : 0; if (qtyAvailHint) qtyAvailHint.textContent = 'Available: ' + String(n); if (qtyInput){ const clamp = Math.max(1, n); qtyInput.max = String(clamp); if (parseInt(qtyInput.value||'1',10) > n){ qtyInput.value = String(clamp); } } })
        .catch(()=>{ /* silent */ })
        .finally(()=>{ inFlightAvail = false; });
    }
    function populateModelOptions(preserveValue) {
      const cat = (categorySelect && categorySelect.value) ? categorySelect.value : '';
      let models = [];
      modelSelect.innerHTML = ''; const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='Select Model'; modelSelect.appendChild(opt0);
      if (cat && catModelMap[cat]) {
        models = catModelMap[cat].slice();
        models.forEach(m=>{ const o=document.createElement('option'); o.value=m; o.textContent=m; modelSelect.appendChild(o); });
      }
      if (preserveValue && cat) { modelSelect.value = preserveValue; if (modelSelect.value !== preserveValue) { modelSelect.value=''; } }
      updateAvailHint();
    }
    function updateModelEnabled(){
      const hasCat = !!(categorySelect && categorySelect.value);
      if (!hasCat) {
        if (modelSelect) {
          modelSelect.value = '';
          modelSelect.disabled = true;
          modelSelect.classList.add('disabled');
          populateModelOptions('');
        }
      } else {
        if (modelSelect) {
          modelSelect.disabled = false;
          modelSelect.classList.remove('disabled');
          populateModelOptions(modelSelect.value);
        }
      }
    }
    function refreshCategoryOptions(preserveCat) {
      const cats = Object.keys(catModelMap).sort((a,b)=>a.localeCompare(b,undefined,{sensitivity:'base',numeric:true}));
      const current = categorySelect ? categorySelect.value : '';
      const keep = preserveCat ?? current;
      if (!categorySelect) return;
      const prev = categorySelect.value;
      categorySelect.innerHTML = '';
      const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='Select Category'; categorySelect.appendChild(opt0);
      cats.forEach(c=>{ const o=document.createElement('option'); o.value=c; o.textContent=c; categorySelect.appendChild(o); });
      if (keep && cats.includes(keep)) { categorySelect.value = keep; } else { categorySelect.value=''; }
    }
    let inFlightCatalog = false;
    function refreshCatalog(){
      if (document.visibilityState !== 'visible') return;
      if (inFlightCatalog) return; inFlightCatalog = true;
      const selCat = categorySelect ? categorySelect.value : '';
      const selModel = modelSelect ? modelSelect.value : '';
      const __getReqType = ()=>{ try{ var el=document.getElementById('req_type'); return (el && el.value) ? el.value : 'immediate'; }catch(_){ return 'immediate'; } };
      const __catalogUrl = ()=>{ const mode = __getReqType(); return 'user_request.php?action=catalog' + (mode==='reservation' ? '&for=reservation' : ''); };
      fetch(__catalogUrl())
        .then(r=>r.json())
        .then(d=>{
          if (!d) return;
          if (d.catModelMap && typeof d.catModelMap === 'object') { catModelMap = d.catModelMap; }
          if (d.modelMaxMap && typeof d.modelMaxMap === 'object') { modelMaxMap = d.modelMaxMap; }
          // Refresh category options and model options while preserving selections if still valid
          refreshCategoryOptions(selCat);
          populateModelOptions(selModel);
          // Clamp quantity based on updated availability
          if (modelSelect && qtyInput) {
            const curM = modelSelect.value;
            const maxQty = curM ? (modelMaxMap[curM] || 1) : 1;
            qtyInput.max = String(Math.max(1, maxQty));
            if (parseInt(qtyInput.value||'1',10) > maxQty) { qtyInput.value = String(Math.max(1, maxQty)); }
          }
          updateAvailHint();
        })
        .catch(()=>{})
        .finally(()=>{ inFlightCatalog = false; });
    }
    categorySelect && categorySelect.addEventListener('change', ()=>{ updateModelEnabled(); });
    modelSelect && modelSelect.addEventListener('focus', ()=>{ populateModelOptions(modelSelect.value); });
    modelSelect && modelSelect.addEventListener('click', ()=>{ populateModelOptions(modelSelect.value); });
    modelSelect && modelSelect.addEventListener('change', ()=>{
      const selModel = modelSelect.value; if (!selModel) return; const maxQty = modelMaxMap[selModel] || 1; qtyInput && (qtyInput.max = String(Math.max(1, maxQty)));
      if (parseInt(qtyInput.value||'1',10) > maxQty) { qtyInput.value = String(Math.max(1, maxQty)); }
      updateAvailHint();
    });
    // Live refresh for My Recent Requests
    function adjustRecentRequestsScroll(){
      try {
        const card = document.getElementById('recentRequestsCard');
        const body = card ? card.querySelector('.card-body') : null;
        const wrap = card ? card.querySelector('.table-responsive') : null;
        const table = card ? card.querySelector('table') : null;
        const thead = card ? card.querySelector('thead') : null;
        if (!card || !body || !wrap || !table) return;
        // Row and header heights
        let rowH = 36;
        const sampleRow = table.querySelector('tbody tr');
        if (sampleRow) { const r = sampleRow.getBoundingClientRect(); if (r && r.height) rowH = Math.max(24, Math.round(r.height)); }
        const headH = thead ? Math.round(thead.getBoundingClientRect().height || 0) : 0;
        // How many rows fit in the current card body? Ensure at least 12 rows.
        const bodyH = Math.round(body.getBoundingClientRect().height || 0);
        let rowsFit = rowH > 0 ? Math.floor((bodyH - headH) / rowH) : 12;
        rowsFit = Math.max(12, rowsFit);
        const target = headH + (rowsFit * rowH);
        wrap.style.height = target + 'px';
        wrap.style.maxHeight = target + 'px';
      } catch(_){}
    }
    function renderMyRequests(data){
      const tbody = document.getElementById('myReqTbody'); if (!tbody) return;
      const rows = [];
      if (!data || !Array.isArray(data.requests) || data.requests.length===0){ rows.push('<tr><td colspan="6" class="text-center text-muted">No requests yet.</td></tr>'); }
      else {
        data.requests.forEach(function(r){
          const id = parseInt(r.id||0,10);
          const item = String(r.item_name||'');
          const qty = parseInt(r.quantity||0,10);
          const st = String(r.status||'');
          const alloc = parseInt(r.allocations||0,10);
          let disp = '';
          if (st==='Cancelled') {
            disp = 'Cancelled';
          } else if (st==='Rejected') {
            if (qty>0 && alloc>0 && alloc<qty) {
              const rej = Math.max(0, qty-alloc);
              disp = alloc+'/'+qty+' Approved, '+rej+'/'+qty+' Rejected';
            } else {
              disp='Rejected';
            }
          } else if (alloc>=qty || ['Approved','Borrowed'].includes(st)) {
            disp='Approved';
          } else if (alloc>0 && qty>1) {
            disp=alloc+'/'+qty+' Approved';
          } else {
            disp=(st||'Pending');
          }
          const createdTxt = r.created_at_display ? String(r.created_at_display) : (r.created_at? String(r.created_at):'');
          // Compute approver/rejector display
          const approvedBy = String(r.approved_by||'').trim();
          const rejectedBy = String(r.rejected_by||'').trim();
          const cancelledBy = String(r.cancelled_by||'').trim();
          const nameCell = (st==='Cancelled') ? cancelledBy : ((st==='Rejected') ? (rejectedBy!==''? rejectedBy : 'Auto Rejected') : (['Approved','Borrowed','Returned'].includes(st) ? approvedBy : ''));
          rows.push('<tr>'+
            '<td>'+id+'</td>'+
            '<td>'+escapeHtml(item)+'</td>'+
            '<td>'+qty+'</td>'+
            '<td>'+escapeHtml(disp)+'</td>'+
            '<td>'+escapeHtml(nameCell)+'</td>'+
            '<td>'+escapeHtml(createdTxt)+'</td>'+
          '</tr>');
        });
      }
      tbody.innerHTML = rows.join('');
      // After rendering, re-sync heights in case the right card changed
      syncSubmitCardHeight();
      // Adjust scroll box to exactly 15 visible rows
      adjustRecentRequestsScroll();
      try { if (window.__UR_SECTION_LOADED__) window.__UR_SECTION_LOADED__['section-recent'] = true; } catch(_){ }
    }
    // Live renderer for My Borrowed
    function renderMyBorrowed(data){
      const tb = document.getElementById('myBorrowedTbody'); if (!tb) return;
      const rows = [];
      const list = (data && Array.isArray(data.borrowed)) ? data.borrowed : [];
      if (!list.length) {
        rows.push('<tr><td colspan="5" class="text-center text-muted">No active borrowed items.</td></tr>');
      } else {
        list.forEach(function(b){
          const reqId = (parseInt(b.request_id||0,10) > 0) ? parseInt(b.request_id||0,10) : (parseInt(b.borrow_id||0,10)||'');
          const typ = (String(b.type||'').trim() || 'Manual');
          const model = (b.model_display||b.model||b.item_name||'');
          const cat = (b.category||'Uncategorized');
          const approvedAt = (b.approved_at ? new Date(String(b.approved_at).replace(' ','T')).toLocaleString() : (b.borrowed_at ? new Date(String(b.borrowed_at).replace(' ','T')).toLocaleString() : ''));
          const approvedBy = String(b.approved_by || '');
          const returnPending = !!b.return_pending;
          let actions = '—';
          if (!returnPending && typ.toUpperCase() === 'QR' && parseInt(b.request_id||0,10) > 0) {
            const rid = parseInt(b.request_id||0,10);
            const bid = parseInt(b.borrow_id||0,10) || 0;
            actions = '<button type="button" class="btn btn-sm btn-outline-primary open-qr-return" data-bs-toggle="modal" data-bs-target="#userQrReturnModal" data-reqid="'+rid+'" data-borrow_id="'+bid+'" data-model_name="'+escapeHtml(model)+'"><i class="bi bi-qr-code-scan"></i> Return via QR</button>';
          }
          rows.push('<tr class="borrowed-row" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#userBorrowedDetailsModal"'+
            ' data-category="'+escapeHtml(String(cat))+'"'+
            ' data-approved_at="'+escapeHtml(String(approvedAt))+'"'+
            ' data-approved_by="'+escapeHtml(String(approvedBy))+'"'+
          '>'+
            '<td>'+ escapeHtml(String(reqId)) +'</td>'+
            '<td>'+ escapeHtml(typ) +'</td>'+
            '<td>'+ escapeHtml(String(model)) +'</td>'+
            '<td><span class="badge bg-warning text-dark">Borrowed</span></td>'+
            '<td class="text-end">'+ actions +'</td>'+
          '</tr>');
        });
      }
      tb.innerHTML = rows.join('');
      try { if (window.__UR_SECTION_LOADED__) window.__UR_SECTION_LOADED__['section-borrowed'] = true; } catch(_){ }
    }
    // Live renderer for My Overdue
    function renderMyOverdue(data){
      const tb = document.getElementById('myOverdueTbody'); if (!tb) return;
      const list = (data && Array.isArray(data.overdue)) ? data.overdue : [];
      const rows = [];
      if (!list.length) {
        rows.push('<tr><td colspan="4" class="text-center text-muted">No overdue items.</td></tr>');
      } else {
        list.forEach(function(r){
          const reqId = (parseInt(r.request_id||0,10) > 0) ? String(parseInt(r.request_id||0,10)) : (r.borrow_id!=null? String(r.borrow_id): '');
          const model = String(r.model||'');
          const cat = String(r.category||'Uncategorized');
          const borrowedAt = r.borrowed_at ? new Date(String(r.borrowed_at).replace(' ','T')).toLocaleString() : '';
          const due = r.expected_return_at ? new Date(String(r.expected_return_at).replace(' ','T')).toLocaleString() : '';
          const days = (typeof r.overdue_days === 'number') ? r.overdue_days : '';
          rows.push('<tr class="overdue-row" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#userOverdueDetailsModal"'+
            ' data-borrowed_at="'+escapeHtml(borrowedAt)+'"'+
            ' data-due_at="'+escapeHtml(due)+'"'+
            ' data-overdue_days="'+escapeHtml(String(days))+'"'+
          '>'+ 
            '<td>'+ reqId +'</td>'+
            '<td>'+ escapeHtml(model) +'</td>'+
            '<td>'+ escapeHtml(cat) +'</td>'+
            '<td><span class="badge bg-danger">Overdue</span></td>'+
          '</tr>');
        });
      }
      tb.innerHTML = rows.join('');
      try { const warnBtn = document.getElementById('overdueWarnBtn'); if (warnBtn) warnBtn.classList.toggle('d-none', !(list && list.length)); } catch(_){ }
    }
    // Live renderer for Borrow History
    function renderMyHistory(data){
      const tb = document.getElementById('myHistoryTbody'); if (!tb) return;
      const rows = [];
      const list = (data && Array.isArray(data.history)) ? data.history : [];
      if (!list.length) {
        rows.push('<tr><td colspan="6" class="text-center text-muted">No history yet.</td></tr>');
      } else {
        list.forEach(function(hv){
          const stRaw = String(hv.latest_action || hv.status || '');
          let stShow = (stRaw === 'Under Maintenance') ? 'Damaged' : stRaw;
          if (stRaw === 'Found' || stRaw === 'Fixed') stShow = 'Returned';
          let badge = 'secondary';
          if (stShow === 'Returned') badge = 'success';
          else if (stShow === 'Lost') badge = 'danger';
          else if (stShow === 'Damaged') badge = 'warning';
          const reqId = (hv.request_id && Number(hv.request_id)>0) ? String(hv.request_id) : (hv.borrow_id!=null? String(hv.borrow_id): '');
          const modelName = String(hv.model || hv.item_name || '');
          const borrowedAt = hv.borrowed_at ? new Date(hv.borrowed_at).toLocaleString() : '';
          const returnedAt = hv.returned_at ? new Date(hv.returned_at).toLocaleString() : '';
          const category = String(hv.category || 'Uncategorized');
          rows.push('<tr>'+
            '<td>'+ reqId +'</td>'+
            '<td>'+ escapeHtml(modelName) +'</td>'+
            '<td>'+ escapeHtml(borrowedAt) +'</td>'+
            '<td>'+ escapeHtml(returnedAt) +'</td>'+
            '<td>'+ escapeHtml(category) +'</td>'+
            '<td><span class="badge bg-'+badge+'">'+ escapeHtml(stShow) +'</span></td>'+
          '</tr>');
        });
      }
      tb.innerHTML = rows.join('');
    }
    function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }

    document.addEventListener('DOMContentLoaded', ()=>{
      // Initial height sync and on resize
      syncSubmitCardHeight();
      window.addEventListener('resize', function(){ syncSubmitCardHeight(); adjustRecentRequestsScroll(); });
      // Table section switcher
      (function(){
        function showSection(id, label){
          ['section-recent','section-overdue','section-borrowed','section-history'].forEach(function(s){
            var el = document.getElementById(s);
            if (!el) return;
            el.style.display = (s === id) ? '' : 'none';
          });
          var btn = document.getElementById('tableSwitcherBtn');
          if (btn && label){ btn.textContent = 'View: ' + label; }
          // After switching, re-sync heights
          setTimeout(function(){ syncSubmitCardHeight(); }, 0);
          // Track visible section for targeted polling and do an immediate refresh
          try { window.__UR_VISIBLE_SECTION__ = id; immediateSectionFetch(id); } catch(_){ }
          // Persist last selected section
          try { localStorage.setItem('ur_last_section', String(id||'')); } catch(_){ }
        }
        document.querySelectorAll('.table-switch').forEach(function(a){
          a.addEventListener('click', function(e){
            e.preventDefault();
            var id = a.getAttribute('data-section');
            var label = (a.textContent || '').trim();
            showSection(id, label);
            // Close dropdown after selection (Bootstrap or manual fallback)
            try { var ddBtn=document.getElementById('tableSwitcherBtn'); if (ddBtn && window.bootstrap && bootstrap.Dropdown) bootstrap.Dropdown.getOrCreateInstance(ddBtn).hide(); } catch(_){ }
            try {
              var ddBtn2=document.getElementById('tableSwitcherBtn');
              var menu2 = ddBtn2 && ddBtn2.parentElement ? ddBtn2.parentElement.querySelector('.dropdown-menu') : null;
              if (menu2) { menu2.classList.remove('show'); menu2.style.display=''; ddBtn2.setAttribute('aria-expanded','false'); }
            } catch(_){ }
          });
        });
        // Event delegation fallback to ensure clicks always work even if markup changes
        document.addEventListener('click', function(e){
          var a = e.target && e.target.closest ? e.target.closest('.table-switch[data-section]') : null;
          if (!a) return;
          e.preventDefault();
          var id = a.getAttribute('data-section');
          var label = (a.textContent || '').trim();
          showSection(id, label);
          try { var ddBtn=document.getElementById('tableSwitcherBtn'); if (ddBtn && window.bootstrap && bootstrap.Dropdown) bootstrap.Dropdown.getOrCreateInstance(ddBtn).hide(); } catch(_){ }
          try {
            var ddBtn2=document.getElementById('tableSwitcherBtn');
            var menu2 = ddBtn2 && ddBtn2.parentElement ? ddBtn2.parentElement.querySelector('.dropdown-menu') : null;
            if (menu2) { menu2.classList.remove('show'); menu2.style.display=''; ddBtn2.setAttribute('aria-expanded','false'); }
          } catch(_){ }
        });
        // Manual dropdown toggle assist: ensure menu opens even if Bootstrap doesn't
        (function(){
          var btn = document.getElementById('tableSwitcherBtn');
          var menu = btn && btn.parentElement ? btn.parentElement.querySelector('.dropdown-menu') : null;
          if (!btn || !menu) return;
          function ensureOpen(){
            try {
              // If Bootstrap is available, try to open
              if (window.bootstrap && bootstrap.Dropdown) {
                var inst = bootstrap.Dropdown.getOrCreateInstance(btn);
                inst.show();
              }
            } catch(_){ }
            // After a short delay, if still not open, open manually
            setTimeout(function(){
              var isShown = menu.classList.contains('show');
              var floating = document.getElementById('floatingTableMenu');
              if (floating) { try{ floating.remove(); }catch(_){ floating.style.display='none'; } }
              if (!isShown) {
                // Create a simple positioned fallback menu under the button
                var r = btn.getBoundingClientRect();
                var f = document.createElement('div'); f.id='floatingTableMenu'; f.className='dropdown-menu show'; f.style.position='fixed'; f.style.left=(r.left)+'px'; f.style.top=(r.bottom)+'px'; f.style.zIndex='1070'; f.innerHTML = menu.innerHTML; document.body.appendChild(f);
              }
            }, 30);
          }
          btn.addEventListener('click', function(e){
            // Let default happen, but also ensure
            ensureOpen();
          });
          // Close on outside click for manual mode
          document.addEventListener('click', function(ev){
            if (!btn.contains(ev.target) && !menu.contains(ev.target)) {
              if (menu.classList.contains('show')){
                try { if (window.bootstrap && bootstrap.Dropdown) bootstrap.Dropdown.getOrCreateInstance(btn).hide(); } catch(_){ }
                menu.classList.remove('show');
                menu.style.display = '';
                btn.setAttribute('aria-expanded','false');
              }
              var floating = document.getElementById('floatingTableMenu');
              if (floating && !floating.contains(ev.target)) { try{ floating.remove(); }catch(_){ floating.style.display='none'; } btn.setAttribute('aria-expanded','false'); }
            }
          });
        })();
        // No fallback cycling; clicking the button should only open the dropdown
        // Default view on load: honor URL ?view=overdue; otherwise always default to My Recent Requests
        (function(){
          try {
            var v = (new URL(window.location.href)).searchParams.get('view');
            v = v ? String(v).toLowerCase() : '';
            if (v === 'overdue') { showSection('section-overdue', 'Overdue Items'); return; }
          } catch(_){ }
          showSection('section-recent', 'My Recent Requests');
        })();
      })();
      // Cleanup any lingering nav dots on load
      try {
        // Remove any lingering dots globally
        document.querySelectorAll('.nav-borrowed-dot, .nav-req-dot').forEach(el=>{ try{ el.remove(); }catch(_){ el.style.display='none'; } });
        // Also clean the sidebar-specific link
        const reqLinkInit = document.querySelector('#sidebar-wrapper a[href="user_request.php"]');
        if (reqLinkInit) {
          reqLinkInit.querySelectorAll('.nav-borrowed-dot, .nav-req-dot').forEach(el=>{ try{ el.remove(); }catch(_){ el.style.display='none'; } });
        }
      } catch(_){ }
      updateModelEnabled(); updateAvailHint();
      // Reservation earliest-start hint
      function fetchReservationStartHint(){
        try {
          var typeEl = document.getElementById('req_type'); if (!typeEl || typeEl.value !== 'reservation') { reservationEarliestMin=''; document.getElementById('reservedStartHint').textContent=''; return; }
          var modelEl = document.getElementById('model'); var m = modelEl && modelEl.value ? modelEl.value : ''; if (!m){ reservationEarliestMin=''; document.getElementById('reservedStartHint').textContent=''; return; }
          fetch('user_request.php?action=reservation_start_hint&model=' + encodeURIComponent(m), { cache:'no-store' })
            .then(r=>r.json()).then(function(d){
              reservationEarliestMin = (d && d.earliest) ? String(d.earliest) : '';
              var hintEl = document.getElementById('reservedStartHint');
              if (reservationEarliestMin){
                // Display local-friendly text
                try {
                  var dt = new Date(reservationEarliestMin.replace(' ','T'));
                  if (isNaN(dt)) {
                    var m = reservationEarliestMin.match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})$/);
                    if (m) {
                      dt = new Date(parseInt(m[1],10), parseInt(m[2],10)-1, parseInt(m[3],10), parseInt(m[4],10), parseInt(m[5],10), 0, 0);
                    }
                  }
                  var textOut = '';
                  if (dt && !isNaN(dt.getTime())) {
                    var now = new Date();
                    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                    var targetDay = new Date(dt.getFullYear(), dt.getMonth(), dt.getDate());
                    var diffDays = Math.round((targetDay - today)/86400000);
                    var pad = function(n){ return n<10 ? ('0'+n) : n; };
                    var h = dt.getHours();
                    var ampm = h >= 12 ? 'PM' : 'AM';
                    var h12 = h % 12; if (h12 === 0) h12 = 12;
                    var timeStr = h12 + ':' + pad(dt.getMinutes()) + ' ' + ampm;
                    if (diffDays === 0) {
                      textOut = 'Will be available at: ' + timeStr;
                    } else if (diffDays === 1) {
                      textOut = 'Will be available tomorrow at ' + timeStr;
                    } else {
                      var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                      textOut = 'Will be available at: ' + months[dt.getMonth()] + ' ' + dt.getDate() + ', ' + dt.getFullYear() + ' ' + timeStr;
                    }
                  } else {
                    textOut = 'Will be available at: ' + reservationEarliestMin;
                  }
                  hintEl.textContent = textOut;
                } catch(_) { hintEl.textContent = 'Will be available at: ' + reservationEarliestMin; }
                // Update min attribute
                var sf = document.getElementById('reserved_from'); if (sf){ try { var dt2=new Date(reservationEarliestMin.replace(' ','T')); var pad=n=>n<10?('0'+n):n; var v = dt2.getFullYear()+'-'+pad(dt2.getMonth()+1)+'-'+pad(dt2.getDate())+'T'+pad(dt2.getHours())+':'+pad(dt2.getMinutes()); sf.min = v; } catch(_){ } }
              } else {
                if (d && d.single === true && d.available_now === true) { hintEl.textContent = 'Available now'; }
                else { hintEl.textContent=''; }
              }
              updateSubmitButton();
            }).catch(function(){ /*no-op*/ });
        } catch(_){ }
      }
      document.getElementById('model') && document.getElementById('model').addEventListener('change', function(){ fetchReservationStartHint(); });
      document.getElementById('req_type') && document.getElementById('req_type').addEventListener('change', function(){ fetchReservationStartHint(); });
      fetchReservationStartHint();
      // Initialize button state
      try { updateSubmitButton(); } catch(_){ }
      let inFlightReq = false;
      let inFlightBor = false;
      let inFlightHist = false;
      // Auto-dismiss any top alerts after 3 seconds
      setTimeout(()=>{
        document.querySelectorAll('.alert-dismissible').forEach(function(el){
          try { bootstrap.Alert.getOrCreateInstance(el).close(); } catch(e) { try { el.remove(); } catch(_){} }
        });
      }, 3000);
      
      // Throttle background UI refresh and respect visibility/pause flags
      setInterval(()=>{ 
        if (document.visibilityState !== 'visible') return; 
        if (typeof __UR_POLLING_PAUSED__ !== 'undefined' && __UR_POLLING_PAUSED__) return;
        try { updateAvailHint(); } catch(_){ }
        try { updateImmediateReserveHint(); } catch(_){ }
      }, 5000);
      // Keep categories/models live (less frequent)
      setInterval(()=>{ 
        if (document.visibilityState !== 'visible') return; 
        if (typeof __UR_POLLING_PAUSED__ !== 'undefined' && __UR_POLLING_PAUSED__) return;
        try { refreshCatalog(); } catch(_){ }
      }, 15000);
      // Global polling pause control (e.g., when modals are open)
      var __UR_POLLING_PAUSED__ = false;
      // Track whether a section has completed its first successful load
      window.__UR_SECTION_LOADED__ = window.__UR_SECTION_LOADED__ || { 'section-recent': false, 'section-borrowed': false, 'section-overdue': false, 'section-history': false };
      // Track whether we've already shown the one-time Loading... state per section
      window.__UR_SECTION_LOADING_SHOWN__ = window.__UR_SECTION_LOADING_SHOWN__ || { 'section-recent': false, 'section-borrowed': false, 'section-overdue': false, 'section-history': false };
      function pausePolling(v){ __UR_POLLING_PAUSED__ = !!v; }
      try {
        var srModal = document.getElementById('submitRequestModal');
        if (srModal){
          srModal.addEventListener('shown.bs.modal', function(){ pausePolling(true); });
          srModal.addEventListener('hidden.bs.modal', function(){ pausePolling(false); });
        }
        var qrModal2 = document.getElementById('urQrScanModal');
        if (qrModal2){
          qrModal2.addEventListener('shown.bs.modal', function(){ pausePolling(true); });
          qrModal2.addEventListener('hidden.bs.modal', function(){ pausePolling(false); });
        }
      } catch(_){}

      // Targeted polling: only fetch the currently visible section
      let inFlightOver = false;
      function immediateSectionFetch(sec){
        if (!sec) return;
        if (sec === 'section-recent') {
          if (inFlightReq) return; inFlightReq = true;
          fetch('user_request.php?action=my_requests_status', { cache:'no-store' })
            .then(r=>r.json()).then(renderMyRequests).catch(()=>{})
            .finally(()=>{ inFlightReq = false; });
        } else if (sec === 'section-borrowed') {
          if (inFlightBor) return; inFlightBor = true;
          fetch('user_request.php?action=my_borrowed', { cache:'no-store' })
            .then(r=>r.json()).then(d=>{ renderMyBorrowed(d); try { const list=(d&&Array.isArray(d.borrowed))?d.borrowed:[]; const navLink=document.querySelector('#sidebar-wrapper a[href="user_request.php"]'); if(navLink){ navLink.querySelectorAll('.nav-req-dot').forEach(el=>{ try{ el.remove(); }catch(_){ el.style.display='none'; } }); let dot=navLink.querySelector('.nav-borrowed-dot'); if(list.length>0){ if(!dot){ dot=document.createElement('span'); dot.className='nav-borrowed-dot ms-2 d-inline-block rounded-circle'; dot.style.width='8px'; dot.style.height='8px'; dot.style.backgroundColor='#dc3545'; dot.style.verticalAlign='middle'; navLink.appendChild(dot);} dot.style.display='inline-block'; } else { if(dot){ try{ dot.remove(); }catch(_){ dot.style.display='none'; } } document.querySelectorAll('.nav-borrowed-dot').forEach(el=>{ try{ el.remove(); }catch(_){ el.style.display='none'; } }); } } } catch(_){ } }).catch(()=>{})
            .finally(()=>{ inFlightBor = false; });
        } else if (sec === 'section-overdue') {
          if (inFlightOver) return; inFlightOver = true;
          try {
            var tb=document.getElementById('myOverdueTbody');
            if(tb){
              // Pre-render from sessionStorage ONLY if there are items; otherwise keep Loading... until live fetch returns
              try {
                var pf = sessionStorage.getItem('overdue_prefetch');
                if (pf) {
                  var parsed = JSON.parse(pf);
                  if (parsed && parsed.overdue && Array.isArray(parsed.overdue) && parsed.overdue.length > 0) {
                    renderMyOverdue(parsed);
                    window.__UR_SECTION_LOADED__['section-overdue'] = true;
                  }
                }
              } catch(_){ }
              var showOnce = !window.__UR_SECTION_LOADING_SHOWN__['section-overdue'];
              var emptyNow = (tb.children.length === 0);
              if (showOnce && (emptyNow || !window.__UR_SECTION_LOADED__['section-overdue'])) {
                tb.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Loading...</td></tr>';
                window.__UR_SECTION_LOADING_SHOWN__['section-overdue'] = true;
              }
            }
          } catch(_){ }
          fetch('user_request.php?action=my_overdue', { cache:'no-store' })
            .then(r=>r.json()).then(renderMyOverdue).catch(()=>{})
            .finally(()=>{ inFlightOver = false; });
        } else if (sec === 'section-history') {
          if (inFlightHist) return; inFlightHist = true;
          fetch('user_request.php?action=my_history', { cache:'no-store' })
            .then(r=>r.json()).then(renderMyHistory).catch(()=>{})
            .finally(()=>{ inFlightHist = false; });
        }
      }
      function pollVisibleSection(){
        if (document.visibilityState !== 'visible' || __UR_POLLING_PAUSED__) return;
        const sec = window.__UR_VISIBLE_SECTION__ || 'section-recent';
        immediateSectionFetch(sec);
      }
      setInterval(pollVisibleSection, 6000);

      let toastWrap = document.getElementById('userToastWrap');
      if (!toastWrap) {
        toastWrap = document.createElement('div');
        toastWrap.id='userToastWrap';
        toastWrap.style.position='fixed';
        toastWrap.style.right='16px';
        toastWrap.style.bottom='16px';
        toastWrap.style.zIndex='1030';
        document.body.appendChild(toastWrap);
      }
      function attachSwipeForToast(el){
        try{
          let sx=0, sy=0, dx=0, moving=false, removed=false;
          const onStart=(ev)=>{ try{ const t=ev.touches?ev.touches[0]:ev; sx=t.clientX; sy=t.clientY; dx=0; moving=true; el.style.willChange='transform,opacity'; el.classList.add('toast-slide'); el.style.transition='none'; }catch(_){}};
          const onMove=(ev)=>{ if(!moving||removed) return; try{ const t=ev.touches?ev.touches[0]:ev; dx=t.clientX - sx; const adx=Math.abs(dx); const od=1 - Math.min(1, adx/140); el.style.transform='translateX('+dx+'px)'; el.style.opacity=String(od); }catch(_){}};
          const onEnd=()=>{ if(!moving||removed) return; moving=false; try{ el.style.transition='transform 180ms ease, opacity 180ms ease'; const adx=Math.abs(dx); if (adx>80){ removed=true; el.classList.add(dx>0?'toast-remove-right':'toast-remove-left'); setTimeout(()=>{ try{ el.remove(); adjustToastOffsets(); }catch(_){ } }, 200); } else { el.style.transform=''; el.style.opacity=''; } }catch(_){ } };
          el.addEventListener('touchstart', onStart, {passive:true});
          el.addEventListener('touchmove', onMove, {passive:true});
          el.addEventListener('touchend', onEnd, {passive:true});
        }catch(_){ }
      }
      function showToastCustom(msg, cls){
        const el=document.createElement('div');
        el.className='alert '+(cls||'alert-info')+' shadow-sm border-0 toast-slide toast-enter';
        // Desktop sizes
        el.style.minWidth='300px';
        el.style.maxWidth='340px';
        // Mobile compaction
        try{ if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='180px'; el.style.maxWidth='200px'; el.style.fontSize='12px'; } }catch(_){ }
        el.innerHTML='<i class="bi bi-bell me-2"></i>'+String(msg||'');
        toastWrap.appendChild(el);
        try{ adjustToastOffsets(); }catch(_){ }
        attachSwipeForToast(el);
        setTimeout(()=>{ try{ el.classList.add('toast-fade-out'); setTimeout(()=>{ try{ el.remove(); adjustToastOffsets(); }catch(_){ } }, 220); }catch(_){ } }, 5000);
      }
      function adjustToastOffsets(){
        try{
          const tw = document.getElementById('userToastWrap'); if (!tw) return;
          const wrap = document.getElementById('userPersistentWrap');
          // Default right aligns with wrap on mobile
          try{ if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ tw.style.right = '14px'; } else { tw.style.right = '16px'; } }catch(_){ }
          if (wrap && wrap.parentNode){
            const cs = window.getComputedStyle(wrap);
            const base = parseInt(cs.bottom||'16',10)||16;
            const h = wrap.offsetHeight||0;
            const gap = 8;
            tw.style.bottom = (base + h + gap) + 'px';
          } else {
            // Fall back to default bottom padding
            tw.style.bottom = '16px';
          }
        }catch(_){ }
      }
      try{ window.addEventListener('resize', adjustToastOffsets); }catch(_){ }
      let audioCtx = null; function playBeep(){ try{ if(!audioCtx) audioCtx=new(window.AudioContext||window.webkitAudioContext)(); if (audioCtx.state==='suspended'){ try{ audioCtx.resume(); }catch(_e){} } const o=audioCtx.createOscillator(), g=audioCtx.createGain(); o.type='square'; o.frequency.setValueAtTime(880, audioCtx.currentTime); g.gain.setValueAtTime(0.0001, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.35, audioCtx.currentTime+0.03); g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime+0.6); o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime+0.65);}catch(_){} }
      let baseAlloc = new Set(); let baseLogs = new Set(); let baseDec = new Set(); let initNotifs = false;
      function ensurePersistentWrap(){
        let wrap = document.getElementById('userPersistentWrap');
        if (!wrap){
          wrap = document.createElement('div');
          wrap.id='userPersistentWrap';
          wrap.style.position='fixed';
          wrap.style.right='16px';
          wrap.style.bottom='16px';
          wrap.style.zIndex='1030';
          wrap.style.display='flex';
          wrap.style.flexDirection='column';
          wrap.style.gap='8px';
          wrap.style.pointerEvents='none';
          document.body.appendChild(wrap);
        }
        try {
          if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
            wrap.style.right='8px';
            if (!wrap.getAttribute('data-bottom')) { wrap.style.bottom='64px'; }
          }
        } catch(_){ }
        return wrap;
      }
      function addOrUpdateReturnshipNotice(rs){
        const wrap = ensurePersistentWrap(); const id = parseInt(rs.id||0,10); if (!id) return;
        const elId = 'rs-alert-'+id; let el = document.getElementById(elId);
        const name = String(rs.model_name||''); const sn = String(rs.qr_serial_no||'');
        const html = '<i class="bi bi-exclamation-octagon me-2"></i>'+'Admin requested you to return '+(name?name+' ':'')+(sn?('['+sn+']'):'')+'. Click to open.';
        if (!el){
          el = document.createElement('div');
          el.id=elId;
          el.className='alert alert-danger shadow-sm border-0';
          el.style.minWidth='300px';
          el.style.maxWidth='340px';
          el.style.cursor='pointer';
          el.style.margin='0';
          el.style.lineHeight='1.25';
          el.style.borderRadius='8px';
          el.style.pointerEvents='auto';
          el.innerHTML=html;
          // Make smaller on mobile
          try {
            if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
              el.style.minWidth='180px';
              el.style.maxWidth='200px';
              el.style.padding='4px 6px';
              el.style.fontSize='10px';
              const icon = el.querySelector('i'); if (icon) icon.style.fontSize = '12px';
            }
          } catch(_){ }
          el.addEventListener('click', function(){ window.location.href='user_request.php'; });
          wrap.appendChild(el);
          try{ playBeep(); }catch(_){ }
        } else {
          el.innerHTML = html;
          // Re-apply mobile sizing on update as well
          try {
            if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
              el.style.minWidth='180px';
              el.style.maxWidth='200px';
              el.style.padding='4px 6px';
              el.style.fontSize='10px';
              const icon = el.querySelector('i'); if (icon) icon.style.fontSize = '12px';
            } else {
              el.style.minWidth='300px';
              el.style.maxWidth='340px';
              el.style.padding='';
              el.style.fontSize='';
            }
          } catch(_){ }
        }
      }
      function removeReturnshipNotice(id){ const el=document.getElementById('rs-alert-'+id); if (el){ try{ el.remove(); }catch(_){ el.style.display='none'; } } }
      function addOrUpdateOverdueNotices(items){
        const wrap = ensurePersistentWrap();
        const list = Array.isArray(items) ? items : [];
        const count = list.length;
        // Do not show consolidated overdue notice if user is currently viewing the Overdue table/modal
        try {
          let viewing = false;
          try { if (window.__UR_VISIBLE_SECTION__ === 'section-overdue') viewing = true; } catch(_){ }
          if (!viewing) {
            try { const mdl = document.getElementById('userOverdueModal'); if (mdl && mdl.classList && mdl.classList.contains('show')) viewing = true; } catch(_){ }
          }
          if (viewing) {
            const existing = document.getElementById('ov-alert-summary');
            if (existing) { try{ existing.remove(); }catch(_){ existing.style.display='none'; } }
            return;
          }
        } catch(_){ }
        // Clean up any item-specific overdue alerts, keep only summary if present
        try {
          wrap.querySelectorAll('[id^="ov-alert-"]').forEach(function(node){
            if (node.id !== 'ov-alert-summary') { try{ node.remove(); }catch(_){ node.style.display='none'; } }
          });
        } catch(_){ }
        const key = 'ov-alert-summary';
        let el = document.getElementById(key);
        if (count === 0) {
          if (el) { try{ el.remove(); }catch(_){ el.style.display='none'; } }
          return;
        }
        const html = '<i class="bi bi-exclamation-octagon me-2"></i>'
          + (count === 1 ? 'You have an overdue item, Click to view.' : ('You have overdue items ('+count+'), Click to view.'));
        let ding = false;
        if (!el) {
          el = document.createElement('div');
          el.id = key;
          el.className='alert alert-danger shadow-sm border-0';
          el.style.minWidth='300px';
          el.style.maxWidth='340px';
          el.style.cursor='pointer';
          el.style.margin='0';
          el.style.lineHeight='1.25';
          el.style.borderRadius='8px';
          el.style.pointerEvents='auto';
          el.addEventListener('click', function(){ window.location.href = 'user_request.php?view=overdue'; });
          wrap.appendChild(el);
          ding = true;
        }
        const prev = parseInt(el.getAttribute('data-count')||'-1',10);
        if (prev !== count) { el.setAttribute('data-count', String(count)); el.innerHTML = html; }
        // Mobile sizing
        try {
          if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
            el.style.minWidth='180px';
            el.style.maxWidth='200px';
            el.style.padding='4px 6px';
            el.style.fontSize='10px';
            const icon = el.querySelector('i'); if (icon) icon.style.fontSize = '12px';
          } else {
            el.style.minWidth='300px';
            el.style.maxWidth='340px';
            el.style.padding='';
            el.style.fontSize='';
          }
        } catch(_){ }
        if (ding) { try{ playBeep(); }catch(_){ } }
        try{ adjustToastOffsets(); }catch(_){ }
      }
      try { window.__UR_updateOverdue = addOrUpdateOverdueNotices; } catch(_){ }
      function notifPoll(){
        fetch('user_request.php?action=user_notifications')
          .then(r=>r.json())
          .then(d=>{
            const approvals = Array.isArray(d.approvals)?d.approvals:[];
            const logs = Array.isArray(d.lostDamaged)?d.lostDamaged:[];
            const decisions = Array.isArray(d.decisions)?d.decisions:[];
            const returnships = Array.isArray(d.returnships)?d.returnships:[];
            const idsA = new Set(approvals.map(a=>parseInt(a.alloc_id||0,10)).filter(n=>n>0));
            const idsL = new Set(logs.map(l=>parseInt(l.log_id||0,10)).filter(n=>n>0));
            const idsD = new Set(decisions.map(x=> (parseInt(x.id||0,10)+'|'+String(x.status||'')) ));
            const idsR = new Set(returnships.map(r=>parseInt(r.id||0,10)).filter(n=>n>0));
            if (!initNotifs) { baseAlloc = idsA; baseLogs = idsL; baseDec = idsD; baseReturnships = idsR; initNotifs = true; return; }
            let ding = false;
            approvals.forEach(a=>{ const id=parseInt(a.alloc_id||0,10); if(!baseAlloc.has(id)){ ding=true; showToastCustom('The ID.'+String(a.model_id||'')+' ('+String(a.model_name||'')+') has been approved', 'alert-success'); } });
            logs.forEach(l=>{ const id=parseInt(l.log_id||0,10); if(!baseLogs.has(id)){ ding=true; const act=String(l.action||''); const label=(act==='Under Maintenance')?'damaged':'lost'; showToastCustom('The ID.'+String(l.model_id||'')+' ('+String(l.model_name||'')+') was marked as '+label, 'alert-danger'); } });
            // Decision toasts (auto-assign / auto-cancel)
            decisions.forEach(dc=>{ const key=(parseInt(dc.id||0,10)+'|'+String(dc.status||'')); if(!baseDec.has(key)){ ding=true; showToastCustom(String(dc.message||'Reservation updated'), dc.status==='Cancelled'?'alert-danger':'alert-info'); } });
            const pendingSet = new Set();
            if (typeof baseReturnships !== 'undefined') { baseReturnships.forEach(oldId=>{ removeReturnshipNotice(oldId); }); }
            fetch('user_request.php?action=my_overdue', { cache:'no-store' })
              .then(r=>r.json())
              .then(o=>{
                const list = (o && Array.isArray(o.overdue)) ? o.overdue : [];
                try { sessionStorage.setItem('overdue_prefetch', JSON.stringify({ overdue: list })); } catch(_){ }
                addOrUpdateOverdueNotices(list);
              })
              .catch(()=>{});
            if (ding) playBeep();
            baseAlloc = idsA; baseLogs = idsL; baseDec = idsD; baseReturnships = idsR;
          })
          .catch(()=>{});
      }
      notifPoll();
      setInterval(()=>{ if (document.visibilityState==='visible') notifPoll(); }, 1000);
    })();
  </script>
  <script>
    function toggleSidebar(){ const sidebar=document.getElementById('sidebar-wrapper'); sidebar.classList.toggle('active'); if (window.innerWidth<=768){ document.body.classList.toggle('sidebar-open', sidebar.classList.contains('active')); } }
    document.addEventListener('click', function(event){ const sidebar=document.getElementById('sidebar-wrapper'); const toggleBtn=document.querySelector('.mobile-menu-toggle'); if (window.innerWidth<=768){ if (sidebar && toggleBtn && !sidebar.contains(event.target) && !toggleBtn.contains(event.target)) { sidebar.classList.remove('active'); document.body.classList.remove('sidebar-open'); } } });
  </script>
  <script>
    // User notification bell dropdown + cross-page sync
    (function(){
      const bellBtn = document.getElementById('userBellBtn');
      const bellDot = document.getElementById('userBellDot');
      const dropdown = document.getElementById('userBellDropdown');
      const listEl = document.getElementById('userNotifList');
      const emptyEl = document.getElementById('userNotifEmpty');
      const bellWrap = document.getElementById('userBellWrap');
      // Mobile modal elements
      const bellModal = document.getElementById('userBellModal');
      const bellBackdrop = document.getElementById('userBellBackdrop');
      const mobileListEl = document.getElementById('userNotifListM');
      const mobileEmptyEl = document.getElementById('userNotifEmptyM');
      const mobileCloseBtn = document.getElementById('ubmCloseBtn');
      if (!bellBtn || !dropdown || !listEl || !emptyEl) return;
      function isMobile(){ try{ return window.matchMedia && window.matchMedia('(max-width: 768px)').matches; }catch(_){ return window.innerWidth<=768; } }
      function copyNotifToMobile(){ try{ if (mobileListEl) mobileListEl.innerHTML = listEl ? listEl.innerHTML : ''; if (mobileEmptyEl) mobileEmptyEl.style.display = emptyEl ? emptyEl.style.display : ''; } catch(_){ } }
      function openMobileModal(){ if (!bellModal || !bellBackdrop) return; copyNotifToMobile(); bellModal.style.display='flex'; bellBackdrop.style.display='block'; try{ document.body.style.overflow='hidden'; }catch(_){ } }
      function closeMobileModal(){ if (!bellModal || !bellBackdrop) return; bellModal.style.display='none'; bellBackdrop.style.display='none'; try{ document.body.style.overflow=''; }catch(_){ } }
      let fetching = false;
      let latestTs = 0;
      let lastSig = '';
      let currentSig = '';
      function setLoadingList(){
        try{
          if (listEl){
            const has = !!(listEl.innerHTML && listEl.innerHTML.trim() !== '');
            if (!has) listEl.innerHTML = '<div class="text-center text-muted py-2">Loading...</div>';
          }
          if (emptyEl) emptyEl.style.display = 'none';
        }catch(_){ }
      }
      function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
      // Build full list (base requests + extras) in memory and return rows + metadata
      function composeRows(baseList, dn, ovCount, ovSet){
        let latest=0; let sigParts=[]; const combined=[]; const ovset = (ovSet instanceof Set) ? ovSet : (window.__UR_OVSET__ instanceof Set ? window.__UR_OVSET__ : new Set());
        const clearedKeys = new Set((dn && Array.isArray(dn.cleared_keys)) ? dn.cleared_keys : []);
        let ephemCount = 0;
        // Overdue summary always at top when present
        try{
          const oc = parseInt(ovCount||0,10)||0;
          if (oc>0){
            const txt = (oc===1) ? 'You have an overdue item' : ('You have overdue items ('+oc+')');
            const oh = '<a href="user_request.php?view=overdue" class="list-group-item list-group-item-action">'
              + '<div class="d-flex w-100 justify-content-between">'
              +   '<strong class="text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>'+txt+'</strong>'
              + '</div>'
              + '</a>';
            combined.push({type:'overdue', id:0, ts: (Date.now()+1000000), html: oh});
          }
        }catch(_){ }
        (baseList||[]).filter(function(r){ try{ return !clearedKeys.has('req:'+parseInt(r.id||0,10)); }catch(_){ return true; } }).forEach(function(r){
          const id = parseInt(r.id||0,10);
          const st = String(r.status||'');
          sigParts.push(id+'|'+st);
          const when = r.approved_at || r.rejected_at || r.borrowed_at || r.returned_at || r.cancelled_at || r.created_at || r.created_at_display;
          const whenTxt = when ? String(when) : '';
          let tsn = 0; try { const d = when ? new Date(String(when).replace(' ','T')) : null; if (d){ const t=d.getTime(); if(!isNaN(t)) tsn=t; } } catch(_){ }
          if (tsn>latest) latest=tsn;
          // Status mapping
          let dispStatus = st;
          let badgeCls='bg-secondary';
          const isOverdue = (()=>{ try{ return ovset.has(id); }catch(_){ return false; } })();
          if (st==='Rejected' || st==='Cancelled') { dispStatus='Rejected'; badgeCls='bg-danger'; }
          else if (st==='Returned') { dispStatus='Returned'; badgeCls='bg-success'; }
          else if (st==='Approved' || st==='Borrowed') {
            if (isOverdue) { dispStatus='Overdue'; badgeCls='bg-warning text-dark'; }
            else { dispStatus='Approved'; badgeCls='bg-success'; }
          }
          const key = 'req:'+id;
          ephemCount++;
          const html = '<div class="list-group-item d-flex justify-content-between align-items-start">'
            + '<div class="me-2">'
            +   '<div class="d-flex w-100 justify-content-between">'
            +     '<a href="user_request.php" class="fw-bold text-decoration-none">#'+id+' '+escapeHtml(r.item_name||'')+'</a>'
            +     '<small class="text-muted">'+whenTxt+'</small>'
            +   '</div>'
            +   '<div class="mb-0">Status: <span class="badge '+badgeCls+'">'+escapeHtml(dispStatus||'')+'</span></div>'
            + '</div>'
            + '<div><button type="button" class="btn-close u-clear-one" aria-label="Clear" data-key="'+key+'"></button></div>'
            + '</div>';
          combined.push({type:'base', id, ts: tsn, html});
        });
        try{
          const decisions = (dn && Array.isArray(dn.decisions)) ? dn.decisions : [];
          decisions.forEach(function(dc){
            const rid = parseInt(dc.id||0,10)||0; if (!rid) return;
            const msg = escapeHtml(String(dc.message||''));
            const ts = String(dc.ts||'');
            let tsn = 0; try { const d = ts ? new Date(String(ts).replace(' ','T')) : null; if (d){ const t=d.getTime(); if(!isNaN(t)) tsn=t; } }catch(_){ }
            if (tsn>latest) latest=tsn;
            const whenHtml = ts ? ('<small class="text-muted">'+escapeHtml(ts)+'</small>') : '';
            const key = 'decision:'+rid+'|'+escapeHtml(String(dc.status||''));
            try{ if (clearedKeys.has(key)) return; }catch(_){ }
            ephemCount++;
            const html = '<div class="list-group-item d-flex justify-content-between align-items-start">'
              + '<div class="me-2">'
              +   '<div class="d-flex w-100 justify-content-between">'
              +     '<strong>#'+rid+' Decision</strong>'
              +     whenHtml
              +   '</div>'
              +   '<div class="mb-0">'+msg+'</div>'
              + '</div>'
              + '<div><button type="button" class="btn-close u-clear-one" aria-label="Clear" data-key="'+key+'"></button></div>'
              + '</div>';
            combined.push({type:'extra', id: rid, ts: tsn, html});
          });
        }catch(_){ }
        combined.sort(function(a,b){
          // Overdue summary first, then strictly by timestamp (newest to oldest), ignoring type
          if ((a.type==='overdue') !== (b.type==='overdue')) return (a.type==='overdue') ? -1 : 1;
          if (b.ts !== a.ts) return b.ts - a.ts;
          return (b.id||0) - (a.id||0);
        });
        let rows = combined.map(function(x){ return x.html; });
        try {
          const hasOverdue = (combined.length>0 && combined[0].type==='overdue');
          if (ephemCount > 0) {
            const header = '<div class="list-group-item"><div class="d-flex justify-content-between align-items-center"><span class="small text-muted">Notifications</span><button type="button" class="btn btn-sm btn-outline-secondary btn-2xs" id="uClearAllBtn">Clear All</button></div></div>';
            rows.splice(hasOverdue ? 1 : 0, 0, header);
          }
        } catch(_){ }
        return { rows, latest, sig: sigParts.join(',') };
      }
      function renderList(items){ const tb=document.getElementById('userNotifList'); if(!tb) return; 
        const built = composeRows(items, undefined, (window.__UR_OVCOUNT__||0), (window.__UR_OVSET__||new Set()));
        // Update dot state
        try {
          const lastOpen = parseInt(localStorage.getItem('ud_notif_last_open')||'0',10)||0;
          currentSig = built.sig; lastSig = localStorage.getItem('ud_notif_sig_open') || '';
          latestTs = built.latest;
          const changed = !!(built.sig && built.sig !== lastSig);
          const any = built.rows.length>0;
          const showDot = any && (changed || (latestTs>0 && latestTs > lastOpen));
          if (bellDot) bellDot.classList.toggle('d-none', !showDot);
        } catch(_){ }
        // Single DOM commit if content changed
        const html = built.rows.join('');
        if (listEl && html !== lastHtml){ listEl.innerHTML = html; lastHtml = html; }
        if (emptyEl) emptyEl.style.display = (html && html.trim()!=='') ? 'none' : 'block';
        try{ repositionBellDropdown(); }catch(_){ }
      try { if (bellModal && bellModal.style && bellModal.style.display === 'flex') { copyNotifToMobile(); } } catch(_){ }
      }
      let lastHtml = '';
      function poll(force){
        const isOpen = (dropdown && dropdown.classList && dropdown.classList.contains('show')) || (bellModal && bellModal.style && bellModal.style.display === 'flex');
        if (fetching && !force) return; fetching=true;
        if (!isOpen) setLoadingList();
        Promise.all([
          fetch('user_request.php?action=my_requests_status', { cache:'no-store' }).then(r=>r.json()).catch(()=>({})),
          fetch('user_request.php?action=user_notifications', { cache:'no-store' }).then(r=>r.json()).catch(()=>({})),
          fetch('user_request.php?action=my_overdue', { cache:'no-store' }).then(r=>r.json()).catch(()=>({}))
        ])
        .then(([d, dn, ov])=>{
          const raw = (d && Array.isArray(d.requests)) ? d.requests : [];
          const base = raw.filter(r=>['Approved','Rejected','Borrowed','Returned'].includes(String(r.status||'')));
          const picked = base;
          const ovList = (ov && Array.isArray(ov.overdue)) ? ov.overdue : [];
          const ovSet = new Set(ovList.map(o=> parseInt(o.request_id||0,10)).filter(n=>n>0));
          const ovCount = ovList.length;
          window.__UR_OVCOUNT__ = ovCount; window.__UR_OVSET__ = ovSet;
          const built = composeRows(picked, dn, ovCount, ovSet);
          // Update persistent overdue popup behind modal
          try { addOrUpdateOverdueNotices(ovList); } catch(_){ }
          // Update dot state
          try {
            const lastOpen = parseInt(localStorage.getItem('ud_notif_last_open')||'0',10)||0;
            currentSig = built.sig; lastSig = localStorage.getItem('ud_notif_sig_open') || '';
            latestTs = built.latest;
            const changed = !!(built.sig && built.sig !== lastSig);
            const any = built.rows.length>0;
            const showDot = any && (changed || (latestTs>0 && latestTs > lastOpen));
            if (bellDot) bellDot.classList.toggle('d-none', !showDot);
          } catch(_){ }
          // Single DOM commit if content changed
          const html = built.rows.join('');
          if (listEl && html !== lastHtml){ listEl.innerHTML = html; lastHtml = html; }
          if (emptyEl) emptyEl.style.display = (html && html.trim()!=='') ? 'none' : 'block';
          try{ repositionBellDropdown(); }catch(_){ }
          try { if (bellModal && bellModal.style && bellModal.style.display === 'flex') { copyNotifToMobile(); } } catch(_){ }
        })
        .catch(()=>{ })
        .finally(()=>{ fetching=false; });
      }
      bellBtn.addEventListener('click', function(e){
        e.stopPropagation();
        // On mobile, open centered modal after immediate fetch; on desktop, open dropdown and fetch
        if (isMobile()){
          e.preventDefault(); setLoadingList(); poll(true);
          setTimeout(()=>{ try{ copyNotifToMobile(); openMobileModal(); }catch(_){ } }, 50);
        } else {
          dropdown.classList.toggle('show');
          dropdown.style.display = dropdown.classList.contains('show') ? 'block' : '';
          // Anchor under the bell wrapper to avoid viewport reflow
          dropdown.style.position = 'absolute';
          dropdown.style.left = 'auto';
          dropdown.style.right = '0px';
          dropdown.style.top = (bellBtn.offsetHeight + 6) + 'px';
          dropdown.style.transform = 'none';
          dropdown.style.margin = '0';
          try{ dropdown.style.zIndex = '4000'; }catch(_){ }
          setLoadingList();
          poll(true);
        }
        if (bellDot) bellDot.classList.add('d-none');
        try {
          const ts = (latestTs && !isNaN(latestTs)) ? latestTs : 0;
          localStorage.setItem('ud_notif_last_open', String(ts));
          localStorage.setItem('ud_notif_sig_open', currentSig || '');
        } catch(_){ }
      });
      // Close behaviors
      document.addEventListener('click', function(ev){
        const t = ev.target;
        if (t && (t.closest('#userBellDropdown') || t.closest('#userBellBtn') || t.closest('#userBellWrap') || t.closest('#userBellModal'))) return;
        dropdown.classList.remove('show');
        dropdown.style.display='';
        // After closing, refresh once to sync latest
        try{ setTimeout(()=>{ poll(true); }, 50); }catch(_){ }
      });
      if (bellBackdrop) bellBackdrop.addEventListener('click', closeMobileModal);
      if (mobileCloseBtn) mobileCloseBtn.addEventListener('click', closeMobileModal);
      document.addEventListener('keydown', function(ev){ if (ev.key==='Escape'){ closeMobileModal(); dropdown.classList.remove('show'); dropdown.style.display=''; try{ setTimeout(()=>{ poll(true); }, 50); }catch(_){ } } });
      function repositionBellDropdown(){
        if (!dropdown.classList.contains('show')) return;
        try{
          dropdown.style.display='block';
          dropdown.style.position='absolute';
          dropdown.style.left='auto';
          dropdown.style.right='0px';
          dropdown.style.top = (bellBtn.offsetHeight + 6) + 'px';
          dropdown.style.transform='none';
          dropdown.style.margin='0';
        }catch(_){ }
      }
      window.addEventListener('resize', repositionBellDropdown);
      window.addEventListener('scroll', repositionBellDropdown, true);
      // Persistent bottom-right overdue popup (behind modals)
      function ensurePersistentWrap(){
        let wrap = document.getElementById('userPersistentWrap');
        if (!wrap){
          wrap = document.createElement('div');
          wrap.id = 'userPersistentWrap';
          wrap.style.position = 'fixed';
          wrap.style.right = '16px';
          wrap.style.bottom = '16px';
          wrap.style.left = '';
          wrap.style.zIndex = '1030'; // behind Bootstrap modal/backdrop
          wrap.style.display = 'flex';
          wrap.style.flexDirection = 'column';
          wrap.style.gap = '8px';
          wrap.style.pointerEvents = 'none';
          document.body.appendChild(wrap);
        }
        try{
          if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
            wrap.style.right = '8px';
            if (!wrap.getAttribute('data-bottom')) { wrap.style.bottom = '64px'; }
          }
        }catch(_){ }
        return wrap;
      }
      function addOrUpdateOverdueNotices(items){
        const wrap = ensurePersistentWrap();
        const list = Array.isArray(items) ? items : [];
        const count = list.length;
        try {
          let viewing = false;
          try { if (window.__UR_VISIBLE_SECTION__ === 'section-overdue') viewing = true; } catch(_){ }
          if (!viewing) {
            try { const mdl = document.getElementById('userOverdueModal'); if (mdl && mdl.classList && mdl.classList.contains('show')) viewing = true; } catch(_){ }
          }
          if (viewing) {
            const existing = document.getElementById('ov-alert-summary');
            if (existing) { try{ existing.remove(); }catch(_){ existing.style.display='none'; } }
            return;
          }
        } catch(_){ }
        // keep one summary card only
        const key = 'ov-alert-summary';
        let el = document.getElementById(key);
        if (count === 0) { if (el){ try{ el.remove(); }catch(_){ el.style.display='none'; } } return; }
        const html = '<i class="bi bi-exclamation-octagon me-2"></i>' + (count===1 ? 'You have an overdue item, Click to view.' : ('You have overdue items ('+count+'), Click to view.'));
        let first = false;
        if (!el){
          el = document.createElement('div');
          el.id = key;
          el.className = 'alert alert-danger shadow-sm border-0';
          el.style.minWidth = '300px'; el.style.maxWidth = '340px';
          el.style.cursor='pointer'; el.style.margin='0'; el.style.lineHeight='1.25'; el.style.borderRadius='8px';
          el.style.pointerEvents='auto';
          el.addEventListener('click', function(){ window.location.href='user_request.php?view=overdue'; });
          wrap.appendChild(el);
          first = true;
        }
        const prev = parseInt(el.getAttribute('data-count') || '-1', 10);
        if (prev !== count) { el.setAttribute('data-count', String(count)); el.innerHTML = html; }
        try {
          if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
            el.style.minWidth='160px'; el.style.maxWidth='200px'; el.style.padding='4px 6px'; el.style.fontSize='10px';
            const ic = el.querySelector('i'); if (ic) ic.style.fontSize='12px';
          } else { el.style.minWidth='300px'; el.style.maxWidth='340px'; el.style.padding=''; el.style.fontSize=''; }
        } catch(_){ }
        if (first){ try{ /* optional beep */ }catch(_){ } }
        try{ adjustToastOffsets(); }catch(_){ }
      }
      poll(false);
      setInterval(()=>{ if (document.visibilityState==='visible') poll(false); }, 1000);
      // Delegate click for Return via QR buttons inside notifications (desktop and mobile)
      function triggerReturnModal(rid, bid, name){
        try{
          const qrModal = document.getElementById('userQrReturnModal');
          if (qrModal && window.bootstrap && bootstrap.Modal){
            const tmp = document.createElement('button');
            tmp.type='button'; tmp.style.display='none';
            tmp.setAttribute('data-bs-toggle','modal');
            tmp.setAttribute('data-bs-target','#userQrReturnModal');
            tmp.setAttribute('data-reqid', String(rid||''));
            if (bid) tmp.setAttribute('data-borrow_id', String(bid));
            if (name) tmp.setAttribute('data-model_name', String(name));
            document.body.appendChild(tmp); tmp.click(); setTimeout(()=>{ try{ tmp.remove(); }catch(_){ } }, 500);
            // Close bell UI if open
            try{ dropdown.classList.remove('show'); closeMobileModal(); }catch(_){ }
          }
        }catch(_){ }
      }
      listEl && listEl.addEventListener('click', function(e){ const a=e.target && e.target.closest? e.target.closest('.open-qr-return'):null; if(!a) return; e.preventDefault(); const rid=a.getAttribute('data-reqid')||''; const bid=a.getAttribute('data-borrow_id')||''; const name=a.getAttribute('data-model_name')||''; triggerReturnModal(rid,bid,name); });
      mobileListEl && mobileListEl.addEventListener('click', function(e){ const a=e.target && e.target.closest? e.target.closest('.open-qr-return'):null; if(!a) return; e.preventDefault(); const rid=a.getAttribute('data-reqid')||''; const bid=a.getAttribute('data-borrow_id')||''; const name=a.getAttribute('data-model_name')||''; triggerReturnModal(rid,bid,name); });
      document.addEventListener('click', function(ev){
        const x = ev.target && ev.target.closest && ev.target.closest('.u-clear-one');
        if (x){ ev.preventDefault(); const key = x.getAttribute('data-key') || ''; if (!key) return; const fd = new FormData(); fd.append('key', key); fetch('user_request.php?action=user_notif_clear', { method:'POST', body: fd }).then(r=>r.json()).then(()=>{ poll(true); }).catch(()=>{}); return; }
        if (ev.target && ev.target.id === 'uClearAllBtn'){ ev.preventDefault(); const fd = new FormData(); fd.append('limit','300'); fetch('user_request.php?action=user_notif_clear_all', { method:'POST', body: fd }).then(r=>r.json()).then(()=>{ poll(true); }).catch(()=>{}); }
      });
    })();
  </script>
  
  
  <script>
  (function(){
    const readerId = 'urQrReader';

    // Helper: extract serial/id from arbitrary scanned text (URLs, labels)
    function extractSerialFromText(txt){
      try {
        let s = String(txt||'').trim();
        if (s === '') return '';
        // URL with sid or id param
        if (/^https?:\/\//i.test(s)) {
          try {
            const u = new URL(s);
            const sid = u.searchParams.get('sid') || u.searchParams.get('serial') || u.searchParams.get('serial_no');
            if (sid && sid.trim()) return sid.trim();
            const id = u.searchParams.get('id');
            if (id && id.trim()) return id.trim();
          } catch(_) {}
        }
        // Label formats like "Serial: ABC123" or "SN ABC123"
        const m = s.match(/(?:serial\s*[:#-]?\s*|sn\s*[:#-]?\s*)([A-Za-z0-9_-]{3,})/i);
        if (m && m[1]) return m[1].trim();
        // Fallback: last long token of letters/digits/_/-
        const tokens = s.match(/[A-Za-z0-9_-]{3,}/g);
        if (tokens && tokens.length) return tokens[tokens.length-1];
        return s; // last resort
      } catch(_) { return String(txt||'').trim(); }
    }

    // Helper: best-effort legacy JSON parse
    function parseLegacyJSON(txt){
      try{ const d = JSON.parse(txt); if (d && typeof d === 'object') return d; }catch(_){ }
      return null;
    }
    const statusEl = document.getElementById('urQrStatus');
    const startBtn = document.getElementById('urQrStartBtn');
    const stopBtn = document.getElementById('urQrStopBtn');
    const reqBtn = document.getElementById('urQrRequestBtn');
    const camSel = document.getElementById('urCameraSelect');
    const locWrap = document.getElementById('urReqLocWrap');
    const locInput = document.getElementById('urReqLocation');
    const borrowBtn = document.getElementById('urBorrowSubmit');
    const reserveWrap = document.getElementById('urReserveWrap');
    const resFrom = document.getElementById('urResFrom');
    const resTo = document.getElementById('urResTo');
    const resHint = document.getElementById('urResStartHint');
    const reserveBtn = document.getElementById('urReserveSubmit');
    const tImmediate = document.getElementById('urQrTypeImmediate');
    const tReservation = document.getElementById('urQrTypeReservation');
    const modal = document.getElementById('urQrScanModal');
    const readerEl = document.getElementById(readerId);
    const readerPlaceholder = readerEl ? readerEl.innerHTML : '';

    let qr = null; let scanning = false; let starting = false; let lastData = null; let lastCamId = ''; let qrMode = 'immediate'; let qrEarliest = '';

    function pad2(n){ return (n<10?('0'+n):n); }
    function format12h(dt){ let h=dt.getHours(); const ampm=(h>=12?'PM':'AM'); let h12=h%12; if(h12===0) h12=12; return h12+':'+pad2(dt.getMinutes())+' '+ampm; }
    function formatRel12h(dt){
      const now=new Date(); const today=new Date(now.getFullYear(),now.getMonth(),now.getDate());
      const day=new Date(dt.getFullYear(),dt.getMonth(),dt.getDate());
      const diffDays=Math.round((day-today)/86400000);
      const tStr=format12h(dt);
      if(diffDays===0) return 'Will be available at: '+tStr;
      if(diffDays===1) return 'Will be available tomorrow at '+tStr;
      const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      return 'Will be available at: '+months[dt.getMonth()]+' '+dt.getDate()+', '+dt.getFullYear()+' '+tStr;
    }

    function setReqTypeUI(mode){
      qrMode=(mode==='reservation')?'reservation':'immediate';
      if (tImmediate && tReservation){
        if (qrMode==='immediate'){ tImmediate.classList.add('active'); tReservation.classList.remove('active'); }
        else { tReservation.classList.add('active'); tImmediate.classList.remove('active'); }
      }
      const expWrap=document.getElementById('urExpectedWrap');
      if (expWrap) expWrap.classList.toggle('d-none', qrMode!=='immediate');
      if (reserveWrap) reserveWrap.classList.toggle('d-none', qrMode!=='reservation');
      // Always show the location row for both modes; only hide the Borrow button column for reservation
      if (locWrap) { locWrap.classList.remove('d-none'); }
      if (borrowBtn && borrowBtn.parentElement) { borrowBtn.parentElement.classList.toggle('d-none', qrMode!=='immediate'); }
      if (reserveBtn){
        const grp=reserveBtn.closest('.row');
        if (grp && grp.parentElement && grp.parentElement.parentElement){ grp.parentElement.parentElement.classList.toggle('d-none', qrMode!=='reservation'); }
      }
      // Ensure click handlers are bound regardless of which validation branch was taken after scan
      try { if (borrowBtn) borrowBtn.onclick = function(){ submitBorrow(); }; } catch(_){ }
      try { if (reserveBtn) reserveBtn.onclick = function(){ submitReserve(); }; } catch(_){ }
      if (qrMode==='reservation') updateReserveState(); else updateBorrowState();
    }

    async function fetchQrReservationStartHint(){
      try {
        if (!resHint) return; resHint.textContent=''; qrEarliest='';
        if (!lastData || !lastData.data || !lastData.data.model) return;
        const m=String(lastData.data.model||''); if (!m) return;
        const r=await fetch('user_request.php?action=reservation_start_hint&model='+encodeURIComponent(m), {cache:'no-store'});
        const d=await r.json().catch(()=>null);
        const earliest=(d && d.earliest) ? String(d.earliest) : '';
        qrEarliest=earliest;
        if (earliest){
          try{
            const dt=new Date(earliest.replace(' ','T'));
            if (!isNaN(dt)){
              if (resFrom){ resFrom.min = dt.getFullYear()+'-'+pad2(dt.getMonth()+1)+'-'+pad2(dt.getDate())+'T'+pad2(dt.getHours())+':'+pad2(dt.getMinutes()); }
              resHint.textContent = formatRel12h(dt);
            } else { resHint.textContent = 'Will be available at: '+earliest; }
          } catch(_){ resHint.textContent = 'Will be available at: '+earliest; }
        }
      } catch(_){ }
    }

    function updateReserveState(){
      if (!reserveBtn) return;
      const locOk = !!(locInput && locInput.value && locInput.value.trim());
      let ok=false;
      try{
        const s = resFrom && resFrom.value ? new Date(resFrom.value) : null;
        const e = resTo && resTo.value ? new Date(resTo.value) : null;
        const now = new Date(); const max=new Date(); max.setDate(max.getDate()+7);
        ok = !!(s && !isNaN(s) && e && !isNaN(e) && s>now && e>s && e<=max && ((e-s) <= 24*60*60*1000));
        if (ok && qrEarliest){ const m=new Date(qrEarliest.replace(' ','T')); if (m && !isNaN(m) && s < m) ok=false; }
      } catch(_){ ok=false; }
      // Require a valid scanned item for reservation (any recognized scan)
      const hasScan = !!(lastData);
      reserveBtn.disabled = !(hasScan && locOk && ok);
      const enabled = !reserveBtn.disabled;
      try { reserveBtn.classList.remove('btn-primary','btn-secondary','btn-outline-secondary'); reserveBtn.classList.add(enabled ? 'btn-primary' : 'btn-outline-secondary'); } catch(_){ }
    }

    function updateBorrowState(){
      if (!borrowBtn) return;
      const locOk = !!(locInput && locInput.value && locInput.value.trim());
      const expEl = document.getElementById('urExpectedReturn');
      const expOk = !!(expEl && String(expEl.value||'').trim());
      // Require a valid scanned item AND that it is allowed for immediate borrow
      const hasValidScan = !!(lastData && lastData.vr && lastData.vr.allowed);
      const enabled = !!(hasValidScan && locOk && expOk);
      borrowBtn.disabled = !enabled;
      try { borrowBtn.classList.remove('btn-primary','btn-secondary','btn-outline-secondary'); borrowBtn.classList.add(enabled ? 'btn-primary' : 'btn-outline-secondary'); } catch(_){ }
    }

    function setStatus(msg, cls){ if (!statusEl) return; statusEl.className = 'small ' + (cls||'text-muted'); statusEl.textContent = String(msg||''); }

    function populateCams(){
      if (!camSel) return;
      Html5Qrcode.getCameras()
        .then(devs=>{
          while (camSel.options.length>1) camSel.remove(1);
          devs.forEach((d,i)=>{ const opt=document.createElement('option'); opt.value=d.id; opt.text=d.label||('Camera '+(i+1)); camSel.appendChild(opt); });
          try {
            const saved = localStorage.getItem('ur_camera');
            if (saved && Array.isArray(devs) && devs.some(d=>d.id===saved)) { camSel.value = saved; }
          } catch(_){ }
        })
        .catch(()=>setStatus('Error accessing camera devices','text-danger'));
    }
    // Persist selection and switch camera live if scanning
    if (camSel) {
      camSel.addEventListener('change', function(){
        try { localStorage.setItem('ur_camera', camSel.value || ''); } catch(_) { }
        // If already scanning, restart with new camera
        if (scanning) { try { stopScan(); } catch(_){} setTimeout(startScan, 150); }
      });
    }
    function stopScan(){ if (qr && scanning){ try{ qr.stop().then(()=>{ try{qr.clear();}catch(_){ } scanning=false; qr=null; }); }catch(_){ scanning=false; qr=null; } } if (stopBtn) stopBtn.classList.add('d-none'); if (startBtn) startBtn.classList.remove('d-none'); if (camSel) camSel.disabled=false; }
    function startScan(){
      if (starting || scanning) return;
      starting = true;
      setStatus('Initializing camera...','text-muted');
      // Clear previous scan state so rescan starts fresh
      try { document.getElementById('urInfoCard').classList.add('d-none'); } catch(_){ }
      if (locWrap) locWrap.classList.add('d-none');
      if (locInput) locInput.value = '';
      if (borrowBtn){ borrowBtn.disabled = true; try{ borrowBtn.classList.remove('btn-primary'); borrowBtn.classList.add('btn-secondary'); }catch(_){ } }
      if (reserveWrap) reserveWrap.classList.add('d-none');
      if (reserveBtn){ reserveBtn.disabled = true; try{ reserveBtn.classList.remove('btn-primary'); reserveBtn.classList.add('btn-secondary'); }catch(_){ } }
      lastData = null;
      const isLocal = location.hostname==='localhost'||location.hostname==='127.0.0.1'||/^10\.|^192\.168\./.test(location.hostname);
      if(!window.isSecureContext && !isLocal){ setStatus('Camera access requires HTTPS or localhost.','text-danger'); starting=false; return; }
      // Determine camera to use: current selection, last used, or auto-pick first/back camera
      const useCamera = async () => {
        let camId = '';
        if (camSel && camSel.value) camId = camSel.value;
        else if (lastCamId) camId = lastCamId;
        else {
          try {
            const devices = await Html5Qrcode.getCameras();
            if (devices && devices.length) {
              const pref = devices.find(d=>/back|rear|environment/i.test(d.label));
              camId = (pref && pref.id) || devices[0].id;
              if (camSel) {
                // reflect chosen camera in UI for user clarity
                let found = false;
                for (let i=0;i<camSel.options.length;i++){ if (camSel.options[i].value===camId){ camSel.selectedIndex=i; found=true; break; } }
                if (!found) { const opt=document.createElement('option'); opt.value=camId; opt.text=pref?(pref.label||'Camera'): (devices[0].label||'Camera'); camSel.appendChild(opt); camSel.value=camId; }
              }
            }
          } catch(_) {}
        }
        if (!camId) { setStatus('Please select a camera first','text-danger'); starting=false; return; }
        // Clear any placeholder before starting
        if (readerEl) readerEl.innerHTML = '';
        try{
          if(qr){ try{ qr.stop().catch(()=>{}); }catch(_){ } try{ qr.clear(); }catch(_){ } qr=null; }
        }catch(_){ }
        qr = new Html5Qrcode(readerId);
        qr.start(camId,{fps:10,qrbox:{width:300,height:300},aspectRatio:1.0,disableFlip:false}, onScanSuccess, onScanFailure)
          .then(()=>{ scanning=true; starting=false; lastCamId = camId; try{ localStorage.setItem('ur_camera', camId || ''); }catch(_){ } setStatus('Camera active. Point to a QR code.','text-success'); if(startBtn) startBtn.classList.add('d-none'); if(stopBtn) stopBtn.classList.remove('d-none'); if (camSel) camSel.disabled=true; })
          .catch(err=>{ starting=false; setStatus('Unable to start camera: '+(err && (err.message||err)),'text-danger'); if(qr){ try{qr.clear();}catch(_){ } qr=null; } if (readerEl && readerPlaceholder!==''){ readerEl.innerHTML = readerPlaceholder; } });
      };
      // kick off camera selection/use
      useCamera();
    }

    function onScanFailure(err){ /* ignore frequent no-code messages */ }

    async function onScanSuccess(decodedText){
      try {
        await stopScan();
        let modelId = 0, modelName = '', category = '';
        let serialScanned = (decodedText||'').trim();
        // legacy JSON
        try {
          const data = JSON.parse(decodedText);
          if (data){ if(!data.model_id && data.item_id){ data.model_id=data.item_id; delete data.item_id; } modelId = parseInt(data.model_id||0,10)||0; modelName = String(data.model||'').trim() || String(data.item_name||'').trim(); category = String(data.category||'').trim(); serialScanned = String(data.serial_no||'').trim() || serialScanned; }
        } catch(_){ }
        // Normalize serial from URL or labeled text if needed
        if (!modelId && modelName===''){
          if (serialScanned) {
            try {
              if (/^https?:\/\//i.test(serialScanned)) {
                const u = new URL(serialScanned);
                const sid = (u.searchParams.get('sid')||u.searchParams.get('serial')||u.searchParams.get('serial_no')||u.searchParams.get('id')||'').trim();
                if (sid) serialScanned = sid; else {
                  const parts = u.pathname.split('/').filter(Boolean); if (parts.length) serialScanned = parts[parts.length-1];
                }
              } else {
                const m = serialScanned.match(/(?:serial\s*[:#-]?\s*|sn\s*[:#-]?\s*)([A-Za-z0-9_-]{1,})/i);
                if (m && m[1]) serialScanned = m[1].trim();
              }
            } catch(_) {}
          }
          if (!serialScanned){ setStatus('Invalid QR data.','text-danger'); return; }
          setStatus('Looking up serial...','text-muted');
          const look = await fetch('inventory.php?action=item_by_serial&sid='+encodeURIComponent(serialScanned), {cache:'no-store'});
          const jr = await look.json().catch(()=>({success:false}));
          if (!jr || !jr.success || !jr.item){ setStatus('Serial not recognized.','text-danger'); return; }
          const it = jr.item; modelId = parseInt(it.id||0,10)||0; modelName = String(it.model||'').trim() || String(it.item_name||'').trim(); category = String(it.category||'').trim() || 'Uncategorized';
        }
        if (!modelId && modelName===''){ setStatus('Invalid QR data.','text-danger'); return; }

        const payload = { model_id: modelId, model: modelName, item_name: modelName, category: category };
        if (serialScanned) { payload.qr_serial_no = serialScanned; }
        setStatus('Validating item...','text-muted');
        const res = await fetch('user_request.php?action=validate_qr', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
        const vr = await res.json().catch(()=>({allowed:false,reason:'Validation failed'}));
        lastData = { data: payload, vr };
        lastData.serial_no = serialScanned || '';

        // Show details card (always show base info; enrich already done below)
        // Decide default mode: if immediate allowed, default to immediate; else try reservation
        if (locWrap) locWrap.classList.remove('d-none');
        if (vr && vr.allowed){
          setReqTypeUI('immediate');
          const expWrap = document.getElementById('urExpectedWrap'); if (expWrap) expWrap.classList.remove('d-none');
          if (borrowBtn){ borrowBtn.onclick = function(){ submitBorrow(); }; updateBorrowState(); }
          if (locInput){ locInput.oninput = function(){ updateBorrowState(); if (reserveBtn) updateReserveState(); }; try{ locInput.focus(); }catch(_){ } }
          // Show cutoff hint if item is single-quantity and has an upcoming reservation
          (async function(){ try{
            const hEl = document.getElementById('urExpectedHint'); if (!hEl) return; hEl.classList.add('d-none'); hEl.textContent='';
            const q = (payload && payload.model) ? ('model='+encodeURIComponent(payload.model)) : (lastData && lastData.serial_no ? ('sid='+encodeURIComponent(lastData.serial_no)) : '');
            if (!q) return; const r = await fetch('user_request.php?action=single_qty_hint&'+q, {cache:'no-store'});
            const d = await r.json().catch(()=>null);
            if (d && d.ok && d.single && d.hasUpcoming && d.cutoff){
              const rf = d.reserve_from? new Date(String(d.reserve_from).replace(' ','T')) : null;
              const co = new Date(String(d.cutoff).replace(' ','T'));
              const pad=(n)=>n<10?('0'+n):n; const fmt=(dt)=>{ const h=dt.getHours(); const ap=h>=12?'PM':'AM'; let h12=h%12; if(h12===0) h12=12; return h12+':'+pad(dt.getMinutes())+' '+ap; };
              const rfStr = rf ? fmt(rf) : '';
              const coStr = fmt(co);
              hEl.textContent = (rfStr? ('Reserved at '+rfStr+'. ') : '') + 'Set Expected Return no later than '+coStr+' to proceed.';
              hEl.classList.remove('d-none');
            }
          }catch(_){ } })();
        } else {
          setReqTypeUI('reservation');
          if (reserveWrap) reserveWrap.classList.remove('d-none');
          if (reserveBtn){ reserveBtn.disabled = true; try{ reserveBtn.classList.remove('btn-primary'); reserveBtn.classList.add('btn-secondary'); }catch(_){ } reserveBtn.onclick = function(){ submitReserve(); }; }
          if (locInput){ locInput.oninput = function(){ updateReserveState(); }; try{ locInput.focus(); }catch(_){ } }
          await fetchQrReservationStartHint();
        }

        // Show details card (always show base info; enrich with lookup)
        const badge = document.getElementById('urStatusBadge');
        const mapStatusClass = (s)=>{ switch(String(s||'')){ case 'Available': return 'bg-success'; case 'In Use': return 'bg-primary'; case 'Maintenance': return 'bg-warning'; case 'Out of Order': return 'bg-danger'; case 'Reserved': return 'bg-info'; case 'Lost': return 'bg-danger'; case 'Damaged': return 'bg-danger'; default: return 'bg-secondary'; } };
        document.getElementById('urItemName').textContent = modelName || '';
        badge.textContent = (vr && vr.status) ? vr.status : 'Unavailable';
        badge.className = 'badge ' + mapStatusClass(badge.textContent);
        document.getElementById('urCategory').textContent = category || '';
        document.getElementById('urLocation').textContent = '';
        document.getElementById('urBorrowedByWrap').style.display = 'none';
        document.getElementById('urExpectedReturnWrap').style.display = 'none';
        document.getElementById('urReservedByWrap').style.display = 'none';
        document.getElementById('urReservationEndsWrap').style.display = 'none';
        document.getElementById('urInfoCard').classList.remove('d-none');

        const q = modelId ? String(modelId) : serialScanned;
        try{
          const infoRes = await fetch('inventory.php?action=item_by_serial&sid='+encodeURIComponent(q), {cache:'no-store'});
          const info = await infoRes.json().catch(()=>({success:false}));
          if (info && info.success && info.item){
            const it = info.item;
            document.getElementById('urItemName').textContent = it.item_name || it.model || document.getElementById('urItemName').textContent;
            badge.textContent = it.status || badge.textContent;
            badge.className = 'badge ' + mapStatusClass(badge.textContent);
            document.getElementById('urCategory').textContent = it.category || document.getElementById('urCategory').textContent;
            document.getElementById('urLocation').textContent = it.location || '';
            const borrowedBy = it.borrowed_by_full_name || it.borrowed_by_username || '';
            if (borrowedBy){ document.getElementById('urBorrowedBy').textContent = borrowedBy; document.getElementById('urBorrowedByWrap').style.display=''; const exp = it.expected_return_at || ''; document.getElementById('urExpectedReturn').textContent = exp; document.getElementById('urExpectedReturnWrap').style.display = exp ? '' : 'none'; }
            const reservedBy = it.reservation_by_full_name || it.reservation_by_username || '';
            if (!borrowedBy && reservedBy){ document.getElementById('urReservedBy').textContent = reservedBy; document.getElementById('urReservedByWrap').style.display=''; const ends = it.reserved_to || ''; document.getElementById('urReservationEnds').textContent = ends; document.getElementById('urReservationEndsWrap').style.display = ends ? '' : 'none'; }
          }
        } catch(_){ }

        // Context-aware status text color
        const statusText = (document.getElementById('urStatusBadge')?.textContent || '').trim();
        let msg = ''; let cls = 'text-muted';
        switch (statusText){
          case 'Available': msg='This unit is available.'; cls='text-success'; break;
          case 'In Use': msg='This unit is currently in use.'; cls='text-danger'; break;
          case 'Reserved': msg='This unit is currently reserved.'; cls='text-info'; break;
          case 'Maintenance': msg='This unit is under maintenance.'; cls='text-warning'; break;
          case 'Out of Order': msg='This unit is out of order.'; cls='text-warning'; break;
          case 'Lost': msg='This unit is marked as lost.'; cls='text-warning'; break;
          case 'Damaged': msg='This unit is marked as damaged.'; cls='text-warning'; break;
          default: msg='Cannot borrow' + ((lastData && lastData.vr && lastData.vr.reason)?(': ' + lastData.vr.reason):'.'); cls='text-danger';
        }
        setStatus(msg, cls);
        // If reservation mode visible, keep hint updated
        if (qrMode === 'reservation') { try { await fetchQrReservationStartHint(); updateReserveState(); } catch(_){ } }
        // Start Camera button is now visible again; user can click it to rescan
      } catch(e){ setStatus('Invalid QR code data.','text-danger'); }
    }

    function submitBorrow(){
      if (!lastData || !lastData.vr || !lastData.vr.allowed) return;
      const vr = lastData.vr; const src = lastData.data || {};
      const reqLoc = (locInput && locInput.value) ? locInput.value.trim() : '';
      if (!reqLoc){ setStatus('Request location is required.','text-danger'); return; }
      const expEl = document.getElementById('urExpectedReturn');
      const exp = expEl ? String(expEl.value||'').trim() : '';
      if (!exp){ setStatus('Please set Expected Return time.','text-danger'); return; }
      const form=document.createElement('form'); form.method='POST'; form.action='user_request.php';
      const add=(n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=String(v); form.appendChild(i); };
      add('category', vr.category || 'Uncategorized');
      add('model', vr.model || (src.model||src.item_name||''));
      add('quantity', 1);
      add('details', 'Requested via QR scan');
      add('req_location', reqLoc);
      add('req_type', 'immediate');
      add('expected_return_at', expEl ? expEl.value : '');
      if (lastData && lastData.serial_no) { add('qr_serial_no', lastData.serial_no); }
      document.body.appendChild(form);
      form.submit();
    }

    function submitReserve(){
      if (!lastData) return;
      const src = lastData.data || {};
      const reqLoc = (locInput && locInput.value) ? locInput.value.trim() : '';
      if (!reqLoc){ setStatus('Request location is required.','text-danger'); return; }
      const rf = document.getElementById('urResFrom');
      const rt = document.getElementById('urResTo');
      const s = rf ? String(rf.value||'').trim() : '';
      const e = rt ? String(rt.value||'').trim() : '';
      if (!s || !e){ setStatus('Please set reservation start and end.','text-danger'); return; }
      setStatus('Submitting reservation...','text-muted');
      const form=document.createElement('form'); form.method='POST'; form.action='user_request.php';
      const add=(n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=String(v); form.appendChild(i); };
      add('category', (lastData.vr && lastData.vr.category) ? lastData.vr.category : (src.category||'Uncategorized'));
      add('model', (lastData.vr && lastData.vr.model) ? lastData.vr.model : (src.model||src.item_name||''));
      add('quantity', 1);
      add('details', 'Requested via QR scan');
      add('req_location', reqLoc);
      add('req_type', 'reservation');
      add('reserved_from', s);
      add('reserved_to', e);
      if (lastData && lastData.serial_no) { add('qr_serial_no', lastData.serial_no); }
      document.body.appendChild(form);
      form.submit();
    }

    if (borrowBtn) borrowBtn.addEventListener('click', submitBorrow);
    if (startBtn) startBtn.addEventListener('click', startScan);
    if (stopBtn) stopBtn.addEventListener('click', stopScan);

    if (modal){
      modal.addEventListener('shown.bs.modal', ()=>{
        populateCams(); setStatus('Select a camera and click Start Camera to scan.','text-muted'); lastData=null; starting=false; scanning=false; qr=null; qrMode='immediate'; qrEarliest='';
        if (stopBtn) stopBtn.classList.add('d-none'); if (startBtn) startBtn.classList.remove('d-none');
        try{
          document.getElementById('urInfoCard').classList.add('d-none');
          if (locWrap) locWrap.classList.add('d-none');
          if (locInput) locInput.value='';
          if (borrowBtn){ borrowBtn.disabled=true; try{ borrowBtn.classList.remove('btn-primary'); borrowBtn.classList.add('btn-outline-secondary'); }catch(_){ } }
          if (reserveWrap) reserveWrap.classList.add('d-none');
          if (reserveBtn){ reserveBtn.disabled=true; try{ reserveBtn.classList.remove('btn-primary'); reserveBtn.classList.add('btn-outline-secondary'); }catch(_){ } }
          if (readerEl && readerPlaceholder!==''){ readerEl.innerHTML = readerPlaceholder; }
          setReqTypeUI('immediate');
        }catch(_){ }
        // Bind toggle buttons
        if (tImmediate) tImmediate.onclick = ()=> setReqTypeUI('immediate');
        if (tReservation) tReservation.onclick = async ()=> { setReqTypeUI('reservation'); await fetchQrReservationStartHint(); };
        // React to reservation time changes
        if (resFrom) resFrom.addEventListener('input', updateReserveState);
        if (resTo) resTo.addEventListener('input', updateReserveState);
        const expEl = document.getElementById('urExpectedReturn'); if (expEl) expEl.addEventListener('input', updateBorrowState);
        // Robust click binding for reserve
        if (reserveBtn) {
          try { reserveBtn.onclick = function(){ submitReserve(); }; } catch(_){ }
          try { reserveBtn.addEventListener('click', function(ev){ ev.preventDefault(); if (!reserveBtn.disabled) submitReserve(); }); } catch(_){ }
        }
      });
      modal.addEventListener('hide.bs.modal', ()=>{ stopScan(); });
    }
    // Auto-open QR modal when open_qr=1 is present in URL
    try {
      const url = new URL(window.location.href);
      if (url.searchParams.get('open_qr') === '1') {
        const inst = bootstrap.Modal.getOrCreateInstance(modal);
        inst.show();
      }
    } catch(_){ }
  })();
  </script>
  <!-- User Overdue Details Modal -->
  <div class="modal fade" id="userOverdueDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Overdue Item Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>Borrowed At:</strong> <span id="uodBorrowedAt"></span></div>
          <div class="mb-2"><strong>Due Date:</strong> <span id="uodDueAt"></span></div>
          <div class="mb-2"><strong>Overdue (days):</strong> <span id="uodDays"></span></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
  <script>
    (function(){
      var mdl = document.getElementById('userOverdueDetailsModal');
      var lastRow = null;
      if (mdl) {
        mdl.addEventListener('show.bs.modal', function(ev){
          var btn = ev.relatedTarget; if (!btn) return;
          lastRow = btn.closest('tr');
          var b = btn.getAttribute('data-borrowed_at') || '';
          var d = btn.getAttribute('data-due_at') || '';
          var dy = btn.getAttribute('data-overdue_days') || '';
          var elB = document.getElementById('uodBorrowedAt'); if (elB) elB.textContent = b;
          var elD = document.getElementById('uodDueAt'); if (elD) elD.textContent = d;
          var elY = document.getElementById('uodDays'); if (elY) elY.textContent = dy;
        });
        mdl.addEventListener('hidden.bs.modal', function(){
          try {
            if (lastRow && lastRow.parentElement) {
              var tb = document.getElementById('myOverdueTbody');
              lastRow.parentElement.removeChild(lastRow);
              lastRow = null;
              if (tb && tb.children.length === 0) {
                tb.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No overdue items.</td></tr>';
              }
            }
          } catch(_){ }
        });
      }
    })();
  </script>
  <!-- User Borrowed Details Modal -->
  <div class="modal fade" id="userBorrowedDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Borrowed Item Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>Category:</strong> <span id="ubdCategory"></span></div>
          <div class="mb-2"><strong>Approved At:</strong> <span id="ubdApprovedAt"></span></div>
          <div class="mb-2"><strong>Approved By:</strong> <span id="ubdApprovedBy"></span></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
  <script>
    (function(){
      var mdl = document.getElementById('userBorrowedDetailsModal');
      if (!mdl) return;
      mdl.addEventListener('show.bs.modal', function(ev){
        var tr = ev.relatedTarget; if (!tr) return;
        var c = tr.getAttribute('data-category') || '';
        var a = tr.getAttribute('data-approved_at') || '';
        var b = tr.getAttribute('data-approved_by') || '';
        var elC = document.getElementById('ubdCategory'); if (elC) elC.textContent = c;
        var elA = document.getElementById('ubdApprovedAt'); if (elA) elA.textContent = a;
        var elB = document.getElementById('ubdApprovedBy'); if (elB) elB.textContent = b;
      });
    })();
  </script>
  <style>
    @media (max-width: 768px) {
      .bottom-nav{ position: fixed; bottom: 0; left:0; right:0; z-index: 1050; background:#fff; border-top:1px solid #dee2e6; display:flex; justify-content:space-around; padding:8px 6px; transition: transform .2s ease-in-out; }
      .bottom-nav.hidden{ transform: translateY(100%); }
      .bottom-nav a{ text-decoration:none; font-size:12px; color:#333; display:flex; flex-direction:column; align-items:center; gap:4px; }
      .bottom-nav a .bi{ font-size:18px; }
      .bottom-nav-toggle{ position: fixed; right: 14px; bottom: 14px; z-index: 1060; border-radius: 999px; box-shadow: 0 2px 8px rgba(0,0,0,.2); transition: bottom .2s ease-in-out; }
      .bottom-nav-toggle.raised{ bottom: 78px; }
      .bottom-nav-toggle .bi{ font-size: 1.2rem; }
    }
  </style>
  <?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'user'): ?>
  <button type="button" class="btn btn-primary bottom-nav-toggle d-md-none" id="bnToggleUR" aria-controls="urBottomNav" aria-expanded="false" title="Open menu">
    <i class="bi bi-list"></i>
  </button>
  <nav class="bottom-nav d-md-none hidden" id="urBottomNav">
    <a href="user_dashboard.php" aria-label="Dashboard">
      <i class="bi bi-speedometer2"></i>
      <span>Dashboard</span>
    </a>
    <a href="user_request.php" aria-label="Request">
      <i class="bi bi-clipboard-plus"></i>
      <span>Request</span>
    </a>
    <a href="change_password.php" aria-label="Password">
      <i class="bi bi-key"></i>
      <span>Password</span>
    </a>
    <a href="logout.php" aria-label="Logout" onclick="return confirm('Logout now?');">
      <i class="bi bi-box-arrow-right"></i>
      <span>Logout</span>
    </a>
  </nav>
  <script>
    (function(){
      var btn = document.getElementById('bnToggleUR');
      var nav = document.getElementById('urBottomNav');
      function setPersistentWrapOffset(open){
        try{
          if (!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches)) return;
          var wrap = document.getElementById('userPersistentWrap');
          if (!wrap) return;
          var val = open ? '140px' : '64px';
          wrap.style.bottom = val;
          wrap.setAttribute('data-bottom', val);
        }catch(_){ }
      }
      if (btn && nav) {
        btn.addEventListener('click', function(){
          var hid = nav.classList.toggle('hidden');
          btn.setAttribute('aria-expanded', String(!hid));
          if (!hid) {
            btn.classList.add('raised');
            btn.title = 'Close menu';
            var i = btn.querySelector('i'); if (i) { i.className = 'bi bi-x'; }
            setPersistentWrapOffset(true);
            try{ adjustToastOffsets(); }catch(_){ }
          } else {
            btn.classList.remove('raised');
            btn.title = 'Open menu';
            var i2 = btn.querySelector('i'); if (i2) { i2.className = 'bi bi-list'; }
            setPersistentWrapOffset(false);
            try{ adjustToastOffsets(); }catch(_){ }
          }
        });
        // Initialize position
        try { var isOpen = !nav.classList.contains('hidden'); setPersistentWrapOffset(isOpen); } catch(_){ }
      }
    })();
  </script>
  <script>
    (function(){
      try{
        var p=(location.pathname.split('/').pop()||'').split('?')[0].toLowerCase();
        document.querySelectorAll('.bottom-nav a[href]').forEach(function(a){
          var h=(a.getAttribute('href')||'').split('?')[0].toLowerCase();
          if(h===p){ a.classList.add('active'); a.setAttribute('aria-current','page'); }
        });
      }catch(_){ }
    })();
  </script>
  <?php endif; ?>
  </body>
</html>
  