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

$isCategoriesJson = isset($_GET['action']) && $_GET['action'] === 'categories_json';
$isCheckSerial = isset($_GET['action']) && $_GET['action'] === 'check_serial';
$categoryOptions = [];
// Mongo-first categories loading
$catsMongoFailed = false;
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $catCur = $db->selectCollection('categories')->find([], ['sort' => ['name' => 1], 'projection' => ['name' => 1]]);
    foreach ($catCur as $c) { if (!empty($c['name'])) { $categoryOptions[] = (string)$c['name']; } }
    if ($isCategoriesJson) { header('Content-Type: application/json'); echo json_encode(['categories'=>array_values($categoryOptions)]); exit(); }
} catch (Throwable $e) {
    $catsMongoFailed = true;
}
// If Mongo failed, do not fallback to MySQL in production
if ($catsMongoFailed) { if ($isCategoriesJson) { header('Content-Type: application/json'); echo json_encode(['categories'=>[]]); exit(); } }

// Lightweight API: check if a serial exists
if ($isCheckSerial) {
    $sid = trim((string)($_GET['sid'] ?? ''));
    $exists = false;
    if ($sid !== '') {
        // Try MongoDB first
        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            require_once __DIR__ . '/db/mongo.php';
            $dbx = get_mongo_db();
            $itemsCol = $dbx->selectCollection('inventory_items');
            $exists = $itemsCol->countDocuments(['serial_no' => $sid]) > 0;
        } catch (Throwable $e) { }
    }
    header('Content-Type: application/json');
    echo json_encode(['exists' => $exists]);
    exit();
}

$prefill_name = $_GET['item_name'] ?? '';
$prefill_status = $_GET['status'] ?? '';
$prefill_form_type = $_GET['form_type'] ?? '';
$prefill_room = $_GET['room'] ?? '';

