<?php
/**
 * Admin bootstrap — require instead of duplicating init + requireAdmin in each file.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$auth->requireAdmin();
require_once __DIR__ . '/../app/DepositCampaign.php';
$depositCampaign = DepositCampaign::run(5);
if (!empty($depositCampaign['cancelled'])) {
    flash('success', 'Cancelled ' . (int) $depositCampaign['cancelled'] . ' unfinished order(s) and returned the charges.');
}
