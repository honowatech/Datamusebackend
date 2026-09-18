<?php

namespace App\Services\Dfs;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use stdClass;

/**
 * Validateur d'une définition DFS v1 : forme (JSON Schema 2020-12 `docs/dfs/dfs-v1.schema.json` via
 * opis/json-schema) puis sens (règles sémantiques du README hors schéma).
 *
 * Chemin du schéma : argument du constructeur → `config('dfs.schema_path')` (si l'application Laravel
 * est chargée) → `<backend>/../docs/dfs/dfs-v1.schema.json`.
 *
 * Codes d'erreur (severity `error`) :
 *   - `invalid_json`                 texte JSON illisible ou racine non objet
 *   - `unsupported_version`          `dfs_version` d'une version majeure ≠ 1
 *   - `schema`                       violation du JSON Schema (message opis, `path` = donnée fautive)
 *   - `default_language_not_listed`  `settings.default_language` absent de `settings.languages`
 *   - `missing_default_language`     un `I18n` sans la clé `default_language` (§ 9)
 *   - `duplicate_key`                clé partagée par deux sections / groupes / questions / étapes / compagnons (§ 1)
 *   - `companion_collision`          clé de question égale à un compagnon `{key}_other` / `{key}__codes` (§ 1)
 *   - `unknown_var`                  `{"var": "K"}` vers une clé inexistante (§ 16.3)
 *   - `unknown_choice_list`          `choices` (question ou `postcode`) vers une liste absente de `choice_lists`
 *   - `duplicate_choice_name`        deux choix de même `name` dans une liste (§ 1)
 *   - `other_choice_missing`         `other.choice` absent de la liste de la question (§ 4)
 *   - `exclusive_choice_missing`     un code de `exclusive` absent de la liste (§ 3.2)
 *   - `choice_filter_unknown_key`    `Choice.filter` vers une question inconnue (§ 2.3)
 *   - `fiche_source_unknown`         `fiche_code.sources` vers une question inconnue (§ 11)
 *   - `fiche_source_type`            source de code fiche qui n'est ni select_one, ni text, ni calculate
 *   - `fiche_token_unknown`          jeton `{KEY}` du `pattern` sans entrée dans `sources`
 *   - `unknown_operator`             opérateur Logic hors liste (§ 16.3)
 *   - `bad_arity`                    arité invalide, ou `var` sans chaîne (§ 16.3)
 *   - `bad_expr`                     nœud d'expression sans opérateur unique
 *   - `stop_in_repeat`               `stop` dans un groupe répété (§ 7)
 *   - `stop_in_stage`                `stop` dans une étape de suivi (§ 8)
 *   - `circular_calculate`           `calculate` qui se référence lui-même (§ 16.7)
 *   - `unknown_interpolation`        `${cle}` vers une clé inconnue (§ 10)
 *   - `unknown_question_ref`         `duplicate_keys`, `followup_contact_keys` ou `quota.question` vers une clé inconnue
 *   - `quota_question_required`      quota `answer` / `distinct` sans `question` (§ 12)
 *
 * Codes d'avertissement (severity `warning`) :
 *   - `missing_translation`          un `I18n` sans l'une des langues de `settings.languages` (§ 9)
 *   - `forward_reference`            expression référençant une question postérieure dans le document (§ 16.7)
 *   - `unknown_system_var`           variable `_x` hors des neuf variables système (vaut null, § 16.5)
 *   - `fiche_source_unused`          entrée de `sources` sans jeton dans le `pattern`
 *   - `matrix_mixed_choices`         groupe `matrix` dont les questions ne partagent pas la même liste (§ 2.4)
 */
final class DfsValidator
{
    public const SCHEMA_ID = 'https://datamuse.local/schemas/dfs-v1.schema.json';

    private const EXPR_FIELDS = ['relevant', 'required', 'read_only', 'constraint', 'default', 'expression'];

    private const I18N_FIELDS = ['label', 'hint', 'description', 'required_message', 'constraint_message', 'message'];

    public const QUESTION_TYPES = ['select_one', 'select_multiple', 'rank', 'text', 'integer', 'decimal', 'currency', 'date', 'time', 'datetime', 'geopoint', 'photo', 'signature', 'audio', 'note', 'calculate', 'stop', 'group'];

    private string $schemaPath;

    private int $maxSchemaErrors;

