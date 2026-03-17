# Архитектура: Prime/Glow Ассистент

## Стек технологий

| Компонент | Технология |
|---|---|
| Язык backend | PHP 8.2+ |
| База данных | MySQL 8.0+ |
| AI / LLM | OpenAI API (GPT-4o) |
| STT (голос → текст) | OpenAI Whisper API |
| Vision (обработка фото) | OpenAI Vision API (GPT-4o) |
| Telegram интеграция | Telegram Bot API (Webhook) |
| Оплата | Telegram Stars (встроенные платежи) |
| Веб-панель | PHP + Vanilla CSS |
| Веб-сервер | Nginx + PHP-FPM |

---

## Структура директорий

```
prime_assistant/
├── docs/                          # Документация проекта
│   ├── original_request.md
│   ├── architecture.md
│   ├── technical_requirements.md
│   └── data_model.md
│
├── bot/                           # Ядро Telegram-бота
│   ├── webhook.php                # Точка входа Telegram Webhook
│   ├── config.php                 # Конфигурация (env-переменные)
│   ├── bootstrap.php              # Инициализация зависимостей
│   │
│   ├── core/                      # Базовые классы
│   │   ├── Database.php           # Singleton подключения к БД
│   │   ├── TelegramApi.php        # Обёртка Telegram Bot API
│   │   ├── OpenAiApi.php          # Обёртка OpenAI API
│   │   └── Logger.php             # Логирование событий
│   │
│   ├── handlers/                  # Обработчики входящих событий
│   │   ├── MessageHandler.php     # Текстовые сообщения
│   │   ├── VoiceHandler.php       # Голосовые сообщения
│   │   ├── PhotoHandler.php       # Фото сообщения
│   │   ├── CallbackHandler.php    # Callback-кнопки (inline keyboard)
│   │   └── PaymentHandler.php     # Обработка платежей (Stars)
│   │
│   ├── services/                  # Бизнес-логика
│   │   ├── UserService.php        # Управление пользователями
│   │   ├── QuizService.php        # Логика квиза
│   │   ├── ChatService.php        # AI-диалог, контекст, память
│   │   ├── ProfileService.php     # Обновление профиля пользователя
│   │   ├── SummaryService.php     # Компрессия переписки в резюме
│   │   ├── SubscriptionService.php# Управление подписками и пейволом
│   │   └── PersonaService.php     # Определение типажа по квизу
│   │
│   ├── models/                    # Модели данных (Active Record / Repository)
│   │   ├── User.php
│   │   ├── Message.php
│   │   ├── QuizAnswer.php
│   │   ├── Subscription.php
│   │   └── ConversationSummary.php
│   │
│   └── modes/                     # Режимы работы бота
│       ├── NutritionMode.php      # Консультант по питанию
│       ├── CosmeticsMode.php      # Консультант по косметике
│       └── CoachMode.php          # Коуч
│
├── prompts/                       # Системные промпты (Markdown)
│   ├── nutrition_system.md        # Промпт для режима питания
│   ├── cosmetics_system.md        # Промпт для режима косметики
│   ├── coach_system.md            # Промпт для режима коуча
│   ├── profile_update.md          # Промпт для обновления профиля
│   └── summary_compression.md    # Промпт для сжатия переписки
│
├── quiz/                          # Квиз (вопросы и типажи)
│   ├── questions.md               # Вопросы квиза
│   └── personas.md                # Описание типажей + картинки
│
├── admin/                         # Веб-панель администратора
│   ├── index.php                  # Dashboard
│   ├── users.php                  # Список пользователей
│   ├── user_view.php              # Страница пользователя
│   ├── conversation.php           # Переписка пользователя
│   ├── auth.php                   # Авторизация в панели
│   ├── api/                       # JSON API для графиков
│   │   └── stats.php
│   └── assets/
│       ├── css/
│       │   └── admin.css
│       └── js/
│           └── charts.js
│
├── migrations/                    # SQL-миграции
│   ├── 001_create_users.sql
│   ├── 002_create_messages.sql
│   ├── 003_create_quiz_answers.sql
│   ├── 004_create_subscriptions.sql
│   └── 005_create_summaries.sql
│
├── .env.example                   # Пример переменных окружения
├── .env                           # (не в git) Реальные ключи
└── README.md
```

---

## Архитектурные слои

