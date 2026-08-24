-- Password reset (forgot password flow)
-- Run once: import this file in phpMyAdmin or: mysql -u user -p dbname < migrations/003_password_reset.sql

ALTER TABLE `users` ADD COLUMN `password_reset_token` VARCHAR(64) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `password_reset_expires` DATETIME DEFAULT NULL;
