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
        $reserved_list[] = [
            'item_name' => (string)($rr['item_name'] ?? ''),
            'reserved_from' => (string)($rr['reserved_from'] ?? ''),
            'reserved_to' => (string)($rr['reserved_to'] ?? ''),
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
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0d6efd" />
    <script src="pwa-register.js"></script>
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
      /* Mobile bell modal (dashboard) */
      #udBellModal{ display:none; position:fixed; inset:0; z-index:1095; align-items:center; justify-content:center; padding:16px; }
      #udBellBackdrop{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1094; }
      #udBellModal .ubm-box{ background:#fff; width:92vw; max-width:520px; max-height:80vh; border-radius:8px; overflow:hidden; box-shadow:0 10px 24px rgba(0,0,0,.25); display:flex; flex-direction:column; }
      #udBellModal .ubm-head{ padding:10px 12px; border-bottom:1px solid #e9ecef; display:flex; align-items:center; justify-content:space-between; font-weight:600; }
      #udBellModal .ubm-close{ background:transparent; border:0; font-size:20px; line-height:1; }
      #udBellModal .ubm-body{ padding:0; overflow:auto; }
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
                <img src="images/logo-removebg.png" alt="ECA Logo" class="brand-logo me-2" />
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
                <a href="logout.php" class="list-group-item list-group-item-action bg-transparent" onclick="return confirm('Are you sure you want to logout?');">
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
                        <div class="dropdown-menu dropdown-menu-end shadow" id="userBellDropdown" style="min-width: 320px; max-height: 360px; overflow:auto;">
                            <div class="px-3 py-2 border-bottom fw-bold small">Request Updates</div>
                            <div id="userNotifList" class="list-group list-group-flush small"></div>
                            <div class="text-center small text-muted py-2" id="userNotifEmpty">No updates yet.</div>
                            <div class="border-top p-2 text-center">
                                <a href="user_request.php" class="btn btn-sm btn-outline-primary">Go to Requests</a>
                            </div>
                        </div>
                    </div>
                    <!-- Mobile Notifications Modal (dashboard) -->
                    <div id="udBellBackdrop" aria-hidden="true"></div>
                    <div id="udBellModal" role="dialog" aria-modal="true" aria-labelledby="udbmTitle">
                      <div class="ubm-box">
                        <div class="ubm-head">
                          <div id="udbmTitle" class="small">Request Updates</div>
                          <button type="button" id="udbmCloseBtn" class="ubm-close" aria-label="Close">&times;</button>
                        </div>
                        <div class="ubm-body">
                          <div id="udNotifListM" class="list-group list-group-flush small"></div>
                          <div class="text-center small text-muted py-2" id="udNotifEmptyM">No updates yet.</div>
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
            $cur = $ubCol->find([
                'username' => (string)$_SESSION['username'],
                'status' => 'Borrowed',
            ], ['sort' => ['borrowed_at' => -1, 'id' => -1]]);
            foreach ($cur as $ub) {
                $mid = (int)($ub['model_id'] ?? 0);
                $itm = $mid > 0 ? $iiCol->findOne(['id'=>$mid]) : null;
                $modelName = $itm ? (string)($itm['model'] ?? ($itm['item_name'] ?? '')) : '';
                $cat = $itm ? (string)($itm['category'] ?? 'Uncategorized') : 'Uncategorized';
                $cond = $itm ? (string)($itm['condition'] ?? '') : '';
                // Best-effort request id: most recent request by this user matching item name
                $req = $erCol->findOne([
                    'username' => (string)$_SESSION['username'],
                    'item_name' => ['$in' => array_values(array_unique(array_filter([$modelName, (string)($itm['item_name'] ?? '')])))],
                ], ['sort' => ['created_at' => -1, 'id' => -1], 'projection' => ['id'=>1]]);
                $borrowed_list[] = [
                    'request_id' => (int)($req['id'] ?? 0),
                    'borrowed_at' => (string)($ub['borrowed_at'] ?? ''),
                    'model_id' => $mid,
                    'model_name' => $modelName,
                    'category' => ($cat !== '' ? $cat : 'Uncategorized'),
                    'condition' => $cond,
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
                    <th>Model ID</th>
                    <th>Model</th>
                    <th>Category</th>
                    <th>Condition</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($borrowed_list)): ?>
                  <tr><td colspan="6" class="text-center text-muted">No active borrowed items.</td></tr>
                  <?php else: ?>
                  <?php foreach ($borrowed_list as $it): ?>
                  <tr>
                    <td><?php echo htmlspecialchars((string)($it['request_id'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars(date('h:i A m-d-y', strtotime($it['borrowed_at']))); ?></td>
                    <td><?php echo htmlspecialchars((string)($it['model_id'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($it['model_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($it['category'] ?? 'Uncategorized'); ?></td>
                    <td><?php echo htmlspecialchars($it['condition'] ?? ''); ?></td>
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

    <!-- Notification Bell Modal -->
    <div class="modal fade" id="udBellModal" tabindex="-1" aria-labelledby="udBellModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="udBellModalLabel"><i class="bi bi-bell me-2"></i>Notifications</h5>
            <button type="button" class="btn-close" id="udbmCloseBtn" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-0" style="overflow:auto;">
            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Request ID</th>
                    <th>Borrowed At</th>
                    <th>Model ID</th>
                    <th>Model</th>
                    <th>Category</th>
                    <th>Condition</th>
                  </tr>
                </thead>
                <tbody id="udNotifListM">
                </tbody>
              </table>
              <div id="udNotifEmptyM" class="text-center text-muted">No notifications.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div id="udBellBackdrop" class="modal-backdrop fade"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function(){
            const bellBtn = document.getElementById('userBellBtn');
            const bellDot = document.getElementById('userBellDot');
            const dropdown = document.getElementById('userBellDropdown');
            const listEl = document.getElementById('userNotifList');
            const emptyEl = document.getElementById('userNotifEmpty');
            const uname = <?php echo json_encode($_SESSION['username']); ?>;
            // Mobile modal elements for scrollable notifications
            const mBackdrop = document.getElementById('udBellBackdrop');
            const mModal = document.getElementById('udBellModal');
            const mList = document.getElementById('udNotifListM');
            const mEmpty = document.getElementById('udNotifEmptyM');
            const mClose = document.getElementById('udbmCloseBtn');
            function isMobile(){ try{ return window.matchMedia && window.matchMedia('(max-width: 768px)').matches; }catch(_){ return window.innerWidth<=768; } }
            function openMobileModal(){ if (!mModal || !mBackdrop) return; copyToMobile(); mModal.style.display='flex'; mBackdrop.style.display='block'; try{ document.body.style.overflow='hidden'; }catch(_){ } }
            function closeMobileModal(){ if (!mModal || !mBackdrop) return; mModal.style.display='none'; mBackdrop.style.display='none'; try{ document.body.style.overflow=''; }catch(_){ } }
            function copyToMobile(){ try{ if (mList && listEl) mList.innerHTML = listEl.innerHTML; if (mEmpty && emptyEl) mEmpty.style.display = emptyEl.style.display; } catch(_){ }

            if (bellBtn && dropdown) {
                bellBtn.addEventListener('click', function(e){
                    e.stopPropagation();
                    if (isMobile()) { openMobileModal(); }
                    else {
                      dropdown.classList.toggle('show');
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
                });
                document.addEventListener('click', function(){ dropdown.classList.remove('show'); closeMobileModal(); });
                if (mBackdrop) mBackdrop.addEventListener('click', closeMobileModal);
                if (mClose) mClose.addEventListener('click', closeMobileModal);
            }

            let toastWrap = document.getElementById('userToastWrap');
            if (!toastWrap) {
                toastWrap = document.createElement('div');
                toastWrap.id = 'userToastWrap';
                toastWrap.style.position = 'fixed'; toastWrap.style.right = '16px'; toastWrap.style.bottom = '16px'; toastWrap.style.zIndex = '1080';
                document.body.appendChild(toastWrap);
            }
            function showToast(msg){
                const el = document.createElement('div');
                el.className = 'alert alert-info shadow-sm border-0';
                el.style.minWidth = '280px'; el.style.maxWidth = '360px';
                el.innerHTML = '<i class="bi bi-bell me-2"></i>' + String(msg||'');
                toastWrap.appendChild(el);
                setTimeout(()=>{ try { el.remove(); } catch(_){} }, 5000);
            }
            let audioCtx = null; function playBeep(){ try { if (!audioCtx) audioCtx = new (window.AudioContext||window.webkitAudioContext)(); const o=audioCtx.createOscillator(), g=audioCtx.createGain(); o.type='sine'; o.frequency.value=880; g.gain.setValueAtTime(0.0001, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.2, audioCtx.currentTime+0.02); g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime+0.22); o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime+0.25);}catch(_){}}

            let baseline = new Set();
            let initialized = false;
            let fetching = false;
            let latestTs = 0;
            let lastSig = '';
            let currentSig = '';
            function renderList(items){
                const rows = [];
                const sigParts = [];
                (items||[]).forEach(function(r){
                    const id = parseInt(r.id||0,10);
                    const st = String(r.status||'');
                    sigParts.push(id+'|'+st);
                    if (st==='Approved' || st==='Rejected' || st==='Borrowed' || st==='Returned'){
                        const when = r.approved_at || r.rejected_at || r.borrowed_at || r.returned_at || r.created_at;
                        const whenDate = when ? new Date(when) : null;
                        const whenTxt = whenDate ? whenDate.toLocaleString() : '';
                        if (whenDate) { const t = whenDate.getTime(); if (!isNaN(t) && t > latestTs) latestTs = t; }
                        rows.push('<a href="user_request.php" class="list-group-item list-group-item-action">'
                          + '<div class="d-flex w-100 justify-content-between">'
                          +   '<strong>#'+id+' '+escapeHtml(r.item_name||'')+'</strong>'
                          +   '<small class="text-muted">'+whenTxt+'</small>'
                          + '</div>'
                          + '<div class="mb-0">Status: <span class="badge '+(st==='Approved' || st==='Borrowed' ? 'bg-success':'bg-danger')+'">'+escapeHtml(st)+'</span></div>'
                          + '</a>');
                    }
                });
                // Fetch admin-initiated returnship requests and add to the top with action
                fetch('user_request.php?action=user_notifications', { cache:'no-store' })
                  .then(r=>r.json())
                  .then(d=>{
                      const returnships = Array.isArray(d.returnships) ? d.returnships : [];
                      returnships.forEach(function(rs){
                          const rid = parseInt(rs.request_id||0,10)||0;
                          if (!rid) return;
                          const name = (rs.model_name||'').toString();
                          const status = (rs.status||'').toString();
                          const url = 'user_request.php?open_return_qr='+encodeURIComponent(rid)+'&model_name='+encodeURIComponent(name);
                          const action = '<a href="'+url+'" class="btn btn-sm btn-outline-primary"><i class="bi bi-qr-code-scan"></i> Return via QR</a>';
                          rows.unshift('<div class="list-group-item">'
                            + '<div class="d-flex w-100 justify-content-between"><strong>Return Requested: #'+rid+' '+escapeHtml(name)+'</strong>'
                            + '<span class="badge bg-danger">'+escapeHtml(status)+'</span></div>'
                            + '<div class="mt-1 text-end">'+action+'</div>'
                            + '</div>');
                      });
                  })
                  .catch(()=>{})
                  .finally(()=>{
                      listEl.innerHTML = rows.join('');
                      const any = rows.length>0;
                      emptyEl.style.display = any ? 'none' : 'block';
                      // Keep mobile modal content in sync for scrolling
                      copyToMobile();
                  });
                // Cross-page sync: show dot only if there are updates newer than last open
                try {
                    const lastOpen = parseInt(localStorage.getItem('ud_notif_last_open')||'0',10)||0;
                    sigParts.sort();
                    const sig = sigParts.join(',');
                    currentSig = sig;
                    lastSig = localStorage.getItem('ud_notif_sig_open') || '';
                    const changed = sig && sig !== lastSig;
                    const showDot = any && (changed || latestTs > lastOpen);
                    if (bellDot) bellDot.classList.toggle('d-none', !showDot);
                } catch(_){ if (bellDot) bellDot.classList.toggle('d-none', !any); }
            }
            function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }

            function poll(){
                if (fetching) return; fetching = true;
                fetch('user_request.php?action=my_requests_status')
                  .then(r=>r.json())
                  .then(d=>{
                    const list = (d && Array.isArray(d.requests)) ? d.requests : [];
                    const updates = list.filter(r=>['Approved','Rejected','Borrowed','Returned'].includes(String(r.status||'')));
                    renderList(updates);
                    const ids = new Set(updates.map(r=>parseInt(r.id||0,10)));
                    if (!initialized) { baseline = ids; initialized = true; }
                    else {
                        let hasNew = false;
                        updates.forEach(r=>{ const id = parseInt(r.id||0,10); if (!baseline.has(id)) { hasNew = true; showToast('Request #'+id+' '+(r.item_name||'')+' is '+(r.status||'')); } });
                        if (hasNew) playBeep();
                        baseline = ids;
                    }
                    try {
                        const navLink = document.querySelector('a[href="user_request.php"]');
                        if (navLink) {
                            const dot = navLink.querySelector('.nav-req-dot');
                            if (dot) { try { dot.remove(); } catch(e) { dot.style.display = 'none'; } }
                        }
                    } catch(_){ }
                  })
                  .catch(()=>{})
                  .finally(()=>{ fetching = false; });
            }
            poll();
            setInterval(()=>{ if (document.visibilityState==='visible') poll(); }, 2000);

            let baseAlloc = new Set();
            let baseLogs = new Set();
            let baseReturnships = new Set();
            let initNotifs = false;
            function showToastCustom(msg, cls){
                const el = document.createElement('div');
                el.className = 'alert '+(cls||'alert-info')+' shadow-sm border-0';
                el.style.minWidth = '280px'; el.style.maxWidth = '360px';
                el.innerHTML = '<i class="bi bi-bell me-2"></i>'+String(msg||'');
                toastWrap.appendChild(el);
                setTimeout(()=>{ try { el.remove(); } catch(_){} }, 5000);
            }
            function ensurePersistentWrap(){
                let wrap = document.getElementById('userPersistentWrap');
                if (!wrap){
                    wrap = document.createElement('div');
                    wrap.id = 'userPersistentWrap';
                    wrap.style.position = 'fixed';
                    wrap.style.right = '16px';
                    wrap.style.bottom = '16px';
                    wrap.style.zIndex = '1090';
                    wrap.style.display = 'flex';
                    wrap.style.flexDirection = 'column';
                    wrap.style.gap = '8px';
                    document.body.appendChild(wrap);
                }
                return wrap;
            }
            function addOrUpdateReturnshipNotice(rs){
                const wrap = ensurePersistentWrap();
                const id = parseInt(rs.id||0,10); if (!id) return;
                const elId = 'rs-alert-'+id;
                let el = document.getElementById(elId);
                const name = String(rs.model_name||'');
                const sn = String(rs.qr_serial_no||'');
                const html = '<i class="bi bi-exclamation-octagon me-2"></i>'+
                  'Admin requested you to return '+(name?name+' ':'')+(sn?('['+sn+']'):'')+'. Click to open.';
                if (!el){
                    el = document.createElement('div'); el.id = elId;
                    el.className = 'alert alert-danger shadow-sm border-0';
                    el.style.minWidth='300px'; el.style.maxWidth='380px'; el.style.cursor='pointer';
                    el.innerHTML = html;
                    el.addEventListener('click', function(){ window.location.href = 'user_request.php'; });
                    wrap.appendChild(el);
                    try { playBeep(); } catch(_){ }
                } else {
                    el.innerHTML = html;
                }
            }
            function removeReturnshipNotice(id){
                const el = document.getElementById('rs-alert-'+id);
                if (el) { try { el.remove(); } catch(_){ el.style.display='none'; } }
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
                    // Persistent red notices only for Pending (not yet verified)
                    const pendingSet = new Set();
                    returnships.forEach(rs=>{
                        const id = parseInt(rs.id||0,10); if (!id) return;
                        const status = String(rs.status||'');
                        if (status === 'Pending') { pendingSet.add(id); addOrUpdateReturnshipNotice(rs); }
                    });
                    // Remove any notices that are no longer pending
                    if (typeof baseReturnships !== 'undefined'){
                        baseReturnships.forEach(oldId=>{ if (!pendingSet.has(oldId)) removeReturnshipNotice(oldId); });
                    }
                    if (ding) playBeep();
                    baseAlloc = idsA; baseLogs = idsL; baseReturnships = idsR;
                  })
                  .catch(()=>{});
            }
            notifPoll();
            setInterval(()=>{ if (document.visibilityState==='visible') notifPoll(); }, 2000);
        })();

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
            setInterval(()=>{ if (document.visibilityState==='visible') pollBorrow(); }, 3000);
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
      <a href="logout.php" aria-label="Logout" onclick="return confirm('Logout now?');">
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
      </a>
    </nav>
    <script>
      (function(){
        var btn = document.getElementById('bnToggleUD');
        var nav = document.getElementById('udBottomNav');
        if (btn && nav) {
          btn.addEventListener('click', function(){
            var hid = nav.classList.toggle('hidden');
            btn.setAttribute('aria-expanded', String(!hid));
            if (!hid) {
              btn.classList.add('raised');
              btn.title = 'Close menu';
              var i = btn.querySelector('i'); if (i) { i.className = 'bi bi-x'; }
            } else {
              btn.classList.remove('raised');
              btn.title = 'Open menu';
              var i2 = btn.querySelector('i'); if (i2) { i2.className = 'bi bi-list'; }
            }
          });
        }
      })();
    </script>
</body>
</html>
