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
    header("Location: index.php");
    exit();
}

// Default names for Prepared by / Checked by
$preparedByDefault = (string)($_SESSION['username'] ?? '');
try {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  $dbTmp = get_mongo_db();
  $uDoc = $dbTmp->selectCollection('users')->findOne(['username' => ($_SESSION['username'] ?? '')], ['projection' => ['full_name' => 1]]);
  $full = $uDoc && isset($uDoc['full_name']) ? trim((string)$uDoc['full_name']) : '';
  if ($full !== '') { $preparedByDefault = $full; }
} catch (Throwable $e) { /* ignore and keep username */ }

// Optional MongoDB config
@require_once __DIR__ . '/config.php';
// Enable Mongo read path for this page
if (!defined('USE_MONGO')) { define('USE_MONGO', true); }

// Filters (reuse keys from inventory.php)
$search_q = trim($_GET['q'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
// Normalize status filter to match stored values
if ($filter_status === 'Maintenance') { $filter_status = 'Under Maintenance'; }
$filter_category = trim($_GET['category'] ?? '');
$filter_condition = trim($_GET['condition'] ?? '');
$filter_supply = trim($_GET['supply'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

$isAdmin = (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin');

// QR print mode flags
$qrMode = (isset($_GET['qr']) && $_GET['qr'] == '1');
$autoPrint = (isset($_GET['autoprint']) && $_GET['autoprint'] == '1');

// Try MongoDB read path (feature flag) before building MySQL queries
$usedMongo = false;
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
    if ($filter_status !== '') {
      if ($filter_status === 'Out of Order') {
        $match['quantity'] = 0;
      } else {
        $match['status'] = $filter_status;
      }
    }
    if ($filter_category !== '') { $match['category'] = $filter_category; }
    if ($filter_condition !== '') { $match['condition'] = $filter_condition; }
    if ($filter_supply !== '') {
      if ($filter_supply === 'low') { $match['quantity'] = ['$lt' => 10]; }
      elseif ($filter_supply === 'average') { $match['quantity'] = ['$gt' => 10, '$lt' => 50]; }
      elseif ($filter_supply === 'high') { $match['quantity'] = ['$gt' => 50]; }
    }
    // Basic date filter if field exists as string/ISODate; kept simple
    // Building list
    $items = [];
    // Always exclude Permanently Lost from print listing/search
    if (!isset($match['status'])) {
      $match['status'] = ['$ne' => 'Permanently Lost'];
    } else {
      if (is_string($match['status']) && $match['status'] === 'Permanently Lost') { $match['status'] = ['$ne'=>'Permanently Lost']; }
      elseif (is_array($match['status']) && isset($match['status']['$in'])) {
        $match['status']['$in'] = array_values(array_filter($match['status']['$in'], function($s){ return (string)$s !== 'Permanently Lost'; }));
        if (empty($match['status']['$in'])) { $match['status'] = ['$ne'=>'Permanently Lost']; }
      }
    }
    $cursor = $db->selectCollection('inventory_items')->find($match, [
      'sort' => ['category' => 1, 'item_name' => 1, 'id' => 1],
    ]);
    foreach ($cursor as $doc) {
      $items[] = [
        'id' => intval($doc['id'] ?? 0),
        'item_name' => (string)($doc['item_name'] ?? ''),
        'serial_no' => (string)($doc['serial_no'] ?? ''),
        'category' => (string)($doc['category'] ?? ''),
        'location' => (string)($doc['location'] ?? ''),
        'condition' => (string)($doc['condition'] ?? ''),
        'status' => (string)($doc['status'] ?? ''),
        'date_acquired' => (string)($doc['date_acquired'] ?? ''),
      ];
    }
    // When listing Lost, Damaged, or Under Maintenance, enrich with marker and responsible borrower details
    $ldExtraMap = [];
    $needLD = in_array($filter_status, ['Lost','Under Maintenance','Damaged'], true);
    if ($needLD && !empty($items)) {
      $modelIds = array_values(array_unique(array_map(function($r){ return (int)($r['id'] ?? 0); }, $items)));
      // Build map of user full names for quick lookup
      $uCol = $db->selectCollection('users');
      $userNameToFull = function($uname) use ($uCol) {
        $uname = (string)$uname;
        if ($uname === '') return '';
        try { $u = $uCol->findOne(['username'=>$uname], ['projection'=>['full_name'=>1]]); } catch (Throwable $e) { $u = null; }
        $fn = $u && isset($u['full_name']) ? (string)$u['full_name'] : '';
        return $fn !== '' ? $fn : $uname;
      };
      // Marker (who marked Lost/Maintenance)
      $ldCol = $db->selectCollection('lost_damaged_log');
      $ldAction = ($filter_status === 'Damaged') ? 'Under Maintenance' : $filter_status;
      $ubCol = $db->selectCollection('user_borrows');
      foreach ($modelIds as $mid) {
        $log = null;
        try { $log = $ldCol->findOne(['model_id'=>$mid, 'action'=> $ldAction], ['sort'=>['id'=>-1]]); } catch (Throwable $e) { $log = null; }
        $markerUser = $log && isset($log['username']) ? (string)$log['username'] : '';
        $markerFull = $markerUser !== '' ? $userNameToFull($markerUser) : '';
        $ldExtraMap[$mid] = [ 'marker_username' => $markerUser, 'marker_full_name' => $markerFull, 'by_username' => '', 'by_full_name' => '' ];
        if ($log && isset($log['created_at'])) {
          $logTs = (string)$log['created_at'];
          try {
            $cur = $ubCol->find(['model_id'=>$mid, 'returned_at' => ['$ne'=>null]], ['sort'=>['returned_at'=>-1,'id'=>-1]]);
          } catch (Throwable $e) { $cur = []; }
          foreach ($cur as $ub) {
            $ret = (string)($ub['returned_at'] ?? '');
            if ($ret !== '' && $ret <= $logTs) {
              $uname = (string)($ub['username'] ?? '');
              $ldExtraMap[$mid]['by_username'] = $uname;
              $ldExtraMap[$mid]['by_full_name'] = $userNameToFull($uname);
              break;
            }
          }
        }
      }
    }
    // Apply date range filter client-side for Mongo results
    if ($date_from !== '' || $date_to !== '') {
      $fromTs = $date_from !== '' ? strtotime($date_from.' 00:00:00') : null;
      $toTs   = $date_to   !== '' ? strtotime($date_to.' 23:59:59')   : null;
      $items = array_values(array_filter($items, function($r) use ($fromTs, $toTs) {
        $ds = trim((string)($r['date_acquired'] ?? ''));
        if ($ds === '') return false;
        $ts = strtotime($ds);
        if ($ts === false) return false;
        if ($fromTs !== null && $ts < $fromTs) return false;
        if ($toTs   !== null && $ts > $toTs)   return false;
        return true;
      }));
    }
    // Load categories for filter selects (prefer categories collection, fallback to items)
    $categoryOptions = [];
    $catCur = $db->selectCollection('categories')->find([], ['sort' => ['name' => 1], 'projection' => ['name' => 1]]);
    foreach ($catCur as $c) { if (!empty($c['name'])) { $categoryOptions[] = (string)$c['name']; } }
    if (empty($categoryOptions)) {
      $tmp = [];
      foreach ($items as $it) { $nm = trim($it['category'] ?? '') !== '' ? $it['category'] : 'Uncategorized'; $tmp[$nm] = true; }
      $categoryOptions = array_keys($tmp);
      natcasesort($categoryOptions);
      $categoryOptions = array_values($categoryOptions);
    }
    // Borrow history (best-effort with lookups for display fields)
    $borrow_history = [];
    $reservationMode = (strcasecmp($filter_status, 'Reservation') === 0) || (strcasecmp($filter_status, 'Reserved') === 0);
    if ($isAdmin) {
      $iiCol = $db->selectCollection('inventory_items');
      $uCol  = $db->selectCollection('users');
      if (!$reservationMode) {
        // Only BORROW history
        $ubCol = $db->selectCollection('user_borrows');
        $bhCur = $ubCol->find([], ['sort' => ['borrowed_at' => -1, 'id' => -1], 'limit' => 500]);
        foreach ($bhCur as $bh) {
          $row = (array)$bh;
          $username = (string)($row['username'] ?? '');
          $user_id = '';
          $full_name = '';
          $school_id = '';
          if ($username !== '') {
            try { $u = $uCol->findOne(['username'=>$username], ['projection'=>['id'=>1,'full_name'=>1,'school_id'=>1]]); } catch (Throwable $e) { $u = null; }
            if ($u) { $user_id = (string)($u['id'] ?? ''); $full_name = (string)($u['full_name'] ?? ''); $school_id = (string)($u['school_id'] ?? ''); }
          }
          if ($full_name === '' && $username !== '') { $full_name = $username; }
          $model_id = intval($row['model_id'] ?? 0);
          $model_name = '';
          $category = '';
          if ($model_id > 0) {
            try { $itm = $iiCol->findOne(['id'=>$model_id], ['projection'=>['model'=>1,'item_name'=>1,'category'=>1,'serial_no'=>1]]); } catch (Throwable $e) { $itm = null; }
            if ($itm) {
              $mn = trim((string)($itm['model'] ?? ''));
              $model_name = $mn !== '' ? $mn : (string)($itm['item_name'] ?? '');
              $category = trim((string)($itm['category'] ?? ''));
              $row['serial_no'] = (string)($itm['serial_no'] ?? '');
            }
          }
          if ($category === '') { $category = 'Uncategorized'; }
          $row['user_id'] = $user_id;
          $row['school_id'] = $school_id;
          $row['full_name'] = $full_name;
          $row['model_name'] = $model_name;
          $row['category'] = $category;
          $borrow_history[] = $row;
        }
      } else {
        // Only RESERVATION history (any status/time)
        try {
          $erCol = $db->selectCollection('equipment_requests');
          $resCur = $erCol->find(['type' => 'reservation'], ['sort' => ['reserved_from' => -1, 'id' => -1]]);
          foreach ($resCur as $rr) {
            $row = [];
            $username = (string)($rr['username'] ?? '');
            $user_id = '';
            $full_name = '';
            $school_id = '';
            if ($username !== '') {
              try { $u = $uCol->findOne(['username'=>$username], ['projection'=>['id'=>1,'full_name'=>1,'school_id'=>1]]); } catch (Throwable $e) { $u = null; }
              if ($u) { $user_id = (string)($u['id'] ?? ''); $full_name = (string)($u['full_name'] ?? ''); $school_id = (string)($u['school_id'] ?? ''); }
            }
            if ($full_name === '' && $username !== '') { $full_name = $username; }
            $model_id = intval($rr['reserved_model_id'] ?? 0);
            $model_name = '';
            $category = '';
            $serial_no = (string)($rr['reserved_serial_no'] ?? '');
            if ($model_id > 0) {
              try { $itm = $iiCol->findOne(['id'=>$model_id], ['projection'=>['model'=>1,'item_name'=>1,'category'=>1,'serial_no'=>1]]); } catch (Throwable $e) { $itm = null; }
              if ($itm) {
                $mn = trim((string)($itm['model'] ?? ''));
                $model_name = $mn !== '' ? $mn : (string)($itm['item_name'] ?? '');
                $category = trim((string)($itm['category'] ?? ''));
                if ($serial_no === '') { $serial_no = (string)($itm['serial_no'] ?? ''); }
              }
            } else {
              $reqName = (string)($rr['item_name'] ?? '');
              $model_name = $reqName;
              try { $itm = $iiCol->findOne(['$or' => [['model'=>$reqName], ['item_name'=>$reqName]]], ['projection'=>['category'=>1]]); } catch (Throwable $e) { $itm = null; }
              if ($itm) { $category = trim((string)($itm['category'] ?? '')); }
            }
            if ($category === '') { $category = 'Uncategorized'; }
            $row['username'] = $username;
            $row['user_id'] = $user_id;
            $row['school_id'] = $school_id;
            $row['full_name'] = $full_name;
            $row['model_id'] = $model_id;
            $row['model_name'] = $model_name;
            $row['category'] = $category;
            $row['serial_no'] = $serial_no;
            $row['status'] = (string)($rr['status'] ?? 'Reserved');
            $row['borrowed_at'] = (string)($rr['reserved_from'] ?? '');
            $row['returned_at'] = (string)($rr['reserved_to'] ?? '');
            $borrow_history[] = $row;
          }
        } catch (Throwable $e) { /* ignore */ }
      }
      // Sort by start date desc
      usort($borrow_history, function($a,$b){
        $ta = strtotime((string)($a['borrowed_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['borrowed_at'] ?? '')) ?: 0;
        if ($ta === $tb) { return 0; }
        return ($ta > $tb) ? -1 : 1;
      });
    }
    $usedMongo = true;
  } catch (Throwable $e) {
    $usedMongo = false;
  }
}

if (!$usedMongo) {
    $items = [];
    $categoryOptions = [];
}

// Category ID or Name search. Build mapping from current item categories.
$cat_id_raw = trim($_GET['cat_id'] ?? '');
$catIdFilters = [];
$catNameGroups = [];
if ($cat_id_raw !== '') {
    $groups = preg_split('/\s*,\s*/', strtolower($cat_id_raw));
    foreach ($groups as $g) {
        $g = trim($g);
        if ($g === '') continue;
        $tokens = preg_split('/[\/\s]+/', $g);
        $needles = [];
        foreach ($tokens as $t) {
            $t = trim($t);
            if ($t === '') continue;
            if (preg_match('/^(?:cat-)?(\d{1,})$/', $t, $m)) {
                $num = intval($m[1]);
                if ($num > 0) { $catIdFilters[] = sprintf('CAT-%03d', $num); }
            } else {
                $needles[] = strtolower($t);
            }
        }
        if (!empty($needles)) { $catNameGroups[] = $needles; }
    }
}

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

// Apply category filter: by CAT-XXX and/or by category name groups
if (!empty($catIdFilters) || !empty($catNameGroups)) {
    $items = array_values(array_filter($items, function($row) use ($catIdByName, $catIdFilters, $catNameGroups) {
        $cat = trim($row['category'] ?? '') !== '' ? $row['category'] : 'Uncategorized';
        $cid = $catIdByName[$cat] ?? '';
        if (!empty($catIdFilters) && in_array($cid, $catIdFilters, true)) { return true; }
        if (!empty($catNameGroups)) {
            foreach ($catNameGroups as $grp) {
                $all = true;
                foreach ($grp as $n) {
                    if ($n === '') { continue; }
                    $pat = '/(?<![A-Za-z0-9])' . preg_quote($n, '/') . '(?![A-Za-z0-9])/i';
                    if (!preg_match($pat, (string)$cat)) { $all = false; break; }
                }
                if ($all) { return true; }
            }
        }
        return false;
    }));
}

// Serial search: Serial ID or Name. Commas separate groups (OR), spaces separate tokens (AND within a group).
$serial_id_search_raw = trim($_GET['sid'] ?? ($_GET['mid'] ?? ''));
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
        $items = array_values(array_filter($items, function($row) use ($tokenGroups) {
            $serial = strtolower((string)($row['serial_no'] ?? ''));
            $name = strtolower((string)($row['item_name'] ?? ''));
            $hay = $serial . ' ' . $name;
            foreach ($tokenGroups as $grp) {
                $all = true;
                foreach ($grp as $n) {
                    if ($n === '') { continue; }
                    // Enforce whole-token (non-alphanumeric boundary) matching
                    $pat = '/(?<![A-Za-z0-9])' . preg_quote($n, '/') . '(?![A-Za-z0-9])/i';
                    if (!preg_match($pat, $hay)) { $all = false; break; }
                }
                if ($all) { return true; }
            }
            return false;
        }));
    }
}

// Location search (group-sensitive)
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

// Sort by Category ID descending (e.g., CAT-010 above CAT-002; CAT-001 at the bottom)
usort($items, function($a, $b) use ($catIdByName) {
    $ca = trim($a['category'] ?? '') !== '' ? $a['category'] : 'Uncategorized';
    $cb = trim($b['category'] ?? '') !== '' ? $b['category'] : 'Uncategorized';
    $ida = $catIdByName[$ca] ?? 'CAT-000';
    $idb = $catIdByName[$cb] ?? 'CAT-000';
    // Descending order
    return strcmp($idb, $ida);
});

$scrollClass = (count($items) > 10) ? ' table-scroll' : '';
// Determine if extra Lost/Maintenance columns should be shown
$ldShow = in_array($filter_status, ['Lost','Under Maintenance'], true);

// Admin Borrow History (for print below inventory list)
$borrow_history = $borrow_history ?? [];
if ($isAdmin && $usedMongo) {
  // Already populated above in Mongo-first block; no-op to avoid duplicates.
}

// No MySQL connection to close
// Add scroll class for Borrow History if entries are 13 or more
$borrowScrollClass = (count($borrow_history) >= 13) ? ' table-scroll' : '';
?>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Print Inventory</title>
  <link href="css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" />
  <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__.'/css/style.css'); ?>">
  <style>
    @page { size: A4 portrait; margin: 1in; }
    @media print {
      .no-print { display: none !important; }
      html, body { margin: 0 !important; background: #ffffff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      /* Force clean white page with black text */
      * { background: #ffffff !important; background-color: #ffffff !important; background-image: none !important; color: #000000 !important; box-shadow: none !important; }
      *, *::before, *::after { opacity: 1 !important; filter: none !important; -webkit-filter: none !important; text-shadow: none !important; }
      body, #page-content-wrapper, .container-fluid { color: #000000 !important; }
      /* Do not print active modals or backdrops */
      .modal, .modal-backdrop { display: none !important; visibility: hidden !important; }
      /* Re-allow images without tint */
      img { background: transparent !important; }
      table { page-break-inside: auto; }
      tr { page-break-inside: avoid; page-break-after: auto; }
      thead { display: table-header-group; }
      tfoot { display: table-footer-group; }
      .table-scroll { max-height: none !important; overflow: visible !important; }
      /* Ensure consistent table layout and alignment during print */
      .print-table { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 10px; }
      .print-table th, .print-table td { padding: .2rem .3rem; vertical-align: middle; line-height: 1.2; text-align: left; }
      .print-table th { font-weight: 700 !important; color: #000000 !important; }
      .print-table td { font-weight: 500 !important; color: #000000 !important; }
      .print-table th { white-space: normal; word-break: break-word; overflow-wrap: anywhere; }
      .print-table td { word-break: break-word; overflow-wrap: anywhere; }
      /* Datetime two-line layout for borrow history */
      .print-table .col-datetime { white-space: normal; font-size: 10px; }
      .dt { display: inline-block; max-width: 100%; white-space: nowrap; }
      .col-datetime .dt .dt-date,
      .col-datetime .dt .dt-time { display: block; }
      .col-datetime .dt { line-height: 1.35; min-height: calc(1.35em * 2); white-space: nowrap; }
      /* Remove shaded backgrounds for Bootstrap tables explicitly */
      .table, .table th, .table td, .table-light, .table-striped tbody tr:nth-of-type(odd), thead th { background-color: #ffffff !important; background-image: none !important; }
      #page-content-wrapper, .container-fluid, .eca-print-header { background: #ffffff !important; }
      .eca-title, .eca-sub, .report-title, .eca-form-row label { color: #000000 !important; }
      /* Hide calendar icon for date input in print */
      .eca-input[type="date"]::-webkit-calendar-picker-indicator { display: none !important; opacity: 0 !important; }
      .eca-input[type="date"] { -webkit-appearance: none; appearance: none; background: transparent !important; }
      /* Hide placeholders and default date hint when empty in print */
      .eca-input::placeholder { color: transparent !important; }
      .eca-input:placeholder-shown { color: #000000 !important; }
      /* WebKit date empty hint */
      .eca-input[type="date"] { color: transparent !important; -webkit-text-fill-color: transparent !important; }
      .eca-input[type="date"]:valid { color: #000000 !important; -webkit-text-fill-color: #000000 !important; }
      .eca-input[type="date"]::-webkit-datetime-edit { color: transparent !important; }
      .eca-input[type="date"]:valid::-webkit-datetime-edit { color: #000000 !important; }
      .eca-input[type="date"]::-webkit-inner-spin-button,
      .eca-input[type="date"]::-webkit-clear-button { display: none !important; }
      /* Firefox */
      @-moz-document url-prefix() {
        .eca-input[type="date"] { -moz-appearance: textfield; }
        .eca-input::placeholder { color: transparent !important; }
      }
      /* Keep date input visible in print and remove calendar icon via other rules. */
      .eca-form-row .field { align-items: baseline !important; }
      /* Prevent blank first page by normalizing header block inside print wrapper */
      .print-doc { width: 100% !important; border-collapse: collapse !important; border-spacing: 0 !important; }
      .print-doc thead td, .print-doc thead th { padding: 0 !important; }
      .print-doc thead tr:first-child { page-break-before: avoid !important; break-before: avoid-page !important; }
      .print-doc .container-fluid { margin: 0 !important; padding-top: 6mm !important; }
      /* Reduce risk of flex-based pagination quirks (scope only inside print wrapper) */
      .print-doc .d-flex { display: block !important; }
      #page-content-wrapper { width: 100% !important; }
    }

    /* Scrollable table wrapper with sticky header */
    .table-scroll {
      max-height: 480px; /* approx 10 rows */
      overflow-y: auto;
    }
    .table-scroll table { margin-bottom: 0; }
    .table-responsive { position: relative; }
    .table-scroll thead th,
    .table-responsive thead th {
      position: sticky;
      top: 0;
      background: #f8f9fa; /* match .table-light */
      z-index: 2;
      border-bottom: 2px solid #dee2e6;
      color: #212529; /* ensure visible text color */
    }

    /* Screen: compact table row heights */
    .print-table th,
    .print-table td {
      padding: .35rem .5rem;
      line-height: 1.2;
    }
    .print-table thead th {
      padding-top: .30rem;
      padding-bottom: .30rem;
      font-weight: 600;
    }
    /* Screen datetime two-line layout */
    .print-table .col-datetime { white-space: normal; }
    .print-table .col-datetime .dt { display: inline-block; line-height: 1.35; min-height: calc(1.35em * 2); white-space: nowrap; }
    .print-table .col-datetime .dt .dt-date,
    .print-table .col-datetime .dt .dt-time { display: block; }

    /* One-line toolbar for header controls */
    .toolbar { white-space: nowrap; font-size: 0.8rem; overflow: visible; }
    .toolbar > * { flex: 0 0 auto; }
    .toolbar form { margin-bottom: 0; }
    .toolbar .input-group { width: 140px; }
    .toolbar .form-control, .toolbar .btn, .toolbar .dropdown-menu { font-size: 0.8rem; }
    .toolbar .dropdown .btn { white-space: nowrap; }

    /* Fixed sidebar and scrollable content (screen only) */
    @media screen {
      html, body { height: 100%; }
      body { overflow: hidden; }
      #sidebar-wrapper { position: sticky; top: 0; height: 100vh; overflow: hidden; }
      #page-content-wrapper { flex: 1 1 auto; height: 100vh; overflow: auto; }
      @media (max-width: 768px) {
        body { overflow: auto; }
        #page-content-wrapper { height: auto; overflow: visible; }
      }
    }

    /* New permanent header (print-only) */
    .eca-header { text-align: center; margin-bottom: 10px; }
    .eca-header .eca-title { font-weight: 400; letter-spacing: 6px; font-size: 14pt; }
    .eca-header .eca-sub { margin-top: 2px; font-weight: 600; font-size: 12pt; }
    .eca-meta { display: flex; align-items: center; justify-content: space-between; font-size: 9pt; margin-top: 6px; margin-bottom: 10px; }
    .eca-meta .form-no { font-style: italic; }
    .report-title { text-align: center; font-weight: 400; font-size: 14pt; margin: 14px 0 8px; text-transform: uppercase; }
    .eca-form-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
    .eca-form-row .field { display: flex; align-items: center; gap: 8px; }
    .eca-form-row label { font-weight: 600; font-size: 10pt; }
    .eca-input { border: none; border-bottom: 1px solid #000; outline: none; padding: 2px 4px; min-width: 200px; font-size: 10pt; }
    .eca-input[type="date"] { min-width: 160px; }
    /* Remove native calendar UI for date inputs (screen as well) */
    .eca-input[type="date"]::-webkit-calendar-picker-indicator { display: none !important; opacity: 0 !important; -webkit-appearance: none !important; }
    .eca-input[type="date"]::-webkit-inner-spin-button,
    .eca-input[type="date"]::-webkit-clear-button { display: none !important; }
    @-moz-document url-prefix() {
      .eca-input[type="date"] { -moz-appearance: textfield; }
    }
    /* Screen-only: hide the print mirror value */
    /* Print-only value mirror for inputs */
    .eca-print-value { display: inline-block; min-width: 160px; border-bottom: 1px solid #000; padding: 2px 4px; color: #000; position: relative; top: 4px; }
    @media screen { .eca-date-print, .eca-print-value { display: none; }
    }
    .eca-footer { margin-top: 20mm; }
    .eca-footer .field { display: inline-flex; align-items: center; gap: 8px; margin-right: 24mm; }
    .eca-footer label { font-weight: 600; font-size: 10pt; }
    @media print {
      .no-print { display: none !important; }
      #page-content-wrapper { padding-top: 0 !important; }
      .eca-input { border: none; border-bottom: 1px solid #000; }
      /* In print, hide header inputs and show their mirrored spans so values duplicate each page */
      #deptInput, #dateInput { display: none !important; }
      .eca-date-print, .eca-print-value { display: inline-block !important; }
      /* Hide app chrome in print */
      .page-header { display: none !important; }
      .toolbar { display: none !important; }
      /* Hide legacy print header block; we'll use a repeating thead instead */
      .eca-print-header { display: none !important; }
      /* Ensure our print wrapper repeats the header */
      .print-doc thead { display: table-header-group; }
      .print-doc tbody { display: table-row-group; }
    }
    @media screen {
      /* Hide print header on screen */
      .eca-print-header { display: none; }
      /* Only hide the top-level print wrapper header, not inner data-table headers */
      .print-doc > thead { display: none; }
      /* Utility: elements that should only appear in print */
      .only-print { display: none !important; }
    }
  </style>
</head>
<body>
  <!-- Legacy header kept for screen-only view; hidden in print via CSS above -->
  <div class="container-fluid pt-3 eca-print-header">
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
        <input id="deptInput" class="eca-input" type="text" placeholder="Enter department" value="<?php echo htmlspecialchars($_GET['department'] ?? ''); ?>" />
        <span id="deptPrintSpan" class="eca-print-value"></span>
      </div>
      <div class="field">
        <label for="dateInput">Date:</label>
        <input id="dateInput" class="eca-input" type="date" value="<?php echo htmlspecialchars($_GET['date'] ?? ''); ?>" />
        <span id="datePrintSpan" class="eca-date-print eca-print-value"></span>
      </div>
    </div>
    <div class="no-print" style="text-align:right; margin-top:-6px; margin-bottom:8px;">
      <button type="button" id="ecaApplyBtn" class="btn btn-sm btn-outline-secondary">Apply to URL</button>
    </div>
  </div>
  <!-- Mobile Menu Toggle Button -->
  <button class="mobile-menu-toggle d-md-none no-print" onclick="toggleSidebar()">
    <i class="bi bi-list"></i>
  </button>

  <div class="d-flex">
    <!-- Sidebar -->
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
          <a href="inventory.php" class="list-group-item list-group-item-action bg-transparent">
            <i class="bi bi-box-seam me-2"></i>Inventory
          </a>
          <a href="inventory_print.php<?php echo (!empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''); ?>" class="list-group-item list-group-item-action bg-transparent fw-bold">
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
          <a href="inventory.php" class="list-group-item list-group-item-action bg-transparent">
            <i class="bi bi-box-seam me-2"></i>Inventory
          </a>
          <a href="inventory_print.php<?php echo (!empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''); ?>" class="list-group-item list-group-item-action bg-transparent fw-bold">
            <i class="bi bi-printer me-2"></i>Print Inventory
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

    <!-- Page Content -->
    <div class="p-4 w-100" id="page-content-wrapper">
      <div class="container-fluid py-3">
        <div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 no-print mb-2">
          <h2 class="page-title mb-0"><i class="bi bi-printer me-2"></i>Print Inventory</h2>
          <div class="d-flex align-items-center gap-3 ms-auto">
            <?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
              <div class="position-relative" id="adminBellWrap">
                <button class="btn btn-light position-relative" id="adminBellBtn" title="Notifications">
                  <i class="bi bi-bell" style="font-size:1.2rem;"></i>
                  <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle d-none" id="adminBellDot"></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow" id="adminBellDropdown" style="min-width: 320px; max-height: 360px; overflow:auto;">
                  <div class="px-3 py-2 border-bottom fw-bold small">Pending Borrow Requests</div>
                  <div id="adminNotifList" class="list-group list-group-flush small"></div>
                  <div class="text-center small text-muted py-2 d-none" id="adminNotifEmpty">No notifications.</div>
                  <div class="border-top p-2 text-center">
                    <a href="admin_borrow_center.php" class="btn btn-sm btn-outline-primary">Go to Borrow Requests</a>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="toolbar d-flex align-items-center gap-2 flex-nowrap w-100">
            <!-- Toggle button for search modal -->
            <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#searchModal">
              <i class="bi bi-search me-1"></i>Search
            </button>

            <!-- Main filter dropdown (status/category/condition/supply) -->
            <form method="GET" class="d-flex align-items-center gap-2">
              <!-- Filter dropdown (status/category/condition/supply) -->
              <div class="dropdown">
                <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static" data-bs-container="body" aria-expanded="false">
                  <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <div class="dropdown-menu p-3" style="min-width: 280px;">
                  <div class="mb-2">
                    <label class="form-label mb-1">Status</label>
                    <select name="status" class="form-select">
                      <option value="">Status</option>
                      <?php
                        // Desired order
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
                  <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2 me-1"></i>Apply</button>
                    <a href="inventory_print.php" class="btn btn-outline-secondary w-100">Reset</a>
                  </div>
                </div>
              </div>
            </form>

            <!-- Date range filter in separate form to preserve others -->
            <form method="GET" class="d-flex align-items-center gap-2">
              <div class="dropdown">
                <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static" data-bs-container="body" aria-expanded="false">
                  <i class="bi bi-calendar-range me-1"></i>Filter Date
                </button>
                <div class="dropdown-menu p-3" style="min-width: 280px;">
                  <div class="mb-2">
                    <label class="form-label mb-1">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from ?? ''); ?>" />
                  </div>
                  <div class="mb-2">
                    <label class="form-label mb-1">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to ?? ''); ?>" />
                  </div>
                  <div class="d-flex gap-2">
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2 me-1"></i>Apply</button>
                    <button class="btn btn-outline-secondary w-100" type="button" onclick="const f=this.closest('form'); f.querySelector('[name=\'date_from\']').value=''; f.querySelector('[name=\'date_to\']').value=''; f.submit();">Reset</button>
                  </div>
                </div>
              </div>
              <!-- Preserve other filters when submitting date -->
              <input type="hidden" name="q" value="<?php echo htmlspecialchars($search_q ?? ''); ?>" />
              <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status ?? ''); ?>" />
              <input type="hidden" name="category" value="<?php echo htmlspecialchars($filter_category ?? ''); ?>" />
              <input type="hidden" name="condition" value="<?php echo htmlspecialchars($filter_condition ?? ''); ?>" />
              <input type="hidden" name="supply" value="<?php echo htmlspecialchars($filter_supply ?? ''); ?>" />
              <input type="hidden" name="sid" value="<?php echo htmlspecialchars($serial_id_search_raw ?? ''); ?>" />
              <input type="hidden" name="loc" value="<?php echo htmlspecialchars($location_search_raw ?? ''); ?>" />
            </form>
            <a class="btn btn-outline-secondary btn-sm" href="inventory_print.php" title="Reset filters">Reset</a>
            <button class="btn btn-primary btn-sm" type="button" id="openPrintModalBtn"><i class="bi bi-printer me-1"></i>Print</button>
            <form method="GET" class="d-inline-block ms-2">
              <!-- Preserve filters -->
              <input type="hidden" name="q" value="<?php echo htmlspecialchars($search_q ?? ''); ?>" />
              <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status ?? ''); ?>" />
              <input type="hidden" name="category" value="<?php echo htmlspecialchars($filter_category ?? ''); ?>" />
              <input type="hidden" name="condition" value="<?php echo htmlspecialchars($filter_condition ?? ''); ?>" />
              <input type="hidden" name="supply" value="<?php echo htmlspecialchars($filter_supply ?? ''); ?>" />
              <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from ?? ''); ?>" />
              <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to ?? ''); ?>" />
              <input type="hidden" name="cat_id" value="<?php echo htmlspecialchars($_GET['cat_id'] ?? ''); ?>" />
              <input type="hidden" name="sid" value="<?php echo htmlspecialchars($_GET['sid'] ?? ($_GET['mid'] ?? '')); ?>" />
              <input type="hidden" name="loc" value="<?php echo htmlspecialchars($_GET['loc'] ?? ''); ?>" />
              <button type="button" id="openQrPreviewBtn" class="btn btn-info btn-sm" onclick="window.open('qr_print_preview.php<?php echo (!empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING'] ?? '') : ''); ?>', '_blank')"><i class="bi bi-qr-code me-1"></i>Print QR</button>
            </form>
            <button type="button" id="printBorrowHistoryBtn" class="btn btn-outline-secondary btn-sm ms-2"
              data-date-from="<?php echo htmlspecialchars($date_from ?? ''); ?>"
              data-date-to="<?php echo htmlspecialchars($date_to ?? ''); ?>">
              <i class="bi bi-clock-history me-1"></i>Print Borrow History
            </button>
          </div>
        </div>

        <!-- Search Modal -->
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
                    <input type="text" name="sid" class="form-control" value="<?php echo htmlspecialchars($serial_id_search_raw ?? ''); ?>" />
                  </div>
                  <div class="mb-3">
                    <label class="form-label">CAT-ID or Category Name</label>
                    <input type="text" name="cat_id" class="form-control" value="<?php echo htmlspecialchars($cat_id_raw ?? ''); ?>" />
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Location</label>
                    <input type="text" name="loc" class="form-control" value="<?php echo htmlspecialchars($location_search_raw ?? ''); ?>" />
                  </div>
                  <!-- Preserve other filters -->
                  <input type="hidden" name="q" value="<?php echo htmlspecialchars($search_q ?? ''); ?>" />
                  <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status ?? ''); ?>" />
                  <input type="hidden" name="category" value="<?php echo htmlspecialchars($filter_category ?? ''); ?>" />
                  <input type="hidden" name="condition" value="<?php echo htmlspecialchars($filter_condition ?? ''); ?>" />
                  <input type="hidden" name="supply" value="<?php echo htmlspecialchars($filter_supply ?? ''); ?>" />
                  <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from ?? ''); ?>" />
                  <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to ?? ''); ?>" />
                </div>
                <div class="modal-footer">
                  <a href="inventory_print.php" class="btn btn-outline-secondary">Reset</a>
                  <button type="submit" class="btn btn-primary">Apply</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Print wrapper to make header repeat on every printed page -->
        <table class="print-doc" style="width:100%; border-collapse:collapse;">
          <thead>
            <tr>
              <td style="padding:0;">
                <?php if (!$qrMode): ?>
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
                      <input id="deptInput" class="eca-input" type="text" placeholder="Enter department" value="<?php echo htmlspecialchars($_GET['department'] ?? ''); ?>" />
                      <span id="deptPrintSpan" class="eca-print-value"></span>
                    </div>
                    <div class="field">
                      <label for="dateInput">Date:</label>
                      <input id="dateInput" class="eca-input" type="date" value="<?php echo htmlspecialchars($_GET['date'] ?? ''); ?>" />
                      <span id="datePrintSpan" class="eca-date-print eca-print-value"></span>
                    </div>
                  </div>
                </div>
                <?php endif; ?>
              </td>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td style="padding:0;">
        <?php if (!$qrMode): ?>
          <div class="table-responsive<?php echo $scrollClass; ?>">
            <table class="table table-bordered table-sm align-middle print-table">
              <colgroup>
                <col style="width: 12%;" />  <!-- Serial ID -->
                <col style="width: 16%;" />  <!-- Item/Model -->
                <col style="width: 10%;" />  <!-- Category ID -->
                <col style="width: 16%;" />  <!-- Category -->
                <col style="width: 16%;" />  <!-- Location -->
                <col style="width: 10%;" />  <!-- Status -->
                <?php if ($ldShow): ?>
                <col style="width: 10%;" />  <!-- Lost/Damaged By -->
                <col style="width: 10%;" />  <!-- Marked By -->
                <?php endif; ?>
                <col style="width: 10%;" />  <!-- Date Acquired (wider) -->
              </colgroup>
              <thead class="table-light">
                <tr>
                  <th>Serial ID</th>
                  <th>Item/Model</th>
                  <th>Category ID</th>
                  <th>Category</th>
                  <th>Location</th>
                  <th>Status</th>
                  <?php if ($ldShow): ?>
                    <?php if ($filter_status === 'Lost'): ?>
                      <th>Lost By</th>
                    <?php else: ?>
                      <th>Damaged By</th>
                    <?php endif; ?>
                      <th>Marked By</th>
                  <?php endif; ?>
                  <th>Date Acquired</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($items)): ?>
                  <tr><td colspan="<?php echo $ldShow ? 9 : 7; ?>" class="text-center text-muted py-4">No items found.</td></tr>
                <?php else: ?>
                  <?php foreach ($items as $it): ?>
                  <tr>
                    <td><?php echo htmlspecialchars((string)($it['serial_no'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($it['item_name']); ?></td>
                    <?php $catPrint = trim($it['category'] ?? '') !== '' ? $it['category'] : 'Uncategorized'; $catIdPrint = $catIdByName[$catPrint] ?? ''; ?>
                    <td><?php echo htmlspecialchars($catIdPrint); ?></td>
                    <td><?php echo htmlspecialchars($it['category'] ?: 'Uncategorized'); ?></td>
                    <td><?php echo htmlspecialchars($it['location']); ?></td>
                    <td><?php echo htmlspecialchars($it['status']); ?></td>
                    <?php if ($ldShow): ?>
                      <?php $mid = (int)($it['id'] ?? 0); $e = isset($ldExtraMap) && isset($ldExtraMap[$mid]) ? $ldExtraMap[$mid] : null; $by = $e ? (trim((string)($e['by_full_name'] ?? '')) ?: trim((string)($e['by_username'] ?? ''))) : ''; $mk = $e ? (trim((string)($e['marker_full_name'] ?? '')) ?: trim((string)($e['marker_username'] ?? ''))) : ''; ?>
                      <td><?php echo htmlspecialchars($by); ?></td>
                      <td><?php echo htmlspecialchars($mk); ?></td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars($it['date_acquired']); ?></td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ($isAdmin): ?>
          <!-- Borrow History (Admin print) -->
          <div class="mt-4">
            <h5 class="mb-2"><i class="bi bi-clock-history me-2"></i>Borrow History</h5>
            <div class="table-responsive<?php echo $borrowScrollClass; ?>">
              <table class="table table-bordered table-sm align-middle print-table">
                <colgroup>
                  <col style="width: 8%;" />   <!-- User ID -->
                  <col style="width: 16%;" />  <!-- User -->
                  <col style="width: 12%;" />  <!-- Student ID -->
                  <col style="width: 14%;" />  <!-- Serial ID -->
                  <col style="width: 24%;" />  <!-- Item/Model -->
                  <col style="width: 16%;" />  <!-- Category -->
                  <col style="width: 5%;" />   <!-- Borrowed At -->
                  <col style="width: 5%;" />   <!-- Returned At -->
                </colgroup>
                <thead class="table-light">
                  <tr>
                    <th>User ID</th>
                    <th>User</th>
                    <th>Student ID</th>
                    <th>Serial ID</th>
                    <th>Item/Model</th>
                    <th>Category</th>
                    <th class="col-datetime">Time Borrowed</th>
                    <th class="col-datetime">Time returned</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($borrow_history)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">No borrow history.</td></tr>
                  <?php else: ?>
                    <?php foreach ($borrow_history as $hv): ?>
                      <tr>
                        <td><?php echo htmlspecialchars((string)($hv['user_id'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($hv['full_name'] ?? ($hv['username'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($hv['school_id'] ?? '')); ?></td>
                        <?php $serial = trim((string)($hv['serial_no'] ?? '')); $st = trim((string)($hv['status'] ?? '')); $serialOut = ($serial === '' && strcasecmp($st,'Rejected')===0) ? 'Rejected' : $serial; ?>
                        <td><?php echo htmlspecialchars($serialOut); ?></td>
                        <td><?php echo htmlspecialchars($hv['model_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($hv['category'] ?? ''); ?></td>
                        <?php $ba = $hv['borrowed_at'] ?? ''; ?>
                        <td class="col-datetime"><?php
                          if ($ba !== '') {
                            $ts = strtotime($ba);
                            if ($ts !== false) {
                              $datePart = date('F d, Y', $ts);
                              $timePart = date('g:iA', $ts);
                              echo '<span class="dt"><span class="dt-date">'.htmlspecialchars($datePart).'</span><span class="dt-time">'.htmlspecialchars($timePart).'</span></span>';
                            }
                          }
                        ?></td>
                        <?php $ra = $hv['returned_at'] ?? ''; ?>
                        <td class="col-datetime"><?php
                          if ($ra !== '') {
                            $ts = strtotime($ra);
                            if ($ts !== false) {
                              $datePart = date('F d, Y', $ts);
                              $timePart = date('g:iA', $ts);
                              echo '<span class="dt"><span class="dt-date">'.htmlspecialchars($datePart).'</span><span class="dt-time">'.htmlspecialchars($timePart).'</span></span>';
                            }
                          }
                        ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>
        <?php else: ?>
          <style>
            .qr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; }
            .qr-card { border: 1px solid #dee2e6; border-radius: .5rem; padding: 8px; text-align: center; }
            .qr-card img { width: 100%; height: auto; }
            .qr-meta { font-size: 0.8rem; margin-top: 6px; }
            @media print { .qr-grid { gap: 8px; } .qr-card { page-break-inside: avoid; } }
          </style>
          <?php if (empty($items)): ?>
            <div class="text-center text-muted py-4">No items found to print QR.</div>
          <?php else: ?>
            <div class="qr-grid">
              <?php foreach ($items as $it): ?>
                <?php
                  $qrPayload = json_encode([
                    'model_id' => (int)$it['id'],
                    'item_name' => (string)$it['item_name'],
                    'generated_date' => date('Y-m-d H:i:s')
                  ], JSON_UNESCAPED_SLASHES);
                  $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=H&margin=1&format=png&data=' . urlencode($qrPayload);
                ?>
                <div class="qr-card">
                  <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="QR: <?php echo htmlspecialchars($it['item_name']); ?>" />
                  <div class="qr-meta">
                    <div><strong>Serial ID:</strong> <?php echo htmlspecialchars((string)($it['serial_no'] ?? '')); ?></div>
                    <div><strong>Model:</strong> <?php echo htmlspecialchars($it['item_name']); ?></div>
                    <?php $catPrint = trim($it['category'] ?? '') !== '' ? $it['category'] : 'Uncategorized'; ?>
                    <div><?php echo htmlspecialchars($catPrint); ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if ($autoPrint): ?>
              <script>document.addEventListener('DOMContentLoaded', function(){ window.print(); });</script>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
              </td>
            </tr>
          </tbody>
        </table>

        <?php if (!$qrMode): ?>
        <!-- Repeating print footer (print-only) -->
        <table class="print-doc only-print" style="width:100%; border-collapse:collapse;">
          <tfoot>
            <tr>
              <td style="padding:0;">
                <div class="container-fluid pb-3">
                  <div class="eca-footer" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:nowrap;">
                    <div class="field" style="display:flex; align-items:baseline; gap:8px; white-space:nowrap;">
                      <label style="font-weight:600; font-size:10pt;">Prepared by:</label>
                      <span class="eca-print-value" style="display:inline-block; border-bottom:1px solid #000; padding:0 4px 2px; min-width:220px;">
                        <?php echo htmlspecialchars($_GET['prepared_by'] ?? $preparedByDefault); ?>&nbsp;
                      </span>
                    </div>
                    <div class="field" style="display:flex; align-items:baseline; gap:8px; white-space:nowrap;">
                      <label style="font-weight:600; font-size:10pt;">Checked by:</label>
                      <span class="eca-print-value" style="display:inline-block; border-bottom:1px solid #000; padding:0 4px 2px; min-width:220px;">
                        <?php echo htmlspecialchars($_GET['checked_by'] ?? ''); ?>&nbsp;
                      </span>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          </tfoot>
        </table>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- Print Header Modal -->
  <div class="modal fade" id="printHeaderModal" tabindex="-1" aria-labelledby="printHeaderModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="printHeaderModalLabel">Print Header</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="modalDeptInput" class="form-label">Department</label>
            <input type="text" class="form-control" id="modalDeptInput" placeholder="Enter department" />
          </div>
          <div class="mb-3">
            <label for="modalDateInput" class="form-label">Date</label>
            <input type="date" class="form-control" id="modalDateInput" />
          </div>
          <div class="mb-3">
            <label for="modalPreparedInput" class="form-label">Prepared by</label>
            <input type="text" class="form-control" id="modalPreparedInput" placeholder="Enter name" value="<?php echo htmlspecialchars($preparedByDefault); ?>" />
          </div>
          <div class="mb-3">
            <label for="modalCheckedInput" class="form-label">Checked by</label>
            <input type="text" class="form-control" id="modalCheckedInput" placeholder="Enter name" />
          </div>
          <div class="form-text">Leave blank to print with manual writing lines.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="confirmPrintBtn">Print</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    (function(){
      const bellBtn = document.getElementById('adminBellBtn');
      const bellDot = document.getElementById('adminBellDot');
      const dropdown = document.getElementById('adminBellDropdown');
      const listEl = document.getElementById('adminNotifList');
      const emptyEl = document.getElementById('adminNotifEmpty');
      if (bellBtn && dropdown) {
        bellBtn.addEventListener('click', function(e){
          e.stopPropagation(); dropdown.classList.toggle('show');
          dropdown.style.position='absolute';
          dropdown.style.top=(bellBtn.offsetTop + bellBtn.offsetHeight + 6)+'px';
          dropdown.style.left=(bellBtn.offsetLeft - (dropdown.offsetWidth - bellBtn.offsetWidth))+'px';
        });
        document.addEventListener('click', ()=>dropdown.classList.remove('show'));
      }
      let toastWrap = document.getElementById('adminToastWrap');
      if (!toastWrap) { toastWrap=document.createElement('div'); toastWrap.id='adminToastWrap'; toastWrap.style.position='fixed'; toastWrap.style.right='16px'; toastWrap.style.bottom='16px'; toastWrap.style.zIndex='1080'; document.body.appendChild(toastWrap); }
      function showToast(msg){ const el=document.createElement('div'); el.className='alert alert-info shadow-sm border-0'; el.style.minWidth='280px'; el.style.maxWidth='360px'; el.innerHTML='<i class="bi bi-bell me-2"></i>'+String(msg||''); toastWrap.appendChild(el); setTimeout(()=>{ try{ el.remove(); }catch(_){ } }, 5000); }
      let audioCtx=null; function playBeep(){ try{ if(!audioCtx) audioCtx=new (window.AudioContext||window.webkitAudioContext)(); const o=audioCtx.createOscillator(), g=audioCtx.createGain(); o.type='sine'; o.frequency.value=880; g.gain.setValueAtTime(0.0001, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.2, audioCtx.currentTime+0.02); g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime+0.22); o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime+0.25);}catch(_){}}
      function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
      let baseline=new Set(); let initialized=false; let fetching=false;
      function renderCombined(pending, recent){
        const rows=[];
        (pending||[]).forEach(r=>{
          const id=parseInt(r.id||0,10);
          const when=String(r.created_at||'');
          const qty=parseInt(r.quantity||1,10);
          rows.push('<a href="admin_borrow_center.php" class="list-group-item list-group-item-action">'
            + '<div class="d-flex w-100 justify-content-between">'
            +   '<strong>#'+id+'</strong>'
            +   '<small class="text-muted">'+escapeHtml(when)+'</small>'
            + '</div>'
            + '<div class="mb-0">'+escapeHtml(String(r.username||''))+' requests '+escapeHtml(String(r.item_name||''))+' <span class="badge bg-secondary">x'+qty+'</span></div>'
            + '</a>');
        });
        if ((recent||[]).length){
          rows.push('<div class="list-group-item"><div class="d-flex justify-content-between align-items-center"><span class="small text-muted">Processed</span><button type="button" class="btn btn-sm btn-outline-secondary" id="admClearAllBtn">Clear All</button></div></div>');
          (recent||[]).forEach(r=>{
            const id=parseInt(r.id||0,10); const nm=String(r.item_name||''); const st=String(r.status||''); const when=String(r.processed_at||''); const bcls=(st==='Approved')?'badge bg-success':'badge bg-danger';
            rows.push('<div class="list-group-item d-flex justify-content-between align-items-start">'
              + '<div class="me-2">'
              +   '<div class="d-flex w-100 justify-content-between"><strong>#'+id+' '+escapeHtml(nm)+'</strong><small class="text-muted">'+escapeHtml(when)+'</small></div>'
              +   '<div class="small">Status: <span class="'+bcls+'">'+escapeHtml(st)+'</span></div>'
              + '</div>'
              + '<div><button type="button" class="btn btn-sm btn-outline-secondary adm-clear-one" data-id="'+id+'">Clear</button></div>'
              + '</div>');
          });
        }
        listEl.innerHTML = rows.join('');
        emptyEl.style.display = rows.length ? 'none' : '';
      }
      document.addEventListener('click', function(ev){ const one=ev.target && ev.target.closest && ev.target.closest('.adm-clear-one'); if(one){ const rid=parseInt(one.getAttribute('data-id')||'0',10)||0; if(!rid) return; const fd=new FormData(); fd.append('request_id', String(rid)); fetch('admin_borrow_center.php?action=admin_notif_clear',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ poll(); }).catch(()=>{}); return; } if (ev.target && ev.target.id==='admClearAllBtn'){ const fd=new FormData(); fd.append('limit','300'); fetch('admin_borrow_center.php?action=admin_notif_clear_all',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ poll(); }).catch(()=>{}); } });
      function poll(){ if(fetching) return; fetching=true; fetch('admin_borrow_center.php?action=admin_notifications').then(r=>r.json()).then(d=>{ const pending=(d&&Array.isArray(d.pending))? d.pending:[]; const recent=(d&&Array.isArray(d.recent))? d.recent:[]; if (bellDot) bellDot.classList.toggle('d-none', pending.length===0); try{ const navLink=document.querySelector('a[href="admin_borrow_center.php"]'); if(navLink){ let dot=navLink.querySelector('.nav-borrow-dot'); const shouldShow = pending.length>0; if (shouldShow){ if(!dot){ dot=document.createElement('span'); dot.className='nav-borrow-dot ms-2 d-inline-block rounded-circle'; dot.style.width='8px'; dot.style.height='8px'; dot.style.backgroundColor='#dc3545'; dot.style.verticalAlign='middle'; dot.style.display='inline-block'; navLink.appendChild(dot);} else { dot.style.display='inline-block'; } } else if (dot){ dot.style.display='none'; } } }catch(_){} renderCombined(pending, recent); const curr=new Set(pending.map(it=>parseInt(it.id||0,10))); if(!initialized){ baseline=curr; initialized=true; } else { let hasNew=false; pending.forEach(it=>{ const id=parseInt(it.id||0,10); if(!baseline.has(id)){ hasNew=true; showToast('New request: '+(it.username||'')+' → '+(it.item_name||'')+' (x'+(it.quantity||1)+')'); } }); if(hasNew) playBeep(); baseline=curr; } }).catch(()=>{}).finally(()=>{ fetching=false; }); }
      poll(); setInterval(()=>{ if(document.visibilityState==='visible') poll(); }, 1000);
    })();
    function toggleSidebar() {
      const sidebar = document.getElementById('sidebar-wrapper');
      if (sidebar) {
        sidebar.classList.toggle('active');
        if (window.innerWidth <= 768) {
          document.body.classList.toggle('sidebar-open', sidebar.classList.contains('active'));
        }
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
    // Print Borrow History inline via hidden iframe
    document.addEventListener('DOMContentLoaded', function(){
      const btn = document.getElementById('printBorrowHistoryBtn');
      if (!btn) return;
      btn.addEventListener('click', function(){
        const df = btn.getAttribute('data-date-from') || '';
        const dt = btn.getAttribute('data-date-to') || '';
        const params = new URLSearchParams();
        if (df) params.set('date_from', df);
        if (dt) params.set('date_to', dt);
        const url = 'borrow_history_print.php' + (params.toString() ? ('?' + params.toString()) : '');
        try { window.open(url, '_blank', 'noopener'); }
        catch(_) { window.location.href = url; }
      });
    });
    // Apply Department/Date to URL so print preview shows them
    document.addEventListener('DOMContentLoaded', function(){
      var btn = document.getElementById('ecaApplyBtn');
      if (!btn) return;
      btn.addEventListener('click', function(){
        var dept = document.getElementById('deptInput')?.value || '';
        var date = document.getElementById('dateInput')?.value || '';
        var url = new URL(window.location.href);
        var p = url.searchParams;
        if (dept) p.set('department', dept); else p.delete('department');
        if (date) p.set('date', date); else p.delete('date');
        // Preserve other filters automatically as we're editing the existing URL
        url.search = p.toString();
        window.location.href = url.toString();
      });
    });
    document.addEventListener('DOMContentLoaded', function(){
      var openBtn = document.getElementById('openPrintModalBtn');
      if (!openBtn) return;
      openBtn.addEventListener('click', function(){
        try {
          var url = new URL(window.location.href);
          var p = new URLSearchParams(url.search);
          var deptVal = document.getElementById('deptInput')?.value || '';
          var dateVal = document.getElementById('dateInput')?.value || '';
          if (deptVal) p.set('department', deptVal); else p.delete('department');
          if (dateVal) p.set('date', dateVal); else p.delete('date');
          var target = 'print_preview.php?' + p.toString();
          window.open(target, '_blank', 'noopener');
        } catch (e) {
          // Fallback: open without extra params
          window.open('print_preview.php', '_blank', 'noopener');
        }
      });
    });
    // Sync header input values to print-only spans so they repeat each page
    (function(){
      function syncHeaderMirror(){
        var dept = document.getElementById('deptInput')?.value || '';
        var date = document.getElementById('dateInput')?.value || '';
        try { document.querySelectorAll('[id="deptPrintSpan"]').forEach(function(el){ el.textContent = dept; }); } catch(_){ }
        try { document.querySelectorAll('[id="datePrintSpan"]').forEach(function(el){ el.textContent = date; }); } catch(_){ }
      }
      document.addEventListener('DOMContentLoaded', function(){
        syncHeaderMirror();
        var di = document.getElementById('deptInput');
        var dt = document.getElementById('dateInput');
        if (di) di.addEventListener('input', syncHeaderMirror);
        if (dt) dt.addEventListener('input', syncHeaderMirror);
        window.addEventListener('beforeprint', syncHeaderMirror);
      });
    })();
    // Global admin notifications: user verified returns (toast + beep)
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        var isAdmin = <?php echo json_encode(isset($_SESSION['usertype']) && $_SESSION['usertype']==='admin'); ?>;
        if (!isAdmin) return;
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
          pollVerif(); setInterval(function(){ if (document.visibilityState==='visible') pollVerif(); }, 1000);
        } catch(_e){}
      });
    })();
  </script>
</body>
</html>
