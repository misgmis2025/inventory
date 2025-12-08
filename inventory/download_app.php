<?php
// Force download of the MISGMIS Android APK with correct headers
$appPath = __DIR__ . '/app/MISGMIS.apk';
if (!is_file($appPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'APK not found. Please contact MIS.';
    exit;
}

$filesize = filesize($appPath);
header('Content-Description: File Transfer');
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="MISGMIS.apk"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . $filesize);
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Clean output buffers to avoid corrupting the binary
while (ob_get_level() > 0) {
    ob_end_clean();
}

$fp = fopen($appPath, 'rb');
if ($fp === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Failed to open APK.';
    exit;
}

fpassthru($fp);
fclose($fp);
exit;
