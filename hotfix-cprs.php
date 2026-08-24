<?php
/**
 * One-shot hotfix: remove legacy Impl file and sync clean ChildPanelRemoteSettings.php.
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

function hotfix_normalize_php(string $content): string
{
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }
    return preg_replace('/^\s+(?=<\?php)/', '', $content) ?? $content;
}

$repoBase = 'https://raw.githubusercontent.com/ersanjt/smm-turk-panel/main/';
$ctx = stream_context_create([
    'http' => ['timeout' => 45, 'header' => "User-Agent: smm-turk-hotfix-cprs\r\n"],
]);

$actions = [];
$errors = [];

$legacy = __DIR__ . '/app/ChildPanelRemoteSettingsImpl.php';
if (is_file($legacy)) {
    if (@unlink($legacy)) {
        $actions[] = 'deleted ChildPanelRemoteSettingsImpl.php';
    } else {
        $errors[] = 'could not delete ChildPanelRemoteSettingsImpl.php';
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($legacy, true);
    }
} else {
    $actions[] = 'ChildPanelRemoteSettingsImpl.php not present';
}

$content = @file_get_contents($repoBase . 'app/ChildPanelRemoteSettings.php', false, $ctx);
if ($content === false || $content === '') {
    $errors[] = 'download failed: app/ChildPanelRemoteSettings.php';
} else {
    $content = hotfix_normalize_php($content);
    if (strpos($content, 'require_once') !== false && strpos($content, 'ChildPanelRemoteSettingsImpl') !== false) {
        $errors[] = 'downloaded ChildPanelRemoteSettings.php is still a stub';
    } else {
        $dest = __DIR__ . '/app/ChildPanelRemoteSettings.php';
        $tmp = $dest . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $content) === false) {
            $errors[] = 'write failed: ChildPanelRemoteSettings.php';
        } elseif (!@rename($tmp, $dest)) {
            @unlink($tmp);
            $errors[] = 'rename failed: ChildPanelRemoteSettings.php';
        } else {
            @chmod($dest, 0644);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($dest, true);
            }
            $actions[] = 'synced app/ChildPanelRemoteSettings.php';
        }
    }
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

$cprsPath = __DIR__ . '/app/ChildPanelRemoteSettings.php';
$cprsBytes = is_readable($cprsPath)
    ? bin2hex((string) substr((string) file_get_contents($cprsPath), 0, 5))
    : null;
$isStub = is_readable($cprsPath)
    && strpos((string) file_get_contents($cprsPath), 'ChildPanelRemoteSettingsImpl') !== false;

echo json_encode([
    'ok' => $errors === [],
    'actions' => $actions,
    'errors' => $errors,
    'cprs_first_bytes' => $cprsBytes,
    'legacy_impl_exists' => is_file($legacy),
    'cprs_is_stub' => $isStub,
    'class_exists' => class_exists('ChildPanelRemoteSettings', false),
], JSON_PRETTY_PRINT);
