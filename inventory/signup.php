<?php
// Early Mongo signup handler
$mongoFailed = false;
// Lightweight availability API for client-side checks
if (($_GET['action'] ?? '') === 'check_availability') {
    header('Content-Type: application/json');
    $type = trim((string)($_GET['type'] ?? ''));
    $val = trim((string)($_GET['value'] ?? ''));
    $user_type = trim((string)($_GET['user_type'] ?? ''));
    $taken = false;
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/db/mongo.php';
        $db = get_mongo_db();
        $users = $db->selectCollection('users');
        if ($type === 'username') {
            $taken = (bool)$users->findOne(['username' => $val], [
                'projection' => ['_id' => 1],
                'collation' => ['locale' => 'en', 'strength' => 2]
            ]);
        } elseif ($type === 'full_name') {
            // Case-insensitive match on full_name
            $taken = (bool)$users->findOne(['full_name' => $val], [
                'projection' => ['_id' => 1],
                'collation' => ['locale' => 'en', 'strength' => 2]
            ]);
        } elseif ($type === 'school_id') {
            // Global school_id uniqueness across all user types
            $taken = (bool)$users->findOne(['school_id' => $val], ['projection' => ['_id' => 1]]);
        }
    } catch (Throwable $e) { $taken = false; }
    echo json_encode(['taken' => $taken]);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/db/mongo.php';
    $school_id_raw = trim((string)($_POST['school_id'] ?? ''));
    $user_type = trim((string)($_POST['user_type'] ?? ''));
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password_raw = (string)($_POST['password'] ?? '');
    $confirm_password_raw = (string)($_POST['confirm_password'] ?? '');

    if ($full_name === '') {
        $error = "Full name is required!";
    } elseif ($school_id_raw === '' || !preg_match('/^[\d-]+$/', $school_id_raw)) {
        $error = "ID can only contain digits and hyphens.";
    } elseif (!in_array($user_type, ['Student','Staff','Faculty'], true)) {
        $error = "Please select a valid user type.";
    } elseif (strlen($password_raw) < 6 || strlen($password_raw) > 24 || !preg_match('/[A-Z]/', $password_raw) || !preg_match('/\d/', $password_raw)) {
        $error = "Password must be 6-24 chars and contain at least one capital letter and one number.";
    } elseif ($password_raw !== $confirm_password_raw) {
        $error = "Passwords do not match!";
    } else {
        try {
            $db = get_mongo_db();
            $users = $db->selectCollection('users');
            // Case-insensitive uniqueness check on username
            $exists = $users->findOne(['username' => $username], [
                'projection' => ['_id' => 1],
                'collation' => ['locale' => 'en', 'strength' => 2]
            ]);
            if ($exists) {
                $error = "Username already taken!";
            } elseif ($users->findOne(['full_name' => $full_name], [ 'projection'=>['_id'=>1], 'collation'=>['locale'=>'en','strength'=>2] ])) {
                $error = "Full name already taken!";
            } elseif ($users->findOne(['school_id' => $school_id_raw], ['projection'=>['_id'=>1]])) {
                $error = "ID already registered.";
            } else {
                $hasAdmin = $users->countDocuments(['usertype' => 'admin']) > 0;
                $usertype = (!$hasAdmin && $user_type === 'Staff') ? 'admin' : 'user';
                $password_hash = password_hash($password_raw, PASSWORD_DEFAULT);
                // Generate numeric id for consistency with ETL
                $last = $users->findOne([], ['sort' => ['id' => -1], 'projection' => ['id' => 1]]);
                $nextId = ($last && isset($last['id']) ? (int)$last['id'] : 0) + 1;
                $users->insertOne([
                    'id' => $nextId,
                    'school_id' => $school_id_raw,
                    'user_type' => $user_type, // Student | Staff | Faculty
                    'full_name' => $full_name,
                    'username' => $username,
                    'password_hash' => $password_hash,
                    'usertype' => $usertype,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                header("Location: index.php");
                exit();
            }
        } catch (Throwable $e) {
            // Surface a friendly error and log the exception for diagnostics
            $mongoFailed = true;
            $error = "Sign up failed due to a server error. Please try again later.";
            try { error_log('[signup] Mongo error: ' . $e->getMessage()); } catch (Throwable $_) {}
        }
    }
}

// Removed MySQL fallback: signup is now MongoDB-only
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sign Up</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link href="css/bootstrap/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"> 
    <link rel="stylesheet" href="css/style.css" />
    <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" />
</head>
<body class="bg-light allow-mobile">
    <style>
      .signup-wrapper {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem 1rem;
        background-color: #f8f9fa;
      }
      .signup-card {
        width: 100%;
        max-width: 340px;
        background: #ffffff;
        border-radius: 1rem;
        padding: 2rem 1.75rem;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
        border: 1px solid #e5e7eb;
      }
      .signup-title {
        font-size: 2rem;
        font-weight: 700;
        color: #111827;
        margin-bottom: 0.25rem;
        text-align: center;
      }
      .signup-subtitle {
        text-align: center;
        color: #6b7280;
        margin-bottom: 1.75rem;
      }
      .signup-switch {
        text-align: center;
        color: #6b7280;
        margin-top: 1.5rem;
      }
      .signup-switch a {
        color: #2563eb;
        font-weight: 600;
        text-decoration: none;
      }
      .signup-switch a:hover { text-decoration: underline; }
      .capslock-indicator {
        position: absolute;
        right: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #0d6efd;
        font-size: 1rem;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease-in-out;
      }
      .capslock-indicator.active {
        opacity: 1;
      }
      .has-capslock-icon .form-control {
        padding-right: 2rem;
      }
      @media (max-width: 576px) {
        .signup-card { padding: 2rem 1.5rem; }
        .signup-title { font-size: 1.6rem; }
      }
    </style>

    <div class="signup-wrapper">
      <div class="signup-card">
        <div class="auth-logos d-flex align-items-center justify-content-between" style="margin-bottom:.5rem;">
          <img src="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" alt="Logo" style="height:46px; object-fit:contain;" />
          <img src="images/ECA.png?v=<?php echo filemtime(__DIR__.'/images/ECA.png'); ?>" alt="ECA" style="height:40px; object-fit:contain;" />
        </div>
        <h1 class="signup-title">Create Account</h1>
        <p class="signup-subtitle">Enter your information to get started</p>

        <?php if (!empty($error)): ?>
        <p class="text-center" style="color:#dc3545; margin-bottom: 1rem;"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <form method="POST" action="" class="mt-3 auth-form">
                        <label class="form-label" for="school_id">ID</label>
                        <input id="school_id" class="form-control" type="text" name="school_id" placeholder="Enter your school ID" inputmode="numeric" pattern="[0-9-]+" required />
                        <small id="idTakenMsg" class="text-danger" style="display:none;">ID already taken for this user type</small>
                        <label class="form-label mt-2" for="user_type">User Type</label>
                        <select id="user_type" name="user_type" class="form-select" required>
                            <option value="">Select type</option>
                            <option value="Student">Student</option>
                            <option value="Staff">Staff</option>
                            <option value="Faculty">Faculty</option>
                        </select>
                        <label class="form-label" for="full_name">Full Name</label>
                        <input id="full_name" class="form-control" type="text" name="full_name" placeholder="Enter your full name" required />
                        <small id="fullTakenMsg" class="text-danger" style="display:none;">Full name already taken</small>
                        <label class="form-label" for="username">Username</label>
                        <input id="username" class="form-control" type="text" name="username" placeholder="Choose a username" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required />
                        <small id="userTakenMsg" class="text-danger" style="display:none;">Username already taken</small>
                        <label class="form-label mt-2" for="password">Password</label>
                        <div class="position-relative has-capslock-icon">
                          <input id="password" class="form-control" type="password" name="password" placeholder="Create a password" required />
                          <span id="capslock_icon_signup" class="capslock-indicator" title="Caps Lock is ON" aria-hidden="true">
                            <i class="bi bi-capslock-fill"></i>
                          </span>
                        </div>
                        <small id="pwReqMsg" style="display:none; margin-top:.25rem; color:#dc3545;">password must be at least 6 character long</small>
                        <label class="form-label mt-2" for="confirm_password">Confirm Password</label>
                        <div class="position-relative has-capslock-icon">
                          <input id="confirm_password" class="form-control" type="password" name="confirm_password" placeholder="Re-enter your password" required />
                          <span id="capslock_icon_signup_confirm" class="capslock-indicator" title="Caps Lock is ON" aria-hidden="true">
                            <i class="bi bi-capslock-fill"></i>
                          </span>
                        </div>
                        <small id="pwMismatch" style="color:#dc3545; display:none; margin-top: .25rem;">Passwords don't match</small>
                        <div class="mt-2">
                          <label style="display:inline-flex; align-items:center; gap:.5rem; cursor:pointer;">
                            <input type="checkbox" id="toggle_password" />
                            <span>Show password</span>
                          </label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg mt-3 w-100">Sign up</button>
                    </form>
                    <p class="signup-switch mt-3">Already have an account? <a href="index.php">Login</a></p>
      </div>
    </div>
    <script>
      (function() {
        const pwd = document.getElementById('password');
        const cpwd = document.getElementById('confirm_password');
        const msg = document.getElementById('pwMismatch');
        const reqMsg = document.getElementById('pwReqMsg');
        const toggle = document.getElementById('toggle_password');
        const form = document.querySelector('.auth-form');
        const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
        const schoolId = document.getElementById('school_id');
        const userType = document.getElementById('user_type');
        const fullName = document.getElementById('full_name');
        const username = document.getElementById('username');
        const idTakenMsg = document.getElementById('idTakenMsg');
        const fullTakenMsg = document.getElementById('fullTakenMsg');
        const userTakenMsg = document.getElementById('userTakenMsg');
        const capsIcons = Array.prototype.slice.call(document.querySelectorAll('.capslock-indicator'));

        function passwordValid() {
          const val = pwd.value || '';
          const len = val.length;
          const hasCap = /[A-Z]/.test(val);
          const hasDigit = /\d/.test(val);

          // Show helper when focused or typing
          if (document.activeElement === pwd || len > 0) {
            reqMsg.style.display = 'block';
            // Enforce bounds and capital rule
            if (len === 0 || len < 6) {
              reqMsg.textContent = 'password must be at least 6 character long';
              reqMsg.style.color = '#dc3545';
              return false;
            }
            if (len > 24) {
              reqMsg.textContent = 'Password must be at most 24 characters';
              reqMsg.style.color = '#dc3545';
              return false;
            }
            if (!hasCap) {
              reqMsg.textContent = 'Password must contain at least one capital letter';
              reqMsg.style.color = '#dc3545';
              return false;
            }
            if (!hasDigit) {
              reqMsg.textContent = 'Password must contain at least one number';
              reqMsg.style.color = '#dc3545';
              return false;
            }
            // Strength indicator
            if (len >= 6 && len <= 9) {
              reqMsg.textContent = 'Moderate';
              reqMsg.style.color = '#fd7e14'; // orange
            } else if (len >= 10 && len <= 24) {
              reqMsg.textContent = 'Strong';
              reqMsg.style.color = '#198754'; // green
            }
            return true;
          } else {
            reqMsg.style.display = 'none';
          }
          return false;
        }

        function validateMatch() {
          const confirmTyped = cpwd.value.length > 0;
          const matched = pwd.value === cpwd.value;
          const passOk = passwordValid();
          const idOk = /^[\d-]+$/.test(schoolId.value || '');
          const typeOk = ['Student','Staff','Faculty'].includes(userType.value);

          // Show message only after user types something in Confirm Password
          if (confirmTyped && !matched) {
            msg.style.display = 'block';
          } else {
            msg.style.display = 'none';
          }

          // Disable submit until password is valid and passwords match (when confirm typed)
          if (submitBtn) submitBtn.disabled = !passOk || (confirmTyped && !matched) || !idOk || !typeOk;
        }

        pwd.addEventListener('focus', function(){ reqMsg.style.display = 'block'; validateMatch(); });
        pwd.addEventListener('blur', function(){ if (!(pwd.value||'').length) { reqMsg.style.display = 'none'; } });
        pwd.addEventListener('input', validateMatch);
        cpwd.addEventListener('input', validateMatch);
        if (toggle) {
          toggle.addEventListener('change', function() {
            const type = this.checked ? 'text' : 'password';
            pwd.type = type;
            cpwd.type = type;
          });
        }
        if (schoolId) {
          schoolId.addEventListener('input', function(){ this.value = this.value.replace(/[^\d-]/g,''); validateMatch(); });
        }
        if (userType) { userType.addEventListener('change', validateMatch); }
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

        // Simple debounce helper
        function debounce(fn, ms){ let t; return function(){ const ctx=this, args=arguments; clearTimeout(t); t=setTimeout(()=>fn.apply(ctx,args), ms||250); }; }
        async function checkAvailability(type, value){
          try{
            const params = new URLSearchParams({ action:'check_availability', type, value });
            if (type === 'school_id' && userType) params.set('user_type', userType.value||'');
            const r = await fetch('signup.php?'+params.toString(), { cache:'no-store' });
            const d = await r.json();
            return !!(d && d.taken);
          }catch(_){ return false; }
        }
        const onIdInput = debounce(async function(){ if (!schoolId) return; const taken = await checkAvailability('school_id', schoolId.value.trim()); if (idTakenMsg){ idTakenMsg.style.display = taken ? 'block' : 'none'; } }, 300);
        const onFullInput = debounce(async function(){ if (!fullName) return; const v = fullName.value.trim(); if (!v){ if (fullTakenMsg) fullTakenMsg.style.display='none'; return; } const taken = await checkAvailability('full_name', v); if (fullTakenMsg){ fullTakenMsg.style.display = taken ? 'block' : 'none'; } }, 300);
        const onUserInput = debounce(async function(){ if (!username) return; const v = username.value.trim(); if (!v){ if (userTakenMsg) userTakenMsg.style.display='none'; return; } const taken = await checkAvailability('username', v); if (userTakenMsg){ userTakenMsg.style.display = taken ? 'block' : 'none'; } }, 300);
        if (schoolId) schoolId.addEventListener('input', onIdInput);
        if (userType) userType.addEventListener('change', onIdInput);
        if (fullName) fullName.addEventListener('input', onFullInput);
        if (username) username.addEventListener('input', onUserInput);
      })();
    </script>
</body>
</html>
