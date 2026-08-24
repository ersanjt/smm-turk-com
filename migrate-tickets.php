<?php
/**
 * One-time migration: extend tickets for category, subcategory, order_id, attachments.
 * Run once: php migrate-tickets.php
 */
require_once __DIR__ . '/app/init.php';
require_cli();
$db = Database::getInstance();
$pdo = $db->getConnection();

// Ensure tickets table has required columns
$addTicketsColumns = [
    'category'    => "ALTER TABLE tickets ADD COLUMN category VARCHAR(64) DEFAULT ''",
    'subcategory' => "ALTER TABLE tickets ADD COLUMN subcategory VARCHAR(64) DEFAULT ''",
    'order_id'    => "ALTER TABLE tickets ADD COLUMN order_id VARCHAR(500) DEFAULT ''",
    'created_at'  => "ALTER TABLE tickets ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
];
foreach ($addTicketsColumns as $name => $sql) {
    try {
        $pdo->exec($sql);
        echo "Added tickets.$name\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "tickets.$name already exists\n";
        } else {
            throw $e;
        }
    }
}

// ticket_replies: is_staff and created_at
foreach ([
    "ALTER TABLE ticket_replies ADD COLUMN is_staff TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE ticket_replies ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
] as $sql) {
    try {
        $pdo->exec($sql);
        echo "Added ticket_replies column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) { /* skip */ } else throw $e;
    }
}

// ticket_attachments
$pdo->exec("CREATE TABLE IF NOT EXISTS ticket_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    reply_id INT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (ticket_id)
)");
echo "ticket_attachments table OK\n";

// Ensure tickets has status and updated_at if missing
foreach (['status' => "ALTER TABLE tickets ADD COLUMN status VARCHAR(32) DEFAULT 'open'", 'updated_at' => "ALTER TABLE tickets ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"] as $col => $sql) {
    try {
        $pdo->exec($sql);
        echo "Added tickets.$col\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) { /* skip */ } else throw $e;
    }
}

echo "Tickets migration done.\n";
