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
if (!isset($_SESSION['username']) || $_SESSION['usertype'] !== 'user') {
    // Only users (not admins) should land here
    if (!isset($_SESSION['username'])) {
        header("Location: index.php");
    } else {
        // Admins go to admin dashboard
        header("Location: admin_dashboard.php");
    }
    exit();
}
// Mongo-first dashboard data, fallback to MySQL if Mongo is unavailable
$USED_MONGO = false;
$recent_requests = [];
$borrowable_count = $reserved_count = 0;
$reserved_list = [];
$current_borrowed_count = 0;
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();

    // Recent equipment requests
    $erCol = $db->selectCollection('equipment_requests');
    $rq = $erCol->find(['username' => (string)$_SESSION['username']], [
        'sort' => ['created_at' => -1, 'id' => -1],
        'limit' => 5,
        'projection' => ['id'=>1,'item_name'=>1,'status'=>1,'created_at'=>1]
    ]);
    foreach ($rq as $r) {
        $recent_requests[] = [
            'id' => (int)($r['id'] ?? 0),
            'item_name' => (string)($r['item_name'] ?? ''),
            'status' => (string)($r['status'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
        ];
    }

    // Borrowable count: sum of constrained availability per model (respect borrow limits)
    $iiCol = $db->selectCollection('inventory_items');
    $bmCol = $db->selectCollection('borrowable_catalog');
    // Build borrow limit map
    $borrowLimitMap = [];
    $bmCur = $bmCol->find(['active'=>1], ['projection'=>['model_name'=>1,'category'=>1,'borrow_limit'=>1]]);
    foreach ($bmCur as $b) {
      $catRaw = (string)($b['category'] ?? '');
      $cat = $catRaw !== '' ? $catRaw : 'Uncategorized';
      $mod = (string)($b['model_name'] ?? '');
      if ($mod==='') continue;
      $borrowLimitMap[$cat][$mod] = (int)($b['borrow_limit'] ?? 0);
    }
    // Available now per (category, model)
    $availCounts = [];
    $aggAvail = $iiCol->aggregate([
      ['$match'=>['status'=>'Available','quantity'=>['$gt'=>0]]],
      ['$project'=>[
        'category'=>['$ifNull'=>['$category','Uncategorized']],
        'model_key'=>['$ifNull'=>['$model','$item_name']],
        'q'=>['$ifNull'=>['$quantity',1]]
      ]],
      ['$group'=>['_id'=>['c'=>'$category','m'=>'$model_key'], 'avail'=>['$sum'=>'$q']]]
    ]);
    foreach ($aggAvail as $r) { $c=(string)($r->_id['c']??'Uncategorized'); $m=(string)($r->_id['m']??''); if ($m==='') continue; $availCounts[$c][$m]=(int)($r->avail??0); }
    // Consumed counts: active borrows + pending returned_queue + returned_hold
    $consumed = [];
    try {
      $ubCol = $db->selectCollection('user_borrows');
      $aggUb = $ubCol->aggregate([
        ['$match'=>['status'=>'Borrowed']],
        ['$lookup'=>['from'=>'inventory_items','localField'=>'model_id','foreignField'=>'id','as'=>'item']],
        ['$unwind'=>'$item'],
        ['$project'=>['c'=>['$ifNull'=>['$item.category','Uncategorized']], 'm'=>['$ifNull'=>['$item.model','$item.item_name']]]],
        ['$group'=>['_id'=>['c'=>'$c','m'=>'$m'],'cnt'=>['$sum'=>1]]]
      ]);
      foreach ($aggUb as $r) { $c=(string)($r->_id['c']??'Uncategorized'); $m=(string)($r->_id['m']??''); if ($m==='') continue; $consumed[$c][$m] = (int)($r->cnt??0) + (int)($consumed[$c][$m]??0); }
    } catch (Throwable $_) {}
    try {
      $rqCol = $db->selectCollection('returned_queue');
      $aggRq = $rqCol->aggregate([
        ['$match'=>['processed_at'=>['$exists'=>false]]],
        ['$lookup'=>['from'=>'inventory_items','localField'=>'model_id','foreignField'=>'id','as'=>'item']],
        ['$unwind'=>'$item'],
        ['$project'=>['c'=>['$ifNull'=>['$item.category','Uncategorized']], 'm'=>['$ifNull'=>['$item.model','$item.item_name']]]],
        ['$group'=>['_id'=>['c'=>'$c','m'=>'$m'],'cnt'=>['$sum'=>1]]]
      ]);
      foreach ($aggRq as $r) { $c=(string)($r->_id['c']??'Uncategorized'); $m=(string)($r->_id['m']??''); if ($m==='') continue; $consumed[$c][$m] = (int)($r->cnt??0) + (int)($consumed[$c][$m]??0); }
    } catch (Throwable $_) {}
    try {
      $rhCol = $db->selectCollection('returned_hold');
      $aggRh = $rhCol->aggregate([
        ['$project'=>['c'=>['$ifNull'=>['$category','Uncategorized']], 'm'=>['$ifNull'=>['$model_name','']], 'one'=>['$literal'=>1]]],
        ['$group'=>['_id'=>['c'=>'$c','m'=>'$m'],'cnt'=>['$sum'=>'$one']]]
      ]);
      foreach ($aggRh as $r) { $c=(string)($r->_id['c']??'Uncategorized'); $m=(string)($r->_id['m']??''); if ($m==='') continue; $consumed[$c][$m] = (int)($r->cnt??0) + (int)($consumed[$c][$m]??0); }
    } catch (Throwable $_) {}
    // Sum constrained availability across active borrowable models
    $borrowable_count = 0;
    foreach ($borrowLimitMap as $cat => $mods) {
      foreach ($mods as $mod => $limit) {
        $availNow = (int)($availCounts[$cat][$mod] ?? 0);
        $cons = (int)($consumed[$cat][$mod] ?? 0);
        $available = max(0, min(max(0, $limit - $cons), $availNow));
        if ($available > 0) { $borrowable_count += $available; }
      }
    }

    // Reserved items for this user: Approved reservations with start time in the future
    $erCol2 = $db->selectCollection('equipment_requests');
    $nowStr = date('Y-m-d H:i:s');
    $curRes = $erCol2->find([
        'username' => (string)$_SESSION['username'],
        'type' => 'reservation',
        'status' => 'Approved',
        'reserved_from' => ['$gt' => $nowStr],
    ], ['sort' => ['reserved_from' => 1, 'id' => -1], 'limit' => 100, 'projection'=>['item_name'=>1,'reserved_from'=>1,'reserved_to'=>1]]);
    foreach ($curRes as $rr) {
        $rf = '';
        $rt = '';
        try {
            if (isset($rr['reserved_from']) && $rr['reserved_from'] instanceof MongoDB\BSON\UTCDateTime) {
                $dt = $rr['reserved_from']->toDateTime();
                $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                $rf = $dt->format('Y-m-d H:i:s');
            } else { $rf = (string)($rr['reserved_from'] ?? ''); }
        } catch (Throwable $_) { $rf = (string)($rr['reserved_from'] ?? ''); }
        try {
            if (isset($rr['reserved_to']) && $rr['reserved_to'] instanceof MongoDB\BSON\UTCDateTime) {
                $dt2 = $rr['reserved_to']->toDateTime();
                $dt2->setTimezone(new DateTimeZone('Asia/Manila'));
                $rt = $dt2->format('Y-m-d H:i:s');
            } else { $rt = (string)($rr['reserved_to'] ?? ''); }
        } catch (Throwable $_2) { $rt = (string)($rr['reserved_to'] ?? ''); }
        $reserved_list[] = [
            'item_name' => (string)($rr['item_name'] ?? ''),
            'reserved_from' => $rf,
            'reserved_to' => $rt,
        ];
    }
    $reserved_count = count($reserved_list);

    // Current user's borrowed items count
    $current_borrowed_count = (int)$db->selectCollection('user_borrows')->countDocuments([
        'username' => (string)$_SESSION['username'],
        'status' => 'Borrowed',
    ]);

    $USED_MONGO = true;
} catch (Throwable $e) {
    $USED_MONGO = false;
}

if (!$USED_MONGO) {
    // Render with safe empty defaults when Mongo is unavailable
    $recent_requests = [];
    $borrowable_count = 0;
    $reserved_list = [];
    $reserved_count = 0;
    $current_borrowed_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>User Dashboard</title>
    <link href="css/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__.'/css/style.css'); ?>">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0d6efd" />
    <script src="pwa-register.js"></script>
    <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" />
    <style>
      @media (min-width: 769px){
        #sidebar-wrapper{ display:block !important; }
        .mobile-menu-toggle{ display:none !important; }
      }
      @media (max-width: 768px){
        .bottom-nav{ position: fixed; bottom: 0; left:0; right:0; z-index: 1050; background:#fff; border-top:1px solid #dee2e6; display:flex; justify-content:space-around; padding:8px 6px; transition: transform .2s ease-in-out; }
        .bottom-nav.hidden{ transform: translateY(100%); }
        .bottom-nav a{ text-decoration:none; font-size:12px; color:#333; display:flex; flex-direction:column; align-items:center; gap:4px; }
        .bottom-nav a .bi{ font-size:18px; }
        .bottom-nav-toggle{ position: fixed; right: 14px; bottom: 14px; z-index: 1060; border-radius: 999px; box-shadow: 0 2px 8px rgba(0,0,0,.2); transition: bottom .2s ease-in-out; }
        .bottom-nav-toggle.raised{ bottom: 78px; }
        .bottom-nav-toggle .bi{ font-size: 1.2rem; }
        /* Hide left navigation and hamburger on mobile */
        #sidebar-wrapper{ display:none !important; }
        .mobile-menu-toggle{ display:none !important; }
      }
      /* Mobile bell modal (same as request page) */
      #userBellModal{ display:none; position:fixed; inset:0; z-index:1095; align-items:center; justify-content:center; padding:16px; }
      #userBellBackdrop{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1094; }
      #userBellModal .ubm-box{ background:#fff; width:92vw; max-width:520px; max-height:80vh; border-radius:8px; overflow:hidden; box-shadow:0 10px 24px rgba(0,0,0,.25); display:flex; flex-direction:column; }
      #userBellModal .ubm-head{ padding:10px 12px; border-bottom:1px solid #e9ecef; display:flex; align-items:center; justify-content:space-between; font-weight:600; }
      #userBellModal .ubm-close{ background:transparent; border:0; font-size:20px; line-height:1; }
      #userBellModal .ubm-body{ padding:0; overflow:auto; }
    </style>
</head>
<body class="allow-mobile">
    <!-- Mobile Menu Toggle Button -->
    <button class="mobile-menu-toggle d-md-none" onclick="toggleSidebar()">
        <i class="bi bi-list"></i>
    </button>

    <div class="d-flex">
        <!-- Sidebar -->
        <div class="bg-light border-end" id="sidebar-wrapper">
            <div class="sidebar-heading py-4 fs-4 fw-bold border-bottom d-flex align-items-center justify-content-center">
                <img src="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" alt="ECA Logo" class="brand-logo me-2" />
                <span>ECA MIS-GMIS</span>
            </div>
            <div class="list-group list-group-flush my-3">
                <a href="user_dashboard.php" class="list-group-item list-group-item-action bg-transparent fw-bold">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
                <a href="user_request.php" class="list-group-item list-group-item-action bg-transparent">
                    <i class="bi bi-clipboard-plus me-2"></i>Request to Borrow
                </a>
                
                
                <a href="change_password.php" class="list-group-item list-group-item-action bg-transparent">
                    <i class="bi bi-key me-2"></i>Change Password
                </a>
                <a href="logout.php" class="list-group-item list-group-item-action bg-transparent">
                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                </a>
            </div>
        </div>

        <!-- Page Content -->
        <div class="p-4" id="page-content-wrapper">
            <div class="page-header d-flex justify-content-between align-items-center">
                <h2 class="page-title mb-0">
                    <i class="bi bi-speedometer2 me-2"></i>User Dashboard
                </h2>
                <div class="d-flex align-items-center gap-3">
                    <span class="text-muted">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</span>
                    <a href="user_request.php?open_qr=1" class="btn btn-light" id="userQrBtn" title="Scan QR">
                        <i class="bi bi-qr-code-scan" style="font-size:1.2rem;"></i>
                    </a>
                    <div class="position-relative me-2" id="userBellWrap">
                        <button class="btn btn-light position-relative" id="userBellBtn" title="Notifications">
                            <i class="bi bi-bell" style="font-size:1.2rem;"></i>
                            <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle d-none" id="userBellDot"></span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end shadow" id="userBellDropdown" style="min-width: 320px !important; max-width: 360px !important; width: auto !important; max-height: 360px; overflow:auto; z-index: 1070;">
                            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                              <span class="fw-bold small">Request Updates</span>
                              <button type="button" class="btn-close" id="userBellClose" aria-label="Close"></button>
                            </div>
                            <div id="userNotifList" class="list-group list-group-flush small"></div>
                            <div class="text-center small text-muted py-2" id="userNotifEmpty">No updates yet.</div>
                            <div class="border-top p-2 text-center">
                                <a href="user_request.php" class="btn btn-sm btn-outline-primary">Go to Requests</a>
                            </div>
                        </div>
                    </div>
                    <!-- Mobile Notifications Modal (same IDs as request page) -->
                    <div id="userBellBackdrop" aria-hidden="true"></div>
                    <div id="userBellModal" role="dialog" aria-modal="true" aria-labelledby="ubmTitle">
                      <div class="ubm-box">
                        <div class="ubm-head">
                          <div id="ubmTitle" class="small">Request Updates</div>
                          <button type="button" id="ubmCloseBtn" class="ubm-close" aria-label="Close">&times;</button>
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

            <div class="row g-3 mt-2">
                <div class="col-12 col-md-6">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-body d-flex align-items-center">
                            <div class="flex-shrink-0 me-3">
                                <span class="badge rounded-circle bg-success d-inline-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                                    <i class="bi bi-box-arrow-in-down-left fs-5"></i>
                                </span>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 text-muted">Borrowable Items</h6>
                                        <div class="h3 mb-0 fw-bold"><?php echo number_format($borrowable_count); ?></div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#borrowableModal">View</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-body d-flex align-items-center">
                            <div class="flex-shrink-0 me-3">
                                <span class="badge rounded-circle bg-info d-inline-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                                    <i class="bi bi-calendar-check fs-5"></i>
                                </span>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 text-muted">Reserved Items</h6>
                                        <div class="h3 mb-0 fw-bold"><?php echo number_format($reserved_count); ?></div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reservedItemsModal">View</button>
                                </div>
                                <?php $___rv_preview = array_slice($reserved_list ?? [], 0, 3); if (!empty($___rv_preview)): ?>
                                <div class="mt-2">
                                  <ul class="list-unstyled mb-0 small">
                                    <?php foreach ($___rv_preview as $rv): $from = isset($rv['reserved_from']) ? strtotime((string)$rv['reserved_from']) : 0; $to = isset($rv['reserved_to']) ? strtotime((string)$rv['reserved_to']) : 0; ?>
                                      <li class="d-flex justify-content-between">
                                        <span class="text-truncate me-2" style="max-width: 50%;"><?php echo htmlspecialchars((string)($rv['item_name'] ?? '')); ?></span>
                                        <span class="text-muted"><?php echo $from ? date('M j, g:i A', $from) : ''; ?><?php echo ($from && $to) ? ' - ' : ''; ?><?php echo $to ? date('M j, g:i A', $to) : ''; ?></span>
                                      </li>
                                    <?php endforeach; ?>
                                    <?php if (count($reserved_list ?? []) > count($___rv_preview)): ?>
                                      <li class="mt-1"><a href="#" class="small" data-bs-toggle="modal" data-bs-target="#reservedItemsModal">View all...</a></li>
                                    <?php endif; ?>
                                  </ul>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-body d-flex align-items-center">
                            <div class="flex-shrink-0 me-3">
                                <span class="badge rounded-circle bg-primary d-inline-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                                    <i class="bi bi-journal-arrow-up fs-5"></i>
                                </span>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 text-muted">My Currently Borrowed</h6>
                                        <div class="h3 mb-0 fw-bold"><?php echo number_format($current_borrowed_count); ?></div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#myBorrowedModal">View</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    

    <?php
    // Load currently borrowed items for this user to show in the modal
    $borrowed_list = [];
    if ($USED_MONGO) {
        try {
            $db = get_mongo_db();
            $ubCol = $db->selectCollection('user_borrows');
            $iiCol = $db->selectCollection('inventory_items');
            $erCol = $db->selectCollection('equipment_requests');
            $raCol = $db->selectCollection('request_allocations');
            $cur = $ubCol->find([
                'username' => (string)$_SESSION['username'],
                'status' => 'Borrowed',
            ], ['sort' => ['borrowed_at' => -1, 'id' => -1]]);
            foreach ($cur as $ub) {
                $mid = (int)($ub['model_id'] ?? 0);
                $itm = $mid > 0 ? $iiCol->findOne(['id'=>$mid]) : null;
                $modelName = $itm ? (string)($itm['model'] ?? ($itm['item_name'] ?? '')) : '';
                $cat = $itm ? (string)($itm['category'] ?? 'Uncategorized') : 'Uncategorized';
                $serialNo = $itm ? (string)($itm['serial_no'] ?? '') : '';

                // Link to original request via allocation (borrow_id -> request_id)
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
                } catch (Throwable $_e0) { $due = (string)($ub['expected_return_at'] ?? ''); }
                if ($reqId > 0) {
                    try {
                        $req = $erCol->findOne(['id'=>$reqId], ['projection'=>['type'=>1,'reserved_to'=>1,'expected_return_at'=>1]]);
                        if ($req) {
                            $reqType = (string)($req['type'] ?? '');
                            if (strcasecmp($reqType,'reservation')===0) {
                                $rt = (string)($req['reserved_to'] ?? '');
                                try {
                                    if (isset($req['reserved_to']) && $req['reserved_to'] instanceof MongoDB\BSON\UTCDateTime) {
                                        $dt2 = $req['reserved_to']->toDateTime();
                                        $dt2->setTimezone(new DateTimeZone('Asia/Manila'));
                                        $rt = $dt2->format('Y-m-d H:i:s');
                                    }
                                } catch (Throwable $_e1) {}
                                if ($rt !== '') { $due = $rt; }
                            } else {
                                $rt2 = (string)($req['expected_return_at'] ?? '');
                                try {
                                    if (isset($req['expected_return_at']) && $req['expected_return_at'] instanceof MongoDB\BSON\UTCDateTime) {
                                        $dt3 = $req['expected_return_at']->toDateTime();
                                        $dt3->setTimezone(new DateTimeZone('Asia/Manila'));
                                        $rt2 = $dt3->format('Y-m-d H:i:s');
                                    }
                                } catch (Throwable $_e2) {}
                                if ($rt2 !== '') { $due = $rt2; }
                            }
                        }
                    } catch (Throwable $_eReq) { /* ignore and fall back to borrow expected_return_at */ }
                }

                // Normalize borrowed_at
                $ba = '';
                try {
                    if (isset($ub['borrowed_at']) && $ub['borrowed_at'] instanceof MongoDB\BSON\UTCDateTime) {
                        $dt = $ub['borrowed_at']->toDateTime();
                        $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                        $ba = $dt->format('Y-m-d H:i:s');
                    } else { $ba = (string)($ub['borrowed_at'] ?? ''); }
                } catch (Throwable $_b) { $ba = (string)($ub['borrowed_at'] ?? ''); }

                $borrowed_list[] = [
                    'request_id' => $reqId,
                    'borrowed_at' => $ba,
                    'serial_no' => $serialNo,
                    'model_name' => $modelName,
                    'category' => ($cat !== '' ? $cat : 'Uncategorized'),
                    'expected_return_at' => $due,
                ];
            }
        } catch (Throwable $e) {
            // fallback to MySQL below if needed
        }
    }
    // No MySQL fallback
    ?>

    <!-- Borrowable Items Modal -->
    <div class="modal fade" id="borrowableModal" tabindex="-1" aria-labelledby="borrowableModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="borrowableModalLabel"><i class="bi bi-box-arrow-in-down-left me-2"></i>Borrowable Items</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-0" style="height:75vh;">
            <iframe id="borrowableFrame" src="borrowable.php?embed=1" style="border:0;width:100%;height:100%;"></iframe>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Reserved Items Modal -->
    <div class="modal fade" id="reservedItemsModal" tabindex="-1" aria-labelledby="reservedItemsModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="reservedItemsModalLabel"><i class="bi bi-calendar-check me-2"></i>Upcoming Reservations</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Item</th>
                    <th>Reserve Start</th>
                    <th>Reserve End</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($reserved_list)): ?>
                    <tr><td colspan="3" class="text-center text-muted">No upcoming reservations.</td></tr>
                  <?php else: foreach ($reserved_list as $rv): ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string)($rv['item_name'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string)($rv['reserved_from'] ? date('h:i A m-d-y', strtotime($rv['reserved_from'])) : '')); ?></td>
                      <td><?php echo htmlspecialchars((string)($rv['reserved_to'] ? date('h:i A m-d-y', strtotime($rv['reserved_to'])) : '')); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <!-- My Borrowed Modal -->
    <div class="modal fade" id="myBorrowedModal" tabindex="-1" aria-labelledby="myBorrowedModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="myBorrowedModalLabel"><i class="bi bi-collection me-2"></i>My Currently Borrowed</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Request ID</th>
                    <th>Borrowed At</th>
                    <th>Serial ID</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Expected Return</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($borrowed_list)): ?>
                  <tr><td colspan="6" class="text-center text-muted">No active borrowed items.</td></tr>
                  <?php else: ?>
                  <?php foreach ($borrowed_list as $it): ?>
                  <tr>
                    <td><?php echo htmlspecialchars((string)($it['request_id'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($it['borrowed_at'] ? date('h:i A m-d-y', strtotime($it['borrowed_at'])) : '-'); ?></td>
                    <td><?php echo htmlspecialchars((string)($it['serial_no'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($it['model_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($it['category'] ?? 'Uncategorized'); ?></td>
                    <td><?php echo !empty($it['expected_return_at']) ? htmlspecialchars(date('h:i A m-d-y', strtotime($it['expected_return_at']))) : '-'; ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Remove redundant modal/backdrop (using unified IDs above) -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function(){
            const bellBtn = document.getElementById('userBellBtn');
            const bellDot = document.getElementById('userBellDot');
            const dropdown = document.getElementById('userBellDropdown');
            const listEl = document.getElementById('userNotifList');
            const emptyEl = document.getElementById('userNotifEmpty');
            const uname = <?php echo json_encode($_SESSION['username']); ?>;
            // Mobile modal elements for scrollable notifications (match user_request IDs)
            const mBackdrop = document.getElementById('userBellBackdrop');
            const mModal = document.getElementById('userBellModal');
            const mList = document.getElementById('userNotifListM');
            const mEmpty = document.getElementById('userNotifEmptyM');
            const mClose = document.getElementById('ubmCloseBtn');
            function isMobile(){ try{ return window.matchMedia && window.matchMedia('(max-width: 768px)').matches; }catch(_){ return window.innerWidth<=768; } }
            function openMobileModal(){ if (!mModal || !mBackdrop) return; copyToMobile(); mModal.style.display='flex'; mBackdrop.style.display='block'; try{ document.body.style.overflow='hidden'; }catch(_){ } }
            function closeMobileModal(){ if (!mModal || !mBackdrop) return; mModal.style.display='none'; mBackdrop.style.display='none'; try{ document.body.style.overflow=''; }catch(_){ } }
            function copyToMobile(){
                try {
                  if (mList && listEl) mList.innerHTML = listEl.innerHTML;
                  if (mEmpty && emptyEl) mEmpty.style.display = emptyEl.style.display;
                } catch(_){ }
            }
            function setLoadingList(){
                try {
                    if (listEl){
                        const has = !!(listEl.innerHTML && listEl.innerHTML.trim() !== '');
                        if (!has) listEl.innerHTML = '<div class="text-center text-muted py-2">Loading...</div>';
                    }
                    if (emptyEl) emptyEl.style.display = 'none';
                } catch(_){ }
            }
            if (bellBtn && dropdown) {
                bellBtn.addEventListener('click', function(e){
                    e.stopPropagation();
                    setLoadingList();
                    if (isMobile()) { openMobileModal(); }
                    else {
                      dropdown.classList.toggle('show');
                      dropdown.style.display = dropdown.classList.contains('show') ? 'block' : '';
                      dropdown.style.position = 'absolute';
                      dropdown.style.top = (bellBtn.offsetTop + bellBtn.offsetHeight + 6) + 'px';
                      dropdown.style.left = (bellBtn.offsetLeft - (dropdown.offsetWidth - bellBtn.offsetWidth)) + 'px';
                    }
                    if (bellDot) { bellDot.classList.add('d-none'); }
                    // Persist last open timestamp and signature to sync with other pages
                    try {
                        const ts = (latestTs && !isNaN(latestTs)) ? latestTs : 0;
                        localStorage.setItem('ud_notif_last_open', String(ts));
                        localStorage.setItem('ud_notif_sig_open', currentSig || '');
                    } catch(_){ }
                    try { poll(true); } catch(_){ }
                });
                document.addEventListener('click', function(ev){
                    const t = ev.target;
                    if (t && t.closest && (t.closest('#userBellDropdown') || t.closest('#userBellBtn') || t.closest('#userBellWrap') || t.closest('#userBellModal'))) return;
                    dropdown.classList.remove('show'); dropdown.style.display=''; closeMobileModal();
                });
                const uBellClose = document.getElementById('userBellClose');
                if (uBellClose) { uBellClose.addEventListener('click', function(ev){ ev.stopPropagation(); dropdown.classList.remove('show'); dropdown.style.display=''; }); }
                if (mBackdrop) mBackdrop.addEventListener('click', closeMobileModal);
                if (mClose) mClose.addEventListener('click', closeMobileModal);
            }

            let toastWrap = document.getElementById('userToastWrap');
            if (!toastWrap) {
                toastWrap = document.createElement('div');
                toastWrap.id = 'userToastWrap';
                toastWrap.style.position = 'fixed'; toastWrap.style.right = '16px'; toastWrap.style.bottom = '16px'; toastWrap.style.zIndex = '1030';
                document.body.appendChild(toastWrap);
            }
            function showToast(msg){
                const el = document.createElement('div');
                el.className = 'alert alert-info shadow-sm border-0 toast-slide toast-enter';
                // Desktop default
                el.style.minWidth = '300px'; el.style.maxWidth = '340px';
                // Mobile compaction
                try { if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='180px'; el.style.maxWidth='200px'; el.style.fontSize='12px'; } } catch(_){ }
                el.innerHTML = '<i class="bi bi-bell me-2"></i>' + String(msg||'');
                toastWrap.appendChild(el);
                try { adjustToastOffsets(); } catch(_){ }
                attachSwipeForToast(el);
                setTimeout(()=>{ try { el.classList.add('toast-fade-out'); setTimeout(()=>{ try{ el.remove(); adjustToastOffsets(); }catch(_){ } }, 220); } catch(_){} }, 5000);
            }
            function adjustToastOffsets(){
                try{
                    const tw = document.getElementById('userToastWrap'); if (!tw) return;
                    const wrap = document.getElementById('userPersistentWrap');
                    try{ if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ tw.style.right='14px'; } else { tw.style.right='16px'; } }catch(_){ }
                    if (wrap){
                        const cs = window.getComputedStyle(wrap);
                        const base = parseInt(cs.bottom||'16',10)||16;
                        const h = wrap.offsetHeight||0; const gap=8;
                        tw.style.bottom = (base + h + gap) + 'px';
                    } else { tw.style.bottom = '16px'; }
                } catch(_){ }
            }
            try { window.addEventListener('resize', adjustToastOffsets); } catch(_){ }
            function attachSwipeForToast(el){
                try{
                    let sx=0, sy=0, dx=0, moving=false, removed=false;
                    const onStart=(ev)=>{ try{ const t=ev.touches?ev.touches[0]:ev; sx=t.clientX; sy=t.clientY; dx=0; moving=true; el.style.willChange='transform,opacity'; el.classList.add('toast-slide'); el.style.transition='none'; }catch(_){}};
                    const onMove=(ev)=>{ if(!moving||removed) return; try{ const t=ev.touches?ev.touches[0]:ev; dx=t.clientX - sx; const adx=Math.abs(dx); const od=1 - Math.min(1, adx/140); el.style.transform='translateX('+dx+'px)'; el.style.opacity=String(od); }catch(_){}};
                    const onEnd=()=>{ if(!moving||removed) return; moving=false; try{ el.style.transition='transform 180ms ease, opacity 180ms ease'; const adx=Math.abs(dx); if (adx>80){ removed=true; if (dx>0) el.classList.add('toast-remove-right'); else el.classList.add('toast-remove-left'); setTimeout(()=>{ try{ el.remove(); adjustToastOffsets(); }catch(_){ } }, 200); }
                        else { el.style.transform=''; el.style.opacity=''; }
                    }catch(_){ }};
                    el.addEventListener('touchstart', onStart, {passive:true});
                    el.addEventListener('touchmove', onMove, {passive:true});
                    el.addEventListener('touchend', onEnd, {passive:true});
                }catch(_){ }
            }
            let audioCtx = null; function playBeep(){ try { if (!audioCtx) audioCtx = new (window.AudioContext||window.webkitAudioContext)(); if (audioCtx.state==='suspended'){ try{ audioCtx.resume(); }catch(_e){} } const o=audioCtx.createOscillator(), g=audioCtx.createGain(); o.type='square'; o.frequency.setValueAtTime(880, audioCtx.currentTime); g.gain.setValueAtTime(0.0001, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.35, audioCtx.currentTime+0.03); g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime+0.6); o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime+0.65);}catch(_){}}

            let baseline = new Set();
            let initialized = false;
            let fetching = false;
            let latestTs = 0;
            let lastSig = '';
            let currentSig = '';
            let lastHtml = null;
            function composeRows(baseList, dn, ovCount, ovSet){
                let latest=0; let sigParts=[]; const combined=[]; const ovset=(ovSet instanceof Set)?ovSet:new Set();
                const clearedKeys = new Set((dn && Array.isArray(dn.cleared_keys)) ? dn.cleared_keys : []);
                let ephemCount = 0;
                try{ const oc=parseInt(ovCount||0,10)||0; if(oc>0){ const txt=(oc===1)?'You have an overdue item':('You have overdue items ('+oc+')'); combined.push({type:'overdue', id:0, ts:(Date.now()+1000000), html:'<a href="user_request.php?view=overdue" class="list-group-item list-group-item-action"><div class="d-flex w-100 justify-content-between"><strong class="text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>'+txt+'</strong></div></a>'}); } }catch(_){ }
                (baseList||[]).filter(function(r){ try{ return !clearedKeys.has('req:'+parseInt(r.id||0,10)); }catch(_){ return true; } }).forEach(function(r){ const id=parseInt(r.id||0,10); const st=String(r.status||''); sigParts.push(id+'|'+st); const when=r.approved_at||r.rejected_at||r.borrowed_at||r.returned_at||r.created_at; const whenTxt=when?String(when):''; let tsn=0; try{ const d=when?new Date(String(when).replace(' ','T')):null; if(d){ const t=d.getTime(); if(!isNaN(t)) tsn=t; } }catch(_){ } if(tsn>latest) latest=tsn; let disp=st, badge='bg-secondary'; const isOv=ovset.has(id); if(st==='Rejected'||st==='Cancelled'){ disp='Rejected'; badge='bg-danger'; } else if(st==='Returned'){ disp='Returned'; badge='bg-success'; } else if(st==='Approved'||st==='Borrowed'){ disp=isOv?'Overdue':'Approved'; badge=isOv?'bg-warning text-dark':'bg-success'; } const key='req:'+id; ephemCount++; const html='<div class="list-group-item d-flex justify-content-between align-items-start">'+'<div class="me-2">'+'<div class="d-flex w-100 justify-content-between">'+'<a href="user_request.php" class="fw-bold text-decoration-none">#'+id+' '+escapeHtml(r.item_name||'')+'</a>'+'<small class="text-muted">'+whenTxt+'</small>'+'</div>'+'<div class="mb-0">Status: <span class="badge '+badge+'">'+escapeHtml(disp||'')+'</span></div>'+'</div>'+'<div><button type="button" class="btn-close u-clear-one" aria-label="Clear" data-key="'+key+'"></button></div>'+'</div>'; combined.push({type:'base', id, ts:tsn, html}); });
                try{ const decisions=(dn&&Array.isArray(dn.decisions))?dn.decisions:[]; decisions.forEach(function(dc){ const rid=parseInt(dc.id||0,10)||0; if(!rid) return; const msg=escapeHtml(String(dc.message||'')); const ts=String(dc.ts||''); let tsn=0; try{ const d=ts?new Date(String(ts).replace(' ','T')):null; if(d){ const t=d.getTime(); if(!isNaN(t)) tsn=t; } }catch(_){ } if(tsn>latest) latest=tsn; const whenHtml=ts?('<small class="text-muted">'+escapeHtml(ts)+'</small>'):''; const key='decision:'+rid+'|'+escapeHtml(String(dc.status||'')); try{ if (clearedKeys.has(key)) return; }catch(_){ } ephemCount++; const html='<div class="list-group-item d-flex justify-content-between align-items-start">'+'<div class="me-2">'+'<div class="d-flex w-100 justify-content-between">'+'<strong>#'+rid+' Decision</strong>'+whenHtml+'</div>'+'<div class="mb-0">'+msg+'</div>'+'</div>'+'<div><button type="button" class="btn-close u-clear-one" aria-label="Clear" data-key="'+key+'"></button></div>'+'</div>'; combined.push({type:'extra', id:rid, ts:tsn, html}); }); }catch(_){ }
                combined.sort(function(a,b){ if((a.type==='overdue')!==(b.type==='overdue')) return (a.type==='overdue')?-1:1; if(b.ts!==a.ts) return b.ts-a.ts; return (b.id||0)-(a.id||0); });
                let rows = combined.map(x=>x.html);
                try{ const hasOverdue=(combined.length>0 && combined[0].type==='overdue'); if (ephemCount>0){ const header='<div class="list-group-item"><div class="d-flex justify-content-between align-items-center"><span class="small text-muted">Notifications</span><button type="button" class="btn btn-sm btn-outline-secondary btn-2xs" id="uClearAllBtn">Clear All</button></div></div>'; rows.splice(hasOverdue?1:0,0,header); } }catch(_){ }
                return { rows, latest, sig: sigParts.join(',') };
            }
            function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }

            function poll(force){
                if (fetching && !force) return; fetching = true;
                Promise.all([
                    fetch('user_request.php?action=my_requests_status', { cache:'no-store' }).then(r=>r.json()).catch(()=>({})),
                    fetch('user_request.php?action=user_notifications', { cache:'no-store' }).then(r=>r.json()).catch(()=>({})),
                    fetch('user_request.php?action=my_overdue', { cache:'no-store' }).then(r=>r.json()).catch(()=>({}))
                ])
                .then(([d,dn,ov])=>{
                    const list = (d && Array.isArray(d.requests)) ? d.requests : [];
                    const updates = list.filter(r=>['Approved','Rejected','Borrowed','Returned'].includes(String(r.status||'')));
                    const ovList = (ov && Array.isArray(ov.overdue)) ? ov.overdue : [];
                    const ovSet = new Set(ovList.map(o=>parseInt(o.request_id||0,10)).filter(n=>n>0));
                    const oc = ovList.length;
                    const built = composeRows(updates, dn, oc, ovSet);
                    try {
                        const lastOpen = parseInt(localStorage.getItem('ud_notif_last_open')||'0',10)||0;
                        currentSig = built.sig; lastSig = localStorage.getItem('ud_notif_sig_open') || '';
                        latestTs = built.latest;
                        const changed = !!(built.sig && built.sig !== lastSig);
                        const any = built.rows.length>0;
                        const showDot = any && (changed || (latestTs>0 && latestTs > lastOpen));
                        if (bellDot) bellDot.classList.toggle('d-none', !showDot);
                    } catch(_){ }
                    const html = built.rows.join('');
                    // Always overwrite list content so any temporary 'Loading...' placeholder is cleared
                    if (listEl){ listEl.innerHTML = html; lastHtml = html; }
                    if (emptyEl) emptyEl.style.display = (html && html.trim()!=='') ? 'none' : 'block';
                    copyToMobile();
                    // Toast logic for new requests remains unchanged
                    const ids = new Set(updates.map(r=>parseInt(r.id||0,10)));
                    if (!initialized) { baseline = ids; initialized = true; }
                    else {
                        let hasNew = false;
                        updates.forEach(r=>{ const id = parseInt(r.id||0,10); if (!baseline.has(id)) { hasNew = true; showToast('Request #'+id+' '+(r.item_name||'')+' is '+(r.status||'')); } });
                        if (hasNew) playBeep();
                        baseline = ids;
                    }
                    try { const navLink = document.querySelector('a[href="user_request.php"]'); if (navLink) { const dot = navLink.querySelector('.nav-req-dot'); if (dot) { try { dot.remove(); } catch(e) { dot.style.display = 'none'; } } } } catch(_){ }
                })
                .catch(()=>{})
                .finally(()=>{ fetching = false; });
            }
            poll(true);
            setInterval(()=>{ if (document.visibilityState==='visible') poll(false); }, 1000);

            let baseAlloc = new Set();
            let baseLogs = new Set();
            let baseReturnships = new Set();
            let initNotifs = false;
            function showToastCustom(msg, cls){
                const el = document.createElement('div');
                el.className = 'alert '+(cls||'alert-info')+' shadow-sm border-0 toast-slide toast-enter';
                el.style.minWidth = '300px'; el.style.maxWidth = '340px';
                try { if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='180px'; el.style.maxWidth='200px'; el.style.fontSize='12px'; } } catch(_){ }
                el.innerHTML = '<i class="bi bi-bell me-2"></i>'+String(msg||'');
                toastWrap.appendChild(el);
                try { adjustToastOffsets(); } catch(_){ }
                attachSwipeForToast(el);
                setTimeout(()=>{ try { el.classList.add('toast-fade-out'); setTimeout(()=>{ try{ el.remove(); adjustToastOffsets(); }catch(_){ } }, 220); } catch(_){} }, 5000);
            }
            function ensurePersistentWrap(){
                let wrap = document.getElementById('userPersistentWrap');
                if (!wrap){
                    wrap = document.createElement('div');
                    wrap.id = 'userPersistentWrap';
                    wrap.style.position = 'fixed';
                    wrap.style.right = '16px';
                    wrap.style.bottom = '16px';
                    wrap.style.left = '';
                    wrap.style.zIndex = '1030';
                    wrap.style.display = 'flex';
                    wrap.style.flexDirection = 'column';
                    wrap.style.gap = '8px';
                    wrap.style.pointerEvents = 'none';
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
            function addOrUpdateOverdueNotices(items){
                const wrap = ensurePersistentWrap();
                const list = Array.isArray(items) ? items : [];
                const count = list.length;
                // Remove any per-item overdue alerts; keep only summary
                try {
                  wrap.querySelectorAll('[id^="ov-alert-"]').forEach(function(node){
                    if (node.id !== 'ov-alert-summary') { try{ node.remove(); }catch(_){ node.style.display='none'; } }
                  });
                } catch(_){ }
                const key = 'ov-alert-summary';
                let el = document.getElementById(key);
                if (count === 0) { if (el) { try{ el.remove(); }catch(_){ el.style.display='none'; } } return; }
                const html = '<i class="bi bi-exclamation-octagon me-2"></i>' + (count===1 ? 'You have an overdue item, Click to view.' : ('You have overdue items ('+count+'), Click to view.'));
                let ding = false;
                if (!el){
                    el = document.createElement('div');
                    el.id = key;
                    el.className = 'alert alert-danger shadow-sm border-0';
                    el.style.minWidth='300px'; el.style.maxWidth='340px'; el.style.cursor='pointer';
                    el.style.margin='0'; el.style.lineHeight='1.25'; el.style.borderRadius='8px';
                    el.style.pointerEvents='auto';
                    el.addEventListener('click', function(){ window.location.href = 'user_request.php?view=overdue'; });
                    wrap.appendChild(el);
                    ding = true;
                }
                el.innerHTML = html;
                try { if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='180px'; el.style.maxWidth='200px'; el.style.padding='4px 6px'; el.style.fontSize='10px'; const ic=el.querySelector('i'); if (ic) ic.style.fontSize='12px'; } else { el.style.minWidth='300px'; el.style.maxWidth='340px'; el.style.padding=''; el.style.fontSize=''; } } catch(_){ }
                if (ding) { try{ playBeep(); }catch(_){ } }
                try { adjustToastOffsets(); } catch(_){ }
            }
            function notifPoll(){
                fetch('user_request.php?action=user_notifications')
                  .then(r=>r.json())
                  .then(d=>{
                    const approvals = Array.isArray(d.approvals)?d.approvals:[];
                    const logs = Array.isArray(d.lostDamaged)?d.lostDamaged:[];
                    const returnships = Array.isArray(d.returnships)?d.returnships:[];
                    const idsA = new Set(approvals.map(a=>parseInt(a.alloc_id||0,10)).filter(n=>n>0));
                    const idsL = new Set(logs.map(l=>parseInt(l.log_id||0,10)).filter(n=>n>0));
                    const idsR = new Set(returnships.map(r=>parseInt(r.id||0,10)).filter(n=>n>0));
                    if (!initNotifs) { baseAlloc = idsA; baseLogs = idsL; baseReturnships = idsR; initNotifs = true; return; }
                    let ding = false;
                    approvals.forEach(a=>{
                        const id = parseInt(a.alloc_id||0,10);
                        if (!baseAlloc.has(id)) {
                          ding = true;
                          showToastCustom('The ID.'+String(a.model_id||'')+' ('+String(a.model_name||'')+') has been approved', 'alert-success');
                        }
                    });
                    logs.forEach(l=>{
                        const id = parseInt(l.log_id||0,10);
                        if (!baseLogs.has(id)) { ding = true; const act = String(l.action||''); const label = (act==='Under Maintenance')?'damaged':'lost'; showToastCustom('The '+String(l.model_id||'')+' '+String(l.model_name||'')+' was marked as '+label, 'alert-danger'); }
                    });
                    // Overdue stacked notices (non-removable until resolved)
                    fetch('user_request.php?action=my_overdue', { cache:'no-store' })
                      .then(r=>r.json())
                      .then(o=>{ const list = (o && Array.isArray(o.overdue)) ? o.overdue : []; try{ sessionStorage.setItem('overdue_prefetch', JSON.stringify({ overdue: list })); }catch(_){ } addOrUpdateOverdueNotices(list); })
                      .catch(()=>{});
                    if (ding) playBeep();
                    baseAlloc = idsA; baseLogs = idsL; baseReturnships = idsR;
                  })
                  .catch(()=>{});
            }
            notifPoll();
            setInterval(()=>{ if (document.visibilityState==='visible') notifPoll(); }, 1000);
        })();
        document.addEventListener('click', function(ev){ const x = ev.target && ev.target.closest && ev.target.closest('.u-clear-one'); if (x){ ev.preventDefault(); const key = x.getAttribute('data-key')||''; if(!key) return; const fd=new FormData(); fd.append('key', key); fetch('user_request.php?action=user_notif_clear',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ try{ if (typeof poll === 'function') poll(true); }catch(_){ } }).catch(()=>{}); return; } if (ev.target && ev.target.id === 'uClearAllBtn'){ ev.preventDefault(); const fd=new FormData(); fd.append('limit','300'); fetch('user_request.php?action=user_notif_clear_all',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ try{ if (typeof poll === 'function') poll(true); }catch(_){ } }).catch(()=>{}); } });

        // Request to Borrow red dot (based on My Borrowed) for dashboard page
        (function(){
            // Clean any lingering dots on load
            try {
                const reqLinkInit = document.querySelector('#sidebar-wrapper a[href="user_request.php"]');
                if (reqLinkInit) {
                    reqLinkInit.querySelectorAll('.nav-borrowed-dot, .nav-req-dot').forEach(el=>{ try{ el.remove(); }catch(_){ el.style.display='none'; } });
                }
            } catch(_){ }
            function ensureDot(link){
                if (!link) return null;
                let dot = link.querySelector('.nav-borrowed-dot');
                if (!dot) {
                    dot = document.createElement('span');
                    dot.className = 'nav-borrowed-dot ms-2 d-inline-block rounded-circle';
                    dot.style.width = '8px';
                    dot.style.height = '8px';
                    dot.style.backgroundColor = '#dc3545';
                    dot.style.verticalAlign = 'middle';
                    dot.style.display = 'none';
                    link.appendChild(dot);
                }
                return dot;
            }
            let fetchingBorrow = false;
            function pollBorrow(){
                if (fetchingBorrow) return; fetchingBorrow = true;
                fetch('user_request.php?action=my_borrowed')
                  .then(r=>r.json())
                  .then(d=>{
                      const list = (d && Array.isArray(d.borrowed)) ? d.borrowed : [];
                      const any = list.length > 0;
                      const link = document.querySelector('#sidebar-wrapper a[href="user_request.php"]');
                      const dot = ensureDot(link);
                      if (dot) { dot.style.display = any ? 'inline-block' : 'none'; }
                  })
                  .catch(()=>{})
                  .finally(()=>{ fetchingBorrow = false; });
            }
            pollBorrow();
            setInterval(()=>{ if (document.visibilityState==='visible') pollBorrow(); }, 1000);
        })();

        
    </script>
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
    <button type="button" class="btn btn-primary bottom-nav-toggle d-md-none" id="bnToggleUD" aria-controls="udBottomNav" aria-expanded="false" title="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <nav class="bottom-nav d-md-none hidden" id="udBottomNav">
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
      <a href="logout.php" aria-label="Logout">
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
      </a>
    </nav>
    <script>
      (function(){
        var btn = document.getElementById('bnToggleUD');
        var nav = document.getElementById('udBottomNav');
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
              try { adjustToastOffsets(); } catch(_){ }
            } else {
              btn.classList.remove('raised');
              btn.title = 'Open menu';
              var i2 = btn.querySelector('i'); if (i2) { i2.className = 'bi bi-list'; }
              setPersistentWrapOffset(false);
              try { adjustToastOffsets(); } catch(_){ }
            }
          });
          // Initialize position based on current state
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
    <script src="page-transitions.js?v=<?php echo filemtime(__DIR__.'/page-transitions.js'); ?>"></script>
</body>
</html>
