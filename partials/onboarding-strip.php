<?php
/** Dashboard onboarding checklist — include after $auth->isLoggedIn(). */
if (!isset($auth) || !$auth->isLoggedIn()) {
    return;
}
$userId = (int) $auth->getUserId();
$onboarding = new UserOnboarding();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dismiss_onboarding']) && csrf_verify()) {
    $onboarding->dismiss($userId);
    redirect(url('dashboard.php'));
}
if (!$onboarding->shouldShow($userId)) {
    return;
}
$steps = $onboarding->steps($userId);
$progress = $onboarding->progressPercent($userId);
?>
<link rel="stylesheet" href="<?= h(asset_url('assets/css/earn.css')) ?>">
<div class="onboard-strip" data-reveal>
  <div class="onboard-strip-head">
    <div>
      <h2>🚀 Getting started</h2>
      <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0;">Complete these steps to order, deposit, and earn.</p>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
      <span class="onboard-strip-progress"><?= $progress ?>% done</span>
      <form method="POST" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="dismiss_onboarding" value="1">
        <button type="submit" class="onboard-dismiss">Dismiss</button>
      </form>
    </div>
  </div>
  <div class="onboard-steps">
    <?php foreach ($steps as $i => $step): ?>
    <a href="<?= h($step['url']) ?>" class="onboard-step<?= !empty($step['done']) ? ' done' : '' ?>">
      <span class="onboard-step-num"><?= !empty($step['done']) ? '✓' : ($i + 1) ?></span>
      <span class="onboard-step-text">
        <strong><?= h($step['title']) ?></strong>
        <span><?= h($step['desc']) ?></span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
