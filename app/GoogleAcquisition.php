<?php
/**
 * Unique Google customers: first-touch attribution, commercial SEO pages, conversion events.
 */
class GoogleAcquisition
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        self::ensureSchema($this->db);
    }

    public static function ensureSchema(Database $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $pdo = $db->getConnection();
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS acquisition_events (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    visitor_id CHAR(32) NOT NULL,
                    user_id INT UNSIGNED DEFAULT NULL,
                    event ENUM('view','signup','deposit','order') NOT NULL,
                    source VARCHAR(64) NOT NULL DEFAULT '',
                    medium VARCHAR(64) NOT NULL DEFAULT '',
                    campaign VARCHAR(64) DEFAULT NULL,
                    gclid VARCHAR(128) DEFAULT NULL,
                    landing_path VARCHAR(191) NOT NULL DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_acq_visitor_event (visitor_id, event, created_at),
                    KEY idx_acq_source_event (source, event, created_at),
                    KEY idx_acq_user_event (user_id, event)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (PDOException $e) {
            /* table may exist */
        }
        foreach ([
            'utm_medium' => 'VARCHAR(64) DEFAULT NULL',
            'gclid' => 'VARCHAR(128) DEFAULT NULL',
            'acquisition_landing' => 'VARCHAR(191) DEFAULT NULL',
        ] as $col => $def) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute(['users', $col]);
            if ((int) $stmt->fetchColumn() === 0) {
                try {
                    $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $def");
                } catch (PDOException $e) {
                    /* ignore */
                }
            }
        }
        foreach ([
            'ga4_measurement_id',
            'google_ads_id',
            'google_ads_signup_label',
            'google_ads_purchase_label',
            'google_site_verification',
        ] as $settingKey) {
            if ($db->getSetting($settingKey) === null) {
                try {
                    $db->setSetting($settingKey, '');
                } catch (Throwable $e) {
                    /* settings table may be unavailable during install */
                }
            }
        }
    }

    /**
     * Capture first-touch Google (organic / Ads) on guest page views.
     * Call after session_start. Does not overwrite an existing first touch.
     */
    public static function capture(): void
    {
        if (php_sapi_name() === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $visitorId = self::visitorId();
        $gclid = self::sanitizeParam($_GET['gclid'] ?? $_GET['gbraid'] ?? $_GET['wbraid'] ?? '', 128);
        $utmSource = self::sanitizeParam($_GET['utm_source'] ?? '', 64);
        $utmMedium = self::sanitizeParam($_GET['utm_medium'] ?? '', 64);
        $utmCampaign = self::sanitizeParam($_GET['utm_campaign'] ?? '', 64);
        $landing = self::currentPath();
        $referrerHost = self::referrerHost();

        if ($gclid !== '') {
            $utmSource = $utmSource !== '' ? $utmSource : 'google';
            $utmMedium = $utmMedium !== '' ? $utmMedium : 'cpc';
        } elseif ($utmSource === '' && self::isGoogleHost($referrerHost)) {
            $utmSource = 'google';
            $utmMedium = 'organic';
        }

        if (empty($_SESSION['growth_utm_source']) && $utmSource !== '') {
            $_SESSION['growth_utm_source'] = $utmSource;
        }
        if (empty($_SESSION['growth_utm_medium']) && $utmMedium !== '') {
            $_SESSION['growth_utm_medium'] = $utmMedium;
        }
        if (empty($_SESSION['growth_utm_campaign']) && $utmCampaign !== '') {
            $_SESSION['growth_utm_campaign'] = $utmCampaign;
        }
        if (empty($_SESSION['growth_gclid']) && $gclid !== '') {
            $_SESSION['growth_gclid'] = $gclid;
        }
        if (empty($_SESSION['growth_landing']) && $landing !== '') {
            $_SESSION['growth_landing'] = $landing;
        }

        if (self::isBot()) {
            return;
        }

        $source = (string) ($_SESSION['growth_utm_source'] ?? '');
        $medium = (string) ($_SESSION['growth_utm_medium'] ?? '');
        if ($source === '' && $medium === '' && $gclid === '') {
            return;
        }

        try {
            $engine = new self();
            $engine->logEvent('view', null, $visitorId, $landing);
        } catch (Throwable $e) {
            /* best effort */
        }
    }

    public function applyToUser(int $userId): void
    {
        $source = trim((string) ($_SESSION['growth_utm_source'] ?? ''));
        $medium = trim((string) ($_SESSION['growth_utm_medium'] ?? ''));
        $campaign = trim((string) ($_SESSION['growth_utm_campaign'] ?? ''));
        $gclid = trim((string) ($_SESSION['growth_gclid'] ?? ''));
        $landing = trim((string) ($_SESSION['growth_landing'] ?? ''));
        if ($source === '' && $campaign === '' && $gclid === '' && $landing === '') {
            return;
        }
        try {
            $this->db->execute(
                'UPDATE users SET
                    utm_source = COALESCE(utm_source, ?),
                    utm_medium = COALESCE(utm_medium, ?),
                    utm_campaign = COALESCE(utm_campaign, ?),
                    gclid = COALESCE(gclid, ?),
                    acquisition_landing = COALESCE(acquisition_landing, ?)
                 WHERE id = ?',
                [
                    $source !== '' ? $source : null,
                    $medium !== '' ? $medium : null,
                    $campaign !== '' ? $campaign : null,
                    $gclid !== '' ? $gclid : null,
                    $landing !== '' ? $landing : null,
                    $userId,
                ]
            );
        } catch (Throwable $e) {
            /* columns may not exist yet */
        }
    }

    public function trackSignup(int $userId): void
    {
        $this->logEvent('signup', $userId);
        $this->queueClientEvent('sign_up', 0);
    }

    public function trackFirstDeposit(int $userId, float $amount): void
    {
        $prior = (int) ($this->db->fetch(
            "SELECT COUNT(*) c FROM acquisition_events WHERE user_id = ? AND event = 'deposit'",
            [$userId]
        )['c'] ?? 0);
        if ($prior > 0) {
            return;
        }
        $this->logEvent('deposit', $userId);
        $this->queueClientEvent('purchase', max(0, round($amount, 2)));
    }

    public function trackFirstOrder(int $userId, float $charge): void
    {
        $prior = (int) ($this->db->fetch(
            "SELECT COUNT(*) c FROM acquisition_events WHERE user_id = ? AND event = 'order'",
            [$userId]
        )['c'] ?? 0);
        if ($prior > 0) {
            return;
        }
        $this->logEvent('order', $userId);
        $this->queueClientEvent('first_order', max(0, round($charge, 2)));
    }

    /**
     * @return array{ga4: string, ads: string, signup_label: string, purchase_label: string, verification: string}
     */
    public function trackingConfig(): array
    {
        $verification = '';
        if (defined('GOOGLE_SITE_VERIFICATION')) {
            $verification = trim((string) GOOGLE_SITE_VERIFICATION);
        }
        if ($verification === '') {
            $verification = trim((string) ($this->db->getSetting('google_site_verification') ?: ''));
        }
        return [
            'ga4' => strtoupper(trim((string) ($this->db->getSetting('ga4_measurement_id') ?: ''))),
            'ads' => strtoupper(trim((string) ($this->db->getSetting('google_ads_id') ?: ''))),
            'signup_label' => trim((string) ($this->db->getSetting('google_ads_signup_label') ?: '')),
            'purchase_label' => trim((string) ($this->db->getSetting('google_ads_purchase_label') ?: '')),
            'verification' => $verification,
        ];
    }

    /** @return list<array{event: string, value: float}> */
    public static function consumeClientEvents(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $events = $_SESSION['ga_pending_events'] ?? [];
        unset($_SESSION['ga_pending_events']);
        return is_array($events) ? $events : [];
    }

    /**
     * Admin funnel: unique Google visitors → signups → paying customers.
     *
     * @return array<string, mixed>
     */
    public function report(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        $googleFilter = "(source = 'google' OR (gclid IS NOT NULL AND gclid != ''))";

        $uniqueVisitors = (int) ($this->db->fetch(
            "SELECT COUNT(DISTINCT visitor_id) c FROM acquisition_events
             WHERE event = 'view' AND created_at >= ? AND $googleFilter",
            [$since]
        )['c'] ?? 0);
        $signups = (int) ($this->db->fetch(
            "SELECT COUNT(DISTINCT COALESCE(user_id, visitor_id)) c FROM acquisition_events
             WHERE event = 'signup' AND created_at >= ? AND $googleFilter",
            [$since]
        )['c'] ?? 0);
        $payers = (int) ($this->db->fetch(
            "SELECT COUNT(DISTINCT user_id) c FROM acquisition_events
             WHERE event = 'deposit' AND user_id IS NOT NULL AND created_at >= ? AND $googleFilter",
            [$since]
        )['c'] ?? 0);
        $buyers = (int) ($this->db->fetch(
            "SELECT COUNT(DISTINCT user_id) c FROM acquisition_events
             WHERE event = 'order' AND user_id IS NOT NULL AND created_at >= ? AND $googleFilter",
            [$since]
        )['c'] ?? 0);

        $userGoogle = 0;
        $userGooglePaid = 0;
        try {
            $userGoogle = (int) ($this->db->fetch(
                "SELECT COUNT(*) c FROM users
                 WHERE role = 'user' AND created_at >= ?
                   AND (utm_source = 'google' OR (gclid IS NOT NULL AND gclid != ''))",
                [$since]
            )['c'] ?? 0);
            $userGooglePaid = (int) ($this->db->fetch(
                "SELECT COUNT(*) c FROM users
                 WHERE role = 'user' AND created_at >= ? AND spent > 0
                   AND (utm_source = 'google' OR (gclid IS NOT NULL AND gclid != ''))",
                [$since]
            )['c'] ?? 0);
        } catch (Throwable $e) {
            /* columns may not exist */
        }

        $byMedium = [];
        try {
            $byMedium = $this->db->fetchAll(
                "SELECT COALESCE(NULLIF(medium,''), 'unknown') AS medium,
                        COUNT(DISTINCT visitor_id) AS visitors,
                        SUM(event = 'signup') AS signups,
                        SUM(event = 'deposit') AS deposits
                 FROM acquisition_events
                 WHERE created_at >= ? AND $googleFilter
                 GROUP BY medium ORDER BY visitors DESC",
                [$since]
            );
        } catch (Throwable $e) {
            $byMedium = [];
        }

        $landings = [];
        try {
            $landings = $this->db->fetchAll(
                "SELECT landing_path,
                        COUNT(DISTINCT visitor_id) AS visitors,
                        SUM(event = 'signup') AS signups,
                        SUM(event = 'deposit') AS deposits
                 FROM acquisition_events
                 WHERE created_at >= ? AND $googleFilter AND landing_path != ''
                 GROUP BY landing_path ORDER BY visitors DESC LIMIT 20",
                [$since]
            );
        } catch (Throwable $e) {
            $landings = [];
        }

        $recent = [];
        try {
            $recent = $this->db->fetchAll(
                "SELECT u.id, u.username, u.email, u.spent, u.utm_source, u.utm_medium, u.utm_campaign,
                        u.acquisition_landing, u.created_at
                 FROM users u
                 WHERE u.role = 'user' AND (u.utm_source = 'google' OR (u.gclid IS NOT NULL AND u.gclid != ''))
                 ORDER BY u.id DESC LIMIT 25"
            );
        } catch (Throwable $e) {
            $recent = [];
        }

        $signupRate = $uniqueVisitors > 0 ? round(100 * $signups / $uniqueVisitors, 1) : 0.0;
        $payRate = $signups > 0 ? round(100 * max($payers, $userGooglePaid) / max($signups, $userGoogle, 1), 1) : 0.0;

        return [
            'days' => $days,
            'unique_visitors' => $uniqueVisitors,
            'signups' => max($signups, $userGoogle),
            'paying_customers' => max($payers, $userGooglePaid),
            'buyers' => $buyers,
            'signup_rate' => $signupRate,
            'pay_rate' => $payRate,
            'by_medium' => $byMedium,
            'landings' => $landings,
            'recent' => $recent,
            'pages' => self::catalog(),
        ];
    }

    /**
     * Commercial-intent landing pages Google can index as unique URLs.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return [
            [
                'slug' => 'instagram-followers',
                'platform' => 'Instagram',
                'type' => 'followers',
                'search' => ['%Instagram%Follow%', '%Instagram%Takip%'],
                'related' => ['instagram-likes', 'instagram-views', 'cheap-smm-panel'],
            ],
            [
                'slug' => 'instagram-likes',
                'platform' => 'Instagram',
                'type' => 'likes',
                'search' => ['%Instagram%Like%', '%Instagram%Beğeni%', '%Instagram%Begeni%'],
                'related' => ['instagram-followers', 'instagram-views', 'tiktok-likes'],
            ],
            [
                'slug' => 'instagram-views',
                'platform' => 'Instagram',
                'type' => 'views',
                'search' => ['%Instagram%View%', '%Instagram%İzlen%', '%Instagram%Story%'],
                'related' => ['instagram-followers', 'instagram-likes', 'youtube-views'],
            ],
            [
                'slug' => 'tiktok-followers',
                'platform' => 'TikTok',
                'type' => 'followers',
                'search' => ['%TikTok%Follow%', '%TikTok%Takip%', '%Tiktok%Follow%'],
                'related' => ['tiktok-likes', 'tiktok-views', 'instagram-followers'],
            ],
            [
                'slug' => 'tiktok-likes',
                'platform' => 'TikTok',
                'type' => 'likes',
                'search' => ['%TikTok%Like%', '%TikTok%Beğeni%', '%Tiktok%Like%'],
                'related' => ['tiktok-followers', 'tiktok-views', 'instagram-likes'],
            ],
            [
                'slug' => 'tiktok-views',
                'platform' => 'TikTok',
                'type' => 'views',
                'search' => ['%TikTok%View%', '%TikTok%İzlen%', '%Tiktok%View%'],
                'related' => ['tiktok-followers', 'tiktok-likes', 'youtube-views'],
            ],
            [
                'slug' => 'youtube-views',
                'platform' => 'YouTube',
                'type' => 'views',
                'search' => ['%YouTube%View%', '%Youtube%View%', '%YouTube%İzlen%'],
                'related' => ['youtube-subscribers', 'tiktok-views', 'instagram-views'],
            ],
            [
                'slug' => 'youtube-subscribers',
                'platform' => 'YouTube',
                'type' => 'subscribers',
                'search' => ['%YouTube%Sub%', '%Youtube%Sub%', '%YouTube%Abone%'],
                'related' => ['youtube-views', 'instagram-followers', 'cheap-smm-panel'],
            ],
            [
                'slug' => 'twitter-followers',
                'platform' => 'Twitter',
                'type' => 'followers',
                'search' => ['%Twitter%Follow%', '%X %Follow%', '%Twitter%Takip%'],
                'related' => ['instagram-followers', 'telegram-members', 'cheap-smm-panel'],
            ],
            [
                'slug' => 'telegram-members',
                'platform' => 'Telegram',
                'type' => 'members',
                'search' => ['%Telegram%Member%', '%Telegram%Üye%', '%Telegram%Join%'],
                'related' => ['twitter-followers', 'facebook-likes', 'cheap-smm-panel'],
            ],
            [
                'slug' => 'facebook-likes',
                'platform' => 'Facebook',
                'type' => 'likes',
                'search' => ['%Facebook%Like%', '%Facebook%Beğeni%', '%FB %Like%'],
                'related' => ['instagram-likes', 'telegram-members', 'cheap-smm-panel'],
            ],
            [
                'slug' => 'cheap-smm-panel',
                'platform' => 'SMM',
                'type' => 'panel',
                'search' => ['%Instagram%', '%TikTok%', '%YouTube%'],
                'related' => ['instagram-followers', 'tiktok-followers', 'youtube-views'],
            ],
        ];
    }

    public static function findPage(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        foreach (self::catalog() as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }
        return null;
    }

    /**
     * Unique copy per slug + language (not thin duplicates).
     *
     * @return array{title: string, h1: string, desc: string, intro: string, body: list<array{h: string, p: string}>, faqs: list<array{name: string, text: string}>, keywords: string, how: list<array{name: string, text: string}>}
     */
    public static function copy(string $slug, string $lang): array
    {
        $all = self::copyBank();
        $page = $all[$slug] ?? $all['cheap-smm-panel'];
        $pack = $page[$lang] ?? $page['en'] ?? reset($page);
        return $pack;
    }

    /** @return list<array<string, mixed>> */
    public function liveServices(array $page, int $limit = 12): array
    {
        $limit = max(1, min(40, $limit));
        $revenue = new RevenueEngine();
        $rows = [];
        foreach ($page['search'] as $like) {
            $found = $this->db->fetchAll(
                "SELECT * FROM services WHERE status = 'active' AND (category LIKE ? OR name LIKE ?)
                 ORDER BY (rate * (1 + markup/100)) ASC LIMIT ?",
                [$like, $like, $limit]
            );
            foreach ($found as $row) {
                $id = (int) ($row['service_id'] ?? $row['id'] ?? 0);
                if ($id === 0 || isset($rows[$id])) {
                    continue;
                }
                $row['retail_rate'] = $revenue->retailRatePerThousand($row, 0);
                $rows[$id] = $row;
            }
            if (count($rows) >= $limit) {
                break;
            }
        }
        $list = array_values($rows);
        usort($list, static fn ($a, $b) => ($a['retail_rate'] <=> $b['retail_rate']));
        return array_slice($list, 0, $limit);
    }

    public static function pageUrl(string $slug): string
    {
        return function_exists('path') ? path('buy.php') . '/' . rawurlencode($slug) : '/buy/' . rawurlencode($slug);
    }

    public static function hubUrl(): string
    {
        return function_exists('path') ? path('buy.php') : '/buy';
    }

    private function logEvent(string $event, ?int $userId, ?string $visitorId = null, ?string $landing = null): void
    {
        $visitorId = $visitorId ?? self::visitorId();
        $landing = $landing ?? (string) ($_SESSION['growth_landing'] ?? self::currentPath());
        $source = (string) ($_SESSION['growth_utm_source'] ?? '');
        $medium = (string) ($_SESSION['growth_utm_medium'] ?? '');
        $campaign = (string) ($_SESSION['growth_utm_campaign'] ?? '');
        $gclid = (string) ($_SESSION['growth_gclid'] ?? '');

        if ($userId) {
            $user = $this->db->fetch(
                'SELECT utm_source, utm_medium, utm_campaign, gclid, acquisition_landing FROM users WHERE id = ?',
                [$userId]
            );
            if ($user) {
                $source = $source !== '' ? $source : (string) ($user['utm_source'] ?? '');
                $medium = $medium !== '' ? $medium : (string) ($user['utm_medium'] ?? '');
                $campaign = $campaign !== '' ? $campaign : (string) ($user['utm_campaign'] ?? '');
                $gclid = $gclid !== '' ? $gclid : (string) ($user['gclid'] ?? '');
                $landing = $landing !== '' ? $landing : (string) ($user['acquisition_landing'] ?? '');
            }
        }

        if ($event === 'view') {
            $recent = $this->db->fetch(
                "SELECT id FROM acquisition_events
                 WHERE visitor_id = ? AND event = 'view' AND landing_path = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 LIMIT 1",
                [$visitorId, $landing]
            );
            if ($recent) {
                return;
            }
        }

        try {
            $this->db->insert(
                'INSERT INTO acquisition_events (visitor_id, user_id, event, source, medium, campaign, gclid, landing_path)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $visitorId,
                    $userId,
                    $event,
                    substr($source, 0, 64),
                    substr($medium, 0, 64),
                    $campaign !== '' ? substr($campaign, 0, 64) : null,
                    $gclid !== '' ? substr($gclid, 0, 128) : null,
                    substr($landing, 0, 191),
                ]
            );
        } catch (Throwable $e) {
            /* ignore */
        }
    }

    private function queueClientEvent(string $event, float $value): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!isset($_SESSION['ga_pending_events']) || !is_array($_SESSION['ga_pending_events'])) {
            $_SESSION['ga_pending_events'] = [];
        }
        $_SESSION['ga_pending_events'][] = ['event' => $event, 'value' => $value];
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

    private static function currentPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return substr($path, 0, 191);
    }

    private static function referrerHost(): string
    {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $host = parse_url($ref, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }

    private static function isGoogleHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }
        return (bool) preg_match('/(^|\.)google\.[a-z.]+$/', $host);
    }

    private static function isBot(): bool
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return $ua !== '' && (bool) preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|bytespider/i', $ua);
    }

    private static function sanitizeParam(mixed $value, int $max): string
    {
        $v = trim((string) $value);
        if ($v === '' || strlen($v) > $max) {
            return $v === '' ? '' : substr($v, 0, $max);
        }
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $v)) {
            return '';
        }
        return $v;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function copyBank(): array
    {
        return [
            'instagram-followers' => [
                'tr' => [
                    'title' => 'Instagram takipçi satın al — ucuz SMM panel',
                    'h1' => 'Instagram takipçi satın al',
                    'desc' => 'SMM Turk ile Instagram takipçi satın al. Türkiye ve dünya için ucuz SMM panel, anında başlangıç, kripto ödeme. Canlı fiyatlar.',
                    'keywords' => 'instagram takipçi satın al, ucuz instagram takipçi, smm panel türkiye, buy instagram followers',
                    'intro' => 'Google’dan Instagram takipçi arayanlar için bu sayfa net bir yol gösterir: ücretsiz hesap açın, kripto ile bakiye ekleyin, servisi seçin ve siparişi başlatın. SMM Turk bir SMM panelidir — toptan fiyat, otomatik teslimat, 7/24 bilet desteği.',
                    'body' => [
                        ['h' => 'Neden panelden takipçi alınır?', 'p' => 'Tek tek ajanslarla uğraşmak yerine panel, yüzlerce Instagram servisini tek bakiyeden satar. Fiyatlar 1.000 adet başına gösterilir; minimum sipariş servise göre değişir. İlk sipariş için hoş geldin bakiyesi ve ilk yatırıma bonus tanımlıdır.'],
                        ['h' => 'Nasıl çalışır?', 'p' => 'Kayıt → bakiye (USDT, BTC, ETH) → Instagram Followers kategorisinden servis seç → profil linki ve adet → sipariş. Durumu Siparişlerim ekranından izlersiniz. API veya child panel ile aynı katalogu kendi müşterilerinize de satabilirsiniz.'],
                        ['h' => 'Kimler için?', 'p' => 'İçerik üreticileri, ajanslar ve bayi işi kurmak isteyenler. Organik içerikle birlikte kademeli sipariş, ani sıçramadan daha doğal durur. Sahte vaat yok: teslimat hızı ve kalite servise göre değişir — en ucuz satırı her zaman “en kaliteli” değildir.'],
                    ],
                    'how' => [
                        ['name' => 'Ücretsiz kayıt', 'text' => '30 saniyede hesap açın. Google ile giriş de mümkündür.'],
                        ['name' => 'Bakiye ekleyin', 'text' => 'USDT, BTC veya ETH yatırın. İlk yatırıma bonus uygulanabilir.'],
                        ['name' => 'Siparişi başlatın', 'text' => 'Instagram takipçi servisini seçin, link ve adeti girin.'],
                    ],
                    'faqs' => [
                        ['name' => 'Instagram takipçi ne kadar tutar?', 'text' => 'Canlı tablo bu sayfada 1.000 adet başına paneli fiyatını gösterir. En düşük satır stok ve sağlayıcıya göre değişir.'],
                        ['name' => 'Takipçiler düşer mi?', 'text' => 'Servis kalitesine göre değişir. Düşüş yaşarsanız bilet açın; kısmi teslimatta bakiyenin ilgili kısmı iade kurallarına göre işlenir.'],
                        ['name' => 'Ödeme nasıl?', 'text' => 'Şu an yalnızca kripto (BTC, ETH, USDT). Kart veya PayPal yok.'],
                    ],
                ],
                'en' => [
                    'title' => 'Buy Instagram followers — cheap SMM panel',
                    'h1' => 'Buy Instagram followers',
                    'desc' => 'Buy Instagram followers from SMM Turk. Cheap SMM panel for Turkey and worldwide, instant start, crypto checkout. Live rates.',
                    'keywords' => 'buy instagram followers, cheap instagram followers, smm panel, instagram growth',
                    'intro' => 'If you found this page from Google, the path is simple: create a free account, add crypto balance, pick an Instagram followers service, and start the order. SMM Turk is a reseller panel — wholesale rates, automated delivery, 24/7 tickets.',
                    'body' => [
                        ['h' => 'Why order from a panel?', 'p' => 'One wallet covers hundreds of Instagram services instead of chasing agencies. Rates are per 1,000. New accounts can get welcome credit and a first-deposit bonus so the first test order is cheap.'],
                        ['h' => 'How delivery works', 'p' => 'Register → deposit USDT/BTC/ETH → choose Instagram Followers → paste the profile URL and quantity. Track status under Orders. Resellers can sell the same catalog via API or a child panel on their own domain.'],
                        ['h' => 'Who it is for', 'p' => 'Creators, agencies, and people building a reseller income. Combine with real posts; smaller batches look more natural. Cheapest is not always highest quality — compare speed and refill notes in the service name.'],
                    ],
                    'how' => [
                        ['name' => 'Create a free account', 'text' => 'Sign up in 30 seconds. Google Sign-In is available.'],
                        ['name' => 'Add funds', 'text' => 'Deposit USDT, BTC, or ETH. First-deposit bonus may apply.'],
                        ['name' => 'Place the order', 'text' => 'Pick an Instagram followers service, enter the link and quantity.'],
                    ],
                    'faqs' => [
                        ['name' => 'How much do Instagram followers cost?', 'text' => 'The live table on this page shows panel rates per 1,000. The cheapest row changes with provider stock.'],
                        ['name' => 'Will followers drop?', 'text' => 'It depends on the service. Open a ticket if delivery is incomplete; partial jobs follow the refund rules.'],
                        ['name' => 'How do I pay?', 'text' => 'Crypto only for now: BTC, ETH, USDT. No cards or PayPal.'],
                    ],
                ],
                'de' => [
                    'title' => 'Instagram-Follower kaufen — günstiges SMM-Panel',
                    'h1' => 'Instagram-Follower kaufen',
                    'desc' => 'Instagram-Follower über SMM Turk kaufen. Günstiges SMM-Panel, Sofortstart, Krypto-Zahlung. Live-Preise.',
                    'keywords' => 'instagram follower kaufen, günstige instagram follower, smm panel',
                    'intro' => 'Von Google hier gelandet? Konto anlegen, Krypto einzahlen, Instagram-Follower-Service wählen, Bestellung starten. SMM Turk ist ein Reseller-Panel mit Großhandelspreisen.',
                    'body' => [
                        ['h' => 'Warum ein Panel?', 'p' => 'Ein Guthaben für hunderte Instagram-Dienste. Preise je 1.000 Stück. Willkommensguthaben und Erst-Einzahlungsbonus senken die erste Testbestellung.'],
                        ['h' => 'Ablauf', 'p' => 'Registrieren → USDT/BTC/ETH → Instagram Followers wählen → Profil-URL und Menge. Status unter Bestellungen. API und Child Panel für Wiederverkäufer.'],
                        ['h' => 'Für wen?', 'p' => 'Creator, Agenturen, Reseller. Mit echten Posts kombinieren. Der günstigste Dienst ist nicht immer der beste.'],
                    ],
                    'how' => [
                        ['name' => 'Kostenlos registrieren', 'text' => 'Konto in 30 Sekunden. Optional Google-Login.'],
                        ['name' => 'Guthaben laden', 'text' => 'USDT, BTC oder ETH. Bonus auf die erste Einzahlung möglich.'],
                        ['name' => 'Bestellen', 'text' => 'Follower-Service wählen, Link und Menge eingeben.'],
                    ],
                    'faqs' => [
                        ['name' => 'Was kosten Instagram-Follower?', 'text' => 'Die Live-Tabelle zeigt Panelpreise je 1.000. Der günstigste Satz ändert sich mit dem Anbieter.'],
                        ['name' => 'Fallen Follower ab?', 'text' => 'Je nach Service. Bei Teillieferung Ticket öffnen.'],
                        ['name' => 'Zahlung?', 'text' => 'Nur Krypto: BTC, ETH, USDT.'],
                    ],
                ],
            ],
            'instagram-likes' => [
                'tr' => [
                    'title' => 'Instagram beğeni satın al — ucuz SMM',
                    'h1' => 'Instagram beğeni satın al',
                    'desc' => 'Instagram gönderilerine ucuz beğeni. SMM Turk paneli, anında başlangıç, kripto ödeme, canlı fiyat listesi.',
                    'keywords' => 'instagram beğeni satın al, ucuz instagram like, smm panel instagram',
                    'intro' => 'Gönderi etkileşimini hızlı artırmak isteyenler için Instagram beğeni servisleri panelde 1.000 adet fiyatıyla listelenir. Kayıt ücretsizdir; ödeme yalnızca kripto.',
                    'body' => [
                        ['h' => 'Beğeni ne işe yarar?', 'p' => 'Yeni bir gönderinin sosyal kanıtını yükseltir. Takipçi siparişiyle birlikte kullanıldığında keşifte daha tutarlı durur. Küçük partiler, tek seferde dev siparişten daha inandırıcıdır.'],
                        ['h' => 'Sipariş ipuçları', 'p' => 'Herkese açık gönderi URL’si gerekir. Gizli hesaplar teslim edilemez. Servis adındaki hız (anında / 24 saat) ve ülke filtresini okuyun.'],
                        ['h' => 'Bayi kullanımı', 'p' => 'Ajanslar aynı beğeni servislerini API veya child panel üzerinden kendi müşterilerine satabilir; marj sizde kalır.'],
                    ],
                    'how' => [
                        ['name' => 'Hesap açın', 'text' => 'Ücretsiz kayıt veya Google ile giriş.'],
                        ['name' => 'Bakiye', 'text' => 'USDT / BTC / ETH yatırın.'],
                        ['name' => 'Gönderi linki', 'text' => 'Beğeni servisini seçip genel gönderi URL’sini yapıştırın.'],
                    ],
                    'faqs' => [
                        ['name' => 'Beğeniler kalıcı mı?', 'text' => 'Servise göre değişir. Düşüş garantisi servis açıklamasında yazıyorsa geçerlidir.'],
                        ['name' => 'Story’ye beğeni gider mi?', 'text' => 'Hayır — bu sayfa gönderi beğenileri içindir. Story görüntüleme ayrı servistir.'],
                        ['name' => 'Minimum adet?', 'text' => 'Her satırın Min sütununa bakın; çoğu servis 10–50’den başlar.'],
                    ],
                ],
                'en' => [
                    'title' => 'Buy Instagram likes — cheap SMM panel',
                    'h1' => 'Buy Instagram likes',
                    'desc' => 'Cheap Instagram likes for posts. SMM Turk panel, instant start, crypto payment, live rates.',
                    'keywords' => 'buy instagram likes, cheap instagram likes, smm panel instagram',
                    'intro' => 'Instagram like services are listed per 1,000. Free signup, crypto checkout only. Use likes with real posts so engagement looks consistent.',
                    'body' => [
                        ['h' => 'Why likes', 'p' => 'They add social proof on a new post. Smaller batches usually look more natural than one huge hit.'],
                        ['h' => 'Order tips', 'p' => 'You need a public post URL. Private accounts cannot be delivered. Read speed and geo notes in the service name.'],
                        ['h' => 'Resellers', 'p' => 'Agencies can resell the same likes via API or a child panel and keep the markup.'],
                    ],
                    'how' => [
                        ['name' => 'Sign up', 'text' => 'Free account or Google Sign-In.'],
                        ['name' => 'Add funds', 'text' => 'USDT, BTC, or ETH.'],
                        ['name' => 'Paste the post', 'text' => 'Choose a likes service and the public post URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'Do likes stay?', 'text' => 'Depends on the service. Refill terms are in the service name when offered.'],
                        ['name' => 'Stories?', 'text' => 'This page is for post likes. Story views are a different service.'],
                        ['name' => 'Minimum quantity?', 'text' => 'See the Min column — often 10–50.'],
                    ],
                ],
                'de' => [
                    'title' => 'Instagram-Likes kaufen — günstiges SMM-Panel',
                    'h1' => 'Instagram-Likes kaufen',
                    'desc' => 'Günstige Instagram-Likes für Beiträge. SMM Turk, Sofortstart, Krypto, Live-Preise.',
                    'keywords' => 'instagram likes kaufen, günstige instagram likes, smm panel',
                    'intro' => 'Like-Dienste je 1.000 gelistet. Kostenlose Registrierung, nur Krypto. Mit echten Posts kombinieren.',
                    'body' => [
                        ['h' => 'Warum Likes?', 'p' => 'Sozialer Beweis auf neuen Beiträgen. Kleine Chargen wirken natürlicher.'],
                        ['h' => 'Tipps', 'p' => 'Öffentliche Beitrags-URL nötig. Private Konten werden nicht beliefert.'],
                        ['h' => 'Reseller', 'p' => 'Dieselben Likes über API oder Child Panel weiterverkaufen.'],
                    ],
                    'how' => [
                        ['name' => 'Registrieren', 'text' => 'Kostenloses Konto oder Google-Login.'],
                        ['name' => 'Einzahlen', 'text' => 'USDT, BTC oder ETH.'],
                        ['name' => 'Link einfügen', 'text' => 'Like-Service und öffentliche URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'Bleiben Likes?', 'text' => 'Je nach Service. Refill steht im Dienstnamen.'],
                        ['name' => 'Stories?', 'text' => 'Diese Seite gilt für Beitragslikes.'],
                        ['name' => 'Minimum?', 'text' => 'Spalte Min — oft 10–50.'],
                    ],
                ],
            ],
            'instagram-views' => [
                'tr' => [
                    'title' => 'Instagram görüntülenme satın al',
                    'h1' => 'Instagram görüntülenme ve story izlenme',
                    'desc' => 'Instagram reels, video ve story görüntülenme. Ucuz SMM panel, kripto ödeme, canlı fiyat.',
                    'keywords' => 'instagram görüntülenme satın al, instagram story izlenme, reels views',
                    'intro' => 'Reels ve story izlenme, keşif algoritmasına sinyal gönderir. Bu sayfa görüntülenme servislerinin canlı fiyatını listeler — takipçi veya beğeniden ayrı bir üründür.',
                    'body' => [
                        ['h' => 'Reels vs story', 'p' => 'Reels/video URL’si ile story linki farklı servislerdir. Yanlış link iptal veya hatalı teslimata yol açar. Siparişten önce servis adını okuyun.'],
                        ['h' => 'Ne zaman işe yarar?', 'p' => 'Yeni içerik yayınladıktan sonraki ilk saatlerde izlenme, erişimi destekleyebilir. Organik paylaşım ritmini değiştirmez; sadece ek sinyal ekler.'],
                        ['h' => 'Fiyat', 'p' => 'Görüntülenme genelde takipçiden ucuzdur. Tabloda 1K başına en düşük satırı ve minimumu kontrol edin.'],
                    ],
                    'how' => [
                        ['name' => 'Kayıt', 'text' => 'Ücretsiz hesap.'],
                        ['name' => 'Bakiye', 'text' => 'Kripto yatırım.'],
                        ['name' => 'Doğru link', 'text' => 'Reels, video veya story servisine uygun URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'İzlenme profilime yazar mı?', 'text' => 'Evet, Instagram’ın gösterdiği görüntülenme sayısına eklenir (servis çalışırsa).'],
                        ['name' => 'Ne kadar sürer?', 'text' => 'Servise göre dakikalar veya saatler. Adındaki hız ifadesine bakın.'],
                        ['name' => 'Gizli hesap?', 'text' => 'Teslim edilemez. Hesabı herkese açık tutun.'],
                    ],
                ],
                'en' => [
                    'title' => 'Buy Instagram views and story views',
                    'h1' => 'Buy Instagram views',
                    'desc' => 'Instagram Reels, video, and story views. Cheap SMM panel, crypto payment, live rates.',
                    'keywords' => 'buy instagram views, instagram story views, reels views smm',
                    'intro' => 'Views send a reach signal. This page lists view services — separate from followers or likes — with live panel rates.',
                    'body' => [
                        ['h' => 'Reels vs stories', 'p' => 'Reels/video URLs and story links are different services. Wrong links fail. Read the service name first.'],
                        ['h' => 'When it helps', 'p' => 'Views in the first hours after posting can support reach. They do not replace a posting schedule.'],
                        ['h' => 'Price', 'p' => 'Views are usually cheaper than followers. Check rate per 1K and minimum in the table.'],
                    ],
                    'how' => [
                        ['name' => 'Sign up', 'text' => 'Free account.'],
                        ['name' => 'Add funds', 'text' => 'Crypto deposit.'],
                        ['name' => 'Correct URL', 'text' => 'Match Reels, video, or story service.'],
                    ],
                    'faqs' => [
                        ['name' => 'Do views count on the profile?', 'text' => 'They add to Instagram’s view count when the service delivers.'],
                        ['name' => 'How fast?', 'text' => 'Minutes to hours depending on the service name.'],
                        ['name' => 'Private account?', 'text' => 'Cannot be delivered. Keep the account public.'],
                    ],
                ],
                'de' => [
                    'title' => 'Instagram-Views kaufen',
                    'h1' => 'Instagram-Views und Story-Views',
                    'desc' => 'Instagram Reels-, Video- und Story-Views. Günstiges SMM-Panel, Krypto, Live-Preise.',
                    'keywords' => 'instagram views kaufen, story views, reels views',
                    'intro' => 'Views sind ein Reichweitensignal. Diese Seite listet View-Dienste — getrennt von Followern und Likes.',
                    'body' => [
                        ['h' => 'Reels vs Stories', 'p' => 'Unterschiedliche Dienste, unterschiedliche Links. Falsche URL = keine Lieferung.'],
                        ['h' => 'Wann sinnvoll?', 'p' => 'In den ersten Stunden nach dem Post. Ersetzt keinen Content-Plan.'],
                        ['h' => 'Preis', 'p' => 'Meist günstiger als Follower. Rate je 1.000 und Minimum prüfen.'],
                    ],
                    'how' => [
                        ['name' => 'Registrieren', 'text' => 'Kostenloses Konto.'],
                        ['name' => 'Einzahlen', 'text' => 'Krypto.'],
                        ['name' => 'Passende URL', 'text' => 'Reels, Video oder Story.'],
                    ],
                    'faqs' => [
                        ['name' => 'Zählen Views am Profil?', 'text' => 'Ja, wenn der Dienst liefert.'],
                        ['name' => 'Wie schnell?', 'text' => 'Minuten bis Stunden.'],
                        ['name' => 'Privates Konto?', 'text' => 'Keine Lieferung. Konto öffentlich halten.'],
                    ],
                ],
            ],
            'tiktok-followers' => [
                'tr' => [
                    'title' => 'TikTok takipçi satın al — ucuz SMM panel',
                    'h1' => 'TikTok takipçi satın al',
                    'desc' => 'TikTok takipçi, ucuz SMM panel Türkiye. Anında başlangıç, kripto ödeme, canlı fiyatlar.',
                    'keywords' => 'tiktok takipçi satın al, ucuz tiktok takipçi, smm panel tiktok',
                    'intro' => 'TikTok’ta sosyal kanıt, yeni videoların deneme oranını etkiler. SMM Turk panelinden TikTok takipçi servislerini 1K fiyatıyla alın; kayıt ücretsiz, ödeme kripto.',
                    'body' => [
                        ['h' => 'Takipçi + izlenme', 'p' => 'Sadece takipçi almak yetmez; yeni videolara izlenme ve beğeni eklemek profili daha tutarlı gösterir. İlgili sayfalara bu sayfanın altından geçin.'],
                        ['h' => 'Link formatı', 'p' => 'Profil URL’si (tiktok.com/@kullanici) gerekir. Video linki takipçi servisine yapıştırılırsa sipariş başarısız olur.'],
                        ['h' => 'Bayi', 'p' => 'Kendi TikTok büyüme işinizi child panel veya API ile kurabilirsiniz — müşteri size öder, maliyet panel bakiyenizden düşer.'],
                    ],
                    'how' => [
                        ['name' => 'Kayıt', 'text' => 'Ücretsiz hesap.'],
                        ['name' => 'Yatırım', 'text' => 'USDT, BTC, ETH.'],
                        ['name' => 'Profil linki', 'text' => '@kullanıcı URL’si ile sipariş.'],
                    ],
                    'faqs' => [
                        ['name' => 'TikTok takipçi fiyatı?', 'text' => 'Canlı tabloda 1K başına. Stok değiştikçe satır güncellenir.'],
                        ['name' => 'Hesap kapanır mı?', 'text' => 'Aşırı ani artış risklidir. Kademeli sipariş ve gerçek içerik önerilir.'],
                        ['name' => 'Ödeme?', 'text' => 'Yalnızca kripto.'],
                    ],
                ],
                'en' => [
                    'title' => 'Buy TikTok followers — cheap SMM panel',
                    'h1' => 'Buy TikTok followers',
                    'desc' => 'Buy TikTok followers from a cheap SMM panel. Instant start, crypto checkout, live rates.',
                    'keywords' => 'buy tiktok followers, cheap tiktok followers, smm panel tiktok',
                    'intro' => 'Follower count is social proof on TikTok. Order from SMM Turk per 1,000. Free signup, crypto only.',
                    'body' => [
                        ['h' => 'Followers plus views', 'p' => 'Followers alone look thin. Pair with views/likes on new videos. Related pages are linked below.'],
                        ['h' => 'Link format', 'p' => 'Use a profile URL (tiktok.com/@user). A video URL on a followers service will fail.'],
                        ['h' => 'Resell', 'p' => 'Run your own TikTok growth shop via child panel or API — clients pay you, cost hits your panel balance.'],
                    ],
                    'how' => [
                        ['name' => 'Sign up', 'text' => 'Free account.'],
                        ['name' => 'Deposit', 'text' => 'USDT, BTC, ETH.'],
                        ['name' => 'Profile URL', 'text' => 'Order with the @username link.'],
                    ],
                    'faqs' => [
                        ['name' => 'Price?', 'text' => 'Live table per 1K. Rows change with stock.'],
                        ['name' => 'Ban risk?', 'text' => 'Sudden spikes are risky. Use batches plus real content.'],
                        ['name' => 'Payment?', 'text' => 'Crypto only.'],
                    ],
                ],
                'de' => [
                    'title' => 'TikTok-Follower kaufen',
                    'h1' => 'TikTok-Follower kaufen',
                    'desc' => 'TikTok-Follower im günstigen SMM-Panel. Sofortstart, Krypto, Live-Preise.',
                    'keywords' => 'tiktok follower kaufen, günstige tiktok follower, smm panel',
                    'intro' => 'Follower sind Social Proof. Bestellung je 1.000, kostenlose Registrierung, nur Krypto.',
                    'body' => [
                        ['h' => 'Follower plus Views', 'p' => 'Nur Follower wirken dünn. Mit Views/Likes auf neuen Videos kombinieren.'],
                        ['h' => 'Link', 'p' => 'Profil-URL (@user), keine Video-URL beim Follower-Dienst.'],
                        ['h' => 'Reseller', 'p' => 'Eigenes Geschäft per Child Panel oder API.'],
                    ],
                    'how' => [
                        ['name' => 'Registrieren', 'text' => 'Kostenloses Konto.'],
                        ['name' => 'Einzahlen', 'text' => 'USDT, BTC, ETH.'],
                        ['name' => 'Profil-Link', 'text' => 'Mit @username bestellen.'],
                    ],
                    'faqs' => [
                        ['name' => 'Preis?', 'text' => 'Live-Tabelle je 1.000.'],
                        ['name' => 'Sperre?', 'text' => 'Plötzliche Sprünge vermeiden, echte Inhalte nutzen.'],
                        ['name' => 'Zahlung?', 'text' => 'Nur Krypto.'],
                    ],
                ],
            ],
            'tiktok-likes' => [
                'tr' => [
                    'title' => 'TikTok beğeni satın al',
                    'h1' => 'TikTok beğeni satın al',
                    'desc' => 'TikTok video beğenisi, ucuz SMM panel, kripto ödeme, canlı fiyat listesi.',
                    'keywords' => 'tiktok beğeni satın al, tiktok likes, smm panel tiktok',
                    'intro' => 'Video beğenisi, For You dağılımında erken sosyal kanıttır. Panelde 1K fiyatı ve minimum adet canlıdır.',
                    'body' => [
                        ['h' => 'Video URL şart', 'p' => 'Profil değil, tek video linki. Yanlış URL teslimatı durdurur.'],
                        ['h' => 'Zamanlama', 'p' => 'Yayından hemen sonra küçük bir beğeni paketi, saatler sonra dev paketten daha doğal durur.'],
                        ['h' => 'Ajanslar', 'p' => 'Müşteri videoları için API ile otomatik sipariş açabilirsiniz.'],
                    ],
                    'how' => [
                        ['name' => 'Kayıt', 'text' => 'Ücretsiz.'],
                        ['name' => 'Bakiye', 'text' => 'Kripto.'],
                        ['name' => 'Video linki', 'text' => 'Beğeni servisi + video URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'Beğeniler düşer mi?', 'text' => 'Servise bağlı. Ticket ile kısmi iade kuralları geçerlidir.'],
                        ['name' => 'Canlı yayın beğenisi?', 'text' => 'Ayrı servis olabilir; adında Live geçen satırları seçin.'],
                        ['name' => 'Ödeme?', 'text' => 'Kripto.'],
                    ],
                ],
                'en' => [
                    'title' => 'Buy TikTok likes',
                    'h1' => 'Buy TikTok likes',
                    'desc' => 'TikTok video likes from a cheap SMM panel. Crypto checkout, live rates.',
                    'keywords' => 'buy tiktok likes, cheap tiktok likes, smm panel',
                    'intro' => 'Likes are early social proof on For You. Live rates per 1,000 and minimum quantity are in the table.',
                    'body' => [
                        ['h' => 'Video URL required', 'p' => 'Not a profile link. Wrong URLs stop delivery.'],
                        ['h' => 'Timing', 'p' => 'A small pack right after posting looks more natural than a huge dump hours later.'],
                        ['h' => 'Agencies', 'p' => 'Automate client video orders through the API.'],
                    ],
                    'how' => [
                        ['name' => 'Sign up', 'text' => 'Free.'],
                        ['name' => 'Funds', 'text' => 'Crypto.'],
                        ['name' => 'Video link', 'text' => 'Likes service + video URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'Do likes drop?', 'text' => 'Depends on the service. Tickets cover partial rules.'],
                        ['name' => 'Live likes?', 'text' => 'Separate services — pick rows that say Live.'],
                        ['name' => 'Pay?', 'text' => 'Crypto.'],
                    ],
                ],
                'de' => [
                    'title' => 'TikTok-Likes kaufen',
                    'h1' => 'TikTok-Likes kaufen',
                    'desc' => 'TikTok-Video-Likes, günstiges SMM-Panel, Krypto, Live-Preise.',
                    'keywords' => 'tiktok likes kaufen, smm panel tiktok',
                    'intro' => 'Likes sind früher Social Proof. Live-Preise je 1.000 in der Tabelle.',
                    'body' => [
                        ['h' => 'Video-URL', 'p' => 'Kein Profil-Link. Falsche URL stoppt die Lieferung.'],
                        ['h' => 'Timing', 'p' => 'Kleine Menge direkt nach dem Post wirkt natürlicher.'],
                        ['h' => 'Agenturen', 'p' => 'API für Kunden-Videos.'],
                    ],
                    'how' => [
                        ['name' => 'Registrieren', 'text' => 'Kostenlos.'],
                        ['name' => 'Guthaben', 'text' => 'Krypto.'],
                        ['name' => 'Video-Link', 'text' => 'Like-Service + URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'Fallen Likes ab?', 'text' => 'Je nach Service. Ticket bei Teillieferung.'],
                        ['name' => 'Live-Likes?', 'text' => 'Eigene Dienste mit „Live“ im Namen.'],
                        ['name' => 'Zahlung?', 'text' => 'Krypto.'],
                    ],
                ],
            ],
            'tiktok-views' => [
                'tr' => [
                    'title' => 'TikTok izlenme satın al',
                    'h1' => 'TikTok izlenme satın al',
                    'desc' => 'TikTok video izlenme, ucuz SMM, kripto ödeme, canlı 1K fiyatı.',
                    'keywords' => 'tiktok izlenme satın al, tiktok views, smm panel',
                    'intro' => 'İzlenme, TikTok’ta en ucuz büyüme sinyalidir. Bu sayfa view servislerini listeler; takipçi sayfasından ayrıdır.',
                    'body' => [
                        ['h' => 'Neden izlenme?', 'p' => 'Düşük maliyetle videoya hareket verir. Beğeni ve takipçi ile kombine edilirse profil daha dengeli görünür.'],
                        ['h' => 'URL', 'p' => 'Yalnızca video linki. Profil linki view servisinde çalışmaz.'],
                        ['h' => 'Hız', 'p' => 'Bazı servisler dakikada binler, bazıları 24 saate yayar. Adı okuyun.'],
                    ],
                    'how' => [
                        ['name' => 'Hesap', 'text' => 'Ücretsiz kayıt.'],
                        ['name' => 'Ödeme', 'text' => 'Kripto bakiye.'],
                        ['name' => 'Video', 'text' => 'İzlenme servisi + video URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'İzlenme kalıcı mı?', 'text' => 'Genelde evet; platform sayımı değişirse bilet açın.'],
                        ['name' => 'Takipçi de gelir mi?', 'text' => 'Hayır. Takipçi ayrı sipariştir.'],
                        ['name' => 'Minimum?', 'text' => 'Min sütununa bakın — izlenmede genelde yüksektir.'],
                    ],
                ],
                'en' => [
                    'title' => 'Buy TikTok views',
                    'h1' => 'Buy TikTok views',
                    'desc' => 'TikTok video views, cheap SMM panel, crypto, live rate per 1K.',
                    'keywords' => 'buy tiktok views, cheap tiktok views, smm panel',
                    'intro' => 'Views are the cheapest TikTok growth signal. Separate from the followers page. Live rates below.',
                    'body' => [
                        ['h' => 'Why views', 'p' => 'Low-cost motion on a video. Combine with likes and followers for a balanced profile.'],
                        ['h' => 'URL', 'p' => 'Video link only. Profile URLs fail on view services.'],
                        ['h' => 'Speed', 'p' => 'Some services drip over 24h. Read the name.'],
                    ],
                    'how' => [
                        ['name' => 'Account', 'text' => 'Free signup.'],
                        ['name' => 'Pay', 'text' => 'Crypto balance.'],
                        ['name' => 'Video', 'text' => 'Views service + video URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'Do views stay?', 'text' => 'Usually. Open a ticket if the count reverses.'],
                        ['name' => 'Followers included?', 'text' => 'No. Order followers separately.'],
                        ['name' => 'Minimum?', 'text' => 'See Min — often higher on views.'],
                    ],
                ],
                'de' => [
                    'title' => 'TikTok-Views kaufen',
                    'h1' => 'TikTok-Views kaufen',
                    'desc' => 'TikTok-Video-Views, günstiges SMM-Panel, Krypto, Live-Preis je 1.000.',
                    'keywords' => 'tiktok views kaufen, smm panel',
                    'intro' => 'Views sind das günstigste TikTok-Signal. Getrennt von der Follower-Seite.',
                    'body' => [
                        ['h' => 'Warum Views?', 'p' => 'Günstige Bewegung am Video. Mit Likes und Followern kombinieren.'],
                        ['h' => 'URL', 'p' => 'Nur Video-Link.'],
                        ['h' => 'Tempo', 'p' => 'Manche Dienste über 24h. Namen lesen.'],
                    ],
                    'how' => [
                        ['name' => 'Konto', 'text' => 'Kostenlos.'],
                        ['name' => 'Zahlung', 'text' => 'Krypto.'],
                        ['name' => 'Video', 'text' => 'View-Service + URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'Bleiben Views?', 'text' => 'Meist ja. Ticket bei Rückgang.'],
                        ['name' => 'Follower inklusive?', 'text' => 'Nein.'],
                        ['name' => 'Minimum?', 'text' => 'Spalte Min.'],
                    ],
                ],
            ],
            'youtube-views' => [
                'tr' => [
                    'title' => 'YouTube izlenme satın al — ucuz SMM',
                    'h1' => 'YouTube izlenme satın al',
                    'desc' => 'YouTube video izlenme, ucuz SMM panel, kripto ödeme, canlı fiyat. Türkiye ve dünya.',
                    'keywords' => 'youtube izlenme satın al, ucuz youtube views, smm panel youtube',
                    'intro' => 'YouTube izlenme, videonun önerilerde durmasını destekleyebilir. SMM Turk’te view servisleri 1K fiyatıyla listelenir; ödeme kripto, kayıt ücretsiz.',
                    'body' => [
                        ['h' => 'İzlenme süresi', 'p' => 'Bazı servisler gerçek izlenme süresi hedefler, bazıları hızlı sayaçtır. Servis adındaki retention / hour ifadesini okuyun. Monetizasyon politikaları YouTube’a aittir; panel reklam geliri garanti etmez.'],
                        ['h' => 'Doğru URL', 'p' => 'youtube.com/watch veya youtu.be linki. Shorts ayrı satır olabilir.'],
                        ['h' => 'Abone ile fark', 'p' => 'İzlenme ve abone ayrı sipariştir. Kanal büyümesi için ikisini kademeli kullanın.'],
                    ],
                    'how' => [
                        ['name' => 'Kayıt', 'text' => 'Ücretsiz hesap.'],
                        ['name' => 'Bakiye', 'text' => 'USDT/BTC/ETH.'],
                        ['name' => 'Video linki', 'text' => 'View servisi seçin.'],
                    ],
                    'faqs' => [
                        ['name' => 'İzlenme analitiğe yazar mı?', 'text' => 'YouTube sayımına eklenir; kaynak kırılımı panelde görünmez.'],
                        ['name' => 'Telif / telifli müzik?', 'text' => 'İçerik sizin sorumluluğunuzdadır. Telif ihlali videolara sipariş etmeyin.'],
                        ['name' => 'Ödeme?', 'text' => 'Kripto.'],
                    ],
                ],
                'en' => [
                    'title' => 'Buy YouTube views — cheap SMM panel',
                    'h1' => 'Buy YouTube views',
                    'desc' => 'YouTube video views from a cheap SMM panel. Crypto checkout, live rates, Turkey and worldwide.',
                    'keywords' => 'buy youtube views, cheap youtube views, smm panel youtube',
                    'intro' => 'Views can support a video’s chance in suggested. Listed per 1,000. Crypto only, free signup.',
                    'body' => [
                        ['h' => 'Watch time', 'p' => 'Some services target retention; others are fast counters. Read the service name. The panel does not guarantee ad revenue — that is YouTube policy.'],
                        ['h' => 'URL', 'p' => 'watch or youtu.be links. Shorts may be a separate row.'],
                        ['h' => 'Subscribers', 'p' => 'Views and subs are different orders. Use both in batches for channel growth.'],
                    ],
                    'how' => [
                        ['name' => 'Sign up', 'text' => 'Free.'],
                        ['name' => 'Funds', 'text' => 'USDT/BTC/ETH.'],
                        ['name' => 'Video link', 'text' => 'Pick a views service.'],
                    ],
                    'faqs' => [
                        ['name' => 'Do views show in Analytics?', 'text' => 'They add to the public count. Source breakdown is not in the panel.'],
                        ['name' => 'Copyrighted music?', 'text' => 'Your content, your risk. Do not order on infringing videos.'],
                        ['name' => 'Pay?', 'text' => 'Crypto.'],
                    ],
                ],
                'de' => [
                    'title' => 'YouTube-Views kaufen',
                    'h1' => 'YouTube-Views kaufen',
                    'desc' => 'YouTube-Video-Views, günstiges SMM-Panel, Krypto, Live-Preise.',
                    'keywords' => 'youtube views kaufen, günstige youtube views, smm panel',
                    'intro' => 'Views können Suggested unterstützen. Preise je 1.000, nur Krypto.',
                    'body' => [
                        ['h' => 'Watchtime', 'p' => 'Manche Dienste zielen auf Retention. Werbeeinnahmen werden nicht garantiert.'],
                        ['h' => 'URL', 'p' => 'watch oder youtu.be. Shorts ggf. extra.'],
                        ['h' => 'Abos', 'p' => 'Views und Abos sind getrennte Bestellungen.'],
                    ],
                    'how' => [
                        ['name' => 'Registrieren', 'text' => 'Kostenlos.'],
                        ['name' => 'Guthaben', 'text' => 'USDT/BTC/ETH.'],
                        ['name' => 'Video-Link', 'text' => 'View-Service wählen.'],
                    ],
                    'faqs' => [
                        ['name' => 'Analytics?', 'text' => 'Zähler steigt; Quelle nicht im Panel.'],
                        ['name' => 'Urheberrecht?', 'text' => 'Ihre Inhalte, Ihr Risiko.'],
                        ['name' => 'Zahlung?', 'text' => 'Krypto.'],
                    ],
                ],
            ],
            'youtube-subscribers' => [
                'tr' => [
                    'title' => 'YouTube abone satın al',
                    'h1' => 'YouTube abone satın al',
                    'desc' => 'YouTube abone, ucuz SMM panel, kripto ödeme, canlı fiyat.',
                    'keywords' => 'youtube abone satın al, buy youtube subscribers, smm panel',
                    'intro' => 'Abone sayısı kanalın ilk izlenimidir. Panelden 1K fiyatıyla sipariş; izlenme ayrı üründür.',
                    'body' => [
                        ['h' => 'Abone vs izlenme', 'p' => 'Sadece abone almak, boş kanal gibi durabilir. Yeni videolara izlenme ekleyin.'],
                        ['h' => 'Link', 'p' => 'Kanal URL’si (youtube.com/@handle veya /channel/ID). Video linki abone servisinde yanlış olur.'],
                        ['h' => 'Politika', 'p' => 'YouTube sahte etkileşimi yasaklayabilir. Kademeli gidin, gerçek içerik üretin. Panel YouTube kurallarını değiştiremez.'],
                    ],
                    'how' => [
                        ['name' => 'Kayıt', 'text' => 'Ücretsiz.'],
                        ['name' => 'Yatırım', 'text' => 'Kripto.'],
                        ['name' => 'Kanal linki', 'text' => 'Abone servisi seçin.'],
                    ],
                    'faqs' => [
                        ['name' => 'Aboneler düşer mi?', 'text' => 'Servise göre. Refill varsa adında yazar.'],
                        ['name' => '1000 abone rozeti?', 'text' => 'Rozet ve para çekme YouTube şartlarına bağlıdır; panel garanti vermez.'],
                        ['name' => 'Ödeme?', 'text' => 'Kripto.'],
                    ],
                ],
                'en' => [
                    'title' => 'Buy YouTube subscribers',
                    'h1' => 'Buy YouTube subscribers',
                    'desc' => 'YouTube subscribers from a cheap SMM panel. Crypto checkout, live rates.',
                    'keywords' => 'buy youtube subscribers, cheap youtube subs, smm panel',
                    'intro' => 'Subscriber count is first impression. Order per 1K. Views are a separate product.',
                    'body' => [
                        ['h' => 'Subs vs views', 'p' => 'Subs alone can look empty. Add views on new videos.'],
                        ['h' => 'Link', 'p' => 'Channel URL (@handle or /channel/ID), not a video URL.'],
                        ['h' => 'Policy', 'p' => 'YouTube may penalize fake engagement. Use batches and real content. The panel cannot override YouTube rules.'],
                    ],
                    'how' => [
                        ['name' => 'Sign up', 'text' => 'Free.'],
                        ['name' => 'Deposit', 'text' => 'Crypto.'],
                        ['name' => 'Channel link', 'text' => 'Pick a subscribers service.'],
                    ],
                    'faqs' => [
                        ['name' => 'Do subs drop?', 'text' => 'Depends. Refill is noted in the service name.'],
                        ['name' => 'Silver play button?', 'text' => 'Badges and payouts are YouTube’s rules. Not guaranteed here.'],
                        ['name' => 'Pay?', 'text' => 'Crypto.'],
                    ],
                ],
                'de' => [
                    'title' => 'YouTube-Abonnenten kaufen',
                    'h1' => 'YouTube-Abonnenten kaufen',
                    'desc' => 'YouTube-Abos, günstiges SMM-Panel, Krypto, Live-Preise.',
                    'keywords' => 'youtube abonnenten kaufen, smm panel youtube',
                    'intro' => 'Abo-Zahl ist der erste Eindruck. Bestellung je 1.000. Views separat.',
                    'body' => [
                        ['h' => 'Abos vs Views', 'p' => 'Nur Abos wirken leer. Views auf neue Videos legen.'],
                        ['h' => 'Link', 'p' => 'Kanal-URL, keine Video-URL.'],
                        ['h' => 'Richtlinien', 'p' => 'YouTube kann Fake-Engagement ahnden. Chargen + echte Inhalte.'],
                    ],
                    'how' => [
                        ['name' => 'Registrieren', 'text' => 'Kostenlos.'],
                        ['name' => 'Einzahlen', 'text' => 'Krypto.'],
                        ['name' => 'Kanal-Link', 'text' => 'Abo-Service wählen.'],
                    ],
                    'faqs' => [
                        ['name' => 'Fallen Abos ab?', 'text' => 'Je nach Service.'],
                        ['name' => 'Play Button?', 'text' => 'YouTube-Regeln, keine Garantie.'],
                        ['name' => 'Zahlung?', 'text' => 'Krypto.'],
                    ],
                ],
            ],
            'twitter-followers' => [
                'tr' => [
                    'title' => 'X (Twitter) takipçi satın al',
                    'h1' => 'X / Twitter takipçi satın al',
                    'desc' => 'X (Twitter) takipçi, ucuz SMM panel, kripto ödeme, canlı fiyat.',
                    'keywords' => 'twitter takipçi satın al, x follower, smm panel twitter',
                    'intro' => 'X’te takipçi sayısı profilin ilk filtresidir. Panelden 1K fiyatıyla sipariş; kayıt ücretsiz, ödeme kripto.',
                    'body' => [
                        ['h' => 'Link', 'p' => 'x.com/kullanici veya twitter.com/kullanici. Tweet linki takipçi servisinde yanlış olur.'],
                        ['h' => 'Kalite', 'p' => 'Ucuz satırlar daha fazla düşüş gösterebilir. Servis adındaki country / real ifadelerini karşılaştırın.'],
                        ['h' => 'Gelir', 'p' => 'Kendi müşterilerinize child panel ile aynı katalogu satabilirsiniz.'],
                    ],
                    'how' => [
                        ['name' => 'Kayıt', 'text' => 'Ücretsiz.'],
                        ['name' => 'Bakiye', 'text' => 'Kripto.'],
                        ['name' => 'Profil', 'text' => 'Takipçi servisi + profil URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'Mavi tik gelir mi?', 'text' => 'Hayır. Doğrulama X’in kendi ürünüdür.'],
                        ['name' => 'Tweet beğenisi?', 'text' => 'Ayrı servis; bu sayfa takipçi içindir.'],
                        ['name' => 'Ödeme?', 'text' => 'Kripto.'],
                    ],
                ],
                'en' => [
                    'title' => 'Buy X (Twitter) followers',
                    'h1' => 'Buy X / Twitter followers',
                    'desc' => 'X (Twitter) followers from a cheap SMM panel. Crypto checkout, live rates.',
                    'keywords' => 'buy twitter followers, buy x followers, smm panel twitter',
                    'intro' => 'Follower count is the first filter on X. Order per 1K. Free signup, crypto only.',
                    'body' => [
                        ['h' => 'Link', 'p' => 'x.com/user or twitter.com/user. Tweet URLs fail on follower services.'],
                        ['h' => 'Quality', 'p' => 'Cheaper rows may drop more. Compare country / real notes in the name.'],
                        ['h' => 'Income', 'p' => 'Resell the catalog on a child panel.'],
                    ],
                    'how' => [
                        ['name' => 'Sign up', 'text' => 'Free.'],
                        ['name' => 'Funds', 'text' => 'Crypto.'],
                        ['name' => 'Profile', 'text' => 'Followers service + profile URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'Blue check?', 'text' => 'No. Verification is X’s product.'],
                        ['name' => 'Tweet likes?', 'text' => 'Separate service.'],
                        ['name' => 'Pay?', 'text' => 'Crypto.'],
                    ],
                ],
                'de' => [
                    'title' => 'X (Twitter) Follower kaufen',
                    'h1' => 'X / Twitter-Follower kaufen',
                    'desc' => 'X-Follower, günstiges SMM-Panel, Krypto, Live-Preise.',
                    'keywords' => 'twitter follower kaufen, x follower, smm panel',
                    'intro' => 'Followerzahl ist der erste Filter. Bestellung je 1.000, nur Krypto.',
                    'body' => [
                        ['h' => 'Link', 'p' => 'x.com/user. Tweet-URL ist falsch für Follower-Dienste.'],
                        ['h' => 'Qualität', 'p' => 'Günstigere Zeilen können stärker abfallen.'],
                        ['h' => 'Einkommen', 'p' => 'Katalog per Child Panel weiterverkaufen.'],
                    ],
                    'how' => [
                        ['name' => 'Registrieren', 'text' => 'Kostenlos.'],
                        ['name' => 'Guthaben', 'text' => 'Krypto.'],
                        ['name' => 'Profil', 'text' => 'Follower-Service + URL.'],
                    ],
                    'faqs' => [
                        ['name' => 'Häkchen?', 'text' => 'Nein. Das ist X-Produkt.'],
                        ['name' => 'Tweet-Likes?', 'text' => 'Separater Dienst.'],
                        ['name' => 'Zahlung?', 'text' => 'Krypto.'],
                    ],
                ],
            ],
            'telegram-members' => [
                'tr' => [
                    'title' => 'Telegram üye satın al',
                    'h1' => 'Telegram kanal / grup üye satın al',
                    'desc' => 'Telegram üye, ucuz SMM panel, kripto ödeme, canlı fiyat.',
                    'keywords' => 'telegram üye satın al, telegram members, smm panel telegram',
                    'intro' => 'Kanal veya grup üye sayısı güven sinyali verir. Panelden üye servisleri 1K fiyatıyla; kayıt ücretsiz.',
                    'body' => [
                        ['h' => 'Kamu linki', 'p' => 't.me/kanal kullanıcı adı gerekir. Özel davet linkleri çoğu serviste çalışmaz. Kanal herkese açık olsun.'],
                        ['h' => 'Üye vs izlenme', 'p' => 'Post görüntülenme ayrı satırdır. Üye siparişi kanal listesine ekler.'],
                        ['h' => 'Düşüş', 'p' => 'Ucuz üyeler çıkabilir. Refill notunu okuyun.'],
                    ],
                    'how' => [
                        ['name' => 'Kayıt', 'text' => 'Ücretsiz.'],
                        ['name' => 'Bakiye', 'text' => 'Kripto.'],
                        ['name' => 't.me linki', 'text' => 'Üye servisi seçin.'],
                    ],
                    'faqs' => [
                        ['name' => 'Bot mu gerçek mi?', 'text' => 'Servis adına bakın (real / bot / mixed). Fiyat farkı buradan gelir.'],
                        ['name' => 'Yorum da gelir mi?', 'text' => 'Hayır. Yorum ayrı servistir.'],
                        ['name' => 'Ödeme?', 'text' => 'Kripto.'],
                    ],
                ],
                'en' => [
                    'title' => 'Buy Telegram members',
                    'h1' => 'Buy Telegram channel / group members',
                    'desc' => 'Telegram members from a cheap SMM panel. Crypto checkout, live rates.',
                    'keywords' => 'buy telegram members, telegram channel members, smm panel',
                    'intro' => 'Member count is a trust signal. Order per 1K. Free signup.',
                    'body' => [
                        ['h' => 'Public link', 'p' => 'Need t.me/username. Private invite links often fail. Keep the channel public.'],
                        ['h' => 'Members vs views', 'p' => 'Post views are a different row. Member orders add to the member list.'],
                        ['h' => 'Drops', 'p' => 'Cheap members may leave. Read refill notes.'],
                    ],
                    'how' => [
                        ['name' => 'Sign up', 'text' => 'Free.'],
                        ['name' => 'Funds', 'text' => 'Crypto.'],
                        ['name' => 't.me link', 'text' => 'Pick a members service.'],
                    ],
                    'faqs' => [
                        ['name' => 'Bots or real?', 'text' => 'Read the service name (real / bot / mixed). That drives price.'],
                        ['name' => 'Comments too?', 'text' => 'No. Separate service.'],
                        ['name' => 'Pay?', 'text' => 'Crypto.'],
                    ],
                ],
                'de' => [
                    'title' => 'Telegram-Mitglieder kaufen',
                    'h1' => 'Telegram-Kanal/Gruppen-Mitglieder kaufen',
                    'desc' => 'Telegram-Mitglieder, günstiges SMM-Panel, Krypto, Live-Preise.',
                    'keywords' => 'telegram mitglieder kaufen, smm panel telegram',
                    'intro' => 'Mitgliederzahl ist ein Vertrauenssignal. Bestellung je 1.000.',
                    'body' => [
                        ['h' => 'Öffentlicher Link', 'p' => 't.me/username. Private Invites scheitern oft.'],
                        ['h' => 'Mitglieder vs Views', 'p' => 'Post-Views sind ein anderer Dienst.'],
                        ['h' => 'Abfall', 'p' => 'Günstige Mitglieder können gehen. Refill lesen.'],
                    ],
                    'how' => [
                        ['name' => 'Registrieren', 'text' => 'Kostenlos.'],
                        ['name' => 'Guthaben', 'text' => 'Krypto.'],
                        ['name' => 't.me-Link', 'text' => 'Mitglieder-Service wählen.'],
                    ],
                    'faqs' => [
                        ['name' => 'Bots oder echt?', 'text' => 'Steht im Dienstnamen.'],
                        ['name' => 'Kommentare?', 'text' => 'Nein.'],
                        ['name' => 'Zahlung?', 'text' => 'Krypto.'],
                    ],
                ],
            ],
            'facebook-likes' => [
                'tr' => [
                    'title' => 'Facebook beğeni / sayfa beğeni satın al',
                    'h1' => 'Facebook beğeni satın al',
                    'desc' => 'Facebook gönderi ve sayfa beğenisi, ucuz SMM panel, kripto, canlı fiyat.',
                    'keywords' => 'facebook beğeni satın al, facebook page likes, smm panel facebook',
                    'intro' => 'Sayfa veya gönderi beğenisi sosyal kanıttır. Servis adında Page vs Post ayrımını okuyun; link tipi farklıdır.',
                    'body' => [
                        ['h' => 'Sayfa vs gönderi', 'p' => 'Page like için sayfa URL’si, post like için gönderi URL’si. Karıştırmayın.'],
                        ['h' => 'Bölge', 'p' => 'Bazı satırlar ülke hedeflidir ve daha pahalıdır. Global satırlar ucuzdur, kalite değişir.'],
                        ['h' => 'Reels', 'p' => 'Facebook Reels izlenme ayrı servis olabilir.'],
                    ],
                    'how' => [
                        ['name' => 'Kayıt', 'text' => 'Ücretsiz.'],
                        ['name' => 'Bakiye', 'text' => 'Kripto.'],
                        ['name' => 'Doğru URL', 'text' => 'Sayfa veya gönderi.'],
                    ],
                    'faqs' => [
                        ['name' => 'Profil beğenisi?', 'text' => 'Kişisel profil değil, genellikle Sayfa veya gönderi. Servis adını okuyun.'],
                        ['name' => 'Yorum?', 'text' => 'Ayrı servis.'],
                        ['name' => 'Ödeme?', 'text' => 'Kripto.'],
                    ],
                ],
                'en' => [
                    'title' => 'Buy Facebook likes / page likes',
                    'h1' => 'Buy Facebook likes',
                    'desc' => 'Facebook post and page likes. Cheap SMM panel, crypto, live rates.',
                    'keywords' => 'buy facebook likes, facebook page likes, smm panel facebook',
                    'intro' => 'Page or post likes are social proof. Read Page vs Post in the service name — the URL type differs.',
                    'body' => [
                        ['h' => 'Page vs post', 'p' => 'Page likes need a page URL; post likes need a post URL. Do not mix them.'],
                        ['h' => 'Geo', 'p' => 'Country-targeted rows cost more. Global rows are cheaper with mixed quality.'],
                        ['h' => 'Reels', 'p' => 'Facebook Reels views may be a separate service.'],
                    ],
                    'how' => [
                        ['name' => 'Sign up', 'text' => 'Free.'],
                        ['name' => 'Funds', 'text' => 'Crypto.'],
                        ['name' => 'Correct URL', 'text' => 'Page or post.'],
                    ],
                    'faqs' => [
                        ['name' => 'Profile likes?', 'text' => 'Usually Pages or posts, not personal profiles. Read the name.'],
                        ['name' => 'Comments?', 'text' => 'Separate service.'],
                        ['name' => 'Pay?', 'text' => 'Crypto.'],
                    ],
                ],
                'de' => [
                    'title' => 'Facebook-Likes / Seiten-Likes kaufen',
                    'h1' => 'Facebook-Likes kaufen',
                    'desc' => 'Facebook-Beitrags- und Seiten-Likes. Günstiges SMM-Panel, Krypto, Live-Preise.',
                    'keywords' => 'facebook likes kaufen, facebook seiten likes, smm panel',
                    'intro' => 'Seiten- oder Beitragslikes. Page vs Post im Dienstnamen lesen — der URL-Typ ist anders.',
                    'body' => [
                        ['h' => 'Seite vs Beitrag', 'p' => 'Seiten-Likes brauchen Seiten-URL, Beitragslikes Beitrags-URL.'],
                        ['h' => 'Geo', 'p' => 'Länderzeilen teurer. Global günstiger, Qualität gemischt.'],
                        ['h' => 'Reels', 'p' => 'Reels-Views ggf. extra.'],
                    ],
                    'how' => [
                        ['name' => 'Registrieren', 'text' => 'Kostenlos.'],
                        ['name' => 'Guthaben', 'text' => 'Krypto.'],
                        ['name' => 'Passende URL', 'text' => 'Seite oder Beitrag.'],
                    ],
                    'faqs' => [
                        ['name' => 'Profil-Likes?', 'text' => 'Meist Seite oder Beitrag. Namen lesen.'],
                        ['name' => 'Kommentare?', 'text' => 'Separater Dienst.'],
                        ['name' => 'Zahlung?', 'text' => 'Krypto.'],
                    ],
                ],
            ],
            'cheap-smm-panel' => [
                'tr' => [
                    'title' => 'SMM Panel Türkiye — en ucuz SMM paneli',
                    'h1' => 'SMM Panel Türkiye: Instagram, TikTok, YouTube',
                    'desc' => 'SMM Panel Türkiye. Instagram takipçi, TikTok beğeni, YouTube izlenme. Kripto ödeme, bayi API, child panel. Türkiye ve dünya.',
                    'keywords' => 'en ucuz smm panel, smm panel türkiye, cheap smm panel, reseller panel',
                    'intro' => 'SMM panel, sosyal medya servislerini toptan satan bir yazılımdır. SMM Turk; kayıt ücretsiz, ödeme kripto, fiyatlar 1.000 adet başına. Google’dan “ucuz SMM panel” arayanlar için bu sayfa hem fiyat hem de para kazanma yollarını bağlar.',
                    'body' => [
                        ['h' => 'Müşteri olarak kullanın', 'p' => 'Kendi hesaplarınız için sipariş verin. Hoş geldin bakiyesi ve ilk yatırıma bonus, ilk testi ucuzlatır. Canlı fiyatlar platform sayfalarında da vardır.'],
                        ['h' => 'Bayi olarak kazanın', 'p' => 'Child panel: kendi alan adınız, kendi müşteriniz, marj sizde. Affiliate: davet linki. API: kendi yazılımınıza toptan bağlanır. Earn sayfasında üç yolun karşılaştırması var.'],
                        ['h' => 'Neden SMM Turk?', 'p' => 'Türkiye odaklı marka, TR/EN/DE arayüz, kripto cüzdanlar, otomatik teslimat, bilet desteği. Kart vaadi yok — ödeme yöntemi net.'],
                    ],
                    'how' => [
                        ['name' => 'Kayıt', 'text' => '30 saniye, Google ile giriş opsiyonel.'],
                        ['name' => 'İlk bakiye', 'text' => 'Kripto veya hoş geldin kredisi.'],
                        ['name' => 'Sipariş veya bayi', 'text' => 'Hizmet alın veya Earn’den gelir kurun.'],
                    ],
                    'faqs' => [
                        ['name' => 'SMM panel nedir?', 'text' => 'Takipçi, beğeni, izlenme gibi servisleri toptan satan bir panel. Siz bakiyeden sipariş açarsınız; sağlayıcı teslim eder.'],
                        ['name' => 'En ucuz satır mı en iyisi?', 'text' => 'Hayır. Hız, refill ve kalite servise göre değişir. Önce ucuz test, sonra tekrar sipariş.'],
                        ['name' => 'Nasıl para kazanırım?', 'text' => 'Child panel, affiliate ve API. /earn sayfasına bakın.'],
                    ],
                ],
                'en' => [
                    'title' => 'SMM Panel Turkey — cheapest SMM panel worldwide',
                    'h1' => 'SMM Panel Turkey: cheapest Instagram, TikTok, YouTube',
                    'desc' => 'SMM panel Turkey. Cheap Instagram followers, TikTok likes, YouTube views. Crypto deposits, reseller API, child panels. Turkey and worldwide.',
                    'keywords' => 'cheapest smm panel, cheap smm panel, smm panel turkey, reseller panel',
                    'intro' => 'An SMM panel resells social services wholesale. SMM Turk: free signup, crypto checkout, rates per 1,000. This page is for people who searched Google for a cheap panel — and for people who want to earn from it.',
                    'body' => [
                        ['h' => 'Use it as a customer', 'p' => 'Order for your own accounts. Welcome credit and first-deposit bonus make the first test cheap. Platform pages list live rates.'],
                        ['h' => 'Earn as a reseller', 'p' => 'Child panel: your domain, your customers, your markup. Affiliate: referral link. API: wholesale into your own software. Compare all three on the Earn page.'],
                        ['h' => 'Why SMM Turk?', 'p' => 'Turkey-first brand, TR/EN/DE UI, crypto wallets, automated delivery, tickets. No fake card logos — payment method is honest.'],
                    ],
                    'how' => [
                        ['name' => 'Sign up', 'text' => '30 seconds, optional Google Sign-In.'],
                        ['name' => 'First balance', 'text' => 'Crypto or welcome credit.'],
                        ['name' => 'Order or resell', 'text' => 'Buy services or open Earn.'],
                    ],
                    'faqs' => [
                        ['name' => 'What is an SMM panel?', 'text' => 'A dashboard that sells followers, likes, and views wholesale. You order from balance; a provider delivers.'],
                        ['name' => 'SMM panel Turkey?', 'text' => 'Yes. SMM Turk is an SMM panel used from Turkey and worldwide, with crypto checkout and TR/EN/DE UI.'],
                        ['name' => 'Is cheapest best?', 'text' => 'No. Speed, refill, and quality vary. Test cheap, then reorder.'],
                        ['name' => 'How do I make money?', 'text' => 'Child panel, affiliate, and API. See /earn.'],
                    ],
                ],
                'de' => [
                    'title' => 'SMM Panel Türkei — günstigstes SMM-Panel weltweit',
                    'h1' => 'SMM Panel Türkei: Instagram, TikTok, YouTube',
                    'desc' => 'SMM Panel Türkei. Günstige Instagram-Follower, TikTok-Likes, YouTube-Views. Krypto, Reseller-API, Child Panels.',
                    'keywords' => 'smm panel türkei, günstigstes smm panel, cheap smm panel, reseller panel',
                    'intro' => 'Ein SMM-Panel verkauft Social-Dienste im Großhandel. Kostenlose Registrierung, nur Krypto, Preise je 1.000.',
                    'body' => [
                        ['h' => 'Als Kunde', 'p' => 'Für eigene Konten bestellen. Willkommensguthaben und Einzahlungsbonus senken den Test.'],
                        ['h' => 'Als Reseller verdienen', 'p' => 'Child Panel, Affiliate, API. Vergleich auf der Earn-Seite.'],
                        ['h' => 'Warum SMM Turk?', 'p' => 'Marke mit Türkei-Fokus, TR/EN/DE, ehrliche Krypto-Zahlung.'],
                    ],
                    'how' => [
                        ['name' => 'Registrieren', 'text' => '30 Sekunden.'],
                        ['name' => 'Guthaben', 'text' => 'Krypto oder Bonus.'],
                        ['name' => 'Bestellen oder verdienen', 'text' => 'Dienste kaufen oder Earn öffnen.'],
                    ],
                    'faqs' => [
                        ['name' => 'Was ist ein SMM-Panel?', 'text' => 'Dashboard für Follower, Likes, Views im Großhandel.'],
                        ['name' => 'Ist günstig am besten?', 'text' => 'Nein. Erst testen.'],
                        ['name' => 'Geld verdienen?', 'text' => 'Child Panel, Affiliate, API — siehe /earn.'],
                    ],
                ],
            ],
        ];
    }
}
