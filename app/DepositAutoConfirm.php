<?php
/**
 * Auto-confirm pending crypto deposits using on-chain verification.
 */
class DepositAutoConfirm
{
    private Database $db;
    private CryptoVerifier $verifier;
    private DepositManager $deposits;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->verifier = new CryptoVerifier();
        $this->deposits = new DepositManager();
    }

    /** Wallet catalog key → DB setting key */
    public static function walletSettingKey(string $coinKey): string
    {
        return $coinKey;
    }

    /**
     * Try to verify and credit one pending deposit.
     * @return array{processed:bool,approved:bool,status:string,message:string}
     */
    public function processTransaction(array $tx, array $walletCatalog): array
    {
        $reference = trim((string) ($tx['reference'] ?? ''));
        if ($reference === '') {
            return ['processed' => false, 'approved' => false, 'status' => 'waiting_tx', 'message' => 'TxHash not submitted yet.'];
        }

        if (!$this->verifier->isAutoConfirmEnabled()) {
            return ['processed' => true, 'approved' => false, 'status' => 'manual', 'message' => 'Auto-confirm disabled — admin approval required.'];
        }

        $coinKey = self::parseCoinKey((string) ($tx['description'] ?? ''), $walletCatalog);
        if ($coinKey === null || !isset($walletCatalog[$coinKey])) {
            return ['processed' => true, 'approved' => false, 'status' => 'manual', 'message' => 'Could not detect payment method.'];
        }

        $walletAddress = trim((string) ($this->db->getSetting($coinKey) ?? ''));
        if ($walletAddress === '') {
            return ['processed' => true, 'approved' => false, 'status' => 'manual', 'message' => 'Wallet address not configured.'];
        }

        $verify = $this->verifier->verify(
            $coinKey,
            $reference,
            $walletAddress,
            (float) $tx['amount']
        );

        if ($verify['status'] === 'confirmed' && $verify['ok']) {
            $result = $this->deposits->approvePendingDeposit((int) $tx['id']);
            if ($result['success']) {
                Logger::log("Auto-approved deposit #{$tx['id']} user#{$tx['user_id']}", 'deposits');
                return ['processed' => true, 'approved' => true, 'status' => 'confirmed', 'message' => 'Payment confirmed — balance credited.'];
            }
            return ['processed' => true, 'approved' => false, 'status' => 'error', 'message' => $result['error'] ?? 'Credit failed.'];
        }

        return [
            'processed' => true,
            'approved' => false,
            'status' => $verify['status'],
            'message' => $verify['message'],
        ];
    }

    /** @return int Number of deposits auto-approved */
    public function processAllPending(int $limit = 30): int
    {
        if (!$this->verifier->isAutoConfirmEnabled()) {
            return 0;
        }

        $walletCatalog = self::buildWalletCatalog($this->db);
        $rows = $this->db->fetchAll(
            "SELECT id, user_id, amount, description, reference, status, created_at
             FROM transactions
             WHERE type = 'deposit' AND status = 'pending' AND reference != ''
             ORDER BY id ASC
             LIMIT ?",
            [$limit]
        );

        $approved = 0;
        foreach ($rows as $tx) {
            $out = $this->processTransaction($tx, $walletCatalog);
            if ($out['approved']) {
                $approved++;
            }
        }
        return $approved;
    }

    public static function parseCoinKey(string $description, array $walletCatalog): ?string
    {
        if (stripos($description, 'USDT TRC20') !== false) {
            return isset($walletCatalog['wallet_usdt_trc20']) ? 'wallet_usdt_trc20' : null;
        }
        if (preg_match('/\(crypto\)$/i', $description)) {
            return null;
        }
        foreach ($walletCatalog as $key => $w) {
            $needle = ($w['label'] ?? '') . ' ' . ($w['network'] ?? '');
            if ($needle !== ' ' && stripos($description, $needle) !== false) {
                return $key;
            }
        }
        return null;
    }

    /** @return array<string,array{label:string,network:string}> */
    public static function buildWalletCatalog(Database $db): array
    {
        $defs = [
            'wallet_usdt_trc20' => ['label' => 'USDT', 'network' => 'TRC20'],
            'wallet_usdt_erc20' => ['label' => 'USDT', 'network' => 'ERC20'],
            'wallet_eth' => ['label' => 'ETH', 'network' => 'Ethereum'],
            'wallet_btc' => ['label' => 'BTC', 'network' => 'Bitcoin'],
            'wallet_bnb' => ['label' => 'BNB', 'network' => 'BEP20'],
            'wallet_sol' => ['label' => 'SOL', 'network' => 'Solana'],
        ];
        $out = [];
        foreach ($defs as $key => $meta) {
            $addr = $db->getSetting($key);
            if ($addr !== null && trim($addr) !== '') {
                $out[$key] = $meta;
            }
        }
        return $out;
    }
}
