-- Migration: Create sent_mailings table to track automated messages
-- Created At: 2026-04-13

CREATE TABLE IF NOT EXISTS `sent_mailings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `mailing_key` VARCHAR(191) NOT NULL,
  `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_mailing` (`user_id`, `mailing_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
