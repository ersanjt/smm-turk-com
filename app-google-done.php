<?php
/**
 * Bridge page after Google Sign-In from the Android app.
 * Opens the app via smmturk:// and shows a fallback button if the OS does not.
 */
require_once __DIR__ . '/app/init.php';

$token = preg_replace('/[^a-f0-9]/', '', strtolower(trim((string)($_GET['token'] ?? ''))));
$error = trim((string)($_GET['error'] ?? ''));
$appUri = $token !== '' ? MobileAuth::appUri($token) : MobileAuth::appUri('', $error !== '' ? $error : 'Google sign-in was cancelled.');
$siteName = function_exists('site_name') ? site_name() : 'SMM Turk';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($siteName) ?> — Continue in the app</title>
<?php echo_favicon_links(); ?>
<meta http-equiv="refresh" content="0;url=<?= h($appUri) ?>">
<style>
:root{--primary:#E30A17;--dark:#1a0a0e;--muted:#6b4a50}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',system-ui,sans-serif;background:#1a0a0e;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center}
.card{max-width:380px}
img{width:72px;height:72px;border-radius:18px;margin-bottom:18px}
h1{font-size:22px;margin-bottom:8px}
p{color:#c4a5ab;font-size:14px;line-height:1.55;margin-bottom:20px}
a.btn{display:inline-flex;align-items:center;justify-content:center;background:#E30A17;color:#fff;text-decoration:none;font-weight:700;padding:14px 22px;border-radius:14px}
</style>
<script>location.replace(<?= json_encode($appUri) ?>);</script>
</head>
<body>
<div class="card">
  <img src="<?= h(logo_url()) ?>" alt="">
  <h1><?= $token !== '' ? 'Opening the app…' : 'Could not sign in' ?></h1>
  <p><?= $token !== '' ? 'Return to SMM Turk to finish Google Sign-In.' : h($error !== '' ? $error : 'Google sign-in was cancelled.') ?></p>
  <a class="btn" href="<?= h($appUri) ?>">Open SMM Turk app</a>
</div>
</body>
</html>
