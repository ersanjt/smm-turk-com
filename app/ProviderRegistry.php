<?php
/**
 * Multi-provider routing (SmmFollows + SMMFA).
 */
class ProviderRegistry
{
    public const PRIMARY = 'smmfollows';
    public const SMMFA = 'smmfa';
    public const SMMFA_SERVICE_OFFSET = 8000000;

    /** Customer-facing catalog tiers */
    public const BRAND_ONE = 'SMM Turk One';
    public const BRAND_PRO = 'SMM Turk Pro';

    /** @return array<string, array{name: string, brand: string, url_setting: string, key_setting: string, default_url: string, enabled_setting?: string}> */
    public static function definitions(): array
    {
        return [
            self::PRIMARY => [
                'name' => 'SmmFollows',
                'brand' => self::BRAND_ONE,
                'url_setting' => 'api_url',
                'key_setting' => 'api_key',
                'default_url' => 'https://smmfollows.com/api/v2',
            ],
            self::SMMFA => [
                'name' => 'SMMFA',
                'brand' => self::BRAND_PRO,
                'url_setting' => 'api_url_smmfa',
                'key_setting' => 'api_key_smmfa',
                'default_url' => 'https://smmfa.com/api/v2',
                'enabled_setting' => 'provider_smmfa_enabled',
            ],
        ];
    }

    public static function brandLabel(string $slug): string
    {
        return self::definitions()[$slug]['brand'] ?? self::BRAND_ONE;
    }

    /** Map ?tier=one|pro to provider slug. */
    public static function providerFromTier(string $tier): ?string
    {
        $tier = strtolower(trim($tier));
        return match ($tier) {
            'one' => self::PRIMARY,
            'pro' => self::SMMFA,
            default => null,
        };
    }

    public static function tierFromProvider(string $provider): string
    {
        return $provider === self::SMMFA ? 'pro' : 'one';
    }

    /** True when services.provider exists (attempts auto-migrate once per request). */
    public static function providerSchemaReady(): bool
    {
        return OrderManager::ensureProviderSchema();
    }

    /** SQL suffix + bound params for optional provider filter. */
    public static function providerFilter(?string $slug): array
    {
        if ($slug === null || $slug === '' || !self::providerSchemaReady()) {
            return ['', []];
        }
        return [' AND provider = ?', [$slug]];
    }

    /** Strip legacy prefixes before re-branding on sync. */
    public static function stripBrandPrefix(string $category): string
    {
        $category = trim($category);
        $category = preg_replace('/^(SMMFA\s*—\s*|SMM Turk One\s*—\s*|SMM Turk Pro\s*—\s*)/iu', '', $category) ?? $category;
        return trim($category);
    }

    /** Store category as "SMM Turk One — YouTube" / "SMM Turk Pro — Instagram". */
    public static function formatServiceCategory(string $provider, string $upstreamCategory): string
    {
        $brand = self::brandLabel($provider);
        $inner = self::stripBrandPrefix($upstreamCategory);
        if ($inner === '') {
            return $brand;
        }
        return ContentCorrections::fitCategory($brand . ' — ' . $inner);
    }

    /**
     * Big tier picker: SMM Turk One | SMM Turk Pro | All
     */
    public static function serviceTierStrip(string $basePath, string $activeTier = '', string $search = '', array $extraParams = []): string
    {
        $db = Database::getInstance();
        $counts = [];
        if (self::providerSchemaReady()) {
            foreach ($db->fetchAll("SELECT provider, COUNT(*) c FROM services WHERE status='active' GROUP BY provider") as $row) {
                $counts[$row['provider'] ?? ''] = (int) $row['c'];
            }
        } else {
            $counts[self::PRIMARY] = (int) ($db->fetch("SELECT COUNT(*) c FROM services WHERE status='active'")['c'] ?? 0);
        }
        $oneCount = $counts[self::PRIMARY] ?? 0;
        $proCount = $counts[self::SMMFA] ?? 0;
        $total = $oneCount + $proCount;

        $html = '<div class="service-tier-wrap"><div class="service-tier-strip" role="tablist" aria-label="Service catalog tier">';
        $tiers = [
            '' => ['label' => 'All Services', 'desc' => 'Every catalog', 'count' => $total, 'class' => 'tier-all'],
            'one' => ['label' => self::BRAND_ONE, 'desc' => 'Standard catalog', 'count' => $oneCount, 'class' => 'tier-one'],
            'pro' => ['label' => self::BRAND_PRO, 'desc' => 'Premium catalog', 'count' => $proCount, 'class' => 'tier-pro'],
        ];
        foreach ($tiers as $tierKey => $info) {
            $proReady = self::isEnabled(self::SMMFA) && self::api(self::SMMFA) !== null;
            if ($tierKey === 'pro' && !$proReady) {
                $info['desc'] = $info['count'] > 0
                    ? 'Premium catalog'
                    : 'Premium catalog · sync in Admin';
            }
            $params = $extraParams;
            if ($search !== '') {
                $params['q'] = $search;
            }
            if ($tierKey !== '') {
                $params['tier'] = $tierKey;
            } else {
                unset($params['tier']);
            }
            $url = path($basePath) . ($params ? '?' . http_build_query($params) : '');
            $active = $activeTier === $tierKey;
            $extraClass = ($tierKey === 'pro' && !$proReady && $info['count'] === 0) ? ' tier-setup' : '';
            $html .= '<a class="service-tier-card ' . $info['class'] . $extraClass . ($active ? ' active' : '') . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($info['desc'], ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<span class="service-tier-label">' . htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8') . '</span>';
            $html .= '<span class="service-tier-desc">' . htmlspecialchars($info['desc'], ENT_QUOTES, 'UTF-8') . '</span>';
            $html .= '<span class="service-tier-count">' . (int) $info['count'] . ' services</span>';
            $html .= '</a>';
        }
        $html .= '</div></div>';
        return $html;
    }

