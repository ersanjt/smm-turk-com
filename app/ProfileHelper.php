<?php
/**
 * Ensure account-settings DB columns exist (idempotent, safe on web request).
 */
function ensure_account_settings_schema(Database $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = $db->getConnection();
    $columns = [
        'timezone'             => "ALTER TABLE users ADD COLUMN timezone VARCHAR(64) DEFAULT 'UTC'",
        'two_factor_enabled'   => 'ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0',
        'two_factor_secret'    => 'ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(64) DEFAULT NULL',
        'api_key_created_at'   => 'ALTER TABLE users ADD COLUMN api_key_created_at DATETIME NULL',
        'avatar'               => 'ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL',
    ];

    foreach ($columns as $name => $sql) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(['users', $name]);
        if ((int) $stmt->fetchColumn() > 0) {
            continue;
        }
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }

    $avatarsDir = ROOT_PATH . '/uploads/avatars';
    if (!is_dir($avatarsDir)) {
        @mkdir($avatarsDir, 0755, true);
    }
}
