<?php
/**
 * Public landing footer — desktop links + dedicated mobile tile grid + sticky action bar.
 *
 * Optional: $siteName, $footerIsChild (bool)
 */
$footerSiteName = $siteName ?? (function_exists('site_name') ? site_name() : 'SMM Turk');
$footerIsChild = $footerIsChild ?? (function_exists('is_child_panel') && is_child_panel());

$footerTiles = [
    ['href' => route_path('login.php'), 'label' => __('footer_login'), 'icon' => 'logout'],
    ['href' => register_path(), 'label' => __('footer_signup'), 'icon' => 'plus'],
    ['href' => path('blog.php'), 'label' => __('blog_nav_blog'), 'icon' => 'message'],
    ['href' => path('help.php'), 'label' => __('help_nav'), 'icon' => 'info'],
    ['href' => path('terms.php'), 'label' => __('nav_terms'), 'icon' => 'clipboard'],
];
if (!$footerIsChild) {
    $footerTiles[] = ['href' => login_next_path('api-page.php'), 'label' => __('footer_api'), 'icon' => 'api'];
}
$footerTiles[] = ['href' => path('help.php'), 'label' => __('footer_support'), 'icon' => 'tickets'];
?>
<nav class="mob-footer-bar" aria-label="<?= h(__('footer_login')) ?>">
    <a href="<?= h(route_path('login.php')) ?>" class="mob-footer-btn mob-footer-btn-outline"><?= h(__('footer_login')) ?></a>
    <a href="<?= h(register_path()) ?>" class="mob-footer-btn mob-footer-btn-primary"><?= h(__('footer_signup')) ?> →</a>
</nav>

<footer class="footer" role="contentinfo">
    <div class="footer-desktop">
        <div class="footer-links">
            <?php foreach ($footerTiles as $tile): ?>
            <a href="<?= h($tile['href']) ?>"><?= h($tile['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="footer-copy">© <?= date('Y') ?> <?= h($footerSiteName) ?>. <?= h(__('footer_copyright')) ?>.</div>
    </div>

    <div class="footer-mobile">
        <a href="<?= h(home_path()) ?>" class="footer-mobile-brand">
            <img src="<?= h(logo_url()) ?>" alt="" width="40" height="40" loading="lazy">
            <span class="footer-mobile-name"><?= site_name_logo_html() ?></span>
        </a>
        <h2 class="footer-mobile-title"><?= h(__('footer_quick_links')) ?></h2>
        <nav class="footer-mobile-grid" aria-label="<?= h(__('footer_quick_links')) ?>">
            <?php foreach ($footerTiles as $tile): ?>
            <a href="<?= h($tile['href']) ?>" class="footer-mobile-tile">
                <span class="footer-mobile-tile-ico"><?= icon($tile['icon'], 20) ?></span>
                <span class="footer-mobile-tile-label"><?= h($tile['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <p class="footer-copy">© <?= date('Y') ?> <?= h($footerSiteName) ?>. <?= h(__('footer_copyright')) ?>.</p>
    </div>
</footer>
