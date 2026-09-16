<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /surveys/import/xlsform` (multipart) : `{project_id, file (.xlsx ≤ 5 Mo), title?}`.
 *
 * La taille est vérifiée par le contrôleur **avant** la validation (réponse `413` du contrat, et non `422`).
 */
class ImportXlsFormRequest extends FormRequest
{
    /** Contrat : XLSForm ≤ 5 Mo. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Extensions acceptées par `XlsFormConverter::fromXlsForm()`. */
    public const EXTENSIONS = ['xlsx', 'xls', 'csv'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'file' => ['required', 'file'],
            'title' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Joignez le classeur XLSForm à importer (champ `file`).',
        ];
    }

    public function projectId(): int
    {
        return (int) $this->validated('project_id');
    }

    public function title(): ?string
    {
        $title = $this->validated('title');

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }
}
