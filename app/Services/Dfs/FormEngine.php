<?php

namespace App\Services\Dfs;

use InvalidArgumentException;
use stdClass;

/**
 * Moteur de formulaire DFS v1 (README § 4, § 6, § 7, § 8, § 10, § 11, § 16.7, § 17).
 *
 * Cycle : `setAnswer()` / `setAnswers()` / `toggleChoice()` → passes d'évaluation jusqu'à stabilité
 * (pertinence → calculs → `default` → détection des `stop` → code fiche ; 10 passes max, diagnostic
 * `unstable`) → lecture de `state()`, `visibleKeys()`, `validate()`, `payloadAnswers()`, `pages()`.
 *
 * Réponses : les valeurs sont conservées localement même quand la question devient non pertinente ;
 * elles sont exclues du payload et rendues `null` aux expressions (`var`). Les compagnons
 * `{key}_other` / `{key}__codes` sont des clés de réponse ordinaires, actives quand le choix « autre »
 * est sélectionné / quand le verbatim est répondu.
 *
 * Étapes de suivi : `enterStage('j7')` place le moteur dans l'étape ; `validate()`, `visibleKeys()` et
 * `payloadAnswers()` ne portent alors que sur les questions de l'étape, les réponses de base et des
 * étapes déjà entrées restant lisibles par les expressions.
 *
 * Groupes répétés : `answers[G]` est une liste d'instances `[{enfant: valeur}]` ; `setRepeatAnswer()`,
 * `addRepeatInstance()` et `removeRepeatInstance()` les manipulent ; les erreurs de validation portent
 * `repeat_index` (0-based).
 *
 * Codes d'erreur de validation (ordre required → constraint → type/paramètres, une erreur par question) :
 * `required`, `constraint`, `type`, `min`, `max`, `min_length`, `max_length`, `format`, `min_selected`,
 * `max_selected`, `min_ranked`, `max_ranked`, `accuracy`, `other_required`, `choice`.
 */