    /** @var array<int, Validator> validateurs opis par nombre maximal d'erreurs */
    private array $validators = [];

    /** @var array<int, array{path: string, code: string, message: string, severity: string}> */
    private array $errors = [];

    /** @var array<int, array{path: string, code: string, message: string, severity: string}> */
    private array $warnings = [];

    /**
     * @param  string|null  $schemaPath  chemin du JSON Schema (défaut : configuration puis `docs/dfs/`)
     * @param  int  $maxSchemaErrors  nombre maximal d'erreurs JSON Schema rapportées. La première passe
     *                                s'arrête à la première erreur (rapide) ; une définition invalide est
     *                                revalidée avec cette limite pour rapporter plusieurs erreurs (plus lent).
     */
    public function __construct(?string $schemaPath = null, int $maxSchemaErrors = 10)
    {
        $this->schemaPath = $schemaPath ?? self::defaultSchemaPath();
        $this->maxSchemaErrors = max(1, $maxSchemaErrors);
    }

    public static function defaultSchemaPath(): string
    {
        if (function_exists('app') && function_exists('config')) {
            try {
                $app = app();
                if ($app->bound('config')) {
                    $configured = config('dfs.schema_path');
                    if (is_string($configured) && $configured !== '') {
                        return $configured;
                    }
                }
            } catch (\Throwable) {
                // Hors application Laravel : repli sur le chemin relatif.
            }
        }

        return dirname(__DIR__, 3).'/../docs/dfs/dfs-v1.schema.json';
    }

    public function schemaPath(): string
    {
        return $this->schemaPath;
    }

    /**
     * @param  array<string, mixed>|stdClass|string  $definition  définition DFS (tableau, objet ou texte JSON)
     */
    public function validate(array|stdClass|string $definition): ValidationResult
    {
        $this->errors = [];
        $this->warnings = [];

        $object = $this->toObject($definition);
        if ($object === null) {
            return $this->result();
        }

        $version = $object->dfs_version ?? null;
        if (! is_string($version) || preg_match('/^1(\.\d+)?$/', $version) !== 1) {
            $this->error('/dfs_version', 'unsupported_version', 'Version DFS non prise en charge : '.json_encode($version).' (attendu 1.x).');
        }

        $this->validateSchema($object);

        $def = DfsDefaults::apply($object);
        if (is_array($def['sections'] ?? null) && is_array($def['settings'] ?? null)) {
            $this->validateSemantics($def);
        }

        return $this->result();
    }

    // ---------------------------------------------------------------------------
    // JSON Schema
    // ---------------------------------------------------------------------------

    private function validateSchema(stdClass $object): void
    {
        $validator = $this->validator(1);
        if ($validator === null) {
            return;
        }
        $result = $validator->validate($object, self::SCHEMA_ID);
        if ($result->isValid() || $result->error() === null) {
            return;
        }
        if ($this->maxSchemaErrors > 1) {
            $detailed = $this->validator($this->maxSchemaErrors)?->validate($object, self::SCHEMA_ID);
            if ($detailed !== null && $detailed->error() !== null) {
                $result = $detailed;
            }
        }
        $leaves = [];
        $this->flattenSchemaError($result->error(), $leaves);
        $seen = [];
        foreach ($leaves as $leaf) {
            $sig = $leaf['path'].'|'.$leaf['message'];
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $this->error($leaf['path'], 'schema', $leaf['message']);
        }
    }

    private function validator(int $maxErrors): ?Validator
    {
        if (isset($this->validators[$maxErrors])) {
            return $this->validators[$maxErrors];
        }
        if (! is_file($this->schemaPath)) {
            $this->error('/', 'schema', "Schéma DFS introuvable : {$this->schemaPath}");

            return null;
        }
        $validator = new Validator;
        $validator->setMaxErrors($maxErrors);
        $validator->resolver()?->registerFile(self::SCHEMA_ID, $this->schemaPath);

        return $this->validators[$maxErrors] = $validator;
    }

