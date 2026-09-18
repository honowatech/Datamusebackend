<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /surveys/{id}/public-links` (B-12).
 */
class StorePublicLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_responses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'expires_at.after' => "La date d'expiration doit être postérieure à maintenant.",
            'max_responses.min' => 'Le nombre maximal de réponses doit valoir au moins 1.',
        ];
    }
}
