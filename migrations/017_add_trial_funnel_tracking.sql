ALTER TABLE users ADD COLUMN trial_funnel_step INT DEFAULT 0;
ALTER TABLE users ADD COLUMN last_funnel_message_at DATETIME NULL;
