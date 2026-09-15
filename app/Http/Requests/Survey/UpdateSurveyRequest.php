<?php

namespace App\Http\Requests\Survey;

use App\Enums\SurveyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /surveys/{id}` : {title?, status? (draft|active|closed)}.
 */
class UpdateSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', Rule::enum(SurveyStatus::class)],
        ];
    }

    public function status(): ?SurveyStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? SurveyStatus::from($status) : null;
    }
}
