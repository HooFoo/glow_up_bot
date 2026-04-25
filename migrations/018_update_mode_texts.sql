-- Update Nutrition Mode Text
INSERT INTO texts (`key`, title, content, active) VALUES 
('msg_mode_nutrition_header', 'Заголовок режима Питание', 'ты в режиме питания 🍽️ здесь мы не считаем калории ради цифр\nмы собираем тебе питание, от которого есть энергия и нет откатов\nвыбери, что нужно 👇', 1)
ON DUPLICATE KEY UPDATE content = VALUES(content);

-- Update Cosmetologist Mode Text
INSERT INTO texts (`key`, title, content, active) VALUES 
('msg_mode_cosmetics_header', 'Заголовок режима Косметолог', 'ты в режиме ИИ косметолога ✨ здесь мы не просто подбираем уход\nа собираем систему, от которой кожа реально меняется\nвыбери, с чего начнём 👇', 1)
ON DUPLICATE KEY UPDATE content = VALUES(content);

-- Add Beauty Assistant Mode Text
INSERT INTO texts (`key`, title, content, active) VALUES 
('msg_mode_beauty_assistant_header', 'Заголовок режима Beauty-ассистент', 'Режим Beauty-ассистент 🤖 здесь я собираю тебе систему под тебя:\nсостояние → питание → тело → ритуалы\n\nНе советы «для всех», а конкретные действия под твой день.\nТы можешь:\n— получить план на сегодня\n— разобрать своё состояние\n— собрать свой Prime-режим\n\nНапиши, как ты себя чувствуешь сейчас 👇', 1)
ON DUPLICATE KEY UPDATE content = VALUES(content);

-- Add Practices Mode Text (Locked/Upsell)
INSERT INTO texts (`key`, title, content, active) VALUES 
('msg_mode_practices_header', 'Заголовок режима Практики', 'Режим: Практики 🧘‍♀️ Здесь собраны практики, которые возвращают тебя в состояние.\n\nНо доступ открыт только в Prime-формате ✨', 1)
ON DUPLICATE KEY UPDATE content = VALUES(content);

-- Add Switched Messages
INSERT INTO texts (`key`, title, content, active) VALUES 
('msg_mode_nutrition_switched', 'Сообщение при переключении на Питание', 'Режим переключён на: *🥗 Питание*\n\nЗдесь мы собираем тебе питание, от которого есть энергия и нет откатов\. Выбери, что нужно 👇', 1),
('msg_mode_cosmetics_switched', 'Сообщение при переключении на Косметику', 'Режим переключён на: *✨ Твой ИИ косметолог*\n\nЗдесь мы собираем систему, от которой кожа реально меняется\. Выбери, с чего начнём 👇', 1),
('msg_mode_beauty_assistant_switched', 'Сообщение при переключении на Ассистента', 'Режим переключён на: *🤖 Beauty-ассистент*\n\nЗдесь я собираю конкретные действия под твой день\. Напиши, как ты себя чувствуешь сейчас 👇', 1),
('msg_mode_practices_switched', 'Сообщение при переключении на Практики', 'Режим переключён на: *🧘‍♀️ Практики*\n\nЗдесь собраны практики, возвращающие тебя в состояние ✨', 1)
ON DUPLICATE KEY UPDATE content = VALUES(content);

-- Add Button Texts
INSERT INTO texts (`key`, title, content, active) VALUES 
('btn_mode_nutrition', 'Кнопка: Питание', '🥗 Питание', 1),
('btn_mode_cosmetics', 'Кнопка: Косметолог', '✨ Твой ИИ косметолог', 1),
('btn_mode_beauty_assistant', 'Кнопка: Ассистент', '🤖 Beauty-ассистент', 1),
('btn_mode_practices', 'Кнопка: Практики', '🧘‍♀️ Практики (доступ в Prime)', 1)
ON DUPLICATE KEY UPDATE content = VALUES(content);
