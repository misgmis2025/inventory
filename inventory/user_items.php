<?php
session_start();
if (!isset($_SESSION['username']) || ($_SESSION['usertype'] ?? 'user') !== 'user') {
    if (!isset($_SESSION['username'])) { header('Location: index.php'); } else { header('Location: admin_dashboard.php'); }
    exit();
}
require_once __DIR__ . '/auth.php';
inventory_redirect_if_disabled();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db/mongo.php';
try { $db = get_mongo_db(); } catch (Throwable $e) { http_response_code(500); echo 'Database unavailable'; exit(); }

// Ensure user_borrows table exists
// MongoDB collections
$ubCol = $db->selectCollection('user_borrows');
$iiCol = $db->selectCollection('inventory_items');
$erCol = $db->selectCollection('equipment_requests');
$ldCol = $db->selectCollection('lost_damaged_log');
$delLogCol = $db->selectCollection('inventory_delete_log');

// Ensure user_reports table exists
// (user_reports table intentionally not managed here; user reporting disabled by design)

$message = '';
$error = '';

// Handle actions: mark borrowed from a recent scan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'borrow') {
        $model_id = intval($_POST['model_id'] ?? 0);
        if ($model_id > 0) {
            // Validate item exists
            $exists = $iiCol->findOne(['id' => $model_id]);
            if ($exists) {
                // Avoid duplicate active borrow
                $dup = $ubCol->findOne(['username' => (string)$_SESSION['username'], 'model_id' => $model_id, 'status' => 'Borrowed']);
                if ($dup) {
                    $message = 'Already marked as borrowed.';
                } else {
                    $last = $ubCol->findOne([], ['sort' => ['id' => -1], 'projection' => ['id' => 1]]);
                    $nextId = ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
                    // Compute borrowed_at from the user's latest matching request's created_at
                    $ba = date('Y-m-d H:i:s');
                    try {
                        $modelName = (string)($exists['model'] ?? ($exists['item_name'] ?? ''));
                        $cand = array_values(array_unique(array_filter([$modelName, (string)($exists['item_name'] ?? '')])));
                        if (!empty($cand)) {
                            $req = $erCol->findOne([
                                'username' => (string)$_SESSION['username'],
                                'item_name' => ['$in' => $cand]
                            ], ['sort' => ['created_at' => -1, 'id' => -1], 'projection' => ['created_at' => 1]]);
                            if ($req && isset($req['created_at'])) {
                                if ($req['created_at'] instanceof MongoDB\BSON\UTCDateTime) {
                                    $dt = $req['created_at']->toDateTime();
                                    $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                                    $ba = $dt->format('Y-m-d H:i:s');
                                } else {
                                    $ba = (string)$req['created_at'];
                                }
                            }
                        }
                    } catch (Throwable $_c) { /* keep $ba as now */ }

                    $snapItemName = (string)($exists['item_name'] ?? '');
                    $snapModel = (string)($exists['model'] ?? '');
                    $snapCategory = trim((string)($exists['category'] ?? '')) !== '' ? (string)$exists['category'] : 'Uncategorized';
                    $snapSerial = (string)($exists['serial_no'] ?? '');

                    // Snapshot user identity for history/print stability
                    $snapUserId = '';
                    $snapSchoolId = '';
                    $snapFullName = (string)$_SESSION['username'];
                    try {
                        $uCol = $db->selectCollection('users');
                        $uSnap = $uCol->findOne(['username'=>(string)$_SESSION['username']], ['projection'=>['id'=>1,'school_id'=>1,'full_name'=>1]]);
                        if ($uSnap) {
                            $snapUserId = (string)($uSnap['id'] ?? '');
                            $snapSchoolId = (string)($uSnap['school_id'] ?? '');
                            $snapFullName = (string)($uSnap['full_name'] ?? $snapFullName);
                        }
                    } catch (Throwable $_us) {}

                    $ubCol->insertOne([
                        'id' => $nextId,
                        'username' => (string)$_SESSION['username'],
                        'model_id' => $model_id,
                        'borrowed_at' => $ba,
                        'returned_at' => null,
                        'status' => 'Borrowed',
                        // Snapshot fields for stable history (no backing request)
                        'request_id' => null,
                        'item_name' => $snapItemName,
                        'model' => $snapModel,
                        'category' => $snapCategory,
                        'serial_no' => $snapSerial,
                        'user_id' => $snapUserId,
                        'school_id' => $snapSchoolId,
                        'full_name' => $snapFullName,
                    ]);
                    $message = 'Item marked as borrowed.';
                }
            } else {
                $error = 'Invalid item.';
            }
        } else {
            $error = 'Missing item information.';
        }
    }
}

