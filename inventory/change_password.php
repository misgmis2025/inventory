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

$username = $_SESSION['username'];
$usertype = $_SESSION['usertype'] ?? 'user';

$emergency = 'ECAMISGMIS2025';

// Mongo-first path
$CP_MONGO_FAILED = false;
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $usersCol = $db->selectCollection('users');

    // AJAX: verify current password correctness (for live feedback)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'check_current') {
        header('Content-Type: application/json');
        $current = $_POST['current_password'] ?? '';
        $ok = false;
        $u = $usersCol->findOne(['username'=>$username], ['projection'=>['password_hash'=>1,'usertype'=>1]]);
        if ($u) {
            $ph = (string)($u['password_hash'] ?? '');
            $role = (string)($u['usertype'] ?? 'user');
            $ok = ($ph !== '' && password_verify($current, $ph)) || ($current === $emergency && $role === 'admin');
        }
        echo json_encode(['ok'=>$ok]);
        exit();
    }
} catch (Throwable $e) {
    $CP_MONGO_FAILED = true;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new !== $confirm) {
        $error = 'Passwords do not match!';
    } elseif (strlen($new) < 6 || strlen($new) > 24 || !preg_match('/[A-Z]/', $new)) {
        $error = 'Password must be 6-24 chars and contain at least one capital letter.';
    } else {
        if (!$CP_MONGO_FAILED) {
            $u = $usersCol->findOne(['username'=>$username], ['projection'=>['password_hash'=>1,'usertype'=>1]]);
            if ($u) {
                $storedHash = (string)($u['password_hash'] ?? '');
                $role = (string)($u['usertype'] ?? 'user');
                $authOk = ($storedHash !== '' && password_verify($current, $storedHash)) || ($current === $emergency && $role === 'admin');
                if ($authOk) {
                    if ($new === $current || ($storedHash !== '' && password_verify($new, $storedHash))) {
                        $error = 'New password must be different from current password.';
                    } else {
                        $newHash = password_hash($new, PASSWORD_DEFAULT);
                        $usersCol->updateOne(['username'=>$username], ['$set'=>['password_hash'=>$newHash, 'updated_at'=>date('Y-m-d H:i:s')]]);
                        $message = 'Password updated successfully.';
                    }
                } else { $error = 'Current password is incorrect.'; }
            } else { $error = 'User not found.'; }
        } else {
            $error = 'Service temporarily unavailable. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <link href="css/bootstrap/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__.'/css/style.css'); ?>">
    <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" />
    <!-- Sidebar visible: removed previous rule that hid it on this page -->
    <style>
      @media (min-width: 769px) {
        #sidebar-wrapper{ display:block !important; }
        .mobile-menu-toggle{ display:none !important; }
      }
      @media (max-width: 768px) {
        /* Keep notification bells visible on mobile */
        #adminBellWrap, #userBellWrapCP {
          position: static !important;
          top: auto !important;
          right: auto !important;
          z-index: auto !important;
        }
        .page-header .d-flex.align-items-center.gap-3{ flex-wrap: nowrap; gap: 8px !important; }
        #userBellBtnCP, #adminBellBtn, .page-header .btn { padding: .25rem .5rem; }
        /* Bottom nav styles (match Request page) */
        .bottom-nav{ position: fixed; bottom: 0; left:0; right:0; z-index: 1050; background:#fff; border-top:1px solid #dee2e6; display:flex; justify-content:space-around; padding:8px 6px; transition: transform .2s ease-in-out; }
        .bottom-nav.hidden{ transform: translateY(100%); }
        .bottom-nav a{ text-decoration:none; font-size:12px; color:#333; display:flex; flex-direction:column; align-items:center; gap:4px; }
        .bottom-nav a .bi{ font-size:18px; }
        .bottom-nav-toggle{ position: fixed; right: 14px; bottom: 14px; z-index: 1060; border-radius: 999px; box-shadow: 0 2px 8px rgba(0,0,0,.2); transition: bottom .2s ease-in-out; }
        .bottom-nav-toggle.raised{ bottom: 78px; }
        .bottom-nav-toggle .bi{ font-size: 1.2rem; }
      }
      /* Mobile notifications modal (match Request page) */
      #userBellModalCP{ display:none; position:fixed; inset:0; z-index:1095; align-items:center; justify-content:center; padding:16px; }
      #userBellBackdropCP{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1094; }
      #userBellModalCP .ubm-box{ background:#fff; width:92vw; max-width:520px; max-height:80vh; border-radius:8px; overflow:hidden; box-shadow:0 10px 24px rgba(0,0,0,.25); display:flex; flex-direction:column; }
      #userBellModalCP .ubm-head{ padding:10px 12px; border-bottom:1px solid #e9ecef; display:flex; align-items:center; justify-content:space-between; font-weight:600; }
      #userBellModalCP .ubm-close{ background:transparent; border:0; font-size:20px; line-height:1; }
      #userBellModalCP .ubm-body{ padding:0; overflow:auto; }
      .capslock-indicator {
        position: absolute;
        right: 2.3rem;
        top: 50%;
        transform: translateY(-50%);
        color: #0d6efd;
        font-size: 1rem;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease-in-out;
      }
      .capslock-indicator i {
        color: #0d6efd !important;
      }
      .capslock-indicator.active {
        opacity: 1;
      }
      .has-capslock-icon .form-control {
        padding-right: 3.2rem;
      }
      .password-toggle-btn {
        position: absolute;
        right: 0.65rem;
        top: 50%;
        transform: translateY(-50%);
        border: 0;
        background: transparent;
        padding: 0;
        color: #6b7280;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }
      .password-toggle-btn:focus {
        outline: none;
        box-shadow: none;
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
                <?php if ($usertype === 'admin'): ?>
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
                    <a href="qr_scanner.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-camera me-2"></i>QR Scanner
                    </a>
                    <a href="admin_borrow_center.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-clipboard-check me-2"></i>Borrow Requests
                    </a>
                    <a href="user_management.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-people me-2"></i>User Management
                    </a>
                <?php else: ?>
                    <a href="user_dashboard.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-speedometer2 me-2"></i>Dashboard
                    </a>
                    <a href="user_request.php" class="list-group-item list-group-item-action bg-transparent">
                        <i class="bi bi-clipboard-plus me-2"></i>Request to Borrow
                    </a>
                    
                <?php endif; ?>
        <?php if ($usertype !== 'admin'): ?>
        <button type="button" class="btn btn-primary bottom-nav-toggle d-md-none" id="bnToggleCPU" aria-controls="cpBottomNavU" aria-expanded="false" title="Open menu">
          <i class="bi bi-list"></i>
        </button>
        <nav class="bottom-nav d-md-none hidden" id="cpBottomNavU">
          <a href="user_dashboard.php" aria-label="Dashboard">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard</span>
          </a>
          <a href="user_request.php" aria-label="Request">
            <i class="bi bi-clipboard-plus"></i>
            <span>Request</span>
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
            var btn = document.getElementById('bnToggleCPU');
            var nav = document.getElementById('cpBottomNavU');
            function setPersistentWrapOffset(open){
              try{
                if (!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches)) return;
                var wrap = document.getElementById('userPersistentWrap');
                if (!wrap) return;
                // Measure nav height when visible to position popup just above it
                var nav = document.getElementById('cpBottomNavU');
                var bottomPx = 28;
                try {
                  if (nav && !nav.classList.contains('hidden')) {
                    var rect = nav.getBoundingClientRect();
                    var h = Math.round(Math.max(0, window.innerHeight - rect.top));
                    if (!h || !isFinite(h)) h = 64;
                    bottomPx = h + 12; // 12px breathing room above nav
                  }
                } catch(_){ bottomPx = open ? 140 : 28; }
                wrap.style.bottom = String(bottomPx)+'px';
                // Keep right edge consistent and tuck near toggle
                wrap.style.right = '14px';
                // Popup remains non-interactive (click-through)
                wrap.style.pointerEvents = 'none';
              }catch(_){ }
            }
            if (btn && nav) {
              btn.addEventListener('click', function(){
                var hid = nav.classList.toggle('hidden');
                btn.setAttribute('aria-expanded', String(!hid));
                if (!hid) {
                  btn.classList.add('raised');
                  btn.title = 'Close menu';
                  var i = btn.querySelector('i'); if (i) { i.className = 'bi bi-x'; }
                  setPersistentWrapOffset(true);
                } else {
                  btn.classList.remove('raised');
                  btn.title = 'Open menu';
                  var i2 = btn.querySelector('i'); if (i2) { i2.className = 'bi bi-list'; }
                  setPersistentWrapOffset(false);
                }
              });
              // Initialize position
              try { var isOpen = !nav.classList.contains('hidden'); setPersistentWrapOffset(isOpen); } catch(_){ }
              // Observe changes to nav visibility to adjust popup even if toggled elsewhere
              try {
                var obs = new MutationObserver(function(){
                  try { var open = !nav.classList.contains('hidden'); setPersistentWrapOffset(open); } catch(_){}
                });
                obs.observe(nav, { attributes: true, attributeFilter: ['class'] });
              } catch(_){ }
              // Keep position correct on resize/orientation
              try { window.addEventListener('resize', function(){ var open = !nav.classList.contains('hidden'); setPersistentWrapOffset(open); }); } catch(_){ }
              try { window.addEventListener('orientationchange', function(){ var open = !nav.classList.contains('hidden'); setPersistentWrapOffset(open); }); } catch(_){ }
            }
          })();
        document.addEventListener('click', function(ev){ const x = ev.target && ev.target.closest && ev.target.closest('.u-clear-one'); if (x){ ev.preventDefault(); const key = x.getAttribute('data-key')||''; if(!key) return; const fd=new FormData(); fd.append('key', key); fetch('user_request.php?action=user_notif_clear',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ try{ if (typeof poll === 'function') poll(true); }catch(_){ } }).catch(()=>{}); return; } if (ev.target && ev.target.id === 'uClearAllBtn'){ ev.preventDefault(); const fd=new FormData(); fd.append('limit','300'); fetch('user_request.php?action=user_notif_clear_all',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ try{ if (typeof poll === 'function') poll(true); }catch(_){ } }).catch(()=>{}); } });
        </script>
        <?php endif; ?>
                <a href="change_password.php" class="list-group-item list-group-item-action bg-transparent fw-bold">
                    <i class="bi bi-key me-2"></i>Change Password
                </a>
                <a href="logout.php" class="list-group-item list-group-item-action bg-transparent" onclick="return confirm('Are you sure you want to logout?');">
                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                </a>
            </div>
        </div>

        <div class="p-4" id="page-content-wrapper">
            <div class="page-header d-flex justify-content-between align-items-center">
                <h2 class="page-title mb-0">
                    <i class="bi bi-key me-2"></i>Change Password
                </h2>
                <div class="d-flex align-items-center gap-3">
                    <span class="text-muted">Welcome, <?php echo htmlspecialchars($username); ?>!</span>
                    <?php if ($usertype === 'admin'): ?>
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
                    <?php else: ?>
                    <div class="position-relative me-2" id="userBellWrapCP">
                        <button class="btn btn-light position-relative" id="userBellBtnCP" title="Notifications">
                            <i class="bi bi-bell" style="font-size:1.2rem;"></i>
                            <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle d-none" id="userBellDotCP"></span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end shadow" id="userBellDropdownCP" style="min-width: 320px !important; max-width: 360px !important; width: auto !important; max-height: 360px; overflow:auto;">
                            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                              <span class="fw-bold small">Request Updates</span>
                              <button type="button" class="btn-close" id="userBellCloseCP" aria-label="Close"></button>
                            </div>
                            <div id="userNotifListCP" class="list-group list-group-flush small"></div>
                            <div class="text-center small text-muted py-2" id="userNotifEmptyCP">No updates yet.</div>
                            <div class="border-top p-2 text-center">
                                <a href="user_request.php" class="btn btn-sm btn-outline-primary">Go to Requests</a>
                            </div>
                        </div>
                    </div>
                    <!-- Mobile Notifications Modal -->
                    <div id="userBellBackdropCP" aria-hidden="true"></div>
                    <div id="userBellModalCP" role="dialog" aria-modal="true" aria-labelledby="ubmTitleCP">
                      <div class="ubm-box">
                        <div class="ubm-head">
                          <div id="ubmTitleCP" class="small">Request Updates</div>
                          <button type="button" class="ubm-close" id="ubmCloseBtnCP" aria-label="Close">&times;</button>
                        </div>
                        <div class="ubm-body">
                          <div id="userNotifListMobileCP" class="list-group list-group-flush small"></div>
                          <div class="text-center small text-muted py-2" id="userNotifEmptyMobileCP">No updates yet.</div>
                          <div class="border-top p-2 text-center">
                            <a href="user_request.php" class="btn btn-sm btn-outline-primary">Go to Requests</a>
                          </div>
                        </div>
                      </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-12 col-md-8 col-lg-6 mx-auto">
                    <div class="form-section">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-bold" for="current_password">Current Password</label>
                                <div class="position-relative has-capslock-icon">
                                    <input type="password" id="current_password" name="current_password" class="form-control" required />
                                    <span class="capslock-indicator" title="Caps Lock is ON" aria-hidden="true">
                                        <i class="bi bi-capslock-fill"></i>
                                    </span>
                                </div>
                                <small id="currentPwMsg" style="color:#dc3545; display:none; margin-top:.25rem;">Wrong password</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold" for="new_password">New Password</label>
                                <div class="position-relative has-capslock-icon">
                                    <input type="password" id="new_password" name="new_password" class="form-control" required />
                                    <button type="button" id="view_password_change" class="password-toggle-btn" aria-label="Show password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <span class="capslock-indicator" title="Caps Lock is ON" aria-hidden="true">
                                        <i class="bi bi-capslock-fill"></i>
                                    </span>
                                </div>
                                <small id="pwReqMsg" style="display:none; margin-top:.25rem; color:#dc3545;">password must be at least 6 character long</small>
                                <small id="pwSameMsg" style="display:none; margin-top:.25rem; color:#dc3545;">New password must be different from current password</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold" for="confirm_password">Confirm New Password</label>
                                <div class="position-relative has-capslock-icon">
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required />
                                    <span class="capslock-indicator" title="Caps Lock is ON" aria-hidden="true">
                                        <i class="bi bi-capslock-fill"></i>
                                    </span>
                                </div>
                                <small id="pwMismatch" style="color:#dc3545; display:none; margin-top: .25rem;">Passwords don't match</small>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Update Password</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="history.back()">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function(){
            var isAdmin = <?php echo json_encode($usertype === 'admin'); ?>;
            if (!isAdmin) return;
            const bellBtn=document.getElementById('adminBellBtn');
            const bellDot=document.getElementById('adminBellDot');
            const dropdown=document.getElementById('adminBellDropdown');
            const listEl=document.getElementById('adminNotifList');
            const emptyEl=document.getElementById('adminNotifEmpty');
            let latestTs=0;
            if(bellBtn&&dropdown){ bellBtn.addEventListener('click',function(e){ e.stopPropagation(); dropdown.classList.toggle('show'); if (window.innerWidth <= 768) { dropdown.style.position='fixed'; dropdown.style.top=(bellBtn.getBoundingClientRect().bottom + 6)+'px'; dropdown.style.left='auto'; dropdown.style.right='12px'; } else { dropdown.style.position='absolute'; dropdown.style.top=(bellBtn.offsetTop+bellBtn.offsetHeight+6)+'px'; dropdown.style.left=(bellBtn.offsetLeft-(dropdown.offsetWidth-bellBtn.offsetWidth))+'px'; } if (bellDot) bellDot.classList.add('d-none'); try{ const nowTs = latestTs || Date.now(); localStorage.setItem('admin_notif_last_open', String(nowTs)); }catch(_){ } }); document.addEventListener('click',function(ev){ const t=ev.target; if (t && (t.closest && (t.closest('#adminBellDropdown')||t.closest('#adminBellBtn')||t.closest('#adminBellWrap')))) return; dropdown.classList.remove('show'); }); }
            let toastWrap=document.getElementById('adminToastWrap'); if(!toastWrap){ toastWrap=document.createElement('div'); toastWrap.id='adminToastWrap'; toastWrap.style.position='fixed'; toastWrap.style.right='16px'; toastWrap.style.bottom='16px'; toastWrap.style.zIndex='1030'; document.body.appendChild(toastWrap);} function showToast(msg){ const el=document.createElement('div'); el.className='alert alert-info shadow-sm border-0'; el.style.minWidth='280px'; el.style.maxWidth='360px'; el.innerHTML='<i class="bi bi-bell me-2"></i>'+String(msg||''); toastWrap.appendChild(el); setTimeout(()=>{ try{ el.remove(); }catch(_){ } },5000); }
            function adjustAdminToastOffset(){ try{ var tw=document.getElementById('adminToastWrap'); if(!tw) return; var baseRight=(window.matchMedia&&window.matchMedia('(max-width: 768px)').matches)?14:16; tw.style.right=baseRight+'px'; var bottomPx=16; try{ if(window.matchMedia&&window.matchMedia('(max-width: 768px)').matches){ var nav=document.getElementById('cpBottomNav'); var hidden=nav && nav.classList && nav.classList.contains('hidden'); if(nav && !hidden){ var rect=nav.getBoundingClientRect(); var h=Math.round(Math.max(0, window.innerHeight-rect.top)); if(!h||!isFinite(h)) h=64; bottomPx=h+12; } else { var btn=document.getElementById('bnToggleCP'); if (btn){ var br=btn.getBoundingClientRect(); var bh=Math.round(Math.max(0, window.innerHeight-br.top)); if(!bh||!isFinite(bh)) bh=64; bottomPx=bh+12; } else { bottomPx=16; } } } }catch(_){ bottomPx=64; } tw.style.bottom=String(bottomPx)+'px'; }catch(_){ } }
            try{ window.addEventListener('resize', adjustAdminToastOffset); }catch(_){ }
            try{ window.adjustAdminToastOffset = adjustAdminToastOffset; window.__adm_adjust_toast = adjustAdminToastOffset; adjustAdminToastOffset(); }catch(_){ }
            let audioCtx=null; function playBeep(){ try{ if(!audioCtx) audioCtx=new(window.AudioContext||window.webkitAudioContext)(); const o=audioCtx.createOscillator(), g=audioCtx.createGain(); o.type='sine'; o.frequency.value=880; g.gain.setValueAtTime(0.0001,audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.2,audioCtx.currentTime+0.02); g.gain.exponentialRampToValueAtTime(0.0001,audioCtx.currentTime+0.22); o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime+0.25);}catch(_){} }
            function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
            function fmt12(txt){ try{ const s=String(txt||'').trim(); const m=s.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/); if(!m) return s; const date=m[1]; const H=parseInt(m[2],10); const mm=m[3]; const ap=(H>=12?'pm':'am'); let h=H%12; if(h===0) h=12; return date+' '+h+':'+mm+ap; } catch(_){ return String(txt||''); } }
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
                      +   '<small class="text-muted">'+escapeHtml(fmt12(when))+'</small>'
                        + '</div>'
                        + '<div class="mb-0">'+escapeHtml(String(r.username||''))+' requests '+escapeHtml(String(r.item_name||''))+' <span class="badge bg-secondary">x'+qty+'</span></div>'
                        + '</a>');
                  });
                  if ((recent||[]).length){
                    rows.push('<div class="list-group-item"><div class="d-flex justify-content-between align-items-center"><span class="small text-muted">Processed</span><button type="button" class="btn btn-sm btn-outline-secondary btn-2xs" id="admClearAllBtn">Clear All</button></div></div>');
                    (recent||[]).forEach(r=>{
                      const id=parseInt(r.id||0,10);
                      const nm=String(r.item_name||'');
                      const st=String(r.status||'');
                      const when=String(r.processed_at||'');
                      const bcls = (st==='Approved') ? 'badge bg-success' : 'badge bg-danger';
                      rows.push('<div class="list-group-item d-flex justify-content-between align-items-start">'
                        + '<div class="me-2">'
                        +   '<div class="d-flex w-100 justify-content-between"><strong>#'+id+' '+escapeHtml(nm)+'</strong><small class="text-muted">'+escapeHtml(fmt12(when))+'</small></div>'
                        +   '<div class="small">Status: <span class="'+bcls+'">'+escapeHtml(st)+'</span></div>'
                        + '</div>'
                        + '<div><button type="button" class="btn-close adm-clear-one" aria-label="Clear" data-id="'+id+'"></button></div>'
                        + '</div>');
                    });
                  }
                  if (listEl) listEl.innerHTML=rows.join(''); if (emptyEl) emptyEl.style.display=rows.length?'none':'block';
              }
              document.addEventListener('click', function(ev){
                const one = ev.target && ev.target.closest && ev.target.closest('.adm-clear-one');
                if (one){ ev.preventDefault(); const rid=parseInt(one.getAttribute('data-id')||'0',10)||0; if(!rid) return; const fd=new FormData(); fd.append('request_id', String(rid)); fetch('admin_borrow_center.php?action=admin_notif_clear',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ poll(); }).catch(()=>{}); return; }
                if (ev.target && ev.target.id === 'admClearAllBtn'){ ev.preventDefault(); const fd=new FormData(); fd.append('limit','300'); fetch('admin_borrow_center.php?action=admin_notif_clear_all',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ poll(); }).catch(()=>{}); }
              });
              function poll(){
                  if(fetching) return; fetching=true;
                  fetch('admin_borrow_center.php?action=admin_notifications')
                    .then(r=>r.json())
                    .then(d=>{
                      const pending=(d&&Array.isArray(d.pending))? d.pending: [];
                      const recent=(d&&Array.isArray(d.recent))? d.recent: [];
                      renderCombined(pending, recent);
                      try{
                          const showDot = pending.length > 0;
                          if (bellDot) bellDot.classList.toggle('d-none', !showDot);
                      }catch(_){ if (bellDot) bellDot.classList.toggle('d-none', pending.length===0); }
                    try{
                        const navLink=document.querySelector('a[href="admin_borrow_center.php"]');
                        if(navLink){
                            let dot=navLink.querySelector('.nav-borrow-dot');
                            const shouldShow = pending.length>0;
                            if (shouldShow){
                                if(!dot){
                                    dot=document.createElement('span');
                                    dot.className='nav-borrow-dot ms-2 d-inline-block rounded-circle';
                                    dot.style.width='8px';
                                    dot.style.height='8px';
                                    dot.style.backgroundColor='#dc3545';
                                    dot.style.verticalAlign='middle';
                                    dot.style.display='inline-block';
                                    navLink.appendChild(dot);
                                } else { dot.style.display='inline-block'; }
                            } else if (dot){ dot.style.display='none'; }
                        }
                    }catch(_){ }
                    const curr=new Set(pending.map(it=>parseInt(it.id||0,10)));
                    if(!initialized){ baseline=curr; initialized=true; }
                    else {
                        let hasNew=false;
                        pending.forEach(it=>{ const id=parseInt(it.id||0,10); if(!baseline.has(id)){ hasNew=true; showToast('New request: '+(it.username||'')+' → '+(it.item_name||'')+' (x'+(it.quantity||1)+')'); } });
                        if(hasNew) playBeep();
                        baseline=curr;
                    }
                  })
                  .catch(()=>{})
                  .finally(()=>{ fetching=false; });
            }
            poll(); setInterval(()=>{ if(document.visibilityState==='visible') poll(); }, 1000);
        })();
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
        // Request to Borrow red dot (based on My Borrowed) for non-admin users
        (function(){
            var isAdmin = <?php echo json_encode($usertype === 'admin'); ?>;
            if (isAdmin) return;
            // Clean lingering dots on load
            try {
                const reqLinkInit = document.querySelector('#sidebar-wrapper a[href="user_request.php"]');
                if (reqLinkInit) {
                    reqLinkInit.querySelectorAll('.nav-borrowed-dot, .nav-req-dot').forEach(el=>{ try{ el.remove(); }catch(_){ el.style.display='none'; } });
                }
            } catch(_){ }
            function ensureDot(link){
                if (!link) return null;
                let dot = link.querySelector('.nav-borrowed-dot');
                if (!dot) {
                    dot = document.createElement('span');
                    dot.className = 'nav-borrowed-dot ms-2 d-inline-block rounded-circle';
                    dot.style.width = '8px';
                    dot.style.height = '8px';
                    dot.style.backgroundColor = '#dc3545';
                    dot.style.verticalAlign = 'middle';
                    dot.style.display = 'none';
                    link.appendChild(dot);
                }
                return dot;
            }
            let fetchingBorrow = false;
            function pollBorrow(){
                if (fetchingBorrow) return; fetchingBorrow = true;
                fetch('user_request.php?action=my_borrowed')
                  .then(r=>r.json())
                  .then(d=>{
                      const list = (d && Array.isArray(d.borrowed)) ? d.borrowed : [];
                      const reqLink = document.querySelector('#sidebar-wrapper a[href="user_request.php"]');
                      if (reqLink) {
                          // Remove any legacy req-dot
                          reqLink.querySelectorAll('.nav-req-dot').forEach(el=>{ try{ el.remove(); }catch(_){ el.style.display='none'; } });
                          let dot = reqLink.querySelector('.nav-borrowed-dot');
                          if (list.length > 0) {
                              dot = ensureDot(reqLink);
                              if (dot) dot.style.display = 'inline-block';
                          } else {
                              if (dot) { try{ dot.remove(); } catch(e){ dot.style.display='none'; } }
                          }
                      }
                  })
                  .catch(()=>{})
                  .finally(()=>{ fetchingBorrow = false; });
            }
            pollBorrow();
            setInterval(()=>{ if (document.visibilityState==='visible') pollBorrow(); }, 1000);
        })();
        // User approval/lost-damaged popups (non-admin)
        (function(){
            var isAdmin = <?php echo json_encode($usertype === 'admin'); ?>;
            if (isAdmin) return;
            let toastWrap = document.getElementById('userToastWrap');
            if (!toastWrap) {
                toastWrap = document.createElement('div');
                toastWrap.id = 'userToastWrap';
                toastWrap.style.position = 'fixed';
                toastWrap.style.right = '16px';
                toastWrap.style.bottom = '16px';
                toastWrap.style.zIndex = '1030';
                document.body.appendChild(toastWrap);
            }
            function attachSwipeForToast(el){
                try{
                    let sx=0, sy=0, dx=0, moving=false, removed=false;
                    const onStart=(ev)=>{ try{ const t=ev.touches?ev.touches[0]:ev; sx=t.clientX; sy=t.clientY; dx=0; moving=true; el.style.willChange='transform,opacity'; el.classList.add('toast-slide'); el.style.transition='none'; }catch(_){}};
                    const onMove=(ev)=>{ if(!moving||removed) return; try{ const t=ev.touches?ev.touches[0]:ev; dx=t.clientX - sx; const adx=Math.abs(dx); const od=1 - Math.min(1, adx/140); el.style.transform='translateX('+dx+'px)'; el.style.opacity=String(od); }catch(_){}};
                    const onEnd=()=>{ if(!moving||removed) return; moving=false; try{ el.style.transition='transform 180ms ease, opacity 180ms ease'; const adx=Math.abs(dx); if (adx>80){ removed=true; el.classList.add(dx>0?'toast-remove-right':'toast-remove-left'); setTimeout(()=>{ try{ el.remove(); adjustToastOffsets(); }catch(_){ } }, 200); } else { el.style.transform=''; el.style.opacity=''; } }catch(_){ } };
                    el.addEventListener('touchstart', onStart, {passive:true});
                    el.addEventListener('touchmove', onMove, {passive:true});
                    el.addEventListener('touchend', onEnd, {passive:true});
                }catch(_){ }
            }
            function showToastCustom(msg, cls){
                const el = document.createElement('div');
                el.className = 'alert ' + (cls||'alert-info') + ' shadow-sm border-0 toast-slide toast-enter';
                el.style.minWidth = '300px';
                el.style.maxWidth = '340px';
                try { if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='180px'; el.style.maxWidth='200px'; el.style.fontSize='12px'; } } catch(_){ }
                el.innerHTML = '<i class="bi bi-bell me-2"></i>'+String(msg||'');
                toastWrap.appendChild(el);
                try{ adjustToastOffsets(); }catch(_){ }
                attachSwipeForToast(el);
                setTimeout(()=>{ try{ el.classList.add('toast-fade-out'); setTimeout(()=>{ try{ el.remove(); adjustToastOffsets(); }catch(_){ } }, 220); }catch(_){ } }, 5000);
            }
            function adjustToastOffsets(){
                try{
                    const tw = document.getElementById('userToastWrap'); if (!tw) return;
                    const wrap = document.getElementById('userPersistentWrap');
                    try{ if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ tw.style.right='14px'; } else { tw.style.right='16px'; } }catch(_){ }
                    if (wrap){
                        const cs = window.getComputedStyle(wrap);
                        const base = parseInt(cs.bottom||'16',10)||16;
                        const h = wrap.offsetHeight||0; const gap=8;
                        tw.style.bottom = (base + h + gap) + 'px';
                    } else { tw.style.bottom = '16px'; }
                }catch(_){ }
            }
            try { window.addEventListener('resize', adjustToastOffsets); } catch(_){ }
            let audioCtx = null;
            function ensureAudio(){
                try {
                    if (!audioCtx) audioCtx = new (window.AudioContext||window.webkitAudioContext)();
                    if (audioCtx && audioCtx.state === 'suspended') { audioCtx.resume().catch(()=>{}); }
                } catch(_){ }
            }
            // Unlock audio on first interaction
            ['click','touchstart','keydown'].forEach(ev=>{
                document.addEventListener(ev, function onFirst(){ try{ ensureAudio(); }catch(_){} document.removeEventListener(ev, onFirst); }, { once: true });
            });
            function playBeep(){
                try {
                    ensureAudio();
                    const o = audioCtx.createOscillator();
                    const g = audioCtx.createGain();
                    o.type = 'square'; o.frequency.setValueAtTime(880, audioCtx.currentTime);
                    g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
                    g.gain.exponentialRampToValueAtTime(0.35, audioCtx.currentTime+0.03);
                    g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime+0.6);
                    o.connect(g); g.connect(audioCtx.destination);
                    o.start(); o.stop(audioCtx.currentTime+0.65);
                } catch(_){ }
            }
            let baseAlloc = new Set();
            let baseLogs = new Set();
            let initNotifs = false;
            function ensurePersistentWrap(){
                let wrap = document.getElementById('userPersistentWrap');
                if (!wrap){
                    wrap = document.createElement('div');
                    wrap.id = 'userPersistentWrap';
                    wrap.style.position = 'fixed';
                    wrap.style.right = '16px';
                    wrap.style.bottom = '16px';
                    wrap.style.left = '';
                    wrap.style.zIndex = '1030';
                    wrap.style.display = 'flex';
                    wrap.style.flexDirection = 'column';
                    wrap.style.gap = '8px';
                    wrap.style.pointerEvents = 'none';
                    document.body.appendChild(wrap);
                }
                try { if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ wrap.style.right='8px'; if (!wrap.getAttribute('data-bottom')) { wrap.style.bottom='64px'; } } } catch(_){ }
                return wrap;
            }
            function addOrUpdateOverdueNotices(items){
                const wrap = ensurePersistentWrap();
                const list = Array.isArray(items) ? items : [];
                const count = list.length;
                // Remove item-specific overdue alerts; keep only summary
                try {
                  wrap.querySelectorAll('[id^="ov-alert-"]').forEach(function(node){ if (node.id !== 'ov-alert-summary') { try{ node.remove(); }catch(_){ node.style.display='none'; } } });
                } catch(_){ }
                const key = 'ov-alert-summary';
                let el = document.getElementById(key);
                if (count === 0) { if (el){ try{ el.remove(); }catch(_){ el.style.display='none'; } } return; }
                const html = '<i class="bi bi-exclamation-octagon me-2"></i>' + (count===1 ? 'You have an overdue item, Click to view.' : ('You have overdue items ('+count+'), Click to view.'));
                let ding = false;
                if (!el){
                    el = document.createElement('div');
                    el.id = key;
                    el.className = 'alert alert-danger shadow-sm border-0';
                    el.style.minWidth='300px'; el.style.maxWidth='340px'; el.style.cursor='pointer';
                    el.style.margin='0'; el.style.lineHeight='1.25'; el.style.borderRadius='8px';
                    el.style.pointerEvents='auto';
                    el.addEventListener('click', function(){ window.location.href = 'user_request.php?view=overdue'; });
                    wrap.appendChild(el);
                    ding = true;
                }
                const prev = parseInt(el.getAttribute('data-count')||'-1',10);
                if (prev !== count) { el.setAttribute('data-count', String(count)); el.innerHTML = html; }
                try {
                  if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
                    el.style.minWidth='180px';
                    el.style.maxWidth='200px';
                    el.style.padding='4px 6px';
                    el.style.fontSize='10px';
                    const ic=el.querySelector('i'); if (ic) ic.style.fontSize='12px';
                  } else {
                    el.style.minWidth='300px';
                    el.style.maxWidth='340px';
                    el.style.padding='';
                    el.style.fontSize='';
                  }
                } catch(_){ }
                if (ding) playBeep();
                try { adjustToastOffsets(); } catch(_){ }
            }
            function notifPoll(){
                fetch('user_request.php?action=user_notifications')
                  .then(r=>r.json())
                  .then(d=>{
                    const approvals = Array.isArray(d.approvals)? d.approvals : [];
                    const logs = Array.isArray(d.lostDamaged)? d.lostDamaged : [];
                    const idsA = new Set(approvals.map(a=>parseInt(a.alloc_id||0,10)).filter(n=>n>0));
                    const idsL = new Set(logs.map(l=>parseInt(l.log_id||0,10)).filter(n=>n>0));
                    if (!initNotifs) { baseAlloc = idsA; baseLogs = idsL; initNotifs = true; return; }
                    let ding = false;
                    approvals.forEach(a=>{
                        const id = parseInt(a.alloc_id||0,10);
                        if (!baseAlloc.has(id)) {
                            ding = true;
                            showToastCustom('The ID.'+String(a.model_id||'')+' ('+String(a.model_name||'')+') has been approved', 'alert-success');
                        }
                    });
                    logs.forEach(l=>{
                        const id = parseInt(l.log_id||0,10);
                        if (!baseLogs.has(id)) {
                            ding = true;
                            const act = String(l.action||'');
                            const label = (act==='Under Maintenance') ? 'damaged' : 'lost';
                            showToastCustom('The '+String(l.model_id||'')+' '+String(l.model_name||'')+' was marked as '+label, 'alert-danger');
                        }
                    });
                    if (ding) playBeep();
                    baseAlloc = idsA; baseLogs = idsL;
                  })
                  .catch(()=>{});
            }
            // Run after DOM is ready to ensure container exists
            document.addEventListener('DOMContentLoaded', function(){ notifPoll(); });
            setInterval(()=>{ if (document.visibilityState==='visible') notifPoll(); }, 1000);
        })();
        (function(){
            const current = document.getElementById('current_password');
            const currentMsg = document.getElementById('currentPwMsg');
            const pwd = document.getElementById('new_password');
            const cpwd = document.getElementById('confirm_password');
            const reqMsg = document.getElementById('pwReqMsg');
            const sameMsg = document.getElementById('pwSameMsg');
            const mismatchMsg = document.getElementById('pwMismatch');
            const form = document.querySelector('form');
            const submitBtn = form.querySelector('button[type="submit"]');
            const toggle = document.getElementById('toggle_password_change');
            const capsIcons = Array.prototype.slice.call(document.querySelectorAll('.capslock-indicator'));
            const viewBtn = document.getElementById('view_password_change');

            let currentOk = false;
            let timer = null;

            function passwordValid() {
                const val = pwd.value || '';
                const len = val.length;
                const hasCap = /[A-Z]/.test(val);
                if (len > 0) {
                    reqMsg.style.display = 'block';
                    if (len === 0 || len < 6) { reqMsg.textContent = 'password must be at least 6 character long'; reqMsg.style.color = '#dc3545'; return false; }
                    if (len > 24) { reqMsg.textContent = 'Password must be at most 24 characters'; reqMsg.style.color = '#dc3545'; return false; }
                    if (!hasCap) { reqMsg.textContent = 'Password must contain atleast one Capital letter'; reqMsg.style.color = '#dc3545'; return false; }
                    if (len >= 6 && len <= 9) { reqMsg.textContent = 'Moderate'; reqMsg.style.color = '#fd7e14'; }
                    else { reqMsg.textContent = 'Strong'; reqMsg.style.color = '#198754'; }
                    return true;
                } else {
                    reqMsg.style.display = 'none';
                }
                return false;
            }

            function updateSubmit() {
                const passOk = passwordValid();
                const matched = (cpwd.value.length === 0) ? false : (pwd.value === cpwd.value);
                const sameAsCurrent = (pwd.value.length>0 && current.value.length>0 && pwd.value === current.value);
                sameMsg.style.display = sameAsCurrent ? 'block' : 'none';
                mismatchMsg.style.display = (cpwd.value.length>0 && !matched) ? 'block' : 'none';
                submitBtn.disabled = !(currentOk && passOk && matched) || sameAsCurrent;
            }

            function scheduleCheckCurrent() {
                if (!current.value) { currentOk = false; currentMsg.style.display = 'none'; updateSubmit(); return; }
                if (timer) clearTimeout(timer);
                timer = setTimeout(() => {
                    const formData = new FormData();
                    formData.append('ajax', 'check_current');
                    formData.append('current_password', current.value);
                    fetch('change_password.php', { method: 'POST', body: formData, credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(j => {
                            currentOk = !!(j && j.ok);
                            currentMsg.style.display = currentOk ? 'none' : 'block';
                            updateSubmit();
                        })
                        .catch(() => { currentOk = false; currentMsg.style.display = 'block'; updateSubmit(); });
                }, 300);
            }

            current.addEventListener('input', scheduleCheckCurrent);
            pwd.addEventListener('blur', () => { if (!(pwd.value||'').length) { reqMsg.style.display = 'none'; } });
            pwd.addEventListener('input', updateSubmit);
            cpwd.addEventListener('input', updateSubmit);
            function applyPasswordVisibility(show) {
                const type = show ? 'text' : 'password';
                if (current) current.type = type;
                if (pwd) pwd.type = type;
                if (cpwd) cpwd.type = type;
                if (toggle) toggle.checked = !!show;
                if (viewBtn) {
                    const icon = viewBtn.querySelector('i');
                    if (icon) {
                        icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
                    }
                }
            }
            function setCapsIcon(isOn) {
                if (!capsIcons || !capsIcons.length) return;
                capsIcons.forEach(function(icon){
                    if (!icon) return;
                    if (isOn) {
                        icon.classList.add('active');
                    } else {
                        icon.classList.remove('active');
                    }
                });
            }
            function handleCaps(e) {
                if (!e || typeof e.getModifierState !== 'function') return;
                const isCaps = e.getModifierState('CapsLock');
                setCapsIcon(isCaps);
            }
            if (typeof window.addEventListener === 'function') {
                window.addEventListener('keydown', handleCaps);
                window.addEventListener('keyup', handleCaps);
            }

            if (toggle) {
                toggle.addEventListener('change', function(){
                    applyPasswordVisibility(this.checked);
                });
            }

            if (viewBtn) {
                viewBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    const show = !pwd || pwd.type === 'password';
                    applyPasswordVisibility(show);
                });
            }

            // Initialize
            submitBtn.disabled = true;
        })();
        </script>
        <script>
        // Global admin notifications: user verified returns (toast + beep)
        (function(){
            var isAdmin = <?php echo json_encode($usertype === 'admin'); ?>;
            if (!isAdmin) return;
            document.addEventListener('DOMContentLoaded', function(){
              try {
                var toastWrap = document.getElementById('adminToastWrap');
                if (!toastWrap) {
                  toastWrap = document.createElement('div'); toastWrap.id = 'adminToastWrap';
                  toastWrap.style.position='fixed'; toastWrap.style.right='16px'; toastWrap.style.bottom='16px'; toastWrap.style.zIndex='1030';
                  document.body.appendChild(toastWrap);
                }
                function attachSwipeForToast(el){ try{ var sx=0, sy=0, dx=0, moving=false, removed=false; var onStart=function(ev){ try{ var t=ev.touches?ev.touches[0]:ev; sx=t.clientX; sy=t.clientY; dx=0; moving=true; el.style.willChange='transform,opacity'; el.classList.add('toast-slide'); el.style.transition='none'; }catch(_){}}; var onMove=function(ev){ if(!moving||removed) return; try{ var t=ev.touches?ev.touches[0]:ev; dx=t.clientX - sx; var adx=Math.abs(dx); var od=1 - Math.min(1, adx/140); el.style.transform='translateX('+dx+'px)'; el.style.opacity=String(od); }catch(_){}}; var onEnd=function(){ if(!moving||removed) return; moving=false; try{ el.style.transition='transform 180ms ease, opacity 180ms ease'; var adx=Math.abs(dx); if (adx>80){ removed=true; el.classList.add(dx>0?'toast-remove-right':'toast-remove-left'); setTimeout(function(){ try{ el.remove(); }catch(_){ } }, 200); } else { el.style.transform=''; el.style.opacity=''; } }catch(_){ } }; el.addEventListener('touchstart', onStart, {passive:true}); el.addEventListener('touchmove', onMove, {passive:true}); el.addEventListener('touchend', onEnd, {passive:true}); }catch(_){ } }
                function showToast(msg, cls){ var el=document.createElement('div'); el.className='alert '+(cls||'alert-info')+' shadow-sm border-0 toast-slide'; el.style.minWidth='300px'; el.style.maxWidth='340px'; try{ if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='180px'; el.style.maxWidth='200px'; el.style.fontSize='12px'; } }catch(_){ } el.innerHTML='<i class="bi bi-bell me-2"></i>'+String(msg||''); toastWrap.appendChild(el); attachSwipeForToast(el); setTimeout(function(){ try{ el.classList.add('toast-fade-out'); setTimeout(function(){ try{ el.remove(); }catch(_){ } }, 220); }catch(_){ } }, 5000); }
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
                var retBase = new Set(); var retInit=false; var retFetching=false;
                function pollUserReturns(){ if (retFetching) return; retFetching = true;
                  fetch('admin_borrow_center.php?action=return_feed')
                    .then(function(r){ return r.json(); })
                    .then(function(d){ var list=(d&&d.ok&&Array.isArray(d.returns))?d.returns:[]; var ids=new Set(list.map(function(v){ return parseInt(v.id||0,10); }).filter(function(n){ return n>0; })); if(!retInit){ retBase=ids; retInit=true; return; } var ding=false; list.forEach(function(v){ var id=parseInt(v.id||0,10); if(!retBase.has(id)){ ding=true; var name=String(v.model_name||''); var sn=String(v.qr_serial_no||''); var loc=String(v.location||''); showToast('User returned '+(name?name+' ':'')+(sn?('['+sn+']'):'')+(loc?(' @ '+loc):''), 'alert-success'); } }); if(ding){ try{ playBeep(); }catch(_){ } } retBase=ids; })
                    .catch(function(){})
                    .finally(function(){ retFetching=false; });
                }
                pollUserReturns(); setInterval(function(){ if (document.visibilityState==='visible') pollUserReturns(); }, 2000);
              } catch(_e){}
            });
        })();
        </script>
        <script>
        // User notification bell (non-admin) on Change Password page
        (function(){
            var isAdmin = <?php echo json_encode($usertype === 'admin'); ?>;
            const bellBtn = document.getElementById('userBellBtnCP');
            const bellDot = document.getElementById('userBellDotCP');
            const dropdown = document.getElementById('userBellDropdownCP');
            const listEl = document.getElementById('userNotifListCP');
            const emptyEl = document.getElementById('userNotifEmptyCP');
            const bellModal = document.getElementById('userBellModalCP');
            const bellBackdrop = document.getElementById('userBellBackdropCP');
            const mobileCloseBtn = document.getElementById('ubmCloseBtnCP');
            const mList = document.getElementById('userNotifListMobileCP');
            const mEmpty = document.getElementById('userNotifEmptyMobileCP');
            let latestTs = 0;
            let lastSig = '';
            let currentSig = '';
            // Persistent bottom-right overdue popup (behind modals)
            function ensurePersistentWrap(){
                let wrap = document.getElementById('userPersistentWrap');
                if (!wrap){
                    wrap = document.createElement('div');
                    wrap.id = 'userPersistentWrap';
                    wrap.style.position = 'fixed';
                    wrap.style.right = '16px';
                    wrap.style.bottom = '16px';
                    wrap.style.left = '';
                    wrap.style.zIndex = '1030'; // below Bootstrap modal/backdrop
                    wrap.style.display = 'flex';
                    wrap.style.flexDirection = 'column';
                    wrap.style.gap = '8px';
                    wrap.style.pointerEvents = 'none';
                    document.body.appendChild(wrap);
                }
                try { if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ wrap.style.right='8px'; if (!wrap.getAttribute('data-bottom')) { wrap.style.bottom='64px'; } } } catch(_){ }
                return wrap;
            }
            function addOrUpdateOverdueNotices(items){
                const wrap = ensurePersistentWrap();
                const list = Array.isArray(items) ? items : [];
                const count = list.length;
                const key = 'ov-alert-summary';
                let el = document.getElementById(key);
                if (count === 0) { if (el){ try{ el.remove(); }catch(_){ el.style.display='none'; } } return; }
                const html = '<i class="bi bi-exclamation-octagon me-2"></i>' + (count===1 ? 'You have an overdue item, Click to view.' : ('You have overdue items ('+count+'), Click to view.'));
                let first = false;
                if (!el){
                    el = document.createElement('div');
                    el.id = key;
                    el.className = 'alert alert-danger shadow-sm border-0';
                    el.style.minWidth='300px'; el.style.maxWidth='340px'; el.style.cursor='pointer';
                    el.style.margin='0'; el.style.lineHeight='1.25'; el.style.borderRadius='8px';
                    el.style.pointerEvents='auto';
                    el.addEventListener('click', function(){ window.location.href='user_request.php?view=overdue'; });
                    wrap.appendChild(el);
                    first = true;
                }
                el.innerHTML = html;
                try { if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='160px'; el.style.maxWidth='200px'; el.style.padding='4px 6px'; el.style.fontSize='10px'; const ic=el.querySelector('i'); if (ic) ic.style.fontSize='12px'; } else { el.style.minWidth='300px'; el.style.maxWidth='340px'; el.style.padding=''; el.style.fontSize=''; } } catch(_){ }
            }
            function isMobile(){ try{ return window.matchMedia && window.matchMedia('(max-width: 768px)').matches; }catch(_){ return window.innerWidth<=768; } }
            function copyNotifToMobile(){ try{ if (mList) mList.innerHTML = listEl ? listEl.innerHTML : ''; if (mEmpty) mEmpty.style.display = emptyEl ? emptyEl.style.display : ''; } catch(_){ } }
            function openMobileModal(){ if (!bellModal || !bellBackdrop) return; copyNotifToMobile(); bellModal.style.display='flex'; bellBackdrop.style.display='block'; try{ document.body.style.overflow='hidden'; }catch(_){ } }
            function closeMobileModal(){ if (!bellModal || !bellBackdrop) return; bellModal.style.display='none'; bellBackdrop.style.display='none'; try{ document.body.style.overflow=''; }catch(_){ } }
            if (bellBtn && dropdown) {
                bellBtn.addEventListener('click', function(e){
                    e.stopPropagation();
                    if (isMobile()){
                      e.preventDefault();
                      try{ if (emptyEl) { const has = !!(listEl && listEl.innerHTML && listEl.innerHTML.trim()!==''); if (!has) listEl.innerHTML = '<div class="text-center text-muted py-2">Loading...</div>'; emptyEl.style.display='none'; } }catch(_){ }
                      try{ poll(true); }catch(_){ }
                      setTimeout(()=>{ try{ copyNotifToMobile(); openMobileModal(); }catch(_){ } }, 50);
                    } else {
                      dropdown.classList.toggle('show');
                      dropdown.style.position = 'absolute';
                      dropdown.style.top = (bellBtn.offsetTop + bellBtn.offsetHeight + 6) + 'px';
                      dropdown.style.left = (bellBtn.offsetLeft - (dropdown.offsetWidth - bellBtn.offsetWidth)) + 'px';
                      dropdown.style.maxWidth = '360px';
                      if (bellDot) bellDot.classList.add('d-none');
                      try { const ts = (latestTs && !isNaN(latestTs)) ? latestTs : 0; localStorage.setItem('ud_notif_last_open', String(ts)); localStorage.setItem('ud_notif_sig_open', currentSig || ''); } catch(_){ }
                      try{ poll(true); }catch(_){ }
                    }
                });
                document.addEventListener('click', function(){ dropdown.classList.remove('show'); closeMobileModal(); });
                if (bellBackdrop) bellBackdrop.addEventListener('click', closeMobileModal);
                if (mobileCloseBtn) mobileCloseBtn.addEventListener('click', closeMobileModal);
            }
            function escapeHtml(s){ return String(s).replace(/[&<>\"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\\\"":"&quot;","'":"&#39;"}[m])); }
            function composeRows(baseList, dn, ovCount, ovSet){
                let latest=0; let sigParts=[]; const combined=[]; const ovset=(ovSet instanceof Set)?ovSet:new Set();
                const clearedKeys = new Set((dn && Array.isArray(dn.cleared_keys)) ? dn.cleared_keys : []);
                let ephemCount = 0;
                try{ }catch(_){ }
                (baseList||[]).filter(function(r){ try{ return !clearedKeys.has('req:'+parseInt(r.id||0,10)); }catch(_){ return true; } }).forEach(function(r){ const id=parseInt(r.id||0,10); const st=String(r.status||''); sigParts.push(id+'|'+st); const when=r.approved_at||r.rejected_at||r.borrowed_at||r.returned_at||r.created_at; const whenTxt=when?String(when):''; let tsn=0; try{ const d=when?new Date(String(when).replace(' ','T')):null; if(d){ const t=d.getTime(); if(!isNaN(t)) tsn=t; } }catch(_){ } if(tsn>latest) latest=tsn; let disp=st, badge='bg-secondary'; const isOv=ovset.has(id); if(st==='Rejected'||st==='Cancelled'){ disp='Rejected'; badge='bg-danger'; } else if(st==='Returned'){ disp='Returned'; badge='bg-success'; } else if(st==='Approved'||st==='Borrowed'){ disp=isOv?'Overdue':'Approved'; badge=isOv?'bg-warning text-dark':'bg-success'; } const key='req:'+id; ephemCount++; const html='<div class="list-group-item d-flex justify-content-between align-items-start">'+'<div class="me-2">'+'<div class="d-flex w-100 justify-content-between">'+'<a href="user_request.php" class="fw-bold text-decoration-none">#'+id+' '+escapeHtml(r.item_name||'')+'</a>'+'<small class="text-muted">'+whenTxt+'</small>'+'</div>'+'<div class="mb-0">Status: <span class="badge '+badge+'">'+escapeHtml(disp||'')+'</span></div>'+'</div>'+'<div><button type="button" class="btn-close u-clear-one" aria-label="Clear" data-key="'+key+'"></button></div>'+'</div>'; combined.push({type:'base', id, ts:tsn, html}); });
                try{ const decisions=(dn&&Array.isArray(dn.decisions))?dn.decisions:[]; decisions.forEach(function(dc){ const rid=parseInt(dc.id||0,10)||0; if(!rid) return; const msg=escapeHtml(String(dc.message||'')); const ts=String(dc.ts||''); let tsn=0; try{ const d=ts?new Date(String(ts).replace(' ','T')):null; if(d){ const t=d.getTime(); if(!isNaN(t)) tsn=t; } }catch(_){ } if(tsn>latest) latest=tsn; const whenHtml=ts?('<small class="text-muted">'+escapeHtml(ts)+'</small>'):''; const key='decision:'+rid+'|'+escapeHtml(String(dc.status||'')); try{ if (clearedKeys.has(key)) return; }catch(_){ } ephemCount++; const html='<div class="list-group-item d-flex justify-content-between align-items-start">'+'<div class="me-2">'+'<div class="d-flex w-100 justify-content-between">'+'<strong>#'+rid+' Decision</strong>'+whenHtml+'</div>'+'<div class="mb-0">'+msg+'</div>'+'</div>'+'<div><button type="button" class="btn-close u-clear-one" aria-label="Clear" data-key="'+key+'"></button></div>'+'</div>'; combined.push({type:'extra', id:rid, ts:tsn, html}); }); }catch(_){ }
                combined.sort(function(a,b){ if((a.type==='overdue')!==(b.type==='overdue')) return (a.type==='overdue')?-1:1; if(b.ts!==a.ts) return b.ts-a.ts; return (b.id||0)-(a.id||0); });
                let rows = combined.map(x=>x.html);
                try{ if (ephemCount>0){ const header='<div class="list-group-item"><div class="d-flex justify-content-between align-items-center"><span class="small text-muted">Notifications</span><button type="button" class="btn btn-sm btn-outline-secondary btn-2xs" id="uClearAllBtn">Clear All</button></div></div>'; rows.splice(0,0,header); } }catch(_){ }
                return { rows, latest, sig: sigParts.join(',') };
            }
            let fetching=false; let lastHtml='';
            function poll(force){
                if (fetching && !force) return; fetching=true;
                Promise.all([
                    fetch('user_request.php?action=my_requests_status', { cache:'no-store' }).then(r=>r.json()).catch(()=>({})),
                    fetch('user_request.php?action=user_notifications', { cache:'no-store' }).then(r=>r.json()).catch(()=>({}))
                ])
                .then(([d,dn])=>{
                    const raw=(d&&Array.isArray(d.requests))?d.requests:[];
                    const base=raw.filter(r=>['Approved','Rejected','Borrowed','Returned'].includes(String(r.status||'')));
                    const built=composeRows(base, dn, 0, new Set());
                    try{
                        const lastOpen=parseInt(localStorage.getItem('ud_notif_last_open')||'0',10)||0;
                        currentSig=built.sig; lastSig=localStorage.getItem('ud_notif_sig_open')||'';
                        const changed=!!(built.sig && built.sig!==lastSig);
                        const any=built.rows.length>0;
                        if (bellDot) bellDot.classList.toggle('d-none', !(any && (changed || (built.latest>0 && built.latest>lastOpen))));
                    }catch(_){ }
                    const html=built.rows.join('');
                    if (listEl && html!==lastHtml){ listEl.innerHTML=html; lastHtml=html; }
                    if (emptyEl) emptyEl.style.display=(html && html.trim()!=='')?'none':'block';
                    try { if (bellModal && bellModal.style && bellModal.style.display === 'flex') { copyNotifToMobile(); } } catch(_){ }
                })
                .catch(()=>{})
                .finally(()=>{ fetching=false; });
            }
            poll(false);
            setInterval(()=>{ if (document.visibilityState==='visible') poll(false); }, 1000);
        })();
        </script>
        <script>
            document.addEventListener('DOMContentLoaded', function(){
                const clearAllBtn = document.getElementById('uClearAllBtn');
                if (clearAllBtn) {
                    clearAllBtn.addEventListener('click', function(){
                        const formData = new FormData();
                        formData.append('ajax', 'clear_all');
                        fetch('user_request.php?action=user_notif_clear', { method: 'POST', body: formData, credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(j => {
                                if (j && j.ok) {
                                    const clearedKeys = new Set(j.cleared_keys || []);
                                    const listItems = listEl ? listEl.children : [];
                                    for (let i = 0; i < listItems.length; i++) {
                                        const item = listItems[i];
                                        const key = item.querySelector('.u-clear-one') ? item.querySelector('.u-clear-one').getAttribute('data-key') : '';
                                        if (clearedKeys.has(key)) {
                                            try { item.remove(); } catch (_) { }
                                        }
                                    }
                                    poll(true);
                                }
                            })
                            .catch(() => {});
                    });
        (function(){
            var isAdmin = <?php echo json_encode($usertype === 'admin'); ?>;
            if (isAdmin) return;
            return;
            let audioCtx = null; function playBeep(){ try{ if(!audioCtx) audioCtx=new(window.AudioContext||window.webkitAudioContext)(); const o=audioCtx.createOscillator(), g=audioCtx.createGain(); o.type='sine'; o.frequency.value=660; g.gain.setValueAtTime(0.0001,audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.15,audioCtx.currentTime+0.02); g.gain.exponentialRampToValueAtTime(0.0001,audioCtx.currentTime+0.22); o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime+0.24);}catch(_){} }
            function ensureWrap(){
                let w=document.getElementById('userPersistentWrap');
                if(!w){
                    w=document.createElement('div');
                    w.id='userPersistentWrap';
                    w.style.position='fixed';
                    w.style.right='16px';
                    w.style.bottom='16px';
                    w.style.zIndex='1090';
                    w.style.display='flex';
                    w.style.flexDirection='column';
                    w.style.gap='8px';
                    w.style.pointerEvents='none';
                    document.body.appendChild(w);
                w.style.gap='8px';
                w.style.pointerEvents='none';
                document.body.appendChild(w);
              }
              try{
                if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){
                  w.style.right='8px';
                  if (!w.getAttribute('data-bottom')) {
                    try {
                      var nav = document.getElementById('cpBottomNavU');
                      var isOpen = nav ? !nav.classList.contains('hidden') : false;
                      var val = isOpen ? '140px' : '64px';
                      w.style.bottom = val;
                      w.setAttribute('data-bottom', val);
                    } catch(_){ w.style.bottom='64px'; }
                  }
                }
              }catch(_){ }
              return w;
            }
            function addOrUpdateReturnshipNotice(rs){
              const wrap=ensureWrap(); const id=parseInt(rs.id||0,10); if(!id) return;
              const elId='rs-alert-'+id; let el=document.getElementById(elId);
              const name=String(rs.model_name||''); const sn=String(rs.qr_serial_no||'');
              const html='<i class="bi bi-exclamation-octagon me-2"></i>'+'Admin requested you to return '+(name?name+' ':'')+(sn?('['+sn+']'):'')+'. Click to open.';
              if(!el){
                el=document.createElement('div'); el.id=elId;
                el.className='alert alert-danger shadow-sm border-0';
                el.style.minWidth='300px'; el.style.maxWidth='340px'; el.style.cursor='pointer';
                el.style.margin='0'; el.style.lineHeight='1.25'; el.style.borderRadius='8px';
                el.style.pointerEvents='auto';
                el.innerHTML=html;
                try{ if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='180px'; el.style.maxWidth='200px'; el.style.padding='4px 6px'; el.style.fontSize='10px'; const ic=el.querySelector('i'); if (ic) ic.style.fontSize='12px'; } }catch(_){ }
                el.addEventListener('click', function(){ window.location.href='user_request.php'; });
                wrap.appendChild(el);
                try{ playBeep(); }catch(_){ }
              } else {
                el.innerHTML=html;
                try{ if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches){ el.style.minWidth='180px'; el.style.maxWidth='200px'; el.style.padding='4px 6px'; el.style.fontSize='10px'; const ic=el.querySelector('i'); if (ic) ic.style.fontSize='12px'; } else { el.style.minWidth='300px'; el.style.maxWidth='340px'; el.style.padding=''; el.style.fontSize=''; } }catch(_){ }
              }
            }
            function removeReturnshipNotice(id){ const el=document.getElementById('rs-alert-'+id); if(el){ try{ el.remove(); }catch(_){ el.style.display='none'; } } }
            let baseReturnships = new Set(); let init=false; let fetching=false;
            function poll(){ if(fetching) return; fetching=true; fetch('user_request.php?action=user_notifications').then(r=>r.json()).then(d=>{ const returnships=Array.isArray(d.returnships)?d.returnships:[]; const ids=new Set(returnships.map(r=>parseInt(r.id||0,10)).filter(n=>n>0)); if(!init){ baseReturnships=ids; init=true; return; } const pendingSet=new Set(); returnships.forEach(rs=>{ const id=parseInt(rs.id||0,10); if(!id) return; const st=String(rs.status||''); if(st==='Pending'){ pendingSet.add(id); addOrUpdateReturnshipNotice(rs); } }); baseReturnships.forEach(oldId=>{ if(!pendingSet.has(oldId)) removeReturnshipNotice(oldId); }); }).catch(()=>{}).finally(()=>{ fetching=false; }); }
            poll();
            setInterval(()=>{ if(document.visibilityState==='visible') poll(); }, 2000);
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
        <?php if ($usertype === 'admin'): ?>
        <button type="button" class="btn btn-primary bottom-nav-toggle d-md-none" id="bnToggleCP" aria-controls="cpBottomNav" aria-expanded="false" title="Open menu">
          <i class="bi bi-list"></i>
        </button>
        <nav class="bottom-nav d-md-none hidden" id="cpBottomNav">
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
            var btn = document.getElementById('bnToggleCP');
            var nav = document.getElementById('cpBottomNav');
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
        <?php endif; ?>
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
        <script src="page-transitions.js?v=<?php echo filemtime(__DIR__.'/page-transitions.js'); ?>"></script>
      </body>
      </html>
