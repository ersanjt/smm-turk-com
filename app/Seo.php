<?php
/**
 * Central SEO helpers — meta tags, canonicals, hreflang, JSON-LD, geo targeting.
 */
class Seo
{
    public static function siteUrl(): string
    {
        return defined('SITE_URL') && SITE_URL !== '' ? rtrim(SITE_URL, '/') : '';
    }

    public static function siteName(): string
    {
        if (function_exists('site_name')) {
            return site_name();
        }
        return defined('SITE_NAME') ? SITE_NAME : 'SMM Turk';
    }

    /** Absolute URL from path(), script name, or full URL. */
    public static function absoluteUrl(string $pathOrUrl): string
    {
        if (preg_match('#^https?://#i', $pathOrUrl)) {
            return $pathOrUrl;
        }
        if (str_contains($pathOrUrl, '.php')) {
            return url($pathOrUrl);
        }
        $base = self::siteUrl();
        $p = str_starts_with($pathOrUrl, '/') ? $pathOrUrl : '/' . ltrim($pathOrUrl, '/');
        return $base !== '' ? $base . $p : $p;
    }

    /** Google Search Console verification meta (set GOOGLE_SITE_VERIFICATION in config.php). */
    public static function verificationMeta(): string
    {
        if (!defined('GOOGLE_SITE_VERIFICATION') || trim((string) GOOGLE_SITE_VERIFICATION) === '') {
            return '';
        }
        return '<meta name="google-site-verification" content="' . self::e(trim((string) GOOGLE_SITE_VERIFICATION)) . '">';
    }

    public static function geoRegion(): string
    {
        return defined('GEO_REGION') ? GEO_REGION : 'TR';
    }

    public static function geoPlaceName(): string
    {
        return defined('GEO_PLACENAME') ? GEO_PLACENAME : 'Turkey';
    }

    public static function geoLocality(): string
    {
        return defined('GEO_LOCALITY') ? GEO_LOCALITY : 'Ankara';
    }

    public static function geoLat(): float
    {
        return defined('GEO_LAT') ? (float) GEO_LAT : 39.9334;
    }

    public static function geoLng(): float
    {
        return defined('GEO_LNG') ? (float) GEO_LNG : 32.8597;
    }

    public static function geoTimezone(): string
    {
        return defined('GEO_TIMEZONE') ? GEO_TIMEZONE : 'Europe/Istanbul';
    }

    /** BCP 47 language tag with region (e.g. tr-TR, en). */
    public static function htmlLang(string $lang): string
    {
        return match ($lang) {
            'tr' => 'tr-TR',
            'en' => 'en',
            'de' => 'de-DE',
            default => 'tr-TR',
        };
    }

    /** hreflang attribute value (language + region for primary markets). */
    public static function hreflangCode(string $lang): string
    {
        return self::htmlLang($lang);
    }

    /** @return string[] BCP 47 tags for all supported languages. */
    public static function supportedHtmlLangs(): array
    {
        if (!class_exists('Lang')) {
            return ['tr-TR', 'en', 'de-DE'];
        }
        return array_map(fn(string $l) => self::htmlLang($l), Lang::allowed());
    }

    /** Geo meta tags for HTML head (Turkey primary, worldwide service). */
    public static function geoMetaTags(?string $contentLang = null): string
    {
        $region = self::geoRegion();
        $place = self::geoPlaceName();
        $lat = self::geoLat();
        $lng = self::geoLng();
        $pos = $lat . ';' . $lng;
        $icbm = $lat . ', ' . $lng;

        $lines = [
            '<meta name="geo.region" content="' . self::e($region) . '">',
            '<meta name="geo.placename" content="' . self::e($place) . '">',
            '<meta name="geo.position" content="' . self::e($pos) . '">',
            '<meta name="ICBM" content="' . self::e($icbm) . '">',
        ];
        if ($contentLang !== null && $contentLang !== '') {
            $lines[] = '<meta http-equiv="content-language" content="' . self::e(self::htmlLang($contentLang)) . '">';
        }
        return implode("\n    ", $lines);
    }

    public static function ogLocale(string $lang): string
    {
        return match ($lang) {
            'tr' => 'tr_TR',
            'en' => 'en_US',
            'de' => 'de_DE',
            default => 'tr_TR',
        };
    }

    public static function robotsContent(bool $indexable = true, bool $follow = true): string
    {
        if ($indexable) {
            return 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
        }
        return 'noindex, ' . ($follow ? 'follow' : 'nofollow');
    }

    /** Self-referencing canonical / alternate URL for a language version. */
    public static function localizedUrl(string $url, ?string $lang = null): string
    {
        if (!class_exists('Lang')) {
            return $url;
        }
        $lang = $lang ?? Lang::current();
        if (Lang::isPrimary($lang)) {
            return self::stripLangParam($url);
        }
        $base = self::stripLangParam($url);
        return $base . (str_contains($base, '?') ? '&' : '?') . 'lang=' . rawurlencode($lang);
    }

    /** Alias: canonical for the active language on this page. */
    public static function pageCanonical(string $baseUrl, ?string $lang = null): string
    {
        return self::localizedUrl($baseUrl, $lang);
    }

