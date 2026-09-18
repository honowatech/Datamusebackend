<?php

namespace App\Http\Controllers\Survey;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Survey\ReviewSubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Http\Resources\UserResource;
use App\Jobs\MaterializeSurveyDatasourceJob;
use App\Models\DeletedSubmission;
use App\Models\Submission;
use App\Models\SubmissionMedia;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Services\Dfs\LabelResolver;
use App\Services\Dfs\QuestionCatalog;
use App\Services\Survey\SubmissionFilter;
use App\Services\Survey\SubmissionQualityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Soumissions côté web (B-10, contrat : tag Soumissions).
 *
 * - `GET /surveys/{id}/submissions` — liste filtrée et paginée (`SubmissionSummary`). Un superviseur
 *   voit tout le questionnaire ; un enquêteur affecté ne voit que **ses** fiches.
 * - `GET /submissions/{id}` — détail (`SubmissionDetail`) : réponses, libellés, médias avec URL signée
 *   15 min, entrées de suivi, codages de verbatims, explication des drapeaux, appareil.
 * - `PATCH /submissions/{id}` — revue qualité (`status`, `quality_notes`, `reviewed_by/at`). Idempotent.
 * - `DELETE /submissions/{id}` — suppression définitive ; l'uuid passe par `DeletedSubmission::remember()`
 *   pour que le mobile reçoive `duplicate` s'il le renvoie, et les fichiers médias sont effacés.
 *
 * `PATCH` et `DELETE` marquent la datasource `dirty` et mettent un rebuild en file
 * (`MaterializeSurveyDatasourceJob::refresh`).
 */
class SubmissionController extends ApiController
{
    /** Colonnes de tri autorisées (contrat : défaut `-received_at`). */
    public const SORTABLE = ['ended_at', 'received_at', 'duration_seconds', 'suspicion_score', 'fiche_code'];

    /** Validité de l'URL signée d'un média (contrat : 15 min). */
    public const MEDIA_URL_MINUTES = 15;

    public function __construct(
        private readonly SubmissionQualityService $quality,
        private readonly SubmissionFilter $filter,
    ) {}

    public function index(Request $request, Survey $survey): JsonResponse
    {
        $user = $request->user();
        $supervisor = $user->can('viewSubmissions', $survey);
        if (! $supervisor) {
            // Un enquêteur affecté accède à la liste, restreinte à ses propres fiches.
            Gate::authorize('collect', $survey);
        }

        $query = $this->filtered($survey, $request, $supervisor ? null : $user->id);

        [$column, $direction] = $this->sort($request, self::SORTABLE, '-received_at');
        $query->orderBy($column, $direction)->orderBy('id', $direction);

        $paginator = $query->paginate($this->perPage($request))->withQueryString();

        $versions = $this->versionNumbers($survey);
        foreach ($paginator->items() as $submission) {
            $submission->setAttribute('version_number', $versions[$submission->survey_version_id] ?? null);
        }

        return $this->paginated($paginator, SubmissionResource::class);
    }

    public function show(Request $request, Submission $submission): JsonResponse
    {
        Gate::authorize('view', $submission);

        $submission->loadMissing(['enumerator', 'reviewer', 'version', 'media', 'followUps.enumerator', 'codings', 'device']);
        $settings = $submission->version?->settings() ?? [];

        $data = SubmissionResource::summary($submission) + [
            'answers' => is_array($submission->answers) ? $submission->answers : [],
            'labels' => $this->labels($submission, (string) $request->query('lang', $submission->language ?? 'fr')),
            'media' => $this->media($submission),
            'follow_ups_entries' => $this->followUps($submission),
            'codings' => $this->codings($submission),
            'flag_details' => $this->quality->flagDetails($submission, $settings),
            'device' => [
                'device_id' => $submission->device?->device_id,
                'app_version' => $submission->device?->app_version,
                'time_offset_ms' => $submission->device_time_offset_ms,
            ],
        ];

        return $this->ok($data);
    }

