<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /mobile/submissions` : lot de 1 à 50 payloads `$defs/Submission`.
 *
 * La validation de forme reste volontairement **superficielle** (uuid, identifiants, horodatages) :
 * la conformité fine des réponses relève du moteur DFS, qui produit un résultat `rejected` par
 * élément plutôt qu'un `422` global (le lot n'est jamais tout-ou-rien).
 *
 * Un lot de plus de 50 éléments est refusé avant la validation par `SubmissionSyncController`
 * (`413`, cf. plan § 5.3) ; `max:50` ci-dessous ne sert que de garde-fou.
 */
class SyncSubmissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Pas de `max:` ici : au-delà de MAX_BATCH le contrôleur répond 413 (et non 422).
            'submissions' => ['required', 'array', 'min:1'],
            'submissions.*' => ['required', 'array'],
            'submissions.*.uuid' => ['required', 'string', 'uuid'],
            'submissions.*.survey_id' => ['required', 'integer', 'min:1'],
            'submissions.*.form_version' => ['required', 'integer', 'min:1'],
            'submissions.*.language' => ['required', 'string', 'max:10'],
            'submissions.*.started_at' => ['required', 'date'],
            'submissions.*.ended_at' => ['required', 'date'],
            'submissions.*.client_updated_at' => ['required', 'date'],
            'submissions.*.status' => ['required', 'string', 'in:completed,screened_out'],
            'submissions.*.answers' => ['present', 'array'],
            'submissions.*.device_id' => ['nullable', 'string', 'max:128'],
            'submissions.*.fiche_code' => ['nullable', 'string', 'max:64'],
            'submissions.*.zone' => ['nullable', 'string', 'max:100'],
            'submissions.*.end_reason' => ['nullable', 'string', 'max:40'],
            'submissions.*.device_time_offset_ms' => ['nullable', 'integer'],
            'submissions.*.followup_stage' => ['nullable', 'string', 'max:40'],
            'submissions.*.parent_submission_uuid' => ['nullable', 'string', 'uuid'],
            'submissions.*.geo' => ['nullable', 'array'],
            'submissions.*.media' => ['nullable', 'array', 'max:50'],
            'submissions.*.media.*.question_key' => ['required', 'string', 'max:40'],
            'submissions.*.media.*.sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'submissions.*.media.*.mime' => ['required', 'string', 'max:100'],
            'submissions.*.media.*.size' => ['required', 'integer', 'min:0'],
            'submissions.*.media.*.repeat_index' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function submissions(): array
    {
        // On repart des données brutes : `validated()` élide les clés non listées (valeurs de réponse imbriquées).
        $rows = (array) $this->input('submissions', []);

        return array_values(array_filter($rows, 'is_array'));
    }
}
