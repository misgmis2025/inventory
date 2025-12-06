<?php

function fcm_get_server_key(): string {
    $env = getenv('FCM_SERVER_KEY');
    if ($env !== false && trim($env) !== '') {
        return trim($env);
    }
    if (defined('FCM_SERVER_KEY') && FCM_SERVER_KEY) {
        return (string)FCM_SERVER_KEY;
    }
    return '';
}

function fcm_full_url(string $path): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if ($path === '') { $path = '/'; }
    elseif ($path[0] !== '/') { $path = '/' . $path; }
    return $scheme . '://' . $host . $path;
}

function fcm_send_to_user_tokens($mongo_db, string $username, string $title, string $body, string $targetUrl = '', array $extraData = []): bool {
    if (!$mongo_db || $username === '' || $title === '' || $body === '') {
        return false;
    }
    $key = fcm_get_server_key();
    if ($key === '') {
        return false;
    }
    try {
        $col = $mongo_db->selectCollection('user_device_tokens');
        $cur = $col->find(['username' => $username], ['projection' => ['token' => 1]]);
        $tokens = [];
        foreach ($cur as $doc) {
            $t = (string)($doc['token'] ?? '');
            if ($t !== '') {
                $tokens[] = $t;
            }
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
    $payload = [
        'registration_ids' => $tokens,
        'priority' => 'high',
        'notification' => [
            'title' => $title,
            'body' => $body,
        ],
        'data' => $data,
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://fcm.googleapis.com/fcm/send',
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: key=' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false || $code < 200 || $code >= 300) {
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return true;
}
