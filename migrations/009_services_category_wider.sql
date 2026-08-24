-- Widen services.category/type for long provider labels (SmmFollows API)
ALTER TABLE services MODIFY COLUMN category VARCHAR(255) DEFAULT NULL;
ALTER TABLE services MODIFY COLUMN type VARCHAR(100) DEFAULT 'Default';
