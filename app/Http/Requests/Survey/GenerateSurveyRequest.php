<?php

namespace App\Http\Requests\Survey;

use App\Support\ApiKeyResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /surveys/generate` (contrat : schéma `GenerateSurveyIn`).
 *
 * Deux formes acceptées :
 *   - `application/json` : `{project_id, source_text, language?, languages?, hints?, survey_id?, title?,
 *     provider?, apiKey?, create?}` ;
 *   - `multipart/form-data` : mêmes champs + `docx` (le contrôleur vérifie la taille avant validation pour
 *     répondre `413` et non `422`, et extrait le texte avec `DocxTextExtractor`).
 *
 * `source_text` et `docx` sont mutuellement exclusifs mais l'un des deux est obligatoire.
 */
class GenerateSurveyRequest extends FormRequest
{
    /** Longueur maximale du texte source (contrat : `source_text.maxLength`). */
    public const MAX_SOURCE_LENGTH = 200000;

    /** Minimum utile : en dessous, le modèle n'a rien à structurer. */
    public const MIN_SOURCE_LENGTH = 30;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'survey_id' => ['nullable', 'integer', 'min:1'],
            'source_text' => ['nullable', 'string', 'min:'.self::MIN_SOURCE_LENGTH, 'max:'.self::MAX_SOURCE_LENGTH],
            'docx' => ['nullable', 'file'],
            'title' => ['nullable', 'string', 'max:200'],
            'language' => ['nullable', 'string', 'regex:/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/'],
            'languages' => ['nullable', 'array', 'max:10'],
            'languages.*' => ['string', 'regex:/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/'],
            'hints' => ['nullable', 'array'],
            'hints.fiche_code_pattern' => ['nullable', 'string', 'max:200'],
            'hints.follow_up_days' => ['nullable', 'array', 'max:10'],
            'hints.follow_up_days.*' => ['integer', 'min:0', 'max:3650'],
            'hints.currency' => ['nullable', 'string', 'size:3'],
            'hints.extra' => ['nullable', 'string', 'max:2000'],
            'provider' => ['nullable', Rule::in(ApiKeyResolver::PROVIDERS)],
            'apiKey' => ['nullable', 'string', 'max:500'],
            'create' => ['nullable', 'boolean'],
        ];
    }

    /**
     * `source_text` **ou** `docx` : au moins l'un des deux (message sur `source_text`, comme le contrat).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (trim((string) $this->input('source_text')) === '' && ! $this->hasFile('docx')) {
                $validator->errors()->add(
                    'source_text',
                    'Fournissez le texte du questionnaire (`source_text`) ou un document Word (`docx`).',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source_text.min' => 'Le texte du questionnaire est trop court pour être structuré (:min caractères minimum).',
            'source_text.max' => 'Le texte du questionnaire dépasse :max caractères.',
            'language.regex' => 'La langue doit être un code court (fr, en, fr-CM).',
            'languages.*.regex' => 'Chaque langue doit être un code court (fr, en, fr-CM).',
        ];
    }

    public function projectId(): int
    {
        return (int) $this->validated('project_id');
    }

    public function surveyId(): ?int
    {
        $id = $this->validated('survey_id');

        return $id === null ? null : (int) $id;
    }

    public function defaultLanguage(): string
    {
        $language = $this->validated('language');

        return is_string($language) && $language !== '' ? $language : 'fr';
    }

    /**
     * Langues du questionnaire : `languages` si fourni, sinon la seule `language`. La langue par défaut est
     * toujours présente et placée en tête.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        $languages = $this->validated('languages');
        $languages = is_array($languages) ? array_values(array_filter($languages, 'is_string')) : [];
        $default = $this->defaultLanguage();

        if (! in_array($default, $languages, true)) {
            array_unshift($languages, $default);
        }

        return array_values(array_unique($languages));
    }

    /**
     * @return array<string, mixed>
     */
    public function hints(): array
    {
        $hints = $this->validated('hints');

        return is_array($hints) ? $hints : [];
    }

    public function provider(): string
    {
        return ApiKeyResolver::normalizeProvider($this->validated('provider'));
    }

    /**
     * Crée-t-on un questionnaire ? Faux = mode **proposition** : le résultat reste dans le job
     * (`result.definition`) pour être comparé et fusionné côté web.
     *
     * E-03 — `create: false` fait désormais foi **même avec un `survey_id`**. Le contrat le prévoit
     * (« Si fourni, la proposition est fusionnée côté web au lieu de créer un questionnaire ») et le
     * builder en a besoin : il envoie `survey_id` pour l'autorisation (`generateAi` sur CE questionnaire)
     * et `create: false` pour récupérer la proposition à passer au `DiffReview`. Sans `create`, un
     * `survey_id` continue de mettre à jour le brouillon, comme avant.
     */
    public function shouldCreate(): bool
    {
        if ($this->has('create')) {
            return $this->boolean('create');
        }

        return true;
    }

    public function title(): ?string
    {
        $title = $this->validated('title');

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }
}
