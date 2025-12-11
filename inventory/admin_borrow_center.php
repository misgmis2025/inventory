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
// If not authorized, avoid redirecting for print endpoints so we don't print the login page
$__act = $_GET['action'] ?? '';
if (!isset($_SESSION['username']) || $_SESSION['usertype'] !== 'admin') {
  if ($__act === 'print_overdue') {
    http_response_code(401);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Not authorized</title></head><body>Not authorized</body></html>';
    exit();
  }
  header('Location: index.php');
  exit();
}
require_once __DIR__ . '/auth.php';
inventory_redirect_if_disabled();
// Action routing (define early so it's available to all handlers below)
$act = $_GET['action'] ?? '';
// Resolve current admin full name for autofill (fallback to username)
$adminFullNameDefault = (string)($_SESSION['username'] ?? '');
try {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  @require_once __DIR__ . '/db/fcm.php';
  $dbName = get_mongo_db();
  $uDocAF = $dbName->selectCollection('users')->findOne(['username' => ($_SESSION['username'] ?? '')], ['projection' => ['full_name' => 1]]);
  $fnAF = $uDocAF && isset($uDocAF['full_name']) ? trim((string)$uDocAF['full_name']) : '';
  if ($fnAF !== '') { $adminFullNameDefault = $fnAF; }
} catch (Throwable $_eAF) { /* ignore */ }

if (!function_exists('ab_fcm_notify_request_status')) {
  function ab_fcm_notify_request_status($db, $reqDoc, string $newStatus, string $reason = ''): void {
    if (!$db || !is_object($db)) { return; }
    if (!function_exists('fcm_send_to_user_tokens')) { return; }
    if (!is_array($reqDoc) && !($reqDoc instanceof ArrayAccess)) {
      try { $reqDoc = (array)$reqDoc; } catch (Throwable $_) { return; }
    }
    try {
      $uname = trim((string)($reqDoc['username'] ?? ''));
      if ($uname === '') { return; }
      $rid = (int)($reqDoc['id'] ?? 0);
      $item = trim((string)($reqDoc['item_name'] ?? ''));
      $qty  = max(1, (int)($reqDoc['quantity'] ?? 1));
      $statusLc = strtolower($newStatus);
      $itemPart = $item !== '' ? $item : 'your request';
      if ($qty > 1 && $item !== '') { $itemPart .= ' (x' . $qty . ')'; }
      $verb = $newStatus;
      if ($statusLc === 'approved') { $verb = 'approved'; }
      elseif ($statusLc === 'borrowed') { $verb = 'approved and marked Borrowed'; }
      elseif ($statusLc === 'rejected') { $verb = 'rejected'; }
      elseif ($statusLc === 'returned') { $verb = 'marked Returned'; }
      elseif ($statusLc === 'lost') { $verb = 'marked Lost'; }
      elseif ($statusLc === 'under maintenance') { $verb = 'marked Under Maintenance'; }

      $title = 'Request update';
      $body = 'Your request';
      if ($rid > 0) { $body .= ' #' . $rid; }
      if ($itemPart !== '') { $body .= ' for ' . $itemPart; }
      $body .= ' was ' . $verb . '.';
      if ($statusLc === 'returned') {
        $body = 'Your borrowed item from request #' . ($rid ?: '') . ' has been marked Returned.';
      }
      if ($statusLc === 'rejected' && $reason !== '') {
        $body .= ' Reason: ' . $reason;
      }

      $target = fcm_full_url('/inventory/user_request.php');
      $extra = [
        'status' => $newStatus,
        'request_id' => $rid,
        'type' => (string)($reqDoc['type'] ?? ''),
      ];
      if ($reason !== '') { $extra['reason'] = $reason; }
      try {
        fcm_send_to_user_tokens($db, $uname, $title, $body, $target, $extra);
      } catch (Throwable $_f) { /* ignore push errors */ }
    } catch (Throwable $_outer) { /* ignore */ }
  }
}
// Print Overdue Items (admin) using the same data as overdue_json
if ($act === 'print_overdue' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  @header('Content-Type: text/html; charset=UTF-8');
  $prepared = trim($_GET['prepared_by'] ?? '');
  $checked  = trim($_GET['checked_by'] ?? '');
  $dateVal  = trim($_GET['date'] ?? date('Y-m-d'));
  // Auto-fill Prepared by with admin's full name (fallback to username) if not provided
  if ($prepared === '') {
    $prepared = isset($adminFullNameDefault) && trim((string)$adminFullNameDefault) !== ''
      ? (string)$adminFullNameDefault
      : (string)($_SESSION['username'] ?? '');
  }
  try {
    $db = get_mongo_db();
    $ub = $db->selectCollection('user_borrows');
    $ra = $db->selectCollection('request_allocations');
    $er = $db->selectCollection('equipment_requests');
    $ii = $db->selectCollection('inventory_items');
    $users = $db->selectCollection('users');
    $now = date('Y-m-d H:i:s');
    $rows = [];
    // Consider any active borrow (not returned), regardless of status label
    $cur = $ub->find(['$or' => [ ['returned_at' => null], ['returned_at' => ''] ]]);
    foreach ($cur as $b) {
      $bid = (int)($b['id'] ?? 0);
      $mid = (int)($b['model_id'] ?? 0);
      // Resolve due date: reservation reserved_to > request expected_return_at > borrow expected_return_at
      $dueAt = (string)($b['expected_return_at'] ?? '');
      try {
        if (isset($b['expected_return_at']) && $b['expected_return_at'] instanceof MongoDB\BSON\UTCDateTime) {
          $dtE = $b['expected_return_at']->toDateTime();
          $dtE->setTimezone(new DateTimeZone('Asia/Manila'));
          $dueAt = $dtE->format('Y-m-d H:i:s');
        }
      } catch (Throwable $_) {}
      try {
        if (isset($b['expected_return_at']) && $b['expected_return_at'] instanceof MongoDB\BSON\UTCDateTime) {
          $dtE = $b['expected_return_at']->toDateTime();
          $dtE->setTimezone(new DateTimeZone('Asia/Manila'));
          $dueAt = $dtE->format('Y-m-d H:i:s');
        }
      } catch (Throwable $_d1) { /* ignore */ }
      try {
        if (isset($b['expected_return_at']) && $b['expected_return_at'] instanceof MongoDB\BSON\UTCDateTime) {
          $dtE = $b['expected_return_at']->toDateTime();
          $dtE->setTimezone(new DateTimeZone('Asia/Manila'));
          $dueAt = $dtE->format('Y-m-d H:i:s');
        }
      } catch (Throwable $_) {}
      try {
        if (isset($b['expected_return_at']) && $b['expected_return_at'] instanceof MongoDB\BSON\UTCDateTime) {
          $dtE = $b['expected_return_at']->toDateTime();
          $dtE->setTimezone(new DateTimeZone('Asia/Manila'));
          $dueAt = $dtE->format('Y-m-d H:i:s');
        }
      } catch (Throwable $_) { /* ignore */ }
      try {
        if (isset($b['expected_return_at']) && $b['expected_return_at'] instanceof MongoDB\BSON\UTCDateTime) {
          $dtE = $b['expected_return_at']->toDateTime();
          $dtE->setTimezone(new DateTimeZone('Asia/Manila'));
          $dueAt = $dtE->format('Y-m-d H:i:s');
        }
      } catch (Throwable $_d1) { /* ignore */ }
      $approvedBy = '';
      $reqType = '';
      $borrowerUser = (string)($b['username'] ?? '');
      $borrowerFull = $borrowerUser;
      $borrowerSid = '';
      try {
        $u = $users->findOne(['username'=>$borrowerUser], ['projection'=>['full_name'=>1,'school_id'=>1]]);
        if ($u) {
          if (isset($u['full_name']) && trim((string)$u['full_name'])!=='') { $borrowerFull = (string)$u['full_name']; }
          if (isset($u['school_id'])) { $borrowerSid = (string)$u['school_id']; }
        }
      } catch (Throwable $_e) {}
      // link to request via allocation if exists
      $alloc = $ra->findOne(['borrow_id' => $bid]);
      if ($alloc && isset($alloc['request_id'])) {
        $rid = (int)$alloc['request_id'];
        $req = $er->findOne(['id'=>$rid]);
        if ($req) {
          $reqType = (string)($req['type'] ?? 'immediate');
          $approvedBy = (string)($req['approved_by'] ?? '');
          if (strcasecmp($reqType,'reservation')===0) {
            $rt = (string)($req['reserved_to'] ?? '');
            try { if (isset($req['reserved_to']) && $req['reserved_to'] instanceof MongoDB\BSON\UTCDateTime) { $d=$req['reserved_to']->toDateTime(); $d->setTimezone(new DateTimeZone('Asia/Manila')); $rt = $d->format('Y-m-d H:i:s'); } } catch (Throwable $_x) {}
            if ($rt !== '') { $dueAt = $rt; }
          } else {
            $rt2 = (string)($req['expected_return_at'] ?? '');
            try { if (isset($req['expected_return_at']) && $req['expected_return_at'] instanceof MongoDB\BSON\UTCDateTime) { $d2=$req['expected_return_at']->toDateTime(); $d2->setTimezone(new DateTimeZone('Asia/Manila')); $rt2 = $d2->format('Y-m-d H:i:s'); } } catch (Throwable $_y) {}
            if ($rt2 !== '') { $dueAt = $rt2; }
          }
        }
      }
      if ($dueAt === '') { continue; }
      if (strtotime($dueAt) && strtotime($dueAt) < strtotime($now)) {
        $it = $mid>0 ? $ii->findOne(['id'=>$mid]) : null;
        $serial = $it ? (string)($it['serial_no'] ?? '') : '';
        $model = $it ? ((string)($it['model'] ?? '') ?: (string)($it['item_name'] ?? '')) : '';
        $category = $it ? ((string)($it['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized';
        $location = $it ? (string)($it['location'] ?? '') : '';
        $remarks = $it ? (string)($it['remarks'] ?? '') : '';
        $rows[] = [
          'serial' => $serial,
          'model' => $model,
          'category' => $category,
          'location' => $location,
          'borrowed_by' => $borrowerFull,
          'school_id' => $borrowerSid,
          'approved_by' => $approvedBy,
          'remarks' => $remarks,
          'due_at' => $dueAt,
        ];
      }
    }
  } catch (Throwable $e) { $rows = []; }
  ?><!DOCTYPE html>
  <html lang="en"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overdue Items Print</title>
    <link href="css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo file_exists(__DIR__.'/images/logo-removebg.png') ? (filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png')) : time(); ?>" />
    <style>
      @page { size: A4 portrait; margin: 0.6in 0.25in 0.25in 0.25in; }
      @media print { .no-print { display:none!important } html,body{ -webkit-print-color-adjust:exact; print-color-adjust:exact; } thead{display:table-header-group} tfoot{display:table-footer-group} .print-wrap{ overflow: visible !important; } }
      .print-table { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 10px; }
      .print-table th, .print-table td { padding: .2rem .25rem; vertical-align: middle; line-height: 1.2; text-align: left; word-break: break-word; white-space: normal; }
      .print-root { padding-top: 12mm; margin-top: 6mm; }
      .eca-header { text-align:center; margin-bottom:10px; }
      .eca-title { font-weight:400; letter-spacing:6px; font-size:14pt; }
      .print-wrap { width: 100%; overflow: visible; }
      .eca-meta { display:flex; align-items:center; justify-content:space-between; font-size:9pt; margin-top:6px; margin-bottom:10px; }
      .report-title { text-align:center; font-weight:400; font-size:14pt; margin:14px 0 14px; text-transform:uppercase; }
      .eca-footer { margin-top:18mm; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:nowrap; }
      .eca-footer .field { display:inline-flex; align-items:center; gap:8px; white-space:nowrap; }
      .eca-print-value { display:inline-block; border-bottom:1px solid #000; padding:0 4px 2px; min-width:180px; }
      .page { page-break-after: always; }
      .page:last-child { page-break-after: auto; }
      .blank-row td { padding-top: .2rem !important; padding-bottom: .2rem !important; }
    </style>
  </head><body>
    <div class="container-fluid pt-3 print-root">
      <?php
        // Use a slightly larger page size so the grid visually fills more of the page
        $pageSize = 20;
        $totalRows = is_array($rows) ? count($rows) : 0;
        $pages = max(1, (int)ceil($totalRows / $pageSize));
        for ($p = 0; $p < $pages; $p++) {
          $slice = array_slice($rows, $p * $pageSize, $pageSize);
          $fill  = $pageSize - count($slice);
      ?>
      <div class="page">
        <div class="eca-header"><div class="eca-title">ECA</div><div class="eca-sub">Exact Colleges of Asia, Inc.</div></div>
        <div class="eca-meta"><div><strong>Date:</strong> <?php echo htmlspecialchars($dateVal); ?></div><div></div></div>
        <div class="report-title">OVERDUE ITEMS</div>
        <div class="print-wrap mb-2">
          <table class="table table-bordered table-sm align-middle print-table">
            <thead class="table-light">
              <tr>
                <th>Serial ID</th>
                <th>Item</th>
                <th>Category</th>
                <th>Location</th>
                <th>Borrowed By</th>
                <th>School ID</th>
                <th>Approved By</th>
                <th>Remarks</th>
                <th>Due At</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($slice)): ?>
                <?php /* no data on this page; will render blank rows below */ ?>
              <?php endif; ?>
              <?php foreach ($slice as $rw): ?>
                <tr>
                  <td><?php echo htmlspecialchars($rw['serial']); ?></td>
                  <td><?php echo htmlspecialchars($rw['model']); ?></td>
                  <td><?php echo htmlspecialchars($rw['category']); ?></td>
                  <td><?php echo htmlspecialchars($rw['location']); ?></td>
                  <td><?php echo htmlspecialchars($rw['borrowed_by']); ?></td>
                  <td><?php echo htmlspecialchars((string)($rw['school_id'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars($rw['approved_by']); ?></td>
                  <td><?php echo htmlspecialchars($rw['remarks']); ?></td>
                  <td><?php echo htmlspecialchars($rw['due_at'] ? date('F d, Y g:iA', strtotime($rw['due_at'])) : ''); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php for ($i = 0; $i < $fill; $i++): ?>
                <tr class="blank-row">
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>

      <!-- no modal JS in print view -->
        <div class="eca-footer">
          <div class="field"><label>Prepared by:</label><span class="eca-print-value"><?php echo htmlspecialchars($prepared); ?>&nbsp;</span></div>
          <div class="field"><label>Checked by:</label><span class="eca-print-value"><?php echo htmlspecialchars($checked); ?>&nbsp;</span></div>
        </div>
      </div>
      <?php } ?>
    </div>
    <script>
      // Auto-trigger print on load for convenience
      window.addEventListener('load', function(){ try{ window.print(); }catch(_){ } });
    </script>
  <script>
    (function(){
      function norm(s){ return String(s||'').toLowerCase(); }
      function setupFilter(inputId, tbodySel, rowSelector, attrKeys){
        var inp = document.getElementById(inputId);
        if (!inp) return;
        function apply(){
          var q = norm(inp.value);
          document.querySelectorAll(tbodySel + ' ' + rowSelector).forEach(function(tr){
            var hay = '';
            (attrKeys||[]).forEach(function(k){ hay += ' ' + norm(tr.getAttribute(k)||''); });
            hay += ' ' + norm(tr.textContent||'');
            tr.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
          });
        }
        inp.addEventListener('input', apply);
      }
      document.addEventListener('DOMContentLoaded', function(){
        setupFilter('pendingSearch', '#pendingTbody', 'tr.pending-row', ['data-user','data-reqid']);
        setupFilter('borrowedSearch', '#borrowedTbody', 'tr.borrowed-row', ['data-user','data-reqid','data-serial','data-model']);
        var od = document.getElementById('overdueOnly');
        if (od) {
          od.addEventListener('change', function(){
            var on = !!od.checked;
            document.querySelectorAll('#borrowedTbody tr.borrowed-row').forEach(function(tr){
              var pass = (!on) || (tr.getAttribute('data-overdue') === '1');
              if (!pass) { tr.style.display = 'none'; return; }
              var qEl = document.getElementById('borrowedSearch');
              var q = qEl ? (qEl.value||'').toLowerCase() : '';
              if (q) {
                var txt = (tr.textContent||'').toLowerCase();
                tr.style.display = (txt.indexOf(q) !== -1) ? '' : 'none';
              } else {
                tr.style.display = '';
              }
            });
          });
        }
        try {
          var tts = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
          tts.map(function(el){ return new bootstrap.Tooltip(el); });
        } catch(_){ }
      });
    })();
  </script>
  </body></html><?php
  exit();
}

// List overdue borrowed items (JSON)
if ($act === 'overdue_json' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $ub = $db->selectCollection('user_borrows');
    $ra = $db->selectCollection('request_allocations');
    $er = $db->selectCollection('equipment_requests');
    $ii = $db->selectCollection('inventory_items');
    $users = $db->selectCollection('users');
    $now = date('Y-m-d H:i:s');
    $items = [];
    // Consider any active borrow (not returned), regardless of status label
    $cur = $ub->find(['$or' => [ ['returned_at' => null], ['returned_at' => ''] ]]);
    foreach ($cur as $b) {
      $bid = (int)($b['id'] ?? 0);
      $mid = (int)($b['model_id'] ?? 0);
      // Resolve due date: reservation reserved_to > request expected_return_at > borrow expected_return_at
      $dueAt = (string)($b['expected_return_at'] ?? '');
      $approvedBy = '';
      $reqType = '';
      $borrowerUser = (string)($b['username'] ?? '');
      $borrowerFull = $borrowerUser;
      $borrowerSid = '';
      try { $u = $users->findOne(['username'=>$borrowerUser], ['projection'=>['full_name'=>1,'school_id'=>1]]); if ($u) { if (isset($u['full_name']) && trim((string)$u['full_name'])!=='') { $borrowerFull = (string)$u['full_name']; } if (isset($u['school_id'])) { $borrowerSid = (string)$u['school_id']; } } } catch (Throwable $_e) {}
      // link to request via allocation if exists
      $alloc = $ra->findOne(['borrow_id' => $bid]);
      if ($alloc && isset($alloc['request_id'])) {
        $rid = (int)$alloc['request_id'];
        $req = $er->findOne(['id'=>$rid]);
        if ($req) {
          $reqType = (string)($req['type'] ?? 'immediate');
          $approvedBy = (string)($req['approved_by'] ?? '');
          if (strcasecmp($reqType,'reservation')===0) {
            $rt = (string)($req['reserved_to'] ?? '');
            try { if (isset($req['reserved_to']) && $req['reserved_to'] instanceof MongoDB\BSON\UTCDateTime) { $d=$req['reserved_to']->toDateTime(); $d->setTimezone(new DateTimeZone('Asia/Manila')); $rt = $d->format('Y-m-d H:i:s'); } } catch (Throwable $__) {}
            if ($rt !== '') { $dueAt = $rt; }
          } else {
            $rt2 = (string)($req['expected_return_at'] ?? '');
            try { if (isset($req['expected_return_at']) && $req['expected_return_at'] instanceof MongoDB\BSON\UTCDateTime) { $d2=$req['expected_return_at']->toDateTime(); $d2->setTimezone(new DateTimeZone('Asia/Manila')); $rt2 = $d2->format('Y-m-d H:i:s'); } } catch (Throwable $___) {}
            if ($rt2 !== '') { $dueAt = $rt2; }
          }
        }
      }
      if ($dueAt === '') { continue; }
      if (strtotime($dueAt) && strtotime($dueAt) < strtotime($now)) {
        $it = $mid>0 ? $ii->findOne(['id'=>$mid]) : null;
        $serial = $it ? (string)($it['serial_no'] ?? '') : '';
        $model = $it ? ((string)($it['model'] ?? '') ?: (string)($it['item_name'] ?? '')) : '';
        $category = $it ? ((string)($it['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized';
        $location = $it ? (string)($it['location'] ?? '') : '';
        $remarks = $it ? (string)($it['remarks'] ?? '') : '';
        $items[] = [
          'serial' => $serial,
          'model' => $model,
          'category' => $category,
          'location' => $location,
          'borrowed_by' => $borrowerFull,
          'school_id' => $borrowerSid,
          'approved_by' => $approvedBy,
          'remarks' => $remarks,
          'due_at' => $dueAt,
        ];
      }
    }
    echo json_encode(['ok'=>true, 'items'=>$items]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'items'=>[]]);
  }
  exit();
}
// Request info (admin): return request_location and basic fields for a request id (JSON)
if ($act === 'request_info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $er = $db->selectCollection('equipment_requests');
    $rid = intval($_GET['request_id'] ?? 0);
    if ($rid <= 0) { echo json_encode(['ok'=>false,'reason'=>'Missing id']); exit(); }
    $doc = $er->findOne(['id'=>$rid], ['projection'=>['id'=>1,'item_name'=>1,'request_location'=>1,'qr_serial_no'=>1,'status'=>1,'approved_at'=>1]]);
    if (!$doc) { echo json_encode(['ok'=>false,'reason'=>'Not found']); exit(); }
    $approvedAt = '';
    try {
      if (isset($doc['approved_at']) && $doc['approved_at'] instanceof MongoDB\BSON\UTCDateTime) {
        $dt = $doc['approved_at']->toDateTime();
        $dt->setTimezone(new DateTimeZone('Asia/Manila'));
        $approvedAt = $dt->format('Y-m-d H:i:s');
      } else { $approvedAt = (string)($doc['approved_at'] ?? ''); }
    } catch (Throwable $_) { $approvedAt = (string)($doc['approved_at'] ?? ''); }
    echo json_encode(['ok'=>true,'request'=>[
      'id' => (int)($doc['id'] ?? 0),
      'item_name' => (string)($doc['item_name'] ?? ''),
      'request_location' => (string)($doc['request_location'] ?? ''),
      'qr_serial_no' => (string)($doc['qr_serial_no'] ?? ''),
      'status' => (string)($doc['status'] ?? ''),
      'approved_at' => $approvedAt,
    ]]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'reason'=>'DB unavailable']);
  }
  exit();
}
// Action routing (legacy position; $act already defined above)

// List selectable serials (not yet whitelisted) for a given category and model (JSON)
if ($act === 'selectable_serials' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $itemsCol = $db->selectCollection('inventory_items');
    $buCol = $db->selectCollection('borrowable_units');
    $cat = trim((string)($_GET['category'] ?? ''));
    $model = trim((string)($_GET['model'] ?? ''));
    if ($cat === '' || $model === '') { echo json_encode(['ok'=>false,'items'=>[]]); exit; }
    // Collect ids already whitelisted for this model/category
    $whIds = [];
    $curWh = $buCol->find(['category'=>$cat, 'model_name'=>$model], ['projection'=>['model_id'=>1]]);
    foreach ($curWh as $w) { $mid = (int)($w['model_id'] ?? 0); if ($mid>0) $whIds[$mid] = true; }
    // Get all inventory items for this model/category not yet whitelisted
    $cur = $itemsCol->find([
      '$or' => [ ['model'=>$model], ['item_name'=>$model] ],
      'category' => $cat,
      'status' => 'Available',
      'quantity' => ['$gt' => 0]
    ], ['projection'=>['id'=>1,'serial_no'=>1,'status'=>1,'model'=>1,'item_name'=>1,'quantity'=>1], 'sort'=>['id'=>1]]);
    $list = [];
    foreach ($cur as $it) {
      $mid = (int)($it['id'] ?? 0); if ($mid<=0) continue;
      if (isset($whIds[$mid])) continue;
      $serial = (string)($it['serial_no'] ?? '');
      $st = (string)($it['status'] ?? '');
      $mname = (string)($it['model'] ?? ($it['item_name'] ?? $model));
      $list[] = ['model_id'=>$mid, 'serial_no'=>$serial, 'model'=>$mname, 'status'=>$st];
    }
    echo json_encode(['ok'=>true,'items'=>$list]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'items'=>[]]);
  }
  exit();
}

// List whitelisted serials for a given category/model (JSON)
if ($act === 'list_borrowable_units' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $buCol = $db->selectCollection('borrowable_units');
    $iiCol = $db->selectCollection('inventory_items');
    $erCol = $db->selectCollection('equipment_requests');
    $ubCol = $db->selectCollection('user_borrows');
    $allocCol = $db->selectCollection('request_allocations');
    $bcCol = $db->selectCollection('borrowable_catalog');
    $cat = trim((string)($_GET['category'] ?? ''));
    $model = trim((string)($_GET['model'] ?? ''));
    if ($cat === '' || $model === '') { echo json_encode(['ok'=>false,'items'=>[]]); exit; }
    // Reconcile whitelist with borrow_limit: auto-add Available units up to limit
    try {
      $bm = $bcCol->findOne(['category'=>$cat,'model_name'=>$model], ['projection'=>['borrow_limit'=>1,'active'=>1]]);
      $limit = $bm && isset($bm['borrow_limit']) ? (int)$bm['borrow_limit'] : 0;
      $active = $bm && isset($bm['active']) ? (int)$bm['active'] : 0;
      if ($active === 1 && $limit > 0) {
        $curCount = (int)$buCol->countDocuments(['category'=>$cat,'model_name'=>$model]);
        $need = max(0, $limit - $curCount);
        if ($need > 0) {
          // Get already whitelisted ids to avoid duplicates
          $existing = [];
          foreach ($buCol->find(['category'=>$cat,'model_name'=>$model], ['projection'=>['model_id'=>1]]) as $r) { $existing[] = (int)($r['model_id'] ?? 0); }
          $existing = array_values(array_unique(array_filter($existing)));
          // Find Available items belonging to this model/category not already whitelisted
          $query = [
            'status' => 'Available',
            'quantity' => ['$gt' => 0],
            '$or' => [ ['model'=>$model], ['item_name'=>$model] ],
            'category' => $cat
          ];
          $opts = ['projection'=>['id'=>1], 'sort'=>['id'=>1], 'limit'=> $need*3 /* overfetch a bit in case of dups */ ];
          $toInsert = [];
          foreach ($iiCol->find($query, $opts) as $it) {
            $mid = (int)($it['id'] ?? 0);
            if ($mid>0 && !in_array($mid, $existing, true)) { $toInsert[] = $mid; if (count($toInsert) >= $need) break; }
          }
          foreach ($toInsert as $mid) {
            try { $buCol->insertOne(['model_id'=>$mid,'model_name'=>$model,'category'=>$cat,'created_at'=>date('Y-m-d H:i:s')]); } catch (Throwable $_e) {}
          }
        }
      }
    } catch (Throwable $_recon) { /* ignore */ }
    $list = [];
    $cur = $buCol->find(['category'=>$cat,'model_name'=>$model], ['projection'=>['model_id'=>1,'created_at'=>1]]);
    foreach ($cur as $row) {
      $mid = (int)($row['model_id'] ?? 0); if ($mid<=0) continue;
      $it = $iiCol->findOne(['id'=>$mid], ['projection'=>['serial_no'=>1,'status'=>1,'remarks'=>1,'location'=>1]]);
      // Auto-clean ghost whitelist entries where the inventory item no longer exists or is permanently retired
      if (!$it) {
        try { $buCol->deleteOne(['model_id'=>$mid]); } catch (Throwable $_cl) {}
        continue;
      }
      $rawStatus = (string)($it['status'] ?? '');
      if (in_array($rawStatus, ['Permanently Lost','Disposed'], true)) {
        try { $buCol->deleteOne(['model_id'=>$mid]); } catch (Throwable $_cl2) {}
        continue;
      }
      $location = (string)($it['location'] ?? '');
      $nowStr = date('Y-m-d H:i:s');
      $dispStatus = $rawStatus;
      $inUseStart = '';
      $inUseEnd = '';
      $resFrom = '';
      $resTo = '';
      // If currently borrowed, set In Use with start/end times
      try { $ub = $ubCol->findOne(['model_id'=>$mid,'status'=>'Borrowed'], ['projection'=>['id'=>1,'borrowed_at'=>1]]); } catch (Throwable $_ub) { $ub = null; }
      if ($ub) {
        $dispStatus = 'In Use';
        if (isset($ub['borrowed_at'])) { $inUseStart = (string)$ub['borrowed_at']; }
        try {
          $al = $allocCol->findOne(['borrow_id'=>(int)($ub['id']??0)], ['projection'=>['request_id'=>1]]);
          if ($al && isset($al['request_id'])) {
            $orig = $erCol->findOne(['id'=>(int)$al['request_id']], ['projection'=>['expected_return_at'=>1,'reserved_to'=>1]]);
            if ($orig) {
              $endStr = (string)($orig['expected_return_at'] ?? ($orig['reserved_to'] ?? ''));
              if ($endStr !== '') { $inUseEnd = $endStr; }
            }
          }
        } catch (Throwable $_al) { /* ignore */ }
      } else {
        // Else look for next upcoming/active reservation on this unit
        try {
          $res = $erCol->findOne([
            'type' => 'reservation',
            'status' => 'Approved',
            'reserved_model_id' => $mid,
            '$or' => [ ['reserved_to' => ['$gt' => $nowStr]], ['reserved_to' => ['$exists' => false]] ]
          ], ['projection' => ['reserved_from'=>1,'reserved_to'=>1], 'sort'=>['reserved_from'=>1]]);
          if ($res) {
            $dispStatus = 'Reserved';
            $resFrom = (string)($res['reserved_from'] ?? '');
            $resTo   = (string)($res['reserved_to'] ?? '');
          }
        } catch (Throwable $_rs) { /* ignore */ }
      }
      $list[] = [
        'model_id'       => $mid,
        'serial_no'      => $it ? (string)($it['serial_no'] ?? '') : '',
        'status'         => $dispStatus,
        'location'       => $location,
        'reserved_from'  => $resFrom,
        'reserved_to'    => $resTo,
        'in_use_start'   => $inUseStart,
        'in_use_end'     => $inUseEnd,
        'remarks'        => $it ? (string)($it['remarks'] ?? '') : '',
      ];
    }
    echo json_encode(['ok'=>true,'items'=>$list]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'items'=>[]]);
  }
  exit();
}

// Reservation timeline per serial (JSON)
if ($act === 'reservation_timeline_json' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  if (!isset($_SESSION['username']) || ($_SESSION['usertype'] ?? '') !== 'admin') { http_response_code(401); echo json_encode(['ok'=>false,'reason'=>'unauthorized']); exit(); }
  try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $ii = $db->selectCollection('inventory_items');
    $ub = $db->selectCollection('user_borrows');
    $er = $db->selectCollection('equipment_requests');
    $alloc = $db->selectCollection('request_allocations');
    $users = $db->selectCollection('users');

    $days = max(1, min(60, (int)($_GET['days'] ?? 14)));
    $q = trim((string)($_GET['q'] ?? ''));
    $category = trim((string)($_GET['category'] ?? ''));
    $atRaw = trim((string)($_GET['at'] ?? ''));
    $dayRaw = trim((string)($_GET['day'] ?? ''));
    $atStr = '';
    $dayStart = '';
    $dayEnd = '';
    if ($dayRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayRaw)) {
      $dayStart = $dayRaw . ' 00:00:00';
      $dayEnd = $dayRaw . ' 23:59:59';
    }
    if ($atRaw !== '') {
      $tmp = str_replace('T', ' ', $atRaw);
      $ts = strtotime($tmp);
      if ($ts !== false) { $atStr = date('Y-m-d H:i:s', $ts); }
    }
    // Fallback: if only a date or midnight time was provided, treat as full-day filter
    if ($dayStart === '' && $atRaw !== '') {
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $atRaw)) {
        $dayStart = $atRaw . ' 00:00:00';
        $dayEnd = $atRaw . ' 23:59:59';
        $atStr = '';
      } else if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]00:00/', $atRaw, $m)) {
        $dayStart = $m[1] . ' 00:00:00';
        $dayEnd = $m[1] . ' 23:59:59';
        $atStr = '';
      }
    }
    $now = date('Y-m-d H:i:s');
    $end = date('Y-m-d H:i:s', time() + $days * 86400);
    $norm = function($s){ $s = trim((string)$s); if ($s==='') return ''; $s = str_replace('T',' ', $s); $ts = @strtotime($s); return $ts!==false ? date('Y-m-d H:i:s', $ts) : ''; };

    // Build candidate items (serialized units)
    $match = ['serial_no' => ['$ne' => '']];
    if ($category !== '') { $match['category'] = $category; }
    if ($q !== '') {
      $match['$or'] = [
        ['serial_no' => ['$regex' => $q, '$options' => 'i']],
        ['item_name' => ['$regex' => $q, '$options' => 'i']],
        ['model'     => ['$regex' => $q, '$options' => 'i']],
      ];
    }
    $cur = $ii->find($match, ['projection' => ['id'=>1,'serial_no'=>1,'item_name'=>1,'model'=>1,'category'=>1,'location'=>1], 'limit' => 200]);
    $items = [];
    foreach ($cur as $doc) {
      $id = (int)($doc['id'] ?? 0);
      $serial = (string)($doc['serial_no'] ?? '');
      if ($serial === '') continue;
      $model = (string)($doc['model'] ?? ''); if ($model === '') $model = (string)($doc['item_name'] ?? '');
      $items[] = [
        'id'        => $id,
        'serial_no' => $serial,
        'model'     => $model,
        'category'  => (string)($doc['category'] ?? ''),
        'location'  => (string)($doc['location'] ?? ''),
      ];
    }
    // Categories list for filter (stable, independent of current category/search)
    try {
      $baseMatch = ['serial_no' => ['$ne' => '']];
      $cats = $ii->distinct('category', $baseMatch);
      $cats = array_values(array_filter(array_map('strval', (array)$cats)));
      sort($cats);
    } catch (Throwable $_) {
      $cats = array_values(array_unique(array_filter(array_map(function($r){ return (string)($r['category'] ?? ''); }, $items))));
      sort($cats);
    }

    // Build in-use map by serial
    $inUseMap = [];
    if ($dayStart !== '') {
      // In-use overlapping the whole day: borrowed_at <= end AND (returned_at is null/empty OR returned_at >= start)
      $ubQuery = [
        'borrowed_at' => ['$lte' => $dayEnd],
        '$or' => [ ['returned_at' => null], ['returned_at' => ''], ['returned_at' => ['$gte' => $dayStart]] ]
      ];
      $curUse = $ub->find($ubQuery, ['projection'=>['id'=>1,'model_id'=>1,'serial_no'=>1,'username'=>1,'borrowed_at'=>1,'expected_return_at'=>1,'returned_at'=>1]]);
    } else if ($atStr !== '') {
      // In-use at specific datetime: borrowed_at <= at AND (returned_at is null/empty OR returned_at >= at)
      $ubQuery = [
        'borrowed_at' => ['$lte' => $atStr],
        '$or' => [ ['returned_at' => null], ['returned_at' => ''], ['returned_at' => ['$gte' => $atStr]] ]
      ];
      $curUse = $ub->find($ubQuery, ['projection'=>['id'=>1,'model_id'=>1,'serial_no'=>1,'username'=>1,'borrowed_at'=>1,'expected_return_at'=>1,'returned_at'=>1]]);
    } else {
      // Currently in-use
      $curUse = $ub->find(['$or' => [['returned_at'=>null], ['returned_at'=>'']]], ['projection'=>['id'=>1,'model_id'=>1,'serial_no'=>1,'username'=>1,'borrowed_at'=>1,'expected_return_at'=>1,'returned_at'=>1]]);
    }
    foreach ($curUse as $b) {
      $serial = trim((string)($b['serial_no'] ?? ''));
      if ($serial === '') continue;
      $from = $norm($b['borrowed_at'] ?? '');
      $to = $norm($b['expected_return_at'] ?? '');
      $retAt = $norm($b['returned_at'] ?? '');
      // try linking allocation -> request for better end date if missing
      if ($to === '') {
        try {
          $al = $alloc->findOne(['borrow_id'=>(int)($b['id'] ?? 0)], ['projection'=>['request_id'=>1]]);
          if ($al && isset($al['request_id'])) {
            $rq = $er->findOne(['id'=>(int)$al['request_id']], ['projection'=>['expected_return_at'=>1,'reserved_to'=>1]]);
            if ($rq) { $to = $norm(($rq['expected_return_at'] ?? ($rq['reserved_to'] ?? ''))); }
          }
        } catch (Throwable $_) { }
      }
      // If filtering by a specific datetime, keep only overlaps with [from, effective_to]
      if ($atStr !== '') {
        $ats = @strtotime($atStr);
        $fts = $from !== '' ? @strtotime($from) : false;
        // Effective end: returned_at if present, else expected
        $effTo = $retAt !== '' ? $retAt : $to;
        $tts = $effTo !== '' ? @strtotime($effTo) : false;
        if (!($fts !== false && $ats !== false && $fts <= $ats && ($tts === false || $tts >= $ats))) {
          continue;
        }
        // For display: don't show an end earlier than the selected time when still out
        if ($retAt === '' || (@strtotime(str_replace('T',' ', $retAt)) !== false && @strtotime(str_replace('T',' ', $retAt)) >= $ats)) {
          // If returned_at exists and is after 'at', prefer it for display; else keep expected
          if ($retAt !== '' && (@strtotime($retAt) >= $ats)) {
            $to = $retAt;
          } else if ($to !== '' && (@strtotime($to) < $ats)) {
            $to = $atStr; // overdue at that time, cap display at selected time
          }
        }
      } else if ($dayStart !== '') {
        // Day range: ensure overlap and clamp display within the day
        $startTs = @strtotime($dayStart); $endTs = @strtotime($dayEnd);
        $fts = $from !== '' ? @strtotime($from) : false;
        $effTo = $retAt !== '' ? $retAt : $to; $tts = $effTo !== '' ? @strtotime($effTo) : false;
        if (!($fts !== false && $startTs !== false && $fts <= $endTs && ($tts === false || $tts >= $startTs))) {
          continue;
        }
        if ($from !== '' && $fts < $startTs) { $from = $dayStart; }
        if ($effTo !== '' && $tts > $endTs) { $to = $dayEnd; } else { $to = $effTo!==''?$effTo:$to; }
      }
      // resolve full name
      $uname = (string)($b['username'] ?? '');
      $fname = '';
      try { $u = $users->findOne(['username'=>$uname], ['projection'=>['full_name'=>1]]); if ($u) $fname = trim((string)($u['full_name'] ?? '')); } catch (Throwable $_) { }
      $inUseMap[$serial] = [ 'username'=>$uname, 'full_name'=>($fname!==''?$fname:$uname), 'from'=>$from, 'to'=>$to ];
    }

    // Reservations per serial (approved, overlapping window)
    $resBySerial = [];
    if ($atStr !== '' ) {
      $qRes = [
        'type' => 'reservation', 'status' => 'Approved',
        'reserved_from' => ['$lte' => $atStr],
        '$or' => [ ['reserved_to' => ['$gte' => $atStr]], ['reserved_to' => ['$exists' => false]] ]
      ];
    } else if ($dayStart !== '') {
      $qRes = [
        'type'=>'reservation', 'status'=>'Approved',
        'reserved_from' => ['$lte' => $dayEnd],
        '$or' => [['reserved_to' => ['$gte' => $dayStart]], ['reserved_to' => ['$exists' => false]]]
      ];
    } else {
      $qRes = [
        'type'=>'reservation', 'status'=>'Approved',
        'reserved_from' => ['$lt' => $end],
        '$or' => [['reserved_to' => ['$gt' => $now]], ['reserved_to' => ['$exists' => false]]]
      ];
    }
    $curRes = $er->find($qRes, ['projection'=>['reserved_serial_no'=>1,'reserved_from'=>1,'reserved_to'=>1,'username'=>1,'status'=>1], 'sort'=>['reserved_from'=>1]]);
    foreach ($curRes as $r) {
      $serial = trim((string)($r['reserved_serial_no'] ?? ''));
      if ($serial === '') continue; // only serial-specific reservations
      $uname = (string)($r['username'] ?? '');
      $fname = '';
      try { $u = $users->findOne(['username'=>$uname], ['projection'=>['full_name'=>1]]); if ($u) $fname = trim((string)($u['full_name'] ?? '')); } catch (Throwable $_) { }
      $rf = $norm(($r['reserved_from'] ?? ''));
      $rt = $norm(($r['reserved_to'] ?? ''));
      if ($atStr !== '') {
        $ats = @strtotime($atStr); $rfs = $rf!==''?@strtotime($rf):false; $rts = $rt!==''?@strtotime($rt):false;
        if (!($rfs !== false && $ats !== false && $rfs <= $ats && ($rts === false || $rts >= $ats))) { continue; }
      } else if ($dayStart !== '') {
        $startTs = @strtotime($dayStart); $endTs = @strtotime($dayEnd);
        $rfs = $rf!==''?@strtotime($rf):false; $rts = $rt!==''?@strtotime($rt):false;
        if (!($rfs !== false && $rfs <= $endTs && ($rts === false || $rts >= $startTs))) { continue; }
        if ($rfs !== false && $rfs < $startTs) { $rf = $dayStart; }
        if ($rts !== false && $rts > $endTs) { $rt = $dayEnd; }
      }
      $resBySerial[$serial][] = [
        'from' => $rf,
        'to'   => $rt,
        'username' => $uname,
        'full_name' => ($fname!==''?$fname:$uname),
        'status' => (string)($r['status'] ?? 'Approved'),
      ];
    }

    // If a specific datetime or day is set, ensure all overlapping serials are present in $items
    if ($atStr !== '' || $dayStart !== '') {
      $have = [];
      foreach ($items as $itmp) { $s = (string)($itmp['serial_no'] ?? ''); if ($s !== '') { $have[$s] = true; } }
      $ensure = array_values(array_unique(array_merge(array_keys($inUseMap), array_keys($resBySerial))));
      foreach ($ensure as $srl) {
        if (isset($have[$srl])) continue;
        try {
          $doc = $ii->findOne(['serial_no'=>$srl], ['projection'=>['id'=>1,'serial_no'=>1,'item_name'=>1,'model'=>1,'category'=>1,'location'=>1]]);
          if (!$doc) continue;
          // Respect category filter
          $docCat = (string)($doc['category'] ?? '');
          if ($category !== '' && strcasecmp($docCat, $category) !== 0) continue;
          // Respect search filter
          if ($q !== '') {
            $hay = [ (string)($doc['serial_no'] ?? ''), (string)($doc['item_name'] ?? ''), (string)($doc['model'] ?? '') ];
            $found = false; foreach ($hay as $h) { if ($h !== '' && stripos($h, $q) !== false) { $found = true; break; } }
            if (!$found) continue;
          }
          $modelName = (string)($doc['model'] ?? ''); if ($modelName === '') $modelName = (string)($doc['item_name'] ?? '');
          $items[] = [
            'id'        => (int)($doc['id'] ?? 0),
            'serial_no' => (string)($doc['serial_no'] ?? ''),
            'model'     => $modelName,
            'category'  => $docCat,
            'location'  => (string)($doc['location'] ?? ''),
          ];
        } catch (Throwable $_) { /* ignore */ }
      }
    }

    // Compose response items
    $out = [];
    foreach ($items as $it) {
      $serial = $it['serial_no'];
      if (($atStr !== '' || $dayStart !== '') && !isset($inUseMap[$serial]) && empty($resBySerial[$serial] ?? [])) { continue; }
      $row = [
        'serial_no' => $serial,
        'model' => $it['model'],
        'category' => $it['category'],
        'location' => $it['location'],
      ];
      if (isset($inUseMap[$serial])) { $row['in_use'] = $inUseMap[$serial]; }
      $row['reservations'] = $resBySerial[$serial] ?? [];
      $out[] = $row;
    }
    echo json_encode(['ok'=>true, 'categories'=>$cats, 'items'=>$out]);
    exit();
  } catch (Throwable $e) { echo json_encode(['ok'=>false]); exit(); }
}

// Print Lost/Damaged history (admin) with inventory_print-style header/footer
if ($act === 'print_lost_damaged' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  $event = trim($_GET['event'] ?? '');
  $statusFilter = trim($_GET['status'] ?? '');
  $dept = trim($_GET['department'] ?? '');
  $dateVal = trim($_GET['date'] ?? date('Y-m-d'));
  $prepared = trim($_GET['prepared_by'] ?? '');
  $checked  = trim($_GET['checked_by'] ?? '');
  if ($prepared === '') {
    try {
      @require_once __DIR__ . '/../vendor/autoload.php';
      @require_once __DIR__ . '/db/mongo.php';
      $dbu = get_mongo_db();
      $u = $dbu->selectCollection('users')->findOne(['username'=>($_SESSION['username'] ?? '')], ['projection'=>['full_name'=>1]]);
      $fn = $u && isset($u['full_name']) ? trim((string)$u['full_name']) : '';
      $prepared = $fn !== '' ? $fn : (string)($_SESSION['username'] ?? '');
    } catch (Throwable $_e) { $prepared = (string)($_SESSION['username'] ?? ''); }
  }
  try {
    $db = get_mongo_db();
    $ld = $db->selectCollection('lost_damaged_log');
    $ii = $db->selectCollection('inventory_items');
    $ub = $db->selectCollection('user_borrows');
    $uCol = $db->selectCollection('users');
    // Pull recent logs (newest first) for episode-based history, similar to on-screen Lost/Damaged History
    $logs = iterator_to_array($ld->find([], ['sort'=>['created_at'=>-1,'id'=>-1], 'limit'=>1000]));
    // De-dup exact duplicates (same model, action, timestamp) and omit resolution-only rows from the base event list
    $seenKeys = [];
    $filtered = [];
    foreach ($logs as $l) {
      $mid0 = (int)($l['model_id'] ?? 0);
      $act0 = (string)($l['action'] ?? '');
      $ts0  = (string)($l['created_at'] ?? '');
      if ($mid0 <= 0 || $ts0 === '' || $act0 === '') continue;
      if (in_array($act0, ['Found','Fixed'], true)) continue;
      $k = $mid0.'|'.$act0.'|'.$ts0;
      if (isset($seenKeys[$k])) continue;
      $seenKeys[$k] = true;
      $filtered[] = $l;
    }
    // Collapse entries with the same model and exact timestamp by preferring a higher-priority event
    $pickByTs = [];
    $prio = function($act){
      if ($act === 'Disposed') return 4;
      if ($act === 'Permanently Lost') return 3;
      if ($act === 'Lost') return 2;
      if ($act === 'Under Maintenance') return 1;
      return 0;
    };
    foreach ($filtered as $l) {
      $mid0 = (int)($l['model_id'] ?? 0); $ts0 = (string)($l['created_at'] ?? ''); $act0 = (string)($l['action'] ?? '');
      $k = $mid0.'|'.$ts0;
      if (!isset($pickByTs[$k]) || $prio($act0) > $prio((string)($pickByTs[$k]['action'] ?? ''))) {
        $pickByTs[$k] = $l;
      }
    }
    $filtered = array_values($pickByTs);
    // Build per-model chronological logs to compute per-episode final status
    $byModelChron = [];
    foreach ($logs as $l) {
      $midE = (int)($l['model_id'] ?? 0);
      if ($midE <= 0) continue;
      $byModelChron[$midE][] = $l;
    }
    foreach ($byModelChron as $midE => &$arrE) {
      usort($arrE, function($a, $b){
        $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($ta === $tb) {
          $ia = (int)($a['id'] ?? 0);
          $ib = (int)($b['id'] ?? 0);
          if ($ia === $ib) return 0;
          return ($ia < $ib) ? -1 : 1;
        }
        return ($ta < $tb) ? -1 : 1;
      });
    }
    unset($arrE);
    $episodeStatus = [];
    $episodeResolutionMap = [];
    foreach ($byModelChron as $midE => $logsE) {
      $nE = is_array($logsE) ? count($logsE) : 0;
      $openTs = null;
      $openBaseStatus = '';
      for ($iE = 0; $iE < $nE; $iE++) {
        $le = $logsE[$iE];
        $actE = strtolower((string)($le['action'] ?? ''));
        $tsE  = (string)($le['created_at'] ?? '');
        if ($tsE === '' || $actE === '') continue;
        // Episode starts when item enters Lost or Damaged (Under Maintenance)
        if (in_array($actE, ['lost','under maintenance','damaged'], true)) {
          $openTs = $tsE;
          $openBaseStatus = ($actE === 'lost') ? 'Lost' : 'Under Maintenance';
          $episodeStatus[$midE.'|'.$tsE] = $openBaseStatus;
          continue;
        }
        if ($openTs === null) {
          // Resolution with no open episode; ignore
          continue;
        }
        if (in_array($actE, ['found','fixed','permanently lost','disposed','disposal'], true)) {
          $statusE = '';
          if ($actE === 'found') { $statusE = 'Found'; }
          elseif ($actE === 'fixed') { $statusE = 'Fixed'; }
          elseif ($actE === 'permanently lost') { $statusE = 'Permanently Lost'; }
          elseif ($actE === 'disposed' || $actE === 'disposal') { $statusE = 'Disposed'; }
          else { $statusE = $openBaseStatus; }
          $episodeStatus[$midE.'|'.$openTs] = $statusE;
          // Track which resolution log (by its own timestamp) closed this episode
          $episodeResolutionMap[$midE.'|'.$tsE] = $openTs;
          $openTs = null;
          $openBaseStatus = '';
        }
      }
    }
    // Compute last action dates per model (use all logs, including Found/Fixed)
    $lastMap = [];
    foreach ($logs as $l) {
      $mid = (int)($l['model_id'] ?? 0);
      $act = (string)($l['action'] ?? '');
      $ts  = (string)($l['created_at'] ?? '');
      if ($mid <= 0 || $ts === '' || $act === '') continue;
      if (!isset($lastMap[$mid])) { $lastMap[$mid] = ['last_lost_at'=>'','last_maint_at'=>'','last_found_at'=>'','last_fixed_at'=>'','last_perm_lost_at'=>'','last_disposed_at'=>'']; }
      $actLower = strtolower($act);
      if ($actLower === 'lost' && $ts > (string)$lastMap[$mid]['last_lost_at']) { $lastMap[$mid]['last_lost_at'] = $ts; }
      if (($act === 'Under Maintenance' || $actLower === 'damaged') && $ts > (string)$lastMap[$mid]['last_maint_at']) { $lastMap[$mid]['last_maint_at'] = $ts; }
      if ($actLower === 'found' && $ts > (string)$lastMap[$mid]['last_found_at']) { $lastMap[$mid]['last_found_at'] = $ts; }
      if ($actLower === 'fixed' && $ts > (string)$lastMap[$mid]['last_fixed_at']) { $lastMap[$mid]['last_fixed_at'] = $ts; }
      // Track Permanently Lost and Disposed separately as well
      if ($actLower === 'permanently lost' && $ts > (string)$lastMap[$mid]['last_perm_lost_at']) { $lastMap[$mid]['last_perm_lost_at'] = $ts; }
      if (($actLower === 'disposed' || $actLower === 'disposal') && $ts > (string)$lastMap[$mid]['last_disposed_at']) { $lastMap[$mid]['last_disposed_at'] = $ts; }
    }
    // Build printable rows using the same per-episode semantics as the on-screen history
    $rows = [];
    foreach ($filtered as $r) {
      $mid = (int)($r['model_id'] ?? 0);
      if ($mid <= 0) { continue; }
      $actLowerRow = strtolower((string)($r['action'] ?? ''));
      $tsRow = (string)($r['created_at'] ?? '');
      // If this is a Permanently Lost / Disposed resolution that finalized an existing episode,
      // skip it as its own row; the original Lost/Damaged entry will carry the final Status.
      if ($mid > 0 && $tsRow !== '' && in_array($actLowerRow, ['permanently lost','disposed','disposal'], true)
          && isset($episodeResolutionMap[$mid.'|'.$tsRow])) {
        continue;
      }
      $itm = $ii->findOne(['id'=>$mid]);
      $lm = $lastMap[$mid] ?? ['last_lost_at'=>'','last_maint_at'=>'','last_found_at'=>'','last_fixed_at'=>'','last_perm_lost_at'=>'','last_disposed_at'=>''];
      // Determine per-episode final status (currentAction), same as modal history
      $currentAction = '';
      $epKey = $mid.'|'.$tsRow;
      if (isset($episodeStatus[$epKey])) {
        $currentAction = (string)$episodeStatus[$epKey];
      }
      if ($currentAction === '') {
        $lostAt  = !empty($lm['last_lost_at']) ? strtotime((string)$lm['last_lost_at']) : null;
        $foundAt = !empty($lm['last_found_at']) ? strtotime((string)$lm['last_found_at']) : null;
        $maintAt = !empty($lm['last_maint_at']) ? strtotime((string)$lm['last_maint_at']) : null;
        $fixedAt = !empty($lm['last_fixed_at']) ? strtotime((string)$lm['last_fixed_at']) : null;
        $permLostAt = !empty($lm['last_perm_lost_at']) ? strtotime((string)$lm['last_perm_lost_at']) : null;
        $disposedAt = !empty($lm['last_disposed_at']) ? strtotime((string)$lm['last_disposed_at']) : null;
        $latestAny = max($lostAt ?: 0, $foundAt ?: 0, $maintAt ?: 0, $fixedAt ?: 0, $permLostAt ?: 0, $disposedAt ?: 0);
        if ($latestAny > 0) {
          if ($disposedAt && $disposedAt === $latestAny) {
            $currentAction = 'Disposed';
          }
          elseif ($permLostAt && $permLostAt === $latestAny) {
            $currentAction = 'Permanently Lost';
          }
          elseif ($lostAt && $lostAt === $latestAny) {
            $currentAction = 'Lost';
          }
          elseif ($maintAt && $maintAt === $latestAny) {
            if ($fixedAt && $fixedAt > $maintAt) { $currentAction = 'Fixed'; }
            else { $currentAction = 'Under Maintenance'; }
          }
          elseif ($foundAt && $foundAt === $latestAny) {
            $currentAction = 'Found';
          }
          elseif ($fixedAt && $fixedAt === $latestAny) {
            $currentAction = 'Fixed';
          }
        }
        if ($currentAction === '') {
          $ist = $itm ? (string)($itm['status'] ?? '') : '';
          if (in_array($ist, ['Lost','Under Maintenance','Found','Fixed'], true)) { $currentAction = $ist; }
        }
      }
      // Apply Status filter (per-episode final status)
      if ($statusFilter !== '' && strcasecmp($statusFilter,'All') !== 0) {
        if ($currentAction === '' || strcasecmp($currentAction, $statusFilter) !== 0) continue;
      }
      // Resolve user who lost/damaged vs admin who marked it, similar to previous print logic
      $userCandidates = [
        (string)($r['affected_username'] ?? ''),
        (string)($r['lost_by'] ?? ''),
        (string)($r['damaged_by'] ?? ''),
        (string)($r['user_username'] ?? ''),
        (string)($r['student_username'] ?? ''),
        (string)($r['borrower_username'] ?? ''),
        (string)($r['borrowed_by'] ?? ''),
        (string)($r['username'] ?? ''),
      ];
      $markedCandidates = [
        (string)($r['marked_by'] ?? ''),
        (string)($r['admin_username'] ?? ''),
        (string)($r['created_by'] ?? ''),
        (string)($r['performed_by'] ?? ''),
        (string)($r['action_by'] ?? ''),
        (string)($r['username'] ?? ''),
      ];
      $pickFirst = function(array $arr){ foreach ($arr as $v) { if (isset($v) && trim((string)$v) !== '') return (string)$v; } return ''; };
      $markedUname = $pickFirst($markedCandidates);
      $userUname = $pickFirst($userCandidates);
      $logWhen = $tsRow;
      if (($userUname === '' || ($markedUname !== '' && $userUname === $markedUname)) && $mid > 0) {
        try {
          $q1 = [
            'model_id' => $mid,
            'borrowed_at' => ['$lte' => $logWhen],
            '$or' => [ ['returned_at' => null], ['returned_at' => ''], ['returned_at' => ['$gte' => $logWhen]] ]
          ];
          $opt = ['sort' => ['borrowed_at' => -1, 'id' => -1], 'limit' => 1];
          $c1 = $ub->find($q1, $opt);
          $foundBorrow = false;
          foreach ($c1 as $br) { $cand = (string)($br['username'] ?? ''); if ($cand !== '') { $userUname = $cand; $foundBorrow = true; break; } }
          if (!$foundBorrow) {
            $q2 = [ 'model_id' => $mid, 'borrowed_at' => ['$lte' => $logWhen] ];
            $c2 = $ub->find($q2, $opt);
            foreach ($c2 as $br) { $cand = (string)($br['username'] ?? ''); if ($cand !== '') { $userUname = $cand; $foundBorrow = true; break; } }
          }
          if (!$foundBorrow && $userUname === '' && $itm) {
            $userUname = (string)($itm['last_borrower_username'] ?? ($itm['last_borrower'] ?? ''));
          }
          if (!$foundBorrow && $userUname === '' && $markedUname !== '') { $userUname = $markedUname; }
        } catch (Throwable $e) { /* ignore */ }
      }
      if ($userUname === '' && $itm) {
        $userUname = (string)($itm['last_borrower_username'] ?? ($itm['last_borrower'] ?? ''));
      }
      $resolve = function($uname) use ($uCol){
        $full = $uname;
        if ($uname !== '') {
          try { $uu = $uCol->findOne(['username'=>$uname], ['projection'=>['full_name'=>1]]); if ($uu && !empty($uu['full_name'])) { $full = (string)$uu['full_name']; } } catch (Throwable $e) {}
        }
        return $full;
      };
      $userFull = $resolve($userUname);
      $markedFull = $resolve($markedUname);
      // Normalize Event display: show base category (Lost or Damaged) regardless of terminal outcome
      $evt = (string)($r['action'] ?? '');
      $evtLower = strtolower($evt);
      $eventBase = in_array($evtLower, ['lost','permanently lost','found']) ? 'Lost'
                  : (in_array($evtLower, ['under maintenance','damaged','fixed','disposed','disposal']) ? 'Damaged' : $evt);
      // Apply Event filter on the normalized base event
      if ($event !== '' && strcasecmp($event,'All') !== 0) {
        if (strcasecmp($eventBase, $event) !== 0) continue;
      }
      $rows[] = [
        'serial' => $itm ? (string)($itm['serial_no'] ?? '') : (string)($r['serial_no'] ?? ''),
        'model' => $itm ? ((string)($itm['model'] ?? '') ?: (string)($itm['item_name'] ?? '')) : (string)($r['model_key'] ?? ''),
        'category' => $itm ? ((string)($itm['category'] ?? '') ?: 'Uncategorized') : (string)($r['category'] ?? 'Uncategorized'),
        'location' => $itm ? (string)($itm['location'] ?? '') : (string)($r['location'] ?? ''),
        'remarks' => (string)($r['notes'] ?? ($itm['remarks'] ?? '')),
        'event' => $eventBase,
        'lost_damaged_by' => $userFull,
        'marked_by' => $markedFull,
        'at' => $tsRow,
        'status' => $currentAction,
      ];
    }
    // Keep rows order roughly newest first
    usort($rows, function($a,$b){
      $ta = strtotime((string)($a['at'] ?? '')) ?: 0; $tb = strtotime((string)($b['at'] ?? '')) ?: 0;
      if ($ta === $tb) return 0; return ($ta > $tb) ? -1 : 1;
    });
  } catch (Throwable $e) { $rows = []; }
  ?><!DOCTYPE html>
  <html lang="en"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lost/Damaged History Print</title>
    <link href="css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo file_exists(__DIR__.'/images/logo-removebg.png') ? (filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png')) : time(); ?>" />
    <style>
      @page { size: A4 portrait; margin: 0.6in 0.25in 0.25in 0.25in; }
      @media print { .no-print { display:none!important } html,body{ -webkit-print-color-adjust:exact; print-color-adjust:exact; } thead{display:table-header-group} tfoot{display:table-footer-group} .print-wrap{ overflow: visible !important; } }
      .print-table { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 9px; }
      .print-table th, .print-table td { padding: .18rem .2rem; vertical-align: middle; line-height: 1.2; text-align: left; word-break: break-word; white-space: normal; }
      .print-root { padding-top: 15mm; margin-top: 6mm; }
      .eca-header { text-align:center; margin-bottom:10px; }
      .eca-title { font-weight:400; letter-spacing:6px; font-size:14pt; }
      .print-wrap { width: 100%; overflow: visible; }
      .eca-meta { display:flex; align-items:center; justify-content:space-between; font-size:9pt; margin-top:6px; margin-bottom:8px; }
      .dept-row { margin-bottom: 8mm; }
      .report-title { text-align:center; font-weight:400; font-size:14pt; margin:14px 0 8px; text-transform:uppercase; }
      .eca-footer { margin-top:18mm; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:nowrap; }
      .eca-footer .field { display:inline-flex; align-items:center; gap:8px; margin-right:0; white-space:nowrap; }
      .eca-footer .field label { margin:0; white-space:nowrap; }
      .eca-print-value { display:inline-block; border-bottom:1px solid #000; padding:0 4px 2px; min-width:180px; }
      .blank-row td { padding-top: .18rem !important; padding-bottom: .18rem !important; }
      .page { page-break-after: always; }
      .page:last-child { page-break-after: auto; }
    </style>
  </head><body>
    <div class="container-fluid pt-3 print-root">
      <?php
        $pageSize = 20;
        $totalRows = is_array($rows) ? count($rows) : 0;
        $pages = max(1, (int)ceil($totalRows / $pageSize));
        for ($p = 0; $p < $pages; $p++) {
          $slice = array_slice($rows, $p * $pageSize, $pageSize);
          $fill = $pageSize - count($slice);
      ?>
      <div class="page">
        <div class="eca-header"><div class="eca-title">ECA</div><div class="eca-sub">Exact Colleges of Asia, Inc.</div></div>
        <div class="eca-meta"><div class="form-no">Form No. <em>IF</em>/OO/Jun.2011</div><div></div></div>
        <div class="report-title">LOST/DAMAGED HISTORY</div>
        <div class="d-flex align-items-center justify-content-between dept-row">
          <div><strong>Department:</strong> <span class="eca-print-value"><?php echo htmlspecialchars($dept); ?>&nbsp;</span></div>
          <div><strong>Date:</strong> <span class="eca-print-value"><?php echo htmlspecialchars($dateVal); ?>&nbsp;</span></div>
        </div>

      <script>
        (function(){
          function byId(id){ return document.getElementById(id); }
          function show(el){ if(el){ el.style.display = 'block'; } }
          function hide(el){ if(el){ el.style.display = 'none'; } }
          function widen(col, full){
            if (!col) return;
            if (!col.dataset.orig) col.dataset.orig = col.className;
            col.className = full ? 'col-12' : (col.dataset.orig || col.className);
          }
          function resetCols(){
            ['pending-col','borrowed-col','reservations-col'].forEach(function(id){ var el=byId(id); if(el && el.dataset.orig){ el.className = el.dataset.orig; }});
          }
          function hideAllCards(){
            ['pending-list','borrowed-list','reservations-list'].forEach(function(id){ hide(byId(id)); });
          }
          function applyBorrowView(mode){
            mode = (mode||'').toLowerCase();
            var pbRow = byId('pb-row');
            var retRow = byId('returned-list');
            var pCol = byId('pending-col');
            var bCol = byId('borrowed-col');
            var rsvCol = byId('reservations-col');
            var pCard = byId('pending-list');
            var bCard = byId('borrowed-list');
            var rsvCard = byId('reservations-list');
            // Hide everything first
            hide(pbRow); hide(retRow);
            hide(pCol); hide(bCol); hide(rsvCol);
            hideAllCards();
            resetCols();
            if (mode === 'borrowed') {
              show(pbRow); show(bCol); show(bCard); widen(bCol, true);
            } else if (mode === 'reservations') {
              show(retRow); show(rsvCol); show(rsvCard); widen(rsvCol, true);
            } else { // pending default
              show(pbRow); show(pCol); show(pCard); widen(pCol, true);
            }
          }
          function init(){
            var sel = byId('brViewSelect');
            if (!sel) return;
            // Determine default from hash or ?scroll= param
            var def = (location.hash || '').replace('#','').toLowerCase();
            if (def.endsWith('-list')) def = def.replace('-list','');
            if (def.endsWith('-card')) def = def.replace('-card','');
            var params = new URLSearchParams(location.search);
            var scr = (params.get('scroll') || '').toLowerCase();
            if (!def && ['pending','borrowed','reservations'].includes(scr)) def = scr;
            if (!['pending','borrowed','reservations'].includes(def)) def = 'pending';
            sel.value = def;
            applyBorrowView(def);
            sel.addEventListener('change', function(){ applyBorrowView(this.value); });
            window.addEventListener('hashchange', function(){
              var h = (location.hash||'').replace('#','').toLowerCase();
              if (h.endsWith('-list')) h = h.replace('-list','');
              if (h.endsWith('-card')) h = h.replace('-card','');
              if (['pending','borrowed','reservations'].includes(h)) { sel.value = h; applyBorrowView(h); }
            });
          }
          if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
        })();
      </script>

      

        <div class="print-wrap mb-2">
          <table class="table table-bordered table-sm align-middle print-table">
            <thead class="table-light"><tr>
              <th>Serial ID</th><th>Model</th><th>Category</th><th>Location</th><th>Remarks</th><th>Event</th><th>Lost/Damaged by</th><th>Marked By</th><th>Date Lost/Damaged</th><th>Status</th>
            </tr></thead>
            <tbody>
              <?php if (empty($slice)): ?>
                <?php /* render 15 blank rows if no data on this page */ ?>
              <?php endif; ?>
              <?php foreach ($slice as $rw): ?>
                <tr>
                  <td><?php echo htmlspecialchars($rw['serial']); ?></td>
                  <td><?php echo htmlspecialchars($rw['model']); ?></td>
                  <td><?php echo htmlspecialchars($rw['category']); ?></td>
                  <td><?php echo htmlspecialchars($rw['location']); ?></td>
                  <td><?php echo htmlspecialchars($rw['remarks']); ?></td>
                  <td><?php echo htmlspecialchars($rw['event']); ?></td>
                  <td><?php echo htmlspecialchars($rw['lost_damaged_by'] ?? ''); ?></td>
                  <td><?php echo htmlspecialchars($rw['marked_by'] ?? ''); ?></td>
                  <td><?php $ts = strtotime((string)($rw['at'] ?? '')); if ($ts) { echo '<div>'.htmlspecialchars(date('M d,Y', $ts)).'</div><div>'.htmlspecialchars(date('g:ia', $ts)).'</div>'; } ?></td>
                  <td><?php echo htmlspecialchars($rw['status']); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php for ($i = 0; $i < $fill; $i++): ?>
                <tr class="blank-row">
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td>
                  <td><div>&nbsp;</div><div>&nbsp;</div></td>
                  <td>&nbsp;</td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
        <div class="eca-footer">
          <div class="field"><label>Prepared by:</label><span class="eca-print-value"><?php echo htmlspecialchars($prepared); ?>&nbsp;</span></div>
          <div class="field"><label>Checked by:</label><span class="eca-print-value"><?php echo htmlspecialchars($checked); ?>&nbsp;</span></div>
        </div>
      </div>

      <!-- Reservation Timeline Modal -->
      <div class="modal fade" id="resTimelineModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width:95vw; width:95vw; max-height:95vh; height:95vh;">
          <div class="modal-content" style="height:100%;">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-calendar-week me-2"></i>Reservation Timeline</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="height:calc(95vh - 120px); overflow:auto;">
              <div class="row g-2 align-items-end mb-2">
                <div class="col-12 col-md-4">
                  <label class="form-label mb-1 small">Category</label>
                  <select id="resFilterCategory" class="form-select form-select-sm"><option value="">All</option></select>
                </div>
                <div class="col-8 col-md-5">
                  <label class="form-label mb-1 small">Search</label>
                  <input id="resFilterSearch" type="text" class="form-control form-control-sm" placeholder="Search serial/model" />
                </div>
                <div class="col-12 col-md-3">
                  <label class="form-label mb-1 small">Date</label>
                  <input id="resFilterDay" type="date" class="form-control form-control-sm" />
                </div>
              </div>
              <div id="resTimelineList" class="row g-2"></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>
      <?php } ?>
    </div>
    <script>
      $(function(){
        $('[data-bs-toggle="tooltip"]').tooltip();
      });
    </script>
    <script>
      $(function(){
        $('#pending-list input[type="search"]').on('input', function(){
          var val = $(this).val().toLowerCase();
          $('#pending-list tbody tr').each(function(){
            $(this).toggle($(this).text().toLowerCase().indexOf(val) !== -1);
          });
        });
        $('#borrowed-list input[type="search"]').on('input', function(){
          var val = $(this).val().toLowerCase();
          $('#borrowed-list tbody tr').each(function(){
            $(this).toggle($(this).text().toLowerCase().indexOf(val) !== -1);
          });
        });
        $('#borrowed-list .overdue-toggle').on('change', function(){
          var showOverdue = $(this).is(':checked');
          $('#borrowed-list tbody tr').each(function(){
            var overdue = $(this).data('overdue');
            $(this).toggle(showOverdue ? overdue : true);
          });
        });
      });
    </script>
    <script>window.addEventListener('load', function(){ window.print(); });</script>
  </body></html><?php
  exit();
}
// Cancel an approved reservation
if ($act === 'cancel_reservation') {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  try {
    $db = get_mongo_db();
    $er = $db->selectCollection('equipment_requests');
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
      // Resolve canceller full name (fallback to username)
      $cancelUser = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'system';
      $cancelName = $cancelUser;
      try {
        $uDoc = $db->selectCollection('users')->findOne(['username'=>$cancelUser], ['projection'=>['full_name'=>1]]);
        if ($uDoc && isset($uDoc['full_name']) && trim((string)$uDoc['full_name'])!=='') { $cancelName = (string)$uDoc['full_name']; }
      } catch (Throwable $eun) {}
      $reqDoc = $er->findOne(['id'=>$id]);
      $now = date('Y-m-d H:i:s');
      $er->updateOne(
        ['id'=>$id, 'type'=>'reservation', 'status'=>'Approved'],
        ['$set'=>[
          'status'=>'Cancelled',
          'cancelled_at'=>$now,
          'cancelled_by'=>$cancelName
        ]]
      );
      if ($reqDoc) { ab_fcm_notify_request_status($db, $reqDoc, 'Cancelled'); }
    }
    header('Location: admin_borrow_center.php?reservation_cancelled=1#reservations-list'); exit();
  } catch (Throwable $e) {
    header('Location: admin_borrow_center.php?error=tx'); exit();
  }
}
// Standalone handler: delete selected whitelisted units
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['do'] ?? '') === 'delete_units')) {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  try {
    $cat = trim((string)($_POST['category'] ?? ''));
    $model = trim((string)($_POST['model'] ?? ''));
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_values(array_unique(array_filter(array_map('intval', $_POST['ids'])))) : [];
    if ($cat !== '' && $model !== '' && !empty($ids)) {
      $db = get_mongo_db();
      $buCol = $db->selectCollection('borrowable_units');
      $bcCol = $db->selectCollection('borrowable_catalog');
      $iiCol = $db->selectCollection('inventory_items');
      $erCol = $db->selectCollection('equipment_requests');
      // Find IDs currently In Use (actively borrowed)
      $inUse = [];
      foreach ($iiCol->find(['id' => ['$in' => $ids], 'status' => ['$in' => ['In Use','Lost','Damaged','Under Maintenance']]], ['projection' => ['id' => 1]]) as $row) {
        $inUse[] = (int)($row['id'] ?? 0);
      }
      $inUse = array_values(array_unique(array_filter($inUse)));
      // Find IDs reserved in approved reservations (assigned unit)
      $reserved = [];
      try {
        $curRes = $erCol->find(
          ['type'=>'reservation','status'=>'Approved','reserved_model_id'=>['$in'=>$ids]],
          ['projection'=>['reserved_model_id'=>1]]
        );
        foreach ($curRes as $r) { if (isset($r['reserved_model_id'])) { $reserved[] = (int)$r['reserved_model_id']; } }
        $reserved = array_values(array_unique(array_filter($reserved)));
      } catch (Throwable $_) { $reserved = []; }
      // Compute deletable IDs (not In Use and not Reserved)
      $blocked = array_values(array_unique(array_merge($inUse, $reserved)));
      $canDelete = array_values(array_diff($ids, $blocked));
      $removed = 0;
      if (!empty($canDelete)) {
        $delRes = $buCol->deleteMany(['model_id' => ['$in' => $canDelete]]);
        $removed = (int)($delRes->getDeletedCount() ?? 0);
        if ($removed > 0) {
          $cur = $bcCol->findOne(['model_name'=>$model,'category'=>$cat], ['projection'=>['borrow_limit'=>1]]);
          if ($cur && isset($cur['borrow_limit'])) {
            $new = max(0, (int)$cur['borrow_limit'] - $removed);
            $bcCol->updateOne(['model_name'=>$model,'category'=>$cat], ['$set'=>['borrow_limit'=>$new]]);
          }
        }
      }
      // Redirect with counts for UI feedback
      $blocked_in_use = count($inUse);
      $blocked_reserved = count($reserved);
      header('Location: admin_borrow_center.php?deleted=' . $removed . '&blocked_in_use=' . $blocked_in_use . '&blocked_reserved=' . $blocked_reserved);
      exit();
    }
  } catch (Throwable $e) { /* ignore */ }
  header('Location: admin_borrow_center.php?deleted=0'); exit();
}
// Reject a pending request (cancel without deleting)
if ($act === 'reject' && isset($_GET['id'])) {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  try {
    $db = get_mongo_db();
    $er = $db->selectCollection('equipment_requests');
    $id = (int)($_GET['id'] ?? 0);
    $now = date('Y-m-d H:i:s');
    // Resolve rejector full name (fallback to username)
    $rejectUser = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'system';
    $rejectName = $rejectUser;
    try {
      $uDoc = $db->selectCollection('users')->findOne(['username'=>$rejectUser], ['projection'=>['full_name'=>1]]);
      if ($uDoc && isset($uDoc['full_name']) && trim((string)$uDoc['full_name'])!=='') { $rejectName = (string)$uDoc['full_name']; }
    } catch (Throwable $eun) {}
    $reqDoc = $er->findOne(['id' => $id]);
    // Only reject pending requests
    $er->updateOne(
      ['id' => $id, 'status' => 'Pending'],
      ['$set' => ['status' => 'Rejected', 'rejected_at' => $now, 'rejected_by' => $rejectName]]
    );
    if ($reqDoc) { ab_fcm_notify_request_status($db, $reqDoc, 'Rejected'); }
    header('Location: admin_borrow_center.php?rejected=1'); exit();
  } catch (Throwable $e) {
    header('Location: admin_borrow_center.php?error=tx'); exit();
  }
}

if (in_array($act, ['mark_returned','mark_lost','mark_maintenance'], true) && isset($_GET['id'])) {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  try {
    $db = get_mongo_db();
    $er = $db->selectCollection('equipment_requests');
    $ii = $db->selectCollection('inventory_items');
    $ub = $db->selectCollection('user_borrows');
    $ra = $db->selectCollection('request_allocations');
    $ldCol = $db->selectCollection('lost_damaged_log');
    $now = date('Y-m-d H:i:s');

    $reqId = (int)$_GET['id'];
    $req = $er->findOne(['id'=>$reqId]);
    if (!$req) { header('Location: admin_borrow_center.php?error=notfound'); exit(); }
    $who = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'system';
    $item = trim((string)($req['item_name'] ?? ''));
    $reqQty = max(1,(int)($req['quantity'] ?? 1));

    // Pick one active borrow linked to this request (oldest by borrowed_at, then id)
    $allocs = iterator_to_array($ra->find(['request_id'=>$reqId], ['projection'=>['borrow_id'=>1]]));
    $borrowIds = array_values(array_filter(array_map(function($d){ return isset($d['borrow_id']) ? (int)$d['borrow_id'] : 0; }, $allocs)));
    if (empty($borrowIds)) { header('Location: admin_borrow_center.php?error=noborrow'); exit(); }
    $cur = $ub->find(['id' => ['$in'=>$borrowIds], 'status'=>'Borrowed']);
    $activeBorrows = [];
    foreach ($cur as $b) { $activeBorrows[] = $b; }
    if (empty($activeBorrows)) { header('Location: admin_borrow_center.php?error=noborrow'); exit(); }
    usort($activeBorrows, function($a,$b){
      $ta = strtotime((string)($a['borrowed_at'] ?? '')) ?: 0;
      $tb = strtotime((string)($b['borrowed_at'] ?? '')) ?: 0;
      if ($ta === $tb) { return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0)); }
      return $ta <=> $tb;
    });
    $pick = $activeBorrows[0];
    $borrowId = (int)($pick['id'] ?? 0);
    $mid = (int)($pick['model_id'] ?? 0);

    if ($act==='mark_returned') {
      // Set item as Available and close borrow
      $ii->updateOne(['id'=>$mid], ['$set'=>['status'=>'Available','location'=>'MIS Office']]);
      $ub->updateOne(['id'=>$borrowId, 'status'=>'Borrowed'], ['$set'=>['status'=>'Returned','returned_at'=>$now]]);
      // Ensure returned unit is whitelisted for borrowing
      try {
        $buCol = $db->selectCollection('borrowable_units');
        $bcCol = $db->selectCollection('borrowable_catalog');
        $it = $ii->findOne(['id'=>$mid], ['projection'=>['model'=>1,'item_name'=>1,'category'=>1]]);
        $mn = $it ? (string)($it['model'] ?? ($it['item_name'] ?? '')) : '';
        $cat = $it ? (string)(($it['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized';
        if ($mn !== '') {
          $isActive = (bool)$bcCol->countDocuments(['model_name'=>$mn,'category'=>$cat,'active'=>1]);
          if ($isActive) {
            $exists = (int)$buCol->countDocuments(['model_id'=>$mid]);
            if ($exists === 0) { $buCol->insertOne(['model_id'=>$mid,'model_name'=>$mn,'category'=>$cat,'created_at'=>$now]); }
          }
        }
      } catch (Throwable $_e) {}
      // Remaining active borrows for this request
      $allocBorrowIds = array_values(array_filter(array_map(function($d){ return (int)($d['borrow_id'] ?? 0); }, $allocs)));
      $rem = $ub->countDocuments(['id'=>['$in'=>$allocBorrowIds], 'status'=>'Borrowed']);
      if ($rem <= 0) {
        $allocTotal = count($allocBorrowIds);
        if ($allocTotal >= $reqQty) {
          $er->updateOne(['id'=>$reqId], ['$set'=>['status'=>'Returned','returned_at'=>$now]]);
          ab_fcm_notify_request_status($db, $req, 'Returned');
        }
      }
      header('Location: admin_borrow_center.php?scroll=borrowed#borrowed-list'); exit();
    } elseif ($act==='mark_lost') {
      $ii->updateOne(['id'=>$mid], ['$set'=>['status'=>'Lost']]);
      $ub->updateOne(['id'=>$borrowId, 'status'=>'Borrowed'], ['$set'=>['status'=>'Returned','returned_at'=>$now]]);
      $nextLD = $db->selectCollection('lost_damaged_log')->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
      $lid = ($nextLD && isset($nextLD['id']) ? (int)$nextLD['id'] : 0) + 1;
      $ldCol->insertOne(['id'=>$lid,'model_id'=>$mid,'username'=>$who,'action'=>'Lost','created_at'=>$now]);
      ab_fcm_notify_request_status($db, $req, 'Lost');

      // Also update borrowable lists: remove this unit and decrement borrow_limit
      try {
        $buCol = $db->selectCollection('borrowable_units');
        $bcCol = $db->selectCollection('borrowable_catalog');
        $it = $ii->findOne(['id'=>$mid], ['projection'=>['model'=>1,'item_name'=>1,'category'=>1]]);
        $mn = $it ? (string)($it['model'] ?? ($it['item_name'] ?? '')) : '';
        $cat = $it ? (string)(($it['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized';
        if ($mn !== '') {
          $buCol->deleteOne(['model_id'=>$mid]);
          $cur = $bcCol->findOne(['model_name'=>$mn,'category'=>$cat], ['projection'=>['borrow_limit'=>1]]);
          if ($cur && isset($cur['borrow_limit'])) {
            $new = max(0, (int)$cur['borrow_limit'] - 1);
            $bcCol->updateOne(['model_name'=>$mn,'category'=>$cat], ['$set'=>['borrow_limit'=>$new]]);
          }
        }
      } catch (Throwable $eadj) { /* ignore adjustments */ }

      header('Location: admin_borrow_center.php?scroll=lost#lost-damaged'); exit();
    } else { // mark_maintenance
      $ii->updateOne(['id'=>$mid], ['$set'=>['status'=>'Under Maintenance']]);
      $ub->updateOne(['id'=>$borrowId, 'status'=>'Borrowed'], ['$set'=>['status'=>'Returned','returned_at'=>$now]]);
      $nextLD = $db->selectCollection('lost_damaged_log')->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
      $lid = ($nextLD && isset($nextLD['id']) ? (int)$nextLD['id'] : 0) + 1;
      $ldCol->insertOne(['id'=>$lid,'model_id'=>$mid,'username'=>$who,'action'=>'Under Maintenance','created_at'=>$now]);
      ab_fcm_notify_request_status($db, $req, 'Under Maintenance');
      header('Location: admin_borrow_center.php?scroll=lost#lost-damaged'); exit();
    }
  } catch (Throwable $e) {
    header('Location: admin_borrow_center.php?error=mark'); exit();
  }
}
if (in_array($act, ['validate_model_id','approve_with','edit_reservation_serial','approve','validate_return_id','return_with','returnship_status','request_returnship','approve_returnship','returnship_feed','return_feed'], true)) {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  try {
    $db = get_mongo_db();
    $er = $db->selectCollection('equipment_requests');
    $ii = $db->selectCollection('inventory_items');
    $ub = $db->selectCollection('user_borrows');
    $ra = $db->selectCollection('request_allocations');

    $now = date('Y-m-d H:i:s');

    $getDesired = function($itemName) use ($ii) {
      $doc = $ii->findOne([
        '$or' => [ ['model'=>$itemName], ['item_name'=>$itemName] ]
      ], ['sort' => ['id' => -1]]);
      $m = $doc ? (string)($doc['model'] ?? ($doc['item_name'] ?? '')) : (string)$itemName;
      $c = $doc ? (string)($doc['category'] ?? '') : '';
      $c = trim($c) !== '' ? $c : 'Uncategorized';
      return [$m,$c];
    };

    $nextId = function($col) use ($db) {
      $last = $db->selectCollection($col)->findOne([], ['sort' => ['id' => -1], 'projection' => ['id' => 1]]);
      $cur = $last && isset($last['id']) ? (int)$last['id'] : 0;
      return $cur + 1;
    };

    if ($act === 'validate_model_id' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      header('Content-Type: application/json');
      $id = (int)($_POST['request_id'] ?? 0);
      $serial = trim((string)($_POST['serial_no'] ?? ''));
      $modelIdIn = (int)($_POST['model_id'] ?? 0);
      if ($id <= 0) { echo json_encode(['ok'=>false,'reason'=>'Missing parameters']); exit; }
      $req = $er->findOne(['id'=>$id]);
      if (!$req || (string)($req['status'] ?? '') !== 'Pending') { echo json_encode(['ok'=>false,'reason'=>'Request not pending']); exit; }
      // If this request originated from a QR with a specific serial, require that exact serial
      $qrSerialReq = trim((string)($req['qr_serial_no'] ?? ''));
      if ($qrSerialReq !== '') {
        if ($serial === '' || strcasecmp($serial, $qrSerialReq) !== 0) {
          echo json_encode(['ok'=>false,'reason'=>'This request requires the specific QR serial.']); exit;
        }
      }
      $item = trim((string)($req['item_name'] ?? ''));
      [$dm,$dc] = $getDesired($item);
      $unit = null;
      if ($serial !== '') { $unit = $ii->findOne(['serial_no'=>$serial]); }
      elseif ($modelIdIn > 0) { $unit = $ii->findOne(['id'=>$modelIdIn]); }
      if (!$unit) { echo json_encode(['ok'=>false,'reason'=>'Serial not found']); exit; }
      if ((string)($unit['status'] ?? '') !== 'Available') { echo json_encode(['ok'=>false,'reason'=>'Serial not available']); exit; }
      try {
        $buCol = $db->selectCollection('borrowable_units');
        $uid = (int)($unit['id'] ?? 0);
        if ($uid > 0) {
          $whitelisted = (int)$buCol->countDocuments(['model_id' => ['$in' => [$uid, (string)$uid]]]) > 0;
          if (!$whitelisted) { echo json_encode(['ok'=>false,'reason'=>'Item not borrowable']); exit; }
        }
      } catch (Throwable $_w) {}
      $um = (string)($unit['model'] ?? ($unit['item_name'] ?? ''));
      $uc = trim((string)($unit['category'] ?? '')) !== '' ? (string)$unit['category'] : 'Uncategorized';
      $match = (strcasecmp(trim($um), trim($dm))===0) && (strcasecmp(trim($uc), trim($dc))===0);
      if (!$match) { echo json_encode(['ok'=>false,'reason'=>'Item mismatch']); exit; }
      // For reservation requests, also ensure this specific unit is not already reserved in the same window
      $reqType = strtolower((string)($req['type'] ?? 'immediate'));
      if ($reqType === 'reservation') {
        $rf = (string)($req['reserved_from'] ?? '');
        $rt = (string)($req['reserved_to'] ?? '');
        $tsStart = $rf !== '' ? strtotime($rf) : null;
        $tsEnd   = $rt !== '' ? strtotime($rt) : null;
        if (!$tsStart || !$tsEnd || $tsEnd <= $tsStart) {
          echo json_encode(['ok'=>false,'reason'=>'Invalid reservation time']); exit;
        }
        $assignedMid = (int)($unit['id'] ?? 0);
        if ($assignedMid > 0) {
          $buf = 5 * 60;
          try {
            $curR = $er->find(
              ['type'=>'reservation','status'=>'Approved','reserved_model_id'=>$assignedMid],
              ['projection'=>['reserved_from'=>1,'reserved_to'=>1,'id'=>1]]
            );
            foreach ($curR as $row) {
              $ofs = isset($row['reserved_from']) ? strtotime((string)$row['reserved_from']) : null;
              $ote = isset($row['reserved_to']) ? strtotime((string)$row['reserved_to']) : null;
              if (!$ofs || !$ote) { continue; }
              // conflict unless there is at least 5-min gap between intervals
              $noOverlapWithBuffer = ($ote <= ($tsStart - $buf)) || ($tsEnd <= ($ofs - $buf));
              if (!$noOverlapWithBuffer) {
                echo json_encode(['ok'=>false,'reason'=>'Conflicts with another approved reservation']); exit;
              }
            }
          } catch (Throwable $_e) { /* ignore, treat as no conflict */ }
        }
      }
      echo json_encode(['ok'=>true,'reason'=>'OK','model'=>$um,'category'=>$uc,'id'=>(int)($unit['id'] ?? 0)]); exit;
    }

    if ($act === 'approve_with' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      $id = (int)($_POST['request_id'] ?? 0);
      $serial = trim((string)($_POST['serial_no'] ?? ''));
      $modelIdIn = (int)($_POST['model_id'] ?? 0);
      $reqType = trim((string)($_POST['req_type'] ?? 'immediate'));
      $expectedReturn = trim((string)($_POST['expected_return_at'] ?? ''));
      $reserveStart = trim((string)($_POST['reserved_from'] ?? ''));
      $reserveEnd = trim((string)($_POST['reserved_to'] ?? ''));
      $req = $er->findOne(['id'=>$id]);
      if (!$req || (string)($req['status'] ?? '') !== 'Pending') { header('Location: admin_borrow_center.php?error=notpend'); exit(); }
      // Enforce QR serial if present: ignore any posted serial/model_id and use the request's QR serial
      $qrSerialReq = trim((string)($req['qr_serial_no'] ?? ''));
      if ($qrSerialReq !== '') { $serial = $qrSerialReq; $modelIdIn = 0; }
      $user = trim((string)($req['username'] ?? ''));
      $item = trim((string)($req['item_name'] ?? ''));
      $qty  = max(1, (int)($req['quantity'] ?? 1));
      $reqLocation = (string)($req['request_location'] ?? '');
      [$dm,$dc] = $getDesired($item);

      // Resolve approver full name
      $approverUser = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'system';
      $approverName = $approverUser;
      try { $uDoc = $db->selectCollection('users')->findOne(['username'=>$approverUser], ['projection'=>['full_name'=>1]]); if ($uDoc && isset($uDoc['full_name']) && trim((string)$uDoc['full_name'])!=='') { $approverName = (string)$uDoc['full_name']; } } catch (Throwable $eun) {}

      if ($reqType === 'reservation') {
        // Validate reservation times
        if ($reserveStart === '' || $reserveEnd === '') { header('Location: admin_borrow_center.php?error=time_required'); exit(); }
        $tsStart = strtotime($reserveStart); $tsEnd = strtotime($reserveEnd);
        if (!$tsStart || !$tsEnd || $tsEnd <= $tsStart || $tsStart <= time()) { header('Location: admin_borrow_center.php?error=time_required'); exit(); }
        // Normalize input times to 'Y-m-d H:i:s'
        $reserveStartNorm = date('Y-m-d H:i:s', $tsStart);
        $reserveEndNorm   = date('Y-m-d H:i:s', $tsEnd);
        // Optional: assign a specific serial/model for this reservation
        $assignedUnit = null; $assignedMid = 0; $assignedSerial = '';
        if ($serial !== '') { $assignedUnit = $ii->findOne(['serial_no'=>$serial]); }
        elseif ($modelIdIn > 0) { $assignedUnit = $ii->findOne(['id'=>$modelIdIn]); }
        if ($assignedUnit) { $assignedMid = (int)($assignedUnit['id'] ?? 0); $assignedSerial = (string)($assignedUnit['serial_no'] ?? ''); }
        // If assigning a unit, enforce no overlapping reservations on the same unit with 5-minute buffer
        if ($assignedMid > 0) {
          $buf = 5*60;
          $conflicts = [];
          try {
            $curR = $er->find(['type'=>'reservation','status'=>'Approved','reserved_model_id'=>$assignedMid], ['projection'=>['reserved_from'=>1,'reserved_to'=>1,'id'=>1]]);
            foreach ($curR as $row) {
              $ofs = isset($row['reserved_from']) ? strtotime((string)$row['reserved_from']) : null;
              $ote = isset($row['reserved_to']) ? strtotime((string)$row['reserved_to']) : null;
              if (!$ofs || !$ote) { continue; }
              // conflict unless there is at least 5-min gap between intervals
              $noOverlapWithBuffer = ($ote <= ($tsStart - $buf)) || ($tsEnd <= ($ofs - $buf));
              if (!$noOverlapWithBuffer) { $conflicts[] = (int)($row['id'] ?? 0); }
            }
          } catch (Throwable $echk) {}
          if (!empty($conflicts)) { header('Location: admin_borrow_center.php?error=serial_reserved_conflict'); exit(); }
        }
        // Mark request approved as reservation; do not claim an item now. Persist assigned unit if any.
        $set = [
          'status'=>'Approved', 'approved_at'=>$now, 'approved_by'=>$approverName,
          'type'=>'reservation', 'reserved_from'=>$reserveStartNorm, 'reserved_to'=>$reserveEndNorm
        ];
        if ($assignedMid > 0) { $set['reserved_model_id'] = $assignedMid; }
        if ($assignedSerial !== '') { $set['reserved_serial_no'] = $assignedSerial; }
        $er->updateOne(['id'=>$id, 'status'=>'Pending'], ['$set'=>$set]);
        ab_fcm_notify_request_status($db, $req, 'Approved');
        header('Location: admin_borrow_center.php?approved=1'); exit();
      }

      // Immediate borrow: require expected return
      if ($expectedReturn === '') { header('Location: admin_borrow_center.php?error=time_required'); exit(); }
      $tsExpected = strtotime($expectedReturn); if (!$tsExpected || $tsExpected <= time()) { header('Location: admin_borrow_center.php?error=time_required'); exit(); }

      // Apply 5-min cutoff only if effectively single-unit item
      $applyCutoff = false; $earliest = null;
      try {
        // total units
        $totalUnits = 0; $curI = $ii->find(['$or'=>[['model'=>$item], ['item_name'=>$item]]], ['projection'=>['quantity'=>1]]);
        foreach ($curI as $it) { $totalUnits += (int)($it['quantity'] ?? 1); }
        if ($totalUnits <= 1) {
          $q = [ 'item_name'=>$item, 'type'=>'reservation', 'status'=>'Approved', 'reserved_from'=>['$gt'=>date('Y-m-d H:i:s')] ];
          $proj = ['reserved_from'=>1];
          $curRes = $er->find($q, ['projection'=>$proj]);
          foreach ($curRes as $r) { $t = isset($r['reserved_from']) ? strtotime((string)$r['reserved_from']) : null; if ($t && ($earliest===null || $t < $earliest)) { $earliest = $t; } }
          $applyCutoff = (bool)$earliest;
        }
      } catch (Throwable $echeck) {}
      if ($applyCutoff && $earliest) {
        $cutoff = $earliest - (5*60);
        if ($tsExpected > $cutoff) { header('Location: admin_borrow_center.php?error=reservation_conflict'); exit(); }
      }

      try {
        $buCol = $db->selectCollection('borrowable_units');
        $unitForWhitelist = null;
        if ($serial !== '') {
          $unitForWhitelist = $ii->findOne(['serial_no'=>$serial], ['projection'=>['id'=>1]]);
        } elseif ($modelIdIn > 0) {
          $unitForWhitelist = $ii->findOne(['id'=>$modelIdIn], ['projection'=>['id'=>1]]);
        }
        if ($unitForWhitelist) {
          $uid = (int)($unitForWhitelist['id'] ?? 0);
          if ($uid > 0) {
            $whitelisted = (int)$buCol->countDocuments(['model_id' => ['$in' => [$uid, (string)$uid]]]) > 0;
            if (!$whitelisted) { header('Location: admin_borrow_center.php?error=preferred_unavailable'); exit(); }
          }
        }
      } catch (Throwable $_w2) {}

      // Proceed to claim a unit and mark borrowed
      if ($serial === '' && $modelIdIn <= 0) { header('Location: admin_borrow_center.php?error=preferred_unavailable'); exit(); }
      $query = ($serial !== '') ? ['serial_no'=>$serial,'status'=>'Available'] : ['id'=>$modelIdIn,'status'=>'Available'];
      $claimed = $ii->findOneAndUpdate(
        $query,
        ['$set'=>['status'=>'In Use','location'=>$reqLocation]],
        ['returnDocument'=>MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
      );
      if (!$claimed) { header('Location: admin_borrow_center.php?error=preferred_unavailable'); exit(); }
      $um = (string)($claimed['model'] ?? ($claimed['item_name'] ?? ''));
      $uc = trim((string)($claimed['category'] ?? '')) !== '' ? (string)$claimed['category'] : 'Uncategorized';
      $match = (strcasecmp(trim($um), trim($dm))===0) && (strcasecmp(trim($uc), trim($dc))===0);
      if (!$match) { $ii->updateOne(['id'=>(int)($claimed['id'] ?? 0)], ['$set'=>['status'=>'Available']]); header('Location: admin_borrow_center.php?error=item_mismatch'); exit(); }
      $borrowedAtFromReq = '';
      try {
        if (isset($req['created_at']) && $req['created_at'] instanceof MongoDB\BSON\UTCDateTime) {
          $dtBA = $req['created_at']->toDateTime();
          $dtBA->setTimezone(new DateTimeZone('Asia/Manila'));
          $borrowedAtFromReq = $dtBA->format('Y-m-d H:i:s');
        } else { $borrowedAtFromReq = (string)($req['created_at'] ?? $now); }
      } catch (Throwable $_ba) { $borrowedAtFromReq = (string)($req['created_at'] ?? $now); }
      $borrowId = $nextId('user_borrows');
      $snapItemName = (string)($claimed['item_name'] ?? '');
      $snapModel = (string)($claimed['model'] ?? '');
      $snapCategory = trim((string)($claimed['category'] ?? '')) !== '' ? (string)$claimed['category'] : 'Uncategorized';
      $snapSerial = (string)($claimed['serial_no'] ?? '');
      // Snapshot user identity for history/print stability
      $snapUserId = '';
      $snapSchoolId = '';
      $snapFullName = $user;
      try {
        $uCol = $db->selectCollection('users');
        $uSnap = $uCol->findOne(['username'=>$user], ['projection'=>['id'=>1,'school_id'=>1,'full_name'=>1]]);
        if ($uSnap) {
          $snapUserId = (string)($uSnap['id'] ?? '');
          $snapSchoolId = (string)($uSnap['school_id'] ?? '');
          $snapFullName = (string)($uSnap['full_name'] ?? $snapFullName);
        }
      } catch (Throwable $_us) {}
      $ub->insertOne([
        'id'=>$borrowId,
        'username'=>$user,
        'model_id'=>(int)($claimed['id'] ?? 0),
        'status'=>'Borrowed',
        'borrowed_at'=>$borrowedAtFromReq,
        'expected_return_at'=>$expectedReturn,
        // Snapshot fields for stable history
        'request_id'=>$id,
        'item_name'=>$snapItemName,
        'model'=>$snapModel,
        'category'=>$snapCategory,
        'serial_no'=>$snapSerial,
        'user_id'=>$snapUserId,
        'school_id'=>$snapSchoolId,
        'full_name'=>$snapFullName,
      ]);
      $exists = $ra->countDocuments(['request_id'=>$id,'borrow_id'=>$borrowId]);
      if ($exists === 0) { $ra->insertOne(['id'=>$nextId('request_allocations'),'request_id'=>$id,'borrow_id'=>$borrowId,'allocated_at'=>$now]); }
      $allocCount = $ra->countDocuments(['request_id'=>$id]);
      if ($allocCount >= $qty) {
        $er->updateOne(['id'=>$id, 'status'=>['$in'=>['Pending','Approved']]], ['$set'=>['status'=>'Borrowed','approved_at'=>$req['approved_at'] ?? $now,'approved_by'=>$approverName,'borrowed_at'=>$borrowedAtFromReq,'type'=>'immediate','expected_return_at'=>$expectedReturn]]);
        ab_fcm_notify_request_status($db, $req, 'Borrowed');
        header('Location: admin_borrow_center.php?approved=1&scroll=borrowed#borrowed-list'); exit();
      } else {
        $er->updateOne(['id'=>$id, 'status'=>'Pending'], ['$set'=>['approved_at'=>$now,'approved_by'=>$approverName,'type'=>'immediate','expected_return_at'=>$expectedReturn]]);
        $left = max(0, $qty - $allocCount);
        ab_fcm_notify_request_status($db, $req, 'Approved');
        header('Location: admin_borrow_center.php?allocated=1&left=' . $left . '&req=' . $id); exit();
      }
    }

    if ($act === 'edit_reservation_serial' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      $id = (int)($_POST['request_id'] ?? 0);
      $serial = trim((string)($_POST['serial_no'] ?? ''));
      if ($id <= 0 || $serial === '') { header('Location: admin_borrow_center.php?error=edit_serial_missing#reservations-list'); exit(); }
      $reqDoc = $er->findOne(['id'=>$id]);
      if (!$reqDoc || (string)($reqDoc['status'] ?? '') !== 'Approved' || (string)($reqDoc['type'] ?? '') !== 'reservation') { header('Location: admin_borrow_center.php?error=edit_serial_notapproved#reservations-list'); exit(); }
      $item = trim((string)($reqDoc['item_name'] ?? ''));
      if ($item === '') { header('Location: admin_borrow_center.php?error=edit_serial_baditem#reservations-list'); exit(); }
      // Ensure model has more than one total units
      $totalUnits = 0; try { $curI = $ii->find(['$or'=>[['model'=>$item], ['item_name'=>$item]]], ['projection'=>['quantity'=>1]]); foreach ($curI as $it) { $totalUnits += (int)($it['quantity'] ?? 1); } } catch (Throwable $_) { $totalUnits = 0; }
      if ($totalUnits <= 1) { header('Location: admin_borrow_center.php?error=edit_serial_single#reservations-list'); exit(); }
      // Resolve desired model/category
      [$dm,$dc] = $getDesired($item);
      // Find unit by serial
      $unit = $ii->findOne(['serial_no'=>$serial]);
      if (!$unit) { header('Location: admin_borrow_center.php?error=edit_serial_notfound#reservations-list'); exit(); }
      $um = (string)($unit['model'] ?? ($unit['item_name'] ?? ''));
      $uc = trim((string)($unit['category'] ?? '')) !== '' ? (string)$unit['category'] : 'Uncategorized';
      $match = (strcasecmp(trim($um), trim($dm))===0) && (strcasecmp(trim($uc), trim($dc))===0);
      if (!$match) { header('Location: admin_borrow_center.php?error=edit_serial_mismatch#reservations-list'); exit(); }
      // Conflict check on the specific unit with 5-min buffer
      $tsStart = isset($reqDoc['reserved_from']) ? strtotime((string)$reqDoc['reserved_from']) : null;
      $tsEnd   = isset($reqDoc['reserved_to']) ? strtotime((string)$reqDoc['reserved_to']) : null;
      if (!$tsStart || !$tsEnd || $tsEnd <= $tsStart) { header('Location: admin_borrow_center.php?error=edit_serial_time#reservations-list'); exit(); }
      $assignedMid = (int)($unit['id'] ?? 0);
      $buf = 5*60; $conflict = false;
      try {
        $curR = $er->find(['type'=>'reservation','status'=>'Approved','reserved_model_id'=>$assignedMid,'id'=>['$ne'=>$id]], ['projection'=>['reserved_from'=>1,'reserved_to'=>1,'id'=>1]]);
        foreach ($curR as $row) {
          $ofs = isset($row['reserved_from']) ? strtotime((string)$row['reserved_from']) : null;
          $ote = isset($row['reserved_to']) ? strtotime((string)$row['reserved_to']) : null;
          if (!$ofs || !$ote) { continue; }
          $noOverlapWithBuffer = ($ote <= ($tsStart - $buf)) || ($tsEnd <= ($ofs - $buf));
          if (!$noOverlapWithBuffer) { $conflict = true; break; }
        }
      } catch (Throwable $_) { $conflict = false; }
      if ($conflict) { header('Location: admin_borrow_center.php?error=edit_serial_conflict#reservations-list'); exit(); }
      // If the unit is currently borrowed, ensure its expected return precedes our reservation start with buffer
      try {
        $currBorrow = $ub->findOne(['model_id'=>$assignedMid,'status'=>'Borrowed'], ['projection'=>['id'=>1]]);
      } catch (Throwable $_) { $currBorrow = null; }
      if ($currBorrow) {
        $al = null; try { $al = $ra->findOne(['borrow_id'=>(int)($currBorrow['id']??0)], ['projection'=>['request_id'=>1]]); } catch (Throwable $_) { $al = null; }
        $endTs = null;
        if ($al && isset($al['request_id'])) {
          try {
            $origReq = $er->findOne(['id'=>(int)$al['request_id']], ['projection'=>['expected_return_at'=>1,'reserved_to'=>1]]);
            if ($origReq) {
              $endStr = (string)($origReq['expected_return_at'] ?? ($origReq['reserved_to'] ?? ''));
              if ($endStr !== '') { $endTs = strtotime($endStr); }
            }
          } catch (Throwable $_) { $endTs = null; }
        }
        if (!$endTs) { header('Location: admin_borrow_center.php?error=edit_serial_inuse#reservations-list'); exit(); }
        if ($endTs <= time()) { header('Location: admin_borrow_center.php?error=edit_serial_overdue#reservations-list'); exit(); }
        if (!($endTs <= ($tsStart - $buf))) { header('Location: admin_borrow_center.php?error=edit_serial_inuse#reservations-list'); exit(); }
      }
      // Save assignment and record edit note for user notification
      $assignedSerial = (string)($unit['serial_no'] ?? '');
      $locStr = (string)($unit['location'] ?? '');
      $er->updateOne(['id'=>$id], ['$set'=>[
        'reserved_model_id'=>$assignedMid,
        'reserved_serial_no'=>$assignedSerial,
        'edited_at'=>$now,
        'edit_note'=>'Edited to ' . $assignedSerial . ($locStr!=='' ? (' @ ' . $locStr) : '')
      ]]);
      // FCM push: notify user that their reservation serial was edited
      try {
        if (function_exists('fcm_send_to_user_tokens') && isset($db) && $db) {
          $uname = trim((string)($reqDoc['username'] ?? ''));
          if ($uname !== '') {
            $title = 'Reservation updated';
            $body = 'Reservation #' . $id . ' updated to [' . $assignedSerial . ']';
            if ($locStr !== '') { $body .= ' @ ' . $locStr; }
            $extra = [
              'request_id' => $id,
              'type' => 'reservation',
              'status' => 'Edited',
              'serial_no' => $assignedSerial,
              'location' => $locStr,
            ];
            fcm_send_to_user_tokens(
              $db,
              $uname,
              $title,
              $body,
              fcm_full_url('/inventory/user_request.php'),
              $extra
            );
          }
        }
      } catch (Throwable $_fcm_edit) { /* ignore */ }
      header('Location: admin_borrow_center.php?edit_serial=1#reservations-list'); exit();
    }

    if ($act === 'approve') {
      $id = (int)(($_GET['id'] ?? ($_POST['id'] ?? 0)));
      $ct = $_SERVER['CONTENT_TYPE'] ?? '';
      if ($id <= 0 && stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];
        $id = (int)($data['id'] ?? 0);
      }
      $req = $er->findOne(['id'=>$id]);
      if (!$req || (string)($req['status'] ?? '') !== 'Pending') { header('Location: admin_borrow_center.php?error=notpend'); exit(); }
      $user = trim((string)($req['username'] ?? ''));
      $item = trim((string)($req['item_name'] ?? ''));
      $qty  = max(1, (int)($req['quantity'] ?? 1));
      $preferredId = 0;
      $details = (string)($req['details'] ?? '');
      if ($details !== '' && preg_match('/Scanned\s+Model\s+ID:\s*(\d+)/i', $details, $m)) { $preferredId = (int)($m[1] ?? 0); }
      $picked = [];
      if ($preferredId > 0) {
        $c = $ii->findOneAndUpdate(['id'=>$preferredId,'status'=>'Available'], ['$set'=>['status'=>'In Use','location'=>(string)($req['request_location'] ?? '')]], ['returnDocument'=>MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]);
        if ($c && (int)($c['id'] ?? 0) === $preferredId) { $picked[] = $preferredId; }
      }
      $need = $qty - count($picked);
      if ($need > 0) {
        $cur = $ii->find([
          'status'=>'Available',
          '$or' => [ ['model'=>$item], ['item_name'=>$item] ],
          $preferredId>0 ? ['id' => ['$ne'=>$preferredId]] : []
        ], ['sort'=>['id'=>1]]);
        foreach ($cur as $doc) {
          if ($need <= 0) break;
          $mid = (int)($doc['id'] ?? 0);
          $c = $ii->findOneAndUpdate(['id'=>$mid,'status'=>'Available'], ['$set'=>['status'=>'In Use','location'=>(string)($req['request_location'] ?? '')]], ['returnDocument'=>MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]);
          if ($c) { $picked[] = $mid; $need--; }
        }
      }
      if (count($picked) < $qty) { foreach ($picked as $mid) { $ii->updateOne(['id'=>$mid], ['$set'=>['status'=>'Available']]); } header('Location: admin_borrow_center.php?insufficient=1'); exit(); }
      // Resolve approver full name
      $approverUser = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'system';
      $approverName = $approverUser;
      try { $uDoc = $db->selectCollection('users')->findOne(['username'=>$approverUser], ['projection'=>['full_name'=>1]]); if ($uDoc && isset($uDoc['full_name']) && trim((string)$uDoc['full_name'])!=='') { $approverName = (string)$uDoc['full_name']; } } catch (Throwable $eun) {}
      $borrowedAtFromReq = '';
      try {
        if (isset($req['created_at']) && $req['created_at'] instanceof MongoDB\BSON\UTCDateTime) {
          $dtBA = $req['created_at']->toDateTime();
          $dtBA->setTimezone(new DateTimeZone('Asia/Manila'));
          $borrowedAtFromReq = $dtBA->format('Y-m-d H:i:s');
        } else { $borrowedAtFromReq = (string)($req['created_at'] ?? $now); }
      } catch (Throwable $_ba) { $borrowedAtFromReq = (string)($req['created_at'] ?? $now); }
      $er->updateOne(['id'=>$id, 'status'=>'Pending'], ['$set'=>['status'=>'Borrowed','approved_at'=>$req['approved_at'] ?? $now,'approved_by'=>$approverName,'borrowed_at'=>$borrowedAtFromReq]]);
      foreach ($picked as $mid) {
        $borrowId = $nextId('user_borrows');
        $snapItemName = '';
        $snapModel = '';
        $snapCategory = 'Uncategorized';
        $snapSerial = '';
        try {
          $doc = $ii->findOne(['id'=>$mid], ['projection'=>['item_name'=>1,'model'=>1,'category'=>1,'serial_no'=>1]]);
          if ($doc) {
            $snapItemName = (string)($doc['item_name'] ?? '');
            $snapModel = (string)($doc['model'] ?? '');
            $snapCategory = trim((string)($doc['category'] ?? '')) !== '' ? (string)$doc['category'] : 'Uncategorized';
            $snapSerial = (string)($doc['serial_no'] ?? '');
          }
        } catch (Throwable $_snap) {}
        $ub->insertOne([
          'id'=>$borrowId,
          'username'=>$user,
          'model_id'=>$mid,
          'status'=>'Borrowed',
          'borrowed_at'=>$borrowedAtFromReq,
          // Snapshot fields for stable history
          'request_id'=>$id,
          'item_name'=>$snapItemName,
          'model'=>$snapModel,
          'category'=>$snapCategory,
          'serial_no'=>$snapSerial,
        ]);
        $ra->insertOne(['id'=>$nextId('request_allocations'),'request_id'=>$id,'borrow_id'=>$borrowId,'allocated_at'=>$now]);
      }
      ab_fcm_notify_request_status($db, $req, 'Borrowed');
      header('Location: admin_borrow_center.php?scroll=borrowed#borrowed-list'); exit();
    }

    if ($act === 'validate_return_id' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      header('Content-Type: application/json');
      $reqId = (int)($_POST['request_id'] ?? 0);
      $serial = trim((string)($_POST['serial_no'] ?? ''));
      $modelIdIn = (int)($_POST['model_id'] ?? 0);
      $ct = $_SERVER['CONTENT_TYPE'] ?? '';
      if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];
        if ($reqId <= 0) { $reqId = (int)($data['request_id'] ?? 0); }
        if ($serial === '') { $serial = trim((string)($data['serial_no'] ?? '')); }
        if ($modelIdIn <= 0) { $modelIdIn = (int)($data['model_id'] ?? 0); }
      }
      if ($reqId <= 0) { echo json_encode(['ok'=>false,'reason'=>'Missing parameters']); exit; }
      $req = $er->findOne(['id'=>$reqId]);
      if (!$req) { echo json_encode(['ok'=>false,'reason'=>'Request not found']); exit; }
      $unit = null;
      if ($serial !== '') { $unit = $ii->findOne(['serial_no'=>$serial]); }
      elseif ($modelIdIn > 0) { $unit = $ii->findOne(['id'=>$modelIdIn]); }
      if (!$unit) { echo json_encode(['ok'=>false,'reason'=>'Serial not found']); exit; }
      // Collect active borrows linked to this request
      $allocs = iterator_to_array($ra->find(['request_id'=>$reqId], ['projection'=>['borrow_id'=>1]]));
      $borrowIds = array_values(array_filter(array_map(function($d){ return isset($d['borrow_id']) ? (int)$d['borrow_id'] : 0; }, $allocs)));
      if (empty($borrowIds)) { echo json_encode(['ok'=>false,'reason'=>'No active borrow for request']); exit; }
      $mid = (int)($unit['id'] ?? 0);
      $matchBorrow = $ub->findOne(['id' => ['$in'=>$borrowIds], 'status'=>'Borrowed', 'model_id'=>$mid]);
      if (!$matchBorrow) { echo json_encode(['ok'=>false,'reason'=>'The scanned item does not match the borrowed record.']); exit; }
      $um = (string)($unit['model'] ?? ($unit['item_name'] ?? ''));
      $uc = trim((string)($unit['category'] ?? '')) !== '' ? (string)$unit['category'] : 'Uncategorized';
      echo json_encode(['ok'=>true,'reason'=>'OK','model'=>$um,'category'=>$uc,'id'=>$mid]); exit;
    }

    if ($act === 'return_with' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      $reqId = (int)($_POST['request_id'] ?? 0);
      $serial = trim((string)($_POST['serial_no'] ?? ''));
      $modelIdIn = (int)($_POST['model_id'] ?? 0);
      $ct = $_SERVER['CONTENT_TYPE'] ?? '';
      if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];
        if ($reqId <= 0) { $reqId = (int)($data['request_id'] ?? 0); }
        if ($serial === '') { $serial = trim((string)($data['serial_no'] ?? '')); }
        if ($modelIdIn <= 0) { $modelIdIn = (int)($data['model_id'] ?? 0); }
      }
      if ($reqId <= 0) { header('Location: admin_borrow_center.php?error=return_missing'); exit(); }
      $req = $er->findOne(['id'=>$reqId]);
      if (!$req) { header('Location: admin_borrow_center.php?error=return_req_notfound'); exit(); }
      $unit = null;
      if ($serial !== '') { $unit = $ii->findOne(['serial_no'=>$serial]); }
      elseif ($modelIdIn > 0) { $unit = $ii->findOne(['id'=>$modelIdIn]); }
      if (!$unit) { header('Location: admin_borrow_center.php?error=return_serial_notfound'); exit(); }
      $mid = (int)($unit['id'] ?? 0);
      // Find an active borrow for this request with this model_id
      $allocs = iterator_to_array($ra->find(['request_id'=>$reqId], ['projection'=>['borrow_id'=>1]]));
      $borrowIds = array_values(array_filter(array_map(function($d){ return isset($d['borrow_id']) ? (int)$d['borrow_id'] : 0; }, $allocs)));
      if (empty($borrowIds)) { header('Location: admin_borrow_center.php?error=return_no_alloc'); exit(); }
      $borrow = $ub->findOne(['id' => ['$in'=>$borrowIds], 'status'=>'Borrowed', 'model_id'=>$mid]);
      if (!$borrow) { header('Location: admin_borrow_center.php?error=return_not_borrowed'); exit(); }
      // Process return
      $ii->updateOne(['id'=>$mid], ['$set'=>['status'=>'Available','location'=>'MIS Office']]);
      $ub->updateOne(['id'=>(int)($borrow['id'] ?? 0), 'status'=>'Borrowed'], ['$set'=>['status'=>'Returned','returned_at'=>$now]]);
      // Ensure whitelisted
      try {
        $buCol = $db->selectCollection('borrowable_units');
        $bcCol = $db->selectCollection('borrowable_catalog');
        $it = $ii->findOne(['id'=>$mid], ['projection'=>['model'=>1,'item_name'=>1,'category'=>1]]);
        $mn = $it ? (string)($it['model'] ?? ($it['item_name'] ?? '')) : '';
        $cat = $it ? (string)(($it['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized';
        if ($mn !== '') {
          $isActive = (bool)$bcCol->countDocuments(['model_name'=>$mn,'category'=>$cat,'active'=>1]);
          if ($isActive) {
            if ((int)$buCol->countDocuments(['model_id'=>$mid]) === 0) { $buCol->insertOne(['model_id'=>$mid,'model_name'=>$mn,'category'=>$cat,'created_at'=>$now]); }
          }
        }
      } catch (Throwable $_e) {}
      // If no remaining active borrows, mark request Returned
      $rem = $ub->countDocuments(['id'=>['$in'=>$borrowIds], 'status'=>'Borrowed']);
      if ($rem <= 0) {
        $er->updateOne(['id'=>$reqId], ['$set'=>['status'=>'Returned','returned_at'=>$now]]);
        // Use the already-loaded $req document for FCM notification
        if (isset($req) && $req) {
          ab_fcm_notify_request_status($db, $req, 'Returned');
        }
      }
      header('Location: admin_borrow_center.php?scroll=borrowed#borrowed-list'); exit();
    }
    if ($act === 'validate_return_id' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      header('Content-Type: application/json');
      $reqId = (int)($_POST['request_id'] ?? 0);
      $serial = trim((string)($_POST['serial_no'] ?? ''));
      if ($reqId <= 0 || $serial === '') { echo json_encode(['ok'=>false,'reason'=>'Missing parameters']); exit; }
      $ct = $_SERVER['CONTENT_TYPE'] ?? '';
      if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];
        if ($reqId <= 0) { $reqId = (int)($data['request_id'] ?? 0); }
        if ($serial === '') { $serial = trim((string)($data['serial_no'] ?? '')); }
      }
      $req = $er->findOne(['id'=>$reqId]);
      if (!$req) { echo json_encode(['ok'=>false,'reason'=>'Request not found']); exit; }
      $unit = $ii->findOne(['serial_no'=>$serial]);
      if (!$unit) { echo json_encode(['ok'=>false,'reason'=>'Serial not found']); exit; }
      $allocs = iterator_to_array($ra->find(['request_id'=>$reqId], ['projection'=>['borrow_id'=>1]]));
      $borrowIds = array_values(array_filter(array_map(function($d){ return isset($d['borrow_id']) ? (int)$d['borrow_id'] : 0; }, $allocs)));
      if (empty($borrowIds)) { echo json_encode(['ok'=>false,'reason'=>'No active borrow for request']); exit; }
      $mid = (int)($unit['id'] ?? 0);
      $matchBorrow = $ub->findOne(['id' => ['$in'=>$borrowIds], 'status'=>'Borrowed', 'model_id'=>$mid]);
      if (!$matchBorrow) { echo json_encode(['ok'=>false,'reason'=>'The scanned item does not match the borrowed record.']); exit; }
      $um = (string)($unit['model'] ?? ($unit['item_name'] ?? ''));
      $uc = trim((string)($unit['category'] ?? '')) !== '' ? (string)$unit['category'] : 'Uncategorized';
      echo json_encode(['ok'=>true,'reason'=>'OK','model'=>$um,'category'=>$uc,'id'=>$mid]); exit;
    }
    if ($act === 'return_with' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      $reqId = (int)($_POST['request_id'] ?? 0);
      $serial = trim((string)($_POST['serial_no'] ?? ''));
      $ct = $_SERVER['CONTENT_TYPE'] ?? '';
      if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];
        if ($reqId <= 0) { $reqId = (int)($data['request_id'] ?? 0); }
        if ($serial === '') { $serial = trim((string)($data['serial_no'] ?? '')); }
      }
      if ($reqId <= 0 || $serial === '') { header('Location: admin_borrow_center.php?error=return_missing'); exit(); }
      $req = $er->findOne(['id'=>$reqId]);
      if (!$req) { header('Location: admin_borrow_center.php?error=return_req_notfound'); exit(); }
      $unit = $ii->findOne(['serial_no'=>$serial]);
      if (!$unit) { header('Location: admin_borrow_center.php?error=return_serial_notfound'); exit(); }
      $mid = (int)($unit['id'] ?? 0);
      $allocs = iterator_to_array($ra->find(['request_id'=>$reqId], ['projection'=>['borrow_id'=>1]]));
      $borrowIds = array_values(array_filter(array_map(function($d){ return isset($d['borrow_id']) ? (int)$d['borrow_id'] : 0; }, $allocs)));
      if (empty($borrowIds)) { header('Location: admin_borrow_center.php?error=return_no_alloc'); exit(); }
      $borrow = $ub->findOne(['id' => ['$in'=>$borrowIds], 'status'=>'Borrowed', 'model_id'=>$mid]);
      if (!$borrow) { header('Location: admin_borrow_center.php?error=return_not_borrowed'); exit(); }
      $ii->updateOne(['id'=>$mid], ['$set'=>['status'=>'Available']]);
      $ub->updateOne(['id'=>(int)($borrow['id'] ?? 0), 'status'=>'Borrowed'], ['$set'=>['status'=>'Returned','returned_at'=>$now]]);
      $rem = $ub->countDocuments(['id'=>['$in'=>$borrowIds], 'status'=>'Borrowed']);
      if ($rem <= 0) { $er->updateOne(['id'=>$reqId], ['$set'=>['status'=>'Returned','returned_at'=>$now]]); }
      header('Location: admin_borrow_center.php?scroll=borrowed#borrowed-list'); exit();
    }

    // ===== QR Returnship helpers =====
    if ($act === 'returnship_status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
      header('Content-Type: application/json');
      $reqId = (int)($_GET['request_id'] ?? 0);
      if ($reqId <= 0) { echo json_encode(['ok'=>false,'reason'=>'Missing request_id']); exit; }
      $rsCol = $db->selectCollection('returnship_requests');
      $doc = $rsCol->findOne(['request_id'=>$reqId], ['sort'=>['id'=>-1]]);
      if (!$doc) { echo json_encode(['ok'=>true,'exists'=>false]); exit; }
      $exists = true;
      $verified = !empty($doc['verified_at']);
      $loc = (string)($doc['location'] ?? '');
      echo json_encode(['ok'=>true,'exists'=>$exists,'verified'=>$verified,'location'=>$loc,'status'=>(string)($doc['status'] ?? 'Pending')]); exit;
    }
    if ($act === 'request_returnship' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      $reqId = (int)($_POST['request_id'] ?? 0);
      if ($reqId <= 0) { header('Location: admin_borrow_center.php?error=return_req_notfound'); exit(); }
      $reqDoc = $er->findOne(['id'=>$reqId]); if (!$reqDoc) { header('Location: admin_borrow_center.php?error=return_req_notfound'); exit(); }
      $rsCol = $db->selectCollection('returnship_requests');
      $last = $rsCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
      $nid = ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
      $rsCol->insertOne([
        'id'=>$nid,
        'request_id'=>$reqId,
        'username'=>(string)($reqDoc['username'] ?? ''),
        'model_name'=>(string)($reqDoc['item_name'] ?? ''),
        'qr_serial_no'=>(string)($reqDoc['qr_serial_no'] ?? ''),
        'status'=>'Pending',
        'created_at'=>$now,
        'created_by'=>'admin'
      ]);
      ab_fcm_notify_request_status($db, $reqDoc, 'Return Requested');
      header('Location: admin_borrow_center.php?scroll=borrowed#borrowed-list'); exit();
    }
    if ($act === 'approve_returnship' && $_SERVER['REQUEST_METHOD'] === 'POST') {
      $reqId = (int)($_POST['request_id'] ?? 0);
      $ct = $_SERVER['CONTENT_TYPE'] ?? '';
      if ($reqId <= 0 && stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];
        $reqId = (int)($data['request_id'] ?? 0);
      }
      if ($reqId <= 0) { header('Location: admin_borrow_center.php?error=return_missing'); exit(); }
      $reqDoc = $er->findOne(['id'=>$reqId]);
      if (!$reqDoc) { header('Location: admin_borrow_center.php?error=return_req_notfound'); exit(); }
      $rsCol = $db->selectCollection('returnship_requests');
      $rr = $rsCol->findOne(['request_id'=>$reqId], ['sort'=>['id'=>-1]]);
      if (!$rr || empty($rr['verified_at'])) { header('Location: admin_borrow_center.php?error=return_not_verified'); exit(); }
      // Strict: verified_serial must equal request.qr_serial_no and map to the same unit
      $origSerial = trim((string)($reqDoc['qr_serial_no'] ?? ''));
      $verSerial = trim((string)($rr['verified_serial'] ?? ''));
      if ($origSerial === '' || strcasecmp($origSerial, $verSerial) !== 0) { header('Location: admin_borrow_center.php?error=serial_mismatch'); exit(); }
      $unit = $ii->findOne(['serial_no'=>$verSerial]); if (!$unit) { header('Location: admin_borrow_center.php?error=serial_not_found'); exit(); }
      $midVerified = (int)($unit['id'] ?? 0);
      // Ensure an active borrow under this request matches that exact unit
      $allocs = iterator_to_array($ra->find(['request_id'=>$reqId], ['projection'=>['borrow_id'=>1]]));
      $borrowIds = array_values(array_filter(array_map(function($d){ return isset($d['borrow_id']) ? (int)$d['borrow_id'] : 0; }, $allocs)));
      if (empty($borrowIds)) { header('Location: admin_borrow_center.php?error=return_no_alloc'); exit(); }
      $borrow = $ub->findOne(['id'=>['$in'=>$borrowIds], 'status'=>'Borrowed', 'model_id'=>$midVerified]);
      if (!$borrow) { header('Location: admin_borrow_center.php?error=return_not_borrowed'); exit(); }
      // Process return
      // Update location based on user-provided return location if available; fallback to current item location or MIS Office
      $locSet = '';
      try { $locSet = trim((string)($rr['location'] ?? '')); } catch (Throwable $_l) { $locSet = ''; }
      if ($locSet === '') { $locSet = (string)($unit['location'] ?? ''); if ($locSet === '') { $locSet = 'MIS Office'; } }
      $ii->updateOne(['id'=>$midVerified], ['$set'=>['status'=>'Available','location'=>$locSet]]);
      $ub->updateOne(['id'=>(int)($borrow['id'] ?? 0), 'status'=>'Borrowed'], ['$set'=>['status'=>'Returned','returned_at'=>$now]]);
      // Ensure whitelisted
      try {
        $buCol = $db->selectCollection('borrowable_units');
        $bcCol = $db->selectCollection('borrowable_catalog');
        $it = $ii->findOne(['id'=>$midVerified], ['projection'=>['model'=>1,'item_name'=>1,'category'=>1]]);
        $mn = $it ? (string)($it['model'] ?? ($it['item_name'] ?? '')) : '';
        $cat = $it ? (string)(($it['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized';
        if ($mn !== '') {
          $isActive = (bool)$bcCol->countDocuments(['model_name'=>$mn,'category'=>$cat,'active'=>1]);
          if ($isActive) {
            if ((int)$buCol->countDocuments(['model_id'=>$midVerified]) === 0) { $buCol->insertOne(['model_id'=>$midVerified,'model_name'=>$mn,'category'=>$cat,'created_at'=>$now]); }
          }
        }
      } catch (Throwable $_e) {}
      $rem = $ub->countDocuments(['id'=>['$in'=>$borrowIds], 'status'=>'Borrowed']);
      if ($rem <= 0) { $er->updateOne(['id'=>$reqId], ['$set'=>['status'=>'Returned','returned_at'=>$now]]); }
      $rsCol->updateOne(['id'=>(int)($rr['id'] ?? 0)], ['$set'=>['status'=>'Approved','approved_at'=>$now,'approved_by'=>(string)($_SESSION['username'] ?? 'admin')]]);
      header('Location: admin_borrow_center.php?scroll=borrowed#borrowed-list'); exit();
    }
    // ===== End QR Returnship helpers =====

    // Lightweight admin feed for user return verifications
    if ($act === 'returnship_feed' && $_SERVER['REQUEST_METHOD'] === 'GET') {
      header('Content-Type: application/json');
      $rsCol = $db->selectCollection('returnship_requests');
      $cur = $rsCol->find(['status' => ['$in'=>['Requested']], 'verified_at' => ['$exists' => true, '$ne' => '']], ['sort'=>['id'=>-1], 'limit'=>150]);
      $out = [];
      foreach ($cur as $r) {
        $out[] = [
          'id' => (int)($r['id'] ?? 0),
          'request_id' => (int)($r['request_id'] ?? 0),
          'username' => (string)($r['username'] ?? ''),
          'model_name' => (string)($r['model_name'] ?? ''),
          'qr_serial_no' => (string)($r['qr_serial_no'] ?? ''),
          'location' => (string)($r['location'] ?? ''),
          'verified_at' => (string)($r['verified_at'] ?? ''),
        ];
      }
      echo json_encode(['ok'=>true,'verifications'=>$out]); exit;
    }
    // Recent user returns feed (self returns via QR)
    if ($act === 'return_feed' && $_SERVER['REQUEST_METHOD'] === 'GET') {
      header('Content-Type: application/json');
      try {
        $rfCol = $db->selectCollection('return_events');
        $cur = $rfCol->find([], ['sort'=>['id'=>-1], 'limit'=>80]);
        $rows = [];
        foreach ($cur as $e) {
          $rows[] = [
            'id' => (int)($e['id'] ?? 0),
            'request_id' => (int)($e['request_id'] ?? 0),
            'model_name' => (string)($e['model_name'] ?? ''),
            'qr_serial_no' => (string)($e['qr_serial_no'] ?? ''),
            'location' => (string)($e['location'] ?? ''),
            'username' => (string)($e['username'] ?? ''),
            'created_at' => (string)($e['created_at'] ?? ''),
          ];
        }
        echo json_encode(['ok'=>true,'returns'=>$rows]);
      } catch (Throwable $e) { echo json_encode(['ok'=>false,'returns'=>[]]); }
      exit;
    }
  } catch (Throwable $e) {
    header('Location: admin_borrow_center.php?error=tx'); exit();
  }
}

// Early-handle JSON endpoints via MongoDB to avoid MySQL dependency
if ($act === 'pending_json' || $act === 'borrowed_json' || $act === 'reservations_json' || $act === 'validate_reservation_serial' || $act === 'list_reservation_serials') {
  // Apply a sane default limit for heavy lists to keep responses fast
  $reqLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : 300;
  if ($reqLimit < 50) { $reqLimit = 50; }
  if ($reqLimit > 1000) { $reqLimit = 1000; }
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  header('Content-Type: application/json');
  try {
    $db = get_mongo_db();
    if ($act === 'pending_json') {
      $rows = [];
      $er = $db->selectCollection('equipment_requests');
      $uCol = $db->selectCollection('users');
      // Auto-reject any Pending reservations whose start time has already passed
      try {
        $nowStr = date('Y-m-d H:i:s');
        $curPendRes = $er->find(['status'=>'Pending','type'=>'reservation']);
        foreach ($curPendRes as $pd) {
          $rf = (string)($pd['reserved_from'] ?? '');
          $ts = $rf !== '' ? strtotime($rf) : null;
          if ($ts && $ts <= time()) {
            $er->updateOne(['id'=>(int)($pd['id'] ?? 0), 'status'=>'Pending'], ['$set'=>[
              'status'=>'Rejected', 'rejected_at'=>$nowStr, 'rejected_reason'=>'Auto-rejected: reservation start passed without approval'
            ]]);
            ab_fcm_notify_request_status($db, $pd, 'Rejected', 'Auto-rejected: reservation start passed without approval');
          }
        }
        $curPendImm = $er->find(['status'=>'Pending','type'=>'immediate']);
        foreach ($curPendImm as $pd) {
          $erAt = (string)($pd['expected_return_at'] ?? '');
          $ts = $erAt !== '' ? strtotime($erAt) : null;
          if ($ts && $ts <= time()) {
            $er->updateOne(['id'=>(int)($pd['id'] ?? 0), 'status'=>'Pending'], ['$set'=>[
              'status'=>'Rejected', 'rejected_at'=>$nowStr, 'rejected_reason'=>'Auto-rejected: expected return reached without approval'
            ]]);
            ab_fcm_notify_request_status($db, $pd, 'Rejected', 'Auto-rejected: expected return reached without approval');
          }
        }
      } catch (Throwable $_) {}
      $rq = $er->find(['status' => 'Pending'], ['sort' => ['created_at' => 1, 'id' => 1], 'limit' => $reqLimit]);
      $itemsCol = $db->selectCollection('inventory_items');
      $allocCol = $db->selectCollection('request_allocations');
      foreach ($rq as $doc) {
        $id = (int)($doc['id'] ?? 0);
        $itemName = (string)($doc['item_name'] ?? '');
        $qty = (int)($doc['quantity'] ?? 1);
        // If this model no longer exists in inventory (total quantity == 0), auto-reject
        $totAgg = $itemsCol->aggregate([
          ['$match'=>['$or'=>[['model'=>$itemName],['item_name'=>$itemName]]]],
          ['$project'=>['q'=>['$ifNull'=>['$quantity',1]]]],
          ['$group'=>['_id'=>null,'total'=>['$sum'=>'$q']]]
        ])->toArray();
        $totalQty = (int)($totAgg[0]['total'] ?? 0);
        if ($totalQty <= 0) {
          try { $er->updateOne(['id'=>$id,'status'=>'Pending'], ['$set'=>['status'=>'Rejected','rejected_at'=>date('Y-m-d H:i:s')]]); } catch (Throwable $eAuto) {}
          continue; // do not include in pending output
        }
        $available = $itemsCol->countDocuments([
          'status' => 'Available',
          '$or' => [ ['model' => $itemName], ['item_name' => $itemName] ]
        ]);
        $allocCount = $allocCol->countDocuments(['request_id' => $id]);
        // Build display time in Asia/Manila 12-hour format
        $createdLocal = '';
        try {
          if (isset($doc['created_at']) && $doc['created_at'] instanceof MongoDB\BSON\UTCDateTime) {
            $dt = $doc['created_at']->toDateTime();
            $dt->setTimezone(new DateTimeZone('Asia/Manila'));
          } else {
            $raw = (string)($doc['created_at'] ?? '');
            // Strings are stored already in local time; parse as Asia/Manila without shifting
            $dt = $raw !== '' ? new DateTime($raw, new DateTimeZone('Asia/Manila')) : new DateTime('now', new DateTimeZone('Asia/Manila'));
          }
          $createdLocal = $dt->format('h:i A m-d-y');
        } catch (Throwable $e2) {
          $createdLocal = (string)($doc['created_at'] ?? '');
        }
        // Resolve student id from users
        $uname = (string)($doc['username'] ?? '');
        $sid = '';
        $ufull = $uname;
        if ($uname !== '') {
          try {
            $u = $uCol->findOne(['username'=>$uname], ['projection'=>['school_id'=>1,'full_name'=>1,'first_name'=>1,'last_name'=>1,'name'=>1]]);
            if ($u) {
              if (isset($u['school_id'])) { $sid = (string)$u['school_id']; }
              if (isset($u['full_name']) && trim((string)$u['full_name'])!=='') { $ufull = (string)$u['full_name']; }
              elseif (isset($u['first_name']) || isset($u['last_name'])) { $ufull = trim((string)($u['first_name']??'').' '.(string)($u['last_name']??'')); }
              elseif (isset($u['name']) && trim((string)$u['name'])!=='') { $ufull = (string)$u['name']; }
            }
          } catch (Throwable $_) { $sid = ''; $ufull = $uname; }
        }
        $rows[] = [
          'id' => $id,
          'username' => $uname,
          'user_full_name' => $ufull,
          'school_id' => $sid,
          'item_name' => $itemName,
          'quantity' => $qty,
          'status' => (string)($doc['status'] ?? ''),
          'created_at' => (string)($doc['created_at'] ?? ''),
          'created_at_display' => $createdLocal,
          'request_location' => (string)($doc['request_location'] ?? ''),
          'details' => (string)($doc['details'] ?? ''),
          'type' => (string)($doc['type'] ?? ''),
          'expected_return_at' => (string)($doc['expected_return_at'] ?? ''),
          'reserved_from' => (string)($doc['reserved_from'] ?? ''),
          'reserved_to' => (string)($doc['reserved_to'] ?? ''),
          'qr_serial_no' => (string)($doc['qr_serial_no'] ?? ''),
          'available_count' => $available,
          'remaining' => max($qty - $allocCount, 0),
        ];
      }
      echo json_encode(['pending' => $rows]);
      exit();
    } else if ($act === 'borrowed_json') {
      $rows = [];
      $allocCol = $db->selectCollection('request_allocations');
      $ubCol = $db->selectCollection('user_borrows');
      $erCol = $db->selectCollection('equipment_requests');
      $iiCol = $db->selectCollection('inventory_items');
      $uCol = $db->selectCollection('users');
      // Only consider the most recent allocations to avoid scanning the whole collection
      $allocs = $allocCol->find([], ['sort' => ['id' => -1], 'limit' => $reqLimit, 'projection' => ['request_id' => 1, 'borrow_id' => 1]]);
      foreach ($allocs as $al) {
        $reqId = (int)($al['request_id'] ?? 0);
        $borrowId = (int)($al['borrow_id'] ?? 0);
        if ($reqId <= 0 || $borrowId <= 0) { continue; }
        $er = $erCol->findOne(['id' => $reqId]);
        if (!$er) { continue; }
        $ub = $ubCol->findOne(['id' => $borrowId]);
        if (!$ub) { continue; }
        // Only show if this allocation's borrow is still active
        if ((string)($ub['status'] ?? '') !== 'Borrowed') { continue; }
        $mid = (int)($ub['model_id'] ?? 0);
        $ii = $mid > 0 ? $iiCol->findOne(['id' => $mid]) : null;
        $model = '';
        $cat = 'Uncategorized';
        if ($ii) {
          $model = (string)($ii['model'] ?? ($ii['item_name'] ?? ''));
          $cat = trim((string)($ii['category'] ?? '')) !== '' ? (string)$ii['category'] : 'Uncategorized';
        }
        // Build expected_return_display in Asia/Manila 12-hour format (fallback to reservation end)
        $expectedDisp = '';
        try {
          $rawExp = (string)($er['expected_return_at'] ?? ($er['reserved_to'] ?? ''));
          if ($rawExp !== '') {
            // Strings are stored already in local time; parse as Asia/Manila without shifting
            $dtE = new DateTime($rawExp, new DateTimeZone('Asia/Manila'));
            $expectedDisp = $dtE->format('h:i A m-d-y');
          }
        } catch (Throwable $e2) { $expectedDisp = (string)($er['expected_return_at'] ?? ($er['reserved_to'] ?? '')); }
        // Resolve student id from users for borrower
        $uname = (string)($ub['username'] ?? '');
        $sid = '';
        $ufull = $uname;
        if ($uname !== '') {
          try {
            $u = $uCol->findOne(['username'=>$uname], ['projection'=>['school_id'=>1,'full_name'=>1,'first_name'=>1,'last_name'=>1,'name'=>1]]);
            if ($u) {
              if (isset($u['school_id'])) { $sid = (string)$u['school_id']; }
              if (isset($u['full_name']) && trim((string)$u['full_name'])!=='') { $ufull = (string)$u['full_name']; }
              elseif (isset($u['first_name']) || isset($u['last_name'])) { $ufull = trim((string)($u['first_name']??'').' '.(string)($u['last_name']??'')); }
              elseif (isset($u['name']) && trim((string)$u['name'])!=='') { $ufull = (string)$u['name']; }
            }
          } catch (Throwable $_) { /* keep defaults */ }
        }
        $rows[] = [
          'request_id' => $reqId,
          'username' => $uname,
          'user_full_name' => $ufull,
          'school_id' => $sid,
          'model_id' => $mid,
          'serial_no' => $ii ? (string)($ii['serial_no'] ?? '') : '',
          'model' => $model,
          'category' => $cat,
          'location' => $ii ? (string)($ii['location'] ?? '') : '',
          'expected_return_at' => (string)($er['expected_return_at'] ?? ($er['reserved_to'] ?? '')),
          'expected_return_display' => $expectedDisp,
          'type' => ((isset($er['qr_serial_no']) && trim((string)$er['qr_serial_no'])!=='') ? 'QR' : 'Manual'),
        ];
      }
      // Sort by expected_return_at desc, then id desc similar to SQL
      usort($rows, function($a,$b){
        $ta = strtotime((string)($a['expected_return_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['expected_return_at'] ?? '')) ?: 0;
        if ($ta === $tb) { return ($b['request_id'] <=> $a['request_id']); }
        return $tb <=> $ta;
      });
      echo json_encode(['borrowed' => $rows]);
      exit();
    } else if ($act === 'reservations_json') {
      // Auto-promote due reservations to Borrowed if start time has arrived and units are available
      $erCol = $db->selectCollection('equipment_requests');
      $iiCol = $db->selectCollection('inventory_items');
      $ubCol = $db->selectCollection('user_borrows');
      $allocCol = $db->selectCollection('request_allocations');
      $nowStr = date('Y-m-d H:i:s');
      // Preflight reassignment/cancellation for upcoming reservations tied to a specific serial
      try {
        $upcoming = $erCol->find([
          'type' => 'reservation',
          'status' => 'Approved',
          'reserved_from' => ['$gt' => $nowStr],
          'reserved_model_id' => ['$exists' => true, '$ne' => 0]
        ], ['projection'=>['id'=>1,'username'=>1,'item_name'=>1,'reserved_from'=>1,'reserved_to'=>1,'reserved_model_id'=>1]]);
        foreach ($upcoming as $rsv) {
          $rid = (int)($rsv['id'] ?? 0); if ($rid <= 0) continue;
          $itemName = (string)($rsv['item_name'] ?? ''); if ($itemName==='') continue;
          $rf = (string)($rsv['reserved_from'] ?? ''); $rt = (string)($rsv['reserved_to'] ?? '');
          $tsStart = $rf!=='' ? strtotime($rf) : null; $tsEnd = $rt!=='' ? strtotime($rt) : null; if (!$tsStart || !$tsEnd) continue;
          $mid = (int)($rsv['reserved_model_id'] ?? 0); if ($mid <= 0) continue;
          $buf = 5*60;
          // Check if current unit will be available in time
          $mustReassign = false; $endTs = null; $hasKnownEnd = false;
          try {
            $ub = $ubCol->findOne(['model_id'=>$mid,'status'=>'Borrowed'], ['projection'=>['id'=>1]]);
            if ($ub) {
              $al = $allocCol->findOne(['borrow_id'=>(int)($ub['id']??0)], ['projection'=>['request_id'=>1]]);
              if ($al && isset($al['request_id'])) {
                $orig = $erCol->findOne(['id'=>(int)$al['request_id']], ['projection'=>['expected_return_at'=>1,'reserved_to'=>1]]);
                if ($orig) {
                  $endStr = (string)($orig['expected_return_at'] ?? ($orig['reserved_to'] ?? ''));
                  if ($endStr !== '') { $endTs = strtotime($endStr); $hasKnownEnd = (bool)$endTs; }
                }
              }
              if (!$hasKnownEnd) { $mustReassign = true; }
              elseif ($endTs && $endTs <= time()) { $mustReassign = true; }
              elseif (!($endTs <= ($tsStart - $buf))) { $mustReassign = true; }
            }
          } catch (Throwable $_) { /* ignore */ }
          if (!$mustReassign) { continue; }
          // Try to find an alternative unit: prefer Available, else In Use that returns before start with buffer; avoid overlapping reservations
          $found = null; $foundSerial = ''; $foundLoc = '';
          try {
            // Helper to test conflicts on a candidate unit id
            $conflicts = function($candId) use ($erCol,$rid,$tsStart,$tsEnd,$buf){
              try {
                $curR = $erCol->find(['type'=>'reservation','status'=>'Approved','reserved_model_id'=>$candId,'id'=>['$ne'=>$rid]], ['projection'=>['reserved_from'=>1,'reserved_to'=>1]]);
                foreach ($curR as $row) {
                  $ofs = isset($row['reserved_from']) ? strtotime((string)$row['reserved_from']) : null;
                  $ote = isset($row['reserved_to']) ? strtotime((string)$row['reserved_to']) : null;
                  if (!$ofs || !$ote) continue;
                  $noOverlapWithBuffer = ($ote <= ($tsStart - $buf)) || ($tsEnd <= ($ofs - $buf));
                  if (!$noOverlapWithBuffer) return true;
                }
              } catch (Throwable $_c) { }
              return false;
            };
            // Available candidates first
            $candCur = $iiCol->find(['status'=>'Available', '$or'=>[['model'=>$itemName],['item_name'=>$itemName]], 'id'=>['$ne'=>$mid]], ['projection'=>['id'=>1,'serial_no'=>1,'location'=>1]]);
            foreach ($candCur as $u) {
              $cid = (int)($u['id'] ?? 0); if ($cid<=0) continue; if ($conflicts($cid)) continue;
              $found = $cid; $foundSerial = (string)($u['serial_no'] ?? ''); $foundLoc = (string)($u['location'] ?? ''); break;
            }
            if (!$found) {
              // Consider units currently In Use that will be free in time
              $cand2 = $iiCol->find(['status'=>['$in'=>['In Use','Reserved']], '$or'=>[['model'=>$itemName],['item_name'=>$itemName]], 'id'=>['$ne'=>$mid]], ['projection'=>['id'=>1,'serial_no'=>1,'location'=>1]]);
              foreach ($cand2 as $u2) {
                $cid = (int)($u2['id'] ?? 0); if ($cid<=0) continue; if ($conflicts($cid)) continue;
                $ub2 = $ubCol->findOne(['model_id'=>$cid,'status'=>'Borrowed'], ['projection'=>['id'=>1]]);
                if (!$ub2) { $found = $cid; $foundSerial = (string)($u2['serial_no'] ?? ''); $foundLoc = (string)($u2['location'] ?? ''); break; }
                $al2 = $allocCol->findOne(['borrow_id'=>(int)($ub2['id']??0)], ['projection'=>['request_id'=>1]]);
                $ok=false; if ($al2 && isset($al2['request_id'])) {
                  $orig2 = $erCol->findOne(['id'=>(int)$al2['request_id']], ['projection'=>['expected_return_at'=>1,'reserved_to'=>1]]);
                  if ($orig2) { $end2Str = (string)($orig2['expected_return_at'] ?? ($orig2['reserved_to'] ?? '')); $t2 = $end2Str!=='' ? strtotime($end2Str) : null; if ($t2 && $t2 > time() && ($t2 <= ($tsStart - $buf))) { $ok=true; } }
                }
                if ($ok) { $found = $cid; $foundSerial = (string)($u2['serial_no'] ?? ''); $foundLoc = (string)($u2['location'] ?? ''); break; }
              }
            }
          } catch (Throwable $_f) { $found = null; }
          if ($found) {
            // Persist reassignment
            $erCol->updateOne(['id'=>$rid], ['$set'=>[
              'reserved_model_id'=>$found,
              'reserved_serial_no'=>$foundSerial,
              'edited_at'=>$nowStr,
              'edit_note'=>'Auto-assigned to ' . $foundSerial . ' @ ' . $foundLoc
            ]]);
          } else {
            // Auto-cancel
            $erCol->updateOne(['id'=>$rid,'status'=>'Approved'], ['$set'=>[
              'status'=>'Cancelled', 'cancelled_at'=>$nowStr, 'cancelled_by'=>'system', 'cancelled_reason'=>'Auto-cancelled: reserved unit overdue/unavailable'
            ]]);
          }
        }
      } catch (Throwable $_pre) { /* ignore preflight */ }
      try {
        // Fetch all approved reservations and check due using strtotime to avoid string comparison issues
        $cur = $erCol->find(['type'=>'reservation','status'=>'Approved']);
        foreach ($cur as $er) {
          $rf = (string)($er['reserved_from'] ?? '');
          $ts = $rf !== '' ? strtotime($rf) : null;
          if (!$ts || $ts > time()) { continue; }
          $reqId = (int)($er['id'] ?? 0); if ($reqId<=0) continue;
          $itemName = trim((string)($er['item_name'] ?? '')); if ($itemName==='') continue;
          $reservedMid = (int)($er['reserved_model_id'] ?? 0);
          $reqLoc = trim((string)($er['request_location'] ?? ''));
          // Prefer the specifically assigned unit if provided
          if ($reservedMid > 0) {
            $set = ['status'=>'In Use']; if ($reqLoc !== '') { $set['location'] = $reqLoc; }
            $unit = $iiCol->findOneAndUpdate(['id'=>$reservedMid, 'status'=>'Available'], ['$set'=>$set], ['returnDocument'=>MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]);
          } else {
            // Find any available unit for this model
            $set = ['status'=>'In Use']; if ($reqLoc !== '') { $set['location'] = $reqLoc; }
            $unit = $iiCol->findOneAndUpdate([
              'status'=>'Available', '$or'=>[['model'=>$itemName],['item_name'=>$itemName]]
            ], ['$set'=>$set], ['returnDocument'=>MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]);
          }
          if (!$unit) { continue; }
          $mid = (int)($unit['id'] ?? 0);
          
          $lastUb = $ubCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
          $nextUb = ($lastUb && isset($lastUb['id']) ? (int)$lastUb['id'] : 0) + 1;
          $borrowedAtFromReq = $nowStr;
          try {
            if (isset($er['created_at']) && $er['created_at'] instanceof MongoDB\BSON\UTCDateTime) {
              $dtBA = $er['created_at']->toDateTime();
              $dtBA->setTimezone(new DateTimeZone('Asia/Manila'));
              $borrowedAtFromReq = $dtBA->format('Y-m-d H:i:s');
            } else { $borrowedAtFromReq = (string)($er['created_at'] ?? $nowStr); }
          } catch (Throwable $_ba) { $borrowedAtFromReq = (string)($er['created_at'] ?? $nowStr); }

          $snapItemName = (string)($unit['item_name'] ?? '');
          $snapModel = (string)($unit['model'] ?? '');
          $snapCategory = trim((string)($unit['category'] ?? '')) !== '' ? (string)$unit['category'] : 'Uncategorized';
          $snapSerial = (string)($unit['serial_no'] ?? '');

          $ubCol->insertOne([
            'id'=>$nextUb,
            'username'=>(string)($er['username'] ?? ''),
            'model_id'=>$mid,
            'serial_no'=>$snapSerial,
            'status'=>'Borrowed',
            'borrowed_at'=>$borrowedAtFromReq,
            // Snapshot fields for stable history
            'request_id'=>$reqId,
            'item_name'=>$snapItemName,
            'model'=>$snapModel,
            'category'=>$snapCategory,
          ]);
          // Allocation link
          $lastAl = $allocCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
          $nextAl = ($lastAl && isset($lastAl['id']) ? (int)$lastAl['id'] : 0) + 1;
          $allocCol->insertOne(['id'=>$nextAl,'request_id'=>$reqId,'borrow_id'=>$nextUb,'allocated_at'=>$nowStr]);
          $erCol->updateOne(['id'=>$reqId], ['$set'=>['status'=>'Borrowed','borrowed_at'=>$borrowedAtFromReq]]);
        }
      } catch (Throwable $_) { /* swallow */ }
      // Now list remaining active reservations (Approved)
      $rows = [];
      $uCol = $db->selectCollection('users');
      $unitSumCache = [];
      try {
        // List upcoming/active approved reservations with a limit to keep UI fast
        $rq = $erCol->find(['type'=>'reservation','status'=>'Approved'], ['sort'=>['reserved_from'=>1,'id'=>1], 'limit' => $reqLimit, 'projection'=>['id'=>1,'username'=>1,'item_name'=>1,'reserved_from'=>1,'reserved_to'=>1,'request_location'=>1,'qr_serial_no'=>1,'reserved_model_id'=>1,'reserved_serial_no'=>1]]);
        foreach ($rq as $doc) {
          $uname = (string)($doc['username'] ?? '');
          $sid = '';
          $ufull = $uname;
          if ($uname !== '') { try { $u = $uCol->findOne(['username'=>$uname], ['projection'=>['school_id'=>1,'full_name'=>1,'first_name'=>1,'last_name'=>1,'name'=>1]]); if ($u) { if (isset($u['school_id'])) { $sid = (string)$u['school_id']; } if (isset($u['full_name']) && trim((string)$u['full_name'])!=='') { $ufull = (string)$u['full_name']; } elseif (isset($u['first_name']) || isset($u['last_name'])) { $ufull = trim((string)($u['first_name']??'').' '.(string)($u['last_name']??'')); } elseif (isset($u['name']) && trim((string)$u['name'])!=='') { $ufull = (string)$u['name']; } } } catch (Throwable $_) { $sid = $sid; $ufull = $ufull; } }
          // Resolve category and location for display from inventory_items
          $itemName = (string)($doc['item_name'] ?? '');
          $iiDoc = null;
          if ($itemName !== '') {
            try {
              $iiDoc = $iiCol->findOne(['$or' => [['model'=>$itemName], ['item_name'=>$itemName]]], ['projection'=>['category'=>1,'location'=>1]]);
            } catch (Throwable $_) { $iiDoc = null; }
          }
          $totalUnits = 0;
          if ($itemName !== '') {
            if (array_key_exists($itemName, $unitSumCache)) { $totalUnits = (int)$unitSumCache[$itemName]; }
            else {
              try {
                $rx = '^' . preg_quote($itemName, '/') . '$';
                $agg = $iiCol->aggregate([
                  ['$match'=>['$or'=>[
                    ['model' => ['$regex' => $rx, '$options' => 'i']],
                    ['item_name' => ['$regex' => $rx, '$options' => 'i']]
                  ]]],
                  ['$project'=>['q'=>['$ifNull'=>['$quantity',1]]]],
                  ['$group'=>['_id'=>null,'sum'=>['$sum'=>'$q']]]
                ])->toArray();
                $totalUnits = (int)($agg[0]['sum'] ?? 0);
              } catch (Throwable $_) { $totalUnits = 0; }
              $unitSumCache[$itemName] = $totalUnits;
            }
          }
          $cat = $iiDoc ? ((string)($iiDoc['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized';
          // Location should reflect user's requested location on reservation
          $loc = (string)($doc['request_location'] ?? '');
          $rows[] = [
            'id' => (int)($doc['id'] ?? 0),
            'username' => $uname,
            'user_full_name' => $ufull,
            'school_id' => $sid,
            'item_name' => (string)($doc['item_name'] ?? ''),
            'reserved_from' => (string)($doc['reserved_from'] ?? ''),
            'reserved_to' => (string)($doc['reserved_to'] ?? ''),
            'category' => $cat,
            'location' => $loc,
            'type' => ((isset($doc['qr_serial_no']) && trim((string)$doc['qr_serial_no'])!=='') ? 'QR' : 'Manual'),
            'reserved_model_id' => (int)($doc['reserved_model_id'] ?? 0),
            'reserved_serial_no' => (string)($doc['reserved_serial_no'] ?? ''),
            'multi' => ($totalUnits > 1),
          ];
        }
      } catch (Throwable $_) { $rows = []; }
      echo json_encode(['reservations'=>$rows]);
      exit();
    } else if ($act === 'list_reservation_serials') {
      header('Content-Type: application/json');
      $id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
      if ($id <= 0) { echo json_encode(['ok'=>false,'items'=>[],'reason'=>'Missing request']); exit(); }
      $erCol = $db->selectCollection('equipment_requests');
      $iiCol = $db->selectCollection('inventory_items');
      $ubCol = $db->selectCollection('user_borrows');
      $allocCol = $db->selectCollection('request_allocations');
      try { $reqDoc = $erCol->findOne(['id'=>$id]); } catch (Throwable $_) { $reqDoc = null; }
      if (!$reqDoc) { echo json_encode(['ok'=>false,'items'=>[],'reason'=>'Request not found']); exit(); }
      $itemName = trim((string)($reqDoc['item_name'] ?? ''));
      $rf = (string)($reqDoc['reserved_from'] ?? '');
      $rt = (string)($reqDoc['reserved_to'] ?? '');
      $tsStart = $rf!=='' ? strtotime($rf) : null;
      $tsEnd   = $rt!=='' ? strtotime($rt) : null;
      $buf = 5*60;
      $items = [];
      try {
        $cur = $iiCol->find(['$or'=>[['model'=>$itemName], ['item_name'=>$itemName]]], ['projection'=>['id'=>1,'serial_no'=>1,'model'=>1,'item_name'=>1,'status'=>1,'location'=>1]]);
        foreach ($cur as $unit) {
          $mid = (int)($unit['id'] ?? 0);
          if ($mid <= 0) { continue; }
          $serial = (string)($unit['serial_no'] ?? '');
          $model  = (string)($unit['model'] ?? ($unit['item_name'] ?? ''));
          $st     = (string)($unit['status'] ?? '');
          $status = $st !== '' ? $st : 'Available';
          $loc    = (string)($unit['location'] ?? '');
          $inUseEnd = '';
          $inUseStart = '';
          $resFrom = '';
          $resTo = '';
          $fits = true;
          // current borrow check
          $ub = null; try { $ub = $ubCol->findOne(['model_id'=>$mid,'status'=>'Borrowed'], ['projection'=>['id'=>1,'borrowed_at'=>1]]); } catch (Throwable $_) { $ub = null; }
          if ($ub) {
            $endTs = null; $al = null; $hasKnownEnd = false;
            if (isset($ub['borrowed_at'])) { $inUseStart = (string)$ub['borrowed_at']; }
            try { $al = $allocCol->findOne(['borrow_id'=>(int)($ub['id']??0)], ['projection'=>['request_id'=>1]]); } catch (Throwable $_) { $al = null; }
            if ($al && isset($al['request_id'])) {
              try {
                $orig = $erCol->findOne(['id'=>(int)$al['request_id']], ['projection'=>['expected_return_at'=>1,'reserved_to'=>1]]);
                if ($orig) {
                  $endStr = (string)($orig['expected_return_at'] ?? ($orig['reserved_to'] ?? ''));
                  if ($endStr !== '') {
                    $inUseEnd = $endStr;
                    $t = strtotime($endStr);
                    $hasKnownEnd = (bool)$t;
                    // Exclude overdue items
                    if ($t && $t <= time()) { $fits = false; }
                    // Enforce 5-min buffer relative to reservation start
                    if ($t && $tsStart && !($t <= ($tsStart - $buf))) { $fits = false; }
                  }
                }
              } catch (Throwable $_) { }
            }
            // If currently in use but expected return is unknown, do not list as eligible
            if (!$hasKnownEnd) { $fits = false; }
            $status = 'In Use';
          }
          // reservation conflicts on this unit (exclude current request id)
          $conflict = false; $confFrom = ''; $confTo = '';
          try {
            $curR = $erCol->find(['type'=>'reservation','status'=>'Approved','reserved_model_id'=>$mid,'id'=>['$ne'=>$id]], ['projection'=>['reserved_from'=>1,'reserved_to'=>1]]);
            foreach ($curR as $row) {
              $ofs = isset($row['reserved_from']) ? strtotime((string)$row['reserved_from']) : null;
              $ote = isset($row['reserved_to']) ? strtotime((string)$row['reserved_to']) : null;
              if (!$ofs || !$ote) { continue; }
              if ($tsStart && $tsEnd) {
                $noOverlapWithBuffer = ($ote <= ($tsStart - $buf)) || ($tsEnd <= ($ofs - $buf));
                if (!$noOverlapWithBuffer) { $conflict = true; $confFrom = (string)$row['reserved_from']; $confTo = (string)$row['reserved_to']; break; }
              }
            }
          } catch (Throwable $_) { }
          if ($conflict) { if ($status !== 'In Use') { $status = 'Reserved'; } $resFrom = $confFrom; $resTo = $confTo; $fits = false; }
          // Only allow inventory statuses eligible for editing (include Reserved if non-conflicting)
          if (!in_array($status, ['Available','In Use','Reserved'], true)) { $fits = false; }
          // Only include units that fit the reservation window (eligible for editing)
          if (!$fits) { continue; }
          $items[] = [
            'model_id'=>$mid,
            'serial_no'=>$serial,
            'model_name'=>$model,
            'status'=>$status,
            'location'=>$loc,
            'reserved_from'=>$resFrom,
            'reserved_to'=>$resTo,
            'in_use_start'=>$inUseStart,
            'in_use_end'=>$inUseEnd,
            'fits'=>$fits
          ];
        }
      } catch (Throwable $_) { $items = []; }
      echo json_encode(['ok'=>true,'items'=>$items]);
      exit();
    } else if ($act === 'validate_reservation_serial') {
      // Validate a serial for a given Approved reservation id
      $id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
      $serial = isset($_GET['serial_no']) ? trim((string)$_GET['serial_no']) : '';
      if ($id <= 0 || $serial === '') { echo json_encode(['ok'=>false,'reason'=>'Missing request or serial']); exit(); }
      $erCol = $db->selectCollection('equipment_requests');
      $iiCol = $db->selectCollection('inventory_items');
      $ubCol = $db->selectCollection('user_borrows');
      $allocCol = $db->selectCollection('request_allocations');
      try { $reqDoc = $erCol->findOne(['id'=>$id]); } catch (Throwable $e) { $reqDoc = null; }
      if (!$reqDoc) { echo json_encode(['ok'=>false,'reason'=>'Request not found']); exit(); }
      if ((string)($reqDoc['status'] ?? '') !== 'Approved' || (string)($reqDoc['type'] ?? '') !== 'reservation') { echo json_encode(['ok'=>false,'reason'=>'Only approved reservations are editable']); exit(); }
      $rf = (string)($reqDoc['reserved_from'] ?? '');
      $rt = (string)($reqDoc['reserved_to'] ?? '');
      $tsStart = $rf !== '' ? strtotime($rf) : null;
      $tsEnd   = $rt !== '' ? strtotime($rt) : null;
      if (!$tsStart || !$tsEnd || $tsEnd <= $tsStart) { echo json_encode(['ok'=>false,'reason'=>'Invalid reservation time']); exit(); }
      $itemName = trim((string)($reqDoc['item_name'] ?? ''));
      // Resolve desired model/category locally
      $dm = $itemName; $dc = 'Uncategorized';
      try {
        $iiDoc = $iiCol->findOne(['$or' => [['model'=>$itemName], ['item_name'=>$itemName]]], ['projection'=>['category'=>1]]);
        if ($iiDoc) { $dc = trim((string)($iiDoc['category'] ?? '')) !== '' ? (string)$iiDoc['category'] : 'Uncategorized'; }
      } catch (Throwable $_) { $dc = 'Uncategorized'; }
      // Multi-unit enforcement: compute total units
      $totalUnits = 0;
      if ($itemName !== '') {
        try {
          $rx = '^' . preg_quote($itemName, '/') . '$';
          $agg = $iiCol->aggregate([
            ['$match'=>['$or'=>[
              ['model' => ['$regex' => $rx, '$options' => 'i']],
              ['item_name' => ['$regex' => $rx, '$options' => 'i']]
            ]]],
            ['$project'=>['q'=>['$ifNull'=>['$quantity',1]]]],
            ['$group'=>['_id'=>null,'sum'=>['$sum'=>'$q']]]
          ])->toArray();
          $totalUnits = (int)($agg[0]['sum'] ?? 0);
        } catch (Throwable $_) { $totalUnits = 0; }
      }
      if ($totalUnits <= 1) { echo json_encode(['ok'=>false,'reason'=>'Single-unit model cannot edit serial']); exit(); }
      // Find unit by serial and verify it belongs to the same model/category
      $unit = $iiCol->findOne(['serial_no'=>$serial], ['projection'=>['id'=>1,'status'=>1,'model'=>1,'item_name'=>1,'category'=>1]]);
      if (!$unit) { echo json_encode(['ok'=>false,'reason'=>'Serial ID not found']); exit(); }
      $um = (string)($unit['model'] ?? ($unit['item_name'] ?? ''));
      $uc = trim((string)($unit['category'] ?? '')) !== '' ? (string)$unit['category'] : 'Uncategorized';
      $match = (strcasecmp(trim($um), trim($dm))===0) && (strcasecmp(trim($uc), trim($dc))===0);
      if (!$match) { echo json_encode(['ok'=>false,'reason'=>'Serial belongs to a different model']); exit(); }
      $assignedMid = (int)($unit['id'] ?? 0);
      $buf = 5*60; $conflict = false; $reason = '';
      // Conflict with other approved reservations on the same unit
      try {
        $curR = $erCol->find(['type'=>'reservation','status'=>'Approved','reserved_model_id'=>$assignedMid,'id'=>['$ne'=>$id]], ['projection'=>['reserved_from'=>1,'reserved_to'=>1,'id'=>1]]);
        foreach ($curR as $row) {
          $ofs = isset($row['reserved_from']) ? strtotime((string)$row['reserved_from']) : null;
          $ote = isset($row['reserved_to']) ? strtotime((string)$row['reserved_to']) : null;
          if (!$ofs || !$ote) { continue; }
          $noOverlapWithBuffer = ($ote <= ($tsStart - $buf)) || ($tsEnd <= ($ofs - $buf));
          if (!$noOverlapWithBuffer) { $conflict = true; $reason = 'Conflicts with another approved reservation'; break; }
        }
      } catch (Throwable $_) { }
      if ($conflict) { echo json_encode(['ok'=>false,'reason'=>$reason]); exit(); }
      // If currently borrowed, ensure expected return does not overlap
      try { $ub = $ubCol->findOne(['model_id'=>$assignedMid,'status'=>'Borrowed'], ['projection'=>['id'=>1,'borrowed_at'=>1]]); } catch (Throwable $_) { $ub = null; }
      if ($ub) {
        $al = $allocCol->findOne(['borrow_id'=>(int)($ub['id']??0)], ['projection'=>['request_id'=>1]]);
        $endTs = null;
        if ($al && isset($al['request_id'])) {
          $orig = $erCol->findOne(['id'=>(int)$al['request_id']], ['projection'=>['expected_return_at'=>1,'reserved_to'=>1]]);
          if ($orig) {
            $endStr = (string)($orig['expected_return_at'] ?? ($orig['reserved_to'] ?? ''));
            if ($endStr !== '') { $endTs = strtotime($endStr); }
          }
        }
        if (!$endTs) { echo json_encode(['ok'=>false,'reason'=>'Selected unit is in use; expected return unknown']); exit(); }
        if ($endTs <= time()) { echo json_encode(['ok'=>false,'reason'=>'Selected unit is overdue']); exit(); }
        if (!($endTs <= ($tsStart - $buf))) { echo json_encode(['ok'=>false,'reason'=>'Selected unit is in use and returns too late']); exit(); }
      }
      // Status check (allow Available, Reserved, or In Use with safe return)
      $st = (string)($unit['status'] ?? '');
      if ($st !== '' && !in_array($st, ['Available','In Use','Reserved'], true)) { echo json_encode(['ok'=>false,'reason'=>'Unit not available (status: '.$st.')']); exit(); }
      echo json_encode(['ok'=>true]);
      exit();
    }
  } catch (Throwable $e) {
    echo json_encode(['error' => 'mongo_unavailable']);
  }
}
// JSON endpoints handled above via Mongo

// Admin notification clear: mark a single processed request as cleared for this admin
if ($act === 'admin_notif_clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $clears = $db->selectCollection('admin_notif_clears');
    $admin = (string)($_SESSION['username'] ?? '');
    $rid = (int)($_POST['request_id'] ?? 0);
    if ($admin !== '' && $rid > 0) {
      $clears->updateOne(
        ['admin' => $admin, 'request_id' => $rid],
        ['$set' => ['admin' => $admin, 'request_id' => $rid, 'cleared_at' => date('Y-m-d H:i:s')]],
        ['upsert' => true]
      );
    }
    echo json_encode(['ok' => true]);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false]);
  }
  exit();
}

// Admin notification clear all: mark current processed notifications as cleared for this admin
if ($act === 'admin_notif_clear_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $er = $db->selectCollection('equipment_requests');
    $rf = $db->selectCollection('return_events');
    $clears = $db->selectCollection('admin_notif_clears');
    $admin = (string)($_SESSION['username'] ?? '');
    if ($admin !== '') {
      $limit = isset($_POST['limit']) ? max(1, min((int)$_POST['limit'], 1000)) : 300;
      $ids = [];
      try {
        $cur = $er->find(['status' => ['$in' => ['Approved','Rejected']]], ['sort' => ['updated_at' => -1, 'approved_at' => -1, 'rejected_at' => -1, 'id' => -1], 'limit' => $limit]);
        foreach ($cur as $row) {
          $rid = (int)($row['id'] ?? 0); if ($rid > 0) { $ids[$rid] = true; }
        }
      } catch (Throwable $_a) {}
      try {
        $curR = $rf->find([], ['sort' => ['id' => -1], 'limit' => $limit]);
        foreach ($curR as $e) {
          $rid2 = (int)($e['request_id'] ?? 0); if ($rid2 > 0) { $ids[$rid2] = true; }
        }
      } catch (Throwable $_r) {}
      if (!empty($ids)) {
        $now = date('Y-m-d H:i:s');
        foreach (array_keys($ids) as $rid) {
          $clears->updateOne(
            ['admin' => $admin, 'request_id' => (int)$rid],
            ['$set' => ['admin' => $admin, 'request_id' => (int)$rid, 'cleared_at' => $now]],
            ['upsert' => true]
          );
        }
      }
    }
    echo json_encode(['ok' => true]);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false]);
  }
  exit();
}

// Admin notifications: combined pending + processed (Approved/Rejected) with per-admin clears
if ($act === 'admin_notifications' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: application/json');
  try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $er = $db->selectCollection('equipment_requests');
    $uCol = $db->selectCollection('users');
    $clears = $db->selectCollection('admin_notif_clears');
    $admin = (string)($_SESSION['username'] ?? '');
    $limit = isset($_GET['limit']) ? max(50, min((int)$_GET['limit'], 500)) : 200;

    // Pending
    $pending = [];
    try {
      $rq = $er->find(['status' => 'Pending'], ['sort' => ['created_at' => 1, 'id' => 1], 'limit' => $limit]);
      foreach ($rq as $doc) {
        $uname = (string)($doc['username'] ?? '');
        $sid = '';
        $ufull = $uname;
        if ($uname !== '') {
          try {
            $ud = $uCol->findOne(['username'=>$uname], ['projection'=>['school_id'=>1,'full_name'=>1,'first_name'=>1,'last_name'=>1,'name'=>1]]);
            if ($ud) {
              if (isset($ud['school_id'])) { $sid = (string)$ud['school_id']; }
              if (isset($ud['full_name']) && trim((string)$ud['full_name'])!=='') { $ufull = (string)$ud['full_name']; }
              elseif (isset($ud['first_name']) || isset($ud['last_name'])) { $ufull = trim((string)($ud['first_name']??'').' '.(string)($ud['last_name']??'')); }
              elseif (isset($ud['name']) && trim((string)$ud['name'])!=='') { $ufull = (string)$ud['name']; }
            }
          } catch (Throwable $_) { $sid=''; $ufull=$uname; }
        }
        $pending[] = [
          'id' => (int)($doc['id'] ?? 0),
          'username' => $uname,
          'user_full_name' => $ufull,
          'school_id' => $sid,
          'item_name' => (string)($doc['item_name'] ?? ''),
          'quantity' => (int)($doc['quantity'] ?? 1),
          'status' => (string)($doc['status'] ?? ''),
          'created_at' => (string)($doc['created_at'] ?? ''),
          'type' => (string)($doc['type'] ?? ''),
        ];
      }
    } catch (Throwable $_p) { $pending = []; }

    // Processed (Approved/Rejected), excluding per-admin cleared + include recent returns
    $recent = [];
    try {
      $idsCleared = [];
      if ($admin !== '') {
        foreach ($clears->find(['admin'=>$admin], ['projection'=>['request_id'=>1]]) as $c) {
          $idsCleared[(int)($c['request_id'] ?? 0)] = true;
        }
      }
      $cur = $er->find(['status' => ['$in' => ['Approved','Rejected']]], ['sort'=>['updated_at'=>-1,'approved_at'=>-1,'rejected_at'=>-1,'id'=>-1], 'limit' => $limit]);
      foreach ($cur as $row) {
        $rid = (int)($row['id'] ?? 0); if ($rid <= 0) continue;
        if ($admin !== '' && isset($idsCleared[$rid])) continue;
        $st = (string)($row['status'] ?? '');
        $pby = $st==='Approved' ? (string)($row['approved_by'] ?? '') : (string)($row['rejected_by'] ?? '');
        $pat = $st==='Approved' ? (string)($row['approved_at'] ?? '') : (string)($row['rejected_at'] ?? '');
        $uname = (string)($row['username'] ?? '');
        $ufull = $uname;
        try { $ud = $uCol->findOne(['username'=>$uname], ['projection'=>['full_name'=>1]]); if ($ud && isset($ud['full_name']) && trim((string)$ud['full_name'])!=='') { $ufull = (string)$ud['full_name']; } } catch (Throwable $_u) { $ufull=$uname; }
        $recent[] = [
          'id' => $rid,
          'username' => $uname,
          'user_full_name' => $ufull,
          'item_name' => (string)($row['item_name'] ?? ''),
          'quantity' => (int)($row['quantity'] ?? 1),
          'status' => $st,
          'processed_by' => $pby,
          'processed_at' => $pat,
        ];
      }
      // Also include recent user QR returns as 'Returned'
      try {
        $rf = $db->selectCollection('return_events');
        $curR = $rf->find([], ['sort'=>['id'=>-1], 'limit' => 50]);
        foreach ($curR as $e) {
          $rid2 = (int)($e['request_id'] ?? 0);
          if ($rid2 > 0 && $admin !== '' && isset($idsCleared[$rid2])) { continue; }
          $uname = (string)($e['username'] ?? '');
          $ufull = $uname;
          try { $ud = $uCol->findOne(['username'=>$uname], ['projection'=>['full_name'=>1]]); if ($ud && isset($ud['full_name']) && trim((string)$ud['full_name'])!=='') { $ufull = (string)$ud['full_name']; } } catch (Throwable $_u2) { $ufull=$uname; }
          $recent[] = [
            'id' => $rid2,
            'username' => $uname,
            'user_full_name' => $ufull,
            'item_name' => (string)($e['model_name'] ?? ''),
            'quantity' => 1,
            'status' => 'Returned',
            'processed_by' => '',
            'processed_at' => (string)($e['created_at'] ?? ''),
          ];
        }
      } catch (Throwable $_r2) { /* ignore */ }
    } catch (Throwable $_r) { $recent = []; }
    echo json_encode(['ok'=>true, 'pending'=>$pending, 'recent'=>$recent]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'pending'=>[], 'recent'=>[]]);
  }
  exit();
}

// Handle borrowable list POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $do = $_POST['do'] ?? '';
  if ($do === 'add_borrowable') {
    $cat = trim($_POST['category'] ?? '');
    $models = [];
    $bulkLimit = isset($_POST['bulk_limit']) ? (int)$_POST['bulk_limit'] : null;
    $selectedSerials = (isset($_POST['serials']) && is_array($_POST['serials'])) ? $_POST['serials'] : [];
    // Accept either single model or multiple models[] from merged UI
    if (!empty($_POST['models']) && is_array($_POST['models'])) {
      $models = array_filter(array_map('trim', $_POST['models']));
    } else if (isset($_POST['model'])) {
      $m = trim($_POST['model']);
      if ($m !== '') { $models = [$m]; }
    }
    // Mongo-first implementation when ABC_MONGO_FILLED is true
      try {
        @require_once __DIR__ . '/../vendor/autoload.php';
        @require_once __DIR__ . '/db/mongo.php';
        $db = get_mongo_db();
        $itemsCol = $db->selectCollection('inventory_items');
        $bcCol = $db->selectCollection('borrowable_catalog');
        $holdCol = $db->selectCollection('returned_hold');
        $buCol = $db->selectCollection('borrowable_units');

        if ($cat !== '' && $bulkLimit !== null && empty($models)) {
          if ($bulkLimit < 0) { $bulkLimit = 0; }
          // Aggregate distinct model keys with total quantity per model within category
          $agg = $itemsCol->aggregate([
            ['$match' => ['category' => $cat]],
            ['$project' => [
              'model_key' => ['$ifNull' => ['$model', '$item_name']],
              'quantity'  => ['$ifNull' => ['$quantity', 1]]
            ]],
            ['$group' => ['_id' => '$model_key', 'total_qty' => ['$sum' => '$quantity']]],
          ]);
          foreach ($agg as $row) {
            $modelName = (string)($row->_id ?? ''); if ($modelName === '') continue;
            $total = (int)($row->total_qty ?? 0);
            // Increment existing limit by bulk amount, cap at total
            $existing = $bcCol->findOne(['model_name'=>$modelName,'category'=>$cat], ['projection'=>['borrow_limit'=>1]]);
            $curLimit = $existing && isset($existing['borrow_limit']) ? (int)$existing['borrow_limit'] : 0;
            $inc = max(0, (int)$bulkLimit);
            $newLimit = $curLimit + $inc;
            if ($newLimit > $total) { $newLimit = $total; }
            if ($newLimit < 0) { $newLimit = 0; }
            $existingDoc = $bcCol->findOne(['model_name'=>$modelName,'category'=>$cat], ['projection'=>['active'=>1]]);
            $keepActive = $existingDoc && isset($existingDoc['active']) ? (int)$existingDoc['active'] : 1;
            $bcCol->updateOne(
              ['model_name' => $modelName, 'category' => $cat],
              ['$set' => ['model_name'=>$modelName,'category'=>$cat,'active'=>$keepActive,'borrow_limit'=>$newLimit,'created_at'=>date('Y-m-d H:i:s')]],
              ['upsert' => true]
            );
            // Release up to the increment amount from returned_hold into Available
            $toRelease = max(0, $newLimit - $curLimit);
            if ($toRelease > 0) {
              try {
                $released = 0;
                // Honor admin-selected serials first, if any were posted for this model
                $selList = isset($selectedSerials[$modelName]) && is_array($selectedSerials[$modelName]) ? $selectedSerials[$modelName] : [];
                foreach ($selList as $sid) {
                  if ($released >= $toRelease) break;
                  $midRel = (int)$sid; if ($midRel <= 0) continue;
                  $itemsCol->updateOne(['id'=>$midRel], ['$set'=>['status'=>'Available']]);
                  $holdCol->deleteOne(['model_id'=>$midRel]);
                  $released++;
                }
                // Fallback: release arbitrary remaining from hold
                if ($released < $toRelease) {
                  $relCur = $holdCol->find(['category'=>$cat,'model_name'=>$modelName], ['limit'=>($toRelease - $released)]);
                  foreach ($relCur as $h) {
                    if ($released >= $toRelease) break;
                    $midRel = (int)($h['model_id'] ?? 0);
                    if ($midRel > 0) {
                      $itemsCol->updateOne(['id'=>$midRel], ['$set'=>['status'=>'Available']]);
                      $holdCol->deleteOne(['model_id'=>$midRel]);
                      $released++;
                    }
                  }
                }
              } catch (Throwable $eRel) { /* ignore release errors */ }
            }
            // Ensure whitelist is topped up to match newLimit (even if inactive)
            try {
              $whCur = (int)$buCol->countDocuments(['model_name'=>$modelName,'category'=>$cat]);
              $needWh = max(0, $newLimit - $whCur);
              if ($needWh > 0) {
                $existing = [];
                foreach ($buCol->find(['model_name'=>$modelName,'category'=>$cat], ['projection'=>['model_id'=>1]]) as $rEx) { $existing[] = (int)($rEx['model_id'] ?? 0); }
                $existing = array_values(array_unique(array_filter($existing)));
                $queryW = [
                  'status' => 'Available',
                  'quantity' => ['$gt' => 0],
                  '$or' => [ ['model'=>$modelName], ['item_name'=>$modelName] ],
                  'category' => $cat,
                  'id' => ['$nin' => $existing]
                ];
                $optsW = ['projection'=>['id'=>1], 'sort'=>['id'=>1], 'limit'=>$needWh*3];
                $added = 0;
                foreach ($itemsCol->find($queryW, $optsW) as $itW) {
                  $midW = (int)($itW['id'] ?? 0); if ($midW<=0) continue; if (in_array($midW, $existing, true)) continue;
                  try { $buCol->insertOne(['model_id'=>$midW,'model_name'=>$modelName,'category'=>$cat,'created_at'=>date('Y-m-d H:i:s')]); $added++; if ($added >= $needWh) break; } catch (Throwable $_i){ }
                }
              }
            } catch (Throwable $_top){ }
          }
        } elseif ($cat !== '' && (!empty($models) || (isset($_POST['limits']) && is_array($_POST['limits'])))) {
          // Optional per-item limits coming from UI
          $limits = isset($_POST['limits']) && is_array($_POST['limits']) ? $_POST['limits'] : [];
          if (empty($models) && !empty($limits)) {
            foreach ($limits as $k => $v) { $vv = (int)$v; if ($vv > 0) { $models[] = $k; } }
          }
          foreach ($models as $modelName) {
            if ($modelName === '') continue;
            $reqLimit = isset($limits[$modelName]) ? (int)$limits[$modelName] : 1;
            if ($reqLimit < 0) { $reqLimit = 0; }
            // Sum total quantity for this (cat, model)
            $agg = $itemsCol->aggregate([
              ['$match' => [
                'category' => $cat,
                '$or' => [ ['model'=>$modelName], ['item_name'=>$modelName] ]
              ]],
              ['$project' => ['quantity' => ['$ifNull' => ['$quantity', 1]]]],
              ['$group' => ['_id' => null, 'total_qty' => ['$sum' => '$quantity']]]
            ]);
            $total = 0; foreach ($agg as $r) { $total = (int)($r->total_qty ?? 0); break; }
            // Treat reqLimit as an increment to current borrow_limit; cap by total
            $existing = $bcCol->findOne(['model_name'=>$modelName,'category'=>$cat], ['projection'=>['borrow_limit'=>1]]);
            $curLimit = $existing && isset($existing['borrow_limit']) ? (int)$existing['borrow_limit'] : 0;
            $inc = max(0, (int)$reqLimit);
            $newLimit = $curLimit + $inc;
            if ($newLimit > $total) { $newLimit = $total; }
            if ($newLimit < 0) { $newLimit = 0; }
            // If specific serials were selected, whitelist those and set borrow_limit from whitelist count
            $selList = isset($selectedSerials[$modelName]) && is_array($selectedSerials[$modelName]) ? $selectedSerials[$modelName] : [];
            if (!empty($selList)) {
              $now = date('Y-m-d H:i:s');
              $added = 0;
              foreach ($selList as $sid) {
                $mid = (int)$sid; if ($mid<=0) continue;
                // upsert whitelist
                $buCol->updateOne(
                  ['model_id'=>$mid],
                  ['$set'=>['model_id'=>$mid,'model_name'=>$modelName,'category'=>$cat,'created_at'=>$now]],
                  ['upsert'=>true]
                );
                // if in hold, release to Available and remove from hold
                $h = $holdCol->findOne(['model_id'=>$mid]);
                if ($h) { $itemsCol->updateOne(['id'=>$mid], ['$set'=>['status'=>'Available']]); $holdCol->deleteOne(['model_id'=>$mid]); }
                $added++;
              }
              // Recompute whitelist count and sync borrow_limit
              $cnt = (int)$buCol->countDocuments(['model_name'=>$modelName,'category'=>$cat]);
              if ($cnt > $total) { $cnt = $total; }
              $existingDoc = $bcCol->findOne(['model_name'=>$modelName,'category'=>$cat], ['projection'=>['active'=>1]]);
              $keepActive = $existingDoc && isset($existingDoc['active']) ? (int)$existingDoc['active'] : 1;
              $bcCol->updateOne(
                ['model_name'=>$modelName,'category'=>$cat],
                ['$set'=>['model_name'=>$modelName,'category'=>$cat,'active'=>$keepActive,'borrow_limit'=>$cnt,'created_at'=>$now]],
                ['upsert'=>true]
              );
            } else {
              // Legacy path: no explicit serials selected, keep increment behavior
              $existingDoc = $bcCol->findOne(['model_name'=>$modelName,'category'=>$cat], ['projection'=>['active'=>1]]);
              $keepActive = $existingDoc && isset($existingDoc['active']) ? (int)$existingDoc['active'] : 1;
              $bcCol->updateOne(
                ['model_name'=>$modelName,'category'=>$cat],
                ['$set'=>['model_name'=>$modelName,'category'=>$cat,'active'=>$keepActive,'borrow_limit'=>$newLimit,'created_at'=>date('Y-m-d H:i:s')]],
                ['upsert'=>true]
              );
              $toRelease = max(0, $newLimit - $curLimit);
              if ($toRelease > 0) {
                try {
                  $released = 0;
                  $relCur = $holdCol->find(['category'=>$cat,'model_name'=>$modelName], ['limit'=>$toRelease]);
                  foreach ($relCur as $h) {
                    if ($released >= $toRelease) break;
                    $midRel = (int)($h['model_id'] ?? 0);
                    if ($midRel > 0) {
                      $itemsCol->updateOne(['id'=>$midRel], ['$set'=>['status'=>'Available']]);
                      $holdCol->deleteOne(['model_id'=>$midRel]);
                      $released++;
                    }
                  }
                } catch (Throwable $eRel) { /* ignore release errors */ }
              }
              // Top up whitelist to newLimit (even if inactive)
              try {
                $whCur = (int)$buCol->countDocuments(['model_name'=>$modelName,'category'=>$cat]);
                $needWh = max(0, $newLimit - $whCur);
                if ($needWh > 0) {
                  $existing = [];
                  foreach ($buCol->find(['model_name'=>$modelName,'category'=>$cat], ['projection'=>['model_id'=>1]]) as $rEx) { $existing[] = (int)($rEx['model_id'] ?? 0); }
                  $existing = array_values(array_unique(array_filter($existing)));
                  $queryW = [
                    'status' => 'Available',
                    'quantity' => ['$gt' => 0],
                    '$or' => [ ['model'=>$modelName], ['item_name'=>$modelName] ],
                    'category' => $cat,
                    'id' => ['$nin' => $existing]
                  ];
                  $optsW = ['projection'=>['id'=>1], 'sort'=>['id'=>1], 'limit'=>$needWh*3];
                  $added = 0;
                  foreach ($itemsCol->find($queryW, $optsW) as $itW) {
                    $midW = (int)($itW['id'] ?? 0); if ($midW<=0) continue; if (in_array($midW, $existing, true)) continue;
                    try { $buCol->insertOne(['model_id'=>$midW,'model_name'=>$modelName,'category'=>$cat,'created_at'=>date('Y-m-d H:i:s')]); $added++; if ($added >= $needWh) break; } catch (Throwable $_i){ }
                  }
                }
              } catch (Throwable $_top){ }
            }
          }
        }
        header('Location: admin_borrow_center.php?bm=added'); exit();
      } catch (Throwable $e) {
        // Fall back to MySQL path below on error
      }
    // Removed MySQL fallback path; handled above with MongoDB
    header('Location: admin_borrow_center.php?bm=added'); exit();
  } elseif ($do === 'toggle_borrowable') {
    // Mongo: toggle active flag
    try {
      @require_once __DIR__ . '/../vendor/autoload.php';
      @require_once __DIR__ . '/db/mongo.php';
      $db = get_mongo_db();
      $bmCol = $db->selectCollection('borrowable_catalog');
      $cat = trim($_POST['category'] ?? '');
      $model = trim($_POST['model'] ?? '');
      $active = (int)($_POST['active'] ?? 0);
      if ($cat !== '' && $model !== '') {
        $bmCol->updateOne(['model_name'=>$model,'category'=>$cat], ['$set'=>['active'=>$active]]);
      }
    } catch (Throwable $e) {}
    header('Location: admin_borrow_center.php?bm=toggled'); exit();
  } elseif ($do === 'delete_borrowable') {
    // Mongo: logically delete borrowable entry by setting borrow_limit=0 (preserve active flag)
    try {
      @require_once __DIR__ . '/../vendor/autoload.php';
      @require_once __DIR__ . '/db/mongo.php';
      $db = get_mongo_db();
      $bmCol = $db->selectCollection('borrowable_catalog');
      $cat = trim($_POST['category'] ?? '');
      $model = trim($_POST['model'] ?? '');
      if ($cat !== '' && $model !== '') {
        $bmCol->updateOne(['model_name'=>$model,'category'=>$cat], ['$set'=>['borrow_limit'=>0]], ['upsert'=>true]);
      }
    } catch (Throwable $e) {}
    header('Location: admin_borrow_center.php?bm=deleted'); exit();
  } elseif (in_array($do, ['returned_add','returned_remove'], true)) {
    // Returned List feature removed: no-op
    header('Location: admin_borrow_center.php?scroll=pending#pending-list'); exit();
  } elseif (in_array($do, ['mark_found','mark_fixed'], true)) {
    // Mark item as Found/Fixed -> set Available immediately and log; no queue
    $midRaw = (int)($_POST['model_id'] ?? 0);
    $note = trim($_POST['notes'] ?? '');
    if ($midRaw > 0) {
      try {
        @require_once __DIR__ . '/../vendor/autoload.php';
        @require_once __DIR__ . '/db/mongo.php';
        $db = get_mongo_db();
        $iiCol = $db->selectCollection('inventory_items');
        $ldCol = $db->selectCollection('lost_damaged_log');
        $by = $_SESSION['username'] ?? 'system';
        $now = date('Y-m-d H:i:s');
        // Resolve actual inventory model id: first assume POSTed id is inventory_items.id
        $mid = $midRaw;
        $doc = $iiCol->findOne(['id'=>$mid]);
        if (!$doc) {
          // Fallback: treat posted id as lost_damaged_log.id and resolve its model_id
          try {
            $logDoc = $ldCol->findOne(['id'=>$midRaw]);
            if ($logDoc && isset($logDoc['model_id'])) {
              $mid = (int)($logDoc['model_id'] ?? 0);
              if ($mid > 0) { $doc = $iiCol->findOne(['id'=>$mid]); }
            }
          } catch (Throwable $_lf) { /* ignore */ }
        }
        if ($mid > 0 && $doc) {
          // Set unit to Available
          $iiCol->updateOne(['id'=>$mid], ['$set'=>['status'=>'Available']]);
          // Resolve prior Lost/Under Maintenance logs so they disappear from lists
          try { $ldCol->updateMany(['model_id'=>$mid, 'action'=>['$in'=>['Lost','Under Maintenance']], 'resolved_at'=>['$exists'=>false]], ['$set'=>['resolved_at'=>$now]]); } catch (Throwable $e2) {}
          // Log Found/Fixed action
          $ldCol->insertOne(['model_id'=>$mid,'username'=>$by,'action'=>($do==='mark_found'?'Found':'Fixed'),'notes'=>$note,'created_at'=>$now]);
        }
      } catch (Throwable $e) {}
    }
    header('Location: admin_borrow_center.php?scroll=lost#lost-damaged'); exit();
  }
  elseif ($do === 'mark_permanent_lost') {
    $mid = (int)($_POST['model_id'] ?? 0);
    $note = trim($_POST['notes'] ?? '');
    if ($mid > 0) {
      try {
        @require_once __DIR__ . '/../vendor/autoload.php';
        @require_once __DIR__ . '/db/mongo.php';
        $db = get_mongo_db();
        $iiCol = $db->selectCollection('inventory_items');
        $ldCol = $db->selectCollection('lost_damaged_log');
        $delLogCol = $db->selectCollection('inventory_delete_log');
        $retCol = $db->selectCollection('retired_serials');
        $by = $_SESSION['username'] ?? 'system';
        $now = date('Y-m-d H:i:s');
        // Capture serial before removal
        $doc = $iiCol->findOne(['id'=>$mid], ['projection'=>['serial_no'=>1,'model'=>1,'item_name'=>1,'category'=>1,'location'=>1,'quantity'=>1,'status'=>1]]);
        $serial = $doc && isset($doc['serial_no']) ? (string)$doc['serial_no'] : '';
        $snapModelKey = $doc ? (string)($doc['model'] ?? ($doc['item_name'] ?? '')) : '';
        $snapCategory = $doc ? (string)($doc['category'] ?? '') : '';
        $snapLocation = $doc ? (string)($doc['location'] ?? '') : '';
        // Mark item as Permanently Lost in inventory status
        $iiCol->updateOne(['id'=>$mid], ['$set'=>['status'=>'Permanently Lost']]);
        // Adjust borrow_limit for this model/category and prune if total is now zero
        try {
          $bmCol = $db->selectCollection('borrowable_catalog');
          $modelKey = $doc ? (string)($doc['model'] ?? ($doc['item_name'] ?? '')) : '';
          $cat = $doc ? ((string)($doc['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized';
          if ($modelKey !== '') {
            // Compute new total excluding Permanently Lost/Disposed
            $agg = $iiCol->aggregate([
              ['$match' => [
                'category' => $cat,
                '$or' => [ ['model'=>$modelKey], ['item_name'=>$modelKey] ]
              ]],
              ['$project' => [ 'q' => ['$ifNull' => ['$quantity', 1]], 'status' => ['$ifNull' => ['$status','']] ]],
              ['$project' => [ 'cnt' => ['$cond' => [[ '$and' => [[ '$ne' => ['$status','Permanently Lost'] ], [ '$ne' => ['$status','Disposed'] ] ] ], '$q', 0 ]] ]],
              ['$group' => ['_id' => null, 'total' => ['$sum' => '$cnt']]]
            ])->toArray();
            $newTotal = (int)($agg[0]['total'] ?? 0);
            $existing = $bmCol->findOne(['model_name'=>$modelKey,'category'=>$cat], ['projection'=>['borrow_limit'=>1]]);
            $curLimit = $existing && isset($existing['borrow_limit']) ? (int)$existing['borrow_limit'] : 0;
            $newLimit = max(0, $curLimit - 1);
            if ($newLimit > $newTotal) { $newLimit = $newTotal; }
            if ($newTotal <= 0) {
              // Remove the entry entirely if there are no remaining units
              try { $bmCol->deleteOne(['model_name'=>$modelKey,'category'=>$cat]); } catch (Throwable $eDelBm) {}
            } else {
              $bmCol->updateOne(
                ['model_name'=>$modelKey,'category'=>$cat],
                ['$set'=>['active'=>1,'borrow_limit'=>$newLimit,'created_at'=>$now]],
                ['upsert'=>true]
              );
            }
          }
        } catch (Throwable $eBm) { /* ignore */ }
        // Retire serial
        try { $retCol->createIndex(['serial_no'=>1], ['unique'=>true]); } catch (Throwable $eIdx) {}
        if ($serial !== '') {
          try { $retCol->updateOne(['serial_no'=>$serial], ['$set'=>['serial_no'=>$serial,'reason'=>'Permanently Lost','model_id'=>$mid,'retired_at'=>$now,'retired_by'=>$by]], ['upsert'=>true]); } catch (Throwable $eRet) {}
        }
        // Deletion history snapshot (reason: Permanently Lost)
        try {
          $lastDel = $delLogCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
          $nextDelId = ($lastDel && isset($lastDel['id']) ? (int)$lastDel['id'] : 0) + 1;
          $delLogCol->insertOne([
            'id' => $nextDelId,
            'item_id' => $mid,
            'serial_no' => $serial,
            'deleted_by' => $by,
            'deleted_at' => $now,
            'reason' => 'Permanently Lost',
            'item_name' => $doc ? (string)($doc['item_name'] ?? '') : '',
            'model' => $doc ? (string)($doc['model'] ?? '') : '',
            'category' => $doc ? (string)($doc['category'] ?? 'Uncategorized') : 'Uncategorized',
            'quantity' => $doc ? (int)($doc['quantity'] ?? 1) : 1,
            'status' => 'Permanently Lost',
          ]);
        } catch (Throwable $eDelLog) { /* ignore */ }
        // Do not delete the inventory record; keep it for audit/history. It will be hidden via status and not used in flows.
        // Resolve any prior Lost/Under Maintenance records so it disappears from active lists
        try { $ldCol->updateMany(['model_id'=>$mid, 'action'=>['$in'=>['Lost','Under Maintenance']], 'resolved_at'=>['$exists'=>false]], ['$set'=>['resolved_at'=>$now]]); } catch (Throwable $e2) {}
        // Log Permanently Lost action with snapshot fields to preserve display after deletion
        $ldCol->insertOne([
          'model_id'=>$mid,
          'username'=>$by,
          'action'=>'Permanently Lost',
          'notes'=>$note,
          'created_at'=>$now,
          'serial_no'=>$serial,
          'model_key'=>$snapModelKey,
          'category'=>$snapCategory,
          'location'=>$snapLocation,
        ]);
      } catch (Throwable $e) {}
    }
    header('Location: admin_borrow_center.php?scroll=lost#lost-damaged'); exit();
  }
  elseif ($do === 'dispose_item') {
    $mid = (int)($_POST['model_id'] ?? 0);
    $confirmText = trim((string)($_POST['confirm_text'] ?? ''));
    if ($confirmText !== 'DISPOSE') { header('Location: admin_borrow_center.php?error=confirm_dispose'); exit(); }
    if ($mid > 0) {
      try {
        @require_once __DIR__ . '/../vendor/autoload.php';
        @require_once __DIR__ . '/db/mongo.php';
        $db = get_mongo_db();
        $iiCol = $db->selectCollection('inventory_items');
        $ldCol = $db->selectCollection('lost_damaged_log');
        $delLogCol = $db->selectCollection('inventory_delete_log');
        $retCol = $db->selectCollection('retired_serials');
        $by = $_SESSION['username'] ?? 'system';
        $now = date('Y-m-d H:i:s');
        $doc = $iiCol->findOne(['id'=>$mid], ['projection'=>['serial_no'=>1,'model'=>1,'item_name'=>1,'category'=>1,'location'=>1,'quantity'=>1]]);
        $serial = $doc && isset($doc['serial_no']) ? (string)$doc['serial_no'] : '';
        // Update status to Disposed (record kept for audit)
        $iiCol->updateOne(['id'=>$mid], ['$set'=>['status'=>'Disposed']]);
        // Adjust borrow_limit for this model/category and prune if total is now zero
        try {
          $bmCol = $db->selectCollection('borrowable_catalog');
          $modelKey = $doc ? (string)($doc['model'] ?? ($doc['item_name'] ?? '')) : '';
          $cat = $doc ? ((string)($doc['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized';
          if ($modelKey !== '') {
            $agg = $iiCol->aggregate([
              ['$match' => [
                'category' => $cat,
                '$or' => [ ['model'=>$modelKey], ['item_name'=>$modelKey] ]
              ]],
              ['$project' => [ 'q' => ['$ifNull' => ['$quantity', 1]], 'status' => ['$ifNull' => ['$status','']] ]],
              ['$project' => [ 'cnt' => ['$cond' => [[ '$and' => [[ '$ne' => ['$status','Permanently Lost'] ], [ '$ne' => ['$status','Disposed'] ] ] ], '$q', 0 ]] ]],
              ['$group' => ['_id' => null, 'total' => ['$sum' => '$cnt']]]
            ])->toArray();
            $newTotal = (int)($agg[0]['total'] ?? 0);
            $existing = $bmCol->findOne(['model_name'=>$modelKey,'category'=>$cat], ['projection'=>['borrow_limit'=>1]]);
            $curLimit = $existing && isset($existing['borrow_limit']) ? (int)$existing['borrow_limit'] : 0;
            $newLimit = max(0, $curLimit - 1);
            if ($newLimit > $newTotal) { $newLimit = $newTotal; }
            if ($newTotal <= 0) {
              try { $bmCol->deleteOne(['model_name'=>$modelKey,'category'=>$cat]); } catch (Throwable $eDelBm) {}
            } else {
              $bmCol->updateOne(
                ['model_name'=>$modelKey,'category'=>$cat],
                ['$set'=>['active'=>1,'borrow_limit'=>$newLimit,'created_at'=>$now]],
                ['upsert'=>true]
              );
            }
          }
        } catch (Throwable $eBm) { /* ignore */ }
        // Retire serial
        try { $retCol->createIndex(['serial_no'=>1], ['unique'=>true]); } catch (Throwable $eIdx) {}
        if ($serial !== '') {
          try { $retCol->updateOne(['serial_no'=>$serial], ['$set'=>['serial_no'=>$serial,'reason'=>'Disposed','model_id'=>$mid,'retired_at'=>$now,'retired_by'=>$by]], ['upsert'=>true]); } catch (Throwable $eRet) {}
        }
        // Deletion history snapshot (reason: Disposed)
        try {
          $lastDel = $delLogCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
          $nextDelId = ($lastDel && isset($lastDel['id']) ? (int)$lastDel['id'] : 0) + 1;
          $delLogCol->insertOne([
            'id' => $nextDelId,
            'item_id' => $mid,
            'serial_no' => $serial,
            'deleted_by' => $by,
            'deleted_at' => $now,
            'reason' => 'Disposed',
            'item_name' => $doc ? (string)($doc['item_name'] ?? '') : '',
            'model' => $doc ? (string)($doc['model'] ?? '') : '',
            'category' => $doc ? (string)($doc['category'] ?? 'Uncategorized') : 'Uncategorized',
            'quantity' => $doc ? (int)($doc['quantity'] ?? 1) : 1,
            'status' => 'Disposed',
          ]);
        } catch (Throwable $eDel) { /* ignore */ }
        // Resolve any open Under Maintenance logs for this item
        try { $ldCol->updateMany(['model_id'=>$mid, 'action'=>'Under Maintenance', 'resolved_at'=>['$exists'=>false]], ['$set'=>['resolved_at'=>$now]]); } catch (Throwable $_e) {}
        // Log to Lost/Damaged history as Disposed
        $nextLD = $ldCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
        $lid = ($nextLD && isset($nextLD['id']) ? (int)$nextLD['id'] : 0) + 1;
        $ldCol->insertOne([
          'id'=>$lid,
          'model_id'=>$mid,
          'username'=>$by,
          'action'=>'Disposed',
          'created_at'=>$now,
          'serial_no'=>$serial,
          'model_key'=>$doc ? (string)($doc['model'] ?? ($doc['item_name'] ?? '')) : '',
          'category'=>$doc ? ((string)($doc['category'] ?? '') ?: 'Uncategorized') : 'Uncategorized',
          'location'=>$doc ? (string)($doc['location'] ?? '') : '',
        ]);
      } catch (Throwable $e) { /* ignore */ }
    }
    header('Location: admin_borrow_center.php?scroll=lost#lost-damaged'); exit();
  }
  elseif (in_array($do, ['mark_lost_with_details','mark_maint_with_details'], true)) {
    $reqId = (int)($_POST['request_id'] ?? 0);
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    // no location field; only remarks
    if ($remarks === '') { header('Location: admin_borrow_center.php?error=remarks_required'); exit(); }
    if ($reqId > 0) {
      try {
        @require_once __DIR__ . '/../vendor/autoload.php';
        @require_once __DIR__ . '/db/mongo.php';
        $db = get_mongo_db();
        $er = $db->selectCollection('equipment_requests');
        $ii = $db->selectCollection('inventory_items');
        $ub = $db->selectCollection('user_borrows');
        $ra = $db->selectCollection('request_allocations');
        $ld = $db->selectCollection('lost_damaged_log');
        $now = date('Y-m-d H:i:s');
        $who = $_SESSION['username'] ?? 'system';

        $req = $er->findOne(['id'=>$reqId]);
        if ($req) {
          $allocs = iterator_to_array($ra->find(['request_id'=>$reqId], ['projection'=>['borrow_id'=>1]]));
          $borrowIds = array_values(array_filter(array_map(function($d){ return isset($d['borrow_id']) ? (int)$d['borrow_id'] : 0; }, $allocs)));
          if (!empty($borrowIds)) {
            $cur = $ub->find(['id' => ['$in'=>$borrowIds], 'status'=>'Borrowed']);
            $activeBorrows = [];
            foreach ($cur as $b) { $activeBorrows[] = $b; }
            if (!empty($activeBorrows)) {
              usort($activeBorrows, function($a,$b){
                $ta = strtotime((string)($a['borrowed_at'] ?? '')) ?: 0;
                $tb = strtotime((string)($b['borrowed_at'] ?? '')) ?: 0;
                if ($ta === $tb) { return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0)); }
                return $ta <=> $tb;
              });
              $pick = $activeBorrows[0];
              $borrowId = (int)($pick['id'] ?? 0);
              $mid = (int)($pick['model_id'] ?? 0);
              if ($mid > 0 && $borrowId > 0) {
                if ($do === 'mark_lost_with_details') {
                  $iiUpdates = ['status' => 'Lost']; if ($remarks !== '') { $iiUpdates['remarks'] = $remarks; }
                  $ii->updateOne(['id'=>$mid], ['$set'=>$iiUpdates]);
                  $ub->updateOne(['id'=>$borrowId,'status'=>'Borrowed'], ['$set'=>['status'=>'Returned','returned_at'=>$now]]);
                  $note = ($remarks !== '' ? $remarks : '');
                  $nextLD = $ld->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
                  $lid = ($nextLD && isset($nextLD['id']) ? (int)$nextLD['id'] : 0) + 1;
                  $ld->insertOne(['id'=>$lid,'model_id'=>$mid,'username'=>$who,'action'=>'Lost','notes'=>$note,'created_at'=>$now]);
                } else { // mark_maint_with_details
                  $iiUpdates = ['status' => 'Under Maintenance']; if ($remarks !== '') { $iiUpdates['remarks'] = $remarks; }
                  $ii->updateOne(['id'=>$mid], ['$set'=>$iiUpdates]);
                  $ub->updateOne(['id'=>$borrowId,'status'=>'Borrowed'], ['$set'=>['status'=>'Returned','returned_at'=>$now]]);
                  $note = ($remarks !== '' ? $remarks : '');
                  $nextLD = $ld->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
                  $lid = ($nextLD && isset($nextLD['id']) ? (int)$nextLD['id'] : 0) + 1;
                  $ld->insertOne(['id'=>$lid,'model_id'=>$mid,'username'=>$who,'action'=>'Under Maintenance','notes'=>$note,'created_at'=>$now]);
                }
              }
            }
          }
        }
      } catch (Throwable $e) { /* ignore */ }
    }
    header('Location: admin_borrow_center.php?scroll=lost#lost-damaged'); exit();
  }
}

// Removed legacy MySQL fallback list population; Mongo data already prepared above
// Build quick lookup of active borrowable models per category
try {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  $db = isset($db) && $db instanceof MongoDB\Database ? $db : get_mongo_db();
  $bmCol = $db->selectCollection('borrowable_catalog');
  $borrowables = [];
  foreach ($bmCol->find([], ['sort'=>['category'=>1,'model_name'=>1]]) as $bm) { $borrowables[] = $bm; }
  $borrowLimitMap = [];
  foreach ($borrowables as $bm) {
    $c = (string)($bm['category'] ?? '');
    $m = (string)($bm['model_name'] ?? '');
    if ($c === '' || $m === '') continue;
    if (!isset($borrowLimitMap[$c])) { $borrowLimitMap[$c] = []; }
    $borrowLimitMap[$c][$m] = (int)($bm['borrow_limit'] ?? 0);
  }
  $activeConsumed = [];
  try {
    $ubCol = $db->selectCollection('user_borrows');
    $iiCol = $db->selectCollection('inventory_items');
    $mids = [];
    foreach ($ubCol->find(['status'=>'Borrowed'], ['projection'=>['model_id'=>1]]) as $br) {
      $mid = (int)($br['model_id'] ?? 0); if ($mid > 0) { $mids[$mid] = true; }
    }
    $midList = array_values(array_unique(array_map('intval', array_keys($mids))));
    $map = [];
    if (!empty($midList)) {
      foreach ($iiCol->find(['id'=>['$in'=>$midList]], ['projection'=>['id'=>1,'category'=>1,'model'=>1,'item_name'=>1]]) as $it) {
        $map[(int)($it['id'] ?? 0)] = [
          'c' => (string)($it['category'] ?? 'Uncategorized'),
          'm' => (string)($it['model'] ?? ($it['item_name'] ?? '')),
        ];
      }
      foreach ($ubCol->find(['status'=>'Borrowed','model_id'=>['$in'=>$midList]], ['projection'=>['model_id'=>1]]) as $br2) {
        $mid = (int)($br2['model_id'] ?? 0);
        if ($mid > 0 && isset($map[$mid])) {
          $c = $map[$mid]['c'] !== '' ? $map[$mid]['c'] : 'Uncategorized';
          $m = $map[$mid]['m'];
          if ($m !== '') {
            if (!isset($activeConsumed[$c])) { $activeConsumed[$c] = []; }
            if (!isset($activeConsumed[$c][$m])) { $activeConsumed[$c][$m] = 0; }
            $activeConsumed[$c][$m]++;
          }
        }
      }
    }
  } catch (Throwable $_ac) { $activeConsumed = $activeConsumed ?? []; }
  $pendingReturned = [];
  try {
    $rsCol = $db->selectCollection('returnship_requests');
    $iiCol2 = isset($iiCol) ? $iiCol : $db->selectCollection('inventory_items');
    $curPR = $rsCol->find(['verified_at'=>['$exists'=>true,'$ne'=>''], 'status'=>['$in'=>['Pending','Requested']]], ['projection'=>['qr_serial_no'=>1,'model_name'=>1]]);
    foreach ($curPR as $pr) {
      $serial = trim((string)($pr['qr_serial_no'] ?? ''));
      $mNm = trim((string)($pr['model_name'] ?? ''));
      $cat = 'Uncategorized';
      if ($serial !== '') {
        $it = $iiCol2->findOne(['serial_no'=>$serial], ['projection'=>['category'=>1,'model'=>1,'item_name'=>1]]);
        if ($it) {
          $cat = (string)($it['category'] ?? 'Uncategorized');
          if ($mNm === '') { $mNm = (string)($it['model'] ?? ($it['item_name'] ?? '')); }
        }
      }
      if ($mNm === '') continue;
      if (!isset($pendingReturned[$cat])) { $pendingReturned[$cat] = []; }
      if (!isset($pendingReturned[$cat][$mNm])) { $pendingReturned[$cat][$mNm] = 0; }
      $pendingReturned[$cat][$mNm]++;
    }
  } catch (Throwable $_pr) { $pendingReturned = $pendingReturned ?? []; }
} catch (Throwable $_bm) {
  $borrowables = $borrowables ?? [];
  $borrowLimitMap = $borrowLimitMap ?? [];
  $activeConsumed = $activeConsumed ?? [];
  $pendingReturned = $pendingReturned ?? [];
}
$activeBorrow = [];
foreach ($borrowables as $b) {
  if ((int)$b['active'] !== 1) continue;
  $c = trim((string)$b['category']);
  $m = trim((string)$b['model_name']);
  if ($c === '' || $m === '') continue;
  if (!isset($activeBorrow[$c])) { $activeBorrow[$c] = []; }
  $activeBorrow[$c][$m] = true;
}

// Inventory catalog and quantity stats via Mongo
try {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  $db = isset($db) && $db instanceof MongoDB\Database ? $db : get_mongo_db();
  $iiCol = $db->selectCollection('inventory_items');
  $holdCol = $db->selectCollection('returned_hold');
  $buCol = $db->selectCollection('borrowable_units');
  // Inventory catalog (category -> models)
  $invCatModels = [];
  $categoryCounts = [];
  foreach ($iiCol->find([], ['projection'=>['category'=>1,'model'=>1,'item_name'=>1]]) as $doc) {
    $c = trim((string)($doc['category'] ?? '')) !== '' ? (string)$doc['category'] : 'Uncategorized';
    $m = (string)($doc['model'] ?? ($doc['item_name'] ?? ''));
    if ($c === '' || $m === '') continue;
    if (!isset($invCatModels[$c])) { $invCatModels[$c] = []; $categoryCounts[$c] = 0; }
    if (!in_array($m, $invCatModels[$c], true)) { $invCatModels[$c][] = $m; }
    $categoryCounts[$c]++;
  }
  $heldCounts = [];
  foreach ($holdCol->aggregate([
    ['$group' => ['_id' => ['category'=>'$category','model_name'=>'$model_name'], 'cnt' => ['$sum' => 1]]]
  ]) as $row) {
    $id = (array)($row->_id ?? []);
    $c = trim((string)($id['category'] ?? '')) !== '' ? (string)$id['category'] : 'Uncategorized';
    $m = (string)($id['model_name'] ?? '');
    $cnt = (int)($row->cnt ?? 0);
    if ($c === '' || $m === '') continue;
    if (!isset($heldCounts[$c])) { $heldCounts[$c] = []; }
    $heldCounts[$c][$m] = $cnt;
    if (!isset($invCatModels[$c])) { $invCatModels[$c] = []; }
    if (!in_array($m, $invCatModels[$c], true)) { $invCatModels[$c][] = $m; }
  }
  foreach ($invCatModels as $c => &$mods) { natcasesort($mods); $mods = array_values(array_unique($mods)); }
  unset($mods);
  // Quantity stats per (category, model). Exclude Permanently Lost/Disposed from totals.
  // Also compute whitelisted status buckets so we can derive Available/Total inside
  // the borrowable list from the whitelist instead of raw inventory totals.
  $qtyStats = [];
  $wlBuckets = [];
  foreach ($iiCol->aggregate([
    ['$project' => [
      'category' => ['$ifNull' => ['$category', 'Uncategorized']],
      'model_name' => ['$ifNull' => ['$model', '$item_name']],
      'q' => ['$ifNull' => ['$quantity', 1]],
      'status' => ['$ifNull' => ['$status','']]
    ]],
    ['$project' => [
      'category' => 1,
      'model_name' => 1,
      // Available counts only when Available and q > 0
      'available' => ['$cond' => [['$and' => [['$eq' => ['$status','Available']], ['$gt' => ['$q', 0]]]], '$q', 0]],
      // Total excludes items that are not lendable (Lost/Damaged/Under Maintenance/Permanently Lost/Disposed)
      'total_count' => ['$cond' => [
        ['$and' => [
          ['$ne' => ['$status', 'Permanently Lost']],
          ['$ne' => ['$status', 'Disposed']],
          ['$ne' => ['$status', 'Lost']],
          ['$ne' => ['$status', 'Damaged']],
          ['$ne' => ['$status', 'Under Maintenance']]
        ]], '$q', 0
      ]]
    ]],
    ['$group' => [
      '_id' => ['category'=>'$category','model_name'=>'$model_name'],
      'available' => ['$sum' => '$available'],
      'total' => ['$sum' => '$total_count']
    ]]
  ]) as $row) {
    $id = (array)($row->_id ?? []);
    $c = (string)($id['category'] ?? 'Uncategorized');
    $m = (string)($id['model_name'] ?? '');
    if ($c === '' || $m === '') continue;
    if (!isset($qtyStats[$c])) { $qtyStats[$c] = []; }
    $qtyStats[$c][$m] = [ 'available' => (int)($row->available ?? 0), 'total' => (int)($row->total ?? 0) ];
  }
  // Build whitelist status buckets per (category, model) so that the borrowable list
  // can reflect 40/46-style semantics based on whitelisted units only.
  foreach ($buCol->aggregate([
    ['$lookup' => [
      'from' => 'inventory_items',
      'localField' => 'model_id',
      'foreignField' => 'id',
      'as' => 'item'
    ]],
    ['$unwind' => '$item'],
    ['$project' => [
      'category' => '$category',
      'model_name' => '$model_name',
      'status' => ['$ifNull' => ['$item.status', '']],
    ]],
    ['$group' => [
      '_id' => [
        'category' => ['$ifNull' => ['$category', 'Uncategorized']],
        'model_name' => ['$ifNull' => ['$model_name', '']],
      ],
      'total' => ['$sum' => 1],
      'avail' => ['$sum' => [
        '$cond' => [[ '$eq' => ['$status', 'Available'] ], 1, 0]
      ]],
      'lostDamaged' => ['$sum' => [
        '$cond' => [[ '$in' => ['$status', ['Lost','Damaged','Under Maintenance']] ], 1, 0]
      ]]
    ]]
  ]) as $row) {
    $id = (array)($row->_id ?? []);
    $c = (string)($id['category'] ?? 'Uncategorized');
    $m = (string)($id['model_name'] ?? '');
    if ($c === '' || $m === '') continue;
    if (!isset($wlBuckets[$c])) { $wlBuckets[$c] = []; }
    $wlBuckets[$c][$m] = [
      'total' => (int)($row->total ?? 0),
      'avail' => (int)($row->avail ?? 0),
      'lostDamaged' => (int)($row->lostDamaged ?? 0),
    ];
  }
  // Prune models with zero total from Add From Category lists
  if (!empty($invCatModels)) {
    foreach ($invCatModels as $c => &$mods) {
      $mods = array_values(array_filter($mods, function($m) use ($qtyStats, $c){
        return isset($qtyStats[$c][$m]) && (int)$qtyStats[$c][$m]['total'] > 0;
      }));
    }
    unset($mods);
    // Recompute category counts displayed in the dropdown
    $categoryCounts = [];
    foreach ($invCatModels as $c => $mods) { $categoryCounts[$c] = count($mods); }
  }
} catch (Throwable $e) {
  // Default to empty on failure
  $invCatModels = $invCatModels ?? [];
  $categoryCounts = $categoryCounts ?? [];
  $heldCounts = $heldCounts ?? [];
  $qtyStats = $qtyStats ?? [];
}

// Returned queue removed: no items to render in Returned List
$returnedItems = [];

// Lost items via Mongo (latest action Lost)
try {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  $db = isset($db) && $db instanceof MongoDB\Database ? $db : get_mongo_db();
  $ldCol = $db->selectCollection('lost_damaged_log');
  $iiCol = $db->selectCollection('inventory_items');
  $ubCol = $db->selectCollection('user_borrows');
  $uCol  = $db->selectCollection('users');
  $lostItems = [];
  // Start from inventory: any unit whose current status is Lost
  $curInv = $iiCol->find(['status' => 'Lost'], ['sort' => ['id' => 1], 'limit' => 300]);
  foreach ($curInv as $ii) {
    $mid = (int)($ii['id'] ?? 0);
    if ($mid <= 0) continue;
    // Latest Lost log for notes/marked_by/marked_at (may be null if never logged)
    $l = null;
    try {
      $l = $ldCol->findOne(['model_id'=>$mid,'action'=>'Lost'], ['sort'=>['created_at'=>-1,'id'=>-1]]);
    } catch (Throwable $_l) { $l = null; }
    // Resolve affected student's username (prefer latest borrower)
    $studUser = '';
    try {
      $br = $ubCol->findOne(
        ['model_id'=>$mid],
        ['sort'=>['borrowed_at'=>-1,'id'=>-1], 'projection'=>['username'=>1]]
      );
      if ($br && isset($br['username'])) { $studUser = (string)$br['username']; }
    } catch (Throwable $_) {}
    if ($studUser === '') {
      $studUser = (string)($ii['last_borrower_username'] ?? ($ii['last_borrower'] ?? ''));
    }
    $studSid = '';
    $userName = $studUser;
    if ($studUser !== '') {
      try {
        $uu = $uCol->findOne(
          ['username'=>$studUser],
          ['projection'=>['school_id'=>1,'full_name'=>1,'first_name'=>1,'last_name'=>1,'name'=>1]]
        );
        if ($uu) {
          if (isset($uu['full_name']) && trim((string)$uu['full_name'])!=='') {
            $userName = (string)$uu['full_name'];
          }
          elseif (isset($uu['first_name']) || isset($uu['last_name'])) {
            $userName = trim((string)($uu['first_name']??'').' '.(string)($uu['last_name']??''));
          }
          elseif (isset($uu['name']) && trim((string)$uu['name'])!=='') {
            $userName = (string)$uu['name'];
          }
          if (isset($uu['school_id'])) { $studSid = (string)$uu['school_id']; }
        }
      } catch (Throwable $_) {}
    }
    $lostItems[] = [
      'model_id' => $mid,
      'serial_no' => (string)($ii['serial_no'] ?? ''),
      'model_key' => (string)($ii['model'] ?? ($ii['item_name'] ?? '')),
      'category' => (string)($ii['category'] ?? 'Uncategorized'),
      'user_name' => (string)$userName,
      'location' => (string)($ii['location'] ?? ''),
      'condition' => (string)($ii['condition'] ?? ''),
      'marked_by' => $l ? (string)($l['username'] ?? '') : '',
      'student_school_id' => $studSid,
      'marked_at' => $l ? (string)($l['created_at'] ?? '') : '',
      'notes' => $l ? (string)($l['notes'] ?? '') : '',
      'remarks' => (string)($ii['remarks'] ?? ''),
    ];
  }
} catch (Throwable $e) { $lostItems = []; }

// Damaged items via Mongo (latest action Under Maintenance)
try {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  $db = isset($db) && $db instanceof MongoDB\Database ? $db : get_mongo_db();
  $ldCol = $db->selectCollection('lost_damaged_log');
  $iiCol = $db->selectCollection('inventory_items');
  $uCol  = $db->selectCollection('users');
  $ubCol = $db->selectCollection('user_borrows');
  $damagedItems = [];
  // Start from inventory: any unit whose current status is Under Maintenance
  $curInv = $iiCol->find(['status' => 'Under Maintenance'], ['sort' => ['id' => 1], 'limit' => 300]);
  foreach ($curInv as $ii) {
    $mid = (int)($ii['id'] ?? 0);
    if ($mid <= 0) continue;
    // Latest Under Maintenance log for notes/marked_by/marked_at (may be null)
    $l = null;
    try {
      $l = $ldCol->findOne(['model_id'=>$mid,'action'=>'Under Maintenance'], ['sort'=>['created_at'=>-1,'id'=>-1]]);
    } catch (Throwable $_l) { $l = null; }
    // Resolve affected student's username (prefer latest borrower)
    $studUser = '';
    try {
      $br = $ubCol->findOne(
        ['model_id'=>$mid],
        ['sort'=>['borrowed_at'=>-1,'id'=>-1], 'projection'=>['username'=>1]]
      );
      if ($br && isset($br['username'])) { $studUser = (string)$br['username']; }
    } catch (Throwable $_) {}
    if ($studUser === '') {
      $studUser = (string)($ii['last_borrower_username'] ?? ($ii['last_borrower'] ?? ''));
    }
    $studSid = '';
    $userName = $studUser;
    if ($studUser !== '') {
      try {
        $uu = $uCol->findOne(
          ['username'=>$studUser],
          ['projection'=>['school_id'=>1,'full_name'=>1,'first_name'=>1,'last_name'=>1,'name'=>1]]
        );
        if ($uu) {
          if (isset($uu['full_name']) && trim((string)$uu['full_name'])!=='') {
            $userName = (string)$uu['full_name'];
          }
          elseif (isset($uu['first_name']) || isset($uu['last_name'])) {
            $userName = trim((string)($uu['first_name']??'').' '.(string)($uu['last_name']??''));
          }
          elseif (isset($uu['name']) && trim((string)$uu['name'])!=='') {
            $userName = (string)$uu['name'];
          }
          if (isset($uu['school_id'])) { $studSid = (string)$uu['school_id']; }
        }
      } catch (Throwable $_) {}
    }
    $damagedItems[] = [
      'model_id' => $mid,
      'serial_no' => (string)($ii['serial_no'] ?? ''),
      'model_key' => (string)($ii['model'] ?? ($ii['item_name'] ?? '')),
      'category' => (string)($ii['category'] ?? 'Uncategorized'),
      'user_name' => (string)$userName,
      'location' => (string)($ii['location'] ?? ''),
      'condition' => (string)($ii['condition'] ?? ''),
      'marked_by' => $l ? (string)($l['username'] ?? '') : '',
      'student_school_id' => $studSid,
      'marked_at' => $l ? (string)($l['created_at'] ?? '') : '',
      'notes' => $l ? (string)($l['notes'] ?? '') : '',
    ];
  }
} catch (Throwable $e) { $damagedItems = []; }

// Lost/Damaged history rows via Mongo with last_* dates per model
try {
  @require_once __DIR__ . '/../vendor/autoload.php';
  @require_once __DIR__ . '/db/mongo.php';
  $db = isset($db) && $db instanceof MongoDB\Database ? $db : get_mongo_db();
  $ldCol = $db->selectCollection('lost_damaged_log');
  $iiCol = $db->selectCollection('inventory_items');
  $ldHistory = [];
  $raw = iterator_to_array($ldCol->find([], ['sort'=>['created_at'=>-1,'id'=>-1], 'limit'=>300]));
  // De-dup exact duplicates (same model, action, timestamp) and omit resolution-only rows in the list
  $seenKeys = [];
  $filtered = [];
  foreach ($raw as $l) {
    $mid0 = (int)($l['model_id'] ?? 0);
    $act0 = (string)($l['action'] ?? '');
    $ts0  = (string)($l['created_at'] ?? '');
    if ($mid0 <= 0 || $ts0 === '' || $act0 === '') continue;
    // Skip resolution events in the event list; status column will reflect current state
    if (in_array($act0, ['Found','Fixed'], true)) continue;
    $k = $mid0.'|'.$act0.'|'.$ts0;
    if (isset($seenKeys[$k])) continue;
    $seenKeys[$k] = true;
    $filtered[] = $l;
  }
  // Additionally collapse entries with the same model and exact timestamp by preferring a higher-priority event
  $pickByTs = [];
  $prio = function($act){
    if ($act === 'Disposed') return 4;
    if ($act === 'Permanently Lost') return 3;
    if ($act === 'Lost') return 2;
    if ($act === 'Under Maintenance') return 1;
    return 0;
  };
  foreach ($filtered as $l) {
    $mid0 = (int)($l['model_id'] ?? 0); $ts0 = (string)($l['created_at'] ?? ''); $act0 = (string)($l['action'] ?? '');
    $k = $mid0.'|'.$ts0;
    if (!isset($pickByTs[$k]) || $prio($act0) > $prio((string)($pickByTs[$k]['action'] ?? ''))) {
      $pickByTs[$k] = $l;
    }
  }
  $filtered = array_values($pickByTs);
  // Build per-model chronological logs to compute per-episode final status
  $byModelChron = [];
  foreach ($raw as $l) {
    $midE = (int)($l['model_id'] ?? 0);
    if ($midE <= 0) continue;
    $byModelChron[$midE][] = $l;
  }
  foreach ($byModelChron as $midE => &$arrE) {
    usort($arrE, function($a, $b){
      $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
      $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
      if ($ta === $tb) {
        $ia = (int)($a['id'] ?? 0);
        $ib = (int)($b['id'] ?? 0);
        if ($ia === $ib) return 0;
        return ($ia < $ib) ? -1 : 1;
      }
      return ($ta < $tb) ? -1 : 1;
    });
  }
  unset($arrE);
  $episodeStatus = [];
  $episodeResolutionMap = [];
  $episodeResolutionAt = [];
  foreach ($byModelChron as $midE => $logsE) {
    $nE = is_array($logsE) ? count($logsE) : 0;
    $openTs = null;
    $openBaseStatus = '';
    for ($iE = 0; $iE < $nE; $iE++) {
      $le = $logsE[$iE];
      $actE = strtolower((string)($le['action'] ?? ''));
      $tsE  = (string)($le['created_at'] ?? '');
      if ($tsE === '' || $actE === '') continue;
      // Episode starts when item enters Lost or Damaged (Under Maintenance)
      if (in_array($actE, ['lost','under maintenance','damaged'], true)) {
        $openTs = $tsE;
        $openBaseStatus = ($actE === 'lost') ? 'Lost' : 'Under Maintenance';
        $episodeStatus[$midE.'|'.$tsE] = $openBaseStatus;
        continue;
      }
      if ($openTs === null) {
        // Resolution with no open episode; ignore
        continue;
      }
      if (in_array($actE, ['found','fixed','permanently lost','disposed','disposal'], true)) {
        $statusE = '';
        if ($actE === 'found') { $statusE = 'Found'; }
        elseif ($actE === 'fixed') { $statusE = 'Fixed'; }
        elseif ($actE === 'permanently lost') { $statusE = 'Permanently Lost'; }
        elseif ($actE === 'disposed' || $actE === 'disposal') { $statusE = 'Disposed'; }
        else { $statusE = $openBaseStatus; }
        $episodeStatus[$midE.'|'.$openTs] = $statusE;
        // Track which resolution log (by its own timestamp) closed this episode
        $episodeResolutionMap[$midE.'|'.$tsE] = $openTs;
        // Also track the resolution timestamp per episode (start_ts -> resolution_ts)
        if ($openTs !== '') {
          $episodeResolutionAt[$midE.'|'.$openTs] = $tsE;
        }
        $openTs = null;
        $openBaseStatus = '';
      }
    }
  }
  // Compute last action dates per model (use all logs, including Found/Fixed)
  $lastMap = [];
  foreach ($raw as $l) {
    $mid = (int)($l['model_id'] ?? 0);
    $act = (string)($l['action'] ?? '');
    $ts  = (string)($l['created_at'] ?? '');
    if ($mid <= 0 || $ts === '' || $act === '') continue;
    if (!isset($lastMap[$mid])) { $lastMap[$mid] = ['last_lost_at'=>'','last_maint_at'=>'','last_found_at'=>'','last_fixed_at'=>'','last_perm_lost_at'=>'','last_disposed_at'=>'']; }
    $actLower = strtolower($act);
    if ($actLower === 'lost' && $ts > (string)$lastMap[$mid]['last_lost_at']) { $lastMap[$mid]['last_lost_at'] = $ts; }
    if (($act === 'Under Maintenance' || $actLower === 'damaged') && $ts > (string)$lastMap[$mid]['last_maint_at']) { $lastMap[$mid]['last_maint_at'] = $ts; }
    if ($actLower === 'found' && $ts > (string)$lastMap[$mid]['last_found_at']) { $lastMap[$mid]['last_found_at'] = $ts; }
    if ($actLower === 'fixed' && $ts > (string)$lastMap[$mid]['last_fixed_at']) { $lastMap[$mid]['last_fixed_at'] = $ts; }
    // Track Permanently Lost and Disposed separately as well
    if ($actLower === 'permanently lost' && $ts > (string)$lastMap[$mid]['last_perm_lost_at']) { $lastMap[$mid]['last_perm_lost_at'] = $ts; }
    if (($actLower === 'disposed' || $actLower === 'disposal') && $ts > (string)$lastMap[$mid]['last_disposed_at']) { $lastMap[$mid]['last_disposed_at'] = $ts; }
  }
  // Build history rows enriched with last_* dates (using de-duplicated $filtered list)
  foreach ($filtered as $l) {
    $mid = (int)($l['model_id'] ?? 0);
    $actRowLower = strtolower((string)($l['action'] ?? ''));
    $tsRow = (string)($l['created_at'] ?? '');
    // If this is a Permanently Lost / Disposed resolution that finalized an existing episode,
    // skip it as its own row; the original Lost/Damaged entry will carry the final Status.
    if ($mid > 0 && $tsRow !== '' && in_array($actRowLower, ['permanently lost','disposed','disposal'], true)
        && isset($episodeResolutionMap[$mid.'|'.$tsRow])) {
      continue;
    }
    $ii = $mid>0 ? $iiCol->findOne(['id'=>$mid]) : null;
    $lm = $lastMap[$mid] ?? ['last_lost_at'=>'','last_maint_at'=>'','last_found_at'=>'','last_fixed_at'=>'','last_perm_lost_at'=>'','last_disposed_at'=>''];
    // Determine current_action for filtering and display
    $currentAction = '';
    $epKey = $mid.'|'.(string)($l['created_at'] ?? '');
    if (isset($episodeStatus[$epKey])) {
      // Prefer per-episode status when available so each Lost/Damaged entry keeps its own final outcome
      $currentAction = (string)$episodeStatus[$epKey];
    }
    if ($currentAction === '') {
      $lostAt  = !empty($lm['last_lost_at']) ? strtotime((string)$lm['last_lost_at']) : null;
      $foundAt = !empty($lm['last_found_at']) ? strtotime((string)$lm['last_found_at']) : null;
      $maintAt = !empty($lm['last_maint_at']) ? strtotime((string)$lm['last_maint_at']) : null;
      $fixedAt = !empty($lm['last_fixed_at']) ? strtotime((string)$lm['last_fixed_at']) : null;
      $permLostAt = !empty($lm['last_perm_lost_at']) ? strtotime((string)$lm['last_perm_lost_at']) : null;
      $disposedAt = !empty($lm['last_disposed_at']) ? strtotime((string)$lm['last_disposed_at']) : null;
      $latestAny = max($lostAt ?: 0, $foundAt ?: 0, $maintAt ?: 0, $fixedAt ?: 0, $permLostAt ?: 0, $disposedAt ?: 0);
      if ($latestAny > 0) {
        if ($disposedAt && $disposedAt === $latestAny) {
          // Disposed is terminal
          $currentAction = 'Disposed';
        }
        elseif ($permLostAt && $permLostAt === $latestAny) {
          // If there was a Found after Permanently Lost (unlikely), prefer Found; otherwise Permanently Lost
          $currentAction = 'Permanently Lost';
        }
        elseif ($lostAt && $lostAt === $latestAny) {
          // If there was a Found after Lost, consider Found; otherwise still Lost
          $currentAction = 'Lost';
        }
        elseif ($maintAt && $maintAt === $latestAny) {
          // If there was a Fixed after Damaged, consider Fixed; otherwise still Damaged
          if ($fixedAt && $fixedAt > $maintAt) { $currentAction = 'Fixed'; }
          else { $currentAction = 'Under Maintenance'; }
        }
        elseif ($foundAt && $foundAt === $latestAny) {
          $currentAction = 'Found';
        }
        elseif ($fixedAt && $fixedAt === $latestAny) {
          $currentAction = 'Fixed';
        }
      }
      if ($currentAction === '') {
        // Fallback to current inventory status if no history pair detected
        // Do NOT infer 'Permanently Lost' from inventory status; only show it when explicitly logged
        $ist = $ii ? (string)($ii['status'] ?? '') : '';
        if (in_array($ist, ['Lost','Under Maintenance','Found','Fixed'], true)) { $currentAction = $ist; }
      }
    }
    // Resolve the user's full name: prefer last borrower at/before log time
    $logWhen = (string)($l['created_at'] ?? '');
    $userUname = (string)($l['affected_username'] ?? '');
    // Determine admin marker for this log (who marked it)
    $markedCandidates = [
      (string)($l['marked_by'] ?? ''),
      (string)($l['admin_username'] ?? ''),
      (string)($l['created_by'] ?? ''),
      (string)($l['performed_by'] ?? ''),
      (string)($l['action_by'] ?? ''),
      (string)($l['username'] ?? ''),
    ];
    $pickFirst = function(array $arr){ foreach ($arr as $v) { if (isset($v) && trim((string)$v) !== '') return (string)$v; } return ''; };
    $markedUname = $pickFirst($markedCandidates);
    $tryBorrow = function($when) use ($ubCol, $mid){
      $pick = '';
      try {
        if ($when !== '' && $mid > 0) {
          $q1 = [
            'model_id' => $mid,
            'borrowed_at' => ['$lte' => $when],
            '$or' => [ ['returned_at' => null], ['returned_at' => ''], ['returned_at' => ['$gte' => $when]] ]
          ];
          $opt = ['sort' => ['borrowed_at' => -1, 'id' => -1], 'limit' => 1];
          foreach ($ubCol->find($q1, $opt) as $br) { $pick = (string)($br['username'] ?? ''); if ($pick!=='') break; }
          if ($pick==='') { $q2 = ['model_id'=>$mid, 'borrowed_at' => ['$lte'=>$when]]; foreach ($ubCol->find($q2, $opt) as $br){ $pick=(string)($br['username']??''); if($pick!=='') break; } }
        }
      } catch (Throwable $e) { /* ignore */ }
      return $pick;
    };
    if ($userUname === '') { $userUname = $tryBorrow($logWhen); }
    // For manual edits from inventory.php, treat the admin marker as the responsible user
    $src = isset($l['source']) ? (string)$l['source'] : '';
    if ($src === 'manual_edit' && $markedUname !== '') { $userUname = $markedUname; }
    // If still none, show admin; only then fallback to item last borrower
    if ($userUname === '' && $markedUname !== '') { $userUname = $markedUname; }
    if ($userUname === '' && $ii) { $userUname = (string)($ii['last_borrower_username'] ?? ($ii['last_borrower'] ?? '')); }
    $userFull = $userUname;
    $userSid = '';
    if ($userUname !== '') {
      try {
        $uf = $uCol->findOne(['username'=>$userUname], ['projection'=>['full_name'=>1,'school_id'=>1]]);
        if ($uf && !empty($uf['full_name'])) { $userFull = (string)$uf['full_name']; }
        if ($uf && isset($uf['school_id'])) { $userSid = (string)$uf['school_id']; }
      } catch (Throwable $e) {}
    }

    $ldHistory[] = [
      'id' => (int)($l['id'] ?? 0),
      'model_id' => $mid,
      'serial_no' => $ii ? (string)($ii['serial_no'] ?? '') : (string)($l['serial_no'] ?? ''),
      'username' => $userFull,
      'user_school_id' => $userSid,
      'action' => (string)($l['action'] ?? ''),
      'notes' => (string)($l['notes'] ?? ''),
      'created_at' => (string)($l['created_at'] ?? ''),
      'item_name' => $ii ? (string)($ii['item_name'] ?? '') : '',
      'model_key' => $ii ? (string)($ii['model'] ?? ($ii['item_name'] ?? '')) : (string)($l['model_key'] ?? ''),
      'category' => $ii ? (string)($ii['category'] ?? 'Uncategorized') : (string)($l['category'] ?? 'Uncategorized'),
      'location' => $ii ? (string)($ii['location'] ?? '') : (string)($l['location'] ?? ''),
      'last_lost_at' => (string)$lm['last_lost_at'],
      'last_maint_at' => (string)$lm['last_maint_at'],
      'last_found_at' => (string)$lm['last_found_at'],
      'last_fixed_at' => (string)$lm['last_fixed_at'],
      'last_disposed_at' => (string)$lm['last_disposed_at'],
      'episode_resolved_at' => isset($episodeResolutionAt[$epKey]) ? (string)$episodeResolutionAt[$epKey] : '',
      'current_action' => $currentAction,
    ];
  }
} catch (Throwable $e) { $ldHistory = []; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Borrow Requests</title>
  <link href="css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__.'/css/style.css'); ?>">
  <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" />
  <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
  <style>
    html, body { height: 100%; }
    body { overflow: hidden; }
    #sidebar-wrapper { position: sticky; top: 0; height: 100vh; overflow: hidden; }
    #page-content-wrapper { flex: 1 1 auto; height: 100vh; overflow: auto; padding: 0.5rem !important; }
    @media (max-width: 768px) { body { overflow: auto; } #page-content-wrapper { height: auto; overflow: visible; } }
    /* Slightly shorter scrollable lists */
    @media (min-width: 768px) {
      .list-scroll { min-height: 45vh; max-height: calc(100vh - 300px); overflow-y: auto; }
    }
  </style>
</head>
<body class="allow-mobile">
  <div class="d-flex">
    <div class="bg-light border-end" id="sidebar-wrapper">
      <div class="sidebar-heading py-4 fs-4 fw-bold border-bottom d-flex align-items-center justify-content-center">
        <img src="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" alt="ECA Logo" class="brand-logo me-2" />
        <span>ECA MIS-GMIS</span>
      </div>
      <div class="list-group list-group-flush my-3">
        <a href="admin_dashboard.php" class="list-group-item list-group-item-action bg-transparent"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
        <a href="inventory.php" class="list-group-item list-group-item-action bg-transparent"><i class="bi bi-box-seam me-2"></i>Inventory</a>
        <a href="inventory_print.php" class="list-group-item list-group-item-action bg-transparent"><i class="bi bi-printer me-2"></i>Print Inventory</a>
        <a href="generate_qr.php" class="list-group-item list-group-item-action bg-transparent"><i class="bi bi-qr-code me-2"></i>Add Item/Generate QR</a>
        <a href="qr_scanner.php" class="list-group-item list-group-item-action bg-transparent"><i class="bi bi-camera me-2"></i>QR Scanner</a>
        <a href="admin_borrow_center.php" class="list-group-item list-group-item-action bg-transparent fw-bold"><i class="bi bi-clipboard-check me-2"></i>Borrow Requests</a>
        <a href="user_management.php" class="list-group-item list-group-item-action bg-transparent"><i class="bi bi-people me-2"></i>User Management</a>
        <a href="change_password.php" class="list-group-item list-group-item-action bg-transparent"><i class="bi bi-key me-2"></i>Change Password</a>
        <a href="logout.php" class="list-group-item list-group-item-action bg-transparent"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
      </div>
    </div>
    <div class="p-4" id="page-content-wrapper">
      <div class="page-header d-flex justify-content-between align-items-center">
        <h2 class="page-title"><i class="bi bi-clipboard-check me-2"></i>Borrow Center</h2>
        <div class="dropdown">
          <button class="btn btn-outline-primary dropdown-toggle" type="button" id="bcActionsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-list"></i> Actions
          </button>
          <ul class="dropdown-menu dropdown-menu-end actions-menu" aria-labelledby="bcActionsDropdown">
            <li>
              <a class="dropdown-item d-flex align-items-center" href="#" data-bs-toggle="modal" data-bs-target="#ldHistoryModal">
                <i class="bi bi-clock-history me-2"></i>Lost/Damaged History
              </a>
            </li>
            <li>
              <a class="dropdown-item d-flex align-items-center" href="#" data-bs-toggle="modal" data-bs-target="#lostDamagedListModal">
                <i class="bi bi-exclamation-triangle me-2"></i>Lost/Damaged Item List
              </a>
            </li>
            <li>
              <a class="dropdown-item d-flex align-items-center" href="#" data-bs-toggle="modal" data-bs-target="#overdueModal">
                <i class="bi bi-hourglass-split me-2"></i>Overdue Items
              </a>
            </li>
            <li>
              <a class="dropdown-item d-flex align-items-center" href="#" data-bs-toggle="modal" data-bs-target="#resTimelineModal">
                <i class="bi bi-calendar-week me-2"></i>Reservation Timeline
              </a>
            </li>
          </ul>
        </div>
      </div>

      <div class="modal fade" id="resTimelineModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width:95vw; width:95vw; max-height:95vh; height:95vh;">
          <div class="modal-content" style="height:100%;">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-calendar-week me-2"></i>Reservation Timeline</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="height:calc(95vh - 120px); overflow:auto;">
              <div class="row g-2 align-items-end mb-2">
                <div class="col-12 col-md-4">
                  <label class="form-label mb-1 small">Category</label>
                  <select id="resFilterCategory" class="form-select form-select-sm"><option value="">All</option></select>
                </div>
                <div class="col-8 col-md-5">
                  <label class="form-label mb-1 small">Search</label>
                  <input id="resFilterSearch" type="text" class="form-control form-control-sm" placeholder="Search serial/model" />
                </div>
                <div class="col-12 col-md-3">
                  <label class="form-label mb-1 small">Date </label>
                  <input id="resFilterDay" type="date" class="form-control form-control-sm" />
                </div>
              </div>
              <div id="resTimelineList" class="row g-2"></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

  <!-- Mark Lost Modal -->
  <div class="modal fade" id="markLostModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="POST" class="modal-content">
        <input type="hidden" name="do" value="mark_lost_with_details" />
        <input type="hidden" name="request_id" id="ml_req_id" />
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Mark as Lost</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>Item:</strong> <span id="ml_model_name"></span> (<span id="ml_model_id"></span>)</div>
          <div class="mb-2">
            <label class="form-label">Remarks</label>
            <textarea class="form-control" name="remarks" rows="3" placeholder="Describe what happened" required></textarea>
          </div>
          <div class="small text-muted">Remarks will also update the item's remarks in Inventory.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-check2-circle me-1"></i>Confirm Lost</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Mark Maintenance (Damaged) Modal -->
  <div class="modal fade" id="markMaintModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="POST" class="modal-content">
        <input type="hidden" name="do" value="mark_maint_with_details" />
        <input type="hidden" name="request_id" id="mm_req_id" />
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-tools me-2"></i>Mark as Damaged / Under Maintenance</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>Item:</strong> <span id="mm_model_name"></span> (<span id="mm_model_id"></span>)</div>
          <div class="mb-2">
            <label class="form-label">Remarks (type of damage)</label>
            <textarea class="form-control" name="remarks" rows="3" placeholder="Describe the damage" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning text-dark"><i class="bi bi-check2-circle me-1"></i>Confirm</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Populate Mark Lost / Maintenance modals
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        var lostM = document.getElementById('markLostModal');
        if (lostM) {
          lostM.addEventListener('show.bs.modal', function(e){
            var btn = e.relatedTarget; if (!btn) return;
            var rid = btn.getAttribute('data-reqid') || '';
            var serial = btn.getAttribute('data-serial') || '';
            var name = btn.getAttribute('data-model_name') || '';
            var idEl = document.getElementById('ml_req_id'); if (idEl) idEl.value = rid;
            var nmEl = document.getElementById('ml_model_name'); if (nmEl) nmEl.textContent = name;
            var midEl = document.getElementById('ml_model_id'); if (midEl) midEl.textContent = serial;
          });
        }
        var maintM = document.getElementById('markMaintModal');
        if (maintM) {
          maintM.addEventListener('show.bs.modal', function(e){
            var btn = e.relatedTarget; if (!btn) return;
            var rid = btn.getAttribute('data-reqid') || '';
            var serial = btn.getAttribute('data-serial') || '';
            var name = btn.getAttribute('data-model_name') || '';
            var idEl2 = document.getElementById('mm_req_id'); if (idEl2) idEl2.value = rid;
            var nmEl2 = document.getElementById('mm_model_name'); if (nmEl2) nmEl2.textContent = name;
            var midEl2 = document.getElementById('mm_model_id'); if (midEl2) midEl2.textContent = serial;
          });
        }
      });
    })();
  </script>
  <!-- View Borrowable Serials Modal -->
  <div class="modal fade" id="bmViewUnitsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-list-ul me-2"></i>Borrowable Serials</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2 small text-muted" id="bmViewUnitsMeta"></div>
          <div class="table-responsive">
            <style>
              #bmViewUnitsModal table{table-layout:fixed;width:100%;}
              #bmViewUnitsModal th,#bmViewUnitsModal td{white-space:normal;overflow:visible;text-overflow:clip;font-size:clamp(10px,0.9vw,12px);line-height:1.2;}
              #bmViewUnitsModal td:nth-child(2){overflow-wrap:normal;word-break:keep-all;}
              #bmViewUnitsModal .twol{display:block;line-height:1.15;}
              #bmViewUnitsModal .twol .dte,#bmViewUnitsModal .twol .tme{display:block;}
              #bmViewUnitsModal tbody tr{height:calc(2 * 1.2em + 0.6rem);} /* uniform ~two-line row height */
            </style>
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Serial ID</th>
                  <th>Status</th>
                  <th>Location</th>
                  <th>Start</th>
                  <th>End</th>
                </tr>
              </thead>
              <tbody id="bmViewUnitsBody">
                <tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>
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
  <script>
    (function(){
      function esc(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
      function two(n){ n=parseInt(n,10); return (n<10?'0':'')+n; }
      function fmtD(dt){ try{ if(!dt) return ''; var s=String(dt).trim(); var m=s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/); if(!m) return ''; return two(parseInt(m[2],10))+'-'+two(parseInt(m[3],10))+'-'+m[1]; }catch(_){ return ''; } }
      function fmtT(dt){ try{ if(!dt) return ''; var s=String(dt).trim(); var m=s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/); if(!m) return ''; var H=parseInt(m[4],10), M=parseInt(m[5],10); var ap=(H>=12?'pm':'am'); var h=H%12; if(h===0)h=12; return h+':'+two(M)+' '+ap; }catch(_){ return ''; } }
      function twoLine(dt){ if(!dt) return ''; var d=fmtD(dt), t=fmtT(dt); if(!d && !t) return ''; return '<span class="twol"><span class="dte">'+esc(d)+'</span> <span class="tme">'+esc(t)+'</span></span>'; }
      async function fetchWhitelisted(cat, model){
        const url = 'admin_borrow_center.php?action=list_borrowable_units&category='+encodeURIComponent(cat)+'&model='+encodeURIComponent(model);
        const r = await fetch(url); if(!r.ok) return [];
        const j = await r.json().catch(()=>({ok:false,items:[]}));
        if (!j || !j.ok || !Array.isArray(j.items)) return [];
        return j.items;
      }
      document.addEventListener('click', async function(e){
        const btn = e.target.closest && e.target.closest('.bm-view-units');
        if (!btn) return;
        const cat = btn.getAttribute('data-category')||'';
        const model = btn.getAttribute('data-model')||'';
        const meta = document.getElementById('bmViewUnitsMeta');
        const body = document.getElementById('bmViewUnitsBody');
        if (meta) meta.innerHTML = 'Category: <strong>'+esc(cat)+'</strong> | Model: <strong>'+esc(model)+'</strong>';
        if (body) body.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>';
        const items = await fetchWhitelisted(cat, model);
        const rows = [];
        if (!items.length) {
          rows.push('<tr><td colspan="5" class="text-center text-muted">No whitelisted serials yet.</td></tr>');
        } else {
          items.forEach(function(it){
            var rs='', re='';
            if (String(it.status)==='Reserved' && it.reserved_from && it.reserved_to){ rs=twoLine(it.reserved_from); re=twoLine(it.reserved_to); }
            else if (String(it.status)==='In Use'){ if (it.in_use_start) rs=twoLine(it.in_use_start); if (it.in_use_end) re=twoLine(it.in_use_end); }
            rows.push('<tr>'+
              '<td>'+esc(it.serial_no||'')+'</td>'+
              '<td>'+esc(it.status||'')+'</td>'+
              '<td>'+esc(it.location||'')+'</td>'+
              '<td>'+rs+'</td>'+
              '<td>'+re+'</td>'+
            '</tr>');
          });
        }
        if (body) body.innerHTML = rows.join('');
      });
    })();
  </script>
  <script>
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        // Admin notifications: user verified returns (toast + beep)
        try {
          var toastWrap = document.getElementById('adminToastWrap');
          if (!toastWrap) {
            toastWrap = document.createElement('div'); toastWrap.id = 'adminToastWrap';
            toastWrap.style.position='fixed'; toastWrap.style.right='16px'; toastWrap.style.bottom='16px'; toastWrap.style.zIndex='1030';
            document.body.appendChild(toastWrap);
          }
          window.adjustAdminToastOffset = function(){
              try{
                var tw=document.getElementById('adminToastWrap'); if(!tw) return;
                var baseRight = (window.matchMedia && window.matchMedia('(max-width: 768px)').matches)?14:16; tw.style.right=baseRight+'px';
                var bottomPx=16;
                try{
                  if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
                    var nav=document.querySelector('.bottom-nav'); var hidden=nav && nav.classList && nav.classList.contains('hidden');
                    if (nav && !hidden){
                      var rect=nav.getBoundingClientRect(); var h=Math.round(Math.max(0, window.innerHeight-rect.top)); if(!h||!isFinite(h)) h=64; bottomPx=h+12; 
                    } else {
                      // align above floating toggle button if present
                      var btn=document.querySelector('.bottom-nav-toggle');
                      if (btn){ var br=btn.getBoundingClientRect(); var bh=Math.round(Math.max(0, window.innerHeight-br.top)); if(!bh||!isFinite(bh)) bh=64; bottomPx=bh+12; }
                      else { bottomPx=16; }
                    }
                  }
                }catch(_){ bottomPx=64; }
                tw.style.bottom=String(bottomPx)+'px';
              }catch(_){ }
          }
          try{ window.addEventListener('resize', window.adjustAdminToastOffset); }catch(_){ }
          try{ window.adjustAdminToastOffset(); }catch(_){ }
          function attachSwipeForToast(el){ try{ var sx=0, sy=0, dx=0, moving=false, removed=false; var onStart=function(ev){ try{ var t=ev.touches?ev.touches[0]:ev; sx=t.clientX; sy=t.clientY; dx=0; moving=true; el.style.willChange='transform,opacity'; el.classList.add('toast-slide'); el.style.transition='none'; }catch(_){}}; var onMove=function(ev){ if(!moving||removed) return; try{ var t=ev.touches?ev.touches[0]:ev; dx=t.clientX - sx; var adx=Math.abs(dx); var od=1 - Math.min(1, adx/140); el.style.transform='translateX('+dx+'px)'; el.style.opacity=String(od); }catch(_){}}; var onEnd=function(){ if(!moving||removed) return; moving=false; try{ el.style.transition='transform 180ms ease, opacity 180ms ease'; var adx=Math.abs(dx); if (adx>80){ removed=true; el.classList.add(dx>0?'toast-remove-right':'toast-remove-left'); setTimeout(function(){ try{ el.remove(); }catch(_){ } }, 200); } else { el.style.transform=''; el.style.opacity=''; } }catch(_){ } }; el.addEventListener('touchstart', onStart, {passive:true}); el.addEventListener('touchmove', onMove, {passive:true}); el.addEventListener('touchend', onEnd, {passive:true}); }catch(_){ } }
          function showToast(msg, cls){ var el=document.createElement('div'); el.className='alert '+(cls||'alert-info')+' shadow-sm border-0 toast-slide toast-enter'; el.style.minWidth='300px'; el.style.maxWidth='340px'; try{ if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='180px'; el.style.maxWidth='200px'; el.style.fontSize='12px'; } }catch(_){ } el.innerHTML='<i class="bi bi-bell me-2"></i>'+String(msg||''); toastWrap.appendChild(el); try{ window.adjustAdminToastOffset(); }catch(_){ } attachSwipeForToast(el); setTimeout(function(){ try{ el.classList.add('toast-fade-out'); setTimeout(function(){ try{ el.remove(); }catch(_){ } }, 220); }catch(_){ } }, 5000); }

          // Show any server-side flash messages (approved/rejected/etc.) as admin toasts
          try {
            if (window.__AB_FLASH) {
              if (window.__AB_FLASH.approved) {
                showToast('Request approved successfully.','alert-success');
              }
              if (window.__AB_FLASH.rejected) {
                showToast('Request rejected successfully.','alert-secondary');
              }
              if (window.__AB_FLASH.insufficient) {
                showToast('Insufficient available units to fulfill the request.','alert-warning');
              }
              if (window.__AB_FLASH.error) {
                showToast(window.__AB_FLASH.error,'alert-danger');
              }
              window.__AB_FLASH = null;
            }
          } catch(_){ }
          function playBeep(){ try{ if(!window.__brAudioCtx){ window.__brAudioCtx = new (window.AudioContext||window.webkitAudioContext)(); } var ctx = window.__brAudioCtx; if (ctx.state === 'suspended') { try{ ctx.resume(); }catch(_e){} } var o = ctx.createOscillator(); var g = ctx.createGain(); o.type='square'; o.frequency.setValueAtTime(880, ctx.currentTime); g.gain.setValueAtTime(0.0001, ctx.currentTime); o.connect(g); g.connect(ctx.destination); o.start(); g.gain.exponentialRampToValueAtTime(0.35, ctx.currentTime+0.03); g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime+0.6); o.stop(ctx.currentTime+0.65); } catch(_e){} }
          var baseVerif = new Set(); var initFeed=false; var feeding=false;
          function pollVerif(){ if (feeding) return; feeding=true;
            fetch('admin_borrow_center.php?action=returnship_feed')
              .then(function(r){ return r.json(); })
              .then(function(d){ var list = (d && d.ok && Array.isArray(d.verifications)) ? d.verifications : []; var ids = new Set(list.map(function(v){ return parseInt(v.id||0,10); }).filter(function(n){ return n>0; })); if (!initFeed){ baseVerif = ids; initFeed=true; return; } var ding=false; list.forEach(function(v){ var id=parseInt(v.id||0,10); if (!baseVerif.has(id)){ ding=true; var name=String(v.model_name||''); var sn=String(v.qr_serial_no||''); var loc=String(v.location||''); showToast('User verified return for '+(name?name+' ':'')+(sn?('['+sn+']'):'')+(loc?(' @ '+loc):''), 'alert-info'); } }); if (ding) playBeep(); baseVerif = ids; })
              .catch(function(){})
              .finally(function(){ feeding=false; });
          }
          pollVerif(); setInterval(function(){ if (document.visibilityState==='visible') pollVerif(); }, 2000);
          // Also poll user self-return feed (return_events) for toasts
          var baseRet = new Set(); var initRet=false; var fetchingRet=false;
          function pollUserReturns(){ if (fetchingRet) return; fetchingRet = true;
            fetch('admin_borrow_center.php?action=return_feed')
              .then(function(r){ return r.json(); })
              .then(function(d){ var list = (d && d.ok && Array.isArray(d.returns)) ? d.returns : []; var ids = new Set(list.map(function(v){ return parseInt(v.id||0,10); }).filter(function(n){ return n>0; })); if (!initRet){ baseRet = ids; initRet=true; return; } var ding=false; list.forEach(function(v){ var id=parseInt(v.id||0,10); if (!baseRet.has(id)){ ding=true; var name=String(v.model_name||''); var sn=String(v.qr_serial_no||''); var loc=String(v.location||''); showToast('User returned '+(name?name+' ':'')+(sn?('['+sn+']'):'')+(loc?(' @ '+loc):''), 'alert-success'); } }); if (ding) try{ playBeep(); }catch(_){ } baseRet = ids; })
              .catch(function(){})
              .finally(function(){ fetchingRet=false; });
          }
          pollUserReturns(); setInterval(function(){ if (document.visibilityState==='visible') pollUserReturns(); }, 2000);
          var __brBaseIds = new Set(); var __brInit=false; var __brFetch=false;
          function pollBorrowReqSound(){ if (__brFetch) return; __brFetch = true; fetch('admin_borrow_center.php?action=admin_notifications').then(function(r){ return r.json(); }).then(function(d){ var pending = (d && Array.isArray(d.pending)) ? d.pending : []; var curr = new Set(pending.map(function(it){ return parseInt(it.id||0,10); }).filter(function(n){ return n>0; })); if (!__brInit) { __brBaseIds = curr; __brInit = true; return; } var hasNew=false; curr.forEach(function(id){ if(!__brBaseIds.has(id)) hasNew=true; }); if (hasNew) { try{ playBeep(); }catch(_){ } } __brBaseIds = curr; }).catch(function(){}).finally(function(){ __brFetch = false; }); }
          pollBorrowReqSound(); setInterval(function(){ if (document.visibilityState==='visible') pollBorrowReqSound(); }, 1500);
        } catch(_e){}
        var mdl = document.getElementById('qrReturnAdminModal');
        if (!mdl) return;
        var reqSpan = document.getElementById('qrAdmReq');
        var modelSpan = document.getElementById('qrAdmModel');
        var serialSpan = document.getElementById('qrAdmSerial');
        var locEl = document.getElementById('qrAdmLoc');
        mdl.addEventListener('show.bs.modal', function(e){
          var btn = e.relatedTarget; if (!btn) return;
          var rid = btn.getAttribute('data-reqid')||'';
          var mdlName = btn.getAttribute('data-model_name')||'';
          var serial = btn.getAttribute('data-serial')||'';
          reqSpan.textContent = rid; modelSpan.textContent = mdlName; serialSpan.textContent = serial || '—';
          if (locEl) locEl.textContent = '—';
          if (rid) {
            fetch('admin_borrow_center.php?action=request_info&request_id='+encodeURIComponent(String(rid)))
              .then(function(r){ return r.json(); })
              .then(function(resp){
                if (resp && resp.ok && resp.request) {
                  var loc = String(resp.request.request_location || resp.request.req_location || '');
                  if (locEl) locEl.textContent = loc ? loc : '—';
                } else { if (locEl) locEl.textContent = '—'; }
              })
              .catch(function(){ if (locEl) locEl.textContent = '—'; });
          }
        });
      });
    })();
  </script>
  <!-- Serial Selection Modal -->
  <div class="modal fade" id="bmSerialSelectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-ui-checks-grid me-2"></i>Select Serials to Release</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="bmSerialSelectBody">
          <div class="text-muted">Loading serials...</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="bmSerialConfirmBtn">Confirm</button>
        </div>
      </div>
    </div>
  </div>
  <script>
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        const form = document.getElementById('bm_add_form');
        if (!form) return;
        const catSel = document.getElementById('bm_bulk_category');
        const modalEl = document.getElementById('bmSerialSelectModal');
        const modalBody = document.getElementById('bmSerialSelectBody');
        const confirmBtn = document.getElementById('bmSerialConfirmBtn');
        let bsModal = null;
        function ensureModal(){ if (!bsModal && window.bootstrap && bootstrap.Modal) { bsModal = bootstrap.Modal.getOrCreateInstance(modalEl); } }
      function gatherSelections(){
        const map = {};
        const tbody = document.getElementById('bm_models_body');
        if (!tbody) return map;
        tbody.querySelectorAll('tr').forEach(tr=>{
          const cb = tr.querySelector('.bm-row-check');
          const lim = tr.querySelector('input[name^="limits["]');
          if (!cb || !lim) return;
          const model = (cb.value||'').trim();
          const limit = parseInt(lim.value||'0',10)||0;
          if (cb.checked && limit > 0 && model) { map[model] = limit; }
        });
        return map;
      }
      async function fetchSelectableSerials(cat, model){
        const url = 'admin_borrow_center.php?action=selectable_serials&category='+encodeURIComponent(cat)+'&model='+encodeURIComponent(model);
        const r = await fetch(url); if (!r.ok) return [];
        const j = await r.json().catch(()=>({ok:false,items:[]}));
        if (!j || !j.ok || !Array.isArray(j.items)) return [];
        return j.items;
      }
      function esc(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
      function buildModalContent(dataMap){
        const parts = [];
        Object.keys(dataMap).forEach(model=>{
          const info = dataMap[model];
          const items = info.items||[]; const limit = info.limit||0;
          parts.push('<div class="mb-3">'+
            '<div class="d-flex justify-content-between align-items-center mb-1">'+
              '<strong>'+esc(model)+'</strong>'+
              '<span class="badge bg-secondary">Limit '+limit+'</span>'+
            '</div>');
          if (!items.length) { parts.push('<div class="text-muted small">No serials in hold for this model.</div></div>'); return; }
          parts.push('<div class="d-flex flex-wrap gap-2">');
          let pre = 0;
          items.forEach((it, idx)=>{
            const sid = parseInt(it.model_id||0,10)||0;
            const serial = String(it.serial_no||'');
            const shouldCheck = (limit>0 && pre < limit);
            if (shouldCheck) pre++;
            parts.push('<label class="border rounded px-2 py-1 d-inline-flex align-items-center bm-serial-chip">'+
              '<input type="checkbox" class="form-check-input me-2 bm-serial-check" data-model="'+esc(model)+'" data-mid="'+sid+'"'+(shouldCheck?' checked':'')+' />'+
              '<span class="small">'+(serial?esc(serial):'(no serial)')+'</span>'+
            '</label>');
          });
          parts.push('</div></div>');
        });
        modalBody.innerHTML = parts.join('');
        // enforce per-model limits without CSS.escape
        modalBody.querySelectorAll('.bm-serial-check').forEach(cb=>{
          cb.addEventListener('change', function(){
            const model = this.getAttribute('data-model')||'';
            const limit = (dataMap[model] && dataMap[model].limit) ? parseInt(dataMap[model].limit,10)||0 : 0;
            if (!model || !limit) return;
            let count = 0;
            modalBody.querySelectorAll('.bm-serial-check').forEach(x=>{
              if ((x.getAttribute('data-model')||'')===model && x.checked) count++;
            });
            if (count > limit) { this.checked = false; }
          });
        });
      }
      form && form.addEventListener('submit', async function(ev){
        try {
          const cat = catSel ? catSel.value : '';
          const selMap = gatherSelections();
          // If nothing selected with limit, submit as-is
          if (!cat || Object.keys(selMap).length === 0) return;
          ev.preventDefault();
          // Prefetch hold serials for each selected model
          const dataMap = {};
          await Promise.all(Object.keys(selMap).map(async (m)=>{
            const items = await fetchSelectableSerials(cat, m);
            dataMap[m] = { items: items, limit: selMap[m] };
          }));
          ensureModal();
          buildModalContent(dataMap);
          // If nothing to select for all models, show a notice in the modal
          const allEmpty = Object.keys(dataMap).every(k => !dataMap[k].items || dataMap[k].items.length === 0);
          if (allEmpty) {
            modalBody.innerHTML = '<div class="alert alert-info mb-0">No units to select for the chosen models. Click Confirm to proceed.</div>';
          }
          // Confirm handler adds hidden inputs and submits
          const onConfirm = function(){
            // Clear previous hidden serial inputs
            form.querySelectorAll('input[name^="serials["]').forEach(n=>n.remove());
            const picksByModel = {};
            modalBody.querySelectorAll('.bm-serial-check:checked').forEach(cb=>{
              const m = cb.getAttribute('data-model')||'';
              const mid = parseInt(cb.getAttribute('data-mid')||'0',10)||0;
              if (!picksByModel[m]) picksByModel[m] = [];
              if (mid>0) picksByModel[m].push(mid);
            });
            Object.keys(picksByModel).forEach(m=>{
              picksByModel[m].forEach(mid=>{
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'serials['+m+'][]';
                inp.value = String(mid);
                form.appendChild(inp);
              });
            });
            // Submit
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            confirmBtn.removeEventListener('click', onConfirm);
            bsModal && bsModal.hide();
            setTimeout(()=>{ form.submit(); }, 0);
          };
          const onHidden = function(){ confirmBtn.removeEventListener('click', onConfirm); modalEl.removeEventListener('hidden.bs.modal', onHidden); };
          modalEl.addEventListener('hidden.bs.modal', onHidden);
          confirmBtn.addEventListener('click', onConfirm);
          bsModal && bsModal.show();
        } catch (_) { /* fallback: allow submit */ }
      });
      });
    })();
  </script>
  <script>
    // Return with Scan Modal logic (init after DOM is ready)
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        function q(id){ return document.getElementById(id); }
        var scanner = null, scanning = false;
        var mdl = q('returnScanModal');
        var reqDisp = q('rsReq');
        var modelDisp = q('rsModel');
        var reqIdEl = q('rsReqId');
        var inputId = q('rsSerial');
        var statusEl = q('rsStatus');
        var startBtn = q('rsStart');
        var stopBtn = q('rsStop');
        var readerDiv = q('rsReader');
        var form = q('rsForm');
        var imgInput = q('rsImageFile');
        var returnBtn = q('rsReturnBtn');
        var camWrap = q('rsCamWrap');
        var camSelect = q('rsCameraSelect');
        var refreshBtn = q('rsRefreshCams');
        var camsCache = [];
        var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        function setStatus(t,cls){ if(statusEl){ statusEl.textContent=t; statusEl.className='small '+(cls||'text-muted'); } }
        function stop(){
          if (scanner && scanning){
            scanner.stop().then(()=>{
              try{ scanner.clear(); }catch(_){ }
              scanner = null;
              scanning = false;
              if(startBtn) startBtn.style.display='inline-block';
              if(stopBtn) stopBtn.style.display='none';
              setStatus('Scanner stopped.','text-muted');
            }).catch(()=>{ try{ scanner=null; }catch(_){} });
          } else {
            try{ if(scanner){ try{ scanner.clear(); }catch(_){ } scanner=null; } }catch(_){ }
            scanning = false;
          }
        }
        function listCams(){
          if (!camSelect) return;
          camSelect.innerHTML = '';
          if (typeof Html5Qrcode === 'undefined' || !Html5Qrcode.getCameras) return;
          var p = null;
          try { if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) { p = navigator.mediaDevices.getUserMedia({ video: true }); } } catch(_){ }
          (p ? p.then(function(){ return Html5Qrcode.getCameras(); }) : Html5Qrcode.getCameras()).then(function(cams){
            camsCache = cams || [];
            camsCache.forEach(function(c, idx){
              var opt = document.createElement('option');
              opt.value = c.id;
              opt.textContent = c.label || ('Camera '+(idx+1));
              camSelect.appendChild(opt);
            });
            // Restore previously used camera if it still exists; otherwise prefer a back/environment camera, then first
            var saved = '';
            try { saved = localStorage.getItem('rs_camera') || ''; } catch(_){ saved = ''; }
            var selectedId = '';
            if (saved) {
              try {
                var exists = camsCache.some(function(c){ return c.id === saved; });
                if (exists) { selectedId = saved; }
              } catch(_){ }
            }
            if (!selectedId && camsCache.length > 0) {
              var pref = null;
              try { pref = camsCache.find(function(c){ return /back|rear|environment/i.test(String(c.label||'')); }); } catch(_){ }
              if (pref && pref.id) { selectedId = pref.id; }
              else { selectedId = camsCache[0].id; }
            }
            if (selectedId) { camSelect.value = selectedId; }
          }).catch(function(){ /* ignore */ });
        }
        function startWithSelected(){
          if (scanning || typeof Html5Qrcode==='undefined') return;
          setStatus('Starting camera...','text-info');
          try{
            try{ if (scanner) { try{ scanner.clear(); }catch(_){ } scanner = null; } }catch(_){ }
            scanner=new Html5Qrcode('rsReader');
            var id = (camSelect && camSelect.value) ? camSelect.value : (camsCache[0] && camsCache[0].id);
            if (!id) { setStatus('No camera found','text-danger'); return; }
            try { localStorage.setItem('rs_camera', id); } catch(_){ }
            var cfg = {fps:10,qrbox:{width:250,height:250}};
            function applyVideoTweaks(){
              var v=null; try{ v=document.querySelector('#rsReader video'); if(v){ v.setAttribute('playsinline',''); v.setAttribute('webkit-playsinline',''); v.muted=true; } }catch(_){ }
            }
            function markStarted(){
              scanning=true;
              if(startBtn) startBtn.style.display='none';
              if(stopBtn) stopBtn.style.display='inline-block';
              applyVideoTweaks();
              setStatus('Camera active. Scan a QR.','text-success');
            }
            scanner.start(id, cfg, onScanSuccess, ()=>{})
              .then(function(){ markStarted(); })
              .catch(function(err){
                // Fallback: try environment-facing camera constraints when device id fails
                scanner.start({ facingMode: { exact: 'environment' } }, cfg, onScanSuccess, ()=>{})
                  .then(function(){ markStarted(); })
                  .catch(function(){
                    scanner.start({ facingMode: 'environment' }, cfg, onScanSuccess, ()=>{})
                      .then(function(){ markStarted(); })
                      .catch(function(e2){ setStatus('Camera error: '+((e2&&e2.message)||'start failure'),'text-danger'); });
                  });
              });
          } catch(e){ setStatus('Scanner init failed','text-danger'); }
        }
        function validateCurrentId(){
          var rid = (reqIdEl && reqIdEl.value) ? reqIdEl.value : '';
          var sn = (inputId && inputId.value) ? inputId.value.trim() : '';
          if (!rid || !sn) { if(returnBtn) returnBtn.disabled = true; return; }
          setStatus('Validating Serial ID...','text-info');
          var body = 'request_id='+encodeURIComponent(rid)+'&serial_no='+encodeURIComponent(sn);
          fetch('admin_borrow_center.php?action=validate_return_id', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
            .then(r=>r.json()).then(function(resp){
              if (resp && resp.ok){ setStatus('Valid: '+(resp.model||'')+' | '+(resp.category||''),'text-success'); if(returnBtn) returnBtn.disabled = false; }
              else { setStatus(resp && resp.reason ? resp.reason : 'Invalid Serial ID.','text-danger'); if(returnBtn) returnBtn.disabled = true; }
            }).catch(function(){ setStatus('Validation error','text-danger'); if(returnBtn) returnBtn.disabled = true; });
        }
        function parseFlexiblePayload(txt){
          try { var o = JSON.parse(txt); if (o && typeof o==='object') return o; } catch(_e) {}
          try {
            var s = txt.trim();
            var qs = '';
            if (/^https?:\/\//i.test(s)) { var u = new URL(s); qs = u.search || ''; }
            else if (s.includes('=') && (s.includes('&') || s.includes('=') )) { qs = s; }
            if (qs) {
              if (qs.startsWith('?')) qs = qs.slice(1);
              var usp = new URLSearchParams(qs);
              var obj = {}; usp.forEach((v,k)=>{ obj[k]=v; });
              if (Object.keys(obj).length) return obj;
            }
          } catch(_e) {}
          try { var obj2 = {}; var parts = txt.split(/[\,\n]+/); parts.forEach(function(p){ var kv = p.split(/[:=]/); if (kv.length>=2){ var k=kv[0].trim(); var v=kv.slice(1).join(':').trim(); if(k) obj2[k]=v; }}); if (Object.keys(obj2).length) return obj2; } catch(_e) {}
          if (/^\s*[\w\-]+\s*$/.test(txt)) { return { serial_no: txt.trim() }; }
          return null;
        }
        function onScanSuccess(txt){
          var data = parseFlexiblePayload(txt);
          if (!data) { setStatus('Invalid QR format','text-danger'); return; }
          var serial = (data.serial_no || data.serial || data.sn || data.s || data.sid || '').toString().trim();
          var mdl = (data.model || data.item_name || data.name || '');
          var cat = (data.category || data.cat || '');
          if (serial){ if(inputId) inputId.value = String(serial); setStatus('Scanned Serial: '+serial+(mdl||cat?(' ('+[mdl,cat].filter(Boolean).join(' | ')+')'):'') ,'text-success'); stop(); validateCurrentId(); }
          else { setStatus('QR missing serial_no','text-danger'); }
        }
        if (mdl) {
          mdl.addEventListener('show.bs.modal', function(e){
            var btn=e.relatedTarget; var rid=btn?.getAttribute('data-reqid')||''; var mdlName=btn?.getAttribute('data-model_name')||'';
            if (reqIdEl) reqIdEl.value=rid;
            if (reqDisp) reqDisp.textContent=rid;
            if (modelDisp) modelDisp.textContent=mdlName||'-';
            if (inputId) inputId.value='';
            if (returnBtn) returnBtn.disabled = true;
            setStatus('Scan the item\'s QR or enter Serial ID manually.','text-muted');
            if (readerDiv) readerDiv.innerHTML='';
            if (camWrap) camWrap.style.display = 'block';
            listCams();
            if (startBtn) startBtn.disabled = false;
            if (stopBtn) stopBtn.disabled = false;
          });
        }
        if (startBtn) startBtn.addEventListener('click', function(){ startWithSelected(); });
        if (stopBtn) stopBtn.addEventListener('click', function(){ stop(); });
        if (form) form.addEventListener('submit', function(){ stop(); });
        if (inputId) inputId.addEventListener('input', function(){ if(returnBtn) returnBtn.disabled = true; if (this.value.trim()) { validateCurrentId(); } else { setStatus('Scan the item\'s QR or enter Serial ID manually.','text-muted'); } });
        if (camSelect) camSelect.addEventListener('change', function(){
          localStorage.setItem('rs_camera', this.value);
          if (scanning) { stop(); setTimeout(startWithSelected, 100); }
        });
        if (refreshBtn) refreshBtn.addEventListener('click', function(){ stop(); if (readerDiv) readerDiv.innerHTML=''; listCams(); setTimeout(startWithSelected, 150); });
        if (imgInput) imgInput.addEventListener('change', function(){ var f=this.files&&this.files[0]; if(!f){return;} stop(); setStatus('Processing image...','text-info');
          function tryScanSequence(file){
            if (typeof Html5Qrcode !== 'undefined' && typeof Html5Qrcode.scanFile === 'function') {
              return Html5Qrcode.scanFile(file, true)
                .catch(function(){ return Html5Qrcode.scanFile(file, false); })
                .catch(function(){ var inst = new Html5Qrcode('rsReader'); return inst.scanFile(file, true).finally(function(){ try{inst.clear();}catch(e){} }); });
            }
            var inst2 = new Html5Qrcode('rsReader');
            return inst2.scanFile(file, true).finally(function(){ try{inst2.clear();}catch(e){} });
          }
          tryScanSequence(f).then(function(txt){ onScanSuccess(txt); setStatus('QR scanned from image.','text-success'); })
            .catch(function(){ setStatus('No QR found in image.','text-danger'); });
        });

        // Fallback: if data-API fails, open programmatically on click
        document.addEventListener('click', function(ev){
          var btn = ev.target.closest('[data-bs-target="#returnScanModal"]');
          if (!btn) return;
          try {
            var el = q('returnScanModal');
            if (!el) return;
            var rid = btn.getAttribute('data-reqid') || '';
            var mdlName = btn.getAttribute('data-model_name') || '';
            if (reqIdEl) reqIdEl.value = rid;
            if (reqDisp) reqDisp.textContent = rid;
            if (modelDisp) modelDisp.textContent = mdlName || '-';
            if (inputId) inputId.value = '';
            if (returnBtn) returnBtn.disabled = true;
            setStatus('Scan the item\'s QR or enter Serial ID manually.','text-muted');
            if (readerDiv) readerDiv.innerHTML='';
            if (window.bootstrap && bootstrap.Modal) { var inst = bootstrap.Modal.getOrCreateInstance(el); inst.show(); }
          } catch(_e) {}
        });
      });
    })();
  </script>

      <?php if (isset($_GET['approved']) || isset($_GET['rejected']) || isset($_GET['insufficient']) || !empty($_GET['error'])): ?>
        <script>
          window.__AB_FLASH = window.__AB_FLASH || {};
          <?php if (isset($_GET['approved'])): ?>
          window.__AB_FLASH.approved = true;
          <?php endif; ?>
          <?php if (isset($_GET['rejected'])): ?>
          window.__AB_FLASH.rejected = true;
          <?php endif; ?>
          <?php if (isset($_GET['insufficient'])): ?>
          window.__AB_FLASH.insufficient = true;
          <?php endif; ?>
          <?php if (!empty($_GET['error'])): $err=trim($_GET['error']); ?>
          window.__AB_FLASH.error = <?php
            $msg = '';
            if ($err==='preferred_unavailable') $msg = 'The specified Model ID is not available.';
            elseif ($err==='item_mismatch') $msg = 'The scanned/typed item does not match the requested model and category. Please scan the correct item.';
            elseif ($err==='pick') $msg = 'Failed to lock items for this request. Try again.';
            elseif ($err==='stale') $msg = 'Update conflict. Refresh and retry.';
            elseif ($err==='tx') $msg = 'Transaction failed. Please retry.';
            elseif ($err==='time_required') $msg = 'Please provide valid time values. For Immediate, set Expected Return. For Reservation, set a future Start and End.';
            elseif ($err==='reservation_conflict') $msg = 'Expected return exceeds the cutoff due to an upcoming reservation. Please set an earlier return time.';
            else $msg = 'Action failed.';
            echo json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          ?>;
          <?php endif; ?>
        </script>
      <?php endif; ?>

      

      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="input-group input-group-sm" style="max-width: 480px; width: 100%;">
          <span class="input-group-text"><i class="bi bi-funnel"></i>&nbsp;View</span>
          <select id="brViewSelect" class="form-select" onchange="window.__brApply && window.__brApply(this.value)">
            <option value="pending" selected>Pending Requests</option>
            <option value="borrowed">Borrowed Items</option>
            <option value="reservations">Approved Reservations</option>
          </select>
        </div>
      </div>
      <style>
        /* Center the top Actions dropdown on small screens */
        @media (max-width: 768px) {
          #bcActionsDropdown + .actions-menu {
            position: fixed !important;
            top: 12% !important;
            left: 50% !important;
            transform: translateX(-50%) !important;
            width: min(320px, 92vw) !important;
            max-height: 70vh !important;
            overflow: auto !important;
            z-index: 1080 !important;
          }
        }
        /* Initial state: show only Pending until JS applies selection */
        #returned-list,
        #borrowed-col,
        #reservations-col { display: none; }
        #pending-col { display: block; }
        /* Make the visible table area wider and consistent */
        #pb-row, #returned-list { --bs-gutter-x: 0; margin-left: 0; margin-right: 0; }
        #pb-row .card,
        #returned-list .card { width: 100%; }
        #pb-row .table,
        #returned-list .table { width: 100%; table-layout: auto; font-size: 0.95rem; }
        #pb-row .table th, #pb-row .table td,
        #returned-list .table th, #returned-list .table td { padding: 0.5rem 0.75rem; line-height: 1.25rem; vertical-align: middle; white-space: normal; overflow: visible; text-overflow: clip; }
        /* Reduce side paddings on the single visible column so the table uses the full page width */
        #pb-row > .col-12,
        #returned-list > .col-12 { padding-left: 0 !important; padding-right: 0 !important; }
        #pb-row > [class^="col-"], #pb-row > [class*=" col-"],
        #returned-list > [class^="col-"], #returned-list > [class*=" col-"] { padding-left: 0 !important; padding-right: 0 !important; }
        /* Consistent scrollable area height for all lists */
        .list-scroll { max-height: 70vh; overflow: auto; }
        /* Mobile tweak: fit Borrowable List without horizontal scroll */
        @media (max-width: 576px) {
          #bm-table-wrap { overflow-x: hidden !important; overflow-y: visible !important; }
          #bm-table-wrap table { min-width: 100%; font-size: 0.82rem; }
          #bm-table-wrap table th, #bm-table-wrap table td { padding: 0.25rem 0.4rem; white-space: normal; }
          #bm-table-wrap .dropdown-toggle { padding: 0.1rem 0.3rem; font-size: 0.8rem; line-height: 1; }
        }
      </style>
      <div id="pb-row" class="row g-3">
        <div id="pending-col" class="col-12 col-lg-6">
          <div id="pending-list" class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
              <div>
                <strong>Pending Requests</strong>
              </div>
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="input-group input-group-sm search-pill" style="max-width:260px;">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
		          <input type="search" id="pendingSearch" class="form-control" placeholder="Search Req ID or item" />
                </div>
              </div>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive list-scroll">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Req ID</th>
                      <th>Type</th>
                      <th>User</th>
                      <th>School ID</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody id="pendingTbody">
                    <?php if (empty($pending)): ?>
                      <tr><td colspan="5" class="text-center text-muted">No pending requests.</td></tr>
                    <?php else: ?>
                      <?php foreach ($pending as $r): ?>
                      <tr class="pending-row" role="button" tabindex="0"
                          data-user="<?php echo htmlspecialchars($r['user_full_name'] ?? $r['username']); ?>"
                          data-reqid="<?php echo (int)$r['id']; ?>"
                          data-student="<?php echo htmlspecialchars((string)($r['student_school_id'] ?? ($r['student_id'] ?? ($r['school_id'] ?? '')))); ?>"
                          data-item="<?php echo htmlspecialchars($r['item_name']); ?>"
                          data-qty="<?php echo isset($r['remaining']) ? (int)$r['remaining'] : (int)$r['quantity']; ?>"
                          data-loc="<?php echo htmlspecialchars((string)($r['request_location'] ?? ''), ENT_QUOTES); ?>"
                            data-details="<?php echo htmlspecialchars((string)$r['details'] ?? '', ENT_QUOTES); ?>"
                            data-avail="<?php echo (int)$r['available_count']; ?>"
                            data-requested="<?php echo htmlspecialchars(date('h:i A m-d-y', strtotime($r['created_at']))); ?>"
                            data-reqtype="<?php echo htmlspecialchars((string)($r['type'] ?? 'immediate')); ?>">
                          <td><?php echo (int)$r['id']; ?></td>
                          <td><?php $isQrType = (isset($r['qr_serial_no']) && trim((string)$r['qr_serial_no']) !== ''); ?><span class="badge <?php echo $isQrType ? 'badge-qr' : 'badge-manual'; ?>"><?php echo $isQrType ? 'QR' : 'Manual'; ?></span></td>
                          <td><?php echo htmlspecialchars($r['user_full_name'] ?? $r['username']); ?></td>
                          <td><!-- Student ID (filled by JS) --></td>
                          <td class="text-end">
                          <div class="btn-group btn-group-sm segmented-actions" role="group" aria-label="Actions">
                              <button type="button" class="btn btn-sm btn-success border border-dark rounded-start py-1 px-1 lh-1 fs-6" title="Approve/Scan" aria-label="Approve/Scan" data-bs-toggle="modal" data-bs-target="#approveScanModal" data-reqid="<?php echo (int)$r['id']; ?>" data-item="<?php echo htmlspecialchars($r['item_name']); ?>" data-qty="<?php echo isset($r['remaining']) ? (int)$r['remaining'] : (int)$r['quantity']; ?>" data-reqtype="<?php echo htmlspecialchars((string)($r['type'] ?? 'immediate')); ?>" data-expected_return_at="<?php echo htmlspecialchars((string)($r['expected_return_at'] ?? '')); ?>" data-reserved_from="<?php echo htmlspecialchars((string)($r['reserved_from'] ?? '')); ?>" data-reserved_to="<?php echo htmlspecialchars((string)($r['reserved_to'] ?? '')); ?>" data-qr_serial="<?php echo htmlspecialchars((string)($r['qr_serial_no'] ?? '')); ?>">
                                <?php $isQr = isset($r['qr_serial_no']) && trim((string)$r['qr_serial_no']) !== ''; ?>
                                <i class="bi <?php echo $isQr ? 'bi-check2-circle' : 'bi-qr-code-scan'; ?>"></i>
                              </button>
                              <button type="button" class="btn btn-sm btn-danger border border-dark rounded-end py-1 px-1 lh-1 fs-6 reject-btn" title="Reject" aria-label="Reject"
                                      data-reject-id="<?php echo (int)$r['id']; ?>"
                                      data-reject-item="<?php echo htmlspecialchars($r['item_name']); ?>"
                                      data-reject-user="<?php echo htmlspecialchars($r['user_full_name'] ?? $r['username']); ?>">
                                <i class="bi bi-x"></i>
                              </button>
                          </div>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <style>
                #bm-table-wrap .dropdown-menu{ max-height:60vh; overflow:auto; }
              </style>
              <script>
                (function(){
                  var floating = null; // track floating menu state on mobile
                  function isMobile(){ return window.innerWidth <= 768; }
                  function positionFloating(menu, btnRect){
                    var gap = 8;
                    var vw = window.innerWidth, vh = window.innerHeight;
                    // Prefer below, else above
                    var top = btnRect.bottom + gap;
                    var maxHeight = Math.floor(vh * 0.7);
                    var willOverflowBottom = top + menu.offsetHeight > vh;
                    if (willOverflowBottom) {
                      var aboveTop = Math.max(8, btnRect.top - gap - Math.min(maxHeight, menu.offsetHeight));
                      top = aboveTop;
                    }
                    var left = Math.min(Math.max(8, btnRect.right - menu.offsetWidth), vw - menu.offsetWidth - 8);
                    if (left < 8) left = 8;
                    menu.style.position = 'fixed';
                    menu.style.top = top + 'px';
                    menu.style.left = left + 'px';
                    menu.style.right = 'auto';
                    menu.style.bottom = 'auto';
                    menu.style.transform = 'none';
                    menu.style.zIndex = '1090';
                    menu.style.maxHeight = Math.floor(vh * 0.7) + 'px';
                    menu.style.overflow = 'auto';
                  }
                  document.addEventListener('show.bs.dropdown', function(ev){
                    try {
                      var btn = ev.target;
                      if (!btn || !btn.closest) return;
                      var wrap = btn.closest('#bm-table-wrap');
                      if (!wrap) return; // only handle borrowable list table
                      var dropdown = btn.closest('.dropdown');
                      if (!dropdown) return;
                      var menu = dropdown.querySelector('.dropdown-menu');
                      if (!menu) return;
                      // Desktop/tablet: keep Popper behavior inside scroll parent
                      if (!isMobile()) {
                        dropdown.classList.remove('dropup','dropstart');
                        return;
                      }
                      // Mobile: make the menu a floating, fixed overlay attached to body
                      var rect = btn.getBoundingClientRect();
                      var placeholder = document.createElement('span'); placeholder.style.display='none';
                      menu.__origParent = menu.parentNode;
                      menu.__placeholder = placeholder;
                      menu.parentNode.insertBefore(placeholder, menu);
                      document.body.appendChild(menu);
                      menu.classList.add('show'); // ensure visible when reparented
                      positionFloating(menu, rect);
                      function onWinChange(){ try { positionFloating(menu, btn.getBoundingClientRect()); } catch(_){} }
                      window.addEventListener('scroll', onWinChange, true);
                      window.addEventListener('resize', onWinChange);
                      floating = { menu: menu, onWinChange: onWinChange };
                    } catch(_){ }
                  });
                  document.addEventListener('hide.bs.dropdown', function(){
                    try {
                      if (floating && floating.menu){
                        var m = floating.menu;
                        // restore to original parent/position
                        if (m.__origParent && m.__placeholder && m.__placeholder.parentNode){
                          m.__origParent.insertBefore(m, m.__placeholder);
                          m.__placeholder.remove();
                        }
                        // cleanup styles
                        m.removeAttribute('style');
                        m.classList.remove('show');
                        window.removeEventListener('scroll', floating.onWinChange, true);
                        window.removeEventListener('resize', floating.onWinChange);
                      }
                    } catch(_){ }
                    floating = null;
                  });
                })();
              </script>
            </div>
          </div>
        </div>
        <script>
          (function(){
            document.addEventListener('DOMContentLoaded', function(){
              var tbody = document.getElementById('pendingTbody');
              if (!tbody) return;

              var detailsModal = null;
              var mdlEl = document.getElementById('requestDetailsModal');
              if (mdlEl && window.bootstrap && bootstrap.Modal) {
                detailsModal = bootstrap.Modal.getOrCreateInstance(mdlEl);
              }

              var rejectModal = null;
              var rEl = document.getElementById('rejectConfirmModal');
              if (rEl && window.bootstrap && bootstrap.Modal) {
                rejectModal = bootstrap.Modal.getOrCreateInstance(rEl);
              }
              var rejReq = document.getElementById('rejReqId');
              var rejUser = document.getElementById('rejUser');
              var rejItem = document.getElementById('rejItem');
              var rejBtn = document.getElementById('rejConfirmBtn');
              var pendingRejectId = '';

              function shouldIgnoreTarget(target){
                if (!target || !target.closest) return false;
                // Do not open details when clicking inside the segmented action buttons (Approve/Reject)
                return !!target.closest('.segmented-actions');
              }

              tbody.addEventListener('click', function(e){
                var t = e.target;
                if (t && t.closest) {
                  var rbtn = t.closest('.reject-btn');
                  if (rbtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    pendingRejectId = rbtn.getAttribute('data-reject-id') || '';
                    var u = rbtn.getAttribute('data-reject-user') || '';
                    var it = rbtn.getAttribute('data-reject-item') || '';
                    if (rejReq) rejReq.textContent = pendingRejectId;
                    if (rejUser) rejUser.textContent = u;
                    if (rejItem) rejItem.textContent = it;
                    if (rejectModal) {
                      rejectModal.show();
                    } else if (pendingRejectId) {
                      // Fallback if Bootstrap modal is unavailable
                      if (window.confirm('Reject this request?')) {
                        window.location.href = 'admin_borrow_center.php?action=reject&id=' + encodeURIComponent(pendingRejectId);
                      }
                    }
                    return;
                  }
                }

                var row = t && t.closest && t.closest('tr.pending-row');
                if (!row) return;
                if (shouldIgnoreTarget(t)) return;
                if (detailsModal) {
                  detailsModal.show(row);
                }
              });

              if (rejBtn) {
                rejBtn.addEventListener('click', function(){
                  if (!pendingRejectId) return;
                  window.location.href = 'admin_borrow_center.php?action=reject&id=' + encodeURIComponent(pendingRejectId);
                });
              }

              // Keyboard support: Enter/Space on a focused pending row opens details
              tbody.addEventListener('keydown', function(e){
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var row = e.target && e.target.closest && e.target.closest('tr.pending-row');
                if (!row) return;
                if (shouldIgnoreTarget(e.target)) return;
                e.preventDefault();
                if (detailsModal) {
                  detailsModal.show(row);
                }
              });
            });
          })();
        </script>

        

        <div id="borrowed-col" class="col-12 col-lg-6">
          <div id="borrowed-list" class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
              <div>
                <strong>Borrowed Items</strong>
              </div>
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="input-group input-group-sm search-pill" style="max-width:260px;">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
		          <input type="search" id="borrowedSearch" class="form-control" placeholder="Search Req ID or model" />
                </div>
              </div>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive list-scroll">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Req ID</th>
                      <th>Type</th>
                      <th>User</th>
                      <th>School ID</th>
                      <th>Expected Return</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody id="borrowedTbody">
                    <?php if (empty($borrowed)): ?>
                      <tr><td colspan="6" class="text-center text-muted">No active borrowed items.</td></tr>
                    <?php else: ?>
                      <?php foreach ($borrowed as $b): ?>
                        <?php $rawExp = (string)($b['expected_return_at'] ?? ($b['reserved_to'] ?? '')); $isOverdue = ($rawExp !== '' && strtotime($rawExp) && strtotime($rawExp) < time()); ?>
                        <tr class="borrowed-row" role="button" tabindex="0"
                            data-bs-toggle="modal" data-bs-target="#borrowedDetailsModal"
                            data-user="<?php echo htmlspecialchars($b['username']); ?>"
                            data-reqid="<?php echo (int)$b['request_id']; ?>"
                            data-student="<?php echo htmlspecialchars((string)($b['student_school_id'] ?? ($b['student_id'] ?? ($b['school_id'] ?? '')))); ?>"
                            data-serial="<?php echo htmlspecialchars((string)($b['serial_no'] ?? '')); ?>"
                            data-model="<?php echo htmlspecialchars($b['model']); ?>"
                            data-category="<?php echo htmlspecialchars($b['category']); ?>"
                            data-location="<?php echo htmlspecialchars((string)($b['location'] ?? '')); ?>"
                            data-expected_raw="<?php echo htmlspecialchars($rawExp); ?>"
                            data-overdue="<?php echo $isOverdue ? '1' : '0'; ?>">
                          <td><?php echo (int)$b['request_id']; ?></td>
                          <td><?php $ty = (isset($b['type']) && trim((string)$b['type'])!=='' ? (string)$b['type'] : ((isset($b['qr_serial_no']) && trim((string)$b['qr_serial_no'])!=='') ? 'QR' : 'Manual')); $isQrTy = (strcasecmp($ty,'QR')===0); ?><span class="badge <?php echo $isQrTy ? 'badge-qr' : 'badge-manual'; ?>"><?php echo htmlspecialchars($ty); ?></span></td>
                          <td><?php echo htmlspecialchars($b['username']); ?></td>
                          <td><!-- Student ID (filled by JS) --></td>
                          <td><?php 
                            $disp = $rawExp !== '' ? date('h:i A m-d-y', strtotime($rawExp)) : '-';
                            echo htmlspecialchars($disp);
                            if ($isOverdue) { echo ' <span class="text-danger" title="Overdue"><i class="bi bi-exclamation-circle-fill"></i></span>'; }
                          ?></td>
                          <td class="text-end">
                            <div class="btn-group btn-group-sm segmented-actions" role="group" aria-label="Borrowed Actions">
                              <button type="button" class="btn btn-sm btn-light border border-dark rounded-start py-1 px-1 lh-1 fs-6" title="Return/Scan" aria-label="Return/Scan" data-bs-toggle="modal" data-bs-target="#returnScanModal" data-reqid="<?php echo (int)$b['request_id']; ?>" data-model_name="<?php echo htmlspecialchars($b['model']); ?>" data-serial="<?php echo htmlspecialchars((string)($b['serial_no'] ?? '')); ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
                              <button type="button" class="btn btn-sm btn-danger border border-dark rounded-0 py-1 px-1 lh-1 fs-6" title="Lost" aria-label="Lost" data-bs-toggle="modal" data-bs-target="#markLostModal" data-reqid="<?php echo (int)$b['request_id']; ?>" data-model_id="<?php echo (int)$b['model_id']; ?>" data-model_name="<?php echo htmlspecialchars($b['model']); ?>" data-serial="<?php echo htmlspecialchars((string)($b['serial_no'] ?? '')); ?>"><i class="bi bi-exclamation-triangle"></i></button>
                              <button type="button" class="btn btn-sm btn-warning text-dark border border-dark rounded-end py-1 px-1 lh-1 fs-6" title="Maintenance" aria-label="Maintenance" data-bs-toggle="modal" data-bs-target="#markMaintModal" data-reqid="<?php echo (int)$b['request_id']; ?>" data-model_id="<?php echo (int)$b['model_id']; ?>" data-model_name="<?php echo htmlspecialchars($b['model']); ?>" data-serial="<?php echo htmlspecialchars((string)($b['serial_no'] ?? '')); ?>"><i class="bi bi-tools"></i></button>
                            </div>
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

      <div id="lost-damaged"></div>

      <!-- Lost/Damaged Item List Modal -->
      <div class="modal fade" id="lostDamagedListModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width:95vw; width:95vw; max-height:95vh; height:95vh;">
          <div class="modal-content" style="height:100%;">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Lost/Damaged Item List</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="height:calc(95vh - 120px); overflow:auto;">
              <div class="row g-3">
                <div class="col-12 col-xl-6">
                  <div class="card border-0">
                    <div class="card-header bg-white"><strong>Lost Item List</strong></div>
                    <div class="card-body p-0">
                      <div class="table-responsive" style="max-height:80vh; overflow:auto;">
                        <table class="table table-sm table-striped align-middle mb-0">
                          <thead class="table-light">
                            <tr>
                              <th>Serial ID</th>
                              <th>Model</th>
                              <th>User</th>
                              <th>School ID</th>
                              <th class="text-end">Actions</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php if (empty($lostItems)): ?>
                              <tr><td colspan="5" class="text-center text-muted">No items currently marked as Lost.</td></tr>
                            <?php else: foreach ($lostItems as $li): ?>
                              <tr class="lost-row"
                                  data-serial="<?php echo htmlspecialchars((string)($li['serial_no'] ?? '')); ?>"
                                  data-model="<?php echo htmlspecialchars($li['model_key']); ?>"
                                  data-category="<?php echo htmlspecialchars($li['category']); ?>"
                                  data-location="<?php echo htmlspecialchars((string)($li['location'] ?? '')); ?>"
                                  data-marked_by="<?php echo htmlspecialchars($li['marked_by'] ?: '-'); ?>"
                                  data-student_id="<?php echo htmlspecialchars((string)($li['student_school_id'] ?? '')); ?>"
                                  data-marked_at="<?php echo htmlspecialchars($li['marked_at'] ? date('Y-m-d H:i:s', strtotime($li['marked_at'])) : ''); ?>"
                                  data-remarks="<?php echo htmlspecialchars((string)($li['notes'] ?? '')); ?>">
                                <td><?php echo htmlspecialchars((string)($li['serial_no'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($li['model_key']); ?></td>
                                <td><?php echo htmlspecialchars((string)($li['user_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($li['student_school_id'] ?? '')); ?></td>
                                <td class="text-end">
                                  <button type="button"
                                          class="btn btn-sm btn-success border border-dark py-1 px-1 lh-1 btn-lostdam-mark"
                                          data-bs-toggle="modal"
                                          data-bs-target="#confirmFoundFixedModal"
                                          data-action="found"
                                          data-model_id="<?php echo (int)$li['model_id']; ?>"
                                          title="Mark Found" aria-label="Mark Found">
                                    <i class="bi bi-check2-circle"></i>
                                  </button>
                                  <button type="button"
                                          class="btn btn-sm btn-danger border border-dark py-1 px-1 lh-1 ms-1 ld-confirm-btn"
                                          data-action_type="permanent_lost"
                                          data-model_id="<?php echo (int)$li['model_id']; ?>"
                                          title="Permanently Lost" aria-label="Permanently Lost">
                                    <i class="bi bi-x-octagon"></i>
                                  </button>
                                </td>
                              </tr>
                            <?php endforeach; endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-12 col-xl-6">
                  <div class="card border-0">
                    <div class="card-header bg-white"><strong>Damaged Item List</strong></div>
                    <div class="card-body p-0">
                      <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                          <thead class="table-light">
                            <tr>
                              <th>Serial ID</th>
                              <th>Model</th>
                              <th>User</th>
                              <th>School ID</th>
                              <th class="text-end">Actions</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php if (empty($damagedItems)): ?>
                              <tr><td colspan="5" class="text-center text-muted">No items currently under maintenance.</td></tr>
                            <?php else: foreach ($damagedItems as $di): ?>
                              <tr class="damaged-row"
                                  data-serial="<?php echo htmlspecialchars((string)($di['serial_no'] ?? '')); ?>"
                                  data-model="<?php echo htmlspecialchars($di['model_key']); ?>"
                                  data-category="<?php echo htmlspecialchars($di['category']); ?>"
                                  data-location="<?php echo htmlspecialchars((string)($di['location'] ?? '')); ?>"
                                  data-marked_by="<?php echo htmlspecialchars($di['marked_by'] ?: '-'); ?>"
                                  data-student_id="<?php echo htmlspecialchars((string)($di['student_school_id'] ?? '')); ?>"
                                  data-marked_at="<?php echo htmlspecialchars($di['marked_at'] ? date('Y-m-d H:i:s', strtotime($di['marked_at'])) : ''); ?>"
                                  data-remarks="<?php echo htmlspecialchars((string)($di['notes'] ?? '')); ?>">
                                <td><?php echo htmlspecialchars((string)($di['serial_no'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($di['model_key']); ?></td>
                                <td><?php echo htmlspecialchars((string)($di['user_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($di['student_school_id'] ?? '')); ?></td>
                                <td class="text-end">
                                  <button type="button"
                                          class="btn btn-sm btn-success border border-dark py-1 px-1 lh-1 btn-lostdam-mark"
                                          data-bs-toggle="modal"
                                          data-bs-target="#confirmFoundFixedModal"
                                          data-action="fixed"
                                          data-model_id="<?php echo (int)$di['model_id']; ?>"
                                          title="Mark Fixed" aria-label="Mark Fixed">
                                    <i class="bi bi-wrench-adjustable-circle"></i>
                                  </button>
                                  <button type="button"
                                          class="btn btn-sm btn-danger border border-dark py-1 px-1 lh-1 ms-1 ld-confirm-btn"
                                          data-action_type="dispose"
                                          data-model_id="<?php echo (int)$di['model_id']; ?>"
                                          title="Dispose" aria-label="Dispose">
                                    <i class="bi bi-trash3"></i>
                                  </button>
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
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Nested details modal for Lost/Damaged rows -->
      <div class="modal fade" id="lostDamagedDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Item Details</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div><strong>Category:</strong> <span id="lddCategory"></span></div>
              <div><strong>Location:</strong> <span id="lddLocation"></span></div>
              <div><strong>Marked By:</strong> <span id="lddMarkedBy"></span></div>
              <div><strong>Marked At:</strong> <span id="lddMarkedAt"></span></div>
              <div><strong>Remarks:</strong> <span id="lddRemarks"></span></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

      <script>
        (function(){
          function fillDetailsFromRow(tr){
            document.getElementById('lddCategory').textContent = tr.getAttribute('data-category')||'';
            document.getElementById('lddLocation').textContent = tr.getAttribute('data-location')||'';
            document.getElementById('lddMarkedBy').textContent = tr.getAttribute('data-marked_by')||'';
            document.getElementById('lddMarkedAt').textContent = tr.getAttribute('data-marked_at')||'';
            document.getElementById('lddRemarks').textContent = tr.getAttribute('data-remarks')||'';
          }
          document.addEventListener('DOMContentLoaded', function(){
            var parent = document.getElementById('lostDamagedListModal');
            if (!parent) return;
            parent.addEventListener('click', function(e){
              var t = e.target;
              if (t.closest('.text-end, .btn, button, a, form, input, select, textarea')) return; // ignore action cells/controls
              var row = t.closest('tr.lost-row, tr.damaged-row');
              if (!row) return;
              e.stopPropagation();
              var child = document.getElementById('lostDamagedDetailsModal');
              if (!child) return;
              fillDetailsFromRow(row);
              // keep parent open while child shows
              function preventHide(ev){ ev.preventDefault(); }
              parent.addEventListener('hide.bs.modal', preventHide);
              var inst = new bootstrap.Modal(child, {backdrop: 'static'});
              inst.show();
              function onHidden(){
                parent.removeEventListener('hide.bs.modal', preventHide);
                child.removeEventListener('hidden.bs.modal', onHidden);
                try { parent.focus(); } catch(_){ }
              }
              child.addEventListener('hidden.bs.modal', onHidden);
            });
          });
        })();
      </script>

      <!-- Confirm Mark Found/Fixed Modal -->
      <div class="modal fade" id="confirmFoundFixedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <form method="POST" action="admin_borrow_center.php" class="modal-content">
            <input type="hidden" name="model_id" id="cff_model_id" value="" />
            <input type="hidden" name="do" id="cff_do" value="" />
            <div class="modal-header">
              <h5 class="modal-title">Confirm <span id="cff_action_label">Found</span></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <p id="cff_message">Mark this item as FOUND and return it to Available?</p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Confirm</button>
            </div>
          </form>
        </div>
      </div>

      <script>
        (function(){
          document.addEventListener('DOMContentLoaded', function(){
            var parent = document.getElementById('lostDamagedListModal');
            var modalEl = document.getElementById('confirmFoundFixedModal');
            if (!parent || !modalEl || typeof bootstrap === 'undefined') return;
            var modelInput = document.getElementById('cff_model_id');
            var doInput = document.getElementById('cff_do');
            var labelSpan = document.getElementById('cff_action_label');
            var msgEl = document.getElementById('cff_message');
            parent.addEventListener('click', function(e){
              var btn = e.target.closest('.btn-lostdam-mark');
              if (!btn) return;
              e.preventDefault();
              var mid = btn.getAttribute('data-model_id') || '';
              var action = (btn.getAttribute('data-action') || '').toLowerCase();
              if (!mid || (action !== 'found' && action !== 'fixed')) return;
              if (modelInput) modelInput.value = mid;
              if (doInput) doInput.value = (action === 'found' ? 'mark_found' : 'mark_fixed');
              if (labelSpan) labelSpan.textContent = (action === 'found' ? 'Found' : 'Fixed');
              if (msgEl) {
                msgEl.textContent = (action === 'found'
                  ? 'Mark this item as FOUND and return it to Available?'
                  : 'Mark this item as FIXED and return it to Available?');
              }
              var inst = bootstrap.Modal.getOrCreateInstance(modalEl);
              inst.show();
            });
          });
        })();
      </script>

      <!-- Overdue Items Modal -->
      <div class="modal fade" id="overdueModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width:95vw; width:95vw; max-height:95vh; height:95vh;">
          <div class="modal-content" style="height:100%;">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-hourglass-split me-2"></i>Overdue Items</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="height:calc(95vh - 120px); overflow:auto;">
              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>Serial ID</th>
                      <th>Item</th>
                      <th>Location</th>
                      <th>Borrowed By</th>
                      <th>School ID</th>
                    </tr>
                  </thead>
                  <tbody id="overdueTbody">
                    <tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>
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
      
      <script>
        (function(){
          function gid(id){ return document.getElementById(id); }
          function esc(s){ return String(s==null?'':s); }
          function fmt(dt){ try{ if(!dt) return ''; var d=new Date(dt.replace(' ','T')); if(isNaN(d)) return esc(dt); var h=d.getHours()%12||12, m=('0'+d.getMinutes()).slice(-2), ap=d.getHours()<12?'AM':'PM'; var mo=('0'+(d.getMonth()+1)).slice(-2), da=('0'+d.getDate()).slice(-2), yr=d.getFullYear().toString().slice(-2); return h+':'+m+' '+ap+' '+mo+'-'+da+'-'+yr; }catch(e){ return esc(dt); } }
          function render(items){
            var tb = gid('overdueTbody'); if(!tb) return;
            if(!Array.isArray(items) || !items.length){ tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No overdue items.</td></tr>'; return; }
            var html = items.map(function(r){
              return '<tr class="overdue-row" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#overdueDetailsModal"'
                + ' data-approved_by="'+esc(r.approved_by)+'"'
                + ' data-remarks="'+esc(r.remarks)+'"'
                + ' data-due_at="'+esc(r.due_at)+'"'
                + '>'
                + '<td>'+esc(r.serial)+'</td>'
                + '<td>'+esc(r.model)+'</td>'
                + '<td>'+esc(r.location)+'</td>'
                + '<td>'+esc(r.borrowed_by)+'</td>'
                + '<td>'+esc(r.school_id||'')+'</td>'
              + '</tr>';
            }).join('');
            tb.innerHTML = html;
          }
          function load(){
            var tb = gid('overdueTbody'); if (tb) tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>';
            fetch('admin_borrow_center.php?action=overdue_json', {cache:'no-store'})
              .then(function(r){ return r.json(); })
              .then(function(j){ if(!j||j.ok!==true) throw new Error('bad'); render(j.items||[]); })
              .catch(function(){ if(tb) tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Failed to load.</td></tr>'; });
          }
          function init(){ var m = gid('overdueModal'); if(!m) return; m.addEventListener('show.bs.modal', load); }
          if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
        })();
      </script>

      <!-- Overdue Details Modal -->
      <div class="modal fade" id="overdueDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Overdue Details</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="mb-2"><strong>Approved By:</strong> <span id="odApprovedBy"></span></div>
              <div class="mb-2"><strong>Remarks:</strong> <span id="odRemarks"></span></div>
              <div class="mb-2"><strong>Due At:</strong> <span id="odDueAt"></span></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

      <script>
        (function(){
          function gid(id){ return document.getElementById(id); }
          function fmt(dt){ try{ if(!dt) return ''; var d=new Date(String(dt).replace(' ','T')); if(isNaN(d)) return String(dt); var h=d.getHours()%12||12, m=('0'+d.getMinutes()).slice(-2), ap=d.getHours()<12?'AM':'PM'; var mo=('0'+(d.getMonth()+1)).slice(-2), da=('0'+d.getDate()).slice(-2), yr=d.getFullYear(); return mo+'-'+da+'-'+yr+' '+h+':'+m+ap; }catch(e){ return String(dt); } }
          var mdl = document.getElementById('overdueDetailsModal');
          if (mdl) {
            mdl.addEventListener('show.bs.modal', function (event) {
              var trg = event.relatedTarget;
              var approved = trg ? (trg.getAttribute('data-approved_by')||'') : '';
              var remarks = trg ? (trg.getAttribute('data-remarks')||'') : '';
              var dueAt = trg ? (trg.getAttribute('data-due_at')||'') : '';
              var elA = gid('odApprovedBy'), elR = gid('odRemarks'), elD = gid('odDueAt');
              if (elA) elA.textContent = approved;
              if (elR) elR.textContent = remarks;
              if (elD) elD.textContent = fmt(dueAt);
            });
          }
        })();
      </script>

      <div class="row g-3 mt-1" id="returned-list">
        <div id="reservations-col" class="col-12 col-md-6">
          <div id="reservations-list" class="card border-0 shadow-sm mt-3 h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
              <strong>Approved Reservations</strong>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive list-scroll">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Req ID</th>
                      <th>User</th>
                      <th>School ID</th>
                      <th>Item</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody id="reservationsTbody"><tr><td colspan="5" class="text-center text-muted">No approved reservations.</td></tr></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mt-1 align-items-stretch">
        <div class="col-12 col-xl-7">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
              <strong>Borrowable List</strong>
            </div>
            <div class="card-body p-0">
              <div id="bm-table-wrap" class="table-responsive" style="overflow: visible;">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Category</th>
                      <th>Model</th>
                      <th>Quantity</th>
                      <th>Active</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($borrowables)): ?>
                      <tr><td colspan="5" class="text-center text-muted">No borrowable entries yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($borrowables as $bm): ?>
                        <?php
                          // Visibility rules:
                          // - Active entries: show only if computed remaining (show) > 0
                          // - Inactive (deleted) entries: show only if there are items currently in use
                          $c = (string)$bm['category']; $m = (string)$bm['model_name'];
                          $cs = $c; $ms = $m;
                          // Resolve quantity row (case-insensitive fallback like below)
                          $rowPre = null;
                          if (isset($qtyStats[$cs]) && isset($qtyStats[$cs][$ms])) { $rowPre = $qtyStats[$cs][$ms]; }
                          else {
                            $clp = null; $mlp = mb_strtolower($ms);
                            foreach ($qtyStats as $kc => $arr) { if (mb_strtolower($kc) === mb_strtolower($cs)) { $clp = $kc; break; } }
                            if ($clp !== null) {
                              if (isset($qtyStats[$clp][$ms])) { $rowPre = $qtyStats[$clp][$ms]; }
                              else { foreach ($qtyStats[$clp] as $km => $v) { if (mb_strtolower($km) === $mlp) { $rowPre = $v; break; } } }
                            }
                          }
                          // Borrow limit (capacity)
                          $curLimitPre = 0;
                          if (isset($borrowLimitMap[$cs]) && isset($borrowLimitMap[$cs][$ms])) { $curLimitPre = (int)$borrowLimitMap[$cs][$ms]; }
                          else {
                            $clp2 = null; $mlp2 = mb_strtolower($ms);
                            foreach ($borrowLimitMap as $kc2 => $arr2) { if (mb_strtolower($kc2) === mb_strtolower($cs)) { $clp2 = $kc2; break; } }
                            if ($clp2 !== null) {
                              if (isset($borrowLimitMap[$clp2][$ms])) { $curLimitPre = (int)$borrowLimitMap[$clp2][$ms]; }
                              else { foreach ($borrowLimitMap[$clp2] as $km2 => $v2) { if (mb_strtolower($km2) === $mlp2) { $curLimitPre = (int)$v2; break; } } }
                            }
                          }
                          // Available now and total
                          $availPre = $rowPre && isset($rowPre['available']) ? (int)$rowPre['available'] : 0;
                          $totalPre = $rowPre && isset($rowPre['total']) ? (int)$rowPre['total'] : 0;
                          if ($availPre < 0) $availPre = 0;
                          if ($totalPre < 0) $totalPre = 0;
                          // Compute consumed same as Quantity cell (active borrows + pending returns + held)
                          $consumedPre = 0;
                          if (isset($activeConsumed[$cs]) && isset($activeConsumed[$cs][$ms])) { $consumedPre += (int)$activeConsumed[$cs][$ms]; }
                          if (isset($pendingReturned[$cs]) && isset($pendingReturned[$cs][$ms])) { $consumedPre += (int)$pendingReturned[$cs][$ms]; }
                          if (isset($heldCounts[$cs]) && isset($heldCounts[$cs][$ms])) { $consumedPre += (int)$heldCounts[$cs][$ms]; }
                          if ($consumedPre === 0) {
                            $mlow = mb_strtolower($ms);
                            $cl3 = null; foreach ($activeConsumed as $kc3 => $arr3) { if (mb_strtolower($kc3) === mb_strtolower($cs)) { $cl3 = $kc3; break; } }
                            if ($cl3 !== null) { foreach (($activeConsumed[$cl3] ?? []) as $km3 => $v3) { if (mb_strtolower($km3) === $mlow) { $consumedPre += (int)$v3; break; } } }
                            $cl4 = null; foreach ($pendingReturned as $kc4 => $arr4) { if (mb_strtolower($kc4) === mb_strtolower($cs)) { $cl4 = $kc4; break; } }
                            if ($cl4 !== null) { foreach (($pendingReturned[$cl4] ?? []) as $km4 => $v4) { if (mb_strtolower($km4) === $mlow) { $consumedPre += (int)$v4; break; } } }
                            $cl5 = null; foreach ($heldCounts as $kc5 => $arr5) { if (mb_strtolower($kc5) === mb_strtolower($cs)) { $cl5 = $kc5; break; } }
                            if ($cl5 !== null) { foreach (($heldCounts[$cl5] ?? []) as $km5 => $v5) { if (mb_strtolower($km5) === $mlow) { $consumedPre += (int)$v5; break; } } }
                          }
                          // Remaining within borrowable capacity cannot exceed available
                          $showPre = max(0, min($curLimitPre - $consumedPre, $availPre));
                          // In-use count (active borrows only) for inactive visibility rule
                          $inUsePre = 0;
                          if (isset($activeConsumed[$cs]) && isset($activeConsumed[$cs][$ms])) { $inUsePre += (int)$activeConsumed[$cs][$ms]; }
                          else {
                            $clp3 = null; $mlp3 = mb_strtolower($ms);
                            foreach ($activeConsumed as $kc3 => $arr3) { if (mb_strtolower($kc3) === mb_strtolower($cs)) { $clp3 = $kc3; break; } }
                            if ($clp3 !== null) { foreach (($activeConsumed[$clp3] ?? []) as $km3 => $v3) { if (mb_strtolower($km3) === $mlp3) { $inUsePre += (int)$v3; break; } } }
                          }
                          $isActive = ((int)$bm['active'] === 1);
                          if ($isActive) {
                            // Active: do not remove groups that still have in-use items.
                            // Show if either remaining capacity is > 0 OR there are items currently in use.
                            if ($showPre <= 0 && $inUsePre <= 0) { continue; }
                          } else {
                            // Inactive/Deleted: show only if there are items currently in use.
                          }
                        ?>
                        <tr>
                          <td><?php echo htmlspecialchars($bm['category']); ?></td>
                          <td><?php echo htmlspecialchars($bm['model_name']); ?></td>
                          <td>
                            <?php 
                              $c = (string)$bm['category']; $m = (string)$bm['model_name'];
                              $cs = $c; $ms = $m;
                              $row = null;
                              if (isset($qtyStats[$cs]) && isset($qtyStats[$cs][$ms])) { $row = $qtyStats[$cs][$ms]; }
                              else {
                                $cl = null; $ml = mb_strtolower($ms);
                                foreach ($qtyStats as $kc => $arr) { if (mb_strtolower($kc) === mb_strtolower($cs)) { $cl = $kc; break; } }
                                if ($cl !== null) {
                                  if (isset($qtyStats[$cl][$ms])) { $row = $qtyStats[$cl][$ms]; }
                                  else {
                                    foreach ($qtyStats[$cl] as $km => $v) { if (mb_strtolower($km) === $ml) { $row = $v; break; } }
                                  }
                                }
                              }
                              // Show current borrowable capacity (borrow_limit), not current availability.
                              // This reflects how many units are in the borrowable list out of total existing units.
                              $curLimit = 0;
                              if (isset($borrowLimitMap[$cs]) && isset($borrowLimitMap[$cs][$ms])) {
                                $curLimit = (int)$borrowLimitMap[$cs][$ms];
                              } else {
                                // case-insensitive fallback
                                $cl2 = null; $ml2 = mb_strtolower($ms);
                                foreach ($borrowLimitMap as $kc2 => $arr2) { if (mb_strtolower($kc2) === mb_strtolower($cs)) { $cl2 = $kc2; break; } }
                                if ($cl2 !== null) {
                                  if (isset($borrowLimitMap[$cl2][$ms])) { $curLimit = (int)$borrowLimitMap[$cl2][$ms]; }
                                  else { foreach ($borrowLimitMap[$cl2] as $km2 => $v2) { if (mb_strtolower($km2) === $ml2) { $curLimit = (int)$v2; break; } } }
                                }
                              }
                              $avail = $row && isset($row['available']) ? (int)$row['available'] : 0;
                              $total = $row && isset($row['total']) ? (int)$row['total'] : 0;
                              if ($total < 0) { $total = 0; }
                              if ($curLimit < 0) { $curLimit = 0; }
                              if ($avail < 0) { $avail = 0; }
                              if ($curLimit > $total) { $curLimit = $total; }
                              if ($avail > $total) { $avail = $total; }
                              // Compute consumed: active borrows + pending returns + held
                              $consumed = 0;
                              // direct match
                              if (isset($activeConsumed[$cs]) && isset($activeConsumed[$cs][$ms])) { $consumed += (int)$activeConsumed[$cs][$ms]; }
                              if (isset($pendingReturned[$cs]) && isset($pendingReturned[$cs][$ms])) { $consumed += (int)$pendingReturned[$cs][$ms]; }
                              if (isset($heldCounts[$cs]) && isset($heldCounts[$cs][$ms])) { $consumed += (int)$heldCounts[$cs][$ms]; }
                              // case-insensitive fallbacks
                              if ($consumed === 0) {
                                $cl3 = null; $ml3 = mb_strtolower($ms);
                                foreach ($activeConsumed as $kc3 => $arr3) { if (mb_strtolower($kc3) === mb_strtolower($cs)) { $cl3 = $kc3; break; } }
                                if ($cl3 !== null) {
                                  foreach (($activeConsumed[$cl3] ?? []) as $km3 => $v3) { if (mb_strtolower($km3) === $ml3) { $consumed += (int)$v3; break; } }
                                }
                                $cl4 = null;
                                foreach ($pendingReturned as $kc4 => $arr4) { if (mb_strtolower($kc4) === mb_strtolower($cs)) { $cl4 = $kc4; break; } }
                                if ($cl4 !== null) {
                                  foreach (($pendingReturned[$cl4] ?? []) as $km4 => $v4) { if (mb_strtolower($km4) === $ml3) { $consumed += (int)$v4; break; } }
                                }
                                $cl5 = null;
                                foreach ($heldCounts as $kc5 => $arr5) { if (mb_strtolower($kc5) === mb_strtolower($cs)) { $cl5 = $kc5; break; } }
                                if ($cl5 !== null) {
                                  foreach (($heldCounts[$cl5] ?? []) as $km5 => $v5) { if (mb_strtolower($km5) === $ml3) { $consumed += (int)$v5; break; } }
                                }
                              }
                              // Remaining within borrowable capacity cannot exceed available
                              $wlAvail = $avail;
                              if (isset($wlBuckets) && isset($wlBuckets[$cs]) && isset($wlBuckets[$cs][$ms])) {
                                $wlAvail = (int)($wlBuckets[$cs][$ms]['avail'] ?? 0);
                              }
                              if ($wlAvail < 0) { $wlAvail = 0; }
                              if ($wlAvail > $avail) { $wlAvail = $avail; }
                              $show = max(0, min($curLimit - $consumed, $wlAvail));
                              echo htmlspecialchars($show.' / '.$total);
                            ?>
                          </td>
                          <td>
                            <span class="badge bg-<?php echo ((int)$bm['active']===1?'success':'secondary'); ?>"><?php echo ((int)$bm['active']===1?'Active':'Inactive'); ?></span>
                          </td>
                          <td class="text-end">
                            <div class="dropdown position-relative">
                              <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle py-0 px-1 lh-1" style="font-size:.85rem;" data-bs-toggle="dropdown" data-bs-boundary="scrollParent" data-bs-display="static" data-bs-reference="parent" data-bs-offset="0,6" aria-expanded="false">
                                <i class="bi bi-gear"></i>
                              </button>
                              <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                  <form method="POST" class="m-0">
                                    <input type="hidden" name="do" value="toggle_borrowable" />
                                    <input type="hidden" name="category" value="<?php echo htmlspecialchars($bm['category']); ?>" />
                                    <input type="hidden" name="model" value="<?php echo htmlspecialchars($bm['model_name']); ?>" />
                                    <input type="hidden" name="active" value="<?php echo ((int)$bm['active']===1?0:1); ?>" />
                                    <button type="submit" class="dropdown-item">
                                      <i class="bi <?php echo ((int)$bm['active']===1?'bi-eye-slash':'bi-eye'); ?> me-1"></i><?php echo ((int)$bm['active']===1?'Deactivate':'Activate'); ?>
                                    </button>
                                  </form>
                                </li>
                                <li>
                                  <button type="button" class="dropdown-item bm-view-units"
                                    data-category="<?php echo htmlspecialchars($bm['category']); ?>"
                                    data-model="<?php echo htmlspecialchars($bm['model_name']); ?>"
                                    data-bs-toggle="modal" data-bs-target="#bmViewUnitsModal">
                                    <i class="bi bi-list-ul me-1"></i>View
                                  </button>
                                </li>
                                <li>
                                  <button type="button" class="dropdown-item text-danger bm-delete-units"
                                    data-category="<?php echo htmlspecialchars($bm['category']); ?>"
                                    data-model="<?php echo htmlspecialchars($bm['model_name']); ?>"
                                    data-bs-toggle="modal" data-bs-target="#bmDeleteUnitsModal">
                                    <i class="bi bi-trash me-1"></i>Delete
                                  </button>
                                </li>
                              </ul>
                            </div>
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

        <div class="col-12 col-xl-5">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center"><strong>Add From Category</strong></div>
            <div class="card-body d-flex flex-column">
              <form method="POST" id="bm_add_form">
                <input type="hidden" name="do" value="add_borrowable" />
                <div class="mb-3">
                  <label class="form-label fw-bold" for="bm_bulk_category">Category</label>
                  <select id="bm_bulk_category" name="category" class="form-select" required>
                    <option value="">Select Category</option>
                    <?php foreach (array_keys($invCatModels) as $cat): ?>
                      <?php $cnt = isset($categoryCounts[$cat]) ? (int)$categoryCounts[$cat] : 0; ?>
                      <option value="<?php echo htmlspecialchars($cat); ?>">
                        <?php echo htmlspecialchars($cat . ' (' . $cnt . ' models)'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <strong>Models</strong>
                  <div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="bm_bulk_select_all">Select All</button>
                  </div>
                </div>
                <div class="table-responsive flex-grow-1" style="min-height:0; overflow:auto;">
                  <table class="table table-sm align-middle mb-2">
                    <thead>
                      <tr>
                        <th style="width:42px;"><input type="checkbox" id="bm_master_check" /></th>
                        <th>Model</th>
                        <th>Remaining / Total</th>
                        <th style="width:120px;">Limit</th>
                      </tr>
                    </thead>
                    <tbody id="bm_models_body">
                      <!-- populated by JS -->
                    </tbody>
                  </table>
                </div>
                <div class="d-grid">
                  <button type="submit" class="btn btn-success"><i class="bi bi-collection me-1"></i>Add Selected</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <script>
        document.addEventListener('DOMContentLoaded', function(){
          try {
            var url = new URL(window.location.href);
            var sc = url.searchParams.get('scroll');
            var targetId = '';
            if (sc === 'lost') targetId = 'lost-damaged';
            else if (sc === 'returned') targetId = 'returned-list';
            else if (sc === 'borrowed') targetId = 'borrowed-list';
            else if (window.location.hash) targetId = window.location.hash.replace(/^#/, '');
            if (targetId) {
              var el = document.getElementById(targetId);
              if (el) { setTimeout(function(){ el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 150); }
            }
          } catch(e) { /* no-op */ }
        });
      </script>

    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
  <script>
    // Live 1s polling for Pending, Borrowed, and Reservations tables
    function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
    function renderPending(d){ const tb=document.getElementById('pendingTbody'); if(!tb) return; const rows=[]; const list=(d&&Array.isArray(d.pending))?d.pending:[]; if(!list.length){ rows.push('<tr><td colspan="5" class="text-center text-muted">No pending requests.</td></tr>'); } else { list.forEach(function(r){ const dt = r.created_at_display? String(r.created_at_display) : (r.created_at? String(r.created_at):''); const typ = (r && r.qr_serial_no && String(r.qr_serial_no).trim()!=='') ? 'QR' : 'Manual'; const id = parseInt(r.id||0,10); const user = (r.user_full_name||r.username||''); const qty = parseInt((r.remaining!=null?r.remaining:r.quantity)||0,10); const schoolId = (r.student_school_id||r.student_id||r.school_id||''); const avail = parseInt(r.available_count||0,10); rows.push('<tr class="pending-row" role="button" tabindex="0"'+
      ' data-user="'+escapeHtml(user)+'"'+
      ' data-reqid="'+id+'"'+
      ' data-student="'+escapeHtml(String(schoolId||''))+'"'+
      ' data-item="'+escapeHtml(r.item_name||'')+'"'+
      ' data-qty="'+qty+'"'+
      ' data-loc="'+escapeHtml(r.request_location||'')+'"'+
      ' data-details="'+escapeHtml(r.details||'')+'"'+
      ' data-avail="'+avail+'"'+
      ' data-requested="'+escapeHtml(dt)+'"'+
      ' data-reqtype="'+escapeHtml(String(r.type||'immediate'))+'"'+
      '>'+ 
      '<td>'+id+'</td>'+ 
      '<td>'+escapeHtml(typ)+'</td>'+ 
      '<td>'+escapeHtml(user)+'</td>'+ 
      '<td>'+escapeHtml(r.school_id||'')+'</td>'+ 
      '<td class="text-end">'+ 
        '<div class="btn-group btn-group-sm segmented-actions" role="group" aria-label="Actions">'+
          '<button type="button" class="btn btn-sm btn-success border border-dark rounded-start py-1 px-1 lh-1 fs-6" title="Approve/Scan" aria-label="Approve/Scan" data-bs-toggle="modal" data-bs-target="#approveScanModal" data-reqid="'+id+'" data-item="'+escapeHtml(r.item_name||'')+'" data-qty="'+qty+'" data-reqtype="'+escapeHtml(String(r.type||''))+'" data-expected_return_at="'+escapeHtml(String(r.expected_return_at||''))+'" data-reserved_from="'+escapeHtml(String(r.reserved_from||''))+'" data-reserved_to="'+escapeHtml(String(r.reserved_to||''))+'" data-qr_serial="'+escapeHtml(String(r.qr_serial_no||''))+'"><i class=\"bi '+(typ==='QR'?'bi-check2-circle':'bi-qr-code-scan')+'\"></i></button>'+
          '<button type="button" class="btn btn-sm btn-danger border border-dark rounded-end py-1 px-1 lh-1 fs-6 reject-btn" title="Reject" aria-label="Reject" data-reject-id="'+id+'" data-reject-item="'+escapeHtml(r.item_name||'')+'" data-reject-user="'+escapeHtml(user)+'"><i class=\"bi bi-x\"></i></button>'+
        '</div>'+
      '</td>'+
    '</tr>'); }); }
      tb.innerHTML=rows.join(''); }
    function renderBorrowed(d){ const tb=document.getElementById('borrowedTbody'); if(!tb) return; const rows=[]; const list=(d&&Array.isArray(d.borrowed))?d.borrowed:[]; if(!list.length){ rows.push('<tr><td colspan="6" class="text-center text-muted">No active borrowed items.</td></tr>'); } else { list.forEach(function(b){ const dt = b.expected_return_display? String(b.expected_return_display) : (b.expected_return_at? String(b.expected_return_at):''); const typ = (b && b.type) ? String(b.type) : (((b && b.qr_serial_no) ? 'QR' : 'Manual')); const rawExp=(b && (b.expected_return_at||b.reserved_to))? String(b.expected_return_at||b.reserved_to):''; let t=NaN; if(rawExp){ try{ t=new Date(rawExp.replace(' ','T')).getTime(); }catch(_){ t=NaN; } } const overdue = !!(rawExp && !isNaN(t) && t < Date.now()); const expHtml = escapeHtml(dt) + (overdue ? ' <span class="text-danger" title="Overdue"><i class="bi bi-exclamation-circle-fill"></i></span>' : ''); rows.push('<tr class="borrowed-row" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#borrowedDetailsModal"'+
      ' data-user="'+escapeHtml((b.user_full_name||b.username||''))+'"'+
      ' data-reqid="'+parseInt(b.request_id||0,10)+'"'+
      ' data-serial="'+escapeHtml(b.serial_no||'')+'"'+
      ' data-model="'+escapeHtml(b.model||'')+'"'+
      ' data-category="'+escapeHtml(b.category||'')+'"'+
      ' data-location="'+escapeHtml(b.location||'')+'"'+
      ' data-expected_raw="'+escapeHtml(String(b.expected_return_at||b.reserved_to||''))+'"'+
      '>'+ 
      '<td>'+parseInt(b.request_id||0,10)+'</td>'+ 
      '<td>'+escapeHtml(typ)+'</td>'+ 
      '<td>'+escapeHtml((b.user_full_name||b.username||''))+'</td>'+ 
      '<td>'+escapeHtml(b.school_id||'')+'</td>'+ 
      '<td>'+expHtml+'</td>'+ 
      '<td class="text-end">'+ 
        '<div class="btn-group btn-group-sm segmented-actions" role="group" aria-label="Borrowed Actions">'+ 
         '<button type="button" class="btn btn-sm btn-light border border-dark rounded-start py-1 px-1 lh-1 fs-6" title="Return/Scan" aria-label="Return/Scan" data-bs-toggle="modal" data-bs-target="#returnScanModal" data-reqid="'+parseInt(b.request_id||0,10)+'" data-model_name="'+escapeHtml(b.model||'')+'" data-serial="'+escapeHtml(b.serial_no||'')+'"><i class="bi bi-arrow-counterclockwise"></i></button>'+ 
         '<button type="button" class="btn btn-sm btn-danger border border-dark rounded-0 py-1 px-1 lh-1 fs-6" title="Lost" aria-label="Lost" data-bs-toggle="modal" data-bs-target="#markLostModal" data-reqid="'+parseInt(b.request_id||0,10)+'" data-model_id="'+parseInt(b.model_id||0,10)+'" data-model_name="'+escapeHtml(b.model||'')+'" data-serial="'+escapeHtml(b.serial_no||'')+'"><i class="bi bi-exclamation-triangle"></i></button>'+ 
         '<button type="button" class="btn btn-sm btn-warning text-dark border border-dark rounded-end py-1 px-1 lh-1 fs-6" title="Maintenance" aria-label="Maintenance" data-bs-toggle="modal" data-bs-target="#markMaintModal" data-reqid="'+parseInt(b.request_id||0,10)+'" data-model_id="'+parseInt(b.model_id||0,10)+'" data-model_name="'+escapeHtml(b.model||'')+'" data-serial="'+escapeHtml(b.serial_no||'')+'"><i class="bi bi-tools"></i></button>'+ 
        '</div>'+ 
      '</td>'+ 
    '</tr>'); }); } 
      tb.innerHTML=rows.join(''); }
    function fmt(dt){
      if(!dt) return '';
      try {
        var s=String(dt).trim();
        var m=s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if(!m) return String(dt);
        var yyyy=m[1], MM=m[2], DD=m[3], HH=parseInt(m[4],10), mm=m[5];
        var ap = HH>=12 ? 'PM' : 'AM';
        var hh = HH%12; if(hh===0) hh=12; hh=('0'+hh).slice(-2);
        return MM+'-'+DD+'-'+yyyy+' '+hh+':'+mm+ap;
      } catch(_) { return String(dt); }
    }
    function renderReservations(d){ const tb=document.getElementById('reservationsTbody'); if(!tb) return; const rows=[]; const list=(d&&Array.isArray(d.reservations))?d.reservations:[]; if(!list.length){ rows.push('<tr><td colspan="5" class="text-center text-muted">No approved reservations.</td></tr>'); } else { list.forEach(function(r){ rows.push('<tr class="reservation-row" role="button" tabindex="0"'+
      ' data-user="'+escapeHtml((r.user_full_name||r.username||''))+'"'+
      ' data-school_id="'+escapeHtml(r.school_id||'')+'"'+
      ' data-item="'+escapeHtml(r.item_name||'')+'"'+
      ' data-category="'+escapeHtml(r.category||'')+'"'+
      ' data-location="'+escapeHtml(r.location||'')+'"'+
      ' data-rstart_raw="'+escapeHtml(String(r.reserved_from||''))+'"'+
      ' data-rend_raw="'+escapeHtml(String(r.reserved_to||''))+'"'+
      '>'+
      '<td>'+parseInt(r.id||0,10)+'</td>'+
      '<td>'+escapeHtml((r.user_full_name||r.username||''))+'</td>'+
      '<td>'+escapeHtml(r.school_id||'')+'</td>'+
      '<td>'+escapeHtml(r.item_name||'')+'</td>'+
      '<td class="text-end">'+
        '<div class="btn-group btn-group-sm segmented-actions" role="group" aria-label="Reservation Actions">'+
         '<button type="button" class="btn btn-sm btn-outline-primary border border-dark rounded-start py-0 px-1 lh-1 fs-6" title="Edit Serial" aria-label="Edit Serial" data-bs-toggle="modal" data-bs-target="#editResSerialModal" data-reqid="'+parseInt(r.id||0,10)+'" data-item="'+escapeHtml(r.item_name||'')+'" data-serial="'+escapeHtml(String(r.reserved_serial_no||''))+'"><i class="bi bi-pencil-square"></i> Edit</button>'+
         '<a href="admin_borrow_center.php?action=cancel_reservation&id='+parseInt(r.id||0,10)+'" class="btn btn-sm btn-outline-danger border border-dark rounded-end py-0 px-1 lh-1 fs-6" title="Cancel" aria-label="Cancel" onclick="return confirm(\'Cancel this reservation?\');"><i class="bi bi-x"></i> Cancel</a>'+
        '</div>'+
      '</td>'+
    '</tr>'); }); }
      tb.innerHTML=rows.join(''); }
    document.addEventListener('DOMContentLoaded', function(){
      setInterval(()=>{ fetch('admin_borrow_center.php?action=pending_json').then(r=>r.json()).then(renderPending).catch(()=>{}); }, 1000);
      setInterval(()=>{ fetch('admin_borrow_center.php?action=borrowed_json').then(r=>r.json()).then(renderBorrowed).catch(()=>{}); }, 1000);
      setInterval(()=>{ fetch('admin_borrow_center.php?action=reservations_json').then(r=>r.json()).then(renderReservations).catch(()=>{}); }, 1000);
    });
  </script>
  <script>
    (function(){
      function esc(s){ return String(s).replace(/[&<>"]/g, function(m){ return ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"})[m]; }); }
      function two(n){ n=parseInt(n||0,10); return (n<10?'0':'')+n; }
      function parseDt(dt){
        try{
          if(!dt) return null;
          var s=String(dt).trim();
          var m=s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
          if(!m) return null;
          return { y:parseInt(m[1],10), m:parseInt(m[2],10), d:parseInt(m[3],10), H:parseInt(m[4],10), M:parseInt(m[5],10) };
        }catch(_){ return null; }
      }
      function toMs(p){ try{ if(!p) return NaN; return new Date(p.y, p.m-1, p.d, p.H, p.M).getTime(); }catch(_){ return NaN; } }
      function fmtDate(dt){ var p=parseDt(dt); if(!p) return ''; return two(p.m)+'-'+two(p.d)+'-'+p.y; }
      function fmtTime(dt){ var p=parseDt(dt); if(!p) return ''; var h=p.H, ap=(h>=12?'PM':'AM'); h=h%12; if(h===0)h=12; return (h<10?('0'+h):h)+':'+two(p.M)+ap; }
      function twoLine(dt){ var d=fmtDate(dt), t=fmtTime(dt); if(!d && !t) return ''; return '<span class="twol"><span class="dte">'+esc(d)+'</span> <span class="tme">'+esc(t)+'</span></span>'; }
      function overlap(a1,a2,b1,b2){
        var A1=parseDt(a1), A2=parseDt(a2), B1=parseDt(b1), B2=parseDt(b2);
        if(!A1||!B1) return false; // need starts
        var endA = A2 ? toMs(A2) : Number.POSITIVE_INFINITY;
        var endB = B2 ? toMs(B2) : Number.POSITIVE_INFINITY;
        return (toMs(A1) <= endB) && (toMs(B1) <= endA);
      }
      function renderCards(list){
        var wrap = document.getElementById('resTimelineList');
        if (!wrap) return;
        if (!Array.isArray(list) || !list.length) {
          wrap.innerHTML = '<div class="col-12"><div class="text-center text-muted small py-3">No items for this filter.</div></div>';
          return;
        }

        // Group items by model + category so the timeline shows per-item groups
        var groups = {};
        list.forEach(function(it){
          var model = String(it.model||'');
          var cat = String(it.category||'');
          var key = model+'||'+cat;
          if (!groups[key]) {
            groups[key] = { model:model, category:cat, items:[] };
          }
          groups[key].items.push(it);
        });

        var out = [];
        var keys = Object.keys(groups).sort(function(a,b){
          var ma = groups[a].model.toLowerCase();
          var mb = groups[b].model.toLowerCase();
          if (ma < mb) return -1; if (ma > mb) return 1; return 0;
        });

        keys.forEach(function(key, idx){
          var g = groups[key];
          var grpId = 'resgrp_'+idx;
          var anyClash = false;

          var bodyHtml = g.items.map(function(it, sIdx){
            var serial = String(it.serial_no||'');
            var loc = String(it.location||'');
            var use = it.in_use || null;
            var res = Array.isArray(it.reservations)? it.reservations : [];
            var serialHasClash = false;
            if (use && (use.to||'')) {
              res.forEach(function(r){ if (!serialHasClash && overlap(use.from, use.to, r.from, r.to)) serialHasClash=true; });
            }
            if (serialHasClash) anyClash = true;

            var safeSerial = (serial || ('serial_'+sIdx)).replace(/[^A-Za-z0-9_-]/g,'_');
            var resTarget = 'reslist_'+idx+'_'+safeSerial;

            var resButton = '';
            var resSection = '';
            if (res.length) {
              var lbl = (res.length===1) ? 'Reservation' : ('Reservations ('+res.length+')');
              var listHtml = res.map(function(r){
                var clash = use && overlap(use.from, use.to, r.from, r.to);
                return (
                  '<div class="d-flex flex-column p-2 rounded '+(clash?'bg-danger bg-opacity-10 border border-danger':'bg-light')+' mb-2">'+
                    '<div><i class="bi bi-person-fill me-1"></i>'+esc(r.full_name||r.username||'')+'</div>'+
                    '<div class="small">'+twoLine(r.from)+' → '+twoLine(r.to)+'</div>'+
                  '</div>'
                );
              }).join('');
              resButton = '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#'+resTarget+'" aria-expanded="false" aria-controls="'+resTarget+'">'+
                            '<i class="bi bi-calendar-event me-1"></i>'+esc(lbl)+
                          '</button>';
              resSection = '<div id="'+resTarget+'" class="collapse mt-2">'+listHtml+'</div>';
            }

            var useBadge = '';
            var useDetail = '';
            if (use) {
              useBadge = '<span class="badge bg-primary ms-2">In Use</span>';
              useDetail = '<div class="small text-muted mt-1">'+twoLine(use.from)+' → '+twoLine(use.to)+'</div>';
            }

            return (
              '<div class="border rounded p-2 mb-2 '+(serialHasClash?'border-danger':'border-light')+'">'+
                '<div class="d-flex justify-content-between align-items-center">'+
                  '<div>'+
                    '<strong>'+esc(serial || '(no serial)')+'</strong>'+
                    (loc ? ' <span class="text-muted small ms-1">'+esc(loc)+'</span>' : '')+
                    (useBadge || '')+
                  '</div>'+
                  '<div>'+
                    (resButton || '')+
                  '</div>'+
                '</div>'+
                useDetail+
                resSection+
              '</div>'
            );
          }).join('');

          out.push(
            '<div class="col-12 col-lg-6 col-xxl-3">'+
              '<div class="card shadow-sm '+(anyClash?'border-danger':'')+'">'+
                '<div class="card-header bg-white d-flex justify-content-between align-items-center" role="button" style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#'+grpId+'" aria-expanded="false" aria-controls="'+grpId+'">'+
                  '<div>'+
                    '<div><strong>'+esc(g.model || '(no model)')+'</strong></div>'+
                    (g.category ? '<div class="small text-muted">'+esc(g.category)+'</div>' : '')+
                  '</div>'+
                '</div>'+
                '<div id="'+grpId+'" class="collapse">'+
                  '<div class="card-body">'+
                    (bodyHtml || '<div class="text-muted small">No serials match this filter.</div>')+
                  '</div>'+
                '</div>'+
              '</div>'+
            '</div>'
          );
        });

        wrap.innerHTML = out.join('');
      }
      async function loadTimeline(){
        var catSel = document.getElementById('resFilterCategory');
        var qInp = document.getElementById('resFilterSearch');
        var dayInp = document.getElementById('resFilterDay');
        var cat = catSel ? (catSel.value||'') : '';
        var q = qInp ? (qInp.value||'') : '';
        var day = dayInp ? (dayInp.value||'') : '';
        var url = 'admin_borrow_center.php?action=reservation_timeline_json' +
                  (cat?('&category='+encodeURIComponent(cat)):'') +
                  (q?('&q='+encodeURIComponent(q)):'') +
                  (day?('&day='+encodeURIComponent(day)):'');

        try {
          var r = await fetch(url);
          var j = await r.json();
          if (!j || !j.ok) { renderCards([]); return; }
          var cats = Array.isArray(j.categories)? j.categories : [];
          var sel = document.getElementById('resFilterCategory');
          if (sel) {
            var cur = sel.value;
            var opts = ['<option value="">All</option>'].concat(cats.map(function(c){ var v=String(c||''); return '<option value="'+esc(v)+'"'+(cur===v?' selected':'')+'>'+esc(v)+'</option>'; }));
            sel.innerHTML = opts.join('');
            if (cur && cats.indexOf(cur)===-1) sel.value='';
          }
          renderCards(Array.isArray(j.items)? j.items : []);
        } catch(_e) { renderCards([]); }
      }
      document.addEventListener('DOMContentLoaded', function(){
        var mdl = document.getElementById('resTimelineModal'); if (!mdl) return;
        var wired=false, timer=null;
        mdl.addEventListener('show.bs.modal', function(){
          loadTimeline();
          if (wired) return; wired=true;
          var c=document.getElementById('resFilterCategory');
          var a=document.getElementById('resFilterDay');
          var q=document.getElementById('resFilterSearch');
          if (c) c.addEventListener('change', loadTimeline);
          if (a) a.addEventListener('change', loadTimeline);
          if (q) q.addEventListener('input', function(){ if (timer) clearTimeout(timer); timer=setTimeout(loadTimeline, 300); });
        });
      });
    })();
  </script>
  <!-- Returned Item Details Modal -->
  <div class="modal fade" id="returnedDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Returned Item Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>Request ID:</strong> <span id="rtdReq"></span></div>
          <div class="mb-2"><strong>Serial ID:</strong> <span id="rtdSerial"></span></div>
          <div class="mb-2"><strong>Model:</strong> <span id="rtdModel"></span></div>
          <div class="mb-2"><strong>Category:</strong> <span id="rtdCategory"></span></div>
          <div class="mb-2"><strong>Last Borrower:</strong> <span id="rtdLast"></span></div>
          <div class="mb-2"><strong>School ID:</strong> <span id="rtdSid"></span></div>
          <div class="mb-2"><strong>Returned At:</strong> <span id="rtdReturned"></span></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Populate Returned Item Details modal
    (function(){
      function fmtLocal(dt){ try { if(!dt) return '-'; var s=String(dt); if(/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(s)) s=s.replace(' ','T'); var d=new Date(s); if (isNaN(d.getTime())) return String(dt); var mm=('0'+(d.getMonth()+1)).slice(-2), dd=('0'+d.getDate()).slice(-2), yyyy=d.getFullYear().toString().slice(-2); var h=d.getHours()%12||12, m=('0'+d.getMinutes()).slice(-2), ap=d.getHours()<12?'AM':'PM'; return h+':'+m+' '+ap+' '+mm+'-'+dd+'-'+yyyy; } catch(_){ return String(dt)||'-'; } }
      var mdl = document.getElementById('returnedDetailsModal');
      if (mdl) {
        mdl.addEventListener('show.bs.modal', function (event) {
          var trg = event.relatedTarget;
          var src = (trg && typeof trg.closest === 'function') ? trg.closest('[data-bs-target="#returnedDetailsModal"]') : null;
          var el = src || trg || (typeof window !== 'undefined' ? window._rtdSrc : null);
          var req = el ? (el.getAttribute('data-reqid') || '') : '';
          var serial = el ? (el.getAttribute('data-serial') || '') : '';
          var model = el ? (el.getAttribute('data-model') || '') : '';
          var cat = el ? (el.getAttribute('data-category') || '') : '';
          var last = el ? (el.getAttribute('data-last_borrower') || '') : '';
          var sid = el ? (el.getAttribute('data-student_id') || '') : '';
          var returned = el ? (el.getAttribute('data-returned_at') || '') : '';
          document.getElementById('rtdReq').textContent = req;
          document.getElementById('rtdSerial').textContent = serial;
          document.getElementById('rtdModel').textContent = model;
          document.getElementById('rtdCategory').textContent = cat || 'Uncategorized';
          document.getElementById('rtdLast').textContent = last;
          document.getElementById('rtdSid').textContent = sid;
          document.getElementById('rtdReturned').textContent = returned ? fmtLocal(returned) : '-';
        });
      }
    })();
  </script>

  <!-- Confirm Permanently Lost / Dispose Modal -->
  <div class="modal fade" id="ldConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="POST" action="admin_borrow_center.php" class="modal-content" id="ldConfirmForm">
        <input type="hidden" name="model_id" id="ldConfirmModelId" value="" />
        <input type="hidden" name="do" id="ldConfirmDo" value="" />
        <input type="hidden" name="confirm_text" id="ldConfirmText" value="" />
        <div class="modal-header">
          <h5 class="modal-title" id="ldConfirmTitle">Confirm Action</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p id="ldConfirmMessage" class="mb-3"></p>
          <div class="mb-2">
            <label for="ldConfirmInput" class="form-label small" id="ldConfirmLabel"></label>
            <input type="text" class="form-control" id="ldConfirmInput" autocomplete="off" />
          </div>
          <div class="form-text" id="ldConfirmHint"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="ldConfirmOkBtn">OK</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        var parent = document.getElementById('lostDamagedListModal');
        var modalEl = document.getElementById('ldConfirmModal');
        var formEl = document.getElementById('ldConfirmForm');
        if (!parent || !modalEl || !formEl || typeof bootstrap === 'undefined') return;
        var titleEl = document.getElementById('ldConfirmTitle');
        var msgEl = document.getElementById('ldConfirmMessage');
        var labelEl = document.getElementById('ldConfirmLabel');
        var hintEl = document.getElementById('ldConfirmHint');
        var inputEl = document.getElementById('ldConfirmInput');
        var modelInput = document.getElementById('ldConfirmModelId');
        var doInput = document.getElementById('ldConfirmDo');
        var confirmHidden = document.getElementById('ldConfirmText');
        var okBtn = document.getElementById('ldConfirmOkBtn');
        var expected = '';

        function updateLdConfirmState() {
          if (!inputEl || !okBtn) return;
          var val = String(inputEl.value || '');
          var match = expected && (val.trim() === expected);
          okBtn.disabled = !match;
          if (match) {
            okBtn.classList.remove('btn-danger');
            okBtn.classList.add('btn-primary');
          } else {
            okBtn.classList.remove('btn-primary');
            okBtn.classList.add('btn-danger');
          }
        }

        parent.addEventListener('click', function(e){
          var btn = e.target.closest('.ld-confirm-btn');
          if (!btn) return;
          e.preventDefault();
          var type = (btn.getAttribute('data-action_type') || '').toLowerCase();
          var mid = btn.getAttribute('data-model_id') || '';
          expected = '';
          if (type === 'permanent_lost') {
            expected = 'LOST';
            if (titleEl) titleEl.textContent = 'Confirm Permanently Lost';
            if (msgEl) msgEl.textContent = 'Type LOST to confirm this item will be marked as Permanently Lost. This action will be recorded and set the item\'s status to Permanently Lost.';
            if (labelEl) labelEl.textContent = 'Type LOST to confirm:';
          } else if (type === 'dispose') {
            expected = 'DISPOSE';
            if (titleEl) titleEl.textContent = 'Confirm Dispose';
            if (msgEl) msgEl.textContent = 'Type DISPOSE to confirm disposing this item. This action will retire the serial and mark it as Disposed.';
            if (labelEl) labelEl.textContent = 'Type DISPOSE to confirm:';
          }
          if (hintEl) hintEl.textContent = '';
          if (inputEl) {
            inputEl.value = '';
            inputEl.placeholder = expected;
          }
          if (okBtn) {
            okBtn.disabled = true;
            okBtn.classList.remove('btn-primary');
            okBtn.classList.add('btn-danger');
          }
          if (modelInput) modelInput.value = mid;
          if (doInput) {
            doInput.value = (type === 'permanent_lost' ? 'mark_permanent_lost' : (type === 'dispose' ? 'dispose_item' : ''));
          }
          if (confirmHidden) confirmHidden.value = '';
          var inst = bootstrap.Modal.getOrCreateInstance(modalEl);
          inst.show();
          if (inputEl) {
            setTimeout(function(){ try{ inputEl.focus(); }catch(_){ } }, 150);
          }
        });

        if (inputEl) {
          inputEl.addEventListener('input', function(){
            updateLdConfirmState();
          });
        }

        formEl.addEventListener('submit', function(ev){
          if (!expected) return;
          var val = inputEl ? String(inputEl.value || '') : '';
          if (val.trim() !== expected) {
            ev.preventDefault();
            alert('Confirmation failed. You must type ' + expected + ' exactly as shown to proceed.');
            if (inputEl) { inputEl.focus(); }
            return;
          }
          if (confirmHidden) confirmHidden.value = expected;
        });
      });
    })();
  </script>
  <!-- Approve by Scan/ID Modal -->
  <div class="modal fade" id="approveScanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-qr-code-scan me-2"></i>Scan/Approve Request</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>Requested Item:</strong> <span id="asItem"></span></div>
          <div class="mb-2"><strong>Quantity:</strong> <span id="asQty"></span></div>
          <div class="mb-3">
            <label class="form-label small d-block mb-1">Request Type</label>
            <div><span class="badge bg-secondary" id="asReqTypeDisplay">Immediate</span></div>
            <div id="immediateFields" class="mt-2">
              <label class="form-label small mb-1">Expected Return</label>
              <input type="text" class="form-control form-control-sm" id="asExpectedReturnRO" readonly />
            </div>
            <div id="reservationFields" class="mt-2" style="display:none;">
              <div class="row g-2">
                <div class="col-12 col-md-6">
                  <label class="form-label small mb-1">Reserve Start</label>
                  <input type="text" class="form-control form-control-sm" id="asReserveStartRO" readonly />
                </div>
                <div class="col-12 col-md-6">
                  <label class="form-label small mb-1">Reserve End</label>
                  <input type="text" class="form-control form-control-sm" id="asReserveEndRO" readonly />
                </div>
              </div>
              <div class="form-text">Item remains borrowable until 5 minutes before start.</div>
            </div>
          </div>
          <div class="mb-2"><small id="asStatus" class="text-muted">You can scan a QR or enter ID manually.</small></div>
          <div id="asReader" class="border rounded p-2 mb-2" style="max-width:360px;"></div>
          <div class="d-flex gap-2 mb-3">
            <button type="button" id="asStart" class="btn btn-success btn-sm"><i class="bi bi-camera-video"></i> Start</button>
            <button type="button" id="asStop" class="btn btn-danger btn-sm" style="display:none;"><i class="bi bi-stop-circle"></i> Stop</button>
          </div>
          <div class="mb-3" id="asCamWrap" style="max-width:360px;">
            <label class="form-label small mb-1">Camera (desktop only)</label>
            <select id="asCameraSelect" class="form-select form-select-sm"></select>
            <button type="button" id="asRefreshCams" class="btn btn-sm btn-outline-secondary mt-2">Refresh</button>
          </div>
          <div class="mt-2">
            <label class="form-label small mb-1">Or upload QR image</label>
            <input type="file" id="asImageFile" class="form-control form-control-sm" accept="image/*" />
          </div>
          <form id="asForm" method="POST" action="admin_borrow_center.php?action=approve_with">
            <input type="hidden" name="request_id" id="asReqId" value="" />
            <!-- Hidden fields retained for compatibility but server ignores them and uses request values -->
            <input type="hidden" name="req_type" id="asReqType" value="" />
            <input type="hidden" name="expected_return_at" id="asExpectedReturnField" value="" />
            <input type="hidden" name="reserved_from" id="asReserveStartField" value="" />
            <input type="hidden" name="reserved_to" id="asReserveEndField" value="" />
            <div class="input-group">
              <span class="input-group-text">Serial ID</span>
              <input type="text" class="form-control" name="serial_no" id="asSerial" placeholder="Enter Serial ID" required />
            </div>
            <div class="mt-3 text-end">
              <button type="submit" id="asApproveBtn" class="btn btn-primary" disabled><i class="bi bi-check2 me-1"></i>Approve</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
  <!-- QR Borrowed Item View Modal -->
  <div class="modal fade" id="qrReturnAdminModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Borrowed Item (QR)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>Request ID:</strong> <span id="qrAdmReq"></span></div>
          <div class="mb-2"><strong>Model:</strong> <span id="qrAdmModel"></span></div>
          <div class="mb-2"><strong>Serial:</strong> <span id="qrAdmSerial"></span></div>
          <div class="mb-2"><strong>Location:</strong> <span id="qrAdmLoc"></span></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
  <!-- Return by Scan/ID Modal -->
  <div class="modal fade" id="returnScanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise me-2"></i>Scan/Return Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>Request ID:</strong> <span id="rsReq"></span></div>
          <div class="mb-2"><strong>Expected Model:</strong> <span id="rsModel"></span></div>
          <div class="mb-2"><small id="rsStatus" class="text-muted">Scan the item's QR or enter Serial ID manually.</small></div>
          <div id="rsReader" class="border rounded p-2 mb-2" style="max-width:360px;"></div>
          <div class="d-flex gap-2 mb-3">
            <button type="button" id="rsStart" class="btn btn-success btn-sm"><i class="bi bi-camera-video"></i> Start</button>
            <button type="button" id="rsStop" class="btn btn-danger btn-sm" style="display:none;"><i class="bi bi-stop-circle"></i> Stop</button>
          </div>
          <div class="mb-3" id="rsCamWrap" style="max-width:360px;">
            <label class="form-label small mb-1">Camera (desktop only)</label>
            <select id="rsCameraSelect" class="form-select form-select-sm"></select>
            <button type="button" id="rsRefreshCams" class="btn btn-sm btn-outline-secondary mt-2">Refresh</button>
          </div>
          <div class="mt-2">
            <label class="form-label small mb-1">Or upload QR image</label>
            <input type="file" id="rsImageFile" class="form-control form-control-sm" accept="image/*" />
          </div>
          <form id="rsForm" method="POST" action="admin_borrow_center.php?action=return_with">
            <input type="hidden" name="request_id" id="rsReqId" value="" />
            <div class="input-group">
              <span class="input-group-text">Serial ID</span>
              <input type="text" class="form-control" name="serial_no" id="rsSerial" placeholder="Enter Serial ID" required />
            </div>
            <div class="mt-3 text-end">
              <button type="submit" id="rsReturnBtn" class="btn btn-primary" disabled><i class="bi bi-check2 me-1"></i>Return</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
  <!-- Request Details Modal -->
  <div class="modal fade" id="requestDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Request Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>User:</strong> <span id="rdUser"></span></div>
          <div class="mb-2"><strong>Request Type:</strong> <span id="rdType"></span></div>
          <div class="mb-2"><strong>Item:</strong> <span id="rdItem"></span></div>
          <div class="mb-2"><strong>Location:</strong> <span id="rdLoc"></span></div>
          <div class="mb-2"><strong>Quantity:</strong> <span id="rdQty"></span></div>
          <div class="mb-2"><strong>Available:</strong> <span id="rdAvail"></span></div>
          <div class="mb-2"><strong>Requested At:</strong> <span id="rdRequested"></span></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
  <!-- Reject Pending Request Modal -->
  <div class="modal fade" id="rejectConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Request</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">You are about to reject request <strong>#<span id="rejReqId"></span></strong>.</p>
          <p class="mb-2"><strong>User:</strong> <span id="rejUser"></span></p>
          <p class="mb-0"><strong>Item:</strong> <span id="rejItem"></span></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger" id="rejConfirmBtn">Reject</button>
        </div>
      </div>
    </div>
  </div>
  <!-- Reservation Details Modal -->
  <div class="modal fade" id="reservationDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Reservation Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>User:</strong> <span id="rvUser"></span></div>
          <div class="mb-2"><strong>School ID:</strong> <span id="rvSid"></span></div>
          <div class="mb-2"><strong>Item:</strong> <span id="rvItem"></span></div>
          <div class="mb-2"><strong>Category:</strong> <span id="rvCategory"></span></div>
          <div class="mb-2"><strong>Location:</strong> <span id="rvLocation"></span></div>
          <div class="mb-2"><strong>Reserve Start:</strong> <span id="rvStart"></span></div>
          <div class="mb-2"><strong>Reserve End:</strong> <span id="rvEnd"></span></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Edit Reserved Serial Modal -->
  <div class="modal fade" id="editResSerialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Reserved Serial</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="admin_borrow_center.php?action=edit_reservation_serial">
          <div class="modal-body">
            <input type="hidden" name="request_id" id="ersReqId" value="" />
            <div class="mb-2"><strong>Request ID:</strong> <span id="ersReqDisp"></span></div>
            <div class="mb-2"><strong>Item:</strong> <span id="ersItemDisp"></span></div>
            <div class="mb-2"><strong>Current Serial:</strong> <span id="ersCurSerialDisp"></span></div>
            <div class="input-group">
              <span class="input-group-text">New Serial ID</span>
              <input type="text" class="form-control" name="serial_no" id="ersSerial" placeholder="Enter Serial ID" required />
            </div>
            
            <div id="ersValMsg" class="form-text mt-1"></div>
            <div class="mt-2 d-flex gap-2">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="ersViewAvailBtn"><i class="bi bi-list-ul me-1"></i>View Available List</button>
              <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="ersRefreshAvailBtn"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
            </div>
            <div id="ersAvailWrap" class="mt-2 d-none">
              <style>
                #ersAvailWrap table{table-layout:fixed;width:100%;}
                #ersAvailWrap th,#ersAvailWrap td{white-space:normal !important;overflow:visible !important;text-overflow:clip !important;font-size:clamp(10px,0.9vw,12px);line-height:1.2;word-break:normal !important;overflow-wrap:break-word !important;}
                #ersAvailWrap th:nth-child(1),#ersAvailWrap td:nth-child(1){width:14%;}
                #ersAvailWrap th:nth-child(2),#ersAvailWrap td:nth-child(2){width:16%;}
                #ersAvailWrap th:nth-child(3),#ersAvailWrap td:nth-child(3){width:14%;}
                /* Status column: do not break inside single words */
                #ersAvailWrap td:nth-child(3){overflow-wrap:normal !important;word-break:keep-all !important;}
                #ersAvailWrap th:nth-child(4),#ersAvailWrap td:nth-child(4){width:18%;} /* Location column */
                #ersAvailWrap th:nth-child(5),#ersAvailWrap td:nth-child(5){width:19%;}
                #ersAvailWrap th:nth-child(6),#ersAvailWrap td:nth-child(6){width:19%;}
                /* Ensure Start/End can wrap (in addition to global wrap) */
                #ersAvailWrap td:nth-child(5), #ersAvailWrap td:nth-child(6){white-space:normal !important;}
                /* Uniform two-line row height and alignment */
                #ersAvailWrap tbody tr{height:2.9em;}
                #ersAvailWrap td{vertical-align:middle;}
                #ersAvailWrap tbody tr td{min-height:2.8em;}
                #ersAvailWrap th,#ersAvailWrap td{padding-top:0.35rem;padding-bottom:0.35rem;}
                /* Two-line wrapper for date/time */
                #ersAvailWrap .twol{display:block; line-height:1.15;}
                #ersAvailWrap .twol .dte,#ersAvailWrap .twol .tme{display:block;}
              </style>
              <div class="table-responsive" style="max-height:220px; overflow:auto;">
                <table class="table table-sm align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Serial ID</th>
                      <th>Model</th>
                      <th>Status</th>
                      <th>Location</th>
                      <th>Start</th>
                      <th>End</th>
                    </tr>
                  </thead>
                  <tbody id="ersAvailBody"><tr><td colspan="6" class="text-center text-muted">Loading...</td></tr></tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" id="ersSaveBtn" class="btn btn-primary" disabled><i class="bi bi-save me-1"></i>Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Action Error Modal -->
  <div class="modal fade" id="actionErrorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Action Failed</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="aemMessage"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Prefill Edit Reserved Serial modal
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        var mdl = document.getElementById('editResSerialModal');
        if (!mdl) return;
        mdl.addEventListener('show.bs.modal', function (event) {
          var trg = event.relatedTarget;
          if (!trg) return;
          var req = trg.getAttribute('data-reqid') || '';
          var item = trg.getAttribute('data-item') || '';
          var cur = trg.getAttribute('data-serial') || '';
          var reqSpan = document.getElementById('ersReqDisp'); if (reqSpan) reqSpan.textContent = req;
          var itemSpan = document.getElementById('ersItemDisp'); if (itemSpan) itemSpan.textContent = item;
          var curSpan = document.getElementById('ersCurSerialDisp'); if (curSpan) curSpan.textContent = cur || '-';
          var reqIdField = document.getElementById('ersReqId'); if (reqIdField) reqIdField.value = req;
          var serialField = document.getElementById('ersSerial'); if (serialField) { serialField.value = cur || ''; setTimeout(function(){ try{ serialField.focus(); serialField.select(); }catch(_){ } }, 50); }
          try { if (window._ersQueueValidate) window._ersQueueValidate(); } catch(_) {}
        });
      });
    })();
  </script>

  <script>
    // Show a friendly error modal when redirected back with ?error=...
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        try {
          var sp = new URLSearchParams(window.location.search);
          var err = sp.get('error');
          if (!err) return;
          var msg = '';
          if (err === 'edit_serial_conflict') {
            msg = 'Cannot assign this serial. It conflicts with another approved reservation for the selected time window.';
          } else if (err === 'edit_serial_inuse') {
            msg = 'Cannot assign this serial. It is currently in use and returns too late for the reservation start (requires a 5-minute buffer).';
          } else if (err === 'edit_serial_overdue') {
            msg = 'Cannot assign this serial. It is currently overdue and its return time is unknown.';
          } else if (err === 'edit_serial_missing') {
            msg = 'Missing request or serial.';
          } else if (err === 'edit_serial_notapproved') {
            msg = 'Only approved reservations can be edited.';
          } else if (err === 'edit_serial_baditem') {
            msg = 'Invalid item for this reservation.';
          } else if (err === 'edit_serial_single') {
            msg = 'Serial cannot be changed for single-unit models.';
          } else if (err === 'edit_serial_notfound') {
            msg = 'Serial ID not found.';
          } else if (err === 'edit_serial_mismatch') {
            msg = 'Serial belongs to a different model; please choose a matching unit.';
          } else if (err === 'edit_serial_time') {
            msg = 'Invalid reservation time.';
          } else {
            return;
          }
          var body = document.getElementById('aemMessage');
          if (body) body.textContent = msg;
          var mdl = document.getElementById('actionErrorModal');
          if (mdl && typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(mdl).show();
          }
        } catch(_) { }
      });
    })();
  </script>

  <script>
    (function(){
      function setStatus(ok, msg){
        var inp = document.getElementById('ersSerial');
        var fb = document.getElementById('ersValMsg');
        var btn = document.getElementById('ersSaveBtn');
        if (fb) fb.textContent = msg ? String(msg) : '';
        if (inp) { inp.classList.remove('is-valid','is-invalid'); }
        if (btn) btn.disabled = true;
        if (ok === true) { if (inp) inp.classList.add('is-valid'); if (btn) btn.disabled = false; }
        else if (ok === false) { if (inp) inp.classList.add('is-invalid'); }
      }
      function validateNow(){
        var inp = document.getElementById('ersSerial');
        var rid = parseInt((document.getElementById('ersReqId')||{}).value||'0',10);
        var v = (inp && inp.value) ? inp.value.trim() : '';
        if (!v || !rid){ setStatus(false, v? 'Missing request' : 'Enter a serial ID'); return; }
        setStatus(null, 'Checking...');
        fetch('admin_borrow_center.php?action=validate_reservation_serial&request_id=' + encodeURIComponent(rid) + '&serial_no=' + encodeURIComponent(v), { headers: { 'Accept': 'application/json' } })
          .then(function(r){ return r.json(); })
          .then(function(j){ if (j && j.ok){ setStatus(true, 'Valid'); } else { setStatus(false, (j && j.reason) ? j.reason : 'Invalid'); } })
          .catch(function(){ setStatus(false, 'Could not validate.'); });
      }
      var t = null;
      function queue(){ if (t) clearTimeout(t); t = setTimeout(validateNow, 350); }
      document.addEventListener('DOMContentLoaded', function(){
        window._ersQueueValidate = queue;
        var inp = document.getElementById('ersSerial');
        var form = document.querySelector('#editResSerialModal form');
        if (inp) { inp.addEventListener('input', queue); inp.addEventListener('blur', validateNow); }
        if (form) { form.addEventListener('submit', function(e){ var btn = document.getElementById('ersSaveBtn'); if (btn && btn.disabled){ e.preventDefault(); e.stopPropagation(); return false; } }); }
      });
    })();
  </script>

  <script>
    // Edit Reserved Serial: View Available List loader and renderer
    (function(){
      function two(n){ n=parseInt(n,10); return (n<10?'0':'')+n; }
      function fmtDateOnly(dt){ try{ if(!dt) return ''; var s=String(dt).trim(); if(/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(s)) s=s.replace(' ','T'); var d=new Date(s); if(isNaN(d.getTime())) return String(dt); var mm=two(d.getMonth()+1), dd=two(d.getDate()), yyyy=d.getFullYear(); return mm+'-'+dd+'-'+yyyy; }catch(_){ return String(dt);} }
      function fmtTimeOnly(dt){ try{ if(!dt) return ''; var s=String(dt).trim(); if(/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(s)) s=s.replace(' ','T'); var d=new Date(s); if(isNaN(d.getTime())) return String(dt); var h=d.getHours(), m=two(d.getMinutes()); var ap=(h>=12?'pm':'am'); h=h%12; if(h===0)h=12; return h+':'+m+' '+ap; }catch(_){ return String(dt);} }
      function twoLine(dt){ if(!dt) return ''; var d=fmtDateOnly(dt), t=fmtTimeOnly(dt); if(!d&&!t) return ''; return '<span class="twol"><span class="dte">'+escapeHtml(d)+'</span><span class="tme">'+escapeHtml(t)+'</span></span>'; }
      function render(list){ var tb=document.getElementById('ersAvailBody'); if(!tb) return; if(!list||!list.length){ tb.innerHTML='<tr><td colspan="6" class="text-center text-muted">No items.</td></tr>'; return; } var order={'Available':0,'Reserved':1,'In Use':2}; list.sort(function(a,b){ var oa=(order[a.status]??9), ob=(order[b.status]??9); if(oa!==ob) return oa-ob; var sa=String(a.serial_no||''), sb=String(b.serial_no||''); return sa.localeCompare(sb); }); var rows=list.map(function(r){ var rs='', re=''; if (String(r.status)==='Reserved' && r.reserved_from && r.reserved_to){ rs=twoLine(r.reserved_from); re=twoLine(r.reserved_to); } else if (String(r.status)==='In Use'){ if (r.in_use_start) { rs=twoLine(r.in_use_start); } if (r.in_use_end) { re=twoLine(r.in_use_end); } } var locTxt=escapeHtml(String(r.location||'')); var rsTip=fmtDateOnly(r.reserved_from||r.in_use_start||'')+' '+fmtTimeOnly(r.reserved_from||r.in_use_start||''); var reTip=fmtDateOnly(r.reserved_to||r.in_use_end||'')+' '+fmtTimeOnly(r.reserved_to||r.in_use_end||''); return '<tr><td>'+escapeHtml(String(r.serial_no||'(no serial)'))+'</td><td>'+escapeHtml(String(r.model_name||''))+'</td><td>'+escapeHtml(String(r.status||''))+'</td><td title="'+locTxt+'">'+locTxt+'</td><td title="'+escapeHtml(rsTip.trim())+'">'+rs+'</td><td title="'+escapeHtml(reTip.trim())+'">'+re+'</td></tr>'; }).join(''); tb.innerHTML=rows; try{ var wrap=document.getElementById('ersAvailWrap'); var sc=wrap?wrap.querySelector('.table-responsive'):null; if(sc){ var first=tb.querySelector('tr'); if(first){ var rH=first.offsetHeight||28; var thead=wrap.querySelector('thead'); var hH=thead?thead.offsetHeight:28; sc.style.maxHeight=(hH + rH*5)+'px'; } } }catch(_){ }
      }
      function load(){ var ridEl=document.getElementById('ersReqId'); var rid=parseInt((ridEl&&ridEl.value)||'0',10)||0; var tb=document.getElementById('ersAvailBody'); if(tb) tb.innerHTML='<tr><td colspan="6" class="text-center text-muted">Loading...</td></tr>'; fetch('admin_borrow_center.php?action=list_reservation_serials&request_id='+encodeURIComponent(rid),{cache:'no-store'}).then(function(r){return r.json();}).then(function(j){ render((j&&j.items)||[]); }).catch(function(){ if(tb) tb.innerHTML='<tr><td colspan="6" class="text-center text-danger">Failed to load.</td></tr>'; }); }
      document.addEventListener('DOMContentLoaded', function(){ var btn=document.getElementById('ersViewAvailBtn'); var ref=document.getElementById('ersRefreshAvailBtn'); var wrap=document.getElementById('ersAvailWrap'); var mdl=document.getElementById('editResSerialModal'); var loaded=false; if(btn){ btn.addEventListener('click', function(){ if(!wrap) return; var sh = wrap.classList.contains('d-none'); if (sh){ wrap.classList.remove('d-none'); if(ref) ref.classList.remove('d-none'); if(!loaded){ load(); loaded=true; } } else { wrap.classList.add('d-none'); } }); }
        if(ref){ ref.addEventListener('click', function(){ load(); }); }
        if(mdl){ mdl.addEventListener('hidden.bs.modal', function(){ loaded=false; if(wrap){ wrap.classList.add('d-none'); } if(ref){ ref.classList.add('d-none'); } }); }
      });
    })();
  </script>

  <script>
    // Open Reservation Details from row click/keyboard (delegated)
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        var tbody = document.getElementById('reservationsTbody');
        if (!tbody) return;
        function openResFrom(el){ try { window._rvSrc = el; } catch(_) {}
          var mdl = document.getElementById('reservationDetailsModal');
          if (mdl) bootstrap.Modal.getOrCreateInstance(mdl).show();
        }
        tbody.addEventListener('click', function(e){
          if (e.target && e.target.closest && e.target.closest('.segmented-actions')) return;
          var tr = e.target && e.target.closest && e.target.closest('tr.reservation-row');
          if (tr) openResFrom(tr);
        });
        tbody.addEventListener('keydown', function(e){
          var tr = e.target && e.target.closest && e.target.closest('tr.reservation-row');
          if (!tr) return;
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openResFrom(tr); }
        });
      });
    })();
  </script>

  <script>
    // Populate Reservation Details modal
    (function(){
      var mdl = document.getElementById('reservationDetailsModal');
      if (mdl) {
        function fmtLocal(dt){ try { if(!dt) return '-'; var s=String(dt); if(/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(s)) s=s.replace(' ','T'); var d=new Date(s); if (isNaN(d.getTime())) return String(dt); var mm=('0'+(d.getMonth()+1)).slice(-2), dd=('0'+d.getDate()).slice(-2), yyyy=d.getFullYear().toString().slice(-2); var h=d.getHours()%12||12, m=('0'+d.getMinutes()).slice(-2), ap=d.getHours()<12?'AM':'PM'; return h+':'+m+' '+ap+' '+mm+'-'+dd+'-'+yyyy; } catch(_){ return String(dt)||'-'; } }
        mdl.addEventListener('show.bs.modal', function (event) {
          var trg = event.relatedTarget;
          var src = (trg && typeof trg.closest === 'function') ? trg.closest('[data-bs-target="#reservationDetailsModal"]') : null;
          var el = src || trg || (typeof window !== 'undefined' ? window._rvSrc : null);
          var user = el ? (el.getAttribute('data-user') || '') : '';
          var sid = el ? (el.getAttribute('data-school_id') || '') : '';
          var item = el ? (el.getAttribute('data-item') || '') : '';
          var cat = el ? (el.getAttribute('data-category') || '') : '';
          var loc = el ? (el.getAttribute('data-location') || '') : '';
          var rs = el ? (el.getAttribute('data-rstart_raw') || '') : '';
          var re = el ? (el.getAttribute('data-rend_raw') || '') : '';
          document.getElementById('rvUser').textContent = user;
          document.getElementById('rvSid').textContent = sid;
          document.getElementById('rvItem').textContent = item;
          document.getElementById('rvCategory').textContent = cat || 'Uncategorized';
          document.getElementById('rvLocation').textContent = loc || '';
          document.getElementById('rvStart').textContent = fmtLocal(rs);
          document.getElementById('rvEnd').textContent = fmtLocal(re);
        });
      }
    })();
  </script>

  <!-- Lost/Damaged History Print Header Modal -->
  <!-- Borrowed Item Details Modal -->
  <div class="modal fade" id="borrowedDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Borrowed Item Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>User:</strong> <span id="bdUser"></span></div>
          <div class="mb-2"><strong>Serial ID:</strong> <span id="bdSerial"></span></div>
          <div class="mb-2"><strong>Item:</strong> <span id="bdModel"></span></div>
          <div class="mb-2"><strong>Category:</strong> <span id="bdCategory"></span></div>
          <div class="mb-2"><strong>Location:</strong> <span id="bdLocation"></span></div>
          <div class="mb-2"><strong>Expected Return:</strong> <span id="bdExpected"></span></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Ensure borrowed rows open details modal (delegated)
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        var tbody = document.getElementById('borrowedTbody');
        if (!tbody) return;
        function openDetailsFrom(el){ try { window._bdSrc = el; } catch(_) {}
          var mdl = document.getElementById('borrowedDetailsModal');
          if (mdl) bootstrap.Modal.getOrCreateInstance(mdl).show();
        }
        tbody.addEventListener('click', function(e){
          if (e.target && e.target.closest && e.target.closest('.segmented-actions')) return;
          var tr = e.target && e.target.closest && e.target.closest('tr.borrowed-row');
          if (tr) openDetailsFrom(tr);
        });
        tbody.addEventListener('keydown', function(e){
          var tr = e.target && e.target.closest && e.target.closest('tr.borrowed-row');
          if (!tr) return;
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDetailsFrom(tr); }
        });
      });
    })();
  </script>

  <script>
    // Populate Borrowed Item Details modal
    (function(){
      var mdl = document.getElementById('borrowedDetailsModal');
      if (mdl) {
        mdl.addEventListener('show.bs.modal', function (event) {
          var trg = event.relatedTarget;
          var src = (trg && typeof trg.closest === 'function') ? trg.closest('[data-bs-target="#borrowedDetailsModal"]') : null;
          var el = src || trg || (typeof window !== 'undefined' ? window._bdSrc : null);
          var user = el ? (el.getAttribute('data-user') || '') : '';
          var serial = el ? (el.getAttribute('data-serial') || '') : '';
          var model = el ? (el.getAttribute('data-model') || '') : '';
          var category = el ? (el.getAttribute('data-category') || '') : '';
          var location = el ? (el.getAttribute('data-location') || '') : '';
          var expRaw = el ? (el.getAttribute('data-expected_raw') || '') : '';
          var expTxt = expRaw ? (function(s){ try { return s ? new Date(s.replace(' ','T')).toLocaleString() : ''; } catch(_) { return s; } })(expRaw) : '';
          document.getElementById('bdUser').textContent = user;
          document.getElementById('bdSerial').textContent = serial;
          document.getElementById('bdModel').textContent = model;
          document.getElementById('bdCategory').textContent = category;
          document.getElementById('bdLocation').textContent = location;
          document.getElementById('bdExpected').textContent = expTxt || '-';
        });
      }
    })();
  </script>
  <div class="modal fade" id="borrowedDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Borrowed Item Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><strong>User:</strong> <span id="bdUser"></span></div>
          <div class="mb-2"><strong>Serial ID:</strong> <span id="bdSerial"></span></div>
          <div class="mb-2"><strong>Item:</strong> <span id="bdModel"></span></div>
          <div class="mb-2"><strong>Category:</strong> <span id="bdCategory"></span></div>
          <div class="mb-2"><strong>Location:</strong> <span id="bdLocation"></span></div>
          <div class="mb-2"><strong>Expected Return:</strong> <span id="bdExpected"></span></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Delete Whitelisted Serials Modal -->
  <div class="modal fade" id="bmDeleteUnitsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Whitelisted Serials</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div><strong>Category:</strong> <span id="bmDelCat"></span> &nbsp; <strong>Model:</strong> <span id="bmDelModel"></span></div>
            <div>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="bmDelSelectAll">Select All</button>
              <button type="button" class="btn btn-sm btn-outline-secondary ms-2 d-none" id="bmDelUnselectAll">Unselect All</button>
            </div>
          </div>
          <div id="bmDeleteBody"><div class="text-muted">Loading whitelisted serials...</div></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger" id="bmDeleteConfirmBtn">Delete Selected</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Populate Request Details modal
    (function(){
      var mdl = document.getElementById('requestDetailsModal');
      if (mdl) {
        mdl.addEventListener('show.bs.modal', function (event) {
          var trg = event.relatedTarget;
          var src = (trg && typeof trg.closest === 'function') ? trg.closest('[data-bs-target="#requestDetailsModal"]') : null;
          var el = src || trg;
          var user = el ? (el.getAttribute('data-user') || '') : '';
          var item = el ? (el.getAttribute('data-item') || '') : '';
          var qty  = el ? (el.getAttribute('data-qty') || '') : '';
          var loc  = el ? (el.getAttribute('data-loc') || '') : '';
          var avail = el ? (el.getAttribute('data-avail') || '') : '';
          var requested = el ? (el.getAttribute('data-requested') || '') : '';
          var reqtype = el ? (el.getAttribute('data-reqtype') || '') : '';
          document.getElementById('rdUser').textContent = user;
          document.getElementById('rdItem').textContent = item;
          document.getElementById('rdQty').textContent = qty;
          var rl = document.getElementById('rdLoc'); if (rl) rl.textContent = loc || '';
          var ra = document.getElementById('rdAvail'); if (ra) ra.textContent = avail || '';
          var rr = document.getElementById('rdRequested'); if (rr) rr.textContent = requested || '';
          var rt = document.getElementById('rdType'); if (rt) rt.textContent = (reqtype||'').trim() ? reqtype : 'immediate';
        });
      }
      // Sync print form with current filters on submit
      document.addEventListener('DOMContentLoaded', function(){
        var pf = document.getElementById('ldPrintHistoryForm');
        if (pf) {
          pf.addEventListener('submit', function(){
            var ev = document.getElementById('ldEventFilter');
            var cs = document.getElementById('ldCurrentFilter');
            var evOut = document.getElementById('ldPrintEvent');
            var csOut = document.getElementById('ldPrintStatus');
            if (evOut) evOut.value = (ev && ev.value) ? ev.value : 'All';
            if (csOut) csOut.value = (cs && cs.value) ? cs.value : 'All';
          });
        }
      });
    })();

    // Approve with Scan Modal logic
    (function(){
      var mdl = document.getElementById('approveScanModal');
      var reqIdEl = document.getElementById('asReqId');
      var itemEl = document.getElementById('asItem');
      var qtyEl = document.getElementById('asQty');
      var inputId = document.getElementById('asSerial');
      var statusEl = document.getElementById('asStatus');
      var startBtn = document.getElementById('asStart');
      var stopBtn = document.getElementById('asStop');
      var readerDiv = document.getElementById('asReader');
      var form = document.getElementById('asForm');
      var imgInput = document.getElementById('asImageFile');
      var camWrap = document.getElementById('asCamWrap');
      var camSelect = document.getElementById('asCameraSelect');
      var readerDiv = document.getElementById('asReader');
      var refreshBtn = document.getElementById('asRefreshCams');
      var scanner = null, scanning=false;
      var approveBtn = document.getElementById('asApproveBtn');
      var reqTypeField = document.getElementById('asReqType');
      var typeBadge = document.getElementById('asReqTypeDisplay');
      var rtImmediateDiv = document.getElementById('immediateFields');
      var rtReserveDiv = document.getElementById('reservationFields');
      var expRetRO = document.getElementById('asExpectedReturnRO');
      var expRetField = document.getElementById('asExpectedReturnField');
      var resStartRO = document.getElementById('asReserveStartRO');
      var resEndRO = document.getElementById('asReserveEndRO');
      var resStartField = document.getElementById('asReserveStartField');
      var resEndField = document.getElementById('asReserveEndField');
      var camsCache = [];
      var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
      function two(n){ n=parseInt(n,10); return (n<10?'0':'')+n; }
      function fmtDisplay(dt){
        if (!dt) return '';
        try {
          // Accept ISO like 2025-10-29T10:00 or 'YYYY-MM-DD HH:MM[:SS]'
          let d=null;
          if (typeof dt==='string') {
            let s=dt.trim();
            // Normalize space format to 'YYYY-MM-DDTHH:MM'
            if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(s)) { s = s.replace(' ', 'T'); }
            d = new Date(s);
            if (isNaN(d.getTime())) {
              // Manual parse
              const m = dt.match(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
              if (m) { d = new Date(parseInt(m[1],10), parseInt(m[2],10)-1, parseInt(m[3],10), parseInt(m[4],10), parseInt(m[5],10), 0); }
            }
          } else if (dt instanceof Date) { d = dt; }
          if (!d || isNaN(d.getTime())) return String(dt);
          let h=d.getHours(); const m=two(d.getMinutes()); const ampm = h>=12?'PM':'AM'; h = h%12; if (h===0) h=12; const mm=two(d.getMonth()+1); const dd=two(d.getDate()); const yyyy=d.getFullYear();
          return mm+'-'+dd+'-'+yyyy+' '+two(h)+':'+m+ampm;
        } catch(_) { return String(dt); }
      }
      function applyReqType(val, expRet, rs, re){
        reqTypeField.value = val || '';
        if (val === 'reservation') {
          typeBadge.textContent = 'Reservation';
          rtReserveDiv.style.display='block'; rtImmediateDiv.style.display='none';
          resStartRO.value = fmtDisplay(rs||'');
          resEndRO.value = fmtDisplay(re||'');
          resStartField.value = rs || '';
          resEndField.value = re || '';
          expRetRO.value = '';
          expRetField.value = '';
        } else {
          typeBadge.textContent = 'Immediate';
          rtReserveDiv.style.display='none'; rtImmediateDiv.style.display='block';
          expRetRO.value = fmtDisplay(expRet||'');
          expRetField.value = expRet || '';
          resStartRO.value = '';
          resEndRO.value = '';
          resStartField.value = '';
          resEndField.value = '';
        }
      }
      function setStatus(t,cls){ if(statusEl){ statusEl.textContent=t; statusEl.className='small '+(cls||'text-muted'); } }
      function stop(){
        if (scanner && scanning){
          scanner.stop().then(()=>{
            try{ scanner.clear(); }catch(_){ }
            scanner = null;
            scanning=false;
            startBtn.style.display='inline-block';
            stopBtn.style.display='none';
            setStatus('Scanner stopped.','text-muted');
          }).catch(()=>{ try{ scanner=null; }catch(_){} });
        } else {
          try{ if(scanner){ try{ scanner.clear(); }catch(_){ } scanner=null; } }catch(_){ }
          scanning=false;
        }
      }
      function listCams(){
        if (!camSelect) return;
        camSelect.innerHTML = '';
        if (typeof Html5Qrcode === 'undefined' || !Html5Qrcode.getCameras) return;
        var p = null; try { if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) { p = navigator.mediaDevices.getUserMedia({ video: true }); } } catch(_){ }
        (p ? p.then(function(){ return Html5Qrcode.getCameras(); }) : Html5Qrcode.getCameras()).then(function(cams){
          camsCache = cams || [];
          camsCache.forEach(function(c, idx){
            var opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.label || ('Camera '+(idx+1));
            camSelect.appendChild(opt);
          });
          // Restore previously used camera if it still exists; otherwise prefer a back/environment camera, then first
          var saved = '';
          try { saved = localStorage.getItem('as_camera') || ''; } catch(_){ saved = ''; }
          var selectedId = '';
          if (saved) {
            try {
              var exists = camsCache.some(function(c){ return c.id === saved; });
              if (exists) { selectedId = saved; }
            } catch(_){ }
          }
          if (!selectedId && camsCache.length > 0) {
            var pref = null;
            try { pref = camsCache.find(function(c){ return /back|rear|environment/i.test(String(c.label||'')); }); } catch(_){ }
            if (pref && pref.id) { selectedId = pref.id; }
            else { selectedId = camsCache[0].id; }
          }
          if (selectedId) { camSelect.value = selectedId; }
        }).catch(function(){ /* ignore */ });
      }
      function startWithSelected(){
        if (scanning || typeof Html5Qrcode==='undefined') return;
        setStatus('Starting camera...','text-info');
        try{
          try{ if (scanner) { try{ scanner.clear(); }catch(_){ } scanner = null; } }catch(_){ }
          scanner=new Html5Qrcode('asReader');
          var id = (camSelect && camSelect.value) ? camSelect.value : (camsCache[0] && camsCache[0].id);
          if (!id) { setStatus('No camera found','text-danger'); return; }
          try { localStorage.setItem('as_camera', id); } catch(_){ }
          var cfg = {fps:10,qrbox:{width:250,height:250}};
          function applyVideoTweaks(){
            var v=null; try{ v=document.querySelector('#asReader video'); if(v){ v.setAttribute('playsinline',''); v.setAttribute('webkit-playsinline',''); v.muted=true; } }catch(_){ }
          }
          function markStarted(){
            scanning=true;
            startBtn.style.display='none';
            stopBtn.style.display='inline-block';
            applyVideoTweaks();
            setStatus('Camera active. Scan a QR.','text-success');
          }
          scanner.start(id, cfg, onScanSuccess, ()=>{})
            .then(function(){ markStarted(); })
            .catch(function(err){
              // Fallback: try environment-facing camera constraints when device id fails
              scanner.start({ facingMode: { exact: 'environment' } }, cfg, onScanSuccess, ()=>{})
                .then(function(){ markStarted(); })
                .catch(function(){
                  scanner.start({ facingMode: 'environment' }, cfg, onScanSuccess, ()=>{})
                    .then(function(){ markStarted(); })
                    .catch(function(e2){ setStatus('Camera error: '+((e2&&e2.message)||'start failure'),'text-danger'); });
                });
            });
        } catch(e){ setStatus('Scanner init failed','text-danger'); }
      }
      function validateCurrentId(modelId){
        var rid = (reqIdEl && reqIdEl.value) ? reqIdEl.value : '';
        var sn = (inputId && inputId.value) ? inputId.value.trim() : '';
        if (!rid || !sn) { approveBtn && (approveBtn.disabled = true); return; }
        setStatus('Validating Serial ID...','text-info');
        var body = 'request_id='+encodeURIComponent(rid)+'&serial_no='+encodeURIComponent(sn);
        fetch('admin_borrow_center.php?action=validate_model_id', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
          .then(r=>r.json()).then(function(resp){
            if (resp && resp.ok){ setStatus('Valid: '+(resp.model||'')+' | '+(resp.category||''),'text-success'); approveBtn && (approveBtn.disabled = false); }
            else { setStatus(resp && resp.reason ? resp.reason : 'Invalid Serial ID.','text-danger'); approveBtn && (approveBtn.disabled = true); }
          }).catch(function(){ setStatus('Validation error','text-danger'); approveBtn && (approveBtn.disabled = true); });
      }
      function parseFlexiblePayload(txt){
        // Try JSON first
        try { var o = JSON.parse(txt); if (o && typeof o==='object') return o; } catch(_e) {}
        // Try URL or query string
        try {
          var s = txt.trim();
          var qs = '';
          if (/^https?:\/\//i.test(s)) { var u = new URL(s); qs = u.search || ''; }
          else if (s.includes('=') && (s.includes('&') || s.includes('=') )) { qs = s; }
          if (qs) {
            if (qs.startsWith('?')) qs = qs.slice(1);
            var usp = new URLSearchParams(qs);
            var obj = {};
            usp.forEach((v,k)=>{ obj[k]=v; });
            if (Object.keys(obj).length) return obj;
          }
        } catch(_e) {}
        // Try key:value pairs separated by newlines/commas
        try {
          var obj2 = {}; var parts = txt.split(/[,\n]+/);
          parts.forEach(function(p){ var kv = p.split(/[:=]/); if (kv.length>=2){ var k=kv[0].trim(); var v=kv.slice(1).join(':').trim(); if(k) obj2[k]=v; }});
          if (Object.keys(obj2).length) return obj2;
        } catch(_e) {}
        // If pure text/number, treat as serial_no (Serial ID)
        if (/^\s*[\w\-]+\s*$/.test(txt)) { return { serial_no: txt.trim() }; }
        return null;
      }
      function onScanSuccess(txt){
        var data = parseFlexiblePayload(txt);
        if (!data) { setStatus('Invalid QR format','text-danger'); return; }
        var serial = (data.serial_no || data.serial || data.sn || data.s || data.sid || '').toString().trim();
        var mdl = (data.model || data.item_name || data.name || '');
        var cat = (data.category || data.cat || '');
        if (serial){ inputId.value = String(serial); setStatus('Scanned Serial: '+serial+(mdl||cat?(' ('+[mdl,cat].filter(Boolean).join(' | ')+')'):'') ,'text-success'); stop(); validateCurrentId(); }
        else { setStatus('QR missing serial_no','text-danger'); }
      }
      mdl && mdl.addEventListener('show.bs.modal', function(e){
        var btn=e.relatedTarget; var rid=btn?.getAttribute('data-reqid')||''; var item=btn?.getAttribute('data-item')||''; var qty=btn?.getAttribute('data-qty')||'';
        var rtype = btn?.getAttribute('data-reqtype')||'';
        var exp = btn?.getAttribute('data-expected_return_at')||'';
        var rs = btn?.getAttribute('data-reserved_from')||'';
        var re = btn?.getAttribute('data-reserved_to')||'';
        var qrSerial = btn?.getAttribute('data-qr_serial')||'';
        reqIdEl.value=rid; itemEl.textContent=item; qtyEl.textContent=qty; inputId.value=''; approveBtn && (approveBtn.disabled = true); readerDiv.innerHTML='';
        applyReqType((rtype||'').toLowerCase()==='reservation'?'reservation':'immediate', exp, rs, re);
        if (qrSerial && qrSerial.trim() !== '') {
          // Prefilled from QR: lock UI to the provided serial
          inputId.value = qrSerial;
          try { inputId.readOnly = true; inputId.classList.add('bg-light'); } catch(_){ }
          if (startBtn) startBtn.style.display='none';
          if (stopBtn) stopBtn.style.display='none';
          if (camWrap) camWrap.style.display='none';
          var img = document.getElementById('asImageFile'); if (img) img.closest('.mt-2')?.classList.add('d-none');
          setStatus('Serial provided by QR submission. Scanning disabled for this request.','text-info');
          // Auto-validate the serial to enable Approve button
          validateCurrentId();
        } else {
          // Normal flow with scanner allowed
          try { inputId.readOnly = false; inputId.classList.remove('bg-light'); } catch(_){ }
          if (startBtn) startBtn.style.display='inline-block';
          if (stopBtn) stopBtn.style.display='none';
          (function(){ var img = document.getElementById('asImageFile'); if (img) { var w = img.closest('.mt-2'); if (w) w.classList.remove('d-none'); } })();
          setStatus('You can scan a QR or enter ID manually.','text-muted');
          if (camWrap) camWrap.style.display = isMobile ? 'block' : 'block';
          listCams();
          if (startBtn) startBtn.disabled = false;
          if (stopBtn) stopBtn.disabled = false;
        }
      });
      startBtn && startBtn.addEventListener('click', function(){ startWithSelected(); });
      stopBtn && stopBtn.addEventListener('click', function(){ stop(); });
      form && form.addEventListener('submit', function(){ stop(); /* server uses request values; hidden fields already set for compatibility */ });
      inputId && inputId.addEventListener('input', function(){ approveBtn && (approveBtn.disabled = true); if (this.value.trim()) { validateCurrentId(); } else { setStatus('You can scan a QR or enter ID manually.','text-muted'); } });
      camSelect && camSelect.addEventListener('change', function(){
        localStorage.setItem('as_camera', this.value);
        if (scanning) { stop(); setTimeout(startWithSelected, 100); }
      });
      refreshBtn && refreshBtn.addEventListener('click', function(){ stop(); if (readerDiv) readerDiv.innerHTML=''; listCams(); setTimeout(startWithSelected, 150); });
      imgInput && imgInput.addEventListener('change', function(){ var f=this.files&&this.files[0]; if(!f){return;} stop(); setStatus('Processing image...','text-info');
        function tryScanSequence(file){
          // 1) Static scanFile with preview
          if (typeof Html5Qrcode !== 'undefined' && typeof Html5Qrcode.scanFile === 'function') {
            return Html5Qrcode.scanFile(file, true)
              .catch(function(){
                // 2) Static scanFile without preview
                return Html5Qrcode.scanFile(file, false);
              })
              .catch(function(){
                // 3) Instance scan
                var inst = new Html5Qrcode('asReader');
                return inst.scanFile(file, true).finally(function(){ try{inst.clear();}catch(e){} });
              });
          }
          // Fallback to instance only
          var inst2 = new Html5Qrcode('asReader');
          return inst2.scanFile(file, true).finally(function(){ try{inst2.clear();}catch(e){} });
        }
        tryScanSequence(f).then(function(txt){ onScanSuccess(txt); setStatus('QR scanned from image.','text-success'); })
          .catch(function(){
            setStatus('No QR found in image. Tips: use a clear, high-contrast PNG/JPG, ensure the QR is large and flat (no tilt), and try re-exporting the QR.', 'text-danger');
          });
      });
    })();
  </script>

  <!-- Lost/Damaged History Modal -->
  <div class="modal fade" id="ldHistoryModal" tabindex="-1" aria-labelledby="ldHistoryLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header d-flex align-items-center justify-content-between">
          <h5 class="modal-title" id="ldHistoryLabel"><i class="bi bi-clock-history me-2"></i>Lost/Damaged History</h5>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
        </div>
        <div class="modal-body">
          <div class="row g-2 align-items-end mb-2">
            <div class="col-12 col-md-3">
              <label class="form-label">Event</label>
              <select class="form-select form-select-sm" id="ldEventFilter">
                <option value="">All</option>
                <option>Lost</option>
                <option>Permanently Lost</option>
                <option>Disposed</option>
                <option>Found</option>
                <option>Under Maintenance</option>
                <option>Fixed</option>
              </select>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Current Status</label>
              <select class="form-select form-select-sm" id="ldCurrentFilter">
                <option value="">All</option>
                <option value="Lost">Still Lost</option>
                <option value="Permanently Lost">Permanently Lost</option>
                <option value="Disposed">Disposed</option>
                <option value="Under Maintenance">Still Damaged</option>
                <option value="Found">Found</option>
                <option value="Fixed">Fixed</option>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Search (Model/Category)</label>
              <input type="text" class="form-control form-control-sm" id="ldSearchFilter" placeholder="Type to search..." />
            </div>
          </div>
          <!-- Print header details handled via a secondary modal -->
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle" id="ldHistoryTable">
              <thead class="table-light">
                <tr>
                  <th>Serial ID</th>
                  <th>Model</th>
                  <th>Category</th>
                  <th>User</th>
                  <th>School ID</th>
                  <th>Location</th>
                  <th>Event</th>
                  <th>By</th>
                  <th id="ldDateCol1">Date Damaged/Lost</th>
                  <th id="ldDateCol2">Date Fixed/Found</th>
                  <th>Remarks</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($ldHistory)): ?>
                  <tr class="ld-row"><td colspan="12" class="text-center text-muted">No history yet.</td></tr>
                <?php else: foreach ($ldHistory as $h): ?>
                  <?php $ca = trim((string)($h['current_action'] ?? '')); ?>
                  <tr class="ld-row" data-event="<?php echo htmlspecialchars($h['action']); ?>" data-current="<?php echo htmlspecialchars($ca); ?>" data-model="<?php echo htmlspecialchars($h['model_key']); ?>" data-category="<?php echo htmlspecialchars($h['category']); ?>">
                    <td><?php echo htmlspecialchars((string)($h['serial_no'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($h['model_key']); ?></td>
                    <td><?php echo htmlspecialchars($h['category']); ?></td>
                    <td><?php echo htmlspecialchars($h['username'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars((string)($h['user_school_id'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($h['location'] ?? '')); ?></td>
                    <td>
                      <?php
                        $ev = trim((string)$h['action']);
                        $evLower = strtolower($ev);
                        // Normalize display for badge style: base Lost vs Damaged
                        if (in_array($evLower, ['lost','permanently lost'])) {
                          $evDisplay = 'Lost';
                          $evCls = 'danger';
                        } else {
                          // Under Maintenance / Damaged / Fixed / Disposed / Disposal
                          $evDisplay = 'Damaged';
                          $evCls = 'warning text-dark';
                        }
                      ?>
                      <span class="badge bg-<?php echo $evCls; ?>"><?php echo htmlspecialchars($evDisplay); ?></span>
                    </td>
                    <td><?php echo htmlspecialchars($h['username'] ?: '-'); ?></td>
                    <td>
                      <?php
                        $lostDamagedDate = '';
                        $evNow = (string)($h['action'] ?? '');
                        // If this specific log row is Lost or Under Maintenance, show its own timestamp
                        if ($evNow === 'Lost' || $evNow === 'Under Maintenance') {
                          $lostDamagedDate = (string)($h['created_at'] ?? '');
                        }
                        // Fallback to the latest known lost/damaged timestamps per model
                        if ($lostDamagedDate === '') {
                          if (!empty($h['last_lost_at'])) { $lostDamagedDate = (string)$h['last_lost_at']; }
                          elseif (!empty($h['last_maint_at'])) { $lostDamagedDate = (string)$h['last_maint_at']; }
                        }
                        echo $lostDamagedDate ? htmlspecialchars(date('h:i A m-d-y', strtotime($lostDamagedDate))) : '-';
                      ?>
                    </td>
                    <td>
                      <?php
                        $foundFixedDate = '';
                        // Per-episode resolution time: only show when this episode actually ended in Found or Fixed
                        $epResolved = (string)($h['episode_resolved_at'] ?? '');
                        if ($ca === 'Found' || $ca === 'Fixed') {
                          if ($epResolved !== '') { $foundFixedDate = $epResolved; }
                        }
                        echo $foundFixedDate ? htmlspecialchars(date('h:i A m-d-y', strtotime($foundFixedDate))) : '-';
                      ?>
                    </td>
                    <td><?php echo htmlspecialchars((string)($h['notes'] ?? '')) ?: '-'; ?></td>
                    <td>
                      <?php
                        if ($ca === 'Lost') { echo '<span class="badge bg-danger">Still Lost</span>'; }
                        elseif ($ca === 'Permanently Lost') { echo '<span class="badge bg-danger">Permanently Lost</span>'; }
                        elseif ($ca === 'Disposed') { echo '<span class="badge bg-danger">Disposed</span>'; }
                        elseif ($ca === 'Under Maintenance') { echo '<span class="badge bg-warning text-dark">Still Damaged</span>'; }
                        elseif ($ca === 'Found') { echo '<span class="badge bg-success">Found</span>'; }
                        elseif ($ca === 'Fixed') { echo '<span class="badge bg-success">Fixed</span>'; }
                        else { echo '<span class="badge bg-secondary">Unknown</span>'; }
                      ?>
                    </td>
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
  <script>
    // Populate Request Details modal
    (function(){
      var mdl = document.getElementById('requestDetailsModal');
      if (mdl) {
        mdl.addEventListener('show.bs.modal', function (event) {
          var btn = event.relatedTarget;
          var user = btn?.getAttribute('data-user') || '';
          var item = btn?.getAttribute('data-item') || '';
          var qty  = btn?.getAttribute('data-qty') || '';
          var details = btn?.getAttribute('data-details') || '';
          document.getElementById('rdUser').textContent = user;
          document.getElementById('rdItem').textContent = item;
          document.getElementById('rdQty').textContent = qty;
          var d = document.getElementById('rdDetails');
          d.textContent = details && details.trim() ? details : 'No details provided.';
        });
      }
    })();

    // Filters for Lost/Damaged Event Log
    (function(){
      function updateLdDateColumns(){
        var ev = (document.getElementById('ldEventFilter')?.value || '').trim();
        var col1 = document.getElementById('ldDateCol1');
        var col2 = document.getElementById('ldDateCol2');
        // Determine which date types to show
        var showPair = 'all';
        if (ev === 'Lost' || ev === 'Found') showPair = 'lost';
        else if (ev === 'Under Maintenance' || ev === 'Fixed') showPair = 'damaged';
        // Set headers
        if (showPair === 'lost') { if (col1) col1.textContent = 'Date Lost'; if (col2) col2.textContent = 'Date Found'; }
        else if (showPair === 'damaged') { if (col1) col1.textContent = 'Date Damaged'; if (col2) col2.textContent = 'Date Fixed'; }
        else { if (col1) col1.textContent = 'Date Damaged/Lost'; if (col2) col2.textContent = 'Date Fixed/Found'; }
        // Show/hide spans inside cells accordingly
        document.querySelectorAll('#ldHistoryTable tbody tr').forEach(function(tr){
          // Reset all spans hidden
          tr.querySelectorAll('.ld-date').forEach(function(sp){ sp.style.display = 'none'; });
          var tds = tr.querySelectorAll('td');
          var date1 = tds[5]; var date2 = tds[6];
          if (showPair === 'lost') {
            if (date1) { date1.querySelectorAll('.ld-date-lost').forEach(function(sp){ sp.style.display = ''; }); }
            if (date2) { date2.querySelectorAll('.ld-date-found').forEach(function(sp){ sp.style.display = ''; }); }
          } else if (showPair === 'damaged') {
            if (date1) { date1.querySelectorAll('.ld-date-damaged').forEach(function(sp){ sp.style.display = ''; }); }
            if (date2) { date2.querySelectorAll('.ld-date-fixed').forEach(function(sp){ sp.style.display = ''; }); }
          } else {
            // All: first column shows one of Damaged/Lost (prefer Damaged), second shows one of Found/Fixed (prefer Found)
            if (date1) {
              var dmg = date1.querySelector('.ld-date-damaged');
              var lst = date1.querySelector('.ld-date-lost');
              if (dmg && dmg.textContent.trim()) { dmg.style.display = ''; }
              else if (lst && lst.textContent.trim()) { lst.style.display = ''; }
            }
            if (date2) {
              var fnd = date2.querySelector('.ld-date-found');
              var fxd = date2.querySelector('.ld-date-fixed');
              if (fnd && fnd.textContent.trim()) { fnd.style.display = ''; }
              else if (fxd && fxd.textContent.trim()) { fxd.style.display = ''; }
            }
          }
        });
      }

      function applyLdFilters(){
        var ev = document.getElementById('ldEventFilter')?.value.toLowerCase() || '';
        var cur = document.getElementById('ldCurrentFilter')?.value.toLowerCase() || '';
        var q = (document.getElementById('ldSearchFilter')?.value || '').toLowerCase();
        document.querySelectorAll('#ldHistoryTable tbody tr.ld-row').forEach(function(tr){
          var tev = (tr.getAttribute('data-event') || '').toLowerCase();
          var tcur = (tr.getAttribute('data-current') || '').toLowerCase();
          var tmodel = (tr.getAttribute('data-model') || '').toLowerCase();
          var tcat = (tr.getAttribute('data-category') || '').toLowerCase();
          var ok = true;
          if (ev) {
            var matchCurrent = (ev === 'found' || ev === 'fixed' || ev === 'permanently lost' || ev === 'disposed');
            if (matchCurrent) { if (tcur !== ev) ok = false; }
            else { if (tev !== ev) ok = false; }
          }
          if (cur && tcur !== cur) ok = false;
          if (q && !(tmodel.includes(q) || tcat.includes(q))) ok = false;
          tr.style.display = ok ? '' : 'none';
        });
        updateLdDateColumns();
      }
      ['ldEventFilter','ldCurrentFilter','ldSearchFilter'].forEach(function(id){
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', applyLdFilters);
        if (el) el.addEventListener('change', applyLdFilters);
      });
      document.getElementById('ldHistoryModal')?.addEventListener('shown.bs.modal', function(){ updateLdDateColumns(); applyLdFilters(); });
    })();
    const bmCatModels = <?php echo json_encode($invCatModels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    const qtyStats = <?php echo json_encode($qtyStats, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    const borrowLimitMap = <?php echo json_encode($borrowLimitMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    const wlBuckets = <?php echo json_encode($wlBuckets, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    const bmBulkCatSelect = document.getElementById('bm_bulk_category');
    const bmModelsBody = document.getElementById('bm_models_body');
    const bmMasterCheck = document.getElementById('bm_master_check');
    const bmBulkSelectAllBtn = document.getElementById('bm_bulk_select_all');
    const heldCounts = <?php echo json_encode($heldCounts, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    function buildModelsTable() {
      const c = bmBulkCatSelect ? bmBulkCatSelect.value : '';
      const models = (c && bmCatModels[c]) ? bmCatModels[c].slice() : [];
      bmModelsBody.innerHTML = '';
      if (!models.length) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td colspan="4" class="text-center text-muted">No models found for this category.</td>';
        bmModelsBody.appendChild(tr);
        return;
      }
      models.forEach((m)=>{
        const key = String(m);
        const stats = (qtyStats[c] && qtyStats[c][key]) ? qtyStats[c][key] : {available:0,total:0};
        const total = (stats.total ?? 0);
        // Use current borrow_limit and whitelisted buckets to compute how many more units
        // can be added to the borrowable list, while staying within total lendable units.
        const curLimit = (borrowLimitMap[c] && typeof borrowLimitMap[c][key] !== 'undefined')
          ? parseInt(borrowLimitMap[c][key], 10)
          : 0;
        const wl = (wlBuckets[c] && wlBuckets[c][key]) ? wlBuckets[c][key] : { total:0, avail:0, lostDamaged:0 };
        const wlTotal = wl.total ?? 0;
        const wlLostDamaged = wl.lostDamaged ?? 0;
        const wlLendable = Math.max(0, wlTotal - wlLostDamaged);
        // Effective whitelisted capacity cannot exceed total lendable units
        const curCapacity = Math.min(wlLendable, total);
        const remaining = Math.max(0, total - curCapacity);
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><input type="checkbox" class="bm-row-check" name="models[]" value="${key.replace(/"/g,'&quot;')}" /></td>
          <td>${key}</td>
          <td>${remaining} / ${total}</td>
          <td><input type="number" class="form-control form-control-sm" name="limits[${key.replace(/"/g,'&quot;')}]" min="0" max="${remaining}" value="0" /></td>
        `;
        bmModelsBody.appendChild(tr);
      });
      // Attach change listeners so checking a row auto-fills its limit to remaining, unchecking sets to 0
      document.querySelectorAll('#bm_models_body .bm-row-check').forEach(function(cb){
        cb.addEventListener('change', function(){
          const tr = cb.closest('tr');
          const inp = tr ? tr.querySelector('input[name^="limits["]') : null;
          if (!inp) return;
          const max = parseInt(inp.getAttribute('max') || '0', 10) || 0;
          inp.value = cb.checked ? String(max) : '0';
        });
      });
      // When limits are edited manually, reflect in checkbox state
      document.querySelectorAll('#bm_models_body input[name^="limits["]').forEach(function(inp){
        inp.addEventListener('input', function(){
          const tr = inp.closest('tr');
          const cb = tr ? tr.querySelector('.bm-row-check') : null;
          if (!cb) return;
          const val = parseInt(inp.value || '0', 10) || 0;
          cb.checked = val > 0;
        });
      });
    }
    bmBulkCatSelect && bmBulkCatSelect.addEventListener('change', buildModelsTable);
    bmBulkSelectAllBtn && bmBulkSelectAllBtn.addEventListener('click', function(){
      document.querySelectorAll('#bm_models_body .bm-row-check').forEach(cb=>{
        cb.checked = true;
        const tr = cb.closest('tr');
        const inp = tr ? tr.querySelector('input[name^="limits["]') : null;
        if (inp) {
          const max = parseInt(inp.getAttribute('max') || '0', 10) || 0;
          inp.value = String(max);
        }
      });
    });
    bmMasterCheck && bmMasterCheck.addEventListener('change', function(){
      const checked = bmMasterCheck.checked;
      document.querySelectorAll('#bm_models_body .bm-row-check').forEach(cb=>{
        cb.checked = checked;
        const tr = cb.closest('tr');
        const inp = tr ? tr.querySelector('input[name^="limits["]') : null;
        if (inp) {
          const max = parseInt(inp.getAttribute('max') || '0', 10) || 0;
          inp.value = checked ? String(max) : '0';
        }
      });
    });
    // Build once on load if a category is already selected
    document.addEventListener('DOMContentLoaded', function(){
      if (bmBulkCatSelect && bmBulkCatSelect.value) { buildModelsTable(); }
    });
    function toggleSidebar(){ const sidebar=document.getElementById('sidebar-wrapper'); sidebar.classList.toggle('active'); if (window.innerWidth<=768){ document.body.classList.toggle('sidebar-open', sidebar.classList.contains('active')); } }
    document.addEventListener('click', function(event){ const sidebar=document.getElementById('sidebar-wrapper'); const toggleBtn=document.querySelector('.mobile-menu-toggle'); if (window.innerWidth<=768){ if (sidebar && toggleBtn && !sidebar.contains(event.target) && !toggleBtn.contains(event.target)) { sidebar.classList.remove('active'); document.body.classList.remove('sidebar-open'); } } });
  </script>
  <script>
    (function(){
      let fetchingDot = false;
      function pollDot(){
        if (document.visibilityState !== 'visible') return;
        if (fetchingDot) return; fetchingDot = true;
        fetch('admin_borrow_center.php?action=pending_json')
          .then(r=>r.json())
          .then(d=>{
            const items = (d && Array.isArray(d.pending)) ? d.pending : [];
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
          })
          .catch(()=>{})
          .finally(()=>{ fetchingDot = false; });
      }
      pollDot();
      setInterval(pollDot, 2000);
    })();
    // Delete whitelisted serials modal logic
    (function(){
      const modalEl = document.getElementById('bmDeleteUnitsModal');
      const bodyEl = document.getElementById('bmDeleteBody');
      const catEl = document.getElementById('bmDelCat');
      const modelEl = document.getElementById('bmDelModel');
      const btnAll = document.getElementById('bmDelSelectAll');
      const btnNone = document.getElementById('bmDelUnselectAll');
      const btnConfirm = document.getElementById('bmDeleteConfirmBtn');
      let curCat = '', curModel = '';
      async function loadWhitelisted(cat, model){
        bodyEl.innerHTML = '<div class="text-muted">Loading whitelisted serials...</div>';
        try {
          const url = 'admin_borrow_center.php?action=list_borrowable_units&category='+encodeURIComponent(cat)+'&model='+encodeURIComponent(model);
          const r = await fetch(url); const j = await r.json().catch(()=>({ok:false,items:[]}));
          const items = (j && j.ok && Array.isArray(j.items)) ? j.items : [];
          if (!items.length) { bodyEl.innerHTML = '<div class="text-muted">No whitelisted serials.</div>'; return; }
          const parts = ['<div class="d-flex flex-wrap gap-2">'];
          items.forEach(it=>{
            const mid = parseInt(it.model_id||0,10)||0;
            const sn = String(it.serial_no||'');
            const st = String(it.status||'');
            const blocked = /^(in use|reserved|lost|damaged|under maintenance)$/i.test(st);
            const disAttr = blocked ? ' disabled' : '';
            const badgeCls = (st==='In Use') ? 'bg-primary' : (st==='Reserved' ? 'bg-info' : (st==='Available' ? 'bg-success' : 'bg-secondary'));
            const esc = s=>String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m]));
            parts.push('<label class="border rounded px-2 py-1 d-inline-flex align-items-center">'+
              '<input type="checkbox" class="form-check-input me-2 bm-del-check" value="'+mid+'"'+disAttr+' />'+
              '<span class="small me-2">'+(sn?esc(sn):'(no serial)')+'</span>'+
              (st?('<span class="badge '+badgeCls+'">'+esc(st)+'</span>'):'')+
            '</label>');
          });
          parts.push('</div>');
          bodyEl.innerHTML = parts.join('');
        } catch(_) { bodyEl.innerHTML = '<div class="text-danger">Failed to load.</div>'; }
      }
      document.querySelectorAll('.bm-delete-units').forEach(btn=>{
        btn.addEventListener('click', function(){
          curCat = this.getAttribute('data-category')||''; curModel = this.getAttribute('data-model')||'';
          if (catEl) catEl.textContent = curCat; if (modelEl) modelEl.textContent = curModel;
          loadWhitelisted(curCat, curModel);
        });
      });
      // Ensure Unselect All hidden initially on each load
      if (btnNone) { btnNone.classList.add('d-none'); }
      btnAll && btnAll.addEventListener('click', function(){
        bodyEl.querySelectorAll('.bm-del-check:not(:disabled)').forEach(cb=>{ cb.checked = true; });
        if (btnNone) btnNone.classList.remove('d-none');
      });
      btnNone && btnNone.addEventListener('click', function(){
        bodyEl.querySelectorAll('.bm-del-check:not(:disabled)').forEach(cb=>{ cb.checked = false; });
        if (btnNone) btnNone.classList.add('d-none');
      });
      btnConfirm && btnConfirm.addEventListener('click', function(){
        const picks = Array.from(bodyEl.querySelectorAll('.bm-del-check:checked')).map(cb=>cb.value);
        if (!picks.length) { const m = bootstrap.Modal.getInstance(modalEl); if (m) m.hide(); return; }
        const form = document.createElement('form'); form.method='POST'; form.action='admin_borrow_center.php';
        const add=(n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=String(v); form.appendChild(i); };
        add('do','delete_units'); add('category',curCat); add('model',curModel);
        picks.forEach(id=>{ const i=document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=String(id); form.appendChild(i); });
        document.body.appendChild(form); form.submit();
      });
    })();
  </script>
  <script>
    (function(){
      function gid(id){ return document.getElementById(id); }
      function showEl(e){ if(!e) return; e.classList.remove('d-none'); e.style.display='block'; }
      function hideEl(e){ if(!e) return; e.classList.add('d-none'); e.style.display='none'; }
      function widen(col){
        if (!col) return;
        if (!col.dataset._orig) col.dataset._orig = col.className;
        // Force full-width: strip Bootstrap grid col- classes and set to col-12
        var cls = col.className || '';
        cls = cls.split(/\s+/).filter(function(c){ return !/^col-(sm|md|lg|xl|xxl)?-\d+$/.test(c); }).join(' ');
        col.className = (cls ? (cls + ' ') : '') + 'col-12';
      }
      function resetCols(){
        ['pending-col','borrowed-col','reservations-col','returned-col'].forEach(function(id){
          var el = gid(id);
          if (el && el.dataset._orig) { el.className = el.dataset._orig; }
        });
      }
      function parseDefault(){
        // Force default to 'pending' regardless of hash or query params
        return 'pending';
      }
      function apply(mode){
        mode = (mode||'').toLowerCase();
        var pbRow = gid('pb-row');
        var retRow = gid('returned-list');
        var pCol = gid('pending-col');
        var bCol = gid('borrowed-col');
        var rsvCol = gid('reservations-col');
        var retCol = gid('returned-col');
        var pCard = gid('pending-list');
        var bCard = gid('borrowed-list');
        var rsvCard = gid('reservations-list');
        var retCard = gid('returned-card');
        hideEl(pbRow); hideEl(retRow);
        hideEl(pCol); hideEl(bCol); hideEl(rsvCol); hideEl(retCol);
        hideEl(pCard); hideEl(bCard); hideEl(rsvCard); hideEl(retCard);
        resetCols();
        if (mode === 'borrowed') { showEl(pbRow); showEl(bCol); showEl(bCard); widen(bCol); }
        else if (mode === 'reservations') { showEl(retRow); showEl(rsvCol); showEl(rsvCard); widen(rsvCol); }
        else if (mode === 'returned') { showEl(retRow); showEl(retCol); showEl(retCard); widen(retCol); }
        else { showEl(pbRow); showEl(pCol); showEl(pCard); widen(pCol); }
      }
      // Expose for inline onchange
      window.__brApply = apply;
      function init(){
        var sel = gid('brViewSelect');
        if (!sel) return;
        var def = parseDefault();
        sel.value = def;
        apply(def);
        sel.addEventListener('change', function(){ apply(this.value); });
        window.addEventListener('hashchange', function(){ var d=parseDefault(); sel.value=d; apply(d); });
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
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
  <button type="button" class="btn btn-primary bottom-nav-toggle d-md-none" id="bnToggleBC" aria-controls="bcBottomNav" aria-expanded="false" title="Open menu">
    <i class="bi bi-list"></i>
  </button>
  <nav class="bottom-nav d-md-none hidden" id="bcBottomNav">
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
      var btn = document.getElementById('bnToggleBC');
      var nav = document.getElementById('bcBottomNav');
      if (btn && nav) {
        btn.addEventListener('click', function(){
          var hid = nav.classList.toggle('hidden');
          btn.setAttribute('aria-expanded', String(!hid));
          // move button up when nav is visible and swap icon/title
          if (!hid) {
            btn.classList.add('raised');
            btn.title = 'Close menu';
            var i = btn.querySelector('i'); if (i) { i.className = 'bi bi-x'; }
            try{ if (typeof adjustAdminToastOffset === 'function') adjustAdminToastOffset(); }catch(_){ }
          } else {
            btn.classList.remove('raised');
            btn.title = 'Open menu';
            var i2 = btn.querySelector('i'); if (i2) { i2.className = 'bi bi-list'; }
            try{ if (typeof adjustAdminToastOffset === 'function') adjustAdminToastOffset(); }catch(_){ }
          }
        });
        // Initialize toast offset according to current state
        try{ if (typeof adjustAdminToastOffset === 'function') adjustAdminToastOffset(); }catch(_){ }
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
  <script>
    (function(){
      function norm(s){ return String(s||'').toLowerCase(); }
      function attachScopedSearch(inputId, tbodyId, rowSelector, attrKeys){
        var inp = document.getElementById(inputId);
        var tb = document.getElementById(tbodyId);
        if (!inp || !tb) return;
        function apply(){
          var q = norm(inp.value);
          tb.querySelectorAll(rowSelector).forEach(function(tr){
            var hay = '';
            (attrKeys||[]).forEach(function(k){ hay += ' ' + norm(tr.getAttribute(k)||''); });
            var match = (!q || hay.indexOf(q) !== -1);
            tr.setAttribute('data-filtered', match ? '1' : '0');
            if (match) { try{ tr.style.removeProperty('display'); }catch(_){} } else { tr.style.display = 'none'; }
          });
        }
        inp.addEventListener('input', apply);
        inp.addEventListener('keyup', apply);
        inp.addEventListener('change', apply);
        // Observe only row additions/removals to avoid loops from our own attribute writes
        var mo = new MutationObserver(function(muts){
          for (var i=0;i<muts.length;i++){ if (muts[i].type === 'childList') { apply(); break; } }
        });
        try { mo.observe(tb, { childList: true }); } catch(_){ }
        // initialize state so CSS can enforce visibility
        apply();
      }
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function(){
          // Pending: search only by Request ID and item name
          attachScopedSearch('pendingSearch', 'pendingTbody', 'tr.pending-row', ['data-reqid','data-item']);
          // Borrowed: search only by Request ID and model name
          attachScopedSearch('borrowedSearch', 'borrowedTbody', 'tr.borrowed-row', ['data-reqid','data-model']);
        });
      } else {
        attachScopedSearch('pendingSearch', 'pendingTbody', 'tr.pending-row', ['data-reqid','data-item']);
        attachScopedSearch('borrowedSearch', 'borrowedTbody', 'tr.borrowed-row', ['data-reqid','data-model']);
      }
    })();
  </script>
  <script src="page-transitions.js?v=<?php echo filemtime(__DIR__.'/page-transitions.js'); ?>"></script>
</body>
</html>