final class FormEngine
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SCREENED_OUT = 'screened_out';

    public const MAX_PASSES = 10;

    private const MESSAGES = [
        'fr' => [
            'required' => 'Réponse obligatoire.',
            'constraint' => 'Réponse non valide.',
            'type' => 'Format de réponse incorrect.',
            'min' => 'Valeur inférieure au minimum.',
            'max' => 'Valeur supérieure au maximum.',
            'min_length' => 'Texte trop court.',
            'max_length' => 'Texte trop long.',
            'format' => 'Format non reconnu.',
            'min_selected' => 'Pas assez de choix sélectionnés.',
            'max_selected' => 'Trop de choix sélectionnés.',
            'min_ranked' => 'Pas assez d\'éléments classés.',
            'max_ranked' => 'Trop d\'éléments classés.',
            'accuracy' => 'Précision GPS insuffisante.',
            'other_required' => 'Précisez la réponse « Autre ».',
            'choice' => 'Choix inconnu.',
        ],
        'en' => [
            'required' => 'This answer is required.',
            'constraint' => 'Invalid answer.',
            'type' => 'Incorrect answer format.',
            'min' => 'Value below the minimum.',
            'max' => 'Value above the maximum.',
            'min_length' => 'Text too short.',
            'max_length' => 'Text too long.',
            'format' => 'Unrecognised format.',
            'min_selected' => 'Not enough choices selected.',
            'max_selected' => 'Too many choices selected.',
            'min_ranked' => 'Not enough items ranked.',
            'max_ranked' => 'Too many items ranked.',
            'accuracy' => 'GPS accuracy insufficient.',
            'other_required' => 'Please specify the "Other" answer.',
            'choice' => 'Unknown choice.',
        ],
    ];

    /** @var array<string, mixed> */
    private array $definition;

    private QuestionCatalog $catalog;

    private EngineContext $ctx;

    private LogicEvaluator $evaluator;

    private FicheCodeGenerator $ficheGenerator;

    /** @var array<string, mixed> réponses locales (questions, compagnons, groupes répétés) */
    private array $answers = [];

    /** @var array<string, mixed> valeurs des `calculate` (null si non pertinent) */
    private array $calculated = [];

    /** @var array<string, bool> pertinence de chaque nœud (section, groupe, question, étape) */
    private array $relevance = [];

    /** @var array<string, true> */
    private array $defaultsApplied = [];

    /** @var array<string, array<int, array{relevance: array<string, bool>, calculated: array<string, mixed>, defaults: array<string, true>}>> */
    private array $repeat = [];

    private string $status = self::STATUS_IN_PROGRESS;

    private ?string $endReason = null;

    private ?int $stopIndex = null;

    private ?string $endTime = null;

    private string $startTime;

    private bool $finalized = false;

    private ?int $seq = null;

    private ?string $ficheCode = null;

    private ?string $currentStage = null;

    /** @var array<string, true> */
    private array $enteredStages = [];

    /** @var array<int, array{key: string|null, field: string, path: string, code: string, message: string}> */
    private array $diagnostics = [];

    /**
     * @param  array<string, mixed>|stdClass  $definition  définition DFS (les défauts du schéma sont appliqués ici)
     *
     * @throws InvalidArgumentException si `dfs_version` n'est pas une version majeure 1
     */
    public function __construct(array|stdClass $definition, EngineContext $ctx)
    {
        $def = DfsDefaults::apply($definition);
        $version = (string) ($def['dfs_version'] ?? '');
        if (preg_match('/^1(\.\d+)?$/', $version) !== 1) {
            throw new InvalidArgumentException("Version DFS non prise en charge : « {$version} » (attendu 1.x).");
        }
        $this->definition = $def;
        $this->catalog = new QuestionCatalog($def);
        $this->ctx = $ctx;
        $this->evaluator = new LogicEvaluator;
        $this->ficheGenerator = new FicheCodeGenerator;
        $this->startTime = $ctx->startTime ?? LogicEvaluator::nowString($ctx->clockVars());
        $this->runPasses();
    }

    // ---------------------------------------------------------------------------
    // Saisie
    // ---------------------------------------------------------------------------

    /** Renseigne une réponse (null = efface) puis réévalue le formulaire. */
    public function setAnswer(string $key, mixed $value): void
    {
        $this->store($key, $value);
        $this->runPasses();
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function setAnswers(array $answers): void
    {
        foreach ($answers as $key => $value) {
            $this->store((string) $key, $value);
        }
        $this->runPasses();
    }

    /**
     * Simule le clic sur un choix d'un `select_multiple` (ajout/retrait), en appliquant `exclusive` et
     * l'ordre de la liste.
     */
    public function toggleChoice(string $key, string $code): void
    {
        $node = $this->catalog->node($key);
        $def = $node['def'] ?? [];
        $current = isset($this->answers[$key]) && LogicEvaluator::isList($this->answers[$key]) ? $this->answers[$key] : [];
        $exclusive = is_array($def['exclusive'] ?? null) ? $def['exclusive'] : [];
        if (in_array($code, $current, true)) {
            $current = array_values(array_filter($current, static fn ($c) => $c !== $code));
        } elseif (in_array($code, $exclusive, true)) {
            $current = [$code];
        } else {
            $current = array_values(array_filter($current, static fn ($c) => ! in_array($c, $exclusive, true)));
            $current[] = $code;
        }
        $this->setAnswer($key, $current);
    }

    /** Renseigne la réponse d'un enfant d'une instance de groupe répété (index 0-based). */
    public function setRepeatAnswer(string $group, int $index, string $key, mixed $value): void
    {
        $instances = $this->instances($group);
        while (count($instances) <= $index) {
            $instances[] = [];
        }
        if ($value === null) {
            unset($instances[$index][$key]);
        } else {
            $instances[$index][$key] = $this->normalizeValue($key, $value);
        }
        $this->answers[$group] = array_values($instances);
        $this->runPasses();
    }

    /** Ajoute une instance vide ; renvoie son index, ou null si `repeat.max` est atteint. */
    public function addRepeatInstance(string $group): ?int
    {
        $node = $this->catalog->node($group);
        $instances = $this->instances($group);
        $max = $node['def']['repeat']['max'] ?? null;
        if (is_int($max) && count($instances) >= $max) {
            return null;
        }
        $instances[] = [];
        $this->answers[$group] = $instances;
        $this->runPasses();

        return count($instances) - 1;
    }

    public function removeRepeatInstance(string $group, int $index): void
    {
        $instances = $this->instances($group);
        if (isset($instances[$index])) {
            array_splice($instances, $index, 1);
            $this->answers[$group] = $instances;
            unset($this->repeat[$group]);
            $this->runPasses();
        }
    }

    /** Place le moteur dans une étape de suivi (null = formulaire de base). */
    public function enterStage(?string $stageKey): void
    {
        if ($stageKey !== null && ! in_array($stageKey, $this->catalog->stageKeys(), true)) {
            throw new InvalidArgumentException("Étape inconnue : {$stageKey}");
        }
        $this->currentStage = $stageKey;
        if ($stageKey !== null) {
            $this->enteredStages[$stageKey] = true;
        }
        $this->runPasses();
    }

    public function setLang(string $lang): void
    {
        $this->ctx->lang = $lang;
        $this->runPasses();
    }

    /**
     * Met à jour des variables système du contexte (`_enumerator`, `_seq`, `_today`, …) et réévalue.
     *
     * @param  array<string, mixed>  $vars
     */
    public function applyContext(array $vars): void
    {
        $this->ctx->applySystemVars($vars);
        $this->runPasses();
    }

    /**
     * Finalise l'entretien (ou l'étape courante) : valide tout, puis fige `_end_time` et le statut
     * (`completed`, ou `screened_out` si un stop est déclenché). Renvoie les erreurs de validation ;
     * la finalisation n'a lieu que si la liste est vide.
     *
     * @return array<int, array{key: string, repeat_index?: int, code: string, message: string}>
     */
    public function finalize(): array
    {
        $errors = $this->validate();
        if ($errors !== []) {
            return $errors;
        }
        $this->finalized = true;
        if ($this->status !== self::STATUS_SCREENED_OUT) {
            $this->status = self::STATUS_COMPLETED;
        }
        $this->endTime = $this->ctx->endTime ?? LogicEvaluator::nowString($this->ctx->clockVars());
        $this->runPasses();

        return [];
    }

    // ---------------------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------------------

    /**
     * @return array{answers: array<string, mixed>, status: string, endReason: string|null, calculated: array<string, mixed>, ficheCode: string|null, seq: int|null, endTime: string|null, startTime: string, stage: string|null, lang: string}
     */
    public function state(): array
    {
        return [
            'answers' => $this->answers,
            'status' => $this->status,
            'endReason' => $this->endReason,
            'calculated' => $this->calculated,
            'ficheCode' => $this->ficheCode,
            'seq' => $this->seqValue(),
            'endTime' => $this->ctx->endTime ?? $this->endTime,
            'startTime' => $this->startTime,
            'stage' => $this->currentStage,
            'lang' => $this->lang(),
        ];
    }

    public function status(): string
    {
        return $this->status;
    }

    public function endReason(): ?string
    {
        return $this->endReason;
    }

    /**
     * @return array<string, mixed>
     */
    public function calculated(): array
    {
        return $this->calculated;
    }

    /**
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        return $this->answers;
    }

    public function ficheCode(): ?string
    {
        return $this->ficheCode;
    }

    public function seq(): ?int
    {
        return $this->seqValue();
    }

    public function currentStage(): ?string
    {
        return $this->currentStage;
    }

    public function catalog(): QuestionCatalog
    {
        return $this->catalog;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->definition;
    }

    public function lang(): string
    {
        return $this->ctx->lang ?? $this->catalog->defaultLanguage();
    }

    /**
     * Diagnostics d'évaluation accumulés lors de la dernière réévaluation (`{key, field, path, code, message}`).
     *
     * @return array<int, array{key: string|null, field: string, path: string, code: string, message: string}>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** Pertinence brute d'un nœud (sans tenir compte du stop déclenché ni de l'étape courante). */
    public function isRelevant(string $key): bool
    {
        if (isset($this->relevance[$key])) {
            return $this->relevance[$key];
        }
        $companion = $this->catalog->companions()[$key] ?? null;
        if ($companion !== null) {
            return ($this->relevance[$companion['host']] ?? false) && $this->companionActive($key);
        }

        return false;
    }

    /**
     * Visibilité d'une clé (question, note, stop, groupe, section, étape ou compagnon) : pertinente,
     * dans le périmètre courant (formulaire de base ou étape entrée) et située avant ou au niveau du
     * premier `stop` déclenché.
     */
    public function isVisible(string $key): bool
    {
        $node = $this->catalog->node($key);
        if ($node === null) {
            $companion = $this->catalog->companions()[$key] ?? null;

            return $companion !== null && $this->isVisible($companion['host']) && $this->companionActive($key);
        }
        if (! ($this->relevance[$key] ?? false)) {
            return false;
        }
        if ($node['kind'] === 'stage') {
            return $this->currentStage === $key;
        }
        if (! $this->inScope($node)) {
            return false;
        }
        if ($node['stage'] !== null) {
            return true;
        }
        if ($this->stopIndex === null) {
            return true;
        }
        if ($node['kind'] === 'section') {
            return $node['index'] <= $this->stopSectionIndex();
        }

        return $node['index'] <= $this->stopIndex;
    }

    /**
     * Clés visibles (questions, notes, stops, groupes, compagnons actifs), dans l'ordre du document.
     *
     * @return string[]
     */
    public function visibleKeys(): array
    {
        $keys = [];
        foreach ($this->catalog->nodes() as $node) {
            if ($node['kind'] === 'section' || $node['kind'] === 'stage') {
                continue;
            }
            if ($node['repeat']) {
                continue;
            }
            if ($this->isVisible($node['key'])) {
                $keys[] = $node['key'];
                foreach ($this->companionKeysOf($node['key']) as $companion) {
                    if ($this->companionActive($companion)) {
                        $keys[] = $companion;
                    }
                }
            }
        }

        return $keys;
    }

    // ==== F-B3 ==== lecture des groupes répétés (la fiche de réponse rend chaque instance)

    /**
     * Instances d'un groupe répété, telles que stockées (index 0-based).
     *
     * @return array<int, array<string, mixed>>
     */
    public function instancesOf(string $group): array
    {
        return $this->instances($group);
    }

    /**
     * Pertinence d'un enfant **dans une instance** de groupe répété (index 0-based).
     *
     * `isRelevant()` / `isVisible()` ne savent rien des enfants répétés : leur pertinence est locale à
     * l'instance et vit dans l'état interne du groupe.
     */
    public function instanceRelevant(string $group, int $index, string $key): bool
    {
        return ($this->relevance[$group] ?? false)
            && (bool) ($this->repeat[$group][$index]['relevance'][$key] ?? false);
    }

    /**
     * Valeur d'un enfant d'instance : résultat du `calculate` s'il en est un, sinon la réponse saisie.
     */
    public function instanceValue(string $group, int $index, string $key): mixed
    {
        $node = $this->catalog->node($key);
        if (($node['type'] ?? null) === 'calculate') {
            return $this->repeat[$group][$index]['calculated'][$key] ?? null;
        }

        return $this->instances($group)[$index][$key] ?? null;
    }

    // ==== /F-B3 ====

    /**
     * Sections visibles du formulaire de base, dans l'ordre (pertinentes, jusqu'à la section du stop
     * déclenché incluse).
     *
     * @return string[]
     */
    public function visibleSections(): array
    {
        $out = [];
        foreach ($this->catalog->sectionKeys() as $key) {
            if (($this->relevance[$key] ?? false) && ($this->stopIndex === null || $this->catalog->node($key)['index'] <= $this->stopSectionIndex())) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Écrans du formulaire : `section` = une page par section visible (`{section, items}`) ;
     * `question` = une page par item visible hors `calculate`/`stop` (un groupe = un écran).
     *
     * @return array<int, array{section: string|null, items: string[]}>
     */
    public function pages(string $mode = 'section'): array
    {
        $pages = [];
        if ($this->currentStage !== null) {
            $stageNode = $this->catalog->node($this->currentStage);
            $items = array_values(array_filter($stageNode['children'], fn (string $k): bool => $this->isVisible($k) && ! in_array($this->catalog->node($k)['type'], ['calculate', 'stop'], true)));
            if ($mode === 'question') {
                foreach ($items as $item) {
                    $pages[] = ['section' => $this->currentStage, 'items' => [$item]];
                }
            } else {
                $pages[] = ['section' => $this->currentStage, 'items' => $items];
            }

            return $pages;
        }
        foreach ($this->visibleSections() as $sectionKey) {
            $section = $this->catalog->node($sectionKey);
            $items = [];
            foreach ($section['children'] as $childKey) {
                $child = $this->catalog->node($childKey);
                if (! $this->isVisible($childKey)) {
                    continue;
                }
                if ($mode === 'question' && in_array($child['type'], ['calculate', 'stop'], true)) {
                    continue;
                }
                $items[] = $childKey;
            }
            if ($mode === 'question') {
                foreach ($items as $item) {
                    $pages[] = ['section' => $sectionKey, 'items' => [$item]];
                }
            } else {
                $pages[] = ['section' => $sectionKey, 'items' => $items];
            }
        }

        return $pages;
    }

    /**
     * Pertinence d'une étape de suivi, évaluée sur les réponses de base et celles des étapes entrées
     * ou listées dans `$completedStages` (README § 14).
     *
     * @param  string[]  $completedStages
     */
    public function stageRelevant(string $stageKey, array $completedStages = []): bool
    {
        $node = $this->catalog->node($stageKey);
        if ($node === null || $node['kind'] !== 'stage') {
            return false;
        }
        $scopes = $this->enteredStages;
        foreach ($completedStages as $s) {
            $scopes[$s] = true;
        }
        $env = $this->buildEnv($scopes);

        return $this->truthyExpr($node['def']['relevant'] ?? null, $env, $this->sysVars(), $stageKey, 'relevant');
    }

    // ---------------------------------------------------------------------------
    // Libellés
    // ---------------------------------------------------------------------------

    /**
     * Valeurs affichables des réponses pertinentes (libellés des choix, nombres formatés, `""` si vide),
     * plus les variables système, pour l'interpolation `${cle}`.
     *
     * @return array<string, string>
     */
    public function displayValues(): array
    {
        $env = $this->buildEnv($this->enteredStages);
        $out = [];
        foreach ($env as $key => $value) {
            $info = $this->catalog->get((string) $key);
            $type = $info['type'] ?? null;
            if ($type === 'select_one') {
                $out[$key] = $this->choiceLabel($info['choices'] ?? [], LogicEvaluator::toStr($value));
            } elseif ($type === 'select_multiple' || $type === 'rank') {
                $codes = LogicEvaluator::isList($value) ? $value : [$value];
                $out[$key] = implode(', ', array_map(fn ($c) => $this->choiceLabel($info['choices'] ?? [], LogicEvaluator::toStr($c)), $codes));
            } elseif (LogicEvaluator::isObject($value)) {
                $out[$key] = '';
            } else {
                $out[$key] = LogicEvaluator::toStr($value);
            }
        }
        foreach ($this->sysVars() as $name => $value) {
            if (str_starts_with((string) $name, '_')) {
                $out[$name] = LogicEvaluator::toStr($value);
            }
        }

        return $out;
    }

    /** Libellé résolu d'un nœud (section, groupe, étape, question, compagnon), langue active, interpolé. */
    public function label(string $key): string
    {
        $node = $this->catalog->node($key);
        if ($node !== null) {
            return $this->text($node['def']['label'] ?? null, $key);
        }
        $companion = $this->catalog->companions()[$key] ?? null;
        if ($companion !== null) {
            $hostDef = $this->catalog->node($companion['host'])['def'] ?? [];
            $label = $companion['kind'] === 'other' ? ($hostDef['other']['label'] ?? null) : ($hostDef['postcode']['label'] ?? null);

            return $this->text($label, $key);
        }

        return $key;
    }

    /** Message d'un `stop` (ou `hint`, `description`, … : tout `I18n` du formulaire), interpolé. */
    public function text(mixed $i18n, string $fallback = ''): string
    {
        if (! is_array($i18n) && ! is_string($i18n) && ! $i18n instanceof stdClass) {
            return $fallback;
        }

        return LabelResolver::t($i18n, $this->lang(), $this->catalog->defaultLanguage(), $this->displayValues(), $fallback);
    }

    // ---------------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------------

    /**
     * Valide les questions visibles (du formulaire de base, d'une section, ou de l'étape courante).
     *
     * @return array<int, array{key: string, repeat_index?: int, code: string, message: string}>
     */
    public function validate(?string $sectionKey = null): array
    {
        $errors = [];
        $env = $this->buildEnv($this->enteredStages);
        $sys = $this->sysVars();
        foreach ($this->catalog->nodes() as $node) {
            if ($this->currentStage !== null) {
                if ($node['stage'] !== $this->currentStage) {
                    continue;
                }
            } elseif ($node['stage'] !== null || ($sectionKey !== null && $node['section'] !== $sectionKey)) {
                continue;
            }
            if ($node['kind'] === 'group' && $node['repeat']) {
                if (! $this->isVisible($node['key'])) {
                    continue;
                }
                foreach ($this->instances($node['key']) as $i => $instance) {
                    $state = $this->repeat[$node['key']][$i] ?? ['relevance' => [], 'calculated' => []];
                    $local = $this->instanceEnv($env, $instance, $state);
                    $localSys = ['_repeat_index' => $i + 1] + $sys;
                    foreach ($node['children'] as $childKey) {
                        if (! ($state['relevance'][$childKey] ?? false)) {
                            continue;
                        }
                        $child = $this->catalog->node($childKey);
                        $error = $this->validateQuestion($child['def'], $instance[$childKey] ?? null, $instance, $local, $localSys);
                        if ($error !== null) {
                            $errors[] = ['key' => $childKey, 'repeat_index' => $i] + $error;
                        }
                    }
                }

                continue;
            }
            if ($node['kind'] !== 'question' || $node['repeat'] || ! $this->isVisible($node['key'])) {
                continue;
            }
            $error = $this->validateQuestion($node['def'], $this->answers[$node['key']] ?? null, $this->answers, $env, $sys);
            if ($error !== null) {
                $errors[] = ['key' => $node['key']] + $error;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $store  réponses locales (formulaire ou instance) pour les compagnons
     * @param  array<string, mixed>  $env
     * @param  array<string, mixed>  $sys
     * @return array{code: string, message: string}|null
     */
    private function validateQuestion(array $def, mixed $value, array $store, array $env, array $sys): ?array
    {
        $type = (string) ($def['type'] ?? '');
        if (in_array($type, ['note', 'stop', 'calculate'], true)) {
            return null;
        }
        $key = (string) $def['key'];
        $empty = LogicEvaluator::isEmpty($value);

        // 1. required
        $required = $def['required'] ?? false;
        $isRequired = is_bool($required) ? $required : $this->truthyExpr($required, $env, $sys, $key, 'required');
        if ($isRequired && $empty) {
            return $this->error('required', $def['required_message'] ?? null);
        }
        if ($empty) {
            return null;
        }

        // 2. constraint
        if (array_key_exists('constraint', $def) && ! $this->truthyExpr($def['constraint'], $env, $sys, $key, 'constraint')) {
            return $this->error('constraint', $def['constraint_message'] ?? null);
        }

        // 3. type et paramètres
        $code = $this->validateType($def, $value, $store);

        return $code === null ? null : $this->error($code);
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $store
     */
    private function validateType(array $def, mixed $value, array $store): ?string
    {
        $type = (string) $def['type'];
        $key = (string) $def['key'];
        switch ($type) {
            case 'select_one':
                if (! is_string($value)) {
                    return 'type';
                }
                if (! $this->isKnownChoice($key, $value)) {
                    return 'choice';
                }

                return $this->otherRequired($def, [$value], $store);
            case 'select_multiple':
            case 'rank':
                if (! LogicEvaluator::isList($value)) {
                    return 'type';
                }
                foreach ($value as $code) {
                    if (! is_string($code)) {
                        return 'type';
                    }
                    if (! $this->isKnownChoice($key, $code)) {
                        return 'choice';
                    }
                }
                $n = count($value);
                if ($type === 'select_multiple') {
                    if (isset($def['min_selected']) && $n < (int) $def['min_selected']) {
                        return 'min_selected';
                    }
                    if (isset($def['max_selected']) && $n > (int) $def['max_selected']) {
                        return 'max_selected';
                    }

                    return $this->otherRequired($def, $value, $store);
                }
                $minRanked = isset($def['min_ranked']) ? (int) $def['min_ranked'] : count($this->catalog->choicesFor($key) ?? []);
                if ($n < $minRanked) {
                    return 'min_ranked';
                }
                if (isset($def['max_ranked']) && $n > (int) $def['max_ranked']) {
                    return 'max_ranked';
                }

                return null;
            case 'text':
                if (! is_string($value)) {
                    return 'type';
                }
                $len = mb_strlen($value, 'UTF-8');
                if (isset($def['min_length']) && $len < (int) $def['min_length']) {
                    return 'min_length';
                }
                if (isset($def['max_length']) && $len > (int) $def['max_length']) {
                    return 'max_length';
                }

                return self::formatValid($def['format'] ?? 'text', $value) ? null : 'format';
            case 'integer':
                if (! self::isIntegral($value)) {
                    return 'type';
                }

                return self::minMax($def, $value);
            case 'decimal':
            case 'currency':
                if (! is_int($value) && ! is_float($value)) {
                    return 'type';
                }
                if (isset($def['decimals']) && is_float($value)) {
                    $rounded = round($value, (int) $def['decimals']);
                    if (abs($rounded - $value) > 1e-9) {
                        return 'type';
                    }
                }

                return self::minMax($def, $value);
            case 'date':
                if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || LogicEvaluator::parseTemporal($value, 0) === null) {
                    return 'type';
                }

                return self::minMaxString($def, $value);
            case 'time':
                if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $value) !== 1) {
                    return 'type';
                }

                return self::minMaxString($def, $value);
            case 'datetime':
                if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) !== 1 || LogicEvaluator::parseTemporal($value, 0) === null) {
                    return 'type';
                }

                return self::minMaxString($def, $value);
            case 'geopoint':
                $v = self::asObject($value);
                if ($v === null || ! isset($v['lat'], $v['lng']) || ! is_numeric($v['lat']) || ! is_numeric($v['lng'])) {
                    return 'type';
                }
                if (isset($def['accuracy_max_m'], $v['accuracy']) && is_numeric($v['accuracy']) && (float) $v['accuracy'] > (float) $def['accuracy_max_m']) {
                    return 'accuracy';
                }

                return null;
            case 'photo':
            case 'signature':
            case 'audio':
                $v = self::asObject($value);
                if ($v === null || ! isset($v['sha256'], $v['mime'], $v['size']) || ! is_string($v['sha256']) || ! is_string($v['mime']) || ! is_numeric($v['size'])) {
                    return 'type';
                }

                return null;
            default:
                return null;
        }
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  string[]  $selected
     * @param  array<string, mixed>  $store
     */
    private function otherRequired(array $def, array $selected, array $store): ?string
    {
        $other = $def['other'] ?? null;
        if (! is_array($other) || ! ($other['required'] ?? true)) {
            return null;
        }
        if (! in_array($other['choice'] ?? null, $selected, true)) {
            return null;
        }
        $otherKey = $this->catalog->otherKeyOf((string) $def['key']) ?? $def['key'].'_other';

        return LogicEvaluator::isEmpty($store[$otherKey] ?? null) ? 'other_required' : null;
    }

    private function isKnownChoice(string $key, string $code): bool
    {
        $choices = $this->catalog->choicesFor($key);
        if ($choices === null) {
            return true;
        }
        foreach ($choices as $choice) {
            if ((string) (((array) $choice)['name'] ?? '') === $code) {
                return true;
            }
        }

        return false;
    }

    private static function isIntegral(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private static function minMax(array $def, int|float $value): ?string
    {
        if (isset($def['min']) && is_numeric($def['min']) && $value < $def['min']) {
            return 'min';
        }
        if (isset($def['max']) && is_numeric($def['max']) && $value > $def['max']) {
            return 'max';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private static function minMaxString(array $def, string $value): ?string
    {
        if (isset($def['min']) && is_string($def['min']) && strcmp($value, $def['min']) < 0) {
            return 'min';
        }
        if (isset($def['max']) && is_string($def['max']) && strcmp($value, $def['max']) > 0) {
            return 'max';
        }

        return null;
    }

    private static function formatValid(string $format, string $value): bool
    {
        return match ($format) {
            'phone' => preg_match('/^\+?[0-9][0-9 ().-]{4,24}$/', $value) === 1,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            default => true,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function asObject(mixed $value): ?array
    {
        if ($value instanceof stdClass) {
            return (array) $value;
        }

        return is_array($value) && ! array_is_list($value) ? $value : null;
    }

    /**
     * @return array{code: string, message: string}
     */
    private function error(string $code, mixed $message = null): array
    {
        $lang = $this->lang();
        $generic = self::MESSAGES[$lang][$code] ?? self::MESSAGES['fr'][$code] ?? $code;
        $text = $message !== null ? $this->text($message, $generic) : $generic;

        return ['code' => $code, 'message' => $text !== '' ? $text : $generic];
    }

    // ---------------------------------------------------------------------------
    // Payload
    // ---------------------------------------------------------------------------

    /**
     * `Submission.answers` : une clé par question pertinente et répondue, par `calculate` pertinent
     * (même null) et par compagnon actif ; en mode étape, uniquement les réponses de l'étape courante.
     *
     * @return array<string, mixed>
     */
    public function payloadAnswers(): array
    {
        $out = [];
        foreach ($this->catalog->nodes() as $node) {
            if ($this->currentStage !== null ? $node['stage'] !== $this->currentStage : $node['stage'] !== null) {
                continue;
            }
            if (! ($this->relevance[$node['key']] ?? false)) {
                continue;
            }
            if ($node['kind'] === 'group' && $node['repeat']) {
                $instances = $this->instancesPayload($node['key']);
                if ($instances !== []) {
                    $out[$node['key']] = $instances;
                }

                continue;
            }
            if ($node['kind'] !== 'question' || $node['repeat']) {
                continue;
            }
            $key = $node['key'];
            if ($node['type'] === 'calculate') {
                $out[$key] = $this->calculated[$key] ?? null;

                continue;
            }
            if (in_array($node['type'], ['note', 'stop'], true)) {
                continue;
            }
            $value = $this->answers[$key] ?? null;
            if (LogicEvaluator::isEmpty($value)) {
                continue;
            }
            $out[$key] = $value;
            foreach ($this->companionKeysOf($key) as $companion) {
                if ($this->companionActive($companion) && ! LogicEvaluator::isEmpty($this->answers[$companion] ?? null)) {
                    $out[$companion] = $this->answers[$companion];
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------------
    // Passes d'évaluation
    // ---------------------------------------------------------------------------

    private function runPasses(): void
    {
        $this->diagnostics = [];
        for ($i = 0; $i < self::MAX_PASSES; $i++) {
            $before = $this->snapshot();
            $this->diagnostics = [];
            $this->pass();
            if ($this->snapshot() === $before) {
                return;
            }
        }
        $this->diagnostics[] = ['key' => null, 'field' => 'engine', 'path' => '/', 'code' => 'unstable', 'message' => 'Le formulaire ne se stabilise pas après '.self::MAX_PASSES.' passes.'];
    }

    private function snapshot(): string
    {
        return json_encode([$this->relevance, $this->calculated, $this->answers, $this->status, $this->endReason, $this->seq, $this->ficheCode, $this->repeat], JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }

    private function pass(): void
    {
        $sys = $this->sysVars();
        $env = $this->buildEnv($this->enteredStages);

        // 1. Pertinence, dans l'ordre du document.
        foreach ($this->catalog->nodes() as $node) {
            $key = $node['key'];
            $def = $node['def'];
            if ($node['kind'] === 'question' && $node['repeat']) {
                continue; // traité avec le groupe
            }
            $parentRel = $node['parent'] === null ? true : ($this->relevance[$node['parent']] ?? true);
            $rel = $parentRel && $this->truthyExpr($def['relevant'] ?? null, $env, $sys, $key, 'relevant');
            $this->relevance[$key] = $rel;
            if ($node['kind'] === 'group' && $node['repeat']) {
                $this->passRepeat($node, $env, $sys, 'relevance');
                $this->syncEnv($env, $node);
            } elseif ($node['kind'] === 'question') {
                $this->syncEnv($env, $node);
            }
        }

        // 2. Calculs pertinents, dans l'ordre du document.
        foreach ($this->catalog->nodes() as $node) {
            if ($node['kind'] === 'group' && $node['repeat']) {
                $this->passRepeat($node, $env, $sys, 'calculate');
                $this->syncEnv($env, $node);

                continue;
            }
            if ($node['kind'] !== 'question' || $node['type'] !== 'calculate' || $node['repeat']) {
                continue;
            }
            $key = $node['key'];
            $this->calculated[$key] = ($this->relevance[$key] ?? false)
                ? $this->ev($node['def']['expression'] ?? null, $env, $sys, $key, 'expression')
                : null;
            $this->syncEnv($env, $node);
        }

        // 3. `default` des questions devenues visibles sans valeur (une seule fois).
        foreach ($this->catalog->nodes() as $node) {
            if ($node['kind'] === 'group' && $node['repeat']) {
                $this->passRepeat($node, $env, $sys, 'default');
                $this->syncEnv($env, $node);

                continue;
            }
            if ($node['kind'] !== 'question' || $node['repeat'] || ! array_key_exists('default', $node['def'])) {
                continue;
            }
            if (in_array($node['type'], ['calculate', 'note', 'stop'], true)) {
                continue;
            }
            $key = $node['key'];
            if (! ($this->relevance[$key] ?? false) || isset($this->defaultsApplied[$key]) || ($this->answers[$key] ?? null) !== null) {
                continue;
            }
            $this->defaultsApplied[$key] = true;
            $value = $this->ev($node['def']['default'], $env, $sys, $key, 'default');
            if ($value !== null) {
                $this->answers[$key] = $this->normalizeValue($key, $value);
            }
            $this->syncEnv($env, $node);
        }

        // 4. Détection des `stop` (§ 8).
        $this->detectStop();

        // 5. Code fiche et compteur (§ 11).
        $this->updateFicheCode($env);
    }

    /**
     * Sous-passe d'un groupe répété : pertinence, calculs ou `default` de chaque enfant, par instance.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $env
     * @param  array<string, mixed>  $sys
     */
    private function passRepeat(array $node, array $env, array $sys, string $phase): void
    {
        $group = $node['key'];
        $instances = $this->instances($group);
        $min = (int) ($node['def']['repeat']['min'] ?? 0);
        while (count($instances) < $min) {
            $instances[] = [];
        }
        $groupRel = $this->relevance[$group] ?? false;
        foreach ($instances as $i => $instance) {
            $state = $this->repeat[$group][$i] ?? ['relevance' => [], 'calculated' => [], 'defaults' => []];
            $localSys = ['_repeat_index' => $i + 1] + $sys;
            $local = $this->instanceEnv($env, $instance, $state);
            foreach ($node['children'] as $childKey) {
                $child = $this->catalog->node($childKey);
                $def = $child['def'];
                if ($phase === 'relevance') {
                    $rel = $groupRel && $this->truthyExpr($def['relevant'] ?? null, $local, $localSys, $childKey, 'relevant');
                    $state['relevance'][$childKey] = $rel;
                } elseif ($phase === 'calculate' && $child['type'] === 'calculate') {
                    $state['calculated'][$childKey] = ($state['relevance'][$childKey] ?? false)
                        ? $this->ev($def['expression'] ?? null, $local, $localSys, $childKey, 'expression')
                        : null;
                } elseif ($phase === 'default' && array_key_exists('default', $def) && ! in_array($child['type'], ['calculate', 'note', 'stop'], true)) {
                    if (($state['relevance'][$childKey] ?? false) && ! isset($state['defaults'][$childKey]) && ($instance[$childKey] ?? null) === null) {
                        $state['defaults'][$childKey] = true;
                        $value = $this->ev($def['default'], $local, $localSys, $childKey, 'default');
                        if ($value !== null) {
                            $instance[$childKey] = $this->normalizeValue($childKey, $value);
                        }
                    }
                }
                $local = $this->instanceEnv($env, $instance, $state);
            }
            $instances[$i] = $instance;
            $this->repeat[$group][$i] = $state;
        }
        $this->repeat[$group] = array_slice($this->repeat[$group] ?? [], 0, count($instances));
        $this->answers[$group] = $instances;
    }

    /**
     * Environnement d'évaluation à l'intérieur d'une instance : réponses globales + enfants pertinents.
     *
     * @param  array<string, mixed>  $env
     * @param  array<string, mixed>  $instance
     * @param  array{relevance: array<string, bool>, calculated: array<string, mixed>}  $state
     * @return array<string, mixed>
     */
    private function instanceEnv(array $env, array $instance, array $state): array
    {
        foreach ($state['relevance'] as $childKey => $rel) {
            if (! $rel) {
                continue;
            }
            $child = $this->catalog->node($childKey);
            if ($child['type'] === 'calculate') {
                $env[$childKey] = $state['calculated'][$childKey] ?? null;
            } elseif (array_key_exists($childKey, $instance) && $instance[$childKey] !== null) {
                $env[$childKey] = $instance[$childKey];
            }
        }

        return $env;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function instances(string $group): array
    {
        $value = $this->answers[$group] ?? null;
        if (! LogicEvaluator::isList($value)) {
            return [];
        }

        return array_values(array_map(static fn ($i) => $i instanceof stdClass ? (array) $i : (is_array($i) ? $i : []), $value));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function instancesPayload(string $group): array
    {
        $out = [];
        foreach ($this->instances($group) as $i => $instance) {
            $state = $this->repeat[$group][$i] ?? ['relevance' => [], 'calculated' => []];
            $row = [];
            foreach ($this->catalog->node($group)['children'] as $childKey) {
                if (! ($state['relevance'][$childKey] ?? false)) {
                    continue;
                }
                $child = $this->catalog->node($childKey);
                if ($child['type'] === 'calculate') {
                    $row[$childKey] = $state['calculated'][$childKey] ?? null;
                } elseif (! in_array($child['type'], ['note', 'stop'], true) && ! LogicEvaluator::isEmpty($instance[$childKey] ?? null)) {
                    $row[$childKey] = $instance[$childKey];
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    private function detectStop(): void
    {
        foreach ($this->catalog->nodes() as $node) {
            if ($node['kind'] !== 'question' || $node['type'] !== 'stop' || $node['stage'] !== null) {
                continue;
            }
            if ($this->relevance[$node['key']] ?? false) {
                $this->stopIndex = $node['index'];
                $this->endReason = $node['key'];
                $this->status = self::STATUS_SCREENED_OUT;

                return;
            }
        }
        $this->stopIndex = null;
        if ($this->status === self::STATUS_SCREENED_OUT && ! $this->finalized) {
            $this->status = self::STATUS_IN_PROGRESS;
        }
        if (! $this->finalized) {
            $this->endReason = null;
        }
    }

    /**
     * @param  array<string, mixed>  $env
     */
    private function updateFicheCode(array $env): void
    {
        $settings = $this->definition['settings']['fiche_code'] ?? null;
        if (! is_array($settings)) {
            $this->ficheCode = null;

            return;
        }
        $sources = (array) ($settings['sources'] ?? []);
        $values = [];
        $choiceLists = [];
        foreach ($sources as $token => $questionKey) {
            $questionKey = (string) $questionKey;
            $value = $env[$questionKey] ?? null;
            if (LogicEvaluator::isEmpty($value)) {
                $this->ficheCode = null;

                return;
            }
            $values[$questionKey] = $value;
            $choices = $this->catalog->choicesFor($questionKey);
            if ($choices !== null) {
                $choiceLists[$questionKey] = $choices;
            }
        }
        if ($this->seqValue() === null && $this->ctx->seqProvider !== null) {
            $tokens = [];
            foreach ($sources as $token => $questionKey) {
                $tokens[$token] = LogicEvaluator::toStr($values[(string) $questionKey]);
            }
            $allocated = ($this->ctx->seqProvider)([
                'scope' => (string) ($settings['counter']['scope'] ?? 'enumerator'),
                'enumerator_id' => $this->ctx->enumeratorId,
                'device_id' => $this->ctx->deviceId,
                'zone' => $this->ctx->zone,
                'sources' => $tokens,
            ]);
            $this->seq = is_int($allocated) ? $allocated : null;
        }
        $this->ficheCode = $this->ficheGenerator->render($settings, $values, $choiceLists, $this->seqValue());
    }

    private function seqValue(): ?int
    {
        return $this->ctx->seq ?? $this->seq;
    }

    // ---------------------------------------------------------------------------
    // Environnement et utilitaires
    // ---------------------------------------------------------------------------

    /**
     * Variables système (§ 16.5) et horloge.
     *
     * @return array<string, mixed>
     */
    private function sysVars(): array
    {
        return [
            '_status' => $this->ctx->status ?? $this->status,
            '_start_time' => $this->ctx->startTime ?? $this->startTime,
            '_end_time' => $this->ctx->endTime ?? $this->endTime,
            '_enumerator' => $this->ctx->enumeratorId,
            '_device' => $this->ctx->deviceId,
            '_zone' => $this->ctx->zone,
            '_lang' => $this->lang(),
            '_repeat_index' => null,
            '_seq' => $this->seqValue(),
        ] + $this->ctx->clockVars();
    }

    /**
     * Réponses effectives lisibles par `var` : questions pertinentes et répondues du formulaire de base
     * et des étapes listées, calculs pertinents, compagnons actifs, groupes répétés.
     *
     * @param  array<string, true>  $stageScopes
     * @return array<string, mixed>
     */
    private function buildEnv(array $stageScopes): array
    {
        $env = [];
        foreach ($this->catalog->nodes() as $node) {
            if ($node['stage'] !== null && ! isset($stageScopes[$node['stage']]) && $node['stage'] !== $this->currentStage) {
                continue;
            }
            if ($node['kind'] === 'question' && ! $node['repeat']) {
                $this->syncEnv($env, $node);
            } elseif ($node['kind'] === 'group' && $node['repeat']) {
                $this->syncEnv($env, $node);
            }
        }

        return $env;
    }

    /**
     * Met à jour l'environnement pour un nœud (question ou groupe répété) et ses compagnons.
     *
     * @param  array<string, mixed>  $env
     * @param  array<string, mixed>  $node
     */
    private function syncEnv(array &$env, array $node): void
    {
        $key = $node['key'];
        $rel = $this->relevance[$key] ?? true;
        if ($node['kind'] === 'group') {
            if ($rel) {
                $env[$key] = $this->instancesPayload($key);
            } else {
                unset($env[$key]);
            }

            return;
        }
        if (! $rel || in_array($node['type'], ['note', 'stop'], true)) {
            unset($env[$key]);
            foreach ($this->companionKeysOf($key) as $companion) {
                unset($env[$companion]);
            }

            return;
        }
        if ($node['type'] === 'calculate') {
            $env[$key] = $this->calculated[$key] ?? null;

            return;
        }
        if (array_key_exists($key, $this->answers) && $this->answers[$key] !== null) {
            $env[$key] = $this->answers[$key];
        } else {
            unset($env[$key]);
        }
        foreach ($this->companionKeysOf($key) as $companion) {
            if ($this->companionActive($companion) && array_key_exists($companion, $this->answers) && $this->answers[$companion] !== null) {
                $env[$companion] = $this->answers[$companion];
            } else {
                unset($env[$companion]);
            }
        }
    }

    /**
     * @return string[]
     */
    private function companionKeysOf(string $hostKey): array
    {
        $keys = [];
        $other = $this->catalog->otherKeyOf($hostKey);
        if ($other !== null) {
            $keys[] = $other;
        }
        $codes = $this->catalog->postcodeKeyOf($hostKey);
        if ($codes !== null) {
            $keys[] = $codes;
        }

        return $keys;
    }

    /** Un compagnon est actif quand le choix « autre » de l'hôte est sélectionné / le verbatim est répondu. */
    private function companionActive(string $companionKey): bool
    {
        $companion = $this->catalog->companions()[$companionKey] ?? null;
        if ($companion === null) {
            return false;
        }
        $host = $this->catalog->node($companion['host']);
        $value = $this->answers[$companion['host']] ?? null;
        if ($companion['kind'] === 'postcode') {
            return ! LogicEvaluator::isEmpty($value);
        }
        $choice = $host['def']['other']['choice'] ?? null;
        if (LogicEvaluator::isList($value)) {
            return in_array($choice, $value, true);
        }

        return is_string($value) && $value === $choice;
    }

    private function inScope(array $node): bool
    {
        if ($node['stage'] === null) {
            return $this->currentStage === null;
        }

        return $node['stage'] === $this->currentStage;
    }

    private function stopSectionIndex(): int
    {
        if ($this->stopIndex === null) {
            return PHP_INT_MAX;
        }
        $stopNode = $this->catalog->nodes()[$this->stopIndex];
        $section = $this->catalog->node((string) $stopNode['section']);

        return $section['index'] ?? PHP_INT_MAX;
    }

    private function store(string $key, mixed $value): void
    {
        if ($value === null) {
            unset($this->answers[$key]);

            return;
        }
        $node = $this->catalog->node($key);
        if ($node !== null && $node['kind'] === 'group' && $node['repeat']) {
            $this->answers[$key] = LogicEvaluator::isList($value) ? array_values(array_map(static fn ($i) => $i instanceof stdClass ? (array) $i : (is_array($i) ? $i : []), $value)) : [];
            unset($this->repeat[$key]);

            return;
        }
        $this->answers[$key] = $this->normalizeValue($key, $value);
    }

    /** Normalise une valeur saisie : `select_multiple` dans l'ordre de la liste ; stdClass → tableau. */
    private function normalizeValue(string $key, mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = DfsDefaults::toArray($value);
        }
        $info = $this->catalog->get($key);
        if ($info !== null && $info['type'] === 'select_multiple' && LogicEvaluator::isList($value)) {
            return $this->sortByChoiceOrder($value, $info['choices'] ?? []);
        }

        return $value;
    }

    /**
     * @param  array<int, mixed>  $codes
     * @param  array<int, array<string, mixed>>  $choices
     * @return array<int, mixed>
     */
    private function sortByChoiceOrder(array $codes, array $choices): array
    {
        $order = [];
        foreach ($choices as $i => $choice) {
            $order[(string) (((array) $choice)['name'] ?? '')] = $i;
        }
        $known = [];
        $unknown = [];
        foreach ($codes as $code) {
            if (is_string($code) && isset($order[$code])) {
                $known[$order[$code]] = $code;
            } else {
                $unknown[] = $code;
            }
        }
        ksort($known);

        return array_values(array_unique(array_merge(array_values($known), $unknown), SORT_REGULAR));
    }

    /**
     * @param  array<int, array<string, mixed>>  $choices
     */
    private function choiceLabel(array $choices, string $code): string
    {
        foreach ($choices as $choice) {
            $choice = (array) $choice;
            if ((string) ($choice['name'] ?? '') === $code) {
                return LabelResolver::resolve($choice['label'] ?? $code, $this->lang(), $this->catalog->defaultLanguage(), $code);
            }
        }

        return $code;
    }

    /**
     * Évalue une expression et journalise ses diagnostics.
     *
     * @param  array<string, mixed>  $env
     * @param  array<string, mixed>  $sys
     */
    private function ev(mixed $expr, array $env, array $sys, string $key, string $field): mixed
    {
        $result = $this->evaluator->evaluate($expr, $env, $sys);
        foreach ($result->diagnostics as $d) {
            $this->diagnostics[] = ['key' => $key, 'field' => $field] + $d;
        }

        return $result->value;
    }

    /**
     * Valeur de vérité d'une expression booléenne facultative (absente → vrai ; null → faux).
     *
     * @param  array<string, mixed>  $env
     * @param  array<string, mixed>  $sys
     */
    private function truthyExpr(mixed $expr, array $env, array $sys, string $key, string $field): bool
    {
        if ($expr === null) {
            return true;
        }
        if (is_bool($expr)) {
            return $expr;
        }

        return LogicEvaluator::truthy($this->ev($expr, $env, $sys, $key, $field));
    }
}
