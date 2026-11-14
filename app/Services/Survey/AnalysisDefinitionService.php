<?php

namespace App\Services\Survey;

use App\Models\Survey;
use App\Models\SurveyAnalysisDefinition;
use App\Models\User;
use App\Services\Dfs\LogicEvaluator;
use App\Support\SqlIdentifier;

/**
 * Segments enregistrés et variables calculées de l'explorateur sans code — chantier 5.
 *
 * Les deux sont des expressions du **langage Logic** du DFS (mêmes opérateurs et arités que dans un
 * questionnaire), où `{"var": "<nom>"}` désigne une variable du dictionnaire d'analyse :
 *
 *   - un **segment** est une expression booléenne : la soumission appartient au segment si elle est vraie ;
 *   - une **variable calculée** produit une valeur par soumission, avec un niveau de mesure déclaré.
 *
 * L'évaluation ligne à ligne se fait dans le navigateur, sur le SQLite téléchargé. Le serveur conserve
 * les définitions et en garantit la validité : opérateurs et arités, variables existantes et non `pii`
 * (les définitions sont partagées avec tous les superviseurs), absence de référence circulaire.
 *
 * Une variable calculée ne peut citer que des variables du dictionnaire ; un segment peut en plus citer
 * des variables calculées.
 */
class AnalysisDefinitionService
{
    public const MAX_PER_KIND = 100;

    public const MAX_EXPRESSION_BYTES = 16384;

    public const MAX_DEPTH = 32;

    public const LEVELS = ['nominal', 'ordinal', 'numeric', 'date', 'text'];

    public function __construct(
        private readonly AnalysisDictionaryService $dictionary,
        private readonly AnalysisDatasetService $datasets,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Survey $survey, string $kind): array
    {
        $allowed = $this->allowedVariables($survey, $kind);

        return SurveyAnalysisDefinition::query()->with('editor:id,name')
            ->where('survey_id', $survey->id)->where('kind', $kind)
            ->orderBy('label')->orderBy('id')->get()
            ->map(fn (SurveyAnalysisDefinition $d) => $this->present($d, $allowed))
            ->all();
    }

    public function find(Survey $survey, string $kind, string $key): ?SurveyAnalysisDefinition
    {
        return SurveyAnalysisDefinition::query()
            ->where('survey_id', $survey->id)->where('kind', $kind)->where('key', $key)->first();
    }

    /**
     * Crée (`$existing` nul) ou remplace une définition.
     *
     * @param  array<string, mixed>  $input
     * @return array{errors: array<string, list<string>>}|array{definition: array<string, mixed>}
     */
    public function save(Survey $survey, string $kind, array $input, User $user, ?SurveyAnalysisDefinition $existing = null): array
    {
        $errors = [];
        $allowed = $this->allowedVariables($survey, $kind);

        $label = is_string($input['label'] ?? null) ? trim($input['label']) : '';
        if ($label === '' || mb_strlen($label) > 120) {
            $errors['label'][] = 'Le libellé est obligatoire (120 caractères au plus).';
        }

        $description = $input['description'] ?? null;
        if ($description !== null && (! is_string($description) || mb_strlen($description) > 500)) {
            $errors['description'][] = 'La description est une chaîne de 500 caractères au plus.';
        }

        $key = $existing?->key;
        if ($existing === null) {
            $key = $this->newKey($survey, $kind, $input['key'] ?? null, $label, $errors);
            if (SurveyAnalysisDefinition::query()->where('survey_id', $survey->id)->where('kind', $kind)->count() >= self::MAX_PER_KIND) {
                $errors['key'][] = 'Au plus '.self::MAX_PER_KIND.' définitions de ce type par enquête.';
            }
        } elseif (isset($input['key']) && $input['key'] !== $existing->key) {
            $errors['key'][] = 'La clé ne peut pas être modifiée : supprimez puis recréez la définition.';
        }

        $level = null;
        if ($kind === SurveyAnalysisDefinition::KIND_VARIABLE) {
            $level = $input['level'] ?? null;
            if (! is_string($level) || ! in_array($level, self::LEVELS, true)) {
                $errors['level'][] = 'Le niveau de mesure doit valoir : '.implode(', ', self::LEVELS).'.';
            }
        }

        $expression = $input['expression'] ?? null;
        if (! is_array($expression) || $expression === [] || array_is_list($expression)) {
            $errors['expression'][] = "L'expression Logic est obligatoire (un objet à un seul opérateur).";
        } elseif (strlen((string) json_encode($expression)) > self::MAX_EXPRESSION_BYTES) {
            $errors['expression'][] = 'Expression trop volumineuse ('.self::MAX_EXPRESSION_BYTES.' octets au plus).';
        } else {
            $this->checkExpression($expression, 'expression', $allowed, $errors, 0);
        }

        if ($errors !== []) {
            return ['errors' => $errors];
        }

        $attributes = [
            'label' => $label,
            'description' => $description === '' ? null : $description,
            'level' => $level,
            'expression' => $expression,
            'updated_by' => $user->id,
        ];
        if ($existing === null) {
            $definition = SurveyAnalysisDefinition::query()->create($attributes + [
                'survey_id' => $survey->id, 'kind' => $kind, 'key' => $key, 'created_by' => $user->id,
            ]);
        } else {
            $existing->fill($attributes)->save();
            $definition = $existing;
        }

        return ['definition' => $this->present($definition->load('editor:id,name'), $allowed)];
    }

