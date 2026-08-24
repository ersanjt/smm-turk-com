<?php
/**
 * One-time migration: referral visits tracking + total_referral_earnings.
 * Run once: php migrate-affiliates.php
 */
require_once __DIR__ . '/app/init.php';
require_cli();
$pdo = Database::getInstance()->getConnection();

$pdo->exec("CREATE TABLE IF NOT EXISTS referral_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    referrer_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (referrer_id),
    INDEX (created_at)
)");

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN total_referral_earnings DECIMAL(10,4) NOT NULL DEFAULT 0");
    echo "Added users.total_referral_earnings\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') === false) throw $e;
}

echo "Affiliates migration done.\n";
