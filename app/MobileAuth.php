<?php
/**
 * One-time tokens so the Android app can finish the same Google OAuth flow as the website.
 */
class MobileAuth {
    public const APP_SCHEME = 'smmturk';
    public const APP_HOST = 'google-auth';

    public static function isRequested(): bool {
        $flag = $_GET['mobile'] ?? $_POST['mobile'] ?? '';
        if ($flag !== '' && $flag !== '0' && strtolower((string)$flag) !== 'false') {
            return true;
        }
        return !empty($_SESSION['google_oauth_mobile']);
    }

    public static function markFlow(): void {
        $_SESSION['google_oauth_mobile'] = 1;
    }

    public static function clearFlow(): void {
        unset($_SESSION['google_oauth_mobile']);
    }

    public static function inFlow(): bool {
        return !empty($_SESSION['google_oauth_mobile']);
    }

    public static function storeUserToken(int $userId): string {
        $dir = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__)) . '/tmp/mobile_oauth';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $token = bin2hex(random_bytes(24));
        file_put_contents($dir . '/' . $token . '.json', json_encode([
            'user_id' => $userId,
            'exp' => time() + 300,
        ]), LOCK_EX);
        return $token;
    }

    public static function consumeToken(string $token): ?int {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower($token));
        if ($token === '' || strlen($token) < 32) {
            return null;
        }
        $path = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__)) . '/tmp/mobile_oauth/' . $token . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = (string)file_get_contents($path);
        @unlink($path);
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['user_id']) || empty($data['exp'])) {
            return null;
        }
        if (time() > (int)$data['exp']) {
            return null;
        }
        return (int)$data['user_id'];
    }

    public static function appUri(string $token = '', string $error = ''): string {
        $q = $token !== '' ? ('token=' . rawurlencode($token)) : ('error=' . rawurlencode($error));
        return self::APP_SCHEME . '://' . self::APP_HOST . '?' . $q;
    }

    public static function handoffUrl(string $token = '', string $error = ''): string {
        $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
        $path = $base . '/app-google-done';
        if ($token !== '') {
            return $path . '?token=' . rawurlencode($token);
        }
        return $path . '?error=' . rawurlencode($error);
    }

    public static function redirectToApp(int $userId): void {
        self::clearFlow();
        $token = self::storeUserToken($userId);
        header('Location: ' . self::handoffUrl($token), true, 302);
        exit;
    }

    public static function redirectError(string $error): void {
        self::clearFlow();
        header('Location: ' . self::handoffUrl('', $error), true, 302);
        exit;
    }
}
