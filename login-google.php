<?php
/**
 * Redirect to Google OAuth consent screen. Requires GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in config.
 */
require_once __DIR__ . '/app/init.php';

if ($auth->isLoggedIn()) {
    redirect(url('dashboard.php'));
}

$clientId = defined('GOOGLE_CLIENT_ID') ? trim(GOOGLE_CLIENT_ID) : '';
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$redirectUri = url('login-google-callback.php');

if ($clientId === '' || $siteUrl === '') {
    flash('error', 'Google Sign-In is not configured.');
    redirect(url('login.php'));
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = [
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
];
$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
header('Location: ' . $url);
exit;
