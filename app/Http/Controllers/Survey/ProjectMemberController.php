<?php

namespace App\Http\Controllers\Survey;

use App\Enums\ProjectRole;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Survey\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\SurveyProject;
use App\Models\User;
use App\Services\Survey\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Membres d'un projet (contrat : tag Membres & invitations).
 */
class ProjectMemberController extends ApiController
{
    public function __construct(private readonly MembershipService $membership) {}

    /**
     * GET /projects/{id}/members — superviseur et plus. Chaque membre porte `stats`
     * {submissions_count, last_activity_at} calculées sur les soumissions du projet.
     */
    public function index(Request $request, SurveyProject $project): JsonResponse
    {
        Gate::authorize('viewMembers', $project);

        $request->validate([
            'role' => ['nullable', Rule::enum(ProjectRole::class)],
            'status' => ['nullable', Rule::in([ProjectMember::STATUS_ACTIVE, ProjectMember::STATUS_INACTIVE])],
        ]);

        $query = ProjectMember::query()
            ->with('user')
            ->forProject($project->id)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($role = $request->query('role')) {
            $query->role($role);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($this->perPage($request));
        $this->attachStats($project, $paginator->items());

        return $this->paginated($paginator, MemberResource::class);
    }

    /**
     * PUT /projects/{id}/members/{userId} — analyste du projet. Crée (201) ou met à jour (200) le membre.
     * Le propriétaire ne peut être ni rétrogradé ni désactivé (409).
     */
    public function update(UpdateMemberRequest $request, SurveyProject $project, User $user): JsonResponse
    {
        Gate::authorize('manageMembers', $project);

        $role = $request->role();
        $status = $request->status();

        if ($user->id === $project->owner_id && ($role !== ProjectRole::Analyste || $status !== ProjectMember::STATUS_ACTIVE)) {
            return $this->fail('Le propriétaire du projet ne peut pas être rétrogradé ni désactivé.', 409, null, ['code' => 'owner_protected']);
        }

        $existing = ProjectMember::query()->forProject($project->id)->where('user_id', $user->id)->first();
        $member = $this->membership->upsertMember($project, $user, $role, $request->validated('zone'), $status, $existing);
        $member->load('user');

        return $this->ok(new MemberResource($member), [], $existing === null ? 201 : 200);
    }

    /**
     * DELETE /projects/{id}/members/{userId} — analyste du projet. Le propriétaire ne peut pas être retiré (409).
     * Supprime les assignations du membre ; ses soumissions sont conservées.
     */
    public function destroy(Request $request, SurveyProject $project, User $user): JsonResponse|Response
    {
        Gate::authorize('manageMembers', $project);

        if ($user->id === $project->owner_id) {
            return $this->fail('Le propriétaire du projet ne peut pas être retiré.', 409, null, ['code' => 'owner_protected']);
        }

        $member = ProjectMember::query()->forProject($project->id)->where('user_id', $user->id)->firstOrFail();
        $this->membership->removeMember($member);

        return response()->noContent();
    }

    /**
     * Pose l'attribut transient `stats` sur chaque membre.
     *
     * @param  array<int, ProjectMember>  $members
     */
    private function attachStats(SurveyProject $project, array $members): void
    {
        $userIds = array_map(static fn (ProjectMember $m) => $m->user_id, $members);
        if ($userIds === []) {
            return;
        }

        $rows = Submission::query()
            ->where('project_id', $project->id)
            ->whereIn('enumerator_id', $userIds)
            ->groupBy('enumerator_id')
            ->selectRaw('enumerator_id, COUNT(*) AS submissions_count, MAX(created_at) AS last_activity_at')
            ->get()
            ->keyBy('enumerator_id');

        foreach ($members as $member) {
            $row = $rows->get($member->user_id);
            $last = $row?->last_activity_at;

            $member->setAttribute('stats', [
                'submissions_count' => (int) ($row?->submissions_count ?? 0),
                'last_activity_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
            ]);
        }
    }
}
