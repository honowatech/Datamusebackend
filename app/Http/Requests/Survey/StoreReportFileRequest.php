<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /reports/{id}/files` (B-11) : archivage d'un DOCX/PDF produit **côté client** (W-12).
 */
class StoreReportFileRequest extends FormRequest
{
    /** Contrat : `.docx` ou `.pdf`, 10 Mo au maximum. */
    public const MAX_KB = 10240;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::MAX_KB],
            'format' => ['required', Rule::in(['docx', 'pdf'])],
            'label' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'format.in' => 'Le format doit valoir « docx » ou « pdf ».',
            'file.max' => 'Le fichier dépasse 10 Mo.',
        ];
    }

    /** `format()` est déjà pris par `Illuminate\Http\Request` (négociation de contenu). */
    public function fileFormat(): string
    {
        return (string) $this->validated('format');
    }

    public function label(): ?string
    {
        $label = $this->validated('label');

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }
}
