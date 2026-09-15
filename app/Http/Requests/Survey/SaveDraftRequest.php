<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /surveys/{id}/draft` : {definition (objet DFS v1), base_revision (≥ 0)}.
 * La validation DFS (structurelle → 422 liste `{path, code, message}`) est faite par SurveyVersionService.
 */
class SaveDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'definition' => ['required', 'array'],
            'base_revision' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->validated('definition');
    }

    public function baseRevision(): int
    {
        return (int) $this->validated('base_revision');
    }
}
