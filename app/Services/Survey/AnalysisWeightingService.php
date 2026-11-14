<?php

namespace App\Services\Survey;

use App\Models\Survey;
use App\Models\SurveyWeightingPlan;
use App\Models\User;

/**
 * Plan de pondération d'une enquête (`GET` / `PUT /surveys/{id}/analysis/weighting`).
 *
 * Le serveur ne calcule pas les poids : il conserve et valide le plan (marges cibles du calage sur
 * marges, bornage des poids) ; le navigateur exécute le calage sur le SQLite téléchargé.
 *
 * Une marge porte sur une variable **nominale ou ordinale** de la table `reponses` (une valeur par
 * soumission) ; ses cibles sont ramenées à des parts de somme 1. Les questions `pii` ne sont pas
 * éligibles : le plan doit s'appliquer à tous les lecteurs, y compris ceux dont le fichier est masqué.
 */
class AnalysisWeightingService
{
    public const MAX_MARGINS = 10;

    public const MAX_TARGETS = 200;

    public function __construct(
        private readonly AnalysisDictionaryService $dictionary,
        private readonly AnalysisDatasetService $datasets,
    ) {}

    /**
     * Plan enregistré (ou plan vide), avec les avertissements de cohérence vis-à-vis du dictionnaire actuel.
     *
     * @return array<string, mixed>
     */
    public function show(Survey $survey): array
    {
        $plan = SurveyWeightingPlan::query()->with('editor:id,name')->where('survey_id', $survey->id)->first();
        if ($plan === null) {
            return [
                'enabled' => false,
                'margins' => [],
                'bounds' => ['min' => null, 'max' => null],
                'updated_at' => null,
                'updated_by' => null,
                'warnings' => [],
            ];
        }

        $margins = is_array($plan->margins) ? $plan->margins : [];
        $eligible = $this->eligibleVariables($survey);
        $warnings = [];
        foreach ($margins as $margin) {
            $name = (string) ($margin['variable'] ?? '');
            if (! isset($eligible[$name])) {
                $warnings[] = "La variable « {$name} » n'existe plus dans le dictionnaire ou n'est plus pondérable : cette marge sera ignorée.";

                continue;
            }
            $known = array_column($eligible[$name]['choices'], 'code');
            $unknown = $known === [] ? [] : array_diff(array_column($margin['targets'] ?? [], 'code'), $known);
            if ($unknown !== []) {
                $warnings[] = "La variable « {$name} » n'a plus les modalités : ".implode(', ', $unknown).'.';
            }
        }

        return [
            'enabled' => (bool) $plan->enabled,
            'margins' => $margins,
            'bounds' => ['min' => $plan->min_weight, 'max' => $plan->max_weight],
            'updated_at' => $plan->updated_at?->toIso8601String(),
            'updated_by' => $plan->editor === null ? null : ['id' => $plan->editor->id, 'name' => $plan->editor->name],
            'warnings' => $warnings,
        ];
    }

