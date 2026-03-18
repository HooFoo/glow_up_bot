<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\OpenAiApi;
use App\Core\TelegramApi;
use App\Core\Config;

class ChatService
{
    private Database $db;
    private OpenAiApi $openai;
    private TelegramApi $telegram;

    public function __construct(TelegramApi $telegram)
    {
        $this->db = Database::getInstance();
        $this->openai = new OpenAiApi();
        $this->telegram = $telegram;
    }

    /**
     * Process a text message in the current mode.
     */
    public function handleMessage(int $chatId, int $userId, string $text, string $mediaType = 'text'): void
    {
        $userService = new UserService();
        $user = $userService->findById($userId);
        $mode = $user['active_mode'] ?? 'nutrition';

        // Send typing indicator
        $this->telegram->sendChatAction($chatId, 'typing');

        // Save user message
        $this->saveMessage($userId, 'user', $text, $mode, $mediaType);

        // Build context and generate response
        $context = $this->buildContext($userId, $mode);
        $context[] = ['role' => 'user', 'content' => $text];

        $reply = $this->openai->chat($context, 0.7, 1024);

        // Save assistant message
        $this->saveMessage($userId, 'assistant', $reply, $mode);

        // Send reply
        $this->telegram->sendMessage($chatId, $reply, null, 'MarkdownV2');

        // Increment message count and check counters
        $newCount = $userService->incrementMessageCount($userId);
        $this->checkCounters($userId, $newCount, $mode);
    }

    /**
     * Process a photo message with optional caption.
     */
    public function handlePhoto(int $chatId, int $userId, string $fileId, ?string $caption = null): void
    {
        $userService = new UserService();
        $user = $userService->findById($userId);
        $mode = $user['active_mode'] ?? 'nutrition';

        $this->telegram->sendChatAction($chatId, 'typing');

        // Download photo
        $tmpFile = sys_get_temp_dir() . '/prime_photo_' . uniqid() . '.jpg';
        $this->telegram->downloadFile($fileId, $tmpFile);

        // Read and encode
        $imageData = base64_encode(file_get_contents($tmpFile));
        unlink($tmpFile);

        // Build vision prompt
        $prompt = $caption ?: 'Что ты видишь на этом фото? Проанализируй с учётом моего профиля.';
        $systemPrompt = $this->buildSystemPrompt($userId, $mode);

        $reply = $this->openai->vision($imageData, 'image/jpeg', $prompt, $systemPrompt);

        // Save messages
        $userMsgText = $caption ? "[Фото] {$caption}" : '[Фото]';
        $this->saveMessage($userId, 'user', $userMsgText, $mode, 'photo');
        $this->saveMessage($userId, 'assistant', $reply, $mode);

        $this->telegram->sendMessage($chatId, $reply, null, 'MarkdownV2');

        $newCount = $userService->incrementMessageCount($userId);
        $this->checkCounters($userId, $newCount, $mode);
    }

    /**
     * Process a voice message.
     */
    public function handleVoice(int $chatId, int $userId, string $fileId): void
    {
        $this->telegram->sendChatAction($chatId, 'typing');

        // Download audio
        $tmpFile = sys_get_temp_dir() . '/prime_voice_' . uniqid() . '.ogg';
        $this->telegram->downloadFile($fileId, $tmpFile);

        // Transcribe
        $text = $this->openai->transcribe($tmpFile);
        unlink($tmpFile);

        if (empty($text)) {
            $this->telegram->sendMessage($chatId, 'Не удалось распознать голосовое сообщение 😔 Попробуй ещё раз или напиши текстом.');
            return;
        }

        // Process as text message
        $this->handleMessage($chatId, $userId, $text, 'voice');
    }

    // ─── Context Building ────────────────────────────────────────

    private function buildContext(int $userId, string $mode): array
    {
        $systemPrompt = $this->buildSystemPrompt($userId, $mode);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Last 5 messages
        $history = $this->db->fetchAll(
            'SELECT role, content FROM messages WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5',
            [':uid' => $userId]
        );
        $history = array_reverse($history);

        foreach ($history as $m) {
            $messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        return $messages;
    }

    private function buildSystemPrompt(int $userId, string $mode): string
    {
        $root = Config::getProjectRoot();

        // 1. Base system prompt
        $base = file_get_contents($root . '/prompts/base_system.md');

        // 2. Mode-specific prompt
        $modeFile = match ($mode) {
            'nutrition'  => '/prompts/nutrition_system.md',
            'cosmetics'  => '/prompts/cosmetics_system.md',
            'coach'      => '/prompts/coach_system.md',
            default      => '/prompts/nutrition_system.md',
        };
        $modePrompt = file_get_contents($root . $modeFile);

        // 3. User profile
        $profileService = new ProfileService();
        $profileJson = $profileService->getProfileJson($userId);

        // 4. Conversation summary
        $summaryService = new SummaryService();
        $summary = $summaryService->getSummary($userId, $mode);

        // Compose
        $systemPrompt = $base . "\n\n---\n\n" . $modePrompt;
        $systemPrompt = str_replace('{{USER_PROFILE_JSON}}', $profileJson, $systemPrompt);
        $systemPrompt = str_replace('{{CONVERSATION_SUMMARY}}', $summary ?: '(Нет предыдущего резюме)', $systemPrompt);

        return $systemPrompt;
    }

    // ─── Message Storage ─────────────────────────────────────────

    private function saveMessage(int $userId, string $role, string $content, string $mode, string $mediaType = 'text'): void
    {
        $this->db->insert(
            'INSERT INTO messages (user_id, role, content, mode, media_type) VALUES (:uid, :role, :content, :mode, :media)',
            [':uid' => $userId, ':role' => $role, ':content' => $content, ':mode' => $mode, ':media' => $mediaType]
        );
    }

    // ─── Counter Checks ──────────────────────────────────────────

    private function checkCounters(int $userId, int $count, string $mode): void
    {
        // Every 5 messages — compress summary
        if ($count % 5 === 0) {
            $summaryService = new SummaryService();
            $summaryService->compress($userId, $mode);
        }

        // Every 10 messages — update profile
        if ($count % 10 === 0) {
            $profileService = new ProfileService();
            $profileService->updateFromHistory($userId);
        }
    }

    // ─── Admin queries ───────────────────────────────────────────

    public function getMessages(int $userId, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;
        return $this->db->fetchAll(
            'SELECT * FROM messages WHERE user_id = :uid ORDER BY created_at ASC LIMIT :limit OFFSET :offset',
            [':uid' => $userId, ':limit' => $perPage, ':offset' => $offset]
        );
    }

    public function getMessageCount(int $userId): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM messages WHERE user_id = :uid', [':uid' => $userId]);
        return (int) ($row['cnt'] ?? 0);
    }
}
