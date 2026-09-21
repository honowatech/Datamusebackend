<?php

namespace App\Services\Survey;

use App\Enums\FollowUpStatus;
use App\Enums\SubmissionChannel;
use App\Enums\SubmissionStatus;
use App\Http\Resources\SubmissionResource;
use App\Http\Resources\UserResource;
use App\Models\FollowUpEntry;
use App\Models\Submission;
use App\Models\SubmissionMedia;
use App\Services\Dfs\DfsDefaults;
use App\Services\Dfs\EngineContext;
use App\Services\Dfs\FormEngine;
use App\Services\Dfs\LabelResolver;
use App\Services\Dfs\QuestionCatalog;
use Throwable;

/**
 * Fiche de réponse (`GET /submissions/{id}/sheet`, plan « fiche de réponse » § 2, tâche F-B3).
 *
 * Une fiche se lit **avec sa propre version** du questionnaire (`submissions.survey_version_id`),
 * jamais avec la version courante : libellés, ordre et listes de choix d'époque. Le `FormEngine` est
 * rejoué sur les réponses stockées, ce qui donne gratuitement :
 *
 * - `asked` — la question était-elle pertinente pour CETTE fiche (pertinence héritée section → groupe
 *   → question, § 16.7 du DFS) ; une section située après le `stop` déclenché est `asked: false` ;
 * - `stop` — le `stop` qui a interrompu l'entretien, avec son message interpolé ;
 * - la pertinence **par instance** d'un groupe répété (`FormEngine::instanceRelevant`).
 *
 * Les `note` sont omises, les `calculate` sont rendus avec leur `display`. Les questions
 * `tags: ["pii"]` sont masquées (`masked: true`, `value`/`display` nuls) tant que le lecteur n'est pas
 * analyste du projet — c'est l'appelant qui tranche via `$includePii`.
 *
 * Les étapes de suivi complétées sont rendues avec un moteur entré dans l'étape (`enterStage`), après
 * être entré dans les étapes complétées précédentes : c'est ce qui rend « J+14 seulement si retiré à
 * J+7 » évaluable (§ 14).
 */
class SubmissionSheetBuilder
{
    /** Validité de l'URL signée d'un média (contrat : 15 min, comme `GET /submissions/{id}`). */
    public const MEDIA_URL_MINUTES = 15;

    public function __construct(
        private readonly SubmissionQualityService $quality = new SubmissionQualityService,
    ) {}

    /**
     * @param  string|null  $lang  langue des libellés ; défaut : langue de passation de la fiche
     * @param  bool  $includePii  le lecteur voit-il les réponses `pii` ?
     * @param  int|null  $restrictToEnumerator  borne la navigation précédent/suivant à un enquêteur
     * @return array<string, mixed> schéma `SubmissionSheet` du contrat
     */
    public function build(
        Submission $submission,
        ?string $lang = null,
        bool $includePii = false,
        ?int $restrictToEnumerator = null,
    ): array {
        $submission->loadMissing([
            'enumerator', 'reviewer', 'version', 'media', 'followUps.enumerator', 'codings', 'device', 'publicLink', 'survey',
        ]);

        $version = $submission->version;
        $definition = DfsDefaults::apply($version?->definition ?? []);
        $catalog = new QuestionCatalog($definition);
        $default = $catalog->defaultLanguage();
        $lang = $lang !== null && $lang !== '' ? $lang : ((string) ($submission->language ?: $default));
        $settings = is_array($definition['settings'] ?? null) ? $definition['settings'] : [];

        $answers = is_array($submission->answers) ? $submission->answers : [];
        $engine = $this->engine($definition, $submission, $settings, $lang, $answers);
        $codings = $this->codings($submission, $lang, $default);

        return [
            'submission' => SubmissionResource::summary($submission),
            'origin' => $this->origin($submission),
            'survey' => [
                'id' => (int) $submission->survey_id,
                'title' => LabelResolver::resolve(
                    $definition['title'] ?? ($submission->survey?->title ?? ''),
                    $lang,
                    $default,
                    (string) ($submission->survey?->title ?? ''),
                ),
                'version' => $version === null ? null : (int) $version->version,
                'language' => $lang,
                'languages' => array_values(array_filter((array) ($settings['languages'] ?? [$default]), 'is_string')),
            ],
            'sections' => $engine === null ? [] : $this->sections($engine, $catalog, $submission, $answers, $lang, $default, $includePii, $codings),
            'stop' => $engine === null ? null : $this->stop($engine, $catalog),
            'follow_ups' => $this->followUps($definition, $submission, $catalog, $settings, $engine, $answers, $lang, $default, $includePii, $codings),
            'quality' => [
                'flags' => $this->quality->flagDetails($submission, $settings),
                'duplicate_of' => $this->duplicateOf($submission, $settings),
            ],
            'navigation' => $this->navigation($submission, $restrictToEnumerator),
        ];
    }

