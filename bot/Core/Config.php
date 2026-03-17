<?php

declare(strict_types=1);

namespace App\Core;

class Config
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (empty(self::$cache)) {
            self::load();
        }

        return self::$cache[$key] ?? $default;
    }

    private static function load(): void
    {
        $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();

        self::$cache = $_ENV;
    }

    public static function getTelegramToken(): string
    {
        return self::get('TELEGRAM_BOT_TOKEN', '');
    }

    public static function getTelegramWebhookSecret(): string
    {
        return self::get('TELEGRAM_WEBHOOK_SECRET', '');
    }

    public static function getTelegramStarsPrice(): int
    {
        return (int) self::get('TELEGRAM_STARS_PRICE', 299);
    }

    public static function getOpenAiKey(): string
    {
        return self::get('OPENAI_API_KEY', '');
    }

    public static function getOpenAiModel(): string
    {
        return self::get('OPENAI_MODEL', 'gpt-4o');
    }

    public static function getWhisperModel(): string
    {
        return self::get('OPENAI_WHISPER_MODEL', 'whisper-1');
    }

    public static function getDbHost(): string
    {
        return self::get('DB_HOST', '127.0.0.1');
    }

    public static function getDbPort(): int
    {
        return (int) self::get('DB_PORT', 3306);
    }

    public static function getDbName(): string
    {
        return self::get('DB_NAME', 'prime_assistant');
    }

    public static function getDbUser(): string
    {
        return self::get('DB_USER', 'root');
    }

    public static function getDbPass(): string
    {
        return self::get('DB_PASS', '');
    }

    public static function getAdminLogin(): string
    {
        return self::get('ADMIN_LOGIN', 'admin');
    }

    public static function getAdminPassword(): string
    {
        return self::get('ADMIN_PASSWORD', '');
    }

    public static function getAppEnv(): string
    {
        return self::get('APP_ENV', 'production');
    }

    public static function getLogLevel(): string
    {
        return self::get('LOG_LEVEL', 'error');
    }

    public static function getFreeDays(): int
    {
        return (int) self::get('FREE_DAYS', 2);
    }

    public static function getSubscriptionDays(): int
    {
        return (int) self::get('SUBSCRIPTION_DAYS', 30);
    }

    public static function getAppUrl(): string
    {
        return rtrim(self::get('APP_URL', ''), '/');
    }

    public static function isDebug(): bool
    {
        return self::getAppEnv() === 'development';
    }

    public static function getProjectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
