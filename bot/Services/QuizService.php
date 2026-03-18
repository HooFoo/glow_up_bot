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
            // Flexible regex to handle different line endings and spacing
            $pattern = '/## Вопрос \d+: ' . preg_quote($qId, '/') . '\s+\*\*Текст:\*\*\s+(.+?)\s+\*\*Варианты:\*\*\s+((?:\d+\..*(?:[\r\n]+|$))+)/u';
            
            if (preg_match($pattern, $content, $m)) {
                $text = trim($m[1]);
                $optionsRaw = trim($m[2]);
                $options = [];
                
                preg_match_all('/(\d+)\.\s+(.+)/u', $optionsRaw, $optMatches, PREG_SET_ORDER);
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
        // Question IDs contain underscores (e.g. morning_routine), so we
        // strip the 'quiz_' prefix and extract the answer index from the end
        $rest = substr($data, 5); // remove 'quiz_' prefix
        $lastUnderscore = strrpos($rest, '_');
        if ($lastUnderscore === false) {
            return;
        }

        $questionId = substr($rest, 0, $lastUnderscore);
        $answerIndex = (int) substr($rest, $lastUnderscore + 1);

        // Find the answer text
        if (empty($this->questions)) {
            $this->loadQuestions();
        }
        
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
        if ($currentIndex === false) {
            return;
        }
        
        $nextIndex = $currentIndex + 1;

        if ($nextIndex < count(self::QUESTION_IDS)) {
            $this->sendQuestion($chatId, $userId, $nextIndex);
        } else {
            $this->finishQuiz($chatId, $userId);
        }
    }

    private function sendQuestion(int $chatId, int $userId, int $index): void
    {
        if (empty($this->questions)) {
            $this->loadQuestions();
        }

        $qId = self::QUESTION_IDS[$index] ?? null;
        if (!$qId) return;
        
        $question = $this->questions[$qId] ?? null;

        if (!$question) {
            return;
        }

        $questionNumber = $index + 1;
        $total = count(self::QUESTION_IDS);
        
        // Formatting with HTML as the project standard
        $text = "❓ <b>Вопрос {$questionNumber}/{$total}</b>\n";
        $text .= "<b>«" . htmlspecialchars($question['text']) . "»</b>\n\n";
        
        $buttons = [];
        $row = [];
        foreach ($question['options'] as $idx => $optText) {
            // Extract "Title" part if it's in quotes or separated by colon/dot
            $titleText = '';
            $remainingText = '';

            if (preg_match('/^[«"](.+?)[»"][\s\:]*(.*)$/u', $optText, $m)) {
                $titleText = $m[1];
                $remainingText = $m[2];
            } elseif (preg_match('/^(.+?)[.\:—]\s*(.*)$/u', $optText, $m)) {
                $titleText = $m[1];
                $remainingText = $m[2];
            } else {
                $titleText = $optText;
                $remainingText = '';
            }

            // Body: "1. «Title» — Description"
            $text .= "{$idx}. <b>«{$titleText}»</b>";
            if ($remainingText) {
                // If the remaining text doesn't start with a separator, add one
                $separator = (str_starts_with($remainingText, ':') || str_starts_with($remainingText, '—')) ? '' : ' — ';
                $text .= $separator . htmlspecialchars($remainingText);
            }
            $text .= "\n";
            
            // Button label: only the title
            $label = $titleText;
            
            $row[] = ['text' => $label, 'callback_data' => "quiz_{$qId}_{$idx}"];
            
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
        @set_time_limit(180); // Ensure the script has enough time for the simulation

        // 1. Send "Processing" message
        $msg = $this->telegram->sendMessage($chatId, "⏳ <b>Обрабатываю твои ответы...</b>\n\nМой ИИ-алгоритм анализирует твое текущее состояние и формирует персональный Glow-архетип.\n\nЭто займет около 2 минут...");
        $processingMessageId = $msg['result']['message_id'] ?? null;

        // 2. Wait for 120 seconds
        // Note: webhook.php should support background processing via fastcgi_finish_request() and ignore_user_abort(true)
        sleep(120);

        // 3. Delete the processing message
        if ($processingMessageId) {
            $this->telegram->deleteMessage($chatId, $processingMessageId);
        }

        // 4. Calculate persona and send result
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
