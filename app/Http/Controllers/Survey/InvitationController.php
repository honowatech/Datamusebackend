<?php

namespace App\Http\Controllers\Survey;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Survey\AcceptInvitationRequest;
use App\Http\Requests\Survey\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\MemberResource;
use App\Http\Resources\ProjectResource;
use App\Models\ProjectInvitation;
use App\Models\SurveyProject;
use App\Notifications\ProjectInvitationNotification;
use App\Services\Survey\MembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Invitations à un projet : par e-mail (token) ou par code de connexion (contrat : tag Membres & invitations).
 */
class InvitationController extends ApiController
{
    public const DEFAULT_EXPIRY_DAYS = 14;

    public function __construct(private readonly MembershipService $membership) {}

    /**
     * GET /projects/{id}/invitations — superviseur et plus (join_code / join_url réservés aux analystes).
     */
    public function index(Request $request, SurveyProject $project): JsonResponse
    {
        Gate::authorize('viewMembers', $project);

        $request->validate([
            'status' => ['nullable', Rule::in([InvitationResource::STATUS_PENDING, InvitationResource::STATUS_ACCEPTED, InvitationResource::STATUS_EXPIRED])],
        ]);

        $query = ProjectInvitation::query()
            ->with('inviter')
            ->where('project_id', $project->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        match ($request->query('status')) {
            InvitationResource::STATUS_EXPIRED => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()),
            InvitationResource::STATUS_ACCEPTED => $query
                ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereColumn('uses', '>=', 'max_uses'),
            InvitationResource::STATUS_PENDING => $query->usable(),
            default => null,
        };

        return $this->paginated($query->paginate($this->perPage($request)), InvitationResource::class);
    }

    /**
     * POST /projects/{id}/invitations — analyste du projet.
     */
    public function store(StoreInvitationRequest $request, SurveyProject $project): JsonResponse
    {
        Gate::authorize('manageMembers', $project);

        $mode = $request->mode();
        $expiresAt = $request->validated('expires_at')
            ? Carbon::parse($request->validated('expires_at'))
            : now()->addDays(self::DEFAULT_EXPIRY_DAYS);

        $invitation = new ProjectInvitation([
            'project_id' => $project->id,
            'role' => $request->role(),
            'zone' => $request->validated('zone'),
            'expires_at' => $expiresAt,
            'invited_by' => $request->user()->id,
            'uses' => 0,
        ]);

        if ($mode === StoreInvitationRequest::MODE_EMAIL) {
            $invitation->email = strtolower(trim((string) $request->validated('email')));
            $invitation->max_uses = 1;
        } else {
            $invitation->email = null;
            $invitation->join_code = $this->membership->uniqueJoinCode();
            $invitation->max_uses = (int) ($request->validated('max_uses') ?? 1);
        }

        $invitation->save();
        $invitation->setRelation('project', $project)->load('inviter');

        if ($invitation->email !== null) {
            Notification::route('mail', $invitation->email)->notify(new ProjectInvitationNotification($invitation));
        }

        return $this->ok(new InvitationResource($invitation), [], 201);
    }

    /**
     * DELETE /projects/{id}/invitations/{invId} — analyste du projet. 404 si l'invitation n'appartient pas au projet.
     */
    public function destroy(Request $request, SurveyProject $project, int $invId): JsonResponse|Response
    {
        Gate::authorize('manageMembers', $project);

        ProjectInvitation::query()
            ->where('project_id', $project->id)
            ->whereKey($invId)
            ->firstOrFail()
            ->delete();

        return response()->noContent();
    }

    /**
     * POST /invitations/accept — utilisateur connecté, `token` ou `join_code`.
     * 404 inconnue, 410 expirée/épuisée, 200 `{project, member}` (idempotent si déjà membre).
     */
    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        $invitation = $this->membership->findInvitation($request->token(), $request->joinCode());

        if ($invitation === null) {
            return $this->fail('Invitation introuvable.', 404, null, ['code' => 'invitation_not_found']);
        }

        $member = $this->membership->accept($invitation, $request->user());
        $member->load('user');

        return $this->ok([
            'project' => new ProjectResource($invitation->project->loadCounts()),
            'member' => new MemberResource($member),
        ]);
    }
}
