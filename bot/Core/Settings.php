<?php

declare(strict_types=1);

namespace App\Core;

class Settings
{
    private static array $cache = [];
    private static bool $loaded = false;

    /**
     * Get a setting by key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::$cache[$key] ?? $default;
    }

    /**
     * Get a boolean setting.
     */
    public static function isEnabled(string $key): bool
    {
        $val = self::get($key, '0');
        return $val === '1' || $val === 'true' || $val === true || $val === 1;
    }

    /**
     * Load all settings from database.
     */
    public static function load(): void
    {
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll('SELECT key_name, value_text FROM settings');
            foreach ($rows as $row) {
                self::$cache[$row['key_name']] = $row['value_text'];
            }
            self::$loaded = true;
        } catch (\Throwable $e) {
            // If table doesn't exist yet or DB error, just use defaults
            self::$loaded = false;
        }
    }

    /**
     * Update a setting (mainly for admin).
     */
    public static function set(string $key, string $value): void
    {
        $db = Database::getInstance();
        $db->execute(
            'INSERT INTO settings (key_name, value_text) VALUES (:key, :val) 
             ON DUPLICATE KEY UPDATE value_text = :val',
            [':key' => $key, ':val' => $value]
        );
        self::$cache[$key] = $value;
    }
}
