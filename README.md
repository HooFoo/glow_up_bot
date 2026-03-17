# Prime/Glow Ассистент — Telegram Bot

Персональный AI-ассистент по питанию, косметике и коучингу с квизом,
типажами и подпиской через Telegram Stars.

## Стек

- **PHP 8.2+** — backend
- **MySQL 8.0+** — база данных
- **OpenAI API** — GPT-4o, Whisper, Vision
- **Telegram Bot API** — Webhook
- **Telegram Stars** — монетизация

## Документация

| Документ | Описание |
|---|---|
| [docs/original_request.md](docs/original_request.md) | Исходное техническое задание |
| [docs/architecture.md](docs/architecture.md) | Архитектура проекта |
| [docs/technical_requirements.md](docs/technical_requirements.md) | Технические требования |
| [docs/data_model.md](docs/data_model.md) | Модель данных и SQL-миграции |

## Быстрый старт

1. Клонировать репозиторий
2. Скопировать `.env.example` в `.env` и заполнить все переменные
3. Установить зависимости: `composer install`
4. Применить миграции из папки `migrations/`
5. Настроить Nginx на папку `bot/` как document root
6. Зарегистрировать webhook:
   ```
   https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://yourdomain.com/bot/webhook.php&secret_token={SECRET}
   ```
7. Открыть `https://yourdomain.com/admin/` для доступа в панель

## Структура проекта

```
prime_assistant/
├── docs/          # Документация
├── bot/           # Ядро бота (webhook + handlers + services)
├── prompts/       # Системные промпты (*.md)
├── quiz/          # Вопросы квиза и описание типажей
├── admin/         # Веб-панель администратора
├── migrations/    # SQL-миграции
└── .env.example
```
