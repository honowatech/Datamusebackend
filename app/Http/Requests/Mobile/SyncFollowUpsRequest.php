<?php

namespace App\Http\Requests\Mobile;

use App\Services\Survey\FollowUpService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /mobile/follow-ups` : lot de 1 à 50 payloads `FollowUpIn` (`$defs/Submission` + `followup_stage`
 * + `parent_submission_uuid`).
 *
 * Comme pour `POST /mobile/submissions`, la validation de forme reste superficielle : la conformité des
 * réponses relève du moteur DFS, qui produit un `rejected` **par entrée** (le lot n'est jamais
 * tout-ou-rien).
 */
class SyncFollowUpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:'.FollowUpService::MAX_BATCH],
            'entries.*' => ['required', 'array'],
            'entries.*.uuid' => ['required', 'string', 'uuid'],
            'entries.*.followup_stage' => ['required', 'string', 'max:40'],
            'entries.*.parent_submission_uuid' => ['required', 'string', 'uuid'],
            'entries.*.answers' => ['present', 'array'],
            'entries.*.started_at' => ['nullable', 'date'],
            'entries.*.ended_at' => ['nullable', 'date'],
            'entries.*.client_updated_at' => ['nullable', 'date'],
            'entries.*.language' => ['nullable', 'string', 'max:10'],
            'entries.*.device_id' => ['nullable', 'string', 'max:128'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'entries.max' => 'Le lot doit contenir au plus '.FollowUpService::MAX_BATCH.' réponses de suivi.',
        ];
    }

    /**
     * Payloads bruts : `validated()` éliderait les valeurs de réponse imbriquées.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        $rows = (array) $this->input('entries', []);

        return array_values(array_filter($rows, 'is_array'));
    }
}
