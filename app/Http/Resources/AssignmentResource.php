<?php

namespace App\Http\Resources;

use App\Models\EnumeratorAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `Assignment` : AssignmentIn + {survey_id, user, submissions_count?, valid_count?}.
 * `submissions_count` / `valid_count` sont présents lorsque le contrôleur les a calculés (attributs transients).
 *
 * @mixin EnumeratorAssignment
 */
class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'survey_id' => $this->survey_id,
            'user_id' => $this->user_id,
            'user' => $this->relationLoaded('user') ? UserResource::ref($this->user) : ['id' => $this->user_id, 'name' => ''],
            'zone' => $this->zone,
            'quota_target' => $this->quota_target,
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'submissions_count' => $this->when(isset($this->resource->submissions_count), fn () => (int) $this->resource->submissions_count),
            'valid_count' => $this->when(isset($this->resource->valid_count), fn () => (int) $this->resource->valid_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
