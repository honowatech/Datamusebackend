<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

/**
 * Fournisseur IA « rejeu » (développement et démonstration uniquement).
 *
 * Il ne fait **aucun appel réseau** : la réponse est lue dans `storage/app/llm-replay/{prompt_name}/`
 * (`config('services.llm.replay_path')`). Chaque dossier porte un `index.json` qui décrit les règles de
 * sélection ; les réponses elles-mêmes sont des fichiers `*.json` (JSON rendu tel quel) ou `*.md`
 * (markdown rendu tel quel).
 *
 * ```jsonc
 * {
 *   "prompt": "verbatim_classify",
 *   "rules": [
 *     { "id": "munago-freins",
 *       "when": { "vars": { "question_key": "q6_freins" }, "contains": ["…"], "regex": "…" },
 *       "response": "q6_freins.json",
 *       "figures": { "n_valides": ["dont (\\d+) valides", "54"] },
 *       "transform": "verbatim_classify" }
 *   ],
 *   "default": { "response": "default.json", "transform": "verbatim_classify" }
 * }
 * ```
 *
 * Sélection : le **nom du prompt** donne le dossier (`$options['prompt_name']`, transmis par
 * `SurveyAiService`, `SqlGenerationService`, `LlmRouterService` et `ChatController`), puis la première
 * règle dont toutes les conditions `when` sont vraies l'emporte ; à défaut, `default`.
 *
 * Conditions disponibles dans `when` (toutes facultatives, combinées en **et**) :
 *   - `vars`        : `{clé: valeur | [valeurs]}` comparé à `$options['replay_vars']` (insensible à la casse) ;
 *   - `contains`    : liste de fragments **tous** présents dans le message utilisateur ;
 *   - `any_contains`: liste de fragments dont **au moins un** est présent ;
 *   - `regex`       : motif appliqué au message utilisateur ;
 *   - `system`      : liste de fragments **tous** présents dans l'instruction système.
 *
 * Deux traitements facultatifs s'appliquent ensuite au contenu du fichier :
 *   1. `figures` — substitution `{{nom}}` par une valeur **extraite du message reçu** (`[motif, repli]`).
 *      C'est ce qui permet à une synthèse ou à un rapport rejoué de ne citer que des chiffres réels ;
 *   2. `transform` — reconstruction de la réponse à partir du message reçu :
 *      - `translation`      : `[{path, text}]` reçu → `[{path, text traduit}]` (table `by_text`) ;
 *      - `verbatim_classify`: `[{ref, text}]` reçu → `[{ref, themes, sentiment, confidence}]` (table `by_text`).
 *      Les identifiants (`path`, `ref`) sont donc toujours ceux **réellement demandés** par le lot.
 */
class LlmReplayProvider
{
    /** Nom de fournisseur exposé partout où la simulation doit être visible. */
    public const PROVIDER = 'replay';

    /** Suffixe ajouté aux messages des jobs produits en rejeu. */
    public const SIMULATED_SUFFIX = ' (simulé)';

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function generate(array $messages, ?string $systemInstruction, array $options = []): string
    {
        self::assertAllowed();

        $prompt = trim((string) ($options['prompt_name'] ?? ''));
        if ($prompt === '') {
            throw new RuntimeException(
                'Mode rejeu : le nom du prompt est absent (`options[\'prompt_name\']`). '
                .'Chaque appel IA doit nommer son prompt pour que le rejeu sache quoi renvoyer.'
            );
        }

        $userMessage = self::lastUserMessage($messages);
        $vars = self::normalizeVars($options['replay_vars'] ?? []);

        $dir = self::directory($prompt);
        $index = self::readIndex($dir, $prompt);
        $rule = self::select($index, $userMessage, (string) $systemInstruction, $vars);

        $file = $dir.DIRECTORY_SEPARATOR.$rule['response'];
        if (! is_file($file)) {
            throw new RuntimeException("Mode rejeu : la réponse « {$rule['response']} » du prompt « {$prompt} » est introuvable ({$file}).");
        }

        $content = (string) file_get_contents($file);
        $content = self::applyFigures(
            $content,
            is_array($rule['figures'] ?? null) ? $rule['figures'] : [],
            $userMessage,
            str_ends_with(strtolower($rule['response']), '.json'),
        );
        $content = self::applyTransform($content, (string) ($rule['transform'] ?? ''), $userMessage, $rule['id']);

        self::sleepLikeANetworkCall();

        Log::info('IA simulée (rejeu) : réponse servie depuis le dépôt local.', [
            'prompt' => $prompt,
            'rule' => $rule['id'],
            'response_file' => $rule['response'],
            'transform' => $rule['transform'] ?? null,
            'bytes' => strlen($content),
        ]);

        return $content;
    }

