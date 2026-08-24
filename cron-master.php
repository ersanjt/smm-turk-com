<?php
/**
 * Master 24/7 automation — parent + all child panels.
 *
 * cPanel cron (every 5 minutes):
 *   php /home/smmturk/public_html/cron-master.php
 *
 * Or HTTP (if CLI disabled):
 *   curl -fsS "https://smm-turk.com/cron-master.php?token=TOKEN"
 *   TOKEN = hash_hmac('sha256', 'cron-master', CRON_SECRET) from config.php
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/ChildPanelAutomation.php';
require_cli_or_cron_token('cron-master');

$auto = new ChildPanelAutomation();
$stats = $auto->run();

if (php_sapi_name() === 'cli') {
    echo date('Y-m-d H:i:s') . ' automation ' . json_encode($stats, JSON_UNESCAPED_SLASHES) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'stats' => $stats]);
}
