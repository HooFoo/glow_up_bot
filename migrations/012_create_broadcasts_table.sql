-- Migration: Create Broadcasts table and add last_message_at to users
-- Created At: 2026-04-13

-- Add last_message_at to users for filtering
ALTER TABLE `users` ADD COLUMN `last_message_at` TIMESTAMP NULL DEFAULT NULL AFTER `message_count`;

-- Create broadcasts table
CREATE TABLE IF NOT EXISTS `broadcasts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_text` TEXT NOT NULL,
  `filters` JSON DEFAULT NULL,
  `status` ENUM('pending', 'sending', 'completed', 'paused', 'failed') NOT NULL DEFAULT 'pending',
  `total_users` INT UNSIGNED NOT NULL DEFAULT 0,
  `sent_users` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_users` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `error_details` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create broadcast_logs for individual delivery status (optional but good for tracking)
CREATE TABLE IF NOT EXISTS `broadcast_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `broadcast_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('sent', 'failed') NOT NULL,
  `error_message` TEXT DEFAULT NULL,
  `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_broadcast_user` (`broadcast_id`, `user_id`),
  CONSTRAINT `fk_broadcast_logs_broadcast` FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
