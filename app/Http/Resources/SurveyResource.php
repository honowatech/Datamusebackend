<?php

namespace App\Http\Resources;

use App\Models\EnumeratorAssignment;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `Survey` : métadonnées + `published_version` / `draft_version` (résumés sans définition),
 * `target_database_id`, `submissions_count`, `created_by`, `my_assignment` (assignation de l'utilisateur courant).
 *
 * Relations attendues (`SurveyResource::RELATIONS`) : `publishedVersion`, `draftVersion`, `datasource`,
 * `creator`, `assignments.user`.
 *
 * @mixin Survey
 */
class SurveyResource extends JsonResource
{
    /** @var list<string> */
    public const RELATIONS = ['publishedVersion', 'draftVersion', 'datasource', 'creator', 'assignments.user'];

    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'status' => $this->status?->value,
            'published_version' => $this->summary($this->relationLoaded('publishedVersion') ? $this->publishedVersion : null),
            'draft_version' => $this->summary($this->relationLoaded('draftVersion') ? $this->draftVersion : null),
            'target_database_id' => $this->relationLoaded('datasource') ? $this->datasource?->target_database_id : null,
            'submissions_count' => (int) $this->submissions_count,
            'last_submission_at' => $this->last_submission_at?->toIso8601String(),
            'created_by' => $this->relationLoaded('creator') ? UserResource::ref($this->creator) : null,
            'my_assignment' => $this->when($this->relationLoaded('assignments') && $user instanceof User, function () use ($user) {
                $assignment = $this->assignments->first(fn (EnumeratorAssignment $a) => $a->user_id === $user->id);

                return $assignment === null ? null : new AssignmentResource($assignment);
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function summary(?SurveyVersion $version): ?SurveyVersionResource
    {
        return $version === null ? null : new SurveyVersionResource($version);
    }
}
