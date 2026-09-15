<?php

namespace App\Http\Resources;

use App\Models\ProjectMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `Member` : {project_id, user, role, zone, status, joined_at} (+ `stats` sur la liste :
 * {submissions_count, last_activity_at}).
 *
 * @mixin ProjectMember
 */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'project_id' => $this->project_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'role' => $this->role?->value,
            'zone' => $this->zone,
            'status' => $this->status,
            'joined_at' => $this->created_at?->toIso8601String(),
            'stats' => $this->when(isset($this->resource->stats), fn () => $this->resource->stats),
        ];
    }
}
