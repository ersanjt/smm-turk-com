<?php
/**
 * New-user onboarding checklist — guides signup → order → deposit → earn.
 */
class UserOnboarding
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function shouldShow(int $userId): bool
    {
        if ($this->isDismissed($userId)) {
            return false;
        }
        $steps = $this->steps($userId);
        foreach ($steps as $step) {
            if (empty($step['done'])) {
                return true;
            }
        }
        return false;
    }

    public function dismiss(int $userId): void
    {
        $dir = ROOT_PATH . '/storage/onboarding';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @touch($dir . '/' . $userId . '.skip');
    }

    private function isDismissed(int $userId): bool
    {
        return is_file(ROOT_PATH . '/storage/onboarding/' . $userId . '.skip');
    }

    /** @return list<array{id: string, title: string, desc: string, url: string, done: bool}> */
    public function steps(int $userId): array
    {
        $user = $this->db->fetch(
            'SELECT username, balance, spent, created_at FROM users WHERE id = ?',
            [$userId]
        ) ?: [];
        $orderCount = (int) ($this->db->fetch('SELECT COUNT(*) c FROM orders WHERE user_id = ?', [$userId])['c'] ?? 0);
        $depositCount = (int) ($this->db->fetch(
            "SELECT COUNT(*) c FROM transactions WHERE user_id = ? AND type = 'deposit' AND status = 'completed'",
            [$userId]
        )['c'] ?? 0);
        $hasChildPanel = false;
        try {
            $hasChildPanel = (int) ($this->db->fetch(
                "SELECT COUNT(*) c FROM child_panels WHERE user_id = ? AND status != 'cancelled'",
                [$userId]
            )['c'] ?? 0) > 0;
        } catch (Throwable $e) {
            /* table may not exist */
        }
        $refVisits = 0;
        try {
            $refVisits = (int) ($this->db->fetch('SELECT COUNT(*) c FROM referral_visits WHERE referrer_id = ?', [$userId])['c'] ?? 0);
        } catch (Throwable $e) {
            /* */
        }

        return [
            [
                'id' => 'account',
                'title' => 'Account ready',
                'desc' => 'Edit profile, email & security in Settings',
                'url' => page_url('account-settings.php'),
                'done' => true,
            ],
            [
                'id' => 'order',
                'title' => 'Place your first order',
                'desc' => 'Use free welcome balance or add funds',
                'url' => page_url('dashboard.php'),
                'done' => $orderCount > 0,
            ],
            [
                'id' => 'funds',
                'title' => 'Add funds (crypto)',
                'desc' => 'BTC, ETH, USDT — bonus on first deposit',
                'url' => page_url('add-funds.php'),
                'done' => $depositCount > 0,
            ],
            [
                'id' => 'earn',
                'title' => 'Earn money',
                'desc' => 'Child panel, affiliates, or API reseller',
                'url' => page_url('earn.php'),
                'done' => $hasChildPanel || $refVisits > 0,
            ],
        ];
    }

    public function progressPercent(int $userId): int
    {
        $steps = $this->steps($userId);
        if ($steps === []) {
            return 100;
        }
        $done = 0;
        foreach ($steps as $s) {
            if (!empty($s['done'])) {
                $done++;
            }
        }
        return (int) round(($done / count($steps)) * 100);
    }
}
