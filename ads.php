<?php
/**
 * /ads.txt — valid empty file until a real AdSense publisher ID is enabled.
 */
require_once __DIR__ . '/app/init.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$line = "# No authorized digital sellers\n";
try {
    $db = Database::getInstance();
    $raw = trim((string) ($db->getSetting('adsense_publisher_id') ?: ''));
    $enabled = (string) ($db->getSetting('adsense_enabled') ?: '0');
    if ($enabled === '1' && preg_match('/^ca-pub-(\d{10,20})$/', $raw, $match)) {
        $line = 'google.com, pub-' . $match[1] . ", DIRECT, f08c47fec0942fa0\n";
    }
} catch (Throwable $e) {
    $line = "# No authorized digital sellers\n";
}

echo $line;
