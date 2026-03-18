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
        $this->http = new Client(['timeout' => 30]);
    }

    // ─── Sending Messages ────────────────────────────────────────

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null, string $parseMode = 'HTML'): array
    {
        $params = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => $parseMode,
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->request('sendMessage', $params);
    }

    public function sendDocument(int|string $chatId, string $filePath, ?string $caption = null): array
    {
        $multipart = [
            ['name' => 'chat_id', 'contents' => (string) $chatId],
            ['name' => 'document', 'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)],
        ];
        if ($caption) {
            $multipart[] = ['name' => 'caption', 'contents' => $caption];
        }

        $response = $this->http->post("{$this->baseUrl}/sendDocument", [
            'multipart' => $multipart,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function sendPhoto(int|string $chatId, string $filePath, ?string $caption = null): array
    {
        $multipart = [
            ['name' => 'chat_id', 'contents' => (string) $chatId],
            ['name' => 'photo', 'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)],
        ];
        if ($caption) {
            $multipart[] = ['name' => 'caption', 'contents' => $caption];
            $multipart[] = ['name' => 'parse_mode', 'contents' => 'HTML'];
        }

        $response = $this->http->post("{$this->baseUrl}/sendPhoto", [
            'multipart' => $multipart,
        ]);

        return json_decode($response->getBody()->getContents(), true);
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
            'parse_mode' => 'HTML',
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

    // ─── Low-level ───────────────────────────────────────────────

    private function request(string $method, array $params = []): array
    {
        try {
            $response = $this->http->post("{$this->baseUrl}/{$method}", [
                'form_params' => $params,
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (\Throwable $e) {
            Logger::getInstance()->error("Telegram API error: {$method}", [
                'error'  => $e->getMessage(),
                'params' => $params,
            ]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
