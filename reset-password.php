<?php
require_once __DIR__ . '/app/init.php';

if ($auth->isLoggedIn()) {
    redirect(url('dashboard.php'));
}

$siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
$token = trim($_GET['token'] ?? '');
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $result = $auth->resetPasswordByToken($token, $password);
            if ($result['success']) {
                $success = true;
            } else {
                $error = $result['error'];
            }
        }
    }
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="<?= h(Seo::robotsContent(false)) ?>">
<title><?= htmlspecialchars($siteName) ?> — Set new password</title>
<link rel="icon" type="image/png" href="<?= h(logo_url()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Plus Jakarta Sans',sans-serif;background:linear-gradient(135deg,#1a0a0e 0%,#2d1519 50%,#1a0a0e 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.auth-box{background:#fff;border-radius:24px;padding:40px;width:100%;max-width:420px;box-shadow:0 24px 80px rgba(0,0,0,.25)}
.auth-logo{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;margin-bottom:8px}
.auth-logo img{width:48px;height:48px;border-radius:12px;flex-shrink:0;object-fit:contain}
.auth-logo .logo{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:#1a0a0e;letter-spacing:-.04em;line-height:1.1;text-transform:uppercase}
.auth-logo .logo span{color:#E30A17;letter-spacing:-.02em}
.tagline{text-align:center;color:#6b4a50;font-size:13px;margin-bottom:28px}
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:11px;font-weight:700;color:#6b4a50;margin-bottom:5px;text-transform:uppercase}
.form-control{width:100%;padding:12px 14px;border:1.5px solid #f0e6e8;border-radius:10px;font-size:14px;outline:none}
.form-control:focus{border-color:#E30A17}
.btn{width:100%;padding:13px;background:#E30A17;color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;margin-top:6px}
.btn:hover{background:#B90812}
.alert{padding:11px 14px;border-radius:10px;font-size:13px;margin-bottom:14px}
.alert-error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.alert-success{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0}
.back-link{display:block;text-align:center;margin-top:18px;font-size:13px;color:#6b4a50}
.back-link a{color:#E30A17;font-weight:600}
</style>
</head>
<body>
<div class="auth-box">
  <div class="auth-logo">
    <img src="<?= h(logo_url()) ?>" alt="" width="48" height="48">
    <span class="logo"><?= site_name_logo_html() ?></span>
  </div>
  <div class="tagline">Set your new password</div>
  <?php if ($success): ?>
  <div class="alert alert-success">Password updated successfully. You can now log in.</div>
  <p class="back-link"><a href="<?= h(path('login.php')) ?>">Go to Login</a></p>
  <?php elseif ($token === ''): ?>
  <div class="alert alert-error">Invalid or missing reset link. Request a new one from the login page.</div>
  <p class="back-link"><a href="<?= h(path('forgot-password.php')) ?>">Forgot password?</a> · <a href="<?= h(path('login.php')) ?>">Login</a></p>
  <?php else: ?>
  <?php if ($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <div class="form-group">
      <label class="form-label">New password (min 6 characters)</label>
      <input type="password" name="password" class="form-control" required minlength="6" autofocus>
    </div>
    <div class="form-group">
      <label class="form-label">Confirm password</label>
      <input type="password" name="password_confirm" class="form-control" required minlength="6">
    </div>
    <button type="submit" class="btn">Set new password</button>
  </form>
  <p class="back-link"><a href="<?= h(path('login.php')) ?>">Back to Login</a></p>
  <?php endif; ?>
</div>
</body>
</html>
