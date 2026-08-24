-- Add email verification columns (run once for existing installs; new installs use install-cpanel.sql)
-- If you get "duplicate column" error, columns already exist.
ALTER TABLE `users` ADD COLUMN `email_verification_token` varchar(64) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `email_verification_expires` datetime DEFAULT NULL;
