<?php
/**
 * GA4 + Google Ads tags. Safe no-op when IDs are empty.
 * Optional: $gaConversionEvents already consumed; otherwise pulled from session.
 */
if (!class_exists('GoogleAcquisition', false) || !class_exists('Database')) {
    return;
}
try {
    $gaCfg = (new GoogleAcquisition())->trackingConfig();
} catch (Throwable $e) {
    return;
}
$ga4Id = $gaCfg['ga4'] ?? '';
$adsId = $gaCfg['ads'] ?? '';
if ($ga4Id !== '' && !preg_match('/^G-[A-Z0-9]+$/', $ga4Id)) {
    $ga4Id = '';
}
if ($adsId !== '' && !preg_match('/^AW-[0-9]+$/', $adsId)) {
    $adsId = '';
}
if ($ga4Id === '' && $adsId === '') {
    $pendingGa = GoogleAcquisition::consumeClientEvents();
    unset($pendingGa);
    return;
}
$primaryId = $ga4Id !== '' ? $ga4Id : $adsId;
$gaEvents = $gaConversionEvents ?? GoogleAcquisition::consumeClientEvents();
$signupLabel = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($gaCfg['signup_label'] ?? ''));
$purchaseLabel = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($gaCfg['purchase_label'] ?? ''));
?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= h($primaryId) ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
<?php if ($ga4Id !== ''): ?>
gtag('config', <?= json_encode($ga4Id) ?>);
<?php endif; ?>
<?php if ($adsId !== ''): ?>
gtag('config', <?= json_encode($adsId) ?>);
<?php endif; ?>
<?php foreach ($gaEvents as $ev):
    $evName = (string) ($ev['event'] ?? '');
    $evVal = (float) ($ev['value'] ?? 0);
    if ($evName === 'sign_up'): ?>
gtag('event', 'sign_up', {method: 'site'});
<?php if ($adsId !== '' && $signupLabel !== ''): ?>
gtag('event', 'conversion', {send_to: <?= json_encode($adsId . '/' . $signupLabel) ?>});
<?php endif; ?>
<?php elseif ($evName === 'purchase' || $evName === 'first_order'): ?>
gtag('event', 'purchase', {currency: 'USD', value: <?= json_encode($evVal) ?>});
<?php if ($adsId !== '' && $purchaseLabel !== ''): ?>
gtag('event', 'conversion', {send_to: <?= json_encode($adsId . '/' . $purchaseLabel) ?>, value: <?= json_encode($evVal) ?>, currency: 'USD'});
<?php endif; ?>
<?php endif; endforeach; ?>
</script>
