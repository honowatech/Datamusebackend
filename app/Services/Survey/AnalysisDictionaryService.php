<?php

namespace App\Services\Survey;

use App\Enums\SubmissionChannel;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Models\VerbatimCodebook;
use App\Services\Analysis\LevelInference;
use App\Services\Dfs\LabelResolver;
use App\Services\Dfs\QuestionCatalog;
use PDO;
use Throwable;

/**
 * Dictionnaire de variables d'une enquête pour l'analyse native (`GET /surveys/{id}/analysis/dictionary`).
 *
 * Chaque variable associe une question DFS (ou une métadonnée de soumission) à son emplacement dans le
 * SQLite matérialisé (`table`, `column`) et à un niveau de mesure :
 *
 * | Type DFS                         | `level`                                                         |
 * |----------------------------------|-----------------------------------------------------------------|
 * | `select_one`                     | `nominal`, ou `ordinal` si les codes forment une échelle chiffrée |
 * | `select_multiple`, `rank`        | `multiple` (codes séparés par `;` ; ordre = classement pour `rank`) |
 * | `integer`, `decimal`, `currency` | `numeric`                                                       |
 * | `date`, `datetime`, `time`       | `date`                                                          |
 * | `geopoint`                       | `geo` (`columns.lat/lng/precision`)                             |
 * | `text`                           | `text`                                                          |
 * | `calculate`                      | inféré sur les valeurs (`LevelInference`), `text` sans fichier  |
 * | `photo`, `signature`, `audio`, `note`, `stop` | absents du dictionnaire                            |
 *
 * La disposition vient de `ReponsesLayout` (même code que la matérialisation et l'export) puis est
 * confrontée au fichier réel : une variable dont la colonne n'existe pas dans le SQLite est écartée.
 * Les questions `tags: ["pii"]` ne sont listées que si le lecteur a droit à l'export (`$includePii`).
 */
class AnalysisDictionaryService
{
    private const MEDIA_TYPES = ['photo', 'signature', 'audio'];

    /** Libellés des métadonnées de soumission (fr / en). */
    private const META_LABELS = [
        '_zone' => ['fr' => 'Zone', 'en' => 'Zone'],
        '_canal' => ['fr' => 'Canal de collecte', 'en' => 'Collection channel'],
        '_statut' => ['fr' => 'Statut', 'en' => 'Status'],
        '_enqueteur' => ['fr' => 'Enquêteur', 'en' => 'Enumerator'],
        '_langue' => ['fr' => 'Langue', 'en' => 'Language'],
        '_version_formulaire' => ['fr' => 'Version du formulaire', 'en' => 'Form version'],
        '_motif_fin' => ['fr' => 'Motif de fin', 'en' => 'End reason'],
        '_debut' => ['fr' => "Début de l'entretien", 'en' => 'Interview start'],
        '_fin' => ['fr' => "Fin de l'entretien", 'en' => 'Interview end'],
        '_recu_le' => ['fr' => 'Date de réception', 'en' => 'Received at'],
        '_duree_sec' => ['fr' => 'Durée (secondes)', 'en' => 'Duration (seconds)'],
        '_score_suspicion' => ['fr' => 'Score de suspicion', 'en' => 'Suspicion score'],
        '_position' => ['fr' => 'Position GPS de la soumission', 'en' => 'Submission GPS position'],
    ];

    private const CHOICE_LABELS = [
        'mobile' => ['fr' => 'Mobile', 'en' => 'Mobile'],
        'public' => ['fr' => 'Lien public', 'en' => 'Public link'],
        'web' => ['fr' => 'Web', 'en' => 'Web'],
        'submitted' => ['fr' => 'Soumise', 'en' => 'Submitted'],
        'screened_out' => ['fr' => 'Hors cible', 'en' => 'Screened out'],
        'validated' => ['fr' => 'Validée', 'en' => 'Validated'],
        'rejected' => ['fr' => 'Rejetée', 'en' => 'Rejected'],
    ];

