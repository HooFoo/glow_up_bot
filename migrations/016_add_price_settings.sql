-- Migration: Add price settings
-- 016_add_price_settings.sql

INSERT IGNORE INTO settings (key_name, value_text) VALUES 
('price_subscription', '1990'),
('price_course', '10000');
