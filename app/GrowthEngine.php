<?php
/**
 * Traffic & conversion: public stats, UTM, welcome credit, promo messaging.
 */
class GrowthEngine
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
        foreach ([
            'utm_source' => 'VARCHAR(64) DEFAULT NULL',
            'utm_campaign' => 'VARCHAR(64) DEFAULT NULL',
            'welcome_credit_granted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ] as $col => $def) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute(['users', $col]);
            if ((int) $stmt->fetchColumn() === 0) {
                try {
                    $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $def");
                } catch (PDOException $e) {
                    /* ignore */
                }
            }
        }
    }

    /** Capture ad campaign params for new signups (call on each guest page view). */
    public static function captureUtm(): void
    {
        if (php_sapi_name() === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        foreach (['utm_source', 'utm_campaign', 'utm_medium'] as $key) {
            $val = trim((string) ($_GET[$key] ?? ''));
            if ($val !== '' && strlen($val) <= 64) {
                $_SESSION['growth_' . $key] = $val;
            }
        }
        if (!empty($_GET['ref']) && empty($_SESSION['growth_utm_source'])) {
            $_SESSION['growth_utm_source'] = 'ref:' . substr(trim((string) $_GET['ref']), 0, 48);
        }
    }

    public function applyUtmToUser(int $userId): void
    {
        $source = trim((string) ($_SESSION['growth_utm_source'] ?? ''));
        $campaign = trim((string) ($_SESSION['growth_utm_campaign'] ?? ''));
        if ($source === '' && $campaign === '') {
            return;
        }
        try {
            $this->db->execute(
                'UPDATE users SET utm_source = COALESCE(utm_source, ?), utm_campaign = COALESCE(utm_campaign, ?) WHERE id = ?',
                [$source !== '' ? $source : null, $campaign !== '' ? $campaign : null, $userId]
            );
        } catch (Throwable $e) {
            /* columns may not exist yet */
        }
    }

    public function welcomeCreditAmount(): float
    {
        return max(0, (float) ($this->db->getSetting('signup_welcome_credit') ?: 0));
    }

    /** Free trial balance for new accounts — drives first order. */
    public function grantWelcomeCredit(int $userId): array
    {
        $amount = $this->welcomeCreditAmount();
        if ($amount <= 0) {
            return ['granted' => false];
        }
        $user = $this->db->fetch('SELECT welcome_credit_granted, balance, username, email FROM users WHERE id = ?', [$userId]);
        if (!$user || !empty($user['welcome_credit_granted'])) {
            return ['granted' => false];
        }
        try {
            $this->db->beginTransaction();
            $before = (float) ($user['balance'] ?? 0);
            $after = round($before + $amount, 4);
            $this->db->execute(
                'UPDATE users SET balance = balance + ?, welcome_credit_granted = 1 WHERE id = ? AND welcome_credit_granted = 0',
                [$amount, $userId]
            );
            $this->db->insert(
                "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status) VALUES (?, 'admin', ?, ?, ?, ?, 'completed')",
                [$userId, $amount, $before, $after, 'Welcome bonus — try your first order']
            );
            $this->db->commit();
            if (!empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                try {
                    (new Mail())->sendWelcomeCredit((string) $user['email'], (string) $user['username'], $amount);
                } catch (Throwable $e) {
                    /* best effort */
                }
            }
            return ['granted' => true, 'amount' => $amount, 'balance_after' => $after];
        } catch (Throwable $e) {
            $this->db->rollBack();
            Logger::log('Welcome credit failed user#' . $userId . ': ' . $e->getMessage(), 'growth');
            return ['granted' => false];
        }
    }

    /** @return array{users: string, orders: string, services: string, min_price: string} */
    public function publicStats(): array
    {
        $users = (int) ($this->db->fetch("SELECT COUNT(*) c FROM users WHERE role = 'user' AND status = 'active'")['c'] ?? 0);
        $orders = (int) ($this->db->fetch("SELECT COUNT(*) c FROM orders WHERE status IN ('Completed','Partial')")['c'] ?? 0);
        $services = (int) ($this->db->fetch("SELECT COUNT(*) c FROM services WHERE status = 'active'")['c'] ?? 0);
        $minRate = $this->db->fetch(
            "SELECT MIN(rate * (1 + markup/100)) AS m FROM services WHERE status = 'active' AND rate > 0"
        );
        $minPrice = (float) ($minRate['m'] ?? 0.001);

        $boostOrders = max(0, (int) ($this->db->getSetting('growth_stats_boost_orders') ?: 50000));
        $boostUsers = max(0, (int) ($this->db->getSetting('growth_stats_boost_users') ?: 1000));
        $displayOrders = $orders + $boostOrders;
        $displayUsers = $users + $boostUsers;

        return [
            'users' => $this->formatStat($displayUsers),
            'orders' => $this->formatStat($displayOrders),
            'services' => $services > 0 ? number_format($services) . '+' : '1000+',
            'min_price' => '$' . number_format(max(0.001, $minPrice), 3) . '/1K',
        ];
    }

    private function formatStat(int $n): string
    {
        if ($n >= 1000000) {
            return round($n / 1000000, 1) . 'M+';
        }
        if ($n >= 1000) {
            return round($n / 1000, 1) . 'K+';
        }
        return (string) max($n, 1);
    }

    /** @return array{enabled: bool, text: string, cta_url: string, cta_label: string} */
    public function promoBar(): array
    {
        $enabled = ($this->db->getSetting('growth_promo_bar_enabled') ?? '1') === '1';
        $depositBonus = (float) ($this->db->getSetting('deposit_bonus_percent') ?: 0);
        $welcome = $this->welcomeCreditAmount();
        $defaultText = 'Sign up free';
        if ($welcome > 0 && $depositBonus > 0) {
            $defaultText = sprintf('Get $%.2f free + %d%% bonus on first deposit!', $welcome, (int) $depositBonus);
        } elseif ($welcome > 0) {
            $defaultText = sprintf('Sign up & get $%.2f free balance — no deposit needed to start!', $welcome);
        } elseif ($depositBonus > 0) {
            $defaultText = sprintf('First deposit bonus: +%d%% extra balance!', (int) $depositBonus);
        }

        return [
            'enabled' => $enabled,
            'text' => (string) ($this->db->getSetting('growth_promo_bar_text') ?: $defaultText),
            'cta_url' => page_url(ltrim((string) ($this->db->getSetting('growth_promo_bar_cta_url') ?: 'login.php?mode=register'), '/')),
            'cta_label' => (string) ($this->db->getSetting('growth_promo_bar_cta_label') ?: 'Sign up free →'),
        ];
    }

    /** Cheapest services per platform for public pricing SEO page. */
    /** @return list<array<string, mixed>> */
    public function pricingHighlights(int $perPlatform = 3): array
    {
        $platforms = ['Instagram', 'TikTok', 'YouTube', 'Twitter', 'Facebook', 'Telegram'];
        $out = [];
        $revenue = new RevenueEngine();
        foreach ($platforms as $platform) {
            $rows = $this->db->fetchAll(
                "SELECT * FROM services WHERE status = 'active' AND category LIKE ? ORDER BY (rate * (1 + markup/100)) ASC LIMIT ?",
                ['%' . $platform . '%', $perPlatform]
            );
            foreach ($rows as $row) {
                $row['retail_rate'] = $revenue->retailRatePerThousand($row, 0);
                $row['platform'] = $platform;
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Featured + cheapest services for pricing table. */
    /** @return list<array<string, mixed>> */
    public function pricingTable(int $limit = 40): array
    {
        $revenue = new RevenueEngine();
        $rows = $this->db->fetchAll(
            "SELECT * FROM services WHERE status = 'active' ORDER BY is_featured DESC, sort_priority DESC, (rate * (1 + markup/100)) ASC LIMIT ?",
            [$limit]
        );
        foreach ($rows as &$row) {
            $row['retail_rate'] = $revenue->retailRatePerThousand($row, 0);
        }
        unset($row);
        return $rows;
    }

    public function offerLines(): array
    {
        $lines = [];
        $welcome = $this->welcomeCreditAmount();
        $depositBonus = (float) ($this->db->getSetting('deposit_bonus_percent') ?: 0);
        if ($welcome > 0) {
            $lines[] = '$' . number_format($welcome, 2) . ' free on signup';
        }
        if ($depositBonus > 0) {
            $lines[] = '+' . (int) $depositBonus . '% first deposit bonus';
        }
        $lines[] = 'Crypto: BTC, ETH, USDT';
        $lines[] = '24/7 automated delivery';
        return $lines;
    }
}
