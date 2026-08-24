<?php
/**
 * Coupons, VIP tiers, deposit bonus, featured services — revenue growth engine.
 */
class RevenueEngine
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        self::ensureSchema($this->db);
    }

    public static function ensureSchema(Database $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $pdo = $db->getConnection();
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS coupons (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(32) NOT NULL,
                    type ENUM('order_percent','order_fixed','deposit_percent','deposit_fixed') NOT NULL DEFAULT 'order_percent',
                    value DECIMAL(10,4) NOT NULL DEFAULT 0,
                    min_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                    max_uses INT UNSIGNED NOT NULL DEFAULT 0,
                    uses_count INT UNSIGNED NOT NULL DEFAULT 0,
                    per_user_limit INT UNSIGNED NOT NULL DEFAULT 1,
                    expires_at DATETIME DEFAULT NULL,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    note VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_coupons_code (code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS coupon_uses (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    coupon_id INT UNSIGNED NOT NULL,
                    user_id INT UNSIGNED NOT NULL,
                    context ENUM('order','deposit') NOT NULL,
                    reference_id INT UNSIGNED DEFAULT NULL,
                    discount_amount DECIMAL(12,4) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_coupon_user (coupon_id, user_id),
                    KEY idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (PDOException $e) {
            /* tables may exist */
        }
        foreach (['is_featured' => 'TINYINT(1) NOT NULL DEFAULT 0', 'sort_priority' => 'INT NOT NULL DEFAULT 0'] as $col => $def) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute(['services', $col]);
            if ((int) $stmt->fetchColumn() === 0) {
                try {
                    $pdo->exec("ALTER TABLE services ADD COLUMN `$col` $def");
                } catch (PDOException $e) {
                    /* ignore */
                }
            }
        }
    }

    /** @return array{name: string, discount_percent: float, next_tier: ?string, next_at: ?float} */
    public function vipTier(int $userId): array
    {
        $spent = (float) ($this->db->fetch('SELECT spent FROM users WHERE id = ?', [$userId])['spent'] ?? 0);
        $platinumAt = (float) ($this->db->getSetting('revenue_vip_platinum_spent') ?: 2000);
        $platinumDisc = (float) ($this->db->getSetting('revenue_vip_platinum_discount') ?: 10);
        $goldAt = (float) ($this->db->getSetting('revenue_vip_gold_spent') ?: 500);
        $goldDisc = (float) ($this->db->getSetting('revenue_vip_gold_discount') ?: 5);
        $silverAt = (float) ($this->db->getSetting('revenue_vip_silver_spent') ?: 100);
        $silverDisc = (float) ($this->db->getSetting('revenue_vip_silver_discount') ?: 2);

        if ($spent >= $platinumAt) {
            return ['name' => 'Platinum', 'discount_percent' => $platinumDisc, 'next_tier' => null, 'next_at' => null];
        }
        if ($spent >= $goldAt) {
            return ['name' => 'Gold', 'discount_percent' => $goldDisc, 'next_tier' => 'Platinum', 'next_at' => $platinumAt];
        }
        if ($spent >= $silverAt) {
            return ['name' => 'Silver', 'discount_percent' => $silverDisc, 'next_tier' => 'Gold', 'next_at' => $goldAt];
        }
        return ['name' => 'Bronze', 'discount_percent' => 0.0, 'next_tier' => 'Silver', 'next_at' => $silverAt];
    }

    /**
     * @return array{charge: float, base: float, vip_discount: float, coupon_discount: float, coupon_id: ?int, coupon_error: ?string}
     */
    public function computeOrderCharge(int $userId, array $service, int $quantity, ?string $couponCode = null): array
    {
        $markup = 1 + ((float) ($service['markup'] ?? 0) / 100);
        $base = round(((float) $service['rate'] / 1000) * $quantity * $markup, 4);
        $vip = $this->vipTier($userId);
        $vipDiscount = round($base * ((float) $vip['discount_percent'] / 100), 4);
        $afterVip = round($base - $vipDiscount, 4);

        $couponDiscount = 0.0;
        $couponId = null;
        $couponError = null;
        $code = strtoupper(trim((string) $couponCode));
        if ($code !== '') {
            $check = $this->validateCoupon($code, $userId, 'order', $afterVip);
            if ($check['valid']) {
                $couponDiscount = (float) $check['discount'];
                $couponId = (int) $check['coupon_id'];
            } else {
                $couponError = $check['error'] ?? 'Invalid coupon';
            }
        }

        $charge = max(0.0001, round($afterVip - $couponDiscount, 4));
        return [
            'charge' => $charge,
            'base' => $base,
            'vip_discount' => $vipDiscount,
            'coupon_discount' => $couponDiscount,
            'coupon_id' => $couponId,
            'coupon_error' => $couponError,
        ];
    }

    public function retailRatePerThousand(array $service, int $userId = 0): float
    {
        $markup = 1 + ((float) ($service['markup'] ?? 0) / 100);
        $rate = round((float) $service['rate'] * $markup, 5);
        if ($userId > 0) {
            $vip = $this->vipTier($userId);
            $rate = round($rate * (1 - (float) $vip['discount_percent'] / 100), 5);
        }
        return $rate;
    }

    /** @return array{valid: bool, discount?: float, coupon_id?: int, error?: string} */
    public function validateCoupon(string $code, int $userId, string $context, float $amount): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['valid' => false, 'error' => 'Coupon code required.'];
        }
        $coupon = $this->db->fetch('SELECT * FROM coupons WHERE UPPER(code) = ? AND active = 1', [$code]);
        if (!$coupon) {
            return ['valid' => false, 'error' => 'Coupon not found or inactive.'];
        }
        $type = (string) ($coupon['type'] ?? '');
        $isOrder = str_starts_with($type, 'order_');
        $isDeposit = str_starts_with($type, 'deposit_');
        if (($context === 'order' && !$isOrder) || ($context === 'deposit' && !$isDeposit)) {
            return ['valid' => false, 'error' => 'This coupon cannot be used here.'];
        }
        if (!empty($coupon['expires_at']) && strtotime((string) $coupon['expires_at']) < time()) {
            return ['valid' => false, 'error' => 'Coupon expired.'];
        }
        $maxUses = (int) ($coupon['max_uses'] ?? 0);
        if ($maxUses > 0 && (int) ($coupon['uses_count'] ?? 0) >= $maxUses) {
            return ['valid' => false, 'error' => 'Coupon usage limit reached.'];
        }
        $perUser = (int) ($coupon['per_user_limit'] ?? 1);
        if ($perUser > 0) {
            $used = (int) $this->db->fetch(
                'SELECT COUNT(*) c FROM coupon_uses WHERE coupon_id = ? AND user_id = ? AND context = ?',
                [(int) $coupon['id'], $userId, $context]
            )['c'];
            if ($used >= $perUser) {
                return ['valid' => false, 'error' => 'You already used this coupon.'];
            }
        }
        $minAmount = (float) ($coupon['min_amount'] ?? 0);
        if ($minAmount > 0 && $amount < $minAmount) {
            return ['valid' => false, 'error' => 'Minimum amount for this coupon is $' . number_format($minAmount, 2) . '.'];
        }

        $value = (float) ($coupon['value'] ?? 0);
        $discount = match ($type) {
            'order_percent', 'deposit_percent' => round($amount * ($value / 100), 4),
            'order_fixed', 'deposit_fixed' => min($amount, $value),
            default => 0.0,
        };
        if ($discount <= 0) {
            return ['valid' => false, 'error' => 'Coupon has no discount value.'];
        }

        return ['valid' => true, 'discount' => $discount, 'coupon_id' => (int) $coupon['id']];
    }

    public function recordCouponUse(int $couponId, int $userId, string $context, float $discountAmount, ?int $referenceId = null): void
    {
        $this->db->insert(
            'INSERT INTO coupon_uses (coupon_id, user_id, context, reference_id, discount_amount) VALUES (?, ?, ?, ?, ?)',
            [$couponId, $userId, $context, $referenceId, $discountAmount]
        );
        $this->db->execute('UPDATE coupons SET uses_count = uses_count + 1 WHERE id = ?', [$couponId]);
    }

    /** Bonus credited after a completed deposit. */
    public function applyDepositBonus(int $userId, float $depositAmount, ?string $couponCode = null): array
    {
        $totalBonus = 0.0;
        $details = [];
        $firstOnly = ($this->db->getSetting('deposit_bonus_first_only') ?? '1') === '1';
        $completedCount = (int) $this->db->fetch(
            "SELECT COUNT(*) c FROM transactions WHERE user_id = ? AND type = 'deposit' AND status = 'completed'",
            [$userId]
        )['c'];
        $isFirst = $completedCount <= 1;

        if (!$firstOnly || $isFirst) {
            $pct = (float) ($this->db->getSetting('deposit_bonus_percent') ?: 0);
            if ($pct > 0) {
                $b = round($depositAmount * ($pct / 100), 4);
                $this->creditBonus($userId, $b, 'Deposit bonus: ' . $pct . '%');
                $totalBonus += $b;
                $details[] = $pct . '% deposit bonus';
            }
        }

        $code = strtoupper(trim((string) $couponCode));
        if ($code !== '') {
            $check = $this->validateCoupon($code, $userId, 'deposit', $depositAmount);
            if ($check['valid']) {
                $b = (float) $check['discount'];
                $this->creditBonus($userId, $b, 'Coupon bonus: ' . $code);
                $this->recordCouponUse((int) $check['coupon_id'], $userId, 'deposit', $b, null);
                $totalBonus += $b;
                $details[] = 'coupon ' . $code;
            }
        }

        return ['bonus' => $totalBonus, 'details' => $details];
    }

    private function creditBonus(int $userId, float $amount, string $description): void
    {
        if ($amount <= 0) {
            return;
        }
        try {
            $this->db->beginTransaction();
            $user = $this->db->fetch('SELECT balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$user) {
                $this->db->rollBack();
                return;
            }
            $before = (float) $user['balance'];
            $after = round($before + $amount, 4);
            $this->db->execute('UPDATE users SET balance = balance + ? WHERE id = ?', [$amount, $userId]);
            $this->db->insert(
                "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status) VALUES (?, 'admin', ?, ?, ?, ?, 'completed')",
                [$userId, $amount, $before, $after, $description]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            Logger::log('Bonus credit failed user#' . $userId . ': ' . $e->getMessage(), 'deposits');
        }
    }

    /** @return list<array<string, mixed>> */
    public function featuredServices(int $limit = 8, ?string $providerFilter = null): array
    {
        $sql = "SELECT * FROM services WHERE status = 'active' AND is_featured = 1";
        $params = [];
        if ($providerFilter) {
            [$clause, $p] = ProviderRegistry::providerFilter($providerFilter);
            $sql .= $clause;
            $params = $p;
        }
        $sql .= ' ORDER BY sort_priority DESC, service_id ASC LIMIT ' . (int) $limit;
        return $this->db->fetchAll($sql, $params);
    }

    /** @return array{title: string, text: string, cta_label: string, cta_url: string} */
    public function promoBanner(): array
    {
        return [
            'title' => (string) ($this->db->getSetting('dashboard_promo_title') ?: '🎁 Add funds — start ordering'),
            'text' => (string) ($this->db->getSetting('dashboard_promo_text') ?: 'Crypto deposits credited fast.'),
            'cta_label' => (string) ($this->db->getSetting('dashboard_promo_cta_label') ?: 'Add Funds →'),
            'cta_url' => page_url(ltrim((string) ($this->db->getSetting('dashboard_promo_cta_url') ?: 'add-funds.php'), '/')),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function upsellServices(int $serviceId, int $limit = 4): array
    {
        $svc = $this->db->fetch('SELECT category, provider FROM services WHERE service_id = ?', [$serviceId]);
        if (!$svc) {
            return [];
        }
        $cat = trim((string) ($svc['category'] ?? ''));
        if ($cat === '') {
            return [];
        }
        return $this->db->fetchAll(
            "SELECT * FROM services WHERE status = 'active' AND TRIM(COALESCE(category,'')) = ? AND service_id != ? ORDER BY is_featured DESC, sort_priority DESC, service_id ASC LIMIT ?",
            [$cat, $serviceId, $limit]
        );
    }

    /** Convert referral earnings to user balance (self-service or admin). */
    public function payoutReferralEarnings(int $userId, bool $force = false): array
    {
        $min = (float) ($this->db->getSetting('referral_min_payout') ?: 10);
        $user = $this->db->fetch('SELECT id, username, referral_earnings, balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }
        $amount = (float) ($user['referral_earnings'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'No referral earnings to payout.'];
        }
        if (!$force && $amount < $min) {
            return ['success' => false, 'error' => 'Minimum payout is $' . number_format($min, 2) . '.'];
        }

        $this->db->beginTransaction();
        try {
            $before = (float) $user['balance'];
            $after = round($before + $amount, 4);
            $this->db->execute('UPDATE users SET balance = balance + ?, referral_earnings = 0 WHERE id = ? AND referral_earnings >= ?', [$amount, $userId, $amount]);
            $this->db->insert(
                "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status) VALUES (?, 'referral', ?, ?, ?, ?, 'completed')",
                [$userId, $amount, $before, $after, 'Affiliate payout to balance']
            );
            $this->db->commit();
            return ['success' => true, 'amount' => $amount, 'balance_after' => $after];
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => 'Payout failed.'];
        }
    }

    /** @return list<array<string, mixed>> */
    public function allCoupons(): array
    {
        return $this->db->fetchAll('SELECT * FROM coupons ORDER BY active DESC, id DESC');
    }

    /** @param array<string, mixed> $data */
    public function saveCoupon(array $data, ?int $id = null): array
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '' || strlen($code) > 32) {
            return ['success' => false, 'error' => 'Invalid coupon code.'];
        }
        $type = (string) ($data['type'] ?? 'order_percent');
        if (!in_array($type, ['order_percent', 'order_fixed', 'deposit_percent', 'deposit_fixed'], true)) {
            return ['success' => false, 'error' => 'Invalid coupon type.'];
        }
        $value = (float) ($data['value'] ?? 0);
        if ($value <= 0) {
            return ['success' => false, 'error' => 'Value must be positive.'];
        }
        $row = [
            $code,
            $type,
            $value,
            (float) ($data['min_amount'] ?? 0),
            (int) ($data['max_uses'] ?? 0),
            (int) ($data['per_user_limit'] ?? 1),
            !empty($data['expires_at']) ? $data['expires_at'] : null,
            !empty($data['active']) ? 1 : 0,
            trim((string) ($data['note'] ?? '')),
        ];
        if ($id) {
            $this->db->execute(
                'UPDATE coupons SET code=?, type=?, value=?, min_amount=?, max_uses=?, per_user_limit=?, expires_at=?, active=?, note=? WHERE id=?',
                array_merge($row, [$id])
            );
        } else {
            $id = (int) $this->db->insert(
                'INSERT INTO coupons (code, type, value, min_amount, max_uses, per_user_limit, expires_at, active, note) VALUES (?,?,?,?,?,?,?,?,?)',
                $row
            );
        }
        return ['success' => true, 'id' => $id];
    }
}