    /**
     * Supprime une définition. Une variable calculée encore citée par des segments n'est pas supprimée :
     * renvoie alors la liste des clés de ces segments.
     *
     * @return list<string> segments bloquants (vide = supprimée)
     */
    public function delete(Survey $survey, SurveyAnalysisDefinition $definition): array
    {
        if ($definition->kind === SurveyAnalysisDefinition::KIND_VARIABLE) {
            $blocking = SurveyAnalysisDefinition::query()
                ->where('survey_id', $survey->id)->where('kind', SurveyAnalysisDefinition::KIND_SEGMENT)->get()
                ->filter(fn (SurveyAnalysisDefinition $segment) => in_array($definition->key, self::references($segment->expression), true))
                ->pluck('key')->values()->all();
            if ($blocking !== []) {
                return $blocking;
            }
        }
        $definition->delete();

        return [];
    }

    // ------------------------------------------------------------------ présentation

    /**
     * @param  array<string, true>  $allowed
     * @return array<string, mixed>
     */
    private function present(SurveyAnalysisDefinition $definition, array $allowed): array
    {
        $references = self::references($definition->expression);
        $missing = array_values(array_filter($references, fn (string $name) => ! isset($allowed[$name])));

        $out = [
            'key' => $definition->key,
            'label' => $definition->label,
            'description' => $definition->description,
        ];
        if ($definition->kind === SurveyAnalysisDefinition::KIND_VARIABLE) {
            $out['level'] = $definition->level;
        }

        return $out + [
            'expression' => $definition->expression,
            'references' => $references,
            // Variables citées qui n'existent plus (nouvelle version du questionnaire, question devenue `pii`).
            'missing' => $missing,
            'valid' => $missing === [],
            'updated_at' => $definition->updated_at?->toIso8601String(),
            'updated_by' => $definition->editor === null ? null : ['id' => $definition->editor->id, 'name' => $definition->editor->name],
        ];
    }

    /**
     * Noms de variables cités par une expression (`{"var": "nom"}`), dédoublonnés, dans l'ordre.
     *
     * @return list<string>
     */
    public static function references(mixed $expression): array
    {
        $out = [];
        foreach (LogicEvaluator::referencedVars($expression) as $reference) {
            $out[(string) $reference['ref']] = true;
        }

        return array_map('strval', array_keys($out));
    }

