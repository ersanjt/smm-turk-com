<?php
/**
 * Unified database setup & optimization — idempotent, safe on production.
 *
 * Creates missing tables/columns and applies all performance indexes.
 * Run once after deploy: php migrate-db.php
 *
 * Replaces: migrate-performance-indexes.php, migrate-tickets.php, migrate-blog.php,
 *           migrate-affiliates.php, migrate-child-panel.php, migrate-account-settings.php, etc.
 */
require_once __DIR__ . '/app/init.php';
require_cli();

$pdo = Database::getInstance()->getConnection();

function db_ok(string $msg): void
{
    echo "OK: $msg\n";
}

function db_skip(string $msg): void
{
    echo "SKIP: $msg\n";
}

function db_exec(PDO $pdo, string $sql, string $label): void
{
    try {
        $pdo->exec($sql);
        db_ok($label);
    } catch (PDOException $e) {
        $m = $e->getMessage();
        if (str_contains($m, 'Duplicate column')
            || str_contains($m, 'Duplicate key name')
            || str_contains($m, 'already exists')
            || str_contains($m, 'Duplicate entry')
            || str_contains($m, 'check that column/key exists')) {
            db_skip($label);
            return;
        }
        throw $e;
    }
}

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function db_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (db_column_exists($pdo, $table, $column)) {
        db_skip("column $table.$column");
        return;
    }
    db_exec($pdo, "ALTER TABLE `$table` ADD COLUMN `$column` $definition", "column $table.$column");
}

function db_apply_sql_file(PDO $pdo, string $path): void
{
    if (!is_readable($path)) {
        echo "WARN: missing $path\n";
        return;
    }
    $sql = file_get_contents($path);
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
    foreach ($statements as $stmt) {
        if ($stmt === '' || str_starts_with($stmt, '--')) {
            continue;
        }
        db_exec($pdo, $stmt, substr($stmt, 0, 72) . '...');
    }
}

echo "=== SMM Turk DB migration ===\n";
echo 'Database: ' . DB_NAME . "\n\n";

