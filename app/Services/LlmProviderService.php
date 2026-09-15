<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class LlmProviderService
{
    public function generate(string $provider, string $apiKey, array $messages, string $systemInstruction = null): string
    {
        if ($provider === 'deepseek') {
            return $this->callDeepSeek($apiKey, $messages, $systemInstruction);
        } else {
            return $this->callGemini($apiKey, $messages, $systemInstruction);
        }
    }

    private function callDeepSeek(string $apiKey, array $messages, ?string $systemInstruction): string
    {
        $apiMessages = [];
        if ($systemInstruction) {
            $apiMessages[] = ["role" => "system", "content" => $systemInstruction];
        }

        foreach ($messages as $msg) {
            $apiMessages[] = [
                "role" => $msg['role'] === 'user' ? 'user' : 'assistant',
                "content" => $msg['content']
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->post("https://api.deepseek.com/v1/chat/completions", [
            "model" => "deepseek-chat",
            "messages" => $apiMessages,
            "stream" => false
        ]);

        if ($response->failed()) {
            throw new Exception('Erreur API DeepSeek: ' . $response->body());
        }

        $data = $response->json();
        return $data['choices'][0]['message']['content'] ?? '';
    }

    private function callGemini(string $apiKey, array $messages, ?string $systemInstruction): string
    {
        $contents = [];
        foreach ($messages as $msg) {
            $contents[] = [
                "role" => $msg['role'] === 'user' ? 'user' : 'model',
                "parts" => [["text" => $msg['content']]]
            ];
        }

        $payload = ["contents" => $contents];
        if ($systemInstruction) {
            $payload["system_instruction"] = [
                "parts" => [["text" => $systemInstruction]]
            ];
        }

        $response = Http::post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}",
            $payload
        );

        if ($response->failed()) {
            throw new Exception('Erreur API Gemini: ' . $response->body());
        }

        $data = $response->json();
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }
}
