<?php
/**
 * Child panel orders, billing, and automated provisioning.
 */
class ChildPanelManager
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    public const PROVISION_DNS_WAIT = 'dns_wait';
    public const PROVISION_QUEUED = 'queued';
    public const PROVISION_PROVISIONING = 'provisioning';
    public const PROVISION_READY = 'ready';
    public const PROVISION_FAILED = 'failed';

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
        $columns = [
            'admin_email'       => 'VARCHAR(255) DEFAULT NULL',
            'panel_url'         => 'VARCHAR(255) DEFAULT NULL',
            'panel_api_key'     => 'VARCHAR(64) DEFAULT NULL',
            'provision_status'  => "VARCHAR(32) NOT NULL DEFAULT 'pending'",
            'provision_error'   => 'VARCHAR(512) DEFAULT NULL',
            'provision_log'     => 'TEXT DEFAULT NULL',
            'activated_at'      => 'DATETIME DEFAULT NULL',
            'expires_at'        => 'DATETIME DEFAULT NULL',
            'ns_verified'       => 'TINYINT(1) NOT NULL DEFAULT 0',
            'document_root'     => 'VARCHAR(512) DEFAULT NULL',
            'admin_password_enc'=> 'TEXT DEFAULT NULL',
            'updated_at'        => 'DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ];
        foreach ($columns as $name => $def) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute(['child_panels', $name]);
            if ((int) $stmt->fetchColumn() === 0) {
                try {
                    $pdo->exec("ALTER TABLE child_panels ADD COLUMN `$name` $def");
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate column') === false) {
                        throw $e;
                    }
                }
            }
        }
        try {
            $pdo->exec('ALTER TABLE child_panels DROP INDEX uk_child_panels_domain');
        } catch (PDOException $e) {
            /* unique index may not exist */
        }
        try {
            $pdo->exec('CREATE INDEX idx_child_panels_domain ON child_panels (domain)');
        } catch (PDOException $e) {
            /* index may exist */
        }
        $dir = ROOT_PATH . '/storage/child-panels';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    public function monthlyPrice(): float
    {
        return (float) ($this->db->getSetting('child_panel_price') ?: 5);
    }

    public function autoMode(): string
    {
        $mode = strtolower(trim((string) ($this->db->getSetting('child_panel_auto_mode') ?? 'instant')));
        return in_array($mode, ['manual', 'instant', 'dns', 'whm', 'full'], true) ? $mode : 'instant';
    }

    public function serverIp(): string
    {
        return trim((string) ($this->db->getSetting('child_panel_server_ip') ?? ''));
    }

    public function provisioningEnabled(): bool
    {
        $whm = new WhmProvisioner();
        if ($whm->isConfigured()) {
            return true;
        }
        return (new ChildPanelDeployer())->homePath() !== '';
    }

    public static function encryptSecret(string $plain): string
    {
        $key = defined('SECRET_KEY') ? (string) SECRET_KEY : 'smm-child-panel';
        $iv = substr(hash('sha256', $key . 'iv'), 0, 16);
        $enc = openssl_encrypt($plain, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        return $enc === false ? '' : base64_encode($enc);
    }

    public static function decryptSecret(string $enc): string
    {
        if ($enc === '') {
            return '';
        }
        $key = defined('SECRET_KEY') ? (string) SECRET_KEY : 'smm-child-panel';
        $iv = substr(hash('sha256', $key . 'iv'), 0, 16);
        $raw = base64_decode($enc, true);
        if ($raw === false) {
            return '';
        }
        $plain = openssl_decrypt($raw, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    public static function generateDeployPassword(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 14; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    public function storeAdminPassword(int $panelId, string $plainPassword): void
    {
        $this->db->execute(
            'UPDATE child_panels SET admin_password = ?, admin_password_enc = ? WHERE id = ?',
            [password_hash($plainPassword, PASSWORD_DEFAULT), self::encryptSecret($plainPassword), $panelId]
        );
    }

    public function usesParentLogin(array $panel): bool
    {
        return trim((string) ($panel['admin_password_enc'] ?? '')) === '';
    }

    /** @return array{password: string, hash: ?string, regenerated: bool} */
    private function resolveDeployPassword(int $panelId, array $panel, ?string $provided = null): array
    {
        if ($provided !== null && $provided !== '') {
            $this->storeAdminPassword($panelId, $provided);
            return ['password' => $provided, 'hash' => null, 'regenerated' => false];
        }

        if ($this->usesParentLogin($panel)) {
            $parent = $this->db->fetch('SELECT username, email, password FROM users WHERE id = ?', [$panel['user_id'] ?? 0]);
            if (!empty($parent['password'])) {
                $this->db->execute(
                    'UPDATE child_panels SET admin_username = ?, admin_email = ?, admin_password = ? WHERE id = ?',
                    [
                        (string) ($parent['username'] ?? $panel['admin_username'] ?? ''),
                        (string) ($parent['email'] ?? $panel['admin_email'] ?? ''),
                        (string) $parent['password'],
                        $panelId,
                    ]
                );
                return ['password' => '', 'hash' => (string) $parent['password'], 'regenerated' => false];
            }
        }

        $plain = self::decryptSecret((string) ($panel['admin_password_enc'] ?? ''));
        if ($plain !== '') {
            return ['password' => $plain, 'hash' => null, 'regenerated' => false];
        }

        $plain = self::generateDeployPassword();
        $this->storeAdminPassword($panelId, $plain);
        $this->appendLog($panelId, 'Generated new admin password for deploy.');
        return ['password' => $plain, 'hash' => null, 'regenerated' => true];
    }

    public function getStoredAdminPassword(array $panel): string
    {
        return self::decryptSecret((string) ($panel['admin_password_enc'] ?? ''));
    }

    /**
     * @return array{success: bool, error?: string, admin_password?: string, admin_username?: string, uses_parent_login?: bool}
     */
    public function syncAdminFromParentAccount(int $panelId, ?int $userId = null): array
    {
        $panel = $this->fetchPanelForOwner($panelId, $userId);
        if ($panel === null) {
            return ['success' => false, 'error' => 'Panel not found.'];
        }
        $check = $this->assertPanelReadyForLoginSync($panel);
        if (!$check['success']) {
            return $check;
        }

        $parent = $this->db->fetch('SELECT username, email, password FROM users WHERE id = ?', [$panel['user_id']]);
        if (!$parent || empty($parent['password'])) {
            return ['success' => false, 'error' => 'Parent account not found.'];
        }

        $username = trim((string) $parent['username']);
        $email = trim((string) $parent['email']);
        $hash = (string) $parent['password'];

        $this->db->execute(
            'UPDATE child_panels SET admin_username = ?, admin_email = ?, admin_password = ?, admin_password_enc = NULL WHERE id = ?',
            [$username, $email, $hash, $panelId]
        );
        $this->appendLog($panelId, 'Admin login synced with parent SMM Turk account.');

        $sync = $this->pushAdminCredentialsToChild($panel, $username, $email, $hash, true);
        if (!$sync['success']) {
            return $sync;
        }

        return [
            'success' => true,
            'admin_username' => $username,
            'uses_parent_login' => true,
        ];
    }

    /**
     * Change panel login password (also updates your SMM Turk account when using shared login).
     *
     * @return array{success: bool, error?: string, uses_parent_login?: bool}
     */
    public function changeChildPanelLoginPassword(int $panelId, int $userId, string $currentPassword, string $newPassword, string $confirmPassword): array
    {
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => 'New password must be at least 6 characters.'];
        }
        if ($newPassword !== $confirmPassword) {
            return ['success' => false, 'error' => 'New passwords do not match.'];
        }

        $panel = $this->fetchPanelForOwner($panelId, $userId);
        if ($panel === null) {
            return ['success' => false, 'error' => 'Panel not found.'];
        }
        $check = $this->assertPanelReadyForLoginSync($panel);
        if (!$check['success']) {
            return $check;
        }

        $parent = $this->db->fetch('SELECT username, email, password FROM users WHERE id = ?', [$userId]);
        if (!$parent || !password_verify($currentPassword, (string) ($parent['password'] ?? ''))) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->db->execute('UPDATE users SET password = ? WHERE id = ?', [$newHash, $userId]);

        $username = trim((string) $parent['username']);
        $email = trim((string) $parent['email']);
        $this->db->execute(
            'UPDATE child_panels SET admin_username = ?, admin_email = ?, admin_password = ?, admin_password_enc = NULL WHERE user_id = ? AND status = ? AND provision_status = ?',
            [$username, $email, $newHash, $userId, self::STATUS_ACTIVE, self::PROVISION_READY]
        );
        $this->appendLog($panelId, 'Panel login password changed (synced with SMM Turk account).');

        $panels = $this->db->fetchAll(
            'SELECT * FROM child_panels WHERE user_id = ? AND status = ? AND provision_status = ?',
            [$userId, self::STATUS_ACTIVE, self::PROVISION_READY]
        );
        foreach ($panels as $childPanel) {
            $sync = $this->pushAdminCredentialsToChild($childPanel, $username, $email, $newHash, true);
            if (!$sync['success']) {
                return $sync;
            }
        }

        return ['success' => true, 'uses_parent_login' => true];
    }

    public function syncChildPanelsPasswordHashForUser(int $userId, string $passwordHash): void
    {
        if ($passwordHash === '') {
            return;
        }
        $parent = $this->db->fetch('SELECT username, email FROM users WHERE id = ?', [$userId]);
        if (!$parent) {
            return;
        }
        $username = trim((string) $parent['username']);
        $email = trim((string) $parent['email']);
        $this->db->execute(
            'UPDATE child_panels SET admin_username = ?, admin_email = ?, admin_password = ?, admin_password_enc = NULL
             WHERE user_id = ? AND status = ? AND provision_status = ? AND (admin_password_enc IS NULL OR admin_password_enc = \'\')',
            [$username, $email, $passwordHash, $userId, self::STATUS_ACTIVE, self::PROVISION_READY]
        );
        $panels = $this->db->fetchAll(
            'SELECT * FROM child_panels WHERE user_id = ? AND status = ? AND provision_status = ? AND (admin_password_enc IS NULL OR admin_password_enc = \'\')',
            [$userId, self::STATUS_ACTIVE, self::PROVISION_READY]
        );
        foreach ($panels as $panel) {
            $this->pushAdminCredentialsToChild($panel, $username, $email, $passwordHash, true);
        }
    }

    /**
     * Reset child panel admin login (DB password + optional new secret). Clears login rate limits on the child site.
     *
     * @return array{success: bool, error?: string, admin_password?: string, admin_username?: string}
     */
    public function resetAdminLoginPassword(int $panelId, ?int $userId = null, bool $regenerate = true): array
    {
        if (!$regenerate) {
            return $this->syncAdminFromParentAccount($panelId, $userId);
        }

        $panel = $this->fetchPanelForOwner($panelId, $userId);
        if (!$panel) {
            return ['success' => false, 'error' => 'Panel not found.'];
        }
        if (($panel['status'] ?? '') !== self::STATUS_ACTIVE) {
            return ['success' => false, 'error' => 'Panel is not active yet.'];
        }
        if (($panel['provision_status'] ?? '') !== self::PROVISION_READY) {
            return ['success' => false, 'error' => 'Panel is not fully deployed yet.'];
        }

        $documentRoot = trim((string) ($panel['document_root'] ?? ''));
        if ($documentRoot === '') {
            $deployer = new ChildPanelDeployer();
            $documentRoot = $deployer->docrootForDomain((string) ($panel['domain'] ?? ''));
        }
        if ($documentRoot === '' || !is_dir($documentRoot)) {
            return ['success' => false, 'error' => 'Child panel files not found on server.'];
        }

        $adminUsername = trim((string) ($panel['admin_username'] ?? ''));
        $adminEmail = trim((string) ($panel['admin_email'] ?? $panel['email'] ?? ''));
        if ($adminUsername === '' || $adminEmail === '') {
            return ['success' => false, 'error' => 'Admin username or email missing on order.'];
        }

        if ($regenerate) {
            $plain = self::generateDeployPassword();
            $this->storeAdminPassword($panelId, $plain);
            $this->appendLog($panelId, 'Admin login password reset.');
        } else {
            $plain = self::decryptSecret((string) ($panel['admin_password_enc'] ?? ''));
            if ($plain === '') {
                $plain = self::generateDeployPassword();
                $this->storeAdminPassword($panelId, $plain);
                $this->appendLog($panelId, 'Generated admin password (none stored).');
            }
        }

        $deployer = new ChildPanelDeployer();
        $sync = $deployer->syncAdminUser(
            $documentRoot,
            $adminUsername,
            $plain,
            $adminEmail,
            trim((string) ($panel['panel_api_key'] ?? ''))
        );
        if (!$sync['success']) {
            return ['success' => false, 'error' => $sync['error'] ?? 'Could not update admin user on child panel.'];
        }

        return [
            'success' => true,
            'admin_password' => $plain,
            'admin_username' => $adminUsername,
            'uses_parent_login' => false,
        ];
    }

    /** @return ?array<string, mixed> */
    public function getPanelForUser(int $panelId, int $userId): ?array
    {
        return $this->fetchPanelForOwner($panelId, $userId);
    }

    public function appendLogPublic(int $panelId, string $line): void
    {
        $this->appendLog($panelId, $line);
    }

    /** @return ?array<string, mixed> */
    private function fetchPanelForOwner(int $panelId, ?int $userId): ?array
    {
        $sql = 'SELECT cp.*, u.username, u.email FROM child_panels cp JOIN users u ON u.id = cp.user_id WHERE cp.id = ?';
        $params = [$panelId];
        if ($userId !== null) {
            $sql .= ' AND cp.user_id = ?';
            $params[] = $userId;
        }
        $panel = $this->db->fetch($sql, $params);
        return $panel ?: null;
    }

    /** @return array{success: bool, error?: string} */
    private function assertPanelReadyForLoginSync(array $panel): array
    {
        if (($panel['status'] ?? '') !== self::STATUS_ACTIVE) {
            return ['success' => false, 'error' => 'Panel is not active yet.'];
        }
        if (($panel['provision_status'] ?? '') !== self::PROVISION_READY) {
            return ['success' => false, 'error' => 'Panel is not fully deployed yet.'];
        }
        return ['success' => true];
    }

    /** @return array{success: bool, error?: string} */
    private function pushAdminCredentialsToChild(array $panel, string $username, string $email, string $passwordOrHash, bool $passwordIsHash): array
    {
        $documentRoot = trim((string) ($panel['document_root'] ?? ''));
        if ($documentRoot === '') {
            $deployer = new ChildPanelDeployer();
            $documentRoot = $deployer->docrootForDomain((string) ($panel['domain'] ?? ''));
        }
        if ($documentRoot === '' || !is_dir($documentRoot)) {
            return ['success' => false, 'error' => 'Child panel files not found on server.'];
        }
        $deployer = new ChildPanelDeployer();
        return $deployer->syncAdminUser(
            $documentRoot,
            $username,
            $passwordOrHash,
            $email,
            trim((string) ($panel['panel_api_key'] ?? '')),
            $passwordIsHash
        );
    }

    /** @return array{ready: bool, method: string, ns: list<string>, a: list<string>, resolved_ip: string} */
    public function checkDomainReady(string $domain): array
    {
        $domain = self::normalizeDomain($domain);
        $dnsMode = strtolower(trim((string) ($this->db->getSetting('child_panel_dns_mode') ?? 'both')));
        $serverIp = $this->serverIp();

        $nsRecords = $this->fetchPublicDns($domain, 'NS');
        $nsOk = $this->nameserversMatch($nsRecords);
        $aRecords = $this->fetchPublicDns($domain, 'A');
        $aOk = $this->addressRecordsMatch($aRecords, $serverIp);

        $resolvedIp = $this->resolveHostIp($domain);
        $resolveOk = $serverIp !== '' && $resolvedIp === $serverIp;
        $resolveAny = $resolvedIp !== '' && $resolvedIp !== $domain;
        $cloudflareOk = $dnsMode === 'both' && ($aRecords !== [] || $resolveAny);
        $serverOk = $this->domainHostedOnServer($domain);

        if ($dnsMode === 'ns') {
            return $this->dnsReadyResult($nsOk || $serverOk, $serverOk ? 'server' : 'ns', $nsRecords, $aRecords, $resolvedIp);
        }
        if ($dnsMode === 'a') {
            $ready = $aOk || $resolveOk || $serverOk;
            $method = $serverOk ? 'server' : ($aOk ? 'a' : ($resolveOk ? 'resolve' : ''));
            return $this->dnsReadyResult($ready, $method, $nsRecords, $aRecords, $resolvedIp);
        }
        $ready = $nsOk || $aOk || $resolveOk || $cloudflareOk || $serverOk;
        $method = $serverOk ? 'server' : ($nsOk ? 'ns' : ($aOk ? 'a' : ($resolveOk ? 'resolve' : ($cloudflareOk ? 'cf' : ''))));
        return $this->dnsReadyResult($ready, $method, $nsRecords, $aRecords, $resolvedIp);
    }

    private function domainHostedOnServer(string $domain): bool
    {
        $whm = new WhmProvisioner();
        if ($whm->isConfigured() && $whm->domainExistsOnAccount($domain)) {
            return true;
        }
        $deployer = new ChildPanelDeployer();
        $docroot = $deployer->docrootForDomain($domain);
        return $docroot !== '' && is_dir($docroot) && is_file($docroot . '/index.php');
    }

    /** @return array{ready: bool, method: string, ns: list<string>, a: list<string>, resolved_ip: string, expected_ns: list<string>, expected_ip: string, hint: string} */
    public function getDomainDnsDiagnostics(string $domain): array
    {
        $check = $this->checkDomainReady($domain);
        $expectedNs = array_values(array_filter([
            strtolower(rtrim(trim((string) ($this->db->getSetting('child_panel_ns1') ?? '')), '.')),
            strtolower(rtrim(trim((string) ($this->db->getSetting('child_panel_ns2') ?? '')), '.')),
        ]));
        $expectedIp = $this->serverIp();
        $hint = $this->buildDnsHint($check, $expectedNs, $expectedIp);
        return [
            'ready' => $check['ready'],
            'method' => $check['method'],
            'ns' => $check['ns'],
            'a' => $check['a'],
            'resolved_ip' => $check['resolved_ip'],
            'expected_ns' => $expectedNs,
            'expected_ip' => $expectedIp,
            'hint' => $hint,
        ];
    }

    /** @param list<string> $nsRecords */
    private function nameserversMatch(array $nsRecords): bool
    {
        $ns1 = strtolower(rtrim(trim((string) ($this->db->getSetting('child_panel_ns1') ?? '')), '.'));
        $ns2 = strtolower(rtrim(trim((string) ($this->db->getSetting('child_panel_ns2') ?? '')), '.'));
        $required = array_filter([$ns1, $ns2]);
        if ($required === []) {
            return false;
        }
        foreach ($required as $need) {
            if (!in_array($need, $nsRecords, true)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $aRecords */
    private function addressRecordsMatch(array $aRecords, string $serverIp): bool
    {
        if ($serverIp === '') {
            return $aRecords !== [];
        }
        return in_array($serverIp, $aRecords, true);
    }

    private function resolveHostIp(string $domain): string
    {
        $resolved = @gethostbyname($domain);
        if (!is_string($resolved) || $resolved === $domain) {
            return '';
        }
        return $resolved;
    }

    /** @return list<string> */
    private function fetchPublicDns(string $domain, string $type): array
    {
        $records = [];
        $phpType = $type === 'NS' ? DNS_NS : DNS_A;
        $raw = @dns_get_record($domain, $phpType);
        if (is_array($raw)) {
            foreach ($raw as $row) {
                $value = $type === 'NS'
                    ? strtolower(rtrim((string) ($row['target'] ?? ''), '.'))
                    : (string) ($row['ip'] ?? '');
                if ($value !== '') {
                    $records[] = $value;
                }
            }
        }
        if ($records !== []) {
            return array_values(array_unique($records));
        }

        $records = $this->fetchDnsOverHttps('https://dns.google/resolve', $domain, $type);
        if ($records !== []) {
            return $records;
        }

        return $this->fetchDnsOverHttps('https://cloudflare-dns.com/dns-query', $domain, $type);
    }

    /** @return list<string> */
    private function fetchDnsOverHttps(string $baseUrl, string $domain, string $type): array
    {
        $records = [];
        $url = $baseUrl . '?name=' . rawurlencode($domain) . '&type=' . rawurlencode($type);
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'SMM-Turk-ChildPanel/1.0',
                'header' => "Accept: application/dns-json\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $json = @file_get_contents($url, false, $ctx);
        if (!is_string($json) || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        foreach ($data['Answer'] ?? [] as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $value = (string) ($answer['data'] ?? '');
            if ($value === '') {
                continue;
            }
            if ($type === 'NS') {
                $records[] = strtolower(rtrim($value, '.'));
            } elseif (filter_var($value, FILTER_VALIDATE_IP)) {
                $records[] = $value;
            }
        }
        return array_values(array_unique($records));
    }

    /** @param list<string> $nsRecords @param list<string> $aRecords */
    private function dnsReadyResult(bool $ready, string $method, array $nsRecords, array $aRecords, string $resolvedIp): array
    {
        return [
            'ready' => $ready,
            'method' => $method,
            'ns' => $nsRecords,
            'a' => $aRecords,
            'resolved_ip' => $resolvedIp,
        ];
    }

    /** @param array{ready: bool, method: string, ns: list<string>, a: list<string>, resolved_ip: string} $check */
    private function buildDnsHint(array $check, array $expectedNs, string $expectedIp): string
    {
        if ($check['ready']) {
            return 'DNS looks good — click Check DNS to deploy your panel.';
        }
        if ($check['ns'] !== [] && !$this->nameserversMatch($check['ns'])) {
            $seen = implode(', ', $check['ns']);
            $want = implode(' + ', $expectedNs);
            return "Nameservers still show: {$seen}. Expected: {$want}. DNS changes can take up to 24–48 hours to propagate globally.";
        }
        if ($check['a'] !== [] || $check['resolved_ip'] !== '') {
            $seen = $check['a'] !== [] ? implode(', ', $check['a']) : $check['resolved_ip'];
            return "Domain points to {$seen}, not our server ({$expectedIp}). Use nameservers or set A record @ → {$expectedIp} (DNS only).";
        }
        return 'No DNS records detected yet. Set nameservers or an A record, then wait a few minutes and try again.';
    }

    public function checkNameservers(string $domain): bool
    {
        return $this->nameserversMatch($this->fetchPublicDns(self::normalizeDomain($domain), 'NS'));
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = preg_replace('/\p{Cf}/u', '', $domain) ?? $domain;
        $domain = str_replace(
            ["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}", "\u{00A0}"],
            ['-', '-', '-', '-', '-', '-', ' '],
            $domain
        );
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;
        $domain = preg_replace('/\s+/', '', $domain) ?? $domain;
        return rtrim($domain, '.');
    }

    public static function isValidDomain(string $domain): bool
    {
        return self::validateChildPanelDomain($domain) === null;
    }

    public static function validateChildPanelDomain(string $domain): ?string
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return 'Enter your domain name (e.g. yourpanel.com).';
        }
        if (strlen($domain) > 253 || strlen($domain) < 4) {
            return 'Domain name is too short or too long.';
        }
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return 'Enter a domain name, not an IP address.';
        }
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain)) {
            return 'Enter a valid domain (e.g. yourpanel.com). Use only letters, numbers, and hyphens.';
        }
        if (preg_match('/^www\./i', $domain)) {
            return 'Enter the domain without www (e.g. yourpanel.com).';
        }
        $primary = 'smm-turk.com';
        if (defined('SITE_URL')) {
            $host = parse_url((string) SITE_URL, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $primary = self::normalizeDomain($host);
            }
        }
        if ($domain === $primary) {
            return 'Use your own domain — the main site domain cannot be a child panel.';
        }
        return null;
    }

    /** @return array{success: bool, panel_id?: int, error?: string, instant?: bool} */
    public function placeOrder(
        int $userId,
        string $domain,
        string $currency,
        ?string $adminEmail = null
    ): array {
        $domain = self::normalizeDomain($domain);
        $domainError = self::validateChildPanelDomain($domain);
        if ($domainError !== null) {
            return ['success' => false, 'error' => $domainError];
        }

        $existing = $this->db->fetch(
            "SELECT id FROM child_panels WHERE domain = ? AND status NOT IN ('cancelled')",
            [$domain]
        );
        if ($existing) {
            return ['success' => false, 'error' => 'This domain is already registered on a child panel order.'];
        }

        $price = $this->monthlyPrice();
        $user = $this->db->fetch('SELECT id, username, email, password, balance, api_key FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }
        if ((float) $user['balance'] < $price) {
            return ['success' => false, 'error' => 'Insufficient balance. You need $' . number_format($price, 2) . '. Add funds first.'];
        }

        $adminEmail = trim((string) ($adminEmail ?: $user['email'] ?? ''));
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'A valid admin email is required.'];
        }
        $adminUsername = trim((string) ($user['username'] ?? ''));
        if (strlen($adminUsername) < 3) {
            return ['success' => false, 'error' => 'Your account username is too short for panel login.'];
        }
        $parentPasswordHash = (string) ($user['password'] ?? '');
        if ($parentPasswordHash === '') {
            return ['success' => false, 'error' => 'Account password missing.'];
        }

        $mode = $this->autoMode();
        $initialStatus = self::STATUS_PENDING;
        $provisionStatus = match ($mode) {
            'manual' => 'pending',
            'dns' => self::PROVISION_DNS_WAIT,
            default => self::PROVISION_QUEUED,
        };

        $passwordEnc = null;

        $cancelledRow = $this->db->fetch(
            'SELECT id FROM child_panels WHERE domain = ? AND user_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$domain, $userId, self::STATUS_CANCELLED]
        );
        $reuseId = $cancelledRow ? (int) $cancelledRow['id'] : 0;
        if ($reuseId === 0) {
            $cancelledAny = $this->db->fetch(
                'SELECT id, user_id FROM child_panels WHERE domain = ? AND status = ? ORDER BY id DESC LIMIT 1',
                [$domain, self::STATUS_CANCELLED]
            );
            if ($cancelledAny && (int) $cancelledAny['user_id'] !== $userId) {
                return ['success' => false, 'error' => 'This domain was used on another account. Choose a different domain or contact support.'];
            }
        }

        try {
            $this->db->beginTransaction();
            $deducted = $this->db->execute(
                'UPDATE users SET balance = balance - ?, spent = spent + ? WHERE id = ? AND balance >= ?',
                [$price, $price, $userId, $price]
            );
            if ($deducted === 0) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Insufficient balance.'];
            }

            $apiKey = $user['api_key'] ?? bin2hex(random_bytes(20));
            if ($reuseId > 0) {
                $this->db->execute(
                    'UPDATE child_panels SET currency = ?, admin_username = ?, admin_password = ?, admin_password_enc = ?,
                     admin_email = ?, price = ?, status = ?, provision_status = ?, provision_error = NULL, provision_log = NULL,
                     panel_url = NULL, document_root = NULL, panel_api_key = ?, activated_at = NULL, ns_verified = 0, updated_at = NOW()
                     WHERE id = ? AND user_id = ? AND status = ?',
                    [
                        $currency,
                        $adminUsername,
                        $parentPasswordHash,
                        $passwordEnc,
                        $adminEmail,
                        $price,
                        $initialStatus,
                        $provisionStatus,
                        $apiKey,
                        $reuseId,
                        $userId,
                        self::STATUS_CANCELLED,
                    ]
                );
                $panelId = $reuseId;
            } else {
                $panelId = $this->db->insert(
                    'INSERT INTO child_panels (user_id, domain, currency, admin_username, admin_password, admin_password_enc, admin_email, price, status, provision_status, panel_api_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $userId,
                        $domain,
                        $currency,
                        $adminUsername,
                        $parentPasswordHash,
                        $passwordEnc,
                        $adminEmail,
                        $price,
                        $initialStatus,
                        $provisionStatus,
                        $apiKey,
                    ]
                );
            }

            $balanceAfter = (float) $user['balance'] - $price;
            $this->db->insert(
                "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference)
                 VALUES (?, 'order', ?, ?, ?, ?, ?)",
                [$userId, -$price, (float) $user['balance'], $balanceAfter, 'Child panel: ' . $domain, (string) $panelId]
            );

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            Logger::log('Child panel order failed: ' . $e->getMessage(), 'child_panel');
            if (stripos($e->getMessage(), "doesn't exist") !== false) {
                return ['success' => false, 'error' => 'Child panel tables missing. Run php migrate-db.php on the server.'];
            }
            if (str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), 'domain')) {
                return ['success' => false, 'error' => 'This domain was used on a previous order. Try again in a moment or contact support.'];
            }
            return ['success' => false, 'error' => 'Order failed. Please try again or contact support.'];
        }

        $instant = false;
        if ($mode !== 'manual') {
            $dns = $this->checkDomainReady($domain);
            if (!$dns['ready']) {
                $this->db->execute(
                    'UPDATE child_panels SET provision_status = ? WHERE id = ?',
                    [self::PROVISION_DNS_WAIT, $panelId]
                );
            } else {
                $provision = $this->provision((int) $panelId, $adminPassword);
                $instant = $provision['success'] ?? false;
            }
        }

        return ['success' => true, 'panel_id' => (int) $panelId, 'instant' => $instant];
    }

    /** @return array{success: bool, error?: string, pending_dns?: bool} */
    public function provision(int $panelId, ?string $adminPlainPassword = null, bool $forceDeploy = false): array
    {
        $panel = $this->db->fetch(
            'SELECT cp.*, u.username, u.email FROM child_panels cp JOIN users u ON u.id = cp.user_id WHERE cp.id = ?',
            [$panelId]
        );
        if (!$panel) {
            return ['success' => false, 'error' => 'Panel not found.'];
        }
        if (($panel['status'] ?? '') === self::STATUS_CANCELLED) {
            return ['success' => false, 'error' => 'Panel order cancelled.'];
        }

        $this->appendLog($panelId, 'Provisioning started.');
        $this->db->execute(
            "UPDATE child_panels SET provision_status = ?, provision_error = NULL WHERE id = ?",
            [self::PROVISION_PROVISIONING, $panelId]
        );

        $domain = self::normalizeDomain((string) $panel['domain']);
        if ($forceDeploy) {
            $this->appendLog($panelId, 'DNS check skipped (admin force deploy).');
        } else {
            $dns = $this->checkDomainReady($domain);
            if (!$dns['ready']) {
                $this->appendLog($panelId, 'Waiting for DNS (' . ($dns['method'] ?: 'not detected') . ').');
                $this->db->execute(
                    'UPDATE child_panels SET provision_status = ?, provision_error = NULL WHERE id = ?',
                    [self::PROVISION_DNS_WAIT, $panelId]
                );
                return ['success' => false, 'pending_dns' => true, 'error' => 'Domain DNS not ready yet. Point nameservers or A record, then retry.'];
            }
            $this->appendLog($panelId, 'DNS verified via ' . ($dns['method'] ?: 'check') . '.');
        }

        $panelUrl = 'https://' . $domain;
        $documentRoot = trim((string) ($panel['document_root'] ?? ''));
        $deployError = null;

        if ($forceDeploy && ($panel['provision_status'] ?? '') === self::PROVISION_READY) {
            $this->syncAdminFromParentAccount($panelId, null);
            $panel = $this->db->fetch(
                'SELECT cp.*, u.username, u.email FROM child_panels cp JOIN users u ON u.id = cp.user_id WHERE cp.id = ?',
                [$panelId]
            ) ?: $panel;
        }

        $passwordMeta = $this->resolveDeployPassword($panelId, $panel, null);
        $adminPlainPassword = $passwordMeta['password'];
        $adminPasswordHash = $passwordMeta['hash'];

        if ($this->provisioningEnabled()) {
            $whm = new WhmProvisioner();
            if ($whm->isConfigured()) {
                $whmResult = $whm->provisionDomain($domain);
                if (!$whmResult['success']) {
                    $deployError = $whmResult['error'] ?? 'WHM addon domain failed';
                    $this->appendLog($panelId, 'WHM error: ' . $deployError);
                } else {
                    $this->appendLog($panelId, $whmResult['message'] ?? 'Addon domain OK');
                    $documentRoot = trim((string) ($whmResult['document_root'] ?? $documentRoot));
                    $deployer = new ChildPanelDeployer();
                    $documentRoot = $deployer->resolveDocumentRoot($domain, $documentRoot);
                    if (!empty($whmResult['panel_url'])) {
                        $panelUrl = $whmResult['panel_url'];
                    }
                    try {
                        $whm->requestAutoSsl($domain);
                    } catch (Throwable $e) {
                        /* best effort */
                    }
                }
            } else {
                $deployer = new ChildPanelDeployer();
                $documentRoot = $deployer->resolveDocumentRoot($domain, $documentRoot);
                $this->appendLog($panelId, 'Local deploy path: ' . $documentRoot);
            }

            if ($deployError === null && $documentRoot !== '' && ($adminPlainPassword !== '' || ($adminPasswordHash ?? '') !== '')) {
                $deployer = new ChildPanelDeployer();
                $deploy = $deployer->deploy($panel, $documentRoot, $adminPlainPassword, $adminPasswordHash);
                if (!$deploy['success']) {
                    $deployError = $deploy['error'] ?? 'Deploy failed';
                    $this->appendLog($panelId, 'Deploy error: ' . $deployError);
                } else {
                    $documentRoot = $deploy['document_root'] ?? $documentRoot;
                    $this->appendLog($panelId, 'Files deployed to ' . $documentRoot);
                }
            } elseif ($deployError === null && $adminPlainPassword === '' && ($adminPasswordHash ?? '') === '') {
                $deployError = 'Could not resolve admin password for deploy.';
            }
        } else {
            $this->appendLog($panelId, 'Auto-deploy disabled — metadata only.');
        }

        $apiKey = trim((string) ($panel['panel_api_key'] ?? ''));
        if ($apiKey === '') {
            $userRow = $this->db->fetch('SELECT api_key FROM users WHERE id = ?', [$panel['user_id']]);
            $apiKey = $userRow['api_key'] ?? bin2hex(random_bytes(20));
            $this->db->execute('UPDATE child_panels SET panel_api_key = ? WHERE id = ?', [$apiKey, $panelId]);
        }

        $parentApi = trim((string) ($this->db->getSetting('child_panel_parent_api_url') ?? ''));
        if ($parentApi === '') {
            $siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
            $parentApi = $siteUrl !== '' ? $siteUrl . '/api/v2' : '/api/v2';
        }

        if ($deployError !== null && $this->provisioningEnabled()) {
            $this->db->execute(
                'UPDATE child_panels SET provision_status = ?, provision_error = ?, updated_at = NOW() WHERE id = ?',
                [self::PROVISION_FAILED, substr($deployError, 0, 500), $panelId]
            );
            return ['success' => false, 'error' => $deployError];
        }

        $this->writeConfigFile($panelId, [
            'domain' => $domain,
            'panel_url' => $panelUrl,
            'document_root' => $documentRoot,
            'parent_api_url' => $parentApi,
            'api_key' => $apiKey,
            'admin_username' => $panel['admin_username'],
            'admin_email' => $panel['admin_email'] ?? $panel['email'],
            'currency' => $panel['currency'],
            'provisioned_at' => date('c'),
        ]);

        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $this->db->execute(
            "UPDATE child_panels SET status = ?, provision_status = ?, panel_url = ?, document_root = ?,
             activated_at = NOW(), expires_at = ?, provision_error = NULL, ns_verified = 1, updated_at = NOW() WHERE id = ?",
            [
                self::STATUS_ACTIVE,
                self::PROVISION_READY,
                $panelUrl,
                $documentRoot !== '' ? $documentRoot : null,
                $expiresAt,
                $panelId,
            ]
        );
        $this->appendLog($panelId, 'Panel live at ' . $panelUrl);

        $panelForMail = $this->db->fetch(
            'SELECT cp.*, u.username, u.email FROM child_panels cp JOIN users u ON u.id = cp.user_id WHERE cp.id = ?',
            [$panelId]
        ) ?: $panel;
        $to = $panelForMail['admin_email'] ?? $panelForMail['email'] ?? '';
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            try {
                $mail = new Mail();
                $mailPassword = $this->usesParentLogin($panelForMail) ? '' : $adminPlainPassword;
                $mail->sendChildPanelReady(
                    $to,
                    (string) $panelForMail['username'],
                    $domain,
                    $panelUrl,
                    $parentApi,
                    $apiKey,
                    (string) $panelForMail['admin_username'],
                    $mailPassword
                );
            } catch (Throwable $e) {
                Logger::log('Child panel email failed #' . $panelId . ': ' . $e->getMessage(), 'mail');
            }
        }

        return [
            'success' => true,
            'admin_password_regenerated' => $passwordMeta['regenerated'],
            'admin_password' => $passwordMeta['regenerated'] ? $adminPlainPassword : null,
        ];
    }

    /** Process DNS-waiting panels. Returns number provisioned. */
    public function processDnsQueue(): int
    {
        $rows = $this->db->fetchAll(
            "SELECT id, domain FROM child_panels WHERE provision_status = ? AND status NOT IN ('cancelled', 'active')",
            [self::PROVISION_DNS_WAIT]
        );
        $done = 0;
        foreach ($rows as $row) {
            $domain = self::normalizeDomain((string) $row['domain']);
            $dns = $this->checkDomainReady($domain);
            if (!$dns['ready']) {
                continue;
            }
            $this->db->execute('UPDATE child_panels SET ns_verified = 1 WHERE id = ?', [$row['id']]);
            $result = $this->provision((int) $row['id'], null, true);
            if ($result['success']) {
                $done++;
            }
        }
        return $done;
    }

    /** Retry queued/stuck panels (cron). Returns number provisioned. */
    public function retryPendingQueue(): int
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM child_panels
             WHERE status NOT IN ('cancelled', 'active')
             AND provision_status IN (?, ?, ?, ?)
             AND created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
            [self::PROVISION_QUEUED, self::PROVISION_PROVISIONING, self::PROVISION_FAILED, self::PROVISION_DNS_WAIT]
        );
        $done = 0;
        foreach ($rows as $row) {
            $result = $this->provision((int) $row['id'], null, true);
            if ($result['success']) {
                $done++;
            }
        }
        return $done;
    }

    /** @return array{dns: int, retried: int} */
    public function processCron(): array
    {
        return [
            'dns' => $this->processDnsQueue(),
            'retried' => $this->retryPendingQueue(),
        ];
    }

    public function isFullyDeployed(array $panel): bool
    {
        $ps = $panel['provision_status'] ?? '';
        if (($panel['status'] ?? '') !== self::STATUS_ACTIVE || $ps !== self::PROVISION_READY) {
            return false;
        }
        $documentRoot = trim((string) ($panel['document_root'] ?? ''));
        if ($documentRoot === '') {
            return false;
        }
        $check = (new ChildPanelDeployer())->documentRootReady($documentRoot);
        return $check['ok'];
    }

    public function canCancel(array $panel, bool $isAdmin = false): bool
    {
        $st = $panel['status'] ?? '';
        if ($st === self::STATUS_CANCELLED) {
            return false;
        }
        if ($this->isFullyDeployed($panel)) {
            return $isAdmin;
        }
        return in_array($st, [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true);
    }

    public function shouldRefundOnCancel(array $panel): bool
    {
        if (($panel['status'] ?? '') === self::STATUS_CANCELLED) {
            return false;
        }
        return !$this->isFullyDeployed($panel);
    }

    /** @return array{success: bool, error?: string, refunded?: float} */
    public function cancelOrder(int $panelId, ?int $userId = null, bool $refund = true): array
    {
        $panel = $this->db->fetch('SELECT * FROM child_panels WHERE id = ?', [$panelId]);
        if (!$panel) {
            return ['success' => false, 'error' => 'Order not found.'];
        }
        if ($userId !== null && (int) $panel['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'Order not found.'];
        }
        $isAdmin = $userId === null;
        if (!$this->canCancel($panel, $isAdmin)) {
            return ['success' => false, 'error' => 'This order cannot be cancelled. Contact support.'];
        }

        $shouldRefund = $refund && $this->shouldRefundOnCancel($panel);
        $cancelledBy = $isAdmin ? 'admin' : 'user';

        try {
            $this->db->beginTransaction();
            $this->db->execute(
                "UPDATE child_panels SET status = ?, provision_status = ?, provision_error = ? WHERE id = ?",
                [self::STATUS_CANCELLED, self::PROVISION_FAILED, 'Cancelled by ' . $cancelledBy, $panelId]
            );

            $refunded = 0.0;
            if ($shouldRefund) {
                $price = (float) $panel['price'];
                $uid = (int) $panel['user_id'];
                $user = $this->db->fetch('SELECT balance FROM users WHERE id = ?', [$uid]);
                $balanceBefore = (float) ($user['balance'] ?? 0);
                $balanceAfter = $balanceBefore + $price;
                $this->db->execute(
                    'UPDATE users SET balance = balance + ?, spent = GREATEST(0, spent - ?) WHERE id = ?',
                    [$price, $price, $uid]
                );
                $this->db->insert(
                    "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference)
                     VALUES (?, 'refund', ?, ?, ?, ?, ?)",
                    [$uid, $price, $balanceBefore, $balanceAfter, 'Child panel cancelled: ' . $panel['domain'], (string) $panelId]
                );
                $refunded = $price;
            }

            $this->appendLog($panelId, 'Order cancelled by ' . $cancelledBy . ($refunded > 0 ? ' (refunded $' . number_format($refunded, 2) . ')' : ''));
            $this->db->commit();
            return ['success' => true, 'refunded' => $refunded];
        } catch (Throwable $e) {
            $this->db->rollBack();
            Logger::log('Child panel cancel failed: ' . $e->getMessage(), 'child_panel');
            return ['success' => false, 'error' => 'Could not cancel order.'];
        }
    }

    private function appendLog(int $panelId, string $line): void
    {
        $row = $this->db->fetch('SELECT provision_log FROM child_panels WHERE id = ?', [$panelId]);
        $log = trim((string) ($row['provision_log'] ?? ''));
        $log .= ($log !== '' ? "\n" : '') . '[' . date('Y-m-d H:i:s') . '] ' . $line;
        $this->db->execute('UPDATE child_panels SET provision_log = ? WHERE id = ?', [$log, $panelId]);
    }

    /** @param array<string, mixed> $config */
    private function writeConfigFile(int $panelId, array $config): void
    {
        $dir = ROOT_PATH . '/storage/child-panels/' . $panelId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($dir . '/config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function getConfigForPanel(int $panelId, int $userId): ?array
    {
        $panel = $this->db->fetch('SELECT * FROM child_panels WHERE id = ? AND user_id = ?', [$panelId, $userId]);
        if (!$panel) {
            return null;
        }
        $parentApi = trim((string) ($this->db->getSetting('child_panel_parent_api_url') ?? ''));
        if ($parentApi === '') {
            $siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
            $parentApi = $siteUrl !== '' ? $siteUrl . '/api/v2' : '/api/v2';
        }
        return [
            'panel' => $panel,
            'parent_api_url' => $parentApi,
            'ns1' => trim((string) ($this->db->getSetting('child_panel_ns1') ?? '')),
            'ns2' => trim((string) ($this->db->getSetting('child_panel_ns2') ?? '')),
        ];
    }

    /** @return list<string> */
    public static function provisionSteps(array $panel): array
    {
        $status = $panel['status'] ?? 'pending';
        $ps = $panel['provision_status'] ?? 'pending';
        if ($status === self::STATUS_ACTIVE && $ps === self::PROVISION_READY) {
            return ['paid', 'dns_ok', 'deployed', 'active'];
        }
        if ($ps === self::PROVISION_DNS_WAIT) {
            return ['paid', 'dns_wait'];
        }
        if ($ps === self::PROVISION_PROVISIONING || $ps === self::PROVISION_QUEUED) {
            return ['paid', 'provisioning'];
        }
        if ($ps === self::PROVISION_FAILED) {
            return ['paid', 'failed'];
        }
        return ['paid', 'pending'];
    }
}
