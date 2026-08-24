<?php
/**
 * Cron job: Sync order statuses from SmmFollows
 * Add to crontab: every 5 min -> php /path/to/cron-sync.php
 */
require_once __DIR__ . '/app/init.php';
require_cli_or_cron_token('cron-sync');

$om = new OrderManager();
$updated = $om->syncOrders();

if (php_sapi_name() === 'cli') {
    echo date('Y-m-d H:i:s') . " - Updated $updated orders\n";
}
