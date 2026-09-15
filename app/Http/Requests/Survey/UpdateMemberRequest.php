<?php

namespace App\Http\Requests\Survey;

use App\Enums\ProjectRole;
use App\Models\ProjectMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /projects/{id}/members/{userId}` : {role (requis), zone?, status?}.
 */
class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(ProjectRole::class)],
            'zone' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in([ProjectMember::STATUS_ACTIVE, ProjectMember::STATUS_INACTIVE])],
        ];
    }

    public function role(): ProjectRole
    {
        return ProjectRole::from($this->validated('role'));
    }

    public function status(): string
    {
        return $this->validated('status') ?? ProjectMember::STATUS_ACTIVE;
    }
}
