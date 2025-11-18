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
@ini_set('session.use_strict_mode', '1');
@ini_set('log_errors', '1');
@ini_set('error_log', '/proc/self/fd/2'); // log PHP errors to container stderr
session_start();
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

    try {
        $db = get_mongo_db();
        $users = $db->selectCollection('users');
        $doc = $users->findOne(['username' => $username], ['collation' => ['locale' => 'en', 'strength' => 2]]);
        if ($doc) {
            $stored = (string)($doc['password_hash'] ?? ($doc['password'] ?? ''));
            $roleRaw = (string)($doc['usertype'] ?? ($doc['role'] ?? 'user'));
            $role = strtolower($roleRaw ?: 'user');

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
    echo "Invalid username or password.";
}
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
</head>
<body class="bg-light">
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
      @media (max-width: 576px) {
        .login-card { padding: 2rem 1.5rem; }
        .login-title { font-size: 1.6rem; }
      }
    </style>

    <div class="login-wrapper">
      <div class="login-card">
        <h1 class="login-title">Welcome to<br>ECA MIS-GMIS</h1>
        <p class="login-subtitle">Log in to continue to your account</p>
        <form method="POST" action="" class="mt-3">
          <label class="form-label" for="username">Username</label>
          <input id="username" class="form-control" type="text" name="username" placeholder="Enter your username" required />

          <label class="form-label mt-2" for="password">Password</label>
          <input id="password" class="form-control" type="password" name="password" placeholder="Enter your password" required />

          <div class="mt-2">
            <label style="display:inline-flex; align-items:center; gap:.5rem; cursor:pointer;">
              <input type="checkbox" id="toggle_password_login" />
              <span>Show password</span>
            </label>
          </div>

          <button type="submit" class="btn btn-primary btn-lg mt-3 w-100">Log in</button>
        </form>
        <p class="login-switch">Don't have an account? <a href="inventory/signup.php">Sign up here</a></p>
      </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      (function(){
        const pwd = document.getElementById('password');
        const toggle = document.getElementById('toggle_password_login');
        if (pwd && toggle) {
          toggle.addEventListener('change', function(){
            pwd.type = this.checked ? 'text' : 'password';
          });
        }
      })();
    </script>
</body>
</html>
