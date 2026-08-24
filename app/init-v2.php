<?php
/**
 * SMM Turk - Application Bootstrap
 * Loads config and core classes
 */
define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/config.php';
if (!defined('DEPOSITS_CRYPTO_ONLY')) {
    define('DEPOSITS_CRYPTO_ONLY', true);
}
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Mail.php';
require_once __DIR__ . '/MailLocale.php';
require_once __DIR__ . '/Notify.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Totp.php';
require_once __DIR__ . '/SmmApi.php';
require_once __DIR__ . '/ProviderRegistry.php';
require_once __DIR__ . '/ContentCorrections.php';
require_once __DIR__ . '/Icons.php';
require_once __DIR__ . '/ProfileHelper.php';
require_once __DIR__ . '/OrderManager.php';
require_once __DIR__ . '/DepositManager.php';
require_once __DIR__ . '/CryptoVerifier.php';
require_once __DIR__ . '/DepositAutoConfirm.php';
require_once __DIR__ . '/PaymentRegistry.php';
require_once __DIR__ . '/PaymentProcessor.php';
require_once __DIR__ . '/ServiceDeduper.php';
require_once __DIR__ . '/MassOrderHelper.php';
require_once __DIR__ . '/ChildPanelManager.php';
require_once __DIR__ . '/ChildPanelDeployer.php';
require_once __DIR__ . '/ChildPanelUserSync.php';
require_once __DIR__ . '/ChildPanelEndUsers.php';
require_once __DIR__ . '/ChildPanelAutomation.php';
require_once __DIR__ . '/RevenueEngine.php';
require_once __DIR__ . '/ChildPanelRenewal.php';

RevenueEngine::ensureSchema(Database::getInstance());
require_once __DIR__ . '/GrowthEngine.php';
GrowthEngine::ensureSchema(Database::getInstance());
require_once __DIR__ . '/Newsletter.php';
Newsletter::ensureSchema(Database::getInstance());
require_once __DIR__ . '/UserOnboarding.php';
require_once __DIR__ . '/WhmProvisioner.php';
require_once __DIR__ . '/Seo.php';

// In production, log PHP errors to file (tmp/logs/php_errors.log)
if (php_sapi_name() !== 'cli' && defined('SMM_PRODUCTION') && SMM_PRODUCTION) {
    set_error_handler(function ($severity, $message, $file, $line) {
        $s = [E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_PARSE => 'E_PARSE', E_NOTICE => 'E_NOTICE', E_DEPRECATED => 'E_DEPRECATED'];
        $label = $s[$severity] ?? 'E_' . $severity;
        Logger::log("$label: $message in $file:$line", 'php_errors');
        return false;
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            Logger::log("Fatal: {$e['message']} in {$e['file']}:{$e['line']}", 'php_errors');
        }
    });
}

$db   = Database::getInstance();
OrderManager::ensureProviderSchema();
$auth = new Auth();

if (php_sapi_name() !== 'cli' && !$auth->isLoggedIn()) {
    GrowthEngine::captureUtm();
}

// Security headers (web only)
if (php_sapi_name() !== 'cli') {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // CSP: enforce in production unless SMM_CSP_REPORT_ONLY is explicitly enabled
    $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' https://accounts.google.com https://apis.google.com https://cdn.jsdelivr.net https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self' https://accounts.google.com https://apis.google.com https://cloudflareinsights.com; frame-src https://accounts.google.com; manifest-src 'self'; worker-src 'self';";
    $cspReportOnly = defined('SMM_CSP_REPORT_ONLY') && SMM_CSP_REPORT_ONLY;
    if ($cspReportOnly) {
        header("Content-Security-Policy-Report-Only: $csp");
    } else {
        header("Content-Security-Policy: $csp");
    }
}

// Maintenance mode (web only; allow CLI and logged-in admins)
if (php_sapi_name() !== 'cli') {
    $maintenance = $db->getSetting('maintenance_mode');
    if ($maintenance === '1' && !$auth->isAdmin()) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $allow = (strpos($uri, '/admin/') !== false && $auth->isLoggedIn())
            || preg_match('#/(login|login-2fa|forgot-password|reset-password|verify-email)(\.php)?(?:\?|$|/)#', $uri);
        if (!$allow) {
            header('HTTP/1.1 503 Service Unavailable');
            header('Retry-After: 300');
            header('Content-Type: text/html; charset=UTF-8');
            $siteName = defined('SITE_NAME') ? SITE_NAME : 'SMM Turk';
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($siteName) . ' — Maintenance</title><style>body{font-family:sans-serif;background:#1a0a0e;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;text-align:center;padding:20px}.box{max-width:400px}h1{color:#E30A17;font-size:1.5rem}p{color:rgba(255,255,255,.8);line-height:1.6}</style></head><body><div class="box"><h1>Under maintenance</h1><p>We are currently updating. Please try again in a few minutes.</p></div></body></html>';
            exit;
        }
    }
}

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/** Base path for internal links (e.g. '' or '/panel'). Use in href. */
function base_path(): string {
    if (!defined('SITE_URL') || SITE_URL === '') return '';
    $path = parse_url(SITE_URL, PHP_URL_PATH);
    return $path ? rtrim($path, '/') : '';
}

