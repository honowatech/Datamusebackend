<?php

namespace App\Services\Survey;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Models\User;
use App\Models\VerbatimCodebook;
use App\Models\VerbatimCoding;
use App\Services\Dfs\LabelResolver;
use App\Services\Dfs\QuestionCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * B-11 — verbatims : extraction des réponses texte d'une question, livres de codes versionnés
 * (`verbatim_codebooks`) et codages (`verbatim_codings`).
 *
 * Règles :
 *   - seules les questions de type `text` sont codables ; les questions `tags: ["pii"]` sont refusées
 *     (le contrat renvoie `422` sur `classify` et `403` sur la lecture) ;
 *   - les soumissions `rejected` sont exclues (elles ne sont pas non plus matérialisées) ;
 *   - un livre de codes est **versionné par question** (`unique(survey, question_key, version)`) : une
 *     nouvelle découverte crée `version + 1`, les anciens codages restent lisibles ;
 *   - un codage est unique par `(submission, question_key, codebook)`.
 *
 * Après toute écriture, l'appelant marque la datasource `dirty`
 * (`MaterializeSurveyDatasourceJob::refresh`) : `ReponsesLayout` produit alors `{key}_themes`
 * et `{key}_sentiment` pour chaque question dotée d'un livre de codes.
 */
class VerbatimService
{
    /** Nombre maximal de verbatims envoyés au modèle pour construire un livre de codes. */
    public const DISCOVER_SAMPLE = 200;

    /** Taille d'un lot de classification. */
    public const CLASSIFY_BATCH = 40;

    /** Nombre maximal de thèmes d'un livre de codes (contrat `max_themes`). */
    public const MAX_THEMES = 30;

    /** Longueur maximale d'un verbatim transmis au modèle (protection du contexte). */
    public const MAX_TEXT_LENGTH = 1200;

    // ------------------------------------------------------------------ questions

    /**
     * Catalogue de la version publiée (à défaut, la version courante).
     */
    public function catalog(Survey $survey): ?QuestionCatalog
    {
        $version = $this->version($survey);

        return $version === null ? null : QuestionCatalog::fromDefinition($version->definition ?? []);
    }

    public function version(Survey $survey): ?SurveyVersion
    {
        return $survey->publishedVersion
            ?? $survey->currentVersion
            ?? SurveyVersion::query()->where('survey_id', $survey->id)->orderByDesc('version')->first();
    }

    /**
     * Définition d'une question texte codable.
     *
     * @return array{def: array<string, mixed>, label: string, pii: bool}
     *
     * @throws RuntimeException clé inconnue ou type non texte (`unknown_question` / `not_text`)
     */
    public function textQuestion(Survey $survey, string $key): array
    {
        $catalog = $this->catalog($survey);
        $node = $catalog?->node($key);
        // `node()['def']` porte la définition brute (`tags`, `label` i18n) ; `get()` n'en est qu'un résumé.
        $def = is_array($node) && ($node['kind'] ?? null) === 'question' && is_array($node['def'] ?? null) ? $node['def'] : null;

        if ($def === null) {
            throw new RuntimeException("La question « {$key} » n'existe pas dans ce questionnaire.", 404);
        }
        if (($def['type'] ?? null) !== 'text') {
            throw new RuntimeException("La question « {$key} » n'est pas une question ouverte (type « ".(string) ($def['type'] ?? '?').' »).', 422);
        }

        $lang = $catalog?->defaultLanguage() ?? 'fr';

        return [
            'def' => $def,
            'label' => LabelResolver::resolve($def['label'] ?? $key, $lang, $lang, $key),
            'pii' => ReponsesLayout::isPii($def),
        ];
    }

