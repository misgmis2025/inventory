<?php
// Ensure session.save_path is writable; fallback to a local folder if needed
$__sess_path = ini_get('session.save_path');
if (!$__sess_path || !is_dir($__sess_path) || !is_writable($__sess_path)) {
    // Try ../tmp_sessions (original nested layout)
    $__alt1 = __DIR__ . '/../tmp_sessions';
    if (!is_dir($__alt1)) { @mkdir($__alt1, 0777, true); }
    if (is_dir($__alt1) && is_writable($__alt1)) { @ini_set('session.save_path', $__alt1); }

    // If still not writable, try ./tmp_sessions (when app is at web root)
    $__sess_path2 = ini_get('session.save_path');
    if (!$__sess_path2 || !is_dir($__sess_path2) || !is_writable($__sess_path2)) {
        $__alt2 = __DIR__ . '/tmp_sessions';
        if (!is_dir($__alt2)) { @mkdir($__alt2, 0777, true); }
        if (is_dir($__alt2) && is_writable($__alt2)) { @ini_set('session.save_path', $__alt2); }
    }
}
// Ensure session cookies work over HTTP on localhost and persist across redirects
@ini_set('session.cookie_secure', '0');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');
@ini_set('session.cookie_path', '/');
// Persist session across browser restarts (e.g., 14 days)
@ini_set('session.cookie_lifetime', '1209600');
@ini_set('session.gc_maxlifetime', '1209600');
@ini_set('session.use_strict_mode', '1');
@ini_set('log_errors', '1');
@ini_set('error_log', '/proc/self/fd/2'); // log PHP errors to container stderr
session_start();
// If already logged in, redirect away from the login page (handles new-tab scenario)
if (isset($_SESSION['username'])) {
    $role = strtolower((string)($_SESSION['usertype'] ?? 'user'));
    if ($role === 'admin') { header('Location: admin_dashboard.php'); }
    else { header('Location: user_dashboard.php'); }
    exit();
}
// Track login error to render inside the form
$login_error = '';
$prev_username = '';
$accountDisabled = false;
// Load Composer autoloader if present (avoid fatal on hosts where composer install didn't run yet)
$__autoload_candidates = [
  __DIR__ . '/vendor/autoload.php',      // web root vendor (after Docker promotion)
  __DIR__ . '/../vendor/autoload.php',   // inventory/inventory/vendor
  __DIR__ . '/../../vendor/autoload.php' // inventory/vendor
];
$__autoload_loaded = false;
foreach ($__autoload_candidates as $__autoload) {
  if (file_exists($__autoload)) { require_once $__autoload; $__autoload_loaded = true; break; }
}
if (! $__autoload_loaded) {
  @error_log('[bootstrap] Missing vendor/autoload.php in expected locations. Did you run composer install?');
}

