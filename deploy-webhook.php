<?php
/**
 * GitHub Webhook — دیپلوی خودکار بعد از push به main
 *
 * این فایل را داخل public_html روی سرور قرار بده. یک فایل deploy-secret.txt هم
 * بساز (در همان پوشه یا در home) با این محتوا (مقادیر را عوض کن):
 *
 *   WEBHOOK_SECRET=یک_رمز_۳۲_حرفی_تصادفی
 *   DEPLOY_SCRIPT=/home/smmturk/deploy-smm.sh
 *
 * در GitHub: Settings → Webhooks → Add webhook
 *   Payload URL: https://92.205.182.143/deploy-webhook.php (یا با دامنه)
 *   Content type: application/json
 *   Secret: همان WEBHOOK_SECRET
 *   Events: Just the push event
 *
 * راهنما: docs/CHECKLIST-DEPLOY.md
 */
header('Content-Type: application/json; charset=utf-8');

/**
 * @return array{ok: bool, error?: string, config?: array<string, string>, secret_path?: string}
 */
function deploy_load_secret(string $key): array
{
    $homeSecret = dirname(__DIR__) . '/deploy-secret.txt';
    $localSecret = __DIR__ . '/deploy-secret.txt';
    $secretPath = is_readable($localSecret) ? $localSecret : (is_readable($homeSecret) ? $homeSecret : '');
    if ($secretPath === '' || $key === '') {
        return ['ok' => false, 'error' => 'requires ?key=WEBHOOK_SECRET'];
    }
    $config = [];
    foreach (file($secretPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            $config[$m[1]] = trim($m[2], " \t\"'");
        }
    }
    $webhookSecret = $config['WEBHOOK_SECRET'] ?? '';
    if ($webhookSecret === '' || !hash_equals($webhookSecret, $key)) {
        return ['ok' => false, 'error' => 'Unauthorized'];
    }
    return ['ok' => true, 'config' => $config, 'secret_path' => $secretPath];
}

function deploy_normalize_php(string $content): string
{
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }
    return preg_replace('/^\s+(?=<\?php)/', '', $content) ?? $content;
}

/**
 * @return array{ok: bool, repaired?: list<string>, errors?: list<string>}
 */
