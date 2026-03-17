<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TelegramApi;
use App\Core\Config;

class QuizService
{
    private Database $db;
    private TelegramApi $telegram;
    private array $questions = [];

    // Question IDs в порядке отправки
    private const QUESTION_IDS = [
        'morning_routine',
        'nutrition_state',
        'energy_evening',
        'self_care_logic',
        'pain_point',
        'automation_attitude',
        'body_relationship',
    ];

    public function __construct(TelegramApi $telegram)
    {
        $this->db = Database::getInstance();
        $this->telegram = $telegram;
        $this->loadQuestions();
    }

    /**
     * Parse quiz/questions.md into structured array.
     */
    private function loadQuestions(): void
    {
        $path = Config::getProjectRoot() . '/quiz/questions.md';
        $content = file_get_contents($path);

        foreach (self::QUESTION_IDS as $qId) {
            if (preg_match('/## Вопрос \d+: ' . $qId . '\n\*\*Текст:\*\* (.+?)\n\n\*\*Варианты:\*\*\n((?:\d+\..+\n?)+)/u', $content, $m)) {
                $text = trim($m[1]);
                $optionsRaw = trim($m[2]);
                $options = [];
                preg_match_all('/(\d+)\. (.+)/u', $optionsRaw, $optMatches, PREG_SET_ORDER);
                foreach ($optMatches as $opt) {
                    $options[(int) $opt[1]] = trim($opt[2]);
                }
                $this->questions[$qId] = [
                    'id'      => $qId,
                    'text'    => $text,
                    'options' => $options,
                ];
            }
        }
    }

    /**
     * Start the quiz: send the first question.
     */
    public function start(int $chatId, int $userId): void
    {
        $this->sendQuestion($chatId, $userId, 0);
    }

    /**
     * Handle an answer callback and send next question or finish.
     */
    public function handleAnswer(int $chatId, int $userId, string $data): void
    {
        // data format: quiz_{questionId}_{answerIndex}
        $parts = explode('_', $data, 3);
        if (count($parts) < 3) {
            return;
        }

        $questionId = $parts[1];
        $answerIndex = (int) $parts[2];

        // Find the answer text
        $question = $this->questions[$questionId] ?? null;
        if (!$question) {
            return;
        }
        $answerText = $question['options'][$answerIndex] ?? "Ответ {$answerIndex}";

        // Save answer
        $this->db->insert(
            'INSERT INTO quiz_answers (user_id, question_id, answer, answer_index) VALUES (:uid, :qid, :answer, :idx)',
            [':uid' => $userId, ':qid' => $questionId, ':answer' => $answerText, ':idx' => $answerIndex]
        );

        // Find next question index
        $currentIndex = array_search($questionId, self::QUESTION_IDS);
        $nextIndex = $currentIndex + 1;

        if ($nextIndex < count(self::QUESTION_IDS)) {
            $this->sendQuestion($chatId, $userId, $nextIndex);
        } else {
            $this->finishQuiz($chatId, $userId);
        }
    }

    private function sendQuestion(int $chatId, int $userId, int $index): void
    {
        $qId = self::QUESTION_IDS[$index];
        $question = $this->questions[$qId] ?? null;

        if (!$question) {
            return;
        }

        $questionNumber = $index + 1;
        $total = count(self::QUESTION_IDS);
        
        $text = "❓ <b>Вопрос {$questionNumber}/{$total}</b>\n\n";
        $text .= "<b>{$question['text']}</b>\n\n";
        
        $buttons = [];
        $row = [];
        foreach ($question['options'] as $idx => $optText) {
            // Add full text to the message body
            $text .= "{$idx}. {$optText}\n";
            
            // Extract a short title if possible (e.g. from "Title: Description")
            $label = (string)$idx;
            if (preg_match('/«(.+?)»/u', $optText, $m)) {
                $label = "{$idx}. {$m[1]}";
            }
            
            $row[] = ['text' => $label, 'callback_data' => "quiz_{$qId}_{$idx}"];
            
            // If row has 2 buttons, add it and start new row
            if (count($row) === 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $buttons[] = $row;
        }

        $this->telegram->sendMessage($chatId, $text, TelegramApi::inlineKeyboard($buttons));

        // Update user state
        $userService = new UserService();
        $userService->updateState($userId, "quiz_q{$questionNumber}");
    }

    private function finishQuiz(int $chatId, int $userId): void
    {
        // Calculate persona
        $personaService = new PersonaService($this->telegram);
        $personaService->assignFromQuiz($chatId, $userId);
    }

    /**
     * Check if user has an unanswered quiz question pending.
     */
    public function getAnsweredCount(int $userId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) as cnt FROM quiz_answers WHERE user_id = :uid AND question_id IN (' .
            implode(',', array_map(fn($q) => "'{$q}'", self::QUESTION_IDS)) . ')',
            [':uid' => $userId]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    public function getQuestionIds(): array
    {
        return self::QUESTION_IDS;
    }
}