// ─── Extra tables ────────────────────────────────────────────────────────────
$tables = [
    'referral_visits' => "CREATE TABLE IF NOT EXISTS referral_visits (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        referrer_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_referral_visits_referrer (referrer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'child_panels' => "CREATE TABLE IF NOT EXISTS child_panels (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        domain VARCHAR(255) NOT NULL,
        currency VARCHAR(16) NOT NULL DEFAULT 'USD',
        admin_username VARCHAR(64) NOT NULL,
        admin_password VARCHAR(255) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_child_panels_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'ticket_attachments' => "CREATE TABLE IF NOT EXISTS ticket_attachments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT UNSIGNED NOT NULL,
        reply_id INT UNSIGNED DEFAULT NULL,
        file_path VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ticket_attachments_ticket (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'blog_categories' => "CREATE TABLE IF NOT EXISTS blog_categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(128) NOT NULL,
        name VARCHAR(255) NOT NULL,
        meta_description VARCHAR(512) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_blog_categories_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'blog_tags' => "CREATE TABLE IF NOT EXISTS blog_tags (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(128) NOT NULL,
        name VARCHAR(128) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_blog_tags_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'blog_articles' => "CREATE TABLE IF NOT EXISTS blog_articles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_id INT UNSIGNED DEFAULT NULL,
        author_id INT UNSIGNED DEFAULT NULL,
        slug VARCHAR(255) NOT NULL,
        title VARCHAR(512) NOT NULL,
        meta_description VARCHAR(512) DEFAULT NULL,
        meta_keywords VARCHAR(512) DEFAULT NULL,
        excerpt TEXT DEFAULT NULL,
        body LONGTEXT NOT NULL,
        featured_image VARCHAR(512) DEFAULT NULL,
        status ENUM('draft','published') NOT NULL DEFAULT 'published',
        published_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        reading_time_min INT DEFAULT NULL,
        UNIQUE KEY uk_blog_articles_slug (slug),
        KEY idx_blog_status_published (status, published_at),
        KEY idx_blog_category (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'blog_article_tags' => "CREATE TABLE IF NOT EXISTS blog_article_tags (
        article_id INT UNSIGNED NOT NULL,
        tag_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (article_id, tag_id),
        KEY idx_blog_article_tags_tag (tag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
    db_exec($pdo, $sql, "table $name");
}

// ─── Users columns ───────────────────────────────────────────────────────────
$userColumns = [
    'email_verification_token'   => 'VARCHAR(64) DEFAULT NULL',
    'email_verification_expires' => 'DATETIME DEFAULT NULL',
    'google_id'                  => 'VARCHAR(64) DEFAULT NULL',
    'password_reset_token'       => 'VARCHAR(64) DEFAULT NULL',
    'password_reset_expires'     => 'DATETIME DEFAULT NULL',
    'must_change_password'       => 'TINYINT(1) NOT NULL DEFAULT 0',
    'two_factor_secret'          => 'VARCHAR(64) DEFAULT NULL',
    'two_factor_enabled'         => 'TINYINT(1) NOT NULL DEFAULT 0',
    'timezone'                   => "VARCHAR(64) NOT NULL DEFAULT 'UTC'",
    'api_key_created_at'         => 'DATETIME DEFAULT NULL',
    'avatar'                     => 'VARCHAR(255) DEFAULT NULL',
    'total_referral_earnings'    => 'DECIMAL(12,4) NOT NULL DEFAULT 0',
];
foreach ($userColumns as $col => $def) {
    db_add_column($pdo, 'users', $col, $def);
}

// Flag default admin password
try {
    $pdo->exec(
        "UPDATE users SET must_change_password = 1
         WHERE password = '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
         AND must_change_password = 0"
    );
    db_ok('default-password accounts flagged');
} catch (PDOException $e) {
    db_skip('default-password flag');
}

// ─── Tickets columns ─────────────────────────────────────────────────────────
foreach ([
    'category'    => "VARCHAR(64) NOT NULL DEFAULT ''",
    'subcategory' => "VARCHAR(64) NOT NULL DEFAULT ''",
    'order_id'    => "VARCHAR(500) NOT NULL DEFAULT ''",
] as $col => $def) {
    db_add_column($pdo, 'tickets', $col, $def);
}

foreach ([
    'provider' => "VARCHAR(32) NOT NULL DEFAULT 'smmfollows'",
    'provider_service_id' => 'INT UNSIGNED NOT NULL DEFAULT 0',
] as $col => $def) {
    db_add_column($pdo, 'services', $col, $def);
}
db_add_column($pdo, 'orders', 'provider', "VARCHAR(32) NOT NULL DEFAULT 'smmfollows'");
try {
    $pdo->exec("UPDATE services SET provider = 'smmfollows', provider_service_id = service_id WHERE provider_service_id = 0");
    db_ok('services provider backfill');
} catch (PDOException $e) {
    db_skip('services provider backfill');
}

foreach ([
    'is_staff'   => 'TINYINT(1) NOT NULL DEFAULT 0',
    'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
] as $col => $def) {
    db_add_column($pdo, 'ticket_replies', $col, $def);
}

// ─── Performance indexes ─────────────────────────────────────────────────────
echo "\n--- Indexes ---\n";
db_apply_sql_file($pdo, __DIR__ . '/migrations/006_tickets_indexes.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/007_performance_indexes.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/008_extra_indexes.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/009_services_category_wider.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/010_mail_settings.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/011_multi_provider.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/012_service_tier_brands.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/013_payment_gateways.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/014_heleket_panel_mode.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/015_mail_lang.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/016_child_panel_automation.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/017_child_panel_full_auto.sql');
db_apply_sql_file($pdo, __DIR__ . '/migrations/018_child_panel_domain_reuse.sql');

// ─── Analyze tables (refresh optimizer stats) ────────────────────────────────
echo "\n--- Analyze ---\n";
$analyze = ['users', 'services', 'orders', 'transactions', 'tickets', 'ticket_replies', 'settings'];
foreach ($analyze as $table) {
    try {
        $pdo->query("ANALYZE TABLE `$table`");
        db_ok("ANALYZE $table");
    } catch (PDOException $e) {
        db_skip("ANALYZE $table");
    }
}

echo "\n=== Migration complete ===\n";
