CREATE TABLE IF NOT EXISTS system_maintenance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    tenant_id INT NULL,
    action VARCHAR(80) NOT NULL,
    command VARCHAR(255) NOT NULL,
    output LONGTEXT NULL,
    status VARCHAR(30) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_system_maintenance_action (action),
    KEY idx_system_maintenance_user (user_id),
    KEY idx_system_maintenance_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
