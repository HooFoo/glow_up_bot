<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\OpenAiApi;
use App\Core\Config;

class SummaryService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Compress last messages into a summary (called every 5 messages).
     */
    public function compress(int $userId, string $mode = 'general'): void
    {
        $openai = new OpenAiApi();

        // Get existing summary
        $existing = $this->db->fetchOne(
            'SELECT * FROM conversation_summaries WHERE user_id = :uid AND mode = :mode',
            [':uid' => $userId, ':mode' => $mode]
        );

        $previousSummary = $existing ? $existing['summary'] : '';
        $coveredTo = $existing ? (int) $existing['messages_covered_to'] : 0;

        // Get new messages since last compression
        $newMessages = $this->db->fetchAll(
            'SELECT role, content, created_at FROM messages WHERE user_id = :uid AND id > :from_id ORDER BY created_at ASC',
            [':uid' => $userId, ':from_id' => $coveredTo]
        );

        if (empty($newMessages)) {
            return;
        }

        // Build prompt
        $textService = new TextService();
        $promptTemplate = $textService->get('prompt_summary_compression', '', true);
        $promptTemplate = str_replace('{{PREVIOUS_SUMMARY}}', $previousSummary ?: '(нет предыдущего резюме)', $promptTemplate);

        $messagesText = '';
        foreach ($newMessages as $m) {
            $role = $m['role'] === 'user' ? 'Пользователь' : 'Бот';
            $messagesText .= "[{$m['created_at']}] {$role}: {$m['content']}\n";
        }
        $promptTemplate = str_replace('{{NEW_MESSAGES}}', $messagesText, $promptTemplate);

        $aiMessages = [
            ['role' => 'system', 'content' => 'Ты — аналитическая система. Сжимай информацию, сохраняя ключевые факты.'],
            ['role' => 'user', 'content' => $promptTemplate],
        ];

        $newSummary = $openai->chat($aiMessages, 0.3, 1024);

        if (empty($newSummary)) {
            return;
        }

        // Get the max message id we just processed
        $userService = new UserService();
        $user = $userService->findById($userId);
        $newCoveredTo = (int) ($user['message_count'] ?? 0);

        // Upsert
        if ($existing) {
            $this->db->execute(
                'UPDATE conversation_summaries SET summary = :summary, messages_covered_to = :covered WHERE user_id = :uid AND mode = :mode',
                [':summary' => $newSummary, ':covered' => $newCoveredTo, ':uid' => $userId, ':mode' => $mode]
            );
        } else {
            $this->db->insert(
                'INSERT INTO conversation_summaries (user_id, mode, summary, messages_covered_to) VALUES (:uid, :mode, :summary, :covered)',
                [':uid' => $userId, ':mode' => $mode, ':summary' => $newSummary, ':covered' => $newCoveredTo]
            );
        }
    }

    /**
     * Get summary for a user/mode.
     */
    public function getSummary(int $userId, string $mode = 'general'): string
    {
        $row = $this->db->fetchOne(
            'SELECT summary FROM conversation_summaries WHERE user_id = :uid AND mode = :mode',
            [':uid' => $userId, ':mode' => $mode]
        );
        return $row['summary'] ?? '';
    }
}
