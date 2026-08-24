<?php
if (!class_exists('Lang', false)) {
    require_once dirname(__DIR__) . '/app/Lang.php';
}
if (!isset($pageTitle)) $pageTitle = 'Blog';
if (!isset($pageDescription)) $pageDescription = 'SMM Turk Blog — Tips for social media growth, SMM panel guides, Instagram, YouTube, TikTok marketing.';
$siteName = Seo::siteName();
$siteUrl  = Seo::siteUrl();
$canonicalUrl = $canonicalUrl ?? ($siteUrl !== '' ? Seo::absoluteUrl(path('blog.php')) : path('blog.php'));
$pageImg  = $pageImage ?? og_image_url();
if ($pageImg !== '' && !preg_match('#^https?://#i', $pageImg)) {
    $pageImg = Seo::absoluteUrl($pageImg);
}
$lang = isset($lang) ? $lang : (class_exists('Lang', false) ? Lang::current() : 'tr');
$ogType = $ogType ?? 'website';
$ogLocale = Seo::ogLocale($lang);
$articlePublished = $articlePublished ?? null;
$articleModified = $articleModified ?? null;
$articleSection = $articleSection ?? null;
$articleTags = $articleTags ?? [];
$paginationPrev = $paginationPrev ?? null;
$paginationNext = $paginationNext ?? null;
$blogNavActive = $blogNavActive ?? '';
$blogHero = $blogHero ?? true;
$seoIndexable = $seoIndexable ?? true;
$seoHreflang = ($seoHreflang ?? true) && $seoIndexable;
$hreflangBase = Seo::stripLangParam($seoHreflangBase ?? $canonicalUrl);
$seoHreflangBase = $hreflangBase;
$canonicalUrl = Seo::pageCanonical($hreflangBase, $lang);
if ($paginationPrev) {
    $paginationPrev = Seo::pageCanonical($paginationPrev, $lang);
}
if ($paginationNext) {
    $paginationNext = Seo::pageCanonical($paginationNext, $lang);
}
$jsonLdExtra = $jsonLdExtra ?? [];
if (!empty($jsonLd)) {
    $jsonLdExtra[] = $jsonLd;
}
$schemaGraph = [
    Seo::organizationSchema($pageDescription, $lang),
    Seo::websiteSchema($pageDescription),
];
foreach ($jsonLdExtra as $block) {
    if (is_array($block)) {
        $schemaGraph[] = $block;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= h(Seo::htmlLang($lang)) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> — <?= h($siteName) ?></title>
    <meta name="description" content="<?= h($pageDescription) ?>">
    <meta name="robots" content="<?= h(Seo::robotsContent($seoIndexable)) ?>">
    <?php if (!empty($metaKeywords)): ?><meta name="keywords" content="<?= h($metaKeywords) ?>"><?php endif; ?>
    <link rel="canonical" href="<?= h($canonicalUrl) ?>">
    <?php if ($seoHreflang && $seoHreflangBase !== ''): ?>
    <?= Seo::hreflangTags($seoHreflangBase) ?>
    <?php endif; ?>
    <?= Seo::verificationMeta() ?>
    <?php if ($paginationPrev): ?><link rel="prev" href="<?= h($paginationPrev) ?>"><?php endif; ?>
    <?php if ($paginationNext): ?><link rel="next" href="<?= h($paginationNext) ?>"><?php endif; ?>
    <meta name="theme-color" content="#E30A17">
    <?= Seo::geoMetaTags($lang) ?>
    <meta property="og:type" content="<?= h($ogType) ?>">
    <meta property="og:site_name" content="<?= h($siteName) ?>">
    <meta property="og:title" content="<?= h($pageTitle) ?> — <?= h($siteName) ?>">
    <meta property="og:description" content="<?= h($pageDescription) ?>">
    <meta property="og:url" content="<?= h($canonicalUrl) ?>">
    <meta property="og:image" content="<?= h($pageImg) ?>">
    <meta property="og:locale" content="<?= h($ogLocale) ?>">
    <?= Seo::ogLocaleAlternates($lang) ?>
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <?php if ($ogType === 'article' && $articlePublished): ?>
    <meta property="article:published_time" content="<?= h(date('c', strtotime($articlePublished))) ?>">
    <?php endif; ?>
    <?php if ($ogType === 'article' && $articleModified): ?>
    <meta property="article:modified_time" content="<?= h(date('c', strtotime($articleModified))) ?>">
    <?php endif; ?>
    <?php if ($ogType === 'article' && $articleSection): ?>
    <meta property="article:section" content="<?= h($articleSection) ?>">
    <?php endif; ?>
    <?php foreach ($articleTags as $tagName): ?>
    <meta property="article:tag" content="<?= h($tagName) ?>">
    <?php endforeach; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($pageTitle) ?> — <?= h($siteName) ?>">
    <meta name="twitter:description" content="<?= h($pageDescription) ?>">
    <meta name="twitter:image" content="<?= h($pageImg) ?>">
    <link rel="icon" type="image/png" href="<?= h(logo_url()) ?>">
    <link rel="apple-touch-icon" href="<?= h(logo_url()) ?>">
    <link rel="manifest" href="<?= h(path('manifest.php')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/landing.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/ui-pro.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/blog.css')) ?>">
    <script type="application/ld+json"><?= Seo::jsonLd($schemaGraph) ?></script>
    <?php if (!empty($extraStyles)): ?><style><?= $extraStyles ?></style><?php endif; ?>
    <?php if (!empty($extraCssHref)): ?><link rel="stylesheet" href="<?= h($extraCssHref) ?>"><?php endif; ?>
</head>
<body class="blog-page<?= !empty($bodyClassExtra) ? ' ' . h($bodyClassExtra) : '' ?>" data-sw="<?= h(path('pwa-sw.php')) ?>" data-sw-scope="<?= h(base_path() !== '' ? base_path() . '/' : '/') ?>">
<script>(function(){var k='smmturk_theme',d=localStorage.getItem(k)==='dark'||(!localStorage.getItem(k)&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);if(d)document.body.classList.add('theme-dark');})();</script>
<?php
$navActive = in_array($blogNavActive, ['blog', 'help', 'pricing', 'earn', 'terms'], true) ? $blogNavActive : '';
require dirname(__DIR__) . '/partials/landing-nav.php';
?>
