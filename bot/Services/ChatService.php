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
    private TextService $textService;

    public function __construct(TelegramApi $telegram)
    {
        $this->db = Database::getInstance();
        $this->openai = new OpenAiApi();
        $this->telegram = $telegram;
        $this->textService = new TextService();
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

        $reply = $this->callWithThinkingMessage(function() use ($context) {
            return $this->openai->chat($context, 0.7, 1024);
        }, $chatId);

        if (empty(trim($reply))) {
            \App\Core\Logger::getInstance()->error("Empty response from OpenAI (likely reasoning tokens limit reached)");
            $this->telegram->sendMessage($chatId, $this->textService->get('msg_chat_error_empty', "Извини, я слишком глубоко задумалась и не успела ответить 🥺 Попробуй еще раз!", true));
            return;
        }

        // Save assistant message
        $this->saveMessage($userId, 'assistant', $reply, $mode);

        // Send reply
        $this->telegram->sendChatAction($chatId, 'typing');
        $this->telegram->sendMessage($chatId, $this->ensureMarkdownV1($reply), null, 'Markdown');

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
        $prompt = $caption ?: $this->textService->get('msg_chat_vision_prompt_default', 'Что ты видишь на этом фото? Проанализируй с учётом моего профиля.', true);
        $systemPrompt = $this->buildSystemPrompt($userId, $mode);

        $reply = $this->callWithThinkingMessage(function() use ($imageData, $prompt, $systemPrompt) {
            return $this->openai->vision($imageData, 'image/jpeg', $prompt, $systemPrompt);
        }, $chatId);

        if (empty(trim($reply))) {
            \App\Core\Logger::getInstance()->error("Empty vision response from OpenAI");
            $this->telegram->sendMessage($chatId, $this->textService->get('msg_chat_vision_error', "Не удалось проанализировать фото 😔 Попробуй ещё раз!", true));
            return;
        }

        // Save messages
        $userMsgText = $caption ? "[Фото] {$caption}" : '[Фото]';
        $this->saveMessage($userId, 'user', $userMsgText, $mode, 'photo');
        $this->saveMessage($userId, 'assistant', $reply, $mode);

        $this->telegram->sendChatAction($chatId, 'typing');
        $this->telegram->sendMessage($chatId, $this->ensureMarkdownV1($reply), null, 'Markdown');

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
            $this->telegram->sendMessage($chatId, $this->textService->get('msg_chat_voice_error', 'Не удалось распознать голосовое сообщение 😔 Попробуй ещё раз или напиши текстом.', true));
            return;
        }

        // Process as text message
        $this->handleMessage($chatId, $userId, $text, 'voice');
    }

    /**
     * Execute a callback, sending a "thinking" message if it takes longer than 5 seconds.
     */
    private function callWithThinkingMessage(callable $callback, int $chatId): string
    {
        if (!function_exists('pcntl_fork')) {
            return $callback();
        }

        $pid = pcntl_fork();
        if ($pid == -1) {
            return $callback();
        }

        if ($pid == 0) {
            // Child: wait 5 seconds
            sleep(5);
            $this->telegram->sendMessage($chatId, $this->textService->get('msg_chat_thinking', "Я думаю дольше чем обычно. Подожди еще пожалуйста.", true));
            exit(0);
        }

        // Parent
        try {
            $result = $callback();
        } finally {
            // Kill the child
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }

        return $result;
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
        // 1. Base system prompt
        $base = $this->textService->get('prompt_base_system', '', true);

        // 2. Mode-specific prompt
        $modePromptKey = match ($mode) {
            'nutrition'        => 'prompt_nutrition_system',
            'cosmetics'        => 'prompt_cosmetics_system',
            'beauty_assistant' => 'prompt_beauty_assistant_system',
            'practices'        => 'prompt_practices_system',
            default            => 'prompt_nutrition_system',
        };
        $modePrompt = $this->textService->get($modePromptKey, '', true);

        // 3. User profile
        $profileService = new ProfileService();
        $profileJson = $profileService->getProfileJson($userId);

        // 4. Conversation summary
        $summaryService = new SummaryService();
        $summary = $summaryService->getSummary($userId, $mode);

        // Compose
        $systemPrompt = $base . "\n\n---\n\n" . $modePrompt;
        $systemPrompt = str_replace('{{USER_PROFILE_JSON}}', $profileJson, $systemPrompt);
        $systemPrompt = str_replace('{{CONVERSATION_SUMMARY}}', $summary ?: $this->textService->get('msg_chat_no_summary', '(Нет предыдущего резюме)', true), $systemPrompt);

        // Add instruction about markdown
        $systemPrompt .= "\n\nВАЖНО: Для выделения текста (жирный) используй только одну звездочку: *жирный*, не используй двойные **.";

        return $systemPrompt;
    }

    /**
     * Convert standard Markdown to Telegram Markdown V1 and fix common issues.
     */
    private function ensureMarkdownV1(string $text): string
    {
        return $text;
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
