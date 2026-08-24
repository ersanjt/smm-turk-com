ALTER TABLE tickets ADD INDEX idx_tickets_user_updated (user_id, updated_at);
ALTER TABLE tickets ADD INDEX idx_tickets_user_status (user_id, status);
