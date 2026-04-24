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
    private TextService $textService;

    // Persona thresholds: avg score ranges
    private const PERSONAS = [
        'shadow_queen'    => ['min' => 1.0, 'max' => 1.75, 'label' => 'Тенистая Королева', 'emoji' => '👑'],
        'iron_controller' => ['min' => 1.75, 'max' => 2.5,  'label' => 'Железный Контролер', 'emoji' => '⚙️'],
        'sleeping_muse'   => ['min' => 2.5,  'max' => 3.25, 'label' => 'Спящая Муза', 'emoji' => '🎨'],
        'magnetic_prime'  => ['min' => 3.25, 'max' => 5.0,  'label' => 'Магнитная Прайм', 'emoji' => '💎'],
    ];

    public function __construct(TelegramApi $telegram)
    {
        $this->db = Database::getInstance();
        $this->telegram = $telegram;
        $this->textService = new TextService();
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

        // Send result message (image + text + CTA button)
        $this->sendResult($chatId, $userId, $persona);

        // Send gift PDF
        $this->sendGift($chatId, $persona);

        // The funnel used to start automatically here, but now it's triggered by a button in sendResult
    }

    private function sendResult(int $chatId, int $userId, string $persona): void
    {
        $info = self::PERSONAS[$persona];
        $textKey = 'QUIZ_RESULT_' . $persona;
        
        // Fetch text from DB via TextService
        $text = $this->textService->get($textKey);

        if (!$text) {
            $labelEscaped = TelegramApi::escapeMarkdownV2($info['label']);
            $text = sprintf($this->textService->get('msg_persona_result_prefix', 'Твой архетип — %s %s\!'), $info['emoji'], $labelEscaped);
        }

        // Send image + text as caption
        $imagePath = Config::getProjectRoot() . "/bot/assets/archetypes/{$persona}.png";
        
        if (file_exists($imagePath)) {
            $this->telegram->sendPhoto($chatId, $imagePath, $text);
        } else {
            $this->telegram->sendMessage($chatId, $text);
        }
    }

    public function sendGift(int $chatId, string $persona): void
    {
        // Gift file mapping
        $gifts = [
            'shadow_queen'    => 'shadow_queen_sos.pdf',
            'iron_controller' => 'iron_controller_delegation.pdf',
            'sleeping_muse'   => 'sleeping_muse_tracker.pdf',
            'magnetic_prime'  => 'magnetic_prime_longevity.pdf',
        ];

        $filename = $gifts[$persona] ?? null;
        if (!$filename) {
            return;
        }

        $filePath = Config::getProjectRoot() . '/bot/assets/gifts/' . $filename;
        if (file_exists($filePath)) {
            $keyboard = TelegramApi::inlineKeyboard([
                [['text' => $this->textService->get('btn_activate_prime', '✨ Включить мой Прайм режим'), 'callback_data' => 'start_funnel']]
            ]);
            $this->telegram->sendDocument($chatId, $filePath, $this->textService->get('msg_gift_caption', '🎁 Твой персональный подарок\!'), $keyboard);
        }
    }

    private function sendPaywallCta(int $chatId): void
    {
        $text = $this->textService->get('PAYWALL_CTA', 'Начни свой Glow Up прямо сейчас 👇');

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => $this->textService->get('btn_get_access', '✨ ПОЛУЧИТЬ ДОСТУП'), 'callback_data' => 'get_access']],
        ]);

        $this->telegram->sendMessage($chatId, $text, $keyboard);
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