    // ------------------------------------------------------------------ moteur

    /**
     * Moteur rejouant la fiche dans son contexte d'origine (enquêteur, appareil, zone, horloge de
     * l'entretien). Renvoie `null` si la version n'a pas de définition DFS exploitable — la fiche est
     * alors rendue sans sections plutôt que de faire échouer la requête.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $answers
     */
    private function engine(array $definition, Submission $submission, array $settings, string $lang, array $answers): ?FormEngine
    {
        if (! is_string($definition['dfs_version'] ?? null)) {
            return null;
        }

        $ctx = new EngineContext(
            enumeratorId: $submission->enumerator_id === null ? null : (int) $submission->enumerator_id,
            deviceId: $submission->device?->device_id,
            zone: $submission->zone,
            lang: $lang,
            startTime: $submission->started_at?->toIso8601String(),
            endTime: $submission->ended_at?->toIso8601String(),
            // § 16.5 : côté serveur, `submitted` / `validated` sont exposés comme `completed`.
            status: $submission->status === SubmissionStatus::ScreenedOut
                ? FormEngine::STATUS_SCREENED_OUT
                : FormEngine::STATUS_COMPLETED,
            seq: SubmissionSyncService::extractSeq($settings, $submission->fiche_code),
            // Horloge de l'entretien, jamais celle de la consultation.
            now: $submission->ended_at?->toIso8601String(),
            today: $submission->started_at?->format('Y-m-d'),
        );

        try {
            $engine = new FormEngine($definition, $ctx);
            $engine->setAnswers($answers);

            return $engine;
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------ sections

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, array{themes: list<string>, sentiment: string|null}>  $codings
     * @return list<array<string, mixed>>
     */
    private function sections(
        FormEngine $engine,
        QuestionCatalog $catalog,
        Submission $submission,
        array $answers,
        string $lang,
        string $default,
        bool $includePii,
        array $codings,
    ): array {
        $sections = [];

        foreach ($catalog->nodes() as $node) {
            if ($node['kind'] !== 'section') {
                continue;
            }

            $items = [];
            foreach ($node['children'] as $childKey) {
                $child = $catalog->node((string) $childKey);
                if ($child === null) {
                    continue;
                }

                if ($child['kind'] !== 'group') {
                    $item = $this->item(
                        $child, $engine, $catalog, $submission, $answers, $answers,
                        $engine->isVisible((string) $childKey), null, $lang, $default, $includePii, $codings,
                    );
                    if ($item !== null) {
                        $items[] = $item;
                    }

                    continue;
                }

                $groupKey = (string) $childKey;
                $groupLabel = $engine->label($groupKey);

                if (! $child['repeat']) {
                    foreach ($child['children'] as $grandKey) {
                        $grand = $catalog->node((string) $grandKey);
                        if ($grand === null) {
                            continue;
                        }
                        $item = $this->item(
                            $grand, $engine, $catalog, $submission, $answers, $answers,
                            $engine->isVisible((string) $grandKey),
                            ['key' => $groupKey, 'label' => $groupLabel, 'repeat_index' => null],
                            $lang, $default, $includePii, $codings,
                        );
                        if ($item !== null) {
                            $items[] = $item;
                        }
                    }

                    continue;
                }

                // Groupe répété : une passe par instance, pertinence locale à l'instance.
                $instances = $engine->instancesOf($groupKey);
                if ($instances === []) {
                    // Aucune instance : les enfants restent listés comme « non posés ».
                    foreach ($child['children'] as $grandKey) {
                        $grand = $catalog->node((string) $grandKey);
                        if ($grand === null) {
                            continue;
                        }
                        $item = $this->item(
                            $grand, $engine, $catalog, $submission, [], [], false,
                            ['key' => $groupKey, 'label' => $groupLabel, 'repeat_index' => null],
                            $lang, $default, $includePii, $codings,
                        );
                        if ($item !== null) {
                            $items[] = $item;
                        }
                    }

                    continue;
                }

                foreach ($instances as $index => $instance) {
                    foreach ($child['children'] as $grandKey) {
                        $grand = $catalog->node((string) $grandKey);
                        if ($grand === null) {
                            continue;
                        }
                        $values = is_array($instance) ? $instance : [];
                        $values[(string) $grandKey] = $engine->instanceValue($groupKey, (int) $index, (string) $grandKey);
                        $item = $this->item(
                            $grand, $engine, $catalog, $submission, $values, $values,
                            $engine->isVisible($groupKey) && $engine->instanceRelevant($groupKey, (int) $index, (string) $grandKey),
                            ['key' => $groupKey, 'label' => $groupLabel, 'repeat_index' => (int) $index + 1],
                            $lang, $default, $includePii, $codings, (int) $index,
                        );
                        if ($item !== null) {
                            $items[] = $item;
                        }
                    }
                }
            }

            $sections[] = [
                'key' => (string) $node['key'],
                'label' => $engine->label((string) $node['key']),
                'asked' => $engine->isVisible((string) $node['key']),
                'items' => $items,
            ];
        }

        return $sections;
    }

    /**
     * Une ligne de la fiche. `null` pour une `note` ou un `stop` (jamais rendus comme questions).
     *
     * @param  array<string, mixed>  $node  nœud du catalogue
     * @param  array<string, mixed>  $values  réponses où lire la valeur (fiche, ou instance de groupe)
     * @param  array<string, mixed>  $store  réponses où lire les compagnons `_other` / `__codes`
     * @param  array{key: string, label: string, repeat_index: int|null}|null  $group
     * @param  array<string, array{themes: list<string>, sentiment: string|null}>  $codings
     * @return array<string, mixed>|null
     */
    private function item(
        array $node,
        FormEngine $engine,
        QuestionCatalog $catalog,
        Submission $submission,
        array $values,
        array $store,
        bool $asked,
        ?array $group,
        string $lang,
        string $default,
        bool $includePii,
        array $codings,
        ?int $repeatIndex = null,
    ): ?array {
        $type = (string) ($node['type'] ?? '');
        if ($type === '' || in_array($type, ['note', 'stop', 'group'], true)) {
            return null;
        }

        $key = (string) $node['key'];
        $def = is_array($node['def'] ?? null) ? $node['def'] : [];
        $value = $values[$key] ?? null;
        $pii = ReponsesLayout::isPii($def);
        $masked = $pii && ! $includePii;

        $media = $this->mediaOf($submission, $key, $repeatIndex ?? 0);
        $display = $masked
            ? null
            : ($media !== null
                ? SubmissionDisplayFormatter::mediaName($media->path, $key, $media->mime)
                : SubmissionDisplayFormatter::format($def, $value, $catalog->choicesFor($key), $lang, $default));

        $otherKey = $catalog->otherKeyOf($key);
        $codesKey = $catalog->postcodeKeyOf($key);
        $rawCodes = $codesKey !== null ? ($store[$codesKey] ?? null) : null;

        return [
            'key' => $key,
            'type' => $type,
            'label' => $engine->label($key),
            'asked' => $asked,
            'answered' => ! self::isEmpty($value),
            'value' => $masked ? null : $value,
            'display' => $display,
            'other' => $masked || $otherKey === null ? null : self::stringOrNull($store[$otherKey] ?? null),
            'codes' => $rawCodes === null || self::isEmpty($rawCodes)
                ? null
                : SubmissionDisplayFormatter::codeLabels($rawCodes, $catalog->postcodeChoicesFor($key), $lang, $default),
            'themes' => $codings[$key]['themes'] ?? [],
            'sentiment' => $codings[$key]['sentiment'] ?? null,
            'pii' => $pii,
            'masked' => $masked,
            'media' => $media === null ? null : [
                'id' => (int) $media->id,
                'mime' => (string) $media->mime,
                'size' => (int) $media->size,
                'signed_url' => $media->isUploaded() ? $media->signedUrl(self::MEDIA_URL_MINUTES) : null,
                'expires_at' => $media->isUploaded() ? now()->addMinutes(self::MEDIA_URL_MINUTES)->toIso8601String() : null,
            ],
            'group' => $group,
        ];
    }

    private function mediaOf(Submission $submission, string $questionKey, int $repeatIndex): ?SubmissionMedia
    {
        return $submission->media
            ->first(fn (SubmissionMedia $m) => $m->question_key === $questionKey
                && (int) ($m->repeat_index ?? 0) === $repeatIndex);
    }

    // ------------------------------------------------------------------ stop

    /**
     * @return array{key: string, label: string, message: string}|null
     */
    private function stop(FormEngine $engine, QuestionCatalog $catalog): ?array
    {
        $key = $engine->endReason();
        if ($key === null) {
            return null;
        }
        $node = $catalog->node($key);
        if ($node === null || ($node['type'] ?? null) !== 'stop') {
            return null;
        }

        return [
            'key' => $key,
            'label' => $engine->label($key),
            'message' => $engine->text($node['def']['message'] ?? null),
        ];
    }

    // ------------------------------------------------------------------ suivis

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $answers
     * @param  array<string, array{themes: list<string>, sentiment: string|null}>  $codings
     * @return list<array<string, mixed>>
     */
    private function followUps(
        array $definition,
        Submission $submission,
        QuestionCatalog $catalog,
        array $settings,
        ?FormEngine $engine,
        array $answers,
        string $lang,
        string $default,
        bool $includePii,
        array $codings,
    ): array {
        $entries = $submission->followUps->sortBy(fn (FollowUpEntry $e) => $e->due_at?->getTimestamp() ?? 0)->values();
        if ($entries->isEmpty()) {
            return [];
        }

        $stageKeys = $catalog->stageKeys();
        $completed = [];
        foreach ($entries as $entry) {
            if ($entry->status === FollowUpStatus::Done && is_array($entry->answers) && $entry->answers !== []) {
                $completed[(string) $entry->stage_key] = $entry->answers;
            }
        }

        $out = [];
        foreach ($entries as $entry) {
            $stageKey = (string) $entry->stage_key;
            $stageNode = $catalog->node($stageKey);
            $stageAnswers = is_array($entry->answers) ? $entry->answers : [];

            $out[] = [
                'id' => (int) $entry->id,
                'stage_key' => $stageKey,
                'label' => $engine !== null && $stageNode !== null ? $engine->label($stageKey) : $stageKey,
                'status' => $entry->status?->value,
                'due_at' => $entry->due_at?->toIso8601String(),
                'window_ends_at' => $entry->window_ends_at?->toIso8601String(),
                'completed_at' => $entry->completed_at?->toIso8601String(),
                'enumerator' => UserResource::ref($entry->relationLoaded('enumerator') ? $entry->enumerator : null),
                'items' => $stageNode === null || $stageAnswers === []
                    ? []
                    : $this->stageItems(
                        $definition, $submission, $catalog, $settings, $stageNode, $stageKey,
                        $answers, $completed, $stageAnswers, $stageKeys, $lang, $default, $includePii, $codings,
                    ),
            ];
        }

        return $out;
    }

    /**
     * Réponses d'une étape, rendues par un moteur **entré dans l'étape** après les étapes complétées
     * précédentes : sans cela, `relevant` de J+14 (qui lit `j7_retire`) serait toujours faux.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $stageNode
     * @param  array<string, mixed>  $answers
     * @param  array<string, array<string, mixed>>  $completed
     * @param  array<string, mixed>  $stageAnswers
     * @param  list<string>  $stageKeys
     * @param  array<string, array{themes: list<string>, sentiment: string|null}>  $codings
     * @return list<array<string, mixed>>
     */
    private function stageItems(
        array $definition,
        Submission $submission,
        QuestionCatalog $catalog,
        array $settings,
        array $stageNode,
        string $stageKey,
        array $answers,
        array $completed,
        array $stageAnswers,
        array $stageKeys,
        string $lang,
        string $default,
        bool $includePii,
        array $codings,
    ): array {
        $before = [];
        foreach ($stageKeys as $key) {
            if ($key === $stageKey) {
                break;
            }
            if (isset($completed[$key])) {
                $before[$key] = $completed[$key];
            }
        }

        $merged = $answers;
        foreach ($before as $values) {
            $merged = array_replace($merged, $values);
        }
        $merged = array_replace($merged, $stageAnswers);

        $engine = $this->engine($definition, $submission, $settings, $lang, $merged);
        if ($engine === null) {
            return [];
        }
        foreach (array_keys($before) as $key) {
            $engine->enterStage($key);
        }
        $engine->enterStage($stageKey);

        $items = [];
        foreach ($stageNode['children'] as $childKey) {
            $child = $catalog->node((string) $childKey);
            if ($child === null) {
                continue;
            }
            $item = $this->item(
                $child, $engine, $catalog, $submission, $stageAnswers, $stageAnswers,
                $engine->isVisible((string) $childKey), null, $lang, $default, $includePii, $codings,
            );
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    // ------------------------------------------------------------------ origine, qualité, navigation

    /**
     * @return array<string, mixed>
     */
    private function origin(Submission $submission): array
    {
        $enumerator = UserResource::ref($submission->relationLoaded('enumerator') ? $submission->enumerator : null);
        $link = $submission->relationLoaded('publicLink') ? $submission->publicLink : null;
        $channel = $submission->channel?->value ?? SubmissionChannel::Mobile->value;

        $label = match ($channel) {
            SubmissionChannel::Public->value => $link?->label !== null && $link->label !== ''
                ? 'En ligne — lien « '.$link->label.' »'
                : 'En ligne',
            'web' => 'Saisie web',
            default => $enumerator !== null && ($enumerator['name'] ?? '') !== ''
                ? 'Enquêteur : '.$enumerator['name']
                : 'Enquêteur',
        };

        return [
            'channel' => $channel,
            'label' => $label,
            'enumerator' => $enumerator,
            'public_link' => $link === null ? null : [
                'id' => (int) $link->id,
                'label' => $link->label,
                'token_hint' => '…'.mb_substr((string) $link->token, -4),
            ],
            'device' => [
                'device_id' => $submission->device?->device_id,
                'app_version' => $submission->app_version ?? $submission->device?->app_version,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{id: int, fiche_code: string|null}|null
     */
    private function duplicateOf(Submission $submission, array $settings): ?array
    {
        $id = $this->quality->duplicateOf($submission, $settings);
        if ($id === null) {
            return null;
        }

        return [
            'id' => $id,
            'fiche_code' => Submission::query()->whereKey($id)->value('fiche_code'),
        ];
    }

    /**
     * Fiche précédente / suivante dans l'**ordre de réception**, avec les droits du lecteur.
     *
     * @return array{previous_id: int|null, next_id: int|null}
     */
    private function navigation(Submission $submission, ?int $restrictToEnumerator): array
    {
        $receivedAt = $submission->received_at;
        $id = (int) $submission->id;

        $base = fn () => Submission::query()
            ->where('survey_id', $submission->survey_id)
            ->when($restrictToEnumerator !== null, fn ($q) => $q->where('enumerator_id', $restrictToEnumerator));

        if ($receivedAt === null) {
            return [
                'previous_id' => self::intOrNull($base()->where('id', '<', $id)->orderByDesc('id')->value('id')),
                'next_id' => self::intOrNull($base()->where('id', '>', $id)->orderBy('id')->value('id')),
            ];
        }

        $previous = $base()
            ->where(fn ($q) => $q->where('received_at', '<', $receivedAt)
                ->orWhere(fn ($q2) => $q2->where('received_at', $receivedAt)->where('id', '<', $id)))
            ->orderByDesc('received_at')->orderByDesc('id')->value('id');

        $next = $base()
            ->where(fn ($q) => $q->where('received_at', '>', $receivedAt)
                ->orWhere(fn ($q2) => $q2->where('received_at', $receivedAt)->where('id', '>', $id)))
            ->orderBy('received_at')->orderBy('id')->value('id');

        return ['previous_id' => self::intOrNull($previous), 'next_id' => self::intOrNull($next)];
    }

    /**
     * Codage IA par clé de question (même règle de choix de livre de codes que `ReponsesLayout`).
     *
     * @return array<string, array{themes: list<string>, sentiment: string|null}>
     */
    private function codings(Submission $submission, string $lang, string $default): array
    {
        $out = [];
        $best = [];

        foreach ($submission->codings as $coding) {
            $key = (string) $coding->question_key;
            $rank = (int) ($coding->codebook_id ?? 0);
            if (isset($best[$key]) && $best[$key] > $rank) {
                continue;
            }
            $best[$key] = $rank;

            $themes = [];
            foreach (is_array($coding->themes) ? $coding->themes : [] as $theme) {
                if (is_array($theme)) {
                    $label = $theme['label'] ?? null;
                    $themes[] = is_array($label) || is_string($label)
                        ? LabelResolver::resolve($label, $lang, $default, (string) ($theme['key'] ?? ''))
                        : (string) ($theme['key'] ?? '');
                } elseif (is_scalar($theme)) {
                    $themes[] = (string) $theme;
                }
            }

            $out[$key] = [
                'themes' => array_values(array_filter($themes, fn (string $t) => $t !== '')),
                'sentiment' => $coding->sentiment,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ utilitaires

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
