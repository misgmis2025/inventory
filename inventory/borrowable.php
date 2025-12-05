<?php
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
if (!isset($_SESSION['username']) || ($_SESSION['usertype'] ?? 'user') !== 'user') {
    if (!isset($_SESSION['username'])) { header('Location: index.php'); } else { header('Location: admin_dashboard.php'); }
    exit();
}

require_once __DIR__ . '/../vendor/autoload.php';
$items = [];
$embed = isset($_GET['embed']) && $_GET['embed'] == '1';
try {
    require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $bcCol = $db->selectCollection('borrowable_catalog');
    $itCol = $db->selectCollection('inventory_items');
    $set = [];
    $cur = $bcCol->find(['active'=>1], ['projection'=>['category'=>1,'model_name'=>1]]);
    foreach ($cur as $d) {
        $cat = strtolower(trim((string)($d['category'] ?? 'Uncategorized')) ?: 'Uncategorized');
        $mn = strtolower(trim((string)($d['model_name'] ?? '')));
        if ($mn !== '') { $set[$cat.'|'.$mn] = true; }
    }
    // Build borrow limit map and compute constrained availability: min(limit - consumed, availNow)
    $bmCol = $db->selectCollection('borrowable_catalog');
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
    $aggAvail = $itCol->aggregate([
      ['$match'=>['status'=>'Available','quantity'=>['$gt'=>0]]],
      ['$project'=>[
        'category'=>['$ifNull'=>['$category','Uncategorized']],
        'model_key'=>['$ifNull'=>['$model','$item_name']],
        'q'=>['$ifNull'=>['$quantity',1]]
      ]],
      ['$group'=>['_id'=>['c'=>'$category','m'=>'$model_key'], 'avail'=>['$sum'=>'$q']]]
    ]);
    foreach ($aggAvail as $r) {
      $c=(string)($r->_id['c']??'Uncategorized'); $m=(string)($r->_id['m']??''); if ($m==='') continue; $availCounts[$c][$m]=(int)($r->avail??0);
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
    // Build final list constrained by borrow limits and membership in active borrowable catalog
    $items = [];
    foreach ($borrowLimitMap as $cat => $mods) {
      foreach ($mods as $mod => $limit) {
        $k = strtolower($cat).'|'.strtolower($mod);
        if (!isset($set[$k])) continue; // active only for main pass
        $cons = (int)($consumed[$cat][$mod] ?? 0);
        $availNow = (int)($availCounts[$cat][$mod] ?? 0);
        $available = max(0, min(max(0, $limit - $cons), $availNow));
        // Active: include only if there are currently available items
        if ($available > 0) {
          $items[] = [ 'model'=>$mod, 'item_name'=>$mod, 'category'=>$cat, 'available_qty'=>$available ];
        }
        if (count($items) >= 300) break 2;
      }
    }
    // Second pass: include deleted/inactive groups ONLY if they have in-use items
    foreach ($consumed as $c => $mods) {
      foreach ($mods as $m => $cnt) {
        if ((int)$cnt <= 0) continue;
        $key = strtolower($c).'|'.strtolower($m);
        if (isset($set[$key])) continue; // skip active; already handled above
        $items[] = [ 'model'=>$m, 'item_name'=>$m, 'category'=>$c, 'available_qty'=>0 ];
        if (count($items) >= 300) break 2;
      }
    }
} catch (Throwable $e) { $items = []; }
if ($embed) {
    // Minimal embedded view (no sidebar) for modal/iframe
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Borrowable Items</title>
  <link href="css/bootstrap/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    html, body { height: 100%; }
    body { background: #fff; }
    .container { height: 100%; padding: .5rem; }
    .card { height: 100%; display: flex; flex-direction: column; }
    .card-body { flex: 1 1 auto; display: flex; flex-direction: column; padding: 0 !important; }
    .table-responsive { flex: 1 1 auto; height: 100%; overflow: auto; }
    .table-responsive thead th { position: sticky; top: 0; z-index: 2; background: #f8f9fa; }
  </style>
<head>
<body>
  <div class="container">
    <div class="card">
      <div class="card-header"><strong>Borrowable Items</strong></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Model Name</th>
                <th>Category</th>
                <th>Available</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($items)): ?>
                <tr><td colspan="3" class="text-center text-muted">No items available.</td></tr>
              <?php else: foreach ($items as $it): ?>
                <tr>
                  <td><?php echo htmlspecialchars($it['model'] ?: $it['item_name']); ?></td>
                  <td><?php echo htmlspecialchars($it['category'] ?: 'Uncategorized'); ?></td>
                  <td><?php echo (int)($it['available_qty'] ?? 0); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
<?php
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Borrowable Items</title>
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
        <a href="user_items.php" class="list-group-item list-group-item-action bg-transparent">
          <i class="bi bi-collection me-2"></i>My Items
        </a>
        <a href="qr_scanner.php" class="list-group-item list-group-item-action bg-transparent">
          <i class="bi bi-camera me-2"></i>QR Scanner
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
          <i class="bi bi-box-arrow-in-down-left me-2"></i>Borrowable Items
        </h2>
        <a href="user_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
      </div>

      <div class="card">
        <div class="card-header"><strong>Available Items</strong></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th>Model Name</th>
                  <th>Category</th>
                  <th>Condition</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($items)): ?>
                  <tr><td colspan="3" class="text-center text-muted">No items available.</td></tr>
                <?php else: ?>
                  <?php foreach ($items as $it): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($it['model'] ?: $it['item_name']); ?></td>
                      <td><?php echo htmlspecialchars($it['category'] ?: 'Uncategorized'); ?></td>
                      <td><?php echo htmlspecialchars($it['condition'] ?: ''); ?></td>
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
</body>
</html>
