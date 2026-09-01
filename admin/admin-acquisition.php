<?php
require_once __DIR__ . '/_init.php';
$pageTitle = 'Google customers';
$pageSubtitle = 'Unique visitors from Google Search and Ads, and who became paying customers.';
$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90], true)) {
    $days = 30;
}
$acq = new GoogleAcquisition();
$report = $acq->report($days);
$cfg = $acq->trackingConfig();
$gaReady = $cfg['ga4'] !== '' || $cfg['verification'] !== '';

require_once __DIR__ . '/../layouts/header.php';
?>
<div class="admin-page-shell" style="max-width:1100px;">
  <div class="card" style="margin-bottom:18px;">
    <div class="card-title">Unique Google customers</div>
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 16px;line-height:1.55;">
      First-touch: organic Google search or Google Ads click (<code>gclid</code>).
      A unique paying customer is a user whose first visit was Google and who later deposited.
      Range:
      <a href="<?= h(path('admin/admin-acquisition.php') . '?days=7') ?>">7d</a> ·
      <a href="<?= h(path('admin/admin-acquisition.php') . '?days=30') ?>">30d</a> ·
      <a href="<?= h(path('admin/admin-acquisition.php') . '?days=90') ?>">90d</a>
    </p>
    <div class="grid3">
      <div class="form-group">
        <div class="form-label">Unique Google visitors</div>
        <div style="font-size:28px;font-weight:800;"><?= number_format((int) $report['unique_visitors']) ?></div>
        <p style="font-size:12px;color:var(--text-muted);">Distinct <code>st_vid</code> cookies with Google first-touch</p>
      </div>
      <div class="form-group">
        <div class="form-label">Google signups</div>
        <div style="font-size:28px;font-weight:800;"><?= number_format((int) $report['signups']) ?></div>
        <p style="font-size:12px;color:var(--text-muted);">Conversion <?= h((string) $report['signup_rate']) ?>%</p>
      </div>
      <div class="form-group">
        <div class="form-label">Unique paying customers</div>
        <div style="font-size:28px;font-weight:800;color:var(--primary);"><?= number_format((int) $report['paying_customers']) ?></div>
        <p style="font-size:12px;color:var(--text-muted);">First deposit · <?= h((string) $report['pay_rate']) ?>% of Google signups</p>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <div class="card-title">Connect Google (required for Ads + Search Console)</div>
    <?php if (!$gaReady): ?>
    <p style="font-size:13px;color:var(--text-muted);line-height:1.55;">IDs are empty — organic landing pages still work. Add measurement IDs in
      <a href="<?= h(path('admin/admin-settings.php')) ?>#google-acquisition">Settings → Google acquisition</a>.</p>
    <?php else: ?>
    <p style="font-size:13px;color:var(--text-muted);">GA4 <?= $cfg['ga4'] !== '' ? h($cfg['ga4']) : 'not set' ?> · Search Console tag <?= $cfg['verification'] !== '' ? 'set' : 'not set' ?></p>
    <?php endif; ?>
    <ol style="font-size:13px;line-height:1.7;padding-left:18px;margin:8px 0 0;">
      <li>Google Search Console → add <?= h(defined('SITE_URL') ? SITE_URL : 'your domain') ?> → HTML tag → paste in Settings.</li>
      <li>Submit <a href="<?= h(path('sitemap.php')) ?>" target="_blank" rel="noopener">/sitemap.xml</a> (includes /buy pages).</li>
      <li>GA4 → Admin → Data stream → Measurement ID <code>G-XXXX</code> → Settings.</li>
      <li>Google Ads: advertise child panel / API / SMM reseller tools — not “fake followers”. Policy bans that. Organic /buy pages still rank for commercial queries.</li>
    </ol>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <div class="card-title">SEO landing pages (index these)</div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">Each URL is unique content for a Google query. Share them; they are in the sitemap.</p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
      <a class="btn btn-sm" href="<?= h(GoogleAcquisition::hubUrl()) ?>" target="_blank" rel="noopener">/buy</a>
      <?php foreach ($report['pages'] as $p): ?>
      <a class="btn btn-sm" href="<?= h(GoogleAcquisition::pageUrl($p['slug'])) ?>" target="_blank" rel="noopener">/buy/<?= h($p['slug']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!empty($report['by_medium'])): ?>
  <div class="card" style="margin-bottom:18px;">
    <div class="card-title">Google medium split</div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Medium</th><th>Unique visitors</th><th>Signups</th><th>Deposits</th></tr></thead>
        <tbody>
        <?php foreach ($report['by_medium'] as $row): ?>
          <tr>
            <td><?= h((string) $row['medium']) ?></td>
            <td><?= number_format((int) $row['visitors']) ?></td>
            <td><?= number_format((int) $row['signups']) ?></td>
            <td><?= number_format((int) $row['deposits']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($report['landings'])): ?>
  <div class="card" style="margin-bottom:18px;">
    <div class="card-title">Top landing paths</div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Path</th><th>Visitors</th><th>Signups</th><th>Deposits</th></tr></thead>
        <tbody>
        <?php foreach ($report['landings'] as $row): ?>
          <tr>
            <td><code><?= h((string) $row['landing_path']) ?></code></td>
            <td><?= number_format((int) $row['visitors']) ?></td>
            <td><?= number_format((int) $row['signups']) ?></td>
            <td><?= number_format((int) $row['deposits']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title">Recent Google-attributed accounts</div>
    <?php if (empty($report['recent'])): ?>
    <p style="font-size:13px;color:var(--text-muted);">No Google first-touch signups yet. Traffic starts after Google indexes /buy pages and Search Console is verified.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>User</th><th>Medium</th><th>Campaign</th><th>Landing</th><th>Spent</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($report['recent'] as $u): ?>
          <tr>
            <td><?= h((string) $u['username']) ?></td>
            <td><?= h((string) ($u['utm_medium'] ?? '')) ?></td>
            <td><?= h((string) ($u['utm_campaign'] ?? '')) ?></td>
            <td><code><?= h((string) ($u['acquisition_landing'] ?? '')) ?></code></td>
            <td>$<?= number_format((float) ($u['spent'] ?? 0), 2) ?></td>
            <td><?= h((string) ($u['created_at'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