    /**
     * Valide puis enregistre le plan. Renvoie `['errors' => {champ: [messages]}]` si le plan est invalide.
     *
     * @param  array<string, mixed>  $input
     * @return array{errors: array<string, list<string>>}|array{plan: array<string, mixed>}
     */
    public function save(Survey $survey, array $input, User $user): array
    {
        $errors = [];
        $eligible = $this->eligibleVariables($survey);

        $rawMargins = $input['margins'] ?? [];
        if (! is_array($rawMargins) || ! array_is_list($rawMargins)) {
            $errors['margins'][] = '`margins` doit être une liste.';
            $rawMargins = [];
        }
        if (count($rawMargins) > self::MAX_MARGINS) {
            $errors['margins'][] = 'Au plus '.self::MAX_MARGINS.' marges de calage.';
        }

        $margins = [];
        $seen = [];
        foreach (array_slice($rawMargins, 0, self::MAX_MARGINS) as $i => $margin) {
            $path = "margins.$i";
            $name = is_array($margin) && is_string($margin['variable'] ?? null) ? $margin['variable'] : '';
            if ($name === '' || ! isset($eligible[$name])) {
                $errors["$path.variable"][] = $name === ''
                    ? 'La variable de la marge est obligatoire.'
                    : "La variable « {$name} » n'est pas pondérable : il faut une variable nominale ou ordinale de la table `reponses`, non marquée `pii`.";

                continue;
            }
            if (isset($seen[$name])) {
                $errors["$path.variable"][] = "La variable « {$name} » figure déjà dans une autre marge.";

                continue;
            }
            $seen[$name] = true;

            $targets = $this->targets($margin['targets'] ?? null, array_column($eligible[$name]['choices'], 'code'), "$path.targets", $errors);
            if ($targets !== null) {
                $margins[] = ['variable' => $name, 'targets' => $targets];
            }
        }

        $bounds = is_array($input['bounds'] ?? null) ? $input['bounds'] : [];
        $min = $this->bound($bounds['min'] ?? null, 'bounds.min', $errors);
        $max = $this->bound($bounds['max'] ?? null, 'bounds.max', $errors);
        if ($min !== null && ($min <= 0.0 || $min > 1.0)) {
            $errors['bounds.min'][] = 'Le poids minimal doit être supérieur à 0 et au plus égal à 1.';
        }
        if ($max !== null && $max < 1.0) {
            $errors['bounds.max'][] = 'Le poids maximal doit être au moins égal à 1.';
        }

        if ($errors !== []) {
            return ['errors' => $errors];
        }

        SurveyWeightingPlan::query()->updateOrCreate(['survey_id' => $survey->id], [
            'enabled' => array_key_exists('enabled', $input) ? filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN) : $margins !== [],
            'margins' => $margins,
            'min_weight' => $min,
            'max_weight' => $max,
            'updated_by' => $user->id,
        ]);

        return ['plan' => $this->show($survey)];
    }

    // ------------------------------------------------------------------ validation

    /**
     * Cibles d'une marge, normalisées en parts de somme 1.
     *
     * @param  list<string>  $knownCodes  modalités déclarées (vide = variable sans liste de choix : tout code est admis)
     * @param  array<string, list<string>>  $errors
     * @return list<array{code: string, share: float}>|null
     */
    private function targets(mixed $raw, array $knownCodes, string $path, array &$errors): ?array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) < 2) {
            $errors[$path][] = 'Une marge comporte au moins deux cibles `{code, share}`.';

            return null;
        }
        if (count($raw) > self::MAX_TARGETS) {
            $errors[$path][] = 'Au plus '.self::MAX_TARGETS.' cibles par marge.';

            return null;
        }

        $values = [];
        foreach ($raw as $j => $target) {
            $code = is_array($target) && is_scalar($target['code'] ?? null) ? trim((string) $target['code']) : '';
            $share = is_array($target) ? ($target['share'] ?? null) : null;
            if ($code === '') {
                $errors["$path.$j.code"][] = 'Le code de la modalité est obligatoire.';
            } elseif (isset($values[$code])) {
                $errors["$path.$j.code"][] = "La modalité « {$code} » est ciblée deux fois.";
            } elseif ($knownCodes !== [] && ! in_array($code, $knownCodes, true)) {
                $errors["$path.$j.code"][] = "La modalité « {$code} » n'existe pas pour cette variable.";
            }
            if (! is_numeric($share) || (float) $share <= 0.0 || ! is_finite((float) $share)) {
                $errors["$path.$j.share"][] = 'La part cible doit être un nombre strictement positif.';
            } elseif ($code !== '') {
                $values[$code] = (float) $share;
            }
        }
        if (count($values) !== count($raw)) {
            return null;
        }

        $sum = array_sum($values);
        $out = [];
        foreach ($values as $code => $share) {
            $out[] = ['code' => (string) $code, 'share' => round($share / $sum, 6)];
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function bound(mixed $value, string $path, array &$errors): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            $errors[$path][] = 'La borne doit être un nombre.';

            return null;
        }

        return round((float) $value, 4);
    }

    /**
     * Variables sur lesquelles on peut caler : nominales ou ordinales, une valeur par soumission.
     *
     * @return array<string, array<string, mixed>> nom => variable du dictionnaire
     */
    private function eligibleVariables(Survey $survey): array
    {
        $source = $this->datasets->describe($survey, false)['source'] ?? null;

        $out = [];
        foreach ($this->dictionary->variables($survey, null, false, $source) as $variable) {
            if (in_array($variable['level'], ['nominal', 'ordinal'], true) && $variable['table'] === 'reponses') {
                $out[(string) $variable['name']] = $variable;
            }
        }

        return $out;
    }
}
