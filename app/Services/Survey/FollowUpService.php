<?php

namespace App\Services\Survey;

use App\Enums\FollowUpStatus;
use App\Enums\SurveyStatus;
use App\Models\FollowUpEntry;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Models\User;
use App\Services\Dfs\EngineContext;
use App\Services\Dfs\FormEngine;
use App\Services\Dfs\LogicEvaluator;
use App\Services\Dfs\QuestionCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Étapes de suivi longitudinal (README DFS § 14, tâche B-08).
 *
 * Création (`SubmissionReceived` → `App\Listeners\CreateFollowUpEntries`) : une entrée **par étape**
 * du formulaire, `due_at = ended_at + due_offset_days` (même heure), `window_ends_at = due_at +
 * window_days`. `relevant` est évalué sur les réponses de base :
 *   - vrai ou absent                                          → `pending` ;
 *   - faux                                                    → `skipped` ;
 *   - faux mais l'expression référence une clé d'une **autre** étape non encore complétée
 *     (pertinence indéterminée, ex. « J+14 seulement si retiré à J+7 ») → `pending`.
 * Aucune entrée n'est créée pour une soumission `screened_out`.
 *
 * Échéance (`follow-ups:mark-missed`, horaire) : `relevant` est **réévalué** avec les réponses de base
 * et celles des étapes déjà complétées (faux → `skipped`) ; une entrée `pending` dont la fenêtre est
 * dépassée devient `missed`.
 *
 * Collecte (`GET /mobile/follow-ups/due`, `POST /mobile/follow-ups`) : les réponses d'étape sont
 * validées par `FormEngine::enterStage()` puis stockées dans `follow_up_entries.answers`
 * (aucune `Submission` supplémentaire n'est créée).
 */
class FollowUpService
{
    /** Taille maximale d'un lot de réponses de suivi (contrat : `entries` ≤ 50). */
    public const MAX_BATCH = 50;

    /** Tolérance de rattrapage d'une entrée `missed` (au-delà : décision manuelle). */
    public const LATE_GRACE_DAYS = 7;

    /** Fenêtre par défaut de `GET /mobile/follow-ups/due` (en retard incluses). */
    public const DUE_DEFAULT_PAST_DAYS = 30;

    public const DUE_DEFAULT_FUTURE_DAYS = 7;

    /** Séparateur de `respondent_label` (contrat `FollowUpDue`). */
    public const LABEL_SEPARATOR = ' · ';

    // ==================================================================== création

    /**
     * Crée / met à jour les entrées de suivi d'une soumission (jamais d'exception : toute erreur est
     * rapportée et ignorée pour ne pas faire échouer la synchronisation).
     */
    public function syncEntriesFor(Submission $submission): void
    {
        try {
            $this->createEntries($submission);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function createEntries(Submission $submission): void
    {
        if ($submission->isScreenedOut()) {
            // Une soumission devenue hors cible ne porte aucun suivi (README § 14) : on retire les
            // entrées encore ouvertes d'une éventuelle réception antérieure.
            FollowUpEntry::query()
                ->where('submission_id', $submission->id)
                ->where('status', FollowUpStatus::Pending->value)
                ->delete();

            return;
        }

        $version = $this->versionOf($submission);
        $stages = $this->stagesOf($version);
        if ($stages === []) {
            return;
        }

        $existing = FollowUpEntry::query()->where('submission_id', $submission->id)->get()->keyBy('stage_key');
        $completed = $this->completedStages($existing);
        $engine = $this->engineFor($submission, $version, $this->completedAnswers($existing));
        $catalog = $engine->catalog();

        $endedAt = $submission->ended_at ?? $submission->received_at ?? Carbon::now();

        foreach ($stages as $stage) {
            $stageKey = (string) $stage['key'];
            /** @var FollowUpEntry|null $entry */
            $entry = $existing->get($stageKey);

            // Une entrée complétée ou manquée n'est jamais recalculée par une simple réception.
            if ($entry !== null && in_array($entry->status, [FollowUpStatus::Done, FollowUpStatus::Missed], true)) {
                continue;
            }

            $dueAt = $endedAt->copy()->addDays((int) ($stage['due_offset_days'] ?? 0));
            $windowEndsAt = $dueAt->copy()->addDays((int) ($stage['window_days'] ?? 0));

            ($entry ?? new FollowUpEntry)->forceFill([
                'submission_id' => $submission->id,
                'survey_id' => $submission->survey_id,
                'stage_key' => $stageKey,
                'enumerator_id' => $submission->enumerator_id,
                'due_at' => $dueAt,
                'window_ends_at' => $windowEndsAt,
                'status' => $this->resolveStatus($engine, $catalog, $stage, $completed),
            ])->save();
        }
    }

    // ==================================================================== échéances

    /**
     * Commande `follow-ups:mark-missed` : réévalue les entrées échues puis marque `missed` celles dont
     * la fenêtre est dépassée.
     *
     * @return array{skipped: int, missed: int}
     */
    public function markMissed(?Carbon $at = null): array
    {
        $at ??= Carbon::now();

        $skipped = $this->reevaluateDue($at);

        $missed = FollowUpEntry::query()
            ->pending()
            ->where('window_ends_at', '<', $at)
            ->update(['status' => FollowUpStatus::Missed->value, 'updated_at' => Carbon::now()]);

        return ['skipped' => $skipped, 'missed' => (int) $missed];
    }

    /**
     * Réévalue `relevant` des entrées `pending` échues avec les réponses de base et des étapes déjà
     * complétées ; renvoie le nombre d'entrées devenues `skipped`.
     */
    private function reevaluateDue(Carbon $at): int
    {
        $submissionIds = FollowUpEntry::query()
            ->pending()
            ->where('due_at', '<=', $at)
            ->distinct()
            ->pluck('submission_id');

        $skipped = 0;
        foreach ($submissionIds->chunk(100) as $chunk) {
            $submissions = Submission::query()->with('version')->whereIn('id', $chunk->all())->get();
            foreach ($submissions as $submission) {
                $skipped += $this->reevaluate($submission);
            }
        }

        return $skipped;
    }

    /**
     * Réévalue les entrées `pending` d'une soumission (après complétion d'une étape ou à l'échéance).
     * Renvoie le nombre d'entrées passées à `skipped`.
     */
    public function reevaluate(Submission $submission, ?SurveyVersion $version = null): int
    {
        try {
            $version ??= $this->versionOf($submission);
            $stages = $this->stagesOf($version);
            if ($stages === []) {
                return 0;
            }

            $entries = FollowUpEntry::query()->where('submission_id', $submission->id)->get()->keyBy('stage_key');
            $completed = $this->completedStages($entries);
            $engine = $this->engineFor($submission, $version, $this->completedAnswers($entries));
            $catalog = $engine->catalog();

            $skipped = 0;
            foreach ($stages as $stage) {
                /** @var FollowUpEntry|null $entry */
                $entry = $entries->get((string) $stage['key']);
                if ($entry === null || ! $entry->isPending()) {
                    continue;
                }
                if ($this->resolveStatus($engine, $catalog, $stage, $completed) === FollowUpStatus::Skipped) {
                    $entry->forceFill(['status' => FollowUpStatus::Skipped])->save();
                    $skipped++;
                }
            }

            return $skipped;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    // ==================================================================== lecture

    /**
     * `GET /mobile/follow-ups/due` : entrées `pending` de l'enquêteur dont `due_at` tombe dans
     * `[from, to]` (schéma `FollowUpDue`).
     *
     * @return list<array<string, mixed>>
     */
    public function due(User $user, ?Carbon $from = null, ?Carbon $to = null, ?int $surveyId = null): array
    {
        $from ??= Carbon::now()->subDays(self::DUE_DEFAULT_PAST_DAYS)->startOfDay();
        $to ??= Carbon::now()->addDays(self::DUE_DEFAULT_FUTURE_DAYS)->endOfDay();

        $entries = FollowUpEntry::query()
            ->pending()
            ->forEnumerator($user->id)
            ->whereBetween('due_at', [$from, $to])
            ->when($surveyId !== null, fn ($q) => $q->where('survey_id', $surveyId))
            ->with(['submission.version'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $siblings = $entries->isEmpty()
            ? collect()
            : FollowUpEntry::query()
                ->whereIn('submission_id', $entries->pluck('submission_id')->unique()->all())
                ->get()
                ->groupBy('submission_id');

        $out = [];
        foreach ($entries as $entry) {
            $submission = $entry->submission;
            if ($submission === null) {
                continue;
            }
            $version = $submission->version;
            if ($version === null) {
                continue;
            }

            $stage = $this->stageOf($version, $entry->stage_key);
            if ($stage === null) {
                continue;
            }

            $answers = is_array($submission->answers) ? $submission->answers : [];
            $settings = $version->settings();
            $catalog = QuestionCatalog::fromDefinition($version->definition ?? []);

            $out[] = [
                'id' => $entry->id,
                'parent_submission_uuid' => $submission->uuid,
                'parent_submission_id' => $submission->id,
                'survey_id' => $entry->survey_id,
                'version' => $version->version,
                'stage_key' => $entry->stage_key,
                'stage_label' => $this->stageLabel($stage, $settings),
                'due_at' => $entry->due_at?->toIso8601String(),
                'window_ends_at' => $entry->window_ends_at?->toIso8601String(),
                'status' => $entry->status->value,
                'respondent_label' => $this->respondentLabel($settings, $answers, $catalog),
                'fiche_code' => $submission->fiche_code,
                'zone' => $submission->zone,
                'parent_answers_subset' => $this->answersSubset($stage, $settings, $answers, $catalog),
                'completed_stages' => $this->completedStages($siblings->get($submission->id, collect())),
            ];
        }

        return $out;
    }

    // ==================================================================== écriture

    /**
     * `POST /mobile/follow-ups` : traite un lot, dans l'ordre reçu.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<FollowUpSyncResult>
     */
    public function syncBatch(User $user, array $entries): array
    {
        $results = [];
        foreach ($entries as $entry) {
            $results[] = $this->sync($user, is_array($entry) ? $entry : []);
        }

        return $results;
    }

    /**
     * Traite une réponse de suivi (jamais d'exception : toute erreur inattendue devient un `rejected`).
     *
     * @param  array<string, mixed>  $payload
     */
    public function sync(User $user, array $payload): FollowUpSyncResult
    {
        $uuid = strtolower((string) ($payload['uuid'] ?? ''));

        try {
            return $this->process($user, $uuid, $payload);
        } catch (Throwable $e) {
            report($e);

            return FollowUpSyncResult::rejected(
                $uuid,
                [self::issue('/', 'server_error', "Le suivi n'a pas pu être enregistré.")],
                ['_server' => ["Le suivi n'a pas pu être enregistré."]],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function process(User $user, string $uuid, array $payload): FollowUpSyncResult
    {
        $stageKey = (string) ($payload['followup_stage'] ?? '');
        $parentUuid = strtolower((string) ($payload['parent_submission_uuid'] ?? ''));

        $parent = Submission::query()->with('version')->where('uuid', $parentUuid)->first();
        if ($parent === null || $parent->isScreenedOut()) {
            return FollowUpSyncResult::conflict($uuid, FollowUpSyncResult::REASON_PARENT_UNKNOWN, $parentUuid, $stageKey);
        }

        $survey = Survey::query()->find($parent->survey_id);
        if ($survey === null) {
            return FollowUpSyncResult::conflict($uuid, FollowUpSyncResult::REASON_PARENT_UNKNOWN, $parentUuid, $stageKey);
        }
        if (! $user->can('collect', $survey)) {
            return FollowUpSyncResult::conflict($uuid, FollowUpSyncResult::REASON_NOT_ASSIGNED, $parentUuid, $stageKey);
        }
        if ($survey->status === SurveyStatus::Closed) {
            return FollowUpSyncResult::conflict($uuid, FollowUpSyncResult::REASON_SURVEY_CLOSED, $parentUuid, $stageKey);
        }

        $version = $this->versionOf($parent);
        $stage = $this->stageOf($version, $stageKey);
        if ($stage === null) {
            return FollowUpSyncResult::rejected(
                $uuid,
                [self::issue('/followup_stage', 'stage_unknown', 'Étape de suivi inconnue.')],
                ['followup_stage' => ['Étape de suivi inconnue.']],
                $parentUuid,
                $stageKey,
            );
        }

        $entries = FollowUpEntry::query()->where('submission_id', $parent->id)->get()->keyBy('stage_key');
        /** @var FollowUpEntry|null $entry */
        $entry = $entries->get($stageKey);
        $settings = $version->settings();
        $clientUpdatedAt = self::parseTime($payload['client_updated_at'] ?? null) ?? Carbon::now();

        // 1. Idempotence / politique de conflit sur l'état courant de l'entrée.
        $mode = FollowUpSyncResult::ACCEPTED;
        if ($entry !== null) {
            switch ($entry->status) {
                case FollowUpStatus::Done:
                    $editable = (bool) ($settings['enumerator_can_edit_after_submit'] ?? false);
                    if (! $editable || $entry->client_updated_at === null || ! $clientUpdatedAt->greaterThan($entry->client_updated_at)) {
                        return FollowUpSyncResult::duplicate($uuid, $entry, $parentUuid);
                    }
                    $mode = FollowUpSyncResult::UPDATED;
                    break;
                case FollowUpStatus::Skipped:
                    return FollowUpSyncResult::conflict($uuid, FollowUpSyncResult::REASON_STAGE_SKIPPED, $parentUuid, $stageKey, $entry);
                case FollowUpStatus::Missed:
                    // Rattrapage toléré tant que la fenêtre n'est pas dépassée de plus de 7 jours.
                    if ($entry->window_ends_at !== null && $entry->window_ends_at->lt(Carbon::now()->subDays(self::LATE_GRACE_DAYS))) {
                        return FollowUpSyncResult::conflict($uuid, FollowUpSyncResult::REASON_STAGE_MISSED, $parentUuid, $stageKey, $entry);
                    }
                    break;
                default:
                    break;
            }
        }

        // 2. Pertinence de l'étape, réévaluée avec les réponses de base et des étapes complétées.
        $completed = $this->completedStages($entries);
        $completedAnswers = $this->completedAnswers($entries);
        $engine = $this->engineFor($parent, $version, $completedAnswers);
        if (! $engine->stageRelevant($stageKey, $completed)) {
            $entry?->forceFill(['status' => FollowUpStatus::Skipped])->save();

            return FollowUpSyncResult::conflict($uuid, FollowUpSyncResult::REASON_STAGE_SKIPPED, $parentUuid, $stageKey, $entry);
        }

        // 3. Validation des réponses de l'étape par le moteur DFS de la version parente.
        $engine->enterStage($stageKey);
        $engine->setAnswers(is_array($payload['answers'] ?? null) ? $payload['answers'] : []);
        $errors = $engine->validate();
        if ($errors !== []) {
            return FollowUpSyncResult::rejected(
                $uuid,
                SubmissionSyncService::toIssues($errors),
                SubmissionSyncService::toErrorMap($errors),
                $parentUuid,
                $stageKey,
                $entry,
            );
        }

        // 4. Persistance de l'entrée puis réévaluation des étapes suivantes.
        $dueAt = ($parent->ended_at ?? $parent->received_at ?? Carbon::now())->copy()->addDays((int) ($stage['due_offset_days'] ?? 0));
        $answers = $engine->payloadAnswers();

        $entry = DB::transaction(function () use ($entry, $parent, $stageKey, $stage, $dueAt, $answers, $clientUpdatedAt, $user, $payload) {
            $target = $entry ?? new FollowUpEntry;
            $target->forceFill([
                'submission_id' => $parent->id,
                'survey_id' => $parent->survey_id,
                'stage_key' => $stageKey,
                'enumerator_id' => $target->enumerator_id ?? $parent->enumerator_id ?? $user->id,
                'due_at' => $target->due_at ?? $dueAt,
                'window_ends_at' => $target->window_ends_at ?? $dueAt->copy()->addDays((int) ($stage['window_days'] ?? 0)),
                'status' => FollowUpStatus::Done,
                'answers' => $answers,
                'completed_at' => self::parseTime($payload['ended_at'] ?? null) ?? Carbon::now(),
                'client_updated_at' => $clientUpdatedAt,
            ])->save();

            return $target;
        });

        $this->reevaluate($parent, $version);

        return FollowUpSyncResult::stored($uuid, $mode, $entry->refresh(), $parentUuid);
    }

    // ==================================================================== moteur / définition

    private function versionOf(Submission $submission): SurveyVersion
    {
        $version = $submission->version ?? SurveyVersion::query()->find($submission->survey_version_id);
        if ($version === null) {
            throw new \RuntimeException("Version de formulaire introuvable pour la soumission #{$submission->id}.");
        }

        return $version;
    }

    /**
     * Étapes de suivi déclarées par une version (liste, dans l'ordre du document).
     *
     * @return list<array<string, mixed>>
     */
    private function stagesOf(SurveyVersion $version): array
    {
        $stages = $version->definition['follow_up_stages'] ?? [];

        return is_array($stages)
            ? array_values(array_filter($stages, static fn ($s) => is_array($s) && isset($s['key'])))
            : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stageOf(SurveyVersion $version, ?string $stageKey): ?array
    {
        if ($stageKey === null || $stageKey === '') {
            return null;
        }
        foreach ($this->stagesOf($version) as $stage) {
            if ((string) $stage['key'] === $stageKey) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Moteur positionné sur les réponses de base + celles des étapes déjà complétées.
     *
     * @param  array<string, mixed>  $stageAnswers
     */
    private function engineFor(Submission $submission, SurveyVersion $version, array $stageAnswers = []): FormEngine
    {
        $ctx = new EngineContext(
            enumeratorId: $submission->enumerator_id,
            zone: $submission->zone,
            lang: $submission->language,
            startTime: $submission->started_at?->toIso8601String(),
            endTime: $submission->ended_at?->toIso8601String(),
            status: FormEngine::STATUS_COMPLETED,
            seq: SubmissionSyncService::extractSeq($version->settings(), $submission->fiche_code),
        );

        $engine = new FormEngine($version->definition ?? [], $ctx);
        $engine->setAnswers(array_merge(
            is_array($submission->answers) ? $submission->answers : [],
            $stageAnswers,
        ));

        return $engine;
    }

    /**
     * Statut d'une étape selon README § 14 (faux + référence indéterminée à une autre étape → `pending`).
     *
     * @param  array<string, mixed>  $stage
     * @param  list<string>  $completed
     */
    private function resolveStatus(FormEngine $engine, QuestionCatalog $catalog, array $stage, array $completed): FollowUpStatus
    {
        $stageKey = (string) $stage['key'];
        if ($engine->stageRelevant($stageKey, $completed)) {
            return FollowUpStatus::Pending;
        }

        return $this->isIndeterminate($catalog, $stage['relevant'] ?? null, $stageKey, $completed)
            ? FollowUpStatus::Pending
            : FollowUpStatus::Skipped;
    }

    /**
     * Vrai si l'expression référence une clé appartenant à une autre étape non encore complétée
     * (la pertinence ne peut pas être tranchée : l'entrée reste `pending`).
     *
     * @param  list<string>  $completed
     */
    private function isIndeterminate(QuestionCatalog $catalog, mixed $expr, string $stageKey, array $completed): bool
    {
        foreach (LogicEvaluator::referencedKeys($expr) as $key) {
            $stage = $catalog->get($key)['stage'] ?? null;
            if ($stage !== null && $stage !== $stageKey && ! in_array($stage, $completed, true)) {
                return true;
            }
        }

        return false;
    }

    // ==================================================================== payload

    /**
     * `respondent_label` : valeurs des `settings.followup_contact_keys` jointes par « · ».
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $answers
     */
    private function respondentLabel(array $settings, array $answers, QuestionCatalog $catalog): string
    {
        $parts = [];
        foreach ($this->contactKeys($settings) as $key) {
            $value = $answers[$key] ?? null;
            if (LogicEvaluator::isEmpty($value)) {
                continue;
            }
            $parts[] = $this->displayValue($catalog, $key, $value);
        }

        return implode(self::LABEL_SEPARATOR, array_filter($parts, static fn (string $p) => $p !== ''));
    }

    /**
     * `parent_answers_subset` : réponses de base nécessaires aux expressions de l'étape
     * (`relevant` / `constraint` / `required` / `default` / `expression` de l'étape et de ses items)
     * plus les clés de contact.
     *
     * @param  array<string, mixed>  $stage
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function answersSubset(array $stage, array $settings, array $answers, QuestionCatalog $catalog): array
    {
        $needed = LogicEvaluator::referencedKeys($stage['relevant'] ?? null);

        foreach ($stage['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['relevant', 'constraint', 'required', 'default', 'expression'] as $field) {
                $needed = array_merge($needed, LogicEvaluator::referencedKeys($item[$field] ?? null));
            }
        }

        $needed = array_merge($needed, $this->contactKeys($settings));

        $subset = [];
        foreach (array_unique($needed) as $key) {
            // Les clés d'une autre étape ne sont pas des réponses de base : elles arrivent par
            // `completed_stages` côté mobile.
            if (($catalog->get($key)['stage'] ?? null) !== null) {
                continue;
            }
            if (array_key_exists($key, $answers)) {
                $subset[$key] = $answers[$key];
            }
        }

        return $subset;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function contactKeys(array $settings): array
    {
        $keys = $settings['followup_contact_keys'] ?? [];

        return is_array($keys) ? array_values(array_map('strval', array_filter($keys, 'is_string'))) : [];
    }

    /** Libellé affichable d'une réponse (libellé de choix pour les `select_*`). */
    private function displayValue(QuestionCatalog $catalog, string $key, mixed $value): string
    {
        $info = $catalog->get($key);
        $choices = $info['choices'] ?? null;

        if ($choices !== null) {
            $codes = LogicEvaluator::isList($value) ? $value : [$value];
            $labels = [];
            foreach ($codes as $code) {
                $labels[] = self::choiceLabel($choices, LogicEvaluator::toStr($code), $catalog->defaultLanguage());
            }

            return implode(', ', $labels);
        }

        return LogicEvaluator::isObject($value) || LogicEvaluator::isList($value) ? '' : LogicEvaluator::toStr($value);
    }

    /**
     * @param  array<int, array<string, mixed>>  $choices
     */
    private static function choiceLabel(array $choices, string $code, string $lang): string
    {
        foreach ($choices as $choice) {
            $choice = (array) $choice;
            if ((string) ($choice['name'] ?? '') === $code) {
                $label = $choice['label'] ?? null;

                return is_array($label) ? (string) ($label[$lang] ?? reset($label) ?: $code) : (string) ($label ?? $code);
            }
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $stage
     * @param  array<string, mixed>  $settings
     */
    private function stageLabel(array $stage, array $settings): string
    {
        $label = $stage['label'] ?? null;
        $lang = (string) ($settings['default_language'] ?? 'fr');

        if (is_array($label)) {
            $value = $label[$lang] ?? (is_string(reset($label)) ? reset($label) : null);

            return is_string($value) ? $value : (string) $stage['key'];
        }

        return is_string($label) ? $label : (string) $stage['key'];
    }

    // ==================================================================== utilitaires

    /**
     * @param  Collection<array-key, FollowUpEntry>  $entries
     * @return list<string>
     */
    private function completedStages($entries): array
    {
        return $entries
            ->filter(fn (FollowUpEntry $e) => $e->status === FollowUpStatus::Done)
            ->map(fn (FollowUpEntry $e) => (string) $e->stage_key)
            ->values()
            ->all();
    }

    /**
     * Réponses des étapes déjà complétées, fusionnées (lisibles par les expressions des suivantes).
     *
     * @param  Collection<array-key, FollowUpEntry>  $entries
     * @return array<string, mixed>
     */
    private function completedAnswers($entries): array
    {
        $answers = [];
        foreach ($entries as $entry) {
            if ($entry->status === FollowUpStatus::Done && is_array($entry->answers)) {
                $answers = array_merge($answers, $entry->answers);
            }
        }

        return $answers;
    }

    /**
     * @return array{path: string, code: string, message: string}
     */
    private static function issue(string $path, string $code, string $message): array
    {
        return ['path' => $path, 'code' => $code, 'message' => $message];
    }

    private static function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
