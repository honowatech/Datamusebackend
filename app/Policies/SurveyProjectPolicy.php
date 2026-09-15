<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SurveyProject;
use App\Models\User;
use App\Policies\Concerns\ChecksProjectRole;

/**
 * Projet d'enquête. L'admin global est traité par Gate::before (AppServiceProvider).
 */
class SurveyProjectPolicy
{
    use ChecksProjectRole;

    /** Lister ses projets : tout utilisateur authentifié. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Membre du projet (enqueteur et plus). */
    public function view(User $user, SurveyProject $project): bool
    {
        return $this->isMember($user, $project->id);
    }

    /** Analyste global (users.role) ; doublon de la capacité Gate `create-project`. */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->role === UserRole::Analyste;
    }

    public function update(User $user, SurveyProject $project): bool
    {
        return $this->isAnalyst($user, $project->id);
    }

    /** Suppression réservée au propriétaire (ou admin via Gate::before). */
    public function delete(User $user, SurveyProject $project): bool
    {
        return $user->isAdmin() || $project->owner_id === $user->id;
    }

    /** Ajouter / modifier / retirer des membres, créer des invitations : analyste du projet. */
    public function manageMembers(User $user, SurveyProject $project): bool
    {
        return $this->isAnalyst($user, $project->id);
    }

    /** Lister membres et invitations : superviseur (un enquêteur ne voit pas la liste). */
    public function viewMembers(User $user, SurveyProject $project): bool
    {
        return $this->isSupervisor($user, $project->id);
    }

    /** Créer un questionnaire dans le projet : analyste. */
    public function createSurvey(User $user, SurveyProject $project): bool
    {
        return $this->isAnalyst($user, $project->id);
    }
}
