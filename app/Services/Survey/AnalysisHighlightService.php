<?php

namespace App\Services\Survey;

use App\Models\Survey;
use App\Models\SurveyAnalysisHighlights;
use App\Models\SurveyDatasource;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Faits saillants d'une enquête (`GET` / `PUT /surveys/{id}/analysis/highlights`) — chantier 4.
 *
 * La découverte automatique (croisement de toutes les paires de variables, correction des comparaisons
 * multiples, classement par taille d'effet, rédaction par gabarits) est calculée **dans le navigateur**,
 * comme le reste du moteur d'analyse. Le serveur en conserve le résultat pour trois raisons :
 *
 *   - l'équipe voit les mêmes faits saillants sans que chaque appareil refasse le calcul ;
 *   - les rapports et la synthèse, produits côté serveur, peuvent s'appuyer sur des résultats natifs ;
 *   - le serveur sait dire si le jeu enregistré est périmé (données rematérialisées depuis).
 *
 * Le serveur ne recalcule rien : il valide la forme et la cohérence avec le dictionnaire. Un fait ne
 * peut citer que des variables non `pii` du dictionnaire, car le jeu est lu par tous les superviseurs.
 */
class AnalysisHighlightService
{
    public const MAX_ITEMS = 200;

    public const MAX_VARIABLES = 3;

    public const MAX_TEXT = 1000;

    /** Taille maximale, en octets JSON, de `details` (par fait) et de `params`. */
    public const MAX_FREE_JSON = 8192;

    public const LANGUAGES = ['fr', 'en'];

    public function __construct(
        private readonly AnalysisDictionaryService $dictionary,
        private readonly AnalysisDatasetService $datasets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(Survey $survey): array
    {
        $record = SurveyAnalysisHighlights::query()->with('editor:id,name')->where('survey_id', $survey->id)->first();
        $current = SurveyDatasource::query()->where('survey_id', $survey->id)->value('last_materialized_at');
        $current = $current === null ? null : Carbon::parse($current);

        if ($record === null) {
            return [
                'items' => [],
                'dataset_materialized_at' => null,
                'current_materialized_at' => $current?->toIso8601String(),
                'stale' => false,
                'weighted' => false,
                'engine' => null,
                'params' => null,
                'computed_at' => null,
                'updated_by' => null,
            ];
        }

        $known = $this->variableNames($survey);
        $items = array_values(array_filter(
            is_array($record->items) ? $record->items : [],
            fn ($item) => is_array($item) && array_diff($item['variables'] ?? [], $known) === [],
        ));
        $computedOn = $record->dataset_materialized_at;

        return [
            // Un fait dont une variable a disparu du dictionnaire (nouvelle version, question devenue `pii`) n'est plus servi.
            'items' => $items,
            'dataset_materialized_at' => $computedOn?->toIso8601String(),
            'current_materialized_at' => $current?->toIso8601String(),
            'stale' => $current !== null && ($computedOn === null || $computedOn->getTimestamp() !== $current->getTimestamp()),
            'weighted' => (bool) $record->weighted,
            'engine' => $record->engine,
            'params' => $record->params,
            'computed_at' => $record->updated_at?->toIso8601String(),
            'updated_by' => $record->editor === null ? null : ['id' => $record->editor->id, 'name' => $record->editor->name],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{errors: array<string, list<string>>}|array{highlights: array<string, mixed>}
     */
    public function save(Survey $survey, array $input, User $user): array
    {
        $errors = [];

        $rawItems = $input['items'] ?? null;
        if (! is_array($rawItems) || ! array_is_list($rawItems)) {
            $errors['items'][] = '`items` doit être une liste (vide pour effacer les faits saillants).';
            $rawItems = [];
        }
        if (count($rawItems) > self::MAX_ITEMS) {
            $errors['items'][] = 'Au plus '.self::MAX_ITEMS.' faits saillants.';
        }

        $known = $this->variableNames($survey);
        $items = [];
        foreach (array_slice($rawItems, 0, self::MAX_ITEMS) as $i => $raw) {
            $item = $this->item($raw, $known, "items.$i", $errors);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $materializedAt = null;
        if (($input['dataset_materialized_at'] ?? null) !== null) {
            try {
                $materializedAt = is_string($input['dataset_materialized_at']) ? Carbon::parse($input['dataset_materialized_at']) : null;
            } catch (Throwable) {
                $materializedAt = null;
            }
            if ($materializedAt === null) {
                $errors['dataset_materialized_at'][] = '`dataset_materialized_at` doit reprendre la date `dataset.materialized_at` du dictionnaire (ISO 8601).';
            }
        }

        $engine = $input['engine'] ?? null;
        if ($engine !== null && (! is_string($engine) || mb_strlen($engine) > 60)) {
            $errors['engine'][] = '`engine` est une chaîne de 60 caractères au plus.';
        }
        $params = $this->freeObject($input['params'] ?? null, 'params', $errors);

        if ($errors !== []) {
            return ['errors' => $errors];
        }

        SurveyAnalysisHighlights::query()->updateOrCreate(['survey_id' => $survey->id], [
            'dataset_materialized_at' => $materializedAt?->utc(),
            'weighted' => filter_var($input['weighted'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'engine' => $engine,
            'params' => $params,
            'items' => $items,
            'updated_by' => $user->id,
        ]);

        return ['highlights' => $this->show($survey)];
    }

    // ------------------------------------------------------------------ validation

    /**
     * @param  list<string>  $known
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>|null
     */
    private function item(mixed $raw, array $known, string $path, array &$errors): ?array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            $errors[$path][] = 'Chaque fait saillant est un objet.';

            return null;
        }
        $before = count($errors);

        $variables = $raw['variables'] ?? null;
        if (! is_array($variables) || ! array_is_list($variables) || $variables === [] || count($variables) > self::MAX_VARIABLES) {
            $errors["$path.variables"][] = 'Un fait cite de 1 à '.self::MAX_VARIABLES.' variables du dictionnaire.';
            $variables = [];
        }
        foreach ($variables as $j => $name) {
            if (! is_string($name) || ! in_array($name, $known, true)) {
                $errors["$path.variables.$j"][] = 'Variable inconnue du dictionnaire, ou marquée `pii` (un fait partagé ne peut pas en citer).';
            }
        }

        $text = [];
        if (is_array($raw['text'] ?? null)) {
            foreach (self::LANGUAGES as $lang) {
                $value = $raw['text'][$lang] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (! is_string($value) || mb_strlen($value) > self::MAX_TEXT) {
                    $errors["$path.text.$lang"][] = 'Le texte est une chaîne de '.self::MAX_TEXT.' caractères au plus.';

                    continue;
                }
                $text[$lang] = $value;
            }
        }
        if ($text === [] && ! isset($errors["$path.text.fr"]) && ! isset($errors["$path.text.en"])) {
            $errors["$path.text"][] = 'Le texte du fait est obligatoire dans au moins une langue (`fr`, `en`).';
        }

        $out = [
            'variables' => array_values($variables),
            'kind' => $this->label($raw['kind'] ?? null, "$path.kind", $errors),
            'test' => $this->label($raw['test'] ?? null, "$path.test", $errors),
            'statistic' => $this->number($raw['statistic'] ?? null, "$path.statistic", $errors),
            'df' => $this->number($raw['df'] ?? null, "$path.df", $errors),
            'p_value' => $this->probability($raw['p_value'] ?? null, "$path.p_value", $errors),
            'p_adjusted' => $this->probability($raw['p_adjusted'] ?? null, "$path.p_adjusted", $errors),
            'effect' => null,
            'n' => $this->number($raw['n'] ?? null, "$path.n", $errors),
            'text' => $text,
            'details' => $this->freeObject($raw['details'] ?? null, "$path.details", $errors),
        ];

        if (($raw['effect'] ?? null) !== null) {
            $effect = $raw['effect'];
            $name = is_array($effect) ? $this->label($effect['name'] ?? null, "$path.effect.name", $errors) : null;
            $value = is_array($effect) ? $this->number($effect['value'] ?? null, "$path.effect.value", $errors) : null;
            if ($name === null || $value === null) {
                $errors["$path.effect"][] = '`effect` vaut `{ name, value }` (taille d\'effet), ou `null`.';
            } else {
                $out['effect'] = ['name' => $name, 'value' => $value];
            }
        }

        return count($errors) === $before ? $out : null;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function label(mixed $value, string $path, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', $value) !== 1) {
            $errors[$path][] = 'Identifiant de 40 caractères au plus (lettres, chiffres, `_`, `-`, `.`).';

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function number(mixed $value, string $path, array &$errors): int|float|null
    {
        if ($value === null) {
            return null;
        }
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            $errors[$path][] = 'Nombre attendu (ou `null`).';

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function probability(mixed $value, string $path, array &$errors): int|float|null
    {
        $number = $this->number($value, $path, $errors);
        if ($number !== null && ($number < 0 || $number > 1)) {
            $errors[$path][] = 'Une p-valeur est comprise entre 0 et 1.';

            return null;
        }

        return $number;
    }

    /**
     * Objet JSON libre, borné en taille.
     *
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>|null
     */
    private function freeObject(mixed $value, string $path, array &$errors): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }
        if (! is_array($value) || array_is_list($value)) {
            $errors[$path][] = 'Objet attendu (ou `null`).';

            return null;
        }
        if (strlen((string) json_encode($value)) > self::MAX_FREE_JSON) {
            $errors[$path][] = 'Objet trop volumineux ('.self::MAX_FREE_JSON.' octets au plus).';

            return null;
        }

        return $value;
    }

    /**
     * Noms des variables du dictionnaire visibles par tous les lecteurs (hors `pii`).
     *
     * @return list<string>
     */
    private function variableNames(Survey $survey): array
    {
        $source = $this->datasets->describe($survey, false)['source'] ?? null;

        return array_map(fn (array $v) => (string) $v['name'], $this->dictionary->variables($survey, null, false, $source));
    }
}
