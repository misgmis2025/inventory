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
    
    // Compute Total Units using same filter semantics as inventory.php
    $totalUnitsDisplay = 0;
    try {
        $excludedStatuses = ['Permanently Lost','Disposed'];
        $kpiMatch = [];
        // Filters from GET (mirror inventory.php)
        $q = trim($_GET['q'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $category = trim($_GET['category'] ?? '');
        $condition = trim($_GET['condition'] ?? '');
        $supply = trim($_GET['supply'] ?? '');
        $locRaw = trim($_GET['loc'] ?? '');
        $sid_raw = trim($_GET['sid'] ?? '');
        $mid_raw = trim($_GET['mid'] ?? '');
        $cat_id_raw = trim($_GET['cat_id'] ?? '');
        $date_from = trim($_GET['date_from'] ?? '');
        $date_to   = trim($_GET['date_to'] ?? '');

        if ($q !== '') {
            $kpiMatch['$or'] = [
                ['item_name' => ['$regex' => $q, '$options' => 'i']],
                ['serial_no' => ['$regex' => $q, '$options' => 'i']],
                ['model' => ['$regex' => $q, '$options' => 'i']],
            ];
        }
        if ($status !== '') {
            // Mirror inventory.php: never include excluded statuses even if explicitly selected
            if (in_array($status, $excludedStatuses, true)) { $kpiMatch['status'] = ['$nin' => $excludedStatuses]; }
            else { $kpiMatch['status'] = $status; }
        } else {
            $kpiMatch['status'] = ['$nin' => $excludedStatuses];
        }
        if ($category !== '') { $kpiMatch['category'] = $category; }
        if ($condition !== '') { $kpiMatch['condition'] = $condition; }
        if ($supply !== '') {
            if ($supply === 'low') { $kpiMatch['quantity'] = ['$lt' => 10]; }
            elseif ($supply === 'average') { $kpiMatch['quantity'] = ['$gt' => 10, '$lt' => 50]; }
            elseif ($supply === 'high') { $kpiMatch['quantity'] = ['$gt' => 50]; }
        }
        if ($date_from !== '' && $date_to !== '') { $kpiMatch['date_acquired'] = ['$gte' => $date_from, '$lte' => $date_to]; }
        elseif ($date_from !== '') { $kpiMatch['date_acquired'] = ['$gte' => $date_from]; }
        elseif ($date_to !== '') { $kpiMatch['date_acquired'] = ['$lte' => $date_to]; }
        // Location filter will be applied in PHP with whole-word semantics to mirror inventory.php
        $locGroups = [];
        if ($locRaw !== '') {
            $groups = preg_split('/\s*,\s*/', strtolower($locRaw));
            foreach ($groups as $g) {
                $g = trim($g); if ($g === '') continue;
                $tokens = preg_split('/\s+/', $g);
                $needles = [];
                foreach ($tokens as $t) { $t = trim($t); if ($t !== '') { $needles[] = $t; } }
                if (!empty($needles)) { $locGroups[] = $needles; }
            }
        }

        // sid/mid token search (tokens AND within group, groups OR). Match across serial_no | item_name | model
        $serial_id_search_raw = ($sid_raw !== '') ? $sid_raw : $mid_raw;
        if ($serial_id_search_raw !== '') {
            $grpClauses = [];
            $groups = preg_split('/\s*,\s*/', $serial_id_search_raw);
            foreach ($groups as $g) {
                $g = trim($g); if ($g === '') continue;
                $tokens = preg_split('/\s+/', $g);
                $andForTokens = [];
                foreach ($tokens as $t) {
                    $t = trim($t); if ($t === '') continue;
                    $orFields = [
                        ['serial_no' => ['$regex' => $t, '$options' => 'i']],
                        ['item_name' => ['$regex' => $t, '$options' => 'i']],
                        ['model' => ['$regex' => $t, '$options' => 'i']],
                    ];
                    $andForTokens[] = ['$or' => $orFields];
                }
                if (!empty($andForTokens)) { $grpClauses[] = ['$and' => $andForTokens]; }
            }
            if (!empty($grpClauses)) {
                if (isset($kpiMatch['$and'])) { $kpiMatch['$and'][] = ['$or' => $grpClauses]; }
                else { $kpiMatch['$and'] = [['$or' => $grpClauses]]; }
            }
        }

        // cat_id mapping: CAT-001.. based on sorted distinct category names
        if ($cat_id_raw !== '') {
            try {
                $cats = $itemsCol->distinct('category');
                $names = [];
                foreach ($cats as $c) { $nm = trim((string)$c) !== '' ? (string)$c : 'Uncategorized'; if (!in_array($nm, $names, true)) { $names[] = $nm; } }
                natcasesort($names); $names = array_values($names);
                $wanted = [];
                $groups = preg_split('/\s*,\s*/', strtolower($cat_id_raw));
                foreach ($groups as $g) {
                    $g = trim($g); if ($g === '') continue;
                    if (preg_match('/^(?:cat-)?(\d{1,})$/i', $g, $m)) {
                        $num = intval($m[1]); if ($num > 0 && $num <= count($names)) { $wanted[] = $names[$num-1]; }
                    }
                }
                if (!empty($wanted)) { $kpiMatch['category'] = ['$in' => $wanted]; }
            } catch (Throwable $_cat) { /* ignore mapping error */ }
        }

        // Fetch by Mongo match, then refine by location groups in PHP to match word-boundary semantics
        $cursorKpi = $itemsCol->find($kpiMatch, ['projection' => ['location' => 1]]);
        $cnt = 0;
        foreach ($cursorKpi as $docK) {
            if (!empty($locGroups)) {
                $loc = (string)($docK['location'] ?? '');
                $okAny = false;
                foreach ($locGroups as $grp) {
                    $all = true;
                    foreach ($grp as $n) {
                        if ($n === '') continue;
                        $pat = '/(?<![A-Za-z0-9])' . preg_quote($n, '/') . '(?![A-Za-z0-9])/i';
                        if (!preg_match($pat, $loc)) { $all = false; break; }
                    }
                    if ($all) { $okAny = true; break; }
                }
                if (!$okAny) { continue; }
            }
            $cnt++;
        }
        $totalUnitsDisplay = $cnt;
    } catch (Throwable $_tot) { $totalUnitsDisplay = 0; }

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
        $buCol = $db->selectCollection('borrowable_units');
        // Borrow limits map for active models
        $borrowLimitMap = [];
        $activeCur = $bcCol->find(['active' => 1, 'borrow_limit' => ['$gt' => 0]], ['projection' => ['model_name' => 1, 'category' => 1, 'borrow_limit'=>1]]);
        foreach ($activeCur as $b) {
            $c = (string)($b['category'] ?? '');
            $c = ($c !== '') ? $c : 'Uncategorized';
            $m = trim((string)($b['model_name'] ?? ''));
            if ($m === '') continue;
            if (!isset($borrowLimitMap[$c])) $borrowLimitMap[$c] = [];
            $lim = (int)($b['borrow_limit'] ?? 0);
            if ($lim <= 0) continue;
            $borrowLimitMap[$c][$m] = $lim;
        }
        // Available now per (category, model)
        $availNow = [];
        $aggAvail = $buCol->aggregate([
            ['$lookup'=>[
                'from'=>'inventory_items',
                'localField'=>'model_id',
                'foreignField'=>'id',
                'as'=>'item'
            ]],
            ['$unwind'=>'$item'],
            ['$project'=>[
                'category'=>'$category',
                'model_name'=>'$model_name',
                'status'=>['$ifNull'=>['$item.status','']]
            ]],
            ['$group'=>[
                '_id'=>[
                    'category'=>['$ifNull'=>['$category','Uncategorized']],
                    'model_name'=>['$ifNull'=>['$model_name','']]
                ],
                'avail'=>['$sum'=>[
                    '$cond'=>[['$eq'=>['$status','Available']],1,0]
                ]]
            ]]
        ]);
        foreach ($aggAvail as $r) {
            $id = (array)($r->_id ?? []);
            $c = (string)($id['category'] ?? 'Uncategorized');
            $m = (string)($id['model_name'] ?? '');
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
                // Skip stale borrowable entries that have no whitelisted units
                // and no active/pending usage; these typically correspond to
                // deleted categories/items that still have a catalog row.
                $hasAvail = isset($availNow[$cat]) && array_key_exists($mod, $availNow[$cat]);
                $hasCons  = isset($consumed[$cat]) && array_key_exists($mod, $consumed[$cat]);
                if (!$hasAvail && !$hasCons) { continue; }

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

    $availableUnits = [];
    $inUseUnits = [];
    $reservedUnits = [];
    $availableCount = 0; $inUseCount = 0; $reservedCount = 0;
    try {
        $borrowableSet = [];
        try {
            $bc2 = $db->selectCollection('borrowable_catalog');
            $curB = $bc2->find(['active'=>1, 'borrow_limit' => ['$gt' => 0]], ['projection'=>['category'=>1,'model_name'=>1,'borrow_limit'=>1]]);
            foreach ($curB as $b) {
                $c = (string)($b['category'] ?? ''); $c = ($c !== '') ? $c : 'Uncategorized';
                $m = trim((string)($b['model_name'] ?? '')); if ($m === '') continue;
                $lim = (int)($b['borrow_limit'] ?? 0); if ($lim <= 0) continue;
                $borrowableSet[strtolower($c).'|'.strtolower($m)] = true;
            }
        } catch (Throwable $_) {}
        try {
            $bu2 = $db->selectCollection('borrowable_units');
            $aggAvailUnits = $bu2->aggregate([
                ['$lookup'=>[
                    'from'=>'inventory_items',
                    'localField'=>'model_id',
                    'foreignField'=>'id',
                    'as'=>'item'
                ]],
                ['$unwind'=>'$item'],
                ['$project'=>[
                    'category'=>'$category',
                    'model_name'=>'$model_name',
                    'status'=>['$ifNull'=>['$item.status','']],
                    'location'=>['$ifNull'=>['$item.location','']],
                    'remarks'=>['$ifNull'=>['$item.remarks','']],
                    'serial_no'=>['$ifNull'=>['$item.serial_no','']]
                ]]
            ]);
            foreach ($aggAvailUnits as $rowA) {
                $rawStatus = (string)($rowA->status ?? '');
                if ($rawStatus !== 'Available') continue;
                $cat = trim((string)($rowA->category ?? '')) !== '' ? (string)$rowA->category : 'Uncategorized';
                $nm = trim((string)($rowA->model_name ?? ''));
                if ($nm === '') continue;
                $key = strtolower($cat).'|'.strtolower($nm);
                if (!isset($borrowableSet[$key])) continue;
                $availableUnits[] = [
                    'item_name' => $nm,
                    'category' => $cat,
                    'location' => (string)($rowA->location ?? ''),
                    'remarks' => (string)($rowA->remarks ?? ''),
                    'serial_no' => (string)($rowA->serial_no ?? ''),
                ];
                if (count($availableUnits) >= 500) break;
            }
        } catch (Throwable $_a) {}
        $availableCount = count($availableUnits);
        try {
            $ubCol = $db->selectCollection('user_borrows');
            $usersCol = $db->selectCollection('users');
            $curU = $ubCol->find(['status'=>'Borrowed'], ['projection'=>['username'=>1,'model_id'=>1]]);
            foreach ($curU as $ubd) {
                $mid = (int)($ubd['model_id'] ?? 0); if ($mid <= 0) continue;
                $it = $itemsCol->findOne(['id'=>$mid], ['projection'=>['item_name'=>1,'model'=>1,'location'=>1,'serial_no'=>1]]);
                $nm = '';
                $loc = '';
                if ($it) { $nm = (string)($it['model'] ?? ''); if ($nm==='') { $nm = (string)($it['item_name'] ?? ''); } $loc = (string)($it['location'] ?? ''); }
                $u = (string)($ubd['username'] ?? '');
                $full = '';
                if ($u !== '') {
                    $ud = $usersCol->findOne(['username'=>$u], ['projection'=>['full_name'=>1]]);
                    if ($ud && isset($ud['full_name']) && trim((string)$ud['full_name'])!=='') { $full = (string)$ud['full_name']; }
                }
                $inUseUnits[] = [ 'item_name'=>$nm, 'location'=>$loc, 'full_name'=>$full, 'serial_no' => (string)($it['serial_no'] ?? '') ];
                if (count($inUseUnits) >= 500) break;
            }
        } catch (Throwable $_u) {}
        $inUseCount = count($inUseUnits);
        try {
            $erCol = $db->selectCollection('equipment_requests');
            $usersCol = isset($usersCol) ? $usersCol : $db->selectCollection('users');
            $nowStr = date('Y-m-d H:i:s');
            $curR = $erCol->find(
                ['type'=>'reservation','status'=>'Approved','reserved_model_id'=>['$exists'=>true,'$ne'=>0]],
                ['projection'=>['id'=>1,'reserved_model_id'=>1,'reserved_from'=>1,'reserved_to'=>1,'username'=>1,'request_location'=>1,'reserved_serial_no'=>1], 'sort'=>['reserved_from'=>1]]
            );
            $byUnit = [];
            foreach ($curR as $r) {
                $rmid = (int)($r['reserved_model_id'] ?? 0); if ($rmid <= 0) continue;
                if (!isset($byUnit[$rmid])) { $byUnit[$rmid] = ['count'=>0, 'upcoming'=>null]; }
                $byUnit[$rmid]['count']++;
                $rf = trim((string)($r['reserved_from'] ?? ''));
                $rt = trim((string)($r['reserved_to'] ?? ''));
                $isOngoing = ($rf !== '' && $rt !== '' && $rf <= $nowStr && $rt >= $nowStr);
                $isFuture = ($rf !== '' && $rf > $nowStr);
                $pick = false;
                if ($byUnit[$rmid]['upcoming'] === null) { $pick = $isOngoing || $isFuture; }
                else {
                    $curr = $byUnit[$rmid]['upcoming'];
                    $currRf = trim((string)($curr['reserved_from'] ?? ''));
                    $currRt = trim((string)($curr['reserved_to'] ?? ''));
                    $currOngoing = ($currRf !== '' && $currRt !== '' && $currRf <= $nowStr && $currRt >= $nowStr);
                    if ($isOngoing && !$currOngoing) { $pick = true; }
                    elseif ($isFuture && !$currOngoing) { if ($currRf === '' || $rf < $currRf) { $pick = true; } }
                }
                if ($pick) { $byUnit[$rmid]['upcoming'] = $r; }
            }
            foreach ($byUnit as $mid => $info) {
                $it = $itemsCol->findOne(['id'=>(int)$mid], ['projection'=>['item_name'=>1,'model'=>1]]);
                $nm = '';
                if ($it) { $nm = (string)($it['model'] ?? ''); if ($nm==='') { $nm = (string)($it['item_name'] ?? ''); } }
                $doc = $info['upcoming'];
                $rf = $doc ? (string)($doc['reserved_from'] ?? '') : '';
                $rt = $doc ? (string)($doc['reserved_to'] ?? '') : '';
                $u = $doc ? (string)($doc['username'] ?? '') : '';
                $full = '';
                if ($u !== '') {
                    $ud = $usersCol->findOne(['username'=>$u], ['projection'=>['full_name'=>1]]);
                    if ($ud && isset($ud['full_name']) && trim((string)$ud['full_name'])!=='') { $full = (string)$ud['full_name']; }
                }
                $loc = $doc ? (string)($doc['request_location'] ?? '') : '';
                $serial = $doc ? (string)($doc['reserved_serial_no'] ?? '') : '';
                if ($serial === '' && isset($it['serial_no'])) { $serial = (string)$it['serial_no']; }
                $reservedUnits[] = [ 'item_name'=>$nm, 'start'=>$rf, 'end'=>$rt, 'count'=>(int)$info['count'], 'location'=>$loc, 'full_name'=>$full, 'serial_no'=>$serial ];
                if (count($reservedUnits) >= 500) break;
            }
        } catch (Throwable $_r) {}
        $reservedCount = count($reservedUnits);
    } catch (Throwable $_kpi) { $availableUnits=[]; $inUseUnits=[]; $reservedUnits=[]; $availableCount=0; $inUseCount=0; $reservedCount=0; }
    
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
    $availableUnits = []; $inUseUnits = []; $reservedUnits = [];
    $availableCount = 0; $inUseCount = 0; $reservedCount = 0;
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
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__.'/css/style.css'); ?>">
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
          .bottom-nav{ position: fixed; bottom: 0; left:0; right:0; z-index: 1050; background:#fff; border-top:1px solid #dee2e6; display:flex; justify-content:space-around; padding:8px 6px; padding-bottom: calc(8px + env(safe-area-inset-bottom)); }
          body{ padding-bottom: calc(64px + env(safe-area-inset-bottom)); }
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
        .kpi-compact .card-body{ padding: 8px 10px !important; }
        .kpi-compact .fs-4{ font-size: .95rem !important; }
        .kpi-compact .text-muted.small{ font-size: 11px !important; }
        .kpi-compact .bi{ font-size: 1.2rem !important; }
        .kpi-row{ grid-template-columns: 1fr 1fr !important; gap: 8px !important; align-items: stretch !important; }
        .kpi-group{ grid-template-columns: 1fr !important; grid-template-rows: repeat(3, 1fr) !important; gap: 8px !important; height: 100% !important; }
        .kpi-group .card{ height: 100% !important; }
        .kpi-mini .card-body{ padding: 8px 8px !important; }
        .kpi-mini .fs-4{ font-size: .95rem !important; }
        .kpi-mini .text-muted.small{ font-size: 11px !important; }
        .kpi-mini .rowline .label{ font-size:11px !important; }
        .kpi-mini .rowline .value{ font-size:.95rem !important; }
        .kpi-row-4{ grid-template-columns: repeat(3, 1fr) !important; gap:8px !important; }
        .kpi-row-4 > .kpi-card:nth-child(1){ grid-column: 1 / -1 !important; }
        .kpi-card{ aspect-ratio: auto !important; }
        .kpi-card .card-body{ padding:6px 8px !important; display:block !important; }
        .kpi-title{ font-size:11px !important; }
        .kpi-value{ font-size:.95rem !important; }
        .kpi-icon{ font-size:.85rem !important; }
      }
      .kpi-grid{ display: grid; gap: 12px; }
      .kpi-row{ display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: start; }
      .kpi-group{ display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
      .kpi-group .card{ height: auto; }
      .kpi-mini .card-body{ padding: 8px 10px; }
      .kpi-mini .text-muted.small{ font-size: 12px; }
      .kpi-mini .fs-4{ font-size: 1rem; }
      .kpi-mini .rowline{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
      .kpi-mini .rowline .label{ font-size:12px; }
      .kpi-mini .rowline .value{ font-size:1rem; line-height:1; }
      .kpi-row-4{ display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; align-items:stretch; }
      .kpi-card .card-body{ padding: 10px 12px; }
      .kpi-rowline{ display:flex; align-items:center; justify-content:space-between; gap:8px; white-space:nowrap; width:100%; }
      .kpi-title{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
      .kpi-value{ font-size:1rem; line-height:1; }
      .kpi-icon{ font-size:.95rem; margin-left:6px; opacity:.7; vertical-align:middle; }
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
                <a href="inventory_print.php" class="list-group-item list-group-item-action bg-transparent">
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
                <a href="logout.php" class="list-group-item list-group-item-action bg-transparent">
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
                        <!-- Mobile Admin Bell Modal -->
                        <div id="adminBellBackdrop" aria-hidden="true"></div>
                        <div id="adminBellModal" role="dialog" aria-modal="true" aria-labelledby="abmTitle">
                          <div class="ubm-box">
                            <div class="ubm-head">
                              <div id="abmTitle" class="small">Borrow Requests</div>
                              <button type="button" class="ubm-close" id="abmCloseBtn" aria-label="Close">&times;</button>
                            </div>
                            <div class="ubm-body">
                              <div id="adminNotifListM" class="list-group list-group-flush small"></div>
                              <div class="text-center small text-muted py-2 d-none" id="adminNotifEmptyM"></div>
                              <div class="border-top p-2 text-center">
                                <a href="admin_borrow_center.php" class="btn btn-sm btn-outline-primary">Go to Borrow Requests</a>
                              </div>
                            </div>
                          </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kpi-grid mb-3">
              <div class="kpi-row-4">
                <div class="kpi-card card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="openKpi('total_items')" style="cursor:pointer;">
                  <div class="card-body">
                    <div class="kpi-rowline">
                      <span class="kpi-title text-muted">Total Items<i class="bi bi-box-seam kpi-icon text-primary"></i></span>
                      <span class="kpi-value fw-bold"><?php echo (int)$totalItems; ?></span>
                    </div>
                  </div>
                </div>
                <div class="kpi-card card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="openKpi('high')" style="cursor:pointer;">
                  <div class="card-body">
                    <div class="kpi-rowline"><span class="kpi-title text-muted">High Stock</span><span class="kpi-value fw-bold text-success"><?php echo (int)$highCount; ?></span></div>
                  </div>
                </div>
                <div class="kpi-card card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="openKpi('low')" style="cursor:pointer;">
                  <div class="card-body">
                    <div class="kpi-rowline"><span class="kpi-title text-muted">Low Stock</span><span class="kpi-value fw-bold text-warning"><?php echo (int)$lowCount; ?></span></div>
                  </div>
                </div>
                <div class="kpi-card card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="openKpi('out')" style="cursor:pointer;">
                  <div class="card-body">
                    <div class="kpi-rowline"><span class="kpi-title text-muted">Out of Stock</span><span class="kpi-value fw-bold text-danger"><?php echo (int)$outCount; ?></span></div>
                  </div>
                </div>
              </div>
              <div class="kpi-row-4">
                <div id="kpiTotalUnits" class="kpi-card card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="window.location.href='inventory.php'" style="cursor:pointer;">
                  <div class="card-body">
                    <div class="kpi-rowline">
                      <span class="kpi-title text-muted">Total Units<i class="bi bi-collection kpi-icon text-info"></i></span>
                      <span class="kpi-value fw-bold"><?php echo (int)($totalUnitsDisplay ?? $totalUnits); ?></span>
                    </div>
                  </div>
                </div>
                <div class="kpi-card card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="openKpi('available')" style="cursor:pointer;">
                  <div class="card-body">
                    <div class="kpi-rowline"><span class="kpi-title text-muted">Available</span><span class="kpi-value fw-bold text-success"><?php echo (int)($availableCount ?? 0); ?></span></div>
                  </div>
                </div>
                <div class="kpi-card card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="openKpi('in_use')" style="cursor:pointer;">
                  <div class="card-body">
                    <div class="kpi-rowline"><span class="kpi-title text-muted">In Use</span><span class="kpi-value fw-bold text-primary"><?php echo (int)($inUseCount ?? 0); ?></span></div>
                  </div>
                </div>
                <div class="kpi-card card border-0 shadow-sm h-100" role="button" tabindex="0" onclick="openKpi('reserved')" style="cursor:pointer;">
                  <div class="card-body">
                    <div class="kpi-rowline"><span class="kpi-title text-muted">Reserved</span><span class="kpi-value fw-bold text-info"><?php echo (int)($reservedCount ?? 0); ?></span></div>
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
            const abBackdrop = document.getElementById('adminBellBackdrop');
            const abModal = document.getElementById('adminBellModal');
            const abClose = document.getElementById('abmCloseBtn');
            const listElM = document.getElementById('adminNotifListM');
            const emptyElM = document.getElementById('adminNotifEmptyM');
            if (bellWrap) { bellWrap.classList.remove('d-none'); }

            let latestTs = 0;
            function isMobile(){ try{ return window.matchMedia && window.matchMedia('(max-width: 768px)').matches; } catch(_){ return window.innerWidth <= 768; } }
            function copyAdminToMobile(){ try{ if (listElM) listElM.innerHTML = listEl ? listEl.innerHTML : ''; if (emptyElM) emptyElM.style.display = emptyEl ? emptyEl.style.display : ''; }catch(_){ } }
            function openAdminModal(){ if (!abModal || !abBackdrop) return; copyAdminToMobile(); abModal.style.display='flex'; abBackdrop.style.display='block'; try{ document.body.style.overflow='hidden'; }catch(_){ } }
            function closeAdminModal(){ if (!abModal || !abBackdrop) return; abModal.style.display='none'; abBackdrop.style.display='none'; try{ document.body.style.overflow=''; }catch(_){ } }
            if (bellBtn && dropdown) {
                bellBtn.addEventListener('click', function(e){
                    e.stopPropagation();
                    if (isMobile()) {
                        try { const nowTs = latestTs || Date.now(); localStorage.setItem('admin_notif_last_open', String(nowTs)); } catch(_){ }
                        if (bellDot) bellDot.classList.add('d-none');
                        try { copyAdminToMobile(); } catch(_){ }
                        openAdminModal();
                    } else {
                        dropdown.classList.toggle('show');
                        dropdown.style.position = 'absolute';
                        dropdown.style.transform = 'none';
                        dropdown.style.top = (bellBtn.offsetTop + bellBtn.offsetHeight + 6) + 'px';
                        dropdown.style.left = (bellBtn.offsetLeft - (dropdown.offsetWidth - bellBtn.offsetWidth)) + 'px';
                        if (bellDot) bellDot.classList.add('d-none');
                        try { const nowTs = latestTs || Date.now(); localStorage.setItem('admin_notif_last_open', String(nowTs)); } catch(_){ }
                    }
                });
                if (abBackdrop) abBackdrop.addEventListener('click', closeAdminModal);
                if (abClose) abClose.addEventListener('click', closeAdminModal);
                document.addEventListener('click', function(ev){
                    const t = ev.target;
                    if (t && t.closest && (t.closest('#adminBellDropdown') || t.closest('#adminBellBtn') || t.closest('#adminBellWrap') || t.closest('#adminBellModal'))) return;
                    dropdown.classList.remove('show');
                    try { closeAdminModal(); }catch(_){ }
                });
            }

            let toastWrap = document.getElementById('adminToastWrap');
            if (!toastWrap) { toastWrap=document.createElement('div'); toastWrap.id='adminToastWrap'; toastWrap.style.position='fixed'; toastWrap.style.right='16px'; toastWrap.style.bottom='16px'; toastWrap.style.zIndex='1030'; document.body.appendChild(toastWrap); }
            function adjustAdminToastOffset(){
                try{
                    var tw=document.getElementById('adminToastWrap'); if(!tw) return;
                    // Default positions
                    var baseRight = (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) ? 14 : 16;
                    tw.style.right = baseRight + 'px';
                    var bottomPx = 16;
                    try{
                        if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
                            var nav = document.querySelector('.bottom-nav');
                            var hidden = nav && nav.classList && nav.classList.contains('hidden');
                            if (nav && !hidden){
                                var rect = nav.getBoundingClientRect();
                                var h = Math.round(Math.max(0, window.innerHeight - rect.top));
                                if (!h || !isFinite(h)) h = 64;
                                bottomPx = h + 12; // 12px breathing room above nav
                            } else {
                                // Nav hidden: keep above the floating toggle button
                                var btn = document.querySelector('.bottom-nav-toggle');
                                if (btn){
                                    var br = btn.getBoundingClientRect();
                                    var bh = Math.round(Math.max(0, window.innerHeight - br.top));
                                    if (!bh || !isFinite(bh)) bh = 64;
                                    bottomPx = bh + 12; // above toggle
                                } else {
                                    bottomPx = 16;
                                }
                            }
                        }
                    }catch(_){ bottomPx = 64; }
                    tw.style.bottom = String(bottomPx) + 'px';
                }catch(_){ }
            }
            try { window.addEventListener('resize', adjustAdminToastOffset); } catch(_){ }
            try { adjustAdminToastOffset(); } catch(_){ }
            try { window.__adm_adjust_toast = adjustAdminToastOffset; } catch(_){ }
            function attachSwipeForToast(el){
                try{
                    let sx=0, sy=0, dx=0, moving=false, removed=false;
                    const onStart=(ev)=>{ try{ const t=ev.touches?ev.touches[0]:ev; sx=t.clientX; sy=t.clientY; dx=0; moving=true; el.style.willChange='transform,opacity'; el.classList.add('toast-slide'); el.style.transition='none'; }catch(_){}};
                    const onMove=(ev)=>{ if(!moving||removed) return; try{ const t=ev.touches?ev.touches[0]:ev; dx=t.clientX - sx; const adx=Math.abs(dx); const od=1 - Math.min(1, adx/140); el.style.transform='translateX('+dx+'px)'; el.style.opacity=String(od); }catch(_){}};
                    const onEnd=()=>{ if(!moving||removed) return; moving=false; try{ el.style.transition='transform 180ms ease, opacity 180ms ease'; const adx=Math.abs(dx); if (adx>80){ removed=true; el.classList.add(dx>0?'toast-remove-right':'toast-remove-left'); setTimeout(()=>{ try{ el.remove(); }catch(_){ } }, 200); } else { el.style.transform=''; el.style.opacity=''; } }catch(_){ } };
                    el.addEventListener('touchstart', onStart, {passive:true});
                    el.addEventListener('touchmove', onMove, {passive:true});
                    el.addEventListener('touchend', onEnd, {passive:true});
                }catch(_){ }
            }
            function showToast(msg){ const el=document.createElement('div'); el.className='alert alert-info shadow-sm border-0 toast-slide toast-enter'; el.style.minWidth='300px'; el.style.maxWidth='340px'; try{ if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='180px'; el.style.maxWidth='200px'; el.style.fontSize='12px'; } }catch(_){ } el.innerHTML='<i class="bi bi-bell me-2"></i>'+String(msg||''); toastWrap.appendChild(el); adjustAdminToastOffset(); attachSwipeForToast(el); setTimeout(()=>{ try{ el.classList.add('toast-fade-out'); setTimeout(()=>{ try{ el.remove(); }catch(_){ } }, 220); }catch(_){ } }, 5000); }
            let audioCtx = null; function playBeep(){ try{ if(!audioCtx) audioCtx=new (window.AudioContext||window.webkitAudioContext)(); if (audioCtx.state==='suspended'){ try{ audioCtx.resume(); }catch(_e){} } const o=audioCtx.createOscillator(), g=audioCtx.createGain(); o.type='square'; o.frequency.setValueAtTime(880, audioCtx.currentTime); g.gain.setValueAtTime(0.0001, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.35, audioCtx.currentTime+0.03); g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime+0.6); o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime+0.65);}catch(_){}}
            function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
            function fmt12(txt){ try{ const s=String(txt||'').trim(); const m=s.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/); if(!m) return s; const date=m[1]; const H=parseInt(m[2],10); const mm=m[3]; const ap=(H>=12?'pm':'am'); let h=H%12; if(h===0) h=12; return date+' '+h+':'+mm+ap; } catch(_){ return String(txt||''); } }

            let baselineIds = new Set();
            let initialized = false;
            let fetching = false;
            function renderCombined(pending, recent){
                const rows = [];
                latestTs = 0;
                (pending||[]).forEach(function(r){
                    const id = parseInt(r.id||0,10);
                    const user = String(r.username||'');
                    const nm = String(r.item_name||'');
                    const qty = parseInt(r.quantity||1,10);
                    const whenTxt = String(r.created_at||'');
                    rows.push('<a href="admin_borrow_center.php" class="list-group-item list-group-item-action">'
                      + '<div class="d-flex w-100 justify-content-between">'
                      +   '<strong>#'+id+'</strong>'
                      +   '<small class="text-muted">'+escapeHtml(fmt12(whenTxt))+'</small>'
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
                    const whenTxt = String(r.processed_at||'');
                    let bcls = 'badge bg-secondary';
                    if (st==='Approved' || st==='Returned') bcls = 'badge bg-success';
                    else if (st==='Rejected') bcls = 'badge bg-danger';
                    rows.push('<div class="list-group-item d-flex justify-content-between align-items-start">'
                      + '<div class="me-2">'
                      +   '<div class="d-flex w-100 justify-content-between"><strong>#'+id+' '+escapeHtml(nm)+'</strong><small class="text-muted">'+escapeHtml(fmt12(whenTxt))+'</small></div>'
                      +   '<div class="small">Status: <span class="'+bcls+'">'+escapeHtml(st)+'</span></div>'
                      + '</div>'
                      + '<div><button type="button" class="btn-close adm-clear-one" aria-label="Clear" data-id="'+id+'"></button></div>'
                      + '</div>');
                  });
                }
                listEl.innerHTML = rows.join('');
                emptyEl.style.display = rows.length ? 'none' : '';
            }
            document.addEventListener('click', function(ev){
                const one = ev.target && ev.target.closest && ev.target.closest('.adm-clear-one');
                if (one){ ev.preventDefault();
                    const rid = parseInt(one.getAttribute('data-id')||'0',10)||0;
                    if (!rid) return;
                    const fd = new FormData(); fd.append('request_id', String(rid));
                    fetch('admin_borrow_center.php?action=admin_notif_clear', { method:'POST', body: fd })
                      .then(r=>r.json()).then(()=>{ poll(); try{ if (abModal && abModal.style && abModal.style.display==='flex') copyAdminToMobile(); }catch(_){ } }).catch(()=>{});
                    return;
                }
                if (ev.target && ev.target.id === 'admClearAllBtn'){ ev.preventDefault();
                    const fd = new FormData(); fd.append('limit','300');
                    fetch('admin_borrow_center.php?action=admin_notif_clear_all', { method:'POST', body: fd })
                      .then(r=>r.json()).then(()=>{ poll(); try{ if (abModal && abModal.style && abModal.style.display==='flex') copyAdminToMobile(); }catch(_){ } }).catch(()=>{});
                }
            });

            function poll(){
                if (fetching) return; fetching = true;
                fetch('admin_borrow_center.php?action=admin_notifications')
                  .then(r=>r.json())
                  .then(d=>{
                    const pending = (d && Array.isArray(d.pending)) ? d.pending : [];
                    const recent = (d && Array.isArray(d.recent)) ? d.recent : [];
                    renderCombined(pending, recent);
                    try { if (abModal && abModal.style && abModal.style.display==='flex') copyAdminToMobile(); }catch(_){ }
                    try { const showDot = pending.length > 0; if (bellDot) bellDot.classList.toggle('d-none', !showDot); } catch(_){ if (bellDot) bellDot.classList.toggle('d-none', pending.length===0); }
                    try {
                      const navLink = document.querySelector('a[href="admin_borrow_center.php"]');
                      if (navLink) {
                        let dot = navLink.querySelector('.nav-borrow-dot');
                        const shouldShow = pending.length > 0;
                        if (shouldShow) {
                          if (!dot) { dot = document.createElement('span'); dot.className = 'nav-borrow-dot ms-2 d-inline-block rounded-circle'; dot.style.width='8px'; dot.style.height='8px'; dot.style.backgroundColor='#dc3545'; dot.style.verticalAlign='middle'; dot.style.display='inline-block'; navLink.appendChild(dot); }
                          else { dot.style.display = 'inline-block'; }
                        } else if (dot) { dot.style.display = 'none'; }
                      }
                    } catch(_){ }
                    const currIds = new Set(pending.map(it=>parseInt(it.id||0,10)));
                    if (!initialized) { baselineIds = currIds; initialized = true; }
                    else {
                      let hasNew = false; currIds.forEach(id=>{ if(!baselineIds.has(id)) hasNew = true; });
                      if (hasNew) { pending.forEach(it=>{ const id=parseInt(it.id||0,10); if(!baselineIds.has(id)){ showToast('New request: '+(it.username||'')+' → '+(it.item_name||'')+' (x'+(it.quantity||1)+')'); } }); playBeep(); }
                      baselineIds = currIds;
                    }
                  })
                  .catch(()=>{})
                  .finally(()=>{ fetching = false; });
            }
            poll();
            setInterval(()=>{ if (document.visibilityState === 'visible') poll(); }, 1000);
            // Also poll user self-return feed (return_events) for side toasts
            let retBase = new Set(); let retInit=false; let retFetching=false;
            function pollUserReturns(){ if (retFetching) return; retFetching = true;
              fetch('admin_borrow_center.php?action=return_feed')
                .then(r=>r.json())
                .then(d=>{ const list=(d&&d.ok&&Array.isArray(d.returns))?d.returns:[]; const ids=new Set(list.map(v=>parseInt(v.id||0,10)).filter(n=>n>0)); if(!retInit){ retBase=ids; retInit=true; return; } let ding=false; list.forEach(v=>{ const id=parseInt(v.id||0,10); if(!retBase.has(id)){ ding=true; const name=String(v.model_name||''); const sn=String(v.qr_serial_no||''); const loc=String(v.location||''); showToast('User returned '+(name?name+' ':'')+(sn?('['+sn+']'):'')+(loc?(' @ '+loc):''), 'alert-success'); } }); if(ding){ try{ playBeep(); }catch(_){ } } retBase=ids; })
                .catch(()=>{})
                .finally(()=>{ retFetching=false; });
            }
            pollUserReturns();
            setInterval(()=>{ if (document.visibilityState === 'visible') pollUserReturns(); }, 2000);
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
        const availableUnits = <?php echo json_encode($availableUnits, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK); ?>;
        const inUseUnits = <?php echo json_encode($inUseUnits, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK); ?>;
        const reservedUnits = <?php echo json_encode($reservedUnits, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK); ?>;
        function openKpi(kind) {
            const titleMap = {
                total_items: 'All Items',
                total_units: 'Items Contributing to Total Units',
                low: 'Low Stock (<10)',
                out: 'Out of Stock',
                high: 'High Stock (>50)',
                available: 'Available Units (Borrowable)',
                in_use: 'In Use Units',
                reserved: 'Reserved Units (Per Unit)'
            };
            const rows = [];
            if (kind === 'out') {
                (outBorrowables || []).forEach(function(r){ rows.push(r); });
            } else if (kind === 'available') {
                (availableUnits || []).forEach(function(r){ rows.push(r); });
            } else if (kind === 'in_use') {
                (inUseUnits || []).forEach(function(r){ rows.push(r); });
            } else if (kind === 'reserved') {
                (reservedUnits || []).forEach(function(r){ rows.push(r); });
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
                    } else if (kind === 'available') {
                        theadRow.innerHTML = '<th>Serial ID</th><th>Item</th><th>Category</th><th>Location</th><th>Remarks</th>';
                    } else if (kind === 'in_use') {
                        theadRow.innerHTML = '<th>Serial ID</th><th>Item</th><th>Location</th><th>User</th>';
                    } else if (kind === 'reserved') {
                        theadRow.innerHTML = '<th>Serial ID</th><th>Item</th><th>Reserve Start</th><th>Reserve End</th><th>Reservations</th><th>Location</th><th>User</th>';
                    } else {
                        theadRow.innerHTML = '<th>Item</th><th class="text-end">Units</th>';
                    }
                }
                rows.forEach(r => {
                    const tr = document.createElement('tr');
                    const td1 = document.createElement('td');
                    td1.textContent = String(r.item_name ?? '');
                    if (kind === 'out') {
                        const tdCat = document.createElement('td');
                        tdCat.textContent = String(r.category || '');
                        const tdDate = document.createElement('td');
                        tdDate.textContent = String(r.oos_date || '');
                        tr.appendChild(td1);
                        tr.appendChild(tdCat);
                        tr.appendChild(tdDate);
                    } else if (kind === 'available') {
                        const tdSerial = document.createElement('td');
                        tdSerial.textContent = String(r.serial_no ?? '');
                        const tdItem = document.createElement('td'); tdItem.textContent = String(r.item_name ?? '');
                        const tdCat = document.createElement('td');
                        tdCat.textContent = String(r.category || '');
                        const tdLoc = document.createElement('td');
                        tdLoc.textContent = String(r.location || '');
                        const tdRem = document.createElement('td');
                        tdRem.textContent = String(r.remarks || '');
                        tr.appendChild(tdSerial);
                        tr.appendChild(tdItem);
                        tr.appendChild(tdCat);
                        tr.appendChild(tdLoc);
                        tr.appendChild(tdRem);
                    } else if (kind === 'in_use') {
                        const tdSerial = document.createElement('td');
                        tdSerial.textContent = String(r.serial_no ?? '');
                        const tdItem = document.createElement('td'); tdItem.textContent = String(r.item_name ?? '');
                        const tdLoc = document.createElement('td');
                        tdLoc.textContent = String(r.location || '');
                        const tdUser = document.createElement('td');
                        tdUser.textContent = String(r.full_name || '');
                        tr.appendChild(tdSerial);
                        tr.appendChild(tdItem);
                        tr.appendChild(tdLoc);
                        tr.appendChild(tdUser);
                    } else if (kind === 'reserved') {
                        const tdSerial = document.createElement('td');
                        tdSerial.textContent = String(r.serial_no ?? '');
                        const tdItem = document.createElement('td'); tdItem.textContent = String(r.item_name ?? '');
                        const tdS = document.createElement('td');
                        tdS.textContent = String(r.start || '');
                        const tdE = document.createElement('td');
                        tdE.textContent = String(r.end || '');
                        const tdC = document.createElement('td');
                        tdC.textContent = String(r.count || 0);
                        const tdL = document.createElement('td');
                        tdL.textContent = String(r.location || '');
                        const tdU = document.createElement('td');
                        tdU.textContent = String(r.full_name || '');
                        tr.appendChild(tdSerial);
                        tr.appendChild(tdItem);
                        tr.appendChild(tdS);
                        tr.appendChild(tdE);
                        tr.appendChild(tdC);
                        tr.appendChild(tdL);
                        tr.appendChild(tdU);
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
              toastWrap.style.position='fixed'; toastWrap.style.right='16px'; toastWrap.style.bottom='16px'; toastWrap.style.zIndex='1030';
              document.body.appendChild(toastWrap);
            }
            function attachSwipeForToast(el){ try{ var sx=0, sy=0, dx=0, moving=false, removed=false; var onStart=function(ev){ try{ var t=ev.touches?ev.touches[0]:ev; sx=t.clientX; sy=t.clientY; dx=0; moving=true; el.style.willChange='transform,opacity'; el.classList.add('toast-slide'); el.style.transition='none'; }catch(_){}}; var onMove=function(ev){ if(!moving||removed) return; try{ var t=ev.touches?ev.touches[0]:ev; dx=t.clientX - sx; var adx=Math.abs(dx); var od=1 - Math.min(1, adx/140); el.style.transform='translateX('+dx+'px)'; el.style.opacity=String(od); }catch(_){}}; var onEnd=function(){ if(!moving||removed) return; moving=false; try{ el.style.transition='transform 180ms ease, opacity 180ms ease'; var adx=Math.abs(dx); if (adx>80){ removed=true; el.classList.add(dx>0?'toast-remove-right':'toast-remove-left'); setTimeout(function(){ try{ el.remove(); }catch(_){ } }, 200); } else { el.style.transform=''; el.style.opacity=''; } }catch(_){ } }; el.addEventListener('touchstart', onStart, {passive:true}); el.addEventListener('touchmove', onMove, {passive:true}); el.addEventListener('touchend', onEnd, {passive:true}); }catch(_){ } }
            function showToast(msg, cls){ var el=document.createElement('div'); el.className='alert '+(cls||'alert-success')+' shadow-sm border-0 toast-slide toast-enter'; el.style.minWidth='300px'; el.style.maxWidth='340px'; try{ if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='180px'; el.style.maxWidth='200px'; el.style.fontSize='12px'; } }catch(_){ } el.innerHTML='<i class="bi bi-bell me-2"></i>'+String(msg||''); toastWrap.appendChild(el); try{ if (typeof window.__adm_adjust_toast==='function') window.__adm_adjust_toast(); }catch(_){ } attachSwipeForToast(el); setTimeout(function(){ try{ el.classList.add('toast-fade-out'); setTimeout(function(){ try{ el.remove(); }catch(_){ } }, 220); }catch(_){ } }, 5000); }
            function playBeep(){ try{ var ctx = new (window.AudioContext||window.webkitAudioContext)(); var o = ctx.createOscillator(); var g = ctx.createGain(); o.type='triangle'; o.frequency.setValueAtTime(880, ctx.currentTime); g.gain.setValueAtTime(0.0001, ctx.currentTime); o.connect(g); g.connect(ctx.destination); o.start(); g.gain.exponentialRampToValueAtTime(0.1, ctx.currentTime+0.01); g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime+0.4); o.stop(ctx.currentTime+0.45); } catch(_e){} }
            var baseVerif = new Set(); var initFeed=false; var feeding=false;
            function pollVerif(){ if (feeding) return; feeding=true;
              fetch('admin_borrow_center.php?action=returnship_feed')
                .then(function(r){ return r.json(); })
                .then(function(d){ var list = (d && d.ok && Array.isArray(d.verifications)) ? d.verifications : []; var ids = new Set(list.map(function(v){ return parseInt(v.id||0,10); }).filter(function(n){ return n>0; })); if (!initFeed){ baseVerif = ids; initFeed=true; return; } var ding=false; list.forEach(function(v){ var id=parseInt(v.id||0,10); if (!baseVerif.has(id)){ ding=true; var name=String(v.model_name||''); var sn=String(v.qr_serial_no||''); var loc=String(v.location||''); showToast('User returned via QR: '+(name?name+' ':'')+(sn?('['+sn+']'):'')+(loc?(' @ '+loc):''), 'alert-success'); } }); if (ding) playBeep(); baseVerif = ids; })
                .catch(function(){})
                .finally(function(){ feeding=false; });
            }
            pollVerif(); setInterval(function(){ if (document.visibilityState==='visible') pollVerif(); }, 1000);
            window.__adm_adjust_toast = function(){ try{ var wrap=document.getElementById('adminToastWrap'); if(!wrap) return; var nav=document.getElementById('dashBottomNav'); var btn=document.getElementById('bnToggleDash'); var bottom=16; if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ try{ if (nav && !nav.classList.contains('hidden')) { bottom = 78 + 16; } }catch(_){ } } wrap.style.bottom = bottom+'px'; }catch(_){ } };
          } catch(_e){
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
      <a href="logout.php" aria-label="Logout">
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
      </a>
    </nav>
    <script>
      (function(){
        try{
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
                try { if (typeof window.__adm_adjust_toast === 'function') window.__adm_adjust_toast(); } catch(_){ }
              } else {
                btn.classList.remove('raised');
                btn.title = 'Open menu';
                var i2 = btn.querySelector('i'); if (i2) { i2.className = 'bi bi-list'; }
                try { if (typeof window.__adm_adjust_toast === 'function') window.__adm_adjust_toast(); } catch(_){ }
              }
            });
          }
          var p=(location.pathname.split('/').pop()||'').split('?')[0].toLowerCase();
          document.querySelectorAll('.bottom-nav a[href]').forEach(function(a){
            var h=(a.getAttribute('href')||'').split('?')[0].toLowerCase();
            if(h===p){ a.classList.add('active'); a.setAttribute('aria-current','page'); }
          });
        }catch(_){ }
      })();
    </script>
  
</body>
<script src="page-transitions.js?v=<?php echo filemtime(__DIR__.'/page-transitions.js'); ?>"></script>
</html>
