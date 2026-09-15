<?php

namespace App\Policies\Concerns;

use App\Enums\ProjectRole;
use App\Models\User;

/**
 * Hiérarchie des rôles projet : enqueteur < superviseur < analyste.
 * L'admin global contourne tout ; le propriétaire du projet est analyste implicite (User::projectRole).
 */
trait ChecksProjectRole
{
    protected function roleAtLeast(User $user, int $projectId, ProjectRole $min): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $role = $user->projectRole($projectId);

        return $role !== null && $role->atLeast($min);
    }

    protected function isAnalyst(User $user, int $projectId): bool
    {
        return $this->roleAtLeast($user, $projectId, ProjectRole::Analyste);
    }

    protected function isSupervisor(User $user, int $projectId): bool
    {
        return $this->roleAtLeast($user, $projectId, ProjectRole::Superviseur);
    }

    protected function isMember(User $user, int $projectId): bool
    {
        return $this->roleAtLeast($user, $projectId, ProjectRole::Enqueteur);
    }
}
