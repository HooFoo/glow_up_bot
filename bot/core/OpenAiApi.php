<?php

declare(strict_types=1);

namespace App\Core;

use GuzzleHttp\Client;

class OpenAiApi
{
    private Client $http;
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = Config::getOpenAiKey();
        $this->http = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout'  => 60,
            'headers'  => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    // ─── Chat Completion ─────────────────────────────────────────

    /**
     * Generate a text response.
     *
     * @param array $messages  Array of ['role' => ..., 'content' => ...]
     * @param float $temperature
     * @param int   $maxTokens
     * @return string  The assistant's reply text
     */
    public function chat(array $messages, float $temperature = 0.7, int $maxTokens = 1024): string
    {
        $body = [
            'model'       => Config::getOpenAiModel(),
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];

        $result = $this->post('chat/completions', $body);

        return $result['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Chat completion that returns raw JSON (для profile update / summary).
     */
    public function chatJson(array $messages, float $temperature = 0.3, int $maxTokens = 2048): string
    {
        $body = [
            'model'           => Config::getOpenAiModel(),
            'messages'        => $messages,
            'temperature'     => $temperature,
            'max_tokens'      => $maxTokens,
            'response_format' => ['type' => 'json_object'],
        ];

        $result = $this->post('chat/completions', $body);

        return $result['choices'][0]['message']['content'] ?? '{}';
    }

    // ─── Vision (image analysis) ─────────────────────────────────

    /**
     * Analyze an image with GPT-4o Vision.
     *
     * @param string      $imageBase64  Base64-encoded image
     * @param string      $mimeType     e.g. 'image/jpeg'
     * @param string      $prompt       Text prompt / question about the image
     * @param string|null $systemPrompt Optional system prompt
     * @return string
     */
    public function vision(string $imageBase64, string $mimeType, string $prompt, ?string $systemPrompt = null): string
    {
        $messages = [];

        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $messages[] = [
            'role'    => 'user',
            'content' => [
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:{$mimeType};base64,{$imageBase64}",
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
            ],
        ];

        $body = [
            'model'       => Config::getOpenAiModel(),
            'messages'    => $messages,
            'max_tokens'  => 1024,
            'temperature' => 0.7,
        ];

        $result = $this->post('chat/completions', $body);

        return $result['choices'][0]['message']['content'] ?? '';
    }

    // ─── Whisper STT ─────────────────────────────────────────────

    /**
     * Transcribe audio file to text.
     *
     * @param string $filePath  Absolute path to audio file
     * @return string  Transcribed text
     */
    public function transcribe(string $filePath): string
    {
        try {
            $response = $this->http->post('audio/transcriptions', [
                'headers'   => [
                    'Authorization' => "Bearer {$this->apiKey}",
                ],
                'multipart' => [
                    ['name' => 'model', 'contents' => Config::getWhisperModel()],
                    ['name' => 'file',  'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)],
                    ['name' => 'language', 'contents' => 'ru'],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['text'] ?? '';
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Whisper STT error', ['error' => $e->getMessage()]);
            return '';
        }
    }

    // ─── Low-level ───────────────────────────────────────────────

    private function post(string $endpoint, array $body): array
    {
        try {
            $response = $this->http->post($endpoint, [
                'json' => $body,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Throwable $e) {
            Logger::getInstance()->error("OpenAI API error: {$endpoint}", [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
