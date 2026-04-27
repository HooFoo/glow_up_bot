-- Migration: Add thinking message text
-- 021_add_thinking_message.sql

INSERT INTO `texts` (`key`, `title`, `content`) VALUES
('msg_chat_thinking', 'Сообщение: Бот долго думает', 'Я думаю дольше чем обычно. Подожди еще пожалуйста.')
ON DUPLICATE KEY UPDATE `content` = VALUES(`content`);
