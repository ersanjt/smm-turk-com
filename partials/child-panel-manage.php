<?php
/**
 * Remote child panel management UI (included from child-panel.php).
 *
 * @var array<string, mixed> $p
 * @var array<string, mixed> $user
 * @var ChildPanelManager $cpm
 * @var ChildPanelRemoteSettings $cprs
 * @var ChildPanelEndUsers $endUsers
 * @var string $panelUrl
 * @var string $parentApi
 */
$panelId = (int) ($p['id'] ?? 0);
$documentRoot = trim((string) ($p['document_root'] ?? ''));
if ($documentRoot === '') {
    $deployerTmp = new ChildPanelDeployer();
    $documentRoot = $deployerTmp->docrootForDomain((string) ($p['domain'] ?? ''));
}
$settings = $documentRoot !== '' ? $cprs->readSettings($documentRoot) : [];
$google = $documentRoot !== '' ? $cprs->readGoogleOAuth($documentRoot) : ['client_id' => '', 'client_secret' => '', 'configured' => false];
$panelCustomers = $endUsers->listForOwner((int) ($user['id'] ?? 0), $panelId, 50);
$customerCount = $endUsers->countForOwner((int) ($user['id'] ?? 0), $panelId);
$stats = $documentRoot !== '' ? $cprs->panelStats($documentRoot) : ['available' => false];

