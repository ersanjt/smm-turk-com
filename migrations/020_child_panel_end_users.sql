-- End-users who register on child panel sites (visible to parent admin + panel owner)
CREATE TABLE IF NOT EXISTS child_panel_end_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  child_panel_id INT UNSIGNED NOT NULL,
  child_domain VARCHAR(255) NOT NULL,
  child_local_user_id INT UNSIGNED NOT NULL,
  username VARCHAR(64) NOT NULL,
  email VARCHAR(100) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  registered_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_panel_local_user (child_panel_id, child_local_user_id),
  KEY idx_child_panel (child_panel_id),
  KEY idx_email (email),
  KEY idx_registered (registered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
