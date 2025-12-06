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
        return null;
    }
    $cfg = json_decode($json, true);
    if (!is_array($cfg)) {
        return null;
    }
    foreach (['project_id','client_email','private_key'] as $k) {
        if (empty($cfg[$k]) || !is_string($cfg[$k])) {
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
        return null;
    }
    $sig = '';
    if (!openssl_sign($toSign, $sig, $key, 'sha256')) {
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
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['access_token'])) {
        return null;
    }
    $cached = (string)$data['access_token'];
    $expAt = isset($data['expires_in']) ? ($now + (int)$data['expires_in']) : ($now + 3000);
    return $cached;
}

function fcm_send_to_user_tokens($mongo_db, string $username, string $title, string $body, string $targetUrl = '', array $extraData = []): bool {
    if (!$mongo_db || $username === '' || $title === '' || $body === '') {
        return false;
    }
    $sa = fcm_get_service_account();
    if (!$sa) {
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
            return false;
        }
    } catch (Throwable $e) {
        return false;
    }

    $data = $extraData;
    if ($targetUrl !== '') {
        $data['target_url'] = $targetUrl;
    }

    $token = fcm_get_access_token($sa);
    if (!$token) {
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
        curl_close($ch);
        if ($resp !== false && $code >= 200 && $code < 300) {
            $okAny = true;
        }
    }
    return $okAny;
}
