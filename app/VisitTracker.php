<?php
/**
 * One entry per guest per day, with the country Cloudflare already resolved.
 * No external geo lookup, so a page view never waits on a third party.
 */
class VisitTracker
{
    public static function capture(): void
    {
        if (php_sapi_name() === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (self::skipRequest()) {
            return;
        }

        $day = date('Y-m-d');
        if (($_SESSION['visit_day'] ?? '') === $day) {
            return;
        }

        try {
            $db = Database::getInstance();
            self::ensureSchema($db);
            $visitorId = self::visitorId();
            $country = self::countryCode();
            $db->execute(
                'INSERT INTO site_visits (visitor_id, country, day, hits)
                 VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE hits = hits + 1',
                [$visitorId, $country, $day]
            );
            $_SESSION['visit_day'] = $day;
            if ($country !== '') {
                $_SESSION['visit_country'] = $country;
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::log('VisitTracker: ' . $e->getMessage(), 'visits');
            }
        }
    }

    public static function attachCountry(int $userId): void
    {
        if ($userId < 1) {
            return;
        }
        $country = self::countryCode();
        if ($country === '') {
            return;
        }
        try {
            $db = Database::getInstance();
            self::ensureSchema($db);
            $db->execute(
                "UPDATE users SET country = ? WHERE id = ? AND (country IS NULL OR country = '')",
                [$country, $userId]
            );
        } catch (Throwable $e) {
            /* column may be missing during a partial deploy */
        }
    }

    /**
     * @return array{
     *   days: int,
     *   visitors: int,
     *   signups: int,
     *   depositors: int,
     *   deposited: float,
     *   signup_rate: float,
     *   deposit_rate: float,
     *   countries: list<array{code: string, name: string, visitors: int, share: float}>,
     *   dayseries: list<array{day: string, label: string, visitors: int}>,
     *   known_countries: int
     * }
     */
    public static function report(int $days = 7): array
    {
        $days = $days === 30 ? 30 : 7;
        $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $empty = self::emptyReport($days);
        try {
            $db = Database::getInstance();
            self::ensureSchema($db);
            $visitors = (int) ($db->fetch(
                'SELECT COUNT(*) c FROM site_visits WHERE day >= ?',
                [$since]
            )['c'] ?? 0);
            $signups = (int) ($db->fetch(
                "SELECT COUNT(*) c FROM users WHERE role = 'user' AND created_at >= ?",
                [$since . ' 00:00:00']
            )['c'] ?? 0);
            $paid = $db->fetch(
                "SELECT COUNT(DISTINCT user_id) c, COALESCE(SUM(amount), 0) s
                 FROM transactions
                 WHERE type = 'deposit' AND status = 'completed' AND created_at >= ?",
                [$since . ' 00:00:00']
            ) ?: ['c' => 0, 's' => 0];
            $depositors = (int) ($paid['c'] ?? 0);
            $deposited = round((float) ($paid['s'] ?? 0), 2);

            $countryRows = $db->fetchAll(
                'SELECT country, COUNT(*) c FROM site_visits WHERE day >= ? GROUP BY country ORDER BY c DESC LIMIT 12',
                [$since]
            );
            $countries = [];
            foreach ($countryRows as $row) {
                $count = (int) ($row['c'] ?? 0);
                $code = strtoupper((string) ($row['country'] ?? ''));
                $countries[] = [
                    'code' => $code,
                    'name' => self::countryName($code),
                    'visitors' => $count,
                    'share' => $visitors > 0 ? round($count / $visitors * 100, 1) : 0.0,
                ];
            }

            $byDay = [];
            foreach ($db->fetchAll(
                'SELECT day, COUNT(*) c FROM site_visits WHERE day >= ? GROUP BY day',
                [$since]
            ) as $row) {
                $byDay[(string) $row['day']] = (int) $row['c'];
            }
            $series = [];
            $peak = 1;
            for ($i = $days - 1; $i >= 0; $i--) {
                $day = date('Y-m-d', strtotime('-' . $i . ' days'));
                $count = $byDay[$day] ?? 0;
                $peak = max($peak, $count);
                $series[] = [
                    'day' => $day,
                    'label' => date('M j', strtotime($day)),
                    'visitors' => $count,
                ];
            }
            foreach ($series as &$point) {
                $point['share'] = round($point['visitors'] / $peak * 100, 1);
            }
            unset($point);

            return [
                'days' => $days,
                'visitors' => $visitors,
                'signups' => $signups,
                'depositors' => $depositors,
                'deposited' => $deposited,
                'signup_rate' => $visitors > 0 ? round($signups / $visitors * 100, 1) : 0.0,
                'deposit_rate' => $signups > 0 ? round($depositors / $signups * 100, 1) : 0.0,
                'countries' => $countries,
                'dayseries' => $series,
                'known_countries' => count(array_filter($countries, static fn ($row) => $row['code'] !== '')),
            ];
        } catch (Throwable $e) {
            return $empty;
        }
    }

    public static function countryCode(): string
    {
        $cf = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $cf) && !in_array($cf, ['XX', 'T1'], true)) {
            return $cf;
        }
        $session = strtoupper(trim((string) ($_SESSION['visit_country'] ?? $_SESSION['ip_country'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $session) && !in_array($session, ['XX', 'T1'], true)) {
            return $session;
        }
        return '';
    }

    public static function countryName(string $code): string
    {
        $code = strtoupper($code);
        if ($code === '') {
            return 'Unknown';
        }
        return self::names()[$code] ?? $code;
    }

    public static function flag(string $code): string
    {
        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            return '🌐';
        }
        if (!function_exists('mb_chr')) {
            return $code;
        }
        return mb_chr(127397 + ord($code[0]), 'UTF-8') . mb_chr(127397 + ord($code[1]), 'UTF-8');
    }

