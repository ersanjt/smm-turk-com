<?php
/**
 * Payment methods registry (SMMFA-style add funds).
 */
class PaymentRegistry
{
    public const SMMPAYGATE   = 'smmpaygate';
    public const HELEKET      = 'heleket';
    public const USDT_TRC20   = 'usdt_trc20';
    public const BINANCE_PAY  = 'binance_pay';
    public const ZARINPAL     = 'zarinpal';
    public const CRYPTOCLOUD  = 'cryptocloud';

    /** @return array<string, array{label: string, desc: string, type: string, icon: string, enabled_setting: string, settings: array<string, string>}> */
    public static function definitions(): array
    {
        return [
            self::SMMPAYGATE => [
                'label' => 'SmmPayGate',
                'desc' => 'Own payment gateway',
                'type' => 'redirect',
                'icon' => '💳',
                'enabled_setting' => 'payment_smmpaygate_enabled',
                'settings' => [
                    'payment_smmpaygate_api_url' => 'API URL',
                    'payment_smmpaygate_api_key' => 'API Key',
                    'payment_smmpaygate_merchant_id' => 'Merchant ID',
                    'payment_smmpaygate_secret' => 'Webhook Secret (optional)',
                ],
            ],
            self::HELEKET => [
                'label' => 'Heleket',
                'desc' => 'Crypto — USDT & more',
                'type' => 'hybrid',
                'icon' => '⚡',
                'enabled_setting' => 'payment_heleket_enabled',
                'settings' => [
                    'payment_heleket_merchant_id' => 'Merchant UUID',
                    'payment_heleket_api_key' => 'Payment API Key',
                    'payment_heleket_mode' => 'Mode: panel or redirect',
                    'payment_heleket_currency' => 'Crypto currency (USDT)',
                    'payment_heleket_network' => 'Network (tron, bsc, eth…)',
                ],
            ],
            self::USDT_TRC20 => [
                'label' => 'USDT TRC20',
                'desc' => 'Direct wallet transfer',
                'type' => 'manual',
                'icon' => '₮',
                'enabled_setting' => 'payment_usdt_trc20_enabled',
                'settings' => [
                    'wallet_usdt_trc20' => 'TRC20 wallet address',
                ],
            ],
            self::BINANCE_PAY => [
                'label' => 'Binance Pay',
                'desc' => 'Binance checkout',
                'type' => 'redirect',
                'icon' => '🟡',
                'enabled_setting' => 'payment_binance_pay_enabled',
                'settings' => [
                    'payment_binance_pay_api_key' => 'Certificate SN (API Key)',
                    'payment_binance_pay_secret' => 'Secret Key',
                ],
            ],
            self::ZARINPAL => [
                'label' => 'ZarinPal',
                'desc' => 'Iran card / bank',
                'type' => 'redirect',
                'icon' => '🏦',
                'enabled_setting' => 'payment_zarinpal_enabled',
                'settings' => [
                    'payment_zarinpal_merchant_id' => 'Merchant ID (36 chars)',
                    'payment_zarinpal_usd_rate' => 'USD → IRR rate (e.g. 600000)',
                    'payment_zarinpal_sandbox' => 'Sandbox mode (0/1)',
                ],
            ],
            self::CRYPTOCLOUD => [
                'label' => 'CryptoCloud',
                'desc' => 'Multi-crypto invoices',
                'type' => 'redirect',
                'icon' => '☁️',
                'enabled_setting' => 'payment_cryptocloud_enabled',
                'settings' => [
                    'payment_cryptocloud_shop_id' => 'Shop ID',
                    'payment_cryptocloud_api_key' => 'API Key (Token)',
                ],
            ],
        ];
    }

    public static function label(string $slug): string
    {
        return self::definitions()[$slug]['label'] ?? $slug;
    }

    public static function isEnabled(string $slug): bool
    {
        if (self::isManualWalletSlug($slug)) {
            $db = Database::getInstance();
            return trim((string) $db->getSetting($slug)) !== '';
        }

        $def = self::definitions()[$slug] ?? null;
        if (!$def) {
            return false;
        }
        $db = Database::getInstance();
        if ($slug === self::USDT_TRC20) {
            return trim((string) $db->getSetting('wallet_usdt_trc20')) !== '';
        }
        if (($db->getSetting($def['enabled_setting']) ?? '0') !== '1') {
            return false;
        }
        return self::hasRequiredCredentials($slug);
    }

