<?php
// ============================================================
//  SMM Turk Panel - Configuration Template
//  Copy this file to config.php and fill in your values
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'smm_turk');          // Your database name
define('DB_USER', 'your_db_user');      // Your MySQL username
define('DB_PASS', 'your_db_password');  // Your MySQL password
define('DB_CHARSET', 'utf8mb4');

// Base URL for redirects, canonicals, and all internal links. Must match how users open the site.
// Use https and NO trailing slash. Examples:
//   - Site at root:     https://smm-turk.com
//   - Site in subdir:   https://smm-turk.com/panel
define('SITE_URL', 'https://smm-turk.com');
define('SITE_NAME', 'SMM Turk');
// Geo/SEO — primary market Turkey, service worldwide (ISO 3166-1 alpha-2)
define('GEO_REGION', 'TR');
define('GEO_PLACENAME', 'Turkey');
define('GEO_LOCALITY', 'Ankara');
define('GEO_LAT', 39.9334);
define('GEO_LNG', 32.8597);
define('GEO_TIMEZONE', 'Europe/Istanbul');
// Social sharing image (1200x630 PNG/JPG). Relative path or full URL.
define('OG_IMAGE_URL', 'assets/img/og-default.png');
// Google Search Console → HTML tag verification (optional)
define('GOOGLE_SITE_VERIFICATION', '');
define('MAIL_FROM', 'noreply@yourdomain.com'); // Optional; used for verification emails (cPanel: use an email from your domain)

// SmmFollows API - Get key from https://smmfollows.com → Account → API
define('PROVIDER_API_URL', 'https://smmfollows.com/api/v2');
define('PROVIDER_API_KEY', 'YOUR_SMMFOLLOWS_API_KEY');

// Security - Session/CSRF key. In production you MUST use a fixed value:
// 1) Set env SMM_SECRET_KEY to a 32-char hex, or 2) define a constant below.
// Do NOT use bin2hex(random_bytes(16)) at runtime in production (sessions break on restart).
define('SECRET_KEY', getenv('SMM_SECRET_KEY') ?: 'CHANGE_ME_TO_A_FIXED_32_CHAR_HEX_SECRET');
// Optional: separate secret for cron HTTP triggers. Falls back to SECRET_KEY.
define('CRON_SECRET', getenv('CRON_SECRET') ?: '');
// Set true to use CSP report-only mode instead of enforcing headers.
define('SMM_CSP_REPORT_ONLY', false);
define('SESSION_LIFETIME', 86400);  // Idle timeout in seconds (24h). Session expires after inactivity.

// Payments: this panel accepts cryptocurrency deposits ONLY (no cards, PayPal, or bank transfer).
define('DEPOSITS_CRYPTO_ONLY', true);

// Crypto deposit — fallback wallet when Admin → Settings → Crypto Wallets are empty.
define('CRYPTO_WALLET_ADDRESS', 'YOUR_ETH_OR_USDT_ADDRESS');

define('MARKUP_PERCENT', 10);
define('MIN_DEPOSIT', 10);
define('REFERRAL_COMMISSION', 2);

// Google Sign-In (optional). Get credentials: https://console.cloud.google.com/apis/credentials
// Redirect URI = SITE_URL + '/login-google-callback' (no .php). Add it in Console → Credentials → Your OAuth client → Authorized redirect URIs.
define('GOOGLE_CLIENT_ID', '');
define('GOOGLE_CLIENT_SECRET', '');

date_default_timezone_set('UTC');

// Error reporting: default is production (no display_errors). Set SMM_DEBUG=1 or define('SMM_DEBUG', true) to show errors.
$isProduction = (getenv('SMM_DEBUG') === '1' || (defined('SMM_DEBUG') && SMM_DEBUG === true)) ? false : true;
define('SMM_PRODUCTION', $isProduction);
if (!$isProduction) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}
