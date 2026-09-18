<?php

namespace App\Http\Requests\Survey;

use App\Support\ApiKeyResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /surveys/{id}/synthesis` (B-11).
 */
class SynthesizeSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'focus' => ['nullable', 'string', 'max:1000'],
            'language' => ['nullable', 'string', 'max:10'],
            'provider' => ['nullable', Rule::in(ApiKeyResolver::PROVIDERS)],
        ];
    }

    public function focus(): ?string
    {
        $focus = $this->validated('focus');

        return is_string($focus) && trim($focus) !== '' ? trim($focus) : null;
    }

    public function language(): string
    {
        $lang = $this->validated('language');

        return is_string($lang) && $lang !== '' ? $lang : 'fr';
    }

    public function provider(): string
    {
        return ApiKeyResolver::normalizeProvider($this->validated('provider'));
    }
}