/** Full base URL for redirects (e.g. 'https://example.com' or 'https://example.com/panel'). */
function site_base(): string {
    return (defined('SITE_URL') && SITE_URL !== '') ? rtrim(SITE_URL, '/') : '';
}

/**
 * Public URL segments for PHP scripts (script name without .php).
 *
 * @return array<string, string>
 */
function route_aliases(): array {
    return [
        'home' => '',
        'index' => 'dashboard',
        'api-page' => 'api-docs',
        'account-settings' => 'settings',
        'add-funds' => 'funds',
    ];
}

/**
 * Convert .php page paths to clean URLs (no .php extension).
 * Supports query string (?a=1) and fragment (#section).
 */
function clean_page_path(string $p): string {
    $fragment = '';
    if (($hashPos = strpos($p, '#')) !== false) {
        $fragment = substr($p, $hashPos);
        $p = substr($p, 0, $hashPos);
    }
    $query = '';
    if (($qPos = strpos($p, '?')) !== false) {
        $query = substr($p, $qPos);
        $p = substr($p, 0, $qPos);
    }
    $p = ltrim($p, '/');
    if (str_ends_with($p, '.php')) {
        $p = substr($p, 0, -4);
    }
    if ($p === 'admin/index') {
        $p = 'admin';
    } elseif (array_key_exists($p, route_aliases())) {
        $p = route_aliases()[$p];
    }
    $path = ($p === '') ? '/' : '/' . $p;
    return $path . $query . $fragment;
}

/** Internal href with optional query and hash (same-origin). */
function route_path(string $script, array $query = [], string $hash = ''): string {
    $p = path($script);
    if ($query !== []) {
        $p .= (str_contains($p, '?') ? '&' : '?') . http_build_query($query);
    }
    if ($hash !== '') {
        $p .= '#' . ltrim($hash, '#');
    }
    return $p;
}

/** Public home page path (/). */
function home_path(): string {
    return path('home.php');
}

/** Panel dashboard path (/dashboard). */
function dashboard_path(array $query = [], string $hash = ''): string {
    return route_path('dashboard.php', $query, $hash);
}

/** Admin page path (/admin/…). */
function admin_path(string $script, array $query = [], string $hash = ''): string {
    $script = ltrim($script, '/');
    if (!str_starts_with($script, 'admin/')) {
        $script = 'admin/' . $script;
    }
    if (!str_ends_with($script, '.php')) {
        $script .= '.php';
    }
    return route_path($script, $query, $hash);
}

/** Register page with optional extra query params. */
function register_path(array $query = []): string {
    return route_path('login.php', array_merge(['mode' => 'register'], $query));
}

/** Login page that returns to a panel page after sign-in. */
function login_next_path(string $destinationScript, array $query = [], string $hash = '', string $mode = ''): string {
    $params = ['next' => route_path($destinationScript, $query, $hash)];
    if ($mode === 'register') {
        $params['mode'] = 'register';
    }
    return route_path('login.php', $params);
}

/** Full page URL with optional query and hash (for emails and redirects). */
function page_url(string $script, array $query = [], string $hash = ''): string {
    $u = url($script);
    if ($query !== []) {
        $u .= (str_contains($u, '?') ? '&' : '?') . http_build_query($query);
    }
    if ($hash !== '') {
        $u .= '#' . ltrim($hash, '#');
    }
    return $u;
}

/** Internal URL: full URL for redirects (use in Location header). */
function url(string $path): string {
    $path = clean_page_path('/' . ltrim($path, '/'));
    $base = site_base();
    return $base !== '' ? $base . $path : $path;
}

/** Path for internal href (same-origin). Prefer route_path() when adding query/hash. */
function path(string $p): string {
    $path = clean_page_path('/' . ltrim($p, '/'));
    return base_path() . $path;
}

function money(float $amount): string {
    return '$' . number_format($amount, 4);
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff/60) . ' min ago';
    if ($diff < 86400) return floor($diff/3600) . ' hr ago';
    return date('Y-m-d', strtotime($datetime));
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

/** Normalize user-entered order links (add https:// if missing). */
function normalize_order_link(string $link): string {
    $link = trim($link);
    if ($link === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $link)) {
        $link = 'https://' . ltrim($link, '/');
    }
    return filter_var($link, FILTER_VALIDATE_URL) ? $link : '';
}

/** Remember internal path to return after login. */
function store_login_next(?string $next = null): void {
    $next = trim($next ?? ($_GET['next'] ?? ''));
    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
        return;
    }
    $_SESSION['login_next'] = $next;
}