    /**
     * Aplatit l'arbre d'erreurs opis en feuilles `{path, keyword, message}`. Pour `oneOf` / `anyOf`, seule
     * la branche « plausible » est conservée : celle qui ne rejette pas le discriminant `type` (questions)
     * ni la structure du nœud (`required`, `additionalProperties`, `type` : opérateurs Logic).
     *
     * @param  array<int, array{path: string, keyword: string, message: string}>  $out
     */
    private function flattenSchemaError(ValidationError $error, array &$out): void
    {
        $subs = $error->subErrors();
        $keyword = $error->keyword();
        $path = self::pointer($error->data()->fullPath());
        if ($subs === []) {
            $out[] = ['path' => $path, 'keyword' => $keyword, 'message' => $this->schemaMessage($error)];

            return;
        }
        if ($keyword === 'oneOf' || $keyword === 'anyOf') {
            $branches = [];
            foreach ($subs as $sub) {
                $leaves = [];
                $this->flattenSchemaError($sub, $leaves);
                $branches[] = $leaves;
            }
            $data = $error->data()->value();
            $type = $data instanceof stdClass && isset($data->type) && is_string($data->type) ? $data->type : null;
            if ($type !== null) {
                $typePath = $path.'/type';
                if (! in_array($type, self::QUESTION_TYPES, true)) {
                    $out[] = ['path' => $typePath, 'keyword' => 'oneOf', 'message' => "Type inconnu : {$type}"];

                    return;
                }
                foreach ($branches as $leaves) {
                    $rejectsType = false;
                    foreach ($leaves as $leaf) {
                        if ($leaf['path'] === $typePath && in_array($leaf['keyword'], ['const', 'enum'], true)) {
                            $rejectsType = true;
                            break;
                        }
                    }
                    if (! $rejectsType) {
                        array_push($out, ...$leaves);

                        return;
                    }
                }
                $out[] = ['path' => $typePath, 'keyword' => 'oneOf', 'message' => "Type inconnu : {$type}"];

                return;
            }
            foreach ($branches as $leaves) {
                $rejectsShape = false;
                foreach ($leaves as $leaf) {
                    if ($leaf['path'] === $path && in_array($leaf['keyword'], ['required', 'additionalProperties', 'type', 'const', 'enum', 'oneOf', 'anyOf'], true)) {
                        $rejectsShape = true;
                        break;
                    }
                }
                if (! $rejectsShape && $leaves !== []) {
                    array_push($out, ...$leaves);

                    return;
                }
            }
            $out[] = ['path' => $path, 'keyword' => $keyword, 'message' => 'Aucune des formes admises ne correspond ('.$this->describe($data).').'];

            return;
        }
        foreach ($subs as $sub) {
            $this->flattenSchemaError($sub, $out);
        }
    }

    private function schemaMessage(ValidationError $error): string
    {
        $message = (new ErrorFormatter)->formatErrorMessage($error);

        return $error->keyword().' : '.$message;
    }

