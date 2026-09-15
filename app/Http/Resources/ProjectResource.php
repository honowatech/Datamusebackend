<?php

namespace App\Http\Resources;

use App\Models\SurveyProject;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `Project` : ProjectIn + {id, owner_id, my_role, members_count, surveys_count, created_at, updated_at}.
 * `members_count` / `surveys_count` sont présents si chargés (`withCount` / `loadCount`).
 * `my_role` est calculé pour l'utilisateur authentifié, ou pour `forUser()` (ex. inscription avec join_code).
 *
 * @mixin SurveyProject
 */
class ProjectResource extends JsonResource
{
    private ?User $viewer = null;

    public function forUser(?User $user): static
    {
        $this->viewer = $user;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $user = $this->viewer ?? $request->user();

        return [
            'id' => $this->id,
            'owner_id' => $this->owner_id,
            'name' => $this->name,
            'description' => $this->description,
            'client_name' => $this->client_name,
            'settings' => $this->settings ?? (object) [],
            'my_role' => $user instanceof User ? $this->resource->roleOf($user)?->value : null,
            'members_count' => $this->whenCounted('members'),
            'surveys_count' => $this->whenCounted('surveys'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