    public static function isManualWalletSlug(string $slug): bool
    {
        return str_starts_with($slug, 'wallet_') && $slug !== 'wallet_usdt_trc20';
    }

    /** @return array<string, array{label: string, desc: string, type: string, icon: string}> */
    public static function manualWalletMethods(): array
    {
        $db = Database::getInstance();
        $icons = [
            'wallet_btc' => '₿',
            'wallet_eth' => 'Ξ',
            'wallet_usdt_trc20' => '₮',
            'wallet_usdt_erc20' => '₮',
            'wallet_bnb' => '◆',
            'wallet_sol' => '◎',
        ];
        $out = [];
        foreach (DepositAutoConfirm::buildWalletCatalog($db) as $walletKey => $meta) {
            if ($walletKey === 'wallet_usdt_trc20') {
                continue;
            }
            $label = trim(($meta['label'] ?? '') . ' (' . ($meta['network'] ?? '') . ')');
            $out[$walletKey] = [
                'label' => $label,
                'desc' => 'Direct wallet transfer',
                'type' => 'manual',
                'icon' => $icons[$walletKey] ?? '🪙',
            ];
        }
        return $out;
    }

    public static function hasRequiredCredentials(string $slug): bool
    {
        $db = Database::getInstance();
        return match ($slug) {
            self::SMMPAYGATE => trim((string) $db->getSetting('payment_smmpaygate_api_url')) !== ''
                && trim((string) $db->getSetting('payment_smmpaygate_api_key')) !== '',
            self::HELEKET => trim((string) $db->getSetting('payment_heleket_merchant_id')) !== ''
                && trim((string) $db->getSetting('payment_heleket_api_key')) !== '',
            self::USDT_TRC20 => trim((string) $db->getSetting('wallet_usdt_trc20')) !== '',
            self::BINANCE_PAY => trim((string) $db->getSetting('payment_binance_pay_api_key')) !== ''
                && trim((string) $db->getSetting('payment_binance_pay_secret')) !== '',
            self::ZARINPAL => trim((string) $db->getSetting('payment_zarinpal_merchant_id')) !== '',
            self::CRYPTOCLOUD => trim((string) $db->getSetting('payment_cryptocloud_shop_id')) !== ''
                && trim((string) $db->getSetting('payment_cryptocloud_api_key')) !== '',
            default => false,
        };
    }

    /** @return array<string, array> */
    public static function enabledMethods(): array
    {
        $out = [];
        foreach (self::definitions() as $slug => $def) {
            if (self::isEnabled($slug)) {
                $out[$slug] = $def;
            }
        }
        foreach (self::manualWalletMethods() as $slug => $def) {
            if (!isset($out[$slug]) && self::isEnabled($slug)) {
                $out[$slug] = $def;
            }
        }
        return $out;
    }

    public static function orderId(int $transactionId): string
    {
        return 'dep_' . $transactionId;
    }

    public static function depositDescription(float $amount, string $methodSlug): string
    {
        $amountStr = '$' . number_format($amount, 2, '.', '');
        if (self::isManualWalletSlug($methodSlug)) {
            $db = Database::getInstance();
            $catalog = DepositAutoConfirm::buildWalletCatalog($db);
            $meta = $catalog[$methodSlug] ?? null;
            if ($meta) {
                return 'Deposit ' . $amountStr . ' — ' . trim(($meta['label'] ?? '') . ' ' . ($meta['network'] ?? ''));
            }
        }
        return 'Deposit ' . $amountStr . ' — ' . self::label($methodSlug);
    }

    public static function parseMethodFromDescription(?string $description): ?string
    {
        if ($description === null || $description === '') {
            return null;
        }
        foreach (self::definitions() as $slug => $def) {
            if (stripos($description, '— ' . $def['label']) !== false) {
                return $slug;
            }
        }
        $db = Database::getInstance();
        $coinKey = DepositAutoConfirm::parseCoinKey($description, DepositAutoConfirm::buildWalletCatalog($db));
        if ($coinKey !== null) {
            return $coinKey === 'wallet_usdt_trc20' ? self::USDT_TRC20 : $coinKey;
        }
        if (stripos($description, 'USDT TRC20') !== false || stripos($description, 'USDT Tron') !== false) {
            return self::USDT_TRC20;
        }
        if (preg_match('/\(crypto\)$/i', $description)) {
            return self::USDT_TRC20;
        }
        return null;
    }

