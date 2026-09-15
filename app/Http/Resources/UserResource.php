<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `User` : {id, name, email, role, phone, locale, created_at}.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role instanceof UserRole ? $this->role->value : (string) ($this->role ?? UserRole::Analyste->value),
            'phone' => $this->phone,
            'locale' => $this->locale ?? 'fr',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Schéma `UserRef` : {id, name}.
     *
     * @return array{id: int, name: string}|null
     */
    public static function ref(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
