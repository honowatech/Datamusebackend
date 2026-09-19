<?php

namespace App\Services\Survey;

use App\Exceptions\DfsInvalidDefinitionException;
use App\Exceptions\ReportContentInvalidException;
use App\Models\AiJob;
use App\Models\SystemPrompt;
use App\Services\Dfs\DfsDefaults;
use App\Services\Dfs\DfsValidator;
use App\Services\Dfs\ValidationResult;
use App\Services\LlmProviderService;
use App\Support\ApiKeyResolver;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Opérations IA sur la *définition* d'un questionnaire (B-06b) : génération depuis un texte ou un .docx,
 * et traduction des textes i18n. Les opérations IA sur les *réponses* (verbatims, synthèse, rapports)
 * viendront en B-11.
 *
 * Contrat : `docs/openapi/survey.yaml` tags IA et Jobs ; format : `docs/dfs/README.md`.
 *
 * Principes :
 *   - prompts système en base (`system_prompts`, `SystemPromptSeeder`) : `form_generation`, `form_repair`,
 *     `form_translation` ; les marqueurs `{…}` sont substitués par `strtr` (jamais `sprintf` : le contenu
 *     est plein d'accolades JSON) ;
 *   - appel en `json_mode` avec `temperature` 0.2 (réponses stables), parse strict ;
 *   - `DfsDefaults::apply()` puis `DfsValidator` ; en cas d'erreur **structurelle** (`invalid_json`,
 *     `unsupported_version`, `schema`) une **seule** tentative `form_repair` est faite avec la liste des
 *     erreurs, puis le job échoue (`DfsInvalidDefinitionException`) ;
 *   - `dfs_version`, `id` (uuid) et `version` (1) sont imposés par le serveur, comme les langues demandées ;
 *   - la clé API arrive déjà déchiffrée (`ApiKeyResolver::decryptForJob` côté job) : ce service ne touche
 *     jamais au chiffrement.
 */
class SurveyAiService
{
    public const TEMPERATURE = 0.2;

    /** Secondes : doit rester inférieur au `timeout` des jobs (300 s). */
    public const TIMEOUT = 240;

    /** Nombre de textes envoyés par appel de traduction. */
    public const TRANSLATION_BATCH = 60;

    /** Champs dont la valeur est un objet i18n (`docs/dfs/README.md` § 9). */
    public const I18N_FIELDS = ['title', 'label', 'hint', 'description', 'required_message', 'constraint_message', 'message'];

    /** Erreurs qui empêchent d'enregistrer un brouillon (identiques à SurveyVersionService). */
    private const STRUCTURAL_CODES = SurveyVersionService::STRUCTURAL_CODES;

    public function __construct(
        private readonly LlmProviderService $llm,
        private readonly DfsValidator $validator,
        private readonly ReportContentValidator $reportValidator = new ReportContentValidator,
    ) {}

    // ==================================================================== génération

    /**
     * Génère une définition DFS v1 à partir du texte d'un questionnaire papier.
     *
     * @param  array{provider?: string, api_key: string, languages?: list<string>, default_language?: string, hints?: array<string, mixed>, title?: string|null}  $opts
     * @return array<string, mixed> définition DFS valide (structurellement)
     *
     * @throws DfsInvalidDefinitionException JSON toujours invalide après la tentative de réparation
     * @throws RuntimeException prompt absent, fournisseur injoignable
     */
    public function generateForm(string $sourceText, array $opts, AiJob $job): array
    {
        $languages = $this->languages($opts);
        $default = $this->defaultLanguage($opts, $languages);

        $job->markRunning('Lecture du questionnaire source…')->setProgress(10, 'Lecture du questionnaire source…');

        $system = strtr($this->prompt('form_generation'), [
            '{dfs_guide}' => DfsPromptGuide::text(),
            '{languages}' => implode(', ', $languages),
            '{default_language}' => $default,
            '{hints}' => $this->formatHints($opts['hints'] ?? []),
        ]);

        $job->setProgress(40, 'Structuration des sections et des questions…');
        $raw = $this->call($opts, 'form_generation', $system, $this->sourceMessage($sourceText, $opts['title'] ?? null), true, [
            'default_language' => $default,
            'title' => (string) ($opts['title'] ?? ''),
        ]);

        $job->setProgress(80, 'Validation de la structure…');
        [$definition, $result] = $this->parseAndValidate($raw, $languages, $default);

        if ($this->structuralErrors($result) !== []) {
            // Une seule tentative de réparation, avec la liste des erreurs du validateur.
            $job->setProgress(80, 'Correction des erreurs détectées dans la réponse du modèle…');
            $repairSystem = strtr($this->prompt('form_repair'), [
                '{dfs_guide}' => DfsPromptGuide::text(),
                '{errors}' => $this->formatIssues($result->errors),
            ]);
            $raw = $this->call($opts, 'form_repair', $repairSystem, "Document à corriger :\n\n".$raw, true, [
                'default_language' => $default,
            ]);
            [$definition, $result] = $this->parseAndValidate($raw, $languages, $default);
        }

        $structural = $this->structuralErrors($result);
        if ($structural !== []) {
            throw new DfsInvalidDefinitionException(
                $structural,
                $result->warnings,
                'Le fournisseur IA a renvoyé un questionnaire invalide après une tentative de réparation : '
                    .$this->firstMessages($structural),
            );
        }

        $job->setProgress(100, 'Questionnaire structuré.');

        return $definition;
    }

