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

    public function isCancellable(?string $status): bool
    {
        return in_array((string) $status, self::cancellableStatuses(), true);
    }

    /** Panel status values stored on orders.status. */
    public static function workflowStatuses(): array
    {
        return ['Pending', 'Processing', 'In progress', 'Completed', 'Partial', 'Cancelled', 'Refunded'];
    }

    /** Admin may set these without moving money. Cancel / Partial / Refunded have dedicated methods. */
    public static function manualStatuses(): array
    {
        return ['Pending', 'Processing', 'In progress', 'Completed'];
    }

    /** Map provider spelling (Canceled, inprogress, …) onto the orders.status enum. */
    public static function normalizeStatus(string $status): string
    {
        $key = strtolower(trim(str_replace(['_', '-'], ' ', $status)));
        $key = preg_replace('/\s+/', ' ', $key) ?? $key;
        return match ($key) {
            'pending' => 'Pending',
            'processing' => 'Processing',
            'in progress', 'inprogress', 'in process' => 'In progress',
            'completed', 'complete' => 'Completed',
            'partial' => 'Partial',
            'cancelled', 'canceled' => 'Cancelled',
            'refunded', 'refund' => 'Refunded',
            default => '',
        };
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

        $providerWarning = '';
        if (!empty($order['provider_order_id'])) {
            $cancelResult = $this->requestProviderCancel($orderId, (int) $order['provider_order_id']);
            if (!$cancelResult['ok']) {
                $providerWarning = $cancelResult['error'] ?? 'Provider rejected the cancel request.';
                if (!$asAdmin || $status !== 'Pending') {
                    return ['success' => false, 'error' => $providerWarning];
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

        $out = ['success' => true, 'refunded' => $charge];
        if ($providerWarning !== '') {
            $out['warning'] = $providerWarning;
        }
        return $out;
    }

    /** @return array{ok:bool, error?:string} */
    private function requestProviderCancel(int $orderId, int $providerOrderId): array
    {
        $api = ProviderRegistry::apiForOrder($this->db, $orderId);
        if (!$api) {
            return ['ok' => false, 'error' => 'Provider API is not configured.'];
        }
        try {
            $providerResp = $api->cancel([$providerOrderId]);
        } catch (Throwable $e) {
            Logger::log("provider cancel failed #{$orderId}: " . $e->getMessage(), 'orders');
            return ['ok' => false, 'error' => 'Provider cancel request failed.'];
        }
        if (!is_array($providerResp)) {
            return ['ok' => false, 'error' => 'Provider did not accept the cancel request.'];
        }
        if (isset($providerResp['error'])) {
            $err = trim((string) $providerResp['error']);
            if ($this->isBenignProviderCancelError($err)) {
                return ['ok' => true];
            }
            return ['ok' => false, 'error' => $err !== '' ? $err : 'Provider refused to cancel.'];
        }
        $item = $providerResp[0] ?? null;
        if (is_array($item) && isset($item['cancel']['error'])) {
            $err = trim((string) $item['cancel']['error']);
            if ($this->isBenignProviderCancelError($err)) {
                return ['ok' => true];
            }
            return ['ok' => false, 'error' => $err !== '' ? $err : 'Provider refused to cancel.'];
        }
        return ['ok' => true];
    }

    private function isBenignProviderCancelError(string $error): bool
    {
        $e = strtolower($error);
        if ($e === '') {
            return false;
        }
        return str_contains($e, 'already')
            || str_contains($e, 'not found')
            || str_contains($e, 'incorrect order')
            || str_contains($e, 'invalid order');
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

    public function syncOrders(?int $userId = null, int $limit = 200): int {
        $limit = max(1, min(200, $limit));
        $sql = "SELECT id, provider, provider_order_id FROM orders
             WHERE status IN ('Pending','Processing','In progress') AND provider_order_id IS NOT NULL AND provider_order_id > 0";
        $params = [];
        if ($userId !== null && $userId > 0) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY updated_at ASC LIMIT ' . $limit;
        $orders = $this->db->fetchAll($sql, $params);
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
                    $result = $this->applyProviderStatus((int) $order['id'], $status);
                    if (!empty($result['changed'])) {
                        $updated++;
                    }
                } catch (Throwable $e) {
                    Logger::log('Order sync row #' . $order['id'] . ': ' . $e->getMessage(), 'orders');
                }
            }
        }
        return $updated;
    }

    /**
     * @return array{changed: bool, old: string, new: string}
     */
    private function applyProviderStatus(int $orderId, object $status): array
    {
        $row = $this->db->fetch(
            "SELECT o.status, o.start_count, o.remains, o.user_id, o.service_name, o.charge, o.quantity, u.username, u.email
             FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?",
            [$orderId]
        );
        if (!$row) {
            return ['changed' => false, 'old' => '', 'new' => ''];
        }
        $oldStatus = (string) ($row['status'] ?? '');
        $normalized = self::normalizeStatus((string) ($status->status ?? ''));
        $newStatus = $normalized !== '' ? $normalized : ($oldStatus !== '' ? $oldStatus : 'Pending');
        $startCount = (int) ($status->start_count ?? 0);
        $remains = (int) ($status->remains ?? 0);
        $recount = (int) ($status->recount ?? 0);
        $changed = $oldStatus !== $newStatus
            || (int) ($row['start_count'] ?? 0) !== $startCount
            || (int) ($row['remains'] ?? 0) !== $remains;
        if ($changed) {
            $this->updateOrderProgress($orderId, $newStatus, $startCount, $remains, $recount);
        }
        if ($oldStatus !== $newStatus && in_array($newStatus, ['Completed', 'Cancelled', 'Partial', 'Refunded'], true)) {
            if (in_array($newStatus, ['Cancelled', 'Refunded'], true)) {
                $this->creditOrderRefund(
                    $orderId,
                    (int) $row['user_id'],
                    (float) $row['charge'],
                    'Provider ' . $newStatus
                );
            } elseif ($newStatus === 'Partial') {
                $qty = max(1, (int) ($row['quantity'] ?? 1));
                $partial = round((float) $row['charge'] * ($remains / $qty), 4);
                if ($partial > 0) {
                    $this->creditOrderRefund(
                        $orderId,
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
                        $orderId,
                        $row['service_name'] ?? '',
                        $newStatus
                    );
                } catch (Throwable $e) {
                    Logger::log('Order status email failed #' . $orderId, 'mail');
                }
            }
        }
        return ['changed' => $changed, 'old' => $oldStatus, 'new' => $newStatus];
    }

    /** Counts for admin chips. `_all` total, `_stuck` pending with no provider id. */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(self::workflowStatuses(), 0);
        $total = 0;
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) c FROM orders GROUP BY status') as $row) {
            $status = (string) ($row['status'] ?? '');
            $n = (int) ($row['c'] ?? 0);
            if (isset($counts[$status])) {
                $counts[$status] = $n;
            }
            $total += $n;
        }
        $stuck = $this->db->fetch(
            "SELECT COUNT(*) c FROM orders WHERE status IN ('Pending','Processing','In progress') AND (provider_order_id IS NULL OR provider_order_id = 0)"
        );
        $counts['_all'] = $total;
        $counts['_stuck'] = (int) ($stuck['c'] ?? 0);
        return $counts;
    }

    /**
     * @return array{success: bool, error?: string, old?: string, new?: string}
     */
    public function syncOrderById(int $orderId): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'error' => 'Invalid order.'];
        }
        $order = $this->db->fetch(
            'SELECT id, status, provider_order_id FROM orders WHERE id = ?',
            [$orderId]
        );
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }
        $pid = (int) ($order['provider_order_id'] ?? 0);
        if ($pid <= 0) {
            return ['success' => false, 'error' => 'No provider order ID. Resubmit this order first.'];
        }
        $api = ProviderRegistry::apiForOrder($this->db, $orderId);
        if (!$api) {
            return ['success' => false, 'error' => 'Provider API is not configured.'];
        }
        $status = $api->status($pid);
        if (!$status || isset($status->error)) {
            $msg = is_object($status) ? trim((string) ($status->error ?? '')) : '';
            return ['success' => false, 'error' => $msg !== '' ? $msg : 'Provider did not return a status.'];
        }
        $result = $this->applyProviderStatus($orderId, $status);
        return [
            'success' => true,
            'old' => $result['old'],
            'new' => $result['new'],
            'changed' => $result['changed'],
        ];
    }

    /**
     * @param list<int> $orderIds
     * @return array{success: bool, updated: int, skipped: int, error?: string}
     */
    public function syncOrdersByIds(array $orderIds): array
    {
        $ids = [];
        foreach ($orderIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return ['success' => false, 'updated' => 0, 'skipped' => 0, 'error' => 'Select at least one order.'];
        }
        if (count($ids) > 50) {
            return ['success' => false, 'updated' => 0, 'skipped' => 0, 'error' => 'Select at most 50 orders at a time.'];
        }
        $updated = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $result = $this->syncOrderById($id);
            if (!empty($result['success']) && !empty($result['changed'])) {
                $updated++;
            } else {
                $skipped++;
            }
        }
        return ['success' => true, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Send a stuck unfinished order to the provider. Does not charge the customer again.
     *
     * @return array{success: bool, error?: string, provider_order_id?: int}
     */
    public function resubmitToProvider(int $orderId, int $adminId = 0): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'error' => 'Invalid order.'];
        }
        self::ensureProviderSchema();
        try {
            $this->db->beginTransaction();
            $order = $this->db->fetch('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$order) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Order not found.'];
            }
            if (!in_array((string) $order['status'], self::cancellableStatuses(), true)) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Only unfinished orders can be sent to the provider.'];
            }
            if ((int) ($order['provider_order_id'] ?? 0) > 0) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'This order already has a provider ID. Use Sync instead.'];
            }
            $service = $this->db->fetch('SELECT * FROM services WHERE service_id = ?', [(int) $order['service_id']]);
            if (!$service) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Service not found for this order.'];
            }
            $api = $this->apiForService($service);
            if (!$api) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Provider API is not configured for this service.'];
            }
            $upstreamId = ProviderRegistry::upstreamServiceId($service);
            $provider = ProviderRegistry::providerForService($service);
            $response = $api->order([
                'service' => $upstreamId,
                'link' => (string) $order['link'],
                'quantity' => (int) $order['quantity'],
            ]);
            if (!$response || isset($response->error)) {
                $this->db->rollBack();
                $msg = is_object($response) ? trim((string) ($response->error ?? '')) : '';
                return ['success' => false, 'error' => $msg !== '' ? $msg : 'Provider rejected the order.'];
            }
            $newPid = (int) ($response->order ?? 0);
            if ($newPid <= 0) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Provider accepted the order but returned no ID. Check the provider panel.'];
            }
            $this->db->execute(
                'UPDATE orders SET provider_order_id = ?, provider = ? WHERE id = ? AND (provider_order_id IS NULL OR provider_order_id = 0)',
                [$newPid, $provider, $orderId]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            if (class_exists('Logger')) {
                Logger::log("resubmitToProvider #{$orderId}: " . $e->getMessage(), 'orders');
            }
            return ['success' => false, 'error' => 'Could not resubmit this order. Try again.'];
        }
        if (class_exists('Logger')) {
            Logger::log("Order #{$orderId} resubmitted by admin#{$adminId} provider_order={$newPid}", 'orders');
        }
        return ['success' => true, 'provider_order_id' => $newPid];
    }

    /**
     * @return array{success: bool, sent: int, failed: int, errors: list<string>}
     */
    public function resubmitStuckPending(int $limit = 20, int $adminId = 0): array
    {
        $limit = max(1, min(20, $limit));
        $rows = $this->db->fetchAll(
            "SELECT id FROM orders
             WHERE status IN ('Pending','Processing','In progress') AND (provider_order_id IS NULL OR provider_order_id = 0)
             ORDER BY id ASC LIMIT {$limit}"
        );
        $sent = 0;
        $failed = 0;
        $errors = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $result = $this->resubmitToProvider($id, $adminId);
            if (!empty($result['success'])) {
                $sent++;
            } else {
                $failed++;
                $errors[] = '#' . $id . ': ' . ($result['error'] ?? 'failed');
            }
        }
        return ['success' => $sent > 0 || $failed === 0, 'sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function updateOrderLink(int $orderId, string $link, int $adminId = 0): array
    {
        $link = trim($link);
        if ($orderId <= 0) {
            return ['success' => false, 'error' => 'Invalid order.'];
        }
        if ($link === '' || mb_strlen($link) > 2000) {
            return ['success' => false, 'error' => 'Enter a valid link (max 2000 characters).'];
        }
        $order = $this->db->fetch('SELECT id, status FROM orders WHERE id = ?', [$orderId]);
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }
        if (!in_array((string) $order['status'], self::cancellableStatuses(), true)) {
            return ['success' => false, 'error' => 'The link can only be edited on unfinished orders.'];
        }
        $this->db->execute('UPDATE orders SET link = ? WHERE id = ?', [$link, $orderId]);
        if (class_exists('Logger')) {
            Logger::log("Order #{$orderId} link updated by admin#{$adminId}", 'orders');
        }
        return ['success' => true];
    }

    /**
     * Change status without moving money. Cancel / Partial / Refunded are rejected.
     *
     * @return array{success: bool, error?: string}
     */
    public function setManualStatus(int $orderId, string $status, int $adminId = 0): array
    {
        $status = self::normalizeStatus($status);
        if ($orderId <= 0 || !in_array($status, self::manualStatuses(), true)) {
            return ['success' => false, 'error' => 'Use Cancel to refund, or Partial to credit unused quantity. Manual status is Pending, Processing, In progress, or Completed.'];
        }
        try {
            $this->db->beginTransaction();
            $order = $this->db->fetch('SELECT id, status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$order) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Order not found.'];
            }
            $old = (string) ($order['status'] ?? '');
            if (in_array($old, ['Cancelled', 'Refunded'], true)) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Cancelled or refunded orders cannot be reopened.'];
            }
            if ($old === $status) {
                $this->db->rollBack();
                return ['success' => true];
            }
            $this->db->execute('UPDATE orders SET status = ? WHERE id = ?', [$status, $orderId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            if (class_exists('Logger')) {
                Logger::log("setManualStatus #{$orderId}: " . $e->getMessage(), 'orders');
            }
            return ['success' => false, 'error' => 'Could not update status.'];
        }
        if (class_exists('Logger')) {
            Logger::log("Order #{$orderId} status {$old} → {$status} by admin#{$adminId} (manual, no refund)", 'orders');
        }
        return ['success' => true];
    }

    /**
     * Mark partial and refund the undelivered share. Idempotent via creditOrderRefund.
     *
     * @return array{success: bool, error?: string, refunded?: float}
     */
    public function setPartial(int $orderId, int $remains, int $adminId = 0): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'error' => 'Invalid order.'];
        }
        $refund = 0.0;
        try {
            $this->db->beginTransaction();
            $order = $this->db->fetch('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$order) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Order not found.'];
            }
            $old = (string) ($order['status'] ?? '');
            if (!in_array($old, array_merge(self::cancellableStatuses(), ['Partial']), true)) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Only unfinished orders can be marked partial.'];
            }
            $qty = max(1, (int) $order['quantity']);
            if ($remains < 0 || $remains >= $qty) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Remains must be between 0 and quantity − 1. Use Cancel to refund the full charge.'];
            }
            $charge = (float) $order['charge'];
            $refund = round($charge * ($remains / $qty), 4);
            $this->db->execute(
                "UPDATE orders SET status = 'Partial', remains = ? WHERE id = ?",
                [$remains, $orderId]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            if (class_exists('Logger')) {
                Logger::log("setPartial #{$orderId}: " . $e->getMessage(), 'orders');
            }
            return ['success' => false, 'error' => 'Could not mark this order partial.'];
        }
        if ($refund > 0) {
            $this->creditOrderRefund($orderId, (int) $order['user_id'], $refund, 'Admin partial refund');
        }
        if (class_exists('Logger')) {
            Logger::log("Order #{$orderId} marked Partial remains={$remains} refund={$refund} by admin#{$adminId}", 'orders');
        }
        return ['success' => true, 'refunded' => $refund];
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function refillOrder(int $orderId, int $adminId = 0): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'error' => 'Invalid order.'];
        }
        $order = $this->db->fetch('SELECT id, status, provider_order_id FROM orders WHERE id = ?', [$orderId]);
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }
        if ((string) $order['status'] !== 'Completed') {
            return ['success' => false, 'error' => 'Refill is only available for completed orders.'];
        }
        $pid = (int) ($order['provider_order_id'] ?? 0);
        if ($pid <= 0) {
            return ['success' => false, 'error' => 'No provider order ID to refill.'];
        }
        $api = ProviderRegistry::apiForOrder($this->db, $orderId);
        if (!$api) {
            return ['success' => false, 'error' => 'Provider API is not configured.'];
        }
        $response = $api->refill($pid);
        if (!$response || isset($response->error)) {
            $msg = is_object($response) ? trim((string) ($response->error ?? '')) : '';
            return ['success' => false, 'error' => $msg !== '' ? $msg : 'Provider rejected the refill.'];
        }
        if (class_exists('Logger')) {
            Logger::log("Order #{$orderId} refill requested by admin#{$adminId} provider_order={$pid}", 'orders');
        }
        return ['success' => true];
    }

    /**
     * Provider APIs sometimes send recount as "". MySQL strict mode rejects that
     * on an INT column. Always persist a real integer; retry without recount
     * when the column does not exist yet.
     */
    private function updateOrderProgress(int $orderId, string $status, int $startCount, int $remains, int $recount = 0): void
    {
        try {
            $this->db->execute(
                'UPDATE orders SET status = ?, start_count = ?, remains = ?, recount = ? WHERE id = ?',
                [$status, $startCount, $remains, $recount, $orderId]
            );
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'recount')) {
                throw $e;
            }
            $this->db->execute(
                'UPDATE orders SET status = ?, start_count = ?, remains = ? WHERE id = ?',
                [$status, $startCount, $remains, $orderId]
            );
        }
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
