-- Migration: Add course payment link text
INSERT INTO `texts` (`key`, `title`, `content`, `active`) VALUES
('btn_course_payment_url', 'Ссылка для оплаты курса с Настей', 'https://payform.ru/55bvDjV/', 1)
ON DUPLICATE KEY UPDATE content = VALUES(content);
