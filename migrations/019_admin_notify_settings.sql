-- Admin email notifications (signup, orders, deposits)
INSERT INTO `settings` (`key`, `value`) VALUES
('admin_notify_email', ''),
('admin_notify_signup', '1'),
('admin_notify_orders', '1'),
('admin_notify_deposits', '1')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
