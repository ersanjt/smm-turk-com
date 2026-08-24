-- SMM Turk Panel — Full optimized schema (cPanel / phpMyAdmin)
-- Select your database first, then Import this file.
-- Fresh install only; existing DB → use: php migrate-db.php

-- ─── Users ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `balance` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `spent` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `status` enum('active','banned','pending') NOT NULL DEFAULT 'active',
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `api_key` varchar(64) DEFAULT NULL,
  `api_key_created_at` datetime DEFAULT NULL,
  `referral_code` varchar(10) DEFAULT NULL,
  `referred_by` int unsigned DEFAULT NULL,
  `referral_earnings` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `total_referral_earnings` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `email_verification_token` varchar(64) DEFAULT NULL,
  `email_verification_expires` datetime DEFAULT NULL,
  `password_reset_token` varchar(64) DEFAULT NULL,
  `password_reset_expires` datetime DEFAULT NULL,
  `google_id` varchar(64) DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_secret` varchar(64) DEFAULT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `avatar` varchar(255) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  UNIQUE KEY `uk_users_email` (`email`),
  UNIQUE KEY `uk_users_google_id` (`google_id`),
  KEY `idx_users_api_key` (`api_key`),
  KEY `idx_users_referral_code` (`referral_code`),
  KEY `idx_users_referred_by` (`referred_by`),
  KEY `idx_users_status_role` (`status`, `role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Services cache (SmmFollows API) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `services` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `name` text NOT NULL,
  `type` varchar(100) DEFAULT 'Default',
  `category` varchar(255) DEFAULT NULL,
  `rate` decimal(10,5) NOT NULL,
  `min` int NOT NULL,
  `max` int NOT NULL,
  `refill` tinyint(1) NOT NULL DEFAULT 0,
  `cancel` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `markup` decimal(5,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_services_service_id` (`service_id`),
  KEY `idx_services_status_category` (`status`, `category`),
  KEY `idx_services_status_service_id` (`status`, `service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Orders ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `provider_order_id` int DEFAULT NULL,
  `service_id` int NOT NULL,
  `service_name` text,
  `link` text NOT NULL,
  `quantity` int NOT NULL,
  `charge` decimal(12,4) NOT NULL,
  `start_count` int NOT NULL DEFAULT 0,
  `remains` int NOT NULL DEFAULT 0,
  `status` enum('Pending','Processing','In progress','Completed','Partial','Cancelled','Refunded') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orders_user_id` (`user_id`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_status_provider` (`status`, `provider_order_id`),
  KEY `idx_orders_user_status_created` (`user_id`, `status`, `created_at`),
  KEY `idx_orders_provider_order_id` (`provider_order_id`),
  KEY `idx_orders_user_created` (`user_id`, `created_at`),
  KEY `idx_orders_updated_at` (`updated_at`),
  KEY `idx_orders_service_id` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Transactions ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `type` enum('deposit','order','refund','referral','admin') NOT NULL,
  `amount` decimal(12,4) NOT NULL,
  `balance_before` decimal(12,4) NOT NULL,
  `balance_after` decimal(12,4) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `status` enum('pending','completed','failed') NOT NULL DEFAULT 'completed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tx_user_id` (`user_id`),
  KEY `idx_tx_type_status` (`type`, `status`),
  KEY `idx_tx_user_type_status` (`user_id`, `type`, `status`),
  KEY `idx_tx_user_created` (`user_id`, `created_at`),
  KEY `idx_tx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Support tickets ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tickets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `subject` varchar(255) NOT NULL,
  `category` varchar(64) NOT NULL DEFAULT '',
  `subcategory` varchar(64) NOT NULL DEFAULT '',
  `order_id` varchar(500) NOT NULL DEFAULT '',
  `status` enum('open','answered','closed') NOT NULL DEFAULT 'open',
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tickets_user_id` (`user_id`),
  KEY `idx_tickets_user_updated` (`user_id`, `updated_at`),
  KEY `idx_tickets_user_status` (`user_id`, `status`),
  KEY `idx_tickets_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_replies` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `message` text NOT NULL,
  `is_staff` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_replies_ticket_created` (`ticket_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_attachments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int unsigned NOT NULL,
  `reply_id` int unsigned DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_attachments_ticket` (`ticket_id`),
  KEY `idx_ticket_attachments_reply` (`reply_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Affiliates ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `referral_visits` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `referrer_id` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_referral_visits_referrer` (`referrer_id`),
  KEY `idx_referral_visits_referrer_created` (`referrer_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Child panels ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `child_panels` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  `currency` varchar(16) NOT NULL DEFAULT 'USD',
  `admin_username` varchar(64) NOT NULL,
  `admin_password` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_child_panels_user` (`user_id`),
  KEY `idx_child_panels_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Blog ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `blog_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(128) NOT NULL,
  `name` varchar(255) NOT NULL,
  `meta_description` varchar(512) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_blog_categories_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blog_tags` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(128) NOT NULL,
  `name` varchar(128) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_blog_tags_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blog_articles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned DEFAULT NULL,
  `author_id` int unsigned DEFAULT NULL,
  `slug` varchar(255) NOT NULL,
  `title` varchar(512) NOT NULL,
  `meta_description` varchar(512) DEFAULT NULL,
  `meta_keywords` varchar(512) DEFAULT NULL,
  `excerpt` text,
  `body` longtext NOT NULL,
  `featured_image` varchar(512) DEFAULT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'published',
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `reading_time_min` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_blog_articles_slug` (`slug`),
  KEY `idx_blog_status_published` (`status`, `published_at`),
  KEY `idx_blog_category` (`category_id`),
  KEY `idx_blog_author` (`author_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blog_article_tags` (
  `article_id` int unsigned NOT NULL,
  `tag_id` int unsigned NOT NULL,
  PRIMARY KEY (`article_id`, `tag_id`),
  KEY `idx_blog_article_tags_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Settings ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(100) NOT NULL,
  `value` text,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`) VALUES
('site_name', 'SMM Turk'),
('site_url', 'https://smm-turk.com'),
('api_url', 'https://smmfollows.com/api/v2'),
('api_key', ''),
('api_url_smmfa', 'https://smmfa.com/api/v2'),
('api_key_smmfa', ''),
('provider_smmfa_enabled', '0'),
('currency', 'USD'),
('currency_symbol', '$'),
('markup_percent', '10'),
('min_deposit', '10'),
('referral_commission', '2'),
('referral_min_payout', '10'),
('maintenance_mode', '0'),
('registration_enabled', '1'),
('email_verification_required', '1'),
('smtp_host', 'smm-turk.com'),
('smtp_port', '465'),
('smtp_user', 'noreply@smm-turk.com'),
('smtp_pass', ''),
('smtp_from', 'noreply@smm-turk.com'),
('contact_email', 'contact@smm-turk.com'),
('mail_mode', 'auto'),
('smtp_encryption', 'auto'),
('wallet_btc', ''),
('wallet_eth', ''),
('wallet_usdt_trc20', ''),
('wallet_usdt_erc20', ''),
('wallet_bnb', ''),
('wallet_sol', '')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- Default admin — change password immediately after first login
INSERT INTO `users` (`username`, `email`, `password`, `role`, `status`, `balance`, `must_change_password`) VALUES
('admin', 'admin@smm-turk.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active', 0.0000, 1)
ON DUPLICATE KEY UPDATE `role` = 'admin';
