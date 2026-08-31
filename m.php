<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';

$lang = Lang::initPublic();
$siteName = function_exists('site_name') ? site_name() : 'SMM Turk';
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$canonical = $siteUrl ? Seo::absoluteUrl(path('m.php')) : path('m.php');
$canonical = preg_replace('#m\.php$#', 'm', $canonical) ?: $canonical;
$googleAuth = defined('GOOGLE_CLIENT_ID') && trim(GOOGLE_CLIENT_ID) !== '';
$registrationEnabled = (Database::getInstance()->getSetting('registration_enabled') ?? '1') === '1';
$boot = [
    'api' => path('api/mobile.php'),
    'google' => $googleAuth,
    'googleUrl' => path('login-google.php') . '?mobile=1',
    'register' => $registrationEnabled,
    'siteName' => $siteName,
    'logo' => logo_url(),
    'lang' => $lang,
    'home' => home_path(),
    'download' => path('app.php'),
];
?>
<!DOCTYPE html>
<html lang="<?= h(Seo::htmlLang($lang)) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= h($siteName) ?> App</title>
    <meta name="description" content="<?= h($siteName) ?> mobile app — new orders, services, crypto deposits, tickets.">
    <meta name="theme-color" content="#E30A17">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= h($siteName) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="canonical" href="<?= h($canonical) ?>">
    <?php echo_favicon_links(); ?>
    <link rel="apple-touch-icon" href="<?= h(logo_url()) ?>">
    <link rel="manifest" href="<?= h(path('m-manifest.php')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= h(asset_url('assets/mobile-app/app.css')) ?>">
</head>
<body>
    <div id="app" class="app" data-boot='<?= h(json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'>
        <div class="splash" role="status">
            <img src="<?= h(logo_url()) ?>" alt="" width="72" height="72">
            <strong><?= h($siteName) ?></strong>
            <span>Loading…</span>
        </div>
    </div>
    <script src="<?= h(asset_url('assets/mobile-app/app.js')) ?>" defer></script>
</body>
</html>
