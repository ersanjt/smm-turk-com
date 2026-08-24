<?php
/**
 * Content corrections for provider data (typos in service names/categories).
 * Applied on sync so stored data is clean.
 */
class ContentCorrections {

    /** Known typos in service names (from provider API) */
    private static array $nameReplacements = [
        'Tatto' => 'Traffic',
        'Spetity' => 'Spotify',
        'Play Plays ' => 'Plays ',
        'Targe eted' => 'Targeted',
        'Spotify Mays' => 'Spotify Plays',
        'Mays ' => 'Plays ',
        'Biecever' => 'Whichever',
    ];

    /** Typos in category names (from provider API) */
    private static array $categoryReplacements = [
        'Pemium' => 'Premium',           // Telegram Pemium Bot Start
        'Vots ' => 'Bots ',              // Telegram Vots / Clone
        'Vots/' => 'Bots/',
        ' Sevices' => ' Services',        // Twitter USA Sevices
        'Sevices' => 'Services',
        'Live Steam ' => 'Live Stream ', // YouTube Live Steam Views
        'Live Steam-' => 'Live Stream-',
        'Reffers' => 'Referrers',        // Social Reffers
        'Secondes' => 'Seconds',         // 60 Secondes
    ];

    public static function correctServiceName(string $name): string {
        $out = self::decodeAmp($name);
        foreach (self::$nameReplacements as $wrong => $right) {
            $out = str_replace($wrong, $right, $out);
        }
        return $out;
    }

    public static function correctCategory(string $category): string {
        $out = trim($category);
        $out = self::decodeAmp($out);
        // Strip decorative *️⃣ (keycap asterisk) from start and end
        $out = preg_replace('/^[\x{002A}\x{FE0F}\x{20E3}\s]+|[\x{002A}\x{FE0F}\x{20E3}\s]+$/u', '', $out);
        $out = trim($out);
        foreach (self::$categoryReplacements as $wrong => $right) {
            $out = str_replace($wrong, $right, $out);
        }
        return $out;
    }

    /** Fit provider strings into DB column limits (UTF-8 safe). */
    public static function fitCategory(string $category, int $maxLen = 255): string {
        $out = self::correctCategory($category);
        if (mb_strlen($out) > $maxLen) {
            $out = mb_substr($out, 0, $maxLen);
        }
        return $out;
    }

    public static function fitServiceType(string $type, int $maxLen = 100): string {
        $out = trim($type !== '' ? $type : 'Default');
        if (mb_strlen($out) > $maxLen) {
            $out = mb_substr($out, 0, $maxLen);
        }
        return $out;
    }

    /** Avoid double-encoding: store & not &amp; so h() outputs &amp; once */
    private static function decodeAmp(string $s): string {
        while (str_contains($s, '&amp;')) {
            $s = str_replace('&amp;', '&', $s);
        }
        return $s;
    }
}
