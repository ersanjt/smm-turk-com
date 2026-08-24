<?php
class SmmApi {

    private string $api_url;
    private string $api_key;

    public function __construct(?string $api_url = null, ?string $api_key = null) {
        $db = Database::getInstance();
        if ($api_url !== null && $api_url !== '') {
            $this->api_url = $api_url;
        } else {
            $this->api_url = trim((string) ($db->getSetting('api_url') ?? ''));
            if ($this->api_url === '') {
                $this->api_url = defined('PROVIDER_API_URL') ? PROVIDER_API_URL : 'https://smmfollows.com/api/v2';
            }
        }
        if ($api_key !== null && $api_key !== '') {
            $this->api_key = $api_key;
            return;
        }
        $dbKey = $db->getSetting('api_key');
        $this->api_key = ($dbKey !== null && $dbKey !== '')
            ? $dbKey
            : (defined('PROVIDER_API_KEY') ? PROVIDER_API_KEY : '');
    }

    public function order(array $data): ?object {
        $post = array_merge(['key' => $this->api_key, 'action' => 'add'], $data);
        return json_decode((string)$this->connect($post));
    }

    public function status(int $order_id): ?object {
        return json_decode($this->connect([
            'key' => $this->api_key, 'action' => 'status', 'order' => $order_id,
        ]));
    }

    public function multiStatus(array $order_ids): ?object {
        return json_decode($this->connect([
            'key' => $this->api_key, 'action' => 'status', 'orders' => implode(',', $order_ids),
        ]));
    }

    public function services(): ?array {
        $result = json_decode($this->connect(['key' => $this->api_key, 'action' => 'services']), true);
        return is_array($result) ? $result : null;
    }

    public function refill(int $orderId): ?object {
        return json_decode($this->connect([
            'key' => $this->api_key, 'action' => 'refill', 'order' => $orderId,
        ]));
    }

    public function multiRefill(array $orderIds): ?array {
        return json_decode($this->connect([
            'key' => $this->api_key, 'action' => 'refill', 'orders' => implode(',', $orderIds),
        ]), true);
    }

    public function refillStatus(int|string $refillId): ?object {
        return json_decode($this->connect([
            'key' => $this->api_key, 'action' => 'refill_status', 'refill' => (string)$refillId,
        ]));
    }

    public function multiRefillStatus(array $refillIds): ?array {
        return json_decode($this->connect([
            'key' => $this->api_key, 'action' => 'refill_status', 'refills' => implode(',', $refillIds),
        ]), true);
    }

    public function cancel(array $orderIds): ?array {
        if (count($orderIds) === 1) {
            $single = json_decode($this->connect([
                'key' => $this->api_key, 'action' => 'cancel', 'order' => $orderIds[0],
            ]), true);
            if (is_array($single) && !isset($single['error'])) {
                return [['order' => $orderIds[0], 'cancel' => $single['cancel'] ?? 1]];
            }
        }
        return json_decode($this->connect([
            'key' => $this->api_key, 'action' => 'cancel', 'orders' => implode(',', $orderIds),
        ]), true);
    }

    public function balance(): ?object {
        return json_decode($this->connect(['key' => $this->api_key, 'action' => 'balance']));
    }

    public function testConnection(): array {
        $result = $this->balance();
        if ($result && isset($result->balance)) {
            return ['success' => true, 'balance' => $result->balance, 'currency' => $result->currency ?? 'USD'];
        }
        return ['success' => false, 'error' => 'Invalid API key or connection failed'];
    }

    private function connect(array $post): string|false {
        $_post = [];
        foreach ($post as $name => $value) {
            $_post[] = $name . '=' . urlencode((string)$value);
        }
        $isDebug = (getenv('SMM_DEBUG') === '1' || (defined('SMM_DEBUG') && SMM_DEBUG === true));
        $verifySsl = !$isDebug;
        $ch = curl_init($this->api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTFIELDS => implode('&', $_post),
            CURLOPT_USERAGENT => 'SMMTurk/1.0',
            CURLOPT_TIMEOUT => 30,
        ]);
        $result = curl_exec($ch);
        if (curl_errno($ch) != 0 && empty($result)) {
            $result = json_encode(['error' => curl_error($ch)]);
        }
        curl_close($ch);
        return $result;
    }
}
