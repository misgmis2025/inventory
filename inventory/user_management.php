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

$UM_MONGO_FILLED = false;
$users = [];
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $usersCol = $db->selectCollection('users');

    // Handle POST actions in Mongo
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $username = trim($_POST['username'] ?? '');
        if ($username !== '' && $username !== $_SESSION['username']) {
            if ($action === 'set_user_type') {
                $adminPass = $_POST['admin_password'] ?? '';
                if ($adminPass === '') { header('Location: user_management.php?error=auth'); exit(); }
                // Verify admin password
                $me = $usersCol->findOne(['username'=>$_SESSION['username']], ['projection'=>['password_hash'=>1]]);
                $authOk = ($me && isset($me['password_hash']) && password_verify($adminPass, (string)$me['password_hash']));
                if (!$authOk) { header('Location: user_management.php?error=auth'); exit(); }
                $newType = trim($_POST['user_type'] ?? '');
                if ($newType === 'Admin') {
                    // Promote to admin (do not change user_type)
                    $usersCol->updateOne(['username'=>$username], ['$set'=>['usertype'=>'admin', 'updated_at'=>date('Y-m-d H:i:s')]]);
                } else {
                    if (!in_array($newType, ['Student','Staff','Faculty'], true)) { header('Location: user_management.php?error=bad_type'); exit(); }
                    // If target is currently admin, prevent demoting last admin
                    $target = $usersCol->findOne(['username'=>$username], ['projection'=>['usertype'=>1]]);
                    if (($target['usertype'] ?? '') === 'admin') {
                        $countAdmins = (int)$usersCol->countDocuments(['usertype'=>'admin']);
                        if ($countAdmins <= 1) { header('Location: user_management.php?error=last_admin'); exit(); }
                    }
                    // Demote to user and set their user_type
                    $usersCol->updateOne(
                        ['username'=>$username],
                        ['$set'=>['usertype'=>'user','user_type'=>$newType, 'updated_at'=>date('Y-m-d H:i:s')]]
                    );
                }
                header('Location: user_management.php?updated=1'); exit();
            } elseif ($action === 'delete_user') {
                // Disallow deleting any admin; require demotion first
                $target = $usersCol->findOne(['username'=>$username], ['projection'=>['usertype'=>1]]);
                if (($target['usertype'] ?? '') === 'admin') {
                    header('Location: user_management.php?error=delete_admin_forbidden'); exit();
                }
                $usersCol->deleteOne(['username'=>$username]);
                header('Location: user_management.php?deleted=1'); exit();
            } elseif ($action === 'reset_password') {
                $adminPass = $_POST['admin_password'] ?? '';
                $schoolId = trim((string)($_POST['school_id'] ?? ''));
                $newPw = (string)($_POST['new_password'] ?? '');
                $confPw = (string)($_POST['confirm_password'] ?? '');
                if ($adminPass === '' || $schoolId === '' || $newPw === '' || $confPw === '') { header('Location: user_management.php?error=missing'); exit(); }
                // Verify admin password
                $me = $usersCol->findOne(['username'=>$_SESSION['username']], ['projection'=>['password_hash'=>1]]);
                $authOk = ($me && isset($me['password_hash']) && password_verify($adminPass, (string)$me['password_hash']));
                if (!$authOk) { header('Location: user_management.php?error=auth'); exit(); }
                // Fetch target and verify school_id matches
                $target = $usersCol->findOne(['username'=>$username], ['projection'=>['_id'=>1,'school_id'=>1]]);
                if (!$target || (string)($target['school_id'] ?? '') !== $schoolId) { header('Location: user_management.php?error=school_id_mismatch'); exit(); }
                // Validate password policy: 6-24 and at least one capital letter
                if (strlen($newPw) < 6 || strlen($newPw) > 24 || !preg_match('/[A-Z]/', $newPw) || $newPw !== $confPw) {
                    header('Location: user_management.php?error=pw_invalid'); exit();
                }
                $hash = password_hash($newPw, PASSWORD_DEFAULT);
                $usersCol->updateOne(['_id'=>$target['_id']], ['$set'=>['password_hash'=>$hash, 'updated_at'=>date('Y-m-d H:i:s')]]);
                header('Location: user_management.php?reset=1'); exit();
            }
        }
    }

    // List users
    $cur = $usersCol->find([], ['sort'=>['username'=>1], 'projection'=>['username'=>1,'usertype'=>1,'full_name'=>1,'user_type'=>1,'school_id'=>1]]);
    foreach ($cur as $u) {
        $users[] = [
            'username' => (string)($u['username'] ?? ''),
            'usertype' => (string)($u['usertype'] ?? ''),
            'full_name' => (string)($u['full_name'] ?? ''),
            'user_type' => (string)($u['user_type'] ?? ''),
            'school_id' => isset($u['school_id']) ? (string)$u['school_id'] : '',
        ];
    }
    $UM_MONGO_FILLED = true;
} catch (Throwable $e) {
    $UM_MONGO_FILLED = false;
}

