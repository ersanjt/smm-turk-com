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

    /** BCP 47 language tag with region (e.g. tr-TR, en-US). */
    public static function htmlLang(string $lang): string
    {
        return match ($lang) {
            'tr' => 'tr-TR',
            'en' => 'en-US',
            'de' => 'de-DE',
            default => 'tr-TR',
        };
    }

    /**
     * hreflang attribute — use language-only for EN (Google prefers en over en-US
     * when the site is not US-specific), region for TR/DE primary markets.
     */
    public static function hreflangCode(string $lang): string
    {
        return match ($lang) {
            'tr' => 'tr-TR',
            'en' => 'en',
            'de' => 'de-DE',
            default => 'tr-TR',
        };
    }

    /** @return string[] BCP 47 tags for all supported languages. */
    public static function supportedHtmlLangs(): array
    {
        if (!class_exists('Lang')) {
            return ['tr-TR', 'en-US', 'de-DE'];
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

    /** xhtml:link alternates for XML sitemap (multilingual UI pages only). */
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

    /**
     * True when this sitemap URL has real translated UI (not monolingual blog content).
     * Blog posts/categories/tags must NOT claim hreflang alternates.
     */
    public static function sitemapUrlHasHreflang(string $loc): bool
    {
        $path = (string) (parse_url($loc, PHP_URL_PATH) ?? '');
        $path = rtrim($path, '/') ?: '/';
        // /blog/... posts
        if (preg_match('#/blog/.+#', $path)) {
            return false;
        }
        $query = (string) (parse_url($loc, PHP_URL_QUERY) ?? '');
        if ($query !== '') {
            parse_str($query, $q);
            // category/tag/pagination/search — content is monolingual
            if (isset($q['category']) || isset($q['tag']) || isset($q['p']) || isset($q['q'])) {
                return false;
            }
        }
        return true;
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
        $logo = function_exists('logo_url') ? logo_url() : '';
        if ($logo !== '' && !preg_match('#^https?://#i', $logo)) {
            $logo = self::absoluteUrl($logo);
        }
        $schema = [
            '@type' => 'Organization',
            '@id' => ($siteUrl ?: self::absoluteUrl(home_path())) . '/#organization',
            'name' => $siteName,
            'url' => $siteUrl ?: self::absoluteUrl(home_path()),
            'description' => $description ?? '',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $logo !== '' ? $logo : og_image_url(),
            ],
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
        $sameAs = self::sameAsProfiles();
        if ($sameAs !== []) {
            $schema['sameAs'] = $sameAs;
        }
        if ($lang !== null) {
            $schema['inLanguage'] = self::pageLanguage($lang);
        }
        return $schema;
    }

    /** @return list<string> Social profile URLs from SOCIAL_SAME_AS (comma-separated). */
    public static function sameAsProfiles(): array
    {
        if (!defined('SOCIAL_SAME_AS') || trim((string) SOCIAL_SAME_AS) === '') {
            return [];
        }
        $parts = preg_split('/\s*,\s*/', trim((string) SOCIAL_SAME_AS)) ?: [];
        $out = [];
        foreach ($parts as $url) {
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $out[] = $url;
            }
        }
        return array_values(array_unique($out));
    }

    /** Twitter @handle for twitter:site (TWITTER_SITE without @). */
    public static function twitterSiteMeta(): string
    {
        if (!defined('TWITTER_SITE') || trim((string) TWITTER_SITE) === '') {
            return '';
        }
        $handle = ltrim(trim((string) TWITTER_SITE), '@');
        if ($handle === '') {
            return '';
        }
        return '<meta name="twitter:site" content="@' . self::e($handle) . '">';
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
        $siteUrl = self::siteUrl() ?: self::absoluteUrl(home_path());
        $searchTarget = self::absoluteUrl(path('blog.php')) . '?q={search_term_string}';
        return [
            '@type' => 'WebSite',
            '@id' => $siteUrl . '/#website',
            'name' => self::siteName(),
            'url' => $siteUrl,
            'description' => $description ?? '',
            'inLanguage' => self::supportedHtmlLangs(),
            'publisher' => ['@id' => $siteUrl . '/#organization'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $searchTarget,
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * SoftwareApplication for the SMM panel product (home / pricing).
     *
     * @param array{min_price?: string, currency?: string}|null $offers
     */
    public static function softwareApplicationSchema(
        string $description,
        ?string $lang = null,
        ?array $offers = null
    ): array {
        $siteUrl = self::siteUrl() ?: self::absoluteUrl(home_path());
        $schema = [
            '@type' => 'SoftwareApplication',
            'name' => self::siteName() . ' SMM Panel',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => $siteUrl,
            'description' => $description,
            'offers' => [
                '@type' => 'Offer',
                'price' => $offers['min_price'] ?? '0',
                'priceCurrency' => $offers['currency'] ?? 'USD',
                'availability' => 'https://schema.org/InStock',
                'url' => self::absoluteUrl(path('pricing.php')),
            ],
            'provider' => ['@id' => $siteUrl . '/#organization'],
        ];
        if ($lang !== null) {
            $schema['inLanguage'] = self::pageLanguage($lang);
        }
        return $schema;
    }

    /**
     * ItemList of service Offers for the public pricing page.
     *
     * @param list<array{name?: string, platform?: string, retail_rate?: float|int|string}> $services
     */
    public static function pricingOfferListSchema(array $services, string $pageUrl, ?string $lang = null): array
    {
        $elements = [];
        $position = 1;
        foreach (array_slice($services, 0, 24) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $platform = trim((string) ($row['platform'] ?? ''));
            $rate = round((float) ($row['retail_rate'] ?? 0), 4);
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'item' => [
                    '@type' => 'Product',
                    'name' => $platform !== '' ? ($platform . ' — ' . $name) : $name,
                    'category' => $platform !== '' ? $platform : 'SMM',
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => number_format(max(0, $rate), 4, '.', ''),
                        'priceCurrency' => 'USD',
                        'availability' => 'https://schema.org/InStock',
                        'url' => $pageUrl,
                        'priceSpecification' => [
                            '@type' => 'UnitPriceSpecification',
                            'price' => number_format(max(0, $rate), 4, '.', ''),
                            'priceCurrency' => 'USD',
                            'referenceQuantity' => [
                                '@type' => 'QuantitativeValue',
                                'value' => 1000,
                                'unitText' => 'units',
                            ],
                        ],
                    ],
                ],
            ];
        }
        $schema = [
            '@type' => 'ItemList',
            'name' => self::siteName() . ' SMM Prices',
            'url' => $pageUrl,
            'numberOfItems' => count($elements),
            'itemListElement' => $elements,
        ];
        if ($lang !== null) {
            $schema['inLanguage'] = self::pageLanguage($lang);
        }
        return $schema;
    }

    public static function webPageSchema(string $name, string $description, string $url, ?string $lang = null): array
    {
        $schema = [
            '@type' => 'WebPage',
            'name' => $name,
            'description' => $description,
            'url' => $url,
            'isPartOf' => [
                '@id' => (self::siteUrl() ?: self::absoluteUrl(home_path())) . '/#website',
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
     * @param array<int, array{name: string, text?: string}> $steps
     */
    public static function howToSchema(string $name, string $description, array $steps, ?string $lang = null): array
    {
        $list = [];
        $position = 1;
        foreach ($steps as $step) {
            $label = trim((string) ($step['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $item = [
                '@type' => 'HowToStep',
                'position' => $position++,
                'name' => $label,
            ];
            $text = trim((string) ($step['text'] ?? ''));
            if ($text !== '') {
                $item['text'] = $text;
            }
            $list[] = $item;
        }
        $schema = [
            '@type' => 'HowTo',
            'name' => $name,
            'description' => $description,
            'step' => $list,
        ];
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
