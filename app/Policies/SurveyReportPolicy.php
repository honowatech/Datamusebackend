<?php

namespace App\Policies;

use App\Models\Survey;
use App\Models\SurveyReport;
use App\Models\User;
use App\Policies\Concerns\ChecksProjectRole;

/**
 * Rapport IA : lecture pour les superviseurs, création / édition / suppression pour les analystes.
 */
class SurveyReportPolicy
{
    use ChecksProjectRole;

    public function view(User $user, SurveyReport $report): bool
    {
        return $this->isSupervisor($user, $this->projectId($report));
    }

    /** `$user->can('create', [SurveyReport::class, $survey])`. */
    public function create(User $user, Survey $survey): bool
    {
        return $this->isAnalyst($user, $survey->project_id);
    }

    public function update(User $user, SurveyReport $report): bool
    {
        return $this->isAnalyst($user, $this->projectId($report));
    }

    public function delete(User $user, SurveyReport $report): bool
    {
        return $this->isAnalyst($user, $this->projectId($report));
    }

    public function generateAi(User $user, SurveyReport $report): bool
    {
        return $this->update($user, $report);
    }

    private function projectId(SurveyReport $report): int
    {
        $projectId = $report->relationLoaded('survey')
            ? $report->survey?->project_id
            : Survey::withTrashed()->whereKey($report->survey_id)->value('project_id');

        return (int) ($projectId ?? 0);
    }
}
