-- Performance indexes (run once on existing databases)
-- php migrate-performance-indexes.php

ALTER TABLE users ADD INDEX idx_users_api_key (api_key);
ALTER TABLE orders ADD INDEX idx_orders_status_provider (status, provider_order_id);
ALTER TABLE orders ADD INDEX idx_orders_user_status_created (user_id, status, created_at);
ALTER TABLE transactions ADD INDEX idx_tx_type_status (type, status);
ALTER TABLE transactions ADD INDEX idx_tx_user_type_status (user_id, type, status);
ALTER TABLE services ADD INDEX idx_services_status_category (status, category);
