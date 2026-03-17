# Модель данных: Prime/Glow Ассистент

## ER-диаграмма (упрощённая)

```
users (1) ──────── (N) messages
users (1) ──────── (N) quiz_answers
users (1) ──────── (N) subscriptions
users (1) ──────── (N) conversation_summaries
users (1) ──────── (1) user_profiles
```

---

## Таблицы

### `users`
Основная таблица пользователей Telegram.

| Колонка | Тип | Описание |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | Внутренний ID |
| `telegram_id` | BIGINT UNIQUE NOT NULL | Telegram User ID |
| `username` | VARCHAR(255) NULL | @username (без @) |
| `first_name` | VARCHAR(255) NOT NULL | Имя |
| `last_name` | VARCHAR(255) NULL | Фамилия |
| `language_code` | VARCHAR(10) NULL | Код языка (ru, en...) |
| `persona` | VARCHAR(100) NULL | Присвоенный типаж |
| `active_mode` | ENUM('nutrition','cosmetics','coach') NULL | Текущий режим бота |
| `message_count` | INT UNSIGNED DEFAULT 0 | Счётчик всех сообщений |
| `quiz_completed_at` | TIMESTAMP NULL | Время завершения квиза |
| `subscription_end` | TIMESTAMP NULL | Дата окончания подписки |
| `is_blocked` | TINYINT(1) DEFAULT 0 | Заблокирован ли пользователь бот |
| `created_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | Дата регистрации |
| `updated_at` | TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Дата обновления |

**Индексы:**
- `UNIQUE KEY uk_telegram_id (telegram_id)`
- `INDEX idx_subscription_end (subscription_end)`
- `INDEX idx_created_at (created_at)`

---

### `user_profiles`
Профиль пользователя (заполняется квизом и обновляется AI каждые 10 сообщений).

| Колонка | Тип | Описание |
|---|---|---|
| `id` | INT UNSIGNED PK AI | |
| `user_id` | BIGINT UNSIGNED FK → users.id | |
| `profile_json` | JSON NOT NULL | Структурированный профиль |
| `version` | INT UNSIGNED DEFAULT 1 | Версия профиля (инкремент) |
| `updated_at` | TIMESTAMP | Дата последнего обновления |

**Структура `profile_json`:**
```json
{
  "age": 28,
  "gender": "female",
  "goals": ["похудение", "здоровая кожа"],
  "health_restrictions": ["лактозная непереносимость"],
  "skin_type": "комбинированная",
  "diet_preferences": ["без глютена"],
  "lifestyle": "активный",
  "known_facts": ["занимается йогой 3 раза в неделю"],
  "persona": "Активная Практичная"
}
```

**Индексы:**
- `UNIQUE KEY uk_user_id (user_id)`

---

### `quiz_answers`
Ответы пользователя на вопросы квиза.

| Колонка | Тип | Описание |
|---|---|---|
| `id` | INT UNSIGNED PK AI | |
| `user_id` | BIGINT UNSIGNED FK → users.id | |
| `question_id` | VARCHAR(50) NOT NULL | ID вопроса из questions.md |
| `answer` | TEXT NOT NULL | Ответ (текст варианта или произвольно) |
| `created_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

**Индексы:**
- `INDEX idx_user_id (user_id)`

---

### `messages`
История переписки пользователя с ботом.

| Колонка | Тип | Описание |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `user_id` | BIGINT UNSIGNED FK → users.id | |
| `role` | ENUM('user','assistant') NOT NULL | Отправитель |
| `content` | TEXT NOT NULL | Текст сообщения |
| `mode` | ENUM('nutrition','cosmetics','coach','quiz') NULL | Режим на момент сообщения |
| `media_type` | ENUM('text','voice','photo') DEFAULT 'text' | Тип исходного медиа |
| `telegram_message_id` | INT UNSIGNED NULL | ID сообщения в Telegram |
| `created_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

**Индексы:**
- `INDEX idx_user_id_created (user_id, created_at)`
- `INDEX idx_user_id_mode (user_id, mode)`

---

### `conversation_summaries`
Резюме переписки — создаётся/обновляется каждые 5 сообщений.

| Колонка | Тип | Описание |
|---|---|---|
| `id` | INT UNSIGNED PK AI | |
| `user_id` | BIGINT UNSIGNED FK → users.id | |
| `mode` | ENUM('nutrition','cosmetics','coach','general') NOT NULL | Режим разговора |
| `summary` | TEXT NOT NULL | Текст резюме |
| `messages_covered_to` | INT UNSIGNED | Счётчик сообщений до которого сжато |
| `created_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | |

