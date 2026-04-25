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
    private TextService $textService;

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
            'text' => 'Твой текущий вес и рост? \(Например: 65/170\)',
            'type' => 'text',
        ],
        [
            'id'      => 'health_features',
            'text'    => 'Есть ли у тебя установленные дефициты или особенности?',
            'type'    => 'buttons',
            'options' => [
                'low_iron_protein' => '🥩 Да, есть установленные дефициты',
                'breastfeeding'    => '🍼 Я на ГВ (кормящая мама)',
                'sugar_craving'    => '🍬 Тяга к сладкому',
                'optimization'     => '✅ Всё в норме, хочу оптимизировать',
                'no_answer'        => 'Я не знаю',
            ],
        ],
        [
            'id'   => 'last_period_date',
            'text' => "Когда начались последние месячные? \(Например: 15\.03 или 2026\-03\-15\) \n\n Если цикл отсутствует — напиши «нет»\.",
            'type' => 'text',
        ],
    ];

    public function __construct(TelegramApi $telegram)
    {
        $this->db = Database::getInstance();
        $this->telegram = $telegram;
        $this->textService = new TextService();
    }

    /**
     * Start onboarding from question 0.
     */
    public function start(int $chatId, int $userId): void
    {
        $text = $this->textService->get('msg_onboarding_intro', "Чтобы я могла давать тебе точные рекомендации, мне нужны твои базовые настройки\.\n\nЭто займет 30 секунд, но сэкономит тебе часы жизни ✨");
        $this->telegram->sendMessage($chatId, $text);

        $this->sendQuestion($chatId, $userId, 0);
    }

    /**
     * Reset onboarding answers and restart the questionnaire (for profile editing).
     */
    public function resetAndRestart(int $chatId, int $userId): void
    {
        // Delete previous onboarding answers
        $onboardingQuestionIds = array_map(fn($q) => $q['id'], self::QUESTIONS);
        $placeholders = implode(',', array_map(fn($id) => "'{$id}'", $onboardingQuestionIds));
        $this->db->execute(
            "DELETE FROM quiz_answers WHERE user_id = :uid AND question_id IN ({$placeholders})",
            [':uid' => $userId]
        );

        // Reset onboarding flag
        $userService = new UserService();
        $userService->resetOnboarding($userId);

        // Send intro and start from question 0
        $text = $this->textService->get('msg_profile_edit_intro', "✏️ *Редактирование профиля*\n\nДавай обновим твои данные\. Ответь на несколько вопросов заново\.");
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
     * Handle free-text answers (e.g., weight/height, last period date).
     */
    public function handleTextAnswer(int $chatId, int $userId, string $text): void
    {
        // Determine which text question we're expecting
        $userService = new UserService();
        $user = $userService->findById($userId);
        $state = $user['state'] ?? '';

        // body_stats => save weight/height
        if ($state === 'onboard_body_stats') {
            $this->db->insert(
                'INSERT INTO quiz_answers (user_id, question_id, answer) VALUES (:uid, :qid, :answer)',
                [':uid' => $userId, ':qid' => 'body_stats', ':answer' => $text]
            );

            // Move to next question (index 2)
            $this->sendQuestion($chatId, $userId, 2);
            return;
        }

        // last_period_date => validate & save date
        if ($state === 'onboard_last_period_date') {
            $dateValue = $this->parseLastPeriodDate($text);

            if ($dateValue === null) {
                $this->telegram->sendMessage($chatId, $this->textService->get('msg_onboarding_date_error', '❌ Не удалось распознать дату\. Напиши в формате ДД\.ММ \(например: 15\.03\) или «нет», если цикл отсутствует\.'));
                return;
            }

            $this->db->insert(
                'INSERT INTO quiz_answers (user_id, question_id, answer) VALUES (:uid, :qid, :answer)',
                [':uid' => $userId, ':qid' => 'last_period_date', ':answer' => $dateValue]
            );

            // last_period_date is question index 3 => next is index 4 which doesn't exist => finish
            $currentIndex = $this->getQuestionIndex('last_period_date');
            $nextIndex = $currentIndex + 1;

            if ($nextIndex < count(self::QUESTIONS)) {
                $this->sendQuestion($chatId, $userId, $nextIndex);
            } else {
                $this->finishOnboarding($chatId, $userId);
            }
            return;
        }
    }

    /**
     * Parse the last period date from user input.
     * Supports: "15.03", "15.03.2026", "2026-03-15", "нет"/"net"/"no".
     * Returns Y-m-d string or 'no_cycle'.
     */
    private function parseLastPeriodDate(string $input): ?string
    {
        $input = trim(mb_strtolower($input));

        // "нет", "no", "net" => no cycle
        if (in_array($input, ['нет', 'no', 'net', '-', 'нету'], true)) {
            return 'no_cycle';
        }

        // DD.MM or DD.MM.YYYY
        if (preg_match('/^(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?$/', $input, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = isset($m[3]) ? (int) $m[3] : (int) date('Y');
            if ($year < 100) {
                $year += 2000;
            }
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        // ISO format: YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $input, $m)) {
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $input;
            }
        }

        return null;
    }

    private function sendQuestion(int $chatId, int $userId, int $index): void
    {
        if ($index >= count(self::QUESTIONS)) {
            return;
        }

        $q = $this->getLocalizedQuestion($index);
        $header = $this->textService->get('msg_onboarding_header', '📋 *Настройка профиля*');
        $text = "{$header} \n\n {$q['text']}";

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

        // Send confirmation message
        $successText = $this->textService->get('ONBOARDING_FINISH_SUCCESS');
        if ($successText) {
            $this->telegram->sendMessage($chatId, $successText);
        }

        // Send main menu
        $this->sendMainMenu($chatId, $userId);
    }

    private function sendMainMenu(int $chatId, int $userId): void
    {
        $userService = new UserService();
        $user = $userService->findById($userId);
        $name = TelegramApi::escapeMarkdownV2($user['first_name'] ?? 'Подруга');
        $text = sprintf($this->textService->get('msg_main_menu_text', "Привет, %s\! ✨\n\nВыбери, с чего начнём сегодня:"), $name);

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => $this->textService->get('btn_mode_nutrition', '🥗 Питание'), 'callback_data' => 'mode_nutrition']],
            [['text' => $this->textService->get('btn_mode_cosmetics', '✨ Твой ИИ косметолог'), 'callback_data' => 'mode_cosmetics']],
            [['text' => $this->textService->get('btn_mode_beauty_assistant', '🤖 Beauty-ассистент'), 'callback_data' => 'mode_beauty_assistant']],
            [['text' => $this->textService->get('btn_mode_practices', '🧘‍♀️ Практики (доступ в Prime)'), 'callback_data' => 'mode_practices']],
            [['text' => $this->textService->get('btn_mode_profile', '👤 Мой профиль'), 'callback_data' => 'show_profile']],
        ]);

        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    private function getLocalizedQuestion(int $index): array
    {
        $q = self::QUESTIONS[$index];
        $q['text'] = $this->textService->get("onboard_q_{$q['id']}_text", $q['text']);
        
        if (isset($q['options'])) {
            foreach ($q['options'] as $key => $label) {
                $q['options'][$key] = $this->textService->get("onboard_q_{$q['id']}_opt_{$key}", $label);
            }
        }
        
        return $q;
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
