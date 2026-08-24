<?php
require_once __DIR__ . '/app/init.php';
$auth->requireLogin();
$pageTitle = 'Affiliates';
$db   = Database::getInstance();
$user = $auth->getCurrentUser();
$revenue = new RevenueEngine();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && ($_POST['action'] ?? '') === 'payout_balance') {
    $result = $revenue->payoutReferralEarnings((int) $user['id']);
    if ($result['success']) {
        flash('success', '✅ $' . number_format((float) $result['amount'], 2) . ' transferred to your balance.');
    } else {
        flash('error', $result['error'] ?? 'Payout failed.');
    }
    redirect(url('affiliates.php'));
}

$refCode = $auth->ensureReferralCode((int)$user['id']);
$refLink = $refCode !== '' ? url('c/' . $refCode) : url('login.php') . '?mode=register';
$commission = (float)($db->getSetting('referral_commission') ?: 2);
$minPayout  = (float)($db->getSetting('referral_min_payout') ?: 10);

$referralsCount = (int) $db->fetch("SELECT COUNT(*) c FROM users WHERE referred_by = ?", [$user['id']])['c'];
$totalEarned = (float) $db->fetch("SELECT COALESCE(SUM(referral_earnings), 0) e FROM users WHERE referred_by = ?", [$user['id']])['e'];
$unpaid = (float)($user['referral_earnings'] ?? 0);
$totalLifetime = (float)($user['total_referral_earnings'] ?? $unpaid);

$visits = 0;
try {
    $visits = (int) $db->fetch("SELECT COUNT(*) c FROM referral_visits WHERE referrer_id = ?", [$user['id']])['c'];
} catch (Throwable $e) { }
$registrations = $referralsCount;
$conversion = $visits > 0 ? round(($registrations / $visits) * 100, 2) : 0.00;

$hasAnyStats = $visits > 0 || $registrations > 0 || $totalLifetime > 0 || $unpaid > 0;

require_once __DIR__ . '/layouts/header.php';
?>
<link rel="stylesheet" href="<?= h(asset_url('assets/css/affiliates.css')) ?>">

<div class="affiliates-page">
  <div class="aff-hero">
    <h1 class="aff-hero-title">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
      Affiliates
    </h1>
    <p class="aff-hero-desc">Refer friends and earn <?= number_format($commission, 0) ?>% commission on their orders. Share your link and grow your earnings.</p>
  </div>

  <div class="aff-warn" role="alert">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
    <span>We pay only for new customers. Re-registration and abuse will be rejected and your referral account may be terminated.</span>
  </div>

  <div class="aff-card">
    <h3>
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.172-1.171a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.172 1.171z" /></svg>
      Referral details
    </h3>
    <div class="aff-label" style="margin-bottom:8px;font-size:12px;color:var(--text-muted);">Your referral link</div>
    <div class="aff-link-box">
      <input type="text" id="refLink" readonly value="<?= h($refLink) ?>" aria-label="Referral link">
      <button type="button" class="aff-copy-btn" id="copyRefBtn" aria-label="Copy link">Copy</button>
    </div>
    <div class="aff-meta">
      <div class="aff-meta-item">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
        <div><span class="m-label">Commission rate</span><br><span class="m-value"><?= number_format($commission, 0) ?>%</span></div>
      </div>
      <div class="aff-meta-item">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        <div><span class="m-label">Minimum payout</span><br><span class="m-value">$<?= number_format($minPayout, 2) ?></span></div>
      </div>
    </div>
  </div>

  <div class="aff-card">
    <h3>
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
      Statistics
    </h3>
    <div class="aff-stats">
      <div class="aff-stat">
        <svg class="aff-stat-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
        <div class="label">Total visits</div>
        <div class="value"><?= number_format($visits) ?></div>
      </div>
      <div class="aff-stat">
        <svg class="aff-stat-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>
        <div class="label">Registrations</div>
        <div class="value"><?= number_format($registrations) ?></div>
      </div>
      <div class="aff-stat">
        <svg class="aff-stat-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
        <div class="label">Referrals</div>
        <div class="value"><?= number_format($referralsCount) ?></div>
      </div>
      <div class="aff-stat">
        <svg class="aff-stat-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
        <div class="label">Conversion rate</div>
        <div class="value"><?= number_format($conversion, 2) ?>%</div>
      </div>
      <div class="aff-stat">
        <svg class="aff-stat-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        <div class="label">Total earnings</div>
        <div class="value <?= $totalLifetime > 0 ? 'highlight' : '' ?>">$<?= number_format($totalLifetime, 2) ?></div>
      </div>
      <div class="aff-stat">
        <svg class="aff-stat-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
        <div class="label">Unpaid earnings</div>
        <div class="value <?= $unpaid > 0 ? 'highlight' : '' ?>">$<?= number_format($unpaid, 2) ?></div>
      </div>
    </div>
    <?php if (!$hasAnyStats): ?>
    <p class="aff-empty-hint">Share your referral link to see your stats grow.</p>
    <?php endif; ?>
  </div>

  <div class="aff-footer-note">
    When someone registers with your link and places an order, you earn <strong><?= number_format($commission, 0) ?>%</strong> commission.
    <?php if ($unpaid >= $minPayout): ?>
    <form method="POST" style="display:inline;margin-left:8px;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="payout_balance">
      <button type="submit" class="btn btn-primary btn-sm">Transfer $<?= number_format($unpaid, 2) ?> to balance</button>
    </form>
    <?php else: ?>
    Minimum payout: <strong>$<?= number_format($minPayout, 2) ?></strong> — or request via <a href="<?= h(path('tickets.php')) ?>">support ticket</a>.
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var btn = document.getElementById('copyRefBtn');
  var input = document.getElementById('refLink');
  if (!btn || !input) return;
  btn.addEventListener('click', function(){
    navigator.clipboard.writeText(input.value).then(function(){
      btn.textContent = 'Copied!';
      btn.classList.add('copied');
      setTimeout(function(){ btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
    });
  });
})();
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