    /** Shorter pill label when a tier tab is already selected. */
    public static function displayCategoryName(string $category, string $activeTier = ''): string
    {
        if ($activeTier === 'one') {
            $short = preg_replace('/^' . preg_quote(self::BRAND_ONE, '/') . '\s*—\s*/iu', '', $category);
            return ($short !== null && $short !== '') ? $short : $category;
        }
        if ($activeTier === 'pro') {
            $short = preg_replace('/^' . preg_quote(self::BRAND_PRO, '/') . '\s*—\s*/iu', '', $category);
            return ($short !== null && $short !== '') ? $short : $category;
        }
        return $category;
    }

    public static function isEnabled(string $slug): bool
    {
        if ($slug === self::PRIMARY) {
            return true;
        }
        $def = self::definitions()[$slug] ?? null;
        if (!$def) {
            return false;
        }
        if (empty($def['enabled_setting'])) {
            return true;
        }
        $db = Database::getInstance();
        return ($db->getSetting($def['enabled_setting']) ?? '0') === '1';
    }

    public static function api(string $slug): ?SmmApi
    {
        if ($slug === self::PRIMARY && defined('SMM_CHILD_PANEL') && SMM_CHILD_PANEL) {
            $url = defined('PROVIDER_API_URL') ? trim((string) PROVIDER_API_URL) : '';
            $key = defined('PROVIDER_API_KEY') ? trim((string) PROVIDER_API_KEY) : '';
            if ($url !== '' && $key !== '') {
                return new SmmApi($url, $key);
            }
        }
        if (!self::isEnabled($slug)) {
            return null;
        }
        $def = self::definitions()[$slug] ?? null;
        if (!$def) {
            return null;
        }
        $db = Database::getInstance();
        $url = trim((string) ($db->getSetting($def['url_setting']) ?? ''));
        $key = trim((string) ($db->getSetting($def['key_setting']) ?? ''));
        if ($url === '') {
            $url = $def['default_url'];
        }
        if ($key === '') {
            return null;
        }
        return new SmmApi($url, $key);
    }

    /** Panel-facing service_id stored in DB. */
    public static function panelServiceId(string $provider, int $upstreamId): int
    {
        if ($provider === self::SMMFA) {
            return self::SMMFA_SERVICE_OFFSET + $upstreamId;
        }
        return $upstreamId;
    }

    /** Upstream provider service id for API order call. */
    public static function upstreamServiceId(array $service): int
    {
        if (!empty($service['provider_service_id'])) {
            return (int) $service['provider_service_id'];
        }
        $panelId = (int) ($service['service_id'] ?? 0);
        $provider = $service['provider'] ?? self::PRIMARY;
        if ($provider === self::SMMFA && $panelId >= self::SMMFA_SERVICE_OFFSET) {
            return $panelId - self::SMMFA_SERVICE_OFFSET;
        }
        return $panelId;
    }

    public static function providerForService(array $service): string
    {
        $p = trim((string) ($service['provider'] ?? ''));
        return $p !== '' ? $p : self::PRIMARY;
    }

    public static function apiForOrder(Database $db, int $orderId, ?int $userId = null): ?SmmApi
    {
        $sql = "SELECT o.provider, s.provider AS svc_provider FROM orders o
                LEFT JOIN services s ON o.service_id = s.service_id WHERE o.id = ?";
        $params = [$orderId];
        if ($userId !== null) {
            $sql .= ' AND o.user_id = ?';
            $params[] = $userId;
        }
        $row = $db->fetch($sql, $params);
        if (!$row) {
            return null;
        }
        $slug = trim((string) ($row['provider'] ?? $row['svc_provider'] ?? self::PRIMARY));
        return self::api($slug !== '' ? $slug : self::PRIMARY);
    }

    /** @return array<string, SmmApi> */
    public static function enabledApis(): array
    {
        $apis = [];
        foreach (array_keys(self::definitions()) as $slug) {
            $api = self::api($slug);
            if ($api) {
                $apis[$slug] = $api;
            }
        }
        return $apis;
    }
}
