-- Migration: Add direct payment button and related texts
INSERT INTO `texts` (`key`, `title`, `content`, `active`) VALUES
('btn_step_5_direct_pay', 'Кнопка прямой оплаты в шаге 5', 'Оплатить участие', 1),
('msg_step_5_payment_link', 'Сообщение со ссылкой на оплату', 'ссылка на оплату', 1),
('btn_payment_link_text', 'Текст кнопки со ссылкой на оплату', 'Оплатить', 1),
('btn_payment_link_url', 'Ссылка для прямой оплаты', 'https://payform.ru/nvbvDgi/', 1)
ON DUPLICATE KEY UPDATE content = VALUES(content);