    /**
     * @param  string|null  $sqlitePath  fichier matérialisé (null = pas encore matérialisé : disposition prévue, non vérifiée)
     * @return list<array<string, mixed>>
     */
    public function variables(Survey $survey, ?string $lang = null, bool $includePii = false, ?string $sqlitePath = null): array
    {
        $this->columnCache = [];
        $pdo = $sqlitePath !== null ? $this->open($sqlitePath) : null;
        $versions = $this->versions($survey);
        $default = 'fr';
        $question = [];

        if ($versions !== []) {
            $catalogs = array_map(fn (SurveyVersion $v) => QuestionCatalog::fromDefinition($v->definition ?? []), $versions);
            $default = $catalogs[0]->defaultLanguage();
            $codedKeys = VerbatimCodebook::query()->where('survey_id', $survey->id)->distinct()->pluck('question_key')->map(fn ($k) => (string) $k)->all();
            $layout = ReponsesLayout::fromVersions($versions[0], array_slice($versions, 1), $codedKeys)->finalize();

            foreach ($layout->analysisBindings() as $binding) {
                foreach ($this->questionVariables($binding, $catalogs, $lang ?? $default, $default, $includePii, $pdo) as $variable) {
                    $question[] = $variable;
                }
            }
        }

        $variables = array_merge($this->metaVariables($lang ?? $default, $pdo), $question);

        return $pdo === null ? $variables : $this->existing($variables, $pdo);
    }

    // ------------------------------------------------------------------ questions

    /**
     * @param  array{entry: array<string, mixed>, table: string, cols: array<string, string>}  $binding
     * @param  list<QuestionCatalog>  $catalogs  version principale d'abord
     * @return list<array<string, mixed>>
     */
    private function questionVariables(array $binding, array $catalogs, string $lang, string $default, bool $includePii, ?PDO $pdo): array
    {
        $entry = $binding['entry'];
        $key = (string) $entry['key'];
        $type = (string) $entry['type'];
        $def = is_array($entry['def'] ?? null) ? $entry['def'] : [];
        $cols = $binding['cols'];
        $table = $binding['table'];

        if (in_array($type, self::MEDIA_TYPES, true) || (! $includePii && ReponsesLayout::isPii($def))) {
            return [];
        }

        $label = LabelResolver::resolve($def['label'] ?? $key, $lang, $default, $key);
        $make = fn (string $name, string $varLabel, string $level, string $dfsType, string $column, array $choices = []) => [
            'name' => $name,
            'label' => $varLabel,
            'level' => $level,
            'dfs_type' => $dfsType,
            'table' => $table,
            'column' => $column,
            'choices' => $choices,
            'multiple' => $level === 'multiple',
        ];

        $out = [];
        switch ($type) {
            case 'select_one':
            case 'select_multiple':
            case 'rank':
                $choices = $this->choices(array_keys($entry['choices'] ?? []), $catalogs, fn (QuestionCatalog $c) => $c->choicesFor($key), $lang, $default);
                $level = $type === 'select_one' ? ($this->isScale($choices) ? 'ordinal' : 'nominal') : 'multiple';
                $out[] = $make($key, $label, $level, $type, $cols['value'], $choices);
                if (isset($cols['other'], $entry['other_key'])) {
                    $otherLabel = is_array($def['other'] ?? null) && isset($def['other']['label'])
                        ? LabelResolver::resolve($def['other']['label'], $lang, $default, (string) $entry['other_key'])
                        : (string) $entry['other_key'];
                    $out[] = $make((string) $entry['other_key'], $label.' — '.$otherLabel, 'text', 'text', $cols['other']);
                }
                break;

            case 'text':
                $out[] = $make($key, $label, 'text', 'text', $cols['value']);
                if (isset($cols['codes'], $entry['codes_key'])) {
                    $multiple = (bool) ($def['postcode']['multiple'] ?? false);
                    $choices = $this->choices(array_keys($entry['postcode_choices'] ?? []), $catalogs, fn (QuestionCatalog $c) => $c->postcodeChoicesFor($key), $lang, $default);
                    $codesLabel = isset($def['postcode']['label'])
                        ? LabelResolver::resolve($def['postcode']['label'], $lang, $default, (string) $entry['codes_key'])
                        : (string) $entry['codes_key'];
                    $out[] = $make((string) $entry['codes_key'], $label.' — '.$codesLabel, $multiple ? 'multiple' : 'nominal', $multiple ? 'select_multiple' : 'select_one', $cols['codes'], $choices);
                }
                if (isset($cols['themes'], $cols['sentiment'])) {
                    $out[] = $make($key.'_themes', $label.' — '.($lang === 'en' ? 'themes' : 'thèmes'), 'multiple', 'calculate', $cols['themes']);
                    $out[] = $make($key.'_sentiment', $label.' — sentiment', 'nominal', 'calculate', $cols['sentiment']);
                }
                break;

            case 'integer':
            case 'decimal':
            case 'currency':
                $out[] = $make($key, $label, 'numeric', $type, $cols['value']);
                break;

            case 'date':
            case 'datetime':
            case 'time':
                $out[] = $make($key, $label, 'date', $type, $cols['value']);
                break;

            case 'geopoint':
                $out[] = $make($key, $label, 'geo', $type, $cols['lat'])
                    + ['columns' => ['lat' => $cols['lat'], 'lng' => $cols['lng'], 'precision' => $cols['precision']]];
                break;

            case 'calculate':
                $level = 'text';
                $choices = [];
                if ($pdo !== null && $this->hasColumn($pdo, $table, $cols['value'])) {
                    $profile = LevelInference::infer($pdo, $table, $cols['value']);
                    $level = $profile['level'];
                    $choices = array_map(fn (string $v) => ['code' => $v, 'label' => $v], $profile['values']);
                }
                $out[] = $make($key, $label, $level, $type, $cols['value'], $choices);
                break;

            default:
                // Type inconnu de cette version du backend : exposé tel quel, en texte.
                if (isset($cols['value'])) {
                    $out[] = $make($key, $label, 'text', $type, $cols['value']);
                }
        }

        return $out;
    }

