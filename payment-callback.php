<?php
/**
 * Payment return URL (user redirect after paying).
 * Credits only ZarinPal here — it verifies with the merchant API.
 * Other gateways credit exclusively via server-to-server payment-webhook.php.
 */
require_once __DIR__ . '/app/init.php';

$gateway = strtolower(trim($_GET['gateway'] ?? ''));
$defs = PaymentRegistry::definitions();

if (!isset($defs[$gateway])) {
    http_response_code(404);
    echo 'Unknown gateway.';
    exit;
}

$loggedIn = !empty($_SESSION['user_id']);

if ($gateway === PaymentRegistry::ZARINPAL) {
    $processor = new PaymentProcessor();
    $result = $processor->handleCallback($gateway, $_GET);
    if ($result['credited'] ?? false) {
        if ($loggedIn) {
            flash('success', $result['message'] ?? 'Payment confirmed! Your balance has been credited.');
        }
        redirect(page_url('add-funds.php', ['tab' => 'history']));
    }
    if ($loggedIn) {
        flash('error', $result['error'] ?? 'Payment could not be verified. Contact support with your deposit ID.');
    }
    redirect(url('add-funds.php'));
}

if ($loggedIn) {
    $status = strtolower((string) ($_GET['status'] ?? ''));
    if (in_array($status, ['success', 'paid', 'ok'], true)) {
        flash('success', 'Payment received! Your balance will update shortly after confirmation.');
        redirect(page_url('add-funds.php', ['tab' => 'history']));
    }
    flash('info', 'Payment submitted. We will credit your balance after confirmation.');
}
redirect(url('add-funds.php'));
