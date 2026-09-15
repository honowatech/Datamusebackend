<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /invitations/accept` : `token` (64) **ou** `join_code` (8).
 */
class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required_without:join_code', 'prohibits:join_code', 'nullable', 'string', 'size:64'],
            'join_code' => ['required_without:token', 'nullable', 'string', 'size:8'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'token.required_without' => 'Fournissez un jeton d\'invitation ou un code de connexion.',
            'join_code.required_without' => 'Fournissez un jeton d\'invitation ou un code de connexion.',
            'token.prohibits' => 'Fournissez soit un jeton, soit un code de connexion, pas les deux.',
        ];
    }

    public function token(): ?string
    {
        return $this->validated('token') ?: null;
    }

    public function joinCode(): ?string
    {
        return $this->validated('join_code') ?: null;
    }
}
