<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\OpenAiApi;
use App\Core\Config;

class ProfileService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Build initial profile_json from quiz + onboarding answers.
     */
    public function buildInitialProfile(int $userId): void
    {
        $userService = new UserService();
        $user = $userService->findById($userId);

        // Gather all quiz answers
        $answers = $this->db->fetchAll(
            'SELECT question_id, answer, answer_index FROM quiz_answers WHERE user_id = :uid',
            [':uid' => $userId]
        );

        $answerMap = [];
        $quizScores = [];
        foreach ($answers as $a) {
            $answerMap[$a['question_id']] = $a['answer'];
            if ($a['answer_index'] !== null) {
                $quizScores[$a['question_id']] = (int) $a['answer_index'];
            }
        }

        // Parse weight/height
        $bodyStats = $answerMap['body_stats'] ?? '';
        $weight = null;
        $height = null;
        $bmi = null;
        if (preg_match('/(\d+)\s*[\/\-]\s*(\d+)/u', $bodyStats, $m)) {
            $weight = (float) $m[1];
            $height = (float) $m[2];
            if ($height > 0) {
                $bmi = round($weight / (($height / 100) ** 2), 1);
            }
        }

        $profile = [
            'persona'       => $user['persona'] ?? null,
            'persona_label' => PersonaService::getPersonaLabel($user['persona'] ?? ''),
            'quiz_scores'   => $quizScores,
            'goal'          => $answerMap['goal'] ?? null,
            'weight_kg'     => $weight,
            'height_cm'     => $height,
            'bmi'           => $bmi,
            'health_features' => $answerMap['health_features'] ?? null,
            'cycle_phase'   => $answerMap['cycle_phase'] ?? null,
            'known_facts'   => [],
            'last_profile_update_at' => date('c'),
        ];

        $json = json_encode($profile, JSON_UNESCAPED_UNICODE);

        // Upsert
        $existing = $this->db->fetchOne('SELECT id FROM user_profiles WHERE user_id = :uid', [':uid' => $userId]);
        if ($existing) {
            $this->db->execute(
                'UPDATE user_profiles SET profile_json = :json, version = version + 1 WHERE user_id = :uid',
                [':json' => $json, ':uid' => $userId]
            );
        } else {
            $this->db->insert(
                'INSERT INTO user_profiles (user_id, profile_json) VALUES (:uid, :json)',
                [':uid' => $userId, ':json' => $json]
            );
        }
    }

    /**
     * Get the user profile JSON as array.
     */
    public function getProfile(int $userId): ?array
    {
        $row = $this->db->fetchOne('SELECT profile_json FROM user_profiles WHERE user_id = :uid', [':uid' => $userId]);
        if (!$row) {
            return null;
        }
        return json_decode($row['profile_json'], true);
    }

    /**
     * Get raw profile JSON string.
     */
    public function getProfileJson(int $userId): string
    {
        $row = $this->db->fetchOne('SELECT profile_json FROM user_profiles WHERE user_id = :uid', [':uid' => $userId]);
        return $row['profile_json'] ?? '{}';
    }

    /**
     * AI-driven profile update from last N messages (called every 10 messages).
     */
    public function updateFromHistory(int $userId): void
    {
        $openai = new OpenAiApi();

        // Get last 10 messages
        $messages = $this->db->fetchAll(
            'SELECT role, content FROM messages WHERE user_id = :uid ORDER BY created_at DESC LIMIT 10',
            [':uid' => $userId]
        );
        $messages = array_reverse($messages);

        if (empty($messages)) {
            return;
        }

        $currentProfile = $this->getProfileJson($userId);

        // Load prompt template
        $promptTemplate = file_get_contents(Config::getProjectRoot() . '/prompts/profile_update.md');
        $promptTemplate = str_replace('{{CURRENT_PROFILE_JSON}}', $currentProfile, $promptTemplate);

        $historyText = '';
        foreach ($messages as $m) {
            $role = $m['role'] === 'user' ? 'Пользователь' : 'Бот';
            $historyText .= "{$role}: {$m['content']}\n";
        }
        $promptTemplate = str_replace('{{LAST_10_MESSAGES}}', $historyText, $promptTemplate);
        $promptTemplate = str_replace('{{CURRENT_DATETIME}}', date('c'), $promptTemplate);

        $aiMessages = [
            ['role' => 'system', 'content' => 'Ты — аналитическая система. Верни только валидный JSON.'],
            ['role' => 'user', 'content' => $promptTemplate],
        ];

        $newJson = $openai->chatJson($aiMessages, 0.3, 2048);

        // Validate JSON
        $decoded = json_decode($newJson, true);
        if (!$decoded) {
            return;
        }

        $this->db->execute(
            'UPDATE user_profiles SET profile_json = :json, version = version + 1 WHERE user_id = :uid',
            [':json' => $newJson, ':uid' => $userId]
        );
    }
}
