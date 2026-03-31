<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

class TextService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get text content by its key.
     * Escapes for Telegram MarkdownV2 by default.
     */
    public function get(string $key, string $default = '', bool $raw = false): string
    {
        // Try fetching only active text first
        $row = $this->db->fetchOne('SELECT content FROM texts WHERE `key` = :key AND active = 1', [':key' => $key]);
        $text = $row['content'] ?? $default;
        
        if ($raw) {
            return $text;
        }
        
        return \App\Core\TelegramApi::escapeMarkdownV2($text);
    }

    /**
     * Get all texts for administration.
     */
    public function getAll(): array
    {
        return $this->db->fetchAll('SELECT * FROM texts ORDER BY title ASC');
    }

    /**
     * Get a single text record by ID.
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM texts WHERE id = :id', [':id' => $id]);
    }

    /**
     * Update text content.
     */
    public function update(int $id, string $content, string $title): bool
    {
        return $this->db->execute(
            'UPDATE texts SET content = :content, title = :title WHERE id = :id',
            [':id' => $id, ':content' => $content, ':title' => $title]
        ) > 0;
    }
}
