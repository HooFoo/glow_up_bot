<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TelegramApi;
use App\Core\Config;

class PersonaService
{
    private Database $db;
    private TelegramApi $telegram;

    // Persona thresholds: avg score ranges
    private const PERSONAS = [
        'shadow_queen'    => ['min' => 1.0, 'max' => 1.75, 'label' => 'Тенистая Королева', 'emoji' => '👑'],
        'iron_controller' => ['min' => 1.75, 'max' => 2.5,  'label' => 'Железный Контролер', 'emoji' => '⚙️'],
        'sleeping_muse'   => ['min' => 2.5,  'max' => 3.25, 'label' => 'Спящая Муза', 'emoji' => '🎨'],
        'magnetic_prime'  => ['min' => 3.25, 'max' => 5.0,  'label' => 'Магнитная Прайм', 'emoji' => '💎'],
    ];

    // Gift file mapping
    private const GIFTS = [
        'shadow_queen'    => 'shadow_queen_sos.pdf',
        'iron_controller' => 'iron_controller_delegation.pdf',
        'sleeping_muse'   => 'sleeping_muse_tracker.pdf',
        'magnetic_prime'  => 'magnetic_prime_longevity.pdf',
    ];

    public function __construct(TelegramApi $telegram)
    {
        $this->db = Database::getInstance();
        $this->telegram = $telegram;
    }

    /**
     * Calculate persona from quiz answers and send result + gift.
     */
    public function assignFromQuiz(int $chatId, int $userId): void
    {
        // Get all quiz answers for the user
        $answers = $this->db->fetchAll(
            'SELECT answer_index FROM quiz_answers WHERE user_id = :uid ORDER BY created_at ASC',
            [':uid' => $userId]
        );

        if (empty($answers)) {
            return;
        }

        // Calculate average score
        $sum = array_sum(array_column($answers, 'answer_index'));
        $avg = $sum / count($answers);

        // Determine persona
        $persona = 'sleeping_muse'; // default
        foreach (self::PERSONAS as $key => $range) {
            if ($avg >= $range['min'] && $avg < $range['max']) {
                $persona = $key;
                break;
            }
        }

        // Save persona to user
        $userService = new UserService();
        $userService->setPersona($userId, $persona);
        $userService->completeQuiz($userId);

        // Send result message
        $this->sendResult($chatId, $userId, $persona);

        // Send gift
        $this->sendGift($chatId, $persona);

        // Send CTA / paywall message
        $this->sendPaywallCta($chatId);
    }

    private function sendResult(int $chatId, int $userId, string $persona): void
    {
        $info = self::PERSONAS[$persona];
        $messagesPath = Config::getProjectRoot() . '/bot/Texts/messages.md';
        $content = file_get_contents($messagesPath);

        // Try to find persona-specific result text
        $blockKey = 'QUIZ_RESULT_' . $persona;
        $text = $this->extractBlock($content, $blockKey);

        if (!$text) {
            $text = "Твой архетип — {$info['emoji']} {$info['label']}!";
        }

        $this->telegram->sendMessage($chatId, $text);
    }

    public function sendGift(int $chatId, string $persona): void
    {
        $filename = self::GIFTS[$persona] ?? null;
        if (!$filename) {
            return;
        }

        $filePath = Config::getProjectRoot() . '/assets/gifts/' . $filename;
        if (file_exists($filePath)) {
            $this->telegram->sendDocument($chatId, $filePath, '🎁 Твой персональный подарок!');
        }
    }

    private function sendPaywallCta(int $chatId): void
    {
        $messagesPath = Config::getProjectRoot() . '/bot/Texts/messages.md';
        $content = file_get_contents($messagesPath);
        $text = $this->extractBlock($content, 'PAYWALL_CTA');

        if (!$text) {
            $text = 'Начни свой Glow Up прямо сейчас 👇';
        }

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => '✨ ПОЛУЧИТЬ ДОСТУП', 'callback_data' => 'get_access']],
        ]);

        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    /**
     * Extract a text block from messages.md by its key.
     */
    private function extractBlock(string $content, string $key): ?string
    {
        // Text blocks in messages.md are enclosed in ``` after ## KEY header
        $pattern = '/## ' . preg_quote($key, '/') . '\n.*?```\n(.*?)```/su';
        if (preg_match($pattern, $content, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public static function getPersonaLabel(string $persona): string
    {
        return self::PERSONAS[$persona]['label'] ?? $persona;
    }

    public static function getPersonaEmoji(string $persona): string
    {
        return self::PERSONAS[$persona]['emoji'] ?? '✨';
    }
}
