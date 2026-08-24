<?php
/**
 * Payment IPN / webhook (server-to-server).
 */
require_once __DIR__ . '/app/init.php';

$gateway = strtolower(trim($_GET['gateway'] ?? $_POST['gateway'] ?? ''));
$defs = PaymentRegistry::definitions();

if (!isset($defs[$gateway])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unknown gateway']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$params = $_POST;
if ($params === [] && $rawBody !== '') {
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        $params = $json;
    }
}

$processor = new PaymentProcessor();
$result = $processor->handleCallback($gateway, $params, $rawBody);

header('Content-Type: application/json');
if ($result['success']) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => $result['message'] ?? 'OK']);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Failed']);
}
