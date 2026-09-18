<?php

namespace App\Http\Requests\Survey;

use App\Support\ApiKeyResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /reports/{id}/regenerate-section` (B-11).
 */
class RegenerateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'heading' => ['required', 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'provider' => ['nullable', Rule::in(ApiKeyResolver::PROVIDERS)],
        ];
    }

    public function heading(): string
    {
        return trim((string) $this->validated('heading'));
    }

    public function instructions(): ?string
    {
        $instructions = $this->validated('instructions');

        return is_string($instructions) && trim($instructions) !== '' ? trim($instructions) : null;
    }

    public function provider(): string
    {
        return ApiKeyResolver::normalizeProvider($this->validated('provider'));
    }
}
