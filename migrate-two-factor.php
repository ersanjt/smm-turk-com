<?php
/**
 * Add two_factor_secret column for TOTP authenticator apps.
 * Run once: php migrate-two-factor.php
 */
require_once __DIR__ . '/app/init.php';
require_cli();
$db = Database::getInstance();
$pdo = $db->getConnection();

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(64) DEFAULT NULL");
    echo "Added column: two_factor_secret\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Column two_factor_secret already exists, skip.\n";
    } else {
        throw $e;
    }
}
echo "Migration done.\n";
