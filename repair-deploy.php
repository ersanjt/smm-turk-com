<?php
/**
 * Emergency sync of critical PHP files from GitHub main (when rsync/git deploy lags).
 * GET ?key=WEBHOOK_SECRET — same secret as deploy-webhook.php / deploy-secret.txt
 */
header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
$secretFile = file_exists(__DIR__ . '/deploy-secret.txt') ? __DIR__ . '/deploy-secret.txt'
    : (file_exists(dirname(__DIR__) . '/deploy-secret.txt') ? dirname(__DIR__) . '/deploy-secret.txt' : '');

if ($secretFile === '' || !is_readable($secretFile) || $key === '') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden — use ?key=WEBHOOK_SECRET']);
    exit;
}

$config = [];
foreach (file($secretFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid key']);
    exit;
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

function deploy_normalize_php(string $content): string
{
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }
    return preg_replace('/^\s+(?=<\?php)/', '', $content) ?? $content;
}

$repoBase = 'https://raw.githubusercontent.com/ersanjt/smm-turk-panel/main/';
$files = [
    'fix-app.php',
    'hotfix-cprs.php',
    'repair-deploy.php',
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
    'http' => [
        'timeout' => 45,
        'header' => "User-Agent: smm-turk-repair\r\n",
    ],
]);

foreach ($files as $rel) {
    $url = $repoBase . str_replace('\\', '/', $rel);
    $content = @file_get_contents($url, false, $ctx);
    if ($content === false || $content === '') {
        $errors[] = "download failed: $rel";
        continue;
    }
    $content = deploy_normalize_php($content);
    $dest = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $errors[] = "mkdir failed: $dir";
        continue;
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
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($dest, true);
    }
    $repaired[] = $rel;
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

$cprsPath = __DIR__ . '/app/ChildPanelRemoteSettings.php';
if (is_readable($cprsPath)) {
    $cprsBody = (string) file_get_contents($cprsPath);
    if (strpos($cprsBody, 'ChildPanelRemoteSettingsImpl') !== false) {
        $fresh = @file_get_contents($repoBase . 'app/ChildPanelRemoteSettings.php', false, $ctx);
        if ($fresh !== false && $fresh !== '') {
            $fresh = deploy_normalize_php($fresh);
            $tmp = $cprsPath . '.tmp.' . getmypid();
            if (@file_put_contents($tmp, $fresh) !== false && @rename($tmp, $cprsPath)) {
                @chmod($cprsPath, 0644);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($cprsPath, true);
                }
                $repaired[] = '(fixed) app/ChildPanelRemoteSettings.php stub replaced';
            } else {
                @unlink($tmp);
            }
        }
    }
}

echo json_encode([
    'ok' => $errors === [],
    'repaired' => $repaired,
    'errors' => $errors,
    'count' => count($repaired),
    'cprs_first_bytes' => is_readable($cprsPath)
        ? bin2hex((string) substr((string) file_get_contents($cprsPath), 0, 5))
        : null,
    'legacy_impl_exists' => is_file($legacyImpl),
], JSON_PRETTY_PRINT);
