<?php

namespace App\Services\Survey;

use App\Models\FollowUpEntry;
use App\Models\Submission;
use App\Models\SurveyVersion;
use App\Services\Dfs\LabelResolver;
use App\Services\Dfs\QuestionCatalog;
use App\Support\SqlIdentifier;

/**
 * Disposition des colonnes de la table `reponses` (une ligne par soumission) et des tables dérivées
 * (`rep_{groupe}`, `suivi`, `reponses_long`, `choix`, `questions`) d'un questionnaire — plan § 5.2.
 *
 * Construction en trois temps :
 *   1. `fromVersions()`  fusionne les questions de la version principale (publiée) avec celles des autres
 *      versions ayant des soumissions (ordre du document, clés absentes → NULL) ;
 *   2. `scan()`          (facultatif) observe les réponses pour typer les `calculate` et compter les
 *      instances des groupes répétés sans `repeat.max` ;
 *   3. `finalize()`      attribue les noms de colonnes (≤ 60 caractères, dédoublonnés, `SqlIdentifier`).
 *
 * Le même objet sert ensuite à produire les lignes (`rowFor()`, `longRowsFor()`, `repeatRowsFor()`,
 * `stageRowsFor()`) : l'export CSV/XLSX (B-10) réutilise ces méthodes pour garantir des colonnes identiques.
 */
final class ReponsesLayout
{
    public const MAX_REPEAT = 20;

    public const SEPARATOR = ';';

    /** @var array<string, string> colonnes de métadonnées (nom => type SQLite), dans l'ordre */
    public const META_COLUMNS = [
        'submission_id' => 'INTEGER',
        'uuid' => 'TEXT',
        'fiche_code' => 'TEXT',
        'enqueteur_id' => 'INTEGER',
        'enqueteur_nom' => 'TEXT',
        'zone' => 'TEXT',
        'canal' => 'TEXT',
        'statut' => 'TEXT',
        'version_formulaire' => 'INTEGER',
        'langue' => 'TEXT',
        'debut' => 'TEXT',
        'fin' => 'TEXT',
        'duree_sec' => 'INTEGER',
        'lat' => 'REAL',
        'lng' => 'REAL',
        'precision_m' => 'REAL',
        'flags' => 'TEXT',
        'score_suspicion' => 'INTEGER',
        'motif_fin' => 'TEXT',
        'recu_le' => 'TEXT',
    ];

    private const MEDIA_TYPES = ['photo', 'signature', 'audio'];

    /**
     * Questions fusionnées, dans l'ordre du document :
     * `{key, type, section, stage, group, repeat, label, choices: array<code, libellé>, other_key, codes_key,
     *   postcode_choices, coded, value_type, def}`.
     *
     * @var list<array<string, mixed>>
     */
    private array $entries = [];

    /** @var array<string, int> clé de question => index dans `$entries` */
    private array $entryIndex = [];

    /** @var array<string, array{key: string, label: string, section: ?string, repeat: bool, max: ?int, children: list<string>}> */
    private array $groups = [];

    /** @var array<string, array{key: string, label: string, def: array<string, mixed>, children: list<string>}> */
    private array $stageDefs = [];

    /** @var array<string, string> nom => type SQLite (table `reponses`) */
    private array $columns = [];

    /** @var list<array<string, mixed>> liaisons des questions de base non répétées */
    private array $baseBindings = [];

    /** @var array<string, array{table: string, columns: array<string, string>, bindings: list<array<string, mixed>>, instances: int, per_instance: array<int, list<array<string, mixed>>>}> */
    private array $repeats = [];

    /** @var array<string, array{statut: string, date: string, bindings: list<array<string, mixed>>}> */
    private array $stages = [];

    /** @var array{columns: array<string, string>, bindings: array<string, list<array<string, mixed>>>} */
    private array $suivi = ['columns' => [], 'bindings' => []];

    /** @var array<string, array{int: bool, real: bool, other: bool}> */
    private array $calcScan = [];

    /** @var array<string, int> groupe répété => nombre maximal d'instances observé */
    private array $repeatScan = [];

    /** @var array<int, string> */
    private array $enumeratorNames = [];

    /** @var array<int, int> id de version => numéro de version */
    private array $versionNumbers = [];

    /** @var list<string> */
    private array $warnings = [];

    private string $defaultLanguage = 'fr';

    private ?int $primaryVersionId = null;

    private bool $finalized = false;

    // ------------------------------------------------------------------ construction

    /**
     * @param  list<SurveyVersion>  $otherVersions  versions supplémentaires ayant des soumissions
     * @param  list<string>  $codedKeys  clés de questions texte disposant d'un codebook (→ `_themes`, `_sentiment`)
     */
    public static function fromVersions(SurveyVersion $primary, array $otherVersions = [], array $codedKeys = []): self
    {
        $layout = new self;
        $layout->primaryVersionId = $primary->id;
        $layout->versionNumbers[$primary->id] = (int) $primary->version;

        $primaryCatalog = QuestionCatalog::fromDefinition($primary->definition ?? []);
        $layout->defaultLanguage = $primaryCatalog->defaultLanguage();
        $layout->absorb($primaryCatalog, (int) $primary->version, $codedKeys);

        foreach ($otherVersions as $other) {
            if ($other->id === $primary->id) {
                continue;
            }
            $layout->versionNumbers[$other->id] = (int) $other->version;
            $layout->absorb(QuestionCatalog::fromDefinition($other->definition ?? []), (int) $other->version, $codedKeys);
        }

        return $layout;
    }