    // ==================================================================== traduction

    /**
     * Complète les textes i18n manquants dans `$target` : les autres langues ne sont jamais modifiées et
     * `$target` est ajoutée à `settings.languages`. Les textes sont envoyés par lots de 60, repérés par
     * leur pointeur JSON (RFC 6901) vers l'objet i18n (`/sections/0/items/2/label`).
     *
     * @param  array<string, mixed>  $definition
     * @param  array{provider?: string, api_key: string, overwrite?: bool}  $opts
     * @return array<string, mixed> définition complétée
     */
    public function translateForm(array $definition, string $target, AiJob $job, array $opts = []): array
    {
        $source = $this->sourceLanguageOf($definition);
        $overwrite = (bool) ($opts['overwrite'] ?? false);

        $job->markRunning('Lecture du brouillon…')->setProgress(10, 'Lecture du brouillon…');

        $items = [];
        $this->collectI18n($definition, '', $source, $target, $overwrite, $items);

        if ($items !== []) {
            $system = strtr($this->prompt('form_translation'), [
                '{source_lang}' => $source,
                '{target_lang}' => $target,
            ]);

            $batches = array_chunk($items, self::TRANSLATION_BATCH);
            $total = count($batches);
            foreach ($batches as $i => $batch) {
                $job->setProgress(
                    (int) round(40 + 40 * ($i / max(1, $total))),
                    sprintf('Traduction des libellés (%d/%d)…', $i + 1, $total),
                );

                $translated = $this->translateBatch($batch, $system, $opts, $source, $target);
                foreach ($batch as $entry) {
                    $text = $translated[$entry['path']] ?? null;
                    if (is_string($text) && trim($text) !== '') {
                        $this->setI18n($definition, $entry['path'], $target, $text);
                    }
                }
            }
        }

        $job->setProgress(80, 'Validation de la structure…');

        $languages = $definition['settings']['languages'] ?? [];
        $languages = is_array($languages) ? array_values(array_filter($languages, 'is_string')) : [];
        if (! in_array($target, $languages, true)) {
            $languages[] = $target;
        }
        $definition['settings']['languages'] = $languages;

        $job->setProgress(100, sprintf('%d libellé(s) traduit(s) en « %s ».', count($items), $target));

        return $definition;
    }

    /**
     * Textes i18n dépourvus de la langue `$target` (diagnostic / aperçu côté web).
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, array{path: string, text: string}>
     */
    public function missingTranslations(array $definition, string $target, bool $overwrite = false): array
    {
        $items = [];
        $this->collectI18n($definition, '', $this->sourceLanguageOf($definition), $target, $overwrite, $items);

        return $items;
    }

    /**
     * Validation complète (schéma + sémantique) d'une définition, sans contexte de questionnaire.
     *
     * @param  array<string, mixed>  $definition
     */
    public function validateDefinition(array $definition): ValidationResult
    {
        return $this->validator->validate($definition);
    }

    // ==================================================================== B-11 — verbatims

