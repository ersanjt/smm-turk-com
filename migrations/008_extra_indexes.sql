-- Extra performance indexes (idempotent via migrate-db.php)
-- Complements 007_performance_indexes.sql

ALTER TABLE users ADD INDEX idx_users_referred_by (referred_by);
ALTER TABLE users ADD INDEX idx_users_referral_code (referral_code);
ALTER TABLE users ADD INDEX idx_users_status_role (status, role);
ALTER TABLE users ADD INDEX idx_users_google_id (google_id);

ALTER TABLE orders ADD INDEX idx_orders_provider_order_id (provider_order_id);
ALTER TABLE orders ADD INDEX idx_orders_user_created (user_id, created_at);
ALTER TABLE orders ADD INDEX idx_orders_updated_at (updated_at);
ALTER TABLE orders ADD INDEX idx_orders_service_id (service_id);

ALTER TABLE transactions ADD INDEX idx_tx_user_created (user_id, created_at);
ALTER TABLE transactions ADD INDEX idx_tx_created (created_at);

ALTER TABLE tickets ADD INDEX idx_tickets_status (status);
ALTER TABLE tickets ADD INDEX idx_tickets_user_id (user_id);

ALTER TABLE ticket_replies ADD INDEX idx_ticket_replies_ticket_created (ticket_id, created_at);

ALTER TABLE ticket_attachments ADD INDEX idx_ticket_attachments_reply (reply_id);

ALTER TABLE services ADD INDEX idx_services_status_service_id (status, service_id);

ALTER TABLE referral_visits ADD INDEX idx_referral_visits_referrer_created (referrer_id, created_at);

ALTER TABLE child_panels ADD INDEX idx_child_panels_user_status (user_id, status);
