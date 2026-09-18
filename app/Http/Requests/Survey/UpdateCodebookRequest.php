<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /surveys/{id}/verbatims/codebooks/{codebookId}` (B-11) : renommage et fusion de thèmes.
 *
 * Un thème portant `merge_into` est absorbé par le thème cible (ses codages sont réaffectés) puis retiré.
 */
class UpdateCodebookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'themes' => ['required', 'array', 'min:1', 'max:50'],
            'themes.*.key' => ['required', 'string', 'max:40'],
            'themes.*.label' => ['nullable', 'string', 'max:120'],
            'themes.*.description' => ['nullable', 'string', 'max:500'],
            'themes.*.examples' => ['nullable', 'array', 'max:5'],
            'themes.*.examples.*' => ['string', 'max:300'],
            'themes.*.merge_into' => ['nullable', 'string', 'max:40'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function themes(): array
    {
        return array_values((array) $this->validated('themes'));
    }
}
