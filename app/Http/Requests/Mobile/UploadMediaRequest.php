<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /mobile/submissions/{uuid}/media/{questionKey}` : multipart `file` + `sha256` (+ `repeat_index`).
 *
 * La borne de taille est exprimée en kilo-octets à partir de `config('filesystems.survey_media_max_mb')` ;
 * un corps dépassant la limite PHP (`upload_max_filesize` / `post_max_size`) est converti en `413`
 * par le contrôleur. L'en-tête `Idempotency-Key` est lu par le contrôleur (dédoublonnage 24 h).
 */
class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = max(1, (int) config('filesystems.survey_media_max_mb', 25)) * 1024;

        return [
            'file' => ['required', 'file', 'max:'.$maxKb],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'repeat_index' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Le fichier est obligatoire.',
            'file.max' => 'Fichier trop volumineux (maximum '.(int) config('filesystems.survey_media_max_mb', 25).' Mo).',
            'sha256.regex' => 'sha256 doit être une empreinte hexadécimale de 64 caractères.',
        ];
    }

    public function repeatIndex(): int
    {
        $value = $this->input('repeat_index');

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    public function sha256(): string
    {
        return strtolower((string) $this->input('sha256'));
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
