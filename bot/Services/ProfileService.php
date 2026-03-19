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
            'last_period_date' => $answerMap['last_period_date'] ?? null,
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
     * Get the user profile JSON as array, with dynamically calculated cycle phase.
     */
    public function getProfile(int $userId): ?array
    {
        $row = $this->db->fetchOne('SELECT profile_json FROM user_profiles WHERE user_id = :uid', [':uid' => $userId]);
        if (!$row) {
            return null;
        }
        $profile = json_decode($row['profile_json'], true);

        // Dynamically compute cycle phase from last_period_date
        $lastPeriodDate = $profile['last_period_date'] ?? null;
        if ($lastPeriodDate && $lastPeriodDate !== 'no_cycle') {
            $cycleInfo = CycleService::calculatePhase($lastPeriodDate);
            $profile['cycle_phase'] = $cycleInfo ? $cycleInfo['label'] : null;
            $profile['cycle_day'] = $cycleInfo ? $cycleInfo['day'] : null;
            $profile['cycle_phase_key'] = $cycleInfo ? $cycleInfo['key'] : null;
        } else {
            $profile['cycle_phase'] = $lastPeriodDate === 'no_cycle' ? 'Цикл отсутствует' : null;
            $profile['cycle_day'] = null;
            $profile['cycle_phase_key'] = $lastPeriodDate === 'no_cycle' ? 'no_cycle' : null;
        }

        return $profile;
    }

    /**
     * Get raw profile JSON string, enriched with dynamic cycle data for AI prompts.
     */
    public function getProfileJson(int $userId): string
    {
        $profile = $this->getProfile($userId);
        if (!$profile) {
            return '{}';
        }
        return json_encode($profile, JSON_UNESCAPED_UNICODE);
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
        $textService = new TextService();
        $promptTemplate = $textService->get('prompt_profile_update');
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
