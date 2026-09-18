<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /public/surveys/{token}/submissions` (B-12) : **une seule** soumission par appel,
 * accompagnée du pot de miel `website` (qui doit rester vide).
 *
 * Comme pour le mobile (B-07), la validation de forme reste superficielle : la conformité des
 * réponses relève du moteur DFS, qui produit un `rejected` plutôt qu'un `422`.
 */
class PublicSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'website' => ['nullable', 'string', 'max:255'],
            'submission' => ['required', 'array'],
            'submission.uuid' => ['required', 'string', 'uuid'],
            'submission.form_version' => ['nullable', 'integer', 'min:1'],
            'submission.language' => ['nullable', 'string', 'max:10'],
            'submission.started_at' => ['required', 'date'],
            'submission.ended_at' => ['required', 'date'],
            'submission.client_updated_at' => ['nullable', 'date'],
            'submission.status' => ['nullable', 'string', 'in:completed,screened_out'],
            'submission.answers' => ['present', 'array'],
            'submission.end_reason' => ['nullable', 'string', 'max:40'],
            'submission.geo' => ['nullable', 'array'],
            'submission.media' => ['nullable', 'array', 'max:50'],
            'submission.media.*.question_key' => ['required', 'string', 'max:40'],
            'submission.media.*.sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'submission.media.*.mime' => ['required', 'string', 'max:100'],
            'submission.media.*.size' => ['required', 'integer', 'min:0'],
            'submission.media.*.repeat_index' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Payload brut (`validated()` éliderait les valeurs de réponse imbriquées).
     *
     * @return array<string, mixed>
     */
    public function submission(): array
    {
        $payload = $this->input('submission');

        return is_array($payload) ? $payload : [];
    }

    /** Le pot de miel doit rester vide : rempli, la soumission est silencieusement ignorée. */
    public function isBot(): bool
    {
        return trim((string) $this->input('website', '')) !== '';
    }
}
