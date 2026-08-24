<?php
/**
 * Parse and validate bulk order lines (service_id | link | quantity).
 */
class MassOrderHelper
{
    public const MAX_LINES = 100;

    /** @return list<array{line: int, raw: string, service_id: int, link: string, quantity: int, error?: string}> */
    public static function parseBulk(string $text): array
    {
        $rows = [];
        $lineNum = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $lineNum++;
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parsed = self::parseLine($line);
            $parsed['line'] = $lineNum;
            $parsed['raw'] = $line;
            $rows[] = $parsed;
            if (count($rows) >= self::MAX_LINES) {
                break;
            }
        }
        return $rows;
    }

    /** @return array{service_id: int, link: string, quantity: int, error?: string} */
    private static function parseLine(string $line): array
    {
        $parts = null;
        if (preg_match('/[|\t]/', $line)) {
            $parts = array_values(array_filter(array_map('trim', preg_split('/[|\t]+/', $line)), static fn($p) => $p !== ''));
        } elseif (substr_count($line, ',') >= 2) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $line)), static fn($p) => $p !== ''));
        } else {
            $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if ($parts === null || count($parts) < 3) {
            return ['service_id' => 0, 'link' => '', 'quantity' => 0, 'error' => 'Invalid format — use: service_id | link | quantity'];
        }

        if (preg_match('/[|\t]/', $line) || (count($parts) === 3 && !preg_match('/\s/', $parts[1]))) {
            [$serviceIdRaw, $link, $quantityRaw] = [$parts[0], $parts[1], $parts[2]];
        } else {
            $serviceIdRaw = array_shift($parts);
            $quantityRaw = array_pop($parts);
            $link = implode(' ', $parts);
        }

        $serviceId = (int) preg_replace('/\D/', '', (string) $serviceIdRaw);
        $quantity = (int) preg_replace('/\D/', '', (string) $quantityRaw);
        $link = normalize_order_link(trim((string) $link));

        if ($serviceId <= 0) {
            return ['service_id' => 0, 'link' => $link, 'quantity' => $quantity, 'error' => 'Invalid service ID'];
        }
        if ($link === '') {
            return ['service_id' => $serviceId, 'link' => '', 'quantity' => $quantity, 'error' => 'Invalid or missing link'];
        }
        if ($quantity <= 0) {
            return ['service_id' => $serviceId, 'link' => $link, 'quantity' => 0, 'error' => 'Quantity must be greater than 0'];
        }

        return ['service_id' => $serviceId, 'link' => $link, 'quantity' => $quantity];
    }

    public static function estimateCharge(array $service, int $quantity): float
    {
        $markup = 1 + ((float) ($service['markup'] ?? 0) / 100);
        return round(((float) $service['rate'] / 1000) * $quantity * $markup, 4);
    }

    /**
     * @param list<array{line: int, raw: string, service_id: int, link: string, quantity: int, error?: string}> $rows
     * @return array{rows: list<array>, summary: array{total: int, valid: int, invalid: int, duplicate: int, charge: float}, services: array<int, array>}
     */
    public static function validate(int $userId, array $rows): array
    {
        $db = Database::getInstance();
        $balance = (float) ($db->fetch('SELECT balance FROM users WHERE id = ?', [$userId])['balance'] ?? 0);

        $serviceIds = [];
        foreach ($rows as $row) {
            if (empty($row['error']) && ($row['service_id'] ?? 0) > 0) {
                $serviceIds[] = (int) $row['service_id'];
            }
        }
        $serviceIds = array_values(array_unique($serviceIds));

        $servicesById = [];
        if ($serviceIds !== []) {
            $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
            foreach ($db->fetchAll(
                "SELECT * FROM services WHERE service_id IN ($placeholders) AND status = 'active'",
                $serviceIds
            ) as $service) {
                $servicesById[(int) $service['service_id']] = $service;
            }
        }

        $seen = [];
        $validated = [];
        $totalCharge = 0.0;
        $valid = 0;
        $invalid = 0;
        $duplicate = 0;
        $runningBalance = $balance;

        foreach ($rows as $row) {
            $item = $row;
            $item['ok'] = false;
            $item['duplicate'] = false;
            $item['charge'] = 0.0;
            $item['service_name'] = '';
            $item['provider'] = '';

            if (!empty($row['error'])) {
                $item['error'] = $row['error'];
                $invalid++;
                $validated[] = $item;
                continue;
            }

            $dupKey = $row['service_id'] . '|' . strtolower($row['link']) . '|' . $row['quantity'];
            if (isset($seen[$dupKey])) {
                $item['duplicate'] = true;
                $duplicate++;
            }
            $seen[$dupKey] = true;

            $service = $servicesById[$row['service_id']] ?? null;
            if (!$service) {
                $item['error'] = 'Service not found or inactive';
                $invalid++;
                $validated[] = $item;
                continue;
            }

            if ($row['quantity'] < (int) $service['min'] || $row['quantity'] > (int) $service['max']) {
                $item['error'] = "Quantity must be between {$service['min']} and {$service['max']}";
                $invalid++;
                $validated[] = $item;
                continue;
            }

            $charge = self::estimateCharge($service, $row['quantity']);
            $item['charge'] = $charge;
            $item['service_name'] = (string) $service['name'];
            $item['provider'] = ProviderRegistry::providerForService($service);

            if ($charge > $runningBalance) {
                $item['error'] = 'Insufficient balance for this line';
                $invalid++;
                $validated[] = $item;
                continue;
            }

            $item['ok'] = true;
            $runningBalance -= $charge;
            $totalCharge += $charge;
            $valid++;
            $validated[] = $item;
        }

        return [
            'rows' => $validated,
            'summary' => [
                'total' => count($rows),
                'valid' => $valid,
                'invalid' => $invalid,
                'duplicate' => $duplicate,
                'charge' => round($totalCharge, 4),
                'balance' => $balance,
                'balance_after' => round($balance - $totalCharge, 4),
            ],
            'services' => $servicesById,
        ];
    }
}