// Recent scans by this user (last 10)
$recent_scans = [];
try {
    $scCol = $db->selectCollection('inventory_scans');
    $cur = $scCol->find(['scanned_by' => (string)$_SESSION['username']], ['sort' => ['scanned_at' => -1, 'id' => -1], 'limit' => 10]);
    foreach ($cur as $r) {
        $recent_scans[] = [
            'id' => (int)($r['id'] ?? 0),
            'model_id' => (int)($r['model_id'] ?? 0),
            'item_name' => (string)($r['item_name'] ?? ''),
            'status' => (string)($r['status'] ?? ''),
            'form_type' => (string)($r['form_type'] ?? ''),
            'room' => (string)($r['room'] ?? ''),
            'generated_date' => (string)($r['generated_date'] ?? ''),
            'scanned_at' => (string)($r['scanned_at'] ?? ''),
        ];
    }
} catch (Throwable $_) { }

// My borrowed items (active)
$my_borrowed = [];
try {
    $cur = $ubCol->find(['username' => (string)$_SESSION['username'], 'status' => 'Borrowed'], ['sort' => ['borrowed_at' => -1, 'id' => -1]]);
    foreach ($cur as $ub) {
        $mid = (int)($ub['model_id'] ?? 0);
        $itm = $mid > 0 ? $iiCol->findOne(['id' => $mid]) : null;
        $modelName = $itm ? (string)($itm['model'] ?? ($itm['item_name'] ?? '')) : '';
        $cat = $itm ? (string)($itm['category'] ?? 'Uncategorized') : 'Uncategorized';
        $cond = $itm ? (string)($itm['condition'] ?? '') : '';
        $req = $erCol->findOne([
            'username' => (string)$_SESSION['username'],
            'item_name' => ['$in' => array_values(array_unique(array_filter([$modelName, (string)($itm['item_name'] ?? '')])))],
        ], ['sort' => ['created_at' => -1, 'id' => -1], 'projection' => ['id' => 1]]);
        $ba = '';
        try {
            if (isset($ub['borrowed_at']) && $ub['borrowed_at'] instanceof MongoDB\BSON\UTCDateTime) {
                $dt = $ub['borrowed_at']->toDateTime();
                $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                $ba = $dt->format('Y-m-d H:i:s');
            } else {
                $ba = (string)($ub['borrowed_at'] ?? '');
            }
        } catch (Throwable $_t) { $ba = (string)($ub['borrowed_at'] ?? ''); }
        $my_borrowed[] = [
            'request_id' => (int)($req['id'] ?? 0),
            'borrowed_at' => $ba,
            'model_id' => $mid,
            'model' => $modelName,
            'item_name' => $itm ? (string)($itm['item_name'] ?? '') : '',
            'category' => ($cat !== '' ? $cat : 'Uncategorized'),
            'condition' => $cond,
            '__status' => 'Borrowed',
            '__ts' => $ba,
            'row_id' => (int)($req['id'] ?? ($ub['id'] ?? 0)),
        ];
    }
} catch (Throwable $_) { }

// Do not include requests; only show actual borrowed items
$my_borrowed_combined = $my_borrowed;

