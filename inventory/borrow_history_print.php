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
if (!isset($_SESSION['username']) || ($_SESSION['usertype'] ?? '') !== 'admin') {
    http_response_code(401);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Not authorized</title></head><body>Not authorized</body></html>';
    exit();
}

date_default_timezone_set('Asia/Manila');

// Default Prepared by from current admin full name (fallback to username)
$preparedByDefault = (string)($_SESSION['username'] ?? '');
try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $dbTmp = get_mongo_db();
    $uDoc = $dbTmp->selectCollection('users')->findOne(['username' => ($_SESSION['username'] ?? '')], ['projection' => ['full_name' => 1]]);
    $full = $uDoc && isset($uDoc['full_name']) ? trim((string)$uDoc['full_name']) : '';
    if ($full !== '') { $preparedByDefault = $full; }
} catch (Throwable $e) { /* ignore */ }

// Optional filters
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$username = trim($_GET['user'] ?? '');

$history = [];
$usedMongo = false;

// Try MongoDB first (to avoid MySQL dependency/fatal)
try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $ubCol = $db->selectCollection('user_borrows');
    $iiCol = $db->selectCollection('inventory_items');
    $uCol  = $db->selectCollection('users');

    $match = [];
    if ($username !== '') { $match['username'] = $username; }
    // Fetch many, sort desc similar to SQL
    $cur = $ubCol->find($match, ['sort' => ['borrowed_at' => -1, 'id' => -1], 'limit' => 1000]);
    $rows = [];
    foreach ($cur as $doc) { $rows[] = (array)$doc; }
    // Date range filter (best-effort on string dates)
    if ($date_from !== '' || $date_to !== '') {
        $fromTs = $date_from !== '' ? strtotime($date_from.' 00:00:00') : null;
        $toTs   = $date_to   !== '' ? strtotime($date_to.' 23:59:59')   : null;
        $rows = array_values(array_filter($rows, function($r) use ($fromTs,$toTs){
            $ba = trim((string)($r['borrowed_at'] ?? ''));
            if ($ba === '') return false;
            $ts = strtotime($ba);
            if ($ts === false) return false;
            if ($fromTs !== null && $ts < $fromTs) return false;
            if ($toTs   !== null && $ts > $toTs)   return false;
            return true;
        }));
    }
    foreach ($rows as $r) {
        $usernameRow = (string)($r['username'] ?? '');
        $full_name = '';
        if ($usernameRow !== '') {
            try { $u = $uCol->findOne(['username'=>$usernameRow], ['projection'=>['id'=>1,'full_name'=>1,'school_id'=>1]]); } catch (Throwable $e) { $u = null; }
            if ($u) { $full_name = (string)($u['full_name'] ?? ''); $user_id = (string)($u['id'] ?? ''); $school_id = (string)($u['school_id'] ?? ''); }
        }
        if ($full_name === '' && $usernameRow !== '') { $full_name = $usernameRow; }

        $model_id = intval($r['model_id'] ?? 0);
        $model_name = '';
        $category = '';
        $serial_no = '';
        if ($model_id > 0) {
            try { $itm = $iiCol->findOne(['id'=>$model_id], ['projection'=>['model'=>1,'item_name'=>1,'category'=>1,'serial_no'=>1]]); } catch (Throwable $e) { $itm = null; }
            if ($itm) {
                $mn = trim((string)($itm['model'] ?? ''));
                $model_name = $mn !== '' ? $mn : (string)($itm['item_name'] ?? '');
                $category = trim((string)($itm['category'] ?? ''));
                $serial_no = (string)($itm['serial_no'] ?? '');
            }
        }
        if ($category === '') { $category = 'Uncategorized'; }
        // Normalize times
        $ba = '';
        $ra = '';
        try {
            if (isset($r['borrowed_at']) && $r['borrowed_at'] instanceof MongoDB\BSON\UTCDateTime) {
                $dt = $r['borrowed_at']->toDateTime();
                $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                $ba = $dt->format('Y-m-d H:i:s');
            } else { $ba = (string)($r['borrowed_at'] ?? ''); }
        } catch (Throwable $_t1) { $ba = (string)($r['borrowed_at'] ?? ''); }
        try {
            if (isset($r['returned_at']) && $r['returned_at'] instanceof MongoDB\BSON\UTCDateTime) {
                $dt2 = $r['returned_at']->toDateTime();
                $dt2->setTimezone(new DateTimeZone('Asia/Manila'));
                $ra = $dt2->format('Y-m-d H:i:s');
            } else { $ra = (string)($r['returned_at'] ?? ''); }
        } catch (Throwable $_t2) { $ra = (string)($r['returned_at'] ?? ''); }
        $history[] = [
            'user_id' => isset($user_id) ? (string)$user_id : '',
            'school_id' => isset($school_id) ? (string)$school_id : '',
            'full_name' => $full_name,
            'username' => $usernameRow,
            'serial_no' => $serial_no,
            'model_name' => $model_name,
            'category' => $category,
            'borrowed_at' => $ba,
            'returned_at' => $ra,
        ];
    }
    $usedMongo = true;
} catch (Throwable $e) {
    $usedMongo = false;
}

