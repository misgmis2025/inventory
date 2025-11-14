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
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Not authorized</title></head><body>Not authorized</body></html>';
    exit();
}

@require_once __DIR__ . '/config.php';
if (!defined('USE_MONGO')) { define('USE_MONGO', true); }

$search_q = trim($_GET['q'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
// Normalize UI label to stored value
if ($filter_status === 'Maintenance') { $filter_status = 'Under Maintenance'; }
$filter_category = trim($_GET['category'] ?? '');
$filter_condition = trim($_GET['condition'] ?? '');
$filter_supply = trim($_GET['supply'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$cat_id_raw = trim($_GET['cat_id'] ?? '');
$model_id_search_raw = trim($_GET['mid'] ?? '');
$location_search_raw = trim($_GET['loc'] ?? '');
$department = trim($_GET['department'] ?? '');
$header_date = trim($_GET['date'] ?? '');
$prepared_by = trim($_GET['prepared_by'] ?? '');
$checked_by = trim($_GET['checked_by'] ?? '');
// Default Prepared by to current admin full name if not provided
if ($prepared_by === '') {
  try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $dbTmp = get_mongo_db();
    $u = $dbTmp->selectCollection('users')->findOne(['username' => ($_SESSION['username'] ?? '')], ['projection' => ['full_name' => 1]]);
    $fn = $u && isset($u['full_name']) ? trim((string)$u['full_name']) : '';
    if ($fn !== '') { $prepared_by = $fn; } else { $prepared_by = (string)($_SESSION['username'] ?? ''); }
  } catch (Throwable $_e) { $prepared_by = (string)($_SESSION['username'] ?? ''); }
}
// Normalize header date display to MM-DD-YYYY if provided as YYYY-MM-DD
$header_date_display = $header_date;
if ($header_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $header_date)) {
  $tsTmp = strtotime($header_date);
  if ($tsTmp) { $header_date_display = date('m-d-Y', $tsTmp); }
}

$isAdmin = (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin');

$usedMongo = false;
$items = [];
$categoryOptions = [];
$borrow_history = [];
$reservationMode = (strcasecmp($filter_status, 'Reserved') === 0) || (strcasecmp($filter_status, 'Reservation') === 0);

if (defined('USE_MONGO') && USE_MONGO) {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  try {
    $db = get_mongo_db();
    $match = [];
    if ($search_q !== '') {
      if ($isAdmin && ctype_digit($search_q)) { $match['id'] = intval($search_q); }
      else { $match['item_name'] = ['$regex' => $search_q, '$options' => 'i']; }
    }
    if ($filter_status !== '') { $match['status'] = $filter_status; }
    if ($filter_category !== '') { $match['category'] = $filter_category; }
    if ($filter_condition !== '') { $match['condition'] = $filter_condition; }
    if ($filter_supply !== '') {
      if ($filter_supply === 'low') { $match['quantity'] = ['$lt' => 10]; }
      elseif ($filter_supply === 'average') { $match['quantity'] = ['$gt' => 10, '$lt' => 50]; }
      elseif ($filter_supply === 'high') { $match['quantity'] = ['$gt' => 50]; }
    }
    // Apply date range filter for Mongo if provided (supports string or Date types, and both date_acquired/created_at)
    if ($date_from !== '' || $date_to !== '') {
      $orClauses = [];
      $fields = ['date_acquired','created_at'];
      foreach ($fields as $fld) {
        // String-based comparison (YYYY-MM-DD)
        $strCond = [];
        if ($date_from !== '') { $strCond['$gte'] = $date_from; }
        if ($date_to !== '') { $strCond['$lte'] = $date_to; }
        if (!empty($strCond)) { $orClauses[] = [$fld => $strCond]; }

        // Date-type comparison using UTCDateTime
        try {
          $dtCond = [];
          if ($date_from !== '') { $dtCond['$gte'] = new \MongoDB\BSON\UTCDateTime(strtotime($date_from.' 00:00:00') * 1000); }
          if ($date_to !== '') { $dtCond['$lte'] = new \MongoDB\BSON\UTCDateTime(strtotime($date_to.' 23:59:59') * 1000); }
          if (!empty($dtCond)) { $orClauses[] = [$fld => $dtCond]; }
        } catch (\Throwable $e) { /* ignore */ }
      }
      if (!empty($orClauses)) { $match['$or'] = $orClauses; }
    }
    if ($reservationMode) {
      // Build list from current (ongoing) approved reservations
      $erCol = $db->selectCollection('equipment_requests');
      $nowStr = date('Y-m-d H:i:s');
      $resCur = $erCol->find([
        'type' => 'reservation',
        'status' => 'Approved',
        'reserved_from' => ['$lte' => $nowStr],
        'reserved_to' => ['$gte' => $nowStr],
      ]);
      // Group by item_name and category
      $group = [];
      foreach ($resCur as $r) {
        $name = (string)($r['item_name'] ?? '');
        if ($name === '') continue;
        $cat = '';
        try {
          $iiCol = $db->selectCollection('inventory_items');
          $itm = $iiCol->findOne(['$or' => [['model'=>$name], ['item_name'=>$name]]], ['projection'=>['category'=>1,'location'=>1]]);
          if ($itm) { $cat = (string)($itm['category'] ?? ''); $loc = (string)($itm['location'] ?? ''); }
        } catch (Throwable $_) { $loc = ''; }
        $key = $name.'||'.$cat;
        if (!isset($group[$key])) { $group[$key] = ['item_name'=>$name,'category'=>$cat,'quantity'=>0,'location'=>($loc ?? '')]; }
        $group[$key]['quantity'] += max(1, intval($r['quantity'] ?? 1));
      }
      $items = array_values(array_map(function($v){
        return [
          'id' => 0,
          'item_name' => (string)$v['item_name'],
          'category' => (string)($v['category'] ?: 'Uncategorized'),
          'quantity' => (int)$v['quantity'],
          'location' => (string)($v['location'] ?? ''),
          'condition' => '',
          'status' => 'Reserved',
          'date_acquired' => '',
        ];
      }, $group));
      // Sort like before
      usort($items, function($a,$b){ return strcmp(($a['category']??''), ($b['category']??'')); });
    } else {
      $cursor = $db->selectCollection('inventory_items')->find($match, [
        'sort' => ['category' => 1, 'item_name' => 1, 'id' => 1],
      ]);
      foreach ($cursor as $doc) {
        $items[] = [
          'id' => intval($doc['id'] ?? 0),
          'item_name' => (string)($doc['item_name'] ?? ''),
          'category' => (string)($doc['category'] ?? ''),
          'quantity' => intval($doc['quantity'] ?? 0),
          'location' => (string)($doc['location'] ?? ''),
          'condition' => (string)($doc['condition'] ?? ''),
          'status' => (string)($doc['status'] ?? ''),
          'date_acquired' => (string)($doc['date_acquired'] ?? ''),
        ];
      }
    }
    // Enrich for Lost/Under Maintenance: who lost/damaged and who marked (leave blank if no log)
    $ldExtraMap = [];
    $ldShow = in_array($filter_status, ['Lost','Under Maintenance'], true);
    if ($ldShow && !empty($items)) {
      $dbUsers = $db->selectCollection('users');
      $userNameToFull = function($uname) use ($dbUsers){
        $uname = (string)$uname; if ($uname==='') return '';
        try { $u = $dbUsers->findOne(['username'=>$uname], ['projection'=>['full_name'=>1]]); } catch (Throwable $e) { $u = null; }
        $fn = $u && isset($u['full_name']) ? (string)$u['full_name'] : '';
        return $fn !== '' ? $fn : $uname;
      };
      $modelIds = array_values(array_unique(array_map(function($r){ return (int)($r['id'] ?? 0); }, $items)));
      $ldCol = $db->selectCollection('lost_damaged_log');
      $ubCol = $db->selectCollection('user_borrows');
      foreach ($modelIds as $mid) {
        $log = null;
        try { $log = $ldCol->findOne(['model_id'=>$mid, 'action'=>$filter_status], ['sort'=>['id'=>-1]]); } catch (Throwable $e) { $log = null; }
        $markerUser = $log && isset($log['username']) ? (string)$log['username'] : '';
        $ldExtraMap[$mid] = [ 'marker_username'=>$markerUser, 'marker_full_name'=>$markerUser!=='' ? $userNameToFull($markerUser) : '', 'by_username'=>'', 'by_full_name'=>'' ];
        if ($log && isset($log['created_at'])) {
          $logTs = (string)$log['created_at'];
          try { $cur = $ubCol->find(['model_id'=>$mid, 'returned_at' => ['$ne'=>null]], ['sort'=>['returned_at'=>-1,'id'=>-1]]); } catch (Throwable $e) { $cur = []; }
          foreach ($cur as $ub) {
            $ret = (string)($ub['returned_at'] ?? '');
            if ($ret !== '' && $ret <= $logTs) { $uname = (string)($ub['username'] ?? ''); $ldExtraMap[$mid]['by_username']=$uname; $ldExtraMap[$mid]['by_full_name']=$userNameToFull($uname); break; }
          }
        }
      }
    }
    // Categories
    $catCur = $db->selectCollection('categories')->find([], ['sort' => ['name' => 1], 'projection' => ['name' => 1]]);
    foreach ($catCur as $c) { if (!empty($c['name'])) { $categoryOptions[] = (string)$c['name']; } }
    if ($isAdmin) {
      $ubCol = $db->selectCollection('user_borrows');
      $iiCol = $db->selectCollection('inventory_items');
      $uCol  = $db->selectCollection('users');
      $bhCur = $ubCol->find([], ['sort' => ['borrowed_at' => -1, 'id' => -1], 'limit' => 500]);
      foreach ($bhCur as $bh) {
        $row = (array)$bh;
        $username = (string)($row['username'] ?? '');
        $user_id = '';
        $full_name = '';
        if ($username !== '') {
          try { $u = $uCol->findOne(['username'=>$username], ['projection'=>['id'=>1,'full_name'=>1]]); } catch (Throwable $e) { $u = null; }
          if ($u) { $user_id = (string)($u['id'] ?? ''); $full_name = (string)($u['full_name'] ?? ''); }
        }
        if ($full_name === '' && $username !== '') { $full_name = $username; }
        $model_id = intval($row['model_id'] ?? 0);
        $model_name = '';
        $category = '';
        if ($model_id > 0) {
          try { $itm = $iiCol->findOne(['id'=>$model_id], ['projection'=>['model'=>1,'item_name'=>1,'category'=>1]]); } catch (Throwable $e) { $itm = null; }
          if ($itm) {
            $mn = trim((string)($itm['model'] ?? ''));
            $model_name = $mn !== '' ? $mn : (string)($itm['item_name'] ?? '');
            $category = trim((string)($itm['category'] ?? ''));
          }
        }
        if ($category === '') { $category = 'Uncategorized'; }
        $row['user_id'] = $user_id;
        $row['full_name'] = $full_name;
        $row['model_name'] = $model_name;
        $row['category'] = $category;
        $borrow_history[] = $row;
      }
    }
    $usedMongo = true;
  } catch (Throwable $e) { $usedMongo = false; }
}

if (!$usedMongo) {
  // Render empty sets when Mongo is unavailable
  $items = [];
  $categoryOptions = [];
}

// Build category ID mapping from current items
$catNamesTmp = [];
foreach ($items as $giTmp) {
  $catTmp = trim($giTmp['category'] ?? '') !== '' ? $giTmp['category'] : 'Uncategorized';
  $catNamesTmp[$catTmp] = true;
}
$catNamesArr = array_keys($catNamesTmp);
natcasesort($catNamesArr);
$catNamesArr = array_values($catNamesArr);
$applyDatePostFilter = ($date_from !== '' || $date_to !== '');
if ($applyDatePostFilter) {
  $fromTs = $date_from !== '' ? strtotime($date_from.' 00:00:00') : null;
  $toTs   = $date_to   !== '' ? strtotime($date_to.' 23:59:59')   : null;
  $items = array_values(array_filter($items, function($row) use ($fromTs, $toTs){
    $ds = trim((string)($row['date_acquired'] ?? ''));
    if ($ds === '') return false;
    $ts = strtotime($ds);
    if ($ts === false) return false;
    if ($fromTs !== null && $ts < $fromTs) return false;
    if ($toTs !== null && $ts > $toTs) return false;
    return true;
  }));
}
$catIdByName = [];
for ($i = 0; $i < count($catNamesArr); $i++) { $catIdByName[$catNamesArr[$i]] = sprintf('CAT-%03d', $i + 1); }

// Advanced filters mirrored from inventory_print.php
// Category filters by CAT-ID or name tokens
$catNameGroups = [];
$catIdFilters = [];
if ($cat_id_raw !== '') {
  $groups = preg_split('/\s*,\s*/', strtolower($cat_id_raw));
  foreach ($groups as $g) {
    $g = trim($g); if ($g === '') continue;
    $tokens = preg_split('/[\/\s]+/', $g);
    $needles = [];
    foreach ($tokens as $t) {
      $t = trim($t); if ($t === '') continue;
      if (preg_match('/^(?:cat-)?(\d{1,})$/', $t, $m)) { $num = intval($m[1]); if ($num > 0) { $catIdFilters[] = sprintf('CAT-%03d', $num); } }
      else { $needles[] = strtolower($t); }
    }
    if (!empty($needles)) { $catNameGroups[] = $needles; }
  }
}
if (!empty($catIdFilters) || !empty($catNameGroups)) {
  $items = array_values(array_filter($items, function($row) use ($catIdByName, $catIdFilters, $catNameGroups){
    $cat = trim($row['category'] ?? '') !== '' ? $row['category'] : 'Uncategorized';
    $cid = $catIdByName[$cat] ?? '';
    if (!empty($catIdFilters) && in_array($cid, $catIdFilters, true)) { return true; }
    if (!empty($catNameGroups)) {
      foreach ($catNameGroups as $grp) {
        $all = true;
        foreach ($grp as $n) { if ($n !== '' && !preg_match('/(?<![A-Za-z0-9])'.preg_quote($n, '/').'(?![A-Za-z0-9])/i', (string)$cat)) { $all = false; break; } }
        if ($all) { return true; }
      }
    }
    return false;
  }));
}

// Model search groups
if ($model_id_search_raw !== '') {
  $idSet = [];
  $nameGroups = [];
  $groups = preg_split('/\s*,\s*/', $model_id_search_raw);
  foreach ($groups as $g) {
    $g = trim($g); if ($g === '') continue;
    $tokens = preg_split('/\s+/', $g);
    $groupNeedles = [];
    foreach ($tokens as $t) {
      $t = trim($t); if ($t === '') continue;
      if (preg_match('/^\d+$/', $t)) { $idSet[intval($t)] = true; }
      else { $groupNeedles[] = strtolower($t); }
    }
    if (!empty($groupNeedles)) { $nameGroups[] = $groupNeedles; }
  }
  $items = array_values(array_filter($items, function($row) use ($idSet, $nameGroups){
    if (!empty($idSet)) { $rid = intval($row['id']); if (isset($idSet[$rid])) { return true; } }
    if (!empty($nameGroups)) {
      $nm = strtolower((string)($row['item_name'] ?? ''));
      foreach ($nameGroups as $grp) { $all = true; foreach ($grp as $n) { if ($n !== '' && strpos($nm, $n) === false) { $all = false; break; } } if ($all) { return true; } }
    }
    return false;
  }));
}

// Location groups
if ($location_search_raw !== '') {
  $locGroups = [];
  $groups = preg_split('/\s*,\s*/', strtolower($location_search_raw));
  foreach ($groups as $g) { $g = trim($g); if ($g === '') continue; $tokens = preg_split('/\s+/', $g); $needles = []; foreach ($tokens as $t) { $t = trim($t); if ($t !== '') { $needles[] = $t; } } if (!empty($needles)) { $locGroups[] = $needles; } }
  if (!empty($locGroups)) {
    $items = array_values(array_filter($items, function($row) use ($locGroups){
      $loc = (string)($row['location'] ?? '');
      foreach ($locGroups as $grp) {
        $all = true; foreach ($grp as $n) { if ($n !== '' && !preg_match('/(?<![A-Za-z0-9])'.preg_quote($n, '/').'(?![A-Za-z0-9])/i', $loc)) { $all = false; break; } }
        if ($all) { return true; }
      }
      return false;
    }));
  }
}

// Sort by Category ID descending like in inventory_print
usort($items, function($a, $b) use ($catIdByName){
  $ca = trim($a['category'] ?? '') !== '' ? $a['category'] : 'Uncategorized';
  $cb = trim($b['category'] ?? '') !== '' ? $b['category'] : 'Uncategorized';
  $ida = $catIdByName[$ca] ?? 'CAT-000';
  $idb = $catIdByName[$cb] ?? 'CAT-000';
  return strcmp($idb, $ida);
});

$scrollClass = (count($items) > 10) ? ' table-scroll' : '';
$ldShow = in_array($filter_status, ['Lost','Under Maintenance'], true);
$isOOO = ($filter_status === 'Out of Order');
$borrowScrollClass = (count($borrow_history) >= 13) ? ' table-scroll' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Print Preview</title>
  <link href="css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    @page { size: A4 portrait; margin: 1in; }
    @media print {
      .no-print { display: none !important; }
      html, body { margin: 0 !important; background: #ffffff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      * { background: #ffffff !important; color: #000 !important; box-shadow: none !important; }
      thead { display: table-header-group; }
      tfoot { display: table-footer-group; }
      .print-table { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 13px; }
      .print-table th, .print-table td { padding: .38rem .50rem; vertical-align: middle; line-height: 1.22; text-align: left; }
      .table-scroll { max-height: none !important; overflow: visible !important; }
      /* Disable sticky headers in print to avoid headers disappearing */
      .table-scroll thead th { position: static !important; top: auto !important; }
      /* Reinforce header borders so they are visible on paper */
      .print-table thead th { border-bottom: 1px solid #000 !important; }
      /* Prevent blank first page */
      .print-doc { width: 100% !important; border-collapse: collapse !important; border-spacing: 0 !important; }
      .print-doc thead tr:first-child { page-break-before: avoid !important; break-before: avoid-page !important; }
      .container-fluid { padding-left: 0 !important; padding-right: 0 !important; }
      .report-title { margin: 12px 0 22px !important; }
      .eca-form-row { gap: 20px !important; margin-bottom: 24px !important; }
      .table-responsive { margin-top: 16px !important; }
      .print-doc .print-table { margin-top: 10px !important; }
      .container-fluid.pb-3 { padding-bottom: .25rem !important; }
      .eca-footer { margin-top: 36px !important; }
      .page-break { page-break-before: always; break-before: page; }
    }
    .table-scroll { max-height: 480px; overflow-y: auto; }
    .table-responsive { margin-top: 8px; }
    .print-doc .print-table { margin-top: 10px; }
    .table-scroll thead th { position: sticky; top: 0; background: #f8f9fa; z-index: 1; }
    .eca-header { text-align: center; margin-bottom: 14px; }
    .eca-header .eca-title { font-weight: 400; letter-spacing: 6px; font-size: 14pt; }
    .eca-header .eca-sub { margin-top: 2px; font-weight: 600; font-size: 12pt; }
    .eca-meta { display: flex; align-items: center; justify-content: space-between; font-size: 9pt; margin-top: 6px; margin-bottom: 10px; }
    .report-title { text-align: center; font-weight: 400; font-size: 14pt; margin: 14px 0 12px; text-transform: uppercase; }
    .eca-form-row { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-bottom: 20px; }
    .eca-form-row .field { display: flex; align-items: center; gap: 8px; }
    .eca-footer .field { display: flex; align-items: baseline; gap: 8px; white-space: nowrap; }
    .eca-form-row label { font-weight: 600; font-size: 10pt; }
    .eca-input { border: none; border-bottom: 1px solid #000; outline: none; padding: 2px 4px; min-width: 200px; font-size: 10pt; }
    .eca-input.date-field { min-width: 160px; }
    .eca-footer { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 12px; flex-wrap: nowrap; }
    /* Remove native calendar UI for date inputs */
    .eca-input[type="date"]::-webkit-calendar-picker-indicator { display: none !important; opacity: 0 !important; -webkit-appearance: none !important; }
    .eca-input[type="date"]::-webkit-inner-spin-button,
    .eca-input[type="date"]::-webkit-clear-button { display: none !important; }
    @-moz-document url-prefix() {
      .eca-input[type="date"] { -moz-appearance: textfield; }
    }
    .eca-print-value { display: inline-block; border-bottom: 1px solid #000; padding: 0 4px 2px; color: #000; vertical-align: baseline; line-height: 1; }
    #deptPrintSpan, #prepPrintSpan, #checkPrintSpan { min-width: 200px; }
    #datePrintSpan { min-width: 160px; }
    @media screen { .eca-print-value { display: none; } }
    @media print {
      #deptInput, #dateInput, #prepInput, #checkInput { display: none !important; }
      .eca-print-value { display: inline-block !important; }
      thead { display: table-header-group; }
      tbody { display: table-row-group; }
    }
  </style>
</head>
<body>
  <div class="container-fluid pt-3">
    <div class="d-flex align-items-center justify-content-between no-print mb-2">
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.close()"><i class="bi bi-x-lg me-1"></i>Close</button>
        <button class="btn btn-primary btn-sm" type="button" id="doPrintBtn"><i class="bi bi-printer me-1"></i>Print</button>
      </div>
      
    </div>

    <?php 
      $pages = array_chunk($items, 20);
    ?>
    <?php if (empty($pages)): ?>
      <table class="print-doc" style="width:100%; border-collapse:collapse;">
        <thead>
          <tr><td style="padding:0;">
            <div class="container-fluid pt-3">
              <div class="eca-header">
                <div class="eca-title">ECA</div>
                <div class="eca-sub">Exact Colleges of Asia, Inc.</div>
              </div>
              <div class="eca-meta">
                <div class="form-no">Form No. <em>IF</em>/OO/Jun.2011</div>
                <div></div>
              </div>
              <div class="report-title">INVENTORY REPORT</div>
              <div class="eca-form-row">
                <div class="field">
                  <label for="deptInput">Department:</label>
                  <input id="deptInput" class="eca-input" type="text" placeholder="Enter department" value="<?php echo htmlspecialchars($department); ?>" />
                  <span id="deptPrintSpan" class="eca-print-value"></span>
                </div>
                <div class="field">
                  <label for="dateInput">Date:</label>
                  <input id="dateInput" class="eca-input date-field" type="text" placeholder="MM-DD-YYYY" inputmode="numeric" pattern="\d{2}-\d{2}-\d{4}" value="<?php echo htmlspecialchars($header_date_display); ?>" />
                  <span id="datePrintSpan" class="eca-print-value"></span>
                </div>
              </div>
            </div>
          </td></tr>
        </thead>
        <tbody>
          <tr><td style="padding:0;">
            <table class="table table-bordered table-sm align-middle print-table">
              <colgroup>
                <?php if ($isOOO): ?>
                  <col style="width: 60%;" />  <!-- Particulars -->
                  <col style="width: 40%;" />  <!-- Reason -->
                <?php elseif ($ldShow): ?>
                  <col style="width: 30%;" />  <!-- Particulars -->
                  <col style="width: 15%;" />  <!-- Location -->
                  <col style="width: 13%;" />  <!-- Quantity -->
                  <col style="width: 18%;" />  <!-- Lost/Damaged By -->
                  <col style="width: 14%;" />  <!-- Marked By -->
                  <col style="width: 10%;" />  <!-- Remarks -->
                <?php else: ?>
                  <col style="width: 35%;" />
                  <col style="width: 24%;" />
                  <col style="width: 12%;" />
                  <col style="width: 29%;" />
                <?php endif; ?>
              </colgroup>
              <thead class="table-light">
                <tr>
                  <th>Particulars</th>
                  <?php if ($isOOO): ?>
                    <th>Reason</th>
                  <?php else: ?>
                    <th>Location</th>
                    <th>Quantity</th>
                    <?php if ($ldShow): ?>
                      <?php $byLabel = ($filter_status === 'Lost') ? 'Lost By' : 'Damaged By'; ?>
                      <th><?php echo htmlspecialchars($byLabel); ?></th>
                      <th>Marked By</th>
                    <?php endif; ?>
                    <th>Remarks</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <tr><td colspan="<?php echo $isOOO ? 2 : ($ldShow ? 6 : 4); ?>" class="text-center text-muted py-4">No items found.</td></tr>
              </tbody>
            </table>
          </td></tr>
        </tbody>
        <tfoot>
          <tr><td style="padding:0;">
            <div class="container-fluid pb-3">
              <div class="eca-footer">
                <div class="field">
                  <label for="prepInput">Prepared by:</label>
                  <input id="prepInput" class="eca-input" type="text" placeholder="Enter name" value="<?php echo htmlspecialchars($prepared_by); ?>" />
                  <span id="prepPrintSpan" class="eca-print-value"></span>
                </div>
                <div class="field">
                  <label for="checkInput">Checked by:</label>
                  <input id="checkInput" class="eca-input" type="text" placeholder="Enter name" value="<?php echo htmlspecialchars($checked_by); ?>" />
                  <span id="checkPrintSpan" class="eca-print-value"></span>
                </div>
              </div>
            </div>
          </td></tr>
        </tfoot>
      </table>
    <?php else: ?>
      <?php foreach ($pages as $pi => $displayItems): ?>
        <?php $padRows = 20 - count($displayItems); ?>
        <table class="print-doc" style="width:100%; border-collapse:collapse;">
          <thead>
            <tr><td style="padding:0;">
              <div class="container-fluid pt-3">
                <div class="eca-header">
                  <div class="eca-title">ECA</div>
                  <div class="eca-sub">Exact Colleges of Asia, Inc.</div>
                </div>
                <div class="eca-meta">
                  <div class="form-no">Form No. <em>IF</em>/OO/Jun.2011</div>
                  <div></div>
                </div>
                <div class="report-title">INVENTORY REPORT</div>
                <div class="eca-form-row">
                  <div class="field">
                    <label for="deptInput">Department:</label>
                    <?php if ($pi === 0): ?>
                      <input id="deptInput" class="eca-input" type="text" placeholder="Enter department" value="<?php echo htmlspecialchars($department); ?>" />
                    <?php endif; ?>
                    <span id="deptPrintSpan" class="eca-print-value"></span>
                  </div>
                  <div class="field">
                    <label for="dateInput">Date:</label>
                    <?php if ($pi === 0): ?>
                      <input id="dateInput" class="eca-input date-field" type="text" placeholder="MM-DD-YYYY" inputmode="numeric" pattern="\d{2}-\d{2}-\d{4}" value="<?php echo htmlspecialchars($header_date_display); ?>" />
                    <?php endif; ?>
                    <span id="datePrintSpan" class="eca-print-value"></span>
                  </div>
                </div>
              </div>
            </td></tr>
          </thead>
          <tbody>
            <tr><td style="padding:0;">
              <table class="table table-bordered table-sm align-middle print-table">
                <colgroup>
                  <?php if ($isOOO): ?>
                    <col style="width: 60%;" />  <!-- Particulars -->
                    <col style="width: 40%;" />  <!-- Reason -->
                  <?php elseif ($ldShow): ?>
                    <col style="width: 30%;" />  <!-- Particulars -->
                    <col style="width: 15%;" />  <!-- Location -->
                    <col style="width: 13%;" />  <!-- Quantity -->
                    <col style="width: 18%;" />  <!-- Lost/Damaged By -->
                    <col style="width: 14%;" />  <!-- Marked By -->
                    <col style="width: 10%;" />  <!-- Remarks -->
                  <?php else: ?>
                    <col style="width: 35%;" />
                    <col style="width: 24%;" />
                    <col style="width: 12%;" />
                    <col style="width: 29%;" />
                  <?php endif; ?>
                </colgroup>
                <thead class="table-light">
                  <tr>
                    <th>Particulars</th>
                    <?php if ($isOOO): ?>
                      <th>Reason</th>
                    <?php else: ?>
                      <th>Location</th>
                      <th>Quantity</th>
                      <?php if ($ldShow): ?>
                        <?php $byLabel = ($filter_status === 'Lost') ? 'Lost By' : 'Damaged By'; ?>
                        <th><?php echo htmlspecialchars($byLabel); ?></th>
                        <th>Marked By</th>
                      <?php endif; ?>
                      <th>Remarks</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($displayItems as $it): ?>
                    <?php $catPrint = trim($it['category'] ?? '') !== '' ? $it['category'] : 'Uncategorized'; $part = $catPrint.' - '.(string)($it['item_name'] ?? ''); ?>
                    <tr>
                      <td><?php echo htmlspecialchars($part); ?></td>
                      <?php if ($isOOO): ?>
                        <?php $st = (string)($it['status'] ?? ''); $reason = ($st==='Lost') ? 'all items are lost' : ((($st==='Under Maintenance'||$st==='Damaged')) ? 'all items are damaged' : ((($st==='Borrowed'||$st==='In Use')) ? 'all items are in use' : '')); ?>
                        <td><?php echo htmlspecialchars($reason); ?></td>
                      <?php else: ?>
                        <td><?php echo htmlspecialchars((string)($it['location'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($it['quantity'] ?? '')); ?></td>
                        <?php if ($ldShow): ?>
                          <?php $mid = (int)($it['id'] ?? 0); $e = isset($ldExtraMap) && isset($ldExtraMap[$mid]) ? $ldExtraMap[$mid] : null; $by = $e ? (trim((string)($e['by_full_name'] ?? '')) ?: trim((string)($e['by_username'] ?? ''))) : ''; $mk = $e ? (trim((string)($e['marker_full_name'] ?? '')) ?: trim((string)($e['marker_username'] ?? ''))) : ''; ?>
                          <td><?php echo htmlspecialchars($by); ?></td>
                          <td><?php echo htmlspecialchars($mk); ?></td>
                        <?php endif; ?>
                        <td></td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                  <?php for ($i = 0; $i < $padRows; $i++): ?>
                    <tr>
                      <td>&nbsp;</td>
                      <?php if ($isOOO): ?>
                        <td>&nbsp;</td>
                      <?php else: ?>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <?php if ($ldShow): ?>
                          <td>&nbsp;</td>
                          <td>&nbsp;</td>
                        <?php endif; ?>
                        <td>&nbsp;</td>
                      <?php endif; ?>
                    </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </td></tr>
          </tbody>
          <tfoot>
            <tr><td style="padding:0;">
              <div class="container-fluid pb-3">
                <div class="eca-footer">
                  <div class="field">
                    <label for="prepInput">Prepared by:</label>
                    <?php if ($pi === 0): ?>
                      <input id="prepInput" class="eca-input" type="text" placeholder="Enter name" value="<?php echo htmlspecialchars($prepared_by); ?>" />
                    <?php endif; ?>
                    <span id="prepPrintSpan" class="eca-print-value"></span>
                  </div>
                  <div class="field">
                    <label for="checkInput">Checked by:</label>
                    <?php if ($pi === 0): ?>
                      <input id="checkInput" class="eca-input" type="text" placeholder="Enter name" value="<?php echo htmlspecialchars($checked_by); ?>" />
                    <?php endif; ?>
                    <span id="checkPrintSpan" class="eca-print-value"></span>
                  </div>
                </div>
              </div>
            </td></tr>
          </tfoot>
        </table>
        <?php if ($pi < count($pages) - 1): ?>
          <div class="page-break"></div>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>

    
  </div>

  <script>
    document.getElementById('applyBtn')?.addEventListener('click', function(){
      var dept = document.getElementById('deptInput')?.value || '';
      var date = document.getElementById('dateInput')?.value || '';
      var prepared = document.getElementById('prepInput')?.value || '';
      var checked = document.getElementById('checkInput')?.value || '';
      var url = new URL(window.location.href);
      var p = url.searchParams;
      if (dept) p.set('department', dept); else p.delete('department');
      if (date) p.set('date', date); else p.delete('date');
      if (prepared) p.set('prepared_by', prepared); else p.delete('prepared_by');
      if (checked) p.set('checked_by', checked); else p.delete('checked_by');
      url.search = p.toString();
      window.location.href = url.toString();
    });
    document.getElementById('doPrintBtn')?.addEventListener('click', function(){
      try { syncHeaderMirror(); } catch(_){ }
      window.print();
    });
    
    function syncHeaderMirror(){
      var dept = document.getElementById('deptInput')?.value || '';
      var date = document.getElementById('dateInput')?.value || '';
      try { document.querySelectorAll('[id="deptPrintSpan"]').forEach(function(el){ el.textContent = (dept && dept.trim() !== '') ? dept : '\u00A0'; }); } catch(_){ }
      try { document.querySelectorAll('[id="datePrintSpan"]').forEach(function(el){ el.textContent = (date && date.trim() !== '') ? date : '\u00A0'; }); } catch(_){ }
      var prepared = document.getElementById('prepInput')?.value || '';
      var checked = document.getElementById('checkInput')?.value || '';
      try { document.querySelectorAll('[id="prepPrintSpan"]').forEach(function(el){ el.textContent = (prepared && prepared.trim() !== '') ? prepared : '\u00A0'; }); } catch(_){ }
      try { document.querySelectorAll('[id="checkPrintSpan"]').forEach(function(el){ el.textContent = (checked && checked.trim() !== '') ? checked : '\u00A0'; }); } catch(_){ }
    }
    document.addEventListener('DOMContentLoaded', function(){
      syncHeaderMirror();
      var di = document.getElementById('deptInput');
      var dt = document.getElementById('dateInput');
      var pi = document.getElementById('prepInput');
      var ci = document.getElementById('checkInput');
      if (di) di.addEventListener('input', syncHeaderMirror);
      if (dt) dt.addEventListener('input', syncHeaderMirror);
      if (pi) pi.addEventListener('input', syncHeaderMirror);
      if (ci) ci.addEventListener('input', syncHeaderMirror);
      window.addEventListener('beforeprint', syncHeaderMirror);
    });
  </script>
</body>
</html>
