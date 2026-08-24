<?php
/**
 * Payment return URL (user redirect after paying).
 */
require_once __DIR__ . '/app/init.php';

$gateway = strtolower(trim($_GET['gateway'] ?? ''));
$defs = PaymentRegistry::definitions();

if (!isset($defs[$gateway])) {
    http_response_code(404);
    echo 'Unknown gateway.';
    exit;
}

$processor = new PaymentProcessor();
$result = $processor->handleCallback($gateway, $_GET);

if ($gateway === PaymentRegistry::HELEKET || $gateway === PaymentRegistry::SMMPAYGATE || $gateway === PaymentRegistry::BINANCE_PAY) {
    $status = $_GET['status'] ?? '';
    if ($status === 'success' || ($result['credited'] ?? false)) {
        if (!empty($_SESSION['user_id'])) {
            flash('success', $result['message'] ?? 'Payment received! Your balance will update shortly.');
        }
        redirect(page_url('add-funds.php', ['tab' => 'history']));
    }
    if (!empty($_SESSION['user_id'])) {
        flash('info', 'Payment submitted. We will credit your balance after confirmation.');
    }
    redirect(url('add-funds.php'));
}

if ($result['credited'] ?? false) {
    if (!empty($_SESSION['user_id'])) {
        flash('success', $result['message'] ?? 'Payment confirmed! Your balance has been credited.');
    }
    redirect(page_url('add-funds.php', ['tab' => 'history']));
}

if (!empty($_SESSION['user_id'])) {
    flash('error', $result['error'] ?? 'Payment could not be verified. Contact support with your deposit ID.');
}
redirect(url('add-funds.php'));
