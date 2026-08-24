<?php
/**
 * Public Help Center — SEO-friendly guides (no login required).
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';

$lang = Lang::initPublic();
$siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
$siteUrl  = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$helpPath = path('help.php');
$canonicalUrl = $siteUrl !== '' ? Seo::absoluteUrl($helpPath) : $helpPath;

$pageTitle = __('help_title');
$pageDescription = __('help_meta_desc');
$blogNavActive = 'help';
$bodyClassExtra = 'help-page';
$seoHreflang = true;
$seoHreflangBase = $canonicalUrl;
$extraCssHref = asset_url('assets/css/help.css');

$jsonLdExtra = [
    Seo::breadcrumbSchema([
        ['name' => __('blog_nav_home'), 'url' => $siteUrl !== '' ? Seo::absoluteUrl(home_path()) : home_path()],
        ['name' => __('help_title'), 'url' => $canonicalUrl],
    ], $lang),
];

$helpSections = [
    [
        'id' => 'getting-started',
        'title' => __('help_section_start'),
        'body' => __('help_start_body'),
        'items' => [__('help_start_1'), __('help_start_2'), __('help_start_3')],
        'link' => register_path(),
        'link_label' => __('nav_sign_up'),
        'icon' => 'user',
    ],
    [
        'id' => 'orders',
        'title' => __('help_section_orders'),
        'body' => __('help_orders_body'),
        'items' => [__('help_orders_1'), __('help_orders_2'), __('help_orders_3')],
        'link' => login_next_path('dashboard.php'),
        'link_label' => __('help_quick_orders'),
        'icon' => 'cart',
    ],
    [
        'id' => 'payments',
        'title' => __('help_section_payments'),
        'body' => __('help_payments_body'),
        'items' => [__('help_payments_1'), __('help_payments_2')],
        'link' => login_next_path('add-funds.php'),
        'link_label' => __('help_quick_funds'),
        'icon' => 'wallet',
    ],
    [
        'id' => 'api',
        'title' => __('help_section_api'),
        'body' => __('help_api_body'),
        'items' => [],
        'link' => login_next_path('api-page.php'),
        'link_label' => __('footer_api'),
        'icon' => 'code',
    ],
    [
        'id' => 'earn',
        'title' => __('help_section_earn'),
        'body' => __('help_earn_body'),
        'items' => [__('help_earn_1'), __('help_earn_2'), __('help_earn_3')],
        'link' => path('earn.php'),
        'link_label' => __('help_earn_link'),
        'icon' => 'funds',
    ],
    [
        'id' => 'support',
        'title' => __('help_section_support'),
        'body' => __('help_support_body'),
        'items' => [],
        'link' => login_next_path('tickets.php'),
        'link_label' => __('help_quick_ticket'),
        'icon' => 'chat',
    ],
];

$platformGuides = [
    ['id' => 'instagram', 'title' => __('help_cluster_instagram'), 'body' => __('help_cluster_instagram_body')],
    ['id' => 'youtube', 'title' => __('help_cluster_youtube'), 'body' => __('help_cluster_youtube_body')],
    ['id' => 'tiktok', 'title' => __('help_cluster_tiktok'), 'body' => __('help_cluster_tiktok_body')],
    ['id' => 'reseller', 'title' => __('help_cluster_reseller'), 'body' => __('help_cluster_reseller_body'), 'link' => login_next_path('api-page.php')],
];

require_once __DIR__ . '/layouts/blog-header.php';

function help_icon(string $name): string
{
    $icons = [
        'user' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'cart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
        'wallet' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
        'code' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        'chat' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'register' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>',
        'funds' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'order' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        'ticket' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5v2M15 11v2M15 17v2"/><path d="M5 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4V9a2 2 0 0 0 0-4V5z"/></svg>',
    ];
    return $icons[$name] ?? $icons['user'];
}
?>

<header class="help-hero">
  <div class="help-hero-inner">
    <div class="help-hero-badge">💡 <?= h(__('help_hero_badge')) ?></div>
    <h1><?= h(__('help_title')) ?></h1>
    <p class="help-hero-intro"><?= h(__('help_intro')) ?></p>
    <div class="help-hero-stats">
      <div class="help-hero-stat"><strong><?= count($helpSections) + count($platformGuides) ?></strong><span><?= h(__('help_stat_topics')) ?></span></div>
      <div class="help-hero-stat"><strong>6</strong><span><?= h(__('help_stat_faq')) ?></span></div>
      <div class="help-hero-stat"><strong>24/7</strong><span><?= h(__('help_stat_support')) ?></span></div>
    </div>
  </div>
</header>

<main class="help-wrap" role="main">

  <p class="help-quick-label"><?= h(__('help_quick_title')) ?></p>
  <div class="help-quick">
    <a href="<?= h(register_path()) ?>" class="help-quick-card">
      <span class="help-quick-icon"><?= help_icon('register') ?></span>
      <strong><?= h(__('help_quick_register')) ?></strong>
      <span><?= h(__('help_quick_register_desc')) ?></span>
    </a>
    <a href="<?= h(login_next_path('add-funds.php')) ?>" class="help-quick-card">
      <span class="help-quick-icon"><?= help_icon('funds') ?></span>
      <strong><?= h(__('help_quick_funds')) ?></strong>
      <span><?= h(__('help_quick_funds_desc')) ?></span>
    </a>
    <a href="<?= h(login_next_path('dashboard.php')) ?>" class="help-quick-card">
      <span class="help-quick-icon"><?= help_icon('order') ?></span>
      <strong><?= h(__('help_quick_orders')) ?></strong>
      <span><?= h(__('help_quick_orders_desc')) ?></span>
    </a>
    <a href="<?= h(login_next_path('tickets.php')) ?>" class="help-quick-card">
      <span class="help-quick-icon"><?= help_icon('ticket') ?></span>
      <strong><?= h(__('help_quick_ticket')) ?></strong>
      <span><?= h(__('help_quick_ticket_desc')) ?></span>
    </a>
  </div>

  <div class="help-layout">
    <aside class="help-sidebar" aria-label="<?= h(__('help_toc_label')) ?>">
      <div class="help-sidebar-title"><?= h(__('help_sidebar_hint')) ?></div>
      <nav>
        <?php foreach ($helpSections as $sec): ?>
        <a href="#<?= h($sec['id']) ?>"><?= h($sec['title']) ?></a>
        <?php endforeach; ?>
        <a href="#platforms"><?= h(__('help_platforms_title')) ?></a>
        <a href="#faq"><?= h(__('faq_title')) ?></a>
      </nav>
    </aside>

    <div class="help-main">
      <?php foreach ($helpSections as $sec): ?>
      <section id="<?= h($sec['id']) ?>" class="help-section">
        <div class="help-section-head">
          <div class="help-section-icon"><?= help_icon($sec['icon']) ?></div>
          <h2><?= h($sec['title']) ?></h2>
        </div>
        <p><?= h($sec['body']) ?></p>
        <?php if (!empty($sec['items'])): ?>
        <ul>
          <?php foreach ($sec['items'] as $item): ?>
          <li><?= h($item) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if (!empty($sec['link'])): ?>
        <a href="<?= h($sec['link']) ?>" class="help-section-link"><?= h($sec['link_label']) ?> →</a>
        <?php endif; ?>
      </section>
      <?php endforeach; ?>

      <h2 id="platforms" class="help-platforms-title"><?= h(__('help_platforms_title')) ?></h2>
      <div class="help-platforms">
        <?php foreach ($platformGuides as $pg): ?>
        <article id="<?= h($pg['id']) ?>" class="help-platform">
          <h3><?= h($pg['title']) ?></h3>
          <p><?= h($pg['body']) ?></p>
          <?php if (!empty($pg['link'])): ?>
          <a href="<?= h($pg['link']) ?>" class="help-section-link" style="margin-top:10px;"><?= h(__('footer_api')) ?> →</a>
          <?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>

      <section id="faq" class="help-faq-section">
        <h2><?= h(__('faq_title')) ?></h2>
        <div class="help-faq">
          <?php for ($i = 1; $i <= 6; $i++): ?>
          <details class="help-faq-item">
            <summary><?= h(__('faq_' . $i)) ?></summary>
            <p><?= h(__('faq_' . $i . '_a')) ?></p>
          </details>
          <?php endfor; ?>
        </div>
      </section>
    </div>
  </div>

  <div class="help-cta">
    <h2><?= h(__('help_cta_title')) ?></h2>
    <p><?= h(__('help_cta_body')) ?></p>
    <a href="<?= h(register_path()) ?>" class="help-cta-btn"><?= h(__('help_cta_btn')) ?></a>
  </div>

</main>

<script>
(function () {
  var links = document.querySelectorAll('.help-sidebar nav a[href^="#"]');
  if (!links.length || !('IntersectionObserver' in window)) return;
  var map = {};
  links.forEach(function (a) {
    var id = a.getAttribute('href').slice(1);
    var el = document.getElementById(id);
    if (el) map[id] = a;
  });
  var obs = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      links.forEach(function (a) { a.classList.remove('is-active'); });
      var link = map[e.target.id];
      if (link) {
        link.classList.add('is-active');
        link.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
      }
    });
  }, { rootMargin: '-30% 0px -55% 0px', threshold: 0 });
  Object.keys(map).forEach(function (id) {
    var el = document.getElementById(id);
    if (el) obs.observe(el);
  });
})();
</script>

<?php require_once __DIR__ . '/layouts/blog-footer.php'; ?>
