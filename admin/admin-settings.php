<?php
require_once __DIR__ . '/_init.php';
$pageTitle = 'Settings';
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $fields = ['site_name','site_url','site_logo','site_favicon','api_key','api_url','api_key_smmfa','api_url_smmfa','provider_smmfa_enabled','markup_percent','min_deposit','referral_commission','referral_min_payout','registration_enabled','email_verification_required','maintenance_mode',
        'smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','contact_email','admin_notify_email','admin_notify_signup','admin_notify_orders','admin_notify_deposits','mail_mode','smtp_encryption','mail_lang','wallet_btc','wallet_eth','wallet_usdt_trc20','wallet_usdt_erc20','wallet_bnb','wallet_sol',
        'deposit_auto_confirm','deposit_min_confirmations','deposit_amount_tolerance','api_etherscan','api_trongrid','api_bscscan',
        'payment_smmpaygate_enabled','payment_smmpaygate_api_url','payment_smmpaygate_api_key','payment_smmpaygate_merchant_id','payment_smmpaygate_secret',
        'payment_heleket_enabled','payment_heleket_merchant_id','payment_heleket_api_key',
        'payment_heleket_mode','payment_heleket_currency','payment_heleket_network',
        'payment_usdt_trc20_enabled','payment_binance_pay_enabled','payment_binance_pay_api_key','payment_binance_pay_secret',
        'payment_zarinpal_enabled','payment_zarinpal_merchant_id','payment_zarinpal_usd_rate','payment_zarinpal_sandbox',
        'payment_cryptocloud_enabled','payment_cryptocloud_shop_id','payment_cryptocloud_api_key',
        'child_panel_price','child_panel_ns1','child_panel_ns2',
        'child_panel_auto_mode','child_panel_parent_api_url',
        'child_panel_whm_host','child_panel_whm_username','child_panel_whm_api_token',
        'child_panel_cpanel_user','child_panel_server_ip','child_panel_home_path',
        'child_panel_whm_port','child_panel_primary_domain','child_panel_dns_mode',
        'child_panel_template_path','child_panel_min_reseller_balance',
        'deposit_bonus_percent','deposit_bonus_first_only',
        'revenue_vip_silver_spent','revenue_vip_silver_discount',
        'revenue_vip_gold_spent','revenue_vip_gold_discount',
        'revenue_vip_platinum_spent','revenue_vip_platinum_discount',
        'dashboard_promo_title','dashboard_promo_text','dashboard_promo_cta_label','dashboard_promo_cta_url',
        'revenue_low_balance_threshold','revenue_winback_days',
        'signup_welcome_credit','growth_promo_bar_enabled','growth_promo_bar_text',
        'growth_promo_bar_cta_label','growth_promo_bar_cta_url',
        'growth_stats_boost_orders','growth_stats_boost_users'];
    foreach ($fields as $f) {
        if (!isset($_POST[$f])) {
            continue;
        }
        if ($f === 'smtp_pass' && trim($_POST[$f]) === '') {
            continue;
        }
        $db->setSetting($f, trim($_POST[$f]));
    }
    foreach (['provider_smmfa_enabled','payment_smmpaygate_enabled','payment_heleket_enabled','payment_usdt_trc20_enabled','payment_binance_pay_enabled','payment_zarinpal_enabled','payment_cryptocloud_enabled','growth_promo_bar_enabled'] as $cb) {
        if (!isset($_POST[$cb])) {
            $db->setSetting($cb, '0');
        }
    }
    flash('success', '✅ Settings saved successfully.');
    redirect(url('admin/admin-settings.php'));
}

$settings = [];
$rows = $db->fetchAll("SELECT * FROM settings");
foreach ($rows as $r) $settings[$r['key']] = $r['value'];

function s(array $settings, string $key): string {
    return htmlspecialchars($settings[$key] ?? '', ENT_QUOTES);
}

require_once __DIR__ . '/../layouts/header.php';
?>

