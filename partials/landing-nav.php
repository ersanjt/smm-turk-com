<?php
/**
 * Public landing header — desktop menu + mobile drawer.
 *
 * Optional: $navActive (pricing|earn|blog|help|terms|login), $registrationEnabled, $lang, $siteName
 */
if (!isset($registrationEnabled)) {
    $registrationEnabled = (Database::getInstance()->getSetting('registration_enabled') ?? '1') === '1';
}
if (!isset($lang)) {
    $lang = class_exists('Lang', false) ? Lang::current() : 'tr';
}
if (!isset($siteName)) {
    $siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
}
$navActive = $navActive ?? '';

$navItems = [
    ['id' => 'login', 'href' => route_path('login.php'), 'label' => __('nav_sign_in')],
    ['id' => 'pricing', 'href' => path('pricing.php'), 'label' => __('nav_prices')],
    ['id' => 'earn', 'href' => path('earn.php'), 'label' => __('nav_earn')],
    ['id' => 'blog', 'href' => path('blog.php'), 'label' => __('blog_nav_blog')],
    ['id' => 'help', 'href' => path('help.php'), 'label' => __('help_title')],
    ['id' => 'terms', 'href' => path('terms.php'), 'label' => __('nav_terms')],
];
$ctaHref = $registrationEnabled ? register_path() : route_path('login.php');
$ctaLabel = $registrationEnabled ? __('nav_sign_up') : __('nav_sign_in');
?>
<header class="nav" role="banner">
    <div class="nav-inner">
        <a href="<?= h(home_path()) ?>" class="nav-logo" aria-label="<?= h($siteName) ?> Home">
            <span class="nav-logo-icon"><img src="<?= h(logo_url()) ?>" alt="" width="44" height="44" fetchpriority="high"></span>
            <span class="nav-logo-copy">
                <span class="nav-logo-text"><?= site_name_logo_html() ?></span>
                <span class="nav-logo-tag">SMM Panel</span>
            </span>
        </a>

        <nav class="nav-menu" aria-label="<?= h(__('footer_quick_links') ?: 'Main navigation') ?>">
            <?php foreach ($navItems as $item): ?>
            <a href="<?= h($item['href']) ?>" class="nav-menu-link<?= $navActive === $item['id'] ? ' is-active' : '' ?>"><?= h($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="nav-actions">
            <button type="button" class="nav-theme-toggle" id="themeToggle" aria-label="Toggle dark mode" title="Toggle theme">
                <span class="theme-icon theme-icon-light" aria-hidden="true"><?= icon('sun', 18) ?></span>
                <span class="theme-icon theme-icon-dark" aria-hidden="true"><?= icon('moon', 18) ?></span>
            </button>
            <div class="nav-lang nav-lang-compact">
                <button type="button" class="nav-lang-btn" id="langBtn" aria-haspopup="true" aria-expanded="false" aria-controls="langDropdown">
                    <?= strtoupper($lang) ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="nav-lang-dropdown" id="langDropdown" role="menu">
                    <?php foreach (Lang::allowed() as $l): ?>
                    <a href="<?= h(Lang::urlFor($l)) ?>" role="menuitem"<?= $lang === $l ? ' class="is-active"' : '' ?>><?= h(Lang::label($l)) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <a href="<?= h($ctaHref) ?>" class="nav-btn"><?= h($ctaLabel) ?> →</a>
        </div>

        <a href="<?= h($ctaHref) ?>" class="nav-cta-mobile"><?= h($ctaLabel) ?></a>

        <button type="button" class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="navMobilePanel">
            <span class="nav-toggle-bar" aria-hidden="true"></span>
            <span class="nav-toggle-bar" aria-hidden="true"></span>
            <span class="nav-toggle-bar" aria-hidden="true"></span>
        </button>
    </div>

    <div class="nav-mobile" id="navMobilePanel" aria-hidden="true">
        <button type="button" class="nav-mobile-backdrop" id="navBackdrop" aria-label="Close menu" tabindex="-1"></button>
        <div class="nav-mobile-sheet" role="dialog" aria-modal="true" aria-label="<?= h(__('footer_quick_links') ?: 'Menu') ?>">
            <div class="nav-mobile-head">
                <a href="<?= h(home_path()) ?>" class="nav-mobile-brand">
                    <img src="<?= h(logo_url()) ?>" alt="" width="36" height="36">
                    <span>SMM <strong>TURK</strong></span>
                </a>
                <button type="button" class="nav-mobile-close" id="navClose" aria-label="Close menu">
                    <?= icon('x', 22) ?>
                </button>
            </div>
            <nav class="nav-mobile-links" aria-label="<?= h(__('footer_quick_links') ?: 'Main navigation') ?>">
                <?php foreach ($navItems as $item): ?>
                <a href="<?= h($item['href']) ?>" class="nav-mobile-link<?= $navActive === $item['id'] ? ' is-active' : '' ?>"><?= h($item['label']) ?></a>
                <?php endforeach; ?>
                <?php if ($registrationEnabled): ?>
                <a href="<?= h(register_path()) ?>" class="nav-mobile-link nav-mobile-link-accent"><?= h(__('nav_sign_up')) ?></a>
                <?php endif; ?>
            </nav>
            <div class="nav-mobile-tools">
                <span class="nav-mobile-tools-label"><?= h(__('blog_nav_home') ?: 'Preferences') ?></span>
                <div class="nav-mobile-tools-row">
                    <button type="button" class="nav-mobile-theme" id="themeToggleMobile" aria-label="Toggle dark mode">
                        <span class="theme-icon theme-icon-light"><?= icon('sun', 18) ?></span>
                        <span class="theme-icon theme-icon-dark"><?= icon('moon', 18) ?></span>
                        <span>Theme</span>
                    </button>
                    <div class="nav-mobile-langs">
                        <?php foreach (Lang::allowed() as $l): ?>
                        <a href="<?= h(Lang::urlFor($l)) ?>" class="nav-mobile-lang<?= $lang === $l ? ' is-active' : '' ?>"><?= strtoupper($l) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="nav-mobile-cta">
                <a href="<?= h(route_path('login.php')) ?>" class="nav-mobile-btn nav-mobile-btn-outline"><?= h(__('nav_sign_in')) ?></a>
                <a href="<?= h($ctaHref) ?>" class="nav-mobile-btn nav-mobile-btn-primary"><?= h($ctaLabel) ?> →</a>
            </div>
        </div>
    </div>
</header>
