<?php
/**
 * Referral redirect: /c/CODE -> register with ref. Tracks visit for affiliate stats.
 */
require_once __DIR__ . '/app/init.php';

$ref = trim($_GET['ref'] ?? '');
$loginUrl = url('login.php');

if ($ref === '') {
    header('Location: ' . $loginUrl);
    exit;
}

$referrer = $db->fetch("SELECT id FROM users WHERE referral_code = ? AND status = 'active'", [$ref]);
if ($referrer) {
    try {
        $db->insert("INSERT INTO referral_visits (referrer_id) VALUES (?)", [$referrer['id']]);
    } catch (Throwable $e) {
        /* table may not exist */
    }
}

header('Location: ' . page_url('login.php', ['mode' => 'register', 'ref' => $ref]));
exit;
