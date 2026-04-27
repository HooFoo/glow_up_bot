-- Migration: Add skip course button text
-- 020_add_skip_course_btn.sql

INSERT INTO `texts` (`key`, `title`, `content`) VALUES
('btn_skip_course', 'Кнопка: Пропустить курс', 'Продолжить в бесплатной версии')
ON DUPLICATE KEY UPDATE `content` = VALUES(`content`);