    /**
     * Construit un livre de codes à partir d'un échantillon de verbatims (prompt `verbatim_discover`).
     *
     * @param  list<string>  $texts
     * @param  array{provider?: string, api_key: string, question_key?: string, question_label?: string, max_themes?: int, language?: string}  $opts
     * @return list<array<string, mixed>> thèmes normalisés (`VerbatimService::normalizeThemes`)
     *
     * @throws RuntimeException réponse illisible ou sans thème exploitable
     */
    public function discoverThemes(array $texts, array $opts): array
    {
        $max = max(2, min(VerbatimService::MAX_THEMES, (int) ($opts['max_themes'] ?? 10)));

        $system = strtr($this->prompt('verbatim_discover'), [
            '{question}' => (string) ($opts['question_label'] ?? 'Question ouverte'),
            '{max_themes}' => (string) $max,
            '{language}' => (string) ($opts['language'] ?? 'fr'),
        ]);

        $raw = $this->call($opts, 'verbatim_discover', $system, $this->numberedList($texts), true, [
            'question_key' => (string) ($opts['question_key'] ?? ''),
            'language' => (string) ($opts['language'] ?? 'fr'),
        ]);
        $rows = self::rowsOf(self::decodeJson($raw), ['themes', 'items', 'data', 'results']);

        $themes = VerbatimService::normalizeThemes($rows, $max);
        if ($themes === []) {
            throw new RuntimeException('Le fournisseur IA n\'a renvoyé aucun thème exploitable.');
        }

        return $themes;
    }

