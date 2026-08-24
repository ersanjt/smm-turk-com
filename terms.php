<?php
/**
 * Public Terms of Service — indexable, no login required.
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';

$lang = Lang::initPublic();
$siteName = Seo::siteName();
$siteUrl  = Seo::siteUrl();
$termsPath = path('terms.php');
$canonicalUrl = $siteUrl !== '' ? Seo::absoluteUrl($termsPath) : $termsPath;

$pageTitle = __('nav_terms');
$pageDescription = __('terms_meta_desc');
$blogNavActive = 'terms';
$seoHreflang = true;
$seoHreflangBase = $canonicalUrl;
$jsonLd = Seo::breadcrumbSchema([
    ['name' => __('blog_nav_home'), 'url' => $siteUrl !== '' ? Seo::absoluteUrl(home_path()) : home_path()],
    ['name' => $pageTitle, 'url' => $canonicalUrl],
], $lang);

$extraCssHref = asset_url('assets/css/terms.css');
$bodyClassExtra = 'terms-page';

$termsSections = [
    ['icon' => 'server', 'title' => __('terms_s1_title'), 'body' => __('terms_s1_body')],
    ['icon' => 'wallet', 'title' => __('terms_s2_title'), 'body' => __('terms_s2_body')],
    ['icon' => 'clipboard', 'title' => __('terms_s3_title'), 'body' => __('terms_s3_body')],
    ['icon' => 'shield', 'title' => __('terms_s4_title'), 'body' => __('terms_s4_body')],
    ['icon' => 'message', 'title' => __('terms_s5_title'), 'body' => $auth->isLoggedIn() ? __('terms_s5_body_in') : __('terms_s5_body_out')],
];

require_once __DIR__ . '/layouts/blog-header.php';
?>

<header class="terms-hero">
  <div class="terms-hero-inner">
    <nav class="terms-breadcrumb" aria-label="Breadcrumb">
      <a href="<?= h(home_path()) ?>"><?= h(__('blog_nav_home')) ?></a> <span>·</span> <?= h($pageTitle) ?>
    </nav>
    <div class="terms-hero-badge">📄 <?= h(__('terms_hero_badge')) ?></div>
    <h1><?= h($pageTitle) ?></h1>
    <p class="terms-hero-intro"><?= h(sprintf(__('terms_intro'), $siteName)) ?></p>
    <p class="terms-updated"><?= h(__('terms_updated')) ?>: <?= h(date('Y-m-d', filemtime(__FILE__))) ?></p>
  </div>
</header>

<main class="terms-wrap" role="main">
  <ol class="terms-list">
    <?php foreach ($termsSections as $i => $sec): ?>
    <li class="terms-card">
      <div class="terms-card-num"><?= $i + 1 ?></div>
      <div class="terms-card-body">
        <div class="terms-card-head">
          <span class="terms-card-icon"><?= icon($sec['icon'], 20) ?></span>
          <h2><?= h($sec['title']) ?></h2>
        </div>
        <p><?= h($sec['body']) ?></p>
      </div>
    </li>
    <?php endforeach; ?>
  </ol>

  <div class="terms-footer-actions">
    <a href="<?= h(home_path()) ?>" class="terms-back-link">← <?= h(__('blog_nav_home')) ?></a>
    <a href="<?= h(register_path()) ?>" class="terms-agree-btn"><?= h(__('nav_sign_up')) ?> →</a>
  </div>
</main>

<?php require_once __DIR__ . '/layouts/blog-footer.php'; ?>
