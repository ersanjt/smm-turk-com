<?php
/**
 * SMM Turk - Language detection (IP-based + cookie override) and translations
 * Primary: Turkish (Istanbul). Secondary: English, German.
 *
 * Public pages: URL ?lang= drives language (no redirect). Clean URLs = Turkish.
 * Panel: cookie/session preference.
 */
class Lang {
    private static ?string $current = null;
    private static array $strings = [];
    /** @var string Primary site language (canonical URLs, no ?lang=). */
    public const PRIMARY = 'tr';
    /** @var string Secondary site language (?lang=en). */
    public const SECONDARY = 'en';
    public const TERTIARY = 'de';
    private static array $allowed = [self::PRIMARY, self::SECONDARY, self::TERTIARY];

    /** @var string[] Scripts served as public marketing (URL-based language). */
    private static array $publicScripts = [
        'home', 'help', 'terms', 'blog', 'blog-post', '404', 'index', 'pricing', 'earn',
    ];

    public static function init(): string {
        return self::boot('auto');
    }

    /** Public site: ?lang=en|de or default Turkish on clean URLs. */
    public static function initPublic(): string {
        return self::boot('public');
    }

    /** Panel: cookie, session, IP, browser preference. */
    public static function initUser(): string {
        return self::boot('user');
    }

    private static function boot(string $mode): string {
        if (self::$current !== null) {
            return self::$current;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!empty($_GET['lang']) && in_array($_GET['lang'], self::$allowed, true)) {
            $lang = $_GET['lang'];
            $_SESSION['lang'] = $lang;
            setcookie('lang', $lang, time() + 31536000, '/', '', true, true);
            self::$current = $lang;
            self::load($lang);
            return $lang;
        }

        $effectiveMode = $mode === 'auto'
            ? (self::isPanelRequest() ? 'user' : 'public')
            : $mode;

        if ($effectiveMode === 'public') {
            $lang = self::PRIMARY;
        } else {
            $lang = null;
            if (!empty($_COOKIE['lang'])) {
                $lang = self::normalize($_COOKIE['lang']);
            }
            if ($lang === null && !empty($_SESSION['lang'])) {
                $lang = self::normalize($_SESSION['lang']);
            }
            if ($lang === null) {
                $lang = self::detectFromIp();
            }
            if ($lang === null) {
                $lang = self::detectFromBrowser();
            }
            $lang = $lang ?: self::PRIMARY;
            $_SESSION['lang'] = $lang;
        }

        self::$current = $lang;
        self::load($lang);
        return $lang;
    }

    private static function isPanelRequest(): bool {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
        return !in_array($script, self::$publicScripts, true);
    }

    /** Map legacy or invalid codes to supported languages. */
    private static function normalize(?string $code): ?string {
        if ($code === null || $code === '') {
            return null;
        }
        if (in_array($code, self::$allowed, true)) {
            return $code;
        }
        if ($code === 'fr') {
            return self::SECONDARY;
        }
        return null;
    }

    private static function detectFromIp(): ?string {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = trim(explode(',', $ip)[0]);
        if ($ip === '' || $ip === '127.0.0.1' || strpos($ip, '192.168.') === 0) {
            return null;
        }
        if (!isset($_SESSION['ip_country'])) {
            $raw = @file_get_contents('http://ip-api.com/json/' . urlencode($ip) . '?fields=countryCode', false, stream_context_create(['http' => ['timeout' => 2]]));
            $data = $raw ? json_decode($raw, true) : null;
            $_SESSION['ip_country'] = $data['countryCode'] ?? '';
        }
        $code = strtoupper($_SESSION['ip_country'] ?? '');
        $map = [
            'TR' => self::PRIMARY,
            'CY' => self::PRIMARY,
            'DE' => self::TERTIARY, 'AT' => self::TERTIARY, 'CH' => self::TERTIARY, 'LI' => self::TERTIARY,
            'GB' => self::SECONDARY, 'US' => self::SECONDARY, 'CA' => self::SECONDARY,
            'AU' => self::SECONDARY, 'NZ' => self::SECONDARY, 'IE' => self::SECONDARY,
            'FR' => self::SECONDARY, 'BE' => self::SECONDARY, 'NL' => self::SECONDARY,
            'IT' => self::SECONDARY, 'ES' => self::SECONDARY, 'PL' => self::SECONDARY,
            'SE' => self::SECONDARY, 'NO' => self::SECONDARY, 'DK' => self::SECONDARY,
            'FI' => self::SECONDARY, 'PT' => self::SECONDARY, 'GR' => self::SECONDARY,
            'AE' => self::SECONDARY, 'SA' => self::SECONDARY, 'IN' => self::SECONDARY,
            'PK' => self::SECONDARY, 'ID' => self::SECONDARY, 'MY' => self::SECONDARY,
            'SG' => self::SECONDARY, 'PH' => self::SECONDARY, 'NG' => self::SECONDARY,
            'ZA' => self::SECONDARY, 'EG' => self::SECONDARY,
        ];
        return $map[$code] ?? null;
    }

    private static function detectFromBrowser(): ?string {
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        foreach (explode(',', $accept) as $part) {
            $tag = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
            if (in_array($tag, self::$allowed, true)) {
                return $tag;
            }
        }
        return null;
    }

    private static function load(string $code): void {
        $file = dirname(__DIR__) . '/lang/' . $code . '.php';
        self::$strings = is_file($file) ? (require $file) : (require dirname(__DIR__) . '/lang/' . self::PRIMARY . '.php');
    }

    public static function get(string $key): string {
        return self::$strings[$key] ?? $key;
    }

    public static function current(): string {
        return self::$current ?? self::init();
    }

    public static function allowed(): array {
        return self::$allowed;
    }

    public static function primary(): string {
        return self::PRIMARY;
    }

    public static function isPrimary(string $lang): bool {
        return $lang === self::PRIMARY;
    }

    public static function label(string $lang): string {
        return match ($lang) {
            'tr' => 'Türkçe',
            'en' => 'English',
            'de' => 'Deutsch',
            default => strtoupper($lang),
        };
    }

    /**
     * Build a language-switch URL. Primary (Turkish) has no ?lang= query.
     *
     * @param array<string, scalar|null> $query
     */
    public static function urlFor(string $lang, string $path = '', array $query = []): string {
        if ($path === '') {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        }
        if ($query === [] && !empty($_GET)) {
            $query = $_GET;
        }
        unset($query['lang']);
        if (!self::isPrimary($lang)) {
            $query['lang'] = $lang;
        }
        $qs = $query !== [] ? '?' . http_build_query($query) : '';
        return $path . $qs;
    }
}

function __(string $key): string {
    return Lang::get($key);
}
