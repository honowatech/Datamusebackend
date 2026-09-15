<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /projects/{id}/surveys` : {title (requis), definition? (DFS v1, validée par DfsValidator)}.
 */
class StoreSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'definition' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function definition(): ?array
    {
        $definition = $this->validated('definition');

        return is_array($definition) && $definition !== [] ? $definition : null;
    }
}