```
┌─────────────────────────────────────────────┐
│              Telegram Bot API               │
│         (Webhook → webhook.php)             │
└──────────────────┬──────────────────────────┘
                   │
┌──────────────────▼──────────────────────────┐
│             Handlers Layer                  │
│  MessageHandler / VoiceHandler / PhotoHandler│
│  CallbackHandler / PaymentHandler           │
└──────────────────┬──────────────────────────┘
                   │
┌──────────────────▼──────────────────────────┐
│             Services Layer                  │
│  UserService / QuizService / ChatService    │
│  ProfileService / SummaryService            │
│  SubscriptionService / PersonaService       │
└────────┬──────────────────────┬─────────────┘
         │                      │
┌────────▼────────┐   ┌─────────▼────────────┐
│   MySQL (DB)    │   │    OpenAI API        │
│   Models Layer  │   │  GPT-4o / Whisper /  │
│                 │   │  Vision              │
└─────────────────┘   └──────────────────────┘
```

---

## Основные потоки данных

### 1. Поток онбординга (два этапа)

**Phase 1 — Квиз (архетип)**
```
/start → UserService::createOrGet()
       → Текст START_WELCOME (bot/texts/messages.md)
       → [кнопка: НАЧАТЬ НАСТРОЙКУ]
       → Текст QUIZ_INTRO
       → QuizService::start()
           → 7 вопросов из quiz/questions.md (inline buttons)
           → Каждый ответ → QuizAnswer::save()
       → PersonaService::assignPersona()  ← средний балл по 7 ответам
       → Текст QUIZ_RESULT_{persona} (bot/texts/messages.md)
       → PersonaService::sendGift()       ← файл из assets/gifts/
       → Текст PAYWALL_CTA
       → users.quiz_completed_at = NOW() ← старт бесплатного периода
```

**Phase 2 — Онбординг (расширенный профиль)**
```
[кнопка: ПОЛУЧИТЬ ДОСТУП] или начало бесплатного периода
  → SubscriptionService::checkAccess()  ← пейвол или доступ
  → Текст ONBOARDING_INTRO
  → OnboardingService::start()
      → 4 вопроса из quiz/onboarding.md (inline buttons / free text)
      → Каждый ответ → QuizAnswer::save()
  → ProfileService::buildInitialProfile()
      → Собирает итоговый profile_json (архетип + онбординг)
      → INSERT/UPDATE user_profiles
  → Текст MAIN_MENU → выбор режима (Питание / Косметика / Коуч)
```

### 2. Поток диалога
```
1. Вебхук получает Update от Telegram
2. Валидация запроса и маршрутизация (webhook.php)
3. Быстрый ответ Telegram (HTTP 200 OK)
   • С использованием fastcgi_finish_request() или аналогичных методов
   • Цель: разорвать соединение с Telegram, чтобы он не ждал генерации LLM
4. После ответа (Background processing):
   → SubscriptionService::checkAccess()  ← пейвол
   → [ChatAction: "typing"]
   → [VoiceHandler: Whisper STT]
   → [PhotoHandler: Vision описание]
   → ChatService::buildContext()
   → OpenAI API → ответ
   → Message::save() (user + bot)
   → [ChatAction: STOP]
   → TelegramApi::sendMessage()
   → ChatService::checkCounters() (Summary / Profile update)
```

### 3. Поток оплаты
```
Пейвол показан пользователю
  → Нажата кнопка «Купить подписку»
  → Telegram Stars инвойс отправлен
  → pre_checkout_query подтверждён
  → successful_payment обработан
  → SubscriptionService::activate(30 дней)
```

---

## Контекст для LLM (ChatService)

```
[System prompt из prompts/*.md]

[Profile пользователя]
Имя: ...
Типаж: ...
Цели: ...
Ограничения: ...
(обновляется каждые 10 сообщений)

[Резюме предыдущих разговоров]
(сжимается каждые 5 сообщений)

[Последние 5 сообщений]
User: ...
Bot: ...
...
```

---

## Администраторская панель

Доступ через базовую HTTP-авторизацию или сессионную авторизацию (логин/пароль в `.env`).

### Страницы:
| URL | Описание |
|---|---|
| `/admin/` | Dashboard |
| `/admin/users` | Список пользователей |
| `/admin/users/{id}` | Карточка пользователя |
| `/admin/users/{id}/chat` | Переписка пользователя |

### Компоненты Dashboard:
- KPI-карточки (всего пользователей, активных подписок)
- Line chart: новые сессии по дням (10 дней) — Chart.js
- Line chart: покупки подписок по дням (10 дней) — Chart.js

---

## Безопасность

- `.env` не попадает в git (`.gitignore`)
- Webhook подписывается через `secret_token` Telegram
- Валидация всех входящих данных от Telegram
- SQL через PDO с prepared statements (без raw-запросов)
- Admin-панель защищена паролем из `.env`
- Rate limiting на webhook (опционально: nginx-level)
