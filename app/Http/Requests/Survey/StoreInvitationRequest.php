<?php

namespace App\Http\Requests\Survey;

use App\Enums\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /projects/{id}/invitations` : deux modes.
 *  - `email` : lien contenant le token (max_uses forcé à 1), notification mail.
 *  - `code`  : `join_code` à 8 caractères réutilisable `max_uses` fois.
 * `mode` est facultatif : déduit de la présence de `email`.
 */
class StoreInvitationRequest extends FormRequest
{
    public const MODE_EMAIL = 'email';

    public const MODE_CODE = 'code';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['nullable', Rule::in([self::MODE_EMAIL, self::MODE_CODE])],
            'email' => ['nullable', 'required_if:mode,'.self::MODE_EMAIL, 'email', 'max:255'],
            'role' => ['required', Rule::enum(ProjectRole::class)],
            'zone' => ['nullable', 'string', 'max:100'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required_if' => 'Une adresse e-mail est requise pour une invitation par e-mail.',
        ];
    }

    public function mode(): string
    {
        return $this->validated('mode') ?: ($this->filled('email') ? self::MODE_EMAIL : self::MODE_CODE);
    }

    public function role(): ProjectRole
    {
        return ProjectRole::from($this->validated('role'));
    }
}
