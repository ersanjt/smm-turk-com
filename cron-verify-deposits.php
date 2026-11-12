<?php
/**
 * Cron: auto-verify pending crypto deposits on-chain and credit balance.
 * cPanel Cron (every 2 min):
 *   php /home/smmturk/public_html/cron-verify-deposits.php
 */
require_once __DIR__ . '/app/init.php';
require_cli_or_cron_token('cron-verify-deposits');

$deposits = new DepositManager();
$reminded = method_exists($deposits, 'remindUnpaidCheckouts') ? $deposits->remindUnpaidCheckouts(2) : 0;
$expired = method_exists($deposits, 'expireAbandonedCheckouts') ? $deposits->expireAbandonedCheckouts(24) : 0;
$auto = new DepositAutoConfirm();
$approved = $auto->processAllPending(50);

if (php_sapi_name() === 'cli') {
    echo date('Y-m-d H:i:s') . " - Reminded $reminded unpaid checkout(s); expired $expired; auto-approved $approved deposit(s)\n";
}
