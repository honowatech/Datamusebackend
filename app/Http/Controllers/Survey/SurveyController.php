<?php

namespace App\Http\Controllers\Survey;

use App\Enums\ProjectRole;
use App\Enums\SurveyStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Survey\DuplicateSurveyRequest;
use App\Http\Requests\Survey\StoreSurveyRequest;
use App\Http\Requests\Survey\UpdateSurveyRequest;
use App\Http\Resources\SurveyResource;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\User;
use App\Services\Survey\SurveyVersionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Questionnaires (contrat : tag Questionnaires). La logique métier est dans SurveyVersionService.
 */
class SurveyController extends ApiController
{
    /** @var list<string> */
    private const SORTABLE = ['title', 'status', 'created_at', 'updated_at', 'last_submission_at'];

    public function __construct(private readonly SurveyVersionService $service) {}

    /**
     * GET /projects/{id}/surveys — membre du projet. Un enquêteur ne voit que les questionnaires `active`
     * qui lui sont assignés. Paginé ; filtres `status`, `q` ; tri `title|status|created_at|last_submission_at`.
     */
    public function index(Request $request, SurveyProject $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $user = $request->user();

        $request->validate([
            'status' => ['nullable', Rule::enum(SurveyStatus::class)],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $query = Survey::query()
            ->forProject($project->id)
            ->with($this->relationsFor($user));

        if ($this->isEnumeratorOnly($user, $project->id)) {
            $query->active()->whereHas('assignments', fn (Builder $a) => $a->where('user_id', $user->id));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $query->where('title', 'like', "%{$search}%");
        }

        [$column, $direction] = $this->sort($request, self::SORTABLE);
        $query->orderBy($column, $direction)->orderBy('id', 'desc');

        return $this->paginated($query->paginate($this->perPage($request)), SurveyResource::class);
    }

    /**
     * POST /projects/{id}/surveys — analyste du projet. Crée le questionnaire `draft` et sa version 1
     * (brouillon, révision 0) ; `definition` optionnelle, validée structurellement (422 liste `{path, code, message}`).
     */
    public function store(StoreSurveyRequest $request, SurveyProject $project): JsonResponse
    {
        Gate::authorize('create', [Survey::class, $project]);

        $survey = $this->service->createSurvey($project, $request->user(), $request->validated('title'), $request->definition());

        return $this->ok($this->resource($survey, $request->user()), [], 201);
    }

    /**
     * GET /surveys/{id} — membre du projet ; un enquêteur doit être assigné.
     */
    public function show(Request $request, Survey $survey): JsonResponse
    {
        $this->authorizeRead($request->user(), $survey);

        return $this->ok($this->resource($survey, $request->user()));
    }

    /**
     * PUT /surveys/{id} — analyste du projet. Titre et/ou statut (`active` requiert une version publiée → 409 ;
     * `closed` refuse ensuite la collecte).
     */
    public function update(UpdateSurveyRequest $request, Survey $survey): JsonResponse
    {
        Gate::authorize('update', $survey);

        $survey = $this->service->updateSurvey($survey, $request->validated('title'), $request->status());

        return $this->ok($this->resource($survey, $request->user()));
    }

    /**
     * DELETE /surveys/{id} — analyste du projet. Suppression douce ; 409 `survey_has_submissions` s'il existe
     * des soumissions, sauf `?force=1`.
     */
    public function destroy(Request $request, Survey $survey): Response
    {
        Gate::authorize('delete', $survey);

        $this->service->delete($survey, $request->boolean('force'));

        return response()->noContent();
    }

    /**
     * POST /surveys/{id}/duplicate — analyste du projet source et du projet cible (`project_id`, défaut : même projet).
     */
    public function duplicate(DuplicateSurveyRequest $request, Survey $survey): JsonResponse
    {
        Gate::authorize('update', $survey);

        $targetId = $request->validated('project_id');
        $target = $targetId ? SurveyProject::query()->findOrFail((int) $targetId) : $survey->project;
        Gate::authorize('create', [Survey::class, $target]);

        $copy = $this->service->duplicate($survey, $target, $request->user(), $request->validated('title'));

        return $this->ok($this->resource($copy, $request->user()), [], 201);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Lecture : membre du projet ; un enquêteur (rôle projet) doit être assigné (policy `collect`).
     */
    public static function authorizeRead(User $user, Survey $survey): void
    {
        Gate::authorize('view', $survey);

        if (self::isEnumeratorOnly($user, $survey->project_id)) {
            Gate::authorize('collect', $survey);
        }
    }

    public static function isEnumeratorOnly(User $user, int $projectId): bool
    {
        return ! $user->isAdmin() && $user->projectRole($projectId) === ProjectRole::Enqueteur;
    }

    private function resource(Survey $survey, User $user): SurveyResource
    {
        return new SurveyResource($survey->fresh($this->relationsFor($user)));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function relationsFor(User $user): array
    {
        return [
            'publishedVersion',
            'draftVersion',
            'datasource',
            'creator',
            'assignments' => fn ($q) => $q->where('user_id', $user->id)->with('user'),
        ];
    }
}