    /**
     * Choix (ordre de la disposition fusionnée) avec libellés dans la langue demandée.
     *
     * @param  list<int|string>  $codes
     * @param  list<QuestionCatalog>  $catalogs
     * @param  callable(QuestionCatalog): (array<int, array<string, mixed>>|null)  $resolver
     * @return list<array{code: string, label: string}>
     */
    private function choices(array $codes, array $catalogs, callable $resolver, string $lang, string $default): array
    {
        $labels = [];
        foreach ($catalogs as $catalog) {
            foreach ($resolver($catalog) ?? [] as $choice) {
                if (! is_array($choice) || ! isset($choice['name']) || isset($labels[(string) $choice['name']])) {
                    continue;
                }
                $code = (string) $choice['name'];
                $labels[$code] = LabelResolver::resolve($choice['label'] ?? $code, $lang, $default, $code);
            }
        }

        return array_map(fn ($code) => ['code' => (string) $code, 'label' => $labels[(string) $code] ?? (string) $code], $codes);
    }

    /**
     * Échelle chiffrée : au moins 3 choix dont les codes sont tous numériques et strictement monotones
     * dans l'ordre du questionnaire (1…5, 0…10, 5…1). Le schéma DFS ne déclare pas l'ordinalité : c'est
     * une heuristique, que l'interface doit laisser corriger.
     *
     * @param  list<array{code: string, label: string}>  $choices
     */
    private function isScale(array $choices): bool
    {
        if (count($choices) < 3) {
            return false;
        }
        $direction = 0;
        $previous = null;
        foreach ($choices as $choice) {
            if (! is_numeric($choice['code'])) {
                return false;
            }
            $value = (float) $choice['code'];
            if ($previous !== null) {
                $step = $value <=> $previous;
                if ($step === 0 || ($direction !== 0 && $step !== $direction)) {
                    return false;
                }
                $direction = $step;
            }
            $previous = $value;
        }

        return true;
    }

    // ------------------------------------------------------------------ métadonnées de soumission

