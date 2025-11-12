<?php
session_start();
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Missing or invalid id']);
    exit();
}

// Try MongoDB first
$item = null;
try {
    @require_once __DIR__ . '/../vendor/autoload.php';
    @require_once __DIR__ . '/db/mongo.php';
    $db = get_mongo_db();
    $doc = $db->selectCollection('inventory_items')->findOne(['id' => $id], [
        'projection' => ['item_name'=>1,'model'=>1,'category'=>1,'location'=>1,'status'=>1,'serial_no'=>1]
    ]);
    if ($doc) {
        $item = [
            'item_name' => (string)($doc['item_name'] ?? ''),
            'model'     => (string)($doc['model'] ?? ''),
            'category'  => (string)($doc['category'] ?? ''),
            'location'  => (string)($doc['location'] ?? ''),
            'status'    => (string)($doc['status'] ?? 'Available'),
            'serial_no' => (string)($doc['serial_no'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    // fall through to MySQL
}

// Fallback to MySQL if Mongo not available or item not found
if ($item === null) {
    $conn = @new mysqli('localhost', 'root', '', 'inventory_system');
    if (!$conn->connect_error) {
        if ($stmt = $conn->prepare("SELECT item_name, model, category, location, status, serial_no FROM inventory_items WHERE id = ?")) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $item = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }
        $conn->close();
    }
}

if (!$item) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Item not found']);
    exit();
}

$serialOnly = trim((string)($item['serial_no'] ?? ''));
if ($serialOnly === '') { $serialOnly = (string)$id; }
$payload = $serialOnly;

// Cache the generated QR locally to avoid repeated network calls and allow offline reuse
$cacheDir = __DIR__ . '/qr_cache';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
// Use a stable filename based on the item id and payload signature
$sig = substr(sha1($payload), 0, 16);
$basePath = $cacheDir . '/qr_item_' . $id . '_' . $sig . '.png';

if (is_file($basePath)) {
    $img = @file_get_contents($basePath);
} else {
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&margin=2&format=png&data=' . urlencode($payload);
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $img = @file_get_contents($qrUrl, false, $context);
    if ($img !== false) { @file_put_contents($basePath, $img); }
}

if ($img === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to generate QR']);
    exit();
}

$withLabel = isset($_GET['label']) && $_GET['label'] == '1';
if ($withLabel && function_exists('imagecreatefromstring')) {
    $qrImage = @imagecreatefromstring($img);
    if ($qrImage !== false) {
        $qrW = imagesx($qrImage);
        $qrH = imagesy($qrImage);
        $padding = 20;
        $text = trim(($item['item_name'] ?? ''));
        if ($text === '') { $text = 'QR Code'; }
        $font = 5;
        $charW = imagefontwidth($font);
        $charH = imagefontheight($font);
        $maxTextWidth = $qrW - 40;
        $wrapped = [];
        $words = preg_split('/\s+/', $text);
        $line = '';
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if (strlen($try) * $charW <= $maxTextWidth) {
                $line = $try;
            } else {
                if ($line !== '') { $wrapped[] = $line; }
                $line = $word;
            }
        }
        if ($line !== '') { $wrapped[] = $line; }
        $labelHeight = $padding + (max(1, count($wrapped)) * $charH) + $padding;

        $out = imagecreatetruecolor($qrW, $qrH + $labelHeight);
        $white = imagecolorallocate($out, 255, 255, 255);
        $black = imagecolorallocate($out, 0, 0, 0);
        imagefilledrectangle($out, 0, 0, $qrW, $qrH + $labelHeight, $white);
        imagecopy($out, $qrImage, 0, 0, 0, 0, $qrW, $qrH);

        $y = $qrH + $padding;
        foreach ($wrapped as $ln) {
            $textWidth = strlen($ln) * $charW;
            $x = (int)(($qrW - $textWidth) / 2);
            imagestring($out, $font, $x, $y, $ln, $black);
            $y += $charH;
        }

        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        imagepng($out);
        imagedestroy($out);
        imagedestroy($qrImage);
        exit();
    }
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $img;
