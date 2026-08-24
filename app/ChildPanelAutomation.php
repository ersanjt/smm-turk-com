<?php
/**
 * 24/7 automation for parent site + all active child panels (profit pipeline).
 */
class ChildPanelAutomation
{
    private Database $db;
    private ChildPanelManager $cpm;
    private ChildPanelEndUsers $endUsers;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->cpm = new ChildPanelManager();
        $this->endUsers = new ChildPanelEndUsers();
    }

    /** @return array<string, int> */
    public function run(): array
    {
        $stats = $this->cpm->processCron();
        $stats['parent_orders'] = (new OrderManager())->syncOrders();
        $stats['parent_deposits'] = (new DepositAutoConfirm())->processAllPending(50);

        $childOrderStats = $this->runOnAllChildPanels('cron-sync.php', 'Updated');
        $stats['child_orders'] = $childOrderStats['total'];
        $stats['child_panels_synced'] = $childOrderStats['panels'];

        $childDepositStats = $this->runOnAllChildPanels('cron-verify-deposits.php', 'Auto-approved');
        $stats['child_deposits'] = $childDepositStats['total'];

        $stats['users_synced'] = $this->syncAllEndUsers();
        $stats['low_balance_alerts'] = $this->notifyLowResellerBalances();
        $stats['api_repairs'] = $this->repairParentApiSettings();
        $stats['docroot_repairs'] = $this->repairBrokenDocumentRoots();

        $renewal = new ChildPanelRenewal();
        $renewalStats = $renewal->process();
        $stats['panel_renewed'] = $renewalStats['renewed'];
        $stats['panel_suspended'] = $renewalStats['suspended'];
        $stats['panel_reminded'] = $renewalStats['reminded'];

        $stats['winback_emails'] = $this->notifyWinBackUsers();
        $stats['deposit_nudges'] = $this->notifyZeroBalanceUsers();

        Logger::log(
            'Automation: dns=' . ($stats['dns'] ?? 0)
            . ' orders_parent=' . ($stats['parent_orders'] ?? 0)
            . ' orders_child=' . ($stats['child_orders'] ?? 0)
            . ' deposits=' . ($stats['parent_deposits'] ?? 0)
            . ' alerts=' . ($stats['low_balance_alerts'] ?? 0)
            . ' docroot_repairs=' . ($stats['docroot_repairs'] ?? 0),
            'automation'
        );

        return $stats;
    }

    /** @return array{total: int, panels: int} */
    private function runOnAllChildPanels(string $scriptName, string $countPrefix): array
    {
        $panels = $this->activePanels();
        $total = 0;
        $ran = 0;
        foreach ($panels as $panel) {
            $n = $this->runChildScript((string) ($panel['document_root'] ?? ''), $scriptName, $countPrefix);
            if ($n >= 0) {
                $ran++;
                $total += $n;
            }
        }
        return ['total' => $total, 'panels' => $ran];
    }

    /** @return list<array<string, mixed>> */
    private function activePanels(): array
    {
        return $this->db->fetchAll(
            "SELECT id, user_id, domain, document_root FROM child_panels
             WHERE status = 'active' AND provision_status = 'ready' AND document_root IS NOT NULL AND document_root != ''"
        );
    }

    /** Run a cron PHP script inside a child panel directory. Returns parsed count or -1 on skip. */
    private function runChildScript(string $documentRoot, string $scriptName, string $countPrefix): int
    {
        $documentRoot = trim($documentRoot);
        if ($documentRoot === '') {
            return -1;
        }
        $script = rtrim($documentRoot, '/\\') . '/' . $scriptName;
        if (!is_file($script)) {
            return -1;
        }
        $php = $this->phpBinary();
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $code = 0;
        @exec($cmd, $output, $code);
        if ($code !== 0) {
            Logger::log("Child cron failed ($scriptName) in $documentRoot: " . implode(' ', $output), 'automation');
            return 0;
        }
        $text = implode("\n", $output);
        if (preg_match('/' . preg_quote($countPrefix, '/') . '\s+(\d+)/i', $text, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private function phpBinary(): string
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }
        return 'php';
    }

    public function syncAllEndUsers(): int
    {
        $total = 0;
        foreach ($this->activePanels() as $panel) {
            $result = $this->endUsers->importFromChildDatabase((int) $panel['id'], (int) $panel['user_id']);
            if (!empty($result['success'])) {
                $total += (int) ($result['imported'] ?? 0);
            }
        }
        return $total;
    }

    public function repairParentApiSettings(): int
    {
        $parentApi = trim((string) ($this->db->getSetting('child_panel_parent_api_url') ?? ''));
        if ($parentApi === '') {
            $siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
            $parentApi = $siteUrl !== '' ? $siteUrl . '/api/v2' : '';
        }
        if ($parentApi === '') {
            return 0;
        }
        $deployer = new ChildPanelDeployer();
        $fixed = 0;
        foreach ($this->activePanels() as $panel) {
            $pdo = $deployer->pdoFromDocumentRoot((string) ($panel['document_root'] ?? ''));
            if ($pdo === null) {
                continue;
            }
            $apiKey = trim((string) ($this->db->fetch('SELECT panel_api_key FROM child_panels WHERE id = ?', [(int) $panel['id']])['panel_api_key'] ?? ''));
            if ($apiKey === '') {
                continue;
            }
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
                );
                $stmt->execute(['api_url', $parentApi]);
                $stmt->execute(['api_key', $apiKey]);
                $fixed++;
            } catch (Throwable $e) {
                Logger::log('API repair ' . ($panel['domain'] ?? '') . ': ' . $e->getMessage(), 'automation');
            }
        }
        return $fixed;
    }

    /** Re-deploy panels whose document root is missing panel files (403 Forbidden). */
    public function repairBrokenDocumentRoots(): int
    {
        $deployer = new ChildPanelDeployer();
        $repaired = 0;
        foreach ($this->activePanels() as $panel) {
            $documentRoot = trim((string) ($panel['document_root'] ?? ''));
            if ($documentRoot === '') {
                $documentRoot = $deployer->docrootForDomain((string) ($panel['domain'] ?? ''));
            }
            $check = $deployer->documentRootReady($documentRoot);
            if ($check['ok']) {
                continue;
            }
            $result = $this->cpm->provision((int) $panel['id'], null, true);
            if ($result['success']) {
                $repaired++;
                Logger::log('Repaired empty docroot: ' . ($panel['domain'] ?? ''), 'automation');
            } else {
                Logger::log('Docroot repair failed ' . ($panel['domain'] ?? '') . ': ' . ($result['error'] ?? ''), 'automation');
            }
        }
        return $repaired;
    }

    public function notifyLowResellerBalances(): int
    {
        $min = (float) ($this->db->getSetting('child_panel_min_reseller_balance') ?: 10);
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT u.id, u.username, u.email, u.balance
             FROM users u
             JOIN child_panels cp ON cp.user_id = u.id
             WHERE cp.status = 'active' AND cp.provision_status = 'ready' AND u.balance < ?",
            [$min]
        );
        $sent = 0;
        $alertDir = ROOT_PATH . '/storage/alerts/reseller-balance';
        if (!is_dir($alertDir)) {
            @mkdir($alertDir, 0755, true);
        }
        $mail = new Mail();
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $flag = $alertDir . '/' . $userId . '.txt';
            if (is_file($flag) && (time() - (int) @filemtime($flag)) < 86400) {
                continue;
            }
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if ($mail->sendResellerLowBalance($email, (string) ($row['username'] ?? ''), (float) ($row['balance'] ?? 0), $min)) {
                @touch($flag);
                $sent++;
            }
        }
        return $sent;
    }

    public function notifyZeroBalanceUsers(): int
    {
        $threshold = (float) ($this->db->getSetting('revenue_low_balance_threshold') ?: 5);
        $rows = $this->db->fetchAll(
            "SELECT u.id, u.username, u.email, u.balance, u.spent
             FROM users u
             WHERE u.status = 'active' AND u.role = 'user' AND u.balance < ? AND u.spent > 0",
            [$threshold]
        );
        $alertDir = ROOT_PATH . '/storage/alerts/deposit-nudge';
        if (!is_dir($alertDir)) {
            @mkdir($alertDir, 0755, true);
        }
        $mail = new Mail();
        $sent = 0;
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            $flag = $alertDir . '/' . $userId . '.txt';
            if (is_file($flag) && (time() - (int) @filemtime($flag)) < 86400 * 3) {
                continue;
            }
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if ($mail->sendDepositNudge($email, (string) ($row['username'] ?? ''), (float) ($row['balance'] ?? 0))) {
                @touch($flag);
                $sent++;
            }
        }
        return $sent;
    }

    public function notifyWinBackUsers(): int
    {
        $days = max(7, (int) ($this->db->getSetting('revenue_winback_days') ?: 30));
        $rows = $this->db->fetchAll(
            "SELECT u.id, u.username, u.email, u.spent,
                    (SELECT MAX(created_at) FROM orders o WHERE o.user_id = u.id) AS last_order
             FROM users u
             WHERE u.status = 'active' AND u.role = 'user' AND u.spent > 10
             HAVING last_order IS NOT NULL AND last_order < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        $alertDir = ROOT_PATH . '/storage/alerts/winback';
        if (!is_dir($alertDir)) {
            @mkdir($alertDir, 0755, true);
        }
        $mail = new Mail();
        $sent = 0;
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            $flag = $alertDir . '/' . $userId . '.txt';
            if (is_file($flag) && (time() - (int) @filemtime($flag)) < 86400 * 14) {
                continue;
            }
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if ($mail->sendWinBack($email, (string) ($row['username'] ?? ''), (float) ($row['spent'] ?? 0))) {
                @touch($flag);
                $sent++;
            }
        }
        return $sent;
    }
}
