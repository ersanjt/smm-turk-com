-- Revenue growth: coupons, featured services, default promo settings
CREATE TABLE IF NOT EXISTS coupons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    type ENUM('order_percent','order_fixed','deposit_percent','deposit_fixed') NOT NULL DEFAULT 'order_percent',
    value DECIMAL(10,4) NOT NULL DEFAULT 0,
    min_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_uses INT UNSIGNED NOT NULL DEFAULT 0,
    uses_count INT UNSIGNED NOT NULL DEFAULT 0,
    per_user_limit INT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_coupons_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coupon_uses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coupon_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    context ENUM('order','deposit') NOT NULL,
    reference_id INT UNSIGNED DEFAULT NULL,
    discount_amount DECIMAL(12,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_coupon_user (coupon_id, user_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (`key`, `value`) VALUES
('deposit_bonus_percent', '10'),
('deposit_bonus_first_only', '1'),
('revenue_vip_silver_spent', '100'),
('revenue_vip_silver_discount', '2'),
('revenue_vip_gold_spent', '500'),
('revenue_vip_gold_discount', '5'),
('revenue_vip_platinum_spent', '2000'),
('revenue_vip_platinum_discount', '10'),
('dashboard_promo_title', '🎁 Crypto deposits — fast balance credit'),
('dashboard_promo_text', 'Add funds via BTC, ETH, USDT and start ordering in minutes. First deposit bonus available!'),
('dashboard_promo_cta_label', 'Add Funds →'),
('dashboard_promo_cta_url', '/add-funds.php'),
('revenue_low_balance_threshold', '5'),
('revenue_winback_days', '30'),
('referral_min_payout', '10');

INSERT IGNORE INTO coupons (code, type, value, min_amount, max_uses, per_user_limit, active, note) VALUES
('WELCOME10', 'order_percent', 10, 5, 0, 1, 1, '10% off first orders $5+'),
('DEPOSIT10', 'deposit_percent', 10, 20, 0, 1, 1, '10% bonus on deposits $20+');
