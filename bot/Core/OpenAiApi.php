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
            'model'    => Config::getOpenAiModel(),
            'messages' => $messages,
        ];

        $body = $this->addMaxTokens($body, $maxTokens);
        $body = $this->addTemperature($body, $temperature);

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
            'response_format' => ['type' => 'json_object'],
        ];

        $body = $this->addMaxTokens($body, $maxTokens);
        $body = $this->addTemperature($body, $temperature);

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
            'model'    => Config::getOpenAiModel(),
            'messages' => $messages,
        ];

        $body = $this->addMaxTokens($body, 1024);
        $body = $this->addTemperature($body, 0.7);

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

    // ─── Helpers ────────────────────────────────────────────────

    private function addMaxTokens(array $body, int $maxTokens): array
    {
        $model = $body['model'] ?? '';

        // Reasoning models (o1, o3) and newer generations (like the 'gpt-5' in logs) 
        // do not support 'max_tokens' anymore.
        $isReasoning = str_starts_with($model, 'o1') || str_starts_with($model, 'o3');
        $isUnknownOrNewGen = !str_starts_with($model, 'gpt-3.5') && !str_starts_with($model, 'gpt-4');

        if ($isReasoning || $isUnknownOrNewGen) {
            $body['max_completion_tokens'] = $maxTokens;
        } else {
            $body['max_tokens'] = $maxTokens;
        }

        return $body;
    }

    private function addTemperature(array $body, float $temperature): array
    {
        $model = $body['model'] ?? '';
        $isReasoning = str_starts_with($model, 'o1') || str_starts_with($model, 'o3');
        $isGpt5 = $model === 'gpt-5';

        if (!$isReasoning && !$isGpt5) {
            $body['temperature'] = $temperature;
        }

        return $body;
    }

    private function post(string $endpoint, array $body): array
    {
        $requestId = bin2hex(random_bytes(4));
        $logger = Logger::getInstance();
        $model = $body['model'] ?? 'unknown';

        try {
            // Log Request (Debug)
            $logger->debug("OpenAI Request [{$requestId}]", [
                'endpoint' => $endpoint,
                'model'    => $model,
                'body'     => $body
            ]);

            $response = $this->http->post($endpoint, [
                'json' => $body,
            ]);

            $contents = $response->getBody()->getContents();
            $result = json_decode($contents, true);

            // Log Response (Info)
            $tokens = $result['usage'] ?? [];
            $logger->info("OpenAI Response [{$requestId}]", [
                'status' => $response->getStatusCode(),
                'model'  => $result['model'] ?? $model,
                'tokens' => [
                    'prompt'     => $tokens['prompt_tokens'] ?? 0,
                    'completion' => $tokens['completion_tokens'] ?? 0,
                    'total'      => $tokens['total_tokens'] ?? 0
                ]
            ]);

            // Log Response (Debug)
            $logger->debug("OpenAI Raw Response [{$requestId}]", [
                'body' => $result
            ]);

            return $result;
        } catch (\Throwable $e) {
            $logger->error("OpenAI API error [{$requestId}]: {$endpoint}", [
                'error' => $e->getMessage(),
                'body'  => $body,
            ]);
            return [];
        }
    }
}
