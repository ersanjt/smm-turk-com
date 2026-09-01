<?php
/**
 * Crawlable internal links to commercial /buy pages (Google referring pages).
 */
if (!class_exists('GoogleAcquisition', false)) {
    return;
}
$buyLinkLang = $lang ?? (class_exists('Lang', false) ? Lang::current() : 'tr');
$buyLinkSlugs = ['cheap-smm-panel', 'instagram-followers', 'tiktok-views', 'youtube-views'];
$buyPanelCopy = GoogleAcquisition::copy('cheap-smm-panel', $buyLinkLang);
?>
<nav class="public-buy-links" aria-label="<?= h(__('buy_nav')) ?>">
    <a class="public-buy-links-lead" href="<?= h(GoogleAcquisition::pageUrl('cheap-smm-panel')) ?>"><?= h($buyPanelCopy['h1']) ?></a>
    <ul>
        <?php foreach ($buyLinkSlugs as $buyLinkSlug):
            $buyLinkCopy = GoogleAcquisition::copy($buyLinkSlug, $buyLinkLang); ?>
        <li><a href="<?= h(GoogleAcquisition::pageUrl($buyLinkSlug)) ?>"><?= h($buyLinkCopy['h1']) ?></a></li>
        <?php endforeach; ?>
        <li><a href="<?= h(GoogleAcquisition::hubUrl()) ?>"><?= h(__('buy_nav')) ?></a></li>
    </ul>
</nav>
