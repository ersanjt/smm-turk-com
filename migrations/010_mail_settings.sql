-- Default mail settings (idempotent — only inserts missing keys)
INSERT INTO `settings` (`key`, `value`) VALUES
('mail_mode', 'auto'),
('smtp_encryption', 'auto'),
('smtp_host', 'smm-turk.com'),
('smtp_port', '465'),
('smtp_user', 'noreply@smm-turk.com'),
('smtp_from', 'noreply@smm-turk.com'),
('contact_email', 'contact@smm-turk.com')
ON DUPLICATE KEY UPDATE `key` = `key`;
