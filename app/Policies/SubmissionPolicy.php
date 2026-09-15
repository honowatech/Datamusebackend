<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;
use App\Policies\Concerns\ChecksProjectRole;

/**
 * Soumission : superviseur du projet, ou l'enquêteur auteur pour la lecture.
 */
class SubmissionPolicy
{
    use ChecksProjectRole;

    public function view(User $user, Submission $submission): bool
    {
        if ($submission->enumerator_id !== null && $submission->enumerator_id === $user->id) {
            return $this->isMember($user, $submission->project_id);
        }

        return $this->isSupervisor($user, $submission->project_id);
    }

    /** Statut / notes qualité (PATCH) : superviseur. */
    public function update(User $user, Submission $submission): bool
    {
        return $this->isSupervisor($user, $submission->project_id);
    }

    public function reviewSubmissions(User $user, Submission $submission): bool
    {
        return $this->update($user, $submission);
    }

    /** Suppression : analyste. */
    public function delete(User $user, Submission $submission): bool
    {
        return $this->isAnalyst($user, $submission->project_id);
    }
}
