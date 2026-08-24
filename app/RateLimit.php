<?php
/**
 * Simple file-based rate limit (e.g. for login attempts per IP or API per key).
 * Uses tmp/rate_limit/ - ensure this directory exists and is writable.
 * @param int $maxAttempts Max requests per window
 * @param int $windowSeconds Window in seconds
 * @param string|null $customKey If set, rate limit by this key (e.g. API key); otherwise by IP
 */
class RateLimit {
    private string $dir;
    private int $maxAttempts;
    private int $windowSeconds;
    private ?string $customKey;

    public function __construct(int $maxAttempts = 5, int $windowSeconds = 900, ?string $customKey = null) {
        $this->dir = defined('ROOT_PATH') ? ROOT_PATH . '/tmp/rate_limit' : __DIR__ . '/../tmp/rate_limit';
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->customKey = $customKey !== null && $customKey !== '' ? $customKey : null;
    }

    private function key(): string {
        if ($this->customKey !== null) {
            return 'api_' . md5($this->customKey);
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        } elseif ($this->isTrustedProxy($ip) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return md5($ip);
    }

    private function isTrustedProxy(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function filePath(): string {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
        return $this->dir . '/' . $this->key() . '.json';
    }

    /** Returns true if the request is over the limit (should be blocked). */
    public function isLimited(): bool {
        $path = $this->filePath();
        if (!file_exists($path)) {
            return false;
        }
        $data = @json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || empty($data['start']) || empty($data['count'])) {
            return false;
        }
        if (time() - (int)$data['start'] > $this->windowSeconds) {
            @unlink($path);
            return false;
        }
        return (int)$data['count'] >= $this->maxAttempts;
    }

    /** Record an attempt (e.g. failed login or API request). */
    public function recordAttempt(): void {
        $path = $this->filePath();
        $data = ['start' => time(), 'count' => 1];
        if (file_exists($path)) {
            $existing = @json_decode((string)file_get_contents($path), true);
            if (is_array($existing) && isset($existing['start'], $existing['count'])) {
                if (time() - (int)$existing['start'] <= $this->windowSeconds) {
                    $data['start'] = (int)$existing['start'];
                    $data['count'] = (int)$existing['count'] + 1;
                }
            }
        }
        file_put_contents($path, json_encode($data), LOCK_EX);
    }

    /** Clear rate limit for current IP (e.g. after successful login). */
    public function clear(): void {
        $path = $this->filePath();
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
