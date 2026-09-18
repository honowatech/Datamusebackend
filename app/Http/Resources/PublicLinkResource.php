<?php

namespace App\Http\Resources;

use App\Models\PublicLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `PublicLink` (B-12) : `url` = `{FRONTEND_URL}/s/{token}`, `state` calculé.
 *
 * @mixin PublicLink
 */
class PublicLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PublicLink $link */
        $link = $this->resource;

        return [
            'id' => $link->id,
            'survey_id' => $link->survey_id,
            'token' => $link->token,
            'url' => self::url($link),
            'label' => $link->label,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'max_responses' => $link->max_responses,
            'responses_count' => (int) $link->responses_count,
            'is_active' => (bool) $link->is_active,
            'state' => self::state($link),
            'remaining' => self::remaining($link),
            'created_by' => UserResource::ref($link->relationLoaded('creator') ? $link->creator : null),
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }

    public static function url(PublicLink $link): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/s/'.$link->token;
    }

    /** `open` · `inactive` · `expired` · `full` — l'ordre reflète la raison renvoyée dans un `410`. */
    public static function state(PublicLink $link): string
    {
        return match (true) {
            ! $link->is_active => 'inactive',
            $link->isExpired() => 'expired',
            $link->isFull() => 'full',
            default => 'open',
        };
    }

    /** Réponses encore acceptées (`null` = illimité). */
    public static function remaining(PublicLink $link): ?int
    {
        return $link->max_responses === null
            ? null
            : max(0, (int) $link->max_responses - (int) $link->responses_count);
    }
}
