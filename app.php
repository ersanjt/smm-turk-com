<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';

if ($auth->isLoggedIn()) {
    // still allow logged-in users to download the app
}

$lang = Lang::initPublic();
$siteName = function_exists('site_name') ? site_name() : 'SMM Turk';
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$navActive = 'app';
$apkPath = ROOT_PATH . '/downloads/smm-turk-android.apk';
$ipaPath = ROOT_PATH . '/downloads/smm-turk-ios.ipa';
$hasApk = is_file($apkPath);
$hasIpa = is_file($ipaPath);
$apkUrl = path('download-app.php') . '?platform=android';
$iosInstallUrl = path('m.php');
$iosInstallUrl = preg_replace('#m\.php$#', 'm', $iosInstallUrl) ?: $iosInstallUrl;
$canonical = $siteUrl ? Seo::absoluteUrl(path('get-app.php')) : path('get-app.php');
$canonical = preg_replace('#get-app\.php$#', 'get-app', $canonical) ?: $canonical;
$pageImg = og_image_url();
$seoTitle = $siteName . ' — ' . __('app_seo_title');
$seoDescription = __('app_seo_desc');
?>
<!DOCTYPE html>
<html lang="<?= h(Seo::htmlLang($lang)) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($seoTitle) ?></title>
    <meta name="description" content="<?= h($seoDescription) ?>">
    <link rel="canonical" href="<?= h($canonical) ?>">
    <?= Seo::hreflangTags(preg_replace('#get-app\.php$#', 'get-app', Seo::absoluteUrl(path('get-app.php'))) ?: path('get-app')) ?>
    <meta name="theme-color" content="#E30A17">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= h($seoTitle) ?>">
    <meta property="og:description" content="<?= h($seoDescription) ?>">
    <meta property="og:url" content="<?= h($canonical) ?>">
    <meta property="og:image" content="<?= h($pageImg) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <?php echo_favicon_links(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/landing.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/ui-pro.css')) ?>">
    <style>
        .app-hero { padding: 140px 24px 48px; max-width: 1080px; margin: 0 auto; }
        .app-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 28px; }
        .app-card { background: var(--white); border: 1px solid var(--border); border-radius: 20px; padding: 28px; box-shadow: var(--shadow-md); }
        .app-card h2 { font-family: Syne, sans-serif; font-size: 28px; margin-bottom: 10px; color: var(--dark); }
        .app-card p { color: var(--muted); margin-bottom: 18px; line-height: 1.6; }
        .app-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 48px; padding: 12px 18px; border-radius: 12px; font-weight: 700; }
        .app-btn-primary { background: linear-gradient(145deg, var(--primary), var(--primary-dark)); color: #fff; }
        .app-btn-outline { border: 2px solid rgba(227,10,23,.2); color: var(--primary); }
        .app-steps { margin: 0; padding-left: 18px; color: var(--muted); }
        .app-steps li { margin: 8px 0; }
        .app-note { font-size: 13px; color: var(--muted); margin-top: 12px; }
        .app-hero .hero-badge { color: #fff; }
        body.theme-dark .app-card {
            background: #1a1416;
            border-color: rgba(255,255,255,.08);
            box-shadow: 0 8px 32px rgba(0,0,0,.35);
        }
        body.theme-dark .app-card:hover { border-color: rgba(255, 85, 102, 0.28); }
        body.theme-dark .app-card h2 { color: #f5eef0; }
        body.theme-dark .app-card p,
        body.theme-dark .app-steps,
        body.theme-dark .app-note { color: #b8a8ac; }
        body.theme-dark .app-btn-outline { border-color: rgba(255, 85, 102, 0.4); color: #FF5566; }
        @media (max-width: 800px) { .app-grid { grid-template-columns: 1fr; } .app-hero { padding-top: 110px; } }
    </style>
</head>
<body>
<script>(function(){var k='smmturk_theme',d=localStorage.getItem(k)==='dark'||(!localStorage.getItem(k)&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);if(d)document.body.classList.add('theme-dark');})();</script>
<?php require __DIR__ . '/partials/landing-nav.php'; ?>
<main id="main-content" class="app-hero">
    <span class="hero-badge"><?= h(__('app_badge')) ?></span>
    <h1 class="section-title" style="text-align:left;max-width:16ch;"><?= h(__('app_title')) ?></h1>
    <p class="section-desc" style="text-align:left;margin-left:0;"><?= h(__('app_desc')) ?></p>
    <div class="app-grid">
        <article class="app-card">
            <h2>Android</h2>
            <p><?= h(__('app_android_desc')) ?></p>
            <?php if ($hasApk): ?>
            <a class="app-btn app-btn-primary" href="<?= h($apkUrl) ?>" download="smm-turk-android.apk"><?= h(__('app_android_btn')) ?></a>
            <?php else: ?>
            <a class="app-btn app-btn-primary" href="<?= h($apkUrl) ?>"><?= h(__('app_android_btn')) ?></a>
            <?php endif; ?>
            <p class="app-note"><?= h(__('app_android_note')) ?></p>
        </article>
        <article class="app-card">
            <h2>iPhone / iOS</h2>
            <p><?= h(__('app_ios_desc')) ?></p>
            <?php if ($hasIpa): ?>
            <a class="app-btn app-btn-primary" href="<?= h(path('download-app.php') . '?platform=ios') ?>"><?= h(__('app_ios_ipa')) ?></a>
            <?php endif; ?>
            <a class="app-btn <?= $hasIpa ? 'app-btn-outline' : 'app-btn-primary' ?>" href="<?= h($iosInstallUrl) ?>"><?= h(__('app_ios_btn')) ?></a>
            <ol class="app-steps">
                <li><?= h(__('app_ios_s1')) ?></li>
                <li><?= h(__('app_ios_s2')) ?></li>
                <li><?= h(__('app_ios_s3')) ?></li>
            </ol>
        </article>
    </div>
</main>
<?php require __DIR__ . '/partials/landing-footer.php'; ?>
<script src="<?= h(asset_url('assets/js/landing.js')) ?>" defer></script>
<?php require __DIR__ . '/partials/a11y.php'; ?>
</body>
</html>
