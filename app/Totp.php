<?php
/**
 * RFC 6238 TOTP (Google Authenticator compatible).
 */
class Totp {
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function getCode(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string {
        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, $period);
        $binary = pack('N*', 0, $counter);
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );
        $mod = 10 ** $digits;
        return str_pad((string)($value % $mod), $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $window = 1, int $period = 30, int $digits = 6): bool {
        $code = preg_replace('/\s+/', '', trim($code));
        if (!preg_match('/^\d{' . $digits . '}$/', $code)) {
            return false;
        }
        $timestamp = time();
        for ($i = -$window; $i <= $window; $i++) {
            $ts = $timestamp + ($i * $period);
            if (hash_equals(self::getCode($secret, $ts, $period, $digits), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function getProvisioningUri(string $secret, string $label, string $issuer): string {
        $label = rawurlencode($issuer . ':' . $label);
        $issuerEnc = rawurlencode($issuer);
        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) . '&issuer=' . $issuerEnc . '&algorithm=SHA1&digits=6&period=30';
    }

    private static function base32Encode(string $data): string {
        if ($data === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $encoded;
    }

    private static function base32Decode(string $secret): string {
        $secret = strtoupper(preg_replace('/[^A-Z2-7=]/', '', $secret));
        $secret = rtrim($secret, '=');
        if ($secret === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }
        return $decoded;
    }
}
