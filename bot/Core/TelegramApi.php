<?php

declare(strict_types=1);

namespace App\Core;

use GuzzleHttp\Client;

class TelegramApi
{
    private Client $http;
    private string $baseUrl;

    public function __construct()
    {
        $token = Config::getTelegramToken();
        $this->baseUrl = "https://api.telegram.org/bot{$token}";
        $this->http = new Client([
            'timeout' => 30,
            'http_errors' => false // Handle 4xx/5xx manually
        ]);
    }

    // ─── Sending Messages ────────────────────────────────────────

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null, string $parseMode = 'Markdown'): array
    {
        if (trim($text) === '') {
            return ['ok' => false, 'error' => 'Message text is empty'];
        }

        // Standard Markdown uses ** for bold, but Telegram (both V1 and V2) uses single *
        if ($parseMode === 'Markdown' || $parseMode === 'MarkdownV2') {
            $text = str_replace('**', '*', $text);
        }

        // If using legacy Markdown, remove V2-style escaping for characters that don't need it in V1
        // (like dots, hyphens, exclamation marks, etc.) to support texts migrated from V2.
        if ($parseMode === 'Markdown') {
            $text = preg_replace('/\\\\([.!+\-=|{}()>#~])/', '$1', $text);
        }

        $params = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => $parseMode,
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        $result = $this->request('sendMessage', $params);

        // Fallback: If sending failed (likely due to MarkdownV2/HTML formatting errors),
        // try sending the message as plain text (no parse_mode).
        if ($result['ok'] === false && str_contains($result['description'] ?? '', 'can\'t parse entities')) {
            $logger = \App\Core\Logger::getInstance();
            if ($logger) {
                $logger->warning("Telegram formatting error, falling back to plain text", [
                    'error' => $result['description'],
                    'chat_id' => $chatId
                ]);
            }
            unset($params['parse_mode']);
            return $this->request('sendMessage', $params);
        }

        return $result;
    }

    public function sendDocument(int|string $chatId, string $filePath, ?string $caption = null, ?array $replyMarkup = null): array
    {
        $multipart = [
            ['name' => 'chat_id', 'contents' => (string) $chatId],
            ['name' => 'document', 'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)],
        ];
        if ($caption) {
            $multipart[] = ['name' => 'caption', 'contents' => $caption];
            $multipart[] = ['name' => 'parse_mode', 'contents' => 'MarkdownV2'];
        }
        if ($replyMarkup) {
            $multipart[] = ['name' => 'reply_markup', 'contents' => json_encode($replyMarkup)];
        }

        $response = $this->http->post("{$this->baseUrl}/sendDocument", [
            'multipart' => $multipart,
        ]);

        $result = json_decode($response->getBody()->getContents(), true);

        if ($result['ok'] === false && str_contains($result['description'] ?? '', 'can\'t parse entities')) {
            // Retry without parse_mode
            $multipart = array_filter($multipart, fn($item) => $item['name'] !== 'parse_mode');
            $response = $this->http->post("{$this->baseUrl}/sendDocument", [
                'multipart' => $multipart,
            ]);
            return json_decode($response->getBody()->getContents(), true);
        }

        return $result;
    }

    public function sendPhoto(int|string $chatId, string $filePath, ?string $caption = null, ?array $replyMarkup = null): array
    {
        $multipart = [
            ['name' => 'chat_id', 'contents' => (string) $chatId],
            ['name' => 'photo', 'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)],
        ];
        if ($caption) {
            $multipart[] = ['name' => 'caption', 'contents' => $caption];
            $multipart[] = ['name' => 'parse_mode', 'contents' => 'MarkdownV2'];
        }
        if ($replyMarkup) {
            $multipart[] = ['name' => 'reply_markup', 'contents' => json_encode($replyMarkup)];
        }

        $response = $this->http->post("{$this->baseUrl}/sendPhoto", [
            'multipart' => $multipart,
        ]);

        $result = json_decode($response->getBody()->getContents(), true);

        if ($result['ok'] === false && str_contains($result['description'] ?? '', 'can\'t parse entities')) {
            // Retry without parse_mode
            $multipart = array_filter($multipart, fn($item) => $item['name'] !== 'parse_mode');
            $response = $this->http->post("{$this->baseUrl}/sendPhoto", [
                'multipart' => $multipart,
            ]);
            return json_decode($response->getBody()->getContents(), true);
        }

        return $result;
    }

    // ─── Chat Actions ────────────────────────────────────────────

    public function sendChatAction(int|string $chatId, string $action = 'typing'): array
    {
        return $this->request('sendChatAction', [
            'chat_id' => $chatId,
            'action'  => $action,
        ]);
    }

    // ─── Inline Keyboard Helpers ─────────────────────────────────

    /**
     * Build an inline keyboard markup from an array of button rows.
     * Each row is an array of ['text' => '...', 'callback_data' => '...'].
     */
    public static function inlineKeyboard(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    /**
     * Build button rows from a simple key => label map.
     * Creates one button per row.
     */
    public static function buttonColumn(array $buttons): array
    {
        $rows = [];
        foreach ($buttons as $callbackData => $text) {
            $rows[] = [['text' => $text, 'callback_data' => $callbackData]];
        }
        return $rows;
    }

    /**
     * Build a reply keyboard markup.
     */
    public static function replyKeyboard(array $rows, bool $resizeKeyboard = true, bool $oneTimeKeyboard = false): array
    {
        return [
            'keyboard'          => $rows,
            'resize_keyboard'   => $resizeKeyboard,
            'one_time_keyboard' => $oneTimeKeyboard,
        ];
    }

    // ─── Callback Query ──────────────────────────────────────────

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): array
    {
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text) {
            $params['text'] = $text;
        }
        return $this->request('answerCallbackQuery', $params);
    }

    // ─── Payments (Telegram Stars) ───────────────────────────────

    public function sendInvoice(
        int|string $chatId,
        string $title,
        string $description,
        string $payload,
        int $amount,
        string $currency = 'XTR'
    ): array {
        return $this->request('sendInvoice', [
            'chat_id'     => $chatId,
            'title'       => $title,
            'description' => $description,
            'payload'     => $payload,
            'currency'    => $currency,
            'prices'      => json_encode([['label' => $title, 'amount' => $amount]]),
        ]);
    }

    public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok = true, ?string $errorMessage = null): array
    {
        $params = [
            'pre_checkout_query_id' => $preCheckoutQueryId,
            'ok'                    => $ok,
        ];
        if (!$ok && $errorMessage) {
            $params['error_message'] = $errorMessage;
        }
        return $this->request('answerPreCheckoutQuery', $params);
    }

    // ─── File Download ───────────────────────────────────────────

    public function getFileUrl(string $fileId): string
    {
        $result = $this->request('getFile', ['file_id' => $fileId]);
        $filePath = $result['result']['file_path'] ?? '';
        $token = Config::getTelegramToken();
        return "https://api.telegram.org/file/bot{$token}/{$filePath}";
    }

    public function downloadFile(string $fileId, string $destPath): void
    {
        $url = $this->getFileUrl($fileId);
        $this->http->get($url, ['sink' => $destPath]);
    }

    // ─── Edit/Delete Message ─────────────────────────────────────
    
    public function editMessageText(int|string $chatId, int $messageId, string $text, ?array $replyMarkup = null): array
    {
        $params = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'MarkdownV2',
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->request('editMessageText', $params);
    }

    public function deleteMessage(int|string $chatId, int $messageId): array
    {
        return $this->request('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * Escape special characters for Telegram MarkdownV2.
     */
    public static function escapeMarkdownV2(mixed $text): string
    {
        $text = (string)($text ?? '');
        $search = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        $replace = ['\_', '\*', '\[', '\]', '\(', '\)', '\~', '\`', '\>', '\#', '\+', '\-', '\=', '\|', '\{', '\}', '\.', '\!'];
        return str_replace($search, $replace, $text);
    }

    // ─── Low-level ───────────────────────────────────────────────

    private function request(string $method, array $params = []): array
    {
        $requestId = bin2hex(random_bytes(4));
        $logger = Logger::getInstance();

        try {
            $response = $this->http->post("{$this->baseUrl}/{$method}", [
                'form_params' => $params,
            ]);
            
            $contents = $response->getBody()->getContents();
            $result = json_decode($contents, true) ?? ['ok' => false, 'description' => 'Invalid JSON response'];

            // Log Telegram Response (Debug)
            if ($logger) {
                $logger->debug("Telegram API [{$requestId}]: {$method}", [
                    'status' => $response->getStatusCode(),
                    'ok'     => $result['ok'] ?? false,
                    'error'  => $result['description'] ?? null,
                    'params' => $params
                ]);
            }

            // If not OK, but it's a known formatting error, we return result and let sendMessage handle fallback
            // We don't log it as an [ERROR] or [INFO] here yet because it's usually recovered in sendMessage.
            if (($result['ok'] ?? false) === false && str_contains($result['description'] ?? '', 'can\'t parse entities')) {
                $logger->debug("Telegram formatting error [{$requestId}]: {$result['description']}");
            } elseif (($result['ok'] ?? false) === false) {
                // Other business errors
                $logger->error("Telegram API Error [{$requestId}]: {$result['description']}", ['params' => $params]);
            }

            return $result;
        } catch (\Throwable $e) {
            $logger->error("Telegram Network/System Exception [{$requestId}]: {$method}", [
                'error'  => $e->getMessage(),
                'params' => $params,
            ]);
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }
}