    private function describe(mixed $data): string
    {
        if ($data instanceof stdClass) {
            return 'objet {'.implode(', ', array_keys((array) $data)).'}';
        }
        if (is_array($data)) {
            return 'liste de '.count($data).' élément(s)';
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: gettype($data);
    }

    // ---------------------------------------------------------------------------
    // Règles sémantiques
    // ---------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $def  définition (tableaux, défauts appliqués)
     */
    private function validateSemantics(array $def): void
    {
        $settings = $def['settings'];
        $languages = is_array($settings['languages'] ?? null) ? array_values(array_filter($settings['languages'], 'is_string')) : [];
        $defaultLanguage = is_string($settings['default_language'] ?? null) ? $settings['default_language'] : null;
        if ($defaultLanguage !== null && $languages !== [] && ! in_array($defaultLanguage, $languages, true)) {
            $this->error('/settings/default_language', 'default_language_not_listed', "La langue par défaut « {$defaultLanguage} » n'est pas dans settings.languages.");
        }

        // 1. Inventaire des nœuds (ordre du document) et espace de noms unique.
        $nodes = $this->collectNodes($def);
        $index = [];   // clé → position
        $kinds = [];   // clé → kind
        $companions = []; // clé compagnon → chemin de l'hôte
        foreach ($nodes as $pos => $node) {
            $key = $node['key'];
            if (isset($index[$key])) {
                $this->error($node['path'].'/key', 'duplicate_key', "Clé « {$key} » déjà utilisée ({$nodes[$index[$key]]['path']}).");

                continue;
            }
            $index[$key] = $pos;
            $kinds[$key] = $node['kind'];
        }
        foreach ($nodes as $node) {
            foreach ($node['companions'] as $companionKey => $companionPath) {
                if (isset($index[$companionKey])) {
                    $this->error($companionPath, 'companion_collision', "La clé compagnon « {$companionKey} » entre en collision avec la clé de « {$nodes[$index[$companionKey]]['path']} ».");
                } elseif (isset($companions[$companionKey])) {
                    $this->error($companionPath, 'duplicate_key', "Clé compagnon « {$companionKey} » déjà utilisée ({$companions[$companionKey]}).");
                } else {
                    $companions[$companionKey] = $companionPath;
                }
            }
        }
        $known = $index + array_fill_keys(array_keys($companions), PHP_INT_MAX);

        // 2. Listes de choix.
        $choiceLists = is_array($def['choice_lists']) ? $def['choice_lists'] : [];
        foreach ($choiceLists as $name => $list) {
            $this->checkChoices(is_array($list) ? $list : [], '/choice_lists/'.self::escape((string) $name), $known, $languages, $defaultLanguage);
        }

        // 3. Textes de la racine et des paramètres.
        $this->checkI18n($def, '', ['title', 'description'], $languages, $defaultLanguage, $known);
        foreach (['quotas', 'kpis'] as $list) {
            foreach ($settings[$list] ?? [] as $i => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $p = "/settings/{$list}/{$i}";
                $this->checkI18n($item, $p, ['label'], $languages, $defaultLanguage, $known);
                foreach ($list === 'quotas' ? ['filter'] : ['numerator', 'denominator'] as $field) {
                    if (array_key_exists($field, $item) && ! is_string($item[$field])) {
                        $this->checkExpr($item[$field], $p.'/'.$field, $known, PHP_INT_MAX, null);
                    }
                }
                if ($list === 'quotas') {
                    $scope = $item['scope'] ?? 'survey';
                    if (isset($item['question'])) {
                        if (! isset($known[$item['question']])) {
                            $this->error($p.'/question', 'unknown_question_ref', "Question inconnue : {$item['question']}.");
                        }
                    } elseif (in_array($scope, ['answer', 'distinct'], true)) {
                        $this->error($p.'/scope', 'quota_question_required', "Un quota `{$scope}` exige `question`.");
                    }
                }
            }
        }
        foreach (['duplicate_keys', 'followup_contact_keys'] as $field) {
            foreach ($settings[$field] ?? [] as $i => $key) {
                if (! is_string($key) || ! isset($known[$key])) {
                    $this->error("/settings/{$field}/{$i}", 'unknown_question_ref', 'Question inconnue : '.json_encode($key).'.');
                }
            }
        }

        // 4. Code fiche.
        if (isset($settings['fiche_code']) && is_array($settings['fiche_code'])) {
            $this->checkFicheCode($settings['fiche_code'], $nodes, $index);
        }

        // 4 bis. Lien public + question `pii` obligatoire (B-13).
        $this->checkPiiOnPublicLink($settings, $nodes);

        // 5. Sections, groupes, questions, étapes.
        foreach ($nodes as $pos => $node) {
            $p = $node['path'];
            $item = $node['def'];
            $this->checkI18n($item, $p, self::I18N_FIELDS, $languages, $defaultLanguage, $known);
            foreach (self::EXPR_FIELDS as $field) {
                if (! array_key_exists($field, $item) || is_bool($item[$field]) && $field !== 'expression') {
                    continue;
                }
                $self = $node['kind'] === 'question' && ($item['type'] ?? null) === 'calculate' && $field === 'expression' ? $node['key'] : null;
                $this->checkExpr($item[$field], $p.'/'.$field, $known, $pos, $self);
            }
            if ($node['kind'] !== 'question') {
                if ($node['kind'] === 'group' && ($item['appearance'] ?? 'list') === 'matrix') {
                    $lists = [];
                    foreach ($item['items'] ?? [] as $child) {
                        if (is_array($child)) {
                            $lists[] = json_encode($child['choices'] ?? null);
                        }
                    }
                    if (count(array_unique($lists)) > 1) {
                        $this->warning($p.'/items', 'matrix_mixed_choices', 'Les questions d\'un groupe `matrix` devraient partager la même liste de choix.');
                    }
                }

                continue;
            }
            $type = $item['type'] ?? null;
            if ($type === 'stop') {
                if ($node['repeat']) {
                    $this->error($p, 'stop_in_repeat', 'Un `stop` ne peut pas figurer dans un groupe répété.');
                }
                if ($node['stage'] !== null) {
                    $this->error($p, 'stop_in_stage', 'Un `stop` est interdit dans une étape de suivi.');
                }
            }
            if (in_array($type, ['select_one', 'select_multiple', 'rank'], true)) {
                $choices = $this->resolveChoices($item['choices'] ?? null, $choiceLists, $p.'/choices', $known, $languages, $defaultLanguage);
                $names = $choices === null ? null : array_map(static fn ($c) => (string) (((array) $c)['name'] ?? ''), $choices);
                if (isset($item['other']) && is_array($item['other'])) {
                    $this->checkI18n($item['other'], $p.'/other', ['label'], $languages, $defaultLanguage, $known);
                    if ($names !== null && ! in_array((string) ($item['other']['choice'] ?? ''), $names, true)) {
                        $this->error($p.'/other/choice', 'other_choice_missing', 'Le choix « '.($item['other']['choice'] ?? '').' » n\'existe pas dans la liste.');
                    }
                }
                if ($names !== null && is_array($item['exclusive'] ?? null)) {
                    foreach ($item['exclusive'] as $i => $code) {
                        if (! in_array((string) $code, $names, true)) {
                            $this->error("{$p}/exclusive/{$i}", 'exclusive_choice_missing', "Le choix exclusif « {$code} » n'existe pas dans la liste.");
                        }
                    }
                }
            }
            if (isset($item['postcode']) && is_array($item['postcode'])) {
                $this->checkI18n($item['postcode'], $p.'/postcode', ['label'], $languages, $defaultLanguage, $known);
                $this->resolveChoices($item['postcode']['choices'] ?? null, $choiceLists, $p.'/postcode/choices', $known, $languages, $defaultLanguage);
            }
        }
    }

    /**
     * Nœuds du document (sections, groupes, questions, étapes) dans l'ordre, avec chemin et compagnons.
     *
     * @param  array<string, mixed>  $def
     * @return array<int, array{kind: string, key: string, path: string, def: array<string, mixed>, stage: string|null, repeat: bool, companions: array<string, string>}>
     */
    /**
     * B-13 — `settings.allow_public_link` vrai **et** une question `tags: ["pii"]` obligatoire.
     *
     * La définition servie au lien public retire les questions `pii` (`PublicDefinitionFilter`, B-12) mais
     * le moteur valide toujours la version **publiée** : si une telle question est obligatoire et devient
     * pertinente, toute soumission publique est rejetée. Avertissement (et non erreur) car la question
     * peut rester hors du parcours réellement emprunté (`relevant` jamais vrai côté public).
     *
     * @param  array<string, mixed>  $settings
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function checkPiiOnPublicLink(array $settings, array $nodes): void
    {
        if (($settings['allow_public_link'] ?? false) !== true) {
            return;
        }

        foreach ($nodes as $node) {
            // Les étapes de suivi ne sont jamais servies au canal public.
            if ($node['kind'] !== 'question' || $node['stage'] !== null) {
                continue;
            }
            $item = is_array($node['def']) ? $node['def'] : [];
            $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
            if (! in_array('pii', $tags, true) || ($item['required'] ?? null) !== true) {
                continue;
            }

            $this->warning(
                $node['path'].'/required',
                'pii_required_public',
                "La question « {$node['key']} » est marquée `pii` et obligatoire alors que le lien public est activé : "
                    .'elle est retirée de la définition publique, donc toute réponse publique où elle serait pertinente '
                    .'serait rejetée. Rendez-la facultative, conditionnez sa pertinence au canal, ou désactivez '
                    .'`settings.allow_public_link`.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $def
     * @return array<int, array<string, mixed>>
     */
    private function collectNodes(array $def): array
    {
        $nodes = [];
        $add = function (string $kind, array $item, string $path, ?string $stage, bool $repeat) use (&$nodes): void {
            if (! isset($item['key']) || ! is_string($item['key'])) {
                return;
            }
            $companions = [];
            if ($kind === 'question') {
                $type = $item['type'] ?? null;
                if (isset($item['other']) && is_array($item['other']) && in_array($type, ['select_one', 'select_multiple'], true)) {
                    $k = isset($item['other']['key']) && is_string($item['other']['key']) ? $item['other']['key'] : $item['key'].'_other';
                    $companions[$k] = isset($item['other']['key']) ? $path.'/other/key' : $path.'/other';
                }
                if (isset($item['postcode']) && is_array($item['postcode']) && $type === 'text') {
                    $k = isset($item['postcode']['key']) && is_string($item['postcode']['key']) ? $item['postcode']['key'] : $item['key'].'__codes';
                    $companions[$k] = isset($item['postcode']['key']) ? $path.'/postcode/key' : $path.'/postcode';
                }
            }
            $nodes[] = ['kind' => $kind, 'key' => $item['key'], 'path' => $path, 'def' => $item, 'stage' => $stage, 'repeat' => $repeat, 'companions' => $companions];
        };
        foreach ($def['sections'] ?? [] as $si => $section) {
            if (! is_array($section)) {
                continue;
            }
            $sp = "/sections/{$si}";
            $add('section', $section, $sp, null, false);
            foreach ($section['items'] ?? [] as $ii => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $ip = "{$sp}/items/{$ii}";
                if (($item['type'] ?? null) === 'group') {
                    $repeat = isset($item['repeat']) && is_array($item['repeat']);
                    $add('group', $item, $ip, null, $repeat);
                    foreach ($item['items'] ?? [] as $ci => $child) {
                        if (is_array($child)) {
                            $add('question', $child, "{$ip}/items/{$ci}", null, $repeat);
                        }
                    }
                } else {
                    $add('question', $item, $ip, null, false);
                }
            }
        }
        foreach ($def['follow_up_stages'] ?? [] as $si => $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $sp = "/follow_up_stages/{$si}";
            $stageKey = is_string($stage['key'] ?? null) ? $stage['key'] : null;
            $add('stage', $stage, $sp, $stageKey, false);
            foreach ($stage['items'] ?? [] as $ii => $item) {
                if (is_array($item)) {
                    $add('question', $item, "{$sp}/items/{$ii}", $stageKey, false);
                }
            }
        }

        return $nodes;
    }

    /**
     * Vérifie une expression : opérateurs, arités, références (`unknown_var`, `forward_reference`,
     * `unknown_system_var`, `circular_calculate`).
     *
     * @param  array<string, int>  $known  clé → position dans le document
     */
    private function checkExpr(mixed $expr, string $path, array $known, int $position, ?string $selfKey): void
    {
        $this->walkExpr($expr, $path, $known, $position, $selfKey, 0);
    }

    /**
     * @param  array<string, int>  $known
     */
    private function walkExpr(mixed $node, string $path, array $known, int $position, ?string $selfKey, int $depth): void
    {
        if ($node instanceof stdClass) {
            $node = (array) $node;
        }
        if (! is_array($node)) {
            return;
        }
        if ($depth > 64) {
            $this->error($path, 'bad_expr', 'Expression trop profonde.');

            return;
        }
        if (array_is_list($node)) {
            foreach ($node as $i => $e) {
                $this->walkExpr($e, "{$path}/{$i}", $known, $position, $selfKey, $depth + 1);
            }

            return;
        }
        if (count($node) !== 1) {
            $this->error($path, 'bad_expr', 'Un nœud opérateur a exactement une clé.');

            return;
        }
        $op = (string) array_key_first($node);
        $args = $node[$op];
        $p = $path.'/'.self::escape($op);
        if ($op === 'var') {
            if (! is_string($args)) {
                $this->error($p, 'bad_arity', '`var` attend une chaîne.');

                return;
            }
            $head = explode('.', $args)[0];
            if (str_starts_with($head, '_')) {
                if (! in_array($head, LogicEvaluator::SYSTEM_VARS, true)) {
                    $this->warning($p, 'unknown_system_var', "Variable système inconnue « {$head} » (vaut null).");
                }

                return;
            }
            if (! isset($known[$head])) {
                $this->error($p, 'unknown_var', "Clé inconnue : {$head}.");

                return;
            }
            if ($selfKey !== null && $head === $selfKey) {
                $this->error($p, 'circular_calculate', "Le calcul « {$selfKey} » se référence lui-même.");

                return;
            }
            if ($known[$head] > $position) {
                $this->warning($p, 'forward_reference', "Référence à « {$head} », définie plus loin dans le document.");
            }

            return;
        }
        if (! array_key_exists($op, LogicEvaluator::ARITY)) {
            $this->error($p, 'unknown_operator', "Opérateur inconnu : {$op}.");

            return;
        }
        if (! is_array($args) || ! array_is_list($args)) {
            $this->error($p, 'bad_arity', "Les arguments de `{$op}` doivent être un tableau.");

            return;
        }
        [$min, $max] = LogicEvaluator::ARITY[$op];
        $n = count($args);
        if ($n < $min || $n > $max) {
            $this->error($p, 'bad_arity', "`{$op}` attend ".($min === $max ? $min : "{$min} à ".($max === PHP_INT_MAX ? '∞' : $max)).' argument(s), reçu '.$n.'.');
        }
        foreach ($args as $i => $arg) {
            if (($op === 'regex' && $i === 1) || ($op === 'date_diff' && $i === 2)) {
                continue; // littéraux (motif, unité)
            }
            $this->walkExpr($arg, "{$p}/{$i}", $known, $position, $selfKey, $depth + 1);
        }
    }

    /**
     * Vérifie les textes `I18n` d'un objet : `default_language` présent, traductions, interpolations.
     *
     * @param  array<string, mixed>  $item
     * @param  string[]  $fields
     * @param  string[]  $languages
     * @param  array<string, int>  $known
     */
    private function checkI18n(array $item, string $path, array $fields, array $languages, ?string $defaultLanguage, array $known): void
    {
        foreach ($fields as $field) {
            if (! isset($item[$field])) {
                continue;
            }
            $text = $item[$field];
            if ($text instanceof stdClass) {
                $text = (array) $text;
            }
            if (! is_array($text)) {
                continue;
            }
            $p = "{$path}/{$field}";
            if ($defaultLanguage !== null && (! isset($text[$defaultLanguage]) || ! is_string($text[$defaultLanguage]) || $text[$defaultLanguage] === '')) {
                $this->error($p, 'missing_default_language', "Texte sans traduction dans la langue par défaut « {$defaultLanguage} ».");
            }
            foreach ($languages as $lang) {
                if ($lang !== $defaultLanguage && (! isset($text[$lang]) || $text[$lang] === '')) {
                    $this->warning($p, 'missing_translation', "Traduction « {$lang} » manquante.");
                }
            }
            foreach (LabelResolver::placeholders($text) as $placeholder) {
                if (str_starts_with($placeholder, '_')) {
                    if (! in_array($placeholder, LogicEvaluator::SYSTEM_VARS, true)) {
                        $this->warning($p, 'unknown_system_var', "Variable système inconnue « {$placeholder} » dans une interpolation.");
                    }
                } elseif (! isset($known[$placeholder])) {
                    $this->error($p, 'unknown_interpolation', "Interpolation \${{$placeholder}} vers une clé inconnue.");
                }
            }
        }
    }

    /**
     * @param  array<int, mixed>  $choices
     * @param  array<string, int>  $known
     * @param  string[]  $languages
     */
    private function checkChoices(array $choices, string $path, array $known, array $languages, ?string $defaultLanguage): void
    {
        $seen = [];
        foreach ($choices as $i => $choice) {
            if ($choice instanceof stdClass) {
                $choice = (array) $choice;
            }
            if (! is_array($choice)) {
                continue;
            }
            $p = "{$path}/{$i}";
            $name = (string) ($choice['name'] ?? '');
            if (isset($seen[$name])) {
                $this->error($p.'/name', 'duplicate_choice_name', "Code de choix « {$name} » dupliqué dans la liste.");
            }
            $seen[$name] = true;
            $this->checkI18n($choice, $p, ['label'], $languages, $defaultLanguage, $known);
            if (isset($choice['filter']) && (is_array($choice['filter']) || $choice['filter'] instanceof stdClass)) {
                foreach (array_keys((array) $choice['filter']) as $parent) {
                    if (! isset($known[$parent])) {
                        $this->error($p.'/filter/'.self::escape((string) $parent), 'choice_filter_unknown_key', "Question parente inconnue : {$parent}.");
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $choiceLists
     * @param  array<string, int>  $known
     * @param  string[]  $languages
     * @return array<int, mixed>|null
     */
    private function resolveChoices(mixed $ref, array $choiceLists, string $path, array $known, array $languages, ?string $defaultLanguage): ?array
    {
        if (is_string($ref)) {
            if (! isset($choiceLists[$ref]) || ! is_array($choiceLists[$ref])) {
                $this->error($path, 'unknown_choice_list', "Liste de choix inconnue : {$ref}.");

                return null;
            }

            return array_values($choiceLists[$ref]);
        }
        if (is_array($ref) && array_is_list($ref)) {
            $this->checkChoices($ref, $path, $known, $languages, $defaultLanguage);

            return $ref;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fiche
     * @param  array<int, array{kind: string, key: string, path: string, def: array<string, mixed>}>  $nodes
     * @param  array<string, int>  $index
     */
    private function checkFicheCode(array $fiche, array $nodes, array $index): void
    {
        $sources = is_array($fiche['sources'] ?? null) ? $fiche['sources'] : [];
        $pattern = is_string($fiche['pattern'] ?? null) ? $fiche['pattern'] : '';
        preg_match_all('/\{([A-Za-z0-9_]+)(?::full)?\}/', $pattern, $m);
        $tokens = array_unique($m[1]);
        foreach ($tokens as $token) {
            if ($token !== 'NN' && ! array_key_exists($token, $sources)) {
                $this->error('/settings/fiche_code/pattern', 'fiche_token_unknown', "Jeton {{$token}} sans source.");
            }
        }
        foreach ($sources as $token => $questionKey) {
            $p = '/settings/fiche_code/sources/'.self::escape((string) $token);
            if (! is_string($questionKey) || ! isset($index[$questionKey]) || $nodes[$index[$questionKey]]['kind'] !== 'question') {
                $this->error($p, 'fiche_source_unknown', 'Question source inconnue : '.json_encode($questionKey).'.');

                continue;
            }
            $type = $nodes[$index[$questionKey]]['def']['type'] ?? null;
            if (! in_array($type, ['select_one', 'text', 'calculate'], true)) {
                $this->error($p, 'fiche_source_type', "La source « {$questionKey} » doit être select_one, text ou calculate (type {$type}).");
            }
            if (! in_array($token, $tokens, true)) {
                $this->warning($p, 'fiche_source_unused', "Source {{$token}} absente du motif.");
            }
        }
    }

    // ---------------------------------------------------------------------------
    // Utilitaires
    // ---------------------------------------------------------------------------

    /**
     * Convertit l'entrée en objet JSON (stdClass) pour opis ; les tableaux vides des propriétés objet
     * (`choice_lists`, `fiche_code.sources`, `filter`) redeviennent des objets.
     *
     * @param  array<string, mixed>|stdClass|string  $definition
     */
    private function toObject(array|stdClass|string $definition): ?stdClass
    {
        if (is_string($definition)) {
            $decoded = json_decode($definition);
            if (! $decoded instanceof stdClass) {
                $this->error('/', 'invalid_json', json_last_error() !== JSON_ERROR_NONE ? 'JSON illisible : '.json_last_error_msg() : 'La racine doit être un objet JSON.');

                return null;
            }

            return $decoded;
        }
        if ($definition instanceof stdClass) {
            return $definition;
        }
        if (array_is_list($definition) && $definition !== []) {
            $this->error('/', 'invalid_json', 'La racine doit être un objet JSON.');

            return null;
        }
        $encoded = json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $object = is_string($encoded) ? json_decode($encoded) : null;
        if (! $object instanceof stdClass) {
            $object = new stdClass;
        }
        if (isset($object->choice_lists) && $object->choice_lists === []) {
            $object->choice_lists = new stdClass;
        }
        if (isset($object->settings->fiche_code) && isset($object->settings->fiche_code->sources) && $object->settings->fiche_code->sources === []) {
            $object->settings->fiche_code->sources = new stdClass;
        }

        return $object;
    }

    /**
     * @param  array<int, string|int>  $segments
     */
    private static function pointer(array $segments): string
    {
        if ($segments === []) {
            return '/';
        }

        return '/'.implode('/', array_map(static fn ($s): string => self::escape((string) $s), $segments));
    }

    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    private function error(string $path, string $code, string $message): void
    {
        $this->errors[] = ['path' => $path, 'code' => $code, 'message' => $message, 'severity' => 'error'];
    }

    private function warning(string $path, string $code, string $message): void
    {
        $this->warnings[] = ['path' => $path, 'code' => $code, 'message' => $message, 'severity' => 'warning'];
    }

    private function result(): ValidationResult
    {
        return new ValidationResult($this->errors, $this->warnings);
    }
}
