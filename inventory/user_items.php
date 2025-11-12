<?php
session_start();
if (!isset($_SESSION['username']) || ($_SESSION['usertype'] ?? 'user') !== 'user') {
    if (!isset($_SESSION['username'])) { header('Location: index.php'); } else { header('Location: admin_dashboard.php'); }
    exit();
}

$conn = new mysqli('localhost', 'root', '', 'inventory_system');
if ($conn->connect_error) { die('DB connection failed'); }

// Ensure user_borrows table exists
$conn->query("CREATE TABLE IF NOT EXISTS user_borrows (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  model_id INT(11) NOT NULL,
  borrowed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  returned_at TIMESTAMP NULL DEFAULT NULL,
  status ENUM('Borrowed','Returned') NOT NULL DEFAULT 'Borrowed',
  PRIMARY KEY (id),
  INDEX idx_user (username),
  INDEX idx_model (model_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

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
            // Only if the model exists
            $chk = $conn->prepare('SELECT id FROM inventory_items WHERE id = ? LIMIT 1');
            if ($chk) {
                $chk->bind_param('i', $model_id);
                $chk->execute();
                $res = $chk->get_result();
                if ($res && $res->fetch_assoc()) {
                    // Avoid duplicate active borrow for same model and user
                    $dup = $conn->prepare("SELECT id FROM user_borrows WHERE username = ? AND model_id = ? AND status = 'Borrowed' LIMIT 1");
                    $dup->bind_param('si', $_SESSION['username'], $model_id);
                    $dup->execute();
                    $dupRes = $dup->get_result();
                    if ($dupRes && $dupRes->fetch_assoc()) {
                        $message = 'Already marked as borrowed.';
                    } else {
                        $ins = $conn->prepare('INSERT INTO user_borrows (username, model_id) VALUES (?, ?)');
                        if ($ins) {
                            $ins->bind_param('si', $_SESSION['username'], $model_id);
                            if ($ins->execute()) { $message = 'Item marked as borrowed.'; }
                            else { $error = 'Failed to mark as borrowed.'; }
                            $ins->close();
                        }
                    }
                    $dup->close();
                } else {
                    $error = 'Invalid item.';
                }
                $chk->close();
            }
        } else {
            $error = 'Missing item information.';
        }
    }
}

// Recent scans by this user (last 10)
$recent_scans = [];
$rs = $conn->prepare('SELECT id, model_id, item_name, status, form_type, room, generated_date, scanned_at FROM inventory_scans WHERE scanned_by = ? ORDER BY scanned_at DESC, id DESC LIMIT 10');
if ($rs) {
    $rs->bind_param('s', $_SESSION['username']);
    $rs->execute();
    $res = $rs->get_result();
    while ($row = $res->fetch_assoc()) { $recent_scans[] = $row; }
    $rs->close();
}

// My borrowed items (active)
$my_borrowed = [];
$sql = "SELECT 
            ub.id AS borrow_id,
            (
              SELECT er2.id
              FROM equipment_requests er2
              WHERE er2.username = ub.username
                AND (er2.item_name = COALESCE(NULLIF(ii.model,''), ii.item_name) OR er2.item_name = ii.item_name)
              ORDER BY ABS(TIMESTAMPDIFF(SECOND, er2.created_at, ub.borrowed_at)) ASC, er2.id DESC
              LIMIT 1
            ) AS request_id,
            ub.borrowed_at,
            ii.id AS model_id, ii.item_name, ii.model, ii.category, ii.`condition`
        FROM user_borrows ub
        JOIN inventory_items ii ON ii.id = ub.model_id
        WHERE ub.username = ? AND ub.status = 'Borrowed'
        ORDER BY ub.borrowed_at DESC, ub.id DESC";
$bs = $conn->prepare($sql);
if ($bs) {
    $bs->bind_param('s', $_SESSION['username']);
    $bs->execute();
    $res = $bs->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['__status'] = 'Borrowed';
        $row['__ts'] = $row['borrowed_at'];
        // set row_id to matched request_id if present; fallback to borrow_id
        $row['row_id'] = (int)($row['request_id'] ?? $row['borrow_id'] ?? 0);
        $my_borrowed[] = $row;
    }
    $bs->close();
}

// Do not include requests; only show actual borrowed items
$my_borrowed_combined = $my_borrowed;

// My borrow history (this user only)
$my_history = [];
$hsql = "SELECT 
                ub.id AS borrow_id,
                (
                  SELECT er2.id
                  FROM equipment_requests er2
                  WHERE er2.username = ub.username
                    AND (er2.item_name = COALESCE(NULLIF(ii.model,''), ii.item_name) OR er2.item_name = ii.item_name)
                  ORDER BY ABS(TIMESTAMPDIFF(SECOND, er2.created_at, ub.borrowed_at)) ASC, er2.id DESC
                  LIMIT 1
                ) AS request_id,
                ub.borrowed_at, ub.returned_at, ub.status,
                ii.id AS model_id, ii.item_name, ii.model, ii.category, ii.status AS item_status,
                (
                  SELECT l.created_at FROM lost_damaged_log l
                  WHERE l.model_id = ub.model_id AND l.action = 'Lost'
                  ORDER BY l.id DESC LIMIT 1
                ) AS last_lost_at,
                (
                  SELECT l.created_at FROM lost_damaged_log l
                  WHERE l.model_id = ub.model_id AND l.action = 'Found'
                  ORDER BY l.id DESC LIMIT 1
                ) AS last_found_at,
                (
                  SELECT l.created_at FROM lost_damaged_log l
                  WHERE l.model_id = ub.model_id AND l.action = 'Under Maintenance'
                  ORDER BY l.id DESC LIMIT 1
                ) AS last_maint_at,
                (
                  SELECT l.created_at FROM lost_damaged_log l
                  WHERE l.model_id = ub.model_id AND l.action = 'Fixed'
                  ORDER BY l.id DESC LIMIT 1
                ) AS last_fixed_at
         FROM user_borrows ub
         LEFT JOIN inventory_items ii ON ii.id = ub.model_id
         WHERE ub.username = ?
         ORDER BY ub.borrowed_at DESC, ub.id DESC LIMIT 200";
$hs = $conn->prepare($hsql);
if ($hs) {
    $hs->bind_param('s', $_SESSION['username']);
    $hs->execute();
    $res = $hs->get_result();
    while ($row = $res->fetch_assoc()) { $my_history[] = $row; }
    $hs->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Items</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
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
                <img src="images/logo-removebg.png" alt="ECA Logo" class="brand-logo me-2" />
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
                <a href="logout.php" class="list-group-item list-group-item-action bg-transparent" onclick="return confirm('Are you sure you want to logout?');">
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
                                            <th>Model Name</th>
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
                                                <td><?php echo htmlspecialchars((string)($hv['request_id'] ?? '')); ?></td>
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
</body>
</html>
