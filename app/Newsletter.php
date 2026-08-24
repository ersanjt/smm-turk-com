<?php
/**
 * Newsletter subscribers — collect visitor emails from the public blog.
 */
class Newsletter
{
    public static function ensureSchema(Database $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $db->getConnection()->exec(
                "CREATE TABLE IF NOT EXISTS newsletter_subscribers (
                  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  email VARCHAR(190) NOT NULL,
                  lang VARCHAR(5) NOT NULL DEFAULT 'en',
                  source VARCHAR(40) NOT NULL DEFAULT 'blog',
                  status VARCHAR(20) NOT NULL DEFAULT 'active',
                  ip VARCHAR(45) DEFAULT NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uk_newsletter_email (email),
                  KEY idx_newsletter_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            /* ignore — table may be created by migration */
        }
    }

    /**
     * @return array{success: bool, error?: string, already?: bool}
     */
    public static function subscribe(string $email, string $lang = 'en', string $source = 'blog', ?string $ip = null): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return ['success' => false, 'error' => 'invalid_email'];
        }
        $lang = preg_replace('/[^a-z]/', '', strtolower($lang)) ?: 'en';
        $lang = mb_substr($lang, 0, 5);
        $source = mb_substr(preg_replace('/[^a-z0-9_\-]/', '', strtolower($source)) ?: 'blog', 0, 40);

        $db = Database::getInstance();
        self::ensureSchema($db);

        try {
            $existing = $db->fetch('SELECT id, status FROM newsletter_subscribers WHERE email = ? LIMIT 1', [$email]);
            if ($existing) {
                if (($existing['status'] ?? '') !== 'active') {
                    $db->execute("UPDATE newsletter_subscribers SET status = 'active' WHERE id = ?", [(int) $existing['id']]);
                }
                return ['success' => true, 'already' => true];
            }
            $db->execute(
                'INSERT INTO newsletter_subscribers (email, lang, source, ip) VALUES (?, ?, ?, ?)',
                [$email, $lang, $source, $ip !== null ? mb_substr($ip, 0, 45) : null]
            );
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'save_failed'];
        }

        return ['success' => true, 'already' => false];
    }
}
