<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /mobile/devices` : enregistrement / rafraîchissement d'un appareil
 * (contrat OpenAPI `registerDevice`). Idempotent sur `(user, device_id)`.
 */
class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'min:1', 'max:128'],
            'platform' => ['required', 'string', 'in:android,ios,web'],
            'app_version' => ['required', 'string', 'max:40'],
            'model' => ['nullable', 'string', 'max:120'],
            'push_token' => ['nullable', 'string', 'max:512'],
            'device_time' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'platform.in' => 'La plateforme doit être android, ios ou web.',
        ];
    }
}
