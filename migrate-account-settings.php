<?php
/**
 * One-time migration: add columns for Account Settings (timezone, 2FA, API key date).
 * Run once from CLI: php migrate-account-settings.php
 */
require_once __DIR__ . '/app/init.php';
require_cli();
$db = Database::getInstance();
$pdo = $db->getConnection();

$columns = [
    'timezone' => "ALTER TABLE users ADD COLUMN timezone VARCHAR(64) DEFAULT 'UTC'",
    'two_factor_enabled' => "ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0",
    'two_factor_secret' => "ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(64) DEFAULT NULL",
    'api_key_created_at' => "ALTER TABLE users ADD COLUMN api_key_created_at DATETIME NULL",
    'avatar' => "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL",
];

foreach ($columns as $name => $sql) {
    try {
        $pdo->exec($sql);
        echo "Added column: $name\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "Column $name already exists, skip.\n";
        } else {
            throw $e;
        }
    }
}
echo "Migration done.\n";
