<?php
$footerSiteName = $siteName ?? (function_exists('site_name') ? site_name() : 'SMM Turk');
$footerIsChild = function_exists('is_child_panel') && is_child_panel();
require __DIR__ . '/../partials/landing-footer.php';
?>

<script src="<?= h(asset_url('assets/js/landing.js')) ?>" defer></script>
<script src="<?= h(asset_url('assets/js/pwa.js')) ?>" defer></script>
<?php require __DIR__ . '/../partials/a11y.php'; ?>
</body>
</html>
