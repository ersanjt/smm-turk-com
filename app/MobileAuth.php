<?php
/**
 * Short-lived tokens for mobile OAuth handshake and 2FA challenges.
 * Files live under tmp/mobile_oauth (already gitignored via tmp/).
 */
class MobileAuth
{
    private const TTL_SECONDS = 300;

    public static function isRequested(): bool
    {
        $flag = $_GET['mobile'] ?? $_POST['mobile'] ?? '';
        return $flag === '1' || $flag === 'true' || $flag === 'app';
    }

    public static function markMobile(bool $openInApp = false): void
    {
        $_SESSION['google_oauth_mobile'] = 1;
        if ($openInApp) {
            $_SESSION['google_oauth_mobile_app'] = 1;
        } else {
            unset($_SESSION['google_oauth_mobile_app']);
        }
    }

    public static function isMobile(): bool
    {
        return !empty($_SESSION['google_oauth_mobile']);
    }

    public static function wantsAppDeepLink(): bool
    {
        return !empty($_SESSION['google_oauth_mobile_app']);
    }

    public static function clearMobile(): void
    {
        unset($_SESSION['google_oauth_mobile'], $_SESSION['google_oauth_mobile_app']);
    }

    public static function createChallenge(int $userId): string
    {
        $token = bin2hex(random_bytes(24));
        self::write($token, [
            'type' => '2fa',
            'user_id' => $userId,
            'expires' => time() + self::TTL_SECONDS,
        ]);
        return $token;
    }

    public static function peekChallenge(string $token): int
    {
        $data = self::read($token);
        if (!$data || ($data['type'] ?? '') !== '2fa') {
            return 0;
        }
        return (int)($data['user_id'] ?? 0);
    }

    public static function consumeChallenge(string $token): int
    {
        $userId = self::peekChallenge($token);
        self::delete($token);
        return $userId;
    }

    public static function createHandshake(string $apiKey, array $user): string
    {
        $token = bin2hex(random_bytes(24));
        self::write($token, [
            'type' => 'handshake',
            'api_key' => $apiKey,
            'user' => $user,
            'expires' => time() + self::TTL_SECONDS,
        ]);
        return $token;
    }

    public static function takeHandshake(string $token): ?array
    {
        $data = self::read($token);
        self::delete($token);
        if (!$data || ($data['type'] ?? '') !== 'handshake') {
            return null;
        }
        return $data;
    }

    public static function appUrl(array $query = []): string
    {
        $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
        $path = function_exists('path') ? path('m.php') : '/m';
        if ($base !== '' && !preg_match('#^https?://#i', $path)) {
            $url = $base . '/' . ltrim($path, '/');
        } else {
            $url = $path;
        }
        $url = preg_replace('#m\.php$#', 'm', $url) ?: $url;
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $url;
    }

    private static function dir(): string
    {
        $dir = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__)) . '/tmp/mobile_oauth';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function pathFor(string $token): string
    {
        $safe = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
        if (strlen($safe) < 32) {
            return '';
        }
        return self::dir() . '/' . $safe . '.json';
    }

    private static function write(string $token, array $payload): void
    {
        $path = self::pathFor($token);
        if ($path === '') {
            return;
        }
        @file_put_contents($path, json_encode($payload), LOCK_EX);
    }

    private static function read(string $token): ?array
    {
        $path = self::pathFor($token);
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || (int)($data['expires'] ?? 0) < time()) {
            self::delete($token);
            return null;
        }
        return $data;
    }

    private static function delete(string $token): void
    {
        $path = self::pathFor($token);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
