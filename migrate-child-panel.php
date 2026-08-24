<?php
/**
 * One-time migration: child_panels table for child panel orders.
 * Run once: php migrate-child-panel.php
 */
require_once __DIR__ . '/app/init.php';
require_cli();
$pdo = Database::getInstance()->getConnection();

$pdo->exec("CREATE TABLE IF NOT EXISTS child_panels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    currency VARCHAR(16) NOT NULL DEFAULT 'USD',
    admin_username VARCHAR(64) NOT NULL,
    admin_password VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (status)
)");

echo "Child panels table OK.\n";
