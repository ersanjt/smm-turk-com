-- Multi-provider: SmmFollows + SMMFA (smmfa.com)
ALTER TABLE services ADD COLUMN provider VARCHAR(32) NOT NULL DEFAULT 'smmfollows';
ALTER TABLE services ADD COLUMN provider_service_id INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE orders ADD COLUMN provider VARCHAR(32) NOT NULL DEFAULT 'smmfollows';

UPDATE services SET provider = 'smmfollows', provider_service_id = service_id WHERE provider_service_id = 0;

INSERT INTO `settings` (`key`, `value`) VALUES
('api_url_smmfa', 'https://smmfa.com/api/v2'),
('api_key_smmfa', ''),
('provider_smmfa_enabled', '0')
ON DUPLICATE KEY UPDATE `key` = `key`;
