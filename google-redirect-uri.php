<?php
/**
 * Shows the exact redirect URI used for Google Sign-In.
 * Open this in the browser and add the shown URL to Google Cloud Console → Credentials → Authorized redirect URIs.
 * Delete or restrict access in production if you prefer.
 */
require_once __DIR__ . '/app/init.php';

$base = defined('SITE_URL') && SITE_URL !== '' ? rtrim(SITE_URL, '/') : '';
$redirectUri = $base !== '' ? $base . '/login-google-callback' : '';

header('Content-Type: text/plain; charset=utf-8');
if ($redirectUri === '') {
    echo "SITE_URL is not set in config.php. Set it to your site URL (e.g. https://smm-turk.com).\n";
    exit;
}
echo "Add this exact URL in Google Cloud Console:\n\n";
echo $redirectUri . "\n\n";
echo "Console: APIs & Services → Credentials → [Your OAuth 2.0 Client] → Authorized redirect URIs\n";