    // ==================================================================== garde-fous

    /**
     * Le rejeu est un outil de développement : il ne doit jamais servir de fournisseur en production.
     */
    public static function assertAllowed(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'Le fournisseur IA « replay » est réservé au développement et à la démonstration : '
                .'il est refusé lorsque APP_ENV=production. Configurez LLM_DRIVER=live et une clé '
                .'GEMINI_API_KEY ou DEEPSEEK_API_KEY.'
            );
        }
    }

    // ==================================================================== index et sélection

    public static function directory(string $prompt): string
    {
        $root = (string) config('services.llm.replay_path', storage_path('app/llm-replay'));

        return rtrim($root, '/\\').DIRECTORY_SEPARATOR.$prompt;
    }

    /**
     * @return array{rules: list<array<string, mixed>>, default: array<string, mixed>|null, figures: array<string, mixed>}
     */
    private static function readIndex(string $dir, string $prompt): array
    {
        $path = $dir.DIRECTORY_SEPARATOR.'index.json';
        if (! is_file($path)) {
            throw new RuntimeException(
                "Mode rejeu : aucune réponse enregistrée pour le prompt « {$prompt} » ({$path} introuvable). "
                .'Ajoutez le dossier dans `storage/app/llm-replay/` ou repassez en LLM_DRIVER=live.'
            );
        }

        try {
            $index = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Mode rejeu : « {$path} » n'est pas un JSON valide — ".$e->getMessage());
        }
        if (! is_array($index)) {
            throw new RuntimeException("Mode rejeu : « {$path} » doit contenir un objet JSON.");
        }

        return [
            'rules' => array_values(array_filter(
                is_array($index['rules'] ?? null) ? $index['rules'] : [],
                static fn ($r): bool => is_array($r),
            )),
            'default' => is_array($index['default'] ?? null) ? $index['default'] : null,
            // `figures` déclaré au niveau de l'index s'applique à toutes les règles (une règle peut en ajouter).
            'figures' => is_array($index['figures'] ?? null) ? $index['figures'] : [],
        ];
    }

    /**
     * @param  array{rules: list<array<string, mixed>>, default: array<string, mixed>|null, figures: array<string, mixed>}  $index
     * @param  array<string, string>  $vars
     * @return array{id: string, response: string, transform?: string, figures?: array<string, mixed>}
     */
    private static function select(array $index, string $message, string $system, array $vars): array
    {
        foreach ($index['rules'] as $rule) {
            if (self::matches(is_array($rule['when'] ?? null) ? $rule['when'] : [], $message, $system, $vars)) {
                return self::normalizeRule($rule, $index['figures']);
            }
        }

        if ($index['default'] !== null) {
            return self::normalizeRule($index['default'] + ['id' => 'default'], $index['figures']);
        }

        throw new RuntimeException('Mode rejeu : aucune règle ne correspond et l\'index ne déclare pas de réponse par défaut.');
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $sharedFigures
     * @return array{id: string, response: string, transform?: string, figures?: array<string, mixed>}
     */
    private static function normalizeRule(array $rule, array $sharedFigures = []): array
    {
        $response = $rule['response'] ?? null;
        if (! is_string($response) || $response === '' || str_contains($response, '..')) {
            throw new RuntimeException('Mode rejeu : une règle doit porter un nom de fichier `response` valide.');
        }

        return [
            'id' => (string) ($rule['id'] ?? 'sans-id'),
            'response' => $response,
            'transform' => is_string($rule['transform'] ?? null) ? $rule['transform'] : '',
            'figures' => array_merge($sharedFigures, is_array($rule['figures'] ?? null) ? $rule['figures'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $when
     * @param  array<string, string>  $vars
     */
    private static function matches(array $when, string $message, string $system, array $vars): bool
    {
        if ($when === []) {
            return true;
        }

        if (is_array($when['vars'] ?? null)) {
            foreach ($when['vars'] as $key => $expected) {
                $actual = $vars[mb_strtolower((string) $key)] ?? null;
                $candidates = array_map(
                    static fn ($v): string => mb_strtolower(trim((string) $v)),
                    is_array($expected) ? $expected : [$expected],
                );
                if ($actual === null || ! in_array($actual, $candidates, true)) {
                    return false;
                }
            }
        }

        foreach (['contains' => $message, 'system' => $system] as $field => $haystack) {
            if (! is_array($when[$field] ?? null)) {
                continue;
            }
            foreach ($when[$field] as $needle) {
                if (! is_string($needle) || mb_stripos($haystack, $needle) === false) {
                    return false;
                }
            }
        }

        if (is_array($when['any_contains'] ?? null)) {
            $hit = false;
            foreach ($when['any_contains'] as $needle) {
                if (is_string($needle) && mb_stripos($message, $needle) !== false) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        // Les motifs sont écrits sans délimiteur dans `index.json` (comme ceux de `figures`). Un motif de
        // sélection est insensible à la casse, comme `contains` ; ceux de `figures` ne le sont pas.
        if (is_string($when['regex'] ?? null) && preg_match(self::pattern($when['regex'], true), $message) !== 1) {
            return false;
        }

        return true;
    }

    // ==================================================================== chiffres réels

    /**
     * Remplace `{{nom}}` par une valeur **lue dans le message reçu**. Chaque entrée vaut
     * `[motif PCRE avec un groupe capturant, valeur de repli]`.
     *
     * @param  array<string, mixed>  $figures
     * @param  bool  $jsonSafe  échappe la valeur pour qu'elle puisse être insérée dans une chaîne JSON
     *                          (les valeurs purement numériques, utilisées telles quelles dans les
     *                          tableaux et les séries de graphiques, restent inchangées)
     */
    private static function applyFigures(string $content, array $figures, string $message, bool $jsonSafe = false): string
    {
        foreach ($figures as $name => $spec) {
            $pattern = is_array($spec) ? ($spec[0] ?? null) : $spec;
            $fallback = is_array($spec) ? (string) ($spec[1] ?? '') : '';

            $value = $fallback;
            if (is_string($pattern) && $pattern !== '' && preg_match(self::pattern($pattern), $message, $m) === 1) {
                $value = (string) ($m[1] ?? $m[0]);
            }
            if ($jsonSafe) {
                $value = substr((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
            }

            $content = str_replace('{{'.$name.'}}', $value, $content);
        }

        return $content;
    }

    /**
     * Motif PCRE complet à partir du motif **sans délimiteur** écrit dans `index.json`.
     */
    private static function pattern(string $pattern, bool $caseInsensitive = false): string
    {
        return '/'.str_replace('/', '\/', $pattern).'/u'.($caseInsensitive ? 'i' : '');
    }

    // ==================================================================== transformations

    private static function applyTransform(string $content, string $transform, string $message, string $ruleId): string
    {
        return match ($transform) {
            '' => $content,
            'translation' => self::transformTranslation($content, $message, $ruleId),
            'verbatim_classify' => self::transformClassification($content, $message, $ruleId),
            default => throw new RuntimeException("Mode rejeu : transformation « {$transform} » inconnue (règle {$ruleId})."),
        };
    }

    /**
     * Le lot reçu est `[{path, text}]` : la réponse reprend **les chemins demandés**, avec la traduction
     * de `by_text` quand elle existe (sinon le texte source, comme le ferait un modèle sur un texte déjà
     * dans la langue cible).
     */
    private static function transformTranslation(string $content, string $message, string $ruleId): string
    {
        $table = self::table($content, $ruleId);
        $rows = self::decodeList($message, $ruleId);

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['path'] ?? null)) {
                continue;
            }
            $source = (string) ($row['text'] ?? '');
            $translated = $table[self::normalizeText($source)] ?? null;
            $out[] = ['path' => $row['path'], 'text' => is_string($translated) ? $translated : $source];
        }

        return (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Le lot reçu est `[{ref, text}]` : la réponse porte **les mêmes `ref`**, codés d'après `by_text`
     * (repli : `fallback`, sinon aucun thème).
     */
    private static function transformClassification(string $content, string $message, string $ruleId): string
    {
        $decoded = self::decodeObject($content, $ruleId);
        $table = [];
        foreach (is_array($decoded['by_text'] ?? null) ? $decoded['by_text'] : [] as $text => $coding) {
            $table[self::normalizeText((string) $text)] = $coding;
        }
        $fallback = is_array($decoded['fallback'] ?? null)
            ? $decoded['fallback']
            : ['themes' => [], 'sentiment' => 'neutral', 'confidence' => 0.3];

        $out = [];
        foreach (self::decodeList($message, $ruleId) as $row) {
            if (! is_array($row) || ! array_key_exists('ref', $row)) {
                continue;
            }
            $coding = $table[self::normalizeText((string) ($row['text'] ?? ''))] ?? $fallback;
            $out[] = [
                'ref' => $row['ref'],
                'themes' => array_values(array_filter(
                    is_array($coding['themes'] ?? null) ? $coding['themes'] : [],
                    'is_string',
                )),
                'sentiment' => (string) ($coding['sentiment'] ?? 'neutral'),
                'confidence' => (float) ($coding['confidence'] ?? 0.5),
            ];
        }

        return (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed> table `by_text` normalisée
     */
    private static function table(string $content, string $ruleId): array
    {
        $decoded = self::decodeObject($content, $ruleId);
        $table = [];
        foreach (is_array($decoded['by_text'] ?? null) ? $decoded['by_text'] : [] as $key => $value) {
            $table[self::normalizeText((string) $key)] = $value;
        }

        return $table;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(string $raw, string $ruleId): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Mode rejeu : le fichier de la règle « {$ruleId} » doit être un objet JSON.");
        }

        return $decoded;
    }

    /**
     * @return list<mixed>
     */
    private static function decodeList(string $raw, string $ruleId): array
    {
        $decoded = json_decode(trim($raw), true);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException("Mode rejeu : le message reçu par la règle « {$ruleId} » n'est pas un tableau JSON.");
        }

        return $decoded;
    }

    // ==================================================================== divers

    /**
     * Espaces normalisés, casse et ponctuation typographique ignorées : un verbatim recopié depuis la base
     * doit être reconnu quelle que soit la façon dont il a transité.
     */
    private static function normalizeText(string $text): string
    {
        $text = str_replace(["\u{2019}", "\u{2018}", "\u{2026}", "\u{00A0}", "\u{202F}"], ["'", "'", '...', ' ', ' '], $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strtolower(trim($text));
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private static function lastUserMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return (string) ($messages[$i]['content'] ?? '');
            }
        }

        return (string) ($messages[array_key_last($messages)]['content'] ?? '');
    }

    /**
     * @param  mixed  $vars
     * @return array<string, string>
     */
    private static function normalizeVars($vars): array
    {
        $out = [];
        foreach (is_array($vars) ? $vars : [] as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[mb_strtolower((string) $key)] = mb_strtolower(trim((string) $value));
            }
        }

        return $out;
    }

    /**
     * Latence plausible d'un appel réseau : la progression des jobs et les écrans d'attente du web sont
     * donc réellement exercés.
     */
    private static function sleepLikeANetworkCall(): void
    {
        $min = max(0, (int) config('services.llm.replay_latency_min_ms', 500));
        $max = max($min, (int) config('services.llm.replay_latency_max_ms', 2000));
        if ($max === 0) {
            return;
        }

        usleep(random_int($min, $max) * 1000);
    }
}
