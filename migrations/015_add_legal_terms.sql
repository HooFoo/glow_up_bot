-- Migration: Add legal terms acceptance
-- 015_add_legal_terms.sql

ALTER TABLE `users` ADD COLUMN `terms_accepted_at` DATETIME DEFAULT NULL AFTER `onboarding_completed_at`;

INSERT INTO `texts` (`key`, `title`, `content`, `active`) VALUES
('msg_legal_terms', 'Сообщение: Юридические условия', 'Нажимая на кнопку и отвечая на сообщения в чат-боте, вы принимаете условия <a href="https://admin.anketa.prodamus.ru/files/download/305056/944f19f129c825a682d68629b9986d4b">оферты</a> и выражаете <a href="https://disk.yandex.ru/i/Ib2B2c7-SCeT9A">согласие</a> на обработку персональных данных согласно <a href="https://disk.yandex.ru/i/RbKm7-MsDOjpLQ">Политике конфиденциальности</a>, а также даете <a href="https://disk.yandex.ru/i/BGrS3ie1gWj7kQ">согласие</a> на получение рекламных рассылок\n\nP.S. Здесь ты будешь получать только полезную информацию, без спама и воды.', 1),
('btn_accept_terms', 'Кнопка: Принимаю условия', 'Принимаю', 1)
ON DUPLICATE KEY UPDATE content = VALUES(content), title = VALUES(title);
