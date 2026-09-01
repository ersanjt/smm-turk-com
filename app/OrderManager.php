<?php
class OrderManager {

    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    private function apiForService(array $service): ?SmmApi {
        $slug = ProviderRegistry::providerForService($service);
        return ProviderRegistry::api($slug);
    }

    public function placeOrder(int $userId, int $serviceId, string $link, int $quantity, array $extra = []): array {
        $service = $this->db->fetch("SELECT * FROM services WHERE service_id = ? AND status = 'active'", [$serviceId]);
        if (!$service) {
            return ['success' => false, 'error' => 'Service not found or inactive'];
        }

        $api = $this->apiForService($service);
        if (!$api) {
            return ['success' => false, 'error' => 'Provider API not configured for this service. Check Admin → Settings.'];
        }

        if ($quantity < $service['min'] || $quantity > $service['max']) {
            return ['success' => false, 'error' => "Quantity must be between {$service['min']} and {$service['max']}"];
        }

        $couponCode = trim((string) ($extra['coupon'] ?? $extra['coupon_code'] ?? ''));
        unset($extra['coupon'], $extra['coupon_code']);
        $revenue = new RevenueEngine();
        $pricing = $revenue->computeOrderCharge($userId, $service, $quantity, $couponCode !== '' ? $couponCode : null);
        if ($couponCode !== '' && !empty($pricing['coupon_error']) && empty($pricing['coupon_id'])) {
            return ['success' => false, 'error' => $pricing['coupon_error']];
        }
        $charge = (float) $pricing['charge'];
        $upstreamId = ProviderRegistry::upstreamServiceId($service);
        $provider = ProviderRegistry::providerForService($service);
        $orderData = array_merge(['service' => $upstreamId, 'link' => $link, 'quantity' => $quantity], $extra);

        $balanceBefore = 0.0;
        try {
            $this->db->beginTransaction();
            $user = $this->db->fetch("SELECT balance FROM users WHERE id = ? FOR UPDATE", [$userId]);
            if (!$user || (float)$user['balance'] < $charge) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Insufficient balance. Add funds with crypto first.'];
            }
            $balanceBefore = (float)$user['balance'];
            $deducted = $this->db->execute(
                "UPDATE users SET balance = balance - ?, spent = spent + ? WHERE id = ? AND balance >= ?",
                [$charge, $charge, $userId, $charge]
            );
            if ($deducted === 0) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Insufficient balance. Add funds with crypto first.'];
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            if (class_exists('Logger')) {
                Logger::log('placeOrder deduct failed: ' . $e->getMessage(), 'orders');
            }
            return ['success' => false, 'error' => 'Could not place order. Please try again.'];
        }

        $response = $api->order($orderData);
        if (!$response || isset($response->error)) {
            $this->refundCharge($userId, $charge);
            return ['success' => false, 'error' => $response->error ?? 'Provider error. Please try again.'];
        }

