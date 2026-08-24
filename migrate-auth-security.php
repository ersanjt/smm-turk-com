<?php
/**
 * One-time migration: must_change_password column for default admin password enforcement.
 * Run once: php migrate-auth-security.php
 */
require_once __DIR__ . '/app/init.php';
require_cli();

$db = Database::getInstance();
$pdo = $db->getConnection();

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    echo "Added users.must_change_password\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') === false) {
        throw $e;
    }
    echo "Column must_change_password already exists, skip.\n";
}

$db->execute(
    "UPDATE users SET must_change_password = 1 WHERE password = ?",
    ['$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi']
);
echo "Default-password accounts flagged.\n";
echo "Migration done.\n";
