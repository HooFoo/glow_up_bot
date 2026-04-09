-- Migration: Create settings table
-- Description: Stores application-wide settings like logging levels

CREATE TABLE IF NOT EXISTS settings (
    key_name VARCHAR(100) PRIMARY KEY,
    value_text TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial logging settings
INSERT IGNORE INTO settings (key_name, value_text) VALUES 
('log_info_enabled', '0'),
('log_debug_enabled', '0');