    /**
     * @return list<array<string, mixed>>
     */
    private function metaVariables(string $lang, ?PDO $pdo): array
    {
        $label = fn (string $name) => self::META_LABELS[$name][$lang] ?? self::META_LABELS[$name]['fr'];
        $enum = fn (array $values) => array_map(fn (string $v) => ['code' => $v, 'label' => self::CHOICE_LABELS[$v][$lang] ?? self::CHOICE_LABELS[$v]['fr'] ?? $v], $values);
        $make = fn (string $name, string $level, string $column, array $choices = []) => [
            'name' => $name,
            'label' => $label($name),
            'level' => $level,
            'dfs_type' => 'meta',
            'table' => 'reponses',
            'column' => $column,
            'choices' => $choices,
            'multiple' => false,
        ];

        return [
            $make('_zone', 'nominal', 'zone'),
            $make('_canal', 'nominal', 'canal', $enum(SubmissionChannel::values())),
            $make('_statut', 'nominal', 'statut', $enum(SubmissionStatus::values())),
            $make('_enqueteur', 'nominal', 'enqueteur_id', $this->enumeratorChoices($pdo)),
            $make('_langue', 'nominal', 'langue'),
            $make('_version_formulaire', 'nominal', 'version_formulaire'),
            $make('_motif_fin', 'nominal', 'motif_fin'),
            $make('_debut', 'date', 'debut'),
            $make('_fin', 'date', 'fin'),
            $make('_recu_le', 'date', 'recu_le'),
            $make('_duree_sec', 'numeric', 'duree_sec'),
            $make('_score_suspicion', 'numeric', 'score_suspicion'),
            $make('_position', 'geo', 'lat') + ['columns' => ['lat' => 'lat', 'lng' => 'lng', 'precision' => 'precision_m']],
        ];
    }

    /**
     * Enquêteurs présents dans le fichier (`enqueteurs.id` → nom) : mêmes informations que les colonnes
     * `enqueteur_id` / `enqueteur_nom` de l'export.
     *
     * @return list<array{code: string, label: string}>
     */
    private function enumeratorChoices(?PDO $pdo): array
    {
        if ($pdo === null || ! $this->hasColumn($pdo, 'enqueteurs', 'nom')) {
            return [];
        }
        $rows = $pdo->query('SELECT "id", "nom" FROM "enqueteurs" ORDER BY "id"')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn ($r) => ['code' => (string) $r['id'], 'label' => (string) ($r['nom'] ?? $r['id'])], $rows);
    }

    // ------------------------------------------------------------------ versions et fichier

    /**
     * Version principale (publiée, sinon la plus récente) puis autres versions ayant des soumissions non
     * rejetées — la même fusion que `SurveyMaterializationService::layoutFor()`, sans relire les réponses.
     *
     * @return list<SurveyVersion>
     */
    private function versions(Survey $survey): array
    {
        $primary = $survey->publishedVersion ?? $survey->versions()->reorder()->orderByDesc('version')->first();
        if ($primary === null) {
            return [];
        }

        $ids = Submission::query()->where('survey_id', $survey->id)
            ->where('status', '!=', SubmissionStatus::Rejected->value)
            ->distinct()->pluck('survey_version_id')->filter()
            ->reject(fn ($id) => (int) $id === $primary->id)->values();
        $others = $ids->isEmpty() ? [] : SurveyVersion::query()->whereIn('id', $ids)->orderBy('version')->get()->all();

        return array_merge([$primary], $others);
    }

    /**
     * Ne garde que les variables dont toutes les colonnes existent dans le fichier.
     *
     * @param  list<array<string, mixed>>  $variables
     * @return list<array<string, mixed>>
     */
    private function existing(array $variables, PDO $pdo): array
    {
        return array_values(array_filter($variables, function (array $variable) use ($pdo) {
            $columns = array_merge([$variable['column']], array_values(array_slice($variable['columns'] ?? [], 0, 2)));
            foreach ($columns as $column) {
                if (! $this->hasColumn($pdo, $variable['table'], (string) $column)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @var array<string, array<string, true>> */
    private array $columnCache = [];

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $cacheKey = spl_object_id($pdo).':'.$table;
        if (! isset($this->columnCache[$cacheKey])) {
            $rows = $pdo->query('PRAGMA table_info('.LevelInference::quote($table).')')->fetchAll(PDO::FETCH_ASSOC);
            $this->columnCache[$cacheKey] = array_fill_keys(array_map(fn ($r) => (string) $r['name'], $rows), true);
        }

        return isset($this->columnCache[$cacheKey][$column]);
    }

    private function open(string $path): ?PDO
    {
        try {
            return is_file($path) ? new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
