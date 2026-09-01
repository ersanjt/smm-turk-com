<?php
/**
 * Create payments and verify gateway callbacks.
 */
class PaymentProcessor
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @return array{success: bool, redirect_url?: string, error?: string, manual?: bool}
     */
    public function initiate(string $method, int $transactionId, int $userId, float $amount, string $userEmail = ''): array
    {
        if (!PaymentRegistry::isEnabled($method)) {
            return ['success' => false, 'error' => 'This payment method is not available.'];
        }

        $orderId = PaymentRegistry::orderId($transactionId);

        return match ($method) {
            PaymentRegistry::USDT_TRC20 => ['success' => true, 'manual' => true],
            PaymentRegistry::HELEKET => $this->heleketCreate($transactionId, $orderId, $amount, $userEmail),
            PaymentRegistry::CRYPTOCLOUD => $this->cryptoCloudCreate($transactionId, $orderId, $amount, $userEmail),
            PaymentRegistry::BINANCE_PAY => $this->binancePayCreate($transactionId, $orderId, $amount),
            PaymentRegistry::ZARINPAL => $this->zarinpalCreate($transactionId, $orderId, $amount, $userEmail),
            PaymentRegistry::SMMPAYGATE => $this->smmPayGateCreate($transactionId, $orderId, $amount, $userEmail),
            default => PaymentRegistry::isManualWalletSlug($method)
                ? ['success' => true, 'manual' => true]
                : ['success' => false, 'error' => 'Unknown payment method.'],
        };
    }

    /** @return array{success: bool, credited?: bool, error?: string, message?: string} */
    public function handleCallback(string $gateway, array $params, ?string $rawBody = null): array
    {
        return match ($gateway) {
            PaymentRegistry::ZARINPAL => $this->zarinpalCallback($params),
            PaymentRegistry::HELEKET => $this->heleketWebhook($rawBody ?? '', $_SERVER['HTTP_SIGN'] ?? $_SERVER['HTTP_MERCHANT'] ?? ''),
            PaymentRegistry::CRYPTOCLOUD => $this->cryptoCloudWebhook($params, $rawBody ?? ''),
            PaymentRegistry::BINANCE_PAY => $this->binancePayWebhook($rawBody ?? ''),
            PaymentRegistry::SMMPAYGATE => $this->smmPayGateWebhook($params, $rawBody ?? ''),
            default => ['success' => false, 'error' => 'Unknown gateway.'],
        };
    }

    private function storeGatewayRef(int $transactionId, string $ref): void
    {
        $this->db->execute(
            "UPDATE transactions SET reference = ? WHERE id = ? AND type = 'deposit' AND status = 'pending'",
            [substr($ref, 0, 100), $transactionId]
        );
    }

    private function findPendingByOrderId(string $orderId): ?array
    {
        if (!preg_match('/^dep_(\d+)$/', $orderId, $m)) {
            return null;
        }
        $tx = $this->db->fetch(
            "SELECT id, user_id, amount, description, reference, status FROM transactions WHERE id = ? AND type = 'deposit' AND status = 'pending'",
            [(int) $m[1]]
        );
        return $tx ?: null;
    }

    private function creditIfPending(int $transactionId): array
    {
        $dm = new DepositManager();
        return $dm->approvePendingDeposit($transactionId);
    }

    private function httpJson(string $method, string $url, ?array $body = null, array $headers = [], int $timeout = 30): ?array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_USERAGENT => 'SMM-Turk-Payments/1.0',
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body !== null ? json_encode($body) : '{}';
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code >= 500) {
            Logger::log("Payment HTTP $code $url", 'deposits');
            return null;
        }
        $json = json_decode((string) $resp, true);
        return is_array($json) ? $json : null;
    }

    // ─── Heleket ─────────────────────────────────────────────────────────────

    private function heleketSign(array $payload): array
    {
        $merchant = trim((string) $this->db->getSetting('payment_heleket_merchant_id'));
        $apiKey = trim((string) $this->db->getSetting('payment_heleket_api_key'));
        $json = json_encode($payload);
        $sign = md5(base64_encode($json) . $apiKey);
        return [
            'merchant: ' . $merchant,
            'sign: ' . $sign,
        ];
    }

    private function heleketMode(): string
    {
        $mode = strtolower(trim((string) ($this->db->getSetting('payment_heleket_mode') ?? 'panel')));
        return in_array($mode, ['panel', 'redirect'], true) ? $mode : 'panel';
    }

    private function heleketCreate(int $txId, string $orderId, float $amount, string $email): array
    {
        $mode = $this->heleketMode();
        $network = strtolower(trim((string) ($this->db->getSetting('payment_heleket_network') ?: 'bsc')));
        $toCurrency = strtoupper(trim((string) ($this->db->getSetting('payment_heleket_currency') ?: 'USDT')));

        $payload = [
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'USD',
            'order_id' => $orderId,
            'url_callback' => PaymentRegistry::webhookUrl(PaymentRegistry::HELEKET),
            'is_payment_multiple' => true,
        ];

        if ($mode === 'panel') {
            $payload['to_currency'] = $toCurrency;
            $payload['network'] = $network;
        } else {
            $payload['url_return'] = PaymentRegistry::callbackUrl(PaymentRegistry::HELEKET);
            $payload['url_success'] = PaymentRegistry::callbackUrl(PaymentRegistry::HELEKET) . '&status=success';
        }

        if ($email !== '') {
            $payload['payer_email'] = $email;
        }

        $resp = $this->httpJson('POST', 'https://api.heleket.com/v1/payment', $payload, $this->heleketSign($payload));
        if ($resp === null) {
            return ['success' => false, 'error' => 'Could not connect to Heleket. Try again.'];
        }
        if (isset($resp['state']) && (int) $resp['state'] !== 0) {
            $msg = $resp['message'] ?? 'Heleket rejected the request.';
            return ['success' => false, 'error' => is_string($msg) ? $msg : 'Heleket payment failed.'];
        }

        $result = $resp['result'] ?? $resp['data'] ?? $resp;
        $uuid = (string) ($result['uuid'] ?? '');
        $address = trim((string) ($result['address'] ?? ''));
        $payerAmount = (string) ($result['payer_amount'] ?? '');
        $payUrl = $result['url'] ?? $result['payment_url'] ?? null;

        if ($mode === 'panel' && $address !== '') {
            $ref = 'hk:' . $uuid . '|' . $address;
            $this->storeGatewayRef($txId, $ref);
            return [
                'success' => true,
                'manual' => true,
                'heleket' => [
                    'uuid' => $uuid,
                    'address' => $address,
                    'payer_amount' => $payerAmount,
                    'currency' => (string) ($result['payer_currency'] ?? $toCurrency),
                    'network' => (string) ($result['network'] ?? $network),
                    'pay_url' => $payUrl,
                ],
            ];
        }

        if (!$payUrl) {
            $msg = $resp['message'] ?? $resp['error'] ?? 'Heleket did not return a payment URL.';
            return ['success' => false, 'error' => is_string($msg) ? $msg : 'Heleket payment failed.'];
        }
        $this->storeGatewayRef($txId, 'hk:' . ($uuid ?: $orderId) . '|');
        return ['success' => true, 'redirect_url' => $payUrl];
    }

    /** Reload invoice details from Heleket API (panel mode). */
    public function heleketPaymentInfo(string $uuid): ?array
    {
        if ($uuid === '') {
            return null;
        }
        $payload = ['uuid' => $uuid];
        $resp = $this->httpJson('POST', 'https://api.heleket.com/v1/payment/info', $payload, $this->heleketSign($payload));
        if ($resp === null || (isset($resp['state']) && (int) $resp['state'] !== 0)) {
            return null;
        }
        return $resp['result'] ?? $resp['data'] ?? null;
    }

    private function creditIfPaidMatches(int $transactionId, ?float $paidAmount): array
    {
        $tx = $this->db->fetch(
            "SELECT amount FROM transactions WHERE id = ? AND type = 'deposit'",
            [$transactionId]
        );
        if (!$tx) {
            return ['success' => false, 'error' => 'Deposit not found.'];
        }
        if ($paidAmount !== null) {
            $expected = (float) $tx['amount'];
            $tolerance = max(0.02, abs($expected) * 0.02);
            if ($paidAmount <= 0 || abs($paidAmount - $expected) > $tolerance) {
                Logger::log(
                    "Deposit #{$transactionId} amount mismatch paid={$paidAmount} expected={$expected}",
                    'deposits'
                );
                return ['success' => false, 'error' => 'Paid amount does not match deposit.'];
            }
        }
        return $this->creditIfPending($transactionId);
    }

    private function heleketWebhook(string $rawBody, string $headerSign): array
    {
        $apiKey = trim((string) $this->db->getSetting('payment_heleket_api_key'));
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Invalid payload.'];
        }

        // Official verification: sign inside body — doc.heleket.com/methods/payments/webhook
        $sign = (string) ($data['sign'] ?? '');
        unset($data['sign']);
        $hash = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . $apiKey);
        if ($sign === '' || !hash_equals($hash, $sign)) {
            Logger::log('Heleket webhook signature mismatch', 'deposits');
            return ['success' => false, 'error' => 'Invalid Heleket signature.'];
        }

        $orderId = (string) ($data['order_id'] ?? '');
        $status = strtolower((string) ($data['status'] ?? $data['payment_status'] ?? ''));
        if (!in_array($status, ['paid', 'paid_over'], true)) {
            return ['success' => true, 'message' => 'Payment status: ' . $status];
        }

        $tx = $this->findPendingByOrderId($orderId);
        if (!$tx) {
            return ['success' => false, 'error' => 'Deposit not found.'];
        }
        $paid = (float) ($data['payment_amount_usd'] ?? $data['amount'] ?? $data['payer_amount'] ?? 0);
        $result = $this->creditIfPaidMatches((int) $tx['id'], $paid > 0 ? $paid : (float) $tx['amount']);
        return $result['success']
            ? ['success' => true, 'credited' => true, 'message' => 'Heleket payment credited.']
            : ['success' => false, 'error' => $result['error'] ?? 'Credit failed.'];
    }

    // ─── CryptoCloud ─────────────────────────────────────────────────────────

    private function cryptoCloudCreate(int $txId, string $orderId, float $amount, string $email): array
    {
        $shopId = trim((string) $this->db->getSetting('payment_cryptocloud_shop_id'));
        $apiKey = trim((string) $this->db->getSetting('payment_cryptocloud_api_key'));
        $payload = [
            'shop_id' => $shopId,
            'amount' => round($amount, 2),
            'currency' => 'USD',
            'order_id' => $orderId,
        ];
        if ($email !== '') {
            $payload['email'] = $email;
        }
        $resp = $this->httpJson('POST', 'https://api.cryptocloud.plus/v2/invoice/create', $payload, [
            'Authorization: Token ' . $apiKey,
        ]);
        if ($resp === null) {
            return ['success' => false, 'error' => 'Could not connect to CryptoCloud.'];
        }
        $result = $resp['result'] ?? $resp;
        $payUrl = $result['link'] ?? $result['pay_url'] ?? $result['url'] ?? null;
        $invoiceId = $result['invoice_id'] ?? $result['uuid'] ?? $orderId;
        if (!$payUrl) {
            $msg = $resp['status'] ?? $resp['message'] ?? 'CryptoCloud error.';
            return ['success' => false, 'error' => is_string($msg) ? $msg : 'CryptoCloud payment failed.'];
        }
        $this->storeGatewayRef($txId, 'cc:' . $invoiceId);
        return ['success' => true, 'redirect_url' => $payUrl];
    }

    private function cryptoCloudWebhook(array $params, string $rawBody): array
    {
        $apiKey = trim((string) $this->db->getSetting('payment_cryptocloud_api_key'));
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'CryptoCloud is not configured.'];
        }
        if ($params === [] && $rawBody !== '') {
            $json = json_decode($rawBody, true);
            if (is_array($json)) {
                $params = $json;
            }
        }
        $invoiceId = trim((string) ($params['invoice_id'] ?? $params['uuid'] ?? $params['token'] ?? ''));
        $orderId = (string) ($params['order_id'] ?? '');
        $tx = $orderId !== '' ? $this->findPendingByOrderId($orderId) : null;
        if (!$tx && $invoiceId !== '') {
            $tx = $this->db->fetch(
                "SELECT id, user_id, amount, description, reference, status FROM transactions
                 WHERE type = 'deposit' AND status = 'pending' AND reference = ? LIMIT 1",
                ['cc:' . $invoiceId]
            );
        }
        if (!$tx) {
            return ['success' => false, 'error' => 'Deposit not found.'];
        }
        if ($invoiceId === '' && str_starts_with((string) ($tx['reference'] ?? ''), 'cc:')) {
            $invoiceId = substr((string) $tx['reference'], 3);
        }
        if ($invoiceId === '') {
            return ['success' => false, 'error' => 'Missing CryptoCloud invoice id.'];
        }
        $info = $this->httpJson('POST', 'https://api.cryptocloud.plus/v2/invoice/merchant/info', [
            'uuid' => $invoiceId,
        ], ['Authorization: Token ' . $apiKey]);
        if ($info === null) {
            $info = $this->httpJson('POST', 'https://api.cryptocloud.plus/v1/invoice/info', [
                'uuid' => $invoiceId,
            ], ['Authorization: Token ' . $apiKey]);
        }
        $result = is_array($info) ? ($info['result'] ?? $info) : [];
        $status = strtolower((string) ($result['status'] ?? $result['invoice_status'] ?? ''));
        if (!in_array($status, ['paid', 'overpaid', 'success'], true)) {
            return ['success' => true, 'message' => 'CryptoCloud status: ' . $status];
        }
        $paid = (float) ($result['amount_usd'] ?? $result['amount'] ?? $result['paid_amount'] ?? 0);
        $credited = $this->creditIfPaidMatches((int) $tx['id'], $paid > 0 ? $paid : (float) $tx['amount']);
        return $credited['success']
            ? ['success' => true, 'credited' => true, 'message' => 'CryptoCloud payment credited.']
            : ['success' => false, 'error' => $credited['error'] ?? 'Credit failed.'];
    }

    // ─── Binance Pay ─────────────────────────────────────────────────────────

    private function binancePayHeaders(string $body): array
    {
        $apiKey = trim((string) $this->db->getSetting('payment_binance_pay_api_key'));
        $secret = trim((string) $this->db->getSetting('payment_binance_pay_secret'));
        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $payload = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $signature = strtoupper(hash_hmac('SHA512', $payload, $secret));
        return [
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . $apiKey,
            'BinancePay-Signature: ' . $signature,
        ];
    }

    private function binancePayCreate(int $txId, string $orderId, float $amount): array
    {
        $payload = [
            'env' => ['terminalType' => 'WEB'],
            'merchantTradeNo' => $orderId,
            'orderAmount' => round($amount, 2),
            'currency' => 'USDT',
            'goods' => [
                'goodsType' => '01',
                'goodsCategory' => 'D000',
                'referenceGoodsId' => (string) $txId,
                'goodsName' => 'SMM Turk Balance',
                'goodsDetail' => 'Account deposit $' . number_format($amount, 2),
            ],
        ];
        $body = json_encode($payload);
        $resp = $this->httpJson('POST', 'https://bpay.binanceapi.com/binancepay/openapi/v3/order', $payload, $this->binancePayHeaders($body));
        if ($resp === null) {
            return ['success' => false, 'error' => 'Could not connect to Binance Pay.'];
        }
        if (($resp['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'error' => $resp['errorMessage'] ?? 'Binance Pay order failed.'];
        }
        $payUrl = $resp['data']['checkoutUrl'] ?? $resp['data']['universalUrl'] ?? null;
        $prepayId = $resp['data']['prepayId'] ?? $orderId;
        if (!$payUrl) {
            return ['success' => false, 'error' => 'Binance Pay did not return checkout URL.'];
        }
        $this->storeGatewayRef($txId, 'bp:' . $prepayId);
        return ['success' => true, 'redirect_url' => $payUrl];
    }

    private function binancePayWebhook(string $rawBody): array
    {
        $secret = trim((string) $this->db->getSetting('payment_binance_pay_secret'));
        $apiKey = trim((string) $this->db->getSetting('payment_binance_pay_api_key'));
        if ($secret === '' || $apiKey === '') {
            return ['success' => false, 'error' => 'Binance Pay is not configured.'];
        }
        $data = json_decode($rawBody, true);
        $orderId = '';
        if (is_array($data)) {
            $orderId = (string) ($data['data']['merchantTradeNo'] ?? $data['merchantTradeNo'] ?? '');
        }
        if ($orderId === '') {
            return ['success' => false, 'error' => 'Missing Binance order id.'];
        }
        $queryBody = json_encode(['merchantTradeNo' => $orderId]);
        $query = $this->httpJson(
            'POST',
            'https://bpay.binanceapi.com/binancepay/openapi/v2/order/query',
            ['merchantTradeNo' => $orderId],
            $this->binancePayHeaders($queryBody)
        );
        if ($query === null || ($query['status'] ?? '') !== 'SUCCESS') {
            Logger::log('Binance Pay query failed for ' . $orderId, 'deposits');
            return ['success' => false, 'error' => 'Could not verify Binance Pay order.'];
        }
        $order = $query['data'] ?? [];
        $bizStatus = strtoupper((string) ($order['status'] ?? $order['orderStatus'] ?? ''));
        if (!in_array($bizStatus, ['PAID', 'PAY_SUCCESS', 'SUCCESS'], true)) {
            return ['success' => true, 'message' => 'Binance status: ' . $bizStatus];
        }
        $tx = $this->findPendingByOrderId($orderId);
        if (!$tx) {
            return ['success' => false, 'error' => 'Deposit not found.'];
        }
        $paid = (float) ($order['orderAmount'] ?? $order['amount'] ?? 0);
        $result = $this->creditIfPaidMatches((int) $tx['id'], $paid > 0 ? $paid : (float) $tx['amount']);
        return $result['success']
            ? ['success' => true, 'credited' => true, 'message' => 'Binance Pay credited.']
            : ['success' => false, 'error' => $result['error'] ?? 'Credit failed.'];
    }

    // ─── ZarinPal ────────────────────────────────────────────────────────────

    private function zarinpalBase(): string
    {
        $sandbox = ($this->db->getSetting('payment_zarinpal_sandbox') ?? '0') === '1';
        return $sandbox ? 'https://sandbox.zarinpal.com/pg/v4/payment' : 'https://payment.zarinpal.com/pg/v4/payment';
    }

    private function zarinpalCreate(int $txId, string $orderId, float $amount, string $email): array
    {
        $merchantId = trim((string) $this->db->getSetting('payment_zarinpal_merchant_id'));
        $rate = (float) ($this->db->getSetting('payment_zarinpal_usd_rate') ?: 600000);
        $irrAmount = (int) max(10000, round($amount * $rate));
        $payload = [
            'merchant_id' => $merchantId,
            'amount' => $irrAmount,
            'callback_url' => PaymentRegistry::callbackUrl(PaymentRegistry::ZARINPAL),
            'description' => 'SMM Turk deposit #' . $txId,
            'metadata' => array_filter(['email' => $email ?: null, 'order_id' => $orderId]),
        ];
        $resp = $this->httpJson('POST', $this->zarinpalBase() . '/request.json', $payload);
        if ($resp === null) {
            return ['success' => false, 'error' => 'Could not connect to ZarinPal.'];
        }
        $code = (int) ($resp['data']['code'] ?? 0);
        if ($code !== 100) {
            $err = $resp['errors']['message'] ?? $resp['errors']['code'] ?? 'ZarinPal request failed.';
            return ['success' => false, 'error' => is_string($err) ? $err : 'ZarinPal error.'];
        }
        $authority = $resp['data']['authority'] ?? '';
        if ($authority === '') {
            return ['success' => false, 'error' => 'ZarinPal did not return authority.'];
        }
        $this->storeGatewayRef($txId, 'zp:' . $authority);
        $sandbox = ($this->db->getSetting('payment_zarinpal_sandbox') ?? '0') === '1';
        $startBase = $sandbox ? 'https://sandbox.zarinpal.com/pg/StartPay/' : 'https://payment.zarinpal.com/pg/StartPay/';
        return ['success' => true, 'redirect_url' => $startBase . $authority];
    }

    private function zarinpalCallback(array $params): array
    {
        $status = $params['Status'] ?? $params['status'] ?? '';
        $authority = (string) ($params['Authority'] ?? $params['authority'] ?? '');
        if (strtoupper((string) $status) !== 'OK' || $authority === '') {
            return ['success' => false, 'error' => 'Payment cancelled or failed.'];
        }
        $tx = $this->db->fetch(
            "SELECT id, user_id, amount, status FROM transactions WHERE reference = ? AND type = 'deposit' AND status = 'pending'",
            ['zp:' . $authority]
        );
        if (!$tx) {
            $tx = $this->db->fetch(
                "SELECT id, user_id, amount, status FROM transactions WHERE reference LIKE ? AND type = 'deposit' AND status = 'pending' ORDER BY id DESC LIMIT 1",
                ['zp:' . $authority . '%']
            );
        }
        if (!$tx) {
            return ['success' => false, 'error' => 'Deposit not found for this payment.'];
        }
        $merchantId = trim((string) $this->db->getSetting('payment_zarinpal_merchant_id'));
        $rate = (float) ($this->db->getSetting('payment_zarinpal_usd_rate') ?: 600000);
        $irrAmount = (int) max(10000, round((float) $tx['amount'] * $rate));
        $verify = $this->httpJson('POST', $this->zarinpalBase() . '/verify.json', [
            'merchant_id' => $merchantId,
            'amount' => $irrAmount,
            'authority' => $authority,
        ]);
        $code = (int) ($verify['data']['code'] ?? 0);
        if (!in_array($code, [100, 101], true)) {
            $err = $verify['errors']['message'] ?? 'ZarinPal verification failed.';
            return ['success' => false, 'error' => is_string($err) ? $err : 'Verification failed.'];
        }
        $result = $this->creditIfPending((int) $tx['id']);
        return $result['success']
            ? ['success' => true, 'credited' => true, 'message' => 'ZarinPal payment confirmed.']
            : ['success' => false, 'error' => $result['error'] ?? 'Credit failed.'];
    }

    // ─── SmmPayGate (generic REST) ───────────────────────────────────────────

    private function smmPayGateCreate(int $txId, string $orderId, float $amount, string $email): array
    {
        $apiUrl = rtrim(trim((string) $this->db->getSetting('payment_smmpaygate_api_url')), '/');
        $apiKey = trim((string) $this->db->getSetting('payment_smmpaygate_api_key'));
        $merchantId = trim((string) $this->db->getSetting('payment_smmpaygate_merchant_id'));
        $payload = [
            'api_key' => $apiKey,
            'merchant_id' => $merchantId,
            'amount' => round($amount, 2),
            'currency' => 'USD',
            'order_id' => $orderId,
            'callback_url' => PaymentRegistry::webhookUrl(PaymentRegistry::SMMPAYGATE),
            'return_url' => PaymentRegistry::callbackUrl(PaymentRegistry::SMMPAYGATE),
            'email' => $email,
        ];
        $resp = $this->httpJson('POST', $apiUrl . '/payment/create', $payload);
        if ($resp === null) {
            $resp = $this->httpJson('POST', $apiUrl . '/create', $payload);
        }
        if ($resp === null) {
            return ['success' => false, 'error' => 'Could not connect to SmmPayGate. Check API URL.'];
        }
        $payUrl = $resp['payment_url'] ?? $resp['pay_url'] ?? $resp['url'] ?? $resp['data']['payment_url'] ?? null;
        $extId = $resp['transaction_id'] ?? $resp['id'] ?? $orderId;
        if (!$payUrl) {
            $msg = $resp['message'] ?? $resp['error'] ?? 'SmmPayGate did not return payment URL.';
            return ['success' => false, 'error' => is_string($msg) ? $msg : 'SmmPayGate error.'];
        }
        $this->storeGatewayRef($txId, 'spg:' . $extId);
        return ['success' => true, 'redirect_url' => $payUrl];
    }

    private function smmPayGateWebhook(array $params, string $rawBody): array
    {
        if ($params === [] && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }
        $secret = trim((string) $this->db->getSetting('payment_smmpaygate_secret'));
        if ($secret === '') {
            Logger::log('SmmPayGate webhook rejected: secret not configured', 'deposits');
            return ['success' => false, 'error' => 'SmmPayGate secret is not configured.'];
        }
        $sign = (string) ($params['sign'] ?? $params['signature'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '');
        $orderId = (string) ($params['order_id'] ?? '');
        $expected = hash_hmac('sha256', $orderId . ($params['amount'] ?? ''), $secret);
        if ($sign === '' || !hash_equals($expected, $sign)) {
            return ['success' => false, 'error' => 'Invalid SmmPayGate signature.'];
        }
        $status = strtolower((string) ($params['status'] ?? $params['payment_status'] ?? ''));
        if (!in_array($status, ['paid', 'success', 'completed', 'approved'], true)) {
            return ['success' => true, 'message' => 'Status: ' . $status];
        }
        $tx = $this->findPendingByOrderId($orderId);
        if (!$tx) {
            return ['success' => false, 'error' => 'Deposit not found.'];
        }
        $paid = (float) ($params['amount'] ?? 0);
        $result = $this->creditIfPaidMatches((int) $tx['id'], $paid > 0 ? $paid : (float) $tx['amount']);
        return $result['success']
            ? ['success' => true, 'credited' => true, 'message' => 'SmmPayGate payment credited.']
            : ['success' => false, 'error' => $result['error'] ?? 'Credit failed.'];
    }
}
