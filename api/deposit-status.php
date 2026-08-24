<?php
/**
 * JSON status for pending deposit (polling from Add Funds page).
 */
require_once __DIR__ . '/../app/init.php';
header('Content-Type: application/json; charset=utf-8');

if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = $auth->getCurrentUser();
$db = Database::getInstance();

$pending = $db->fetch(
    "SELECT id, amount, description, reference, status, created_at
     FROM transactions
     WHERE user_id = ? AND type = 'deposit' AND status = 'pending'
     ORDER BY id DESC LIMIT 1",
    [(int) $user['id']]
);

$balanceRow = $db->fetch("SELECT balance FROM users WHERE id = ?", [(int) $user['id']]);
$balance = $balanceRow ? (float) $balanceRow['balance'] : 0.0;

if (!$pending) {
    echo json_encode([
        'ok' => true,
        'has_pending' => false,
        'balance' => $balance,
        'status' => 'none',
        'message' => 'No pending deposit.',
    ]);
    exit;
}

$walletCatalog = DepositAutoConfirm::buildWalletCatalog($db);
$auto = new DepositAutoConfirm();
$check = $auto->processTransaction($pending, $walletCatalog);

$pending = $db->fetch(
    "SELECT id, amount, description, reference, status, created_at
     FROM transactions WHERE id = ?",
    [(int) $pending['id']]
);
$balanceRow = $db->fetch("SELECT balance FROM users WHERE id = ?", [(int) $user['id']]);
$balance = $balanceRow ? (float) $balanceRow['balance'] : 0.0;

$completed = $pending && ($pending['status'] ?? '') === 'completed';

echo json_encode([
    'ok' => true,
    'has_pending' => !$completed,
    'deposit_id' => (int) $pending['id'],
    'amount' => (float) $pending['amount'],
    'reference' => $pending['reference'] ?? '',
    'balance' => $balance,
    'status' => $completed ? 'confirmed' : ($check['status'] ?? 'pending'),
    'message' => $completed ? 'Payment confirmed! Your balance is ready.' : ($check['message'] ?? 'Verifying…'),
    'approved' => $completed,
]);
