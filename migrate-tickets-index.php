<?php
/**
 * Add indexes on tickets for faster user list and search.
 * Run once: php migrate-tickets-index.php
 */
require_once __DIR__ . '/app/init.php';
require_cli();
$db = Database::getInstance();
$pdo = $db->getConnection();

$indexes = [
    'idx_tickets_user_updated' => 'ALTER TABLE tickets ADD INDEX idx_tickets_user_updated (user_id, updated_at)',
    'idx_tickets_user_status' => 'ALTER TABLE tickets ADD INDEX idx_tickets_user_status (user_id, status)',
];

foreach ($indexes as $name => $sql) {
    try {
        $pdo->exec($sql);
        echo "Added index: $name\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "Index $name already exists, skip.\n";
        } else {
            throw $e;
        }
    }
}
echo "Migration done.\n";