    public static function ensureSchema(Database $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $pdo = $db->getConnection();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS site_visits (
                visitor_id CHAR(32) NOT NULL,
                country CHAR(2) NOT NULL DEFAULT '',
                day DATE NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (visitor_id, day),
                KEY idx_visit_country (country, day),
                KEY idx_visit_day (day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(['users', 'country']);
        if ((int) $stmt->fetchColumn() === 0) {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN country CHAR(2) NULL DEFAULT NULL");
            } catch (PDOException $e) {
                /* another request may have added it */
            }
        }
    }

    private static function skipRequest(): bool
    {
        $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|wget|curl|python-requests|headless/', $ua)) {
            return true;
        }
        $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        return (bool) preg_match('#/(?:admin|api|cron-|deploy|migrate|health|payment-|install-db|repair-)#', $path);
    }

    private static function visitorId(): string
    {
        $cookie = preg_replace('/[^a-f0-9]/', '', (string) ($_COOKIE['st_vid'] ?? ''));
        if (strlen($cookie) === 32) {
            return $cookie;
        }
        $id = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (defined('SITE_URL') && str_starts_with((string) SITE_URL, 'https://'));
            setcookie('st_vid', $id, [
                'expires' => time() + 86400 * 400,
                'path' => '/',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE['st_vid'] = $id;
        return $id;
    }

    /** @return array<string, mixed> */
    private static function emptyReport(int $days): array
    {
        return [
            'days' => $days,
            'visitors' => 0,
            'signups' => 0,
            'depositors' => 0,
            'deposited' => 0.0,
            'signup_rate' => 0.0,
            'deposit_rate' => 0.0,
            'countries' => [],
            'dayseries' => [],
            'known_countries' => 0,
        ];
    }

    /** @return array<string, string> */
    private static function names(): array
    {
        return [
            'TR' => 'Turkey', 'DE' => 'Germany', 'US' => 'United States', 'GB' => 'United Kingdom',
            'FR' => 'France', 'NL' => 'Netherlands', 'IT' => 'Italy', 'ES' => 'Spain',
            'RU' => 'Russia', 'UA' => 'Ukraine', 'PL' => 'Poland', 'RO' => 'Romania',
            'AZ' => 'Azerbaijan', 'IR' => 'Iran', 'IQ' => 'Iraq', 'SA' => 'Saudi Arabia',
            'AE' => 'United Arab Emirates', 'QA' => 'Qatar', 'KW' => 'Kuwait', 'EG' => 'Egypt',
            'IN' => 'India', 'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'ID' => 'Indonesia',
            'MY' => 'Malaysia', 'SG' => 'Singapore', 'PH' => 'Philippines', 'VN' => 'Vietnam',
            'CN' => 'China', 'JP' => 'Japan', 'KR' => 'South Korea', 'BR' => 'Brazil',
            'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'MX' => 'Mexico',
            'AR' => 'Argentina', 'NG' => 'Nigeria', 'ZA' => 'South Africa', 'KE' => 'Kenya',
            'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland',
            'AT' => 'Austria', 'CH' => 'Switzerland', 'BE' => 'Belgium', 'PT' => 'Portugal',
            'GR' => 'Greece', 'CZ' => 'Czechia', 'HU' => 'Hungary', 'BG' => 'Bulgaria',
            'RS' => 'Serbia', 'HR' => 'Croatia', 'KZ' => 'Kazakhstan', 'UZ' => 'Uzbekistan',
            'GE' => 'Georgia', 'AM' => 'Armenia', 'IL' => 'Israel', 'MA' => 'Morocco',
            'DZ' => 'Algeria', 'TN' => 'Tunisia', 'TH' => 'Thailand', 'HK' => 'Hong Kong',
        ];
    }
}
