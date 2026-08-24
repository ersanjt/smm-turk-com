<?php
/**
 * Parent-side registry of end-users on child panel websites.
 */
class ChildPanelEndUsers
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
        try {
            $db->getConnection()->exec(
                "CREATE TABLE IF NOT EXISTS child_panel_end_users (
                  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  child_panel_id INT UNSIGNED NOT NULL,
                  child_domain VARCHAR(255) NOT NULL,
                  child_local_user_id INT UNSIGNED NOT NULL,
                  username VARCHAR(64) NOT NULL,
                  email VARCHAR(100) NOT NULL,
                  status VARCHAR(20) NOT NULL DEFAULT 'active',
                  registered_at DATETIME NOT NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uk_panel_local_user (child_panel_id, child_local_user_id),
                  KEY idx_child_panel (child_panel_id),
                  KEY idx_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            /* migration may handle */
        }
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function registerFromApi(string $apiKey, string $panelDomain, int $localUserId, string $username, string $email, string $status = 'active', ?string $registeredAt = null): array
    {
        $apiKey = trim($apiKey);
        $panelDomain = ChildPanelManager::normalizeDomain($panelDomain);
        $username = trim($username);
        $email = strtolower(trim($email));
        $status = trim($status) !== '' ? trim($status) : 'active';

        if ($apiKey === '' || $panelDomain === '' || $localUserId <= 0 || $username === '' || $email === '') {
            return ['success' => false, 'error' => 'Missing required fields'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email'];
        }

        $panel = $this->db->fetch(
            "SELECT cp.* FROM child_panels cp WHERE cp.panel_api_key = ? AND cp.domain = ? AND cp.status = 'active' LIMIT 1",
            [$apiKey, $panelDomain]
        );
        if (!$panel) {
            return ['success' => false, 'error' => 'Child panel not found for this API key and domain'];
        }

        $registeredAt = $registeredAt !== null && $registeredAt !== '' ? $registeredAt : date('Y-m-d H:i:s');
        try {
            $this->db->execute(
                "INSERT INTO child_panel_end_users (child_panel_id, child_domain, child_local_user_id, username, email, status, registered_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE username = VALUES(username), email = VALUES(email), status = VALUES(status), registered_at = VALUES(registered_at)",
                [(int) $panel['id'], $panelDomain, $localUserId, $username, $email, $status, $registeredAt]
            );
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Could not save end user'];
        }

        return ['success' => true];
    }

    /** @return list<array<string, mixed>> */
    public function listForOwner(int $ownerUserId, ?int $panelId = null, int $limit = 100): array
    {
        $sql = "SELECT eu.*, cp.domain AS panel_domain
                FROM child_panel_end_users eu
                JOIN child_panels cp ON cp.id = eu.child_panel_id
                WHERE cp.user_id = ?";
        $params = [$ownerUserId];
        if ($panelId !== null && $panelId > 0) {
            $sql .= ' AND eu.child_panel_id = ?';
            $params[] = $panelId;
        }
        $sql .= ' ORDER BY eu.registered_at DESC LIMIT ' . max(1, min(500, $limit));
        return $this->db->fetchAll($sql, $params);
    }

    /** @return list<array<string, mixed>> */
    public function listAllForAdmin(int $limit = 200): array
    {
        return $this->db->fetchAll(
            "SELECT eu.*, cp.domain AS panel_domain, u.username AS owner_username, u.email AS owner_email
             FROM child_panel_end_users eu
             JOIN child_panels cp ON cp.id = eu.child_panel_id
             JOIN users u ON u.id = cp.user_id
             ORDER BY eu.registered_at DESC
             LIMIT " . max(1, min(500, $limit))
        );
    }

    public function countForOwner(int $ownerUserId, ?int $panelId = null): int
    {
        if ($panelId !== null && $panelId > 0) {
            $row = $this->db->fetch(
                'SELECT COUNT(*) c FROM child_panel_end_users eu JOIN child_panels cp ON cp.id = eu.child_panel_id WHERE cp.user_id = ? AND eu.child_panel_id = ?',
                [$ownerUserId, $panelId]
            );
            return (int) ($row['c'] ?? 0);
        }
        $row = $this->db->fetch(
            'SELECT COUNT(*) c FROM child_panel_end_users eu JOIN child_panels cp ON cp.id = eu.child_panel_id WHERE cp.user_id = ?',
            [$ownerUserId]
        );
        return (int) ($row['c'] ?? 0);
    }

    public function countAll(): int
    {
        $row = $this->db->fetch('SELECT COUNT(*) c FROM child_panel_end_users');
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Import existing users from a live child panel DB (one-time / repair).
     *
     * @return array{success: bool, error?: string, imported?: int}
     */
    public function importFromChildDatabase(int $panelId, int $ownerUserId): array
    {
        $panel = $this->db->fetch(
            'SELECT * FROM child_panels WHERE id = ? AND user_id = ? AND status = ? AND provision_status = ?',
            [$panelId, $ownerUserId, ChildPanelManager::STATUS_ACTIVE, ChildPanelManager::PROVISION_READY]
        );
        if (!$panel) {
            return ['success' => false, 'error' => 'Panel not found or not live.'];
        }
        $deployer = new ChildPanelDeployer();
        $documentRoot = trim((string) ($panel['document_root'] ?? ''));
        if ($documentRoot === '') {
            $documentRoot = $deployer->docrootForDomain((string) ($panel['domain'] ?? ''));
        }
        $pdo = $deployer->pdoFromDocumentRoot($documentRoot);
        if ($pdo === null) {
            return ['success' => false, 'error' => 'Could not connect to child panel database.'];
        }
        $apiKey = trim((string) ($panel['panel_api_key'] ?? ''));
        $domain = (string) ($panel['domain'] ?? '');
        $rows = $pdo->query("SELECT id, username, email, status, created_at FROM users WHERE role = 'user' ORDER BY id ASC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
        $imported = 0;
        foreach ($rows as $row) {
            $res = $this->registerFromApi(
                $apiKey,
                $domain,
                (int) ($row['id'] ?? 0),
                (string) ($row['username'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['status'] ?? 'active'),
                (string) ($row['created_at'] ?? date('Y-m-d H:i:s'))
            );
            if ($res['success']) {
                $imported++;
            }
        }
        return ['success' => true, 'imported' => $imported];
    }
}
