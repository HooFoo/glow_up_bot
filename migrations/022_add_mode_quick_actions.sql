-- Migration: Add mode quick actions and labels
-- 022_add_mode_quick_actions.sql

INSERT INTO `texts` (`key`, `title`, `content`) VALUES
-- Mode Button Labels (Main Menu)
('btn_mode_beauty_assistant', 'Кнопка: Glow Up ассистент', '🤖 Glow Up ассистент'),

-- Main Mode (Glow Up / beauty_assistant) Quick Actions
('btn_action_prime_day', 'Кнопка: Собрать мой Prime-день', '🥗 Собрать мой Prime-день'),
('btn_action_tired', 'Кнопка: Я устала', '😵 Я устала'),
('btn_action_glow', 'Кнопка: Хочу glow', '✨ Хочу glow'),
('btn_action_cycle_ritual', 'Кнопка: Ритуал по циклу', '🌙 Ритуал по циклу'),
('btn_action_state_diary', 'Кнопка: Дневник состояния', '🧠 Дневник состояния'),

-- Nutrition Assistant Quick Actions
('btn_action_nutrition_week', 'Кнопка: Собрать мне питание на неделю', '🥗 Собрать мне питание на неделю'),
('btn_action_calories', 'Кнопка: Понять сколько мне есть', '⚖️ Понять сколько мне есть'),
('btn_action_analyze_meal', 'Кнопка: Разобрать мой приём пищи', '📸 Разобрать мой приём пищи'),

-- Beauty Assistant (Cosmetics) Quick Actions
('btn_action_cosmetics_glow', 'Кнопка: Хочу сияние', '✨ Хочу сияние'),
('btn_action_acne', 'Кнопка: Убрать высыпания', '😣 Убрать высыпания'),
('btn_action_skin_tone', 'Кнопка: Выровнять тон', '🎯 Выровнять тон'),
('btn_action_care_routine', 'Кнопка: Собрать мне уход', '🧴 Собрать мне уход'),
('btn_action_analyze_products', 'Кнопка: Разобрать мои средства', '📸 Разобрать мои средства'),

-- Prompt Messages
('msg_action_analyze_meal_prompt', 'Сообщение: Запрос фото еды', 'Пришли фото своего приема пищи или его текстовое описание'),
('msg_action_care_routine_prompt', 'Сообщение: Запрос данных для ухода', 'давай соберу тебе базовый уход под твою кожу\n\nответь коротко:\n\n— кожа сейчас: сухая / жирная / комбинированная\n\n— есть ли высыпания / чувствительность'),
('msg_action_analyze_products_prompt', 'Сообщение: Запрос фото средств', 'Пришли фото своих средств или их текстовое описание'),

-- Switch Mode Messages
('msg_mode_beauty_assistant_switched', 'Сообщение: Режим Glow Up включен', 'Режим переключён на: *%s*\n\nЧем я могу помочь?'),
('msg_mode_nutrition_switched', 'Сообщение: Режим Питание включен', 'Режим переключён на: *%s*\n\nЧем я могу помочь?'),
('msg_mode_cosmetics_switched', 'Сообщение: Режим Косметика включен', 'Режим переключён на: *%s*\n\nЧем я могу помочь?')

ON DUPLICATE KEY UPDATE `content` = VALUES(`content`);