**Логика:**
- Один активный summary per `(user_id, mode)`
- При компрессии — UPDATE существующей записи,不 создание новой
- `messages_covered_to` = `message_count` пользователя на момент компрессии

**Индексы:**
- `UNIQUE KEY uk_user_mode (user_id, mode)`

---

### `subscriptions`
История подписок пользователей.

| Колонка | Тип | Описание |
|---|---|---|
| `id` | INT UNSIGNED PK AI | |
| `user_id` | BIGINT UNSIGNED FK → users.id | |
| `telegram_payment_charge_id` | VARCHAR(255) UNIQUE | ID транзакции от Telegram |
| `stars_amount` | INT UNSIGNED NOT NULL | Сумма в Stars |
| `starts_at` | TIMESTAMP NOT NULL | Начало подписки |
| `ends_at` | TIMESTAMP NOT NULL | Конец подписки |
| `status` | ENUM('active','expired','refunded') DEFAULT 'active' | |
| `created_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |

**Индексы:**
- `INDEX idx_user_id (user_id)`
- `INDEX idx_ends_at (ends_at)`
- `UNIQUE KEY uk_charge_id (telegram_payment_charge_id)`

---

## SQL-миграции

### 001_create_users.sql
```sql
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `telegram_id` BIGINT NOT NULL,
  `username` VARCHAR(255) DEFAULT NULL,
  `first_name` VARCHAR(255) NOT NULL,
  `last_name` VARCHAR(255) DEFAULT NULL,
  `language_code` VARCHAR(10) DEFAULT NULL,
  `persona` VARCHAR(100) DEFAULT NULL,
  `active_mode` ENUM('nutrition','cosmetics','coach') DEFAULT NULL,
  `message_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `quiz_completed_at` TIMESTAMP NULL DEFAULT NULL,
  `subscription_end` TIMESTAMP NULL DEFAULT NULL,
  `is_blocked` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_telegram_id` (`telegram_id`),
  KEY `idx_subscription_end` (`subscription_end`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 002_create_user_profiles.sql
```sql
CREATE TABLE `user_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `profile_json` JSON NOT NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  CONSTRAINT `fk_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 003_create_messages.sql
```sql
CREATE TABLE `messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role` ENUM('user','assistant') NOT NULL,
  `content` TEXT NOT NULL,
  `mode` ENUM('nutrition','cosmetics','coach','quiz') DEFAULT NULL,
  `media_type` ENUM('text','voice','photo') NOT NULL DEFAULT 'text',
  `telegram_message_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_user_mode` (`user_id`, `mode`),
  CONSTRAINT `fk_messages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 004_create_quiz_answers.sql
```sql
CREATE TABLE `quiz_answers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `question_id` VARCHAR(50) NOT NULL,
  `answer` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_quiz_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 005_create_subscriptions.sql
```sql
CREATE TABLE `subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `telegram_payment_charge_id` VARCHAR(255) NOT NULL,
  `stars_amount` INT UNSIGNED NOT NULL,
  `starts_at` TIMESTAMP NOT NULL,
  `ends_at` TIMESTAMP NOT NULL,
  `status` ENUM('active','expired','refunded') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_charge_id` (`telegram_payment_charge_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_ends_at` (`ends_at`),
  CONSTRAINT `fk_subs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 006_create_conversation_summaries.sql
```sql
CREATE TABLE `conversation_summaries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `mode` ENUM('nutrition','cosmetics','coach','general') NOT NULL,
  `summary` TEXT NOT NULL,
  `messages_covered_to` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_mode` (`user_id`, `mode`),
  CONSTRAINT `fk_summaries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Запросы для Admin-панели

### Список пользователей с сортировкой
```sql
SELECT
    u.*,
    (u.subscription_end > NOW()) AS has_active_sub
FROM users u
ORDER BY
    has_active_sub DESC,
    u.created_at DESC
LIMIT 20 OFFSET :offset;
```

### Dashboard: новые сессии по дням (10 дней)
```sql
SELECT
    DATE(created_at) AS day,
    COUNT(*) AS count
FROM users
WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)
GROUP BY DATE(created_at)
ORDER BY day ASC;
```

### Dashboard: покупки подписок по дням (10 дней)
```sql
SELECT
    DATE(created_at) AS day,
    COUNT(*) AS count
FROM subscriptions
WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)
GROUP BY DATE(created_at)
ORDER BY day ASC;
```

### Переписка пользователя (пагинация)
```sql
SELECT * FROM messages
WHERE user_id = :user_id
ORDER BY created_at ASC
LIMIT 50 OFFSET :offset;
```
