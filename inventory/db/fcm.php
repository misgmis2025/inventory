<?php

// FCM HTTP v1 helper using a service account JSON.
// Service account JSON should be stored in environment variable FCM_SERVICE_ACCOUNT_JSON
// (or as a PHP constant FCM_SERVICE_ACCOUNT_JSON).

function fcm_full_url(string $path): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if ($path === '') { $path = '/'; }
    elseif ($path[0] !== '/') { $path = '/' . $path; }
    return $scheme . '://' . $host . $path;
}

function fcm_base64url_encode(string $in): string {
    return rtrim(strtr(base64_encode($in), '+/', '-_'), '=');
}

function fcm_get_service_account(): ?array {
    $json = getenv('FCM_SERVICE_ACCOUNT_JSON');
    if (($json === false || trim($json) === '') && defined('FCM_SERVICE_ACCOUNT_JSON')) {
        $json = (string)FCM_SERVICE_ACCOUNT_JSON;
    }
    if ($json === false || trim($json) === '') {
        error_log('FCM: service account JSON missing');
        return null;
    }
    $cfg = json_decode($json, true);
    if (!is_array($cfg)) {
        error_log('FCM: service account JSON decode failed');
        return null;
    }
    foreach (['project_id','client_email','private_key'] as $k) {
        if (empty($cfg[$k]) || !is_string($cfg[$k])) {
            error_log('FCM: service account missing key ' . $k);
            return null;
        }
    }
    return $cfg;
}

function fcm_get_access_token(array $sa): ?string {
    static $cached = null;
    static $expAt = 0;
    $now = time();
    if (is_string($cached) && $cached !== '' && $expAt - 60 > $now) {
        return $cached;
    }
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];
    $jwtHeader = fcm_base64url_encode(json_encode($header));
    $jwtClaims = fcm_base64url_encode(json_encode($claims));
    $toSign = $jwtHeader . '.' . $jwtClaims;
    $key = openssl_pkey_get_private($sa['private_key']);
    if ($key === false) {
        error_log('FCM: openssl_pkey_get_private failed');
        return null;
    }
    $sig = '';
    if (!openssl_sign($toSign, $sig, $key, 'sha256')) {
        error_log('FCM: openssl_sign failed');
        return null;
    }
    $jwt = $toSign . '.' . fcm_base64url_encode($sig);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://oauth2.googleapis.com/token',
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false || $code < 200 || $code >= 300) {
        $err = curl_error($ch);
        error_log('FCM: token request failed code=' . $code . ' error=' . $err . ' resp=' . substr((string)$resp, 0, 200));
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['access_token'])) {
        error_log('FCM: token response decode failed resp=' . substr((string)$resp, 0, 200));
        return null;
    }
    $cached = (string)$data['access_token'];
    $expAt = isset($data['expires_in']) ? ($now + (int)$data['expires_in']) : ($now + 3000);
    return $cached;
}

function fcm_send_to_user_tokens($mongo_db, string $username, string $title, string $body, string $targetUrl = '', array $extraData = []): bool {
    if (!$mongo_db || $username === '' || $title === '' || $body === '') {
        error_log('FCM: invalid params username=' . $username);
        return false;
    }
    $sa = fcm_get_service_account();
    if (!$sa) {
        error_log('FCM: no service account config');
        return false;
    }
    try {
        $col = $mongo_db->selectCollection('user_device_tokens');
        $cur = $col->find(['username' => $username], ['projection' => ['token' => 1]]);
        $tokens = [];
        foreach ($cur as $doc) {
            $t = (string)($doc['token'] ?? '');
            if ($t !== '') { $tokens[] = $t; }
        }
        $tokens = array_values(array_unique($tokens));
        if (empty($tokens)) {
            error_log('FCM: no tokens for username=' . $username);
            return false;
        }
    } catch (Throwable $e) {
        error_log('FCM: exception while loading tokens for username=' . $username . ' msg=' . $e->getMessage());
        return false;
    }

    $data = $extraData;
    if ($targetUrl !== '') {
        $data['target_url'] = $targetUrl;
    }

    $token = fcm_get_access_token($sa);
    if (!$token) {
        error_log('FCM: failed to obtain access token');
        return false;
    }
    $projectId = (string)$sa['project_id'];
    $endpoint = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';

    $okAny = false;
    foreach ($tokens as $tkn) {
        $payload = [
            'message' => [
                'token' => $tkn,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
            ],
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp !== false && $code >= 200 && $code < 300) {
            $okAny = true;
        } else {
            $status = '';
            $msg = '';
            if (is_string($resp) && $resp !== '') {
                $j = json_decode($resp, true);
                if (is_array($j) && isset($j['error']) && is_array($j['error'])) {
                    $status = isset($j['error']['status']) ? (string)$j['error']['status'] : '';
                    $msg = isset($j['error']['message']) ? (string)$j['error']['message'] : '';
                }
            }
            error_log('FCM: send failed username=' . $username . ' code=' . $code . ' status=' . $status . ' message=' . $msg);
        }
    }
    return $okAny;
}
