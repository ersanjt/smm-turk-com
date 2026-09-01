-- Google unique-customer attribution (also auto-created by GoogleAcquisition::ensureSchema)
CREATE TABLE IF NOT EXISTS acquisition_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visitor_id CHAR(32) NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    event ENUM('view','signup','deposit','order') NOT NULL,
    source VARCHAR(64) NOT NULL DEFAULT '',
    medium VARCHAR(64) NOT NULL DEFAULT '',
    campaign VARCHAR(64) DEFAULT NULL,
    gclid VARCHAR(128) DEFAULT NULL,
    landing_path VARCHAR(191) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_acq_visitor_event (visitor_id, event, created_at),
    KEY idx_acq_source_event (source, event, created_at),
    KEY idx_acq_user_event (user_id, event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (`key`, `value`) VALUES
('ga4_measurement_id', ''),
('google_ads_id', ''),
('google_ads_signup_label', ''),
('google_ads_purchase_label', ''),
('google_site_verification', '');
