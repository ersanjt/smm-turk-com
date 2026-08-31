<?php
/**
 * Shared SEO <head> for public landing pages (home-style).
 *
 * Required: $seoTitle, $seoDescription, $baseCanonical, $lang
 * Optional: $seoOgTitle, $seoOgDescription, $jsonLdGraph, $metaKeywords, $extraCssHrefs, $seoIndexable
 */
$siteName = Seo::siteName();
$seoOgTitle = $seoOgTitle ?? $seoTitle;
$seoOgDescription = $seoOgDescription ?? $seoDescription;
$seoIndexable = $seoIndexable ?? true;
$extraCssHrefs = $extraCssHrefs ?? [];
$pageImg = $pageImg ?? og_image_url();
if ($pageImg !== '' && !preg_match('#^https?://#i', $pageImg)) {
    $pageImg = Seo::absoluteUrl($pageImg);
}
$canonicalUrl = Seo::pageCanonical($baseCanonical, $lang);
$ogLocale = Seo::ogLocale($lang);
$hreflangBase = Seo::stripLangParam($baseCanonical);
?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($seoTitle) ?> — <?= h($siteName) ?></title>
    <meta name="description" content="<?= h($seoDescription) ?>">
    <?php if (!empty($metaKeywords)): ?><meta name="keywords" content="<?= h($metaKeywords) ?>"><?php endif; ?>
    <meta name="robots" content="<?= h(Seo::robotsContent($seoIndexable)) ?>">
    <?php if ($canonicalUrl !== ''): ?><link rel="canonical" href="<?= h($canonicalUrl) ?>"><?php endif; ?>
    <?php if ($seoIndexable && $hreflangBase !== ''): ?>
    <?= Seo::hreflangTags($hreflangBase) ?>
    <?php endif; ?>
    <?= Seo::verificationMeta() ?>
    <meta name="theme-color" content="#E30A17">
    <?= Seo::geoMetaTags($lang) ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= h($siteName) ?>">
    <meta property="og:title" content="<?= h($seoOgTitle) ?> — <?= h($siteName) ?>">
    <meta property="og:description" content="<?= h($seoOgDescription) ?>">
    <meta property="og:url" content="<?= h($canonicalUrl) ?>">
    <meta property="og:image" content="<?= h($pageImg) ?>">
    <meta property="og:image:alt" content="<?= h($seoOgTitle) ?> — <?= h($siteName) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="<?= h($ogLocale) ?>">
    <?= Seo::ogLocaleAlternates($lang) ?>
    <meta name="twitter:card" content="summary_large_image">
    <?= Seo::twitterSiteMeta() ?>
    <meta name="twitter:title" content="<?= h($seoOgTitle) ?> — <?= h($siteName) ?>">
    <meta name="twitter:description" content="<?= h($seoOgDescription) ?>">
    <meta name="twitter:image" content="<?= h($pageImg) ?>">
    <meta name="twitter:image:alt" content="<?= h($seoOgTitle) ?> — <?= h($siteName) ?>">
    <?php echo_favicon_links(); ?>
    <link rel="manifest" href="<?= h(path('manifest.php')) ?>">
    <?php if (!empty($jsonLdGraph)): ?>
    <script type="application/ld+json"><?= Seo::jsonLd($jsonLdGraph) ?></script>
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/landing.css')) ?>">
    <link rel="preload" href="<?= h(asset_url('assets/css/pricing-public.css')) ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="preload" href="<?= h(asset_url('assets/css/ui-pro.css')) ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript>
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/pricing-public.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/ui-pro.css')) ?>">
    </noscript>
    <?php foreach ($extraCssHrefs as $cssHref): ?>
    <link rel="stylesheet" href="<?= h($cssHref) ?>">
    <?php endforeach; ?>
