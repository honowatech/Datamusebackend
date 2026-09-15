<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /surveys/{id}/assignments` : {assignments: [{user_id, zone?, quota_target?, starts_at?, ends_at?}]} (≤ 500).
 * L'appartenance des `user_id` aux enquêteurs actifs du projet est vérifiée par SurveyVersionService.
 */
class SyncAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assignments' => ['present', 'array', 'max:500'],
            'assignments.*.user_id' => ['required', 'integer', 'min:1', 'distinct'],
            'assignments.*.zone' => ['nullable', 'string', 'max:100'],
            'assignments.*.quota_target' => ['nullable', 'integer', 'min:0'],
            'assignments.*.starts_at' => ['nullable', 'date'],
            'assignments.*.ends_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<int, array{user_id: int, zone?: string|null, quota_target?: int|null, starts_at?: string|null, ends_at?: string|null}>
     */
    public function assignments(): array
    {
        return array_values($this->validated('assignments') ?? []);
    }
}