    /**
     * Fusionne les questions d'un catalogue : nouvelles clés insérées après leur prédécesseur connu,
     * choix inconnus ajoutés en fin de liste.
     *
     * @param  list<string>  $codedKeys
     */
    private function absorb(QuestionCatalog $catalog, int $versionNumber, array $codedKeys): void
    {
        $lang = $this->defaultLanguage;
        $previousKey = null;

        foreach ($catalog->nodes() as $node) {
            $kind = $node['kind'];
            $key = (string) $node['key'];
            $def = is_array($node['def']) ? $node['def'] : [];
            $label = isset($def['label']) ? LabelResolver::resolve($def['label'], $lang, $lang, $key) : $key;

            if ($kind === 'group') {
                $repeat = (bool) $node['repeat'];
                $max = $repeat && isset($def['repeat']['max']) && is_numeric($def['repeat']['max']) ? (int) $def['repeat']['max'] : null;
                if (! isset($this->groups[$key])) {
                    $this->groups[$key] = ['key' => $key, 'label' => $label, 'section' => $node['section'], 'repeat' => $repeat, 'max' => $max, 'children' => []];
                } elseif ($max !== null) {
                    $this->groups[$key]['max'] = max($this->groups[$key]['max'] ?? 0, $max);
                }

                continue;
            }
            if ($kind === 'stage') {
                if (! isset($this->stageDefs[$key])) {
                    $this->stageDefs[$key] = ['key' => $key, 'label' => $label, 'def' => $def, 'children' => []];
                }

                continue;
            }
            if ($kind !== 'question') {
                continue;
            }

            $type = (string) $node['type'];
            if (in_array($type, ['note', 'stop', 'group'], true)) {
                continue;
            }

            $choices = $this->choiceMap($catalog->choicesFor($key));
            $postcodeChoices = $catalog->postcodeKeyOf($key) !== null ? $this->choiceMap($catalog->postcodeChoicesFor($key)) : null;

            if (isset($this->entryIndex[$key])) {
                $idx = $this->entryIndex[$key];
                $entry = &$this->entries[$idx];
                if ($entry['type'] !== $type) {
                    $this->warnings[] = sprintf('Clé « %s » : type « %s » (v%d) différent de « %s » ; colonne conservée en « %s ».', $key, $type, $versionNumber, $entry['type'], $entry['type']);
                }
                foreach ($choices ?? [] as $code => $choiceLabel) {
                    if (! isset($entry['choices'][$code])) {
                        $entry['choices'][$code] = $choiceLabel;
                    }
                }
                foreach ($postcodeChoices ?? [] as $code => $choiceLabel) {
                    if (! isset($entry['postcode_choices'][$code])) {
                        $entry['postcode_choices'][$code] = $choiceLabel;
                    }
                }
                $entry['other_key'] ??= $catalog->otherKeyOf($key);
                $entry['codes_key'] ??= $catalog->postcodeKeyOf($key);
                unset($entry);
                $previousKey = $key;

                continue;
            }

            $entry = [
                'key' => $key,
                'type' => $type,
                'section' => $node['section'],
                'stage' => $node['stage'],
                'group' => $node['group'],
                'repeat' => (bool) $node['repeat'],
                'label' => $label,
                'choices' => $choices,
                'other_key' => $catalog->otherKeyOf($key),
                'codes_key' => $catalog->postcodeKeyOf($key),
                'postcode_choices' => $postcodeChoices,
                'coded' => $type === 'text' && in_array($key, $codedKeys, true),
                'value_type' => self::valueType($type, $def),
                'def' => $def,
                'version' => $versionNumber,
            ];

            $position = $previousKey !== null && isset($this->entryIndex[$previousKey]) ? $this->entryIndex[$previousKey] + 1 : count($this->entries);
            array_splice($this->entries, $position, 0, [$entry]);
            $this->reindexEntries();
            $previousKey = $key;
        }
    }

