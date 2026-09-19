<?php

namespace App\Services;

use App\Support\ApiKeyResolver;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * Appels HTTP bruts vers Gemini / DeepSeek — ou **rejeu local** lorsque `LLM_DRIVER=replay`.
 *
 * Options (toutes facultatives, rétro-compatible avec l'ancienne signature) :
 *  - `json_mode`   (bool)   : Gemini `generationConfig.response_mime_type=application/json`,
 *                             DeepSeek `response_format {type: json_object}`.
 *  - `temperature` (float)
 *  - `max_tokens`  (int)    : Gemini `maxOutputTokens`, DeepSeek `max_tokens`.
 *  - `model`       (string) : défaut `config('services.<provider>.model')`.
 *  - `timeout`     (int)    : secondes, défaut 60.
 *  - `prompt_name` (string) : **obligatoire en mode rejeu** — nom du prompt système appelé, qui désigne
 *                             le dossier de `storage/app/llm-replay/` (voir `LlmReplayProvider`).
 *  - `replay_vars` (array)  : paramètres de sélection du rejeu (`question_key`, `heading`, `target_lang`…).
 */
class LlmProviderService
{
    public const DEFAULT_TIMEOUT = 60;

    /** Appels réels vers le fournisseur configuré. */
    public const DRIVER_LIVE = 'live';

    /** Rejeu local, sans réseau ni clé API (développement / démonstration seulement). */
    public const DRIVER_REPLAY = 'replay';

    public function __construct(private readonly ?LlmReplayProvider $replay = null) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function generate(string $provider, string $apiKey, array $messages, ?string $systemInstruction = null, array $options = []): string
    {
        if (self::isReplay()) {
            return ($this->replay ?? new LlmReplayProvider)->generate($messages, $systemInstruction, $options);
        }

        $provider = ApiKeyResolver::normalizeProvider($provider);

        if ($provider === ApiKeyResolver::DEEPSEEK) {
            return $this->callDeepSeek($apiKey, $messages, $systemInstruction, $options);
        }

        return $this->callGemini($apiKey, $messages, $systemInstruction, $options);
    }

    // ------------------------------------------------------------------ pilote

    public static function driver(): string
    {
        return strtolower((string) (config('services.llm.driver') ?: self::DRIVER_LIVE));
    }

    public static function isReplay(): bool
    {
        if (self::driver() !== self::DRIVER_REPLAY) {
            return false;
        }

        // Le garde-fou de production lève ici, avant toute écriture en base.
        LlmReplayProvider::assertAllowed();

        return true;
    }

    /**
     * Fournisseur à **inscrire** (`ai_jobs.provider`, `survey_reports.provider`) : `replay` en rejeu,
     * sinon le fournisseur demandé, normalisé.
     */
    public static function effectiveProvider(?string $provider): string
    {
        return self::isReplay() ? LlmReplayProvider::PROVIDER : ApiKeyResolver::normalizeProvider($provider);
    }

    /**
     * Modèle à **inscrire** : `replay` en rejeu, sinon le modèle configuré du fournisseur.
     */
    public static function effectiveModel(?string $provider): string
    {
        return self::isReplay() ? LlmReplayProvider::PROVIDER : self::defaultModel((string) $provider);
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
