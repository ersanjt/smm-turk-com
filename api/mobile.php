<?php
/**
 * JSON API for the SMM Turk Android app.
 * POST https://smm-turk.com/api/mobile
 * Public: login, register. Authenticated: me, services, add, orders, cancel.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

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

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);
    if (is_array($json)) {
        foreach ($json as $k => $v) {
            if (!isset($_POST[$k])) {
                $_POST[$k] = $v;
            }
        }
    }
}

$action = trim((string)($_POST['action'] ?? ''));

function mobile_out(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function mobile_api_key(): string {
    $key = trim((string)($_POST['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key === '') {
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $key = trim($m[1]);
        }
    }
    return $key;
}

function mobile_format_order(array $row): array {
    return [
        'id' => (int)$row['id'],
        'service_id' => (int)($row['service_id'] ?? 0),
        'service' => (string)($row['service_name'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'link' => (string)($row['link'] ?? ''),
        'quantity' => (int)($row['quantity'] ?? 0),
        'charge' => number_format((float)($row['charge'] ?? 0), 5, '.', ''),
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
        'min' => (string)$s['min'],
        'max' => (string)$s['max'],
        'refill' => (bool)$s['refill'],
        'cancel' => (bool)$s['cancel'],
    ];
}

if ($action === '') {
    mobile_out(['success' => false, 'error' => 'Missing action'], 400);
}

$db = Database::getInstance();

if ($action === 'login') {
    $rateLimit = new RateLimit(8, 900);
    $loginId = strtolower(trim((string)($_POST['email'] ?? $_POST['username'] ?? '')));
    $accountLimit = $loginId !== '' ? new RateLimit(8, 900, 'mobile_login_' . md5($loginId)) : null;
    if ($rateLimit->isLimited() || ($accountLimit && $accountLimit->isLimited())) {
        mobile_out(['success' => false, 'error' => 'Too many login attempts. Try again in 15 minutes.'], 429);
    }
    $result = $auth->authenticateForMobile(
        trim((string)($_POST['email'] ?? $_POST['username'] ?? '')),
        (string)($_POST['password'] ?? ''),
        (string)($_POST['totp'] ?? $_POST['code'] ?? '')
    );
    if (empty($result['success'])) {
        $rateLimit->recordAttempt();
        if ($accountLimit) {
            $accountLimit->recordAttempt();
        }
        $code = !empty($result['needs_2fa']) ? 401 : 403;
        mobile_out([
            'success' => false,
            'needs_2fa' => !empty($result['needs_2fa']),
            'error' => $result['error'] ?? 'Login failed',
        ], $code);
    }
    $rateLimit->clear();
    if ($accountLimit) {
        $accountLimit->clear();
    }
    mobile_out([
        'success' => true,
        'api_key' => $result['api_key'],
        'user' => $result['user'],
    ]);
}

if ($action === 'register') {
    $registrationEnabled = ($db->getSetting('registration_enabled') ?? '1') === '1';
    if (!$registrationEnabled) {
        mobile_out(['success' => false, 'error' => 'Registration is currently disabled.'], 403);
    }
    $registerLimit = new RateLimit(5, 3600, 'mobile_register_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    if ($registerLimit->isLimited()) {
        mobile_out(['success' => false, 'error' => 'Too many registration attempts. Try again later.'], 429);
    }
    $result = $auth->register(
        trim((string)($_POST['username'] ?? '')),
        trim((string)($_POST['email'] ?? '')),
        (string)($_POST['password'] ?? ''),
        trim((string)($_POST['ref'] ?? ''))
    );
    if (empty($result['success'])) {
        $registerLimit->recordAttempt();
        mobile_out(['success' => false, 'error' => $result['error'] ?? 'Registration failed'], 400);
    }
    $registerLimit->clear();
    if (!empty($result['verify_required'])) {
        mobile_out([
            'success' => true,
            'verify_required' => true,
            'email_sent' => !empty($result['email_sent']),
            'message' => 'Account created. Verify your email, then sign in.',
        ]);
    }
    $login = $auth->authenticateForMobile(
        trim((string)($_POST['email'] ?? '')),
        (string)($_POST['password'] ?? '')
    );
    if (empty($login['success'])) {
        mobile_out([
            'success' => true,
            'verify_required' => false,
            'message' => 'Account created. You can sign in now.',
        ]);
    }
    mobile_out([
        'success' => true,
        'verify_required' => false,
        'api_key' => $login['api_key'],
        'user' => $login['user'],
    ]);
}

if ($action === 'google') {
    $rateLimit = new RateLimit(8, 900, 'mobile_google_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    if ($rateLimit->isLimited()) {
        mobile_out(['success' => false, 'error' => 'Too many login attempts. Try again later.'], 429);
    }
    $idToken = trim((string)($_POST['id_token'] ?? ''));
    $result = $auth->authenticateGoogleIdToken($idToken, (string)($_POST['totp'] ?? $_POST['code'] ?? ''));
    if (empty($result['success'])) {
        $rateLimit->recordAttempt();
        $code = !empty($result['needs_2fa']) ? 401 : 403;
        mobile_out([
            'success' => false,
            'needs_2fa' => !empty($result['needs_2fa']),
            'error' => $result['error'] ?? 'Google sign-in failed',
        ], $code);
    }
    $rateLimit->clear();
    mobile_out([
        'success' => true,
        'api_key' => $result['api_key'],
        'user' => $result['user'],
    ]);
}

if ($action === 'google_finish') {
    $rateLimit = new RateLimit(12, 900, 'mobile_google_finish_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    if ($rateLimit->isLimited()) {
        mobile_out(['success' => false, 'error' => 'Too many login attempts. Try again later.'], 429);
    }
    $token = trim((string)($_POST['token'] ?? ''));
    $userId = MobileAuth::consumeToken($token);
    if (!$userId) {
        $rateLimit->recordAttempt();
        mobile_out(['success' => false, 'error' => 'Google sign-in expired. Try again.'], 401);
    }
    $result = $auth->payloadForMobileUserId($userId);
    if (empty($result['success'])) {
        $rateLimit->recordAttempt();
        mobile_out(['success' => false, 'error' => $result['error'] ?? 'Google sign-in failed'], 403);
    }
    $rateLimit->clear();
    mobile_out([
        'success' => true,
        'api_key' => $result['api_key'],
        'user' => $result['user'],
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
$om = new OrderManager();
$revenue = new RevenueEngine();

switch ($action) {
    case 'me':
        $fresh = $db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
        $statsRow = $db->fetch(
            "SELECT
                COUNT(*) AS orders_total,
                SUM(status IN ('Completed','Partial')) AS orders_completed,
                SUM(status IN ('Pending','Processing','In progress')) AS orders_open
             FROM orders WHERE user_id = ?",
            [$userId]
        );
        $recent = $om->getUserOrders($userId, '', 8, 0);
        mobile_out([
            'success' => true,
            'user' => $auth->mobileUserPayload($fresh ?: $userRow, $key),
            'stats' => [
                'orders_total' => (int)($statsRow['orders_total'] ?? 0),
                'orders_completed' => (int)($statsRow['orders_completed'] ?? 0),
                'orders_open' => (int)($statsRow['orders_open'] ?? 0),
            ],
            'recent_orders' => array_map('mobile_format_order', $recent),
            'funds_url' => rtrim((string)(defined('SITE_URL') ? SITE_URL : 'https://smm-turk.com'), '/') . '/funds',
            'site_url' => rtrim((string)(defined('SITE_URL') ? SITE_URL : 'https://smm-turk.com'), '/'),
        ]);
        break;

    case 'services':
        $q = trim((string)($_POST['q'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $sql = "SELECT service_id, name, type, category, rate, min, max, refill, cancel, markup FROM services WHERE status='active'";
        $params = [];
        if ($category !== '') {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        if ($q !== '') {
            $sql .= ' AND (name LIKE ? OR category LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY category, service_id LIMIT 400';
        $services = $db->fetchAll($sql, $params);
        $cats = $db->fetchAll("SELECT DISTINCT category FROM services WHERE status='active' ORDER BY category");
        $output = [];
        foreach ($services as $s) {
            $output[] = mobile_format_service($s, $revenue, $userId);
        }
        mobile_out([
            'success' => true,
            'categories' => array_values(array_filter(array_map(static fn($c) => (string)($c['category'] ?? ''), $cats))),
            'services' => $output,
        ]);
        break;

    case 'add':
        $serviceId = (int)($_POST['service'] ?? $_POST['service_id'] ?? 0);
        $link = trim((string)($_POST['link'] ?? ''));
        $quantity = (int)($_POST['quantity'] ?? 0);
        $extra = [];
        if (!empty($_POST['coupon'])) {
            $extra['coupon'] = trim((string)$_POST['coupon']);
        }
        if (!$serviceId || $link === '' || !$quantity) {
            mobile_out(['success' => false, 'error' => 'Missing required parameters'], 400);
        }
        $link = normalize_order_link($link);
        if ($link === '') {
            mobile_out(['success' => false, 'error' => 'Invalid link'], 400);
        }
        $result = $om->placeOrder($userId, $serviceId, $link, $quantity, $extra);
        if (empty($result['success'])) {
            $code = stripos((string)($result['error'] ?? ''), 'Insufficient') !== false ? 402 : 400;
            mobile_out(['success' => false, 'error' => $result['error'] ?? 'Order failed'], $code);
        }
        $fresh = $db->fetch("SELECT balance FROM users WHERE id = ?", [$userId]);
        mobile_out([
            'success' => true,
            'order' => (int)$result['order_id'],
            'charge' => (string)($result['charge'] ?? ''),
            'balance' => number_format((float)($fresh['balance'] ?? 0), 5, '.', ''),
        ]);
        break;

    case 'orders':
        $status = trim((string)($_POST['status'] ?? ''));
        $allowed = ['', 'Pending', 'Processing', 'In progress', 'Completed', 'Partial', 'Cancelled', 'Refunded'];
        if (!in_array($status, $allowed, true)) {
            $status = '';
        }
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = min(50, max(1, (int)($_POST['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $rows = $om->getUserOrders($userId, $status, $limit, $offset);
        $total = $om->getUserOrderCount($userId, $status);
        mobile_out([
            'success' => true,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'orders' => array_map('mobile_format_order', $rows),
        ]);
        break;

    case 'cancel':
        $orderId = (int)($_POST['order'] ?? $_POST['orders'] ?? 0);
        if ($orderId <= 0) {
            mobile_out(['success' => false, 'error' => 'Missing order id'], 400);
        }
        $_POST['orders'] = (string)$orderId;
        // Reuse v2 cancel logic by including a local copy of the single-order path:
        try {
            $db->beginTransaction();
            $order = $db->fetch(
                "SELECT id, charge, provider_order_id FROM orders WHERE id = ? AND user_id = ? AND status = 'Pending' FOR UPDATE",
                [$orderId, $userId]
            );
            if (!$order) {
                $db->rollBack();
                mobile_out(['success' => false, 'error' => 'Cannot cancel this order'], 400);
            }
            if (!empty($order['provider_order_id'])) {
                $orderApi = ProviderRegistry::apiForOrder($db, $orderId, $userId);
                if ($orderApi) {
                    $providerResp = $orderApi->cancel([(int)$order['provider_order_id']]);
                    $providerItem = is_array($providerResp) ? ($providerResp[0] ?? null) : null;
                    if (is_array($providerItem) && isset($providerItem['cancel']['error'])) {
                        $db->rollBack();
                        mobile_out(['success' => false, 'error' => (string)$providerItem['cancel']['error']], 400);
                    }
                }
            }
            $updated = $db->execute(
                "UPDATE orders SET status = 'Cancelled' WHERE id = ? AND user_id = ? AND status = 'Pending'",
                [$orderId, $userId]
            );
            if ($updated === 0) {
                $db->rollBack();
                mobile_out(['success' => false, 'error' => 'Cannot cancel this order'], 400);
            }
            $balRow = $db->fetch("SELECT balance FROM users WHERE id = ? FOR UPDATE", [$userId]);
            $balanceBefore = (float)($balRow['balance'] ?? 0);
            $db->execute("UPDATE users SET balance = balance + ? WHERE id = ?", [(float)$order['charge'], $userId]);
            $balanceAfter = round($balanceBefore + (float)$order['charge'], 4);
            $db->insert(
                "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference, status) VALUES (?, 'refund', ?, ?, ?, ?, ?, 'completed')",
                [$userId, (float)$order['charge'], $balanceBefore, $balanceAfter, "Refund order #{$orderId}", (string)$orderId]
            );
            $db->commit();
            mobile_out([
                'success' => true,
                'order' => $orderId,
                'balance' => number_format($balanceAfter, 5, '.', ''),
            ]);
        } catch (Throwable $e) {
            $db->rollBack();
            if (class_exists('Logger')) {
                Logger::log('Mobile cancel failed order#' . $orderId . ': ' . $e->getMessage(), 'api');
            }
            mobile_out(['success' => false, 'error' => 'Cannot cancel this order'], 400);
        }
        break;

    default:
        mobile_out(['success' => false, 'error' => 'Unknown action'], 400);
}
