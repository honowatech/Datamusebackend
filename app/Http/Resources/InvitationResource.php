<?php

namespace App\Http\Resources;

use App\Enums\ProjectRole;
use App\Models\ProjectInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `Invitation`. `join_code` et `join_url` ne sont renvoyés qu'aux analystes du projet (et admin).
 *
 * @mixin ProjectInvitation
 */
class InvitationResource extends JsonResource
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    public function toArray(Request $request): array
    {
        $secrets = $this->canSeeSecrets($request->user());

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'email' => $this->email,
            'role' => $this->role?->value,
            'zone' => $this->zone,
            'join_code' => $secrets ? $this->join_code : null,
            'join_url' => $secrets ? self::joinUrl($this->resource) : null,
            'max_uses' => $this->max_uses,
            'uses' => $this->uses,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'status' => self::status($this->resource),
            'invited_by' => UserResource::ref($this->whenLoaded('inviter', fn () => $this->inviter, null)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public static function status(ProjectInvitation $invitation): string
    {
        if ($invitation->isExpired()) {
            return self::STATUS_EXPIRED;
        }
        if ($invitation->isExhausted() || ($invitation->max_uses === 1 && $invitation->accepted_at !== null)) {
            return self::STATUS_ACCEPTED;
        }

        return self::STATUS_PENDING;
    }

    /**
     * Lien d'acceptation ouvert par le frontend : `{FRONTEND_URL}/invitations/accept?token=…`.
     */
    public static function joinUrl(ProjectInvitation $invitation): ?string
    {
        if (! is_string($invitation->token) || $invitation->token === '') {
            return null;
        }

        return rtrim((string) config('app.frontend_url'), '/').'/invitations/accept?token='.$invitation->token;
    }

    private function canSeeSecrets(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return $user->projectRole($this->project_id) === ProjectRole::Analyste;
    }
}