// If Mongo failed, leave $history empty and render safely
$autoPrint = (isset($_GET['autoprint']) && $_GET['autoprint'] == '1');
// Let the browser handle page breaks; render all rows in a single sequence
$pages = !empty($history) ? [$history] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Borrow History Print Preview</title>
  <link href="css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo file_exists(__DIR__.'/images/logo-removebg.png') ? (filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png')) : time(); ?>" />
  <style>
    @page { size: A4 portrait; margin: 0.30in; }
    @media print {
      .no-print { display: none !important; }
      html, body { margin: 0 !important; background: #ffffff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      * { background: #ffffff !important; color: #000 !important; box-shadow: none !important; }
      .print-table thead { display: table-header-group; }
      .print-table { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 10px; }
      .print-table th, .print-table td { padding: .40rem .45rem; vertical-align: middle; line-height: 1.30; text-align: left; }
      .print-table thead th { font-size: 11px; }
      .print-table tbody td { font-size: 9px; }
      .print-table .col-datetime { white-space: normal; font-size: 9px; }
      .col-datetime .dt { white-space: normal; }
      .table-scroll { max-height: none !important; overflow: visible !important; }
      .table-responsive { max-height: none !important; overflow: visible !important; }
      .print-doc { width: 100% !important; border-collapse: collapse !important; border-spacing: 0 !important; }
      .print-doc thead, .print-doc tfoot { display: table-row-group !important; }
      .print-doc thead tr:first-child { page-break-before: avoid !important; break-before: avoid-page !important; }
      .page-break { page-break-before: always !important; break-before: page !important; }
      .container-fluid { padding-left: 0 !important; padding-right: 0 !important; }
      #page-content-wrapper, .print-doc { margin-left: 0 !important; margin-right: 0 !important; }
      .report-title { margin: 6px 0 14px !important; }
      .print-doc .print-table { margin-top: 10px !important; }
      .container-fluid.pb-3 { padding-bottom: 0 !important; }
      .eca-footer { margin-top: 16px !important; }
      /* Responsive font shrinking helpers */
      .shrink-1 { font-size: 11px !important; }
      .shrink-2 { font-size: 10px !important; }
      .shrink-3 { font-size: 9px !important; }
      /* Datetime text container to enforce ellipsis */
      .dt { display: inline-block; max-width: 100%; white-space: nowrap; }
      .col-datetime .dt .dt-date,
      .col-datetime .dt .dt-time { display: block; }
      .col-datetime .dt { line-height: 1.35; min-height: calc(1.35em * 2); }
      .two-line { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; white-space: normal; line-height: 1.35; max-height: calc(1.35em * 2); }
    }
    .table-scroll { max-height: 480px; overflow-y: auto; }
    .table-responsive { margin-top: 8px; }
    .print-doc .print-table { margin-top: 10px; }
    .two-line { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; white-space: normal; line-height: 1.35; max-height: calc(1.35em * 2); }
    .print-table .col-datetime { white-space: normal; font-size: 10px; }
    .print-table .col-datetime .dt { display: inline-block; line-height: 1.35; min-height: calc(1.35em * 2); white-space: normal; }
    .print-table .col-datetime .dt .dt-date,
    .print-table .col-datetime .dt .dt-time { display: block; }
    .table-scroll thead th { position: sticky; top: 0; background: #f8f9fa; z-index: 1; }
    .eca-header { text-align: center; margin-bottom: 14px; }
    .eca-header .eca-title { font-weight: 400; letter-spacing: 6px; font-size: 14pt; }
    .eca-header .eca-sub { margin-top: 2px; font-weight: 600; font-size: 12pt; }
    .eca-meta { display: flex; align-items: center; justify-content: space-between; font-size: 9pt; margin-top: 6px; margin-bottom: 10px; }
    .report-title { text-align: center; font-weight: 400; font-size: 14pt; margin: 14px 0 12px; text-transform: uppercase; }
    .eca-form-row { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-bottom: 20px; }
    .eca-form-row .field { display: flex; align-items: center; gap: 8px; }
    .eca-form-row label { font-weight: 600; font-size: 10pt; }
    .eca-input { border: none; border-bottom: 1px solid #000; outline: none; padding: 2px 4px; min-width: 200px; font-size: 10pt; }
    .eca-input.date-field { min-width: 160px; }
    @media screen { .eca-print-value { display: none !important; } }
    @media print {
      #prepInputFoot, #checkInputFoot,
      #deptInput, #dateInput { display: none !important; }
      .eca-print-value { display: inline-block !important; }
      /* Header lines for Department and Date (print only) */
      #deptPrintSpan { border-bottom: 1px solid #000; padding: 10px 4px 0; min-width: 220px; display: inline-block; line-height: 1; }
      #datePrintSpan { border-bottom: 1px solid #000; padding: 10px 4px 0; min-width: 160px; display: inline-block; line-height: 1; }
    }
    /* Footer name lines */
    .eca-footer .eca-print-value {
      border-bottom: 1px solid #000; padding: 0 4px 2px; min-width: 220px;
    }
    /* Grouped date header inside the table body */
    .date-group-header {
      font-weight: 600; margin-top: 8px; margin-bottom: 4px; font-size: 11px;
      display: inline-flex; align-items: center; gap: 6px;
    }
    .date-group-header .bi { font-size: 12px; }
    .date-separator {
      border-top: 1px solid #dee2e6; margin-top: 4px;
    }
    /* Header lines for Department and Date are defined inside @media print to avoid duplicate lines on screen */
  </style>
  <?php if ($autoPrint): ?>
  <script>document.addEventListener('DOMContentLoaded', function(){ window.print(); });</script>
  <?php endif; ?>
</head>
<body>
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
          <div class="report-title">BORROWED ITEM(S) REPORT</div>
          <div class="eca-form-row">
            <div class="field">
              <label for="deptInput">Department:</label>
              <input id="deptInput" class="eca-input" type="text" placeholder="Enter department" value="<?php echo htmlspecialchars($_GET['department'] ?? ''); ?>" />
              <span id="deptPrintSpan" class="eca-print-value"></span>
            </div>
            <div class="field">
              <label for="dateInput">Date:</label>
              <input id="dateInput" class="eca-input date-field" type="text" placeholder="MM-DD-YYYY" inputmode="numeric" pattern="\d{2}-\d{2}-\d{4}" value="<?php echo htmlspecialchars($_GET['date'] ?? ''); ?>" />
              <span id="datePrintSpan" class="eca-print-value"></span>
            </div>
          </div>
          <div class="d-flex justify-content-end mb-2 no-print">
            <button id="printBtn" class="btn btn-primary"><i class="bi bi-printer"></i> Print</button>
          </div>
        </div>
      </td></tr>
    </thead>
    <tbody>
      <tr><td style="padding:0;">
        <?php $pagesToRender = !empty($pages) ? $pages : [[]]; ?>
        <?php foreach ($pagesToRender as $pi => $displayRows): $padRows = 0; ?>
          <?php if (empty($displayRows)): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-sm align-middle print-table">
                <colgroup>
                  <col style="width: 16%" /> <!-- User -->
                  <col style="width: 12%" /> <!-- Student ID -->
                  <col style="width: 12%" /> <!-- Serial ID -->
                  <col style="width: 22%" /> <!-- Item/Model -->
                  <col style="width: 10%" /> <!-- Category -->
                  <col style="width: 14%" /> <!-- Time Borrowed -->
                  <col style="width: 14%" /> <!-- Time Returned -->
                </colgroup>
                <thead class="table-light">
                  <tr>
                    <th>User</th>
                    <th>Student ID</th>
                    <th>Serial ID</th>
                    <th>Item/Model</th>
                    <th>Category</th>
                    <th class="col-datetime">Time Borrowed</th>
                    <th class="col-datetime">Time Returned</th>
                  </tr>
                </thead>
                <tbody>
                  <tr><td colspan="7" class="text-center text-muted py-3">No history.</td></tr>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <?php
              // Group rows by borrowed date label while preserving original order
              $groups = [];
              $groupOrder = [];
              foreach ($displayRows as $hvTmp) {
                $label = 'Unknown date';
                if (!empty($hvTmp['borrowed_at'])) {
                  $tsTmp = strtotime($hvTmp['borrowed_at']);
                  if ($tsTmp !== false) {
                    $label = date('F j, Y', $tsTmp);
                  }
                }
                if (!isset($groups[$label])) {
                  $groups[$label] = [];
                  $groupOrder[] = $label;
                }
                $groups[$label][] = $hvTmp;
              }
            ?>
            <?php foreach ($groupOrder as $gi => $dateLabel): ?>
              <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle print-table">
                  <colgroup>
                    <col style="width: 16%" /> <!-- User -->
                    <col style="width: 12%" /> <!-- Student ID -->
                    <col style="width: 12%" /> <!-- Serial ID -->
                    <col style="width: 22%" /> <!-- Item/Model -->
                    <col style="width: 10%" /> <!-- Category -->
                    <col style="width: 14%" /> <!-- Time Borrowed -->
                    <col style="width: 14%" /> <!-- Time Returned -->
                  </colgroup>
                  <thead class="table-light">
                    <tr>
                      <th colspan="7">
                        <span class="date-group-header">
                          <i class="bi bi-calendar3"></i>
                          <span><?php echo htmlspecialchars($dateLabel); ?></span>
                        </span>
                      </th>
                    </tr>
                    <?php if ($gi === 0): ?>
                    <tr>
                      <th>User</th>
                      <th>Student ID</th>
                      <th>Serial ID</th>
                      <th>Item/Model</th>
                      <th>Category</th>
                      <th class="col-datetime">Time Borrowed</th>
                      <th class="col-datetime">Time Returned</th>
                    </tr>
                    <?php endif; ?>
                  </thead>
                  <tbody>
                    <?php foreach ($groups[$dateLabel] as $hv): ?>
                      <?php
                        $mn = trim((string)($hv['model_name'] ?? ''));
                        $cat = trim((string)($hv['category'] ?? ''));
                        $usr = trim((string)($hv['full_name'] ?? ($hv['username'] ?? '')));
                        $ser = trim((string)($hv['serial_no'] ?? ''));
                        $modelClass = '';
                        $catClass = '';
                        $userClass = '';
                        $serialClass = '';
                        $lenMn = strlen($mn);
                        $lenCat = strlen($cat);
                        $lenUsr = strlen($usr);
                        $lenSer = strlen($ser);
                        if ($lenMn > 30 && $lenMn <= 45) { $modelClass = 'shrink-1'; }
                        elseif ($lenMn > 45 && $lenMn <= 60) { $modelClass = 'shrink-2'; }
                        elseif ($lenMn > 60) { $modelClass = 'shrink-3'; }
                        if ($lenCat > 20 && $lenCat <= 30) { $catClass = 'shrink-1'; }
                        elseif ($lenCat > 30 && $lenCat <= 45) { $catClass = 'shrink-2'; }
                        elseif ($lenCat > 45) { $catClass = 'shrink-3'; }
                        if ($lenUsr > 18 && $lenUsr <= 26) { $userClass = 'shrink-1'; }
                        elseif ($lenUsr > 26 && $lenUsr <= 34) { $userClass = 'shrink-2'; }
                        elseif ($lenUsr > 34) { $userClass = 'shrink-3'; }
                        if ($lenSer > 12 && $lenSer <= 18) { $serialClass = 'shrink-1'; }
                        elseif ($lenSer > 18 && $lenSer <= 24) { $serialClass = 'shrink-2'; }
                        elseif ($lenSer > 24) { $serialClass = 'shrink-3'; }
                      ?>
                      <tr>
                        <td class="<?php echo $userClass; ?>"><span class="two-line"><?php echo htmlspecialchars($usr); ?></span></td>
                        <td><span class="two-line"><?php echo htmlspecialchars((string)($hv['school_id'] ?? '')); ?></span></td>
                        <td class="<?php echo $serialClass; ?>"><span class="two-line"><?php echo htmlspecialchars($ser); ?></span></td>
                        <td class="<?php echo $modelClass; ?>" title="<?php echo htmlspecialchars($mn); ?>"><span class="two-line"><?php echo htmlspecialchars($mn); ?></span></td>
                        <td class="<?php echo $catClass; ?>"><span class="two-line"><?php echo htmlspecialchars($cat); ?></span></td>
                        <td class="col-datetime"><?php
                          if (!empty($hv['borrowed_at'])) {
                            $ts = strtotime($hv['borrowed_at']);
                            if ($ts !== false) {
                              $timePart = date('g:iA', $ts);
                              echo '<span class="dt"><span class="dt-time">'.htmlspecialchars($timePart).'</span></span>';
                            }
                          }
                        ?></td>
                        <td class="col-datetime"><?php
                          if (!empty($hv['returned_at'])) {
                            $ts = strtotime($hv['returned_at']);
                            if ($ts !== false) {
                              $datePart = date('F d, Y', $ts);
                              $timePart = date('g:iA', $ts);
                              echo '<span class="dt"><span class="dt-date">'.htmlspecialchars($datePart).'</span><span class="dt-time">'.htmlspecialchars($timePart).'</span></span>';
                            }
                          }
                        ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if ($gi === count($groupOrder) - 1): ?>
                      <?php for ($i = 0; $i < $padRows; $i++): ?>
                        <tr>
                          <td>&nbsp;</td>
                          <td>&nbsp;</td>
                          <td>&nbsp;</td>
                          <td>&nbsp;</td>
                          <td>&nbsp;</td>
                          <td class="col-datetime"><span class="dt"><span class="dt-time">&nbsp;</span></span></td>
                          <td class="col-datetime"><span class="dt"><span class="dt-date">&nbsp;</span><span class="dt-time">&nbsp;</span></span></td>
                        </tr>
                      <?php endfor; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php if ($gi < count($groupOrder) - 1): ?>
                <div class="date-separator"></div>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($pi < count($pagesToRender) - 1): ?>
            <div class="page-break"></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </td></tr>
    </tbody>
    <tfoot>
      <tr><td style="padding:0;">
        <div class="eca-footer">
          <div class="eca-form-row">
            <div class="field">
              <label for="prepInputFoot">Prepared by:</label>
              <input id="prepInputFoot" class="eca-input" type="text" placeholder="Enter name" value="<?php echo htmlspecialchars($preparedByDefault); ?>" />
              <span id="prepPrintSpanFoot" class="eca-print-value"></span>
            </div>
            <div class="field">
              <label for="checkInputFoot">Checked by:</label>
              <input id="checkInputFoot" class="eca-input" type="text" placeholder="Enter name" />
              <span id="checkPrintSpanFoot" class="eca-print-value"></span>
            </div>
          </div>
        </div>
      </td></tr>
    </tfoot>
  </table>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Mirror Department/Date (header) and Prepared/Checked (footer inputs) to print spans
    (function(){
      function syncMirrors(){
        var dept = document.getElementById('deptInput')?.value || '';
        var date = document.getElementById('dateInput')?.value || '';
        var prep = document.getElementById('prepInputFoot')?.value || '';
        var check = document.getElementById('checkInputFoot')?.value || '';
        try { document.getElementById('deptPrintSpan').textContent = dept; } catch(_){ }
        try { document.getElementById('datePrintSpan').textContent = date; } catch(_){ }
        try {
          document.getElementById('prepPrintSpanFoot').textContent = (prep && prep.trim() !== '') ? prep : '\u00A0';
        } catch(_){ }
        try {
          document.getElementById('checkPrintSpanFoot').textContent = (check && check.trim() !== '') ? check : '\u00A0';
        } catch(_){ }
      }
      document.addEventListener('DOMContentLoaded', function(){
        syncMirrors();
        var di = document.getElementById('deptInput'); if (di) di.addEventListener('input', syncMirrors);
        var dt = document.getElementById('dateInput'); if (dt) dt.addEventListener('input', syncMirrors);
        var pif = document.getElementById('prepInputFoot'); if (pif) pif.addEventListener('input', syncMirrors);
        var cif = document.getElementById('checkInputFoot'); if (cif) cif.addEventListener('input', syncMirrors);
        window.addEventListener('beforeprint', syncMirrors);
      });
    })();
    // Print button handler
    (function(){
      var btn = document.getElementById('printBtn');
      if (btn) { btn.addEventListener('click', function(){ window.print(); }); }
    })();
  </script>
  <script src="page-transitions.js?v=<?php echo filemtime(__DIR__.'/page-transitions.js'); ?>"></script>
</body>
</html>
