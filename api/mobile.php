<?php
/**
 * Mobile app JSON API.
 * POST https://smm-turk.com/api/mobile
 *
 * Public: config, login, register, login_2fa, google_start, google_finish
 * Authenticated (X-API-Key or Authorization: Bearer): me, services, orders, tickets, deposits
 */
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
header('Access-Control-Max-Age: 86400');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/RateLimit.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$jsonBody = json_decode($rawBody, true);
if (!is_array($jsonBody)) {
    $jsonBody = [];
}

function mobile_in(string $key, $default = '') {
    global $jsonBody;
    if (array_key_exists($key, $jsonBody) && $jsonBody[$key] !== null) {
        return $jsonBody[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    return $default;
}

function mobile_out(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mobile_api_key(): string {
    $header = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    $fromBody = trim((string) mobile_in('key', ''));
    return trim($header !== '' ? $header : $fromBody);
}

function mobile_format_order(array $row): array {
    return [
        'id' => (int)$row['id'],
        'service_id' => (int)($row['service_id'] ?? 0),
        'service_name' => (string)($row['service_name'] ?? ''),
        'link' => (string)($row['link'] ?? ''),
        'quantity' => (int)($row['quantity'] ?? 0),
        'charge' => number_format((float)($row['charge'] ?? 0), 4, '.', ''),
        'status' => (string)($row['status'] ?? ''),
        'start_count' => (string)($row['start_count'] ?? '0'),
        'remains' => (string)($row['remains'] ?? '0'),
        'created_at' => (string)($row['created_at'] ?? ''),
        'currency' => 'USD',
    ];
}

function mobile_format_service(array $s, RevenueEngine $revenue, int $userId): array {
    return [
        'service' => (int)$s['service_id'],
        'name' => (string)$s['name'],
        'type' => (string)($s['type'] ?? 'Default'),
        'category' => (string)($s['category'] ?? ''),
        'rate' => number_format($revenue->retailRatePerThousand($s, $userId), 5, '.', ''),
        'min' => (int)$s['min'],
        'max' => (int)$s['max'],
        'refill' => !empty($s['refill']),
        'cancel' => !empty($s['cancel']),
    ];
}

$action = strtolower(trim((string) mobile_in('action', '')));
if ($action === '') {
    mobile_out(['success' => false, 'error' => 'Missing action'], 400);
}

$db = Database::getInstance();
$revenue = new RevenueEngine();

if ($action === 'config') {
    $google = defined('GOOGLE_CLIENT_ID') && trim((string)GOOGLE_CLIENT_ID) !== '';
    $registrationEnabled = ($db->getSetting('registration_enabled') ?? '1') === '1';
    $minDeposit = (float)($db->getSetting('min_deposit') ?: 10);
    $methods = [];
    foreach (PaymentRegistry::enabledMethods() as $slug => $def) {
        $methods[] = [
            'slug' => $slug,
            'label' => $def['label'] ?? $slug,
            'desc' => $def['desc'] ?? '',
            'type' => $def['type'] ?? 'manual',
            'icon' => $def['icon'] ?? '',
        ];
    }
    mobile_out([
        'success' => true,
        'site_name' => function_exists('site_name') ? site_name() : 'SMM Turk',
        'logo' => function_exists('logo_url') ? logo_url() : '',
        'google' => $google,
        'registration_enabled' => $registrationEnabled,
        'min_deposit' => $minDeposit >= 1 ? $minDeposit : 10,
        'currency' => 'USD',
        'payment_methods' => $methods,
        'ticket_categories' => ['Order', 'Payments', 'Invoice', 'Child Panel', 'API', 'BUG', 'Redeem', 'Request', 'Other'],
    ]);
}

if ($action === 'login') {
    $loginId = trim((string) mobile_in('login', mobile_in('email', mobile_in('username', ''))));
    $password = (string) mobile_in('password', '');
    $totp = trim((string) mobile_in('totp', mobile_in('code', '')));
    if ($loginId === '' || $password === '') {
        mobile_out(['success' => false, 'error' => 'Username and password are required'], 400);
    }
    $accountLimit = new RateLimit(8, 900, 'mobile_login_' . md5($loginId));
    if ($accountLimit->isLimited()) {
        mobile_out(['success' => false, 'error' => 'Too many login attempts. Try again in 15 minutes.'], 429);
    }
    $accountLimit->recordAttempt();
    $result = $auth->loginForMobile($loginId, $password, $totp);
    if (empty($result['success'])) {
        mobile_out(['success' => false, 'error' => $result['error'] ?? 'Invalid credentials'], 401);
    }
    if (!empty($result['needs_2fa'])) {
        mobile_out([
            'success' => true,
            'needs_2fa' => true,
            'challenge' => $result['challenge'],
        ]);
    }
    mobile_out([
        'success' => true,
        'api_key' => $result['api_key'],
        'user' => $result['user'],
    ]);
}

if ($action === 'login_2fa') {
    $challenge = trim((string) mobile_in('challenge', ''));
    $code = trim((string) mobile_in('code', mobile_in('totp', '')));
    if ($challenge === '' || $code === '') {
        mobile_out(['success' => false, 'error' => 'Challenge and code are required'], 400);
    }
    $limit = new RateLimit(10, 900, 'mobile_2fa_' . md5($challenge));
    if ($limit->isLimited()) {
        mobile_out(['success' => false, 'error' => 'Too many attempts. Try again later.'], 429);
    }
    $limit->recordAttempt();
    $result = $auth->completeMobileTwoFactor($challenge, $code);
    if (empty($result['success'])) {
        mobile_out(['success' => false, 'error' => $result['error'] ?? 'Invalid code'], 401);
    }
    mobile_out([
        'success' => true,
        'api_key' => $result['api_key'],
        'user' => $result['user'],
    ]);
}

if ($action === 'register') {
    if (($db->getSetting('registration_enabled') ?? '1') !== '1') {
        mobile_out(['success' => false, 'error' => 'Registration is currently disabled.'], 403);
    }
    $registerLimit = new RateLimit(5, 3600, 'mobile_register_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    if ($registerLimit->isLimited()) {
        mobile_out(['success' => false, 'error' => 'Too many registration attempts. Try again later.'], 429);
    }
    $registerLimit->recordAttempt();
    $username = trim((string) mobile_in('username', ''));
    $email = strtolower(trim((string) mobile_in('email', '')));
    $password = (string) mobile_in('password', '');
    $referral = trim((string) mobile_in('referral', mobile_in('referral_code', '')));
    $result = $auth->register($username, $email, $password, $referral);
    if (empty($result['success'])) {
        mobile_out(['success' => false, 'error' => $result['error'] ?? 'Registration failed'], 400);
    }
    if (!empty($result['verify_required'])) {
        mobile_out([
            'success' => true,
            'verify_required' => true,
            'email_sent' => !empty($result['email_sent']),
            'message' => 'Check your inbox and click the activation link before signing in.',
        ]);
    }
    $user = $db->fetch("SELECT * FROM users WHERE id = ?", [(int)$result['user_id']]);
    if (!$user) {
        mobile_out(['success' => true, 'verify_required' => false, 'message' => 'Account created. Please sign in.']);
    }
    $issued = $auth->issueMobileSession($user);
    mobile_out([
        'success' => true,
        'verify_required' => false,
        'api_key' => $issued['api_key'],
        'user' => $issued['user'],
    ]);
}

if ($action === 'google_start') {
    $google = defined('GOOGLE_CLIENT_ID') && trim((string)GOOGLE_CLIENT_ID) !== '';
    if (!$google) {
        mobile_out(['success' => false, 'error' => 'Google Sign-In is not configured.'], 400);
    }
    $openInApp = trim((string) mobile_in('app', '0')) === '1';
    $url = rtrim(function_exists('url') ? url('login-google.php') : '/login-google.php', '/');
    $url .= '?mobile=1' . ($openInApp ? '&app=1' : '');
    mobile_out(['success' => true, 'url' => $url]);
}

if ($action === 'google_finish') {
    $rateLimit = new RateLimit(12, 900, 'mobile_google_finish_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    if ($rateLimit->isLimited()) {
        mobile_out(['success' => false, 'error' => 'Too many login attempts. Try again later.'], 429);
    }
    $rateLimit->recordAttempt();
    $token = trim((string) mobile_in('token', mobile_in('oauth_token', '')));
    $handshake = MobileAuth::takeHandshake($token);
    if (!$handshake || empty($handshake['api_key'])) {
        mobile_out(['success' => false, 'error' => 'Google sign-in expired. Try again.'], 401);
    }
    mobile_out([
        'success' => true,
        'api_key' => $handshake['api_key'],
        'user' => $handshake['user'] ?? null,
    ]);
}

$key = mobile_api_key();
if ($key === '') {
    mobile_out(['success' => false, 'error' => 'Missing API key'], 401);
}

$apiRateLimit = new RateLimit(120, 60, 'mobile_' . $key);
if ($apiRateLimit->isLimited()) {
    header('Retry-After: 60');
    mobile_out(['success' => false, 'error' => 'Rate limit exceeded. Try again later.'], 429);
}
$apiRateLimit->recordAttempt();

$userRow = $db->fetch("SELECT * FROM users WHERE api_key = ? AND status = 'active'", [$key]);
if (!$userRow) {
    mobile_out(['success' => false, 'error' => 'Invalid API key'], 403);
}
$userId = (int)$userRow['id'];

switch ($action) {
    case 'me':
        $fresh = $db->fetch("SELECT * FROM users WHERE id = ?", [$userId]) ?: $userRow;
        $promo = $revenue->promoBanner();
        $openTickets = 0;
        try {
            $openTickets = (int)($db->fetch("SELECT COUNT(*) c FROM tickets WHERE user_id = ? AND status != 'closed'", [$userId])['c'] ?? 0);
        } catch (Throwable $e) {
            $openTickets = 0;
        }
        $recent = $db->fetchAll(
            "SELECT id, service_id, service_name, link, quantity, charge, status, start_count, remains, created_at
             FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5",
            [$userId]
        );
        mobile_out([
            'success' => true,
            'user' => $auth->mobileUserPayload($fresh, $key),
            'promo' => $promo,
            'open_tickets' => $openTickets,
            'recent_orders' => array_map('mobile_format_order', $recent),
        ]);

    case 'categories':
        $rows = $db->fetchAll(
            "SELECT TRIM(COALESCE(category,'')) AS category, COUNT(*) AS cnt
             FROM services WHERE status='active' GROUP BY TRIM(COALESCE(category,'')) ORDER BY category"
        );
        $out = [];
        foreach ($rows as $row) {
            $cat = trim((string)($row['category'] ?? ''));
            if ($cat === '') {
                continue;
            }
            $out[] = ['category' => $cat, 'count' => (int)$row['cnt']];
        }
        mobile_out(['success' => true, 'categories' => $out]);

    case 'services':
        $q = trim((string) mobile_in('q', ''));
        $cat = trim((string) mobile_in('category', ''));
        $limit = min(200, max(1, (int) mobile_in('limit', 80)));
        $offset = max(0, (int) mobile_in('offset', 0));
        $sql = "SELECT service_id, name, type, category, rate, min, max, refill, cancel, markup FROM services WHERE status='active'";
        $params = [];
        if ($cat !== '') {
            $sql .= ' AND TRIM(category) = ?';
            $params[] = $cat;
        }
        if ($q !== '') {
            $sql .= ' AND (name LIKE ? OR category LIKE ? OR CAST(service_id AS CHAR) LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $countSql = 'SELECT COUNT(*) c FROM (' . $sql . ') t';
        $total = (int)($db->fetch($countSql, $params)['c'] ?? 0);
        $sql .= ' ORDER BY category, service_id LIMIT ' . $limit . ' OFFSET ' . $offset;
        $rows = $db->fetchAll($sql, $params);
        $list = [];
        foreach ($rows as $s) {
            $list[] = mobile_format_service($s, $revenue, $userId);
        }
        mobile_out(['success' => true, 'total' => $total, 'services' => $list]);

    case 'order_preview':
        $serviceId = (int) mobile_in('service', mobile_in('service_id', 0));
        $quantity = (int) mobile_in('quantity', 0);
        $coupon = trim((string) mobile_in('coupon', mobile_in('coupon_code', '')));
        $service = $db->fetch("SELECT * FROM services WHERE service_id = ? AND status='active'", [$serviceId]);
        if (!$service) {
            mobile_out(['success' => false, 'error' => 'Service not found or inactive'], 404);
        }
        if ($quantity <= 0) {
            $quantity = (int)$service['min'];
        }
        $pricing = $revenue->computeOrderCharge($userId, $service, $quantity, $coupon !== '' ? $coupon : null);
        mobile_out([
            'success' => true,
            'service' => mobile_format_service($service, $revenue, $userId),
            'quantity' => $quantity,
            'charge' => number_format((float)$pricing['charge'], 4, '.', ''),
            'currency' => 'USD',
            'coupon_error' => $pricing['coupon_error'] ?? null,
        ]);

    case 'order_create':
        $serviceId = (int) mobile_in('service', mobile_in('service_id', 0));
        $link = trim((string) mobile_in('link', ''));
        $quantity = (int) mobile_in('quantity', 0);
        $coupon = trim((string) mobile_in('coupon', mobile_in('coupon_code', '')));
        if (!$serviceId || $link === '' || !$quantity) {
            mobile_out(['success' => false, 'error' => 'Please fill in all required fields.'], 400);
        }
        $link = normalize_order_link($link);
        if ($link === '') {
            mobile_out(['success' => false, 'error' => 'Please enter a valid link (e.g. https://instagram.com/username).'], 400);
        }
        $extra = $coupon !== '' ? ['coupon' => $coupon] : [];
        $om = new OrderManager();
        $result = $om->placeOrder($userId, $serviceId, $link, $quantity, $extra);
        if (empty($result['success'])) {
            $code = stripos((string)($result['error'] ?? ''), 'Insufficient') !== false ? 402 : 400;
            mobile_out(['success' => false, 'error' => $result['error'] ?? 'Could not place order'], $code);
        }
        mobile_out([
            'success' => true,
            'order_id' => (int)$result['order_id'],
            'charge' => (string)($result['charge'] ?? ''),
            'user' => $auth->mobileUserPayload($db->fetch("SELECT * FROM users WHERE id = ?", [$userId]) ?: $userRow, $key),
        ]);

    case 'orders':
        $status = trim((string) mobile_in('status', ''));
        $limit = min(100, max(1, (int) mobile_in('limit', 40)));
        $offset = max(0, (int) mobile_in('offset', 0));
        $sql = "SELECT id, service_id, service_name, link, quantity, charge, status, start_count, remains, created_at
                FROM orders WHERE user_id = ?";
        $params = [$userId];
        if ($status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $rows = $db->fetchAll($sql, $params);
        mobile_out(['success' => true, 'orders' => array_map('mobile_format_order', $rows)]);

    case 'order':
        $orderId = (int) mobile_in('id', mobile_in('order', 0));
        $row = $db->fetch(
            "SELECT id, service_id, service_name, link, quantity, charge, status, start_count, remains, created_at
             FROM orders WHERE id = ? AND user_id = ?",
            [$orderId, $userId]
        );
        if (!$row) {
            mobile_out(['success' => false, 'error' => 'Order not found'], 404);
        }
        mobile_out(['success' => true, 'order' => mobile_format_order($row)]);

    case 'tickets':
        $rows = $db->fetchAll(
            "SELECT id, subject, status, updated_at, created_at FROM tickets WHERE user_id = ? ORDER BY updated_at DESC LIMIT 50",
            [$userId]
        );
        mobile_out(['success' => true, 'tickets' => $rows]);

    case 'ticket':
        $tid = (int) mobile_in('id', 0);
        $ticket = $db->fetch("SELECT * FROM tickets WHERE id = ? AND user_id = ?", [$tid, $userId]);
        if (!$ticket) {
            mobile_out(['success' => false, 'error' => 'Ticket not found'], 404);
        }
        $replies = $db->fetchAll(
            "SELECT id, message, is_staff, created_at FROM ticket_replies WHERE ticket_id = ? ORDER BY created_at ASC",
            [$tid]
        );
        mobile_out(['success' => true, 'ticket' => $ticket, 'replies' => $replies]);

    case 'ticket_create':
        $message = trim((string) mobile_in('message', ''));
        $category = trim((string) mobile_in('category', 'Other'));
        $orderId = trim((string) mobile_in('order_id', ''));
        if ($message === '') {
            mobile_out(['success' => false, 'error' => 'Please enter your message.'], 400);
        }
        $allowed = ['Order', 'Payments', 'Invoice', 'Child Panel', 'API', 'BUG', 'Redeem', 'Request', 'Other'];
        if (!in_array($category, $allowed, true)) {
            $category = 'Other';
        }
        $subject = $category;
        if ($orderId !== '') {
            $subject .= ' [Order: ' . mb_substr($orderId, 0, 100) . ']';
        }
        $tid = $db->insert("INSERT INTO tickets (user_id, subject, status) VALUES (?, ?, 'open')", [$userId, $subject]);
        try {
            $db->execute(
                "UPDATE tickets SET category = ?, order_id = ? WHERE id = ?",
                [$category, mb_substr($orderId, 0, 500), $tid]
            );
        } catch (Throwable $e) {
            /* optional columns */
        }
        $db->insert(
            "INSERT INTO ticket_replies (ticket_id, user_id, message, is_staff) VALUES (?, ?, ?, 0)",
            [$tid, $userId, $message]
        );
        mobile_out(['success' => true, 'ticket_id' => (int)$tid]);

    case 'ticket_reply':
        $tid = (int) mobile_in('id', 0);
        $msg = trim((string) mobile_in('message', ''));
        $ticket = $db->fetch("SELECT * FROM tickets WHERE id = ? AND user_id = ?", [$tid, $userId]);
        if (!$ticket) {
            mobile_out(['success' => false, 'error' => 'Ticket not found'], 404);
        }
        if ($msg === '') {
            mobile_out(['success' => false, 'error' => 'Please enter your message.'], 400);
        }
        $st = (string)($ticket['status'] ?? 'open');
        if ($st === 'closed') {
            mobile_out(['success' => false, 'error' => 'This ticket is closed.'], 400);
        }
        $db->insert(
            "INSERT INTO ticket_replies (ticket_id, user_id, message, is_staff) VALUES (?, ?, ?, 0)",
            [$tid, $userId, $msg]
        );
        $db->execute("UPDATE tickets SET status = 'open', updated_at = NOW() WHERE id = ?", [$tid]);
        mobile_out(['success' => true]);

    case 'deposits':
        $rows = $db->fetchAll(
            "SELECT id, amount, description, reference, status, created_at
             FROM transactions WHERE user_id = ? AND type = 'deposit' ORDER BY id DESC LIMIT 50",
            [$userId]
        );
        $pending = $db->fetch(
            "SELECT id, amount, description, reference, status, created_at FROM transactions
             WHERE user_id = ? AND type = 'deposit' AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        $payload = ['success' => true, 'deposits' => $rows, 'pending' => null];
        if ($pending) {
            $methodSlug = PaymentRegistry::parseMethodFromDescription($pending['description'] ?? '');
            $manual = $methodSlug ? PaymentRegistry::manualPayMeta($methodSlug) : null;
            $payload['pending'] = [
                'id' => (int)$pending['id'],
                'amount' => number_format((float)$pending['amount'], 2, '.', ''),
                'description' => (string)$pending['description'],
                'reference' => (string)($pending['reference'] ?? ''),
                'status' => (string)$pending['status'],
                'created_at' => (string)$pending['created_at'],
                'method' => $methodSlug,
                'wallet' => $manual,
            ];
        }
        mobile_out($payload);

    case 'deposit_create':
        $method = strtolower(trim((string) mobile_in('method', '')));
        $amount = (float) mobile_in('amount', 0);
        $minDeposit = (float)($db->getSetting('min_deposit') ?: 10);
        $minDeposit = $minDeposit >= 1 ? $minDeposit : 10;
        $paymentMethods = PaymentRegistry::enabledMethods();
        if ($method === '' || !isset($paymentMethods[$method])) {
            mobile_out(['success' => false, 'error' => 'Please select a valid payment method.'], 400);
        }
        if ($amount < $minDeposit) {
            mobile_out(['success' => false, 'error' => "Minimum deposit is \${$minDeposit}."], 400);
        }
        $pendingDeposit = $db->fetch(
            "SELECT id, reference FROM transactions WHERE user_id = ? AND type = 'deposit' AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        if ($pendingDeposit && trim((string)($pendingDeposit['reference'] ?? '')) !== '') {
            mobile_out(['success' => false, 'error' => 'You already have a pending deposit. Wait for confirmation or contact support.'], 400);
        }
        if ($pendingDeposit) {
            $db->execute(
                "UPDATE transactions SET amount = ?, description = ?, reference = '' WHERE id = ? AND user_id = ?",
                [$amount, PaymentRegistry::depositDescription($amount, $method), $pendingDeposit['id'], $userId]
            );
            $depositId = (int)$pendingDeposit['id'];
        } else {
            $balanceBefore = (float)($userRow['balance'] ?? 0);
            $depositId = (int)$db->insert(
                "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference, status)
                 VALUES (?, 'deposit', ?, ?, ?, ?, '', 'pending')",
                [$userId, $amount, $balanceBefore, $balanceBefore, PaymentRegistry::depositDescription($amount, $method)]
            );
        }
        $processor = new PaymentProcessor();
        $init = $processor->initiate($method, $depositId, $userId, $amount, (string)($userRow['email'] ?? ''));
        if (empty($init['success'])) {
            $db->execute("UPDATE transactions SET status = 'failed' WHERE id = ? AND user_id = ?", [$depositId, $userId]);
            mobile_out(['success' => false, 'error' => $init['error'] ?? 'Could not start payment.'], 400);
        }
        $manual = PaymentRegistry::manualPayMeta($method);
        mobile_out([
            'success' => true,
            'deposit_id' => $depositId,
            'amount' => number_format($amount, 2, '.', ''),
            'manual' => !empty($init['manual']),
            'redirect_url' => $init['redirect_url'] ?? null,
            'wallet' => $manual,
        ]);

    case 'deposit_submit_tx':
        $txId = trim((string) mobile_in('tx_hash', mobile_in('reference', '')));
        $depositId = (int) mobile_in('deposit_id', 0);
        if ($txId === '' || $depositId <= 0) {
            mobile_out(['success' => false, 'error' => 'Please paste your transaction ID (TxHash).'], 400);
        }
        $tx = $db->fetch(
            "SELECT id, user_id FROM transactions WHERE id = ? AND user_id = ? AND type = 'deposit' AND status = 'pending'",
            [$depositId, $userId]
        );
        if (!$tx) {
            mobile_out(['success' => false, 'error' => 'Pending deposit not found.'], 404);
        }
        $db->execute("UPDATE transactions SET reference = ? WHERE id = ?", [substr($txId, 0, 100), $tx['id']]);
        $fullTx = $db->fetch(
            "SELECT id, user_id, amount, description, reference, status, created_at FROM transactions WHERE id = ?",
            [$tx['id']]
        );
        $walletCatalog = DepositAutoConfirm::buildWalletCatalog($db);
        $auto = new DepositAutoConfirm();
        $check = $auto->processTransaction($fullTx, $walletCatalog);
        mobile_out([
            'success' => true,
            'approved' => !empty($check['approved']),
            'message' => !empty($check['approved'])
                ? 'Payment confirmed! Your balance has been credited.'
                : ($check['message'] ?? 'Payment submitted. Verifying on-chain…'),
            'user' => $auth->mobileUserPayload($db->fetch("SELECT * FROM users WHERE id = ?", [$userId]) ?: $userRow, $key),
        ]);

    default:
        mobile_out(['success' => false, 'error' => 'Unknown action'], 400);
}
