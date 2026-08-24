<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/RateLimit.php';

if ($auth->isLoggedIn()) {
    redirect(url('dashboard.php'));
}
store_login_next($_GET['next'] ?? null);
$siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');

$mode  = $_GET['mode'] ?? 'login';
$error = '';
$success = '';
$db = Database::getInstance();
$googleLoginEnabled = defined('GOOGLE_CLIENT_ID') && trim(GOOGLE_CLIENT_ID) !== '';
$rateLimit = new RateLimit(5, 900);

// Flash from redirect (e.g. Google callback error)
$flash = $_SESSION['flash'] ?? null;
if ($flash) {
    unset($_SESSION['flash']);
    if ($flash['type'] === 'error') $error = $flash['message'];
    else $success = $flash['message'];
}
$registrationEnabled = ($db->getSetting('registration_enabled') ?? '1') === '1';
$registerRateLimit = new RateLimit(5, 3600, 'register_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
$growth = new GrowthEngine();
$signupOffers = $growth->offerLines();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
    if (!$csrfOk) {
        $error = 'Invalid request. Please try again.';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'resend_verification') {
        $resendEmail = trim($_POST['resend_email'] ?? '');
        $resend = $auth->resendVerificationEmail($resendEmail);
        if ($resend['success']) {
            if (!empty($resend['email_sent'])) {
                $success = 'Verification email sent! Check your inbox and click the link to activate your account.';
            } else {
                $success = 'If that email has a pending account, a new verification link was sent. Check your inbox and spam folder.';
            }
            $_SESSION['pending_verify_email'] = strtolower($resendEmail);
            $mode = 'login';
        } else {
            $error = $resend['error'] ?? 'Could not resend verification email.';
        }
    } elseif ($mode === 'login') {
        $loginId = strtolower(trim($_POST['email'] ?? ''));
        $accountRateLimit = $loginId !== '' ? new RateLimit(8, 900, 'login_user_' . md5($loginId)) : null;
        if ($rateLimit->isLimited() || ($accountRateLimit && $accountRateLimit->isLimited())) {
            $error = 'Too many login attempts. Please try again in 15 minutes.';
        } else {
            $loginInput = trim($_POST['email'] ?? '');
            if (str_contains($loginInput, '@')) {
                $loginInput = strtolower($loginInput);
            }
            $result = $auth->login($loginInput, $_POST['password'] ?? '');
            if ($result['success']) {
                $rateLimit->clear();
                if ($accountRateLimit) {
                    $accountRateLimit->clear();
                }
                if (!empty($result['needs_2fa'])) {
                    redirect(url('login-2fa.php'));
                }
                redirect($auth->postLoginRedirectUrl($result));
            } else {
                $rateLimit->recordAttempt();
                if ($accountRateLimit) {
                    $accountRateLimit->recordAttempt();
                }
                $error = $result['error'];
            }
        }
    } elseif ($mode === 'register') {
        if (!$registrationEnabled) {
            $error = 'Registration is currently disabled.';
        } elseif ($registerRateLimit->isLimited()) {
            $error = 'Too many registration attempts. Please try again in 1 hour.';
        } else {
            $result = $auth->register(
                trim($_POST['username'] ?? ''),
                trim($_POST['email'] ?? ''),
                $_POST['password'] ?? '',
                trim($_POST['ref'] ?? '')
            );
            if ($result['success']) {
                $registerRateLimit->clear();
                if (!empty($result['verify_required'])) {
                    if (!empty($result['email_sent'])) {
                        $success = 'Account created! We sent a verification email — click the link to activate your account, then sign in.';
                    } else {
                        $success = 'Account created, but we could not send the email. Use “Resend verification email” below or contact support.';
                    }
                    $_SESSION['pending_verify_email'] = strtolower(trim($_POST['email'] ?? ''));
                    $mode = 'login';
                } else {
                    $loginResult = $auth->login(trim($_POST['email'] ?? ''), $_POST['password'] ?? '');
                    if ($loginResult['success']) {
                        if (!empty($loginResult['needs_2fa'])) {
                            redirect(url('login-2fa.php'));
                        }
                        flash('success', 'Welcome! Add funds with crypto to start placing orders.');
                        redirect($auth->postLoginRedirectUrl($loginResult));
                    }
                    $success = 'Account created! You can sign in now.';
                    $mode = 'login';
                }
            } else {
                $registerRateLimit->recordAttempt();
                $error = $result['error'];
            }
        }
    } else {
        $error = 'Invalid request.';
    }
}
// Ensure CSRF token exists for forms
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($siteName) ?> — <?= $mode === 'login' ? 'Login' : 'Register' ?></title>
<meta name="description" content="Login or register to SMM Turk — cheapest SMM panel. Crypto deposits, API, 24/7 support.">
<meta name="robots" content="<?= h(Seo::robotsContent(false)) ?>">
<?php if (defined('SITE_URL') && SITE_URL !== ''): ?>
<link rel="canonical" href="<?= h(url('login.php')) ?>">
<?= Seo::geoMetaTags('en') ?>
<?php endif; ?>
<link rel="icon" type="image/png" href="<?= h(logo_url()) ?>">
<link rel="apple-touch-icon" href="<?= h(logo_url()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#E30A17;--primary-dark:#B90812;--muted:#6b4a50;--dark:#1a0a0e;--light:#fef8f9;--white:#fff}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,sans-serif;background:linear-gradient(135deg,#1a0a0e 0%,#2d1519 50%,#1a0a0e 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
.auth-wrap{width:100%;max-width:440px}
.auth-box{background:var(--white);border-radius:24px;padding:40px;width:100%;box-shadow:0 24px 80px rgba(0,0,0,.3),0 0 0 1px rgba(255,255,255,.06)}
.auth-back{display:inline-flex;align-items:center;gap:6px;margin-bottom:16px;font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;transition:color .2s}
.auth-back:hover{color:var(--primary)}
.auth-logo{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;margin-bottom:6px}
.auth-logo img{width:52px;height:52px;border-radius:12px;flex-shrink:0;object-fit:contain}
.auth-logo .logo{font-family:'Syne',sans-serif;font-size:32px;font-weight:800;color:var(--dark);letter-spacing:-.04em;line-height:1.1;text-transform:uppercase}
.auth-logo .logo span{color:var(--primary);letter-spacing:-.02em}
.tagline{text-align:center;color:var(--muted);font-size:14px;margin-bottom:28px;font-weight:500}
.tabs{display:flex;background:rgba(227,10,23,.08);border-radius:14px;padding:5px;margin-bottom:26px}
.tab{flex:1;padding:12px 16px;text-align:center;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;color:var(--muted);text-decoration:none;transition:all .25s;font-family:'Plus Jakarta Sans',sans-serif}
.tab:hover{color:var(--dark)}
.tab.active{background:linear-gradient(145deg,var(--primary),var(--primary-dark));color:var(--white);box-shadow:0 4px 14px rgba(227,10,23,.3)}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:11px;font-weight:700;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
.input-wrap{display:flex;align-items:center;background:var(--light);border:1.5px solid #f0e6e8;border-radius:12px;overflow:hidden;transition:border-color .2s,box-shadow .2s}
.input-wrap:focus-within{border-color:var(--primary);background:var(--white);box-shadow:0 0 0 3px rgba(227,10,23,.12)}
.input-icon{width:48px;height:48px;display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0}
.input-wrap:focus-within .input-icon{color:var(--primary)}
.input-wrap .form-control{border:none;border-radius:0;padding:14px 16px 14px 0;background:transparent;font-size:15px}
.input-wrap .form-control:focus{box-shadow:none;outline:none}
.form-control{width:100%;padding:14px 16px;border:1.5px solid #f0e6e8;border-radius:12px;font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;outline:none;transition:border-color .2s;background:var(--light)}
.form-control:focus{border-color:var(--primary);background:var(--white)}
.btn{width:100%;padding:16px 20px;background:linear-gradient(145deg,var(--primary),var(--primary-dark));color:var(--white);border:none;border-radius:12px;font-family:'Syne',sans-serif;font-size:16px;font-weight:700;letter-spacing:.02em;cursor:pointer;transition:all .25s;margin-top:8px;display:inline-flex;align-items:center;justify-content:center;gap:10px;box-shadow:0 4px 14px rgba(227,10,23,.3),0 1px 0 rgba(255,255,255,.15) inset}
.btn svg{width:20px;height:20px;flex-shrink:0}
.btn:hover{background:linear-gradient(145deg,var(--primary-dark),#9a0610);transform:translateY(-2px);box-shadow:0 8px 28px rgba(227,10,23,.4)}
.btn:active{transform:translateY(0)}
.btn-register{font-size:15px}
.alert{padding:14px 16px;border-radius:12px;font-size:14px;margin-bottom:16px;font-weight:500}
.alert-error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.alert-success{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0}
.auth-divider{display:flex;align-items:center;gap:14px;margin:20px 0;font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.auth-divider::before,.auth-divider::after{content:'';flex:1;height:1px;background:#f0e6e8}
.btn-google{width:100%;padding:14px 16px;background:var(--white);color:var(--dark);border:2px solid #e5e7eb;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:10px;transition:all .25s;text-decoration:none;font-family:'Plus Jakarta Sans',sans-serif}
.btn-google:hover{background:#f9fafb;border-color:var(--primary);color:var(--primary);transform:translateY(-2px);box-shadow:0 4px 12px rgba(227,10,23,.15)}
.footer-link{text-align:center;margin-top:22px;padding-top:20px;border-top:1px solid rgba(227,10,23,.1);font-size:14px;color:var(--muted)}
.footer-link a{color:var(--primary);font-weight:700;transition:color .2s}
.footer-link a:hover{color:var(--primary-dark)}
.resend-box{margin-top:20px;padding-top:18px;border-top:1px dashed rgba(227,10,23,.15)}
.resend-box p{font-size:13px;color:var(--muted);margin-bottom:10px;line-height:1.5}
.resend-box .btn{margin-top:0;font-size:14px;padding:12px 16px}
@keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.auth-box{animation:fadeInUp .45s ease both}
@media(max-width:480px){.auth-box{padding:28px 22px;border-radius:20px}.auth-logo .logo{font-size:26px}.auth-logo img{width:44px;height:44px}}
@media(prefers-reduced-motion:reduce){.auth-box{animation:none}.btn:hover{transform:none}}
</style>
</head>
<body>

<div class="auth-wrap">
<div class="auth-box">
  <a href="<?= h(home_path()) ?>" class="auth-back">← Back to Home</a>
  <div class="auth-logo">
    <img src="<?= h(logo_url()) ?>" alt="" width="52" height="52">
    <span class="logo"><?= site_name_logo_html() ?></span>
  </div>
  <div class="tagline">Social Media Marketing Panel</div>

  <div class="tabs">
    <a href="?mode=login" class="tab <?= $mode === 'login' ? 'active' : '' ?>">Login</a>
    <?php if ($registrationEnabled): ?>
    <a href="?mode=register" class="tab <?= $mode === 'register' ? 'active' : '' ?>">Register</a>
    <?php endif; ?>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <?php if ($mode === 'login'): ?>
  <?php if ($googleLoginEnabled): ?>
  <a href="<?= h(path('login-google.php')) ?>" class="btn-google">
    <svg width="20" height="20" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
    Sign in with Google
  </a>
  <div class="auth-divider">or</div>
  <?php endif; ?>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <div class="form-group">
      <label class="form-label">Email or Username</label>
      <div class="input-wrap">
        <span class="input-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
        <input type="text" name="email" class="form-control" placeholder="your@email.com" required autofocus>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Password</label>
      <div class="input-wrap">
        <span class="input-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
      </div>
    </div>
    <div style="margin-bottom:12px;"><a href="<?= h(path('forgot-password.php')) ?>" style="font-size:14px;color:var(--primary);font-weight:600;text-decoration:none;">Forgot password?</a></div>
    <button type="submit" class="btn">Sign in to Dashboard <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg></button>
  </form>
  <div class="resend-box">
    <p>Didn’t get the verification email? Enter your address and we’ll send a new activation link.</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="resend_verification">
      <div class="form-group" style="margin-bottom:10px">
        <label class="form-label">Email for verification</label>
        <input type="email" name="resend_email" class="form-control" placeholder="your@email.com" required value="<?= htmlspecialchars($_SESSION['pending_verify_email'] ?? '') ?>">
      </div>
      <button type="submit" class="btn">Resend verification email</button>
    </form>
  </div>
  <?php else: ?>
  <?php if (!empty($signupOffers)): ?>
  <div style="background:rgba(227,10,23,.06);border-radius:12px;padding:12px 14px;margin-bottom:16px;font-size:12px;line-height:1.65;color:var(--dark);">
    <strong style="display:block;margin-bottom:6px;">Why join now:</strong>
    <?php foreach ($signupOffers as $offer): ?>✓ <?= h($offer) ?><br><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($googleLoginEnabled): ?>
  <a href="<?= h(path('login-google.php')) ?>" class="btn-google" style="margin-bottom:16px;">
    <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
    Sign up with Google
  </a>
  <div class="auth-divider">— or register with email —</div>
  <?php endif; ?>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <div class="form-group">
      <label class="form-label">Username</label>
      <div class="input-wrap">
        <span class="input-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
        <input type="text" name="username" class="form-control" placeholder="johndoe" required minlength="3">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Email</label>
      <div class="input-wrap">
        <span class="input-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
        <input type="email" name="email" class="form-control" placeholder="your@email.com" required>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Password</label>
      <div class="input-wrap">
        <span class="input-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
        <input type="password" name="password" class="form-control" placeholder="Min 6 characters" required minlength="6">
      </div>
    </div>
    <?php if (isset($_GET['ref'])): ?>
    <input type="hidden" name="ref" value="<?= htmlspecialchars($_GET['ref']) ?>">
    <?php endif; ?>
    <button type="submit" class="btn btn-register">Create Account</button>
  </form>
  <p style="margin-top:14px;font-size:13px;color:var(--muted);line-height:1.5;text-align:center">After registering, check your email and click the verification link to activate your account.</p>
  <?php endif; ?>

  <div class="footer-link">
    <?php if ($mode === 'login'): ?>
      <?php if ($registrationEnabled): ?>Don't have an account? <a href="?mode=register">Register</a><?php endif; ?>
    <?php else: ?>
      Already have an account? <a href="?mode=login">Login</a>
    <?php endif; ?>
  </div>
</div>
</div>

<?php require __DIR__ . '/partials/a11y.php'; ?>
</body>
</html>