    /** Remove lang= from URL (primary / hreflang cluster base). */
    public static function stripLangParam(string $url): string
    {
        if (!str_contains($url, 'lang=')) {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        parse_str($parts['query'] ?? '', $query);
        unset($query['lang']);
        $rebuilt = '';
        if (isset($parts['scheme'], $parts['host'])) {
            $rebuilt = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $rebuilt .= ':' . $parts['port'];
            }
        }
        $rebuilt .= $parts['path'] ?? '';
        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query);
        }
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt !== '' ? $rebuilt : $url;
    }

    /** xhtml:link alternates for XML sitemap (multilingual). */
    public static function sitemapHreflangLinks(string $primaryLoc): string
    {
        if ($primaryLoc === '' || !class_exists('Lang')) {
            return '';
        }
        $loc = self::stripLangParam($primaryLoc);
        $lines = '';
        foreach (Lang::allowed() as $l) {
            $href = self::localizedUrl($loc, $l);
            $lines .= "\n    <xhtml:link rel=\"alternate\" hreflang=\"" . self::e(self::hreflangCode($l))
                . "\" href=\"" . self::e($href) . "\"/>";
        }
        $lines .= "\n    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"" . self::e($loc) . "\"/>";
        return $lines;
    }

    public static function pageLanguage(?string $lang = null): string
    {
        if ($lang === null && class_exists('Lang')) {
            $lang = Lang::current();
        }
        return self::htmlLang($lang ?? Lang::PRIMARY);
    }

    /**
     * Build hreflang link tags for multilingual public pages.
     *
     * @param string $baseCanonical Absolute URL without lang query (Turkish default).
     */
    public static function hreflangTags(string $baseCanonical, bool $langQuery = true): string
    {
        if ($baseCanonical === '' || !class_exists('Lang')) {
            return '';
        }
        unset($langQuery);
        $baseCanonical = self::stripLangParam($baseCanonical);
        $html = '';
        foreach (Lang::allowed() as $l) {
            $href = self::localizedUrl($baseCanonical, $l);
            $html .= '<link rel="alternate" hreflang="' . self::e(self::hreflangCode($l)) . '" href="' . self::e($href) . '">' . "\n    ";
        }
        $html .= '<link rel="alternate" hreflang="x-default" href="' . self::e($baseCanonical) . '">';
        return $html;
    }

    /** og:locale:alternate meta tags (exclude current lang). */
    public static function ogLocaleAlternates(string $currentLang): string
    {
        if (!class_exists('Lang')) {
            return '';
        }
        $html = '';
        foreach (array_diff(Lang::allowed(), [$currentLang]) as $alt) {
            $html .= '<meta property="og:locale:alternate" content="' . self::e(self::ogLocale($alt)) . '">' . "\n    ";
        }
        return rtrim($html);
    }

    public static function organizationSchema(?string $description = null, ?string $lang = null): array
    {
        $siteName = self::siteName();
        $siteUrl = self::siteUrl();
        $schema = [
            '@type' => 'Organization',
            'name' => $siteName,
            'url' => $siteUrl ?: home_path(),
            'description' => $description ?? '',
            'logo' => og_image_url(),
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => self::geoLocality(),
                'addressCountry' => self::geoRegion(),
            ],
            'geo' => [
                '@type' => 'GeoCoordinates',
                'latitude' => self::geoLat(),
                'longitude' => self::geoLng(),
            ],
            'areaServed' => self::areaServedSchema(),
        ];
        if ($lang !== null) {
            $schema['inLanguage'] = self::pageLanguage($lang);
        }
        return $schema;
    }

    /** @return array<int, array<string, mixed>> */
    public static function areaServedSchema(): array
    {
        return [
            [
                '@type' => 'Country',
                'name' => self::geoPlaceName(),
            ],
            [
                '@type' => 'Place',
                'name' => 'Worldwide',
            ],
        ];
    }

    public static function websiteSchema(?string $description = null): array
    {
        $siteUrl = self::siteUrl();
        return [
            '@type' => 'WebSite',
            'name' => self::siteName(),
            'url' => $siteUrl ?: home_path(),
            'description' => $description ?? '',
            'inLanguage' => self::supportedHtmlLangs(),
            'publisher' => [
                '@type' => 'Organization',
                'name' => self::siteName(),
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressCountry' => self::geoRegion(),
                ],
            ],
        ];
    }

    public static function webPageSchema(string $name, string $description, string $url, ?string $lang = null): array
    {
        $schema = [
            '@type' => 'WebPage',
            'name' => $name,
            'description' => $description,
            'url' => $url,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => self::siteName(),
                'url' => self::siteUrl() ?: home_path(),
            ],
        ];
        if ($lang !== null) {
            $schema['inLanguage'] = self::pageLanguage($lang);
        }
        return $schema;
    }

    /** @param array<int, array{name: string, text: string}> $items */
    public static function faqSchema(array $items, ?string $lang = null): array
    {
        $entities = [];
        foreach ($items as $item) {
            $entities[] = [
                '@type' => 'Question',
                'name' => $item['name'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['text']],
            ];
        }
        $schema = ['@type' => 'FAQPage', 'mainEntity' => $entities];
        if ($lang !== null) {
            $schema['inLanguage'] = self::pageLanguage($lang);
        }
        return $schema;
    }

    /**
     * @param array<int, array{name: string, url: string}> $items
     */
    public static function breadcrumbSchema(array $items, ?string $lang = null): array
    {
        $list = [];
        foreach ($items as $i => $item) {
            $list[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }
        $schema = ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
        if ($lang !== null) {
            $schema['inLanguage'] = self::pageLanguage($lang);
        }
        return $schema;
    }

    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $schemas
     */
    public static function jsonLd(array $schemas): string
    {
        if ($schemas === []) {
            return '';
        }
        if (isset($schemas['@type']) || isset($schemas['@context'])) {
            $schemas = [$schemas];
        }
        foreach ($schemas as &$schema) {
            if (!isset($schema['@context'])) {
                $schema = array_merge(['@context' => 'https://schema.org'], $schema);
            }
        }
        unset($schema);
        $payload = count($schemas) === 1
            ? $schemas[0]
            : ['@context' => 'https://schema.org', '@graph' => $schemas];
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
