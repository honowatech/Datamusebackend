<?php

namespace App\Http\Requests\Survey;

use App\Enums\ReportOrientation;
use App\Support\ApiKeyResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /surveys/{id}/reports` (B-11, schéma `ReportIn`).
 */
class StoreReportRequest extends FormRequest
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
            'brief' => ['required', 'string', 'min:20', 'max:8000'],
            'orientation' => ['nullable', Rule::in(ReportOrientation::values())],
            'audience' => ['nullable', 'string', 'max:200'],
            'language' => ['nullable', 'string', 'max:10'],
            'tone' => ['nullable', Rule::in(['neutre', 'persuasif', 'factuel'])],
            'length' => ['nullable', Rule::in(['court', 'moyen', 'long'])],
            'sections' => ['nullable', 'array', 'max:20'],
            'sections.*' => ['string', 'max:120'],
            'include_verbatims' => ['nullable', 'boolean'],
            'provider' => ['nullable', Rule::in(ApiKeyResolver::PROVIDERS)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'brief.min' => 'Le brief doit faire au moins 20 caractères.',
        ];
    }

    /**
     * Colonnes de `survey_reports`.
     *
     * @return array<string, mixed>
     */
    public function attributesForReport(): array
    {
        return [
            'title' => (string) $this->validated('title'),
            'brief' => (string) $this->validated('brief'),
            'orientation' => (string) ($this->validated('orientation') ?? 'commercial'),
            'audience' => (string) ($this->validated('audience') ?? 'Direction commerciale'),
            'language' => (string) ($this->validated('language') ?? 'fr'),
        ];
    }

    /**
     * Reste du `ReportIn`, stocké dans `survey_reports.options`.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [
            'tone' => (string) ($this->validated('tone') ?? 'factuel'),
            'length' => (string) ($this->validated('length') ?? 'moyen'),
            'sections' => array_values((array) ($this->validated('sections') ?? [])),
            'include_verbatims' => (bool) ($this->validated('include_verbatims') ?? true),
        ];
    }

    public function provider(): string
    {
        return ApiKeyResolver::normalizeProvider($this->validated('provider'));
    }
}
