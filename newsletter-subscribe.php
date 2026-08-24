<?php
/**
 * Public newsletter signup handler (used by the blog).
 * Supports both fetch/JSON and classic form POST with redirect fallback.
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';
Lang::initPublic();

$wantsJson = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

$redirectBack = function (string $status) {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $target = $ref !== '' ? $ref : url('blog.php');
    $target = preg_replace('/[?&]sub=[^&]*/', '', $target);
    $sep = strpos($target, '?') !== false ? '&' : '?';
    redirect($target . $sep . 'sub=' . $status . '#newsletter');
};

$respond = function (bool $ok, string $messageKey, int $code = 200) use ($wantsJson, $redirectBack) {
    $message = function_exists('__') ? __($messageKey) : $messageKey;
    if ($wantsJson) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message]);
        exit;
    }
    flash($ok ? 'success' : 'error', $message);
    $redirectBack($ok ? 'ok' : 'err');
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(false, 'newsletter_err', 405);
}
if (!csrf_verify()) {
    $respond(false, 'newsletter_err', 403);
}

$email = (string) ($_POST['email'] ?? '');
$lang = Lang::current();
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

$result = Newsletter::subscribe($email, $lang, 'blog', $ip);
if (!$result['success']) {
    $respond(false, $result['error'] === 'invalid_email' ? 'newsletter_invalid' : 'newsletter_err', 400);
}

$respond(true, !empty($result['already']) ? 'newsletter_already' : 'newsletter_ok');
