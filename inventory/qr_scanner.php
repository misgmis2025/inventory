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
if (!isset($_SESSION['usertype']) || $_SESSION['usertype'] !== 'admin') {
    header("Location: user_dashboard.php");
    exit();
}

// Admin-only: update item status via AJAX with constrained transitions and lost/damaged logging
if (isset($_GET['action']) && $_GET['action'] === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['usertype']) || $_SESSION['usertype'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit();
    }
    try {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $mid = intval($data['model_id'] ?? 0);
        $newStatus = trim((string)($data['new_status'] ?? ''));
        if ($mid <= 0 || $newStatus === '') { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid payload']); exit(); }
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/db/mongo.php';
        $db = get_mongo_db();
        $itemsCol = $db->selectCollection('inventory_items');
        $item = $itemsCol->findOne(['id' => $mid]);
        if (!$item) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Item not found']); exit(); }
        $curr = (string)($item['status'] ?? '');
        $allowed = [];
        if ($curr === 'Available') { $allowed = ['Lost','Under Maintenance','Out of Order']; }
        elseif ($curr === 'Out of Order') { $allowed = ['Available','Under Maintenance','Lost']; }
        else { $allowed = []; }
        if (!in_array($newStatus, $allowed, true)) {
            http_response_code(409);
            echo json_encode(['success'=>false,'error'=>'Transition not allowed from '.$curr.' to '.$newStatus]);
            exit();
        }
        $now = date('Y-m-d H:i:s');
        $itemsCol->updateOne(['id'=>$mid], ['$set'=>['status'=>$newStatus,'updated_at'=>$now,'last_status_changed_by'=>($_SESSION['username']??'system')]]);
        // Log Lost or Maintenance to lost_damaged_log
        if ($newStatus === 'Lost' || $newStatus === 'Under Maintenance') {
            $ldCol = $db->selectCollection('lost_damaged_log');
            $nextLD = $ldCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
            $lid = ($nextLD && isset($nextLD['id']) ? (int)$nextLD['id'] : 0) + 1;
            $admin = $_SESSION['username'] ?? 'system';
            $ldCol->insertOne([
                'id' => $lid,
                'model_id' => $mid,
                'action' => $newStatus === 'Under Maintenance' ? 'Under Maintenance' : 'Lost',
                'created_at' => $now,
                // Ensure admin appears in both User and By style columns used elsewhere
                'username' => $admin,
                'marked_by' => $admin,
                'notes' => 'Updated via QR Scanner'
            ]);
        }
        echo json_encode(['success'=>true,'new_status'=>$newStatus]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Server error']);
    }
    exit();
}
// Admin-only: update remarks via AJAX
if (isset($_GET['action']) && $_GET['action'] === 'update_remarks' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['usertype']) || $_SESSION['usertype'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit();
    }
    try {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $mid = intval($data['model_id'] ?? 0);
        $rem = trim((string)($data['remarks'] ?? ''));
        if ($mid <= 0) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid payload']); exit(); }
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/db/mongo.php';
        $db = get_mongo_db();
        $itemsCol = $db->selectCollection('inventory_items');
        $res = $itemsCol->updateOne(['id' => $mid], ['$set' => ['remarks' => $rem, 'updated_at' => date('Y-m-d H:i:s')]]);
        echo json_encode(['success' => ($res->getModifiedCount() > 0 || $res->getMatchedCount() > 0)]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
    exit();
}

// Admin-only: update location via AJAX
if (isset($_GET['action']) && $_GET['action'] === 'update_location' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['usertype']) || $_SESSION['usertype'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit();
    }
    try {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $mid = intval($data['model_id'] ?? 0);
        $loc = trim((string)($data['location'] ?? ''));
        if ($mid <= 0 || $loc === '') { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid payload']); exit(); }
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/db/mongo.php';
        $db = get_mongo_db();
        $itemsCol = $db->selectCollection('inventory_items');
        $res = $itemsCol->updateOne(['id' => $mid], ['$set' => ['location' => $loc, 'updated_at' => date('Y-m-d H:i:s')]]);
        echo json_encode(['success' => ($res->getModifiedCount() > 0 || $res->getMatchedCount() > 0)]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
    exit();
}

$scannedData = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_data'])) {
    $qrData = trim($_POST['qr_data']);
    
    if (!empty($qrData)) {
        try {
            $decodedData = json_decode($qrData, true);
            if ($decodedData && isset($decodedData['item_name'])) {
                $scannedData = $decodedData;
            } else {
                $error = 'Invalid QR code format. Please scan a valid inventory QR code.';
            }
        } catch (Exception $e) {
            $error = 'Error processing QR code data. Please try again.';
        }
    } else {
        $error = 'Please enter QR code data or scan a QR code.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Scanner - Inventory System</title>
    <link href="css/bootstrap/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__.'/css/style.css'); ?>">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" />
    <style>
        #reader {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
        }
        #reader video {
            width: 100%;
            border-radius: 8px;
        }
        .camera-controls {
            text-align: center;
            margin-top: 15px;
        }
        .camera-status {
            margin-top: 10px;
            font-size: 14px;
        }
        .error-message {
            color: #dc3545;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .success-message {
            color: #155724;
            background: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }

        /* Fixed sidebar, scrollable content */
        html, body { height: 100%; }
        body { overflow: hidden; }
        #sidebar-wrapper { position: sticky; top: 0; height: 100vh; overflow: hidden; }
        #page-content-wrapper { flex: 1 1 auto; height: 100vh; overflow: auto; }
        @media (max-width: 768px) {
            body { overflow: auto; }
            #page-content-wrapper { height: auto; overflow: visible; }
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
                <?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
                    <a href="admin_dashboard.php" class="list-group-item list-group-item-action bg-transparent">
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
                    <a href="qr_scanner.php" class="list-group-item list-group-item-action bg-transparent fw-bold">
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
                    <a href="user_items.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-collection me-2"></i>My Items
                    </a>
                    <a href="qr_scanner.php" class="list-group-item list-group-item-action bg-transparent fw-bold">
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
        <div class="p-4" id="page-content-wrapper">
            <div class="page-header d-flex justify-content-between align-items-center">
                <h2 class="page-title mb-0">
                    <i class="bi bi-camera me-2"></i>QR Code Scanner
                </h2>
                <div class="d-flex align-items-center gap-3">
                    <?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
                    <div class="position-relative" id="adminBellWrap">
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
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="scanner-container">
                <!-- Camera Scanner Section -->
                <div class="camera-section">
                    <h5 class="mb-3">
                        <i class="bi bi-camera me-2"></i>Camera Scanner
                    </h5>
                    <div class="qr-reader">
                        <div id="reader"></div>
                        <div class="camera-controls">
                            <div class="mb-2">
                                <select id="camera-select" class="form-select form-select-sm" style="max-width: 300px; margin: 0 auto 10px;">
                                    <option value="">-- Select Camera --</option>
                                </select>
                            </div>
                            <div>
                                <button id="start-camera" class="btn btn-success me-2">
                                    <i class="bi bi-camera me-2"></i>Start Camera
                                </button>
                                <button id="stop-camera" class="btn btn-danger" style="display: none;">
                                    <i class="bi bi-stop-circle me-2"></i>Stop Camera
                                </button>
                            </div>
                        </div>
                        <div class="camera-status">
                            <small class="text-muted" id="camera-status">Click Start Camera to begin scanning</small>
                        </div>
                        <div id="camera-error" style="display: none;"></div>
                    </div>
                </div>

                <!-- Scanned Information Display -->
                <div class="info-card" id="info-card" style="display: none;">
                    <h5 class="mb-3">
                        <i class="bi bi-info-circle me-2"></i>Scanned Item Information
                    </h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Item Name:</label>
                                <p class="form-control-plaintext" id="item-name"></p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Status:</label>
                                <div>
                                    <span class="badge status-badge" id="status-badge"></span>
                                </div>
                                <?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
                                <div id="status-edit-wrap" class="mt-2" style="display:none; max-width: 380px;">
                                    <div class="input-group">
                                        <select class="form-select" id="status-edit-select"></select>
                                        <button type="button" class="btn btn-primary" id="save-status-btn"><i class="bi bi-save me-1"></i>Save</button>
                                    </div>
                                    <div class="form-text">Allowed transitions depend on current status.</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Category:</label>
                                <p class="form-control-plaintext" id="category"></p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold d-block">Location:</label>
                                <p class="form-control-plaintext" id="location" style="display:block;"></p>
                                <div id="location-edit-wrap" class="input-group" style="display:none; max-width: 380px;">
                                  <input type="text" class="form-control" id="edit-location-input" placeholder="Enter new location" />
                                  <button type="button" class="btn btn-primary" id="save-location-btn"><i class="bi bi-save me-1"></i>Save</button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold d-block">Remarks:</label>
                                <p class="form-control-plaintext" id="remarks" style="display:block;"></p>
                                <div id="remarks-edit-wrap" class="input-group" style="display:none; max-width: 380px;">
                                  <input type="text" class="form-control" id="edit-remarks-input" placeholder="Enter remarks" />
                                  <button type="button" class="btn btn-primary" id="save-remarks-btn"><i class="bi bi-save me-1"></i>Save</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3" id="borrowed-by-wrap" style="display:none;">
                                <label class="form-label fw-bold">Borrowed By:</label>
                                <p class="form-control-plaintext" id="borrowed-by"></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3" id="expected-return-wrap" style="display:none;">
                                <label class="form-label fw-bold">Expected Return:</label>
                                <p class="form-control-plaintext" id="expected-return"></p>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3" id="reserved-by-wrap" style="display:none;">
                                <label class="form-label fw-bold">Reserved By:</label>
                                <p class="form-control-plaintext" id="reserved-by"></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3" id="reservation-ends-wrap" style="display:none;">
                                <label class="form-label fw-bold">Reservation Ends:</label>
                                <p class="form-control-plaintext" id="reservation-ends"></p>
                            </div>
                        </div>
                    </div>
                    <!-- Actions removed by request -->
                    <input type="hidden" id="scanned-model-id" value="0" />
                </div>
                <!-- Message removed by request -->

                <!-- File Upload Section -->
                <div class="file-upload">
                    <h5 class="mb-3">
                        <i class="bi bi-upload me-2"></i>Upload QR Code Image
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <input type="file" class="form-control" id="qr-file" accept="image/*">
                        </div>
                        <div class="col-md-4">
                            <button type="button" class="btn btn-info w-100" onclick="processImage()">
                                <i class="bi bi-search me-2"></i>Process Image
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Scanned Information Display -->
                <?php if ($scannedData): ?>
                    <div class="info-card">
                        <h5 class="mb-3">
                            <i class="bi bi-info-circle me-2"></i>Scanned Item Information
                        </h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Item Name:</label>
                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($scannedData['item_name']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Status:</label>
                                    <div>
                                        <?php
                                        $statusClass = 'secondary';
                                        switch($scannedData['status']) {
                                            case 'Available': $statusClass = 'success'; break;
                                            case 'In Use': $statusClass = 'primary'; break;
                                            case 'Maintenance': $statusClass = 'warning'; break;
                                            case 'Out of Order': $statusClass = 'danger'; break;
                                            case 'Reserved': $statusClass = 'info'; break;
                                            case 'Lost': $statusClass = 'dark'; break;
                                            case 'Damaged': $statusClass = 'danger'; break;
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $statusClass; ?> status-badge">
                                            <?php echo htmlspecialchars($scannedData['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Form Type:</label>
                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($scannedData['form_type']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Room Location:</label>
                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($scannedData['room']); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Generated Date:</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($scannedData['generated_date']); ?></p>
                        </div>
                        <!-- Actions removed by request -->
                    </div>
                <?php else: ?>
                    <div class="info-card">
                        <div class="text-center text-muted">
                            <i class="bi bi-qr-code" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h5 class="mt-3">Ready to Scan</h5>
                            <p class="mt-2">Use any of the methods above to scan or input QR code data and view item information.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function(){
        var isAdmin = <?php echo json_encode(isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'); ?>;
        if (!isAdmin) return;
        const bellBtn=document.getElementById('adminBellBtn');
        const bellDot=document.getElementById('adminBellDot');
        const dropdown=document.getElementById('adminBellDropdown');
        const listEl=document.getElementById('adminNotifList');
        const emptyEl=document.getElementById('adminNotifEmpty');
        const abBackdrop=document.getElementById('adminBellBackdrop');
        const abModal=document.getElementById('adminBellModal');
        const abClose=document.getElementById('abmCloseBtn');
        const listElM=document.getElementById('adminNotifListM');
        const emptyElM=document.getElementById('adminNotifEmptyM');
        function isMobile(){ try{ return window.matchMedia && window.matchMedia('(max-width: 768px)').matches; } catch(_){ return window.innerWidth<=768; } }
        function copyAdminToMobile(){ try{ if (listElM) listElM.innerHTML = listEl ? listEl.innerHTML : ''; if (emptyElM) emptyElM.style.display = emptyEl ? emptyEl.style.display : ''; }catch(_){ }
        }
        function openAdminModal(){ if (!abModal || !abBackdrop) return; copyAdminToMobile(); abModal.style.display='flex'; abBackdrop.style.display='block'; try{ document.body.style.overflow='hidden'; }catch(_){ } }
        function closeAdminModal(){ if (!abModal || !abBackdrop) return; abModal.style.display='none'; abBackdrop.style.display='none'; try{ document.body.style.overflow=''; }catch(_){ } }
        if (bellBtn && dropdown) {
            bellBtn.addEventListener('click', function(e){
                e.stopPropagation();
                if (isMobile()) {
                    if (bellDot) bellDot.classList.add('d-none');
                    openAdminModal();
                } else {
                    dropdown.classList.toggle('show');
                    dropdown.style.position = 'absolute';
                    dropdown.style.transform = 'none';
                    dropdown.style.top = (bellBtn.offsetTop + bellBtn.offsetHeight + 6) + 'px';
                    dropdown.style.left = (bellBtn.offsetLeft - (dropdown.offsetWidth - bellBtn.offsetWidth)) + 'px';
                    if (bellDot) bellDot.classList.add('d-none');
                }
            });
            if (abBackdrop) abBackdrop.addEventListener('click', closeAdminModal);
            if (abClose) abClose.addEventListener('click', closeAdminModal);
            document.addEventListener('click', function(ev){ const t=ev.target; if (t && t.closest && (t.closest('#adminBellDropdown')||t.closest('#adminBellBtn')||t.closest('#adminBellWrap')||t.closest('#adminBellModal'))) return; dropdown.classList.remove('show'); try{ closeAdminModal(); }catch(_){ } });
        }
        let toastWrap=document.getElementById('adminToastWrap'); if(!toastWrap){ toastWrap=document.createElement('div'); toastWrap.id='adminToastWrap'; toastWrap.style.position='fixed'; toastWrap.style.right='16px'; toastWrap.style.bottom='16px'; toastWrap.style.zIndex='1030'; document.body.appendChild(toastWrap);} 
        function adjustAdminToastOffset(){ try{ var tw=document.getElementById('adminToastWrap'); if(!tw) return; var baseRight=(window.matchMedia&&window.matchMedia('(max-width: 768px)').matches)?14:16; tw.style.right=baseRight+'px'; var bottomPx=16; try{ if(window.matchMedia&&window.matchMedia('(max-width: 768px)').matches){ var nav=document.querySelector('.bottom-nav'); var hidden=nav && nav.classList && nav.classList.contains('hidden'); if(nav && !hidden){ var rect=nav.getBoundingClientRect(); var h=Math.round(Math.max(0, window.innerHeight-rect.top)); if(!h||!isFinite(h)) h=64; bottomPx=h+12; } else { bottomPx=16; } } }catch(_){ bottomPx=64; } tw.style.bottom=String(bottomPx)+'px'; }catch(_){ } }
        try{ window.addEventListener('resize', adjustAdminToastOffset); }catch(_){ }
        try{
          // Observe bottom-nav class changes
          var __adm_nav_observer=null;
          function observeBottomNav(){ try{ if(__adm_nav_observer){ try{__adm_nav_observer.disconnect();}catch(_){ } __adm_nav_observer=null; } var nav=document.querySelector('.bottom-nav'); if(!nav) return; __adm_nav_observer=new MutationObserver(function(muts){ for(var i=0;i<muts.length;i++){ var m=muts[i]; if(m.type==='attributes' && m.attributeName==='class'){ try{ adjustAdminToastOffset(); }catch(_){ } } } }); __adm_nav_observer.observe(nav,{attributes:true, attributeFilter:['class']}); }catch(_){ } }
          observeBottomNav();
        }catch(_){ }
        try{ adjustAdminToastOffset(); }catch(_){ }
        function attachSwipeForToast(el){ try{ let sx=0, sy=0, dx=0, moving=false, removed=false; const onStart=(ev)=>{ try{ const t=ev.touches?ev.touches[0]:ev; sx=t.clientX; sy=t.clientY; dx=0; moving=true; el.style.willChange='transform,opacity'; el.classList.add('toast-slide'); el.style.transition='none'; }catch(_){}}; const onMove=(ev)=>{ if(!moving||removed) return; try{ const t=ev.touches?ev.touches[0]:ev; dx=t.clientX-sx; const adx=Math.abs(dx); const od=1-Math.min(1, adx/140); el.style.transform='translateX('+dx+'px)'; el.style.opacity=String(od);}catch(_){}}; const onEnd=()=>{ if(!moving||removed) return; moving=false; try{ el.style.transition='transform 180ms ease, opacity 180ms ease'; const adx=Math.abs(dx); if(adx>80){ removed=true; el.classList.add(dx>0?'toast-remove-right':'toast-remove-left'); setTimeout(()=>{ try{ el.remove(); }catch(_){ } },200);} else { el.style.transform=''; el.style.opacity=''; } }catch(_){ } }; el.addEventListener('touchstart', onStart, {passive:true}); el.addEventListener('touchmove', onMove, {passive:true}); el.addEventListener('touchend', onEnd, {passive:true}); }catch(_){ } }
        function showToast(msg){ const el=document.createElement('div'); el.className='alert alert-info shadow-sm border-0 toast-slide toast-enter'; el.style.minWidth='280px'; el.style.maxWidth='360px'; el.innerHTML='<i class="bi bi-bell me-2"></i>'+String(msg||''); toastWrap.appendChild(el); try{ adjustAdminToastOffset(); }catch(_){ } attachSwipeForToast(el); setTimeout(()=>{ try{ el.classList.add('toast-fade-out'); setTimeout(()=>{ try{ el.remove(); }catch(_){ } },220);}catch(_){ } },5000); }
        let audioCtx=null; function playBeep(){ try{ if(!audioCtx) audioCtx=new(window.AudioContext||window.webkitAudioContext)(); const o=audioCtx.createOscillator(), g=audioCtx.createGain(); o.type='sine'; o.frequency.value=880; g.gain.setValueAtTime(0.0001,audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.2,audioCtx.currentTime+0.02); g.gain.exponentialRampToValueAtTime(0.0001,audioCtx.currentTime+0.22); o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime+0.25);}catch(_){} }
        function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
        function fmt12(txt){ try{ const s=String(txt||'').trim(); const m=s.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/); if(!m) return s; const date=m[1]; const H=parseInt(m[2],10); const mm=m[3]; const ap=(H>=12?'pm':'am'); let h=H%12; if(h===0) h=12; return date+' '+h+':'+mm+ap; } catch(_){ return String(txt||''); } }
        let baseline=new Set(); let initialized=false; let fetching=false;
        function renderCombined(pending, recent){ const rows=[]; (pending||[]).forEach(r=>{ const id=parseInt(r.id||0,10); const when=String(r.created_at||''); const qty=parseInt(r.quantity||1,10); rows.push('<a href="admin_borrow_center.php" class="list-group-item list-group-item-action">'+'<div class="d-flex w-100 justify-content-between">'+'<strong>#'+id+'</strong>'+'<small class="text-muted">'+escapeHtml(fmt12(when))+'</small>'+'</div>'+'<div class="mb-0">'+escapeHtml(String(r.username||''))+' requests '+escapeHtml(String(r.item_name||''))+' <span class="badge bg-secondary">x'+qty+'</span></div>'+'</a>'); }); if ((recent||[]).length){ rows.push('<div class="list-group-item"><div class="d-flex justify-content-between align-items-center"><span class="small text-muted">Processed</span><button type="button" class="btn btn-sm btn-outline-secondary btn-2xs" id="admClearAllBtn">Clear All</button></div></div>'); (recent||[]).forEach(r=>{ const id=parseInt(r.id||0,10); const nm=String(r.item_name||''); const st=String(r.status||''); const when=String(r.processed_at||''); const bcls=(st==='Approved')?'badge bg-success':'badge bg-danger'; rows.push('<div class="list-group-item d-flex justify-content-between align-items-start">'+'<div class="me-2">'+'<div class="d-flex w-100 justify-content-between"><strong>#'+id+' '+escapeHtml(nm)+'</strong><small class="text-muted">'+escapeHtml(fmt12(when))+'</small></div>'+'<div class="small">Status: <span class="'+bcls+'">'+escapeHtml(st)+'</span></div>'+'</div>'+'<div><button type="button" class="btn-close adm-clear-one" aria-label="Clear" data-id="'+id+'"></button></div>'+'</div>'); }); } if (listEl) listEl.innerHTML=rows.join(''); if (emptyEl) emptyEl.style.display = rows.length ? 'none' : ''; }
        document.addEventListener('click', function(ev){
          const one = ev.target && ev.target.closest && ev.target.closest('.adm-clear-one');
          if (one){ ev.preventDefault(); const rid=parseInt(one.getAttribute('data-id')||'0',10)||0; if(!rid) return; const fd=new FormData(); fd.append('request_id', String(rid)); fetch('admin_borrow_center.php?action=admin_notif_clear',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ poll(); try{ if (abModal && abModal.style && abModal.style.display==='flex') copyAdminToMobile(); }catch(_){ } }).catch(()=>{}); return; }
          if (ev.target && ev.target.id === 'admClearAllBtn'){ ev.preventDefault(); const fd=new FormData(); fd.append('limit','300'); fetch('admin_borrow_center.php?action=admin_notif_clear_all',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ poll(); try{ if (abModal && abModal.style && abModal.style.display==='flex') copyAdminToMobile(); }catch(_){ } }).catch(()=>{}); }
        });
        function poll(){ if(fetching) return; fetching=true; fetch('admin_borrow_center.php?action=admin_notifications').then(r=>r.json()).then(d=>{ const pending=(d&&Array.isArray(d.pending))? d.pending: []; const recent=(d&&Array.isArray(d.recent))? d.recent: []; if (bellDot) bellDot.classList.toggle('d-none', pending.length===0); try{ const navLink=document.querySelector('a[href="admin_borrow_center.php"]'); if(navLink){ let dot=navLink.querySelector('.nav-borrow-dot'); const shouldShow = pending.length>0; if (shouldShow){ if(!dot){ dot=document.createElement('span'); dot.className='nav-borrow-dot ms-2 d-inline-block rounded-circle'; dot.style.width='8px'; dot.style.height='8px'; dot.style.backgroundColor='#dc3545'; dot.style.verticalAlign='middle'; dot.style.display='inline-block'; navLink.appendChild(dot);} else { dot.style.display='inline-block'; } } else if (dot){ dot.style.display='none'; } } }catch(_){ } renderCombined(pending, recent); try{ if (abModal && abModal.style && abModal.style.display==='flex') copyAdminToMobile(); }catch(_){ } const curr=new Set(pending.map(it=>parseInt(it.id||0,10))); if(!initialized){ baseline=curr; initialized=true; } else { let hasNew=false; pending.forEach(it=>{ const id=parseInt(it.id||0,10); if(!baseline.has(id)){ hasNew=true; showToast('New request: '+(it.username||'')+' → '+(it.item_name||'')+' (x'+(it.quantity||1)+')'); } }); if(hasNew) playBeep(); baseline=curr; } }).catch(()=>{}).finally(()=>{ fetching=false; }); }
        poll(); setInterval(()=>{ if(document.visibilityState==='visible') poll(); }, 1000);
    })();
    // Map a status to Bootstrap color class
    function mapStatusClass(st){
        switch(String(st||'')){
            case 'Available': return 'bg-success';
            case 'In Use': return 'bg-primary';
            case 'Reserved': return 'bg-info';
            case 'Under Maintenance': return 'bg-warning';
            case 'Out of Order': return 'bg-danger';
            case 'Lost': return 'bg-dark';
            default: return 'bg-secondary';
        }
    }
    function computeAllowedStatuses(curr){
        if (curr==='Available') return ['Lost','Under Maintenance','Out of Order'];
        if (curr==='Out of Order') return ['Available','Under Maintenance','Lost'];
        return [];
    }
    function refreshStatusEditor(){
        try{
            const wrap = document.getElementById('status-edit-wrap'); if(!wrap) return;
            const curr = document.getElementById('status-badge')?.textContent.trim() || '';
            const allowed = computeAllowedStatuses(curr);
            const sel = document.getElementById('status-edit-select');
            sel.innerHTML = '';
            if (allowed.length===0){ wrap.style.display='none'; return; }
            allowed.forEach(s=>{ const opt=document.createElement('option'); opt.value=s; opt.textContent=s; sel.appendChild(opt); });
            wrap.style.display = 'block';
        }catch(_){ }
    }
    function setupStatusSave(){
        const btn = document.getElementById('save-status-btn'); if(!btn) return;
        btn.addEventListener('click', async function(){
            try{
                const mid = parseInt(document.getElementById('scanned-model-id')?.value||'0',10)||0;
                const sel = document.getElementById('status-edit-select');
                const ns = sel ? sel.value : '';
                if (mid<=0 || !ns){ alert('No item loaded or status not selected.'); return; }
                const resp = await fetch('qr_scanner.php?action=update_status', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({model_id: mid, new_status: ns}) });
                const data = await resp.json();
                if (!resp.ok || !data.success){ throw new Error(data.error||'Failed'); }
                const badge = document.getElementById('status-badge');
                badge.textContent = data.new_status;
                badge.className = 'badge status-badge ' + mapStatusClass(data.new_status);
                refreshStatusEditor();
                alert('Status updated.');
            }catch(e){ alert('Error updating status.'); }
        });
    }
    let html5Qrcode = null;
    let isScanning = false;
    var isRegularUser = <?php echo json_encode(isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'user'); ?>;
    var isAdminGlobal = <?php echo json_encode(isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'); ?>;


        // Camera Scanner Functions
        document.addEventListener('DOMContentLoaded', function() {
            const startBtn = document.getElementById('start-camera');
            const stopBtn = document.getElementById('stop-camera');
            const cameraSelect = document.getElementById('camera-select');
            
            // Function to populate camera dropdown
            function populateCameraSelect() {
                Html5Qrcode.getCameras()
                    .then(devices => {
                        console.log('Available cameras:', devices);
                        
                        // Clear existing options except the first one
                        while (cameraSelect.options.length > 1) {
                            cameraSelect.remove(1);
                        }
                        
                        // Add available cameras
                        devices.forEach((device, index) => {
                            const option = document.createElement('option');
                            option.value = device.id;
                            option.text = device.label || `Camera ${index + 1}`;
                            cameraSelect.appendChild(option);
                        });
                        // Preselect saved camera if available
                        try {
                            const saved = localStorage.getItem('qr_camera');
                            if (saved && devices.some(d => d.id === saved)) {
                                cameraSelect.value = saved;
                            }
                        } catch(_){ }
                    })
                    .catch(err => {
                        console.error('Error getting cameras:', err);
                        updateCameraStatus('Error accessing camera devices');
                    });
            }
            
            // Initialize camera selection
            populateCameraSelect();
            
            if (startBtn) {
                startBtn.addEventListener('click', function() {
                    if (!isScanning) {
                        const selectedCameraId = cameraSelect.value;
                        startCamera(selectedCameraId);
                    }
                });
            }
            
            if (stopBtn) {
                stopBtn.addEventListener('click', function() {
                    if (isScanning) {
                        stopCamera();
                    }
                });
            }

            // Save selection on change; if scanning, switch to the new camera
            if (cameraSelect) {
                cameraSelect.addEventListener('change', function(){
                    try { localStorage.setItem('qr_camera', cameraSelect.value || ''); } catch(_){ }
                    if (isScanning) {
                        const id = cameraSelect.value || '';
                        stopCamera();
                        setTimeout(function(){ startCamera(id); }, 150);
                    }
                });
            }
        });

        function startCamera(deviceId = '') {
            console.log('Starting camera...');
            updateCameraStatus('Starting camera...');

            // Check if we're in a secure context
            const isLocal = location.hostname.startsWith("192.168.") || 
                location.hostname.startsWith("10.") || 
                location.hostname === "localhost" || 
                location.hostname === "127.0.0.1";

            if (!window.isSecureContext && !isLocal) {
                alert('Camera access requires HTTPS or localhost.');
                return;
            }

            // Get available cameras
            Html5Qrcode.getCameras()
                .then(function(cameras) {
                    console.log('Available cameras:', cameras);
                    
                    if (!cameras || cameras.length === 0) {
                        updateCameraStatus('No camera found');
                        alert('No camera found. Please connect a camera and try again.');
                        return;
                    }

                    // Use selected camera or find the best available
                    let selectedCamera;
                    if (deviceId) {
                        selectedCamera = cameras.find(cam => cam.id === deviceId) || cameras[0];
                    } else {
                        // Prefer saved device, then back camera, then first
                        let saved = '';
                        try { saved = localStorage.getItem('qr_camera') || ''; } catch(_){ saved=''; }
                        if (saved) {
                            selectedCamera = cameras.find(c => c.id === saved) || null;
                        }
                        if (!selectedCamera) {
                            selectedCamera = cameras.find(c => /back|rear|environment/i.test(c.label)) || cameras[0];
                        }
                        // Update the dropdown to show the selected camera
                        const cameraSelect = document.getElementById('camera-select');
                        if (cameraSelect) {
                            for (let i = 0; i < cameraSelect.options.length; i++) {
                                if (cameraSelect.options[i].value === selectedCamera.id) {
                                    cameraSelect.selectedIndex = i;
                                    break;
                                }
                            }
                        }
                    }

                    console.log('Using camera:', selectedCamera);

                    // Clear previous scanner
                    const readerDiv = document.getElementById('reader');
                    if (readerDiv) readerDiv.innerHTML = '';

                    if (html5Qrcode) {
                        try {
                            html5Qrcode.stop().catch(() => {});
                            html5Qrcode.clear();
                        } catch(e) {
                            console.log('Error clearing previous scanner:', e);
                        }
                        html5Qrcode = null;
                    }

                    // Create new scanner
                    html5Qrcode = new Html5Qrcode('reader');

                    // Start scanning with selected camera
                    html5Qrcode.start(
                        selectedCamera.id,
                        {
                            fps: 10,
                            qrbox: { width: 300, height: 300 },
                            aspectRatio: 1.0,
                            disableFlip: false
                        },
                        onScanSuccess,
                        onScanFailure
                    ).then(function() {
                        console.log('Camera started successfully');
                        isScanning = true;
                        updateCameraStatus('Camera active. Point to a QR code to scan.');
                        document.getElementById('start-camera').style.display = 'none';
                        document.getElementById('stop-camera').style.display = 'inline-block';
                        try { localStorage.setItem('qr_camera', selectedCamera.id || ''); } catch(_){ }
                    }).catch(function(err) {
                        console.error('Camera start error:', err);
                        updateCameraStatus('Camera error: ' + (err.name || 'start failure'));
                        alert('Unable to start camera: ' + (err.message || err));
                    });
                })
                .catch(function(err) {
                    console.error('Camera enumeration error:', err);
                    updateCameraStatus('Camera error: ' + (err.name || 'enumeration failure'));
                    alert('Unable to access cameras: ' + (err.message || err));
                });
        }

        function stopCamera() {
            console.log('Stopping camera...');
            updateCameraStatus('Stopping camera...');

            if (html5Qrcode && isScanning) {
                html5Qrcode.stop().then(function() {
                    console.log('Camera stopped successfully');
                    try {
                        html5Qrcode.clear();
                    } catch(e) {
                        console.log('Error clearing scanner:', e);
                    }
                    isScanning = false;
                    html5Qrcode = null;

                    document.getElementById('start-camera').style.display = 'inline-block';
                    document.getElementById('stop-camera').style.display = 'none';

                    const readerDiv = document.getElementById('reader');
                    if (readerDiv) readerDiv.innerHTML = '';

                    updateCameraStatus('Camera stopped. Click Start Camera to scan again.');
                }).catch(function(error) {
                    console.error('Error stopping camera:', error);
                    isScanning = false;
                    document.getElementById('start-camera').style.display = 'inline-block';
                    document.getElementById('stop-camera').style.display = 'none';
                    updateCameraStatus('Camera stopped.');
                });
            } else {
                isScanning = false;
                document.getElementById('start-camera').style.display = 'inline-block';
                document.getElementById('stop-camera').style.display = 'none';
                updateCameraStatus('Camera stopped.');
            }
        }

        function updateCameraStatus(message) {
            const statusElement = document.getElementById('camera-status');
            if (statusElement) {
                statusElement.textContent = message;
                statusElement.className = 'text-muted';
                
                if (message.includes('error') || message.includes('Error')) {
                    statusElement.className = 'text-danger';
                } else if (message.includes('successfully') || message.includes('active')) {
                    statusElement.className = 'text-success';
                }
            }
            console.log('Status:', message);
        }

        function onScanSuccess(decodedText, decodedResult) {
            console.log('QR Code scanned successfully:', decodedText);
            // Stop camera after successful scan
            stopCamera();

            const mapStatusClass = (status) => {
                switch (status) {
                    case 'Available': return 'bg-success';
                    case 'In Use': return 'bg-primary';
                    case 'Maintenance': return 'bg-warning';
                    case 'Out of Order': return 'bg-danger';
                    case 'Reserved': return 'bg-info';
                    case 'Lost': return 'bg-danger';
                    case 'Damaged': return 'bg-danger';
                    default: return 'bg-secondary';
                }
            };

            // Try legacy JSON first
            try {
                const data = JSON.parse(decodedText);
                if (data && (data.item_name || data.model_id || data.item_id)) {
                    if (!data.model_id && data.item_id) { data.model_id = data.item_id; delete data.item_id; }
                    if (!data.item_name) { throw new Error('Missing item_name in legacy payload'); }

                    document.getElementById('item-name').textContent = data.item_name || '';
                    const statusEl = document.getElementById('status-badge');
                    statusEl.textContent = data.status || '';
                    statusEl.className = 'badge status-badge ' + mapStatusClass(data.status);
                    document.getElementById('category').textContent = data.category || '';
                    const loc = data.location || data.room || '';
                    document.getElementById('location').textContent = loc;
                    const rem = data.remarks || '';
                    document.getElementById('remarks').textContent = rem;
                    // Store model id if available and enable location editing for admin
                    const midInput = document.getElementById('scanned-model-id');
                    const mid = parseInt(data.model_id||0,10)||0;
                    midInput.value = String(mid);
                    setupLocationEdit(mid, loc);
                    refreshStatusEditor(); setupStatusSave();
                    setupRemarksEdit(mid, rem);

                    // Hide borrow/reserve info for legacy payloads
                    document.getElementById('borrowed-by-wrap').style.display = 'none';
                    document.getElementById('expected-return-wrap').style.display = 'none';
                    document.getElementById('reserved-by-wrap').style.display = 'none';
                    document.getElementById('reservation-ends-wrap').style.display = 'none';

                    document.getElementById('info-card').style.display = 'block';

                    fetch('inventory.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(data)
                    })
                    .then(res => res.json())
                    .then(res => { if (!(res && res.success)) { console.warn('Record failed:', (res && res.error) || 'Unknown error'); } })
                    .catch(err => { console.error('Record error:', err); });
                    // Auto-create a pending request for regular users
                    try {
                        if (isRegularUser) {
                            const payload = {
                                model_id: parseInt(data.model_id||0,10)||0,
                                model: data.model || data.item_name || '',
                                item_name: data.item_name || data.model || '',
                                category: data.category || '',
                                qr_serial_no: data.serial_no || ''
                            };
                            fetch('user_request.php?action=create_from_qr', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(payload)
                            }).then(r=>r.json()).then(j=>{
                                if (!(j&&j.success)) { console.warn('QR request create failed:', j&&j.error); }
                            }).catch(()=>{});
                        }
                    } catch(_) {}
                    return;
                }
            } catch (_) {
                // Not JSON or not legacy format; fall through to serial lookup
            }

            // New format: plain serial (or numeric id)
            const serial = (decodedText || '').trim();
            if (serial === '') { alert('Scanned code is empty.'); return; }

            fetch('inventory.php?action=item_by_serial&sid=' + encodeURIComponent(serial), { cache: 'no-store' })
                .then(r => r.json())
                .then(resp => {
                    if (!resp || !resp.success || !resp.item) { throw new Error('Item not found for serial'); }
                    const it = resp.item;
                    document.getElementById('item-name').textContent = it.item_name || '';
                    const statusEl = document.getElementById('status-badge');
                    statusEl.textContent = it.status || '';
                    statusEl.className = 'badge status-badge ' + mapStatusClass(it.status);
                    document.getElementById('category').textContent = it.category || '';
                    const loc2 = it.location || '';
                    document.getElementById('location').textContent = loc2;
                    const rem2 = it.remarks || '';
                    document.getElementById('remarks').textContent = rem2;
                    // Store model id and enable location editing for admin
                    const midInput2 = document.getElementById('scanned-model-id');
                    const mid2 = parseInt(it.id||0,10)||0;
                    midInput2.value = String(mid2);
                    setupLocationEdit(mid2, loc2);
                    refreshStatusEditor(); setupStatusSave();
                    setupRemarksEdit(mid2, rem2);

                    // Borrow info
                    const borrowedBy = it.borrowed_by_full_name || it.borrowed_by_username || '';
                    if (borrowedBy) {
                        document.getElementById('borrowed-by').textContent = borrowedBy;
                        document.getElementById('borrowed-by-wrap').style.display = '';
                        const exp = it.expected_return_at || '';
                        document.getElementById('expected-return').textContent = exp;
                        document.getElementById('expected-return-wrap').style.display = exp ? '' : 'none';
                    } else {
                        document.getElementById('borrowed-by-wrap').style.display = 'none';
                        document.getElementById('expected-return-wrap').style.display = 'none';
                    }

                    // Reservation info
                    const reservedBy = it.reservation_by_full_name || it.reservation_by_username || '';
                    if (!borrowedBy && reservedBy) {
                        document.getElementById('reserved-by').textContent = reservedBy;
                        document.getElementById('reserved-by-wrap').style.display = '';
                        const ends = it.reserved_to || '';
                        document.getElementById('reservation-ends').textContent = ends;
                        document.getElementById('reservation-ends-wrap').style.display = ends ? '' : 'none';
                    } else {
                        document.getElementById('reserved-by-wrap').style.display = 'none';
                        document.getElementById('reservation-ends-wrap').style.display = 'none';
                    }

                    const nowStr = new Date().toLocaleString();
                    document.getElementById('info-card').style.display = 'block';

                    // Record a minimal scan payload for history
                    const rec = {
                        item_name: it.item_name || '',
                        status: it.status || '',
                        form_type: '',
                        room: it.location || '',
                        generated_date: nowStr,
                        model_id: parseInt(it.id || 0, 10) || 0
                    };
                    fetch('inventory.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(rec)
                    }).catch(() => {});

                    // Auto-create a pending request for regular users
                    try {
                        if (isRegularUser) {
                            const payload = {
                                model_id: parseInt(it.id||0,10)||0,
                                model: it.model || it.item_name || '',
                                item_name: it.item_name || it.model || '',
                                category: it.category || '',
                                qr_serial_no: it.serial_no || ''
                            };
                            fetch('user_request.php?action=create_from_qr', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(payload)
                            }).then(r=>r.json()).then(j=>{
                                if (!(j&&j.success)) { console.warn('QR request create failed:', j&&j.error); }
                            }).catch(()=>{});
                        }
                    } catch(_) {}
                })
                .catch(err => {
                    console.error('Serial lookup failed:', err);
                    alert('Serial not recognized. Please ensure the item exists in inventory.');
                });
        }

function onScanFailure(error) {
            // Only log errors, don't show alerts for normal scan attempts
            if (error && error !== 'QR code not found in current image.') {
                console.warn(`QR scan failed: ${error}`);
            }
        }

        

        // Utility Functions
        function printInfo() {
            if (window.print) {
                window.print();
            }
        }

        function copyToClipboard() {
            const name = document.getElementById('item-name')?.textContent || '';
            const status = document.getElementById('status-badge')?.textContent || '';
            const category = document.getElementById('category')?.textContent || '';
            const location = document.getElementById('location')?.textContent || '';
            const remarks = document.getElementById('remarks')?.textContent || '';
            const borrowedBy = document.getElementById('borrowed-by')?.textContent || '';
            const expectedReturn = document.getElementById('expected-return')?.textContent || '';
            const reservedBy = document.getElementById('reserved-by')?.textContent || '';
            const reservationEnds = document.getElementById('reservation-ends')?.textContent || '';

            const lines = [
              `Item Name: ${name}`,
              `Category: ${category}`,
              `Status: ${status}`,
              `Location: ${location}`
            ];
            if (remarks) { lines.push(`Remarks: ${remarks}`); }
            if (borrowedBy) { lines.push(`Borrowed By: ${borrowedBy}`); }
            if (expectedReturn) { lines.push(`Expected Return: ${expectedReturn}`); }
            if (!borrowedBy && reservedBy) { lines.push(`Reserved By: ${reservedBy}`); }
            if (!borrowedBy && reservationEnds) { lines.push(`Reservation Ends: ${reservationEnds}`); }
            const itemInfo = lines.join('\n');

            navigator.clipboard.writeText(itemInfo).then(function() {
                alert('Information copied to clipboard!');
            }).catch(function() {
                alert('Failed to copy to clipboard. Please copy manually.');
            });
        }

        function processImage() {
            const fileInput = document.getElementById('qr-file');
            const file = fileInput.files[0];
            const processBtn = document.querySelector('.file-upload button[onclick="processImage()"]');

            if (!file) {
                alert('Please select an image file first.');
                return;
            }

            if (typeof Html5Qrcode === 'undefined') {
                alert('QR Code library not loaded. Please refresh the page.');
                return;
            }

            // UI: disable button and show loading
            if (processBtn) {
                processBtn.disabled = true;
                processBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...';
            }

            updateCameraStatus('Processing image...');

            // Failsafe timeout to avoid hanging UI
            let finished = false;
            const timeoutId = setTimeout(() => {
                if (!finished) {
                    updateCameraStatus('Image processing timeout.');
                }
            }, 10000);

            // Helper: scan with static method; fallback to instance if needed
            const scanFileWithFallback = () => {
                if (typeof Html5Qrcode.scanFile === 'function') {
                    return Html5Qrcode.scanFile(file, true);
                }
                const temp = new Html5Qrcode('reader');
                return temp.scanFile(file, true).finally(() => {
                    try { temp.clear(); } catch (_) {}
                });
            };

            scanFileWithFallback()
            .then(function(decodedText) {
                console.log('QR Code found in image:', decodedText);
                const mapStatusClass = (status) => {
                    switch (status) {
                        case 'Available': return 'bg-success';
                        case 'In Use': return 'bg-primary';
                        case 'Maintenance': return 'bg-warning';
                        case 'Out of Order': return 'bg-danger';
                        case 'Reserved': return 'bg-info';
                        case 'Lost': return 'bg-dark';
                        case 'Damaged': return 'bg-danger';
                        default: return 'bg-secondary';
                    }
                };

                // Try JSON first
                try {
                    const data = JSON.parse(decodedText);
                    if (data && (data.item_name || data.model_id || data.item_id || data.serial_no)) {
                        if (!data.model_id && data.item_id) { data.model_id = data.item_id; delete data.item_id; }
                        const sid = (data.serial_no && String(data.serial_no).trim() !== '') ? String(data.serial_no).trim() : (Number.isInteger(data.model_id) && data.model_id > 0 ? String(data.model_id) : '');
                        if (sid !== '') {
                            // Resolve full item details via server
                            fetch('inventory.php?action=item_by_serial&sid=' + encodeURIComponent(sid), { cache: 'no-store' })
                                .then(r => r.json())
                                .then(resp => {
                                    if (!resp || !resp.success || !resp.item) { throw new Error('Item not found'); }
                                    const it = resp.item;
                                    document.getElementById('item-name').textContent = it.item_name || '';
                                    const statusEl = document.getElementById('status-badge');
                                    statusEl.textContent = it.status || '';
                                    statusEl.className = 'badge status-badge ' + mapStatusClass(it.status);
                                    document.getElementById('category').textContent = it.category || '';
                                    const loc = it.location || '';
                                    document.getElementById('location').textContent = loc;
                                    const rem = it.remarks || '';
                                    document.getElementById('remarks').textContent = rem;
                                    const mid = parseInt(it.id||0,10)||0;
                                    document.getElementById('scanned-model-id').value = String(mid);
                                    setupLocationEdit(mid, loc);
                                    refreshStatusEditor(); setupStatusSave();
                                    setupRemarksEdit(mid, rem);
                                    document.getElementById('info-card').style.display = 'block';
                                })
                                .catch(err => {
                                    console.error('Lookup failed:', err);
                                    alert('Unable to resolve item from QR.');
                                });
                        } else if (data.item_name) {
                            // Fallback: display minimal info
                            document.getElementById('item-name').textContent = data.item_name || '';
                            const statusEl = document.getElementById('status-badge');
                            statusEl.textContent = data.status || '';
                            statusEl.className = 'badge status-badge ' + mapStatusClass(data.status);
                            const loc = data.location || data.room || '';
                            document.getElementById('location').textContent = loc;
                            const rem = data.remarks || '';
                            document.getElementById('remarks').textContent = rem;
                            document.getElementById('scanned-model-id').value = '0';
                            setupLocationEdit(0, loc);
                            setupRemarksEdit(0, rem);
                            document.getElementById('info-card').style.display = 'block';
                        } else {
                            throw new Error('Unrecognized QR JSON');
                        }

                        // Record scan silently
                        fetch('inventory.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(data)
                        }).catch(()=>{});
                        updateCameraStatus('QR code processed successfully!');
                        return;
                    }
                } catch(_) {
                    // Not JSON or irrelevant JSON
                }

                // Treat as plain serial/id
                const serial = (decodedText || '').trim();
                if (serial === '') { alert('Scanned code is empty.'); return; }
                fetch('inventory.php?action=item_by_serial&sid=' + encodeURIComponent(serial), { cache: 'no-store' })
                    .then(r => r.json())
                    .then(resp => {
                        if (!resp || !resp.success || !resp.item) { throw new Error('Item not found for serial'); }
                        const it = resp.item;
                        document.getElementById('item-name').textContent = it.item_name || '';
                        const statusEl = document.getElementById('status-badge');
                        statusEl.textContent = it.status || '';
                        statusEl.className = 'badge status-badge ' + mapStatusClass(it.status);
                        document.getElementById('category').textContent = it.category || '';
                        const loc = it.location || '';
                        document.getElementById('location').textContent = loc;
                        const rem = it.remarks || '';
                        document.getElementById('remarks').textContent = rem;
                        const mid = parseInt(it.id||0,10)||0;
                        document.getElementById('scanned-model-id').value = String(mid);
                        setupLocationEdit(mid, loc);
                        refreshStatusEditor(); setupStatusSave();
                        setupRemarksEdit(mid, rem);
                        document.getElementById('info-card').style.display = 'block';
                        updateCameraStatus('QR code processed successfully!');
                        // Record minimal scan
                        const nowStr = new Date().toLocaleString();
                        const rec = { item_name: it.item_name||'', status: it.status||'', form_type: '', room: loc, generated_date: nowStr, model_id: mid };
                        fetch('inventory.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(rec) }).catch(()=>{});
                    })
                    .catch(err => {
                        console.warn('No item for serial:', err);
                        alert('No item found for this QR.');
                    });
            })
            .catch(function(err) {
                console.warn('No QR code found in image or scan failed:', err);
                alert('No QR code found in the selected image. Please try another image.');
            })
            .finally(function() {
                finished = true;
                clearTimeout(timeoutId);
                updateCameraStatus('');
                if (processBtn) {
                    processBtn.disabled = false;
                    processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Process Image';
                }
            });
        }

        // Location edit helpers
        function setupLocationEdit(modelId, currentLoc){
            const p = document.getElementById('location');
            const wrap = document.getElementById('location-edit-wrap');
            const inp = document.getElementById('edit-location-input');
            const btn = document.getElementById('save-location-btn');
            if (!p || !wrap || !inp || !btn) return;
            if (isAdminGlobal && modelId > 0){
                wrap.style.display = 'flex';
                p.style.display = 'none';
                inp.value = currentLoc || '';
                btn.onclick = function(){ saveLocation(modelId, inp.value || ''); };
            } else {
                wrap.style.display = 'none';
                p.style.display = 'block';
            }
        }
        async function saveLocation(modelId, newLoc){
            newLoc = String(newLoc||'').trim();
            if (newLoc === '') { alert('Location cannot be empty.'); return; }
            try {
                const r = await fetch('qr_scanner.php?action=update_location', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ model_id: modelId, location: newLoc })
                });
                const j = await r.json();
                if (j && j.success){
                    document.getElementById('location').textContent = newLoc;
                    const p = document.getElementById('location');
                    const wrap = document.getElementById('location-edit-wrap');
                    if (p && wrap){ p.style.display='block'; wrap.style.display='none'; }
                    alert('Location updated.');
                } else {
                    alert('Update failed.');
                }
            } catch(_){ alert('Network error.'); }
        }

        // Remarks edit helpers
        function setupRemarksEdit(modelId, currentRemarks){
            const p = document.getElementById('remarks');
            const wrap = document.getElementById('remarks-edit-wrap');
            const inp = document.getElementById('edit-remarks-input');
            const btn = document.getElementById('save-remarks-btn');
            if (!p || !wrap || !inp || !btn) return;
            if (isAdminGlobal && modelId > 0){
                wrap.style.display = 'flex';
                p.style.display = 'none';
                inp.value = currentRemarks || '';
                btn.onclick = function(){ saveRemarks(modelId, inp.value || ''); };
            } else {
                wrap.style.display = 'none';
                p.style.display = 'block';
            }
        }
        async function saveRemarks(modelId, newRemarks){
            newRemarks = String(newRemarks||'').trim();
            try {
                const r = await fetch('qr_scanner.php?action=update_remarks', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ model_id: modelId, remarks: newRemarks })
                });
                const j = await r.json();
                if (j && j.success){
                    document.getElementById('remarks').textContent = newRemarks;
                    const p = document.getElementById('remarks');
                    const wrap = document.getElementById('remarks-edit-wrap');
                    if (p && wrap){ p.style.display='block'; wrap.style.display='none'; }
                    alert('Remarks updated.');
                } else {
                    alert('Update failed.');
                }
            } catch(_){ alert('Network error.'); }
        }

        // Clean up camera when page is unloaded
        window.addEventListener('beforeunload', function() {
            if (isScanning) {
                stopCamera();
            }
        });
        
        // Add error handling for HTML5 QR Code library
        window.addEventListener('error', function(e) {
            console.error('Global error:', e.error);
            if (e.error && e.error.message && e.error.message.includes('HTML5QrcodeScanner')) {
                updateCameraStatus('QR scanner error');
                alert('QR scanner error. Please refresh the page and try again.');
            }
        });
        
        // Check if HTML5 QR Code library is loaded
        if (typeof Html5Qrcode === 'undefined') {
	console.error('HTML5 QR Code library not loaded');
	updateCameraStatus('QR library not loaded');
} else {
	console.log('HTML5 QR Code library loaded successfully');
}
        
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
    </script>
    <script>
      // Global admin notifications: user verified returns (toast + beep)
      (function(){
        document.addEventListener('DOMContentLoaded', function(){
          var isAdmin = <?php echo json_encode(isset($_SESSION['usertype']) && $_SESSION['usertype']==='admin'); ?>;
          if (!isAdmin) return;
          try {
            var toastWrap = document.getElementById('adminToastWrap');
            if (!toastWrap) {
              toastWrap = document.createElement('div'); toastWrap.id = 'adminToastWrap';
              toastWrap.style.position='fixed'; toastWrap.style.right='16px'; toastWrap.style.bottom='16px'; toastWrap.style.zIndex='1030';
              document.body.appendChild(toastWrap);
            }
            function showToast(msg, cls){ var el=document.createElement('div'); el.className='alert '+(cls||'alert-info')+' shadow-sm border-0 toast-slide toast-enter'; el.style.minWidth='280px'; el.style.maxWidth='360px'; el.innerHTML='<i class=\"bi bi-bell me-2\"></i>'+String(msg||''); toastWrap.appendChild(el); try{ if (typeof adjustAdminToastOffset==='function') adjustAdminToastOffset(); }catch(_){ } setTimeout(function(){ try{ el.classList.add('toast-fade-out'); setTimeout(function(){ try{ el.remove(); }catch(_){ } }, 220); }catch(_){ } }, 5000); }
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
  
  <style>
    @media (max-width: 768px) {
      .bottom-nav{ position: fixed; bottom: 0; left:0; right:0; z-index: 1050; background:#fff; border-top:1px solid #dee2e6; display:flex; justify-content:space-between; gap:6px; flex-wrap:nowrap; overflow-x:hidden; padding:6px 10px; padding-left: calc(10px + constant(safe-area-inset-left)); padding-left: calc(10px + env(safe-area-inset-left)); padding-right: calc(10px + constant(safe-area-inset-right)); padding-right: calc(10px + env(safe-area-inset-right)); box-sizing: border-box; transition: transform .2s ease-in-out; }
      .bottom-nav.hidden{ transform: translateY(100%); }
      .bottom-nav a{ text-decoration:none; font-size:11px; color:#333; display:flex; flex-direction:column; align-items:center; gap:3px; flex:1 1 0; min-width:0; white-space:nowrap; padding:4px 4px; }
      .bottom-nav a .bi{ font-size:16px; }
      .bottom-nav-toggle{ position: fixed; right: 14px; bottom: 14px; z-index: 1060; border-radius: 999px; box-shadow: 0 2px 8px rgba(0,0,0,.2); transition: bottom .2s ease-in-out; }
      .bottom-nav-toggle.raised{ bottom: 78px; }
      .bottom-nav-toggle .bi{ font-size: 1.2rem; }
    }
  </style>
  <?php if (isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin'): ?>
  <button type="button" class="btn btn-primary bottom-nav-toggle d-md-none" id="bnToggleQR" aria-controls="qrBottomNav" aria-expanded="false" title="Open menu">
    <i class="bi bi-list"></i>
  </button>
  <nav class="bottom-nav d-md-none hidden" id="qrBottomNav">
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
      var btn = document.getElementById('bnToggleQR');
      var nav = document.getElementById('qrBottomNav');
      if (btn && nav) {
        btn.addEventListener('click', function(){
          var hid = nav.classList.toggle('hidden');
          btn.setAttribute('aria-expanded', String(!hid));
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
  <?php endif; ?>

</body>
</html>
