<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';
$auth->requireLogin();
$db = Database::getInstance();
ensure_account_settings_schema($db);
$pageTitle = 'Settings';
$forcePasswordChange = !empty($_SESSION['must_change_password']) || isset($_GET['change_password']);

$errors = [];

$user = $auth->getCurrentUser();

// Optional columns (auto-created by ensure_account_settings_schema)
$hasTimezone = array_key_exists('timezone', $user);
$hasTwoFactor = array_key_exists('two_factor_enabled', $user);
$hasTwoFactorSecret = array_key_exists('two_factor_secret', $user);
$hasApiKeyCreatedAt = array_key_exists('api_key_created_at', $user);
$hasAvatar = array_key_exists('avatar', $user);
$avatarPath = $hasAvatar && !empty(trim($user['avatar'] ?? '')) ? trim($user['avatar']) : null;

$userTimezone = $hasTimezone ? ($user['timezone'] ?? 'UTC') : 'UTC';
$twoFactorEnabled = $hasTwoFactor ? (int)($user['two_factor_enabled'] ?? 0) : 0;
$apiKeyCreatedAt = $hasApiKeyCreatedAt && !empty($user['api_key_created_at']) ? $user['api_key_created_at'] : null;

// Allowed image types and max size (2MB)
$avatarAllowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
$avatarMaxSize = 2 * 1024 * 1024;
$twoFactorSetup = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash('error', 'Invalid request. Please try again.');
        redirect(url('account-settings.php'));
    }

    if (isset($_POST['action']) && $_POST['action'] === 'change_email') {
        $newEmail = trim($_POST['new_email'] ?? '');
        $currentPassword = $_POST['current_password_email'] ?? '';
        $result = $auth->updateEmail($user['id'], $newEmail, $currentPassword);
        if ($result['success']) {
            flash('success', 'Email updated successfully.');
            redirect(url('account-settings.php'));
        }
        $errors['email'] = $result['error'];
    }

    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($newPass !== $confirm) {
            $errors['password'] = 'New password and confirmation do not match.';
        } else {
            $result = $auth->updatePassword($user['id'], $current, $newPass);
            if ($result['success']) {
                flash('success', 'Password changed successfully.');
                redirect($auth->postLoginRedirectUrl(['role' => $user['role'] ?? 'user']));
            }
            $errors['password'] = $result['error'];
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === '2fa_setup_start' && $hasTwoFactor && $hasTwoFactorSecret && !$twoFactorEnabled) {
        $setup = $auth->startTwoFactorSetup((int)$user['id']);
        if ($setup['success']) {
            $twoFactorSetup = $setup;
        } else {
            flash('error', $setup['error'] ?? 'Could not start 2FA setup.');
            redirect(url('account-settings.php'));
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === '2fa_setup_confirm' && $hasTwoFactor && $hasTwoFactorSecret) {
        $result = $auth->confirmTwoFactorSetup((int)$user['id'], trim($_POST['totp_code'] ?? ''));
        if ($result['success']) {
            flash('success', 'Two-factor authentication enabled.');
            redirect(url('account-settings.php'));
        }
        $errors['2fa'] = $result['error'];
        $twoFactorSetup = [
            'secret' => $_SESSION['2fa_setup_secret'] ?? '',
            'uri' => Totp::getProvisioningUri(
                (string)($_SESSION['2fa_setup_secret'] ?? ''),
                $user['email'] ?: $user['username'],
                defined('SITE_NAME') ? SITE_NAME : 'SMM Turk'
            ),
        ];
    }

    if (isset($_POST['action']) && $_POST['action'] === '2fa_disable' && $hasTwoFactor && $hasTwoFactorSecret && $twoFactorEnabled) {
        $result = $auth->disableTwoFactor(
            (int)$user['id'],
            $_POST['current_password_2fa'] ?? '',
            trim($_POST['totp_code_disable'] ?? '')
        );
        if ($result['success']) {
            flash('success', 'Two-factor authentication disabled.');
            redirect(url('account-settings.php'));
        }
        $errors['2fa'] = $result['error'];
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_timezone' && $hasTimezone) {
        $tz = trim($_POST['timezone'] ?? 'UTC');
        if (strlen($tz) > 0 && strlen($tz) <= 64) {
            $db->execute("UPDATE users SET timezone = ? WHERE id = ?", [$tz, $user['id']]);
            flash('success', 'Timezone saved.');
            redirect(url('account-settings.php'));
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_language') {
        $lang = strtolower(trim($_POST['lang'] ?? Lang::PRIMARY));
        if (in_array($lang, Lang::allowed(), true)) {
            $_SESSION['lang'] = $lang;
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (defined('SITE_URL') && str_starts_with((string) SITE_URL, 'https://'));
            setcookie('lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
            flash('success', 'Language preference saved.');
            redirect(url('account-settings.php'));
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'generate_api_key') {
        $newKey = bin2hex(random_bytes(20));
        if ($hasApiKeyCreatedAt) {
            $db->execute("UPDATE users SET api_key = ?, api_key_created_at = NOW() WHERE id = ?", [$newKey, $user['id']]);
        } else {
            $db->execute("UPDATE users SET api_key = ? WHERE id = ?", [$newKey, $user['id']]);
        }
        flash('success', 'New API key generated. Update your applications with the new key.');
        redirect(url('account-settings.php'));
    }

    if (isset($_POST['action']) && $_POST['action'] === 'upload_avatar' && isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['avatar'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!isset($avatarAllowedTypes[$mime]) || $file['size'] > $avatarMaxSize) {
            flash('error', 'Please upload a valid image (JPEG, PNG, GIF or WebP, max 2 MB).');
        } else {
            $dir = ROOT_PATH . '/uploads/avatars';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $ext = $avatarAllowedTypes[$mime];
            $filename = (int)$user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $fullPath = $dir . '/' . $filename;
            if (move_uploaded_file($file['tmp_name'], $fullPath)) {
                $oldPath = $hasAvatar && !empty($user['avatar']) ? ROOT_PATH . '/uploads/' . $user['avatar'] : null;
                $relPath = 'avatars/' . $filename;
                $db->execute("UPDATE users SET avatar = ? WHERE id = ?", [$relPath, $user['id']]);
                if ($oldPath && file_exists($oldPath)) {
                    @unlink($oldPath);
                }
                flash('success', 'Profile photo updated.');
            } else {
                flash('error', 'Could not save the image. Try again.');
            }
            redirect(url('account-settings.php'));
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'remove_avatar') {
        if ($avatarPath && file_exists(ROOT_PATH . '/uploads/' . $avatarPath)) {
            @unlink(ROOT_PATH . '/uploads/' . $avatarPath);
        }
        $db->execute("UPDATE users SET avatar = NULL WHERE id = ?", [$user['id']]);
        flash('success', 'Profile photo removed.');
        redirect(url('account-settings.php'));
    }
}

// Re-fetch user after possible updates
$user = $auth->getCurrentUser();
$userTimezone = $hasTimezone ? ($user['timezone'] ?? 'UTC') : 'UTC';
$twoFactorEnabled = $hasTwoFactor ? (int)($user['two_factor_enabled'] ?? 0) : 0;
$apiKeyCreatedAt = $hasApiKeyCreatedAt && !empty($user['api_key_created_at']) ? $user['api_key_created_at'] : null;
$avatarPath = $hasAvatar && !empty(trim($user['avatar'] ?? '')) ? trim($user['avatar']) : null;

if ($twoFactorSetup === null && !$twoFactorEnabled && !empty($_SESSION['2fa_setup_secret']) && (int)($_SESSION['2fa_setup_user_id'] ?? 0) === (int)$user['id']) {
    $twoFactorSetup = [
        'secret' => (string)$_SESSION['2fa_setup_secret'],
        'uri' => Totp::getProvisioningUri(
            (string)$_SESSION['2fa_setup_secret'],
            $user['email'] ?: $user['username'],
            defined('SITE_NAME') ? SITE_NAME : 'SMM Turk'
        ),
    ];
}

$timezones = [
    'UTC' => '(UTC) Greenwich Mean Time, Western European Time',
    'Europe/Istanbul' => '(UTC+3) Turkey',
    'America/New_York' => '(UTC-5) Eastern Time',
    'Europe/London' => '(UTC+0) London',
    'Asia/Dubai' => '(UTC+4) Dubai',
    'Asia/Tehran' => '(UTC+3:30) Tehran',
];

$apiKeyMasked = substr($user['api_key'] ?? '', 0, 8) . '•••••••••••••';
$apiKeyFull = $user['api_key'] ?? '';
$isAdmin = $auth->isAdmin();
$dashLang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? Lang::PRIMARY);
if (!in_array($dashLang, Lang::allowed(), true)) {
    $dashLang = Lang::PRIMARY;
}
$userBalance = number_format((float) ($user['balance'] ?? 0), 3);
$userSpent = number_format((float) ($user['spent'] ?? 0), 3);
$memberSince = !empty($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : '—';

$adminLinks = [
    ['url' => 'admin/index.php', 'icon' => 'grid', 'title' => 'Admin Dashboard', 'desc' => 'Stats, charts, quick actions'],
    ['url' => 'admin/admin-settings.php', 'icon' => 'settings', 'title' => 'Site & Payments', 'desc' => 'API keys, gateways, crypto wallets, mail'],
    ['url' => 'admin/admin-sync.php', 'icon' => 'sync', 'title' => 'Sync Services', 'desc' => 'SMM Turk One / Pro catalog & dedupe'],
    ['url' => 'admin/admin-services.php', 'icon' => 'services', 'title' => 'Services', 'desc' => 'Edit markup, enable/disable services'],
    ['url' => 'admin/admin-orders.php', 'icon' => 'orders', 'title' => 'Orders', 'desc' => 'All customer orders'],
    ['url' => 'admin/admin-deposits.php', 'icon' => 'deposit', 'title' => 'Deposits', 'desc' => 'Pending crypto top-ups'],
    ['url' => 'admin/admin-users.php', 'icon' => 'users', 'title' => 'Users', 'desc' => 'Ban, balance, roles'],
    ['url' => 'admin/admin-tickets.php', 'icon' => 'tickets', 'title' => 'Support Tickets', 'desc' => 'Reply to customer tickets'],
    ['url' => 'admin/admin-child-panels.php', 'icon' => 'server', 'title' => 'Child Panels', 'desc' => 'Activate reseller panels'],
    ['url' => 'admin/admin-mail.php', 'icon' => 'message', 'title' => 'Mail', 'desc' => 'SMTP test & templates'],
    ['url' => 'admin/admin-blog.php', 'icon' => 'clipboard', 'title' => 'Blog', 'desc' => 'SEO posts & content'],
];

require_once __DIR__ . '/layouts/header.php';
?>

<style>
.settings-hub { max-width: 980px; }
.settings-hub .as-hero { margin-bottom: 22px; }
.settings-hub .as-hero-top { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 8px; }
.settings-hub .as-hero-title { display: flex; align-items: center; gap: 10px; font-size: 1.35rem; font-weight: 800; color: var(--text); margin: 0; }
.settings-hub .as-hero-title svg { width: 26px; height: 26px; color: var(--primary); flex-shrink: 0; }
.settings-hub .as-hero-sub { font-size: 13px; color: var(--text-muted); line-height: 1.55; max-width: 560px; margin: 0; }
.settings-hub .as-role-badge { display: inline-flex; align-items: center; padding: 6px 12px; border-radius: 999px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; background: rgba(227,10,23,.12); color: var(--primary); border: 1px solid rgba(227,10,23,.25); }
.settings-hub .as-role-badge.is-admin { background: rgba(139,92,246,.14); color: #7c3aed; border-color: rgba(139,92,246,.3); }
body.theme-dark .settings-hub .as-role-badge.is-admin { color: #c4b5fd; }
.settings-hub .as-stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 22px; }
.settings-hub .as-stat { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
.settings-hub .as-stat-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); margin-bottom: 4px; }
.settings-hub .as-stat-value { font-family: 'Syne', sans-serif; font-size: 1.1rem; font-weight: 800; color: var(--text); }
.settings-hub .as-admin-panel { background: linear-gradient(135deg, rgba(139,92,246,.1) 0%, rgba(227,10,23,.06) 100%); border: 1px solid var(--border); border-radius: 16px; padding: 20px 22px; margin-bottom: 22px; }
body.theme-dark .settings-hub .as-admin-panel { background: linear-gradient(135deg, rgba(139,92,246,.16) 0%, rgba(227,10,23,.1) 100%); border-color: rgba(255,255,255,.1); }
.settings-hub .as-admin-panel h2 { font-size: 15px; font-weight: 800; color: var(--text); margin: 0 0 6px; display: flex; align-items: center; gap: 8px; }
.settings-hub .as-admin-panel p { font-size: 13px; color: var(--text-muted); margin: 0 0 16px; line-height: 1.5; }
.settings-hub .as-admin-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
.settings-hub .as-admin-link { display: flex; flex-direction: column; gap: 4px; padding: 14px 16px; border-radius: 12px; background: var(--white); border: 1px solid var(--border); text-decoration: none; color: inherit; transition: border-color .2s, transform .2s, box-shadow .2s; }
.settings-hub .as-admin-link:hover { border-color: var(--primary); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(227,10,23,.1); }
.settings-hub .as-admin-link strong { font-size: 13px; color: var(--text); display: flex; align-items: center; gap: 8px; }
.settings-hub .as-admin-link span { font-size: 11px; color: var(--text-muted); line-height: 1.4; }
.settings-hub .as-quick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; margin-bottom: 22px; }
.settings-hub .as-quick-link { display: inline-flex; align-items: center; gap: 8px; padding: 12px 14px; border-radius: 12px; background: var(--white); border: 1px solid var(--border); text-decoration: none; color: var(--text); font-size: 13px; font-weight: 600; transition: border-color .2s, color .2s; }
.settings-hub .as-quick-link:hover { border-color: var(--primary); color: var(--primary); }
.settings-hub .settings-layout { display: grid; grid-template-columns: 200px 1fr; gap: 22px; align-items: start; }
.settings-hub .settings-nav { position: sticky; top: 88px; display: flex; flex-direction: column; gap: 4px; padding: 12px; background: var(--white); border: 1px solid var(--border); border-radius: 14px; }
.settings-hub .settings-nav a { padding: 10px 12px; border-radius: 10px; font-size: 12px; font-weight: 600; color: var(--text-muted); text-decoration: none; transition: background .15s, color .15s; }
.settings-hub .settings-nav a:hover { background: rgba(227,10,23,.08); color: var(--primary); }
.settings-hub .as-card { background: var(--white); border-radius: 16px; padding: 22px; margin-bottom: 20px; border: 1px solid var(--border); scroll-margin-top: 88px; }
.settings-hub .as-card h2 { display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 16px; }
.settings-hub .as-card h2 svg { width: 20px; height: 20px; color: var(--primary); opacity: .9; }
.settings-hub .as-profile-summary { display: flex; align-items: center; gap: 16px; padding: 18px 20px; background: linear-gradient(135deg, rgba(227,10,23,.06) 0%, rgba(227,10,23,.02) 100%); border-radius: 14px; border: 1px solid var(--border); margin-bottom: 20px; }
a.as-profile-summary-link { text-decoration: none; color: inherit; transition: border-color .2s, box-shadow .2s; }
a.as-profile-summary-link:hover { border-color: rgba(227,10,23,.25); box-shadow: 0 4px 16px rgba(227,10,23,.08); }
.settings-hub .as-change-photo { display: inline-block; margin-top: 6px; font-size: 11px; font-weight: 600; color: var(--primary); }
.settings-hub .as-file-input { cursor: pointer; }
.settings-hub .as-profile-summary .as-avatar { width: 56px; height: 56px; min-width: 56px; border-radius: 50%; overflow: hidden; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; font-size: 22px; font-weight: 700; }
.settings-hub .as-profile-summary .as-avatar img { width: 100%; height: 100%; object-fit: cover; }
.settings-hub .as-profile-summary .as-info { min-width: 0; }
.settings-hub .as-profile-summary .as-name { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
.settings-hub .as-profile-summary .as-email { font-size: 13px; color: var(--text-muted); }
.settings-hub .as-section-divider { height: 1px; background: var(--border); margin: 20px 0; border: 0; }
.settings-hub .form-group { margin-bottom: 14px; }
.settings-hub .form-group:last-of-type { margin-bottom: 18px; }
.settings-hub .as-api-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
.settings-hub .as-api-row input { flex: 1; min-width: 180px; font-family: monospace; font-size: 13px; }
.settings-hub .as-api-copy { padding: 10px 16px; font-size: 13px; white-space: nowrap; }
.settings-hub .as-avatar-block { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 20px; }
.settings-hub .as-avatar-preview { flex-shrink: 0; }
.settings-hub .as-avatar-preview img { width: 88px; height: 88px; border-radius: 50%; object-fit: cover; border: 3px solid var(--border); display: block; }
.settings-hub .as-avatar-placeholder { width: 88px; height: 88px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--accent)); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 32px; font-weight: 700; }
.settings-hub .as-avatar-actions { flex: 1; min-width: 200px; }
.settings-hub .as-avatar-btn-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 4px; }
.settings-hub .as-avatar-btn-row form { margin: 0; display: inline-flex; }
.settings-hub .as-hint { font-size: 12px; color: var(--text-muted); margin-top: 6px; margin-bottom: 12px; line-height: 1.5; }
.settings-hub .alert { margin-bottom: 14px; }
.settings-hub .as-pref-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.settings-hub .as-pref-row .form-group { flex: 1; min-width: 180px; margin-bottom: 0; }
body.theme-dark .settings-hub .as-card,
body.theme-dark .settings-hub .as-stat,
body.theme-dark .settings-hub .settings-nav,
body.theme-dark .settings-hub .as-admin-link,
body.theme-dark .settings-hub .as-quick-link { box-shadow: 0 2px 12px rgba(0,0,0,.2); }
body.theme-dark .settings-hub .card .form-label { color: #c9b4b9; }
@media (max-width: 860px) {
  .settings-hub .settings-layout { grid-template-columns: 1fr; }
  .settings-hub .settings-nav { position: static; flex-direction: row; flex-wrap: wrap; }
}
</style>

<div class="settings-hub">
  <div class="as-hero">
    <div class="as-hero-top">
      <h1 class="as-hero-title">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        Settings
      </h1>
      <span class="as-role-badge <?= $isAdmin ? 'is-admin' : '' ?>"><?= $isAdmin ? 'Administrator' : 'Customer' ?></span>
    </div>
    <p class="as-hero-sub">Manage your account, security, API access<?= $isAdmin ? ', and panel administration' : '' ?>. Site-wide payment and provider settings are in <strong>Admin → Site &amp; Payments</strong><?= $isAdmin ? '' : ' (admin only)' ?>.</p>
  </div>

  <div class="as-stats-row">
    <div class="as-stat"><div class="as-stat-label">Balance</div><div class="as-stat-value">$<?= h($userBalance) ?></div></div>
    <div class="as-stat"><div class="as-stat-label">Spent</div><div class="as-stat-value">$<?= h($userSpent) ?></div></div>
    <div class="as-stat"><div class="as-stat-label">Member since</div><div class="as-stat-value" style="font-size:14px;"><?= h($memberSince) ?></div></div>
    <div class="as-stat"><div class="as-stat-label">Referral code</div><div class="as-stat-value" style="font-size:14px;"><?= h($user['referral_code'] ?? '—') ?></div></div>
  </div>

  <?php if ($isAdmin): ?>
  <section class="as-admin-panel" id="panel-admin">
    <h2><?= icon('shield', 18) ?> Panel administration</h2>
    <p>Configure the website, payment gateways, service catalogs, users, and support from here.</p>
    <div class="as-admin-grid">
      <?php foreach ($adminLinks as $link): ?>
      <a class="as-admin-link" href="<?= h(admin_path($link['url'])) ?>">
        <strong><?= icon($link['icon'], 16) ?> <?= h($link['title']) ?></strong>
        <span><?= h($link['desc']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php else: ?>
  <div class="alert alert-info" style="margin-bottom:22px;">
    <strong>Panel configuration</strong> (payment gateways, SMM provider API, mail, maintenance) is managed by administrators.
    Need changes? <a href="<?= h(path('tickets.php')) ?>">Open a support ticket</a>.
  </div>
  <?php endif; ?>

  <div class="as-quick-grid">
    <a class="as-quick-link" href="<?= h(path('api-page.php')) ?>"><?= icon('api', 16) ?> API docs</a>
    <a class="as-quick-link" href="<?= h(dashboard_path()) ?>"><?= icon('plus', 16) ?> New order</a>
    <a class="as-quick-link" href="<?= h(path('orders.php')) ?>"><?= icon('orders', 16) ?> My orders</a>
    <a class="as-quick-link" href="<?= h(path('affiliates.php')) ?>"><?= icon('users', 16) ?> Affiliates</a>
    <a class="as-quick-link" href="<?= h(path('tickets.php')) ?>"><?= icon('tickets', 16) ?> Support</a>
    <a class="as-quick-link" href="<?= h(path('add-funds.php')) ?>"><?= icon('wallet', 16) ?> Add funds</a>
  </div>

  <div class="settings-layout">
    <nav class="settings-nav" aria-label="Settings sections">
      <a href="#profile-photo">Profile</a>
      <a href="#preferences">Preferences</a>
      <a href="#email-settings">Email</a>
      <a href="#password-settings">Password</a>
      <?php if ($hasTwoFactor): ?><a href="#security-2fa">2FA</a><?php endif; ?>
      <a href="#api-settings">API key</a>
      <?php if ($isAdmin): ?><a href="#panel-admin">Admin panel</a><?php endif; ?>
    </nav>

    <div class="settings-content">
  <a href="#profile-photo" class="as-profile-summary as-profile-summary-link">
    <div class="as-avatar">
      <?php if ($avatarPath): ?>
      <img src="<?= h(path('uploads/' . $avatarPath)) ?>?v=<?= time() ?>" alt="">
      <?php else: ?>
      <?= strtoupper(substr($user['username'] ?? 'U', 0, 1)) ?>
      <?php endif; ?>
    </div>
    <div class="as-info">
      <div class="as-name"><?= h($user['username'] ?? '') ?></div>
      <div class="as-email"><?= h($user['email'] ?? '') ?></div>
      <span class="as-change-photo">Change profile photo ↓</span>
    </div>
  </a>

  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
    <a href="<?= h(path('add-funds.php')) ?>" class="btn btn-primary btn-sm">Add Funds</a>
    <a href="<?= h(path('dashboard.php')) ?>" class="btn btn-sm">New Order</a>
    <a href="<?= h(path('earn.php')) ?>" class="btn btn-sm">Earn Money</a>
    <a href="<?= h(path('orders.php')) ?>" class="btn btn-sm">Orders</a>
  </div>

  <?php if ($forcePasswordChange): ?>
  <div class="alert alert-error" style="margin-bottom:20px;">
    For security, you must change the default admin password before using the panel.
  </div>
  <?php endif; ?>

  <?php if (!($hasTimezone || $hasTwoFactor || $hasApiKeyCreatedAt || $hasAvatar)): ?>
  <div class="alert alert-error">
    Could not enable profile settings automatically. Run <code>php migrate-db.php</code> on the server.
  </div>
  <?php endif; ?>

  <!-- Profile photo -->
  <div class="as-card" id="profile-photo">
    <h2>
      <?= icon('users', 20) ?>
      Profile photo
    </h2>
    <div class="as-avatar-block">
      <div class="as-avatar-preview" id="avatarPreviewWrap">
        <?php if ($avatarPath): ?>
        <img id="avatarPreviewImg" src="<?= h(path('uploads/' . $avatarPath)) ?>?v=<?= time() ?>" alt="Profile" width="88" height="88">
        <?php else: ?>
        <div class="as-avatar-placeholder" id="avatarPreviewPlaceholder"><?= strtoupper(substr($user['username'] ?? 'U', 0, 1)) ?></div>
        <?php endif; ?>
      </div>
      <div class="as-avatar-actions">
        <form method="post" enctype="multipart/form-data" id="avatarUploadForm">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="upload_avatar">
          <div class="form-group" style="margin-bottom:10px;">
            <label class="form-label" for="avatarFile">Choose image</label>
            <input type="file" name="avatar" id="avatarFile" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control as-file-input" required>
          </div>
          <p class="as-hint">JPEG, PNG, GIF or WebP — max 2 MB. Shown in sidebar after upload.</p>
        </form>
        <div class="as-avatar-btn-row">
          <button type="submit" form="avatarUploadForm" class="btn btn-primary"><?= icon('plus', 16) ?> Upload photo</button>
          <?php if ($avatarPath): ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="remove_avatar">
            <button type="submit" class="btn btn-danger">Remove photo</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Email -->
  <div class="as-card" id="email-settings">
    <h2>
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
      Email address
    </h2>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="change_email">
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Username</label>
          <input type="text" class="form-control" value="<?= h($user['username'] ?? '') ?>" readonly disabled>
        </div>
        <div class="form-group">
          <label class="form-label">Email</label>
          <input type="email" name="new_email" class="form-control" value="<?= h($user['email'] ?? '') ?>" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Current password</label>
        <input type="password" name="current_password_email" class="form-control" placeholder="Enter current password" required>
      </div>
      <?php if (!empty($errors['email'])): ?>
      <div class="alert alert-error"><?= h($errors['email']) ?></div>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary">Update email</button>
    </form>
  </div>

  <!-- Password -->
  <div class="as-card" id="password-settings">
    <h2>
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
      Password
    </h2>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="change_password">
      <div class="form-group">
        <label class="form-label">Current password</label>
        <input type="password" name="current_password" class="form-control" required>
      </div>
      <div class="form-group">
        <label class="form-label">New password</label>
        <input type="password" name="new_password" class="form-control" minlength="6" required>
      </div>
      <div class="form-group">
        <label class="form-label">Confirm new password</label>
        <input type="password" name="confirm_password" class="form-control" minlength="6" required>
      </div>
      <?php if (!empty($errors['password'])): ?>
      <div class="alert alert-error"><?= h($errors['password']) ?></div>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary">Change password</button>
    </form>
  </div>

  <?php if ($hasTwoFactor): ?>
  <div class="as-card" id="security-2fa">
    <h2>
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
      Two-factor authentication
    </h2>
    <?php if (!$hasTwoFactorSecret): ?>
    <p class="as-hint">Run <code>php migrate-two-factor.php</code> once to enable authenticator-based 2FA.</p>
    <?php elseif ($twoFactorEnabled): ?>
    <p class="as-hint" style="margin-bottom:14px;">2FA is <strong>enabled</strong>. Sign-in requires a code from your authenticator app.</p>
    <?php if (!empty($errors['2fa'])): ?><div class="alert alert-error"><?= h($errors['2fa']) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="2fa_disable">
      <div class="form-group">
        <label class="form-label">Current password</label>
        <input type="password" name="current_password_2fa" class="form-control" required>
      </div>
      <div class="form-group">
        <label class="form-label">Authenticator code</label>
        <input type="text" name="totp_code_disable" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="000000">
      </div>
      <button type="submit" class="btn btn-danger">Disable 2FA</button>
    </form>
    <?php elseif ($twoFactorSetup): ?>
    <p class="as-hint">Scan this QR code with Google Authenticator, Authy, or a compatible app. Then enter the 6-digit code to confirm.</p>
    <div style="text-align:center;margin:16px 0;">
      <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&amp;data=<?= h(rawurlencode($twoFactorSetup['uri'])) ?>" width="180" height="180" alt="2FA QR code">
    </div>
    <p class="as-hint" style="word-break:break-all;">Manual key: <code><?= h($twoFactorSetup['secret']) ?></code></p>
    <?php if (!empty($errors['2fa'])): ?><div class="alert alert-error"><?= h($errors['2fa']) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="2fa_setup_confirm">
      <div class="form-group">
        <label class="form-label">6-digit code</label>
        <input type="text" name="totp_code" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="000000">
      </div>
      <button type="submit" class="btn btn-primary">Confirm &amp; enable</button>
    </form>
    <?php else: ?>
    <p class="as-hint" style="margin-bottom:14px;">Add an extra layer of security with an authenticator app (Google Authenticator, Authy, etc.).</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="2fa_setup_start">
      <button type="submit" class="btn btn-primary">Enable 2FA</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="as-card" id="preferences">
    <h2><?= icon('globe', 20) ?> Preferences</h2>
    <form method="post" class="as-pref-row" style="margin-bottom:16px;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_language">
      <div class="form-group">
        <label class="form-label">Dashboard language</label>
        <select name="lang" class="form-control">
          <option value="tr" <?= $dashLang === 'tr' ? 'selected' : '' ?>>Türkçe</option>
          <option value="en" <?= $dashLang === 'en' ? 'selected' : '' ?>>English</option>
          <option value="de" <?= $dashLang === 'de' ? 'selected' : '' ?>>Deutsch</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Save language</button>
    </form>
    <p class="as-hint" style="margin-bottom:14px;">Theme: use the <strong>sun/moon</strong> icon in the top bar to switch light / dark mode.</p>
    <?php if ($hasTimezone): ?>
    <hr class="as-section-divider">
    <form method="post" class="as-pref-row">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_timezone">
      <div class="form-group">
        <label class="form-label">Timezone</label>
        <select name="timezone" class="form-control">
          <?php foreach ($timezones as $tz => $label): ?>
          <option value="<?= h($tz) ?>" <?= $userTimezone === $tz ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Save timezone</button>
    </form>
    <?php endif; ?>
  </div>

  <!-- API Key -->
  <div class="as-card" id="api-settings">
    <h2>
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
      API Key
    </h2>
    <div class="form-group" style="margin-bottom:8px;">
      <label class="form-label">Your API Key</label>
      <div class="as-api-row">
        <input type="text" id="apiKeyInput" class="form-control" value="<?= h($apiKeyMasked) ?>" readonly data-full="<?= h($apiKeyFull) ?>" data-masked="<?= h($apiKeyMasked) ?>">
        <button type="button" class="btn btn-primary as-api-copy" id="apiKeyCopyBtn">Copy</button>
      </div>
    </div>
    <?php if ($apiKeyCreatedAt): ?>
    <p class="as-hint">Created: <?= h(date('Y-m-d H:i', strtotime($apiKeyCreatedAt))) ?></p>
    <?php endif; ?>
    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="generate_api_key">
      <button type="submit" class="btn btn-primary">Generate new API key</button>
    </form>
    <p class="as-hint" style="margin-top:10px;">Regenerating will invalidate the current key. Update your apps with the new key. API endpoint: <code><?= h(defined('SITE_URL') ? rtrim(SITE_URL, '/') . '/api/v2' : '/api/v2') ?></code></p>
  </div>
    </div><!-- .settings-content -->
  </div><!-- .settings-layout -->
</div><!-- .settings-hub -->

<script>
(function(){
  var inp = document.getElementById('apiKeyInput');
  var btn = document.getElementById('apiKeyCopyBtn');
  if (inp && btn) {
    var full = inp.getAttribute('data-full') || '';
    var masked = inp.getAttribute('data-masked') || '';
    btn.addEventListener('click', function(){
      var text = full && full.length > 8 ? full : (inp.value || '');
      if (!text) return;
      navigator.clipboard.writeText(text).then(function(){
        btn.textContent = 'Copied!';
        btn.style.background = 'var(--green)';
        setTimeout(function(){ btn.textContent = 'Copy'; btn.style.background = ''; }, 2000);
      });
    });
    inp.addEventListener('focus', function(){ this.value = full || masked; this.select(); });
    inp.addEventListener('blur', function(){ if (full && full.length > 8) this.value = masked; });
  }

  var fileInput = document.getElementById('avatarFile');
  var previewWrap = document.getElementById('avatarPreviewWrap');
  if (fileInput && previewWrap) {
    fileInput.addEventListener('change', function(){
      var file = this.files && this.files[0];
      if (!file || !file.type.match(/^image\//)) return;
      var url = URL.createObjectURL(file);
      previewWrap.innerHTML = '<img id="avatarPreviewImg" src="' + url + '" alt="Preview" width="88" height="88" style="width:88px;height:88px;border-radius:50%;object-fit:cover;border:3px solid var(--border);">';
    });
  }
})();
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
