<?php

namespace App\Services;

use App\Support\ApiKeyResolver;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * Appels HTTP bruts vers Gemini / DeepSeek.
 *
 * Options (toutes facultatives, rétro-compatible avec l'ancienne signature) :
 *  - `json_mode`   (bool)   : Gemini `generationConfig.response_mime_type=application/json`,
 *                             DeepSeek `response_format {type: json_object}`.
 *  - `temperature` (float)
 *  - `max_tokens`  (int)    : Gemini `maxOutputTokens`, DeepSeek `max_tokens`.
 *  - `model`       (string) : défaut `config('services.<provider>.model')`.
 *  - `timeout`     (int)    : secondes, défaut 60.
 */
class LlmProviderService
{
    public const DEFAULT_TIMEOUT = 60;

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function generate(string $provider, string $apiKey, array $messages, ?string $systemInstruction = null, array $options = []): string
    {
        $provider = ApiKeyResolver::normalizeProvider($provider);

        if ($provider === ApiKeyResolver::DEEPSEEK) {
            return $this->callDeepSeek($apiKey, $messages, $systemInstruction, $options);
        }

        return $this->callGemini($apiKey, $messages, $systemInstruction, $options);
    }

    public static function defaultModel(string $provider): string
    {
        $provider = ApiKeyResolver::normalizeProvider($provider);
        $fallback = $provider === ApiKeyResolver::DEEPSEEK ? 'deepseek-chat' : 'gemini-1.5-flash';

        return (string) (config("services.{$provider}.model") ?: $fallback);
    }

    private function callDeepSeek(string $apiKey, array $messages, ?string $systemInstruction, array $options): string
    {
        $apiMessages = [];
        if ($systemInstruction) {
            $apiMessages[] = ['role' => 'system', 'content' => $systemInstruction];
        }

        foreach ($messages as $msg) {
            $apiMessages[] = [
                'role' => $msg['role'] === 'user' ? 'user' : 'assistant',
                'content' => $msg['content'],
            ];
        }

        $payload = [
            'model' => $options['model'] ?? self::defaultModel(ApiKeyResolver::DEEPSEEK),
            'messages' => $apiMessages,
            'stream' => false,
        ];

        if (! empty($options['json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }

        $response = Http::timeout($this->timeout($options))
            ->withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.deepseek.com/v1/chat/completions', $payload);

        if ($response->failed()) {
            throw new Exception('Erreur API DeepSeek: '.$response->body());
        }

        $data = $response->json();

        return $data['choices'][0]['message']['content'] ?? '';
    }

    private function callGemini(string $apiKey, array $messages, ?string $systemInstruction, array $options): string
    {
        $contents = [];
        foreach ($messages as $msg) {
            $contents[] = [
                'role' => $msg['role'] === 'user' ? 'user' : 'model',
                'parts' => [['text' => $msg['content']]],
            ];
        }

        $payload = ['contents' => $contents];
        if ($systemInstruction) {
            $payload['system_instruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        $generationConfig = [];
        if (! empty($options['json_mode'])) {
            $generationConfig['response_mime_type'] = 'application/json';
        }
        if (isset($options['temperature'])) {
            $generationConfig['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = (int) $options['max_tokens'];
        }
        if ($generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        $model = $options['model'] ?? self::defaultModel(ApiKeyResolver::GEMINI);

        $response = Http::timeout($this->timeout($options))->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
            $payload
        );

        if ($response->failed()) {
            throw new Exception('Erreur API Gemini: '.$response->body());
        }

        $data = $response->json();

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function timeout(array $options): int
    {
        $timeout = (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);

        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
    }
}
