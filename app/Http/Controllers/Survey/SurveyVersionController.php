<?php

namespace App\Http\Controllers\Survey;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Survey\SaveDraftRequest;
use App\Http\Requests\Survey\SyncAssignmentsRequest;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\SurveyVersionResource;
use App\Models\EnumeratorAssignment;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Services\Survey\SurveyVersionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Versions DFS (brouillon, validation, publication, fork) et assignations (contrat : tags Versions, Assignations).
 */
class SurveyVersionController extends ApiController
{
    public function __construct(private readonly SurveyVersionService $service) {}

    // ------------------------------------------------------------------ versions

    /**
     * GET /surveys/{id}/versions — superviseur du projet. Résumés sans définition, `version` décroissante.
     */
    public function index(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        $versions = SurveyVersion::query()
            ->forSurvey($survey->id)
            ->with('publisher')
            ->orderByDesc('version')
            ->get();

        return $this->ok(SurveyVersionResource::collection($versions));
    }

    /**
     * GET /surveys/{id}/versions/{n} — superviseur du projet ; un enquêteur assigné peut lire la version publiée.
     */
    public function show(Request $request, Survey $survey, int $n): JsonResponse
    {
        $version = $this->version($survey, $n);

        if (! Gate::allows('viewSubmissions', $survey)) {
            if (! $version->isPublished() || ! Gate::allows('collect', $survey)) {
                throw new AuthorizationException;
            }
        }

        return $this->ok((new SurveyVersionResource($version->load('publisher')))->withDefinition());
    }

    /**
     * PUT /surveys/{id}/draft — analyste du projet. Verrou optimiste `base_revision` (409 `revision_conflict`,
     * `data.current_revision`), validation structurelle (422), avertissements sémantiques dans `data.warnings`.
     * Crée le brouillon `version + 1` si seule une version publiée existe.
     */
    public function saveDraft(SaveDraftRequest $request, Survey $survey): JsonResponse
    {
        Gate::authorize('update', $survey);

        $result = $this->service->saveDraft($survey, $request->definition(), $request->baseRevision(), $request->user());

        return $this->ok(
            (new SurveyVersionResource($result->version))->withWarnings($result->warnings),
            ['changed' => $result->changed, 'created' => $result->created],
        );
    }

    /**
     * POST /surveys/{id}/validate — analyste du projet. Dry-run complet : `{valid, revision, errors, warnings}`.
     */
    public function validateDraft(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('update', $survey);

        return $this->ok($this->service->validateDraft($survey));
    }

    /**
     * POST /surveys/{id}/publish — analyste du projet. 409 sans brouillon, 422 si erreurs ; sinon version
     * publiée (hash figé), précédente archivée, `surveys.status = active`, datasource, assignations, événement.
     */
    public function publish(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('publish', $survey);

        $request->validate([
            'assign_all_enumerators' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $version = $this->service->publish($survey, $request->user(), $request->boolean('assign_all_enumerators', true));

        return $this->ok(new SurveyVersionResource($version->load('publisher')));
    }

    /**
     * POST /surveys/{id}/versions/{n}/fork — analyste du projet. Nouveau brouillon `max + 1` ; 409 `draft_exists`.
     */
    public function fork(Request $request, Survey $survey, int $n): JsonResponse
    {
        Gate::authorize('update', $survey);

        $draft = $this->service->fork($survey, $this->version($survey, $n));

        return $this->ok((new SurveyVersionResource($draft))->withDefinition(), [], 201);
    }

    // ------------------------------------------------------------------ assignations

    /**
     * GET /surveys/{id}/assignments — superviseur du projet. Avec `submissions_count` / `valid_count` par enquêteur.
     */
    public function assignments(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        $assignments = EnumeratorAssignment::query()
            ->with('user')
            ->where('survey_id', $survey->id)
            ->orderBy('id')
            ->get();

        return $this->ok(AssignmentResource::collection($this->attachCounts($survey, $assignments->all())));
    }

    /**
     * PUT /surveys/{id}/assignments — analyste du projet (remplacement complet). Un superviseur peut
     * modifier `zone` / `quota_target` des enquêteurs déjà assignés, sans ajouter ni retirer (403 sinon).
     */
    public function syncAssignments(SyncAssignmentsRequest $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        $rows = $request->assignments();

        if (! Gate::allows('manageAssignments', $survey)) {
            $current = EnumeratorAssignment::query()->where('survey_id', $survey->id)->pluck('user_id')->sort()->values()->all();
            $wanted = collect($rows)->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($current !== $wanted) {
                throw new AuthorizationException('Seul un analyste peut ajouter ou retirer des enquêteurs.');
            }
        }

        $assignments = $this->service->syncAssignments($survey, $rows);

        return $this->ok(AssignmentResource::collection($this->attachCounts($survey, $assignments->all())));
    }

    // ------------------------------------------------------------------ helpers

    private function version(Survey $survey, int $n): SurveyVersion
    {
        return SurveyVersion::query()->forSurvey($survey->id)->where('version', $n)->firstOrFail();
    }

    /**
     * Pose `submissions_count` et `valid_count` (attributs transients) sur chaque assignation.
     *
     * @param  array<int, EnumeratorAssignment>  $assignments
     * @return array<int, EnumeratorAssignment>
     */
    private function attachCounts(Survey $survey, array $assignments): array
    {
        $userIds = array_map(static fn (EnumeratorAssignment $a) => $a->user_id, $assignments);
        $rows = collect();

        if ($userIds !== []) {
            $invalid = array_map(static fn (SubmissionStatus $s) => "'".$s->value."'", SubmissionStatus::invalid());
            $rows = Submission::query()
                ->forSurvey($survey->id)
                ->whereIn('enumerator_id', $userIds)
                ->groupBy('enumerator_id')
                ->selectRaw('enumerator_id, COUNT(*) AS submissions_count, SUM(CASE WHEN status NOT IN ('.implode(',', $invalid).') THEN 1 ELSE 0 END) AS valid_count')
                ->get()
                ->keyBy('enumerator_id');
        }

        foreach ($assignments as $assignment) {
            $row = $rows->get($assignment->user_id);
            $assignment->setAttribute('submissions_count', (int) ($row?->submissions_count ?? 0));
            $assignment->setAttribute('valid_count', (int) ($row?->valid_count ?? 0));
        }

        return $assignments;
    }
}
