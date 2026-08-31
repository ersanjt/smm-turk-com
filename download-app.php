<?php
/**
 * Direct mobile-app download (Android APK / optional iOS IPA).
 */
require_once __DIR__ . '/app/init.php';

$platform = strtolower(trim((string)($_GET['platform'] ?? 'android')));
$root = defined('ROOT_PATH') ? ROOT_PATH : __DIR__;

if ($platform === 'ios') {
    $file = $root . '/downloads/smm-turk-ios.ipa';
    if (!is_file($file)) {
        header('Location: ' . path('m.php'));
        exit;
    }
    $name = 'smm-turk-ios.ipa';
    $mime = 'application/octet-stream';
} else {
    $file = $root . '/downloads/smm-turk-android.apk';
    $name = 'smm-turk-android.apk';
    $mime = 'application/vnd.android.package-archive';
}

if (!is_file($file) || !is_readable($file)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    $siteName = function_exists('site_name') ? site_name() : 'SMM Turk';
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($siteName) . '</title></head><body style="font-family:sans-serif;padding:32px;text-align:center">';
    echo '<h1>Download not ready yet</h1><p>The Android package will appear here after the next build. You can use the mobile web app meanwhile.</p>';
    echo '<p><a href="' . h(path('m.php')) . '">Open mobile app</a> · <a href="' . h(path('get-app.php')) . '">Back</a></p></body></html>';
    exit;
}

$size = filesize($file);
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)$size);
header('Content-Disposition: attachment; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($file);
exit;
