<?php
/**
 * Programmatic SEO landings for commercial Google queries (/buy/{slug}).
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';
require_once __DIR__ . '/app/PlatformIcons.php';
if (!class_exists('GoogleAcquisition', false)) {
    require_once __DIR__ . '/app/GoogleAcquisition.php';
}

$lang = Lang::initPublic();
$db = Database::getInstance();
$acq = new GoogleAcquisition();
$growth = new GrowthEngine();
$stats = $growth->publicStats();
$promoBar = $growth->promoBar();
$registrationEnabled = ($db->getSetting('registration_enabled') ?? '1') === '1';
$loggedIn = $auth->isLoggedIn();

$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
$page = $slug !== '' ? GoogleAcquisition::findPage($slug) : null;

if ($slug !== '' && $page === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$catalog = GoogleAcquisition::catalog();
$siteName = Seo::siteName();
$navActive = 'buy';

if ($page === null) {
    $copy = [
        'title' => __('buy_hub_meta_title'),
        'h1' => __('buy_hub_h1'),
        'desc' => __('buy_hub_meta_desc'),
        'keywords' => __('buy_hub_keywords'),
        'intro' => __('buy_hub_intro'),
    ];
    $baseCanonical = Seo::absoluteUrl(GoogleAcquisition::hubUrl());
    $seoTitle = $copy['title'];
    $seoDescription = $copy['desc'];
    $metaKeywords = $copy['keywords'];
    $services = [];
    $lowPrice = 0.001;
    $jsonLdGraph = [
        Seo::organizationSchema($seoDescription, $lang),
        Seo::websiteSchema($seoDescription),
        Seo::webPageSchema($copy['h1'], $seoDescription, Seo::pageCanonical($baseCanonical, $lang), $lang),
        Seo::breadcrumbSchema([
            ['name' => __('blog_nav_home'), 'url' => Seo::absoluteUrl(home_path())],
            ['name' => __('buy_nav'), 'url' => $baseCanonical],
        ], $lang),
    ];
} else {
    $copy = GoogleAcquisition::copy($page['slug'], $lang);
    $baseCanonical = Seo::absoluteUrl(GoogleAcquisition::pageUrl($page['slug']));
    $seoTitle = $copy['title'];
    $seoDescription = $copy['desc'];
    $metaKeywords = $copy['keywords'];
    $services = $acq->liveServices($page, 12);
    $lowPrice = 0.001;
    if ($services !== []) {
        $lowPrice = (float) $services[0]['retail_rate'];
    }
    $faqItems = $copy['faqs'] ?? [];
    $jsonLdGraph = [
        Seo::organizationSchema($seoDescription, $lang),
        Seo::websiteSchema($seoDescription),
        Seo::webPageSchema($copy['h1'], $seoDescription, Seo::pageCanonical($baseCanonical, $lang), $lang),
        Seo::breadcrumbSchema([
            ['name' => __('blog_nav_home'), 'url' => Seo::absoluteUrl(home_path())],
            ['name' => __('buy_nav'), 'url' => Seo::absoluteUrl(GoogleAcquisition::hubUrl())],
            ['name' => $copy['h1'], 'url' => $baseCanonical],
        ], $lang),
        Seo::productOfferSchema($copy['h1'], $seoDescription, Seo::pageCanonical($baseCanonical, $lang), [
            'lowPrice' => $lowPrice,
            'offerCount' => max(1, count($services)),
        ]),
        Seo::howToSchema($copy['h1'], $seoDescription, $copy['how'] ?? [], $lang),
    ];
    if ($faqItems !== []) {
        $jsonLdGraph[] = Seo::faqSchema($faqItems, $lang);
    }
}

$registerQs = ['utm_campaign' => $page['slug'] ?? 'buy-hub'];
$ctaRegister = $loggedIn ? path('services.php') : register_path($registerQs);
$extraCssHrefs = [asset_url('assets/css/buy-public.css')];
?>
<!DOCTYPE html>
<html lang="<?= h(Seo::htmlLang($lang)) ?>">
<head>
<?php require __DIR__ . '/partials/public-seo-head.php'; ?>
</head>
<body>
<script>(function(){var k='smmturk_theme',d=localStorage.getItem(k)==='dark'||(!localStorage.getItem(k)&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);if(d)document.body.classList.add('theme-dark');})();</script>
<?php if ($promoBar['enabled']): ?>
<div class="growth-promo-bar">
    <span><?= h($promoBar['text']) ?></span>
    <a href="<?= h($promoBar['cta_url']) ?>"><?= h($promoBar['cta_label']) ?></a>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials/landing-nav.php'; ?>

<main class="buy-public" id="main-content">
<?php if ($page === null): ?>
    <header class="buy-hero">
        <p class="section-label"><?= h(__('buy_nav')) ?></p>
        <h1><?= h($copy['h1']) ?></h1>
        <p class="buy-lead"><?= h($copy['intro']) ?></p>
        <div class="pricing-hero-cta">
            <a href="<?= h($ctaRegister) ?>" class="btn-cta"><?= h(__('buy_cta_register')) ?></a>
            <a href="<?= h(path('pricing.php')) ?>" class="btn-cta-outline"><?= h(__('nav_prices')) ?></a>
        </div>
        <div class="stats-row" style="margin-top:28px;">
            <div class="stat-item"><div class="stat-value"><?= h($stats['services']) ?></div><div class="stat-label"><?= h(__('pricing_stat_services')) ?></div></div>
            <div class="stat-item"><div class="stat-value"><?= h($stats['orders']) ?></div><div class="stat-label"><?= h(__('pricing_stat_orders')) ?></div></div>
            <div class="stat-item"><div class="stat-value"><?= h($stats['min_price']) ?></div><div class="stat-label"><?= h(__('pricing_stat_from')) ?></div></div>
        </div>
    </header>
    <section class="buy-grid-section" aria-labelledby="buy-hub-list">
        <h2 id="buy-hub-list" class="section-title"><?= h(__('buy_hub_list_title')) ?></h2>
        <p class="section-desc"><?= h(__('buy_hub_list_desc')) ?></p>
        <div class="buy-card-grid">
            <?php foreach ($catalog as $item):
                $itemCopy = GoogleAcquisition::copy($item['slug'], $lang);
                $pKey = platformKeyFromCategory($item['platform']);
            ?>
            <a class="buy-card" href="<?= h(GoogleAcquisition::pageUrl($item['slug'])) ?>">
                <h3><?= platformSvgBrand($pKey, 22) ?> <?= h($itemCopy['h1']) ?></h3>
                <p><?= h($itemCopy['desc']) ?></p>
                <span><?= h(__('buy_open_page')) ?> →</span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php else:
    $pKey = platformKeyFromCategory($page['platform']);
?>
    <nav class="buy-crumbs" aria-label="Breadcrumb">
        <a href="<?= h(home_path()) ?>"><?= h(__('blog_nav_home')) ?></a>
        <span aria-hidden="true">/</span>
        <a href="<?= h(GoogleAcquisition::hubUrl()) ?>"><?= h(__('buy_nav')) ?></a>
        <span aria-hidden="true">/</span>
        <span><?= h($copy['h1']) ?></span>
    </nav>
    <header class="buy-hero">
        <p class="section-label"><?= platformSvgBrand($pKey, 18) ?> <?= h($page['platform']) ?></p>
        <h1><?= h($copy['h1']) ?></h1>
        <p class="buy-lead"><?= h($copy['intro']) ?></p>
        <p class="buy-from"><?= h(sprintf(__('buy_from_price'), '$' . number_format($lowPrice, 4) . '/1K')) ?></p>
        <div class="pricing-hero-cta">
            <a href="<?= h($ctaRegister) ?>" class="btn-cta"><?= h(__('buy_cta_register')) ?></a>
            <a href="<?= h(path('login.php')) ?>" class="btn-cta-outline"><?= h(__('pricing_cta_login')) ?></a>
        </div>
    </header>

    <article class="buy-article">
        <?php foreach ($copy['body'] as $block): ?>
        <h2><?= h($block['h']) ?></h2>
        <p><?= h($block['p']) ?></p>
        <?php endforeach; ?>
    </article>

    <?php if (!empty($copy['how'])): ?>
    <section class="buy-how" aria-labelledby="buy-how-title">
        <h2 id="buy-how-title" class="section-title"><?= h(__('buy_how_title')) ?></h2>
        <ol class="buy-how-list">
            <?php foreach ($copy['how'] as $i => $step): ?>
            <li>
                <span class="buy-how-num"><?= $i + 1 ?></span>
                <div>
                    <strong><?= h($step['name']) ?></strong>
                    <p><?= h($step['text']) ?></p>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php endif; ?>

    <section class="buy-prices" aria-labelledby="buy-prices-title">
        <h2 id="buy-prices-title" class="section-title"><?= h(__('buy_live_prices')) ?></h2>
        <p class="section-desc"><?= h(__('buy_live_prices_desc')) ?></p>
        <?php if ($services === []): ?>
        <p class="buy-empty" role="status"><?= h(__('buy_prices_empty')) ?></p>
        <?php else: ?>
        <div class="pricing-table-wrap">
            <table class="pricing-table">
                <thead>
                    <tr>
                        <th><?= h(__('pricing_th_id')) ?></th>
                        <th><?= h(__('pricing_th_service')) ?></th>
                        <th><?= h(__('pricing_th_rate')) ?></th>
                        <th><?= h(__('pricing_th_min')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($services as $s): ?>
                    <tr>
                        <td><?= (int) ($s['service_id'] ?? 0) ?></td>
                        <td><?= h(mb_substr((string) ($s['name'] ?? ''), 0, 80)) ?></td>
                        <td><strong>$<?= number_format((float) $s['retail_rate'], 4) ?></strong></td>
                        <td><?= number_format((int) ($s['min'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <p class="buy-signup-note"><?= h(__('buy_signup_note')) ?></p>
        <a href="<?= h($ctaRegister) ?>" class="btn-cta"><?= h(__('buy_cta_register')) ?></a>
    </section>

    <?php if (!empty($copy['faqs'])): ?>
    <section class="buy-faq" aria-labelledby="buy-faq-title">
        <h2 id="buy-faq-title" class="section-title"><?= h(__('buy_faq_title')) ?></h2>
        <div class="faq-list">
            <?php foreach ($copy['faqs'] as $faq): ?>
            <div class="faq-item">
                <button type="button" class="faq-q" aria-expanded="false"><?= h($faq['name']) ?> <span aria-hidden="true">+</span></button>
                <div class="faq-a"><?= h($faq['text']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="buy-related" aria-labelledby="buy-related-title">
        <h2 id="buy-related-title" class="section-title"><?= h(__('buy_related')) ?></h2>
        <div class="buy-related-links">
            <?php foreach ($page['related'] as $rel):
                $relPage = GoogleAcquisition::findPage($rel);
                if (!$relPage) {
                    continue;
                }
                $relCopy = GoogleAcquisition::copy($rel, $lang);
            ?>
            <a href="<?= h(GoogleAcquisition::pageUrl($rel)) ?>"><?= h($relCopy['h1']) ?></a>
            <?php endforeach; ?>
            <a href="<?= h(path('pricing.php')) ?>"><?= h(__('nav_prices')) ?></a>
            <a href="<?= h(path('blog.php')) ?>"><?= h(__('blog_nav_blog')) ?></a>
            <a href="<?= h(path('earn.php')) ?>"><?= h(__('nav_earn')) ?></a>
        </div>
    </section>
<?php endif; ?>
</main>

<?php require __DIR__ . '/partials/landing-footer.php'; ?>
<script src="<?= h(asset_url('assets/js/landing.js')) ?>" defer></script>
<?php require __DIR__ . '/partials/a11y.php'; ?>
</body>
</html>
