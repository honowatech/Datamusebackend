<?php

namespace App\Policies;

use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\User;
use App\Policies\Concerns\ChecksProjectRole;

/**
 * Questionnaire : les capacités dérivent du rôle dans le projet parent.
 */
class SurveyPolicy
{
    use ChecksProjectRole;

    /** Voir la fiche du questionnaire : membre du projet. */
    public function view(User $user, Survey $survey): bool
    {
        return $this->isMember($user, $survey->project_id);
    }

    /** Créer dans un projet : analyste du projet (`$user->can('create', [Survey::class, $project])`). */
    public function create(User $user, SurveyProject $project): bool
    {
        return $this->isAnalyst($user, $project->id);
    }

    /** Modifier titre / brouillon / assignations : analyste. */
    public function update(User $user, Survey $survey): bool
    {
        return $this->isAnalyst($user, $survey->project_id);
    }

    public function delete(User $user, Survey $survey): bool
    {
        return $this->isAnalyst($user, $survey->project_id);
    }

    /** Publier / fermer une version : analyste. */
    public function publish(User $user, Survey $survey): bool
    {
        return $this->isAnalyst($user, $survey->project_id);
    }

    /** Gérer les affectations d'enquêteurs : analyste. */
    public function manageAssignments(User $user, Survey $survey): bool
    {
        return $this->isAnalyst($user, $survey->project_id);
    }

    /** Consulter les soumissions, statistiques, supervision : superviseur. */
    public function viewSubmissions(User $user, Survey $survey): bool
    {
        return $this->isSupervisor($user, $survey->project_id);
    }

    /** Valider / rejeter des fiches : superviseur. */
    public function reviewSubmissions(User $user, Survey $survey): bool
    {
        return $this->isSupervisor($user, $survey->project_id);
    }

    /** Exporter (CSV/XLSX, XLSForm) : analyste. */
    public function exportSubmissions(User $user, Survey $survey): bool
    {
        return $this->isAnalyst($user, $survey->project_id);
    }

    /** Lancer des opérations IA (génération, traduction, classification, synthèse, rapports) : analyste. */
    public function generateAi(User $user, Survey $survey): bool
    {
        return $this->isAnalyst($user, $survey->project_id);
    }

    /** Collecter (mobile) : enquêteur affecté au questionnaire, ou superviseur et plus. */
    public function collect(User $user, Survey $survey): bool
    {
        if ($this->isSupervisor($user, $survey->project_id)) {
            return true;
        }

        return $this->isMember($user, $survey->project_id)
            && $survey->assignments()->where('user_id', $user->id)->exists();
    }
}
