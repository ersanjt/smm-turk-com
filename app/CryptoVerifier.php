<?php
/**
 * On-chain deposit verification via public blockchain APIs.
 * Trust Wallet / browser wallets are receive-only here — addresses go in Admin Settings.
 */
class CryptoVerifier
{
    private Database $db;
    private int $minConfirmations;
    private float $amountTolerance;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->minConfirmations = max(1, (int) ($this->db->getSetting('deposit_min_confirmations') ?: 1));
        $this->amountTolerance = max(0.01, min(0.15, (float) ($this->db->getSetting('deposit_amount_tolerance') ?: 0.03)));
    }

    /**
     * @return array{ok:bool,status:string,confirmations:int,amount_usd:?float,message:string}
     */
    public function verify(string $coinKey, string $txHash, string $expectedWallet, float $expectedUsd): array
    {
        $txHash = trim($txHash);
        $expectedWallet = trim($expectedWallet);
        if ($txHash === '' || $expectedWallet === '') {
            return $this->result(false, 'invalid', 0, null, 'Missing transaction hash or wallet address.');
        }

        return match ($coinKey) {
            'wallet_usdt_trc20' => $this->verifyTronUsdt($txHash, $expectedWallet, $expectedUsd),
            'wallet_usdt_erc20' => $this->verifyEvmToken($txHash, $expectedWallet, $expectedUsd, 'ethereum', 'USDT'),
            'wallet_eth'          => $this->verifyEvmNative($txHash, $expectedWallet, $expectedUsd, 'ethereum'),
            'wallet_bnb'          => $this->verifyEvmNative($txHash, $expectedWallet, $expectedUsd, 'bsc'),
            'wallet_btc'          => $this->verifyBtc($txHash, $expectedWallet, $expectedUsd),
            'wallet_sol'          => $this->verifySol($txHash, $expectedWallet, $expectedUsd),
            default               => $this->result(false, 'unsupported', 0, null, 'Unsupported payment method for auto-verify.'),
        };
    }

    public function isAutoConfirmEnabled(): bool
    {
        return ($this->db->getSetting('deposit_auto_confirm') ?? '1') !== '0';
    }

    /** @return array{ok:bool,status:string,confirmations:int,amount_usd:?float,message:string} */
    private function result(bool $ok, string $status, int $confirmations, ?float $amountUsd, string $message): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'confirmations' => $confirmations,
            'amount_usd' => $amountUsd,
            'message' => $message,
        ];
    }

    private function amountMatches(float $expectedUsd, float $receivedUsd): bool
    {
        if ($expectedUsd <= 0 || $receivedUsd <= 0) {
            return false;
        }
        $min = $expectedUsd * (1 - $this->amountTolerance);
        $max = $expectedUsd * (1 + $this->amountTolerance);
        return $receivedUsd >= $min && $receivedUsd <= $max;
    }

    private function httpGet(string $url, array $headers = [], int $timeout = 20): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'SMM-Turk-DepositVerifier/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            Logger::log("CryptoVerifier HTTP $code for $url", 'deposits');
            return null;
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    private function httpPostJson(string $url, array $payload, array $headers = [], int $timeout = 20): ?array
    {
        $ch = curl_init($url);
        $jsonBody = json_encode($payload);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_USERAGENT => 'SMM-Turk-DepositVerifier/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            return null;
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    private function trongridHeaders(): array
    {
        $key = trim((string) ($this->db->getSetting('api_trongrid') ?? ''));
        return $key !== '' ? ["TRON-PRO-API-KEY: $key"] : [];
    }

    private function verifyTronUsdt(string $txHash, string $wallet, float $expectedUsd): array
    {
        $data = $this->httpGet(
            'https://api.trongrid.io/v1/transactions/' . rawurlencode($txHash) . '/events',
            $this->trongridHeaders()
        );
        if ($data === null) {
            return $this->result(false, 'pending', 0, null, 'Could not reach Tron network. Will retry automatically.');
        }

        $events = $data['data'] ?? [];
        if (!is_array($events) || $events === []) {
            return $this->result(false, 'pending', 0, null, 'Transaction not found yet on Tron. Wait a minute and refresh.');
        }

        $walletNorm = strtolower($wallet);
        foreach ($events as $event) {
            $result = $event['result'] ?? [];
            $to = strtolower((string) ($result['to'] ?? $result['recipient'] ?? ''));
            if ($to === '' || $to !== $walletNorm) {
                continue;
            }
            $value = (float) ($result['value'] ?? 0);
            $decimals = (int) ($result['decimals'] ?? 6);
            $amount = $value / (10 ** max(0, $decimals));
            if (!$this->amountMatches($expectedUsd, $amount)) {
                return $this->result(false, 'amount_mismatch', 1, $amount, 'Payment found but amount does not match. Contact support with your TxHash.');
            }
            return $this->result(true, 'confirmed', max(1, $this->minConfirmations), $amount, 'USDT TRC20 payment confirmed on Tron.');
        }

        return $this->result(false, 'failed', 0, null, 'Transaction found but not sent to our USDT TRC20 wallet.');
    }

    private function etherscanBase(string $network): string
    {
        return $network === 'bsc' ? 'https://api.bscscan.com/api' : 'https://api.etherscan.io/api';
    }

    private function etherscanKey(string $network): string
    {
        $key = $network === 'bsc'
            ? trim((string) ($this->db->getSetting('api_bscscan') ?? ''))
            : trim((string) ($this->db->getSetting('api_etherscan') ?? ''));
        return $key;
    }

    private function verifyEvmNative(string $txHash, string $wallet, float $expectedUsd, string $network): array
    {
        $apiKey = $this->etherscanKey($network);
        if ($apiKey === '') {
            return $this->result(false, 'pending', 0, null, 'EVM auto-verify needs an API key in Admin → Settings. Admin can approve manually.');
        }

        $base = $this->etherscanBase($network);
        $tx = $this->httpGet($base . '?module=proxy&action=eth_getTransactionByHash&txhash=' . rawurlencode($txHash) . '&apikey=' . rawurlencode($apiKey));
        $receipt = $this->httpGet($base . '?module=proxy&action=eth_getTransactionReceipt&txhash=' . rawurlencode($txHash) . '&apikey=' . rawurlencode($apiKey));

        $txResult = $tx['result'] ?? null;
        if (!is_array($txResult) || empty($txResult['to'])) {
            return $this->result(false, 'pending', 0, null, 'Transaction not found on chain yet.');
        }

        if (strtolower($txResult['to']) !== strtolower($wallet)) {
            return $this->result(false, 'failed', 0, null, 'Transaction was not sent to our wallet address.');
        }

        $status = $receipt['result']['status'] ?? null;
        if ($status !== '0x1') {
            return $this->result(false, 'pending', 0, null, 'Waiting for transaction confirmation.');
        }

        $wei = hexdec(ltrim((string) ($txResult['value'] ?? '0x0'), '0x') ?: '0');
        $ethAmount = $wei / 1e18;
        $price = $this->getCryptoUsdPrice($network === 'bsc' ? 'BNB' : 'ETH');
        $amountUsd = $price > 0 ? $ethAmount * $price : 0.0;

        if ($amountUsd > 0 && !$this->amountMatches($expectedUsd, $amountUsd)) {
            return $this->result(false, 'amount_mismatch', 1, $amountUsd, 'Payment found but USD value is outside tolerance. Contact support.');
        }

        return $this->result(true, 'confirmed', 1, $amountUsd > 0 ? $amountUsd : $expectedUsd, 'Native transfer confirmed.');
    }

    private function verifyEvmToken(string $txHash, string $wallet, float $expectedUsd, string $network, string $symbol): array
    {
        $apiKey = $this->etherscanKey($network);
        if ($apiKey === '') {
            return $this->result(false, 'pending', 0, null, 'Token auto-verify needs Etherscan API key in Admin Settings.');
        }

        $base = $this->etherscanBase($network);
        $receipt = $this->httpGet($base . '?module=proxy&action=eth_getTransactionReceipt&txhash=' . rawurlencode($txHash) . '&apikey=' . rawurlencode($apiKey));
        $logs = $receipt['result']['logs'] ?? [];
        if (!is_array($logs) || $logs === []) {
            return $this->result(false, 'pending', 0, null, 'Token transfer not confirmed yet.');
        }

        $walletNorm = strtolower($wallet);
        foreach ($logs as $log) {
            $topics = $log['topics'] ?? [];
            if (count($topics) < 3) {
                continue;
            }
            // Transfer(address,address,uint256) — topic[2] is to address
            $to = '0x' . substr($topics[2], -40);
            if (strtolower($to) !== $walletNorm) {
                continue;
            }
            $raw = hexdec(ltrim((string) ($log['data'] ?? '0x0'), '0x') ?: '0');
            $amount = $raw / 1e6; // USDT 6 decimals
            if (!$this->amountMatches($expectedUsd, $amount)) {
                return $this->result(false, 'amount_mismatch', 1, $amount, 'USDT amount does not match deposit request.');
            }
            return $this->result(true, 'confirmed', 1, $amount, $symbol . ' token transfer confirmed.');
        }

        return $this->result(false, 'failed', 0, null, 'No USDT transfer to our wallet in this transaction.');
    }

    private function verifyBtc(string $txHash, string $wallet, float $expectedUsd): array
    {
        $data = $this->httpGet('https://blockstream.info/api/tx/' . rawurlencode($txHash));
        if ($data === null) {
            return $this->result(false, 'pending', 0, null, 'BTC transaction not found yet.');
        }

        $status = $data['status'] ?? [];
        $confirmed = !empty($status['confirmed']);
        $confirmations = $confirmed ? max(1, (int) ($status['block_height'] ?? 1)) : 0;

        $outputs = $data['vout'] ?? [];
        $receivedBtc = 0.0;
        foreach ($outputs as $out) {
            $addr = $out['scriptpubkey_address'] ?? '';
            if (strcasecmp($addr, $wallet) === 0) {
                $receivedBtc += ((int) ($out['value'] ?? 0)) / 1e8;
            }
        }

        if ($receivedBtc <= 0) {
            return $this->result(false, 'failed', 0, null, 'No BTC output to our wallet in this transaction.');
        }

        if (!$confirmed || $confirmations < $this->minConfirmations) {
            return $this->result(false, 'pending', $confirmations, null, 'BTC payment seen — waiting for confirmations.');
        }

        $price = $this->getCryptoUsdPrice('BTC');
        $amountUsd = $price > 0 ? $receivedBtc * $price : 0.0;
        if ($amountUsd > 0 && !$this->amountMatches($expectedUsd, $amountUsd)) {
            return $this->result(false, 'amount_mismatch', $confirmations, $amountUsd, 'BTC amount outside tolerance.');
        }

        return $this->result(true, 'confirmed', $confirmations, $amountUsd > 0 ? $amountUsd : $expectedUsd, 'BTC payment confirmed.');
    }

    private function verifySol(string $txHash, string $wallet, float $expectedUsd): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'getTransaction',
            'params' => [$txHash, ['encoding' => 'json', 'maxSupportedTransactionVersion' => 0]],
        ];
        $data = $this->httpPostJson('https://api.mainnet-beta.solana.com', $payload);
        $result = $data['result'] ?? null;
        if (!is_array($result)) {
            return $this->result(false, 'pending', 0, null, 'SOL transaction not found yet.');
        }

        if (empty($result['meta']['err']) === false && $result['meta']['err'] !== null) {
            return $this->result(false, 'failed', 0, null, 'SOL transaction failed on-chain.');
        }

        $pre = $result['meta']['preBalances'] ?? [];
        $post = $result['meta']['postBalances'] ?? [];
        $accounts = $result['transaction']['message']['accountKeys'] ?? [];
        $receivedSol = 0.0;
        foreach ($accounts as $i => $acc) {
            $pk = is_array($acc) ? ($acc['pubkey'] ?? '') : (string) $acc;
            if ($pk !== $wallet) {
                continue;
            }
            $before = (int) ($pre[$i] ?? 0);
            $after = (int) ($post[$i] ?? 0);
            if ($after > $before) {
                $receivedSol += ($after - $before) / 1e9;
            }
        }

        if ($receivedSol <= 0) {
            return $this->result(false, 'failed', 0, null, 'No SOL received at our wallet in this transaction.');
        }

        $price = $this->getCryptoUsdPrice('SOL');
        $amountUsd = $price > 0 ? $receivedSol * $price : 0.0;
        if ($amountUsd > 0 && !$this->amountMatches($expectedUsd, $amountUsd)) {
            return $this->result(false, 'amount_mismatch', 1, $amountUsd, 'SOL amount outside tolerance.');
        }

        return $this->result(true, 'confirmed', 1, $amountUsd > 0 ? $amountUsd : $expectedUsd, 'SOL payment confirmed.');
    }

    private function getCryptoUsdPrice(string $symbol): float
    {
        static $cache = [];
        if (isset($cache[$symbol])) {
            return $cache[$symbol];
        }
        $map = ['BTC' => 'bitcoin', 'ETH' => 'ethereum', 'BNB' => 'binancecoin', 'SOL' => 'solana'];
        $id = $map[$symbol] ?? null;
        if ($id === null) {
            return 0.0;
        }
        $data = $this->httpGet('https://api.coingecko.com/api/v3/simple/price?ids=' . $id . '&vs_currencies=usd');
        $price = (float) ($data[$id]['usd'] ?? 0);
        $cache[$symbol] = $price;
        return $price;
    }
}
