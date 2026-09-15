<?php

namespace App\Services\Survey;

use App\Enums\ProjectRole;
use App\Exceptions\InvitationUnusableException;
use App\Models\EnumeratorAssignment;
use App\Models\ProjectInvitation;
use App\Models\ProjectMember;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adhésion aux projets : acceptation d'invitations (token / join_code), upsert de membres et
 * création des `enumerator_assignments`. Réutilisé par B-05 à la publication d'un questionnaire
 * (`assignActiveEnumeratorsToSurvey`).
 */
class MembershipService
{
    public function findInvitation(?string $token, ?string $joinCode): ?ProjectInvitation
    {
        $query = ProjectInvitation::query()->with('project');

        if (is_string($token) && $token !== '') {
            return $query->where('token', $token)->first();
        }
        if (is_string($joinCode) && $joinCode !== '') {
            return $query->where('join_code', strtoupper(trim($joinCode)))->first();
        }

        return null;
    }

    /**
     * Code de connexion valide (existant, non expiré, non épuisé) ou null.
     */
    public function findUsableJoinCode(string $joinCode): ?ProjectInvitation
    {
        $invitation = $this->findInvitation(null, $joinCode);

        return $invitation !== null && $invitation->isUsable() ? $invitation : null;
    }

    /**
     * Accepte une invitation pour l'utilisateur.
     *
     * - Déjà membre actif : idempotent, aucun usage consommé.
     * - Expirée / épuisée : InvitationUnusableException (410).
     * - Sinon : membre créé ou réactivé avec le rôle/zone de l'invitation, `uses++`, `accepted_at` si épuisée,
     *   assignation aux questionnaires `active` du projet si rôle enquêteur.
     *
     * @throws InvitationUnusableException
     */
    public function accept(ProjectInvitation $invitation, User $user): ProjectMember
    {
        return DB::transaction(function () use ($invitation, $user) {
            /** @var ProjectInvitation $invitation */
            $invitation = ProjectInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $project = $invitation->project;

            $existing = ProjectMember::query()
                ->forProject($project->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing !== null && $existing->status === ProjectMember::STATUS_ACTIVE) {
                return $existing;
            }

            if ($invitation->isExpired()) {
                throw InvitationUnusableException::expired();
            }
            if ($invitation->isExhausted()) {
                throw InvitationUnusableException::usedUp();
            }

            $member = $this->upsertMember($project, $user, $invitation->role, $invitation->zone, ProjectMember::STATUS_ACTIVE, $existing);

            $invitation->uses++;
            if ($invitation->uses >= $invitation->max_uses) {
                $invitation->accepted_at = now();
            }
            $invitation->save();

            return $member;
        });
    }

    /**
     * Crée ou met à jour un membre (unique project/user). Un enquêteur actif est assigné aux questionnaires actifs.
     */
    public function upsertMember(
        SurveyProject $project,
        User $user,
        ProjectRole $role,
        ?string $zone,
        string $status = ProjectMember::STATUS_ACTIVE,
        ?ProjectMember $existing = null,
    ): ProjectMember {
        $member = $existing ?? ProjectMember::query()
            ->forProject($project->id)
            ->where('user_id', $user->id)
            ->first() ?? new ProjectMember(['project_id' => $project->id, 'user_id' => $user->id]);

        $member->role = $role;
        $member->zone = $zone;
        $member->status = $status;
        $member->save();

        $user->forgetProjectRoles();

        if ($role === ProjectRole::Enqueteur && $status === ProjectMember::STATUS_ACTIVE) {
            $this->assignEnumeratorToActiveSurveys($project, $user, $zone);
        }

        return $member;
    }

    /**
     * Retire un membre : supprime ses assignations sur les questionnaires du projet ; ses soumissions sont conservées.
     */
    public function removeMember(ProjectMember $member): void
    {
        DB::transaction(function () use ($member) {
            EnumeratorAssignment::query()
                ->where('user_id', $member->user_id)
                ->whereIn('survey_id', Survey::query()->forProject($member->project_id)->select('id'))
                ->delete();

            $member->delete();
            $member->user?->forgetProjectRoles();
        });
    }

    /**
     * Assigne un enquêteur à tous les questionnaires `active` du projet (idempotent). Retourne le nombre créé.
     */
    public function assignEnumeratorToActiveSurveys(SurveyProject $project, User $user, ?string $zone = null): int
    {
        $created = 0;

        foreach (Survey::query()->forProject($project->id)->active()->get(['id']) as $survey) {
            $assignment = EnumeratorAssignment::query()->firstOrCreate(
                ['survey_id' => $survey->id, 'user_id' => $user->id],
                ['zone' => $zone],
            );
            if ($assignment->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Assigne tous les enquêteurs actifs du projet à un questionnaire (appelé à la publication, B-05). Idempotent.
     */
    public function assignActiveEnumeratorsToSurvey(Survey $survey): int
    {
        $created = 0;

        $members = ProjectMember::query()
            ->forProject($survey->project_id)
            ->active()
            ->role(ProjectRole::Enqueteur)
            ->get(['user_id', 'zone']);

        foreach ($members as $member) {
            $assignment = EnumeratorAssignment::query()->firstOrCreate(
                ['survey_id' => $survey->id, 'user_id' => $member->user_id],
                ['zone' => $member->zone],
            );
            if ($assignment->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Génère un `join_code` à 8 caractères garanti unique en base.
     */
    public function uniqueJoinCode(): string
    {
        do {
            $code = ProjectInvitation::generateJoinCode();
        } while (ProjectInvitation::query()->where('join_code', $code)->exists());

        return $code;
    }
}
