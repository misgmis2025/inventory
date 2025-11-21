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
if (!isset($_SESSION['username']) || $_SESSION['usertype'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Prefer MongoDB for dashboard aggregates
$DASH_MONGO_FILLED = false;
// Filters (read early for Mongo)
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
$periodRaw = strtolower(trim($_GET['period'] ?? 'monthly'));
$period = in_array($periodRaw, ['monthly','yearly']) ? $periodRaw : 'monthly';

try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $itemsCol = $db->selectCollection('inventory_items');

    // Build match filter for dates (prefer date_acquired, fallback to created_at)
    $match = [];
    if ($date_from !== '' || $date_to !== '') {
        $dateOr = [];
        $cond1 = [];
        if ($date_from !== '') { $cond1['date_acquired']['$gte'] = $date_from; }
        if ($date_to !== '')   { $cond1['date_acquired']['$lte'] = $date_to; }
        if (!empty($cond1)) { $dateOr[] = $cond1; }
        $fromTs = ($date_from !== '') ? ($date_from . ' 00:00:00') : '';
        $toTs   = ($date_to   !== '') ? ($date_to   . ' 23:59:59') : '';
        $cond2 = [];
        if ($fromTs !== '') { $cond2['created_at']['$gte'] = $fromTs; }
        if ($toTs   !== '') { $cond2['created_at']['$lte'] = $toTs; }
        if (!empty($cond2)) { $dateOr[] = $cond2; }
        if (!empty($dateOr)) { $match['$or'] = $dateOr; }
    }

    // Fetch and aggregate by item_name in PHP for simplicity/compatibility
    $itemsAgg = [];
    $totalUnits = 0;
    $lowCount = 0;
    $outCount = 0;
    $highCount = 0;
    $byItem = [];
    $stocksMap = [];
    $outBorrowables = [];
    $cursor = $itemsCol->find($match);
    foreach ($cursor as $doc) {
        $nm = (string)($doc['item_name'] ?? '');
        // Default quantity to 1 if missing or invalid to reflect per-unit documents
        $qty = isset($doc['quantity']) ? (int)$doc['quantity'] : 1;
        if ($qty <= 0) { $qty = 1; }
        $totalUnits += $qty;
        if (!isset($byItem[$nm])) { $byItem[$nm] = 0; }
        $byItem[$nm] += $qty;
        // Stock time bucket: prefer date_acquired, fallback to created_at
        $dstr = trim((string)($doc['date_acquired'] ?? ''));
        $when = ($dstr !== '') ? $dstr : '';
        if ($when === '') {
            $ca = trim((string)($doc['created_at'] ?? ''));
            if ($ca !== '') { $when = substr($ca, 0, 10); }
        }
        if ($when !== '') {
            $grp = ($period === 'yearly') ? substr($when, 0, 4) : substr($when, 0, 7);
            if (!isset($stocksMap[$grp])) { $stocksMap[$grp] = 0; }
            $stocksMap[$grp] += $qty;
        }
    }
    // Build itemsAgg rows
    foreach ($byItem as $nm => $qty) { $itemsAgg[] = ['item_name' => $nm, 'total_qty' => $qty]; }
    // Compute counts (low/high from all items);
    $totalItems = count($itemsAgg);
    foreach ($itemsAgg as $row) {
        $q = (int)($row['total_qty'] ?? 0);
        if ($q < 10 && $q > 0) { $lowCount++; }
        elseif ($q > 50) { $highCount++; }
    }

    // Build Out of Stock list from active borrowable catalog using constrained availability (borrowable list quantities)
    try {
        $bcCol = $db->selectCollection('borrowable_catalog');
        $iiCol = $db->selectCollection('inventory_items');
        // Borrow limits map for active models
        $borrowLimitMap = [];
        $activeCur = $bcCol->find(['active' => 1], ['projection' => ['model_name' => 1, 'category' => 1, 'borrow_limit'=>1]]);
        foreach ($activeCur as $b) {
            $c = (string)($b['category'] ?? '');
            $c = ($c !== '') ? $c : 'Uncategorized';
            $m = trim((string)($b['model_name'] ?? ''));
            if ($m === '') continue;
            if (!isset($borrowLimitMap[$c])) $borrowLimitMap[$c] = [];
            $borrowLimitMap[$c][$m] = (int)($b['borrow_limit'] ?? 0);
        }
        // Available now per (category, model)
        $availNow = [];
        $aggAvail = $iiCol->aggregate([
            ['$match'=>['status'=>'Available','quantity'=>['$gt'=>0]]],
            ['$project'=>[
                'category'=>['$ifNull'=>['$category','Uncategorized']],
                'model_key'=>['$ifNull'=>['$model','$item_name']],
                'q'=>['$ifNull'=>['$quantity',1]]
            ]],
            ['$group'=>['_id'=>['c'=>'$category','m'=>'$model_key'], 'avail'=>['$sum'=>'$q']]]
        ]);
        foreach ($aggAvail as $r) {
            $c = (string)($r->_id['c'] ?? 'Uncategorized');
            $m = (string)($r->_id['m'] ?? '');
            if ($m === '') continue;
            if (!isset($availNow[$c])) $availNow[$c] = [];
            $availNow[$c][$m] = (int)($r->avail ?? 0);
        }
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
        // Compute constrained availability and collect out-of-stock
        $outBorrowables = [];
        foreach ($borrowLimitMap as $cat => $mods) {
            foreach ($mods as $mod => $limit) {
                $availableNow = (int)($availNow[$cat][$mod] ?? 0);
                $cons = (int)($consumed[$cat][$mod] ?? 0);
                $avail = max(0, min(max(0, $limit - $cons), $availableNow));
                if ($avail <= 0) {
                    $outBorrowables[] = ['item_name'=>$mod, 'category'=>$cat, 'available_qty'=>0, 'oos_date'=>date('Y-m-d')];
                }
            }
        }
        $outCount = count($outBorrowables);
    } catch (Throwable $_ob) { }
    // Chart data: by item (sorted by item name as in SQL)
    usort($itemsAgg, function($a,$b){ return strcasecmp((string)$a['item_name'], (string)$b['item_name']); });
    $chartLabels = array_map(function($r){ return $r['item_name']; }, $itemsAgg);
    $chartValues = array_map(function($r){ return (int)$r['total_qty']; }, $itemsAgg);

    // Stocks chart (grouped by period)
    // Fill missing periods within selected range with zeros for continuous charting
    if ($period === 'monthly') {
        // Determine range
        $startYM = '';
        $endYM = '';
        if ($date_from !== '') { $startYM = substr($date_from, 0, 7); }
        if ($date_to   !== '') { $endYM   = substr($date_to, 0, 7); }
        // If no explicit filter, infer from existing keys
        if ($startYM === '' && !empty($stocksMap)) { $keys = array_keys($stocksMap); sort($keys, SORT_NATURAL); $startYM = substr($keys[0], 0, 7); }
        if ($endYM   === '' && !empty($stocksMap)) { $keys = isset($keys) ? $keys : array_keys($stocksMap); sort($keys, SORT_NATURAL); $endYM = substr($keys[count($keys)-1], 0, 7); }
        if ($startYM !== '' && $endYM !== '') {
            $y = (int)substr($startYM, 0, 4); $m = (int)substr($startYM, 5, 2);
            $yEnd = (int)substr($endYM, 0, 4); $mEnd = (int)substr($endYM, 5, 2);
            while ($y < $yEnd || ($y === $yEnd && $m <= $mEnd)) {
                $key = sprintf('%04d-%02d', $y, $m);
                if (!isset($stocksMap[$key])) { $stocksMap[$key] = 0; }
                $m++; if ($m > 12) { $m = 1; $y++; }
            }
        }
    } else { // yearly
        $startY = ($date_from !== '') ? (int)substr($date_from, 0, 4) : 0;
        $endY   = ($date_to   !== '') ? (int)substr($date_to, 0, 4) : 0;
        if ($startY === 0 && !empty($stocksMap)) { $keys = array_keys($stocksMap); sort($keys, SORT_NATURAL); $startY = (int)substr($keys[0], 0, 4); }
        if ($endY   === 0 && !empty($stocksMap)) { $keys = isset($keys) ? $keys : array_keys($stocksMap); sort($keys, SORT_NATURAL); $endY = (int)substr($keys[count($keys)-1], 0, 4); }
        if ($startY > 0 && $endY > 0) {
            for ($yy = $startY; $yy <= $endY; $yy++) { $k = (string)$yy; if (!isset($stocksMap[$k])) { $stocksMap[$k] = 0; } }
        }
    }
    ksort($stocksMap, SORT_NATURAL);
    $stocksLabels = array_keys($stocksMap);
    $stocksValues = array_values($stocksMap);
    $groupLabel  = ($period === 'yearly') ? 'Year' : 'Month';
    $stocksTitle = 'Stocks (' . ucfirst($period) . ')';

    $DASH_MONGO_FILLED = true;
} catch (Throwable $e) {
    $DASH_MONGO_FILLED = false;
}

// Filters
$date_from = $date_from;
$date_to   = $date_to;
$periodRaw = $periodRaw;
$period = $period;

// Build base where
$where = [];
$params = [];
$types = '';
if ($date_from !== '' && $date_to !== '') { $where[] = "date_acquired BETWEEN ? AND ?"; $params[] = $date_from; $params[] = $date_to; $types .= 'ss'; }
elseif ($date_from !== '') { $where[] = "date_acquired >= ?"; $params[] = $date_from; $types .= 's'; }
elseif ($date_to !== '') { $where[] = "date_acquired <= ?"; $params[] = $date_to; $types .= 's'; }


$whereSql = '';
if (!empty($where)) { $whereSql = 'WHERE ' . implode(' AND ', $where); }

// If Mongo failed, leave aggregates empty but render page
if (!$DASH_MONGO_FILLED) { $itemsAgg = []; }

// Compute stats placeholders when Mongo failed
if (!$DASH_MONGO_FILLED) {
    $lowCount = 0; $outCount = 0; $highCount = 0; $totalItems = 0; $totalUnits = 0; $outBorrowables = [];
}

// Prepare chart data (MySQL fallback)
if (!$DASH_MONGO_FILLED) {
    $chartLabels = [];
    $chartValues = [];
    foreach ($itemsAgg as $row) {
        $chartLabels[] = $row['item_name'];
        $chartValues[] = (int)$row['total_qty'];
    }
}

if (!$DASH_MONGO_FILLED) { $groupLabel = ($period === 'yearly') ? 'Year' : 'Month'; $stocksTitle = 'Stocks (Sum of Units Added - ' . ucfirst($period) . ')'; }

if (!$DASH_MONGO_FILLED) { $stocksLabels = []; $stocksValues = []; }

// Close later after HTML renders (we might reuse $conn if needed)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css?v=3">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0d6efd" />
    <script src="pwa-register.js"></script>
    <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" />
    <style>
      /* Fixed sidebar, scrollable content */
      html, body { height: 100%; }
      body { overflow: hidden; }
      #sidebar-wrapper { position: sticky; top: 0; height: 100vh; overflow: hidden; }
      #page-content-wrapper { flex: 1 1 auto; height: 100vh; overflow: auto; }
      @media (max-width: 768px) {
        body { overflow: auto; }
        #page-content-wrapper { height: auto; overflow: visible; }
      }
      @media (max-width: 768px){
        @media (max-width: 768px) {
          .bottom-nav{ position: fixed; bottom: 0; left:0; right:0; z-index: 1050; background:#fff; border-top:1px solid #dee2e6; display:flex; justify-content:space-around; padding:8px 6px; }
          body{ padding-bottom: 64px; }
          .bottom-nav a{ text-decoration:none; font-size:12px; color:#333; display:flex; flex-direction:column; align-items:center; gap:4px; }
          .bottom-nav a .bi{ font-size:18px; }
        }
        /* Strong mobile compaction for admin dashboard */
        body.allow-mobile #page-content-wrapper{ padding: 12px !important; }
        body.allow-mobile .page-header{ gap:8px !important; margin-bottom:10px !important; }
        body.allow-mobile .page-title{ font-size: 1rem !important; margin:0 !important; }
        body.allow-mobile .card .card-header{ padding: 8px 10px !important; }
        body.allow-mobile .card .card-body{ padding: 10px !important; }
        body.allow-mobile .fs-4{ font-size: 1rem !important; }
        body.allow-mobile .btn{ padding: 6px 8px !important; font-size: .85rem !important; }
        body.allow-mobile table{ font-size: 12px !important; }
        body.allow-mobile table th, body.allow-mobile table td{ padding: 6px 8px !important; }
        #stockChart, #stocksChart{ height: 160px !important; max-height: 160px !important; }
      }
      /* Sidebar visibility: show on desktop, hide on mobile */
      @media (min-width: 769px){
        #sidebar-wrapper { display: block !important; }
      }
      @media (max-width: 768px){
        #sidebar-wrapper { display: none !important; }
      }
    </style>
</head>
<body class="allow-mobile">
    
    <div class="d-flex">
        <!-- Sidebar -->
        <div class="bg-light border-end" id="sidebar-wrapper">
            <div class="sidebar-heading py-4 fs-4 fw-bold border-bottom d-flex align-items-center justify-content-center">
                <img src="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" alt="ECA Logo" class="brand-logo me-2" />
                <span>ECA MIS-GMIS</span>
            </div>
            <div class="list-group list-group-flush my-3">
                <a href="admin_dashboard.php" class="list-group-item list-group-item-action bg-transparent fw-bold">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
                <a href="inventory.php" class="list-group-item list-group-item-action bg-transparent">
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
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </h2>
                <div class="d-flex align-items-center gap-3">
                    <span class="text-muted">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</span>
                    <div class="position-relative d-none" id="adminBellWrap">
                        <button class="btn btn-light position-relative" id="adminBellBtn" title="Notifications">
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
                </div>
            </div>

            <!-- Inventory KPIs -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="openKpi('total_items')" style="cursor: pointer;">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="text-muted small">Total Items</div>
                                    <div class="fs-4 fw-bold"><?php echo (int)$totalItems; ?></div>
                                </div>
                                <i class="bi bi-box-seam text-primary" style="font-size: 1.8rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div id="kpiTotalUnits" class="card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="window.location.href='inventory.php'" style="cursor: pointer;">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="text-muted small">Total Units</div>
                                    <div class="fs-4 fw-bold"><?php echo (int)$totalUnits; ?></div>
                                </div>
                                <i class="bi bi-collection text-info" style="font-size: 1.8rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="openKpi('low')" style="cursor: pointer;">
                        <div class="card-body">
                            <div class="text-muted small">Low Stock</div>
                            <div class="fs-4 fw-bold text-warning"><?php echo (int)$lowCount; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="openKpi('out')" style="cursor: pointer;">
                        <div class="card-body">
                            <div class="text-muted small">Out of Stock</div>
                            <div class="fs-4 fw-bold text-danger"><?php echo (int)$outCount; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="openKpi('high')" style="cursor: pointer;">
                        <div class="card-body">
                            <div class="text-muted small">High Stock</div>
                            <div class="fs-4 fw-bold text-success"><?php echo (int)$highCount; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" class="row g-2 align-items-end mb-3">
                <div class="col-12 col-md-4">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>" />
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>" />
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Period</label>
                    <select name="period" class="form-select">
                        <option value="monthly" <?php echo ($period === 'monthly' ? 'selected' : ''); ?>>Monthly</option>
                        <option value="yearly" <?php echo ($period === 'yearly' ? 'selected' : ''); ?>>Yearly</option>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
                </div>
            </form>

            <!-- Chart -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Stock by Item</strong></div>
                <div class="card-body">
                    <canvas id="stockChart" height="120"></canvas>
                </div>
            </div>

            <div class="modal fade" id="kpiModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="kpiModalTitle"></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div id="kpiEmpty" class="text-muted d-none">No items found for this category.</div>
                            <div class="table-responsive" id="kpiTableWrap">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th class="text-end">Units</th>
                                        </tr>
                                    </thead>
                                    <tbody id="kpiTbody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stocks Chart -->
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white"><strong><?php echo htmlspecialchars($stocksTitle); ?></strong></div>
                <div class="card-body">
                    <canvas id="stocksChart" height="120"></canvas>
                </div>
            </div>

           

    <!-- Bootstrap Icons and JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function(){
            // Disable Total Units click on mobile, enable on larger screens
            document.addEventListener('DOMContentLoaded', function(){
                var el = document.getElementById('kpiTotalUnits');
                if (!el) return;
                function applyKpiTotalUnitsBehavior(){
                    var isMobile = window.matchMedia('(max-width: 768px)').matches;
                    if (isMobile) {
                        el.style.cursor = 'default';
                        el.setAttribute('aria-disabled','true');
                        el.removeAttribute('onclick');
                        el.removeAttribute('role');
                        el.removeAttribute('tabindex');
                    } else {
                        if (!el.getAttribute('onclick')) {
                            el.setAttribute('onclick', "window.location.href='inventory.php'");
                        }
                        el.style.cursor = 'pointer';
                        el.setAttribute('role','button');
                        el.setAttribute('tabindex','0');
                        el.removeAttribute('aria-disabled');
                    }
                }
                applyKpiTotalUnitsBehavior();
                window.addEventListener('resize', applyKpiTotalUnitsBehavior);
            });
            const bellWrap = document.getElementById('adminBellWrap');
            const bellBtn = document.getElementById('adminBellBtn');
            const bellDot = document.getElementById('adminBellDot');
            const dropdown = document.getElementById('adminBellDropdown');
            const listEl = document.getElementById('adminNotifList');
            const emptyEl = document.getElementById('adminNotifEmpty');
            if (bellWrap) { bellWrap.classList.remove('d-none'); }

            let latestTs = 0;
            if (bellBtn && dropdown) {
                bellBtn.addEventListener('click', function(e){
                    e.stopPropagation();
                    dropdown.classList.toggle('show');
                    if (window.innerWidth <= 768) {
                        dropdown.style.position = 'fixed';
                        dropdown.style.top = '12%';
                        dropdown.style.left = '50%';
                        dropdown.style.transform = 'translateX(-50%)';
                        dropdown.style.right = 'auto';
                        dropdown.style.maxWidth = '92vw';
                    } else {
                        dropdown.style.position = 'absolute';
                        dropdown.style.transform = 'none';
                        dropdown.style.top = (bellBtn.offsetTop + bellBtn.offsetHeight + 6) + 'px';
                        dropdown.style.left = (bellBtn.offsetLeft - (dropdown.offsetWidth - bellBtn.offsetWidth)) + 'px';
                    }
                    if (bellDot) bellDot.classList.add('d-none');
                    try { const nowTs = latestTs || Date.now(); localStorage.setItem('admin_notif_last_open', String(nowTs)); } catch(_){ }
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
            function renderList(items){
                const rows = [];
                latestTs = 0;
                (items||[]).forEach(function(r){
                    const id = parseInt(r.id||0,10);
                    const user = String(r.username||'');
                    const nm = String(r.item_name||'');
                    const qty = parseInt(r.quantity||1,10);
                    const whenRaw = String(r.created_at||'');
                    // Normalize to ISO-like for reliable parsing across browsers
                    const whenDate = whenRaw ? new Date(whenRaw.replace(' ', 'T')) : null;
                    const whenTxt = whenDate ? whenDate.toLocaleString() : '';
                    if (whenDate) { const t = whenDate.getTime(); if (!isNaN(t) && t > latestTs) latestTs = t; }
                    rows.push(
                        '<a href="admin_borrow_center.php" class="list-group-item list-group-item-action">'
                        + '<div class="d-flex w-100 justify-content-between">'
                        +   '<strong>#'+id+'</strong>'
                        +   '<small class="text-muted">'+whenTxt+'</small>'
                        + '</div>'
                        + '<div class="mb-0">'+escapeHtml(user)+' requests '+escapeHtml(nm)+' <span class="badge bg-secondary">x'+qty+'</span></div>'
                        + '</a>'
                    );
                });
                listEl.innerHTML = rows.join('');
                emptyEl.style.display = rows.length ? 'none' : '';
            }
            function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }

            function poll(){
                if (fetching) return; fetching = true;
                fetch('admin_borrow_center.php?action=pending_json')
                    .then(r=>r.json())
                    .then(d=>{
                        const items = (d && Array.isArray(d.pending)) ? d.pending : [];
                        renderList(items);
                        // Show red dot whenever there are any pending requests
                        try {
                            const showDot = items.length > 0;
                            if (bellDot) bellDot.classList.toggle('d-none', !showDot);
                        } catch(_){ if (bellDot) bellDot.classList.toggle('d-none', items.length===0); }
                        try {
                            const navLink = document.querySelector('a[href="admin_borrow_center.php"]');
                            if (navLink) {
                                let dot = navLink.querySelector('.nav-borrow-dot');
                                const shouldShow = items.length > 0;
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
                        const currIds = new Set(items.map(it=>parseInt(it.id||0,10)));
                        if (!initialized) {
                            baselineIds = currIds;
                            initialized = true;
                        } else {
                            let hasNew = false;
                            currIds.forEach(id=>{ if (!baselineIds.has(id)) { hasNew = true; } });
                            if (hasNew) {
                                items.forEach(it=>{ if (!baselineIds.has(parseInt(it.id||0,10))) { showToast('New request: '+(it.username||'')+' → '+(it.item_name||'')+' (x'+(it.quantity||1)+')'); } });
                                playBeep();
                            }
                            baselineIds = currIds;
                        }
                    })
                    .catch(()=>{})
                    .finally(()=>{ fetching = false; });
            }
            poll();
            setInterval(()=>{ if (document.visibilityState === 'visible') poll(); }, 2000);
        })();

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar-wrapper');
            sidebar.classList.toggle('active');
            if (window.innerWidth <= 768) {
                document.body.classList.toggle('sidebar-open', sidebar.classList.contains('active'));
            }
        }
        
        // Close sidebar when clicking outside on mobile
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
        // Chart: Stock by Item
        (function(){
            const ctx = document.getElementById('stockChart');
            if (!ctx) return;
            const labels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
            const values = <?php echo json_encode($chartValues, JSON_NUMERIC_CHECK); ?>;
            if (!labels || labels.length === 0) {
                ctx.parentElement.innerHTML = '<div class="text-muted">No data for the selected filter.</div>';
                return;
            }
            // Limit bars to avoid overcrowding; show top N by qty
            const data = labels.map((l,i)=>({ l, v: values[i] }));
            data.sort((a,b)=>b.v-a.v);
            const top = data.slice(0, 20); // top 20
            const topLabels = top.map(d=>d.l);
            const topValues = top.map(d=>d.v);
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: topLabels,
                    datasets: [{
                        label: 'Units',
                        data: topValues,
                        backgroundColor: 'rgba(13, 110, 253, 0.5)',
                        borderColor: 'rgba(13, 110, 253, 1)',
                        borderWidth: 1,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true },
                        tooltip: { enabled: true }
                    },
                    scales: {
                        x: { ticks: { autoSkip: false, maxRotation: 45, minRotation: 0 } },
                        y: { beginAtZero: true, title: { display: true, text: 'Units' } }
                    }
                }
            });
        })();

        const aggData = <?php echo json_encode($itemsAgg, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK); ?>;
        const outBorrowables = <?php echo json_encode($outBorrowables, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK); ?>;
        function openKpi(kind) {
            const titleMap = {
                total_items: 'All Items',
                total_units: 'Items Contributing to Total Units',
                low: 'Low Stock (<10)',
                out: 'Out of Stock',
                high: 'High Stock (>50)'
            };
            const rows = [];
            if (kind === 'out') {
                (outBorrowables || []).forEach(function(r){ rows.push(r); });
            } else {
                for (const r of aggData) {
                    const qty = parseInt(r.total_qty || 0, 10);
                    if (kind === 'total_items' || kind === 'total_units') rows.push(r);
                    else if (kind === 'low' && qty > 0 && qty < 10) rows.push(r);
                    else if (kind === 'high' && qty > 50) rows.push(r);
                }
            }
            const titleEl = document.getElementById('kpiModalTitle');
            const tbody = document.getElementById('kpiTbody');
            const empty = document.getElementById('kpiEmpty');
            const wrap = document.getElementById('kpiTableWrap');
            const theadRow = document.querySelector('#kpiTableWrap thead tr');
            titleEl.textContent = titleMap[kind] || 'Items';
            tbody.innerHTML = '';
            if (rows.length === 0) {
                empty.classList.remove('d-none');
                wrap.classList.add('d-none');
            } else {
                empty.classList.add('d-none');
                wrap.classList.remove('d-none');
                // Adjust headers based on kind
                if (theadRow) {
                    if (kind === 'out') {
                        theadRow.innerHTML = '<th>Item</th><th>Category</th><th>Since</th>';
                    } else {
                        theadRow.innerHTML = '<th>Item</th><th class="text-end">Units</th>';
                    }
                }
                rows.forEach(r => {
                    const tr = document.createElement('tr');
                    const td1 = document.createElement('td');
                    td1.textContent = r.item_name;
                    if (kind === 'out') {
                        const tdCat = document.createElement('td');
                        tdCat.textContent = String(r.category || '');
                        const tdDate = document.createElement('td');
                        tdDate.textContent = String(r.oos_date || '');
                        tr.appendChild(td1);
                        tr.appendChild(tdCat);
                        tr.appendChild(tdDate);
                    } else {
                        const td2 = document.createElement('td');
                        td2.className = 'text-end';
                        td2.textContent = parseInt(r.total_qty || 0, 10);
                        tr.appendChild(td1);
                        tr.appendChild(td2);
                    }
                    tbody.appendChild(tr);
                });
            }
            const modal = new bootstrap.Modal(document.getElementById('kpiModal'));
            modal.show();
        }

        // Chart: Stocks
        (function(){
            const ctx = document.getElementById('stocksChart');
            if (!ctx) return;
            const labels = <?php echo json_encode($stocksLabels, JSON_UNESCAPED_UNICODE); ?>;
            const values = <?php echo json_encode($stocksValues, JSON_NUMERIC_CHECK); ?>;
            if (!labels || labels.length === 0) {
                ctx.parentElement.innerHTML = '<div class="text-muted">No data for the selected period.</div>';
                return;
            }
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Units Added',
                        data: values,
                        borderColor: 'rgba(25, 135, 84, 1)',
                        backgroundColor: 'rgba(25, 135, 84, 0.2)',
                        tension: 0.2,
                        fill: true,
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true } },
                    scales: {
                        x: { title: { display: true, text: '<?php echo addslashes($groupLabel); ?>' } },
                        y: { beginAtZero: true, title: { display: true, text: 'Units' } }
                    }
                }
            });
        })();
    </script>
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
    <button type="button" class="btn btn-primary bottom-nav-toggle d-md-none" id="bnToggleDash" aria-controls="dashBottomNav" aria-expanded="false" title="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <nav class="bottom-nav d-md-none hidden" id="dashBottomNav">
      <a href="admin_dashboard.php" aria-label="Dashboard">
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
      </a>
      <a href="admin_borrow_center.php" aria-label="Borrow">
        <i class="bi bi-clipboard-check"></i>
        <span>Borrow</span>
      </a>
      <a href="qr_scanner.php" aria-label="QR">
        <i class="bi bi-qr-code-scan"></i>
        <span>QR</span>
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
        var btn = document.getElementById('bnToggleDash');
        var nav = document.getElementById('dashBottomNav');
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
