<?php

namespace App\Http\Requests\Survey;

use App\Support\ApiKeyResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /surveys/{survey}/ai/translate` : `{target_lang, overwrite?, provider?, apiKey?}`.
 */
class TranslateSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target_lang' => ['required', 'string', 'regex:/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/'],
            'overwrite' => ['nullable', 'boolean'],
            'provider' => ['nullable', Rule::in(ApiKeyResolver::PROVIDERS)],
            'apiKey' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'target_lang.regex' => 'La langue cible doit être un code court (en, es, fr-CM).',
        ];
    }

    public function targetLang(): string
    {
        return (string) $this->validated('target_lang');
    }

    public function overwrite(): bool
    {
        return $this->boolean('overwrite');
    }

    public function provider(): string
    {
        return ApiKeyResolver::normalizeProvider($this->validated('provider'));
    }
}
