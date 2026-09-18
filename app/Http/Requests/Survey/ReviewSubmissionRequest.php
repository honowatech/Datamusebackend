<?php

namespace App\Http\Requests\Survey;

use App\Enums\SubmissionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /submissions/{id}` — revue qualité : `status` et/ou `quality_notes`.
 *
 * `screened_out` n'est pas acceptable ici : il relève du moteur DFS (un `stop`), pas de la revue.
 */
class ReviewSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([
                SubmissionStatus::Submitted->value,
                SubmissionStatus::Validated->value,
                SubmissionStatus::Rejected->value,
            ])],
            'quality_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.in' => 'Le statut de revue doit être « submitted », « validated » ou « rejected ».',
        ];
    }
}
