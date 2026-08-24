<?php
require_once __DIR__ . '/_init.php';
$pageTitle = 'Email Test';
$db = Database::getInstance();
$mail = new Mail();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (isset($_POST['test_email'])) {
        $to = trim($_POST['test_to'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid test email address.');
        } else {
            $ok = $mail->sendTest($to);
            $via = $mail->getLastTransport();
            $smtpConfigured = trim((string) ($db->getSetting('smtp_host') ?? '')) !== '';
            if ($ok && $via === 'smtp') {
                flash('success', 'Test email sent via SMTP to ' . $to . '. Check inbox and spam folder.');
            } elseif ($ok && $via === 'mail' && $smtpConfigured) {
                flash('error', 'SMTP auth failed — only PHP mail() fallback ran. Re-enter the noreply mailbox password in Settings → Email, set Encryption to SSL, Mail mode to SMTP only, save, and test again.');
            } elseif ($ok) {
                flash('success', 'Test email sent to ' . $to . ' (via PHP mail()). Check inbox and spam folder.');
            } else {
                flash('error', 'Send failed: ' . ($mail->getLastError() ?? 'Unknown error'));
            }
        }
        redirect(url('admin/admin-mail.php'));
    }
}

$diag = $mail->getDiagnostics();
$incoming = $diag['incoming'] ?? $mail->getIncomingMailDiagnostics();
$user = $auth->getCurrentUser();
$defaultTestTo = $user['email'] ?? '';

$logFile = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__)) . '/tmp/logs/mail.log';
$logTail = '';
if (is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $logTail = implode("\n", array_slice($lines, -25));
}

require_once __DIR__ . '/../layouts/header.php';
?>

<div style="max-width:720px;">
  <div class="card" style="margin-bottom:18px;">
    <div class="card-title"><?= icon('message', 20, '', ['style' => 'vertical-align:-4px;margin-right:8px']) ?> Email diagnostics</div>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;line-height:1.6;">
      <strong>Receiving</strong> email = fix MX in Cloudflare + create mailbox in cPanel → Email Accounts (e.g. <code>contact@smm-turk.com</code>, <code>info@smm-turk.com</code>).<br>
      <strong>Sending</strong> from the panel = configure below, then send a test.
    </p>

    <?php if (!empty($incoming) && empty($incoming['ok'])): ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px;margin-bottom:16px;font-size:13px;line-height:1.65;">
      <strong style="color:#b91c1c;">Incoming mail DNS broken</strong><br>
      <?= h($incoming['hint'] ?? '') ?>
      <?php if (!empty($incoming['mx'])): ?>
      <ul style="margin:10px 0 0 18px;padding:0;">
        <?php foreach ($incoming['mx'] as $mx): ?>
        <li>MX <?= (int) ($mx['priority'] ?? 0) ?> → <code><?= h($mx['host'] ?? '') ?></code>
          <?= !empty($mx['resolves']) ? '✓ ' . h($mx['ip'] ?? '') : '<strong style="color:#b91c1c;">✗ no A record</strong>' ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <p style="margin:12px 0 0;"><strong>Fix in Cloudflare → DNS:</strong></p>
      <ol style="margin:8px 0 0 18px;padding:0;">
        <li>Delete MX pointing to <code>mx.<?= h($incoming['domain'] ?? 'yourdomain.com') ?></code> (if broken).</li>
        <li>Add MX: <code>@</code> → <code><?= h($incoming['recommended_mx'] ?? 'mail.yourdomain.com') ?></code> priority <code>0</code> or <code>10</code>.</li>
        <li>Ensure A record: <code>mail</code> → <code><?= h($incoming['server_ip'] ?: $incoming['mail_a_ip'] ?: '92.205.182.143') ?></code> (DNS only).</li>
        <li>In cPanel → Email Accounts, create <code>info@<?= h($incoming['domain'] ?? '') ?></code> or forward to contact@.</li>
      </ol>
    </div>
    <?php elseif (!empty($incoming['ok'])): ?>
    <p style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px;font-size:13px;color:#166534;margin-bottom:16px;">
      ✓ Incoming mail DNS OK — MX resolves to mail server.
    </p>
    <?php endif; ?>

    <table class="table" style="font-size:13px;">
      <tr><td>Mail From</td><td><code><?= h($diag['from']) ?></code></td></tr>
      <tr><td>Reply-To</td><td><code><?= h($diag['reply_to'] ?? '—') ?></code></td></tr>
      <tr><td>Mode</td><td><?= h($diag['mail_mode']) ?></td></tr>
      <tr><td>SMTP Host</td><td><code><?= h($diag['smtp_host']) ?></code></td></tr>
      <tr><td>SMTP Port</td><td><?= h($diag['smtp_port']) ?></td></tr>
      <tr><td>SMTP User</td><td><code><?= h($diag['smtp_user']) ?></code></td></tr>
      <tr><td>SMTP Password</td><td><?= !empty($diag['smtp_pass_set']) ? 'Saved in settings' : '<strong style="color:var(--primary);">Not set — enter in Settings</strong>' ?></td></tr>
      <tr><td>Encryption</td><td><?= h($diag['smtp_encryption']) ?> <?= (int)$diag['smtp_port'] === 465 ? '(use SSL recommended)' : '' ?></td></tr>
      <tr><td>cPanel SMTP (SSL)</td><td><code><?= h($diag['cpanel_hint_host']) ?></code> port 465 (SSL) — or <code><?= h($diag['cpanel_hint_alt'] ?? '') ?></code></td></tr>
      <tr><td>cPanel SMTP (TLS)</td><td>port 587 + TLS</td></tr>
    </table>
    <p style="margin-top:14px;font-size:12px;color:var(--text-muted);">
      <a href="<?= h(path('admin/admin-settings.php')) ?>">Edit email settings →</a>
    </p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <div class="card-title">Send test email</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="test_email" value="1">
      <div class="form-group">
        <label class="form-label">Send to</label>
        <input type="email" name="test_to" class="form-control" required value="<?= h($defaultTestTo) ?>" placeholder="your@email.com">
      </div>
      <button type="submit" class="btn btn-primary">Send test email</button>
    </form>
  </div>

  <?php if ($logTail !== ''): ?>
  <div class="card">
    <div class="card-title">Recent mail log</div>
    <pre style="font-size:11px;background:var(--bg);padding:12px;border-radius:10px;overflow:auto;max-height:220px;"><?= h($logTail) ?></pre>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
