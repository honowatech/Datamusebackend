<?php

namespace App\Services\Survey;

use App\Enums\MediaState;
use App\Enums\SubmissionChannel;
use App\Enums\SubmissionStatus;
use App\Enums\SurveyStatus;
use App\Enums\VersionStatus;
use App\Events\SubmissionReceived;
use App\Models\DeletedSubmission;
use App\Models\Device;
use App\Models\Submission;
use App\Models\SubmissionMedia;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Models\User;
use App\Services\Dfs\EngineContext;
use App\Services\Dfs\FicheCodeGenerator;
use App\Services\Dfs\FormEngine;
use App\Services\Dfs\LogicEvaluator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Réception idempotente des soumissions (POST /mobile/submissions, B-07 ; réutilisé par le canal
 * public B-12 et la saisie web). Chaque élément du lot est traité dans sa **propre transaction** :
 * une soumission en erreur n'annule pas les autres, et la réponse HTTP reste `200`.
 *
 * `$user` peut être **null** : c'est le canal `public` (B-12), où la légitimité vient du lien
 * (`PublicLink::isOpen()`) et non d'une habilitation. La soumission est alors stockée sans
 * `enumerator_id` ni `device_id`, et `_enumerator` vaut `null` dans les expressions.
 *
 * Politique de conflit (plan § 5.3, docs/openapi/survey.yaml `syncSubmissions`) :
 *  - uuid en liste noire (`deleted_submissions`) → `duplicate` ;
 *  - uuid connu dont le serveur porte `validated` / `rejected` (revue web) → **serveur gagne**, `duplicate` ;
 *  - uuid connu, `client_updated_at` pas plus récent → `duplicate` ;
 *  - uuid connu, plus récent, `settings.enumerator_can_edit_after_submit = false` → `duplicate` ;
 *    `true` → remplacement, `updated` ;
 *  - uuid dont la précédente tentative avait été `rejected` par le moteur → `updated` (remplacement) ;
 *  - enquête `closed` → `conflict(survey_closed)` ; enquêteur non assigné → `conflict(not_assigned)` ;
 *    version archivée et `started_at` postérieur à l'archivage → `conflict(version_archived)`
 *    (un entretien commencé avant l'archivage est accepté, README § 15) ;
 *  - payload invalide pour le moteur DFS de `form_version` → `rejected` + `errors`.
 *
 * Effets d'une soumission stockée : `answers` = `FormEngine::payloadAnswers()` (normalisé),
 * `answers_hash`, `geo` complet + colonnes `geo_lat/lng/accuracy`, `duration_seconds`,
 * `device_time_offset_ms`, `language`, `channel`, `received_at`, drapeaux qualité
 * (`SubmissionQualityService`), lignes `submission_media` `pending` pour les médias annoncés,
 * unicité `(survey, fiche_code)` via `FicheCodeGenerator::nextSuffix` (`fiche_code_reassigned`),
 * compteurs `surveys.submissions_count` / `last_submission_at`, puis événement
 * `SubmissionReceived` **après commit** (B-08 suivis, B-09b matérialisation).
 */
class SubmissionSyncService
{
    /** Taille maximale d'un lot (contrat : `submissions` ≤ 50). */
    public const MAX_BATCH = 50;

    /** Durée de mémorisation d'un uuid rejeté par le moteur (pour renvoyer `updated` au renvoi). */
    public const REJECTED_MEMORY_DAYS = 7;

    public function __construct(
        private readonly SubmissionQualityService $quality = new SubmissionQualityService,
        private readonly FicheCodeGenerator $ficheCodes = new FicheCodeGenerator,
    ) {}

    /**
     * Traite un lot, dans l'ordre reçu.
     *
     * @param  list<array<string, mixed>>  $payloads
     * @return list<SubmissionSyncResult>
     */
    public function syncBatch(?User $user, array $payloads, SubmissionChannel $channel = SubmissionChannel::Mobile): array
    {
        $results = [];
        foreach ($payloads as $payload) {
            $results[] = $this->sync($user, is_array($payload) ? $payload : [], $channel);
        }

        return $results;
    }

    /**
     * Traite une soumission et renvoie son résultat (jamais d'exception : toute erreur inattendue
     * devient un `rejected` avec le code `server_error`).
     *
     * @param  array<string, mixed>  $payload
     */
    public function sync(?User $user, array $payload, SubmissionChannel $channel = SubmissionChannel::Mobile): SubmissionSyncResult
    {
        $uuid = strtolower((string) ($payload['uuid'] ?? ''));
        if ($uuid === '') {
            return SubmissionSyncResult::rejected('', [self::issue('/uuid', 'required', 'uuid manquant.')], ['uuid' => ['uuid manquant.']]);
        }

        try {
            return $this->process($user, $uuid, $payload, $channel);
        } catch (Throwable $e) {
            report($e);

            return SubmissionSyncResult::rejected(
                $uuid,
                [self::issue('/', 'server_error', "La soumission n'a pas pu être enregistrée.")],
                ['_server' => ["La soumission n'a pas pu être enregistrée."]],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function process(?User $user, string $uuid, array $payload, SubmissionChannel $channel): SubmissionSyncResult
    {
        // 1. uuid supprimé définitivement côté web : le mobile doit oublier sa copie locale.
        if (DeletedSubmission::has($uuid)) {
            return SubmissionSyncResult::duplicate($uuid);
        }

        $existing = Submission::query()->where('uuid', $uuid)->first();

        $survey = Survey::query()->find((int) ($payload['survey_id'] ?? 0))
            ?? ($existing !== null ? Survey::query()->find($existing->survey_id) : null);

        if ($survey === null) {
            return SubmissionSyncResult::rejected(
                $uuid,
                [self::issue('/survey_id', 'survey_unknown', 'Questionnaire inconnu.')],
                ['survey_id' => ['Questionnaire inconnu.']],
            );
        }

        // 2. Habilitation de collecte (enquêteur assigné, superviseur, analyste, admin).
        //    `$user` null = canal public (B-12) : le lien a déjà été validé par le contrôleur.
        if ($user !== null && ! $user->can('collect', $survey)) {
            return SubmissionSyncResult::conflict($uuid, SubmissionSyncResult::REASON_NOT_ASSIGNED, $existing);
        }

        // 3. Idempotence / politique de conflit sur un uuid déjà reçu.
        $mode = SubmissionSyncResult::ACCEPTED;
        if ($existing !== null) {
            $decision = $this->decideOnExisting($existing, $payload);
            if ($decision !== null) {
                return $decision;
            }
            $mode = SubmissionSyncResult::UPDATED;
        } elseif ($this->wasRejected($uuid)) {
            // Le mobile corrige un payload précédemment refusé par le moteur : c'est un remplacement.
            $mode = SubmissionSyncResult::UPDATED;
        }

        // 4. Conflits d'état du questionnaire / de la version.
        if ($survey->status === SurveyStatus::Closed) {
            return SubmissionSyncResult::conflict($uuid, SubmissionSyncResult::REASON_SURVEY_CLOSED, $existing);
        }

        $version = $this->resolveVersion($survey, $payload, $existing);
        if ($version === null) {
            return SubmissionSyncResult::rejected(
                $uuid,
                [self::issue('/form_version', 'version_unknown', 'Version de formulaire inconnue.')],
                ['form_version' => ['Version de formulaire inconnue.']],
            );
        }
        if ($version->status === VersionStatus::Draft) {
            return SubmissionSyncResult::rejected(
                $uuid,
                [self::issue('/form_version', 'version_not_published', "Cette version n'est pas publiée.")],
                ['form_version' => ["Cette version n'est pas publiée."]],
            );
        }
        if ($version->isArchived() && $this->startedAfterArchival($version, $payload)) {
            return SubmissionSyncResult::conflict($uuid, SubmissionSyncResult::REASON_VERSION_ARCHIVED, $existing);
        }

        // 5. Validation par le moteur DFS de la version indiquée.
        $settings = $version->settings();
        $engine = $this->buildEngine($user, $version, $payload, $settings);
        $errors = $engine->finalize();
        if ($errors !== []) {
            $this->rememberRejected($uuid);

            return SubmissionSyncResult::rejected($uuid, self::toIssues($errors), self::toErrorMap($errors));
        }

        return $this->store($user, $survey, $version, $engine, $payload, $uuid, $mode, $channel, $settings, $existing);
    }

    // ------------------------------------------------------------------ décisions

    /**
     * Renvoie un résultat terminal si l'uuid connu ne doit pas être remplacé, null pour remplacer.
     *
     * @param  array<string, mixed>  $payload
     */
    private function decideOnExisting(Submission $existing, array $payload): ?SubmissionSyncResult
    {
        // Revue web : le serveur gagne toujours.
        if (in_array($existing->status, [SubmissionStatus::Validated, SubmissionStatus::Rejected], true)) {
            return SubmissionSyncResult::duplicate($existing->uuid, $existing, $this->pendingMediaOf($existing));
        }

        $incoming = self::parseTime($payload['client_updated_at'] ?? null);
        $known = $existing->client_updated_at;
        if ($incoming === null || $known === null || ! $incoming->greaterThan($known)) {
            return SubmissionSyncResult::duplicate($existing->uuid, $existing, $this->pendingMediaOf($existing));
        }

        $version = $existing->version ?? SurveyVersion::query()->find($existing->survey_version_id);
        $editable = (bool) (($version?->settings()['enumerator_can_edit_after_submit'] ?? false));
        if (! $editable) {
            return SubmissionSyncResult::duplicate($existing->uuid, $existing, $this->pendingMediaOf($existing));
        }

        return null;
    }

    /**
     * Version DFS visée par `form_version` (défaut : version publiée).
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveVersion(Survey $survey, array $payload, ?Submission $existing): ?SurveyVersion
    {
        $number = (int) ($payload['form_version'] ?? 0);
        if ($number > 0) {
            $version = SurveyVersion::query()->forSurvey($survey->id)->where('version', $number)->first();
            if ($version !== null) {
                return $version;
            }

            return null;
        }

        return $survey->publishedVersion
            ?? ($existing !== null ? SurveyVersion::query()->find($existing->survey_version_id) : null);
    }

    /**
     * Instant d'archivage d'une version = publication de la version suivante (README § 15) ;
     * repli sur `updated_at`. Vrai si l'entretien a commencé **après** cet instant.
     *
     * @param  array<string, mixed>  $payload
     */
    private function startedAfterArchival(SurveyVersion $version, array $payload): bool
    {
        $startedAt = self::parseTime($payload['started_at'] ?? null);
        if ($startedAt === null) {
            return true;
        }

        $next = SurveyVersion::query()
            ->forSurvey($version->survey_id)
            ->where('version', '>', $version->version)
            ->whereNotNull('published_at')
            ->min('published_at');

        $archivedAt = $next !== null ? Carbon::parse($next) : $version->updated_at;

        return $archivedAt === null || $startedAt->greaterThanOrEqualTo($archivedAt);
    }

    // ------------------------------------------------------------------ moteur

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     */
    private function buildEngine(?User $user, SurveyVersion $version, array $payload, array $settings): FormEngine
    {
        $startedAt = (string) ($payload['started_at'] ?? '');
        $endedAt = (string) ($payload['ended_at'] ?? '');

        $ctx = new EngineContext(
            enumeratorId: $user?->id,
            deviceId: isset($payload['device_id']) && is_string($payload['device_id']) ? $payload['device_id'] : null,
            zone: isset($payload['zone']) && is_string($payload['zone']) ? $payload['zone'] : null,
            lang: isset($payload['language']) && is_string($payload['language']) ? $payload['language'] : null,
            startTime: $startedAt !== '' ? $startedAt : null,
            endTime: $endedAt !== '' ? $endedAt : null,
            seq: self::extractSeq($settings, is_string($payload['fiche_code'] ?? null) ? $payload['fiche_code'] : null),
            // Horloge de l'entretien : les `calculate` `today()` / `now()` doivent refléter le terrain,
            // pas l'instant de réception (une synchronisation peut survenir des jours plus tard).
            now: $endedAt !== '' ? $endedAt : null,
            today: $startedAt !== '' ? substr($startedAt, 0, 10) : null,
        );

        $engine = new FormEngine($version->definition ?? [], $ctx);
        $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
        $engine->setAnswers($answers);

        return $engine;
    }

    /**
     * Retrouve le compteur `{NN}` du code fiche client (README § 11) afin que `{"var": "_seq"}`
     * garde la valeur allouée sur l'appareil.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function extractSeq(array $settings, ?string $ficheCode): ?int
    {
        $pattern = $settings['fiche_code']['pattern'] ?? null;
        if (! is_string($pattern) || $pattern === '' || $ficheCode === null || $ficheCode === '') {
            return null;
        }

        $parts = preg_split('/(\{[A-Za-z0-9_]+(?::full)?\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        $hasCounter = false;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\{([A-Za-z0-9_]+)(:full)?\}$/', $part, $m) === 1) {
                if ($m[1] === 'NN' && ($m[2] ?? '') === '') {
                    $hasCounter = true;
                    $regex .= '(?P<seq>\d+)';
                } else {
                    $regex .= '[A-Za-z0-9]+';
                }

                continue;
            }
            $regex .= preg_quote($part, '/');
        }

        if (! $hasCounter) {
            return null;
        }

        // Un suffixe de collision serveur (`-B`, `-AA`…) ne doit pas empêcher la lecture du compteur.
        return preg_match('/^'.$regex.'(?:-[A-Z]+)?$/', $ficheCode, $m) === 1 && isset($m['seq'])
            ? (int) $m['seq']
            : null;
    }

    // ------------------------------------------------------------------ persistance

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     */
    private function store(
        ?User $user,
        Survey $survey,
        SurveyVersion $version,
        FormEngine $engine,
        array $payload,
        string $uuid,
        string $mode,
        SubmissionChannel $channel,
        array $settings,
        ?Submission $existing,
    ): SubmissionSyncResult {
        $answers = $engine->payloadAnswers();
        $status = $engine->status() === FormEngine::STATUS_SCREENED_OUT || ($payload['status'] ?? null) === 'screened_out'
            ? SubmissionStatus::ScreenedOut
            : SubmissionStatus::Submitted;
        $endReason = $engine->endReason() ?? (is_string($payload['end_reason'] ?? null) ? $payload['end_reason'] : null);

        $geo = is_array($payload['geo'] ?? null) ? $payload['geo'] : null;
        $point = self::pointOf($geo);
        $startedAt = self::parseTime($payload['started_at'] ?? null) ?? now();
        $endedAt = self::parseTime($payload['ended_at'] ?? null) ?? $startedAt;
        $clientUpdatedAt = self::parseTime($payload['client_updated_at'] ?? null) ?? $endedAt;

        $device = $this->resolveDevice($user, $payload['device_id'] ?? null);

        [$submission, $reassigned, $pendingMedia, $flags] = DB::transaction(function () use (
            $existing, $uuid, $survey, $version, $user, $device, $channel, $status, $endReason,
            $answers, $geo, $point, $startedAt, $endedAt, $clientUpdatedAt, $payload, $settings, $engine
        ) {
            $submission = $existing ?? new Submission(['uuid' => $uuid]);

            $ficheCode = is_string($payload['fiche_code'] ?? null) && $payload['fiche_code'] !== ''
                ? $payload['fiche_code']
                : $engine->ficheCode();
            $reassigned = false;
            if ($ficheCode !== null) {
                $resolved = $this->uniqueFicheCode($survey->id, $ficheCode, $submission->id);
                $reassigned = $resolved !== $ficheCode;
                $ficheCode = $resolved;
            }

            $submission->forceFill([
                'uuid' => $uuid,
                'survey_id' => $survey->id,
                'survey_version_id' => $version->id,
                'project_id' => $survey->project_id,
                'enumerator_id' => $user?->id,
                'device_id' => $device?->id,
                'channel' => $channel,
                'status' => $status,
                'fiche_code' => $ficheCode,
                'zone' => is_string($payload['zone'] ?? null) ? $payload['zone'] : ($submission->zone ?? null),
                'language' => is_string($payload['language'] ?? null) ? $payload['language'] : ($settings['default_language'] ?? 'fr'),
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration_seconds' => max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp()),
                'geo_lat' => $point['lat'] ?? null,
                'geo_lng' => $point['lng'] ?? null,
                'geo_accuracy' => $point['accuracy'] ?? null,
                'geo' => $geo,
                'answers' => $answers,
                'end_reason' => $endReason,
                'answers_hash' => Submission::hashAnswers($answers),
                'client_updated_at' => $clientUpdatedAt,
                'received_at' => now(),
                'device_time_offset_ms' => isset($payload['device_time_offset_ms']) && is_numeric($payload['device_time_offset_ms'])
                    ? (int) $payload['device_time_offset_ms']
                    : null,
            ])->save();

            $pendingMedia = $this->syncDeclaredMedia($submission, $engine, $payload);
            $flags = $this->quality->apply(
                $submission,
                $settings,
                is_string($payload['started_at'] ?? null) ? $payload['started_at'] : null,
            )['flags'];

            if ($existing === null) {
                $survey->forceFill([
                    'submissions_count' => (int) $survey->submissions_count + 1,
                    'last_submission_at' => $submission->received_at,
                ])->save();
            } else {
                $survey->forceFill(['last_submission_at' => $submission->received_at])->save();
            }

            return [$submission, $reassigned, $pendingMedia, $flags];
        });

        $this->forgetRejected($uuid);

        event(new SubmissionReceived($submission, $existing === null));

        return SubmissionSyncResult::stored($uuid, $mode, $submission, $pendingMedia, $reassigned, $flags);
    }

    /**
     * Code fiche libre dans l'enquête : conserve celui reçu, sinon suffixe `-B`, `-C`… (README § 11).
     */
    public function uniqueFicheCode(int $surveyId, string $code, ?int $ignoreSubmissionId = null): string
    {
        $existing = Submission::query()
            ->where('survey_id', $surveyId)
            ->when($ignoreSubmissionId !== null, fn ($q) => $q->where('id', '!=', $ignoreSubmissionId))
            ->where(fn ($q) => $q->where('fiche_code', $code)->orWhere('fiche_code', 'like', $code.'-%'))
            ->pluck('fiche_code')
            ->filter()
            ->map(fn ($v) => (string) $v)
            ->all();

        return $this->ficheCodes->nextSuffix($code, $existing);
    }

    /**
     * Crée / rafraîchit les lignes `submission_media` annoncées et renvoie les descripteurs encore
     * attendus (`pending_media` du contrat).
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{question_key: string, repeat_index: int|null, sha256: string, upload_url: string}>
     */
    private function syncDeclaredMedia(Submission $submission, FormEngine $engine, array $payload): array
    {
        $catalog = $engine->catalog();
        $declared = is_array($payload['media'] ?? null) ? $payload['media'] : [];

        foreach ($declared as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = (string) ($item['question_key'] ?? '');
            $sha = strtolower((string) ($item['sha256'] ?? ''));
            if ($key === '' || $sha === '' || ! $catalog->has($key)) {
                continue;
            }
            $repeatIndex = isset($item['repeat_index']) && is_numeric($item['repeat_index']) ? (int) $item['repeat_index'] : 0;

            $media = SubmissionMedia::query()->firstOrNew([
                'submission_id' => $submission->id,
                'question_key' => $key,
                'repeat_index' => $repeatIndex,
            ]);

            // Un fichier déjà reçu avec la même empreinte n'est jamais réinitialisé.
            if ($media->exists && $media->isUploaded() && $media->sha256 === $sha) {
                continue;
            }

            $media->forceFill([
                'submission_id' => $submission->id,
                'question_key' => $key,
                'repeat_index' => $repeatIndex,
                'disk' => (string) config('filesystems.survey_media_disk', 'local'),
                'mime' => (string) ($item['mime'] ?? 'application/octet-stream'),
                'size' => (int) ($item['size'] ?? 0),
                'sha256' => $sha,
                'state' => MediaState::Pending,
                'path' => $media->sha256 === $sha ? $media->path : null,
            ])->save();
        }

        return $this->pendingMediaOf($submission->refresh());
    }

    /**
     * @return list<array{question_key: string, repeat_index: int|null, sha256: string, upload_url: string}>
     */
    public function pendingMediaOf(Submission $submission): array
    {
        return $submission->media()
            ->where('state', '!=', MediaState::Uploaded->value)
            ->orderBy('question_key')
            ->orderBy('repeat_index')
            ->get()
            ->map(fn (SubmissionMedia $media) => [
                'question_key' => $media->question_key,
                'repeat_index' => $media->repeat_index,
                'sha256' => $media->sha256,
                'upload_url' => sprintf('/mobile/submissions/%s/media/%s', $submission->uuid, $media->question_key),
            ])
            ->values()
            ->all();
    }

    /**
     * Appareil de l'utilisateur (créé à la volée si le mobile n'a pas encore appelé `/mobile/devices`).
     */
    private function resolveDevice(?User $user, mixed $deviceId): ?Device
    {
        if ($user === null || ! is_string($deviceId) || $deviceId === '') {
            return null;
        }

        $device = Device::query()->firstOrNew(['user_id' => $user->id, 'device_id' => $deviceId]);
        $device->forceFill(['last_seen_at' => now()])->save();

        return $device;
    }

    // ------------------------------------------------------------------ état des uuid

    /**
     * `GET /mobile/submissions/status` : état serveur de chaque uuid pour l'enquêteur courant
     * (schéma `SubmissionStatusItem`).
     *
     * `unknown` (inconnu ou appartenant à quelqu'un d'autre) · `deleted` (supprimée côté web) ·
     * `media_pending` (des médias annoncés manquent) · `complete` (tous les médias reçus) ·
     * `received` (reçue, aucun média attendu).
     *
     * @param  list<string>  $uuids
     * @return list<array<string, mixed>>
     */
    public function statusOf(User $user, array $uuids): array
    {
        $normalized = array_values(array_unique(array_map(
            fn ($uuid) => strtolower(trim((string) $uuid)),
            $uuids,
        )));

        $submissions = Submission::query()
            ->whereIn('uuid', $normalized)
            ->where('enumerator_id', $user->id)
            ->with('media')
            ->get()
            ->keyBy('uuid');

        $deleted = DeletedSubmission::query()->whereIn('uuid', $normalized)->pluck('uuid')->all();
        $deleted = array_fill_keys(array_map('strtolower', $deleted), true);

        $out = [];
        foreach ($normalized as $uuid) {
            if ($uuid === '') {
                continue;
            }
            if (isset($deleted[$uuid])) {
                $out[] = ['uuid' => $uuid, 'state' => 'deleted', 'server_id' => null, 'status' => null, 'fiche_code' => null, 'pending_media' => []];

                continue;
            }
            /** @var Submission|null $submission */
            $submission = $submissions->get($uuid);
            if ($submission === null) {
                $out[] = ['uuid' => $uuid, 'state' => 'unknown', 'server_id' => null, 'status' => null, 'fiche_code' => null, 'pending_media' => []];

                continue;
            }
            $pending = $this->pendingMediaOf($submission);
            $declared = $submission->media->count();
            $state = match (true) {
                $pending !== [] => 'media_pending',
                $declared > 0 => 'complete',
                default => 'received',
            };
            $out[] = [
                'uuid' => $uuid,
                'state' => $state,
                'server_id' => $submission->id,
                'status' => $submission->status->value,
                'fiche_code' => $submission->fiche_code,
                'pending_media' => $pending,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ mémoire des rejets

    private static function rejectedKey(string $uuid): string
    {
        return 'b07:submission-rejected:'.$uuid;
    }

    private function rememberRejected(string $uuid): void
    {
        Cache::put(self::rejectedKey($uuid), true, now()->addDays(self::REJECTED_MEMORY_DAYS));
    }

    private function wasRejected(string $uuid): bool
    {
        return (bool) Cache::get(self::rejectedKey($uuid), false);
    }

    private function forgetRejected(string $uuid): void
    {
        Cache::forget(self::rejectedKey($uuid));
    }

    // ------------------------------------------------------------------ utilitaires

    /**
     * Erreurs du moteur → `DfsIssue[]` du contrat (`{path, code, message}` + `key`/`repeat_index`).
     *
     * @param  array<int, array{key: string, repeat_index?: int, code: string, message: string}>  $errors
     * @return list<array<string, mixed>>
     */
    public static function toIssues(array $errors): array
    {
        $out = [];
        foreach ($errors as $error) {
            $key = (string) ($error['key'] ?? '');
            $index = $error['repeat_index'] ?? null;
            $path = '/answers/'.$key.($index !== null ? '/'.$index : '');
            $out[] = [
                'path' => $path,
                'code' => (string) ($error['code'] ?? 'invalid'),
                'message' => (string) ($error['message'] ?? ''),
                'key' => $key,
                'repeat_index' => $index,
            ];
        }

        return $out;
    }

    /**
     * Même erreurs, indexées par clé de question (`errors {question_key: [message]}`).
     *
     * @param  array<int, array{key: string, repeat_index?: int, code: string, message: string}>  $errors
     * @return array<string, list<string>>
     */
    public static function toErrorMap(array $errors): array
    {
        $out = [];
        foreach ($errors as $error) {
            $key = (string) ($error['key'] ?? '_');
            $out[$key][] = (string) ($error['message'] ?? '');
        }

        return $out;
    }

    /**
     * @return array{path: string, code: string, message: string}
     */
    private static function issue(string $path, string $code, string $message): array
    {
        return ['path' => $path, 'code' => $code, 'message' => $message];
    }

    /**
     * Horodatage client → instant absolu **en UTC**.
     *
     * Les colonnes `timestamp` ne conservent pas le décalage : sans normalisation, deux payloads
     * exprimés dans des fuseaux différents ne seraient plus comparables (`client_updated_at`).
     * L'heure locale du terrain reste lisible dans `geo.*.ts` et dans le payload d'origine, et le
     * `SubmissionQualityService` la reçoit explicitement pour le drapeau `off_hours`.
     */
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

    /**
     * Position retenue pour les colonnes `geo_*` : capture de début, à défaut de fin.
     *
     * @param  array<string, mixed>|null  $geo
     * @return array{lat: float, lng: float, accuracy: float|null}|null
     */
    private static function pointOf(?array $geo): ?array
    {
        foreach (['start', 'end'] as $slot) {
            $point = $geo[$slot] ?? null;
            if (is_array($point) && isset($point['lat'], $point['lng']) && ! LogicEvaluator::isEmpty($point['lat'])) {
                return [
                    'lat' => (float) $point['lat'],
                    'lng' => (float) $point['lng'],
                    'accuracy' => isset($point['accuracy']) && is_numeric($point['accuracy']) ? (float) $point['accuracy'] : null,
                ];
            }
        }

        return null;
    }
}