    public static function walletAddressForMethod(string $slug): string
    {
        if ($slug === self::USDT_TRC20) {
            $slug = 'wallet_usdt_trc20';
        }
        if (!self::isManualWalletSlug($slug) && $slug !== 'wallet_usdt_trc20') {
            return '';
        }
        $db = Database::getInstance();
        return trim((string) $db->getSetting($slug));
    }

    public static function manualPayMeta(string $slug): ?array
    {
        $db = Database::getInstance();
        $walletKey = $slug === self::USDT_TRC20 ? 'wallet_usdt_trc20' : $slug;
        $catalog = DepositAutoConfirm::buildWalletCatalog($db);
        if (!isset($catalog[$walletKey]) && $slug === self::USDT_TRC20) {
            $addr = trim((string) $db->getSetting('wallet_usdt_trc20'));
            if ($addr !== '') {
                return ['label' => 'USDT', 'network' => 'TRC20', 'address' => $addr, 'wallet_key' => 'wallet_usdt_trc20'];
            }
            return null;
        }
        if (!isset($catalog[$walletKey])) {
            return null;
        }
        $meta = $catalog[$walletKey];
        $addr = trim((string) $db->getSetting($walletKey));
        if ($addr === '') {
            return null;
        }
        return [
            'label' => $meta['label'] ?? '',
            'network' => $meta['network'] ?? '',
            'address' => $addr,
            'wallet_key' => $walletKey,
        ];
    }

    public static function coinColor(string $slug): string
    {
        return match ($slug) {
            self::USDT_TRC20, 'wallet_usdt_trc20', 'wallet_usdt_erc20' => '#26a17b',
            'wallet_btc' => '#f7931a',
            'wallet_eth' => '#627eea',
            'wallet_bnb' => '#f3ba2f',
            'wallet_sol' => '#9945ff',
            self::HELEKET => '#e30a17',
            self::BINANCE_PAY => '#f0b90b',
            self::ZARINPAL => '#0d9488',
            self::CRYPTOCLOUD => '#3b82f6',
            self::SMMPAYGATE => '#6366f1',
            default => '#64748b',
        };
    }

    public static function isRecommended(string $slug): bool
    {
        return $slug === self::USDT_TRC20;
    }

    public static function methodBadge(string $slug, array $meta): string
    {
        $type = $meta['type'] ?? '';
        if ($type === 'manual') {
            return 'Direct wallet';
        }
        if ($slug === self::HELEKET) {
            return 'Auto confirm';
        }
        if ($type === 'redirect') {
            return 'Redirect checkout';
        }
        return 'Payment';
    }

    /** @param array<string, array> $methods */
    public static function groupedMethods(array $methods): array
    {
        $crypto = [];
        $gateways = [];
        foreach ($methods as $slug => $meta) {
            if (($meta['type'] ?? '') === 'manual' || $slug === self::USDT_TRC20 || $slug === self::HELEKET) {
                $crypto[$slug] = $meta;
            } else {
                $gateways[$slug] = $meta;
            }
        }
        if (isset($crypto[self::USDT_TRC20])) {
            $trc = [self::USDT_TRC20 => $crypto[self::USDT_TRC20]];
            unset($crypto[self::USDT_TRC20]);
            $crypto = $trc + $crypto;
        }
        return ['crypto' => $crypto, 'gateways' => $gateways];
    }

    public static function callbackUrl(string $gateway): string
    {
        return url('payment-callback.php') . '?gateway=' . rawurlencode($gateway);
    }

    public static function webhookUrl(string $gateway): string
    {
        return url('payment-webhook.php') . '?gateway=' . rawurlencode($gateway);
    }

    public static function coinKeyForManual(string $slug): ?string
    {
        return $slug === self::USDT_TRC20 ? 'wallet_usdt_trc20' : null;
    }

    /** Parse Heleket reference: hk:uuid|address */
    public static function parseHeleketRef(?string $reference): ?array
    {
        if ($reference === null || !str_starts_with($reference, 'hk:')) {
            return null;
        }
        $parts = explode('|', substr($reference, 3), 2);
        return [
            'uuid' => $parts[0] ?? '',
            'address' => $parts[1] ?? '',
        ];
    }

    public static function heleketNetworkLabel(string $network): string
    {
        return match (strtolower($network)) {
            'tron', 'trc20' => 'TRC20 (Tron)',
            'bsc', 'bep20' => 'BEP-20 (BSC)',
            'eth', 'erc20' => 'ERC20 (Ethereum)',
            'btc' => 'Bitcoin',
            default => strtoupper($network),
        };
    }
}
