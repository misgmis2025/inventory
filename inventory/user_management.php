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
$userStats = [];
$agreementHtml = '';
$pendingVerifications = [];
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $usersCol = $db->selectCollection('users');

    // Load current Borrowing Agreement HTML (if any)
    try {
        $settingsCol = $db->selectCollection('settings');
        $cfg = $settingsCol->findOne(['key' => 'borrow_agreement_html']);
        if ($cfg && isset($cfg['value'])) {
            $agreementHtml = (string)$cfg['value'];
        }
    } catch (Throwable $_settings) {
        $agreementHtml = '';
    }

    // Handle POST actions in Mongo
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        // Global config: update Borrowing Agreement & Accountability Policy
        if ($action === 'update_borrow_agreement') {
            $content = (string)($_POST['borrow_agreement'] ?? '');
            try {
                $settingsCol = $db->selectCollection('settings');
                $settingsCol->updateOne(
                    ['key' => 'borrow_agreement_html'],
                    ['$set' => [
                        'key' => 'borrow_agreement_html',
                        'value' => $content,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]],
                    ['upsert' => true]
                );
            } catch (Throwable $_upd) { /* ignore and still redirect */ }
            header('Location: user_management.php?agreement_updated=1'); exit();
        }

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
                $adminPass = $_POST['admin_password'] ?? '';
                if ($adminPass === '') { header('Location: user_management.php?error=auth&ctx=delete&user='.urlencode($username)); exit(); }
                // Verify admin password
                $me = $usersCol->findOne(['username'=>$_SESSION['username']], ['projection'=>['password_hash'=>1]]);
                $authOk = ($me && isset($me['password_hash']) && password_verify($adminPass, (string)$me['password_hash']));
                if (!$authOk) { header('Location: user_management.php?error=auth&ctx=delete&user='.urlencode($username)); exit(); }
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
            } elseif ($action === 'toggle_disabled') {
                $adminPass = $_POST['admin_password'] ?? '';
                if ($adminPass === '') { header('Location: user_management.php?error=auth'); exit(); }
                $me = $usersCol->findOne(['username'=>$_SESSION['username']], ['projection'=>['password_hash'=>1]]);
                $authOk = ($me && isset($me['password_hash']) && password_verify($adminPass, (string)$me['password_hash']));
                if (!$authOk) { header('Location: user_management.php?error=auth'); exit(); }
                // Toggle disabled flag for non-admin accounts (cannot target self)
                $target = $usersCol->findOne(['username'=>$username], ['projection'=>['usertype'=>1]]);
                if (!$target) { header('Location: user_management.php?error=missing'); exit(); }
                if (($target['usertype'] ?? '') === 'admin') {
                    header('Location: user_management.php?error=disable_admin_forbidden'); exit();
                }
                $newDisabled = isset($_POST['disabled']) ? ((int)$_POST['disabled'] ? 1 : 0) : 1;
                $usersCol->updateOne(
                    ['username'=>$username],
                    ['$set'=>['disabled'=>$newDisabled, 'updated_at'=>date('Y-m-d H:i:s')]]
                );
                header('Location: user_management.php?updated=1'); exit();
            } elseif ($action === 'verify_user' || $action === 'reject_user') {
                $adminPass = $_POST['admin_password'] ?? '';
                if ($adminPass === '') { header('Location: user_management.php?error=auth'); exit(); }
                $me = $usersCol->findOne(['username'=>$_SESSION['username']], ['projection'=>['password_hash'=>1]]);
                $authOk = ($me && isset($me['password_hash']) && password_verify($adminPass, (string)$me['password_hash']));
                if (!$authOk) { header('Location: user_management.php?error=auth'); exit(); }
                $target = $usersCol->findOne(['username'=>$username], ['projection'=>['_id'=>1,'usertype'=>1]]);
                if (!$target) { header('Location: user_management.php?error=missing'); exit(); }
                if (($target['usertype'] ?? '') === 'admin') {
                    header('Location: user_management.php?error=verify_admin_forbidden'); exit();
                }
                $nowStr = date('Y-m-d H:i:s');
                if ($action === 'verify_user') {
                    $usersCol->updateOne(
                        ['_id'=>$target['_id']],
                        ['$set'=>[
                            'verification_status' => 'verified',
                            'verification_verified_at' => $nowStr,
                            'updated_at' => $nowStr,
                        ]]
                    );
                    header('Location: user_management.php?verification_verified=1'); exit();
                } else {
                    $usersCol->updateOne(
                        ['_id'=>$target['_id']],
                        ['$set'=>[
                            'verification_status' => 'rejected',
                            'verification_rejected_at' => $nowStr,
                            'updated_at' => $nowStr,
                        ]]
                    );
                    header('Location: user_management.php?verification_rejected=1'); exit();
                }
            }
        }
    }

    // List users
    $verifiedFilter = [
        '$or' => [
            ['verification_status' => 'verified'],
            ['verification_status' => ['$exists' => false]],
            ['verification_status' => ''],
        ],
    ];
    $cur = $usersCol->find($verifiedFilter, ['sort'=>['username'=>1], 'projection'=>['username'=>1,'usertype'=>1,'full_name'=>1,'user_type'=>1,'school_id'=>1,'disabled'=>1]]);
    foreach ($cur as $u) {
        $users[] = [
            'username' => (string)($u['username'] ?? ''),
            'usertype' => (string)($u['usertype'] ?? ''),
            'full_name' => (string)($u['full_name'] ?? ''),
            'user_type' => (string)($u['user_type'] ?? ''),
            'school_id' => isset($u['school_id']) ? (string)$u['school_id'] : '',
            'disabled' => !empty($u['disabled']),
        ];
    }
    try {
        $pendingCur = $usersCol->find([
            'usertype' => 'user',
            'verification_status' => 'pending',
        ], [
            'sort' => ['verification_requested_at' => 1, 'full_name' => 1],
            'projection' => ['username'=>1,'full_name'=>1,'user_type'=>1,'school_id'=>1,'verification_requested_at'=>1],
        ]);
        foreach ($pendingCur as $pv) {
            $ts = '';
            try {
                if (isset($pv['verification_requested_at']) && $pv['verification_requested_at'] instanceof MongoDB\BSON\UTCDateTime) {
                    $dt = $pv['verification_requested_at']->toDateTime();
                    $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                    $ts = $dt->format('Y-m-d H:i:s');
                } else {
                    $ts = trim((string)($pv['verification_requested_at'] ?? ''));
                }
            } catch (Throwable $_v) {
                $ts = trim((string)($pv['verification_requested_at'] ?? ''));
            }
            $pendingVerifications[] = [
                'username' => (string)($pv['username'] ?? ''),
                'full_name' => (string)($pv['full_name'] ?? ''),
                'user_type' => (string)($pv['user_type'] ?? ''),
                'school_id' => isset($pv['school_id']) ? (string)$pv['school_id'] : '',
                'requested_at' => $ts,
            ];
        }
    } catch (Throwable $_pend) {
        $pendingVerifications = [];
    }
    if (!empty($users)) {
        $me = (string)($_SESSION['username'] ?? '');
        if ($me !== '') {
            usort($users, function($a, $b) use ($me) {
                $isMeA = ($a['username'] === $me);
                $isMeB = ($b['username'] === $me);
                if ($isMeA && !$isMeB) return -1;
                if ($isMeB && !$isMeA) return 1;
                return strcasecmp($a['username'], $b['username']);
            });
        }
        try {
            $nameMap = [];
            $typeMap = [];
            $usernamesSet = [];
            foreach ($users as $u) {
                $ut = (string)($u['usertype'] ?? '');
                $role = (string)($u['user_type'] ?? '');
                if ($ut === 'user' && in_array($role, ['Student','Staff','Faculty'], true)) {
                    $un = (string)($u['username'] ?? '');
                    if ($un === '') continue;
                    $usernamesSet[$un] = true;
                    $nameMap[$un] = (string)($u['full_name'] ?? '');
                    $typeMap[$un] = $role;
                }
            }
            $usernames = array_keys($usernamesSet);
            if (!empty($usernames)) {
                $ubCol = $db->selectCollection('user_borrows');
                $ldCol = $db->selectCollection('lost_damaged_log');
                $iiCol = $db->selectCollection('inventory_items');
                $uCol  = $db->selectCollection('users');
                $nowTs = time();
                foreach ($usernames as $un) {
                    $userStats[$un] = [
                        'full_name' => isset($nameMap[$un]) ? $nameMap[$un] : '',
                        'user_type' => isset($typeMap[$un]) ? $typeMap[$un] : '',
                        'borrowed' => 0,
                        'returned' => 0,
                        'overdue' => 0,
                        'lost' => 0,
                        'damaged' => 0,
                    ];
                }

                $borrowMeta = [];
                $curBor = $ubCol->find(['username' => ['$in' => $usernames]], ['projection' => ['id'=>1,'username'=>1,'borrowed_at'=>1,'returned_at'=>1,'expected_return_at'=>1]]);
                foreach ($curBor as $b) {
                    $un = isset($b['username']) ? (string)$b['username'] : '';
                    $bid = isset($b['id']) ? (int)$b['id'] : 0;
                    if ($un === '' || !isset($userStats[$un])) continue;
                    $userStats[$un]['borrowed']++;
                    $retStr = '';
                    try {
                        if (isset($b['returned_at']) && $b['returned_at'] instanceof MongoDB\BSON\UTCDateTime) {
                            $dt = $b['returned_at']->toDateTime();
                            $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                            $retStr = $dt->format('Y-m-d H:i:s');
                        } else {
                            $retStr = trim((string)($b['returned_at'] ?? ''));
                        }
                    } catch (Throwable $_r) {
                        $retStr = trim((string)($b['returned_at'] ?? ''));
                    }
                    $dueStr = '';
                    try {
                        if (isset($b['expected_return_at']) && $b['expected_return_at'] instanceof MongoDB\BSON\UTCDateTime) {
                            $dt2 = $b['expected_return_at']->toDateTime();
                            $dt2->setTimezone(new DateTimeZone('Asia/Manila'));
                            $dueStr = $dt2->format('Y-m-d H:i:s');
                        } else {
                            $dueStr = trim((string)($b['expected_return_at'] ?? ''));
                        }
                    } catch (Throwable $_e) {
                        $dueStr = trim((string)($b['expected_return_at'] ?? ''));
                    }
                    $isOverdue = false;
                    if ($dueStr !== '') {
                        $dueTs = @strtotime($dueStr);
                        if ($dueTs) {
                            if ($retStr === '') {
                                if ($dueTs < $nowTs) {
                                    $isOverdue = true;
                                    $userStats[$un]['overdue']++;
                                }
                            } else {
                                $retTs = @strtotime($retStr);
                                if ($retTs && $retTs > $dueTs) {
                                    $isOverdue = true;
                                    $userStats[$un]['overdue']++;
                                }
                            }
                        }
                    }
                    $onTime = ($retStr !== '' && !$isOverdue);
                    if ($bid > 0) {
                        $borrowMeta[$bid] = [
                            'username' => $un,
                            'on_time' => $onTime,
                        ];
                    }
                }

                $userSet = array_flip($usernames);
                $badBorrowIds = [];
                $tryBorrowDoc = function($mid, $when) use ($ubCol) {
                    $pick = null;
                    if (!$mid || $when === '') return null;
                    try {
                        $q1 = [
                            'model_id' => $mid,
                            'borrowed_at' => ['$lte' => $when],
                            '$or' => [
                                ['returned_at' => null],
                                ['returned_at' => ''],
                                ['returned_at' => ['$gte' => $when]],
                            ],
                        ];
                        $opt = ['sort' => ['borrowed_at' => -1, 'id' => -1], 'limit' => 1];
                        foreach ($ubCol->find($q1, $opt) as $br) {
                            $pick = $br;
                            break;
                        }
                        if ($pick === null) {
                            $q2 = ['model_id' => $mid, 'borrowed_at' => ['$lte' => $when]];
                            foreach ($ubCol->find($q2, $opt) as $br) {
                                $pick = $br;
                                break;
                            }
                        }
                    } catch (Throwable $_t) {}
                    return $pick;
                };

                $ldCur = $ldCol->find(
                    ['action' => ['$in' => ['Lost','Under Maintenance','Permanently Lost','Disposed','Found','Fixed','Damaged','Disposal']]],
                    ['sort' => ['model_id' => 1, 'created_at' => 1, 'id' => 1], 'limit' => 5000]
                );
                $openEpisodes = [];
                $lostEpisodes = [];
                $damagedEpisodes = [];
                foreach ($ldCur as $l) {
                    $mid = isset($l['model_id']) ? (int)$l['model_id'] : 0;
                    if ($mid <= 0) continue;
                    $logWhen = '';
                    try {
                        if (isset($l['created_at']) && $l['created_at'] instanceof MongoDB\BSON\UTCDateTime) {
                            $dt3 = $l['created_at']->toDateTime();
                            $dt3->setTimezone(new DateTimeZone('Asia/Manila'));
                            $logWhen = $dt3->format('Y-m-d H:i:s');
                        } else {
                            $logWhen = trim((string)($l['created_at'] ?? ''));
                        }
                    } catch (Throwable $_c) {
                        $logWhen = trim((string)($l['created_at'] ?? ''));
                    }
                    $act = strtolower((string)($l['action'] ?? ''));
                    if (!isset($openEpisodes[$mid])) {
                        $openEpisodes[$mid] = null;
                    }
                    $ep =& $openEpisodes[$mid];
                    $isStartCandidate = in_array($act, ['lost','under maintenance','damaged','permanently lost','disposed','disposal'], true);
                    // Start a new episode when we see a loss/damage-type action and none is open
                    if ($isStartCandidate && $ep === null) {
                        $userU = '';
                        $borrowDoc = null;
                        if (isset($l['affected_username'])) {
                            $userU = trim((string)$l['affected_username']);
                        }
                        if ($logWhen !== '') {
                            $borrowDoc = $tryBorrowDoc($mid, $logWhen);
                            if ($userU === '' && $borrowDoc) {
                                $userU = (string)($borrowDoc['username'] ?? '');
                            }
                        }
                        if ($userU === '' && isset($l['username'])) {
                            $cand = trim((string)$l['username']);
                            if (isset($userSet[$cand])) {
                                $userU = $cand;
                            }
                        }
                        if ($userU !== '' && isset($userStats[$userU])) {
                            $isLost = in_array($act, ['lost','permanently lost'], true);
                            $borrowId = 0;
                            if ($borrowDoc && (string)($borrowDoc['username'] ?? '') === $userU) {
                                $borrowId = isset($borrowDoc['id']) ? (int)$borrowDoc['id'] : 0;
                                if ($borrowId > 0) {
                                    $badBorrowIds[$borrowId] = true;
                                }
                            }
                            $ep = [
                                'user' => $userU,
                                'lost' => $isLost,
                                'damaged' => !$isLost,
                            ];
                        }
                        unset($ep);
                        continue;
                    }
                    if ($ep !== null) {
                        // Upgrade flags within an open episode
                        if (in_array($act, ['lost','permanently lost'], true)) {
                            $ep['lost'] = true;
                            $ep['damaged'] = false;
                        } elseif (in_array($act, ['under maintenance','damaged','disposed','disposal'], true)) {
                            if (!$ep['lost']) {
                                $ep['damaged'] = true;
                            }
                        }
                        // Resolution-type actions close the episode but still count it
                        if (in_array($act, ['found','fixed','permanently lost','disposed','disposal'], true)) {
                            $u = $ep['user'];
                            if (!isset($lostEpisodes[$u])) $lostEpisodes[$u] = 0;
                            if (!isset($damagedEpisodes[$u])) $damagedEpisodes[$u] = 0;
                            if (!empty($ep['lost'])) {
                                $lostEpisodes[$u]++;
                            } elseif (!empty($ep['damaged'])) {
                                $damagedEpisodes[$u]++;
                            }
                            $ep = null;
                        }
                    }
                    unset($ep);
                }
                // Any open episodes with no explicit resolution should still be counted once
                foreach ($openEpisodes as $mid => $ep) {
                    if ($ep === null) continue;
                    $u = $ep['user'];
                    if (!isset($userStats[$u])) continue;
                    if (!isset($lostEpisodes[$u])) $lostEpisodes[$u] = 0;
                    if (!isset($damagedEpisodes[$u])) $damagedEpisodes[$u] = 0;
                    if (!empty($ep['lost'])) {
                        $lostEpisodes[$u]++;
                    } elseif (!empty($ep['damaged'])) {
                        $damagedEpisodes[$u]++;
                    }
                }
                // Apply episode counts into userStats
                foreach ($userStats as $uname => &$st) {
                    $st['lost'] = isset($lostEpisodes[$uname]) ? (int)$lostEpisodes[$uname] : 0;
                    $st['damaged'] = isset($damagedEpisodes[$uname]) ? (int)$damagedEpisodes[$uname] : 0;
                }
                unset($st);

                // Successful Returns: on-time returns that are not tied to any lost/damaged episode
                if (!empty($borrowMeta)) {
                    foreach ($borrowMeta as $bid => $meta) {
                        $un = (string)($meta['username'] ?? '');
                        if ($un === '' || !isset($userStats[$un])) continue;
                        if (!empty($meta['on_time']) && empty($badBorrowIds[$bid])) {
                            $userStats[$un]['returned']++;
                        }
                    }
                }
            }
        } catch (Throwable $_agg) {
            $userStats = [];
        }
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
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__.'/css/style.css'); ?>">
    <link rel="icon" type="image/png" href="images/logo-removebg.png?v=<?php echo filemtime(__DIR__.'/images/logo-removebg.png').'-'.filesize(__DIR__.'/images/logo-removebg.png'); ?>" />
    <style>
      html, body { height: 100%; }
      body { overflow: hidden; }
      #sidebar-wrapper { position: sticky; top: 0; height: 100vh; overflow: hidden; }
      #page-content-wrapper { flex: 1 1 auto; height: 100vh; overflow: auto; }
      @media (max-width: 768px) {
        body { overflow: auto; }
        #page-content-wrapper { height: auto; overflow: visible; }
      }
      .accounts-table-scroll { max-height: 260px; min-height: 260px; overflow-y: auto; padding-bottom: 3.5rem; }
      .accounts-table-scroll thead th { position: sticky; top: 0; z-index: 2; }
      .stats-table-scroll { max-height: 260px; min-height: 260px; overflow-y: auto; }
      .stats-table-scroll thead th { position: sticky; top: 0; z-index: 2; }
      #verificationTable { width: 100%; }
      #verificationTable thead th,
      #verificationTable tbody td { padding-top: 0.25rem; padding-bottom: 0.25rem; }
      /* Borrowing Agreement editor: keep toolbar visible while scrolling */
      #editBorrowAgreementModal .modal-body {
        max-height: 70vh;
      }
      #editBorrowAgreementModal .ba-toolbar {
        position: sticky;
        top: 0;
        z-index: 10;
        background-color: #ffffff;
        padding-top: 0.25rem;
        padding-bottom: 0.25rem;
      }
      /* Smaller action buttons in user table */
      .user-actions .btn.btn-sm { padding: 0.1rem 0.3rem; font-size: 0.72rem; line-height: 1; min-height: 1.5rem; }
      .user-actions { gap: 0.2rem !important; }
      .user-actions .btn .bi { font-size: 0.85em; margin-right: 0.25rem !important; }
      .btn-actions-thin { padding: 0.1rem 0.45rem; font-size: 0.78rem; line-height: 1.1; }
      .verification-action-btn.btn { padding: 0.1rem 0.35rem; font-size: 0.72rem; line-height: 1; }
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
                <a href="user_management.php" class="list-group-item list-group-item-action bg-transparent fw-bold">
                    <i class="bi bi-people me-2"></i>User Management
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
                <h2 class="page-title mb-0">
                    <i class="bi bi-people me-2"></i>User Management
                </h2>
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#editBorrowAgreementModal">
                        <i class="bi bi-file-earmark-text me-1"></i>Edit Policy
                    </button>
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
            <?php if (isset($_GET['error']) && $_GET['error']==='disable_admin_forbidden'): ?>
                <div class="alert alert-warning">Cannot disable an admin account. Demote to Student/Staff/Faculty first.</div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error']==='verify_admin_forbidden'): ?>
                <div class="alert alert-warning">Cannot process physical verification for an admin account.</div>
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
            <?php if (isset($_GET['agreement_updated'])): ?>
                <div class="alert alert-success">Borrowing Agreement &amp; Accountability Policy has been updated.</div>
            <?php endif; ?>
            <?php if (isset($_GET['verification_verified'])): ?>
                <div class="alert alert-success">User has been marked as physically verified.</div>
            <?php endif; ?>
            <?php if (isset($_GET['verification_rejected'])): ?>
                <div class="alert alert-info">Physical verification request has been rejected.</div>
            <?php endif; ?>
            <?php if (isset($pendingVerifications) && is_array($pendingVerifications)): ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Pending Verifications</strong>
                    <div class="ms-3" style="max-width: 260px;">
                        <input type="text" id="verificationSearch" class="form-control form-control-sm" placeholder="Search full name or school ID">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive accounts-table-scroll">
                        <table id="verificationTable" class="table table-striped table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Full Name</th>
                                    <th>Username</th>
                                    <th>School ID</th>
                                    <th>User Type</th>
                                    <th class="text-end text-nowrap">Requested At</th>
                                    <th class="text-end text-nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pendingVerifications)): ?>
                                    <tr><td colspan="6" class="text-center text-muted">No pending verifications.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pendingVerifications as $pv): ?>
                                        <tr data-verif-row="1" data-fullname="<?php echo htmlspecialchars($pv['full_name'] ?? ''); ?>" data-school-id="<?php echo htmlspecialchars($pv['school_id'] ?? ''); ?>">
                                            <td><?php echo htmlspecialchars($pv['full_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($pv['username'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($pv['school_id'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($pv['user_type'] ?? ''); ?></td>
                                            <td class="text-end text-nowrap"><?php echo htmlspecialchars($pv['requested_at'] ?? ''); ?></td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <form method="post" action="user_management.php" class="m-0 p-0 verification-action-form">
                                                        <input type="hidden" name="action" value="verify_user" />
                                                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($pv['username'] ?? ''); ?>" />
                                                        <button type="button" class="btn btn-success verification-action-btn" data-username="<?php echo htmlspecialchars($pv['username'] ?? ''); ?>" data-mode="verify">Verify</button>
                                                    </form>
                                                    <form method="post" action="user_management.php" class="m-0 p-0 verification-action-form">
                                                        <input type="hidden" name="action" value="reject_user" />
                                                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($pv['username'] ?? ''); ?>" />
                                                        <button type="button" class="btn btn-outline-danger verification-action-btn" data-username="<?php echo htmlspecialchars($pv['username'] ?? ''); ?>" data-mode="reject">Reject</button>
                                                    </form>
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
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Accounts</strong>
                    <div class="ms-3" style="max-width: 260px;">
                        <input type="text" id="accountsSearch" class="form-control form-control-sm" placeholder="Search username, full name, or school ID">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive accounts-table-scroll">
                        <table id="accountsTable" class="table table-striped table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Full Name</th>
                                    <th>Username</th>
                                    <th>School ID</th>
                                    <th>User Type</th>
                                    <th class="text-end text-nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="3" class="text-center text-muted">No users found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $u): ?>
                                        <?php $isDisabled = !empty($u['disabled']); ?>
                                        <tr data-account-row="1">
                                            <td><?php echo htmlspecialchars($u['full_name'] ?? ''); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($u['username']); ?>
                                                <?php if ($isDisabled): ?>
                                                    <span class="badge bg-secondary ms-1">Disabled</span>
                                                <?php endif; ?>
                                            </td>
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
                                            <td class="text-end">
                                                <?php if ($u['username'] !== ($_SESSION['username'] ?? '')): ?>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary btn-actions-thin dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                        Actions
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li>
                                                            <button type="button"
                                                                    class="dropdown-item type-edit-btn"
                                                                    data-username="<?php echo htmlspecialchars($u['username']); ?>"
                                                                    data-currenttype="<?php echo htmlspecialchars($u['user_type'] ?: ''); ?>">
                                                                <i class="bi bi-pencil-square me-1"></i>Edit Type
                                                            </button>
                                                        </li>
                                                        <li>
                                                            <button type="button"
                                                                    class="dropdown-item pw-reset-btn"
                                                                    data-username="<?php echo htmlspecialchars($u['username']); ?>">
                                                                <i class="bi bi-key me-1"></i>Reset Password
                                                            </button>
                                                        </li>
                                                        <?php if (($u['usertype'] ?? '') !== 'admin'): ?>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="post" action="user_management.php" class="px-3 py-0 m-0 account-toggle-form">
                                                                <input type="hidden" name="action" value="toggle_disabled" />
                                                                <input type="hidden" name="username" value="<?php echo htmlspecialchars($u['username']); ?>" />
                                                                <input type="hidden" name="disabled" value="<?php echo $isDisabled ? '0' : '1'; ?>" />
                                                                <button type="button"
                                                                        class="dropdown-item text-warning p-0 account-disable-btn"
                                                                        data-username="<?php echo htmlspecialchars($u['username']); ?>"
                                                                        data-mode="<?php echo $isDisabled ? 'enable' : 'disable'; ?>">
                                                                    <i class="bi bi-slash-circle me-1"></i><?php echo $isDisabled ? 'Enable Account' : 'Disable Account'; ?>
                                                                </button>
                                                            </form>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="post" action="user_management.php" class="px-3 py-0 m-0 account-delete-form">
                                                                <input type="hidden" name="action" value="delete_user" />
                                                                <input type="hidden" name="username" value="<?php echo htmlspecialchars($u['username']); ?>" />
                                                                <button type="button"
                                                                        class="dropdown-item text-danger p-0 account-delete-btn"
                                                                        data-username="<?php echo htmlspecialchars($u['username']); ?>">
                                                                    <i class="bi bi-trash me-1"></i>Delete
                                                                </button>
                                                            </form>
                                                        </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <strong>User Borrowing Records</strong>
                    <div class="d-flex align-items-center gap-1 flex-nowrap" style="white-space: nowrap;">
                        <input type="text" id="statsSearch" class="form-control form-control-sm" placeholder="Search full name or username" style="max-width: 160px;">
                        <select id="statsUserTypeFilter" class="form-select form-select-sm" style="max-width: 120px;">
                            <option value="all">All Types</option>
                            <option value="Student">Student</option>
                            <option value="Staff">Staff</option>
                            <option value="Faculty">Faculty</option>
                        </select>
                        <select id="statsStatusFilter" class="form-select form-select-sm" style="max-width: 120px;">
                            <option value="all">All Records</option>
                            <option value="clean">Clean Records</option>
                            <option value="lost">Lost</option>
                            <option value="damaged">Damaged</option>
                            <option value="overdue_only">Overdue</option>
                        </select>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive stats-table-scroll">
                        <table id="userStatsTable" class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Full Name</th>
                                    <th>Username</th>
                                    <th>User Type</th>
                                    <th class="text-center">Total Borrowed</th>
                                    <th class="text-center">Successful Returns</th>
                                    <th class="text-center">Total Overdue</th>
                                    <th class="text-center">Total Lost</th>
                                    <th class="text-center">Total Damaged</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($userStats)): ?>
                                    <tr><td colspan="8" class="text-center text-muted">No borrowing records found for non-admin users.</td></tr>
                                <?php else: ?>
                                    <?php
                                      $statsRows = $userStats;
                                      ksort($statsRows);
                                      foreach ($statsRows as $uname => $st):
                                        $over = (int)($st['overdue'] ?? 0);
                                        $lost = (int)($st['lost'] ?? 0);
                                        $dam  = (int)($st['damaged'] ?? 0);
                                        $rowClass = 'table-success';
                                        if ($lost > 0 || $dam > 0) {
                                          $rowClass = 'table-danger';
                                        } elseif ($over > 0) {
                                          $rowClass = 'table-warning';
                                        }
                                        $displayName = ($st['full_name'] !== '' ? $st['full_name'] : $uname);
                                    ?>
                                    <tr class="<?php echo $rowClass; ?>"
                                        data-stats-row="1"
                                        data-fullname="<?php echo htmlspecialchars($displayName); ?>"
                                        data-username="<?php echo htmlspecialchars($uname); ?>"
                                        data-user-type="<?php echo htmlspecialchars($st['user_type'] ?? ''); ?>"
                                        data-overdue="<?php echo (int)($st['overdue'] ?? 0); ?>"
                                        data-lost="<?php echo (int)($st['lost'] ?? 0); ?>"
                                        data-damaged="<?php echo (int)($st['damaged'] ?? 0); ?>">
                                        <td><?php echo htmlspecialchars($displayName); ?></td>
                                        <td><?php echo htmlspecialchars($uname); ?></td>
                                        <td><?php echo htmlspecialchars($st['user_type'] ?? ''); ?></td>
                                        <td class="text-center"><?php echo (int)($st['borrowed'] ?? 0); ?></td>
                                        <td class="text-center"><?php echo (int)($st['returned'] ?? 0); ?></td>
                                        <td class="text-center"><?php echo (int)($st['overdue'] ?? 0); ?></td>
                                        <td class="text-center"><?php echo (int)($st['lost'] ?? 0); ?></td>
                                        <td class="text-center"><?php echo (int)($st['damaged'] ?? 0); ?></td>
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

    <!-- Edit Borrowing Agreement & Accountability Policy Modal -->
    <div class="modal fade" id="editBorrowAgreementModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Edit Borrowing Agreement &amp; Accountability Policy</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <form method="post" action="user_management.php" id="editBorrowAgreementForm">
              <input type="hidden" name="action" value="update_borrow_agreement" />
              <div class="mb-2 small text-muted">
                This content is shown on the Signup page and in the user Submit Request / Scan Item QR modals.
              </div>
              <div class="ba-toolbar">
              <div class="btn-group btn-group-sm mb-2" role="group" aria-label="Formatting">
                <button type="button" class="btn btn-outline-secondary ba-format-btn" data-tag="b"><strong>B</strong></button>
                <button type="button" class="btn btn-outline-secondary ba-format-btn" data-tag="i"><em>I</em></button>
                <button type="button" class="btn btn-outline-secondary ba-format-btn" data-tag="u"><span style="text-decoration:underline;">U</span></button>
                <button type="button" class="btn btn-outline-secondary ba-format-btn" data-tag="ul">&bull; List</button>
                <button type="button" class="btn btn-outline-secondary ba-format-btn" data-tag="center">Center</button>
                <button type="button" class="btn btn-outline-secondary ba-format-btn" data-tag="justify">Justify</button>
              </div>
              </div>
              <div id="borrowAgreementEditor" class="form-control" contenteditable="true" style="min-height: 260px;">
                <?php if ($agreementHtml !== ''): ?>
                  <?php echo $agreementHtml; ?>
                <?php else: ?>
                  <h5 class="fw-bold mb-1 text-center">MIS Borrowing System</h5>
                  <h6 class="fw-bold mb-3 text-center">Borrowing Agreement &amp; Accountability Policy</h6>

                  <p class="mb-2">
                    <strong>Issued by:</strong> MIS Department, Exact Colleges of Asia.<br>
                    <strong>Applies to:</strong> All Users.
                  </p>

                  <h6 class="fw-bold mt-3">1. PURPOSE</h6>
                  <p class="mb-2">This agreement outlines the responsibilities of all users who borrow equipment, devices, tools, or materials from the MIS Inventory System. It ensures proper handling, accountability, and timely return of school property.</p>

                  <h6 class="fw-bold mt-3">2. QR CODE LABEL REQUIREMENT</h6>
                  <p class="mb-1">To organize and identify items, all MIS inventory items include a QR code label.</p>
                  <p class="mb-1 fw-bold">By borrowing an item, the user agrees to the following:</p>
                  <ul class="mb-2">
                    <li>Do not remove or damage the QR code. Removing, peeling, scratching, or damaging the QR label is strictly prohibited.</li>
                    <li>Any damage to the QR label will be considered damage to the item, and the borrower may be required to shoulder repair or replacement costs.</li>
                    <li>Borrowed items must be returned with the QR label fully intact and readable.</li>
                  </ul>

                  <h6 class="fw-bold mt-3">3. BORROWER RESPONSIBILITIES</h6>
                  <p class="mb-1">All borrowers agree to:</p>
                  <ul class="mb-2">
                    <li>Use items only for official or academic purposes.</li>
                    <li>Handle items carefully and keep them secured at all times.</li>
                    <li>Return items on or before the assigned due date and time.</li>
                    <li>Ensure the item is in the same condition as when it was borrowed.</li>
                    <li>Respect all rules implemented by the MIS Department.</li>
                  </ul>

                  <h6 class="fw-bold mt-3">4. DAMAGE, LOSS, AND ACCOUNTABILITY</h6>
                  <p class="mb-1">Borrowers accept and acknowledge:</p>
                  <ul class="mb-2">
                    <li>The borrower is fully responsible for any loss, damage, theft, or tampering involving the item while it is under their possession.</li>
                    <li>If an item is damaged, the borrower must pay for the repair or provide an equivalent replacement of equal or higher value.</li>
                    <li>If an item is lost or unreturned, the borrower must pay the full replacement cost at current market value.</li>
                    <li>Any damage to the QR label (removal, scratches, tearing) will result in a reprinting fee and possibly additional charges if the item itself is affected.</li>
                    <li>Failure to return items or settle charges may lead to suspension of borrowing privileges, withholding of clearance, or administrative actions.</li>
                  </ul>

                  <h6 class="fw-bold mt-3">5. PROHIBITED ACTIONS</h6>
                  <p class="mb-1">Borrowers must NOT:</p>
                  <ul class="mb-2">
                    <li>Lend the item to another person.</li>
                    <li>Tamper with any part of the item including the QR label.</li>
                    <li>Use the item for non-school related or unauthorized activities.</li>
                    <li>Attempt to alter or modify the item in any way.</li>
                  </ul>

                  <h6 class="fw-bold mt-3">6. CONDITIONS OF RELEASE</h6>
                  <p class="mb-1">Items will only be issued if:</p>
                  <ul class="mb-2">
                    <li>The borrower has no pending obligations or violations.</li>
                    <li>The borrower provides accurate personal information.</li>
                    <li>The borrower agrees to all terms listed in this document.</li>
                  </ul>

                  <h6 class="fw-bold mt-3">7. AGREEMENT</h6>
                  <p class="mb-2">By borrowing any item from the MIS Inventory System, the borrower agrees that:</p>
                  <ul class="mb-0">
                    <li>They have read and understood this Borrowing Agreement.</li>
                    <li>They take full responsibility for the item until it is returned.</li>
                    <li>They will pay for or replace any item that is lost, damaged, tampered with, or returned with a damaged QR label.</li>
                    <li>They understand that non-compliance may result in disciplinary action.</li>
                  </ul>
                <?php endif; ?>
              </div>
              <textarea name="borrow_agreement" id="borrowAgreementTextarea" class="d-none"><?php echo htmlspecialchars($agreementHtml !== '' ? $agreementHtml : ''); ?></textarea>
            </form>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="resetBorrowAgreementBtn">Reset to Default</button>
            <button type="submit" form="editBorrowAgreementForm" class="btn btn-primary btn-sm" id="saveBorrowAgreementBtn" disabled>Save Policy</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="verificationDecisionModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Confirmation</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p> 
              Action: <strong id="verificationAction">Verify</strong><br>
              Target account: <strong id="verificationUsername"></strong>
            </p>
            <div class="mb-0">
              <label class="form-label mb-1">Enter your password to confirm</label>
              <input type="password" class="form-control form-control-sm" id="verificationAdminPassword" placeholder="Your password" autocomplete="current-password" />
              <div class="mt-1 small text-danger d-none" id="verificationErrorText">Incorrect password. Please try again.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-success btn-sm" id="verificationConfirmBtn" disabled>Verify</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Account Disable/Enable Modal -->
    <div class="modal fade" id="accountDisableModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Disable Account</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p class="account-disable-body-main">
              Are you sure you want to disable this account? The user will be blocked from logging in.
            </p>
            <p class="mb-2">
              Target account: <strong id="accountDisableUsername"></strong>
            </p>
            <div class="mb-0">
              <label class="form-label mb-1">Enter your password to confirm</label>
              <input type="password" class="form-control form-control-sm" id="accountDisableAdminPassword" placeholder="Your password" autocomplete="current-password" />
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-warning btn-sm" id="accountDisableConfirmBtn">Disable</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Account Delete Modal -->
    <div class="modal fade" id="accountDeleteModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title text-danger">Delete Account</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Are you sure you want to permanently delete this account?</p>
            <p>
              Target account: <strong id="accountDeleteUsername"></strong>
            </p>
            <div class="mb-0">
              <label class="form-label mb-1">Enter your password to confirm</label>
              <input type="password" class="form-control form-control-sm" id="accountDeleteAdminPassword" placeholder="Your password" autocomplete="current-password" />
              <div class="mt-1 small text-danger d-none" id="accountDeleteErrorText">Incorrect password. Please try again.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-danger btn-sm" id="accountDeleteConfirmBtn" disabled>Delete</button>
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
                    if (bellDot) bellDot.classList.add('d-none');
                });
                document.addEventListener('click', ()=>dropdown.classList.remove('show'));
            }
            let toastWrap = document.getElementById('adminToastWrap');
            if (!toastWrap) { toastWrap=document.createElement('div'); toastWrap.id='adminToastWrap'; toastWrap.style.position='fixed'; toastWrap.style.right='16px'; toastWrap.style.bottom='16px'; toastWrap.style.zIndex='1030'; document.body.appendChild(toastWrap); }
            function showToast(msg){ const el=document.createElement('div'); el.className='alert alert-info shadow-sm border-0'; el.style.minWidth='280px'; el.style.maxWidth='360px'; el.innerHTML='<i class="bi bi-bell me-2"></i>'+String(msg||''); toastWrap.appendChild(el); setTimeout(()=>{ try{ el.remove(); }catch(_){ } }, 5000); }
            let audioCtx=null; function playBeep(){ try{ if(!audioCtx) audioCtx=new (window.AudioContext||window.webkitAudioContext)(); if (audioCtx.state==='suspended'){ try{ audioCtx.resume(); }catch(_e){} } const o=audioCtx.createOscillator(), g=audioCtx.createGain(); o.type='square'; o.frequency.setValueAtTime(880, audioCtx.currentTime); g.gain.setValueAtTime(0.0001, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.35, audioCtx.currentTime+0.03); g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime+0.6); o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime+0.65);}catch(_){}}
            function escapeHtml(s){ return String(s).replace(/[&<>"]/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[m])); }
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
                  rows.push('<div class="list-group-item"><div class="d-flex justify-content-between align-items-center"><span class="small text-muted">Processed</span><button type="button" class="btn btn-sm btn-outline-secondary" id="admClearAllBtn">Clear All</button></div></div>');
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
                listEl.innerHTML=rows.join(''); emptyEl.style.display=rows.length?'none':'block';
            }
            document.addEventListener('click', function(ev){
              const one = ev.target && ev.target.closest && ev.target.closest('.adm-clear-one');
              if (one){ ev.preventDefault(); const rid=parseInt(one.getAttribute('data-id')||'0',10)||0; if(!rid) return; const fd=new FormData(); fd.append('request_id', String(rid)); fetch('admin_borrow_center.php?action=admin_notif_clear',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ poll(); }).catch(()=>{}); return; }
              if (ev.target && ev.target.id === 'admClearAllBtn'){ ev.preventDefault(); const fd=new FormData(); fd.append('limit','300'); fetch('admin_borrow_center.php?action=admin_notif_clear_all',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ poll(); }).catch(()=>{}); }
            });
            function poll(){ if(fetching) return; fetching=true; fetch('admin_borrow_center.php?action=admin_notifications').then(r=>r.json()).then(d=>{ const pending=(d&&Array.isArray(d.pending))? d.pending:[]; const recent=(d&&Array.isArray(d.recent))? d.recent:[]; if (bellDot) bellDot.classList.toggle('d-none', pending.length===0); try{ const navLink=document.querySelector('a[href="admin_borrow_center.php"]'); if(navLink){ let dot=navLink.querySelector('.nav-borrow-dot'); const shouldShow = pending.length>0; if (shouldShow){ if(!dot){ dot=document.createElement('span'); dot.className='nav-borrow-dot ms-2 d-inline-block rounded-circle'; dot.style.width='8px'; dot.style.height='8px'; dot.style.backgroundColor='#dc3545'; dot.style.verticalAlign='middle'; dot.style.display='inline-block'; navLink.appendChild(dot);} else { dot.style.display='inline-block'; } } else if (dot){ dot.style.display='none'; } } }catch(_){} renderCombined(pending, recent); const curr=new Set(pending.map(it=>parseInt(it.id||0,10))); if(!initialized){ baseline=curr; initialized=true; } else { let hasNew=false; pending.forEach(it=>{ const id=parseInt(it.id||0,10); if(!baseline.has(id)){ hasNew=true; showToast('New request: '+(it.username||'')+' → '+(it.item_name||'')+' (x'+(it.quantity||1)+')'); } }); if(hasNew) playBeep(); baseline=curr; } }).catch(()=>{}).finally(()=>{ fetching=false; }); }
            poll(); setInterval(()=>{ if(document.visibilityState==='visible') poll(); }, 1000);
            // Also poll user self-return feed (return_events) for side toasts
            var retBase = new Set(); var retInit=false; var retFetching=false;
            function pollUserReturns(){ if (retFetching) return; retFetching = true;
              fetch('admin_borrow_center.php?action=return_feed')
                .then(function(r){ return r.json(); })
                .then(function(d){ var list=(d&&d.ok&&Array.isArray(d.returns))?d.returns:[]; var ids=new Set(list.map(function(v){ return parseInt(v.id||0,10); }).filter(function(n){ return n>0; })); if(!retInit){ retBase=ids; retInit=true; return; } var ding=false; list.forEach(function(v){ var id=parseInt(v.id||0,10); if(!retBase.has(id)){ ding=true; var name=String(v.model_name||''); var sn=String(v.qr_serial_no||''); var loc=String(v.location||''); showToast('User returned '+(name?name+' ':'')+(sn?('['+sn+']'):'')+(loc?(' @ '+loc):'')); } }); if(ding){ try{ playBeep(); }catch(_){ } } retBase=ids; })
                .catch(function(){})
                .finally(function(){ retFetching=false; });
            }
            pollUserReturns(); setInterval(function(){ if (document.visibilityState==='visible') pollUserReturns(); }, 2000);
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
            const accountsSearch = document.getElementById('accountsSearch');
            const accountsTable = document.getElementById('accountsTable');
            if (accountsSearch && accountsTable) {
              const tbody = accountsTable.querySelector('tbody');
              const rows = tbody ? Array.from(tbody.querySelectorAll('tr[data-account-row="1"]')) : [];
              accountsSearch.addEventListener('input', function() {
                const q = this.value.toLowerCase().trim();
                rows.forEach(function(row) {
                  const cells = row.querySelectorAll('td');
                  let haystack = '';
                  if (cells[0]) haystack += (cells[0].textContent || '');
                  if (cells[1]) haystack += ' ' + (cells[1].textContent || '');
                  if (cells[2]) haystack += ' ' + (cells[2].textContent || '');
                  haystack = haystack.toLowerCase();
                  if (!q) {
                    row.classList.remove('d-none');
                  } else if (haystack.indexOf(q) !== -1) {
                    row.classList.remove('d-none');
                  } else {
                    row.classList.add('d-none');
                  }
                });
              });
            }

            const verifSearch = document.getElementById('verificationSearch');
            const verifTable = document.getElementById('verificationTable');
            if (verifSearch && verifTable) {
              const vtbody = verifTable.querySelector('tbody');
              const vrows = vtbody ? Array.from(vtbody.querySelectorAll('tr[data-verif-row="1"]')) : [];
              verifSearch.addEventListener('input', function() {
                const q = this.value.toLowerCase().trim();
                vrows.forEach(function(row) {
                  const full = (row.getAttribute('data-fullname') || '').toLowerCase();
                  const sid = (row.getAttribute('data-school-id') || '').toLowerCase();
                  let show = true;
                  if (q) {
                    if (full.indexOf(q) === -1 && sid.indexOf(q) === -1) {
                      show = false;
                    }
                  }
                  row.classList.toggle('d-none', !show);
                });
              });
            }

            // User Borrowing Records: search + filter (full name/username, type, status)
            const statsTable = document.getElementById('userStatsTable');
            const statsSearch = document.getElementById('statsSearch');
            const statsUserTypeFilter = document.getElementById('statsUserTypeFilter');
            const statsStatusFilter = document.getElementById('statsStatusFilter');
            if (statsTable) {
              const tbody = statsTable.querySelector('tbody');
              const rows = tbody ? Array.from(tbody.querySelectorAll('tr[data-stats-row="1"]')) : [];
              function applyStatsFilters() {
                const q = (statsSearch && statsSearch.value ? statsSearch.value.toLowerCase().trim() : '');
                const typeVal = (statsUserTypeFilter && statsUserTypeFilter.value) ? statsUserTypeFilter.value : 'all';
                const statusVal = (statsStatusFilter && statsStatusFilter.value) ? statsStatusFilter.value : 'all';
                rows.forEach(function(row) {
                  let show = true;
                  const full = (row.getAttribute('data-fullname') || '').toLowerCase();
                  const uname = (row.getAttribute('data-username') || '').toLowerCase();
                  if (q) {
                    if (full.indexOf(q) === -1 && uname.indexOf(q) === -1) {
                      show = false;
                    }
                  }
                  if (show && typeVal && typeVal !== 'all') {
                    const ut = (row.getAttribute('data-user-type') || '');
                    if (ut.toLowerCase() !== typeVal.toLowerCase()) {
                      show = false;
                    }
                  }
                  if (show && statusVal && statusVal !== 'all') {
                    const over = parseInt(row.getAttribute('data-overdue') || '0', 10) || 0;
                    const lost = parseInt(row.getAttribute('data-lost') || '0', 10) || 0;
                    const dam  = parseInt(row.getAttribute('data-damaged') || '0', 10) || 0;
                    switch (statusVal) {
                      case 'clean':
                        if (over > 0 || lost > 0 || dam > 0) { show = false; }
                        break;
                      case 'lost':
                        if (lost <= 0) { show = false; }
                        break;
                      case 'damaged':
                        if (dam <= 0) { show = false; }
                        break;
                      case 'overdue_only':
                        if (!(over > 0 && lost === 0 && dam === 0)) { show = false; }
                        break;
                    }
                  }
                  row.classList.toggle('d-none', !show);
                });
              }
              if (statsSearch) statsSearch.addEventListener('input', applyStatsFilters);
              if (statsUserTypeFilter) statsUserTypeFilter.addEventListener('change', applyStatsFilters);
              if (statsStatusFilter) statsStatusFilter.addEventListener('change', applyStatsFilters);
            }

            const disableModalEl = document.getElementById('accountDisableModal');
            const deleteModalEl = document.getElementById('accountDeleteModal');
            const accountsTbody = document.querySelector('#accountsTable tbody');
            let pendingDisableForm = null;
            let pendingDeleteForm = null;
            if (disableModalEl && deleteModalEl && accountsTbody) {
              const disableModal = new bootstrap.Modal(disableModalEl);
              const deleteModal = new bootstrap.Modal(deleteModalEl);
              const disableUserSpan = document.getElementById('accountDisableUsername');
              const disableAdminPwInput = document.getElementById('accountDisableAdminPassword');
              const deleteUserSpan = document.getElementById('accountDeleteUsername');
              const deleteAdminPwInput = document.getElementById('accountDeleteAdminPassword');
              const deleteErrorText = document.getElementById('accountDeleteErrorText');
              const disableBodyMain = disableModalEl.querySelector('.account-disable-body-main');
              const disableConfirmBtn = document.getElementById('accountDisableConfirmBtn');
              const deleteConfirmBtn = document.getElementById('accountDeleteConfirmBtn');

              accountsTbody.addEventListener('click', function(e) {
                const disableBtn = e.target.closest('.account-disable-btn');
                const deleteBtn = e.target.closest('.account-delete-btn');
                if (disableBtn) {
                  e.preventDefault();
                  const form = disableBtn.closest('form.account-toggle-form');
                  if (!form) return;
                  pendingDisableForm = form;
                  const uname = disableBtn.getAttribute('data-username') || '';
                  const mode = disableBtn.getAttribute('data-mode') || 'disable';
                  if (disableUserSpan) disableUserSpan.textContent = uname;
                  if (disableBodyMain) {
                    if (mode === 'enable') {
                      disableBodyMain.textContent = 'Are you sure you want to enable this account? The user will be able to log in again.';
                    } else {
                      disableBodyMain.textContent = 'Are you sure you want to disable this account? The user will be blocked from logging in.';
                    }
                  }
                  if (disableConfirmBtn) {
                    disableConfirmBtn.textContent = (mode === 'enable') ? 'Enable' : 'Disable';
                    disableConfirmBtn.classList.toggle('btn-warning', mode !== 'enable');
                    disableConfirmBtn.classList.toggle('btn-success', mode === 'enable');
                  }
                  if (disableAdminPwInput) {
                    disableAdminPwInput.value = '';
                  }
                  disableModal.show();
                  return;
                }
                if (deleteBtn) {
                  e.preventDefault();
                  const form = deleteBtn.closest('form.account-delete-form');
                  if (!form) return;
                  pendingDeleteForm = form;
                  const uname = deleteBtn.getAttribute('data-username') || '';
                  if (deleteUserSpan) deleteUserSpan.textContent = uname;
                  if (deleteAdminPwInput) {
                    deleteAdminPwInput.value = '';
                  }
                  if (deleteConfirmBtn) {
                    deleteConfirmBtn.disabled = true;
                  }
                  if (deleteErrorText) {
                    deleteErrorText.classList.add('d-none');
                  }
                  deleteModal.show();
                  return;
                }
              });

              if (deleteAdminPwInput && deleteConfirmBtn) {
                deleteAdminPwInput.addEventListener('input', function() {
                  const hasVal = this.value.trim().length > 0;
                  deleteConfirmBtn.disabled = !hasVal;
                  if (deleteErrorText) {
                    deleteErrorText.classList.add('d-none');
                  }
                });
              }

              if (disableConfirmBtn) {
                disableConfirmBtn.addEventListener('click', function() {
                  if (pendingDisableForm) {
                    if (disableAdminPwInput) {
                      let hidden = pendingDisableForm.querySelector('input[name="admin_password"]');
                      if (!hidden) {
                        hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'admin_password';
                        pendingDisableForm.appendChild(hidden);
                      }
                      hidden.value = disableAdminPwInput.value || '';
                    }
                    pendingDisableForm.submit();
                    pendingDisableForm = null;
                  }
                });
              }
              if (deleteConfirmBtn) {
                deleteConfirmBtn.addEventListener('click', function() {
                  if (pendingDeleteForm) {
                    if (deleteAdminPwInput) {
                      let hidden = pendingDeleteForm.querySelector('input[name="admin_password"]');
                      if (!hidden) {
                        hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'admin_password';
                        pendingDeleteForm.appendChild(hidden);
                      }
                      hidden.value = deleteAdminPwInput.value || '';
                    }
                    pendingDeleteForm.submit();
                    pendingDeleteForm = null;
                  }
                });
              }
            }

            const verifModalEl = document.getElementById('verificationDecisionModal');
            const verifTbody = document.querySelector('#verificationTable tbody');
            let pendingVerifForm = null;
            if (verifModalEl && verifTbody) {
              const verifModal = new bootstrap.Modal(verifModalEl);
              const verifUserSpan = document.getElementById('verificationUsername');
              const verifActionSpan = document.getElementById('verificationAction');
              const verifAdminPwInput = document.getElementById('verificationAdminPassword');
              const verifConfirmBtn = document.getElementById('verificationConfirmBtn');
              const verifErrorText = document.getElementById('verificationErrorText');

              verifTbody.addEventListener('click', function(e) {
                const btn = e.target.closest('.verification-action-btn');
                if (!btn) return;
                e.preventDefault();
                const form = btn.closest('form.verification-action-form');
                if (!form) return;
                pendingVerifForm = form;
                const uname = btn.getAttribute('data-username') || '';
                const mode = btn.getAttribute('data-mode') || 'verify';
                if (verifUserSpan) verifUserSpan.textContent = uname;
                if (verifActionSpan) verifActionSpan.textContent = (mode === 'reject') ? 'Reject' : 'Verify';
                if (verifAdminPwInput) verifAdminPwInput.value = '';
                if (verifConfirmBtn) {
                  verifConfirmBtn.textContent = (mode === 'reject') ? 'Reject' : 'Verify';
                  verifConfirmBtn.classList.toggle('btn-danger', mode === 'reject');
                  verifConfirmBtn.classList.toggle('btn-success', mode !== 'reject');
                  verifConfirmBtn.disabled = true;
                }
                if (verifErrorText) verifErrorText.classList.add('d-none');
                verifModal.show();
              });

              if (verifAdminPwInput && verifConfirmBtn) {
                verifAdminPwInput.addEventListener('input', function() {
                  const hasVal = this.value.trim().length > 0;
                  verifConfirmBtn.disabled = !hasVal;
                  if (verifErrorText) verifErrorText.classList.add('d-none');
                });
              }

              if (verifConfirmBtn) {
                verifConfirmBtn.addEventListener('click', function() {
                  if (pendingVerifForm) {
                    if (verifAdminPwInput) {
                      let hidden = pendingVerifForm.querySelector('input[name="admin_password"]');
                      if (!hidden) {
                        hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'admin_password';
                        pendingVerifForm.appendChild(hidden);
                      }
                      hidden.value = verifAdminPwInput.value || '';
                    }
                    pendingVerifForm.submit();
                    pendingVerifForm = null;
                  }
                });
              }
            }

            // Borrowing Agreement edit modal formatting buttons (WYSIWYG)
            (function(){
              var form = document.getElementById('editBorrowAgreementForm');
              if (!form) return;
              var editor = document.getElementById('borrowAgreementEditor');
              var hidden = document.getElementById('borrowAgreementTextarea');
              var saveBtn = document.getElementById('saveBorrowAgreementBtn');
              var resetBtn = document.getElementById('resetBorrowAgreementBtn');
              if (!editor || !hidden) return;
              var buttons = document.querySelectorAll('.ba-format-btn');
              var originalHtml = (editor.innerHTML || '').trim();

              function normalizeHtml(html) {
                return (html || '').replace(/\s+/g, ' ').trim();
              }

              function updateSaveState() {
                if (!saveBtn) return;
                var current = normalizeHtml(editor.innerHTML);
                var orig = normalizeHtml(originalHtml);
                var dirty = current !== orig;
                saveBtn.disabled = !dirty;
              }

              function isSelectionInsideEditor() {
                if (!editor) return false;
                var sel = window.getSelection ? window.getSelection() : null;
                if (!sel || sel.rangeCount === 0) return false;
                var range = sel.getRangeAt(0);
                var node = range.commonAncestorContainer;
                if (!node) return false;
                if (node.nodeType === 3) {
                  node = node.parentNode;
                }
                return editor.contains(node);
              }

              function updateToolbarActiveState() {
                if (!buttons || !buttons.length) return;
                var inside = isSelectionInsideEditor();
                buttons.forEach(function(btn){
                  var tag = btn.getAttribute('data-tag') || '';
                  var active = false;
                  if (inside && tag) {
                    try {
                      if (tag === 'b') {
                        active = document.queryCommandState && document.queryCommandState('bold');
                      } else if (tag === 'i') {
                        active = document.queryCommandState && document.queryCommandState('italic');
                      } else if (tag === 'u') {
                        active = document.queryCommandState && document.queryCommandState('underline');
                      } else if (tag === 'ul') {
                        active = document.queryCommandState && document.queryCommandState('insertUnorderedList');
                      } else if (tag === 'center') {
                        active = document.queryCommandState && document.queryCommandState('justifyCenter');
                      } else if (tag === 'justify') {
                        active = document.queryCommandState && document.queryCommandState('justifyFull');
                      }
                    } catch (e) {
                      active = false;
                    }
                  }
                  if (active) {
                    btn.classList.add('btn-primary');
                    btn.classList.remove('btn-outline-secondary');
                  } else {
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-outline-secondary');
                  }
                });
              }

              function applyCommand(tag){
                if (!editor) return;
                editor.focus();
                if (tag === 'b') {
                  document.execCommand('bold', false, null);
                } else if (tag === 'i') {
                  document.execCommand('italic', false, null);
                } else if (tag === 'u') {
                  document.execCommand('underline', false, null);
                } else if (tag === 'ul') {
                  document.execCommand('insertUnorderedList', false, null);
                } else if (tag === 'center') {
                  document.execCommand('justifyCenter', false, null);
                } else if (tag === 'justify') {
                  document.execCommand('justifyFull', false, null);
                }
                updateSaveState();
                updateToolbarActiveState();
              }

              buttons.forEach(function(btn){
                btn.addEventListener('click', function(e){
                  e.preventDefault();
                  var tag = this.getAttribute('data-tag') || '';
                  if (!tag) return;
                  applyCommand(tag);
                });
              });

              editor.addEventListener('input', function(){
                updateSaveState();
                updateToolbarActiveState();
              });
              editor.addEventListener('keyup', updateToolbarActiveState);
              editor.addEventListener('mouseup', updateToolbarActiveState);
              document.addEventListener('selectionchange', updateToolbarActiveState);
              updateSaveState();
              updateToolbarActiveState();

              if (resetBtn) {
                resetBtn.addEventListener('click', function(e){
                  e.preventDefault();
                  if (!window.confirm('Reset the policy text back to the last saved version? Unsaved changes will be lost.')) {
                    return;
                  }
                  editor.innerHTML = originalHtml;
                  updateSaveState();
                  updateToolbarActiveState();
                });
              }

              // On submit, copy editor HTML into the hidden textarea
              form.addEventListener('submit', function(){
                if (hidden && editor) {
                  hidden.value = editor.innerHTML;
                }
              });
            })();

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
<script src="page-transitions.js?v=<?php echo filemtime(__DIR__.'/page-transitions.js'); ?>"></script>