<div style="max-width:700px;">
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">🌐 Site Settings</div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Site Name</label>
          <input type="text" name="site_name" class="form-control" value="<?= s($settings,'site_name') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Site URL</label>
          <input type="url" name="site_url" class="form-control" value="<?= s($settings,'site_url') ?>">
        </div>
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Logo path</label>
          <input type="text" name="site_logo" class="form-control" value="<?= s($settings,'site_logo') ?>" placeholder="assets/img/logo-icon.svg">
          <p style="font-size:11px;color:var(--text-muted);margin-top:6px;">Relative path or full URL. Upload via File Manager, then paste path here.</p>
        </div>
        <div class="form-group">
          <label class="form-label">Favicon path</label>
          <input type="text" name="site_favicon" class="form-control" value="<?= s($settings,'site_favicon') ?>" placeholder="assets/img/logo-icon.svg">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Maintenance Mode</label>
        <select name="maintenance_mode" class="form-control">
          <option value="0" <?= ($settings['maintenance_mode']??'0')==='0'?'selected':'' ?>>Off</option>
          <option value="1" <?= ($settings['maintenance_mode']??'0')==='1'?'selected':'' ?>>On</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">User Registration</label>
        <select name="registration_enabled" class="form-control">
          <option value="1" <?= ($settings['registration_enabled']??'1')==='1'?'selected':'' ?>>Enabled</option>
          <option value="0" <?= ($settings['registration_enabled']??'1')==='0'?'selected':'' ?>>Disabled</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Require email verification</label>
        <select name="email_verification_required" class="form-control">
          <option value="1" <?= ($settings['email_verification_required']??'1')==='1'?'selected':'' ?>>On — user must verify email before login (recommended)</option>
          <option value="0" <?= ($settings['email_verification_required']??'1')==='0'?'selected':'' ?>>Off — instant access after register</option>
        </select>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">📧 Email</div>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.65;">
        <strong>cPanel — noreply@smm-turk.com</strong> (SSL/TLS recommended):<br>
        Host <code>smm-turk.com</code> · Port <code>465</code> · Encryption <strong>SSL</strong> (or Auto with port 465).<br>
        Alternative: port <code>587</code> with encryption <strong>TLS</strong>.<br>
        Username = full email (<code>noreply@smm-turk.com</code>) · Password = mailbox password from cPanel → Email Accounts.<br>
        <strong>Mail From</strong> must match SMTP user. Use <code>contact@smm-turk.com</code> as Reply-To / contact if needed.<br>
        <strong>Receiving mail</strong> (e.g. <code>info@smm-turk.com</code>): MX must point to <code>mail.smm-turk.com</code> with A → server IP (<code>92.205.182.143</code>). Broken MX like <code>mx.smm-turk.com</code> without A record blocks Gmail delivery.<br>
        <a href="<?= h(path('admin/admin-mail.php')) ?>" style="color:var(--primary);font-weight:700;">Send test email →</a>
      </p>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Mail mode</label>
          <select name="mail_mode" class="form-control">
            <option value="auto" <?= ($settings['mail_mode']??'auto')==='auto'?'selected':'' ?>>Auto — SMTP if configured, else PHP mail()</option>
            <option value="smtp" <?= ($settings['mail_mode']??'')==='smtp'?'selected':'' ?>>SMTP only</option>
            <option value="mail" <?= ($settings['mail_mode']??'')==='mail'?'selected':'' ?>>PHP mail() only (cPanel)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Email language</label>
          <select name="mail_lang" class="form-control">
            <option value="tr" <?= ($settings['mail_lang']??'tr')==='tr'?'selected':'' ?>>Türkçe (Turkish)</option>
            <option value="en" <?= ($settings['mail_lang']??'')==='en'?'selected':'' ?>>English</option>
          </select>
        </div>
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">SMTP encryption</label>
          <select name="smtp_encryption" class="form-control">
            <option value="auto" <?= ($settings['smtp_encryption']??'auto')==='auto'?'selected':'' ?>>Auto (465=SSL, 587=TLS)</option>
            <option value="ssl" <?= ($settings['smtp_encryption']??'')==='ssl'?'selected':'' ?>>SSL</option>
            <option value="tls" <?= ($settings['smtp_encryption']??'')==='tls'?'selected':'' ?>>TLS (STARTTLS)</option>
            <option value="none" <?= ($settings['smtp_encryption']??'')==='none'?'selected':'' ?>>None</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Mail From (sender — must exist in cPanel)</label>
        <input type="email" name="smtp_from" class="form-control" value="<?= s($settings,'smtp_from') ?>" placeholder="noreply@smm-turk.com">
      </div>
      <div class="form-group">
        <label class="form-label">Contact / Reply-To email</label>
        <input type="email" name="contact_email" class="form-control" value="<?= s($settings,'contact_email') ?>" placeholder="contact@smm-turk.com">
      </div>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
        <strong style="font-size:13px;">Admin notifications</strong>
        <p style="font-size:12px;color:var(--text-muted);margin:8px 0 12px;">Get an email when users sign up, place orders, or request deposits. User emails are sent separately.</p>
      </div>
      <div class="form-group">
        <label class="form-label">Admin notify email</label>
        <input type="email" name="admin_notify_email" class="form-control" value="<?= s($settings,'admin_notify_email') ?>" placeholder="Leave empty = use Contact email above">
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Notify on signup</label>
          <select name="admin_notify_signup" class="form-control">
            <option value="1" <?= ($settings['admin_notify_signup']??'1')==='1'?'selected':'' ?>>On</option>
            <option value="0" <?= ($settings['admin_notify_signup']??'')==='0'?'selected':'' ?>>Off</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Notify on new order</label>
          <select name="admin_notify_orders" class="form-control">
            <option value="1" <?= ($settings['admin_notify_orders']??'1')==='1'?'selected':'' ?>>On</option>
            <option value="0" <?= ($settings['admin_notify_orders']??'')==='0'?'selected':'' ?>>Off</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Notify on deposits</label>
          <select name="admin_notify_deposits" class="form-control">
            <option value="1" <?= ($settings['admin_notify_deposits']??'1')==='1'?'selected':'' ?>>On</option>
            <option value="0" <?= ($settings['admin_notify_deposits']??'')==='0'?'selected':'' ?>>Off</option>
          </select>
        </div>
      </div>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
        <strong style="font-size:13px;">SMTP (cPanel or Gmail/SendGrid)</strong>
      </div>
      <div class="grid2" style="margin-top:10px;">
        <div class="form-group">
          <label class="form-label">SMTP Host</label>
          <input type="text" name="smtp_host" class="form-control" value="<?= s($settings,'smtp_host') ?>" placeholder="smm-turk.com">
        </div>
        <div class="form-group">
          <label class="form-label">SMTP Port</label>
          <input type="text" name="smtp_port" class="form-control" value="<?= s($settings,'smtp_port') ?: '465' ?>" placeholder="465 or 587">
        </div>
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">SMTP User (full email)</label>
          <input type="text" name="smtp_user" class="form-control" value="<?= s($settings,'smtp_user') ?>" placeholder="noreply@smm-turk.com">
        </div>
        <div class="form-group">
          <label class="form-label">SMTP Password</label>
          <input type="password" name="smtp_pass" class="form-control" value="" placeholder="<?= !empty(trim($settings['smtp_pass'] ?? '')) ? 'Saved — enter only to change' : 'Mailbox password from cPanel' ?>" autocomplete="new-password">
          <?php if (!empty(trim($settings['smtp_pass'] ?? ''))): ?>
          <p style="font-size:11px;color:var(--text-muted);margin-top:6px;">Password is saved. Leave blank when saving other settings.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">🔑 Provider API — SmmFollows (primary)</div>
      <div class="form-group">
        <label class="form-label">API URL</label>
        <input type="url" name="api_url" class="form-control" value="<?= s($settings,'api_url') ?: 'https://smmfollows.com/api/v2' ?>" placeholder="https://smmfollows.com/api/v2">
      </div>
      <div class="form-group">
        <label class="form-label">API Key</label>
        <input type="text" name="api_key" class="form-control" value="<?= s($settings,'api_key') ?>" placeholder="Your API key from smmfollows.com">
      </div>
      <div style="font-size:12px;color:var(--text-muted);">
        Get your key from <a href="https://smmfollows.com" target="_blank" rel="noopener noreferrer" style="color:var(--primary);">smmfollows.com</a> → Account → API
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">🔑 Provider API — SMMFA (smmfa.com)</div>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">
        Second provider (premium catalog). Panel category: <strong>SMM Turk Pro</strong>.
        Standard catalog from SmmFollows: <strong>SMM Turk One</strong>.
      </p>
      <div class="form-group">
        <label class="form-label">
          <input type="hidden" name="provider_smmfa_enabled" value="0">
          <input type="checkbox" name="provider_smmfa_enabled" value="1" <?= ($settings['provider_smmfa_enabled']??'0')==='1'?'checked':'' ?>>
          Enable SMMFA provider
        </label>
      </div>
      <div class="form-group">
        <label class="form-label">SMMFA API URL</label>
        <input type="url" name="api_url_smmfa" class="form-control" value="<?= s($settings,'api_url_smmfa') ?: 'https://smmfa.com/api/v2' ?>" placeholder="https://smmfa.com/api/v2">
      </div>
      <div class="form-group">
        <label class="form-label">SMMFA API Key (token)</label>
        <input type="text" name="api_key_smmfa" class="form-control" value="<?= s($settings,'api_key_smmfa') ?>" placeholder="API key from smmfa.com">
      </div>
      <div style="font-size:12px;color:var(--text-muted);">
        Register at <a href="https://smmfa.com" target="_blank" rel="noopener noreferrer" style="color:var(--primary);">smmfa.com</a> → API → copy key, then
        <a href="<?= h(path('admin/admin-sync.php')) ?>" style="color:var(--primary);font-weight:700;">Sync services →</a>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">💰 Pricing & Commissions</div>
      <div class="grid3">
        <div class="form-group">
          <label class="form-label">Default Markup (%)</label>
          <input type="number" name="markup_percent" class="form-control" value="<?= s($settings,'markup_percent') ?>" min="0" step="0.5">
        </div>
        <div class="form-group">
          <label class="form-label">Min Deposit ($)</label>
          <input type="number" name="min_deposit" class="form-control" value="<?= s($settings,'min_deposit') ?>" min="1">
        </div>
        <div class="form-group">
          <label class="form-label">Referral Commission (%)</label>
          <input type="number" name="referral_commission" class="form-control" value="<?= s($settings,'referral_commission') ?>" min="0" step="0.5">
        </div>
      </div>
      <div class="grid2" style="margin-top:8px;">
        <div class="form-group">
          <label class="form-label">Referral min payout ($)</label>
          <input type="number" name="referral_min_payout" class="form-control" value="<?= s($settings,'referral_min_payout') ?: '10' ?>" min="1" step="0.01">
        </div>
        <div class="form-group">
          <label class="form-label">First deposit bonus (%)</label>
          <input type="number" name="deposit_bonus_percent" class="form-control" value="<?= s($settings,'deposit_bonus_percent') ?: '10' ?>" min="0" max="50" step="0.5">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Deposit bonus — first deposit only</label>
        <select name="deposit_bonus_first_only" class="form-control">
          <option value="1" <?= ($settings['deposit_bonus_first_only']??'1')==='1'?'selected':'' ?>>Yes — first deposit only</option>
          <option value="0" <?= ($settings['deposit_bonus_first_only']??'1')==='0'?'selected':'' ?>>No — every deposit</option>
        </select>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">📈 Traffic & signups</div>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">More visitors → more accounts → more deposits. Public <a href="<?= h(path('pricing.php')) ?>" style="color:var(--primary);">/pricing</a> page is indexed for Google.</p>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Welcome credit on signup ($)</label>
          <input type="number" name="signup_welcome_credit" class="form-control" value="<?= s($settings,'signup_welcome_credit') ?: '0.50' ?>" min="0" max="5" step="0.01">
          <p style="font-size:11px;color:var(--text-muted);margin-top:6px;">Free balance so new users can try first order without deposit. Set 0 to disable.</p>
        </div>
        <div class="form-group">
          <label class="form-label">Promo bar on homepage</label>
          <input type="hidden" name="growth_promo_bar_enabled" value="0">
          <label class="checkbox-label"><input type="checkbox" name="growth_promo_bar_enabled" value="1" <?= ($settings['growth_promo_bar_enabled']??'1')==='1'?'checked':'' ?>> Show sticky promo bar</label>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Promo bar text</label>
        <input type="text" name="growth_promo_bar_text" class="form-control" value="<?= s($settings,'growth_promo_bar_text') ?>" placeholder="Sign up free — get $0.50 + 10% deposit bonus!">
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Promo CTA label</label>
          <input type="text" name="growth_promo_bar_cta_label" class="form-control" value="<?= s($settings,'growth_promo_bar_cta_label') ?: 'Create account →' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Promo CTA URL</label>
          <input type="text" name="growth_promo_bar_cta_url" class="form-control" value="<?= s($settings,'growth_promo_bar_cta_url') ?: '/login.php?mode=register' ?>">
        </div>
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Stats boost — orders display</label>
          <input type="number" name="growth_stats_boost_orders" class="form-control" value="<?= s($settings,'growth_stats_boost_orders') ?: '50000' ?>" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">Stats boost — users display</label>
          <input type="number" name="growth_stats_boost_users" class="form-control" value="<?= s($settings,'growth_stats_boost_users') ?: '1200' ?>" min="0">
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">🏆 VIP tiers (spend-based discounts)</div>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Users get automatic order discounts based on lifetime <code>spent</code>. <a href="<?= h(path('admin/admin-coupons.php')) ?>" style="color:var(--primary);">Manage coupons →</a></p>
      <div class="grid3">
        <div class="form-group">
          <label class="form-label">Silver at spent ($)</label>
          <input type="number" name="revenue_vip_silver_spent" class="form-control" value="<?= s($settings,'revenue_vip_silver_spent') ?: '100' ?>" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">Silver discount (%)</label>
          <input type="number" name="revenue_vip_silver_discount" class="form-control" value="<?= s($settings,'revenue_vip_silver_discount') ?: '2' ?>" min="0" max="30" step="0.5">
        </div>
        <div class="form-group">
          <label class="form-label">Gold at spent ($)</label>
          <input type="number" name="revenue_vip_gold_spent" class="form-control" value="<?= s($settings,'revenue_vip_gold_spent') ?: '500' ?>" min="0">
        </div>
      </div>
      <div class="grid3">
        <div class="form-group">
          <label class="form-label">Gold discount (%)</label>
          <input type="number" name="revenue_vip_gold_discount" class="form-control" value="<?= s($settings,'revenue_vip_gold_discount') ?: '5' ?>" min="0" max="30" step="0.5">
        </div>
        <div class="form-group">
          <label class="form-label">Platinum at spent ($)</label>
          <input type="number" name="revenue_vip_platinum_spent" class="form-control" value="<?= s($settings,'revenue_vip_platinum_spent') ?: '2000' ?>" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">Platinum discount (%)</label>
          <input type="number" name="revenue_vip_platinum_discount" class="form-control" value="<?= s($settings,'revenue_vip_platinum_discount') ?: '10' ?>" min="0" max="30" step="0.5">
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">📣 Dashboard promo banner</div>
      <div class="form-group">
        <label class="form-label">Title</label>
        <input type="text" name="dashboard_promo_title" class="form-control" value="<?= s($settings,'dashboard_promo_title') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Text</label>
        <input type="text" name="dashboard_promo_text" class="form-control" value="<?= s($settings,'dashboard_promo_text') ?>">
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Button label</label>
          <input type="text" name="dashboard_promo_cta_label" class="form-control" value="<?= s($settings,'dashboard_promo_cta_label') ?: 'Add Funds →' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Button URL (path)</label>
          <input type="text" name="dashboard_promo_cta_url" class="form-control" value="<?= s($settings,'dashboard_promo_cta_url') ?: '/add-funds.php' ?>">
        </div>
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Low balance email threshold ($)</label>
          <input type="number" name="revenue_low_balance_threshold" class="form-control" value="<?= s($settings,'revenue_low_balance_threshold') ?: '5' ?>" min="0" step="0.01">
        </div>
        <div class="form-group">
          <label class="form-label">Win-back after days inactive</label>
          <input type="number" name="revenue_winback_days" class="form-control" value="<?= s($settings,'revenue_winback_days') ?: '30' ?>" min="7">
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">💳 Payment Gateways (Add Funds)</div>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.65;">
        Enable each method and paste API credentials. Users see enabled methods in <strong>Add Funds → Method</strong> dropdown (like SMMFA).<br>
        Webhook URLs: <code><?= h(PaymentRegistry::webhookUrl('heleket')) ?></code> (Heleket) ·
        <code><?= h(PaymentRegistry::webhookUrl('cryptocloud')) ?></code> (CryptoCloud) ·
        <code><?= h(PaymentRegistry::webhookUrl('binance_pay')) ?></code> (Binance Pay) ·
        <code><?= h(PaymentRegistry::webhookUrl('smmpaygate')) ?></code> (SmmPayGate)
      </p>

      <div class="payment-gw-block">
        <h4>SmmPayGate (own)</h4>
        <label class="form-label"><input type="hidden" name="payment_smmpaygate_enabled" value="0"><input type="checkbox" name="payment_smmpaygate_enabled" value="1" <?= ($settings['payment_smmpaygate_enabled']??'0')==='1'?'checked':'' ?>> Enable</label>
        <div class="form-group"><label class="form-label">API URL</label><input type="url" name="payment_smmpaygate_api_url" class="form-control" value="<?= s($settings,'payment_smmpaygate_api_url') ?>" placeholder="https://api.smmpaygate.com"></div>
        <div class="grid2">
          <div class="form-group"><label class="form-label">API Key</label><input type="text" name="payment_smmpaygate_api_key" class="form-control" value="<?= s($settings,'payment_smmpaygate_api_key') ?>"></div>
          <div class="form-group"><label class="form-label">Merchant ID</label><input type="text" name="payment_smmpaygate_merchant_id" class="form-control" value="<?= s($settings,'payment_smmpaygate_merchant_id') ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">Webhook Secret (optional)</label><input type="text" name="payment_smmpaygate_secret" class="form-control" value="<?= s($settings,'payment_smmpaygate_secret') ?>"></div>
      </div>

      <div class="payment-gw-block">
        <h4>Heleket</h4>
        <label class="form-label"><input type="hidden" name="payment_heleket_enabled" value="0"><input type="checkbox" name="payment_heleket_enabled" value="1" <?= ($settings['payment_heleket_enabled']??'0')==='1'?'checked':'' ?>> Enable</label>
        <p style="font-size:11px;color:var(--text-muted);">
          <a href="https://heleket.com" target="_blank" rel="noopener">heleket.com</a> → Business → API keys.
          Docs: <a href="https://doc.heleket.com" target="_blank" rel="noopener">doc.heleket.com</a> ·
          Webhook IP: <code>31.133.220.8</code>
        </p>
        <div class="grid2">
          <div class="form-group"><label class="form-label">Merchant UUID</label><input type="text" name="payment_heleket_merchant_id" class="form-control" value="<?= s($settings,'payment_heleket_merchant_id') ?>" placeholder="From Heleket dashboard"></div>
          <div class="form-group"><label class="form-label">Payment API Key</label><input type="text" name="payment_heleket_api_key" class="form-control" value="<?= s($settings,'payment_heleket_api_key') ?>"></div>
        </div>
        <div class="grid3" style="margin-top:10px;">
          <div class="form-group">
            <label class="form-label">Display mode</label>
            <select name="payment_heleket_mode" class="form-control">
              <option value="panel" <?= ($settings['payment_heleket_mode']??'panel')==='panel'?'selected':'' ?>>Panel — address + QR in Add Funds (recommended)</option>
              <option value="redirect" <?= ($settings['payment_heleket_mode']??'')==='redirect'?'selected':'' ?>>Redirect — open pay.heleket.com</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Crypto</label>
            <input type="text" name="payment_heleket_currency" class="form-control" value="<?= s($settings,'payment_heleket_currency') ?: 'USDT' ?>" placeholder="USDT">
          </div>
          <div class="form-group">
            <label class="form-label">Network</label>
            <select name="payment_heleket_network" class="form-control">
              <?php
              $hkNet = $settings['payment_heleket_network'] ?? 'bsc';
              foreach (['tron' => 'TRC20 (Tron)', 'bsc' => 'BEP-20 (BSC)', 'eth' => 'ERC20 (ETH)', 'btc' => 'BTC'] as $val => $lbl):
              ?>
              <option value="<?= h($val) ?>" <?= $hkNet === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <p style="font-size:11px;color:var(--text-muted);margin-top:8px;">
          Webhook URL (paste in Heleket project): <code><?= h(PaymentRegistry::webhookUrl('heleket')) ?></code>
        </p>
      </div>

      <div class="payment-gw-block">
        <h4>USDT TRC20 (direct wallet)</h4>
        <label class="form-label"><input type="hidden" name="payment_usdt_trc20_enabled" value="0"><input type="checkbox" name="payment_usdt_trc20_enabled" value="1" <?= ($settings['payment_usdt_trc20_enabled']??'0')==='1'?'checked':'' ?>> Enable</label>
        <p style="font-size:11px;color:var(--text-muted);">Uses <strong>USDT (TRC20)</strong> wallet below + auto on-chain verification.</p>
      </div>

      <div class="payment-gw-block">
        <h4>Binance Pay</h4>
        <label class="form-label"><input type="hidden" name="payment_binance_pay_enabled" value="0"><input type="checkbox" name="payment_binance_pay_enabled" value="1" <?= ($settings['payment_binance_pay_enabled']??'0')==='1'?'checked':'' ?>> Enable</label>
        <p style="font-size:11px;color:var(--text-muted);"><a href="https://merchant.binance.com" target="_blank" rel="noopener">merchant.binance.com</a> → Certificate SN + Secret Key</p>
        <div class="grid2">
          <div class="form-group"><label class="form-label">Certificate SN (API Key)</label><input type="text" name="payment_binance_pay_api_key" class="form-control" value="<?= s($settings,'payment_binance_pay_api_key') ?>"></div>
          <div class="form-group"><label class="form-label">Secret Key</label><input type="password" name="payment_binance_pay_secret" class="form-control" value="<?= s($settings,'payment_binance_pay_secret') ?>" autocomplete="new-password"></div>
        </div>
      </div>

      <div class="payment-gw-block">
        <h4>ZarinPal</h4>
        <label class="form-label"><input type="hidden" name="payment_zarinpal_enabled" value="0"><input type="checkbox" name="payment_zarinpal_enabled" value="1" <?= ($settings['payment_zarinpal_enabled']??'0')==='1'?'checked':'' ?>> Enable</label>
        <p style="font-size:11px;color:var(--text-muted);"><a href="https://www.zarinpal.com" target="_blank" rel="noopener">zarinpal.com</a> → 36-char Merchant ID. Amount converted USD→IRR using rate below.</p>
        <div class="form-group"><label class="form-label">Merchant ID</label><input type="text" name="payment_zarinpal_merchant_id" class="form-control" value="<?= s($settings,'payment_zarinpal_merchant_id') ?>"></div>
        <div class="grid2">
          <div class="form-group"><label class="form-label">USD → IRR rate</label><input type="number" name="payment_zarinpal_usd_rate" class="form-control" value="<?= s($settings,'payment_zarinpal_usd_rate') ?: '600000' ?>" min="10000"></div>
          <div class="form-group"><label class="form-label">Sandbox</label><select name="payment_zarinpal_sandbox" class="form-control"><option value="0" <?= ($settings['payment_zarinpal_sandbox']??'0')==='0'?'selected':'' ?>>Live</option><option value="1" <?= ($settings['payment_zarinpal_sandbox']??'0')==='1'?'selected':'' ?>>Sandbox (test)</option></select></div>
        </div>
      </div>

      <div class="payment-gw-block">
        <h4>CryptoCloud</h4>
        <label class="form-label"><input type="hidden" name="payment_cryptocloud_enabled" value="0"><input type="checkbox" name="payment_cryptocloud_enabled" value="1" <?= ($settings['payment_cryptocloud_enabled']??'0')==='1'?'checked':'' ?>> Enable</label>
        <p style="font-size:11px;color:var(--text-muted);"><a href="https://cryptocloud.plus" target="_blank" rel="noopener">cryptocloud.plus</a> → Shop ID + API Token</p>
        <div class="grid2">
          <div class="form-group"><label class="form-label">Shop ID</label><input type="text" name="payment_cryptocloud_shop_id" class="form-control" value="<?= s($settings,'payment_cryptocloud_shop_id') ?>"></div>
          <div class="form-group"><label class="form-label">API Key (Token)</label><input type="text" name="payment_cryptocloud_api_key" class="form-control" value="<?= s($settings,'payment_cryptocloud_api_key') ?>"></div>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">₿ Crypto Wallets (USDT TRC20 + optional manual coins)</div>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">
        Copy <strong>Receive</strong> addresses from your wallet (Trust Wallet, MetaMask, etc.) and paste them here.
        Users send crypto to these addresses — the panel verifies payments on the blockchain automatically (no wallet extension connection needed).
      </p>
      <div class="form-group">
        <label class="form-label">Bitcoin (BTC)</label>
        <input type="text" name="wallet_btc" class="form-control" value="<?= s($settings,'wallet_btc') ?>" placeholder="bc1... or 1...">
      </div>
      <div class="form-group">
        <label class="form-label">Ethereum (ETH)</label>
        <input type="text" name="wallet_eth" class="form-control" value="<?= s($settings,'wallet_eth') ?>" placeholder="0x...">
      </div>
      <div class="form-group">
        <label class="form-label">USDT (TRC20 – Tron)</label>
        <input type="text" name="wallet_usdt_trc20" class="form-control" value="<?= s($settings,'wallet_usdt_trc20') ?>" placeholder="T...">
      </div>
      <div class="form-group">
        <label class="form-label">USDT (ERC20 – Ethereum)</label>
        <input type="text" name="wallet_usdt_erc20" class="form-control" value="<?= s($settings,'wallet_usdt_erc20') ?>" placeholder="0x...">
      </div>
      <div class="form-group">
        <label class="form-label">BNB (BEP20)</label>
        <input type="text" name="wallet_bnb" class="form-control" value="<?= s($settings,'wallet_bnb') ?>" placeholder="0x...">
      </div>
      <div class="form-group">
        <label class="form-label">Solana (SOL)</label>
        <input type="text" name="wallet_sol" class="form-control" value="<?= s($settings,'wallet_sol') ?>" placeholder="...">
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">🔗 Auto deposit verification</div>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">
        When a user submits a TxHash, the panel checks the blockchain and credits balance automatically.
        <strong>USDT TRC20</strong> works without API keys. For ETH/ERC20/BNB add free API keys from Etherscan / BscScan.
      </p>
      <div class="form-group">
        <label class="form-label">Auto-confirm deposits</label>
        <select name="deposit_auto_confirm" class="form-control">
          <option value="1" <?= ($settings['deposit_auto_confirm']??'1')==='1'?'selected':'' ?>>On — verify on-chain and credit balance</option>
          <option value="0" <?= ($settings['deposit_auto_confirm']??'1')==='0'?'selected':'' ?>>Off — admin approves manually</option>
        </select>
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Min confirmations</label>
          <input type="number" name="deposit_min_confirmations" class="form-control" value="<?= s($settings,'deposit_min_confirmations') ?: '1' ?>" min="1" max="12">
        </div>
        <div class="form-group">
          <label class="form-label">Amount tolerance (0.03 = ±3%)</label>
          <input type="number" name="deposit_amount_tolerance" class="form-control" value="<?= s($settings,'deposit_amount_tolerance') ?: '0.03' ?>" min="0.01" max="0.15" step="0.01">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Etherscan API key (ETH + USDT ERC20)</label>
        <input type="text" name="api_etherscan" class="form-control" value="<?= s($settings,'api_etherscan') ?>" placeholder="Free at etherscan.io/apis">
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">TronGrid API key (optional, USDT TRC20)</label>
          <input type="text" name="api_trongrid" class="form-control" value="<?= s($settings,'api_trongrid') ?>" placeholder="Optional — trongrid.io">
        </div>
        <div class="form-group">
          <label class="form-label">BscScan API key (BNB)</label>
          <input type="text" name="api_bscscan" class="form-control" value="<?= s($settings,'api_bscscan') ?>" placeholder="Free at bscscan.com/apis">
        </div>
      </div>
      <p style="font-size:11px;color:var(--text-muted);margin-top:8px;">
        Cron: <code>*/2 * * * * php /home/smmturk/public_html/cron-verify-deposits.php</code>
      </p>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">👥 Child Panel</div>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">
        Full automation: WHM creates addon domain, copies panel files, creates MySQL DB + admin user.
        Customer sets DNS → cron or “Check DNS” deploys the panel.
      </p>
      <div class="grid3">
        <div class="form-group">
          <label class="form-label">Price per month ($)</label>
          <input type="number" name="child_panel_price" class="form-control" value="<?= s($settings,'child_panel_price') ?: '5' ?>" min="0" step="0.01">
        </div>
        <div class="form-group">
          <label class="form-label">Automation mode</label>
          <select name="child_panel_auto_mode" class="form-control">
            <?php
            $autoMode = s($settings, 'child_panel_auto_mode') ?: 'instant';
            foreach ([
                'instant' => 'Full auto when DNS ready',
                'dns' => 'Wait for DNS then deploy',
                'manual' => 'Manual (admin only)',
            ] as $val => $label):
            ?>
            <option value="<?= h($val) ?>" <?= $autoMode === $val ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">DNS check</label>
          <select name="child_panel_dns_mode" class="form-control">
            <?php $dnsMode = s($settings, 'child_panel_dns_mode') ?: 'both'; ?>
            <option value="both" <?= $dnsMode === 'both' ? 'selected' : '' ?>>NS or A (Cloudflare OK)</option>
            <option value="ns" <?= $dnsMode === 'ns' ? 'selected' : '' ?>>Nameservers only</option>
            <option value="a" <?= $dnsMode === 'a' ? 'selected' : '' ?>>A record only</option>
          </select>
        </div>
      </div>
      <div class="grid2" style="margin-top:8px;">
        <div class="form-group">
          <label class="form-label">Parent API URL</label>
          <input type="url" name="child_panel_parent_api_url" class="form-control" value="<?= s($settings,'child_panel_parent_api_url') ?>" placeholder="https://smm-turk.com/api/v2">
        </div>
        <div class="form-group">
          <label class="form-label">Server IP</label>
          <input type="text" name="child_panel_server_ip" class="form-control" value="<?= s($settings,'child_panel_server_ip') ?: '92.205.182.143' ?>">
        </div>
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Nameserver 1</label>
          <input type="text" name="child_panel_ns1" class="form-control" value="<?= s($settings,'child_panel_ns1') ?>" placeholder="ns1.smm-turk.com">
        </div>
        <div class="form-group">
          <label class="form-label">Nameserver 2</label>
          <input type="text" name="child_panel_ns2" class="form-control" value="<?= s($settings,'child_panel_ns2') ?>" placeholder="ns2.smm-turk.com">
        </div>
      </div>
      <p style="font-size:11px;color:var(--text-muted);margin:12px 0 8px;font-weight:600;">WHM API (root token)</p>
      <div class="grid3">
        <div class="form-group">
          <label class="form-label">WHM host</label>
          <input type="text" name="child_panel_whm_host" class="form-control" value="<?= s($settings,'child_panel_whm_host') ?>" placeholder="server.netinode.net">
        </div>
        <div class="form-group">
          <label class="form-label">WHM user (root)</label>
          <input type="text" name="child_panel_whm_username" class="form-control" value="<?= s($settings,'child_panel_whm_username') ?>" placeholder="root">
        </div>
        <div class="form-group">
          <label class="form-label">WHM API token</label>
          <input type="password" name="child_panel_whm_api_token" class="form-control" value="<?= s($settings,'child_panel_whm_api_token') ?>" autocomplete="new-password">
        </div>
      </div>
      <div class="grid3" style="margin-top:8px;">
        <div class="form-group">
          <label class="form-label">cPanel user</label>
          <input type="text" name="child_panel_cpanel_user" class="form-control" value="<?= s($settings,'child_panel_cpanel_user') ?: 'smmturk' ?>" placeholder="smmturk">
          <p style="font-size:11px;color:var(--text-muted);margin-top:6px;">Must match WHM account username (e.g. <code>smmturk</code>, not the MySQL DB name).</p>
        </div>
        <div class="form-group">
          <label class="form-label">Home path</label>
          <input type="text" name="child_panel_home_path" class="form-control" value="<?= s($settings,'child_panel_home_path') ?: '/home/smmturk' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">WHM port</label>
          <input type="number" name="child_panel_whm_port" class="form-control" value="<?= s($settings,'child_panel_whm_port') ?: '2087' ?>">
        </div>
      </div>
      <div class="form-group" style="margin-top:8px;">
        <label class="form-label">Min reseller balance alert ($)</label>
        <input type="number" name="child_panel_min_reseller_balance" class="form-control" value="<?= s($settings,'child_panel_min_reseller_balance') ?: '10' ?>" min="1" step="0.01">
        <p style="font-size:11px;color:var(--text-muted);margin-top:6px;">Email alert once per day when a child-panel owner balance falls below this (orders use parent balance).</p>
      </div>
      <p style="font-size:11px;color:var(--text-muted);margin-top:8px;">
        <strong>24/7 automation (one cron — replaces separate sync/deposit/child crons):</strong><br>
        <code>*/5 * * * * php /home/smmturk/public_html/cron-master.php</code>
      </p>
    </div>

    <button type="submit" class="btn btn-primary">💾 Save Settings</button>
  </form>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
