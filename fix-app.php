<?php
/**
 * One-shot app file sync from GitHub (bypasses stale OPcache on old filenames).
 * GET ?key=WEBHOOK_SECRET
 */
header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
$secretFile = file_exists(__DIR__ . '/deploy-secret.txt') ? __DIR__ . '/deploy-secret.txt'
    : (file_exists(dirname(__DIR__) . '/deploy-secret.txt') ? dirname(__DIR__) . '/deploy-secret.txt' : '');

if ($secretFile === '' || !is_readable($secretFile) || $key === '') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
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

if (($config['WEBHOOK_SECRET'] ?? '') === '' || !hash_equals($config['WEBHOOK_SECRET'], $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid key']);
    exit;
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

/** Strip UTF-8 BOM and accidental leading whitespace before <?php. */
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
    'app/bootstrap.php',
    'app/init.php',
    'app/init-v2.php',
    'app/ChildPanelRemoteSettings.php',
    'app/Newsletter.php',
    'app/ChildPanelDeployer.php',
    'app/ChildPanelManager.php',
    'app/ChildPanelAutomation.php',
    'app/Auth.php',
    'app/Seo.php',
    'app/Lang.php',
    'lang/tr.php',
    'lang/en.php',
    'lang/de.php',
    'admin/_init.php',
    'admin/index.php',
    'admin/admin-child-panels.php',
    'admin/admin-coupons.php',
    'child-panel.php',
    'partials/child-panel-manage.php',
    'api-page.php',
    'repair-panel.php',
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
    'help.php',
    'terms.php',
    'services.php',
    'pricing.php',
    'earn.php',
    'robots.php',
    'sitemap.php',
    'manifest.php',
    'pwa-sw.php',
    'blog-post.php',
    'layouts/header.php',
    'layouts/footer.php',
    'layouts/blog-footer.php',
    'layouts/blog-header.php',
    'partials/public-seo-head.php',
    'partials/landing-nav.php',
    'partials/landing-footer.php',
    'partials/a11y.php',
    'assets/css/earn.css',
    'assets/css/blog.css',
    'assets/css/help.css',
    'assets/css/terms.css',
    'assets/css/panel-follows.css',
    'assets/css/landing.css',
    'assets/css/ui-pro.css',
    'assets/css/a11y.css',
    'assets/js/landing.js',
    'assets/js/pwa.js',
    'assets/js/a11y.js',
    'DEPLOY_VERSION',
];

$repaired = [];
$errors = [];
$ctx = stream_context_create([
    'http' => ['timeout' => 45, 'header' => "User-Agent: smm-turk-fix-app\r\n"],
]);

foreach ($files as $rel) {
    $content = @file_get_contents($repoBase . $rel, false, $ctx);
    if ($content === false || $content === '') {
        $errors[] = "download: $rel";
        continue;
    }
    $content = deploy_normalize_php($content);
    $dest = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $dir = dirname($dest);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_file($dest)) {
        @unlink($dest);
    }
    $tmp = $dest . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $content) === false) {
        $errors[] = "write: $rel";
        continue;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        $errors[] = "rename: $rel";
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

$deployScriptUpdated = false;
$deployScriptPath = dirname(__DIR__) . '/deploy-smm.sh';
$deployScriptSrc = $repoBase . 'scripts/deploy-cpanel.sh';
$deployScriptBody = @file_get_contents($deployScriptSrc, false, $ctx);
if ($deployScriptBody !== false && $deployScriptBody !== '') {
    if (@file_put_contents($deployScriptPath, $deployScriptBody) !== false) {
        @chmod($deployScriptPath, 0755);
        $deployScriptUpdated = true;
        $repaired[] = '(updated) ../deploy-smm.sh';
    }
}

echo json_encode([
    'ok' => $errors === [] && $repaired !== [],
    'repaired' => $repaired,
    'errors' => $errors,
    'deploy_script_updated' => $deployScriptUpdated,
    'stub_line52' => is_readable(__DIR__ . '/app/ChildPanelRemoteSettings.php')
        ? trim((string) (file(__DIR__ . '/app/ChildPanelRemoteSettings.php')[51] ?? 'n/a'))
        : null,
    'bootstrap_loaded' => is_readable(__DIR__ . '/app/bootstrap.php'),
    'deploy_version' => is_readable(__DIR__ . '/DEPLOY_VERSION') ? trim((string) file_get_contents(__DIR__ . '/DEPLOY_VERSION')) : null,
    'cprs_first_bytes' => is_readable(__DIR__ . '/app/ChildPanelRemoteSettings.php')
        ? bin2hex((string) substr((string) file_get_contents(__DIR__ . '/app/ChildPanelRemoteSettings.php'), 0, 5))
        : null,
    'legacy_impl_exists' => is_file(__DIR__ . '/app/ChildPanelRemoteSettingsImpl.php'),
], JSON_PRETTY_PRINT);