/** Safe post-login destination (internal paths only). */
function consume_login_next(): string {
    $next = trim($_SESSION['login_next'] ?? '');
    unset($_SESSION['login_next']);
    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
        return url('dashboard.php');
    }
    $base = site_base();
    return $base !== '' ? $base . $next : $next;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/** Cache-busted static asset URL for CSS/JS (uses file mtime when available). */
function asset_url(string $path): string {
    $clean = ltrim(str_replace('\\', '/', $path), '/');
    $url = path($clean);
    $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
    $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
    $ver = is_file($file) ? (string) filemtime($file) : '1';
    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $ver;
}

/** Logo / favicon URL with cache busting. */
function logo_url(): string {
    if (class_exists('Database')) {
        $custom = trim((string) (Database::getInstance()->getSetting('site_logo') ?? ''));
        if ($custom !== '') {
            if (preg_match('#^https?://#i', $custom)) {
                return $custom;
            }
            return asset_url(ltrim($custom, '/'));
        }
    }
    return asset_url('assets/img/logo-icon.png');
}

/** Favicon URL — custom path in settings or default logo. */
function favicon_url(): string {
    if (class_exists('Database')) {
        $custom = trim((string) (Database::getInstance()->getSetting('site_favicon') ?? ''));
        if ($custom !== '') {
            if (preg_match('#^https?://#i', $custom)) {
                return $custom;
            }
            return asset_url(ltrim($custom, '/'));
        }
    }
    return logo_url();
}

/** Display site name — custom value from settings (branding) or SITE_NAME constant. */
function site_name(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    if (class_exists('Database')) {
        $custom = trim((string) (Database::getInstance()->getSetting('site_name') ?? ''));
        if ($custom !== '') {
            return $cached = $custom;
        }
    }
    return $cached = (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
}

/** True when running as a deployed reseller (child) panel. */
function is_child_panel(): bool {
    return defined('SMM_CHILD_PANEL') && SMM_CHILD_PANEL;
}

/** Split site name for two-tone logo (last word accented). */
function site_name_logo_parts(): array {
    $name = site_name();
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($words === []) {
        return ['prefix' => '', 'accent' => 'SMM Turk'];
    }
    if (count($words) === 1) {
        return ['prefix' => '', 'accent' => $words[0]];
    }
    $accent = array_pop($words);
    return ['prefix' => implode(' ', $words), 'accent' => $accent];
}

/** Logo markup with last word in accent tag (sidebar, landing, auth pages). */
function site_name_logo_html(string $accentTag = 'span'): string {
    $parts = site_name_logo_parts();
    $open = '<' . $accentTag . '>';
    $close = '</' . $accentTag . '>';
    if ($parts['prefix'] === '') {
        return $open . h($parts['accent']) . $close;
    }
    return h($parts['prefix']) . ' ' . $open . h($parts['accent']) . $close;
}

/** Logo markup with last word bold (topbar). */
function site_name_logo_bold_html(): string {
    $parts = site_name_logo_parts();
    if ($parts['prefix'] === '') {
        return '<b>' . h($parts['accent']) . '</b>';
    }
    return h($parts['prefix']) . ' <b>' . h($parts['accent']) . '</b>';
}

/** Default Open Graph / Twitter image (1200x630 PNG recommended). */
function og_image_url(?string $override = null): string {
    if ($override !== null && trim($override) !== '') {
        $override = trim($override);
        if (preg_match('#^https?://#i', $override)) {
            return $override;
        }
        $base = site_base();
        $resolved = path($override);
        return $base !== '' ? $base . $resolved : $resolved;
    }
    if (defined('OG_IMAGE_URL') && trim((string) OG_IMAGE_URL) !== '') {
        return og_image_url(trim((string) OG_IMAGE_URL));
    }
    return og_image_url('assets/img/og-cover.png');
}

/** HMAC token for cron/maintenance scripts (use in crontab: php cron-sync.php OR ?token=...) */
function internal_token(string $purpose): string {
    $secret = (defined('CRON_SECRET') && CRON_SECRET !== '') ? CRON_SECRET : (defined('SECRET_KEY') ? SECRET_KEY : '');
    if ($secret === '') {
        return '';
    }
    return hash_hmac('sha256', $purpose, $secret);
}

/** Allow CLI only; block direct web access to migration/setup scripts. */
function require_cli(): void {
    if (php_sapi_name() === 'cli') {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden. Run this script from the command line.';
    exit;
}

/** Allow CLI or valid signed cron token via ?token= or X-Cron-Token header. */
function require_cli_or_cron_token(string $purpose): void {
    if (php_sapi_name() === 'cli') {
        return;
    }
    $expected = internal_token($purpose);
    if ($expected === '') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Cron token not configured. Set CRON_SECRET or SECRET_KEY in config.php.';
        exit;
    }
    $provided = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
    if ($provided !== '' && hash_equals($expected, $provided)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}
