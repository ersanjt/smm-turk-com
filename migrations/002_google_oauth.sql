-- Google Sign-In: link accounts by Google ID (run once for existing installs)
ALTER TABLE `users` ADD COLUMN `google_id` varchar(64) DEFAULT NULL UNIQUE;
