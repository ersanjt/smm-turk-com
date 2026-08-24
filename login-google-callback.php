<?php
/**
 * Google OAuth callback: exchange code for token, fetch profile, login or register.
 */
require_once __DIR__ . '/app/init.php';

if ($auth->isLoggedIn()) {
    redirect(url('dashboard.php'));
}

$clientId     = defined('GOOGLE_CLIENT_ID') ? trim(GOOGLE_CLIENT_ID) : '';
$clientSecret = defined('GOOGLE_CLIENT_SECRET') ? trim(GOOGLE_CLIENT_SECRET) : '';
$siteUrl      = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$redirectUri  = url('login-google-callback.php');

$error = '';
$code  = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

if ($clientId === '' || $clientSecret === '' || $siteUrl === '') {
    $error = 'Google Sign-In is not configured.';
} elseif (!hash_equals($_SESSION['google_oauth_state'] ?? '', $state)) {
    $error = 'Invalid state. Please try again.';
} elseif ($code === '') {
    $error = 'No authorization code received.';
} else {
    unset($_SESSION['google_oauth_state']);

    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $tokenBody = http_build_query([
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $tokenBody,
        ],
    ]);
    $tokenJson = @file_get_contents($tokenUrl, false, $ctx);
    if ($tokenJson === false) {
        $error = 'Could not get token from Google.';
    } else {
        $token = json_decode($tokenJson, true);
        $accessToken = $token['access_token'] ?? '';
        if ($accessToken === '') {
            $error = 'Invalid response from Google.';
        } else {
            $userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
            $ctxGet = stream_context_create([
                'http' => [
                    'header' => "Authorization: Bearer " . $accessToken . "\r\n",
                ],
            ]);
            $userJson = @file_get_contents($userInfoUrl, false, $ctxGet);
            if ($userJson === false) {
                $error = 'Could not get profile from Google.';
            } else {
                $profile = json_decode($userJson, true);
                $googleId = $profile['id'] ?? '';
                $email    = $profile['email'] ?? '';
                $name     = $profile['name'] ?? $profile['given_name'] ?? 'User';
                $result   = $auth->loginOrCreateFromGoogle($googleId, $email, $name);
                if ($result['success']) {
                    if (!empty($result['needs_2fa'])) {
                        redirect(url('login-2fa.php'));
                    }
                    redirect($auth->postLoginRedirectUrl($result));
                }
                $error = $result['error'] ?? 'Login failed.';
            }
        }
    }
}

$_SESSION['flash'] = ['type' => 'error', 'message' => $error];
redirect(url('login.php'));
