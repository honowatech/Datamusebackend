<?php

namespace App\Http\Requests\Survey;

/**
 * Schéma OpenAPI `ProjectIn` (mise à jour) : remplacement des champs fournis, `name` obligatoire s'il est présent.
 */
class UpdateProjectRequest extends StoreProjectRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:200']] + parent::rules();
    }
}
