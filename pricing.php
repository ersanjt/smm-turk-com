<?php
/**
 * Public pricing page — SEO traffic from Google ("cheap instagram followers", etc.)
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';
require_once __DIR__ . '/app/PlatformIcons.php';

if ($auth->isLoggedIn()) {
    redirect(url('services.php'));
}

$lang = Lang::initPublic();
$db = Database::getInstance();
$growth = new GrowthEngine();
$stats = $growth->publicStats();
$offers = $growth->offerLines();
$highlights = $growth->pricingHighlights(2);
$table = $growth->pricingTable(36);

$siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
$baseCanonical = Seo::absoluteUrl(path('pricing.php'));
$minPrice = preg_replace('/[^0-9.]/', '', $stats['min_price']);
$seoTitle = __('pricing_meta_title');
$seoDescription = sprintf(__('pricing_meta_desc'), '$' . $minPrice);
$metaKeywords = __('pricing_keywords');
$jsonLdGraph = [
    Seo::organizationSchema($seoDescription, $lang),
    Seo::websiteSchema($seoDescription),
    Seo::webPageSchema(__('pricing_h1'), $seoDescription, Seo::pageCanonical($baseCanonical, $lang), $lang),
    Seo::breadcrumbSchema([
        ['name' => __('blog_nav_home'), 'url' => Seo::absoluteUrl(home_path())],
        ['name' => __('nav_prices'), 'url' => $baseCanonical],
    ], $lang),
];
?>
<!DOCTYPE html>
<html lang="<?= h(Seo::htmlLang($lang)) ?>">
<head>
<?php require __DIR__ . '/partials/public-seo-head.php'; ?>
</head>
<body>
<script>(function(){var k='smmturk_theme',d=localStorage.getItem(k)==='dark'||(!localStorage.getItem(k)&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);if(d)document.body.classList.add('theme-dark');})();</script>
<?php $promo = $growth->promoBar(); if ($promo['enabled']): ?>
<div class="growth-promo-bar">
    <span><?= h($promo['text']) ?></span>
    <a href="<?= h($promo['cta_url']) ?>"><?= h($promo['cta_label']) ?></a>
</div>
<?php endif; ?>

<?php $navActive = 'pricing'; $registrationEnabled = ($db->getSetting('registration_enabled') ?? '1') === '1'; require __DIR__ . '/partials/landing-nav.php'; ?>

<main class="pricing-public">
    <section class="pricing-hero">
        <h1><?= h(__('pricing_h1')) ?></h1>
        <p><?= h(sprintf(__('pricing_intro'), count($offers))) ?></p>
        <ul class="pricing-offers">
            <?php foreach ($offers as $line): ?><li>✓ <?= h($line) ?></li><?php endforeach; ?>
        </ul>
        <div class="pricing-hero-cta">
            <a href="<?= h(register_path()) ?>" class="btn-cta"><?= h(__('pricing_cta_register')) ?></a>
            <a href="<?= h(path('login.php')) ?>" class="btn-cta-outline"><?= h(__('pricing_cta_login')) ?></a>
        </div>
        <div class="stats-row" style="margin-top:32px;">
            <div class="stat-item"><div class="stat-value"><?= h($stats['services']) ?></div><div class="stat-label"><?= h(__('pricing_stat_services')) ?></div></div>
            <div class="stat-item"><div class="stat-value"><?= h($stats['orders']) ?></div><div class="stat-label"><?= h(__('pricing_stat_orders')) ?></div></div>
            <div class="stat-item"><div class="stat-value"><?= h($stats['min_price']) ?></div><div class="stat-label"><?= h(__('pricing_stat_from')) ?></div></div>
        </div>
    </section>

    <?php if (!empty($highlights)): ?>
    <section class="section" style="padding-top:20px;">
        <h2 class="section-title"><?= h(__('pricing_platforms_title')) ?></h2>
        <div class="pricing-platform-grid">
            <?php
            $byPlatform = [];
            foreach ($highlights as $s) {
                $byPlatform[$s['platform']][] = $s;
            }
            foreach ($byPlatform as $platform => $items):
                $pKey = platformKeyFromCategory($platform);
            ?>
            <div class="pricing-platform-card">
                <h3><?= platformSvgBrand($pKey, 20) ?> <?= h($platform) ?></h3>
                <ul>
                    <?php foreach ($items as $s): ?>
                    <li>
                        <span><?= h(mb_substr($s['name'], 0, 48)) ?></span>
                        <strong>$<?= number_format((float) $s['retail_rate'], 4) ?>/1k</strong>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="section" style="background:var(--white);">
        <h2 class="section-title"><?= h(__('pricing_table_title')) ?></h2>
        <p class="section-desc"><?= h(__('pricing_table_desc')) ?></p>
        <div class="pricing-table-wrap">
            <table class="pricing-table">
                <thead><tr><th><?= h(__('pricing_th_id')) ?></th><th><?= h(__('pricing_th_service')) ?></th><th><?= h(__('pricing_th_category')) ?></th><th><?= h(__('pricing_th_rate')) ?></th><th><?= h(__('pricing_th_min')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($table as $s): ?>
                <tr>
                    <td><?= (int) $s['service_id'] ?></td>
                    <td><?= h(mb_substr($s['name'], 0, 70)) ?></td>
                    <td><?= h($s['category'] ?? '') ?></td>
                    <td><strong>$<?= number_format((float) $s['retail_rate'], 4) ?></strong></td>
                    <td><?= number_format((int) $s['min']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="cta-block" style="margin-top:40px;">
            <h2 class="section-title"><?= h(__('pricing_cta_title')) ?></h2>
            <p class="section-desc"><?= h(__('pricing_cta_desc')) ?></p>
            <a href="<?= h(register_path()) ?>" class="btn-cta"><?= h(__('pricing_cta_btn')) ?></a>
        </div>
    </section>
</main>

<footer class="footer" style="padding:24px;text-align:center;font-size:13px;color:var(--muted);">
    <a href="<?= h(home_path()) ?>"><?= h($siteName) ?></a> · <a href="<?= h(path('terms.php')) ?>"><?= h(__('nav_terms')) ?></a> · <a href="<?= h(path('help.php')) ?>"><?= h(__('help_title')) ?></a>
</footer>
<script src="<?= h(asset_url('assets/js/landing.js')) ?>" defer></script>
<?php require __DIR__ . '/partials/a11y.php'; ?>
</body>
</html>