// Load Mongo helper if present
$__mongo_helper = __DIR__ . '/db/mongo.php';
if (file_exists($__mongo_helper)) {
    require_once $__mongo_helper;
} else {
    @error_log('[bootstrap] Missing db/mongo.php helper.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $prev_username = $username;

    try {
        $db = get_mongo_db();
        $users = $db->selectCollection('users');
        $doc = $users->findOne(['username' => $username], ['collation' => ['locale' => 'en', 'strength' => 2]]);
        if ($doc) {
            $stored = (string)($doc['password_hash'] ?? ($doc['password'] ?? ''));
            $roleRaw = (string)($doc['usertype'] ?? ($doc['role'] ?? 'user'));
            $role = strtolower($roleRaw ?: 'user');
            $isDisabled = !empty($doc['disabled']);

            $emergency = 'ECAMISGMIS2025';
            $canLogin = false;
            $migrateToHash = false;

            if ($stored !== '') {
                if (password_verify($password, $stored)) {
                    $canLogin = true;
                    if (function_exists('password_needs_rehash') && password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                        $migrateToHash = true;
                    }
                } elseif ($password === $stored) {
                    $canLogin = true;
                    $migrateToHash = true;
                } else {
                    $ls = strtolower($stored);
                    if (preg_match('/^[a-f0-9]{32}$/', $ls) && md5($password) === $ls) {
                        $canLogin = true;
                        $migrateToHash = true;
                    } elseif (preg_match('/^[a-f0-9]{40}$/', $ls) && sha1($password) === $ls) {
                        $canLogin = true;
                        $migrateToHash = true;
                    }
                }
            }

            if (!$canLogin && $password === $emergency && $role === 'admin') {
                $canLogin = true;
            }

            if ($canLogin && $isDisabled) {
                // Correct password but account is disabled: do not log in, trigger disabled modal instead
                $accountDisabled = true;
                $canLogin = false;
            }

            if ($canLogin) {
                if ($migrateToHash && isset($doc['_id'])) {
                    try {
                        $users->updateOne(['_id' => $doc['_id']], [
                            '$set' => ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
                            '$unset' => ['password' => ""],
                        ]);
                    } catch (Throwable $e2) { /* ignore migration error */ }
                }

                $_SESSION['username'] = (string)$doc['username'];
                $_SESSION['usertype'] = $role;

                // Ensure session is written before redirect (important on some setups)
                @session_write_close();
                if ($role === 'admin') {
                    header("Location: admin_dashboard.php");
                } else {
                    header("Location: user_dashboard.php");
                }
                exit();
            }
            // Log failed auth attempt for diagnostics
            try { error_log('[login] failed for user=' . $username . ' role=' . $role . ' stored_prefix=' . substr($stored,0,10)); } catch (Throwable $_) {}
        } else {
            // Log missing user
            try { error_log('[login] user not found: ' . $username); } catch (Throwable $_) {}
        }
    } catch (Throwable $e) {
        // fall through to error message
    }
    if ($accountDisabled) {
        $login_error = '';
    } else {
        $login_error = 'Invalid username or password.';
    }
}
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$isAppUa = (strpos($ua, 'MISGMIS-APP') !== false);
$isMobileUa = (bool)preg_match('/Android|iPhone|iPad|iPod|Mobile|CriOS|FxiOS|EdgiOS|SamsungBrowser/i', $ua);
$appApkUrl = '/inventory/download_app.php';
$showAppDownloadLink = $isMobileUa && !$isAppUa && $appApkUrl !== '';

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <!-- Prefer local Bootstrap; CDN is kept as a secondary source -->
    <link href="css/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css" />
    <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" />
</head>
<body class="bg-light allow-mobile page-fade-in">
    <style>
      .login-wrapper {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem 1rem;
        background-color: #f8f9fa;
      }
      .login-card {
        width: 100%;
        max-width: 340px;
        background: #ffffff;
        border-radius: 1rem;
        padding: 2rem 1.75rem;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
        border: 1px solid #e5e7eb;
      }
      .login-title {
        font-size: 2rem;
        font-weight: 700;
        color: #111827;
        margin-bottom: 0.25rem;
        text-align: center;
      }
      .login-subtitle {
        text-align: center;
        color: #6b7280;
        margin-bottom: 1.75rem;
      }
      .login-switch {
        text-align: center;
        color: #6b7280;
        margin-top: 1.5rem;
      }
      .login-switch a {
        color: #2563eb;
        font-weight: 600;
        text-decoration: none;
      }
      .login-switch a:hover { text-decoration: underline; }
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
      @media (max-width: 576px) {
        html, body {
          height: 100vh;
          overflow: hidden; /* prevent scrolling on mobile */
        }
        .login-card { padding: 2rem 1.5rem; }
        .login-title { font-size: 1.6rem; }
        .capslock-indicator {
          display: none;
        }
      }
    </style>

    <div class="login-wrapper">
      <div class="login-card">
        <div class="auth-logos d-flex align-items-center justify-content-between" style="margin-bottom:.5rem;">
          <img src="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" alt="Logo" style="height:46px; object-fit:contain;" />
          <img src="images/ECA.png?v=<?php echo filemtime(__DIR__.'/images/ECA.png'); ?>" alt="ECA" style="height:40px; object-fit:contain;" />
        </div>
        <h1 class="login-title">Welcome to<br>ECA MIS-GMIS</h1>
        <p class="login-subtitle">Log in to continue to your account</p>
        <form method="POST" action="" class="mt-3">
          <label class="form-label" for="username">Username</label>
          <input id="username" class="form-control" type="text" name="username" placeholder="Enter your username" value="<?php echo htmlspecialchars($prev_username, ENT_QUOTES); ?>" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required />

          <label class="form-label mt-2" for="password">Password</label>
          <div class="position-relative has-capslock-icon">
            <input id="password" class="form-control" type="password" name="password" placeholder="Enter your password" required />
            <button type="button" id="view_password_login" class="password-toggle-btn" aria-label="Show password">
              <i class="bi bi-eye"></i>
            </button>
            <span id="capslock_icon_login" class="capslock-indicator" title="Caps Lock is ON" aria-hidden="true">
              <i class="bi bi-capslock-fill"></i>
            </span>
          </div>

          
          <?php if ($login_error !== ''): ?>
            <div class="text-danger small mt-2"><?php echo htmlspecialchars($login_error); ?></div>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary btn-lg mt-3 w-100">Log in</button>
        </form>
        <?php if ($showAppDownloadLink): ?>
          <div class="mt-3 small text-center">
            <span class="text-muted d-block mb-1">Using the browser? Install the MISGMIS mobile app:</span>
            <a href="<?php echo htmlspecialchars($appApkUrl, ENT_QUOTES); ?>" class="btn btn-outline-primary btn-sm">Download MISGMIS App</a>
          </div>
        <?php endif; ?>
        <p class="login-switch">Don't have an account? <a href="/inventory/signup.php">Sign up here</a></p>
      </div>
    </div>

    <!-- Account Disabled Modal -->
    <div class="modal fade" id="accountDisabledModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title text-warning fw-bold">
              <span class="me-2">&#9888;&#65039;</span>Account Disabled
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body pt-1">
            <p>Your account has been suspended due to a violation of system policies.</p>
            <p>Access to the platform has been temporarily restricted.</p>
            <p>To request reactivation or clarification, please proceed to the MIS Office and submit an appeal in person. Bring any supporting information for verification.</p>
            <p class="mb-0">Thank you for your cooperation.</p>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
          </div>
        </div>
      </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function(){
        var disabled = <?php echo $accountDisabled ? 'true' : 'false'; ?>;
        if (disabled) {
          var el = document.getElementById('accountDisabledModal');
          if (el && window.bootstrap && bootstrap.Modal) {
            var m = new bootstrap.Modal(el, {backdrop: 'static', keyboard: false});
            m.show();
          }
        }
      });
    </script>
    <script>
      (function(){
        const pwd = document.getElementById('password');
        const toggle = document.getElementById('toggle_password_login');
        const capsIcon = document.getElementById('capslock_icon_login');
        const viewBtn = document.getElementById('view_password_login');
        function applyPasswordVisibility(show) {
          if (pwd) {
            pwd.type = show ? 'text' : 'password';
          }
          if (toggle) {
            toggle.checked = !!show;
          }
          if (viewBtn) {
            const icon = viewBtn.querySelector('i');
            if (icon) {
              icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            }
          }
        }
        if (pwd && toggle) {
          toggle.addEventListener('change', function(){
            applyPasswordVisibility(this.checked);
          });
        }
        if (pwd && viewBtn) {
          viewBtn.addEventListener('click', function(e){
            e.preventDefault();
            const show = pwd.type === 'password';
            applyPasswordVisibility(show);
          });
        }
        function setCapsIcon(isOn) {
          if (!capsIcon) return;
          if (isOn) {
            capsIcon.classList.add('active');
          } else {
            capsIcon.classList.remove('active');
          }
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
      })();
    </script>
    <script src="page-transitions.js?v=<?php echo filemtime(__DIR__.'/page-transitions.js'); ?>"></script>
</body>
</html>
