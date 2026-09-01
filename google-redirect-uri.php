<?php
/**
 * Shows the exact redirect URI used for Google Sign-In (admin only).
 */
require_once __DIR__ . '/app/init.php';
$auth->requireAdmin();

$base = defined('SITE_URL') && SITE_URL !== '' ? rtrim(SITE_URL, '/') : '';
$redirectUri = $base !== '' ? $base . '/login-google-callback' : '';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
if ($redirectUri === '') {
    echo "SITE_URL is not set in config.php. Set it to your site URL (e.g. https://smm-turk.com).\n";
    exit;
}
echo "Add this exact URL in Google Cloud Console:\n\n";
echo $redirectUri . "\n\n";
echo "Console: APIs & Services → Credentials → [Your OAuth 2.0 Client] → Authorized redirect URIs\n";