function deploy_repair_files(): array
{
    $repoBase = 'https://raw.githubusercontent.com/ersanjt/smm-turk-panel/main/';
    $files = [
        'fix-app.php',
        'hotfix-cprs.php',
        'repair-deploy.php',
        'deploy-webhook.php',
        'app/bootstrap.php',
        'app/init.php',
        'app/init-v2.php',
        'app/Seo.php',
        'app/Lang.php',
        'app/Newsletter.php',
        'app/ChildPanelRemoteSettings.php',
        'app/ChildPanelDeployer.php',
        'app/ChildPanelManager.php',
        'app/Auth.php',
        'lang/tr.php',
        'lang/en.php',
        'lang/de.php',
        'home.php',
        'help.php',
        'login.php',
        'login-2fa.php',
        'forgot-password.php',
        'reset-password.php',
        'verify-email.php',
        'blog.php',
        'og-image.php',
        'newsletter-subscribe.php',
        'partials/blog-newsletter.php',
        'services.php',
        'pricing.php',
        'earn.php',
        'robots.php',
        'sitemap.php',
        'manifest.php',
        'blog-post.php',
        'layouts/header.php',
        'layouts/footer.php',
        'partials/public-seo-head.php',
        'partials/landing-nav.php',
        'partials/landing-footer.php',
        'partials/child-panel-manage.php',
        'app/ChildPanelRemoteSettings.php',
        'child-panel.php',
        'DEPLOY_VERSION',
    ];
    $repaired = [];
    $errors = [];
    $ctx = stream_context_create([
        'http' => ['timeout' => 45, 'header' => "User-Agent: smm-turk-repair\r\n"],
    ]);
    foreach ($files as $rel) {
        $content = @file_get_contents($repoBase . $rel, false, $ctx);
        if ($content === false || $content === '') {
            $errors[] = "download failed: $rel";
            continue;
        }
        $content = deploy_normalize_php($content);
        $dest = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($dest)) {
            @unlink($dest);
        }
        $tmp = $dest . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $content) === false) {
            $errors[] = "write failed: $rel";
            continue;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            $errors[] = "rename failed: $rel";
            continue;
        }
        $repaired[] = $rel;
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($dest, true);
        }
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    $legacyImpl = __DIR__ . '/app/ChildPanelRemoteSettingsImpl.php';
    if (is_file($legacyImpl)) {
        @unlink($legacyImpl);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($legacyImpl, true);
        }
        $repaired[] = '(deleted) app/ChildPanelRemoteSettingsImpl.php';
    }
    return [
        'ok' => $errors === [] && $repaired !== [],
        'repaired' => $repaired,
        'errors' => $errors,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $diagKey = trim((string)($_GET['key'] ?? ''));

    if (isset($_GET['repair'])) {
        $auth = deploy_load_secret($diagKey);
        if (!$auth['ok']) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => $auth['error'] ?? 'Forbidden']);
            exit;
        }
        $repair = deploy_repair_files();
        $deployScript = $auth['config']['DEPLOY_SCRIPT'] ?? '/home/smmturk/deploy-smm.sh';
        $deployOut = null;
        $deployRan = false;
        if (is_readable($deployScript) && function_exists('exec')) {
            $disabled = strtolower((string) ini_get('disable_functions'));
            $execOff = $disabled !== '' && in_array('exec', array_map('trim', explode(',', $disabled)), true);
            if (!$execOff) {
                $out = [];
                @exec('bash ' . escapeshellarg($deployScript) . ' 2>&1', $out, $code);
                $deployRan = true;
                $deployOut = ['code' => $code, 'log' => implode("\n", $out)];
            }
        }
        echo json_encode([
            'ok' => $repair['ok'],
            'repair' => $repair,
            'deploy_ran' => $deployRan,
            'deploy' => $deployOut,
            'deploy_version' => is_readable(__DIR__ . '/DEPLOY_VERSION') ? trim((string) file_get_contents(__DIR__ . '/DEPLOY_VERSION')) : null,
            'remote_settings_line52' => is_readable(__DIR__ . '/app/ChildPanelRemoteSettings.php')
                ? trim((string) (file(__DIR__ . '/app/ChildPanelRemoteSettings.php')[51] ?? ''))
                : null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (isset($_GET['diag'])) {
        $auth = deploy_load_secret($diagKey);
        if (!$auth['ok']) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => $auth['error'] ?? 'Forbidden']);
            exit;
        }
        $deployScript = '/home/smmturk/deploy-smm.sh';
        $cronScript = '/home/smmturk/deploy-cron.sh';
        $repoDir = '/home/smmturk/repositories/smm-turk-panel';
        $disabled = strtolower((string) ini_get('disable_functions'));
        $execOff = !function_exists('exec') || ($disabled !== '' && in_array('exec', array_map('trim', explode(',', $disabled)), true));
        echo json_encode([
            'ok' => true,
            'diag' => [
                'deploy_secret' => ($auth['secret_path'] ?? '') !== '' ? 'found' : 'missing',
                'deploy_script' => is_readable($deployScript) ? 'found' : 'missing — upload deploy-cpanel.sh as /home/smmturk/deploy-smm.sh (755)',
                'deploy_cron' => is_readable($cronScript) ? 'found' : 'missing — upload deploy-cron.sh to /home/smmturk/ (755)',
                'git_repo' => is_dir($repoDir . '/.git') ? 'found' : 'missing — clone repo in cPanel Git Version Control',
                'exec_disabled' => $execOff,
                'deploy_version' => is_readable(__DIR__ . '/DEPLOY_VERSION') ? trim((string) file_get_contents(__DIR__ . '/DEPLOY_VERSION')) : null,
                'init_requires_remote_settings' => is_readable(__DIR__ . '/app/init.php')
                    ? (strpos((string) file_get_contents(__DIR__ . '/app/init.php'), 'ChildPanelRemoteSettings') !== false)
                    : null,
                'hint' => $execOff ? 'Set Cron: * * * * * /home/smmturk/deploy-cron.sh' : 'exec OK — webhook can run deploy directly',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'message' => 'Deploy webhook endpoint. Use POST from GitHub. Add ?diag=1 or ?repair=1 with ?key=WEBHOOK_SECRET']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$rawInput = file_get_contents('php://input');
if ($rawInput === false || $rawInput === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empty body']);
    exit;
}

// بارگذاری سکرت از فایل (خارج از public_html امن‌تر است)
$secretFile = file_exists(__DIR__ . '/deploy-secret.txt') ? __DIR__ . '/deploy-secret.txt' : (file_exists(dirname(__DIR__) . '/deploy-secret.txt') ? dirname(__DIR__) . '/deploy-secret.txt' : '');
if ($secretFile === '' || !is_readable($secretFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'deploy-secret.txt not found or not readable']);
    exit;
}

$config = [];
$lines = file($secretFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
        $config[$m[1]] = trim($m[2], " \t\"'");
    }
}

