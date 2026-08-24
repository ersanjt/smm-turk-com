<?php
/**
 * Cron: auto-verify pending crypto deposits on-chain and credit balance.
 * cPanel Cron (every 2 min):
 *   php /home/smmturk/public_html/cron-verify-deposits.php
 */
require_once __DIR__ . '/app/init.php';
require_cli_or_cron_token('cron-verify-deposits');

$auto = new DepositAutoConfirm();
$approved = $auto->processAllPending(50);

if (php_sapi_name() === 'cli') {
    echo date('Y-m-d H:i:s') . " - Auto-approved $approved deposit(s)\n";
}
