<?php

namespace App\Services;

use App\Models\SystemPrompt;

class LlmRouterService
{
    protected $llmProvider;

    public function __construct(LlmProviderService $llmProvider)
    {
        $this->llmProvider = $llmProvider;
    }

    /**
     * Determine the complexity category of the user's prompt.
     * Returns: SIMPLE, COMPLEXE, ANALYSE, EXPLICATION (default: SIMPLE)
     */
    public function categorize(string $userPrompt, string $provider, string $apiKey): string
    {
        $routerPromptConfig = SystemPrompt::where('name', 'router')->first();
        
        if (!$routerPromptConfig) {
            // Fallback default
            return 'SIMPLE';
        }

        $systemInstruction = $routerPromptConfig->content;
        
        $messages = [
            ["role" => "user", "content" => $userPrompt]
        ];

        try {
            // We can force using Gemini for the router if we want it to be fast and cheap,
            // but for now we'll stick to the user's chosen provider.
            $response = $this->llmProvider->generate($provider, $apiKey, $messages, $systemInstruction);
            
            $response = trim(strtoupper($response));
            
            if (str_contains($response, 'COMPLEXE')) return 'COMPLEXE';
            if (str_contains($response, 'ANALYSE')) return 'ANALYSE';
            if (str_contains($response, 'EXPLICATION')) return 'EXPLICATION';
            
            return 'SIMPLE';
        } catch (\Exception $e) {
            // In case of router failure, default to SIMPLE to not block the user
            return 'SIMPLE';
        }
    }
}