function cp_s(array $settings, string $key): string {
    return htmlspecialchars($settings[$key] ?? '', ENT_QUOTES);
}
?>
<div class="cp-manage" id="cp-manage-<?= $panelId ?>">
  <div class="cp-manage-head">
    <strong>⚙️ Manage your panel</strong>
    <span class="cp-status-hint">All settings here — no cPanel or child admin needed. Your customers use your panel URL; you configure everything from SMM Turk.</span>
  </div>

  <div class="cp-manage-tabs" role="tablist">
    <?php
    $tabs = [
        'overview' => 'Overview',
        'branding' => 'Branding',
        'general'  => 'General',
        'wallets'  => 'Wallets',
        'payments' => 'Payments',
        'email'    => 'Email',
        'google'   => 'Google login',
        'customers'=> 'Customers',
        'login'    => 'Login',
    ];
    foreach ($tabs as $tid => $label):
    ?>
    <button type="button" class="cp-manage-tab<?= $tid === 'overview' ? ' active' : '' ?>" data-cp-tab="<?= h($tid) ?>" data-cp-panel="<?= $panelId ?>"><?= h($label) ?></button>
    <?php endforeach; ?>
  </div>

  <!-- Overview -->
  <div class="cp-manage-pane active" data-cp-pane="overview" data-cp-panel="<?= $panelId ?>">
    <?php if (empty($stats['available'])): ?>
    <p class="cp-status-hint">Live stats will appear once your panel database is reachable.</p>
    <?php else:
      $ordersTotal = max(1, (int) $stats['orders_total']);
      $completionRate = round(((int) $stats['orders_completed'] / $ordersTotal) * 100);
    ?>
    <div class="cp-stats-grid">
      <div class="cp-mini-stat">
        <div class="cp-mini-label">Customers</div>
        <div class="cp-mini-value"><?= number_format((int) $stats['customers']) ?></div>
        <div class="cp-mini-sub"><?= number_format((int) $stats['customers_active']) ?> active</div>
      </div>
      <div class="cp-mini-stat">
        <div class="cp-mini-label">Revenue (all time)</div>
        <div class="cp-mini-value">$<?= number_format((float) $stats['revenue'], 2) ?></div>
        <div class="cp-mini-sub">$<?= number_format((float) $stats['revenue_30d'], 2) ?> last 30d</div>
      </div>
      <div class="cp-mini-stat">
        <div class="cp-mini-label">Orders</div>
        <div class="cp-mini-value"><?= number_format((int) $stats['orders_total']) ?></div>
        <div class="cp-mini-sub"><?= number_format((int) $stats['orders_7d']) ?> in last 7d</div>
      </div>
      <div class="cp-mini-stat">
        <div class="cp-mini-label">Completed</div>
        <div class="cp-mini-value"><?= $completionRate ?>%</div>
        <div class="cp-mini-sub"><?= number_format((int) $stats['orders_pending']) ?> pending</div>
      </div>
      <div class="cp-mini-stat">
        <div class="cp-mini-label">Deposits received</div>
        <div class="cp-mini-value">$<?= number_format((float) $stats['deposits_total'], 2) ?></div>
        <div class="cp-mini-sub"><?= number_format((int) $stats['deposits_pending']) ?> pending</div>
      </div>
      <div class="cp-mini-stat">
        <div class="cp-mini-label">Customer balances</div>
        <div class="cp-mini-value">$<?= number_format((float) $stats['customer_balance'], 2) ?></div>
        <div class="cp-mini-sub">held on your panel</div>
      </div>
    </div>

    <?php if (!empty($stats['recent_orders'])): ?>
    <div style="margin-top:16px;">
      <strong style="font-size:12px;">Recent orders</strong>
      <div class="table-wrap" style="margin-top:8px;max-height:260px;">
        <table class="table table-mobile-cards" style="font-size:12px;">
          <thead><tr><th>Customer</th><th>Service</th><th>Charge</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($stats['recent_orders'] as $o):
              $ost = (string) ($o['status'] ?? '');
              $obadge = match (true) {
                  $ost === 'Completed' => 'badge-green',
                  in_array($ost, ['Cancelled', 'Refunded'], true) => 'badge-red',
                  default => 'badge-orange',
              };
          ?>
          <tr>
            <td data-label="Customer"><?= h((string) ($o['username'] ?? '—')) ?></td>
            <td data-label="Service"><?= h(mb_substr((string) ($o['service_name'] ?? ''), 0, 40)) ?></td>
            <td data-label="Charge">$<?= number_format((float) ($o['charge'] ?? 0), 2) ?></td>
            <td data-label="Status"><span class="badge <?= $obadge ?>" style="font-size:10px;"><?= h($ost) ?></span></td>
            <td data-label="Date"><?= h(date('Y-m-d', strtotime((string) ($o['created_at'] ?? 'now')))) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
    <p class="cp-status-hint" style="margin-top:12px;">Revenue is what your customers paid you. Your cost is deducted from your SMM Turk balance per order.</p>
    <?php endif; ?>
  </div>

  <!-- Branding -->
  <div class="cp-manage-pane" data-cp-pane="branding" data-cp-panel="<?= $panelId ?>">
    <form method="POST" enctype="multipart/form-data" class="cp-manage-form">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="panel_id" value="<?= $panelId ?>">
      <input type="hidden" name="save_panel_settings" value="1">
      <input type="hidden" name="settings_section" value="branding">

      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Site name</label>
          <input type="text" name="site_name" class="form-control" value="<?= cp_s($settings, 'site_name') ?>" placeholder="My SMM Panel">
        </div>
        <div class="form-group">
          <label class="form-label">Site URL</label>
          <input type="url" name="site_url" class="form-control" value="<?= cp_s($settings, 'site_url') ?: h($panelUrl) ?>">
        </div>
      </div>

      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Logo</label>
          <?php $logoUrl = $cprs->brandingPreviewUrl($panelUrl, $settings['site_logo'] ?? ''); ?>
          <?php if ($logoUrl !== ''): ?>
          <div class="cp-brand-preview"><img src="<?= h($logoUrl) ?>" alt="Logo" loading="lazy"></div>
          <?php endif; ?>
          <input type="file" name="upload_logo" accept="image/*,.svg,.ico" class="form-control" style="padding:8px;">
          <input type="hidden" name="site_logo" value="<?= cp_s($settings, 'site_logo') ?>">
          <span class="cp-status-hint">Upload PNG, JPG, SVG or ICO (max 2 MB). Saved on your panel automatically.</span>
        </div>
        <div class="form-group">
          <label class="form-label">Favicon</label>
          <?php $favUrl = $cprs->brandingPreviewUrl($panelUrl, $settings['site_favicon'] ?? ''); ?>
          <?php if ($favUrl !== ''): ?>
          <div class="cp-brand-preview cp-brand-preview-sm"><img src="<?= h($favUrl) ?>" alt="Favicon" loading="lazy"></div>
          <?php endif; ?>
          <input type="file" name="upload_favicon" accept="image/*,.svg,.ico" class="form-control" style="padding:8px;">
          <input type="hidden" name="site_favicon" value="<?= cp_s($settings, 'site_favicon') ?>">
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="font-size:12px;padding:8px 14px;">Save branding</button>
    </form>
  </div>

  <!-- General -->
  <div class="cp-manage-pane" data-cp-pane="general" data-cp-panel="<?= $panelId ?>">
    <form method="POST" class="cp-manage-form">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="panel_id" value="<?= $panelId ?>">
      <input type="hidden" name="save_panel_settings" value="1">
      <input type="hidden" name="settings_section" value="general">
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">User registration</label>
          <select name="registration_enabled" class="form-control">
            <option value="1" <?= ($settings['registration_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled</option>
            <option value="0" <?= ($settings['registration_enabled'] ?? '') === '0' ? 'selected' : '' ?>>Disabled</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Email verification</label>
          <select name="email_verification_required" class="form-control">
            <option value="1" <?= ($settings['email_verification_required'] ?? '1') === '1' ? 'selected' : '' ?>>Required</option>
            <option value="0" <?= ($settings['email_verification_required'] ?? '') === '0' ? 'selected' : '' ?>>Off</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Maintenance mode</label>
          <select name="maintenance_mode" class="form-control">
            <option value="0" <?= ($settings['maintenance_mode'] ?? '0') === '0' ? 'selected' : '' ?>>Off</option>
            <option value="1" <?= ($settings['maintenance_mode'] ?? '') === '1' ? 'selected' : '' ?>>On</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Contact email</label>
          <input type="email" name="contact_email" class="form-control" value="<?= cp_s($settings, 'contact_email') ?>" placeholder="support@yourpanel.com">
        </div>
      </div>
      <div class="grid3">
        <div class="form-group">
          <label class="form-label">Markup %</label>
          <input type="number" name="markup_percent" class="form-control" value="<?= cp_s($settings, 'markup_percent') ?: '15' ?>" min="0" step="0.5">
        </div>
        <div class="form-group">
          <label class="form-label">Min deposit ($)</label>
          <input type="number" name="min_deposit" class="form-control" value="<?= cp_s($settings, 'min_deposit') ?: '10' ?>" min="1">
        </div>
        <div class="form-group">
          <label class="form-label">Referral %</label>
          <input type="number" name="referral_commission" class="form-control" value="<?= cp_s($settings, 'referral_commission') ?: '2' ?>" min="0" step="0.5">
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="font-size:12px;padding:8px 14px;">Save general settings</button>
    </form>
  </div>

  <!-- Wallets -->
  <div class="cp-manage-pane" data-cp-pane="wallets" data-cp-panel="<?= $panelId ?>">
    <form method="POST" class="cp-manage-form">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="panel_id" value="<?= $panelId ?>">
      <input type="hidden" name="save_panel_settings" value="1">
      <input type="hidden" name="settings_section" value="wallets">
      <p class="cp-status-hint" style="margin-bottom:12px;">Crypto wallet addresses shown to your customers when they add funds manually.</p>
      <?php
      $walletLabels = [
          'wallet_btc' => 'Bitcoin (BTC)',
          'wallet_eth' => 'Ethereum (ETH)',
          'wallet_usdt_trc20' => 'USDT TRC20',
          'wallet_usdt_erc20' => 'USDT ERC20',
          'wallet_bnb' => 'BNB',
          'wallet_sol' => 'Solana (SOL)',
      ];
      foreach ($walletLabels as $key => $label):
      ?>
      <div class="form-group">
        <label class="form-label"><?= h($label) ?></label>
        <input type="text" name="<?= h($key) ?>" class="form-control" value="<?= cp_s($settings, $key) ?>" placeholder="Wallet address">
      </div>
      <?php endforeach; ?>
      <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);">
        <strong style="font-size:12px;">Auto-confirm deposits</strong>
      </div>
      <div class="grid3" style="margin-top:8px;">
        <div class="form-group">
          <label class="form-label">Auto confirm</label>
          <select name="deposit_auto_confirm" class="form-control">
            <option value="0" <?= ($settings['deposit_auto_confirm'] ?? '0') === '0' ? 'selected' : '' ?>>Off</option>
            <option value="1" <?= ($settings['deposit_auto_confirm'] ?? '') === '1' ? 'selected' : '' ?>>On</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Min confirmations</label>
          <input type="number" name="deposit_min_confirmations" class="form-control" value="<?= cp_s($settings, 'deposit_min_confirmations') ?: '1' ?>" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">Amount tolerance %</label>
          <input type="number" name="deposit_amount_tolerance" class="form-control" value="<?= cp_s($settings, 'deposit_amount_tolerance') ?: '2' ?>" min="0" step="0.1">
        </div>
      </div>
      <div class="grid3">
        <div class="form-group"><label class="form-label">Etherscan API</label><input type="text" name="api_etherscan" class="form-control" value="<?= cp_s($settings, 'api_etherscan') ?>"></div>
        <div class="form-group"><label class="form-label">TronGrid API</label><input type="text" name="api_trongrid" class="form-control" value="<?= cp_s($settings, 'api_trongrid') ?>"></div>
        <div class="form-group"><label class="form-label">BscScan API</label><input type="text" name="api_bscscan" class="form-control" value="<?= cp_s($settings, 'api_bscscan') ?>"></div>
      </div>
      <button type="submit" class="btn btn-primary" style="font-size:12px;padding:8px 14px;">Save wallets</button>
    </form>
  </div>

  <!-- Payments -->
  <div class="cp-manage-pane" data-cp-pane="payments" data-cp-panel="<?= $panelId ?>">
    <form method="POST" class="cp-manage-form">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="panel_id" value="<?= $panelId ?>">
      <input type="hidden" name="save_panel_settings" value="1">
      <input type="hidden" name="settings_section" value="payments">
      <p class="cp-status-hint" style="margin-bottom:12px;">Webhook URLs for your panel (paste in gateway dashboard):<br>
        Heleket: <code><?= h($cprs->panelWebhookUrl($panelUrl, 'heleket')) ?></code><br>
        CryptoCloud: <code><?= h($cprs->panelWebhookUrl($panelUrl, 'cryptocloud')) ?></code><br>
        Binance Pay: <code><?= h($cprs->panelWebhookUrl($panelUrl, 'binance_pay')) ?></code>
      </p>

      <div class="cp-gw-block">
        <label class="form-label"><input type="hidden" name="payment_heleket_enabled" value="0"><input type="checkbox" name="payment_heleket_enabled" value="1" <?= ($settings['payment_heleket_enabled'] ?? '0') === '1' ? 'checked' : '' ?>> Heleket</label>
        <div class="grid2">
          <div class="form-group"><label class="form-label">Merchant UUID</label><input type="text" name="payment_heleket_merchant_id" class="form-control" value="<?= cp_s($settings, 'payment_heleket_merchant_id') ?>"></div>
          <div class="form-group"><label class="form-label">API Key</label><input type="text" name="payment_heleket_api_key" class="form-control" value="<?= cp_s($settings, 'payment_heleket_api_key') ?>"></div>
        </div>
      </div>

      <div class="cp-gw-block">
        <label class="form-label"><input type="hidden" name="payment_usdt_trc20_enabled" value="0"><input type="checkbox" name="payment_usdt_trc20_enabled" value="1" <?= ($settings['payment_usdt_trc20_enabled'] ?? '0') === '1' ? 'checked' : '' ?>> Manual USDT TRC20</label>
      </div>

      <div class="cp-gw-block">
        <label class="form-label"><input type="hidden" name="payment_binance_pay_enabled" value="0"><input type="checkbox" name="payment_binance_pay_enabled" value="1" <?= ($settings['payment_binance_pay_enabled'] ?? '0') === '1' ? 'checked' : '' ?>> Binance Pay</label>
        <div class="grid2">
          <div class="form-group"><label class="form-label">API Key</label><input type="text" name="payment_binance_pay_api_key" class="form-control" value="<?= cp_s($settings, 'payment_binance_pay_api_key') ?>"></div>
          <div class="form-group"><label class="form-label">Secret</label><input type="password" name="payment_binance_pay_secret" class="form-control" value="<?= cp_s($settings, 'payment_binance_pay_secret') ?>" autocomplete="new-password"></div>
        </div>
      </div>

      <div class="cp-gw-block">
        <label class="form-label"><input type="hidden" name="payment_cryptocloud_enabled" value="0"><input type="checkbox" name="payment_cryptocloud_enabled" value="1" <?= ($settings['payment_cryptocloud_enabled'] ?? '0') === '1' ? 'checked' : '' ?>> CryptoCloud</label>
        <div class="grid2">
          <div class="form-group"><label class="form-label">Shop ID</label><input type="text" name="payment_cryptocloud_shop_id" class="form-control" value="<?= cp_s($settings, 'payment_cryptocloud_shop_id') ?>"></div>
          <div class="form-group"><label class="form-label">API Key</label><input type="text" name="payment_cryptocloud_api_key" class="form-control" value="<?= cp_s($settings, 'payment_cryptocloud_api_key') ?>"></div>
        </div>
      </div>

      <div class="cp-gw-block">
        <label class="form-label"><input type="hidden" name="payment_zarinpal_enabled" value="0"><input type="checkbox" name="payment_zarinpal_enabled" value="1" <?= ($settings['payment_zarinpal_enabled'] ?? '0') === '1' ? 'checked' : '' ?>> Zarinpal</label>
        <div class="grid2">
          <div class="form-group"><label class="form-label">Merchant ID</label><input type="text" name="payment_zarinpal_merchant_id" class="form-control" value="<?= cp_s($settings, 'payment_zarinpal_merchant_id') ?>"></div>
          <div class="form-group"><label class="form-label">USD→IRR rate</label><input type="number" name="payment_zarinpal_usd_rate" class="form-control" value="<?= cp_s($settings, 'payment_zarinpal_usd_rate') ?: '600000' ?>"></div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="font-size:12px;padding:8px 14px;">Save payment gateways</button>
    </form>
  </div>

  <!-- Email -->
  <div class="cp-manage-pane" data-cp-pane="email" data-cp-panel="<?= $panelId ?>">
    <form method="POST" class="cp-manage-form">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="panel_id" value="<?= $panelId ?>">
      <input type="hidden" name="save_panel_settings" value="1">
      <input type="hidden" name="settings_section" value="email">
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Mail mode</label>
          <select name="mail_mode" class="form-control">
            <option value="auto" <?= ($settings['mail_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto</option>
            <option value="smtp" <?= ($settings['mail_mode'] ?? '') === 'smtp' ? 'selected' : '' ?>>SMTP only</option>
            <option value="mail" <?= ($settings['mail_mode'] ?? '') === 'mail' ? 'selected' : '' ?>>PHP mail()</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Mail language</label>
          <select name="mail_lang" class="form-control">
            <option value="tr" <?= ($settings['mail_lang'] ?? 'tr') === 'tr' ? 'selected' : '' ?>>Turkish</option>
            <option value="en" <?= ($settings['mail_lang'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Mail from</label>
        <input type="email" name="smtp_from" class="form-control" value="<?= cp_s($settings, 'smtp_from') ?>" placeholder="noreply@yourpanel.com">
      </div>
      <div class="grid2">
        <div class="form-group"><label class="form-label">SMTP host</label><input type="text" name="smtp_host" class="form-control" value="<?= cp_s($settings, 'smtp_host') ?>"></div>
        <div class="form-group"><label class="form-label">SMTP port</label><input type="text" name="smtp_port" class="form-control" value="<?= cp_s($settings, 'smtp_port') ?: '465' ?>"></div>
        <div class="form-group"><label class="form-label">SMTP user</label><input type="text" name="smtp_user" class="form-control" value="<?= cp_s($settings, 'smtp_user') ?>"></div>
        <div class="form-group"><label class="form-label">SMTP password</label><input type="password" name="smtp_pass" class="form-control" placeholder="<?= !empty(trim($settings['smtp_pass'] ?? '')) ? 'Saved — leave blank to keep' : '' ?>" autocomplete="new-password"></div>
      </div>
      <button type="submit" class="btn btn-primary" style="font-size:12px;padding:8px 14px;">Save email settings</button>
    </form>
  </div>

  <!-- Google -->
  <div class="cp-manage-pane" data-cp-pane="google" data-cp-panel="<?= $panelId ?>">
    <form method="POST" class="cp-manage-form">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="panel_id" value="<?= $panelId ?>">
      <input type="hidden" name="save_panel_google" value="1">
      <p class="cp-status-hint" style="margin-bottom:12px;">
        Enable “Sign in with Google” on your child panel.<br>
        In <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>, create OAuth 2.0 credentials.<br>
        Authorized redirect URI: <code><?= h(rtrim($panelUrl, '/') . '/login-google-callback.php') ?></code>
      </p>
      <div class="form-group">
        <label class="form-label">Google Client ID</label>
        <input type="text" name="google_client_id" class="form-control" value="<?= h($google['client_id'] ?? '') ?>" placeholder="xxxx.apps.googleusercontent.com">
      </div>
      <div class="form-group">
        <label class="form-label">Google Client Secret</label>
        <input type="password" name="google_client_secret" class="form-control" value="" placeholder="<?= !empty($google['client_secret'] ?? '') ? 'Saved — leave blank to keep' : 'Client secret' ?>" autocomplete="new-password">
      </div>
      <?php if (!empty($google['configured'])): ?>
      <span class="cp-dns-status ok" style="display:inline-block;margin-bottom:10px;">Google login is configured on this panel.</span>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary" style="font-size:12px;padding:8px 14px;">Save Google OAuth</button>
    </form>
  </div>

  <!-- Customers -->
  <div class="cp-manage-pane" data-cp-pane="customers" data-cp-panel="<?= $panelId ?>">
    <p class="cp-status-hint" style="margin-bottom:12px;">
      <strong><?= (int) $customerCount ?></strong> customers registered on <code><?= h($p['domain'] ?? '') ?></code>.
      They pay you on your panel; orders are fulfilled using your <strong>SMM Turk balance</strong>.
    </p>
    <?php if ($panelCustomers === []): ?>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">No customers synced yet. New signups appear automatically.</p>
    <form method="POST" style="margin-bottom:12px;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="panel_id" value="<?= $panelId ?>">
      <input type="hidden" name="import_panel_customers" value="1">
      <button type="submit" class="btn" style="font-size:12px;padding:8px 14px;">Import existing customers from panel</button>
    </form>
    <?php else: ?>
    <div class="table-wrap" style="margin-bottom:12px;max-height:280px;">
      <table class="table table-mobile-cards" style="font-size:12px;">
        <thead><tr><th>User</th><th>Email</th><th>Status</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($panelCustomers as $cu): ?>
        <tr>
          <td data-label="User"><?= h($cu['username']) ?></td>
          <td data-label="Email"><?= h($cu['email']) ?></td>
          <td data-label="Status"><?= h($cu['status']) ?></td>
          <td data-label="Joined"><?= h(date('Y-m-d', strtotime($cu['registered_at'] ?? 'now'))) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Login (password) -->
  <div class="cp-manage-pane" data-cp-pane="login" data-cp-panel="<?= $panelId ?>">
    <?php
    $usesParentLogin = $cpm->usesParentLogin($p);
    $loginUsername = $usesParentLogin ? ($user['username'] ?? $p['admin_username']) : $p['admin_username'];
    ?>
    <dl class="cp-connect" style="margin-top:0;">
      <dt>Username</dt><dd><code><?= h($loginUsername) ?></code></dd>
      <dt>Password</dt>
      <dd><?= $usesParentLogin ? 'Same as your <strong>SMM Turk</strong> password' : 'See legacy password in panel card above' ?></dd>
      <dt>Your panel login URL</dt>
      <dd><a href="<?= h($panelUrl . '/login') ?>" target="_blank" rel="noopener"><?= h($panelUrl . '/login') ?></a></dd>
    </dl>
    <form method="POST" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="panel_id" value="<?= $panelId ?>">
      <input type="hidden" name="change_panel_password" value="1">
      <div class="form-group"><label class="form-label">Current password</label><input type="password" name="current_password" class="form-control" required></div>
      <div class="grid2">
        <div class="form-group"><label class="form-label">New password</label><input type="password" name="new_password" class="form-control" minlength="6" required></div>
        <div class="form-group"><label class="form-label">Confirm</label><input type="password" name="new_password_confirm" class="form-control" minlength="6" required></div>
      </div>
      <button type="submit" class="btn btn-primary" style="font-size:12px;padding:8px 14px;">Change login password</button>
    </form>
    <?php if (!$usesParentLogin): ?>
    <form method="POST" style="margin-top:10px;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="panel_id" value="<?= $panelId ?>">
      <input type="hidden" name="sync_parent_login" value="1">
      <button type="submit" class="btn" style="font-size:12px;padding:8px 14px;">Use my SMM Turk login instead</button>
    </form>
    <?php endif; ?>
  </div>
</div>
