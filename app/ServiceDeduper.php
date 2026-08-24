<?php
/**
 * Remove duplicate catalog rows (same provider + name or upstream id).
 */
class ServiceDeduper
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** @return array{normalized_categories: int, by_upstream_id: int, by_name: int, remaining: int} */
    public function run(?string $onlyProvider = null): array
    {
        $normalized = $this->normalizeCategories($onlyProvider);
        $byUpstream = $this->dedupeByUpstreamId($onlyProvider);
        $byName = $this->dedupeByName($onlyProvider);

        $sql = "SELECT COUNT(*) c FROM services WHERE status='active'";
        $params = [];
        if ($onlyProvider) {
            $sql .= ' AND provider = ?';
            $params[] = $onlyProvider;
        }
        $remaining = (int) ($this->db->fetch($sql, $params)['c'] ?? 0);

        return [
            'normalized_categories' => $normalized,
            'by_upstream_id' => $byUpstream,
            'by_name' => $byName,
            'remaining' => $remaining,
        ];
    }

    public function normalizeCategories(?string $onlyProvider = null): int
    {
        $sql = "SELECT id, category, provider FROM services WHERE status='active'";
        $params = [];
        if ($onlyProvider) {
            $sql .= ' AND provider = ?';
            $params[] = $onlyProvider;
        }
        $rows = $this->db->fetchAll($sql, $params);
        $updated = 0;
        foreach ($rows as $row) {
            $fixed = $this->normalizeCategoryString((string) ($row['category'] ?? ''), (string) ($row['provider'] ?? ''));
            $current = trim((string) ($row['category'] ?? ''));
            if ($fixed !== $current) {
                $this->db->execute('UPDATE services SET category = ? WHERE id = ?', [$fixed, $row['id']]);
                $updated++;
            }
        }
        return $updated;
    }

    private function normalizeCategoryString(string $category, string $provider): string
    {
        $category = trim($category);
        if ($category === '') {
            return '';
        }
        $brand = ProviderRegistry::brandLabel($provider ?: ProviderRegistry::PRIMARY);
        $double = $brand . ' — ' . $brand . ' — ';
        while (str_contains($category, $double)) {
            $category = str_replace($double, $brand . ' — ', $category);
        }
        return ContentCorrections::fitCategory($category);
    }

    public function dedupeByUpstreamId(?string $onlyProvider = null): int
    {
        $sql = "SELECT id, service_id, provider, provider_service_id
                FROM services
                WHERE status='active' AND provider_service_id > 0";
        $params = [];
        if ($onlyProvider) {
            $sql .= ' AND provider = ?';
            $params[] = $onlyProvider;
        }
        $sql .= ' ORDER BY provider, provider_service_id, service_id ASC';
        $rows = $this->db->fetchAll($sql, $params);

        $seen = [];
        $deactivate = [];
        foreach ($rows as $row) {
            $key = ($row['provider'] ?? '') . ':' . (int) $row['provider_service_id'];
            if (isset($seen[$key])) {
                $deactivate[] = (int) $row['id'];
                continue;
            }
            $seen[$key] = (int) $row['service_id'];
        }
        return $this->deactivateIds($deactivate);
    }

    public function dedupeByName(?string $onlyProvider = null): int
    {
        $sql = "SELECT id, service_id, provider, name
                FROM services
                WHERE status='active'";
        $params = [];
        if ($onlyProvider) {
            $sql .= ' AND provider = ?';
            $params[] = $onlyProvider;
        }
        $sql .= ' ORDER BY provider, service_id ASC';
        $rows = $this->db->fetchAll($sql, $params);

        $seen = [];
        $deactivate = [];
        foreach ($rows as $row) {
            $nameKey = strtolower(trim((string) ($row['name'] ?? '')));
            if ($nameKey === '') {
                continue;
            }
            $key = ($row['provider'] ?? ProviderRegistry::PRIMARY) . ':' . $nameKey;
            if (isset($seen[$key])) {
                $deactivate[] = (int) $row['id'];
                continue;
            }
            $seen[$key] = (int) $row['service_id'];
        }
        return $this->deactivateIds($deactivate);
    }

    /** @param int[] $ids */
    private function deactivateIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $ids = array_values(array_unique($ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->execute(
            "UPDATE services SET status = 'inactive' WHERE id IN ($placeholders) AND status = 'active'",
            $ids
        );
    }
}