    /**
     * Classe un lot de verbatims sur un livre de codes (prompt `verbatim_classify`).
     *
     * @param  list<array{ref: int|string, text: string}>  $batch
     * @param  list<array<string, mixed>>  $themes
     * @param  array{provider?: string, api_key: string, question_key?: string, question_label?: string, language?: string}  $opts
     * @return array<string, array{themes: list<string>, sentiment: ?string, confidence: ?float}> ref → codage
     */
    public function classifyBatch(array $batch, array $themes, array $opts): array
    {
        $system = strtr($this->prompt('verbatim_classify'), [
            '{question}' => (string) ($opts['question_label'] ?? 'Question ouverte'),
            '{themes}' => $this->formatThemes($themes),
            '{language}' => (string) ($opts['language'] ?? 'fr'),
        ]);

        $payload = json_encode(array_values(array_map(
            static fn (array $item): array => ['ref' => $item['ref'], 'text' => $item['text']],
            $batch,
        )), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $raw = $this->call($opts, 'verbatim_classify', $system, (string) $payload, true, [
            'question_key' => (string) ($opts['question_key'] ?? ''),
            'language' => (string) ($opts['language'] ?? 'fr'),
        ]);
        $rows = self::rowsOf(self::decodeJson($raw), ['codings', 'items', 'data', 'results']);

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['ref'])) {
                continue;
            }
            $out[(string) $row['ref']] = [
                'themes' => VerbatimService::themeKeysOf($row['themes'] ?? []),
                'sentiment' => VerbatimService::sentiment($row['sentiment'] ?? null),
                'confidence' => VerbatimService::confidence($row['confidence'] ?? null),
            ];
        }

        return $out;
    }

    // ==================================================================== B-11 — synthèse

    /**
     * Synthèse markdown des résultats, construite depuis les **statistiques** (jamais les lignes brutes).
     *
     * @param  array{provider?: string, api_key: string, focus?: ?string, language?: string}  $opts
     */
    public function synthesize(string $contextMarkdown, array $opts): string
    {
        $focus = is_string($opts['focus'] ?? null) && trim($opts['focus']) !== ''
            ? trim($opts['focus'])
            : '(aucun angle particulier : synthèse générale)';

        $system = strtr($this->prompt('survey_synthesis'), [
            '{focus}' => $focus,
            '{language}' => (string) ($opts['language'] ?? 'fr'),
        ]);

        $markdown = trim($this->call($opts, 'survey_synthesis', $system, $contextMarkdown, false, [
            'language' => (string) ($opts['language'] ?? 'fr'),
            'focus' => $focus,
        ]));
        if ($markdown === '') {
            throw new RuntimeException('Le fournisseur IA a renvoyé une synthèse vide.');
        }

        return self::stripCodeFence($markdown);
    }

    // ==================================================================== B-11 — rapports

    /**
     * Rédige un rapport complet depuis un brief et le contexte statistique. La sortie est validée contre
     * `ReportContent` ; en cas d'erreur, **une seule** tentative de réparation est faite (prompt
     * `report_repair`, à défaut `commercial_report` avec la liste d'erreurs) puis l'appel échoue.
     *
     * @param  array{title: string, brief: string, orientation?: string, audience?: string, language?: string, tone?: string, length?: string, sections?: list<string>}  $brief
     * @param  array{provider?: string, api_key: string}  $opts
     * @return array<string, mixed> `content_json` valide
     *
     * @throws ReportContentInvalidException JSON toujours invalide après réparation
     */
    public function writeReport(string $contextMarkdown, array $brief, array $opts): array
    {
        $system = strtr($this->prompt('commercial_report'), $this->reportMarkers($brief));

        $raw = $this->call($opts, 'commercial_report', $system, $this->briefMessage($brief, $contextMarkdown), true, [
            'orientation' => (string) ($brief['orientation'] ?? 'commercial'),
            'audience' => (string) ($brief['audience'] ?? ''),
            'tone' => (string) ($brief['tone'] ?? 'factuel'),
            'length' => (string) ($brief['length'] ?? 'moyen'),
            'language' => (string) ($brief['language'] ?? 'fr'),
        ]);
        [$content, $errors] = $this->parseReport($raw);

        if ($errors !== []) {
            $repair = strtr($this->prompt('report_repair'), [
                '{errors}' => ReportContentValidator::format($errors),
                '{language}' => (string) ($brief['language'] ?? 'fr'),
            ]);
            $raw = $this->call($opts, 'report_repair', $repair, "Document à corriger :\n\n".$raw, true, [
                'kind' => 'report',
            ]);
            [$content, $errors] = $this->parseReport($raw);
        }

        if ($errors !== []) {
            throw new ReportContentInvalidException(
                $errors,
                'Le fournisseur IA a renvoyé un rapport invalide après une tentative de réparation : '
                    .$this->firstMessages($errors),
            );
        }

        return $content;
    }

    /**
     * Réécrit **une** section (`ReportSection`) d'un rapport existant.
     *
     * @param  array<string, mixed>  $section  section actuelle
     * @param  array{provider?: string, api_key: string, instructions?: ?string, language?: string, title?: string, brief?: string, audience?: string, tone?: string}  $opts
     * @return array<string, mixed> section réécrite et validée
     *
     * @throws ReportContentInvalidException section invalide après réparation
     */
    public function regenerateSection(string $contextMarkdown, array $section, array $opts): array
    {
        $system = strtr($this->prompt('report_section'), [
            '{heading}' => (string) ($section['heading'] ?? ''),
            '{level}' => (string) ($section['level'] ?? 2),
            '{instructions}' => is_string($opts['instructions'] ?? null) && trim($opts['instructions']) !== ''
                ? trim($opts['instructions'])
                : '(aucune consigne particulière : améliore la section en restant factuel)',
            '{language}' => (string) ($opts['language'] ?? 'fr'),
            '{title}' => (string) ($opts['title'] ?? ''),
            '{audience}' => (string) ($opts['audience'] ?? 'Direction commerciale'),
        ]);

        $message = "SECTION ACTUELLE (JSON)\n".json_encode($section, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nDONNÉES DE L'ENQUÊTE\n".$contextMarkdown;

        $raw = $this->call($opts, 'report_section', $system, $message, true, [
            'heading' => (string) ($section['heading'] ?? ''),
            'language' => (string) ($opts['language'] ?? 'fr'),
        ]);
        [$rewritten, $errors] = $this->parseSection($raw, $section);

        if ($errors !== []) {
            $repair = strtr($this->prompt('report_repair'), [
                '{errors}' => ReportContentValidator::format($errors),
                '{language}' => (string) ($opts['language'] ?? 'fr'),
            ]);
            $raw = $this->call($opts, 'report_repair', $repair, "Section à corriger :\n\n".$raw, true, [
                'kind' => 'section',
                'heading' => (string) ($section['heading'] ?? ''),
            ]);
            [$rewritten, $errors] = $this->parseSection($raw, $section);
        }

        if ($errors !== []) {
            throw new ReportContentInvalidException(
                $errors,
                'Le fournisseur IA a renvoyé une section invalide après une tentative de réparation : '
                    .$this->firstMessages($errors),
            );
        }

        return $rewritten;
    }

    /**
     * Validation publique d'un `content_json` (utilisée par `PUT /reports/{id}`).
     *
     * @return list<array{path: string, code: string, message: string, severity: string}>
     */
    public function validateReportContent(mixed $content): array
    {
        return $this->reportValidator->validate($content);
    }

    // ------------------------------------------------------------------ interne — rapports

    /**
     * @return array{0: array<string, mixed>, 1: list<array{path: string, code: string, message: string, severity: string}>}
     */
    private function parseReport(string $raw): array
    {
        $decoded = self::decodeJson($raw);
        if (is_array($decoded) && ! array_is_list($decoded) && isset($decoded['report']) && is_array($decoded['report'])) {
            $decoded = $decoded['report'];
        }

        $errors = $this->reportValidator->validate($decoded);

        return [is_array($decoded) ? $decoded : [], $errors];
    }

    /**
     * Une section régénérée est validée en la replaçant dans un `ReportContent` minimal : les erreurs
     * portent alors le chemin `/sections/0/...`, réécrit en chemin de section pour le prompt de réparation.
     *
     * @param  array<string, mixed>  $current
     * @return array{0: array<string, mixed>, 1: list<array{path: string, code: string, message: string, severity: string}>}
     */
    private function parseSection(string $raw, array $current): array
    {
        $decoded = self::decodeJson($raw);
        if (is_array($decoded) && ! array_is_list($decoded) && isset($decoded['section']) && is_array($decoded['section'])) {
            $decoded = $decoded['section'];
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            return [[], [[
                'path' => '/',
                'code' => 'invalid_json',
                'message' => 'La réponse du modèle n\'est pas un objet JSON de section.',
                'severity' => 'error',
            ]]];
        }

        // Le titre et le niveau restent ceux de la section remplacée (le contrat remplace *cette* section).
        $decoded['heading'] = $current['heading'] ?? ($decoded['heading'] ?? '');
        $decoded['level'] = $current['level'] ?? ($decoded['level'] ?? 2);

        $errors = $this->reportValidator->validate([
            'title' => 'contrôle',
            'summary' => 'contrôle',
            'sections' => [$decoded],
            'recommendations' => [],
        ]);
        $errors = array_values(array_filter(
            $errors,
            static fn (array $e): bool => str_starts_with((string) $e['path'], '/sections/0'),
        ));
        foreach ($errors as $i => $error) {
            $errors[$i]['path'] = '/'.ltrim(substr((string) $error['path'], strlen('/sections/0')), '/');
        }

        return [$decoded, $errors];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return array<string, string>
     */
    private function reportMarkers(array $brief): array
    {
        $sections = array_values(array_filter(
            is_array($brief['sections'] ?? null) ? $brief['sections'] : [],
            static fn ($s): bool => is_string($s) && trim($s) !== '',
        ));

        return [
            '{orientation}' => (string) ($brief['orientation'] ?? 'commercial'),
            '{audience}' => (string) ($brief['audience'] ?? 'Direction commerciale'),
            '{language}' => (string) ($brief['language'] ?? 'fr'),
            '{tone}' => (string) ($brief['tone'] ?? 'factuel'),
            '{length}' => (string) ($brief['length'] ?? 'moyen'),
            '{sections}' => $sections === []
                ? '(aucun plan imposé : propose un plan adapté au brief, 4 à 7 sections)'
                : implode("\n", array_map(static fn (string $s): string => '- '.$s, $sections)),
        ];
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function briefMessage(array $brief, string $contextMarkdown): string
    {
        return "TITRE DU RAPPORT\n".trim((string) ($brief['title'] ?? ''))
            ."\n\nBRIEF\n".trim((string) ($brief['brief'] ?? ''))
            ."\n\nDONNÉES DE L'ENQUÊTE (seule source de chiffres autorisée)\n".$contextMarkdown;
    }

    /**
     * @param  list<array<string, mixed>>  $themes
     */
    private function formatThemes(array $themes): string
    {
        $lines = [];
        foreach ($themes as $theme) {
            if (! is_array($theme) || ! is_string($theme['key'] ?? null)) {
                continue;
            }
            $line = '- `'.$theme['key'].'` — '.(string) ($theme['label'] ?? $theme['key']);
            if (is_string($theme['description'] ?? null) && $theme['description'] !== '') {
                $line .= ' : '.$theme['description'];
            }
            $lines[] = $line;
        }

        return $lines === [] ? '(aucun thème)' : implode("\n", $lines);
    }

    /**
     * @param  list<string>  $texts
     */
    private function numberedList(array $texts): string
    {
        $lines = [];
        foreach (array_values($texts) as $i => $text) {
            $lines[] = ($i + 1).'. '.str_replace("\n", ' ', trim($text));
        }

        return implode("\n", $lines);
    }

    /**
     * Tolère `[...]`, `{"themes": [...]}`, `{"items": [...]}`…
     *
     * @param  array<mixed>|null  $decoded
     * @param  list<string>  $wrappers
     * @return list<mixed>
     */
    private static function rowsOf(?array $decoded, array $wrappers): array
    {
        if ($decoded === null) {
            throw new RuntimeException('Le fournisseur IA a renvoyé une réponse illisible (JSON invalide).');
        }
        if (array_is_list($decoded)) {
            return $decoded;
        }
        foreach ($wrappers as $wrapper) {
            if (isset($decoded[$wrapper]) && is_array($decoded[$wrapper]) && array_is_list($decoded[$wrapper])) {
                return $decoded[$wrapper];
            }
        }

        return [];
    }

    private static function stripCodeFence(string $text): string
    {
        $text = trim($text);
        if (! str_starts_with($text, '```')) {
            return $text;
        }
        $text = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $text);

        return trim((string) preg_replace('/\s*```\s*$/', '', $text));
    }

    // ==================================================================== interne — LLM

    /**
     * @param  array{provider?: string, api_key: string}  $opts
     * @param  string  $promptName  nom du prompt système appelé : trace lisible et **clé de sélection du
     *                              mode rejeu** (`storage/app/llm-replay/{promptName}/`)
     * @param  bool  $jsonMode  `false` pour une réponse markdown libre (synthèse)
     * @param  array<string, mixed>  $replayVars  paramètres de sélection du rejeu (`question_key`, `heading`…)
     */
    private function call(array $opts, string $promptName, string $system, string $userMessage, bool $jsonMode = true, array $replayVars = []): string
    {
        $provider = ApiKeyResolver::normalizeProvider($opts['provider'] ?? null);
        $apiKey = (string) ($opts['api_key'] ?? '');
        if ($apiKey === '') {
            throw new RuntimeException(ApiKeyResolver::missingKeyMessage($provider));
        }

        $text = $this->llm->generate(
            $provider,
            $apiKey,
            [['role' => 'user', 'content' => $userMessage]],
            $system,
            [
                'json_mode' => $jsonMode,
                'temperature' => self::TEMPERATURE,
                'timeout' => self::TIMEOUT,
                'prompt_name' => $promptName,
                'replay_vars' => $replayVars,
            ],
        );

        if (trim($text) === '') {
            throw new RuntimeException('Le fournisseur IA a renvoyé une réponse vide.');
        }

        return $text;
    }

    /**
     * @param  array<int, array{path: string, text: string}>  $batch
     * @param  array{provider?: string, api_key: string}  $opts
     * @return array<string, string> path → traduction
     */
    private function translateBatch(array $batch, string $system, array $opts, string $source = 'fr', string $target = 'en'): array
    {
        $payload = json_encode(array_values($batch), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw = $this->call($opts, 'form_translation', $system, (string) $payload, true, [
            'source_lang' => $source,
            'target_lang' => $target,
        ]);

        $decoded = self::decodeJson($raw);
        if ($decoded === null) {
            throw new RuntimeException('Le fournisseur IA a renvoyé une traduction illisible (JSON invalide).');
        }

        // Tolérance : tableau nu, ou objet enveloppant ({items|translations|data|results: [...]}).
        $rows = $decoded;
        if (! array_is_list($rows)) {
            foreach (['items', 'translations', 'data', 'results'] as $wrapper) {
                if (isset($rows[$wrapper]) && is_array($rows[$wrapper])) {
                    $rows = $rows[$wrapper];
                    break;
                }
            }
        }
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['path'] ?? null) && is_string($row['text'] ?? null)) {
                $out[$row['path']] = $row['text'];
            }
        }

        return $out;
    }

    /**
     * Contenu du prompt système (table `system_prompts`).
     */
    private function prompt(string $name): string
    {
        $content = SystemPrompt::query()->where('name', $name)->value('content');
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException(
                "Le prompt système « {$name} » est absent : exécutez `php artisan db:seed --class=SystemPromptSeeder`."
            );
        }

        return $content;
    }

    // ==================================================================== interne — parsing

    /**
     * Parse strict + normalisation serveur + valeurs par défaut + validation.
     *
     * @param  list<string>  $languages
     * @return array{0: array<string, mixed>, 1: ValidationResult}
     */
    private function parseAndValidate(string $raw, array $languages, string $default): array
    {
        $decoded = self::decodeJson($raw);
        if (! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            return [[], new ValidationResult([[
                'path' => '/',
                'code' => 'invalid_json',
                'message' => 'La réponse du modèle n\'est pas un objet JSON exploitable.',
                'severity' => 'error',
            ]])];
        }

        $definition = DfsDefaults::apply($this->normalize($decoded, $languages, $default));

        return [$definition, $this->validator->validate($definition)];
    }

    /**
     * Décodage strict, après retrait d'un éventuel bloc markdown ```json … ``` et du texte qui l'entoure.
     *
     * @return array<mixed>|null
     */
    public static function decodeJson(string $raw): ?array
    {
        $text = trim($raw);

        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
            $text = (string) preg_replace('/\s*```\s*$/', '', $text);
            $text = trim($text);
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Dernier recours : extraire le plus grand fragment { … } ou [ … ] du texte.
            $decoded = self::decodeLargestFragment($text);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<mixed>|null
     */
    private static function decodeLargestFragment(string $text): ?array
    {
        foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
            $start = strpos($text, $open);
            $end = strrpos($text, $close);
            if ($start === false || $end === false || $end <= $start) {
                continue;
            }
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Impose l'entête serveur (`dfs_version`, `id`, `version`), les langues demandées et l'ordre canonique
     * des clés racine.
     *
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $languages
     * @return array<string, mixed>
     */
    private function normalize(array $definition, array $languages, string $default): array
    {
        $settings = is_array($definition['settings'] ?? null) ? $definition['settings'] : [];
        $settings['languages'] = $languages;
        $settings['default_language'] = $default;

        $ordered = [
            'dfs_version' => '1.0',
            'id' => (string) Str::uuid(),
            'version' => 1,
            'title' => is_array($definition['title'] ?? null) && $definition['title'] !== []
                ? $definition['title']
                : [$default => 'Questionnaire généré'],
        ];
        if (is_array($definition['description'] ?? null) && $definition['description'] !== []) {
            $ordered['description'] = $definition['description'];
        }
        $ordered['settings'] = $settings;
        $ordered['choice_lists'] = is_array($definition['choice_lists'] ?? null) ? $definition['choice_lists'] : [];
        $ordered['sections'] = is_array($definition['sections'] ?? null) ? $definition['sections'] : [];
        $ordered['follow_up_stages'] = is_array($definition['follow_up_stages'] ?? null) ? $definition['follow_up_stages'] : [];

        // Les éventuelles clés inconnues sont conservées : le validateur les signalera (`schema`).
        foreach ($definition as $key => $value) {
            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }

    // ==================================================================== interne — i18n

    /**
     * Collecte récursive des objets i18n auxquels il manque `$target`.
     *
     * @param  array<mixed>  $node
     * @param  array<int, array{path: string, text: string}>  $out
     */
    private function collectI18n(array $node, string $pointer, string $source, string $target, bool $overwrite, array &$out): void
    {
        foreach ($node as $key => $value) {
            if (! is_array($value) || $value === []) {
                continue;
            }
            $child = $pointer.'/'.self::escapePointer((string) $key);

            if (is_string($key) && in_array($key, self::I18N_FIELDS, true) && self::isI18n($value)) {
                $text = $value[$source] ?? null;
                $existing = $value[$target] ?? null;
                $missing = ! is_string($existing) || trim($existing) === '';

                if (is_string($text) && trim($text) !== '' && ($overwrite || $missing)) {
                    $out[] = ['path' => $child, 'text' => $text];
                }

                continue;
            }

            $this->collectI18n($value, $child, $source, $target, $overwrite, $out);
        }
    }

    /**
     * Un objet i18n : table associative non vide dont toutes les valeurs sont des chaînes et toutes les
     * clés ressemblent à des codes de langue BCP-47 courts.
     *
     * @param  array<mixed>  $value
     */
    private static function isI18n(array $value): bool
    {
        if (array_is_list($value)) {
            return false;
        }
        foreach ($value as $lang => $text) {
            if (! is_string($lang) || ! is_string($text) || preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $lang) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Écrit `$text` dans la langue `$lang` de l'objet i18n désigné par `$pointer`, sans toucher aux autres
     * langues ni au reste de la définition.
     *
     * @param  array<string, mixed>  $definition
     */
    private function setI18n(array &$definition, string $pointer, string $lang, string $text): void
    {
        $segments = array_map(
            static fn (string $s): string => str_replace(['~1', '~0'], ['/', '~'], $s),
            array_slice(explode('/', $pointer), 1),
        );

        $node = &$definition;
        foreach ($segments as $segment) {
            $key = ctype_digit($segment) ? (int) $segment : $segment;
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return;
            }
            $node = &$node[$key];
        }

        if (is_array($node) && ! array_is_list($node)) {
            $node[$lang] = $text;
        }
        unset($node);
    }

    private static function escapePointer(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function sourceLanguageOf(array $definition): string
    {
        $default = $definition['settings']['default_language'] ?? null;

        return is_string($default) && $default !== '' ? $default : SurveyVersionService::DEFAULT_LANGUAGE;
    }

    // ==================================================================== interne — divers

    /**
     * @param  array{languages?: list<string>, default_language?: string}  $opts
     * @return list<string>
     */
    private function languages(array $opts): array
    {
        $languages = array_values(array_filter(
            is_array($opts['languages'] ?? null) ? $opts['languages'] : [],
            static fn ($l): bool => is_string($l) && $l !== '',
        ));

        $default = is_string($opts['default_language'] ?? null) && $opts['default_language'] !== ''
            ? $opts['default_language']
            : ($languages[0] ?? SurveyVersionService::DEFAULT_LANGUAGE);

        if (! in_array($default, $languages, true)) {
            array_unshift($languages, $default);
        }

        return array_values(array_unique($languages));
    }

    /**
     * @param  array{default_language?: string}  $opts
     * @param  list<string>  $languages
     */
    private function defaultLanguage(array $opts, array $languages): string
    {
        $default = $opts['default_language'] ?? null;

        return is_string($default) && $default !== '' ? $default : ($languages[0] ?? SurveyVersionService::DEFAULT_LANGUAGE);
    }

    /**
     * @param  array<string, mixed>|null  $hints
     */
    private function formatHints(?array $hints): string
    {
        $lines = [];

        if (is_string($hints['fiche_code_pattern'] ?? null) && $hints['fiche_code_pattern'] !== '') {
            $lines[] = '- Code fiche attendu : `'.$hints['fiche_code_pattern'].'` (renseigne `settings.fiche_code`).';
        }
        if (is_array($hints['follow_up_days'] ?? null) && $hints['follow_up_days'] !== []) {
            $days = implode(', ', array_map(static fn ($d): string => 'J+'.(int) $d, $hints['follow_up_days']));
            $lines[] = '- Étapes de suivi attendues : '.$days.' (une entrée de `follow_up_stages` par échéance).';
        }
        if (is_string($hints['currency'] ?? null) && $hints['currency'] !== '') {
            $lines[] = '- Devise des montants : `'.$hints['currency'].'`.';
        }
        if (is_string($hints['extra'] ?? null) && trim((string) $hints['extra']) !== '') {
            $lines[] = '- Consignes de l\'utilisateur : '.trim((string) $hints['extra']);
        }

        return $lines === [] ? '(aucune indication particulière)' : implode("\n", $lines);
    }

    private function sourceMessage(string $sourceText, ?string $title): string
    {
        $header = "Voici le questionnaire source à convertir en DFS v1.\n";
        if (is_string($title) && trim($title) !== '') {
            $header .= 'Titre souhaité : '.trim($title)."\n";
        }

        return $header."\n=== DÉBUT DU DOCUMENT ===\n".trim($sourceText)."\n=== FIN DU DOCUMENT ===";
    }

    /**
     * @return array<int, array{path: string, code: string, message: string, severity: string}>
     */
    private function structuralErrors(ValidationResult $result): array
    {
        return array_values(array_filter(
            $result->errors,
            static fn (array $e): bool => in_array($e['code'], self::STRUCTURAL_CODES, true),
        ));
    }

    /**
     * @param  array<int, array{path: string, code: string, message: string, severity?: string}>  $issues
     */
    private function formatIssues(array $issues, int $limit = 40): string
    {
        $lines = [];
        foreach (array_slice($issues, 0, $limit) as $issue) {
            $lines[] = sprintf('- %s [%s] %s', $issue['path'] ?? '/', $issue['code'] ?? 'error', $issue['message'] ?? '');
        }
        if (count($issues) > $limit) {
            $lines[] = sprintf('- … et %d autre(s) erreur(s).', count($issues) - $limit);
        }

        return $lines === [] ? '(aucune erreur listée)' : implode("\n", $lines);
    }

    /**
     * @param  array<int, array{message?: string}>  $issues
     */
    private function firstMessages(array $issues, int $limit = 3): string
    {
        $messages = array_map(static fn (array $e): string => (string) ($e['message'] ?? ''), array_slice($issues, 0, $limit));

        return implode(' ', array_filter($messages));
    }
}