    // ------------------------------------------------------------------ validation

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function newKey(Survey $survey, string $kind, mixed $requested, string $label, array &$errors): ?string
    {
        if ($requested !== null && $requested !== '') {
            if (! is_string($requested) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $requested) !== 1) {
                $errors['key'][] = 'La clé commence par une lettre minuscule et ne contient que des minuscules, chiffres et `_` (40 caractères au plus).';

                return null;
            }
            $key = $requested;
        } else {
            $key = SqlIdentifier::clean($label, 40, $kind === SurveyAnalysisDefinition::KIND_SEGMENT ? 'seg_' : 'var_');
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
                $key = ($kind === SurveyAnalysisDefinition::KIND_SEGMENT ? 'seg_' : 'var_').substr(sha1($label), 0, 8);
            }
        }

        if ($this->find($survey, $kind, $key) !== null) {
            $errors['key'][] = "La clé « {$key} » est déjà utilisée.";
        } elseif ($kind === SurveyAnalysisDefinition::KIND_VARIABLE && isset($this->dictionaryNames($survey)[$key])) {
            $errors['key'][] = "La clé « {$key} » est déjà celle d'une variable du dictionnaire.";
        }

        return $key;
    }

    /**
     * Vérifie opérateurs, arités et variables citées (même grammaire que `DfsValidator`).
     *
     * @param  array<string, true>  $allowed
     * @param  array<string, list<string>>  $errors
     */
    private function checkExpression(mixed $node, string $path, array $allowed, array &$errors, int $depth): void
    {
        if (! is_array($node)) {
            return; // littéral
        }
        if ($depth > self::MAX_DEPTH) {
            $errors[$path][] = 'Expression trop profonde.';

            return;
        }
        if (array_is_list($node)) {
            foreach ($node as $i => $child) {
                $this->checkExpression($child, "$path.$i", $allowed, $errors, $depth + 1);
            }

            return;
        }
        if (count($node) !== 1) {
            $errors[$path][] = 'Un nœud opérateur a exactement une clé.';

            return;
        }

        $operator = (string) array_key_first($node);
        $arguments = $node[$operator];
        $here = "$path.$operator";

        if ($operator === 'var') {
            if (! is_string($arguments)) {
                $errors[$here][] = '`var` attend le nom d\'une variable.';
            } elseif (! isset($allowed[$arguments])) {
                $errors[$here][] = "Variable « {$arguments} » inconnue du dictionnaire, marquée `pii`, ou non autorisée ici.";
            }

            return;
        }
        if (! array_key_exists($operator, LogicEvaluator::ARITY)) {
            $errors[$here][] = "Opérateur inconnu : {$operator}.";

            return;
        }
        if (! is_array($arguments) || ! array_is_list($arguments)) {
            $errors[$here][] = "Les arguments de `{$operator}` doivent être un tableau.";

            return;
        }
        [$min, $max] = LogicEvaluator::ARITY[$operator];
        if (count($arguments) < $min || count($arguments) > $max) {
            $errors[$here][] = "`{$operator}` attend ".($min === $max ? $min : "{$min} à ".($max === PHP_INT_MAX ? '∞' : $max)).' argument(s), reçu '.count($arguments).'.';
        }
        foreach ($arguments as $i => $argument) {
            if (($operator === 'regex' && $i === 1) || ($operator === 'date_diff' && $i === 2)) {
                continue; // littéraux (motif, unité)
            }
            $this->checkExpression($argument, "$here.$i", $allowed, $errors, $depth + 1);
        }
    }

    /**
     * Variables qu'une définition de ce type peut citer.
     *
     * @return array<string, true>
     */
    private function allowedVariables(Survey $survey, string $kind): array
    {
        $allowed = $this->dictionaryNames($survey);
        if ($kind === SurveyAnalysisDefinition::KIND_SEGMENT) {
            $computed = SurveyAnalysisDefinition::query()->where('survey_id', $survey->id)
                ->where('kind', SurveyAnalysisDefinition::KIND_VARIABLE)->pluck('key');
            foreach ($computed as $key) {
                $allowed[(string) $key] = true;
            }
        }

        return $allowed;
    }

    /** @var array<int, array<string, true>> */
    private array $namesCache = [];

    /**
     * @return array<string, true> variables du dictionnaire visibles par tous (hors `pii`)
     */
    private function dictionaryNames(Survey $survey): array
    {
        if (! isset($this->namesCache[$survey->id])) {
            $source = $this->datasets->describe($survey, false)['source'] ?? null;
            $names = [];
            foreach ($this->dictionary->variables($survey, null, false, $source) as $variable) {
                $names[(string) $variable['name']] = true;
            }
            $this->namesCache[$survey->id] = $names;
        }

        return $this->namesCache[$survey->id];
    }
}
