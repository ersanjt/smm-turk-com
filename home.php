<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';

if ($auth->isLoggedIn()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$lang = Lang::initPublic();
$db = Database::getInstance();
$growth = new GrowthEngine();
$stats = $growth->publicStats();
$promoBar = $growth->promoBar();
$offerLines = $growth->offerLines();
$registrationEnabled = ($db->getSetting('registration_enabled') ?? '1') === '1';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
$siteUrl  = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$homePath = home_path();
$baseCanonical = $siteUrl ? Seo::absoluteUrl($homePath) : $homePath;
$canonicalUrl = Seo::pageCanonical($baseCanonical, $lang);
$pageImg = og_image_url();
if ($pageImg !== '' && !preg_match('#^https?://#i', $pageImg)) {
    $pageImg = Seo::absoluteUrl($pageImg);
}
$seoTitle = $siteName . ' — ' . __('seo_title');
$seoDescription = __('seo_description');
$seoOgTitle = $siteName . ' — ' . __('seo_og_title');
$seoOgDescription = __('seo_og_description');
$ogLocale = Seo::ogLocale($lang);
$faqItems = [];
for ($faqIndex = 1; $faqIndex <= 6; $faqIndex++) {
    $faqItems[] = ['name' => __('faq_' . $faqIndex), 'text' => __('faq_' . $faqIndex . '_a')];
}
$homeJsonLd = [
    Seo::organizationSchema($seoDescription, $lang),
    Seo::websiteSchema($seoDescription),
    Seo::faqSchema($faqItems, $lang),
];
?>
<!DOCTYPE html>
<html lang="<?= h(Seo::htmlLang($lang)) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($seoTitle) ?></title>
    <meta name="description" content="<?= h($seoDescription) ?>">
    <meta name="robots" content="<?= h(Seo::robotsContent(true)) ?>">
    <?php if ($canonicalUrl): ?><link rel="canonical" href="<?= h($canonicalUrl) ?>"><?php endif; ?>
    <?= Seo::hreflangTags($baseCanonical) ?>
    <?= Seo::verificationMeta() ?>
    <meta name="theme-color" content="#E30A17">
    <?= Seo::geoMetaTags($lang) ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= h($siteName) ?>">
    <meta property="og:title" content="<?= h($seoOgTitle) ?>">
    <meta property="og:description" content="<?= h($seoOgDescription) ?>">
    <meta property="og:url" content="<?= h($canonicalUrl) ?>">
    <meta property="og:image" content="<?= h($pageImg) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="<?= h($ogLocale) ?>">
    <?= Seo::ogLocaleAlternates($lang) ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($seoOgTitle) ?>">
    <meta name="twitter:description" content="<?= h($seoOgDescription) ?>">
    <meta name="twitter:image" content="<?= h($pageImg) ?>">
    <link rel="icon" type="image/png" href="<?= h(logo_url()) ?>">
    <link rel="apple-touch-icon" href="<?= h(logo_url()) ?>">
    <link rel="manifest" href="<?= h(path('manifest.php')) ?>">
    <script type="application/ld+json"><?= Seo::jsonLd($homeJsonLd) ?></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/landing.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/pricing-public.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/ui-pro.css')) ?>">
</head>
<body data-sw="<?= h(path('pwa-sw.php')) ?>" data-sw-scope="<?= h(base_path() !== '' ? base_path() . '/' : '/') ?>">
<script>(function(){var k='smmturk_theme',d=localStorage.getItem(k)==='dark'||(!localStorage.getItem(k)&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);if(d)document.body.classList.add('theme-dark');})();</script>
<?php if ($promoBar['enabled']): ?>
<div class="growth-promo-bar">
    <span><?= h($promoBar['text']) ?></span>
    <a href="<?= h($promoBar['cta_url']) ?>"><?= h($promoBar['cta_label']) ?></a>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/landing-nav.php'; ?>
<main id="main-content">
<section class="hero" aria-labelledby="hero-title">
    <div class="hero-orb hero-orb-1" aria-hidden="true"></div>
    <div class="hero-orb hero-orb-2" aria-hidden="true"></div>
    <div class="hero-orb hero-orb-3" aria-hidden="true"></div>
    <div class="hero-inner">
        <div class="hero-copy">
            <span class="hero-badge"><?= h(__('hero_badge')) ?></span>
            <h1 id="hero-title"><?= h(__('hero_title')) ?><br><span class="hero-title-2"><?= h(__('hero_title_2')) ?></span></h1>
            <p class="hero-desc"><?= __('hero_desc_1') ?></p>
            <?php if (!empty($offerLines)): ?>
            <div class="hero-offers">
                <?php foreach (array_slice($offerLines, 0, 4) as $offer): ?>
                <span class="hero-offer-pill"><?= h($offer) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <p class="hero-desc"><?= __('hero_desc_2') ?></p>
            <p class="hero-desc"><?= __('hero_desc_3') ?></p>
        </div>
        <div class="hero-form-box">
            <h2 class="form-title"><?= h(__('nav_sign_in')) ?></h2>
            <?php $googleAuth = defined('GOOGLE_CLIENT_ID') && trim(GOOGLE_CLIENT_ID) !== ''; ?>
            <?php if ($googleAuth): ?>
            <a href="<?= h(path('login-google.php')) ?>" class="btn-google btn-google-hero">
                <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                <?= h(__('sign_in_with_google')) ?>
            </a>
            <div class="divider">— <?= h(__('or_continue_with_email')) ?> —</div>
            <?php endif; ?>
            <form method="POST" action="<?= h(path('login.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="form-group">
                    <label class="form-label" for="hero-email"><?= h(__('login_username')) ?></label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                        <input type="text" name="email" id="hero-email" class="form-control" placeholder="<?= h(__('login_username')) ?>" required <?= $googleAuth ? '' : 'autofocus' ?>>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="hero-password"><?= h(__('login_password')) ?></label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                        <input type="password" name="password" id="hero-password" class="form-control" placeholder="••••••••" required>
                    </div>
                </div>
                <div class="remember"><label><input type="checkbox" name="remember"> <?= h(__('remember_me')) ?></label></div>
                <div style="margin-bottom:12px;"><a href="<?= h(path('forgot-password.php')) ?>" class="forgot"><?= h(__('forgot_password')) ?></a></div>
                <button type="submit" class="btn-login"><?= h(__('btn_login_dashboard')) ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg></button>
                <p class="register-link"><?= h(__('no_account')) ?> <a href="<?= h(register_path()) ?>">→ <?= h(__('register')) ?></a></p>
            </form>
        </div>
    </div>
</section>

<section id="benefits" class="section section-benefits" aria-labelledby="benefits-heading">
    <div class="section-label"><?= h(__('benefit_heading')) ?></div>
    <h2 id="benefits-heading" class="section-title"><?= h(__('benefit_title')) ?></h2>
    <p class="section-desc"><?= h(__('benefit_intro')) ?></p>
    <div class="benefit-grid">
        <div class="benefit-card">
            <div class="benefit-icon"><?= iconBox('wallet', 'primary') ?></div>
            <div><h3><?= h(__('cheapest')) ?></h3><p><?= h(__('cheapest_desc')) ?></p></div>
        </div>
        <div class="benefit-card">
            <div class="benefit-icon"><?= iconBox('zap', 'orange') ?></div>
            <div><h3><?= h(__('fastest')) ?></h3><p><?= h(__('fastest_desc')) ?></p></div>
        </div>
        <div class="benefit-card">
            <div class="benefit-icon"><?= iconBox('target', 'green') ?></div>
            <div><h3><?= h(__('easy')) ?></h3><p><?= h(__('easy_desc')) ?></p></div>
        </div>
        <div class="benefit-card">
            <div class="benefit-icon"><?= iconBox('chart', 'blue') ?></div>
            <div><h3><?= h(__('realtime')) ?></h3><p><?= h(__('realtime_desc')) ?></p></div>
        </div>
        <div class="benefit-card">
            <div class="benefit-icon"><?= iconBox('refresh', 'purple') ?></div>
            <div><h3><?= h(__('reseller')) ?></h3><p><?= h(__('reseller_desc')) ?></p></div>
        </div>
        <div class="benefit-card">
            <div class="benefit-icon"><?= iconBox('star', 'primary') ?></div>
            <div><h3><?= h(__('quality')) ?></h3><p><?= h(__('quality_desc')) ?></p></div>
        </div>
    </div>
</section>

<section id="services" class="section" style="background: var(--white); padding: 60px 24px;">
    <div class="section-label"><?= h(__('benefit_heading')) ?></div>
    <h2 class="section-title"><?= h(__('services_title')) ?></h2>
    <div class="three-cols">
        <div class="feature-block">
            <div class="icon"><?= iconBox('credit-card', 'green') ?></div>
            <h3><?= h(__('secure_payment')) ?></h3>
            <p><?= h(__('secure_payment_desc')) ?></p>
        </div>
        <div class="feature-block">
            <div class="icon"><?= iconBox('globe', 'blue') ?></div>
            <h3><?= h(__('services_title')) ?></h3>
            <p><?= h(__('services_desc')) ?></p>
        </div>
        <div class="feature-block">
            <div class="icon"><?= iconBox('message', 'primary') ?></div>
            <h3><?= h(__('support_24_7')) ?></h3>
            <p><?= h(__('support_24_7_desc')) ?></p>
        </div>
    </div>
    <p style="text-align:center;margin-top:20px;"><a href="<?= h(path('pricing.php')) ?>" style="color:var(--primary);font-weight:700;"><?= h(__('view_price_list')) ?> →</a></p>
</section>

<section id="earn" class="section" style="background: linear-gradient(180deg, var(--light-warm), var(--white)); padding: 60px 24px;">
    <div class="section-label"><?= h(__('home_earn_label')) ?></div>
    <h2 class="section-title"><?= h(__('earn_hero_h1')) ?></h2>
    <p class="section-desc"><?= h(__('home_earn_desc')) ?></p>
    <div class="benefit-grid" style="margin-top:24px;">
        <div class="benefit-card">
            <div class="benefit-icon"><?= iconBox('server', 'primary') ?></div>
            <div><h3><?= h(__('home_earn_child_t')) ?></h3><p><?= h(__('home_earn_child_d')) ?></p></div>
        </div>
        <div class="benefit-card">
            <div class="benefit-icon"><?= iconBox('users', 'green') ?></div>
            <div><h3><?= h(__('home_earn_aff_t')) ?></h3><p><?= h(__('home_earn_aff_d')) ?></p></div>
        </div>
        <div class="benefit-card">
            <div class="benefit-icon"><?= iconBox('plug', 'blue') ?></div>
            <div><h3><?= h(__('home_earn_api_t')) ?></h3><p><?= h(__('home_earn_api_d')) ?></p></div>
        </div>
    </div>
    <p style="text-align:center;margin-top:28px;">
        <a href="<?= h(path('earn.php')) ?>" class="btn-cta"><?= h(__('home_earn_learn')) ?> →</a>
        &nbsp;
        <a href="<?= h(path('pricing.php')) ?>" class="btn-cta-outline" style="display:inline-block;padding:14px 28px;border:2px solid var(--primary);color:var(--primary);border-radius:12px;font-weight:700;text-decoration:none;"><?= h(__('earn_view_prices')) ?></a>
    </p>
</section>

<section class="section">
    <div class="cta-block">
        <div class="section-label"><?= h(__('quick_response_label')) ?></div>
        <h2 class="section-title"><?= h(__('quick_response')) ?></h2>
        <p class="section-desc"><?= h(__('quick_response_desc')) ?></p>
        <a href="<?= h(register_path()) ?>" class="btn-cta"><?= h(__('sign_up_now')) ?> →</a>
    </div>
    <div class="stats-row">
        <div class="stat-item">
            <div class="icon"><?= iconBox('clock', 'orange') ?></div>
            <div class="stat-value">0.3Sec</div>
            <div class="stat-label"><?= h(__('stat_order_every')) ?></div>
        </div>
        <div class="stat-item">
            <div class="icon"><?= iconBox('check-circle', 'green') ?></div>
            <div class="stat-value"><?= h($stats['orders']) ?></div>
            <div class="stat-label"><?= h(__('stat_orders_completed')) ?></div>
        </div>
        <div class="stat-item">
            <div class="icon"><?= iconBox('dollar', 'primary') ?></div>
            <div class="stat-value"><?= h($stats['min_price']) ?></div>
            <div class="stat-label"><?= h(__('stat_prices_from')) ?></div>
        </div>
        <div class="stat-item">
            <div class="icon"><?= iconBox('users', 'blue') ?></div>
            <div class="stat-value"><?= h($stats['users']) ?></div>
            <div class="stat-label"><?= h(__('stat_active_users')) ?></div>
        </div>
    </div>
</section>

<section id="faq" class="section" style="background: var(--white);">
    <div class="section-label"><?= h(__('faq_label')) ?></div>
    <h2 class="section-title"><?= h(__('faq_title')) ?></h2>
    <div class="faq-list">
        <div class="faq-item"><button type="button" class="faq-q" aria-expanded="false"><?= h(__('faq_1')) ?> <span aria-hidden="true">+</span></button><div class="faq-a"><?= h(__('faq_1_a')) ?></div></div>
        <div class="faq-item"><button type="button" class="faq-q" aria-expanded="false"><?= h(__('faq_2')) ?> <span aria-hidden="true">+</span></button><div class="faq-a"><?= h(__('faq_2_a')) ?></div></div>
        <div class="faq-item"><button type="button" class="faq-q" aria-expanded="false"><?= h(__('faq_3')) ?> <span aria-hidden="true">+</span></button><div class="faq-a"><?= h(__('faq_3_a')) ?></div></div>
        <div class="faq-item"><button type="button" class="faq-q" aria-expanded="false"><?= h(__('faq_4')) ?> <span aria-hidden="true">+</span></button><div class="faq-a"><?= h(__('faq_4_a')) ?></div></div>
        <div class="faq-item"><button type="button" class="faq-q" aria-expanded="false"><?= h(__('faq_5')) ?> <span aria-hidden="true">+</span></button><div class="faq-a"><?= h(__('faq_5_a')) ?></div></div>
        <div class="faq-item"><button type="button" class="faq-q" aria-expanded="false"><?= h(__('faq_6')) ?> <span aria-hidden="true">+</span></button><div class="faq-a"><?= h(__('faq_6_a')) ?></div></div>
    </div>
</section>

<section id="why-us" class="section">
    <div class="section-label"><?= h(__('why_us_label')) ?></div>
    <h2 class="section-title"><?= h(__('why_us_title')) ?></h2>
    <div class="why-us-grid">
        <div class="why-us-icons">
            <div class="why-us-icon"><div class="ico"><?= iconBox('message', 'primary') ?></div><span><?= h(__('live_chat')) ?></span></div>
            <div class="why-us-icon"><div class="ico"><?= iconBox('package', 'green') ?></div><span><?= h(__('multi_services')) ?></span></div>
            <div class="why-us-icon"><div class="ico"><?= iconBox('clipboard', 'orange') ?></div><span><?= h(__('mass_order')) ?></span></div>
            <div class="why-us-icon"><div class="ico"><?= iconBox('plug', 'blue') ?></div><span><?= h(__('api_integration')) ?></span></div>
        </div>
        <div>
            <p class="section-desc" style="margin-bottom:24px;"><?= h(__('why_us_desc')) ?></p>
            <a href="<?= h(register_path()) ?>" class="nav-btn" style="display:inline-block;"><?= h(__('sign_up_now')) ?> →</a>
        </div>
    </div>
</section>

<section class="section" style="padding-top: 0;">
    <div class="cta-block">
        <h2 class="section-title"><?= h(__('cta_ready')) ?></h2>
        <p class="section-desc"><?= h(__('cta_join')) ?></p>
        <a href="<?= h(register_path()) ?>" class="btn-cta"><?= h(__('cta_btn')) ?></a>
    </div>
</section>

</main>

<?php require __DIR__ . '/partials/landing-footer.php'; ?>

    <script src="<?= h(asset_url('assets/js/landing.js')) ?>" defer></script>
    <script src="<?= h(asset_url('assets/js/pwa.js')) ?>" defer></script>
    <?php require __DIR__ . '/partials/a11y.php'; ?>
</body>
</html>
