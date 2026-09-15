<?php

namespace App\Http\Controllers\Survey;

use App\Enums\ProjectRole;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Survey\StoreProjectRequest;
use App\Http\Requests\Survey\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Projets d'enquête (contrat : tag Projets).
 */
class ProjectController extends ApiController
{
    /** @var list<string> */
    private const SORTABLE = ['name', 'created_at', 'updated_at', 'client_name'];

    /**
     * GET /projects — projets dont l'utilisateur est propriétaire ou membre actif (l'admin voit tout). Paginé.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        Gate::authorize('viewAny', SurveyProject::class);

        $query = SurveyProject::query()->withCounts();

        if (! $user->isAdmin()) {
            $query->where(function (Builder $q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhereHas('members', fn (Builder $m) => $m->where('user_id', $user->id)->active());
            });
        }

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%");
            });
        }

        [$column, $direction] = $this->sort($request, self::SORTABLE);
        $query->orderBy($column, $direction)->orderBy('id', 'desc');

        return $this->paginated($query->paginate($this->perPage($request)), ProjectResource::class);
    }

    /**
     * POST /projects — analyste global ou admin. Le créateur devient owner et membre analyste.
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        Gate::authorize('create-project');
        $user = $request->user();

        $project = DB::transaction(function () use ($request, $user) {
            $project = SurveyProject::create($request->projectAttributes() + [
                'owner_id' => $user->id,
                'settings' => $request->projectAttributes()['settings'] ?? ['timezone' => 'Africa/Douala', 'currency' => 'XAF'],
            ]);

            ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'role' => ProjectRole::Analyste,
                'status' => ProjectMember::STATUS_ACTIVE,
            ]);

            return $project;
        });

        $user->forgetProjectRoles();

        return $this->ok(new ProjectResource($project->loadCounts()), [], 201);
    }

    /**
     * GET /projects/{id} — membre du projet.
     */
    public function show(Request $request, SurveyProject $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->ok(new ProjectResource($project->loadCounts()));
    }

    /**
     * PUT /projects/{id} — analyste du projet ; remplace les champs fournis.
     */
    public function update(UpdateProjectRequest $request, SurveyProject $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $project->fill($request->projectAttributes())->save();

        return $this->ok(new ProjectResource($project->refresh()->loadCounts()));
    }

    /**
     * DELETE /projects/{id} — propriétaire ou admin. 409 si le projet a des questionnaires actifs
     * ou des soumissions, sauf `?force=1`. Suppression en cascade (FK) du graphe complet.
     */
    public function destroy(Request $request, SurveyProject $project): JsonResponse|Response
    {
        Gate::authorize('delete', $project);

        if (! $request->boolean('force')) {
            $activeSurveys = Survey::query()->forProject($project->id)->active()->count();
            $submissions = Submission::query()->where('project_id', $project->id)->count();

            if ($activeSurveys > 0 || $submissions > 0) {
                return $this->fail(
                    sprintf(
                        'Le projet contient %d questionnaire(s) actif(s) et %d soumission(s). Fermez les questionnaires ou relancez avec ?force=1.',
                        $activeSurveys,
                        $submissions,
                    ),
                    409,
                    null,
                    ['code' => 'project_not_empty', 'data' => ['active_surveys' => $activeSurveys, 'submissions' => $submissions]],
                );
            }
        }

        DB::transaction(function () use ($project) {
            // Les questionnaires sont en soft delete : on les supprime définitivement pour laisser la cascade FK agir.
            Survey::withTrashed()->forProject($project->id)->forceDelete();
            $project->delete();
        });

        return response()->noContent();
    }
}