    public function update(ReviewSubmissionRequest $request, Submission $submission): JsonResponse
    {
        Gate::authorize('update', $submission);

        $changes = [];
        if ($request->has('status')) {
            $changes['status'] = SubmissionStatus::from((string) $request->input('status'));
        }
        if ($request->has('quality_notes')) {
            $changes['quality_notes'] = $request->input('quality_notes');
        }

        if ($changes !== []) {
            $submission->forceFill($changes + [
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            MaterializeSurveyDatasourceJob::refresh($submission->survey_id);
        }

        $submission->refresh()->loadMissing(['enumerator', 'reviewer', 'version', 'media', 'followUps']);

        return $this->ok(SubmissionResource::summary($submission));
    }

    public function destroy(Request $request, Submission $submission): Response
    {
        Gate::authorize('delete', $submission);

        $surveyId = $submission->survey_id;

        DeletedSubmission::remember($submission, $request->user()->id);
        $this->purgeMedia($submission);
        $submission->delete();

        Survey::query()->whereKey($surveyId)->where('submissions_count', '>', 0)->decrement('submissions_count');
        MaterializeSurveyDatasourceJob::refresh($surveyId);

        return response()->noContent();
    }

    // ------------------------------------------------------------------ filtres

    /**
     * Requête filtrée + relations nécessaires au schéma `SubmissionSummary`.
     *
     * @return Builder<Submission>
     */
    private function filtered(Survey $survey, Request $request, ?int $restrictToEnumerator = null): Builder
    {
        return $this->filter->apply($survey, $request, $restrictToEnumerator)
            ->with([
                'enumerator:id,name',
                'reviewer:id,name',
                'followUps:id,submission_id,status',
            ])
            ->withCount(['media as media_pending_count' => fn ($q) => $q->where('state', '!=', 'uploaded')]);
    }

    /**
     * @return array<int, int> id de version => numéro
     */
    private function versionNumbers(Survey $survey): array
    {
        return SurveyVersion::query()
            ->where('survey_id', $survey->id)
            ->pluck('version', 'id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    // ------------------------------------------------------------------ détail

    /**
     * Libellés affichables : `{clé de question}` → libellé, `{clé}.{code}` → libellé du choix
     * effectivement utilisé dans les réponses.
     *
     * @return array<string, string>
     */
    private function labels(Submission $submission, string $lang): array
    {
        $version = $submission->version;
        if ($version === null) {
            return [];
        }

        $catalog = QuestionCatalog::fromDefinition($version->definition ?? []);
        $default = $catalog->defaultLanguage();
        $answers = is_array($submission->answers) ? $submission->answers : [];
        $out = [];

        foreach ($catalog->all() as $key => $info) {
            $node = $catalog->node((string) $key);
            $out[(string) $key] = LabelResolver::resolve($node['def']['label'] ?? (string) $key, $lang, $default, (string) $key);

            $value = $answers[$key] ?? null;
            if ($value === null || ($info['choices'] ?? null) === null) {
                continue;
            }
            $codes = is_array($value) ? $value : [$value];
            foreach ($info['choices'] as $choice) {
                if (! is_array($choice) || ! isset($choice['name'])) {
                    continue;
                }
                $code = (string) $choice['name'];
                if (in_array($code, array_map(fn ($c) => is_scalar($c) ? (string) $c : '', $codes), true)) {
                    $out[$key.'.'.$code] = LabelResolver::resolve($choice['label'] ?? $code, $lang, $default, $code);
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function media(Submission $submission): array
    {
        return $submission->media
            ->sortBy(['question_key', 'repeat_index'])
            ->map(fn (SubmissionMedia $media) => [
                'id' => $media->id,
                'question_key' => $media->question_key,
                'repeat_index' => $media->repeat_index,
                'mime' => $media->mime,
                'size' => (int) $media->size,
                'sha256' => $media->sha256,
                'state' => $media->state?->value,
                'signed_url' => $media->isUploaded() ? $media->signedUrl(self::MEDIA_URL_MINUTES) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function followUps(Submission $submission): array
    {
        return $submission->followUps
            ->sortBy('due_at')
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'stage_key' => $entry->stage_key,
                'status' => $entry->status?->value,
                'due_at' => $entry->due_at?->toIso8601String(),
                'window_ends_at' => $entry->window_ends_at?->toIso8601String(),
                'completed_at' => $entry->completed_at?->toIso8601String(),
                'enumerator' => UserResource::ref($entry->relationLoaded('enumerator') ? $entry->enumerator : null),
                'answers' => is_array($entry->answers) ? $entry->answers : [],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function codings(Submission $submission): array
    {
        return $submission->codings
            ->map(fn ($coding) => [
                'id' => $coding->id,
                'submission_id' => $coding->submission_id,
                'question_key' => $coding->question_key,
                'codebook_id' => $coding->codebook_id,
                'themes' => is_array($coding->themes) ? $coding->themes : [],
                'sentiment' => $coding->sentiment,
                'confidence' => $coding->confidence !== null ? (float) $coding->confidence : null,
                'source' => $coding->source instanceof \BackedEnum ? $coding->source->value : $coding->source,
            ])
            ->values()
            ->all();
    }

    /** Efface les fichiers médias avant la suppression en cascade des lignes. */
    private function purgeMedia(Submission $submission): void
    {
        foreach ($submission->media as $media) {
            if ($media->path === null) {
                continue;
            }
            try {
                $media->storage()->delete($media->path);
            } catch (Throwable) {
                // Fichier déjà absent ou disque indisponible : la ligne est supprimée quand même.
            }
        }
    }
}
