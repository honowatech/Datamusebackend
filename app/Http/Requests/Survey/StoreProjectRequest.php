<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Schéma OpenAPI `ProjectIn` (création). L'autorisation (`create-project`) est vérifiée par le contrôleur.
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'client_name' => ['nullable', 'string', 'max:200'],
            'settings' => ['nullable', 'array'],
            'settings.timezone' => ['nullable', 'string', 'timezone:all'],
            'settings.currency' => ['nullable', 'string', 'max:10'],
            'settings.zones' => ['nullable', 'array', 'max:500'],
            'settings.zones.*' => ['string', 'max:100'],
        ];
    }

    /**
     * Champs prêts pour l'Eloquent (settings avec valeurs par défaut).
     *
     * @return array<string, mixed>
     */
    public function projectAttributes(): array
    {
        $attributes = $this->safe()->only(['name', 'description', 'client_name']);

        if ($this->has('settings')) {
            $settings = $this->validated('settings') ?? [];
            $attributes['settings'] = $settings + ['timezone' => 'Africa/Douala', 'currency' => 'XAF'];
        }

        return $attributes;
    }
}
