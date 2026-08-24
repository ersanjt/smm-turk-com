<?php
require_once __DIR__ . '/app/init.php';
$db = Database::getInstance();

$token = trim($_GET['token'] ?? '');
$message = '';
$success = false;
$showResend = false;
$resendEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_verification') {
    $csrfOk = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
    if (!$csrfOk) {
        $message = 'Invalid request. Please try again.';
    } else {
        $resendEmail = trim($_POST['resend_email'] ?? '');
        $resend = $auth->resendVerificationEmail($resendEmail);
        if ($resend['success']) {
            if (!empty($resend['email_sent'])) {
                $message = 'A new verification email was sent. Check your inbox and click the link to activate your account.';
                $success = true;
            } else {
                $message = 'If that email has a pending account, a new link was sent. Check your inbox and spam folder.';
                $success = true;
            }
            $showResend = true;
        } else {
            $message = $resend['error'] ?? 'Could not resend verification email.';
            $showResend = true;
        }
    }
} elseif ($token !== '') {
    $user = $db->fetch(
        "SELECT id, username, email, email_verification_expires FROM users WHERE email_verification_token = ? AND status = 'pending'",
        [$token]
    );
    if ($user && $user['email_verification_expires'] && strtotime($user['email_verification_expires']) > time()) {
        $db->execute(
            "UPDATE users SET status = 'active', email_verification_token = NULL, email_verification_expires = NULL WHERE id = ?",
            [$user['id']]
        );
        if (class_exists('ChildPanelUserSync', false)) {
            ChildPanelUserSync::reportRegistration([
                'user_id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'email' => (string) $user['email'],
                'status' => 'active',
                'registered_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $loginResult = $auth->loginById((int)$user['id']);
        if ($loginResult['success']) {
            $credit = (new GrowthEngine())->grantWelcomeCredit((int) $user['id']);
            Notify::welcome($user['username'], $user['email']);
            if (!empty($loginResult['needs_2fa'])) {
                redirect(url('login-2fa.php'));
            }
            $flashMsg = 'Email verified! Welcome to your dashboard.';
            if (!empty($credit['granted'])) {
                $flashMsg .= ' You received $' . number_format((float) $credit['amount'], 2) . ' free balance.';
            }
            flash('success', $flashMsg);
            redirect($auth->postLoginRedirectUrl($loginResult));
        }
        $message = 'Email verified successfully! You can now log in.';
        $success = true;
    } elseif ($user) {
        $message = 'This verification link has expired. Request a new link below.';
        $showResend = true;
        $resendEmail = $user['email'] ?? '';
    } else {
        $message = 'Invalid or already used verification link.';
        $showResend = true;
    }
} else {
    $message = 'No verification token provided.';
    $showResend = true;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="<?= h(Seo::robotsContent(false)) ?>">
<meta name="robots" content="<?= h(Seo::robotsContent(false)) ?>">
<title><?= htmlspecialchars($siteName) ?> — Email Verification</title>
<link rel="icon" type="image/png" href="<?= h(logo_url()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Plus Jakarta Sans',sans-serif;background:linear-gradient(135deg,#1a0a0e 0%,#2d1519 50%,#1a0a0e 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{background:#fff;border-radius:24px;padding:40px;width:100%;max-width:420px;box-shadow:0 24px 80px rgba(0,0,0,.25);text-align:center}
.box-logo{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;margin-bottom:20px}
.box-logo img{width:48px;height:48px;border-radius:12px;flex-shrink:0;object-fit:contain}
.box-logo .logo{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:#1a0a0e;letter-spacing:-.04em;line-height:1.1;text-transform:uppercase}
.box-logo .logo span{color:#E30A17;letter-spacing:-.02em}
.msg{padding:14px;border-radius:12px;font-size:14px;margin-bottom:20px;text-align:left;line-height:1.5}
.msg.ok{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0}
.msg.err{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.btn{display:inline-block;padding:12px 24px;background:#E30A17;color:#fff;border-radius:12px;text-decoration:none;font-weight:700;margin-top:10px;transition:all .2s;border:none;cursor:pointer;font-family:inherit;font-size:14px;width:100%}
.btn:hover{background:#B90812;box-shadow:0 8px 24px rgba(227,10,23,.35)}
.form-label{display:block;font-size:11px;font-weight:700;color:#6b4a50;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;text-align:left}
.form-control{width:100%;padding:12px 14px;border:1.5px solid #f0e6e8;border-radius:12px;font-family:inherit;font-size:15px;margin-bottom:12px;text-align:left}
.resend{margin-top:18px;padding-top:18px;border-top:1px dashed rgba(227,10,23,.15);text-align:left}
.resend p{font-size:13px;color:#6b4a50;margin-bottom:10px;line-height:1.5}
@media(max-width:480px){.box{margin:12px;padding:24px 20px;border-radius:20px}}
</style>
</head>
<body>
<div class="box">
  <div class="box-logo">
    <img src="<?= h(logo_url()) ?>" alt="" width="48" height="48">
    <span class="logo"><?= site_name_logo_html() ?></span>
  </div>
  <div class="msg <?= $success ? 'ok' : 'err' ?>"><?= htmlspecialchars($message) ?></div>
  <?php if ($showResend): ?>
  <div class="resend">
    <p>Enter your email to receive a new activation link.</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="resend_verification">
      <label class="form-label" for="resend_email">Email</label>
      <input type="email" id="resend_email" name="resend_email" class="form-control" placeholder="your@email.com" required value="<?= htmlspecialchars($resendEmail) ?>">
      <button type="submit" class="btn">Resend verification email</button>
    </form>
  </div>
  <?php else: ?>
  <a href="<?= h(path('login.php')) ?>" class="btn">Go to Login</a>
  <?php endif; ?>
</div>
</body>
</html>