        try {
            $this->db->beginTransaction();

            $orderId = $this->db->insert(
                "INSERT INTO orders (user_id, provider, provider_order_id, service_id, service_name, link, quantity, charge, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')",
                [$userId, $provider, $response->order ?? null, $serviceId, $service['name'], $link, $quantity, $charge]
            );

            $balanceAfter = $balanceBefore - $charge;
            $this->db->insert(
                "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference)
                 VALUES (?, 'order', ?, ?, ?, ?, ?)",
                [$userId, -$charge, $balanceBefore, $balanceAfter, "Order #{$orderId}: " . substr($service['name'], 0, 60), (string)$orderId]
            );

            $buyer = $this->db->fetch("SELECT referred_by FROM users WHERE id = ?", [$userId]);
            if (!empty($buyer['referred_by'])) {
                $pct = (float)($this->db->getSetting('referral_commission') ?: (defined('REFERRAL_COMMISSION') ? REFERRAL_COMMISSION : 2));
                if ($pct > 0) {
                    $commission = round($charge * ($pct / 100), 4);
                    $this->db->execute("UPDATE users SET referral_earnings = referral_earnings + ? WHERE id = ?", [$commission, $buyer['referred_by']]);
                    try {
                        $this->db->execute("UPDATE users SET total_referral_earnings = total_referral_earnings + ? WHERE id = ?", [$commission, $buyer['referred_by']]);
                    } catch (Throwable $e) { /* column may not exist */ }
                }
            }

            if (!empty($pricing['coupon_id'])) {
                $revenue->recordCouponUse(
                    (int) $pricing['coupon_id'],
                    $userId,
                    'order',
                    (float) ($pricing['coupon_discount'] ?? 0),
                    (int) $orderId
                );
            }

            $this->db->commit();

            if (class_exists('GoogleAcquisition', false)) {
                try {
                    (new GoogleAcquisition())->trackFirstOrder($userId, (float) $charge);
                } catch (Throwable $e) {
                    /* best effort */
                }
            }

            $buyerRow = $this->db->fetch("SELECT username, email FROM users WHERE id = ?", [$userId]);
            if ($buyerRow && !empty($buyerRow['email'])) {
                try {
                    $mail = new Mail();
                    $mail->sendOrderPlaced(
                        $buyerRow['email'],
                        $buyerRow['username'],
                        (int) $orderId,
                        $service['name'],
                        $quantity,
                        $charge,
                        $link
                    );
                    Notify::orderPlaced(
                        (int) $orderId,
                        $buyerRow['username'],
                        $buyerRow['email'],
                        $service['name'],
                        $quantity,
                        $charge,
                        $link
                    );
                } catch (Throwable $e) {
                    Logger::log('Order placed email failed #' . $orderId . ': ' . $e->getMessage(), 'mail');
                }
            }

            return ['success' => true, 'order_id' => $orderId, 'charge' => $charge];
        } catch (Throwable $e) {
            $this->db->rollBack();
            if (class_exists('Logger')) {
                Logger::log('placeOrder insert failed after provider OK: ' . $e->getMessage(), 'orders');
            }
            $this->refundCharge($userId, $charge);
            return ['success' => false, 'error' => 'Order placed at provider but local save failed. Contact support with your link and service ID.'];
        }
    }

    /** Statuses that have not finished delivery and may be cancelled. */
    public static function cancellableStatuses(): array
    {
        return ['Pending', 'Processing', 'In progress'];
    }

    /**
     * Cancel an unfinished order and refund the charge to the buyer.
     * Tries the provider first when a provider order id exists.
     *
     * @return array{success: bool, error?: string, refunded?: float}
     */
    public function cancelOrder(int $orderId, bool $asAdmin = false): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'error' => 'Invalid order.'];
        }

        $order = $this->db->fetch(
            "SELECT id, user_id, charge, status, provider_order_id, service_name FROM orders WHERE id = ?",
            [$orderId]
        );
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }
        $status = (string) ($order['status'] ?? '');
        if (!in_array($status, self::cancellableStatuses(), true)) {
            return ['success' => false, 'error' => 'Only unfinished orders can be cancelled.'];
        }

        $already = $this->db->fetch(
            "SELECT id FROM transactions WHERE type = 'refund' AND reference = ? LIMIT 1",
            [(string) $orderId]
        );
        if ($already) {
            return ['success' => false, 'error' => 'This order was already refunded.'];
        }

        if (!empty($order['provider_order_id'])) {
            $api = ProviderRegistry::apiForOrder($this->db, $orderId);
            if ($api) {
                $providerResp = $api->cancel([(int) $order['provider_order_id']]);
                $providerItem = is_array($providerResp) ? ($providerResp[0] ?? null) : null;
                if (is_array($providerItem) && isset($providerItem['cancel']['error'])) {
                    $msg = trim((string) $providerItem['cancel']['error']);
                    return ['success' => false, 'error' => $msg !== '' ? $msg : 'Provider rejected the cancel request.'];
                }
            }
        }

        $charge = round((float) $order['charge'], 4);
        $userId = (int) $order['user_id'];

        try {
            $this->db->beginTransaction();
            $locked = $this->db->fetch(
                "SELECT id, user_id, charge, status FROM orders WHERE id = ? FOR UPDATE",
                [$orderId]
            );
            if (!$locked || !in_array((string) $locked['status'], self::cancellableStatuses(), true)) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Order status changed. Refresh and try again.'];
            }
            $dup = $this->db->fetch(
                "SELECT id FROM transactions WHERE type = 'refund' AND reference = ? LIMIT 1",
                [(string) $orderId]
            );
            if ($dup) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'This order was already refunded.'];
            }

            $updated = $this->db->execute(
                "UPDATE orders SET status = 'Cancelled' WHERE id = ? AND status IN ('Pending','Processing','In progress')",
                [$orderId]
            );
            if ($updated === 0) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Could not cancel this order.'];
            }

            $userRow = $this->db->fetch("SELECT balance, username, email FROM users WHERE id = ? FOR UPDATE", [$userId]);
            if (!$userRow) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'User not found.'];
            }
            $balanceBefore = (float) ($userRow['balance'] ?? 0);
            $this->db->execute(
                "UPDATE users SET balance = balance + ?, spent = GREATEST(spent - ?, 0) WHERE id = ?",
                [$charge, $charge, $userId]
            );
            $balanceAfter = round($balanceBefore + $charge, 4);
            $who = $asAdmin ? 'admin' : 'user';
            $this->db->insert(
                "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference, status)
                 VALUES (?, 'refund', ?, ?, ?, ?, ?, 'completed')",
                [$userId, $charge, $balanceBefore, $balanceAfter, "Refund order #{$orderId} ({$who} cancel)", (string) $orderId]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            if (class_exists('Logger')) {
                Logger::log("cancelOrder #{$orderId}: " . $e->getMessage(), 'orders');
            }
            return ['success' => false, 'error' => 'Could not cancel order. Try again.'];
        }

        if (class_exists('Logger')) {
            Logger::log("Order #{$orderId} cancelled by {$who}, refunded {$charge} to user #{$userId}", 'orders');
        }

        if (!empty($userRow['email'])) {
            try {
                $mail = new Mail();
                $mail->sendOrderStatusUpdate(
                    (string) $userRow['email'],
                    (string) ($userRow['username'] ?? ''),
                    $orderId,
                    (string) ($order['service_name'] ?? ''),
                    'Cancelled'
                );
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::log('Order cancel email failed #' . $orderId, 'mail');
                }
            }
        }

        return ['success' => true, 'refunded' => $charge];
    }

    private function refundCharge(int $userId, float $charge): void {
        try {
            $this->db->beginTransaction();
            $this->db->execute(
                "UPDATE users SET balance = balance + ?, spent = GREATEST(spent - ?, 0) WHERE id = ?",
                [$charge, $charge, $userId]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            if (class_exists('Logger')) {
                Logger::log("refundCharge failed user#{$userId}: " . $e->getMessage(), 'orders');
            }
        }
    }

    /** Idempotent provider-driven refund (cancel / partial / refunded). */
    private function creditOrderRefund(int $orderId, int $userId, float $amount, string $reason): void
    {
        $amount = round(max(0, $amount), 4);
        if ($amount <= 0 || $orderId <= 0 || $userId <= 0) {
            return;
        }
        $ref = (string) $orderId;
        try {
            $this->db->beginTransaction();
            $dup = $this->db->fetch(
                "SELECT id FROM transactions WHERE type = 'refund' AND reference = ? LIMIT 1",
                [$ref]
            );
            if ($dup) {
                $this->db->rollBack();
                return;
            }
            $userRow = $this->db->fetch("SELECT balance FROM users WHERE id = ? FOR UPDATE", [$userId]);
            if (!$userRow) {
                $this->db->rollBack();
                return;
            }
            $before = (float) ($userRow['balance'] ?? 0);
            $this->db->execute(
                "UPDATE users SET balance = balance + ?, spent = GREATEST(spent - ?, 0) WHERE id = ?",
                [$amount, $amount, $userId]
            );
            $this->db->insert(
                "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference, status)
                 VALUES (?, 'refund', ?, ?, ?, ?, ?, 'completed')",
                [$userId, $amount, $before, round($before + $amount, 4), $reason . ' #' . $orderId, $ref]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            if (class_exists('Logger')) {
                Logger::log("creditOrderRefund #{$orderId}: " . $e->getMessage(), 'orders');
            }
        }
    }

    public function syncOrders(): int {
        $orders = $this->db->fetchAll(
            "SELECT id, provider, provider_order_id FROM orders
             WHERE status IN ('Pending','Processing','In progress') AND provider_order_id IS NOT NULL
             ORDER BY updated_at ASC LIMIT 200"
        );
        if (empty($orders)) {
            return 0;
        }

        $byProvider = [];
        foreach ($orders as $order) {
            $slug = $order['provider'] ?? ProviderRegistry::PRIMARY;
            $byProvider[$slug][] = $order;
        }

        $updated = 0;
        foreach ($byProvider as $slug => $providerOrders) {
            $api = ProviderRegistry::api($slug);
            if (!$api) {
                continue;
            }
            $providerIds = array_column($providerOrders, 'provider_order_id');
            $statuses = $api->multiStatus($providerIds);
            if (!$statuses) {
                continue;
            }
            foreach ($providerOrders as $order) {
                $pid = $order['provider_order_id'];
                $status = $statuses->$pid ?? null;
                if (!$status || isset($status->error)) {
                    continue;
                }
                try {
                    $row = $this->db->fetch(
                        "SELECT o.status, o.start_count, o.remains, o.user_id, o.service_name, o.charge, o.quantity, u.username, u.email
                         FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?",
                        [$order['id']]
                    );
                    $newStatus = (string) ($status->status ?? '');
                    $oldStatus = (string) ($row['status'] ?? '');
                    if ($newStatus === '') {
                        $newStatus = $oldStatus !== '' ? $oldStatus : 'Pending';
                    }
                    $startCount = (int) ($status->start_count ?? 0);
                    $remains = (int) ($status->remains ?? 0);
                    $changed = $oldStatus !== $newStatus
                        || (int) ($row['start_count'] ?? 0) !== $startCount
                        || (int) ($row['remains'] ?? 0) !== $remains;
                    if ($changed) {
                        $this->db->execute(
                            'UPDATE orders SET status = ?, start_count = ?, remains = ? WHERE id = ?',
                            [$newStatus, $startCount, $remains, $order['id']]
                        );
                        $updated++;
                    }
                    if ($row && $oldStatus !== $newStatus && in_array($newStatus, ['Completed', 'Canceled', 'Cancelled', 'Partial', 'Refunded'], true)) {
                        if (in_array($newStatus, ['Canceled', 'Cancelled', 'Refunded'], true)) {
                            $this->creditOrderRefund(
                                (int) $order['id'],
                                (int) $row['user_id'],
                                (float) $row['charge'],
                                'Provider ' . $newStatus
                            );
                        } elseif ($newStatus === 'Partial') {
                            $qty = max(1, (int) ($row['quantity'] ?? 1));
                            $partial = round((float) $row['charge'] * ($remains / $qty), 4);
                            if ($partial > 0) {
                                $this->creditOrderRefund(
                                    (int) $order['id'],
                                    (int) $row['user_id'],
                                    $partial,
                                    'Provider partial refund'
                                );
                            }
                        }
                        if (!empty($row['email'])) {
                            try {
                                $mail = new Mail();
                                $mail->sendOrderStatusUpdate(
                                    $row['email'],
                                    $row['username'],
                                    (int) $order['id'],
                                    $row['service_name'] ?? '',
                                    $newStatus
                                );
                            } catch (Throwable $e) {
                                Logger::log('Order status email failed #' . $order['id'], 'mail');
                            }
                        }
                    }
                } catch (Throwable $e) {
                    Logger::log('Order sync row #' . $order['id'] . ': ' . $e->getMessage(), 'orders');
                }
            }
        }
        return $updated;
    }

    public function getUserOrders(int $userId, string $status = '', int $limit = 50, int $offset = 0): array {
        $where  = "WHERE o.user_id = ?";
        $params = [$userId];
        if ($status) { $where .= " AND o.status = ?"; $params[] = $status; }

        return $this->db->fetchAll(
            "SELECT o.*, s.category FROM orders o LEFT JOIN services s ON o.service_id = s.service_id
             $where ORDER BY o.created_at DESC LIMIT $limit OFFSET $offset",
            $params
        );
    }

    public function getUserOrderCount(int $userId, string $status = ''): int {
        $where  = "WHERE user_id = ?";
        $params = [$userId];
        if ($status) { $where .= " AND status = ?"; $params[] = $status; }
        $row = $this->db->fetch("SELECT COUNT(*) as cnt FROM orders $where", $params);
        return (int)($row['cnt'] ?? 0);
    }

    public function syncServices(?string $onlyProvider = null): array {
        $this->ensureServicesColumnWidths();
        self::ensureProviderSchema();

        $markup = (float)($this->db->getSetting('markup_percent') ?? MARKUP_PERCENT);
        $totalSynced = 0;
        $totalFailed = 0;
        $errors = [];

        $providers = $onlyProvider ? [$onlyProvider] : array_keys(ProviderRegistry::definitions());
        foreach ($providers as $slug) {
            if (!ProviderRegistry::isEnabled($slug)) {
                continue;
            }
            $api = ProviderRegistry::api($slug);
            if (!$api) {
                $def = ProviderRegistry::definitions()[$slug];
                $errors[] = $def['name'] . ': API key missing';
                continue;
            }
            $test = $api->testConnection();
            if (!$test['success']) {
                $errors[] = ProviderRegistry::definitions()[$slug]['name'] . ': ' . ($test['error'] ?? 'connection failed');
                continue;
            }

            $services = $api->services();
            if (!$services) {
                $errors[] = ProviderRegistry::definitions()[$slug]['name'] . ': could not fetch services';
                continue;
            }

            foreach ($services as $s) {
                $upstreamId = (int) ($s['service'] ?? 0);
                if ($upstreamId <= 0) {
                    $totalFailed++;
                    continue;
                }
                $panelId = ProviderRegistry::panelServiceId($slug, $upstreamId);
                $name = ContentCorrections::correctServiceName($s['name'] ?? '');
                $category = ProviderRegistry::formatServiceCategory($slug, $s['category'] ?? '');
                $type = ContentCorrections::fitServiceType($s['type'] ?? 'Default');
                try {
                    $this->db->execute(
                        "INSERT INTO services (service_id, provider, provider_service_id, name, type, category, rate, min, max, refill, cancel, markup)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE provider=VALUES(provider), provider_service_id=VALUES(provider_service_id),
                         name=VALUES(name), type=VALUES(type), category=VALUES(category),
                         rate=VALUES(rate), min=VALUES(min), max=VALUES(max), refill=VALUES(refill), cancel=VALUES(cancel)",
                        [
                            $panelId, $slug, $upstreamId, $name, $type, $category,
                            $s['rate'], $s['min'], $s['max'],
                            ($s['refill'] ?? false) ? 1 : 0, ($s['cancel'] ?? false) ? 1 : 0, $markup,
                        ]
                    );
                    $totalSynced++;
                } catch (Throwable $e) {
                    if (str_contains($e->getMessage(), 'Data too long') || str_contains($e->getMessage(), '1406')) {
                        try {
                            $categoryShort = ProviderRegistry::formatServiceCategory($slug, $s['category'] ?? '');
                            $typeShort = ContentCorrections::fitServiceType($s['type'] ?? 'Default', 50);
                            $this->db->execute(
                                "INSERT INTO services (service_id, provider, provider_service_id, name, type, category, rate, min, max, refill, cancel, markup)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE provider=VALUES(provider), provider_service_id=VALUES(provider_service_id),
                                 name=VALUES(name), type=VALUES(type), category=VALUES(category),
                                 rate=VALUES(rate), min=VALUES(min), max=VALUES(max), refill=VALUES(refill), cancel=VALUES(cancel)",
                                [
                                    $panelId, $slug, $upstreamId, $name, $typeShort, $categoryShort,
                                    $s['rate'], $s['min'], $s['max'],
                                    ($s['refill'] ?? false) ? 1 : 0, ($s['cancel'] ?? false) ? 1 : 0, $markup,
                                ]
                            );
                            $totalSynced++;
                            continue;
                        } catch (Throwable $e2) {
                            $e = $e2;
                        }
                    }
                    $totalFailed++;
                    if (class_exists('Logger')) {
                        Logger::log("syncServices {$slug} skip #{$upstreamId}: " . $e->getMessage(), 'sync');
                    }
                }
            }
        }

        if ($totalSynced === 0 && $errors !== []) {
            return ['success' => false, 'error' => implode('; ', $errors)];
        }

        $dedupe = new ServiceDeduper();
        $dedupeStats = $dedupe->run($onlyProvider);

        return [
            'success' => true,
            'synced' => $totalSynced,
            'failed' => $totalFailed,
            'errors' => $errors,
            'deduped' => $dedupeStats['by_upstream_id'] + $dedupeStats['by_name'],
            'dedupe_stats' => $dedupeStats,
        ];
    }

    private function ensureServicesColumnWidths(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $pdo = $this->db->getConnection();
            $pdo->exec('ALTER TABLE services MODIFY COLUMN category VARCHAR(255) DEFAULT NULL');
            $pdo->exec("ALTER TABLE services MODIFY COLUMN type VARCHAR(100) DEFAULT 'Default'");
        } catch (Throwable $e) {
            /* already wide enough */
        }
    }

    public static function ensureProviderSchema(): bool {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $db = Database::getInstance();
        if ($db->columnExists('services', 'provider')) {
            $ready = true;
            return true;
        }

        $pdo = $db->getConnection();
        foreach ([
            "ALTER TABLE services ADD COLUMN provider VARCHAR(32) NOT NULL DEFAULT 'smmfollows'",
            'ALTER TABLE services ADD COLUMN provider_service_id INT UNSIGNED NOT NULL DEFAULT 0',
            "ALTER TABLE orders ADD COLUMN provider VARCHAR(32) NOT NULL DEFAULT 'smmfollows'",
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (!str_contains($msg, 'Duplicate column') && !str_contains($msg, 'already exists')) {
                    Logger::log('ensureProviderSchema: ' . $msg, 'schema');
                }
            }
        }
        try {
            $db->execute(
                "UPDATE services SET provider = 'smmfollows', provider_service_id = service_id
                 WHERE provider_service_id = 0 OR provider = ''"
            );
        } catch (Throwable $e) {
            Logger::log('ensureProviderSchema backfill: ' . $e->getMessage(), 'schema');
        }

        $ready = $db->columnExists('services', 'provider');
        if (!$ready) {
            Logger::log('services.provider still missing — run: php public_html/migrate-db.php', 'schema');
        }
        return $ready;
    }
}
