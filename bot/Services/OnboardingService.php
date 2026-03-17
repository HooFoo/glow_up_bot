<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TelegramApi;
use App\Core\Config;

class OnboardingService
{
    private Database $db;
    private TelegramApi $telegram;

    private const QUESTIONS = [
        [
            'id'      => 'goal',
            'text'    => 'Твоя цель на ближайшие 14 дней?',
            'type'    => 'buttons',
            'options' => [
                'weight_loss'    => '📉 Снизить вес / убрать отёки',
                'energy'         => '⚡️ Повысить уровень энергии',
                'skin_hair'      => '✨ Улучшить состояние кожи и волос',
                'stress_balance' => '🧘 Найти баланс и снизить стресс',
            ],
        ],
        [
            'id'   => 'body_stats',
            'text' => 'Твой текущий вес и рост? (Например: 65/170)',
            'type' => 'text',
        ],
        [
            'id'      => 'health_features',
            'text'    => 'Есть ли у тебя установленные дефициты или особенности?',
            'type'    => 'buttons',
            'options' => [
                'low_iron_protein' => '🥩 Низкий ферритин / белок',
                'breastfeeding'    => '🍼 Я на ГВ (кормящая мама)',
                'sugar_craving'    => '🍬 Тяга к сладкому',
                'optimization'     => '✅ Всё в норме, хочу оптимизировать',
            ],
        ],
        [
            'id'      => 'cycle_phase',
            'text'    => 'Твой цикл (для настройки календаря питания)?',
            'type'    => 'buttons',
            'options' => [
                'menstruation'  => '🔴 Сейчас фаза менструации',
                'follicular'    => '🟢 Фаза после (эстрогеновая мощь)',
                'ovulation_pms' => '🟡 Овуляция / ПМС',
                'no_cycle'      => '⚪️ Цикл отсутствует',
            ],
        ],
    ];

    public function __construct(TelegramApi $telegram)
    {
        $this->db = Database::getInstance();
        $this->telegram = $telegram;
    }

    /**
     * Start onboarding from question 0.
     */
    public function start(int $chatId, int $userId): void
    {
        $text = "Чтобы я могла давать тебе точные рекомендации, мне нужны твои базовые настройки.\n\nЭто займет 30 секунд, но сэкономит тебе часы жизни ✨";
        $this->telegram->sendMessage($chatId, $text);

        $this->sendQuestion($chatId, $userId, 0);
    }

    /**
     * Handle onboarding callback answer.
     */
    public function handleAnswer(int $chatId, int $userId, string $data): void
    {
        // data format: onboard_{questionId}_{answerKey}
        $payload = substr($data, 8); // remove 'onboard_' prefix
        $questionId = null;
        $answerKey = null;

        foreach (self::QUESTIONS as $q) {
            if (str_starts_with($payload, $q['id'] . '_')) {
                $questionId = $q['id'];
                $answerKey = substr($payload, strlen($q['id']) + 1);
                break;
            }
        }

        if (!$questionId || !$answerKey) {
            return;
        }

        // Find question definition
        $question = $this->findQuestion($questionId);
        if (!$question) {
            return;
        }

        $answerText = $question['options'][$answerKey] ?? $answerKey;

        // Save to quiz_answers
        $this->db->insert(
            'INSERT INTO quiz_answers (user_id, question_id, answer) VALUES (:uid, :qid, :answer)',
            [':uid' => $userId, ':qid' => $questionId, ':answer' => $answerKey]
        );

        // Advance to next question
        $currentIndex = $this->getQuestionIndex($questionId);
        $nextIndex = $currentIndex + 1;

        if ($nextIndex < count(self::QUESTIONS)) {
            $this->sendQuestion($chatId, $userId, $nextIndex);
        } else {
            $this->finishOnboarding($chatId, $userId);
        }
    }

    /**
     * Handle free-text answers (e.g., weight/height).
     */
    public function handleTextAnswer(int $chatId, int $userId, string $text): void
    {
        // Determine which text question we're expecting
        $userService = new UserService();
        $user = $userService->findById($userId);
        $state = $user['state'] ?? '';

        // If state is onboard_body_stats => save it
        if ($state === 'onboard_body_stats') {
            $this->db->insert(
                'INSERT INTO quiz_answers (user_id, question_id, answer) VALUES (:uid, :qid, :answer)',
                [':uid' => $userId, ':qid' => 'body_stats', ':answer' => $text]
            );

            // Move to next question (index 2)
            $this->sendQuestion($chatId, $userId, 2);
        }
    }

    private function sendQuestion(int $chatId, int $userId, int $index): void
    {
        if ($index >= count(self::QUESTIONS)) {
            return;
        }

        $q = self::QUESTIONS[$index];
        $text = "📋 <b>Настройка профиля</b>\n\n{$q['text']}";

        $userService = new UserService();

        if ($q['type'] === 'buttons' && isset($q['options'])) {
            $buttons = [];
            foreach ($q['options'] as $key => $label) {
                $buttons[] = [['text' => $label, 'callback_data' => "onboard_{$q['id']}_{$key}"]];
            }
            $this->telegram->sendMessage($chatId, $text, TelegramApi::inlineKeyboard($buttons));
            $userService->updateState($userId, "onboard_{$q['id']}");
        } elseif ($q['type'] === 'text') {
            $this->telegram->sendMessage($chatId, $text);
            $userService->updateState($userId, "onboard_{$q['id']}");
        }
    }

    private function finishOnboarding(int $chatId, int $userId): void
    {
        $profileService = new ProfileService();
        $profileService->buildInitialProfile($userId);

        $userService = new UserService();
        $userService->completeOnboarding($userId);

        // Send main menu
        $this->sendMainMenu($chatId, $userId);
    }

    private function sendMainMenu(int $chatId, int $userId): void
    {
        $userService = new UserService();
        $user = $userService->findById($userId);
        $name = $user['first_name'] ?? 'Подруга';

        $text = "Привет, {$name}! ✨\n\nВыбери, с чего начнём сегодня:";

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => '🥗 Питание', 'callback_data' => 'mode_nutrition']],
            [['text' => '✨ Косметика', 'callback_data' => 'mode_cosmetics']],
            [['text' => '🧘 Коучинг', 'callback_data' => 'mode_coach']],
            [['text' => '👤 Мой профиль', 'callback_data' => 'show_profile']],
        ]);

        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    private function findQuestion(string $id): ?array
    {
        foreach (self::QUESTIONS as $q) {
            if ($q['id'] === $id) {
                return $q;
            }
        }
        return null;
    }

    private function getQuestionIndex(string $id): int
    {
        foreach (self::QUESTIONS as $i => $q) {
            if ($q['id'] === $id) {
                return $i;
            }
        }
        return -1;
    }
}