$message = '';
$qrCodePath = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle Add Item submit locally to avoid redirecting to inventory.php
    if (isset($_POST['create_item']) && isset($_SESSION['usertype']) && $_SESSION['usertype'] === 'admin') {
        $item_name = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $quantity = max(0, intval($_POST['quantity'] ?? 1));
        $location = trim($_POST['location'] ?? '');
        if ($location === '') { $location = 'MIS Office'; }
        $condition = trim($_POST['condition'] ?? '');
        $status = 'Available';
        $date_acquired = trim($_POST['date_acquired'] ?? '');
        if ($date_acquired === '') { $date_acquired = date('Y-m-d'); }
        $remarks = trim($_POST['remarks'] ?? '');
        // Expect an array of serial IDs as JSON in serial_list
        $serial_list_raw = $_POST['serial_list'] ?? '[]';
        $serials = json_decode($serial_list_raw, true);
        if (!is_array($serials)) { $serials = []; }
        // normalize and filter empties
        $serials = array_values(array_map(function($s){ return trim((string)$s); }, $serials));
        $serials = array_values(array_filter($serials, function($s){ return $s !== ''; }));
        if ($item_name === '' && $model !== '') { $item_name = $model; }
        if ($item_name !== '' && $quantity > 0) {
            // Server-side validations for serials
            if ($quantity > 0) {
                if (count($serials) !== $quantity) {
                    http_response_code(400);
                    echo 'Serial ID count must equal Quantity.';
                    exit();
                }
                if (count($serials) !== count(array_unique($serials))) {
                    http_response_code(400);
                    echo 'Serial IDs must be unique.';
                    exit();
                }
            }
            try {
                require_once __DIR__ . '/../vendor/autoload.php';
                require_once __DIR__ . '/db/mongo.php';
                $mdb = get_mongo_db();
                $itemsCol = $mdb->selectCollection('inventory_items');
                // Ensure no existing items share provided serial numbers
                if (!empty($serials)) {
                    $existing = $itemsCol->countDocuments(['serial_no' => ['$in' => $serials]]);
                    if ($existing > 0) {
                        http_response_code(400);
                        echo 'One or more Serial IDs already exist.';
                        exit();
                    }
                }
                // get next id once then increment
                $last = $itemsCol->findOne([], ['sort'=>['id'=>-1], 'projection'=>['id'=>1]]);
                $nextId = ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
                for ($i = 0; $i < $quantity; $i++) {
                    $itemsCol->insertOne([
                        'id' => $nextId++,
                        'item_name' => $item_name,
                        'category' => $category,
                        'model' => $model,
                        'quantity' => 1,
                        'location' => $location,
                        'condition' => $condition,
                        'status' => $status,
                        'date_acquired' => $date_acquired,
                        'remarks' => $remarks,
                        'serial_no' => $serials[$i] ?? '',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                $message = 'Item(s) saved successfully.';
            } catch (Throwable $e) {
                http_response_code(500);
                echo 'Database unavailable';
                exit();
            }
        } else {
            $message = 'Please provide required fields.';
        }
    } else {
        // QR generate post (kept for compatibility if used)
        $itemName = trim($_POST['item_name'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $formType = trim($_POST['form_type'] ?? '');
        $room = trim($_POST['room'] ?? '');
        if (!empty($itemName) && !empty($status) && !empty($formType) && !empty($room)) {
            $qrData = json_encode([
                'item_name' => $itemName,
                'status' => $status,
                'form_type' => $formType,
                'room' => $room,
                'generated_date' => date('Y-m-d H:i:s')
            ]);
            $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&margin=2&format=png&data=' . urlencode($qrData);
            if (!is_dir('qr_codes')) { mkdir('qr_codes', 0755, true); }
            $filename = 'qr_codes/' . preg_replace('/[^a-zA-Z0-9]/', '_', $itemName) . '_' . date('Ymd_His') . '.png';
            $qrImage = @file_get_contents($qrCodeUrl);
            if ($qrImage !== false) { file_put_contents($filename, $qrImage); $qrCodePath = $filename; $message = 'QR Code generated successfully!'; }
            else { $message = 'Error generating QR code. Please try again.'; }
        } else {
            $message = $message ?: 'Please fill in all fields.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate QR Code - Admin Dashboard</title>
    <link href="css/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__.'/css/style.css'); ?>">
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
      /* Serial ID input indicators */
      .sid-indicator { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none; }
      .sid-input-wrap { position: relative; }
    </style>

</head>
<body>
    <!-- Mobile Menu Toggle Button -->
    <button class="mobile-menu-toggle d-md-none" onclick="toggleSidebar()">
        <i class="bi bi-list"></i>
    </button>
    
    <div class="d-flex">
        <!-- Sidebar -->
        <div class="bg-light border-end" id="sidebar-wrapper">
            <div class="sidebar-heading py-4 fs-4 fw-bold border-bottom d-flex align-items-center justify-content-center">
                <img src="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" alt="ECA Logo" class="brand-logo me-2" />
                <span>ECA MIS-GMIS</span>
            </div>
            <div class="list-group list-group-flush my-3">
                <a href="admin_dashboard.php" class="list-group-item list-group-item-action bg-transparent">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
                <a href="inventory.php" class="list-group-item list-group-item-action bg-transparent">
					<i class="bi bi-box-seam me-2"></i>Inventory
				</a>
        <a href="inventory_print.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-printer me-2"></i>Print Inventory
                    </a>
                
                <a href="generate_qr.php" class="list-group-item list-group-item-action bg-transparent fw-bold">
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
                    <i class="bi bi-qr-code me-2"></i>Add Item/Generate QR
                </h2>
                <div class="d-flex align-items-center gap-3">
                    <div class="position-relative me-2" id="adminBellWrap">
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
                    <!-- Inline Add Item form is available below -->
                </div>
            </div>


            <div class="row align-items-stretch">
                <div class="col-md-8 col-lg-7">
                        <h5 class="mb-3">
                            <i class="bi bi-box-seam me-2"></i>Add New Item
                        </h5>
                        <form method="POST" class="modal-content p-3 border rounded h-100" action="generate_qr.php" id="inline_add_item_form">
                          <input type="hidden" name="create_item" value="1" />
                          <input type="hidden" name="item_name" id="add_item_name_hidden_inline" />
                          <div class="row g-3">
                            <div class="col-md-6">
                              <label class="form-label fw-bold">Category *</label>
                              <select name="category" id="add_category_inline" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categoryOptions as $cat): ?>
                                  <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                              </select>
                            </div>
                            <div class="col-md-6">
                              <label class="form-label fw-bold">Model *</label>
                              <input type="text" name="model" id="add_model_input_inline" class="form-control" placeholder="Enter model" required />
                            </div>
                            <div class="col-md-3">
                              <label class="form-label fw-bold">Quantity</label>
                              <input type="number" name="quantity" class="form-control" value="1" min="0" />
                            </div>
                            <div class="col-md-3">
                              <label class="form-label fw-bold">Location</label>
                              <input type="text" name="location" class="form-control" placeholder="MIS Office" />
                            </div>
                            <div class="col-md-3">
                              <label class="form-label fw-bold">Serial ID</label
                              >
                              <div class="d-grid">
                                <button type="button" class="btn btn-outline-secondary" id="open_serial_modal_inline">Add S.ID</button>
                              </div>
                              <input type="hidden" name="serial_list" id="serial_list_inline" />
                              <div class="small text-muted mt-1" id="serial_summary_inline"></div>
                            </div>
                            <div class="col-md-3">
                              <label class="form-label fw-bold">Date Acquired</label>
                              <input type="date" name="date_acquired" class="form-control" value="<?php echo date('Y-m-d'); ?>" />
                            </div>
                            <div class="col-12">
                              <label class="form-label fw-bold">Remarks</label>
                              <textarea name="remarks" class="form-control" rows="3" placeholder="Notes, supplier, warranty, etc."></textarea>
                            </div>
                            <div class="col-md-9">
                              <label class="form-label fw-bold">Add Category</label>
                              <div class="input-group">
                                <input type="text" class="form-control" id="inline_new_category" placeholder="New category name" />
                                <button type="button" class="btn btn-outline-primary" id="inline_add_category_btn"><i class="bi bi-plus-lg me-1"></i>Add</button>
                              </div>
                              <div class="small mt-1" id="inline_add_category_msg"></div>
                            </div>
                          </div>
                          <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
                            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-2"></i>Save Item</button>
                            <a href="generate_qr.php" class="btn btn-outline-secondary">Cancel</a>
                            <div class="small ms-2" id="inline_add_item_msg"></div>
                          </div>
                        </form>
                </div>
                <div class="col-md-4 col-lg-5 mt-4 mt-md-0">
                        <h5 class="mb-3">
                            <i class="bi bi-tags me-2"></i>Manage Categories
                        </h5>
                        <div class="border rounded h-100" style="overflow:hidden;">
                            <iframe src="categories.php?embed=1" style="border:0;width:100%;height:100%;"></iframe>
                        </div>
                </div>
            </div>

                
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function(){
            const bellBtn=document.getElementById('adminBellBtn');
            const bellDot=document.getElementById('adminBellDot');
            const dropdown=document.getElementById('adminBellDropdown');
            const listEl=document.getElementById('adminNotifList');
            const emptyEl=document.getElementById('adminNotifEmpty');
            if(bellBtn&&dropdown){
              bellBtn.addEventListener('click',function(e){e.stopPropagation();dropdown.classList.toggle('show');dropdown.style.position='absolute';dropdown.style.top=(bellBtn.offsetTop+bellBtn.offsetHeight+6)+'px';dropdown.style.left=(bellBtn.offsetLeft-(dropdown.offsetWidth-bellBtn.offsetWidth))+'px'; if (bellDot) bellDot.classList.add('d-none');});
              document.addEventListener('click',function(){dropdown.classList.remove('show');});
            }
            let toastWrap=document.getElementById('adminToastWrap'); if(!toastWrap){toastWrap=document.createElement('div');toastWrap.id='adminToastWrap';toastWrap.style.position='fixed';toastWrap.style.right='16px';toastWrap.style.bottom='16px';toastWrap.style.zIndex='1080';document.body.appendChild(toastWrap);} 
            function showToast(msg){const el=document.createElement('div');el.className='alert alert-info shadow-sm border-0';el.style.minWidth='280px';el.style.maxWidth='360px';el.innerHTML='<i class="bi bi-bell me-2"></i>'+String(msg||'');toastWrap.appendChild(el);setTimeout(()=>{try{el.remove();}catch(_){}} ,5000);} 
            let audioCtx=null;function playBeep(){try{if(!audioCtx)audioCtx=new(window.AudioContext||window.webkitAudioContext)();const o=audioCtx.createOscillator(),g=audioCtx.createGain();o.type='sine';o.frequency.value=880;g.gain.setValueAtTime(0.0001,audioCtx.currentTime);g.gain.exponentialRampToValueAtTime(0.2,audioCtx.currentTime+0.02);g.gain.exponentialRampToValueAtTime(0.0001,audioCtx.currentTime+0.22);o.connect(g);g.connect(audioCtx.destination);o.start();o.stop(audioCtx.currentTime+0.25);}catch(_){}}
            function escapeHtml(s){return String(s).replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m]));}
            function fmt12(txt){ try{ const s=String(txt||'').trim(); const m=s.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/); if(!m) return s; const date=m[1]; const H=parseInt(m[2],10); const mm=m[3]; const ap=(H>=12?'pm':'am'); let h=H%12; if(h===0) h=12; return date+' '+h+':'+mm+ap; } catch(_){ return String(txt||''); } }
            let baseline=new Set();let initialized=false;let fetching=false;
            function renderCombined(pending, recent){
              const rows=[];
              (pending||[]).forEach(r=>{const id=parseInt(r.id||0,10);const when=String(r.created_at||'');const qty=parseInt(r.quantity||1,10);rows.push('<a href="admin_borrow_center.php" class="list-group-item list-group-item-action">'+'<div class="d-flex w-100 justify-content-between">'+'<strong>#'+id+'</strong>'+'<small class="text-muted">'+escapeHtml(fmt12(when))+'</small>'+'</div>'+'<div class="mb-0">'+escapeHtml(String(r.username||''))+' requests '+escapeHtml(String(r.item_name||''))+' <span class="badge bg-secondary">x'+qty+'</span></div>'+'</a>');});
              if ((recent||[]).length){ rows.push('<div class="list-group-item"><div class="d-flex justify-content-between align-items-center"><span class="small text-muted">Processed</span><button type="button" class="btn btn-sm btn-outline-secondary btn-2xs" id="admClearAllBtn">Clear All</button></div></div>'); (recent||[]).forEach(r=>{const id=parseInt(r.id||0,10);const nm=String(r.item_name||'');const st=String(r.status||'');const when=String(r.processed_at||'');const bcls=(st==='Approved')?'badge bg-success':'badge bg-danger'; rows.push('<div class="list-group-item d-flex justify-content-between align-items-start">'+'<div class="me-2">'+'<div class="d-flex w-100 justify-content-between"><strong>#'+id+' '+escapeHtml(nm)+'</strong><small class="text-muted">'+escapeHtml(fmt12(when))+'</small></div>'+'<div class="small">Status: <span class="'+bcls+'">'+escapeHtml(st)+'</span></div>'+'</div>'+'<div><button type="button" class="btn-close adm-clear-one" aria-label="Clear" data-id="'+id+'"></button></div>'+'</div>'); }); }
              listEl.innerHTML=rows.join(''); if (emptyEl) emptyEl.style.display=rows.length?'none':'';
            }
            document.addEventListener('click', function(ev){ const one=ev.target && ev.target.closest && ev.target.closest('.adm-clear-one'); if (one){ const rid=parseInt(one.getAttribute('data-id')||'0',10)||0; if(!rid) return; const fd=new FormData(); fd.append('request_id', String(rid)); fetch('admin_borrow_center.php?action=admin_notif_clear',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ poll(); }).catch(()=>{}); return; } if (ev.target && ev.target.id==='admClearAllBtn'){ const fd=new FormData(); fd.append('limit','300'); fetch('admin_borrow_center.php?action=admin_notif_clear_all',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ poll(); }).catch(()=>{}); } });
            function poll(){if(fetching)return;fetching=true;fetch('admin_borrow_center.php?action=admin_notifications').then(r=>r.json()).then(d=>{const pending=(d&&Array.isArray(d.pending))?d.pending:[];const recent=(d&&Array.isArray(d.recent))?d.recent:[]; if(bellDot)bellDot.classList.toggle('d-none',pending.length===0); try{const navLink=document.querySelector('a[href="admin_borrow_center.php"]');if(navLink){let dot=navLink.querySelector('.nav-borrow-dot');const shouldShow=pending.length>0;if(shouldShow){if(!dot){dot=document.createElement('span');dot.className='nav-borrow-dot ms-2 d-inline-block rounded-circle';dot.style.width='8px';dot.style.height='8px';dot.style.backgroundColor='#dc3545';dot.style.verticalAlign='middle';dot.style.display='inline-block';navLink.appendChild(dot);}else{dot.style.display='inline-block';}}else if(dot){dot.style.display='none';}}}catch(_){} renderCombined(pending,recent); const curr=new Set(pending.map(it=>parseInt(it.id||0,10))); if(!initialized){baseline=curr;initialized=true;}else{let hasNew=false; pending.forEach(it=>{const id=parseInt(it.id||0,10); if(!baseline.has(id)){hasNew=true; showToast('New request: '+(it.username||'')+' → '+(it.item_name||'')+' (x'+(it.quantity||1)+')'); } }); if(hasNew)playBeep(); baseline=curr; } }).catch(()=>{}).finally(()=>{fetching=false;});}
            poll();setInterval(()=>{if(document.visibilityState==='visible')poll();},1000);
        })();
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar-wrapper');
            sidebar.classList.toggle('active');
            if (window.innerWidth <= 768) {
                document.body.classList.toggle('sidebar-open', sidebar.classList.contains('active'));
            }
        }
        
        // Minimal validation could be added if needed
        
        // Live refresh categories for selects without page reload
        (function(){
            const INLINE_SELECT_ID = 'add_category_inline';
            const MODAL_SELECT_ID = 'add_category';
            let lastSet = new Set();
            
            // Function to load categories from server
            function loadCategories(callback) {
                fetch('generate_qr.php?action=categories_json')
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.ok) {
                            const selects = [
                                document.getElementById(INLINE_SELECT_ID),
                                document.getElementById(MODAL_SELECT_ID)
                            ];
                            selects.forEach(select => {
                                if (select) applyOptions(select, data.categories);
                            });
                            if (typeof callback === 'function') callback();
                        }
                    });
            }
            
            function applyOptions(selectEl, cats){
                if (!selectEl) return;
                const current = Array.from(selectEl.options).map(o=>o.value);
                // Keep placeholder as first option
                const placeholder = current.length>0 ? selectEl.options[0] : null;
                selectEl.innerHTML = '';
                if (placeholder) {
                    const opt0 = document.createElement('option');
                    opt0.value = '';
                    opt0.textContent = placeholder.textContent || 'Select Category';
                    selectEl.appendChild(opt0);
                } else {
                    const opt0 = document.createElement('option');
                    opt0.value = '';
                    opt0.textContent = 'Select Category';
                    selectEl.appendChild(opt0);
                }
                cats.forEach(c=>{
                    const opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = c;
                    selectEl.appendChild(opt);
                });
            }
            function setsEqual(a,b){ if(a.size!==b.size)return false; for(const v of a){ if(!b.has(v)) return false; } return true; }
            function refresh(){
                fetch('generate_qr.php?action=categories_json')
                  .then(r=>r.ok?r.json():Promise.reject())
                  .then(d=>{
                      const cats = Array.isArray(d.categories) ? d.categories : [];
                      const newSet = new Set(cats);
                      if (!setsEqual(lastSet, newSet)){
                          const inlineSel = document.getElementById(INLINE_SELECT_ID);
                          const modalSel = document.getElementById(MODAL_SELECT_ID);
                          applyOptions(inlineSel, cats);
                          applyOptions(modalSel, cats);
                          lastSet = newSet;
                      }
                  })
                  .catch(()=>{});
            }
            // Initial fetch and periodic polling
            document.addEventListener('DOMContentLoaded', function(){
                refresh();
                setInterval(function(){ if(document.visibilityState==='visible') refresh(); }, 1500);
            });
        })();
        
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

        (function(){
            function setMsg(text, ok){
                var el = document.getElementById('inline_add_category_msg');
                if (!el) return;
                el.textContent = text || '';
                el.className = 'small mt-1 ' + (ok ? 'text-success' : 'text-danger');
            }
            document.addEventListener('DOMContentLoaded', function(){
                var btn = document.getElementById('inline_add_category_btn');
                var input = document.getElementById('inline_new_category');
                if (!btn || !input) return;
                btn.addEventListener('click', function(){
                    var name = (input.value || '').trim();
                    if (name === '') { setMsg('Category name is required', false); return; }
                    btn.disabled = true;
                    setMsg('Adding...', true);
                    var fd = new FormData();
                    fd.append('add', '1');
                    fd.append('name', name);
                    fetch('categories.php', { method: 'POST', body: fd })
                      .then(function(r) {
                          if (!r.ok) return r.text().then(err => { throw new Error(err || 'Failed to add category'); });
                          return r.text();
                      })
                      .then(function(html) {
                          // Check if the response contains an error message
                          const tempDiv = document.createElement('div');
                          tempDiv.innerHTML = html;
                          const errorDiv = tempDiv.querySelector('.alert-danger');
                          
                          if (errorDiv) {
                              throw new Error(errorDiv.textContent.trim() || 'Failed to add category');
                          }
                          
                          setMsg('Category added', true);
                          input.value = '';
                          
                          // Reload the iframe
                          try {
                              var iframe = document.querySelector('iframe[src^="categories.php"]');
                              if (iframe && iframe.contentWindow) { 
                                  iframe.contentWindow.location.reload(); 
                              }
                          } catch(_) {}
                          
                          // Refresh the category dropdowns
                          return fetch('generate_qr.php?action=categories_json')
                              .then(function(r) { 
                                  if (!r.ok) throw new Error('Failed to refresh categories'); 
                                  return r.json(); 
                              });
                      })
                      .then(function(d) {
                          if (!d) return;
                          var cats = Array.isArray(d.categories) ? d.categories : [];
                          
                          function applyOptions(selectEl) {
                              if (!selectEl) return;
                              var placeholder = 'Select Category';
                              var curr0 = selectEl.options && selectEl.options[0] ? selectEl.options[0].textContent : placeholder;
                              var currentValue = selectEl.value;
                              
                              selectEl.innerHTML = '';
                              var opt0 = document.createElement('option');
                              opt0.value = '';
                              opt0.textContent = curr0 || placeholder;
                              selectEl.appendChild(opt0);
                              
                              cats.forEach(function(c) { 
                                  var opt = document.createElement('option'); 
                                  opt.value = c; 
                                  opt.textContent = c;
                                  if (c === currentValue) {
                                      opt.selected = true;
                                  }
                                  selectEl.appendChild(opt); 
                              });
                          }
                          
                          applyOptions(document.getElementById('add_category_inline'));
                          applyOptions(document.getElementById('add_category'));
                      })
                      .catch(function(err) { 
                          setMsg(err.message || 'Failed to add category (may already exist)', false); 
                      })
                      .finally(function() { 
                          btn.disabled = false; 
                      });
                });
                input.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); btn.click(); } });
            });
        })();

        (function(){
            function setItemMsg(text, ok){
                var el = document.getElementById('inline_add_item_msg');
                if (!el) return;
                el.textContent = text || '';
                el.className = 'small ms-2 ' + (ok ? 'text-success' : 'text-danger');
            }
            document.addEventListener('DOMContentLoaded', function(){
                var form = document.getElementById('inline_add_item_form');
                if (!form) return;
                var submitBtn = form.querySelector('button[type="submit"]');
                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    if (submitBtn) submitBtn.disabled = true;
                    setItemMsg('Saving...', true);
                    var fd = new FormData(form);
                    fetch('generate_qr.php', { method: 'POST', body: fd })
                      .then(function(r){ if(!r.ok) throw new Error(); return r.text(); })
                      .then(function(){
                          setItemMsg('Item(s) saved successfully.', true);
                          try { form.reset(); } catch(_){}
                          var hidden = document.getElementById('add_item_name_hidden_inline');
                          if (hidden) hidden.value = '';
                      })
                      .catch(function(){ setItemMsg('Save failed. Please try again.', false); })
                      .finally(function(){ if (submitBtn) submitBtn.disabled = false; });
                });
            });
        })();
    </script>

    

    <!-- Add New Item Modal (copied from inventory.php for consistency) -->
    <div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content" action="generate_qr.php" id="add_item_modal_form">
          <input type="hidden" name="create_item" value="1" />
          <input type="hidden" name="item_name" id="add_item_name_hidden" />
          <div class="modal-header">
            <h5 class="modal-title" id="addItemModalLabel"><i class="bi bi-plus-lg me-2"></i>Add New Item</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-bold">Category *</label>
                <select name="category" id="add_category" class="form-select" required>
                  <option value="">Select Category</option>
                  <?php foreach ($categoryOptions as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold">Model *</label>
                <input type="text" name="model" id="add_model_input" class="form-control" placeholder="Enter model" required />
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold">Quantity</label>
                <input type="number" name="quantity" class="form-control" value="1" min="0" />
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold">Location</label>
                <input type="text" name="location" class="form-control" placeholder="MIS Office" />
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold">Serial ID</label
                >
                <div class="d-grid">
                  <button type="button" class="btn btn-outline-secondary" id="open_serial_modal_main">Add S.ID</button>
                </div>
                <input type="hidden" name="serial_list" id="serial_list_main" />
                <div class="small text-muted mt-1" id="serial_summary_main"></div>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-bold">Date Acquired</label>
                <input type="date" name="date_acquired" class="form-control" />
              </div>
              <div class="col-12">
                <label class="form-label fw-bold">Remarks</label>
                <textarea name="remarks" class="form-control" rows="3" placeholder="Notes, supplier, warranty, etc."></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-2"></i>Save Item</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Serial IDs Modal -->
    <div class="modal fade" id="serialIdsModal" tabindex="-1" aria-labelledby="serialIdsModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="serialIdsModalLabel">Enter Serial IDs</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info py-2 small">Provide a unique Serial ID for each item. Count must match Quantity.</div>
            <div id="serial_modal_error" class="alert alert-danger d-none py-2 small"></div>
            <div class="mb-2 small">
              <span>Quantity:</span>
              <span id="serial_modal_qty" class="fw-bold">0</span>
            </div>
            <div id="serial_inputs_wrap" class="row g-2" style="max-height: 50vh; overflow: auto;"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="serial_modal_save_btn">Save Serial IDs</button>
          </div>
        </div>
      </div>
    </div>

    <script>
    // Sync hidden item_name with the entered model (modal and inline)
    function syncHiddenNameFromInput(inputEl, hiddenEl) {
      if (!inputEl || !hiddenEl) return;
      hiddenEl.value = (inputEl.value || '').trim();
    }
    document.addEventListener('DOMContentLoaded', function() {
      var addModelInput = document.getElementById('add_model_input');
      var addHidden = document.getElementById('add_item_name_hidden');
      if (addModelInput) {
        addModelInput.addEventListener('input', function(){ syncHiddenNameFromInput(addModelInput, addHidden); });
        syncHiddenNameFromInput(addModelInput, addHidden);
      }

      // Inline form wiring
      var addModelInputInline = document.getElementById('add_model_input_inline');
      var addHiddenInline = document.getElementById('add_item_name_hidden_inline');
      if (addModelInputInline) {
        addModelInputInline.addEventListener('input', function(){ syncHiddenNameFromInput(addModelInputInline, addHiddenInline); });
        syncHiddenNameFromInput(addModelInputInline, addHiddenInline);
      }
    });
    </script>

    <script>
      (function(){
        let serialModal, currentCtx = null;
        const sidCache = new Map();
        function debounce(fn, wait){ let t; return function(){ const ctx=this, args=arguments; clearTimeout(t); t=setTimeout(()=>fn.apply(ctx,args), wait); }; }
        function ensureModal(){ if(!serialModal){ const el = document.getElementById('serialIdsModal'); if (el && window.bootstrap) serialModal = new bootstrap.Modal(el); } }
        function setError(msg){ const el = document.getElementById('serial_modal_error'); if(!el) return; if(msg){ el.textContent = msg; el.classList.remove('d-none'); } else { el.textContent=''; el.classList.add('d-none'); } }
        function makeInput(index, value){
          const col = document.createElement('div'); col.className = 'col-md-6';
          const group = document.createElement('div'); group.className = 'input-group input-group-sm';
          const span = document.createElement('span'); span.className = 'input-group-text'; span.textContent = String(index+1);
          const wrap = document.createElement('div'); wrap.className = 'sid-input-wrap flex-grow-1';
          const inp = document.createElement('input'); inp.type = 'text'; inp.className = 'form-control serial-input'; inp.placeholder = 'Serial ID #' + (index+1); inp.value = value || ''; inp.setAttribute('data-index', String(index));
          const icon = document.createElement('i'); icon.className = 'sid-indicator bi'; icon.style.display='none';
          wrap.appendChild(inp); wrap.appendChild(icon);
          group.appendChild(span); group.appendChild(wrap); col.appendChild(group); return col;
        }
        function setIndicator(inputEl, state){ // state: 'ok' | 'bad' | 'clear'
          const wrap = inputEl && inputEl.parentElement; if (!wrap) return;
          const icon = wrap.querySelector('.sid-indicator'); if (!icon) return;
          if (state === 'ok'){ icon.className = 'sid-indicator bi bi-check-circle text-success'; icon.style.display='inline-block'; }
          else if (state === 'bad'){ icon.className = 'sid-indicator bi bi-x-circle text-danger'; icon.style.display='inline-block'; }
          else { icon.style.display='none'; }
        }
        const checkServerDebounced = debounce(async function(inputEl){
          const val = (inputEl.value||'').trim(); if (val===''){ setIndicator(inputEl,'clear'); return; }
          if (sidCache.has(val)) { setIndicator(inputEl, sidCache.get(val) ? 'bad' : 'ok'); return; }
          try {
            const r = await fetch('generate_qr.php?action=check_serial&sid=' + encodeURIComponent(val));
            if (!r.ok) throw new Error();
            const d = await r.json();
            const exists = !!(d && d.exists);
            sidCache.set(val, exists);
            setIndicator(inputEl, exists ? 'bad' : 'ok');
          } catch(_){ /* network issues: do not block, clear */ setIndicator(inputEl,'clear'); }
        }, 250);
        function validateInput(inputEl){
          const val = (inputEl.value||'').trim(); if (val===''){ setIndicator(inputEl,'clear'); return; }
          // Local duplicate check (ignore this input's own index)
          const idx = parseInt(inputEl.getAttribute('data-index')||'-1',10);
          const all = Array.from(document.querySelectorAll('#serial_inputs_wrap input.serial-input'));
          let dup = false;
          for (const el of all){ if (el===inputEl) continue; if ((el.value||'').trim() === val){ dup = true; break; } }
          if (dup){ setIndicator(inputEl,'bad'); return; }
          // Server check
          checkServerDebounced(inputEl);
        }
        function openSerialModal(ctx){
          ensureModal(); setError(''); currentCtx = ctx || null; if(!currentCtx || !serialModal) return;
          const qtyVal = Math.max(0, parseInt((currentCtx.qtyEl && currentCtx.qtyEl.value) || '0', 10));
          const qtyEl = document.getElementById('serial_modal_qty'); if (qtyEl) qtyEl.textContent = String(qtyVal);
          const wrap = document.getElementById('serial_inputs_wrap');
          wrap.innerHTML = '';
          let existing = [];
          try { existing = JSON.parse(currentCtx.hiddenEl.value || '[]'); if(!Array.isArray(existing)) existing = []; } catch(_){ existing = []; }
          const n = Math.min(qtyVal, 500);
          for (let i=0; i<n; i++) { const node = makeInput(i, existing[i] || ''); wrap.appendChild(node); }
          // Wire input events
          const inputs = Array.from(wrap.querySelectorAll('input.serial-input'));
          inputs.forEach(inp=>{
            inp.addEventListener('input', function(){ setIndicator(inp,'clear'); validateInput(inp); });
            // initial validate
            validateInput(inp);
          });
          serialModal.show();
          setTimeout(()=>{ const f = wrap.querySelector('input.serial-input'); if (f) try{ f.focus(); }catch(_){ } }, 150);
        }
        function summarize(list){ if(!Array.isArray(list)) return ''; const n=list.length; if(n===0) return 'No S.IDs set'; if(n<=3) return list.join(', '); return n + ' S.IDs set. First: ' + list.slice(0,3).join(', ') + '...'; }
        function unique(arr){ const seen = new Set(); const out=[]; for(const s of arr){ if(!seen.has(s)){ seen.add(s); out.push(s);} } return out; }
        function collectAndSave(){ if(!currentCtx) return; setError('');
          const wrap = document.getElementById('serial_inputs_wrap');
          const inputs = Array.from(wrap.querySelectorAll('input.serial-input'));
          const qty = Math.max(0, parseInt((currentCtx.qtyEl && currentCtx.qtyEl.value) || '0',10));
          const vals = inputs.map(i=> (i.value||'').trim());
          if (qty !== vals.length) { setError('Serial ID count must equal Quantity ('+qty+').'); return; }
          if (vals.some(v=>v==='')) { setError('All Serial IDs are required.'); return; }
          if (unique(vals).length !== vals.length) { setError('Serial IDs must be unique.'); return; }
          try { currentCtx.hiddenEl.value = JSON.stringify(vals); } catch(_){ currentCtx.hiddenEl.value = '[]'; }
          if (currentCtx.summaryEl) { currentCtx.summaryEl.textContent = summarize(vals); }
          if (serialModal) serialModal.hide();
        }
        document.addEventListener('DOMContentLoaded', function(){
          const inlineBtn = document.getElementById('open_serial_modal_inline');
          const mainBtn = document.getElementById('open_serial_modal_main');
          if (inlineBtn){ inlineBtn.addEventListener('click', function(){
            const form = document.getElementById('inline_add_item_form'); if(!form) return;
            const ctx = {
              qtyEl: form.querySelector('input[name="quantity"]'),
              hiddenEl: document.getElementById('serial_list_inline'),
              summaryEl: document.getElementById('serial_summary_inline')
            };
            openSerialModal(ctx);
          }); }
          if (mainBtn){ mainBtn.addEventListener('click', function(){
            const form = document.getElementById('add_item_modal_form'); if(!form) return;
            const ctx = {
              qtyEl: form.querySelector('input[name="quantity"]'),
              hiddenEl: document.getElementById('serial_list_main'),
              summaryEl: document.getElementById('serial_summary_main')
            };
            openSerialModal(ctx);
          }); }
          const saveBtn = document.getElementById('serial_modal_save_btn'); if (saveBtn){ saveBtn.addEventListener('click', collectAndSave); }

          function wireQtyClear(formId, hiddenId, summaryId){
            const form = document.getElementById(formId); if(!form) return;
            const qtyEl = form.querySelector('input[name="quantity"]');
            const hiddenEl = document.getElementById(hiddenId);
            const summaryEl = document.getElementById(summaryId);
            if (qtyEl) qtyEl.addEventListener('input', function(){ try{
              const arr = JSON.parse(hiddenEl.value||'[]');
              const q = Math.max(0, parseInt(qtyEl.value||'0',10));
              if (Array.isArray(arr) && arr.length !== q){ hiddenEl.value = '[]'; if (summaryEl) summaryEl.textContent = ''; }
            }catch(_){ hiddenEl.value='[]'; if (summaryEl) summaryEl.textContent=''; }
            });
          }
          wireQtyClear('inline_add_item_form','serial_list_inline','serial_summary_inline');
          wireQtyClear('add_item_modal_form','serial_list_main','serial_summary_main');
        });

        // Client-side pre-submit check for inline form (AJAX)
        (function(){
          const form = document.getElementById('inline_add_item_form'); if(!form) return;
          const msgEl = document.getElementById('inline_add_item_msg');
          form.addEventListener('submit', function(e){
            try {
              const qty = Math.max(0, parseInt((form.querySelector('input[name="quantity"]').value)||'0',10));
              const arr = JSON.parse((document.getElementById('serial_list_inline').value)||'[]');
              if (qty>0 && (!Array.isArray(arr) || arr.length !== qty)){
                e.preventDefault();
                if (msgEl){ msgEl.textContent = 'Please provide '+qty+' unique Serial IDs via Add S.ID.'; msgEl.className = 'small ms-2 text-danger'; }
                return false;
              }
            } catch(_){ /* server will validate */ }
          }, true);
        })();
      })();
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
