<?php
/**
 * Cron: DNS verification and retry stuck child panel provisioning.
 * Schedule: every 5 minutes — php /home/smmturk/public_html/cron-child-panels.php
 */
require_once __DIR__ . '/app/init.php';
require_cli_or_cron_token('cron-child-panels');

$cpm = new ChildPanelManager();
$auto = new ChildPanelAutomation();
$result = $auto->run();

if (php_sapi_name() === 'cli') {
    echo date('Y-m-d H:i:s') . ' - ' . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
}
