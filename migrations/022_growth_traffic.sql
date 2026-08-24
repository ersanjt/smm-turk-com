-- Traffic & conversion settings
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('signup_welcome_credit', '0.50'),
('growth_promo_bar_enabled', '1'),
('growth_promo_bar_text', 'Sign up free — get $0.50 balance + 10% first deposit bonus!'),
('growth_promo_bar_cta_label', 'Create account →'),
('growth_promo_bar_cta_url', '/login.php?mode=register'),
('growth_stats_boost_orders', '50000'),
('growth_stats_boost_users', '1200');