// Removed MySQL fallback: user management is now MongoDB-only. If Mongo fails, the page will show an empty list and an error alert.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
      html, body { height: 100%; }
      body { overflow: hidden; }
      #sidebar-wrapper { position: sticky; top: 0; height: 100vh; overflow: hidden; }
      #page-content-wrapper { flex: 1 1 auto; height: 100vh; overflow: auto; }
      @media (max-width: 768px) {
        body { overflow: auto; }
        #page-content-wrapper { height: auto; overflow: visible; }
      }
      /* Smaller action buttons in user table */
      .user-actions .btn.btn-sm { padding: 0.1rem 0.3rem; font-size: 0.72rem; line-height: 1; min-height: 1.5rem; }
      .user-actions { gap: 0.2rem !important; }
      .user-actions .btn .bi { font-size: 0.85em; margin-right: 0.25rem !important; }
    </style>
</head>
<body>
    <button class="mobile-menu-toggle d-md-none" onclick="toggleSidebar()">
        <i class="bi bi-list"></i>
    </button>

    <div class="d-flex">
        <div class="bg-light border-end" id="sidebar-wrapper">
            <div class="sidebar-heading py-4 fs-4 fw-bold border-bottom d-flex align-items-center justify-content-center">
                <img src="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png'); ?>" alt="ECA Logo" class="brand-logo me-2" />
                <span>ECA MIS-GMIS</span>
            </div>

    
            <div class="list-group list-group-flush my-3">
                <a href="admin_dashboard.php" class="list-group-item list-group-item-action bg-transparent">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
                <a href="inventory.php" class="list-group-item list-group-item-action bg-transparent">
                    <i class="bi bi-box-seam me-2"></i>Inventory
                </a>
                <a href="inventory_print.php<?php echo (!empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''); ?>" class="list-group-item list-group-item-action bg-transparent">
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
                <a href="user_management.php" class="list-group-item list-group-item-action bg-transparent fw-bold">
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

        <div class="p-4" id="page-content-wrapper">
            <div class="page-header d-flex justify-content-between align-items-center">
                <h2 class="page-title mb-0">
                    <i class="bi bi-people me-2"></i>User Management
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
                            <div class="text-center small text-muted py-2" id="adminNotifEmpty">No new requests.</div>
                            <div class="border-top p-2 text-center">
                                <a href="admin_borrow_center.php" class="btn btn-sm btn-outline-primary">Go to Borrow Requests</a>
                            </div>
                        </div>
                    </div>
                    <span class="text-muted">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</span>
                </div>
            </div>

            <?php if (isset($_GET['updated'])): ?>
                <div class="alert alert-success">User updated.</div>
            <?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?>
                <div class="alert alert-success">User deleted.</div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error']==='auth'): ?>
                <div class="alert alert-danger">Authorization failed: incorrect password.</div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error']==='last_admin'): ?>
                <div class="alert alert-warning">Action blocked: cannot remove the last admin.</div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error']==='bad_type'): ?>
                <div class="alert alert-warning">Invalid user type.</div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error']==='delete_admin_forbidden'): ?>
                <div class="alert alert-warning">Cannot delete an admin. Demote to Student/Staff/Faculty first.</div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error']==='school_id_mismatch'): ?>
                <div class="alert alert-warning">School ID verification failed for that user.</div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error']==='pw_invalid'): ?>
                <div class="alert alert-warning">Password must be 6-24 chars, include at least one capital letter, and match confirmation.</div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error']==='missing'): ?>
                <div class="alert alert-warning">Please fill out all required fields.</div>
            <?php endif; ?>
            <?php if (isset($_GET['reset'])): ?>
                <div class="alert alert-success">Password has been reset.</div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><strong>Accounts</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>School ID</th>
                                    <th>User Type</th>
                                    <th style="width:420px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="3" class="text-center text-muted">No users found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $u): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                                            <td><?php echo htmlspecialchars($u['full_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($u['school_id'] ?? ''); ?></td>
                                            <td>
                                                <?php if (($u['usertype'] ?? '') === 'admin'): ?>
                                                    <span class="badge bg-primary">Admin</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info text-dark"><?php echo htmlspecialchars($u['user_type'] ?: ''); ?></span>
                                                <?php endif; ?>
                                                <?php if ($u['username'] === ($_SESSION['username'] ?? '')): ?>
                                                    <span class="text-muted small ms-1">(You)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex user-actions gap-2">
                                                    <?php if ($u['username'] !== ($_SESSION['username'] ?? '')): ?>
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-info text-dark type-edit-btn"
                                                            data-username="<?php echo htmlspecialchars($u['username']); ?>"
                                                            data-currenttype="<?php echo htmlspecialchars($u['user_type'] ?: ''); ?>">
                                                        <i class="bi bi-pencil-square me-1"></i>Edit Type
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if ($u['username'] !== ($_SESSION['username'] ?? '')): ?>
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-warning text-dark pw-reset-btn"
                                                            data-username="<?php echo htmlspecialchars($u['username']); ?>">
                                                        <i class="bi bi-key me-1"></i>Reset Password
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if ($u['username'] !== $_SESSION['username'] && ($u['usertype'] ?? '') !== 'admin'): ?>
                                                    <form method="post" action="user_management.php" class="d-inline" onsubmit="return confirm('Delete user <?php echo htmlspecialchars($u['username']); ?>? This cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete_user" />
                                                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($u['username']); ?>" />
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash me-1"></i>Delete
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // --- Admin Notifications on User Management page ---
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
            function renderList(items){
                const rows=[]; (items||[]).forEach(r=>{ const id=parseInt(r.id||0,10); const when=r.created_at? new Date(r.created_at).toLocaleString():''; const qty=parseInt(r.quantity||1,10); rows.push('<a href="admin_borrow_center.php" class="list-group-item list-group-item-action">'+'<div class="d-flex w-100 justify-content-between">'+'<strong>#'+id+'</strong>'+'<small class="text-muted">'+when+'</small>'+'</div>'+'<div class="mb-0">'+escapeHtml(String(r.username||''))+' requests '+escapeHtml(String(r.item_name||''))+' <span class="badge bg-secondary">x'+qty+'</span></div>'+'</a>'); });
                listEl.innerHTML=rows.join(''); emptyEl.style.display=(items&&items.length)?'none':'block';
            }
            function poll(){ if(fetching) return; fetching=true; fetch('admin_borrow_center.php?action=pending_json').then(r=>r.json()).then(d=>{ const items=(d&&Array.isArray(d.pending))? d.pending:[]; if (bellDot) bellDot.classList.toggle('d-none', items.length===0); try{ const navLink=document.querySelector('a[href="admin_borrow_center.php"]'); if(navLink){ let dot=navLink.querySelector('.nav-borrow-dot'); const shouldShow = items.length>0; if (shouldShow){ if(!dot){ dot=document.createElement('span'); dot.className='nav-borrow-dot ms-2 d-inline-block rounded-circle'; dot.style.width='8px'; dot.style.height='8px'; dot.style.backgroundColor='#dc3545'; dot.style.verticalAlign='middle'; dot.style.display='inline-block'; navLink.appendChild(dot);} else { dot.style.display='inline-block'; } } else if (dot){ dot.style.display='none'; } } }catch(_){} renderList(items); const curr=new Set(items.map(it=>parseInt(it.id||0,10))); if(!initialized){ baseline=curr; initialized=true; } else { let hasNew=false; items.forEach(it=>{ const id=parseInt(it.id||0,10); if(!baseline.has(id)){ hasNew=true; showToast('New request: '+(it.username||'')+' → '+(it.item_name||'')+' (x'+(it.quantity||1)+')'); } }); if(hasNew) playBeep(); baseline=curr; } }).catch(()=>{}).finally(()=>{ fetching=false; }); }
            poll(); setInterval(()=>{ if(document.visibilityState==='visible') poll(); }, 2000);
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

        // Role toggle modal wiring (guarded) and Edit Type wiring
        document.addEventListener('DOMContentLoaded', function() {
            // Optional: role modal may not exist anymore
            const modalEl = document.getElementById('roleConfirmModal');
            if (modalEl) {
              const bsModal = new bootstrap.Modal(modalEl);
              const form = document.getElementById('roleConfirmForm');
              const userField = form.querySelector('input[name="username"]');
              const roleField = form.querySelector('input[name="usertype"]');
              const targetUserSpan = document.getElementById('targetUser');
              const targetRoleSpan = document.getElementById('targetRole');
              document.querySelectorAll('.role-toggle-btn').forEach(btn => {
                  btn.addEventListener('click', function() {
                      const uname = this.getAttribute('data-username') || '';
                      const newrole = this.getAttribute('data-newrole') || '';
                      userField.value = uname;
                      roleField.value = newrole;
                      if (targetUserSpan) targetUserSpan.textContent = uname;
                      if (targetRoleSpan) targetRoleSpan.textContent = newrole.charAt(0).toUpperCase() + newrole.slice(1);
                      form.reset();
                      userField.value = uname; // restore after reset
                      roleField.value = newrole;
                      bsModal.show();
                  });
              });
            }

            // Wire Edit Type buttons (always active)
            const typeModalEl = document.getElementById('typeConfirmModal');
            const typeForm = document.getElementById('typeConfirmForm');
            if (typeModalEl && typeForm) {
              typeForm.querySelector('input[name="username"]').value = '';
              const typeSelect = typeForm.querySelector('select[name="user_type"]');
              const typeBsModal = new bootstrap.Modal(typeModalEl);
              document.querySelectorAll('.type-edit-btn').forEach(btn => {
                btn.addEventListener('click', function(){
                  const uname = this.getAttribute('data-username') || '';
                  const curr = this.getAttribute('data-currenttype') || '';
                  typeForm.reset();
                  typeForm.querySelector('input[name="username"]').value = uname;
                  if (typeSelect) typeSelect.value = curr || '';
                  typeBsModal.show();
                });
              });
            }

            // Wire Reset Password buttons
            const pwModalEl = document.getElementById('pwResetModal');
            const pwForm = document.getElementById('pwResetForm');
            if (pwModalEl && pwForm) {
              pwForm.querySelector('input[name="username"]').value = '';
              const pwBsModal = new bootstrap.Modal(pwModalEl);
              document.querySelectorAll('.pw-reset-btn').forEach(btn => {
                btn.addEventListener('click', function(){
                  const uname = this.getAttribute('data-username') || '';
                  pwForm.reset();
                  pwForm.querySelector('input[name="username"]').value = uname;
                  pwBsModal.show();
                });
              });
            }
        });
    </script>

    <div class="modal fade" id="pwResetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" action="user_management.php" class="modal-content" id="pwResetForm">
                <input type="hidden" name="action" value="reset_password" />
                <input type="hidden" name="username" value="" />
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">User's School ID</label>
                        <input type="text" name="school_id" class="form-control" placeholder="Enter user's school ID" required />
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" placeholder="New password" required />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Confirm password" required />
                        </div>
                    </div>
                    <small class="text-muted">6-24 characters and at least one capital letter.</small>
                    <div class="mt-2">
                        <label class="form-label">Your Password</label>
                        <input type="password" name="admin_password" class="form-control" placeholder="Your password" required />
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Reset</button>
                </div>
            </form>
        </div>
    </div>

    <!-- User Type Change Modal (placed at root for proper backdrop/focus) -->
    <div class="modal fade" id="typeConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" action="user_management.php" class="modal-content" id="typeConfirmForm">
                <input type="hidden" name="action" value="set_user_type" />
                <input type="hidden" name="username" value="" />
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-badge me-2"></i>Edit User Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">User Type</label>
                        <select name="user_type" class="form-select" required>
                            <option value="">Select type</option>
                            <option value="Admin">Admin</option>
                            <option value="Student">Student</option>
                            <option value="Staff">Staff</option>
                            <option value="Faculty">Faculty</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Enter your password to confirm</label>
                        <input type="password" name="admin_password" class="form-control" placeholder="Your password" required />
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
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
        pollVerif(); setInterval(function(){ if (document.visibilityState==='visible') pollVerif(); }, 2000);
      } catch(_e){}
    });
  })();
</script>