// My borrow history (this user only)
$my_history = [];
try {
    $cur = $ubCol->find(['username' => (string)$_SESSION['username']], ['sort' => ['borrowed_at' => -1, 'id' => -1], 'limit' => 200]);
    foreach ($cur as $ub) {
        $mid = (int)($ub['model_id'] ?? 0);
        $itm = $mid > 0 ? $iiCol->findOne(['id' => $mid]) : null;
        $modelName = $itm ? (string)($itm['model'] ?? ($itm['item_name'] ?? '')) : '';
        $cat = $itm ? (string)($itm['category'] ?? 'Uncategorized') : 'Uncategorized';
        // Prefer request_id stored on the borrow document; do not guess if missing
        $reqId = isset($ub['request_id']) ? (int)$ub['request_id'] : 0;
        $borrowId = (int)($ub['id'] ?? 0);
        // last status markers
        $last_lost = $ldCol->findOne(['model_id' => $mid, 'action' => 'Lost'], ['sort' => ['id' => -1], 'projection' => ['created_at' => 1]]);
        $last_found = $ldCol->findOne(['model_id' => $mid, 'action' => 'Found'], ['sort' => ['id' => -1], 'projection' => ['created_at' => 1]]);
        $last_maint = $ldCol->findOne(['model_id' => $mid, 'action' => 'Under Maintenance'], ['sort' => ['id' => -1], 'projection' => ['created_at' => 1]]);
        $last_fixed = $ldCol->findOne(['model_id' => $mid, 'action' => 'Fixed'], ['sort' => ['id' => -1], 'projection' => ['created_at' => 1]]);
        $ba = '';
        $ra = '';
        try {
            if (isset($ub['borrowed_at']) && $ub['borrowed_at'] instanceof MongoDB\BSON\UTCDateTime) {
                $dt = $ub['borrowed_at']->toDateTime();
                $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                $ba = $dt->format('Y-m-d H:i:s');
            } else { $ba = (string)($ub['borrowed_at'] ?? ''); }
        } catch (Throwable $_t1) { $ba = (string)($ub['borrowed_at'] ?? ''); }
        try {
            if (isset($ub['returned_at']) && $ub['returned_at'] instanceof MongoDB\BSON\UTCDateTime) {
                $dt2 = $ub['returned_at']->toDateTime();
                $dt2->setTimezone(new DateTimeZone('Asia/Manila'));
                $ra = $dt2->format('Y-m-d H:i:s');
            } else { $ra = (string)($ub['returned_at'] ?? ''); }
        } catch (Throwable $_t2) { $ra = (string)($ub['returned_at'] ?? ''); }
        // Snapshot fields off user_borrows first, then fall back to live inventory item
        $snapItemName = isset($ub['item_name']) ? (string)$ub['item_name'] : '';
        $snapModel = isset($ub['model']) ? (string)$ub['model'] : '';
        $snapCategory = isset($ub['category']) ? (string)$ub['category'] : '';
        if ($itm) {
            if ($snapItemName === '') { $snapItemName = (string)($itm['item_name'] ?? ''); }
            if ($snapModel === '') { $snapModel = (string)($itm['model'] ?? ''); }
            if ($snapCategory === '') { $snapCategory = (string)($itm['category'] ?? 'Uncategorized'); }
        }
        // If item has been deleted, recover snapshot from inventory_delete_log
        if (!$itm && $mid > 0) {
            try { $del = $delLogCol->findOne(['item_id' => $mid], ['sort' => ['id' => -1], 'projection' => ['item_name'=>1,'model'=>1,'category'=>1]]); } catch (Throwable $_del) { $del = null; }
            if ($del) {
                if ($snapItemName === '') { $snapItemName = (string)($del['item_name'] ?? ''); }
                if ($snapModel === '') { $snapModel = (string)($del['model'] ?? ''); }
                if ($snapCategory === '') { $snapCategory = (string)($del['category'] ?? 'Uncategorized'); }
            }
        }
        if ($snapCategory === '') { $snapCategory = 'Uncategorized'; }

        $my_history[] = [
            'request_id' => $reqId,
            'borrow_id' => $borrowId,
            'borrowed_at' => $ba,
            'returned_at' => $ra,
            'status' => (string)($ub['status'] ?? ''),
            'model_id' => $mid,
            'item_name' => $snapItemName,
            'model' => $snapModel,
            'category' => $snapCategory,
            'item_status' => (string)($itm['status'] ?? ''),
            'last_lost_at' => (string)($last_lost['created_at'] ?? ''),
            'last_found_at' => (string)($last_found['created_at'] ?? ''),
            'last_maint_at' => (string)($last_maint['created_at'] ?? ''),
            'last_fixed_at' => (string)($last_fixed['created_at'] ?? ''),
        ];
    }
} catch (Throwable $_) { }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Items</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__.'/css/style.css'); ?>">
    <style>
      html, body { height: 100%; }
      body { overflow: hidden; }
      #sidebar-wrapper { position: sticky; top: 0; height: 100vh; overflow: hidden; }
      #page-content-wrapper { flex: 1 1 auto; height: 100vh; overflow: auto; }
      @media (max-width: 768px) { body { overflow: auto; } #page-content-wrapper { height: auto; overflow: visible; } }
    </style>
</head>
<body>
    <button class="mobile-menu-toggle d-md-none" onclick="toggleSidebar()">
        <i class="bi bi-list"></i>
    </button>

    <div class="d-flex">
        <div class="bg-light border-end" id="sidebar-wrapper">
            <div class="sidebar-heading py-4 fs-4 fw-bold border-bottom d-flex align-items-center justify-content-center">
                <img src="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" alt="ECA Logo" class="brand-logo me-2" />
                <span>ECA MIS-GMIS</span>
            </div>
            <div class="list-group list-group-flush my-3">
                <a href="user_dashboard.php" class="list-group-item list-group-item-action bg-transparent">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
                <a href="user_request.php" class="list-group-item list-group-item-action bg-transparent">
                    <i class="bi bi-clipboard-plus me-2"></i>Request to Borrow
                </a>
                <a href="user_items.php" class="list-group-item list-group-item-action bg-transparent fw-bold">
                    <i class="bi bi-collection me-2"></i>My Items
                </a>
                
                <a href="change_password.php" class="list-group-item list-group-item-action bg-transparent">
                    <i class="bi bi-key me-2"></i>Change Password
                </a>
                <a href="logout.php" class="list-group-item list-group-item-action bg-transparent">
                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                </a>
            </div>
        </div>

        <div class="p-4" id="page-content-wrapper">
            <div class="page-header d-flex justify-content-between align-items-center">
                <h2 class="page-title">
                    <i class="bi bi-collection me-2"></i>My Items
                </h2>
                <a href="user_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
            </div>

            <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <div class="row g-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><strong>My Borrowed</strong></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Request ID</th>
                                            <th>Model ID</th>
                                            <th>Borrowed/Requested At</th>
                                            <th>Model Name</th>
                                            <th>Category</th>
                                            <th>Condition</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($my_borrowed_combined)): ?>
                                            <tr><td colspan="8" class="text-center text-muted">No active borrowed or requested items.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($my_borrowed_combined as $b): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)($b['row_id'] ?? '')); ?></td>
                                                <td><?php echo (isset($b['model_id']) && $b['model_id'] !== null && $b['model_id'] !== '') ? htmlspecialchars((string)$b['model_id']) : '—'; ?></td>
                                                <td><?php echo htmlspecialchars(date('h:i A m-d-y', strtotime($b['borrowed_at']))); ?></td>
                                                <td><?php echo htmlspecialchars(($b['model'] ?: $b['item_name']) ?? ''); ?></td>
                                                <td>
                                                    <?php
                                                    $catDisp = ($b['category'] ?: 'Uncategorized');
                                                    if ($catDisp === 'Uncategorized' && (!isset($b['model_id']) || $b['model_id'] === null || $b['model_id'] === '')) {
                                                        $catDisp = 'Out of Stock';
                                                    }
                                                    echo htmlspecialchars($catDisp);
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($b['condition'] ?: ''); ?></td>
                                                <td><?php echo ($b['__status'] === 'Borrowed') ? 'Borrow' : 'Request'; ?></td>
                                                <td>
                                                    <?php
                                                    $st = (string)($b['__status'] ?? '');
                                                    $avail = isset($b['available_count']) ? (int)$b['available_count'] : null;
                                                    $isRequest = ($st !== 'Borrowed');
                                                    // If it's a request and there is currently no available stock, show Out of Stock
                                                    if ($isRequest && $avail !== null && $avail <= 0) {
                                                        $disp = 'Out of Stock';
                                                        $cls = 'secondary';
                                                    } else {
                                                        $disp = $st;
                                                        $cls = ($st==='Borrowed' ? 'primary' : ($st==='Approved' ? 'success' : 'warning'));
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars($disp); ?></span>
                                                </td>
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

            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><strong>Borrow History</strong></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Request ID</th>
                                            <th>Borrowed At</th>
                                            <th>Returned At</th>
                                            <th>Model ID</th>
                                            <th>Item</th>
                                            <th>Category</th>
                                            <th>Item Status</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($my_history)): ?>
                                            <tr><td colspan="6" class="text-center text-muted">No history yet.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($my_history as $hv): ?>
                                            <tr>
                                                <td><?php
                                                    $rid = isset($hv['request_id']) ? (int)$hv['request_id'] : 0;
                                                    $bid = isset($hv['borrow_id']) ? (int)$hv['borrow_id'] : 0;
                                                    echo htmlspecialchars((string)($rid > 0 ? $rid : $bid));
                                                ?></td>
                                                <td><?php echo htmlspecialchars($hv['borrowed_at'] ? date('h:i A m-d-y', strtotime($hv['borrowed_at'])) : ''); ?></td>
                                                <td><?php echo htmlspecialchars($hv['returned_at'] ? date('h:i A m-d-y', strtotime($hv['returned_at'])) : ''); ?></td>
                                                <td><?php echo htmlspecialchars((string)($hv['model_id'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars(($hv['model'] ?: $hv['item_name']) ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars(($hv['category'] ?: 'Uncategorized')); ?></td>
                                                <td>
                                                    <?php
                                                    // Determine historical item status for display
                                                    $lostAt = isset($hv['last_lost_at']) ? strtotime((string)$hv['last_lost_at']) : null;
                                                    $foundAt = isset($hv['last_found_at']) ? strtotime((string)$hv['last_found_at']) : null;
                                                    $maintAt = isset($hv['last_maint_at']) ? strtotime((string)$hv['last_maint_at']) : null;
                                                    $fixedAt = isset($hv['last_fixed_at']) ? strtotime((string)$hv['last_fixed_at']) : null;
                                                    $showLost = ($lostAt && (!$foundAt || $foundAt <= $lostAt));
                                                    $showMaint = ($maintAt && (!$fixedAt || $fixedAt <= $maintAt));
                                                    if ($showLost) { echo '<span class="badge bg-danger">Lost</span>'; }
                                                    elseif ($showMaint) { echo '<span class="badge bg-warning text-dark">Under Maintenance</span>'; }
                                                    else {
                                                      $ist = (string)($hv['item_status'] ?? '');
                                                      if ($ist === 'Under Maintenance') { echo '<span class="badge bg-warning text-dark">Under Maintenance</span>'; }
                                                      elseif ($ist === 'Lost') { echo '<span class="badge bg-danger">Lost</span>'; }
                                                      else { echo '<span class="badge bg-success">Available</span>'; }
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    // Determine status badge: show Found if item was lost and later found
                                                    $disp = (string)($hv['status'] ?? '');
                                                    $lostAt = isset($hv['last_lost_at']) ? strtotime((string)$hv['last_lost_at']) : null;
                                                    $foundAt = isset($hv['last_found_at']) ? strtotime((string)$hv['last_found_at']) : null;
                                                    if ($lostAt) {
                                                        if ($foundAt && $foundAt > $lostAt) {
                                                            $disp = 'Found';
                                                        } else {
                                                            $disp = 'Lost';
                                                        }
                                                    }
                                                    $cls = ($disp === 'Returned') ? 'success' : (($disp === 'Found') ? 'success' : (($disp === 'Lost') ? 'danger' : 'warning'));
                                                    ?>
                                                    <span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars($disp); ?></span>
                                                </td>
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
        </div>
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
        (function(){
            var ua = navigator.userAgent || '';
            if (ua.indexOf('MISGMIS-APP') !== -1) return;
            if (typeof window.fetch !== 'function') return;
            function checkDisabled(){
                try {
                    fetch('auth_status.php', { cache: 'no-store' })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            try {
                                if (!d) return;
                                if (d.disabled) {
                                    window.location.href = 'logout.php?disabled=1';
                                } else if (d.logged_out) {
                                    window.location.href = 'index.php';
                                }
                            } catch (_){ }
                        })
                        .catch(function(){ });
                } catch (_){ }
            }
            checkDisabled();
            setInterval(function(){
                try {
                    if (document.visibilityState && document.visibilityState !== 'visible') return;
                } catch (_){ }
                checkDisabled();
            }, 2000);
        })();
    </script>
</body>
</html>
