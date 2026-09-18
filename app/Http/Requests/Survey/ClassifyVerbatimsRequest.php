<?php

namespace App\Http\Requests\Survey;

use App\Services\Survey\VerbatimService;
use App\Support\ApiKeyResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /surveys/{id}/verbatims/classify` (B-11).
 */
class ClassifyVerbatimsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question_key' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]{0,39}$/'],
            'mode' => ['required', Rule::in(['discover', 'apply'])],
            'codebook_id' => ['nullable', 'integer', 'min:1'],
            'max_themes' => ['nullable', 'integer', 'min:2', 'max:'.VerbatimService::MAX_THEMES],
            'force' => ['nullable', 'boolean'],
            'language' => ['nullable', 'string', 'max:10'],
            'provider' => ['nullable', Rule::in(ApiKeyResolver::PROVIDERS)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'question_key.regex' => 'La clé de question est invalide.',
            'mode.in' => 'Le mode doit valoir « discover » ou « apply ».',
        ];
    }

    public function questionKey(): string
    {
        return (string) $this->validated('question_key');
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }

    public function codebookId(): ?int
    {
        $id = $this->validated('codebook_id');

        return $id === null ? null : (int) $id;
    }

    public function maxThemes(): int
    {
        return (int) ($this->validated('max_themes') ?? 10);
    }

    public function force(): bool
    {
        return (bool) ($this->validated('force') ?? false);
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
