<?php
// api/v2.php — Public API endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/RateLimit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$key    = trim((string)($_POST['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? ''));
$action = trim((string)($_POST['action'] ?? ''));

if (!$key || !$action) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing key or action']);
    exit;
}

$user = $db->fetch("SELECT id, balance, status FROM users WHERE api_key = ? AND status = 'active'", [$key]);
if (!$user) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

$apiRateLimit = new RateLimit(120, 60, $key);
if ($apiRateLimit->isLimited()) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    exit;
}
$apiRateLimit->recordAttempt();

$om  = new OrderManager();
$revenue = new RevenueEngine();

switch ($action) {

    case 'services':
        $services = $db->fetchAll(
            "SELECT service_id, name, type, category, rate, min, max, refill, cancel, markup FROM services WHERE status='active' ORDER BY service_id"
        );
        $output = [];
        foreach ($services as $s) {
            $output[] = [
                'service'  => (int)$s['service_id'],
                'name'     => $s['name'],
                'type'     => $s['type'] ?? 'Default',
                'category' => $s['category'] ?? '',
                'rate'     => number_format($revenue->retailRatePerThousand($s, (int) $user['id']), 5),
                'min'      => (string)$s['min'],
                'max'      => (string)$s['max'],
                'refill'   => (bool)$s['refill'],
                'cancel'   => (bool)$s['cancel'],
            ];
        }
        echo json_encode($output);
        break;

    case 'add':
        $serviceId = (int)($_POST['service'] ?? 0);
        $link      = trim($_POST['link'] ?? '');
        $quantity  = (int)($_POST['quantity'] ?? 0);
        $extra     = [];
        if (isset($_POST['runs']) && $_POST['runs'] !== '') $extra['runs'] = (int)$_POST['runs'];
        if (isset($_POST['interval']) && $_POST['interval'] !== '') $extra['interval'] = (int)$_POST['interval'];
        if (!empty($_POST['coupon'])) $extra['coupon'] = trim((string) $_POST['coupon']);

        if (!$serviceId || !$link || !$quantity) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required parameters']);
            exit;
        }

        $link = normalize_order_link($link);
        if ($link === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid link']);
            exit;
        }

        $result = $om->placeOrder($user['id'], $serviceId, $link, $quantity, $extra);
        if ($result['success']) {
            echo json_encode(['order' => $result['order_id']]);
        } else {
            $code = stripos($result['error'], 'Insufficient') !== false ? 402 : 400;
            http_response_code($code);
            echo json_encode(['error' => $result['error']]);
        }
        break;

    case 'status':
        if (isset($_POST['orders'])) {
            $ids  = array_map('intval', array_filter(explode(',', str_replace(' ', '', $_POST['orders'] ?? ''))));
            $ids  = array_slice(array_unique($ids), 0, 100);
            $rows = [];
            if (!empty($ids)) {
                $rows = $db->fetchAll(
                    "SELECT id, status, charge, start_count, remains FROM orders WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND user_id = ?",
                    array_merge($ids, [$user['id']])
                );
            }
            $byId = [];
            foreach ($rows as $r) {
                $byId[$r['id']] = [
                    'charge'      => number_format((float)$r['charge'], 5),
                    'start_count' => (string)($r['start_count'] ?? '0'),
                    'status'      => $r['status'],
                    'remains'     => (string)($r['remains'] ?? '0'),
                    'currency'    => 'USD',
                ];
            }
            $out = [];
            foreach ($ids as $id) {
                $out[(string)$id] = $byId[$id] ?? ['error' => 'Incorrect order ID'];
            }
            echo json_encode($out);
        } else {
            $orderId = (int)($_POST['order'] ?? 0);
            $row = $db->fetch("SELECT charge, status, start_count, remains FROM orders WHERE id=? AND user_id=?", [$orderId, $user['id']]);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Incorrect order ID']);
                exit;
            }
            echo json_encode([
                'charge'      => number_format((float)$row['charge'], 5),
                'start_count' => (string)($row['start_count'] ?? '0'),
                'status'      => $row['status'],
                'remains'     => (string)($row['remains'] ?? '0'),
                'currency'    => 'USD',
            ]);
        }
        break;

    case 'refill':
        if (isset($_POST['orders'])) {
            $ourIds = array_map('intval', array_filter(explode(',', str_replace(' ', '', $_POST['orders'] ?? ''))));
            $ourIds = array_slice(array_unique($ourIds), 0, 100);
            $rows = [];
            if (!empty($ourIds)) {
                $rows = $db->fetchAll(
                    "SELECT id, provider, provider_order_id FROM orders WHERE id IN (" . implode(',', array_fill(0, count($ourIds), '?')) . ") AND user_id = ? AND provider_order_id IS NOT NULL",
                    array_merge($ourIds, [$user['id']])
                );
            }
            $byProvider = [];
            foreach ($rows as $r) {
                $slug = $r['provider'] ?? ProviderRegistry::PRIMARY;
                $byProvider[$slug][] = $r;
            }
            $out = [];
            foreach ($byProvider as $slug => $prow) {
                $api = ProviderRegistry::api($slug);
                if (!$api) {
                    continue;
                }
                $providerIds = array_column($prow, 'provider_order_id');
                $resp = $api->multiRefill($providerIds);
                if (!is_array($resp)) {
                    continue;
                }
                foreach ($prow as $i => $r) {
                    $item = $resp[$i] ?? [];
                    $refill = $item['refill'] ?? $item;
                    $out[] = ['order' => (int)$r['id'], 'refill' => $refill];
                }
            }
            $foundOur = array_column($out, 'order');
            foreach ($ourIds as $oid) {
                if (!in_array($oid, $foundOur, true)) {
                    $out[] = ['order' => $oid, 'refill' => ['error' => 'Incorrect order ID']];
                }
            }
            echo json_encode($out);
        } else {
            $orderId = (int)($_POST['order'] ?? 0);
            $row = $db->fetch("SELECT id, provider_order_id FROM orders WHERE id=? AND user_id=?", [$orderId, $user['id']]);
            if (!$row || empty($row['provider_order_id'])) {
                http_response_code(404);
                echo json_encode(['error' => 'Incorrect order ID']);
                exit;
            }
            $api = ProviderRegistry::apiForOrder($db, $orderId, $user['id']);
            if (!$api) {
                http_response_code(503);
                echo json_encode(['error' => 'Provider not available']);
                exit;
            }
            $resp = $api->refill((int)$row['provider_order_id']);
            if ($resp && isset($resp->refill)) {
                echo json_encode(['refill' => (string)$resp->refill]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => $resp->error ?? 'Refill failed']);
            }
        }
        break;

    case 'refill_status':
        $statusApi = ProviderRegistry::api(ProviderRegistry::PRIMARY) ?? ProviderRegistry::api(ProviderRegistry::SMMFA);
        if (isset($_POST['refills'])) {
            $refillIds = array_map('trim', array_filter(explode(',', str_replace(' ', '', $_POST['refills'] ?? ''))));
            $refillIds = array_slice(array_unique($refillIds), 0, 100);
            $resp = $statusApi ? $statusApi->multiRefillStatus($refillIds) : [];
            echo json_encode(is_array($resp) ? $resp : []);
        } else {
            $refillId = trim($_POST['refill'] ?? '');
            if ($refillId === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Missing refill parameter']);
                exit;
            }
            if (!$statusApi) {
                http_response_code(503);
                echo json_encode(['error' => 'Provider not available']);
                exit;
            }
            $resp = $statusApi->refillStatus($refillId);
            if ($resp && isset($resp->status)) {
                echo json_encode(['status' => $resp->status]);
            } elseif ($resp && isset($resp->error)) {
                echo json_encode(['status' => ['error' => $resp->error]]);
            } else {
                echo json_encode(['status' => ['error' => 'Refill not found']]);
            }
        }
        break;

    case 'balance':
        $fresh = $db->fetch("SELECT balance FROM users WHERE id = ?", [$user['id']]);
        echo json_encode([
            'balance'  => number_format((float)($fresh['balance'] ?? 0), 5),
            'currency' => 'USD',
        ]);
        break;

    case 'cancel':
        $ids = array_map('intval', explode(',', $_POST['orders'] ?? ''));
        $out = [];
        foreach ($ids as $id) {
            if ($id <= 0) {
                $out[] = ['order' => $id, 'cancel' => ['error' => 'Cannot cancel']];
                continue;
            }
            try {
                $db->beginTransaction();
                $order = $db->fetch(
                    "SELECT id, charge, provider_order_id FROM orders WHERE id = ? AND user_id = ? AND status = 'Pending' FOR UPDATE",
                    [$id, $user['id']]
                );
                if (!$order) {
                    $db->rollBack();
                    $out[] = ['order' => $id, 'cancel' => ['error' => 'Cannot cancel']];
                    continue;
                }
                if (!empty($order['provider_order_id'])) {
                    $orderApi = ProviderRegistry::apiForOrder($db, $id, $user['id']);
                    if ($orderApi) {
                        $providerResp = $orderApi->cancel([(int)$order['provider_order_id']]);
                        $providerItem = is_array($providerResp) ? ($providerResp[0] ?? null) : null;
                        if (is_array($providerItem) && isset($providerItem['cancel']['error'])) {
                            $db->rollBack();
                            $out[] = ['order' => $id, 'cancel' => $providerItem['cancel']];
                            continue;
                        }
                    }
                }
                $updated = $db->execute(
                    "UPDATE orders SET status = 'Cancelled' WHERE id = ? AND user_id = ? AND status = 'Pending'",
                    [$id, $user['id']]
                );
                if ($updated === 0) {
                    $db->rollBack();
                    $out[] = ['order' => $id, 'cancel' => ['error' => 'Cannot cancel']];
                    continue;
                }
                $userRow = $db->fetch("SELECT balance FROM users WHERE id = ? FOR UPDATE", [$user['id']]);
                $balanceBefore = (float)($userRow['balance'] ?? 0);
                $db->execute("UPDATE users SET balance = balance + ? WHERE id = ?", [(float)$order['charge'], $user['id']]);
                $balanceAfter = round($balanceBefore + (float)$order['charge'], 4);
                $db->insert(
                    "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference, status) VALUES (?, 'refund', ?, ?, ?, ?, ?, 'completed')",
                    [$user['id'], (float)$order['charge'], $balanceBefore, $balanceAfter, "Refund order #{$id}", (string)$id]
                );
                $db->commit();
                $out[] = ['order' => $id, 'cancel' => 1];
            } catch (Throwable $e) {
                $db->rollBack();
                if (class_exists('Logger')) {
                    Logger::log("API cancel failed order#{$id}: " . $e->getMessage(), 'api');
                }
                $out[] = ['order' => $id, 'cancel' => ['error' => 'Cannot cancel']];
            }
        }
        echo json_encode($out);
        break;

    case 'child_user_register':
        $panelDomain = ChildPanelManager::normalizeDomain(trim((string) ($_POST['panel_domain'] ?? '')));
        $localUserId = (int) ($_POST['local_user_id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $euStatus = trim((string) ($_POST['status'] ?? 'active'));
        $registeredAt = trim((string) ($_POST['registered_at'] ?? ''));
        $registry = new ChildPanelEndUsers();
        $reg = $registry->registerFromApi($key, $panelDomain, $localUserId, $username, $email, $euStatus, $registeredAt !== '' ? $registeredAt : null);
        if ($reg['success']) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => $reg['error'] ?? 'Registration sync failed']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