    /**
     * Clés des questions texte non `pii` (celles que le web peut proposer à la classification).
     *
     * @return list<string>
     */
    public function codableKeys(Survey $survey): array
    {
        $catalog = $this->catalog($survey);
        if ($catalog === null) {
            return [];
        }

        $out = [];
        foreach ($catalog->nodes() as $node) {
            $def = is_array($node['def'] ?? null) ? $node['def'] : [];
            if (($node['kind'] ?? null) === 'question' && ($node['type'] ?? null) === 'text' && ! ReponsesLayout::isPii($def)) {
                $out[] = (string) $node['key'];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ verbatims

    /**
     * Réponses texte non vides de `$key`, dans l'ordre des soumissions.
     *
     * @param  array{limit?: int, submission_ids?: list<int>, only_uncoded_for?: int}  $options
     * @return list<array{submission_id: int, uuid: string, fiche_code: ?string, enumerator_id: ?int, ended_at: ?string, text: string}>
     */
    public function verbatims(Survey $survey, string $key, array $options = []): array
    {
        $query = Submission::query()
            ->where('survey_id', $survey->id)
            ->where('status', '!=', SubmissionStatus::Rejected->value)
            ->orderBy('id');

        if (isset($options['submission_ids'])) {
            $query->whereIn('id', $options['submission_ids']);
        }
        if (isset($options['only_uncoded_for'])) {
            $codebookId = (int) $options['only_uncoded_for'];
            $query->whereNotExists(function ($sub) use ($codebookId, $key) {
                $sub->select(DB::raw(1))
                    ->from('verbatim_codings')
                    ->whereColumn('verbatim_codings.submission_id', 'submissions.id')
                    ->where('verbatim_codings.question_key', $key)
                    ->where('verbatim_codings.codebook_id', $codebookId);
            });
        }

        $out = [];
        $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : null;

        foreach ($query->cursor() as $submission) {
            $text = self::textOf($submission->answers ?? [], $key);
            if ($text === null) {
                continue;
            }
            $out[] = [
                'submission_id' => (int) $submission->id,
                'uuid' => (string) $submission->uuid,
                'fiche_code' => $submission->fiche_code,
                'enumerator_id' => $submission->enumerator_id === null ? null : (int) $submission->enumerator_id,
                'ended_at' => $submission->ended_at?->toIso8601String(),
                'text' => $text,
            ];
            if ($limit !== null && count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Texte exploitable d'une réponse (chaîne non vide, tronquée), `null` sinon.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function textOf(array $answers, string $key): ?string
    {
        $value = $answers[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, self::MAX_TEXT_LENGTH);
    }

    // ------------------------------------------------------------------ livres de codes

    public function latestCodebook(Survey $survey, string $key): ?VerbatimCodebook
    {
        return VerbatimCodebook::query()
            ->forQuestion($survey->id, $key)
            ->orderByDesc('version')
            ->first();
    }

    public function nextVersion(Survey $survey, string $key): int
    {
        return 1 + (int) VerbatimCodebook::query()->forQuestion($survey->id, $key)->max('version');
    }

    /**
     * Crée la version suivante du livre de codes de `$key`.
     *
     * @param  list<array<string, mixed>>  $themes
     */
    public function createCodebook(Survey $survey, string $key, array $themes, string $source = VerbatimCodebook::SOURCE_AI): VerbatimCodebook
    {
        return VerbatimCodebook::query()->create([
            'survey_id' => $survey->id,
            'question_key' => $key,
            'version' => $this->nextVersion($survey, $key),
            'themes' => self::normalizeThemes($themes),
            'source' => $source,
        ]);
    }

    /**
     * Normalise et dédoublonne une liste de thèmes (`{key, label, description?, examples?}`).
     *
     * @param  array<int, mixed>  $themes
     * @return list<array<string, mixed>>
     */
    public static function normalizeThemes(array $themes, int $max = self::MAX_THEMES): array
    {
        $out = [];
        foreach ($themes as $theme) {
            if (! is_array($theme)) {
                continue;
            }
            $key = self::slug((string) ($theme['key'] ?? $theme['label'] ?? ''));
            if ($key === '' || isset($out[$key])) {
                continue;
            }
            $entry = [
                'key' => $key,
                'label' => trim((string) ($theme['label'] ?? $key)) ?: $key,
            ];
            if (is_string($theme['description'] ?? null) && trim($theme['description']) !== '') {
                $entry['description'] = mb_substr(trim($theme['description']), 0, 500);
            }
            $examples = [];
            foreach (is_array($theme['examples'] ?? null) ? $theme['examples'] : [] as $example) {
                if (is_string($example) && trim($example) !== '' && count($examples) < 5) {
                    $examples[] = mb_substr(trim($example), 0, 300);
                }
            }
            if ($examples !== []) {
                $entry['examples'] = $examples;
            }
            $out[$key] = $entry;
            if (count($out) >= $max) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * Clé de thème canonique : `^[a-z0-9_]{1,40}$` (contrat, schéma `Theme`).
     */
    public static function slug(string $raw): string
    {
        $value = mb_strtolower(trim($raw));
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim($value, '_');

        return mb_substr($value, 0, 40);
    }

    /**
     * Compteurs d'un livre de codes : codages par thème, total codé, restant à coder.
     *
     * @return array{coded: int, uncoded: int, by_theme: array<string, int>}
     */
    public function counts(VerbatimCodebook $codebook): array
    {
        $codings = VerbatimCoding::query()->where('codebook_id', $codebook->id)->get(['themes']);

        $byTheme = [];
        foreach ($codings as $coding) {
            foreach (self::themeKeysOf($coding->themes) as $theme) {
                $byTheme[$theme] = ($byTheme[$theme] ?? 0) + 1;
            }
        }

        $total = Submission::query()
            ->where('survey_id', $codebook->survey_id)
            ->where('status', '!=', SubmissionStatus::Rejected->value)
            ->count();

        return [
            'coded' => $codings->count(),
            'uncoded' => max(0, $this->answeredCount($codebook) - $codings->count()),
            'by_theme' => $byTheme,
            'total_submissions' => $total,
        ];
    }

    private function answeredCount(VerbatimCodebook $codebook): int
    {
        $survey = $codebook->survey ?? Survey::query()->find($codebook->survey_id);

        return $survey === null ? 0 : count($this->verbatims($survey, (string) $codebook->question_key));
    }

    /**
     * Clés de thèmes d'un codage (tolère `["a"]` et `[{"key": "a"}]`).
     *
     * @return list<string>
     */
    public static function themeKeysOf(mixed $themes): array
    {
        $out = [];
        foreach (is_array($themes) ? $themes : [] as $theme) {
            $key = is_array($theme) ? ($theme['key'] ?? null) : $theme;
            if (is_string($key) && $key !== '') {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    // ------------------------------------------------------------------ codages

    /**
     * Enregistre (ou met à jour) les codages d'un lot.
     *
     * @param  list<array{submission_id: int, themes: list<string>, sentiment?: ?string, confidence?: ?float}>  $codings
     * @return int nombre de lignes écrites
     */
    public function storeCodings(VerbatimCodebook $codebook, array $codings, string $source = VerbatimCoding::SOURCE_AI): int
    {
        $known = $codebook->themeKeys();
        $written = 0;

        DB::transaction(function () use ($codebook, $codings, $source, $known, &$written): void {
            foreach ($codings as $coding) {
                $submissionId = (int) ($coding['submission_id'] ?? 0);
                if ($submissionId <= 0) {
                    continue;
                }
                $themes = array_values(array_filter(
                    self::themeKeysOf($coding['themes'] ?? []),
                    static fn (string $t): bool => in_array($t, $known, true),
                ));

                VerbatimCoding::query()->updateOrCreate(
                    [
                        'submission_id' => $submissionId,
                        'question_key' => $codebook->question_key,
                        'codebook_id' => $codebook->id,
                    ],
                    [
                        'survey_id' => $codebook->survey_id,
                        'themes' => $themes,
                        'sentiment' => self::sentiment($coding['sentiment'] ?? null),
                        'confidence' => self::confidence($coding['confidence'] ?? null),
                        'source' => $source,
                    ],
                );
                $written++;
            }
        });

        return $written;
    }

    public static function sentiment(mixed $value): ?string
    {
        $value = is_string($value) ? mb_strtolower(trim($value)) : null;

        return in_array($value, ['positive', 'neutral', 'negative', 'mixed'], true) ? $value : null;
    }

    public static function confidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round(max(0.0, min(1.0, (float) $value)), 3);
    }

    // ------------------------------------------------------------------ renommage / fusion

    /**
     * Remplace les thèmes d'un livre de codes. Un thème portant `merge_into` est **absorbé** : ses codages
     * sont réaffectés au thème cible puis il disparaît de la liste. Idempotent.
     *
     * @param  list<array<string, mixed>>  $themes
     * @return array{themes: list<array<string, mixed>>, merged: array<string, string>, recoded: int}
     */
    public function updateThemes(VerbatimCodebook $codebook, array $themes): array
    {
        $merges = [];
        $kept = [];
        foreach ($themes as $theme) {
            if (! is_array($theme)) {
                continue;
            }
            $key = self::slug((string) ($theme['key'] ?? ''));
            $target = is_string($theme['merge_into'] ?? null) ? self::slug($theme['merge_into']) : null;
            if ($key === '') {
                continue;
            }
            if ($target !== null && $target !== '' && $target !== $key) {
                $merges[$key] = $target;

                continue;
            }
            unset($theme['merge_into'], $theme['count']);
            $kept[] = $theme;
        }

        $normalized = self::normalizeThemes($kept);
        $keptKeys = array_column($normalized, 'key');

        // Une fusion vers un thème absent de la liste finale est ignorée (le thème source est conservé).
        foreach ($merges as $from => $to) {
            if (! in_array($to, $keptKeys, true)) {
                unset($merges[$from]);
            }
        }

        $recoded = 0;
        DB::transaction(function () use ($codebook, $normalized, $merges, $keptKeys, &$recoded): void {
            $codebook->forceFill(['themes' => $normalized])->save();

            if ($merges === [] && $keptKeys === []) {
                return;
            }

            foreach (VerbatimCoding::query()->where('codebook_id', $codebook->id)->cursor() as $coding) {
                $before = self::themeKeysOf($coding->themes);
                $after = [];
                foreach ($before as $theme) {
                    $mapped = $merges[$theme] ?? $theme;
                    if (in_array($mapped, $keptKeys, true) && ! in_array($mapped, $after, true)) {
                        $after[] = $mapped;
                    }
                }
                if ($after !== $before) {
                    $coding->forceFill(['themes' => $after])->save();
                    $recoded++;
                }
            }
        });

        return ['themes' => $normalized, 'merged' => $merges, 'recoded' => $recoded];
    }

    // ------------------------------------------------------------------ lecture

    /**
     * Verbatims codés d'une question (schéma `VerbatimCoding`) + comptages par thème.
     *
     * @param  array{codebook_id?: ?int, theme?: ?string, sentiment?: ?string, q?: ?string}  $filters
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>, codebook: ?VerbatimCodebook}
     */
    public function listCoded(Survey $survey, string $key, array $filters = []): array
    {
        $codebook = isset($filters['codebook_id']) && $filters['codebook_id'] !== null
            ? VerbatimCodebook::query()->forQuestion($survey->id, $key)->whereKey((int) $filters['codebook_id'])->first()
            : $this->latestCodebook($survey, $key);

        $codings = $codebook === null
            ? collect()
            : VerbatimCoding::query()->where('codebook_id', $codebook->id)->get()->keyBy('submission_id');

        $names = $this->enumeratorNames($survey);
        $items = [];
        $counts = [];

        foreach ($this->verbatims($survey, $key) as $verbatim) {
            /** @var VerbatimCoding|null $coding */
            $coding = $codings->get($verbatim['submission_id']);
            $themes = $coding === null ? [] : self::themeKeysOf($coding->themes);
            foreach ($themes as $theme) {
                $counts[$theme] = ($counts[$theme] ?? 0) + 1;
            }

            if (isset($filters['theme']) && $filters['theme'] !== null && ! in_array($filters['theme'], $themes, true)) {
                continue;
            }
            if (isset($filters['sentiment']) && $filters['sentiment'] !== null && $coding?->sentiment !== $filters['sentiment']) {
                continue;
            }
            if (isset($filters['q']) && is_string($filters['q']) && $filters['q'] !== ''
                && mb_stripos($verbatim['text'], $filters['q']) === false) {
                continue;
            }

            $items[] = [
                'submission_id' => $verbatim['submission_id'],
                'uuid' => $verbatim['uuid'],
                'fiche_code' => $verbatim['fiche_code'],
                'question_key' => $key,
                'text' => $verbatim['text'],
                'codebook_id' => $coding?->codebook_id === null ? null : (int) $coding->codebook_id,
                'themes' => $themes,
                'sentiment' => $coding?->sentiment,
                'confidence' => $coding?->confidence === null ? null : (float) $coding->confidence,
                'source' => $coding?->source,
                'enumerator' => $verbatim['enumerator_id'] === null ? null : [
                    'id' => $verbatim['enumerator_id'],
                    'name' => $names[$verbatim['enumerator_id']] ?? null,
                ],
                'ended_at' => $verbatim['ended_at'],
            ];
        }

        arsort($counts);

        return ['items' => $items, 'counts' => $counts, 'codebook' => $codebook];
    }

    /**
     * Libellés des thèmes du dernier livre de codes d'une question.
     *
     * @return array<string, string>
     */
    public function themeLabels(Survey $survey, string $key): array
    {
        $codebook = $this->latestCodebook($survey, $key);
        $out = [];
        foreach ($codebook?->themes ?? [] as $theme) {
            if (is_array($theme) && is_string($theme['key'] ?? null)) {
                $out[$theme['key']] = (string) ($theme['label'] ?? $theme['key']);
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    public function enumeratorNames(Survey $survey): array
    {
        return User::query()
            ->whereIn('id', Submission::query()->where('survey_id', $survey->id)->distinct()->pluck('enumerator_id')->filter())
            ->pluck('name', 'id')
            ->map(fn ($n) => (string) $n)
            ->all();
    }

    /**
     * @return Collection<int, VerbatimCodebook>
     */
    public function codebooks(Survey $survey, ?string $questionKey = null): Collection
    {
        return VerbatimCodebook::query()
            ->where('survey_id', $survey->id)
            ->when($questionKey !== null, fn ($q) => $q->where('question_key', $questionKey))
            ->orderBy('question_key')
            ->orderByDesc('version')
            ->get();
    }
}