$webhookSecret = $config['WEBHOOK_SECRET'] ?? '';
if ($webhookSecret === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'WEBHOOK_SECRET not set in deploy-secret.txt']);
    exit;
}

// تأیید امضای GitHub (X-Hub-Signature-256)
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if ($sig === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Missing signature']);
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $rawInput, $webhookSecret);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
    exit;
}

$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// فقط روی push به برنچ main واکنش نشان بده
$ref = $payload['ref'] ?? '';
if ($ref !== 'refs/heads/main') {
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Not main branch']);
    exit;
}

$deployScript = $config['DEPLOY_SCRIPT'] ?? '';
$repoPath = $config['REPO_PATH'] ?? '';
$queueFlag = $config['DEPLOY_QUEUE_FLAG'] ?? (dirname(__DIR__) . '/deploy.pending');

$execDisabled = !function_exists('exec');
if (!$execDisabled) {
    $disabled = strtolower((string) ini_get('disable_functions'));
    if ($disabled !== '') {
        $execDisabled = in_array('exec', array_map('trim', explode(',', $disabled)), true);
    }
}

$output = [];
$returnVar = 0;

if ($deployScript !== '') {
    if (!is_readable($deployScript)) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'DEPLOY_SCRIPT not found on server. Copy scripts/deploy-cpanel.sh to /home/smmturk/deploy-smm.sh',
            'path' => $deployScript,
        ]);
        exit;
    }
    if ($execDisabled) {
        if (@file_put_contents($queueFlag, gmdate('c') . " queued\n") === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'exec disabled and cannot write deploy.pending. Add Cron for deploy-cron.sh']);
            exit;
        }
        echo json_encode(['ok' => true, 'message' => 'Deploy queued (exec disabled). Cron runs deploy-cron.sh within 1 minute.', 'queued' => true]);
        exit;
    }
    @exec('bash ' . escapeshellarg($deployScript) . ' 2>&1', $output, $returnVar);
} elseif ($repoPath !== '' && is_dir($repoPath)) {
    if ($execDisabled) {
        if (@file_put_contents($queueFlag, gmdate('c') . " queued\n") === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'exec disabled. Use DEPLOY_SCRIPT + Cron instead']);
            exit;
        }
        echo json_encode(['ok' => true, 'message' => 'Deploy queued', 'queued' => true]);
        exit;
    }
    $cmd = 'cd ' . escapeshellarg($repoPath) . ' && git pull 2>&1';
    @exec($cmd, $output, $returnVar);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Set DEPLOY_SCRIPT or REPO_PATH in deploy-secret.txt']);
    exit;
}

$log = implode("\n", $output);
if ($returnVar !== 0) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Deploy failed', 'return_code' => $returnVar, 'output' => $log]);
    exit;
}

echo json_encode(['ok' => true, 'message' => 'Deploy completed', 'output' => $log]);
