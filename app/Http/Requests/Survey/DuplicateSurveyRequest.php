<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /surveys/{id}/duplicate` : {title?, project_id?}.
 */
class DuplicateSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'project_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:survey_projects,id'],
        ];
    }
}
