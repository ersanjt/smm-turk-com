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
        $orderCount = (int) ($this->db->fetch('SELECT COUNT(*) c FROM orders WHERE user_id = ?', [$userId])['c'] ?? 0);
        $depositCount = (int) ($this->db->fetch(
            "SELECT COUNT(*) c FROM transactions WHERE user_id = ? AND type = 'deposit' AND status = 'completed'",
            [$userId]
        )['c'] ?? 0);

        $minDeposit = (float) ($this->db->getSetting('min_deposit') ?: 10);
        $minDeposit = $minDeposit >= 1 ? $minDeposit : 10;
        $minLabel = '$' . number_format($minDeposit, $minDeposit == floor($minDeposit) ? 0 : 2);
        $bonusPct = (float) ($this->db->getSetting('deposit_bonus_percent') ?: 0);
        $fundsDesc = 'Minimum ' . $minLabel . '. Credited in USD after payment confirms.';
        if ($bonusPct > 0 && $depositCount === 0) {
            $fundsDesc .= ' First deposit +' . number_format($bonusPct, 0) . '%.';
        }

        return [
            [
                'id' => 'funds',
                'title' => 'Add funds',
                'desc' => $fundsDesc,
                'url' => page_url('add-funds.php'),
                'done' => $depositCount > 0,
            ],
            [
                'id' => 'order',
                'title' => 'Place your first order',
                'desc' => 'Pick a service, paste the link, and submit',
                'url' => page_url('dashboard.php'),
                'done' => $orderCount > 0,
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
