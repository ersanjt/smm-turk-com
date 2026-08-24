<?php
/**
 * Repair a child panel deploy (fixes 403 when files missing) or reset login.
 * GET ?key=WEBHOOK_SECRET&panel_id=2
 *   action=auto   — fix files if needed, then fix login (default)
 *   action=sync   — use same username/password as SMM Turk account
 *   action=reset  — new standalone panel password (matches welcome email style)
 *   action=full   — always re-provision files + fix login
 */
header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
$panelId = (int) ($_GET['panel_id'] ?? 0);
$action = strtolower(trim((string) ($_GET['action'] ?? 'auto')));
$secretFile = file_exists(__DIR__ . '/deploy-secret.txt') ? __DIR__ . '/deploy-secret.txt'
    : (file_exists(dirname(__DIR__) . '/deploy-secret.txt') ? dirname(__DIR__) . '/deploy-secret.txt' : '');

if ($secretFile === '' || !is_readable($secretFile) || $key === '' || $panelId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Use ?key=WEBHOOK_SECRET&panel_id=ID']);
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

require_once __DIR__ . '/app/bootstrap.php';

$panel = Database::getInstance()->fetch('SELECT * FROM child_panels WHERE id = ?', [$panelId]);
if (!$panel) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Panel not found']);
    exit;
}

$cpm = new ChildPanelManager();
$deployer = new ChildPanelDeployer();
$docRoot = $deployer->resolveDocumentRoot((string) $panel['domain'], (string) ($panel['document_root'] ?? ''));
$before = $deployer->documentRootReady($docRoot);

$result = ['skipped' => true];
$login = ['success' => false, 'error' => 'No login action run.'];

if ($action === 'sync') {
    $login = $cpm->syncAdminFromParentAccount($panelId, null);
} elseif ($action === 'reset') {
    $login = $cpm->resetAdminLoginPassword($panelId, null, true);
} elseif ($before['ok'] && $action !== 'full') {
    $login = $cpm->syncAdminFromParentAccount($panelId, null);
    if (!$login['success']) {
        $login = $cpm->resetAdminLoginPassword($panelId, null, true);
    }
    $result = ['skipped' => true, 'reason' => 'files_already_ok'];
} else {
    if ($before['ok']) {
        $cpm->syncAdminFromParentAccount($panelId, null);
    }
    $result = $cpm->provision($panelId, null, true);
    if ($action === 'auto' || $action === 'full') {
        $login = $cpm->syncAdminFromParentAccount($panelId, null);
        if (!$login['success']) {
            $login = $cpm->resetAdminLoginPassword($panelId, null, true);
        }
    }
}

$afterRoot = trim((string) (Database::getInstance()->fetch('SELECT document_root FROM child_panels WHERE id = ?', [$panelId])['document_root'] ?? ''));
if ($afterRoot === '') {
    $afterRoot = $docRoot;
}
$after = $deployer->documentRootReady($afterRoot);
$admins = $deployer->inspectAdminUsers($afterRoot);

$loginHint = !empty($login['uses_parent_login'])
    ? 'Sign in with your SMM Turk username and password.'
    : 'Sign in with the admin username and password from login_sync below.';

echo json_encode([
    'ok' => !empty($result['success']) || !empty($result['skipped']) || !empty($login['success']),
    'domain' => $panel['domain'] ?? '',
    'action' => $action,
    'before' => $before,
    'after' => $after,
    'document_root' => $afterRoot,
    'provision' => $result,
    'login_sync' => $login,
    'login_hint' => $loginHint,
    'child_admins' => $admins,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
