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
$filter_category = trim($_GET['category'] ?? '');
$filter_condition = trim($_GET['condition'] ?? '');
$filter_supply = trim($_GET['supply'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$cat_id_raw = trim($_GET['cat_id'] ?? '');
$model_id_search_raw = trim($_GET['mid'] ?? '');
$serial_or_name = trim($_GET['sid'] ?? '');
$location_search_raw = trim($_GET['loc'] ?? '');

$isAdmin = (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin');

$items = [];
$categoryOptions = [];
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
    if ($filter_status !== '') { $match['status'] = $filter_status; }
    if ($filter_category !== '') { $match['category'] = $filter_category; }
    if ($filter_condition !== '') { $match['condition'] = $filter_condition; }
    if ($filter_supply !== '') {
      if ($filter_supply === 'low') { $match['quantity'] = ['$lt' => 10]; }
      elseif ($filter_supply === 'average') { $match['quantity'] = ['$gt' => 10, '$lt' => 50]; }
      elseif ($filter_supply === 'high') { $match['quantity'] = ['$gt' => 50]; }
    }
    // Date range support (string or UTCDateTime fields)
    if ($date_from !== '' || $date_to !== '') {
      $orClauses = [];
      foreach (['date_acquired','created_at'] as $fld) {
        $strCond = [];
        if ($date_from !== '') { $strCond['$gte'] = $date_from; }
        if ($date_to !== '') { $strCond['$lte'] = $date_to; }
        if (!empty($strCond)) { $orClauses[] = [$fld => $strCond]; }
        try {
          $dtCond = [];
          if ($date_from !== '') { $dtCond['$gte'] = new \MongoDB\BSON\UTCDateTime(strtotime($date_from.' 00:00:00') * 1000); }
          if ($date_to !== '') { $dtCond['$lte'] = new \MongoDB\BSON\UTCDateTime(strtotime($date_to.' 23:59:59') * 1000); }
          if (!empty($dtCond)) { $orClauses[] = [$fld => $dtCond]; }
        } catch (\Throwable $e) { /* ignore */ }
      }
      if (!empty($orClauses)) { $match['$or'] = $orClauses; }
    }
    $cursor = $db->selectCollection('inventory_items')->find($match, [ 'sort' => ['category'=>1,'item_name'=>1,'id'=>1] ]);
    foreach ($cursor as $doc) {
      $items[] = [
        'id' => intval($doc['id'] ?? 0),
        'item_name' => (string)($doc['item_name'] ?? ''),
        'serial_no' => (string)($doc['serial_no'] ?? ''),
        'category' => (string)($doc['category'] ?? ''),
        'location' => (string)($doc['location'] ?? ''),
        'status' => (string)($doc['status'] ?? ''),
        'date_acquired' => (string)($doc['date_acquired'] ?? ''),
      ];
    }
    // Categories
    $catCur = $db->selectCollection('categories')->find([], ['sort' => ['name' => 1], 'projection' => ['name' => 1]]);
    foreach ($catCur as $c) { if (!empty($c['name'])) { $categoryOptions[] = (string)$c['name']; } }
    $usedMongo = true;
  } catch (\Throwable $e) { $usedMongo = false; }
}

if (!$usedMongo) {
  $conn = new mysqli('localhost','root','','inventory_system');
  if ($conn->connect_error) { http_response_code(500); echo 'DB connection failed'; exit(); }
  $sql = "SELECT id, item_name, serial_no, category, location, status, date_acquired FROM inventory_items WHERE 1";
  $params = [];$types='';
  if ($search_q !== '') {
    if ($isAdmin) { $sql .= " AND id = ?"; $params[] = intval($search_q); $types.='i'; }
    else { $sql .= " AND item_name LIKE ?"; $params[] = "%$search_q%"; $types.='s'; }
  }
  if ($filter_status !== '') { $sql .= " AND status = ?"; $params[]=$filter_status; $types.='s'; }
  if ($filter_category !== '') { $sql .= " AND category = ?"; $params[]=$filter_category; $types.='s'; }
  if ($filter_condition !== '') { $sql .= " AND `condition` = ?"; $params[]=$filter_condition; $types.='s'; }
  if ($filter_supply !== '') {
    if ($filter_supply==='low') { $sql .= " AND quantity < 10"; }
    elseif ($filter_supply==='average') { $sql .= " AND quantity > 10 AND quantity < 50"; }
    elseif ($filter_supply==='high') { $sql .= " AND quantity > 50"; }
  }
  if ($date_from !== '' && $date_to !== '') { $sql .= " AND date_acquired BETWEEN ? AND ?"; $params[]=$date_from; $params[]=$date_to; $types.='ss'; }
  elseif ($date_from !== '') { $sql .= " AND date_acquired >= ?"; $params[]=$date_from; $types.='s'; }
  elseif ($date_to !== '') { $sql .= " AND date_acquired <= ?"; $params[]=$date_to; $types.='s'; }
  $sql .= " ORDER BY category ASC, item_name ASC, id ASC";
  if ($types!=='') { $stmt=$conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute(); $res=$stmt->get_result(); while($row=$res->fetch_assoc()){ $items[]=$row; } $stmt->close(); }
  else { $res=$conn->query($sql); if($res){ while($row=$res->fetch_assoc()){ $items[]=$row; } } }
  $cres = $conn->query('SELECT name FROM categories ORDER BY name');
  if ($cres) { while($r=$cres->fetch_assoc()){ $categoryOptions[]=$r['name']; } $cres->close(); }
}

// Optional category and location filtering similar to inventory_print
$catIdByName = [];
$catNamesTmp = [];
foreach ($items as $giTmp) { $cat = trim($giTmp['category'] ?? '') !== '' ? $giTmp['category'] : 'Uncategorized'; $catNamesTmp[$cat]=true; }
$catNamesArr = array_keys($catNamesTmp); natcasesort($catNamesArr); $catNamesArr = array_values($catNamesArr);
for ($i=0;$i<count($catNamesArr);$i++){ $catIdByName[$catNamesArr[$i]] = sprintf('CAT-%03d', $i+1); }

if ($cat_id_raw !== '') {
  $catIdFilters = [];$catNameGroups=[];$groups = preg_split('/\s*,\s*/', strtolower($cat_id_raw));
  foreach ($groups as $g) {
    $g=trim($g); if ($g==='') continue; $tokens=preg_split('/[\/\s]+/',$g); $needles=[];
    foreach($tokens as $t){ $t=trim($t); if($t==='') continue; if(preg_match('/^(?:cat-)?(\d{1,})$/',$t,$m)){ $num=intval($m[1]); if($num>0){ $catIdFilters[] = sprintf('CAT-%03d',$num);} } else { $needles[] = $t; } }
    if (!empty($needles)) { $catNameGroups[] = $needles; }
  }
  if (!empty($catIdFilters) || !empty($catNameGroups)) {
    $items = array_values(array_filter($items,function($row) use($catIdByName,$catIdFilters,$catNameGroups){
      $cat = trim($row['category'] ?? '') !== '' ? $row['category'] : 'Uncategorized';
      $cid = $catIdByName[$cat] ?? '';
      if (!empty($catIdFilters) && in_array($cid,$catIdFilters,true)) return true;
      if (!empty($catNameGroups)) {
        foreach ($catNameGroups as $grp) {
          $all=true; foreach ($grp as $n) { if ($n!=='' && !preg_match('/(?<![A-Za-z0-9])'.preg_quote($n,'/').'(?![A-Za-z0-9])/i', (string)$cat)) { $all=false; break; } }
          if ($all) return true;
        }
      }
      return false;
    }));
  }
}

if ($serial_or_name !== '') {
  $groups = preg_split('/\s*,\s*/', $serial_or_name); $tokenGroups=[];
  foreach ($groups as $g) { $g=trim($g); if($g==='') continue; $tokens=preg_split('/\s+/', $g); $needles=[]; foreach ($tokens as $t){ $t=trim($t); if($t!==''){ $needles[] = strtolower($t);} } if(!empty($needles)){ $tokenGroups[]=$needles; } }
  if (!empty($tokenGroups)) {
    $items = array_values(array_filter($items,function($row) use($tokenGroups){
      $serial = strtolower((string)($row['serial_no'] ?? ''));
      $name = strtolower((string)($row['item_name'] ?? ''));
      $hay = $serial.' '.$name;
      foreach ($tokenGroups as $grp) { $all=true; foreach ($grp as $n){ if($n!=='' && strpos($hay,$n)===false){ $all=false; break; } } if ($all) return true; }
      return false;
    }));
  }
}

if ($location_search_raw !== '') {
  $locGroups=[]; $groups=preg_split('/\s*,\s*/', strtolower($location_search_raw));
  foreach ($groups as $g){ $g=trim($g); if($g==='') continue; $tokens=preg_split('/\s+/', $g); $needles=[]; foreach($tokens as $t){ $t=trim($t); if($t!==''){ $needles[]=$t; } } if(!empty($needles)){ $locGroups[]=$needles; } }
  if (!empty($locGroups)) {
    $items = array_values(array_filter($items,function($row) use($locGroups){
      $loc=(string)($row['location'] ?? '');
      foreach ($locGroups as $grp){ $all=true; foreach($grp as $n){ if($n!=='' && !preg_match('/(?<![A-Za-z0-9])'.preg_quote($n,'/').'(?![A-Za-z0-9])/i', $loc)){ $all=false; break; } } if($all) return true; }
      return false;
    }));
  }
}

// Sort like inventory_print (by category id desc)
usort($items, function($a,$b) use($catIdByName){
  $ca = trim($a['category'] ?? '') !== '' ? $a['category'] : 'Uncategorized';
  $cb = trim($b['category'] ?? '') !== '' ? $b['category'] : 'Uncategorized';
  $ida = $catIdByName[$ca] ?? 'CAT-000';
  $idb = $catIdByName[$cb] ?? 'CAT-000';
  return strcmp($idb,$ida);
});

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>QR Print Preview</title>
  <link href="css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    :root { --qr-size: 140px; --qr-col-min: 160px; }
    /* Small outer page margin to maximize QR area */
    @page { size: A4 portrait; margin: 0.2in; }
    @media print {
      .no-print { display: none !important; }
      html, body { margin: 0 !important; background: #ffffff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      /* Reduce container padding so content nearly reaches page edge while keeping a small margin */
      .container-fluid { padding: 0.1in !important; }
      thead { display: table-header-group; }
      tfoot { display: table-footer-group; }
      /* Tighter gaps and card padding in print for denser layout */
      .qr-grid { gap: 2px !important; /* columns auto-fit based on --qr-col-min */ }
      .qr-card { page-break-inside: avoid; border-width: 0.5px; padding: 1px; }
      /* Use CSS var so the slider affects print size */
      .qr-card img { width: var(--qr-size) !important; height: var(--qr-size) !important; max-width: 100% !important; max-height: 100% !important; object-fit: contain; }
    }
    .qr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(var(--qr-col-min), 1fr)); gap: 8px; }
    .qr-card { border: 1px solid #dee2e6; border-radius: .5rem; padding: 6px; text-align: center; }
    .qr-card img { width: var(--qr-size); height: var(--qr-size); max-width: 100%; max-height: 100%; object-fit: contain; display: block; margin: 0 auto; }
    .qr-meta { font-size: 0.75rem; margin-top: 4px; }
    .qr-meta div { white-space: normal; word-break: break-word; overflow-wrap: anywhere; }
  </style>
</head>
<body>
  <div class="container-fluid pt-3">
    <div class="d-flex align-items-center justify-content-between no-print mb-2 flex-wrap" style="gap:8px;">
      <div class="d-flex gap-2 align-items-center">
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.close()"><i class="bi bi-x-lg me-1"></i>Close</button>
        <button class="btn btn-primary btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
      </div>
      <div class="d-flex align-items-center gap-2">
        <label class="small text-muted">QR size</label>
        <?php 
          $unit_raw = strtolower((string)($_GET['qr_unit'] ?? 'px'));
          $qr_unit = in_array($unit_raw, ['px','cm','mm'], true) ? $unit_raw : 'px';
          $qr_size = floatval($_GET['qr_size'] ?? 100);
          if ($qr_unit === 'px') { if ($qr_size < 50) $qr_size = 50; if ($qr_size > 500) $qr_size = 500; }
          elseif ($qr_unit === 'cm') { if ($qr_size < 1.3) $qr_size = 1.3; if ($qr_size > 13.2) $qr_size = 13.2; }
          elseif ($qr_unit === 'mm') { if ($qr_size < 13) $qr_size = 13; if ($qr_size > 132) $qr_size = 132; }
        ?>
        <input id="qrSizeRange" type="range" class="form-range" value="<?php echo htmlspecialchars($qr_size, ENT_QUOTES); ?>" style="width:200px;">
        <input id="qrSizeNumber" type="number" class="form-control form-control-sm" value="<?php echo htmlspecialchars($qr_size, ENT_QUOTES); ?>" style="width:90px;" />
        <select id="qrSizeUnit" class="form-select form-select-sm" style="width:auto;">
          <option value="px" <?php echo $qr_unit==='px'?'selected':''; ?>>px</option>
          <option value="cm" <?php echo $qr_unit==='cm'?'selected':''; ?>>cm</option>
          <option value="mm" <?php echo $qr_unit==='mm'?'selected':''; ?>>mm</option>
        </select>
      </div>
    </div>

    <?php if (empty($items)): ?>
      <div class="text-center text-muted py-4">No items found to print QR.</div>
    <?php else: ?>
      <div class="qr-grid">
        <?php foreach ($items as $it): ?>
          <?php
            $serialOnly = trim((string)($it['serial_no'] ?? ''));
            if ($serialOnly === '') { $serialOnly = (string)((int)($it['id'] ?? 0)); }
            $payload = $serialOnly;
            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=H&margin=1&format=png&data=' . urlencode($payload);
            $catPrint = trim($it['category'] ?? '') !== '' ? $it['category'] : 'Uncategorized';
          ?>
          <div class="qr-card">
            <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="QR: <?php echo htmlspecialchars((string)($it['item_name'] ?? '')); ?>" />
            <div class="qr-meta">
              <div><strong>Serial ID:</strong> <?php echo htmlspecialchars((string)($it['serial_no'] ?? '')); ?></div>
              <div><strong>Model:</strong> <?php echo htmlspecialchars((string)($it['item_name'] ?? '')); ?></div>
              <div><?php echo htmlspecialchars($catPrint); ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <script>
    (function(){
      var root = document.documentElement;
      var r = null, n = null, u = null;
      var bounds = {
        px: {min:40, max:500, step:5},
        cm: {min:1.0, max:13.2, step:0.1},
        mm: {min:10, max:132, step:1}
      };
      function toPx(value, unit){
        var v = parseFloat(value||0);
        if (unit === 'px') return v;
        if (unit === 'cm') return v * 37.7952755906; // 1cm ≈ 37.795px
        if (unit === 'mm') return v * 3.77952755906; // 1mm ≈ 3.7795px
        return v;
      }
      function clamp(value, unit){
        var b = bounds[unit] || bounds.px;
        var v = parseFloat(value||0);
        if (isNaN(v)) v = b.min;
        if (v < b.min) v = b.min;
        if (v > b.max) v = b.max;
        return v;
      }
      function applySize(val, unit){
        var unitSafe = (unit==='px'||unit==='cm'||unit==='mm')? unit : 'px';
        var v = clamp(val, unitSafe);
        // Set CSS variables
        root.style.setProperty('--qr-size', v + unitSafe);
        var px = toPx(v, unitSafe);
        var colMin = Math.max(120, px + 12);
        root.style.setProperty('--qr-col-min', colMin + 'px');
        // Sync controls and attributes
        r.min = String(bounds[unitSafe].min); r.max = String(bounds[unitSafe].max); r.step = String(bounds[unitSafe].step);
        n.min = String(bounds[unitSafe].min); n.max = String(bounds[unitSafe].max); n.step = String(bounds[unitSafe].step);
        r.value = String(v); n.value = String(v); u.value = unitSafe;
        // Persist in URL (without reload)
        try {
          var url = new URL(window.location.href);
          url.searchParams.set('qr_size', v);
          url.searchParams.set('qr_unit', unitSafe);
          history.replaceState(null, '', url.toString());
        } catch(e) {}
      }
      document.addEventListener('DOMContentLoaded', function(){
        r = document.getElementById('qrSizeRange');
        n = document.getElementById('qrSizeNumber');
        u = document.getElementById('qrSizeUnit');
        var initUnit = (u && (u.value==='px'||u.value==='cm'||u.value==='mm')) ? u.value : 'px';
        var initVal = parseFloat(r.value || n.value || '100');
        applySize(initVal, initUnit);
        r.addEventListener('input', function(){ applySize(r.value, u.value); });
        n.addEventListener('input', function(){ applySize(n.value, u.value); });
        u.addEventListener('change', function(){ applySize(n.value || r.value, u.value); });
      });
    })();
  </script>
</body>
</html>
