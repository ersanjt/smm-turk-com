-- Heleket: panel mode (address+QR) and network settings
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('payment_heleket_mode', 'panel'),
('payment_heleket_currency', 'USDT'),
('payment_heleket_network', 'bsc');
