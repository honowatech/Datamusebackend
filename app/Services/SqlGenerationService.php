<?php

namespace App\Services;

use App\Models\SystemPrompt;

class SqlGenerationService
{
    protected $llmProvider;

    public function __construct(LlmProviderService $llmProvider)
    {
        $this->llmProvider = $llmProvider;
    }

    public function generate(string $category, string $userPrompt, array $history, array $schemaData, string $driver, string $provider, string $apiKey, array $businessMetrics = []): array
    {
        // 1. Select the right System Prompt based on category
        $promptName = 'sql_simple'; // Default
        if ($category === 'COMPLEXE') {
            $promptName = 'sql_complexe';
        } elseif ($category === 'ANALYSE') {
            $promptName = 'sql_analyse';
        }

        $systemPromptConfig = SystemPrompt::where('name', $promptName)->first();
        if (!$systemPromptConfig) {
            throw new \Exception("Prompt système '{$promptName}' introuvable en base de données.");
        }

        $systemInstruction = $systemPromptConfig->content;

        // 2. Format Schema
        $schemaText = $this->formatSchemaText($schemaData);

        // Append Business Metrics glossary if any
        if (!empty($businessMetrics)) {
            $schemaText .= "\n═══ GLOSSAIRE MÉTIER ═══\n";
            foreach ($businessMetrics as $metric) {
                $schemaText .= "- \"{$metric['term']}\" = {$metric['sql_definition']}\n";
            }
        }

        // 3. Inject variables into System Prompt
        $systemInstruction = str_replace('{driver}', $driver, $systemInstruction);
        $systemInstruction = str_replace('{schemaText}', $schemaText, $systemInstruction);

        // 4. Build messages array
        $messages = [];
        foreach ($history as $msg) {
            $messages[] = [
                "role" => $msg['role'] === 'user' ? 'user' : 'assistant', // LlmProvider maps 'assistant' to 'model' for Gemini
                "content" => $msg['content']
            ];
        }
        $messages[] = ["role" => "user", "content" => $userPrompt];

        // 5. Call LLM (`prompt_name` : trace, et clé de sélection du mode rejeu)
        $aiResponse = $this->llmProvider->generate($provider, $apiKey, $messages, $systemInstruction, [
            'prompt_name' => $promptName,
            'replay_vars' => ['category' => $category, 'driver' => $driver],
        ]);

        // 6. Clean and parse JSON response
        return $this->parseLlmResponse($aiResponse);
    }

    private function formatSchemaText(array $schemaData): string
    {
        $schemaDetails = $schemaData['schema_details'] ?? null;
        $tableCounts = $schemaData['table_counts'] ?? null;
        $schemaText = '';

        if ($schemaDetails) {
            foreach ($schemaDetails as $tableName => $columns) {
                $rowCount = $tableCounts[$tableName] ?? '?';
                $schemaText .= "TABLE `{$tableName}` ({$rowCount} lignes) :\n";
                foreach ($columns as $col) {
                    $nullable = $col['nullable'] ? 'NULLABLE' : 'NOT NULL';
                    $autoIncrement = ($col['name'] === 'id') ? ' AUTO_INCREMENT' : '';
                    $line = "  - `{$col['name']}` {$col['type']} {$nullable}{$autoIncrement}";
                    if (!empty($col['sample_values'])) {
                        $vals = array_map(function($v) { return "'{$v}'"; }, $col['sample_values']);
                        $line .= "  -- valeurs : " . implode(', ', $vals);
                    }
                    $schemaText .= $line . "\n";
                }
                $schemaText .= "\n";
            }
        } else {
            $schemaText = "Tables : " . implode(', ', $schemaData['tables'] ?? []) . "\n";
        }

        return $schemaText;
    }

    private function parseLlmResponse(string $aiResponse): array
    {
        $aiResponse = trim($aiResponse);
        $aiResponse = preg_replace('/```json\n?/', '', $aiResponse);
        $aiResponse = preg_replace('/```[a-zA-Z]*\n?/', '', $aiResponse);
        $aiResponse = preg_replace('/```/', '', $aiResponse);
        $aiResponse = trim($aiResponse);

        $decoded = json_decode($aiResponse, true);
        if (!$decoded || !isset($decoded['type'])) {
            throw new \Exception("Format de réponse IA invalide. Brut: " . $aiResponse);
        }

        return $decoded;
    }
}
