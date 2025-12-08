<?php
// Shared helper for auth-related checks (e.g., disabled accounts)
if (!function_exists('inventory_redirect_if_disabled')) {
    function inventory_redirect_if_disabled(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Must be called only after session_start(); fail open if not.
            return;
        }
        $uname = (string)($_SESSION['username'] ?? '');
        if ($uname === '') {
            return;
        }
        $role = strtolower((string)($_SESSION['usertype'] ?? ''));
        if ($role === 'admin') {
            return;
        }
        $isDisabled = false;
        try {
            require_once __DIR__ . '/db/mongo.php';
            $db = get_mongo_db();
            $users = $db->selectCollection('users');
            $doc = $users->findOne(['username' => $uname], ['projection' => ['disabled' => 1]]);
            $isDisabled = $doc && !empty($doc['disabled']);
        } catch (\Throwable $e) {
            // On DB error, do not block the user; just log and continue.
            try { error_log('[auth] disabled-check failed: ' . $e->getMessage()); } catch (\Throwable $_) {}
        }
        if ($isDisabled) {
            // Clear session and force re-login with disabled message.
            header('Location: logout.php?disabled=1');
            exit();
        }
    }
}