    private function reindexEntries(): void
    {
        $this->entryIndex = [];
        foreach ($this->entries as $i => $entry) {
            $this->entryIndex[$entry['key']] = $i;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $choices
     * @return array<string, string>|null code => libellé (langue par défaut)
     */
    private function choiceMap(?array $choices): ?array
    {
        if ($choices === null) {
            return null;
        }
        $map = [];
        foreach ($choices as $choice) {
            if (! is_array($choice) || ! isset($choice['name'])) {
                continue;
            }
            $code = (string) $choice['name'];
            $map[$code] = isset($choice['label']) ? LabelResolver::resolve($choice['label'], $this->defaultLanguage, $this->defaultLanguage, $code) : $code;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private static function valueType(string $type, array $def): string
    {
        return match ($type) {
            'integer' => 'INTEGER',
            'decimal' => 'REAL',
            'currency' => ((int) ($def['decimals'] ?? 0)) === 0 ? 'INTEGER' : 'REAL',
            'geopoint' => 'REAL',
            default => 'TEXT',
        };
    }

    // ------------------------------------------------------------------ pré-analyse

    /**
     * Observe un jeu de réponses (soumission, instance de groupe ou étape) : types des `calculate`,
     * nombre d'instances des groupes répétés.
     *
     * @param  array<string, mixed>  $answers
     */
    public function scan(array $answers): void
    {
        foreach ($answers as $key => $value) {
            $key = (string) $key;
            if (isset($this->groups[$key]) && $this->groups[$key]['repeat'] && is_array($value)) {
                $this->repeatScan[$key] = max($this->repeatScan[$key] ?? 0, count($value));
                foreach ($value as $instance) {
                    if (is_array($instance)) {
                        $this->scan($instance);
                    }
                }

                continue;
            }
            $idx = $this->entryIndex[$key] ?? null;
            if ($idx === null || $this->entries[$idx]['type'] !== 'calculate' || $value === null) {
                continue;
            }
            $flags = &$this->calcScan[$key];
            $flags ??= ['int' => false, 'real' => false, 'other' => false];
            if (is_bool($value) || is_int($value)) {
                $flags['int'] = true;
            } elseif (is_float($value)) {
                $flags['real'] = true;
            } else {
                $flags['other'] = true;
            }
            unset($flags);
        }
    }

    /**
     * Pré-analyse de toutes les soumissions et entrées de suivi d'un questionnaire (lecture légère).
     */
    public function scanSurvey(int $surveyId): void
    {
        Submission::query()->where('survey_id', $surveyId)->select(['id', 'answers'])->orderBy('id')
            ->chunk(1000, function ($rows) {
                foreach ($rows as $row) {
                    if (is_array($row->answers)) {
                        $this->scan($row->answers);
                    }
                }
            });

        FollowUpEntry::query()->where('survey_id', $surveyId)->whereNotNull('answers')->select(['id', 'answers'])->orderBy('id')
            ->chunk(1000, function ($rows) {
                foreach ($rows as $row) {
                    if (is_array($row->answers)) {
                        $this->scan($row->answers);
                    }
                }
            });
    }

    // ------------------------------------------------------------------ finalisation

    /**
     * Attribue les noms de colonnes. Idempotent.
     */
    public function finalize(): self
    {
        if ($this->finalized) {
            return $this;
        }
        $this->finalized = true;

        foreach ($this->entries as $i => $entry) {
            if ($entry['type'] === 'calculate') {
                $flags = $this->calcScan[$entry['key']] ?? null;
                $this->entries[$i]['value_type'] = match (true) {
                    $flags === null || $flags['other'] => 'TEXT',
                    $flags['real'] => 'REAL',
                    $flags['int'] => 'INTEGER',
                    default => 'TEXT',
                };
            }
            if ($entry['group'] !== null && isset($this->groups[$entry['group']])) {
                $this->groups[$entry['group']]['children'][] = $entry['key'];
            }
            if ($entry['stage'] !== null && isset($this->stageDefs[$entry['stage']])) {
                $this->stageDefs[$entry['stage']]['children'][] = $entry['key'];
            }
        }

        $taken = [];
        foreach (self::META_COLUMNS as $name => $type) {
            $taken[$name] = true;
            $this->columns[$name] = $type;
        }

        $doneGroups = [];
        foreach ($this->entries as $i => $entry) {
            if ($entry['stage'] !== null) {
                continue;
            }
            $group = $entry['group'];
            if ($entry['repeat'] && $group !== null && isset($this->groups[$group]) && $this->groups[$group]['repeat']) {
                if (isset($doneGroups[$group])) {
                    continue;
                }
                $doneGroups[$group] = true;
                $this->finalizeRepeat($group, $taken);

                continue;
            }
            $this->baseBindings[] = $this->bind($i, '', $taken, $this->columns, null, null);
        }

        $suiviTaken = [];
        foreach (['id', 'submission_id', 'fiche_code', 'stage_key', 'statut', 'echeance', 'fin_fenetre', 'complete_le', 'enqueteur_id'] as $name) {
            $suiviTaken[$name] = true;
        }
        $this->suivi['columns'] = [
            'submission_id' => 'INTEGER', 'fiche_code' => 'TEXT', 'stage_key' => 'TEXT', 'statut' => 'TEXT',
            'echeance' => 'TEXT', 'fin_fenetre' => 'TEXT', 'complete_le' => 'TEXT', 'enqueteur_id' => 'INTEGER',
        ];

        foreach ($this->stageDefs as $stageKey => $stage) {
            $statut = SqlIdentifier::unique(SqlIdentifier::clean($stageKey.'_statut'), $taken);
            $date = SqlIdentifier::unique(SqlIdentifier::clean($stageKey.'_date'), $taken);
            $this->columns[$statut] = 'TEXT';
            $this->columns[$date] = 'TEXT';
            $bindings = [];
            $suiviBindings = [];
            foreach ($stage['children'] as $childKey) {
                $idx = $this->entryIndex[$childKey];
                $bindings[] = $this->bind($idx, '', $taken, $this->columns, null, $stageKey);
                $suiviBindings[] = $this->bind($idx, '', $suiviTaken, $this->suivi['columns'], null, $stageKey);
            }
            $this->stages[$stageKey] = ['statut' => $statut, 'date' => $date, 'bindings' => $bindings];
            $this->suivi['bindings'][$stageKey] = $suiviBindings;
        }

        return $this;
    }

    /**
     * @param  array<string, bool>  $taken
     */
    private function finalizeRepeat(string $group, array &$taken): void
    {
        $def = $this->groups[$group];
        $instances = $def['max'] ?? ($this->repeatScan[$group] ?? 1);
        $instances = max(1, min(self::MAX_REPEAT, $instances));
        if (($this->repeatScan[$group] ?? 0) > self::MAX_REPEAT) {
            $this->warnings[] = sprintf('Groupe répété « %s » : %d instances observées, colonnes limitées à %d (voir la table rep_%s).', $group, $this->repeatScan[$group], self::MAX_REPEAT, $group);
        }

        $perInstance = array_fill(1, $instances, []);
        foreach ($def['children'] as $childKey) {
            $idx = $this->entryIndex[$childKey];
            for ($n = 1; $n <= $instances; $n++) {
                $perInstance[$n][] = $this->bind($idx, '_'.$n, $taken, $this->columns, $n, null);
            }
        }

        $tableTaken = ['id' => true, 'submission_id' => true, 'fiche_code' => true, 'repeat_index' => true];
        $tableColumns = ['submission_id' => 'INTEGER', 'fiche_code' => 'TEXT', 'repeat_index' => 'INTEGER'];
        $tableBindings = [];
        foreach ($def['children'] as $childKey) {
            $tableBindings[] = $this->bind($this->entryIndex[$childKey], '', $tableTaken, $tableColumns, null, null);
        }

        $this->repeats[$group] = [
            'table' => SqlIdentifier::clean('rep_'.$group, prefix: SqlIdentifier::TABLE_PREFIX),
            'columns' => $tableColumns,
            'bindings' => $tableBindings,
            'instances' => $instances,
            'per_instance' => $perInstance,
        ];
    }

    /**
     * Crée la liaison d'une question : rôles => noms de colonnes, et enregistre les colonnes typées.
     *
     * @param  array<string, bool>  $taken
     * @param  array<string, string>  $columns
     * @return array<string, mixed>
     */
    private function bind(int $entryIdx, string $suffix, array &$taken, array &$columns, ?int $repeatIndex, ?string $stage): array
    {
        $entry = $this->entries[$entryIdx];
        $base = $entry['key'].$suffix;
        $cols = [];
        $add = function (string $role, string $raw, string $type) use (&$cols, &$taken, &$columns): void {
            $name = SqlIdentifier::unique(SqlIdentifier::clean($raw), $taken);
            $cols[$role] = $name;
            $columns[$name] = $type;
        };

        switch ($entry['type']) {
            case 'select_one':
                $add('value', $base, 'TEXT');
                $add('lib', $base.'_lib', 'TEXT');
                if ($entry['other_key'] !== null) {
                    $add('other', $base.'_other', 'TEXT');
                }
                break;
            case 'select_multiple':
                $add('value', $base, 'TEXT');
                $add('lib', $base.'_lib', 'TEXT');
                foreach (array_keys($entry['choices'] ?? []) as $code) {
                    $add('choice:'.$code, $base.'__'.$code, 'INTEGER');
                }
                if ($entry['other_key'] !== null) {
                    $add('other', $base.'_other', 'TEXT');
                }
                break;
            case 'rank':
                $add('value', $base, 'TEXT');
                $count = count($entry['choices'] ?? []);
                for ($n = 1; $n <= $count; $n++) {
                    $add('rank:'.$n, $base.'_r'.$n, 'TEXT');
                }
                break;
            case 'text':
                $add('value', $base, 'TEXT');
                if ($entry['codes_key'] !== null) {
                    $add('codes', $base.'_codes', 'TEXT');
                }
                if ($entry['coded']) {
                    $add('themes', $base.'_themes', 'TEXT');
                    $add('sentiment', $base.'_sentiment', 'TEXT');
                }
                break;
            case 'geopoint':
                $add('lat', $base.'_lat', 'REAL');
                $add('lng', $base.'_lng', 'REAL');
                $add('precision', $base.'_precision', 'REAL');
                break;
            default:
                // integer, decimal, currency, date, time, datetime, photo, signature, audio, calculate
                $add('value', $base, $entry['value_type']);
        }

        return ['entry' => $entryIdx, 'cols' => $cols, 'repeat_index' => $repeatIndex, 'stage' => $stage];
    }

    // ------------------------------------------------------------------ contexte des lignes

    /**
     * @param  array<int, string>  $names  id utilisateur => nom
     */
    public function setEnumeratorNames(array $names): self
    {
        $this->enumeratorNames = $names;

        return $this;
    }

    /**
     * @param  array<int, int>  $numbers  id de version => numéro
     */
    public function setVersionNumbers(array $numbers): self
    {
        $this->versionNumbers = $numbers + $this->versionNumbers;

        return $this;
    }

    // ------------------------------------------------------------------ lecture

    /**
     * @return list<array{name: string, type: string}>
     */
    public function columns(): array
    {
        $this->finalize();
        $out = [];
        foreach ($this->columns as $name => $type) {
            $out[] = ['name' => $name, 'type' => $type];
        }

        return $out;
    }

    /**
     * @return array<string, string> nom => type
     */
    public function columnTypes(): array
    {
        $this->finalize();

        return $this->columns;
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_keys($this->columnTypes());
    }

    /**
     * @return array<string, array{table: string, columns: array<string, string>, instances: int}>
     */
    public function repeatTables(): array
    {
        $this->finalize();
        $out = [];
        foreach ($this->repeats as $group => $rep) {
            $out[$group] = ['table' => $rep['table'], 'columns' => $rep['columns'], 'instances' => $rep['instances']];
        }

        return $out;
    }

    /**
     * @return array<string, string> colonnes de la table `suivi` (nom => type)
     */
    public function suiviColumns(): array
    {
        $this->finalize();

        return $this->suivi['columns'];
    }

    /** @return list<string> */
    public function stageKeys(): array
    {
        return array_keys($this->stageDefs);
    }

    public function defaultLanguage(): string
    {
        return $this->defaultLanguage;
    }

    public function primaryVersionId(): ?int
    {
        return $this->primaryVersionId;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Lignes de la table `questions` : question_key, section, etape, groupe, type, libelle, ordre, colonne.
     *
     * @return list<array<string, mixed>>
     */
    public function questionRows(): array
    {
        $this->finalize();
        $columnOf = [];
        foreach ($this->baseBindings as $b) {
            $columnOf[$this->entries[$b['entry']]['key']] = $b['cols']['value'] ?? reset($b['cols']);
        }
        foreach ($this->repeats as $rep) {
            foreach ($rep['per_instance'][1] ?? [] as $b) {
                $columnOf[$this->entries[$b['entry']]['key']] = $b['cols']['value'] ?? reset($b['cols']);
            }
        }
        foreach ($this->stages as $stage) {
            foreach ($stage['bindings'] as $b) {
                $columnOf[$this->entries[$b['entry']]['key']] = $b['cols']['value'] ?? reset($b['cols']);
            }
        }

        $rows = [];
        foreach ($this->entries as $i => $entry) {
            $rows[] = [
                'question_key' => $entry['key'],
                'section' => $entry['section'],
                'etape' => $entry['stage'],
                'groupe' => $entry['group'],
                'type' => $entry['type'],
                'libelle' => $entry['label'],
                'ordre' => $i + 1,
                'colonne' => $columnOf[$entry['key']] ?? null,
                'version' => $entry['version'],
            ];
        }

        return $rows;
    }

    /**
     * Lignes de la table `choix` : question_key, code, libelle, ordre (post-codages sous la clé `{key}__codes`).
     *
     * @return list<array<string, mixed>>
     */
    public function choiceRows(): array
    {
        $rows = [];
        foreach ($this->entries as $entry) {
            $n = 0;
            foreach ($entry['choices'] ?? [] as $code => $label) {
                $rows[] = ['question_key' => $entry['key'], 'code' => (string) $code, 'libelle' => $label, 'ordre' => ++$n];
            }
            if ($entry['codes_key'] !== null) {
                $n = 0;
                foreach ($entry['postcode_choices'] ?? [] as $code => $label) {
                    $rows[] = ['question_key' => $entry['codes_key'], 'code' => (string) $code, 'libelle' => $label, 'ordre' => ++$n];
                }
            }
        }

        return $rows;
    }

    // ------------------------------------------------------------------ production des lignes

    /**
     * Ligne `reponses` d'une soumission (`media`, `followUps`, `codings` doivent être chargés).
     *
     * @return array<string, mixed> nom de colonne => valeur, dans l'ordre des colonnes
     */
    public function rowFor(Submission $submission): array
    {
        $this->finalize();
        $answers = is_array($submission->answers) ? $submission->answers : [];
        $ctx = $this->contextFor($submission);

        $row = [
            'submission_id' => $submission->id,
            'uuid' => $submission->uuid,
            'fiche_code' => $submission->fiche_code,
            'enqueteur_id' => $submission->enumerator_id,
            'enqueteur_nom' => $submission->enumerator_id !== null ? ($this->enumeratorNames[$submission->enumerator_id] ?? null) : null,
            'zone' => $submission->zone,
            'canal' => $submission->channel?->value,
            'statut' => $submission->status?->value,
            'version_formulaire' => $this->versionNumbers[$submission->survey_version_id] ?? null,
            'langue' => $submission->language,
            'debut' => $submission->started_at?->toIso8601String(),
            'fin' => $submission->ended_at?->toIso8601String(),
            'duree_sec' => $submission->duration_seconds,
            'lat' => $submission->geo_lat !== null ? (float) $submission->geo_lat : null,
            'lng' => $submission->geo_lng !== null ? (float) $submission->geo_lng : null,
            'precision_m' => $submission->geo_accuracy !== null ? (float) $submission->geo_accuracy : null,
            'flags' => is_array($submission->flags) && $submission->flags !== [] ? implode(self::SEPARATOR, $submission->flags) : null,
            'score_suspicion' => $submission->suspicion_score,
            'motif_fin' => $submission->end_reason,
            'recu_le' => $submission->received_at?->toIso8601String(),
        ];

        foreach ($this->baseBindings as $binding) {
            $row += $this->values($binding, $answers, $ctx, null);
        }

        foreach ($this->repeats as $group => $rep) {
            $instances = isset($answers[$group]) && is_array($answers[$group]) ? array_values($answers[$group]) : [];
            for ($n = 1; $n <= $rep['instances']; $n++) {
                $instance = isset($instances[$n - 1]) && is_array($instances[$n - 1]) ? $instances[$n - 1] : [];
                foreach ($rep['per_instance'][$n] as $binding) {
                    $row += $this->values($binding, $instance, $ctx, $n - 1);
                }
            }
        }

        $followUps = $this->followUpsByStage($submission);
        foreach ($this->stages as $stageKey => $stage) {
            $entry = $followUps[$stageKey] ?? null;
            $row[$stage['statut']] = $entry?->status?->value;
            $row[$stage['date']] = $entry?->completed_at?->toIso8601String();
            $stageAnswers = $entry !== null && is_array($entry->answers) ? $entry->answers : [];
            foreach ($stage['bindings'] as $binding) {
                $row += $this->values($binding, $stageAnswers, $ctx, null);
            }
        }

        // Ordre strict des colonnes, valeurs manquantes → NULL.
        $ordered = [];
        foreach ($this->columns as $name => $type) {
            $ordered[$name] = $row[$name] ?? null;
        }

        return $ordered;
    }

    /**
     * Lignes des tables `rep_{groupe}` d'une soumission : groupe => liste de lignes (repeat_index 1-based).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function repeatRowsFor(Submission $submission): array
    {
        $this->finalize();
        if ($this->repeats === []) {
            return [];
        }
        $answers = is_array($submission->answers) ? $submission->answers : [];
        $ctx = $this->contextFor($submission);
        $out = [];
        foreach ($this->repeats as $group => $rep) {
            $rows = [];
            $instances = isset($answers[$group]) && is_array($answers[$group]) ? array_values($answers[$group]) : [];
            foreach ($instances as $i => $instance) {
                if (! is_array($instance)) {
                    continue;
                }
                $row = ['submission_id' => $submission->id, 'fiche_code' => $submission->fiche_code, 'repeat_index' => $i + 1];
                foreach ($rep['bindings'] as $binding) {
                    $row += $this->values($binding, $instance, $ctx, $i);
                }
                $ordered = [];
                foreach ($rep['columns'] as $name => $type) {
                    $ordered[$name] = $row[$name] ?? null;
                }
                $rows[] = $ordered;
            }
            $out[$group] = $rows;
        }

        return $out;
    }

    /**
     * Lignes de la table `suivi` d'une soumission (une par entrée de suivi).
     *
     * @return list<array<string, mixed>>
     */
    public function stageRowsFor(Submission $submission): array
    {
        $this->finalize();
        $ctx = $this->contextFor($submission);
        $rows = [];
        foreach ($submission->followUps as $entry) {
            $row = [
                'submission_id' => $submission->id,
                'fiche_code' => $submission->fiche_code,
                'stage_key' => $entry->stage_key,
                'statut' => $entry->status?->value,
                'echeance' => $entry->due_at?->toIso8601String(),
                'fin_fenetre' => $entry->window_ends_at?->toIso8601String(),
                'complete_le' => $entry->completed_at?->toIso8601String(),
                'enqueteur_id' => $entry->enumerator_id,
            ];
            $stageAnswers = is_array($entry->answers) ? $entry->answers : [];
            foreach ($this->suivi['bindings'][$entry->stage_key] ?? [] as $binding) {
                $row += $this->values($binding, $stageAnswers, $ctx, null);
            }
            $ordered = [];
            foreach ($this->suivi['columns'] as $name => $type) {
                $ordered[$name] = $row[$name] ?? null;
            }
            $rows[] = $ordered;
        }

        return $rows;
    }

    /**
     * Lignes `reponses_long` d'une soumission : une par valeur scalaire (un code par ligne pour les
     * listes), compagnons `_other` / `__codes` inclus, réponses des étapes incluses.
     *
     * @return list<array{submission_id: int, fiche_code: ?string, question_key: string, repeat_index: ?int, valeur_texte: ?string, valeur_num: ?float, valeur_code: ?string, langue: string}>
     */
    public function longRowsFor(Submission $submission): array
    {
        $this->finalize();
        $answers = is_array($submission->answers) ? $submission->answers : [];
        $ctx = $this->contextFor($submission);
        $rows = [];

        foreach ($this->baseBindings as $binding) {
            $this->appendLong($rows, $binding, $answers, $ctx, null, $submission);
        }
        foreach ($this->repeats as $group => $rep) {
            $instances = isset($answers[$group]) && is_array($answers[$group]) ? array_values($answers[$group]) : [];
            foreach ($instances as $i => $instance) {
                if (! is_array($instance)) {
                    continue;
                }
                foreach ($rep['bindings'] as $binding) {
                    $this->appendLong($rows, $binding, $instance, $ctx, $i + 1, $submission);
                }
            }
        }
        foreach ($submission->followUps as $entry) {
            $stageAnswers = is_array($entry->answers) ? $entry->answers : [];
            foreach ($this->suivi['bindings'][$entry->stage_key] ?? [] as $binding) {
                $this->appendLong($rows, $binding, $stageAnswers, $ctx, null, $submission);
            }
        }

        return $rows;
    }

    // ------------------------------------------------------------------ interne : valeurs

    /**
     * @return array{media: array<string, string|null>, codings: array<string, array{themes: ?string, sentiment: ?string}>}
     */
    private function contextFor(Submission $submission): array
    {
        $media = [];
        foreach ($submission->media as $m) {
            $media[$m->question_key.'#'.(int) ($m->repeat_index ?? 0)] = $m->path;
        }

        $codings = [];
        $best = [];
        foreach ($submission->codings as $coding) {
            $key = $coding->question_key;
            $rank = (int) ($coding->codebook_id ?? 0);
            if (isset($best[$key]) && $best[$key] > $rank) {
                continue;
            }
            $best[$key] = $rank;
            $themes = is_array($coding->themes) ? array_values(array_filter(array_map(fn ($t) => is_array($t) ? ($t['key'] ?? null) : (is_scalar($t) ? (string) $t : null), $coding->themes))) : [];
            $codings[$key] = ['themes' => $themes === [] ? null : implode(self::SEPARATOR, $themes), 'sentiment' => $coding->sentiment];
        }

        return ['media' => $media, 'codings' => $codings];
    }

    /**
     * @return array<string, FollowUpEntry>
     */
    private function followUpsByStage(Submission $submission): array
    {
        $out = [];
        foreach ($submission->followUps as $entry) {
            $out[$entry->stage_key] = $entry;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $answers
     * @param  array{media: array<string, string|null>, codings: array<string, array{themes: ?string, sentiment: ?string}>}  $ctx
     * @return array<string, mixed>
     */
    private function values(array $binding, array $answers, array $ctx, ?int $mediaRepeatIndex): array
    {
        $entry = $this->entries[$binding['entry']];
        $cols = $binding['cols'];
        $key = $entry['key'];
        $v = $answers[$key] ?? null;
        $out = [];

        switch ($entry['type']) {
            case 'select_one':
                $code = self::scalarText($v);
                $out[$cols['value']] = $code;
                $out[$cols['lib']] = $code === null ? null : ($entry['choices'][$code] ?? $code);
                if (isset($cols['other'])) {
                    $out[$cols['other']] = self::scalarText($answers[$entry['other_key']] ?? null);
                }
                break;

            case 'select_multiple':
                $list = self::codeList($v);
                $out[$cols['value']] = $list === null ? null : implode(self::SEPARATOR, $list);
                $out[$cols['lib']] = $list === null ? null : implode(self::SEPARATOR, array_map(fn ($c) => $entry['choices'][$c] ?? $c, $list));
                foreach ($entry['choices'] ?? [] as $code => $label) {
                    $out[$cols['choice:'.$code]] = $list === null ? null : (in_array((string) $code, $list, true) ? 1 : 0);
                }
                if (isset($cols['other'])) {
                    $out[$cols['other']] = self::scalarText($answers[$entry['other_key']] ?? null);
                }
                break;

            case 'rank':
                $list = self::codeList($v);
                $out[$cols['value']] = $list === null ? null : implode(self::SEPARATOR, $list);
                $count = count($entry['choices'] ?? []);
                for ($n = 1; $n <= $count; $n++) {
                    $out[$cols['rank:'.$n]] = $list[$n - 1] ?? null;
                }
                break;

            case 'text':
                $out[$cols['value']] = self::scalarText($v);
                if (isset($cols['codes'])) {
                    $codes = $answers[$entry['codes_key']] ?? null;
                    $codeList = self::codeList($codes);
                    $out[$cols['codes']] = $codeList === null ? null : implode(self::SEPARATOR, $codeList);
                }
                if (isset($cols['themes'])) {
                    $out[$cols['themes']] = $ctx['codings'][$key]['themes'] ?? null;
                    $out[$cols['sentiment']] = $ctx['codings'][$key]['sentiment'] ?? null;
                }
                break;

            case 'integer':
                $out[$cols['value']] = is_numeric($v) ? (int) $v : (is_bool($v) ? (int) $v : null);
                break;

            case 'decimal':
            case 'currency':
                $out[$cols['value']] = is_numeric($v) ? ($entry['value_type'] === 'INTEGER' ? (int) round((float) $v) : (float) $v) : null;
                break;

            case 'geopoint':
                $out[$cols['lat']] = is_array($v) && is_numeric($v['lat'] ?? null) ? (float) $v['lat'] : null;
                $out[$cols['lng']] = is_array($v) && is_numeric($v['lng'] ?? null) ? (float) $v['lng'] : null;
                $out[$cols['precision']] = is_array($v) && is_numeric($v['accuracy'] ?? null) ? (float) $v['accuracy'] : null;
                break;

            case 'photo':
            case 'signature':
            case 'audio':
                $out[$cols['value']] = $ctx['media'][$key.'#'.(int) ($mediaRepeatIndex ?? 0)] ?? null;
                break;

            case 'calculate':
                $out[$cols['value']] = match ($entry['value_type']) {
                    'INTEGER' => is_bool($v) || is_numeric($v) ? (int) $v : null,
                    'REAL' => is_bool($v) || is_numeric($v) ? (float) $v : null,
                    default => self::scalarText($v),
                };
                break;

            default: // date, time, datetime
                $out[$cols['value']] = self::scalarText($v);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $answers
     * @param  array{media: array<string, string|null>, codings: array<string, array{themes: ?string, sentiment: ?string}>}  $ctx
     */
    private function appendLong(array &$rows, array $binding, array $answers, array $ctx, ?int $repeatIndex, Submission $submission): void
    {
        $entry = $this->entries[$binding['entry']];
        $key = $entry['key'];
        if (! array_key_exists($key, $answers)) {
            return;
        }
        $v = $answers[$key];
        $push = function (string $qkey, ?string $texte, ?float $num, ?string $code) use (&$rows, $repeatIndex, $submission): void {
            $rows[] = [
                'submission_id' => $submission->id,
                'fiche_code' => $submission->fiche_code,
                'question_key' => $qkey,
                'repeat_index' => $repeatIndex,
                'valeur_texte' => $texte,
                'valeur_num' => $num,
                'valeur_code' => $code,
                'langue' => $this->defaultLanguage,
            ];
        };

        switch ($entry['type']) {
            case 'select_one':
                $code = self::scalarText($v);
                if ($code !== null) {
                    $push($key, $entry['choices'][$code] ?? $code, null, $code);
                }
                if ($entry['other_key'] !== null && ($other = self::scalarText($answers[$entry['other_key']] ?? null)) !== null) {
                    $push($entry['other_key'], $other, null, null);
                }
                break;
            case 'select_multiple':
            case 'rank':
                foreach (self::codeList($v) ?? [] as $code) {
                    $push($key, $entry['choices'][$code] ?? $code, null, $code);
                }
                if ($entry['other_key'] !== null && ($other = self::scalarText($answers[$entry['other_key']] ?? null)) !== null) {
                    $push($entry['other_key'], $other, null, null);
                }
                break;
            case 'text':
                if (($text = self::scalarText($v)) !== null) {
                    $push($key, $text, null, null);
                }
                if ($entry['codes_key'] !== null) {
                    foreach (self::codeList($answers[$entry['codes_key']] ?? null) ?? [] as $code) {
                        $push($entry['codes_key'], $entry['postcode_choices'][$code] ?? $code, null, $code);
                    }
                }
                break;
            case 'integer':
            case 'decimal':
            case 'currency':
                if (is_numeric($v)) {
                    $push($key, (string) $v, (float) $v, null);
                }
                break;
            case 'geopoint':
                if (is_array($v) && isset($v['lat'], $v['lng'])) {
                    $push($key, $v['lat'].','.$v['lng'], null, null);
                }
                break;
            case 'photo':
            case 'signature':
            case 'audio':
                $path = $ctx['media'][$key.'#'.(int) (($repeatIndex ?? 1) - 1)] ?? null;
                if ($path !== null || $v !== null) {
                    $push($key, $path ?? (is_array($v) ? ($v['sha256'] ?? null) : null), null, null);
                }
                break;
            case 'calculate':
                if (is_bool($v) || is_numeric($v)) {
                    $push($key, is_bool($v) ? ($v ? '1' : '0') : (string) $v, (float) $v, null);
                } elseif ($v !== null) {
                    $push($key, self::scalarText($v), null, null);
                }
                break;
            default:
                if (($text = self::scalarText($v)) !== null) {
                    $push($key, $text, null, null);
                }
        }
    }

    // ------------------------------------------------------------------ interne : conversions

    /**
     * Valeur scalaire en texte : booléens 0/1, listes/objets en JSON, null conservé.
     */
    public static function scalarText(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                $allScalar = array_reduce($v, fn ($ok, $x) => $ok && (is_scalar($x) || $x === null), true);
                if ($allScalar) {
                    return implode(self::SEPARATOR, array_map(fn ($x) => is_bool($x) ? ($x ? '1' : '0') : (string) $x, $v));
                }
            }

            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        return (string) $v;
    }

    /**
     * @return list<string>|null
     */
    private static function codeList(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        if (is_array($v)) {
            return array_values(array_map(fn ($x) => is_bool($x) ? ($x ? '1' : '0') : (string) $x, array_filter($v, fn ($x) => is_scalar($x))));
        }
        if (is_scalar($v)) {
            return [(string) $v];
        }

        return null;
    }

    /** @return list<string> */
    public static function mediaTypes(): array
    {
        return self::MEDIA_TYPES;
    }
}
