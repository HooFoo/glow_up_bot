-- Migration: Add recurrent payment columns and interface texts
-- 028_add_recurrent_payments.sql

-- Добавляем колонки в таблицу пользователей
ALTER TABLE `users` 
  ADD COLUMN `prodamus_binding_id` VARCHAR(255) NULL DEFAULT NULL AFTER `subscription_end`,
  ADD COLUMN `last_recurrent_attempt` TIMESTAMP NULL DEFAULT NULL AFTER `prodamus_binding_id`;

-- Обновляем ENUM статуса логов платежей
ALTER TABLE `payment_logs` 
  MODIFY COLUMN `status` ENUM('link_sent', 'paid', 'recurrent_failed') NOT NULL DEFAULT 'link_sent';

-- Добавляем новые тексты интерфейса
INSERT INTO `texts` (`key`, `title`, `content`, `active`) VALUES
('msg_profile_autorenewal_active', 'Профиль: Статус автопродления', '💳 Автопродление: Активно', 1),
('btn_cancel_autorenewal', 'Кнопка: Отключить автопродление', '❌ Отключить автопродление', 1),
('msg_autorenewal_cancelled', 'Сообщение: Автопродление отключено', '❌ Автопродление подписки успешно отключено.', 1),
('msg_autorenewal_failed', 'Сообщение: Ошибка автопродления', 'Автопродление не удалось (ошибка списания с карты). 🌙 Чтобы сохранить доступ к питанию, косметике и практикам, пожалуйста, оплатите подписку вручную:', 1),
('btn_pay_manually', 'Кнопка: Оплатить вручную после ошибки', '✨ Оплатить подписку', 1)
ON DUPLICATE KEY UPDATE content = VALUES(content), title = VALUES(title);
