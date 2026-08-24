<?php
require_once __DIR__ . '/app/init.php';

if ($auth->isLoggedIn()) {
    redirect(url('dashboard.php'));
}
if (!$auth->hasPendingTwoFactor()) {
    redirect(url('login.php'));
}

$siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $code = trim($_POST['code'] ?? '');
        $result = $auth->completeTwoFactorLogin($code);
        if ($result['success']) {
            redirect($auth->postLoginRedirectUrl($result));
        }
        $error = $result['error'] ?? 'Verification failed.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="<?= h(Seo::robotsContent(false)) ?>">
<meta name="robots" content="<?= h(Seo::robotsContent(false)) ?>">
<title><?= h($siteName) ?> — Two-factor authentication</title>
<link rel="icon" type="image/png" href="<?= h(logo_url()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#E30A17;--primary-dark:#B90812;--muted:#6b4a50;--dark:#1a0a0e;--light:#fef8f9;--white:#fff}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Plus Jakarta Sans',sans-serif;background:linear-gradient(135deg,#1a0a0e 0%,#2d1519 50%,#1a0a0e 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.auth-wrap{width:100%;max-width:420px}
.auth-box{background:var(--white);border-radius:24px;padding:36px;box-shadow:0 24px 80px rgba(0,0,0,.3)}
.auth-box h1{font-family:'Syne',sans-serif;font-size:22px;margin-bottom:8px;color:var(--dark)}
.auth-box p{color:var(--muted);font-size:14px;line-height:1.6;margin-bottom:20px}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:11px;font-weight:700;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
.form-control{width:100%;padding:14px 16px;border:1.5px solid #f0e6e8;border-radius:12px;font-size:20px;letter-spacing:.3em;text-align:center;font-family:monospace}
.form-control:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 3px rgba(227,10,23,.12)}
.btn{width:100%;padding:14px;background:linear-gradient(145deg,var(--primary),var(--primary-dark));color:#fff;border:none;border-radius:12px;font-family:'Syne',sans-serif;font-size:15px;font-weight:700;cursor:pointer;margin-top:8px}
.btn:hover{opacity:.95}
.alert{padding:12px 14px;border-radius:10px;font-size:13px;margin-bottom:14px;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.back-link{display:inline-block;margin-top:16px;font-size:13px;color:var(--muted);text-decoration:none}
.back-link:hover{color:var(--primary)}
</style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-box">
    <h1>Two-factor authentication</h1>
    <p>Enter the 6-digit code from your authenticator app to continue.</p>
    <?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="one-time-code">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <div class="form-group">
        <label class="form-label" for="code">Authentication code</label>
        <input type="text" id="code" name="code" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code">
      </div>
      <button type="submit" class="btn">Verify &amp; sign in</button>
    </form>
    <a href="<?= h(path('login.php')) ?>" class="back-link">← Back to login</a>
  </div>
</div>
</body>
</html>
