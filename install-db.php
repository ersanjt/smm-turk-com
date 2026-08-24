<?php
/**
 * SMM Turk - Database Connection Setup
 * آپلود این فایل به public_html، پر کردن فرم، تست و ذخیره config
 * بعد از موفقیت این فایل را حذف کن!
 */
header('Content-Type: text/html; charset=utf-8');

$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html dir="rtl" lang="fa"><head><meta charset="UTF-8"><title>غیرفعال</title></head><body style="font-family:tahoma;background:#1a1a2e;color:#eee;padding:40px;text-align:center;"><h1>install-db.php غیرفعال است</h1><p>فایل config.php از قبل وجود دارد. برای امنیت این فایل را از سرور حذف کنید.</p></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تنظیم دیتابیس - SMM Turk</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:tahoma,sans-serif;background:#1a1a2e;color:#eee;min-height:100vh;padding:20px;display:flex;align-items:center;justify-content:center}
        .box{background:#16213e;border-radius:12px;padding:30px;max-width:480px;width:100%;box-shadow:0 10px 40px rgba(0,0,0,.5)}
        h1{font-size:20px;margin-bottom:20px;color:#00d9ff}
        .form-group{margin-bottom:14px}
        label{display:block;font-size:12px;margin-bottom:4px;color:#aaa}
        input{width:100%;padding:10px 12px;border:1px solid #333;border-radius:8px;background:#0f0f23;color:#fff;font-size:14px}
        input:focus{outline:none;border-color:#00d9ff}
        button{width:100%;padding:12px;background:#00d9ff;color:#000;border:none;border-radius:8px;font-weight:bold;cursor:pointer;font-size:15px;margin-top:10px}
        button:hover{background:#00b8d9}
        .btn2{background:#333;color:#00d9ff;margin-top:6px}
        .msg{padding:12px;border-radius:8px;margin-top:16px;font-size:13px}
        .ok{background:#0d3320;color:#4ade80;border:1px solid #22c55e}
        .err{background:#331a1a;color:#f87171;border:1px solid #ef4444}
        .warn{background:#332a0d;color:#fbbf24;border:1px solid #f59e0b}
    </style>
</head>
<body>
<div class="box">
    <h1>🔧 تنظیم اتصال دیتابیس</h1>
    <form method="POST">
        <div class="form-group">
            <label>DB Host</label>
            <input type="text" name="host" value="<?= htmlspecialchars($_POST['host'] ?? 'localhost') ?>" placeholder="localhost">
        </div>
        <div class="form-group">
            <label>DB Name</label>
            <input type="text" name="dbname" value="<?= htmlspecialchars($_POST['dbname'] ?? 'smmturk_turk') ?>" placeholder="smmturk_turk">
        </div>
        <div class="form-group">
            <label>DB User</label>
            <input type="text" name="user" value="<?= htmlspecialchars($_POST['user'] ?? 'smmturk_turk') ?>" placeholder="smmturk_turk">
        </div>
        <div class="form-group">
            <label>DB Password</label>
            <input type="password" name="pass" value="<?= htmlspecialchars($_POST['pass'] ?? '') ?>" placeholder="رمز MySQL">
        </div>
        <button type="submit" name="action" value="test">🔍 تست اتصال</button>
        <button type="submit" name="action" value="save" class="btn2">💾 ذخیره در config.php</button>
    </form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['host']) && !empty($_POST['dbname']) && !empty($_POST['user'])) {
    $host = trim($_POST['host']);
    $db   = trim($_POST['dbname']);
    $user = trim($_POST['user']);
    $pass = $_POST['pass'] ?? '';

    try {
        $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        echo '<div class="msg ok">✅ اتصال موفق! دیتابیس کار می‌کند.</div>';

        if (isset($_POST['action']) && $_POST['action'] === 'save') {
            $config = '<?php
define(\'DB_HOST\', \'' . addslashes($host) . '\');
define(\'DB_NAME\', \'' . addslashes($db) . '\');
define(\'DB_USER\', \'' . addslashes($user) . '\');
define(\'DB_PASS\', \'' . addslashes($pass) . '\');
define(\'DB_CHARSET\', \'utf8mb4\');
define(\'SITE_URL\', \'https://smm-turk.com\');
define(\'SITE_NAME\', \'SMM Turk\');
define(\'PROVIDER_API_URL\', \'https://smmfollows.com/api/v2\');
define(\'PROVIDER_API_KEY\', \'\');
define(\'SECRET_KEY\', \'' . bin2hex(random_bytes(32)) . '\');
define(\'SESSION_LIFETIME\', 86400);
define(\'MARKUP_PERCENT\', 10);
define(\'MIN_DEPOSIT\', 10);
define(\'REFERRAL_COMMISSION\', 2);
define(\'SMM_PRODUCTION\', true);
date_default_timezone_set(\'UTC\');
error_reporting(E_ALL);
ini_set(\'display_errors\', 0);
';
            $path = __DIR__ . '/config.php';
            if (file_put_contents($path, $config)) {
                echo '<div class="msg ok">✅ config.php ذخیره شد. الان برو به <a href="login.php" style="color:#4ade80">login.php</a> — بعد این فایل install-db.php را حذف کن!</div>';
            } else {
                echo '<div class="msg err">❌ خطا در نوشتن config.php — دسترسی فایل را چک کن.</div>';
            }
        }
    } catch (PDOException $e) {
        echo '<div class="msg err">❌ خطا: ' . htmlspecialchars($e->getMessage()) . '</div>';
        echo '<div class="msg warn">نام‌ها را از cPanel دقیق کپی کن. بعضی هاست‌ها از 127.0.0.1 به‌جای localhost استفاده می‌کنند.</div>';
    }
}
?>
</div>
</body>
</html>
