<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /reports/{id}` (B-11) : édition manuelle du titre et/ou du contenu.
 *
 * `content_json` est validé contre `ReportContent` par le contrôleur (`ReportContentValidator`) : la
 * validation fine ne peut pas s'exprimer en règles Laravel (propriétés interdites, largeur des lignes…).
 */
class UpdateReportRequest extends FormRequest
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
            'content_md' => ['sometimes', 'nullable', 'string', 'max:400000'],
            'content_json' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
