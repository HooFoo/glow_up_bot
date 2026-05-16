-- Migration: Add subscription status profile texts
-- 026_add_profile_subscription_texts.sql

INSERT INTO `texts` (`key`, `title`, `content`, `active`) VALUES
('msg_profile_subscription', 'Профиль: Заголовок подписки', 'Подписка: ', 1),
('msg_profile_sub_active', 'Профиль: Статус активен', 'Активна до %s', 1),
('msg_profile_sub_inactive', 'Профиль: Статус не активен', 'Не активна', 1)
ON DUPLICATE KEY UPDATE content = VALUES(content), title = VALUES(title);
