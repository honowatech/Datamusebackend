<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /surveys/{id}/verbatims/codebooks` (B-11, schéma `CodebookIn`) : création d'une version manuelle.
 */
class StoreCodebookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question_key' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]{0,39}$/'],
            'themes' => ['required', 'array', 'min:1', 'max:50'],
            'themes.*.key' => ['required', 'string', 'max:40'],
            'themes.*.label' => ['required', 'string', 'max:120'],
            'themes.*.description' => ['nullable', 'string', 'max:500'],
            'themes.*.examples' => ['nullable', 'array', 'max:5'],
            'themes.*.examples.*' => ['string', 'max:300'],
            'base_codebook_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'themes.min' => 'Un livre de codes contient au moins un thème.',
            'themes.max' => 'Un livre de codes contient au plus 50 thèmes.',
        ];
    }

    public function questionKey(): string
    {
        return (string) $this->validated('question_key');
    }

    /** @return list<array<string, mixed>> */
    public function themes(): array
    {
        return array_values((array) $this->validated('themes'));
    }

    public function baseCodebookId(): ?int
    {
        $id = $this->validated('base_codebook_id');

        return $id === null ? null : (int) $id;
    }
}
