<?php

namespace App\Services\Dfs;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Throwable;

/**
 * Convertisseur XLSForm ⇄ DFS v1 (README § 18).
 *
 * Export `toXlsForm()` : classeur à trois feuilles `survey`, `choices`, `settings` lisible par Kobo /
 * ODK. Tout ce que XLSForm ne représente pas nativement est conservé dans des colonnes `dfs::*` :
 *   - `dfs::type`            type DFS quand le type XLSForm est une approximation (`currency`)
 *   - `dfs::stop`            `note` qui est en réalité un `stop` (le `relevant` est le déclencheur, le `hint` le message)
 *   - `dfs::postcode`        question `{key}__codes` de post-codage : clé de la question hôte
 *   - `dfs::stage`           `begin_group` qui est une étape de suivi (clé de l'étape)
 *   - `dfs::due_offset_days` échéance de l'étape
 *   - `dfs::props`           JSON compact des propriétés DFS non représentables (min/max, tags, other,
 *                            postcode, audience/style, AST exact d'une expression que XPath approxime…)
 *   - feuille `settings` : `dfs::title`, `dfs::description`, `dfs::settings` (code fiche, quotas, KPI, geo, timing…)
 * L'import de nos propres exports est un aller-retour sans perte (`XlsFormRoundTripTest`).
 *
 * Import `fromXlsForm()` : lit un XLSForm quelconque (Kobo, ODK, export Datamuse) et produit une
 * définition DFS **toujours valide** : ce qui n'est pas reconnu est signalé dans `warnings[]`
 * (`{sheet, row, key?, message}`) et omis. Les colonnes `bind::dm:*` du README sont acceptées
 * comme alias des colonnes `dfs::*`.
 */
final class XlsFormConverter
{
    public const PROPS_COLUMN = 'dfs::props';

    private const LANG_NAMES = [
        'fr' => 'Français', 'en' => 'English', 'es' => 'Español', 'pt' => 'Português', 'de' => 'Deutsch',
        'ar' => 'العربية', 'sw' => 'Kiswahili', 'ha' => 'Hausa', 'ff' => 'Fulfulde', 'ln' => 'Lingala',
        'wo' => 'Wolof', 'bm' => 'Bambara', 'it' => 'Italiano', 'nl' => 'Nederlands',
    ];

    private const NAME_TO_CODE = [
        'français' => 'fr', 'francais' => 'fr', 'french' => 'fr', 'english' => 'en', 'anglais' => 'en',
        'español' => 'es', 'espanol' => 'es', 'spanish' => 'es', 'português' => 'pt', 'portugues' => 'pt', 'portuguese' => 'pt',
        'deutsch' => 'de', 'german' => 'de', 'arabic' => 'ar', 'arabe' => 'ar', 'العربية' => 'ar',
        'swahili' => 'sw', 'kiswahili' => 'sw', 'hausa' => 'ha', 'fulfulde' => 'ff', 'lingala' => 'ln',
        'wolof' => 'wo', 'bambara' => 'bm', 'italiano' => 'it', 'italian' => 'it', 'nederlands' => 'nl', 'dutch' => 'nl',
    ];

    private const I18N_FIELDS = ['label', 'hint', 'required_message', 'constraint_message'];

    private const DFS_COLUMNS = ['dfs::type', 'dfs::stop', 'dfs::postcode', 'dfs::stage', 'dfs::due_offset_days', self::PROPS_COLUMN];

    /** Champs de question traduits directement en colonnes XLSForm (le reste va dans `dfs::props`). */
    private const MAPPED_FIELDS = ['key', 'type', 'label', 'hint', 'required', 'required_message', 'relevant', 'constraint', 'constraint_message', 'read_only', 'default', 'appearance', 'choices', 'other', 'postcode', 'expression', 'message'];

    private const CHOICE_RESERVED = ['list_name', 'list name', 'name', 'label', 'hint', 'image', 'audio', 'video', 'media', 'order', 'value'];

    private const META_TYPES = [
        'start' => ['var' => '_start_time'], 'end' => ['var' => '_end_time'], 'today' => ['today' => []],
        'deviceid' => ['var' => '_device'], 'username' => ['var' => '_enumerator'],
    ];

    private const SKIPPED_TYPES = ['phonenumber', 'simserial', 'subscriberid', 'audit', 'email', 'background-audio', 'file', 'video', 'geotrace', 'geoshape', 'acknowledge', 'hidden', 'trigger', 'xml-external', 'csv-external', 'select_one_from_file', 'select_multiple_from_file', 'select_one_external', 'osm'];

    /** @var array<int, array{sheet: string, row: int|null, key: string|null, message: string}> */
    private array $warnings = [];

    /** @var string[] */
    private array $langs = ['fr'];

    private string $default = 'fr';

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $choiceLists = [];

    // ================================================================== EXPORT

    /**
     * @param  array<string, mixed>  $definition  définition DFS
     * @param  string  $outputPath  chemin du classeur `.xlsx` à écrire
     * @return array{warnings: array<int, array{sheet: string, row: int|null, key: string|null, message: string}>}
     */
    public function toXlsForm(array $definition, string $outputPath): array
    {
        $def = DfsDefaults::toArray($definition);
        $this->warnings = [];
        $settings = is_array($def['settings'] ?? null) ? $def['settings'] : [];
        $this->default = is_string($settings['default_language'] ?? null) && $settings['default_language'] !== '' ? $settings['default_language'] : 'fr';
        $langs = is_array($settings['languages'] ?? null) ? array_values(array_filter($settings['languages'], 'is_string')) : [];
        if (! in_array($this->default, $langs, true)) {
            array_unshift($langs, $this->default);
        }
        $this->langs = $langs;
        $this->choiceLists = is_array($def['choice_lists'] ?? null) ? $def['choice_lists'] : [];

        $rows = [];
        foreach ($def['sections'] ?? [] as $section) {
            if (is_array($section)) {
                $this->exportContainer($section, 'section', $rows);
            }
        }
        foreach ($def['follow_up_stages'] ?? [] as $stage) {
            if (is_array($stage)) {
                $this->exportContainer($stage, 'stage', $rows);
            }
        }

        $cascade = $this->cascadeColumns();
        $choiceRows = [];
        foreach ($this->choiceLists as $listName => $choices) {
            foreach (is_array($choices) ? $choices : [] as $choice) {
                if (! is_array($choice)) {
                    continue;
                }
                $row = ['list_name' => (string) $listName, 'name' => (string) ($choice['name'] ?? '')];
                $this->i18nCells($row, 'label', $choice['label'] ?? null);
                $row['dfs::abbr'] = (string) ($choice['abbr'] ?? '');
                foreach (is_array($choice['filter'] ?? null) ? $choice['filter'] : [] as $parent => $value) {
                    $row[$cascade[$parent] ?? $parent] = is_scalar($value) ? $value : LogicEvaluator::toStr($value);
                }
                $choiceRows[] = $row;
            }
        }

        $surveyHeader = array_merge(['type', 'name'], $this->i18nHeaders('label'), $this->i18nHeaders('hint'), ['required'], $this->i18nHeaders('required_message'), ['relevant', 'constraint'], $this->i18nHeaders('constraint_message'), ['calculation', 'appearance', 'default', 'read_only', 'parameters', 'choice_filter'], self::DFS_COLUMNS);
        $choicesHeader = array_merge(['list_name', 'name'], $this->i18nHeaders('label'), ['dfs::abbr'], array_values($cascade));

        $extraSettings = array_diff_key($settings, ['languages' => 1, 'default_language' => 1]);
        $settingsRow = [
            'form_title' => LabelResolver::resolve($def['title'] ?? [], $this->default, $this->default, ''),
            'form_id' => (string) ($def['id'] ?? ''),
            'version' => (int) ($def['version'] ?? 1),
            'default_language' => $this->langHeader($this->default),
            'dfs::dfs_version' => (string) ($def['dfs_version'] ?? '1.0'),
            'dfs::title' => self::json($def['title'] ?? []),
            'dfs::description' => isset($def['description']) ? self::json($def['description']) : '',
            'dfs::settings' => $extraSettings === [] ? '' : self::json($extraSettings),
        ];
        if ($extraSettings !== []) {
            $this->warn('settings', null, null, 'Paramètres sans équivalent XLSForm conservés dans dfs::settings : '.implode(', ', array_keys($extraSettings)).'.');
        }

        $writer = SimpleExcelWriter::create($outputPath, 'xlsx')->noHeaderRow();
        $writer->nameCurrentSheet('survey')->addHeader($surveyHeader);
        foreach ($rows as $row) {
            $writer->addRow(self::cells($row, $surveyHeader));
        }
        $writer->addNewSheetAndMakeItCurrent('choices')->addHeader($choicesHeader);
        foreach ($choiceRows as $row) {
            $writer->addRow(self::cells($row, $choicesHeader));
        }
        $settingsHeader = array_keys($settingsRow);
        $writer->addNewSheetAndMakeItCurrent('settings')->addHeader($settingsHeader);
        $writer->addRow(self::cells($settingsRow, $settingsHeader));
        $writer->close();

        return ['warnings' => $this->warnings];
    }

    /**
     * @param  array<string, mixed>  $container  section, groupe ou étape
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function exportContainer(array $container, string $kind, array &$rows): void
    {
        $key = (string) ($container['key'] ?? '');
        $repeat = $kind === 'group' && isset($container['repeat']) && is_array($container['repeat']);
        $row = ['type' => $repeat ? 'begin_repeat' : 'begin_group', 'name' => $key];
        $this->i18nCells($row, 'label', $container['label'] ?? null);
        $props = [];
        $this->exportExpression($row, $props, 'relevant', $container['relevant'] ?? null, $key, null);
        if ($kind === 'group' && ($container['appearance'] ?? 'list') === 'matrix') {
            $row['appearance'] = 'field-list';
            $props['appearance'] = 'matrix';
        }
        if ($kind === 'stage') {
            $row['dfs::stage'] = $key;
            $row['dfs::due_offset_days'] = (int) ($container['due_offset_days'] ?? 0);
            $this->warn('survey', null, $key, 'Étape de suivi exportée comme groupe (dfs::stage) : XLSForm n\'a pas de notion d\'étape.');
        }
        foreach ($container as $field => $value) {
            if (in_array($field, ['key', 'label', 'relevant', 'items', 'type', 'due_offset_days', 'repeat'], true) || ($field === 'appearance' && $kind === 'group')) {
                continue;
            }
            $props[$field] = $value;
        }
        if ($repeat) {
            $props['repeat'] = $container['repeat'];
        }
        if ($props !== []) {
            $row[self::PROPS_COLUMN] = self::json($props);
        }
        $rows[] = $row;

        foreach ($container['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['type'] ?? null) === 'group') {
                $this->exportContainer($item, 'group', $rows);
            } else {
                $this->exportQuestion($item, $rows);
            }
        }
        $rows[] = ['type' => $repeat ? 'end_repeat' : 'end_group', 'name' => $key];
    }

    /**
     * @param  array<string, mixed>  $q
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function exportQuestion(array $q, array &$rows): void
    {
        $key = (string) ($q['key'] ?? '');
        $type = (string) ($q['type'] ?? '');
        $row = ['name' => $key];
        $props = [];
        $constraintParts = [];

        foreach ($q as $field => $value) {
            if (! in_array($field, self::MAPPED_FIELDS, true)) {
                $props[$field] = $value;
            }
        }

        switch ($type) {
            case 'select_one':
            case 'select_multiple':
            case 'rank':
                $list = $this->materializeList($q['choices'] ?? null, $key, $props);
                $row['type'] = $type.' '.$list;
                $row['choice_filter'] = $this->choiceFilter($list);
                $row['appearance'] = match ($q['appearance'] ?? null) {
                    'dropdown' => 'minimal',
                    'buttons', 'chips' => 'columns',
                    default => '',
                };
                if (isset($q['min_selected'])) {
                    $constraintParts[] = 'count-selected(.) >= '.(int) $q['min_selected'];
                }
                if (isset($q['max_selected'])) {
                    $constraintParts[] = 'count-selected(.) <= '.(int) $q['max_selected'];
                }
                break;
            case 'text':
                $row['type'] = 'text';
                $row['appearance'] = ($q['appearance'] ?? 'short') === 'multiline' ? 'multiline' : (($q['format'] ?? 'text') === 'phone' ? 'numbers' : '');
                if (isset($q['min_length'])) {
                    $constraintParts[] = 'string-length(.) >= '.(int) $q['min_length'];
                }
                if (isset($q['max_length'])) {
                    $constraintParts[] = 'string-length(.) <= '.(int) $q['max_length'];
                }
                break;
            case 'integer':
            case 'decimal':
            case 'currency':
                $row['type'] = $type === 'integer' ? 'integer' : 'decimal';
                if ($type === 'currency') {
                    $row['dfs::type'] = 'currency';
                    $this->warn('survey', null, $key, 'Montant (currency) exporté comme decimal + dfs::type=currency.');
                }
                if (isset($q['min']) && is_numeric($q['min'])) {
                    $constraintParts[] = '. >= '.LogicEvaluator::toStr($q['min']);
                }
                if (isset($q['max']) && is_numeric($q['max'])) {
                    $constraintParts[] = '. <= '.LogicEvaluator::toStr($q['max']);
                }
                break;
            case 'date':
            case 'time':
                $row['type'] = $type;
                break;
            case 'datetime':
                $row['type'] = 'dateTime';
                break;
            case 'geopoint':
            case 'audio':
                $row['type'] = $type;
                break;
            case 'photo':
                $row['type'] = 'image';
                break;
            case 'signature':
                $row['type'] = 'image';
                $row['appearance'] = 'signature';
                break;
            case 'note':
                $row['type'] = 'note';
                break;
            case 'calculate':
                $row['type'] = 'calculate';
                $this->exportExpression($row, $props, 'expression', $q['expression'] ?? null, $key, null);
                break;
            case 'stop':
                $row['type'] = 'note';
                $row['dfs::stop'] = 'yes';
                $this->i18nCells($row, 'hint', $q['message'] ?? null);
                $this->warn('survey', null, $key, 'Fin anticipée (stop) exportée comme note + dfs::stop (relevant = déclencheur, hint = message).');
                break;
            default:
                $this->warn('survey', null, $key, "Type DFS inconnu « {$type} » exporté comme text.");
                $row['type'] = 'text';
        }

        $this->i18nCells($row, 'label', $q['label'] ?? null);
        if ($type !== 'stop') {
            $this->i18nCells($row, 'hint', $q['hint'] ?? null);
        }
        $this->i18nCells($row, 'required_message', $q['required_message'] ?? null);
        $this->i18nCells($row, 'constraint_message', $q['constraint_message'] ?? null);
        $this->exportExpression($row, $props, 'relevant', $q['relevant'] ?? null, $key, null);
        $this->exportBoolOrExpression($row, $props, 'required', $q['required'] ?? null, $key);
        $this->exportBoolOrExpression($row, $props, 'read_only', $q['read_only'] ?? null, $key);
        $this->exportDefault($row, $props, $q['default'] ?? null, $key);
        $this->exportConstraint($row, $props, $q['constraint'] ?? null, $constraintParts, $key);

        if ($props !== []) {
            $row[self::PROPS_COLUMN] = self::json($props);
        }
        $rows[] = $row;

        if (isset($q['other']) && is_array($q['other']) && in_array($type, ['select_one', 'select_multiple'], true)) {
            $other = $q['other'];
            $choice = (string) ($other['choice'] ?? 'autre');
            $companion = ['type' => 'text', 'name' => (string) ($other['key'] ?? $key.'_other')];
            $this->i18nCells($companion, 'label', $other['label'] ?? [$this->default => 'Précisez']);
            $companion['relevant'] = 'selected(${'.$key."}, '".$choice."')";
            $companion['required'] = ($other['required'] ?? true) ? 'yes' : '';
            $companion[self::PROPS_COLUMN] = self::json(['@companion' => 'other', '@host' => $key, '@other' => $other]);
            $rows[] = $companion;
        }
        if (isset($q['postcode']) && is_array($q['postcode']) && $type === 'text') {
            $postcode = $q['postcode'];
            $pprops = [];
            $list = $this->materializeList($postcode['choices'] ?? null, $key.'__codes', $pprops);
            $companion = ['type' => (($postcode['multiple'] ?? true) ? 'select_multiple' : 'select_one').' '.$list, 'name' => (string) ($postcode['key'] ?? $key.'__codes')];
            $this->i18nCells($companion, 'label', $postcode['label'] ?? [$this->default => 'Codes']);
            $companion['relevant'] = 'string-length(${'.$key.'}) > 0';
            $companion['choice_filter'] = $this->choiceFilter($list);
            $companion['dfs::postcode'] = $key;
            $companion[self::PROPS_COLUMN] = self::json(['@companion' => 'postcode', '@host' => $key, '@postcode' => $postcode] + $pprops);
            $rows[] = $companion;
            $this->warn('survey', null, $key, 'Post-codage exporté comme question séparée « '.$companion['name'].' » (dfs::postcode).');
        }
    }

    /**
     * Nom de liste pour une référence `choices` ; une liste en ligne est matérialisée dans `choices`.
     *
     * @param  array<string, mixed>  $props
     */
    private function materializeList(mixed $ref, string $baseName, array &$props): string
    {
        if (is_string($ref)) {
            return $ref;
        }
        if (is_array($ref) && array_is_list($ref)) {
            $name = $baseName;
            $i = 1;
            while (isset($this->choiceLists[$name])) {
                $name = $baseName.'_'.(++$i);
            }
            $this->choiceLists[$name] = $ref;
            $props['@inline'] = true;
            $this->warn('survey', null, $baseName, "Liste de choix en ligne matérialisée sous le nom « {$name} ».");

            return $name;
        }

        return '';
    }

    /**
     * Colonnes de cascade de la feuille `choices` : clé parente → nom de colonne.
     *
     * @return array<string, string>
     */
    private function cascadeColumns(): array
    {
        $columns = [];
        foreach ($this->choiceLists as $choices) {
            foreach (is_array($choices) ? $choices : [] as $choice) {
                foreach (is_array($choice['filter'] ?? null) ? $choice['filter'] : [] as $parent => $value) {
                    $parent = (string) $parent;
                    if (! isset($columns[$parent])) {
                        $lower = strtolower($parent);
                        $reserved = in_array($lower, self::CHOICE_RESERVED, true) || str_starts_with($lower, 'label') || str_starts_with($lower, 'dfs::');
                        $columns[$parent] = $reserved ? 'filter_'.$parent : $parent;
                    }
                }
            }
        }

        return $columns;
    }

    private function choiceFilter(string $list): string
    {
        $parents = [];
        foreach ($this->choiceLists[$list] ?? [] as $choice) {
            foreach (is_array($choice['filter'] ?? null) ? $choice['filter'] : [] as $parent => $value) {
                $parents[(string) $parent] = true;
            }
        }
        if ($parents === []) {
            return '';
        }
        $columns = $this->cascadeColumns();
        $parts = [];
        foreach (array_keys($parents) as $parent) {
            $parts[] = ($columns[$parent] ?? $parent).'=${'.$parent.'}';
        }

        return implode(' and ', $parts);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $props
     */
    private function exportExpression(array &$row, array &$props, string $field, mixed $expr, string $key, ?string $selfKey): ?string
    {
        if ($expr === null) {
            return null;
        }
        $column = $field === 'expression' ? 'calculation' : $field;
        $result = LogicToXPath::convert($expr, $selfKey);
        $row[$column] = $result['xpath'];
        if (! $result['exact']) {
            $props[$field] = $expr;
            $this->warn('survey', null, $key, "{$field} : XPath approximatif (".implode(' ; ', $result['notes'] ?: ['non réversible']).') ; AST conservé dans dfs::props.');
        } elseif ($result['notes'] !== []) {
            $this->warn('survey', null, $key, "{$field} : ".implode(' ; ', $result['notes']).'.');
        }

        return $result['xpath'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $props
     */
    private function exportBoolOrExpression(array &$row, array &$props, string $field, mixed $value, string $key): void
    {
        if ($value === null || $value === false) {
            return;
        }
        if ($value === true) {
            $row[$field] = 'yes';

            return;
        }
        $this->exportExpression($row, $props, $field, $value, $key, $key);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $props
     */
    private function exportDefault(array &$row, array &$props, mixed $value, string $key): void
    {
        if ($value === null) {
            return;
        }
        if (is_int($value) || is_float($value)) {
            $row['default'] = $value;

            return;
        }
        if (is_bool($value)) {
            $row['default'] = $value ? 'true()' : 'false()';

            return;
        }
        if (is_string($value)) {
            if (self::looksLikeExpression($value)) {
                $props['default'] = $value;
                $row['default'] = $value;
            } else {
                $row['default'] = $value;
            }

            return;
        }
        $this->exportExpression($row, $props, 'default', $value, $key, $key);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $props
     * @param  string[]  $parts
     */
    private function exportConstraint(array &$row, array &$props, mixed $constraint, array $parts, string $key): void
    {
        $own = null;
        $exact = true;
        if ($constraint !== null) {
            $result = LogicToXPath::convert($constraint, $key);
            $own = $result['xpath'];
            $exact = $result['exact'];
            if (! $exact) {
                $this->warn('survey', null, $key, 'constraint : XPath approximatif ('.implode(' ; ', $result['notes'] ?: ['non réversible']).') ; AST conservé dans dfs::props.');
            }
        }
        if ($parts === []) {
            if ($own !== null) {
                $row['constraint'] = $own;
                if (! $exact) {
                    $props['constraint'] = $constraint;
                }
            }

            return;
        }
        if ($own !== null) {
            $parts[] = count($parts) > 0 && ! self::isAtomicXPath($own) ? '('.$own.')' : $own;
        }
        $row['constraint'] = implode(' and ', array_map(static fn (string $p): string => str_contains($p, ' and ') || str_contains($p, ' or ') ? '('.$p.')' : $p, $parts));
        // La contrainte écrite mêle bornes synthétiques et contrainte propre : l'AST d'origine (ou son absence) est conservé.
        $props['constraint'] = $constraint;
    }

    private static function isAtomicXPath(string $xpath): bool
    {
        return ! str_contains($xpath, ' and ') && ! str_contains($xpath, ' or ');
    }

    private static function looksLikeExpression(string $value): bool
    {
        return preg_match('/^\s*(\$\{|[a-zA-Z][a-zA-Z0-9\-]*\s*\(|\.\s*[=<>!])/', $value) === 1;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function i18nCells(array &$row, string $base, mixed $i18n): void
    {
        foreach ($this->langs as $lang) {
            $value = '';
            if (is_string($i18n)) {
                $value = $lang === $this->default ? $i18n : '';
            } elseif (is_array($i18n) && isset($i18n[$lang]) && is_string($i18n[$lang])) {
                $value = $i18n[$lang];
            }
            $row[$base.'::'.$this->langHeader($lang)] = $value;
        }
    }

    /**
     * @return string[]
     */
    private function i18nHeaders(string $base): array
    {
        return array_map(fn (string $lang): string => $base.'::'.$this->langHeader($lang), $this->langs);
    }

    private function langHeader(string $lang): string
    {
        return (self::LANG_NAMES[$lang] ?? strtoupper($lang)).' ('.$lang.')';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  string[]  $header
     * @return array<int, mixed>
     */
    private static function cells(array $row, array $header): array
    {
        $cells = [];
        foreach ($header as $column) {
            $value = $row[$column] ?? '';
            if (is_string($value) && $value !== '' && $value[0] === '=') {
                $value = ' '.$value; // évite l'interprétation en formule ; l'import trime les cellules
            } elseif (! is_scalar($value) && $value !== null) {
                $value = self::json($value);
            }
            $cells[] = $value;
        }

        return $cells;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    // ================================================================== IMPORT

    /** @var array<string, string> nom XLSForm → clé DFS */
    private array $keyMap = [];

    /** @var array<string, true> */
    private array $usedKeys = [];

    /** @var array<string, array<string, string>> liste → {colonne de cascade → nom XLSForm de la question parente} */
    private array $listCascades = [];

    /** @var array<string, true> listes de choix consommées en ligne */
    private array $inlinedLists = [];

    /** @var array<int, array<string, mixed>> */
    private array $stages = [];

    /**
     * @param  string  $path  classeur `.xlsx` (ou `.xls`, `.csv` d'une seule feuille `survey`)
     * @return array{definition: array<string, mixed>, warnings: array<int, array{sheet: string, row: int|null, key: string|null, message: string}>}
     *
     * @throws InvalidArgumentException fichier illisible ou sans feuille `survey`
     */
    public function fromXlsForm(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Fichier introuvable : {$path}.");
        }
        $this->warnings = [];
        $this->keyMap = [];
        $this->usedKeys = [];
        $this->listCascades = [];
        $this->inlinedLists = [];
        $this->stages = [];
        $this->choiceLists = [];

        $type = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'xlsx';
        $survey = $this->readSheet($path, $type, 'survey');
        if ($survey === null) {
            throw new InvalidArgumentException('Feuille « survey » introuvable : le fichier n\'est pas un XLSForm.');
        }
        $choices = $this->readSheet($path, $type, 'choices') ?? ['headers' => [], 'rows' => []];
        $settings = $this->readSheet($path, $type, 'settings') ?? ['headers' => [], 'rows' => []];
        $settingsRow = $settings['rows'][0] ?? [];

        // Langues.
        $this->default = $this->parseLanguage(self::cellString($settingsRow['default_language'] ?? null)) ?? 'fr';
        $langs = [];
        foreach ([$survey['headers'], $choices['headers']] as $headers) {
            foreach ($headers as $header) {
                $parsed = $this->parseI18nHeader($header);
                if ($parsed !== null && ! in_array($parsed['lang'], $langs, true)) {
                    $langs[] = $parsed['lang'];
                }
            }
        }
        if (! in_array($this->default, $langs, true)) {
            array_unshift($langs, $this->default);
        }
        $this->langs = $langs;

        // Listes de choix.
        $this->importChoices($choices);

        // Arbre survey.
        $tree = $this->buildTree($survey['rows']);
        $sections = $this->importSections($tree);

        // Feuille settings.
        $title = $this->decodeJson(self::cellString($settingsRow['dfs::title'] ?? null), 'settings', 2);
        if (! is_array($title) || $title === []) {
            $formTitle = self::cellString($settingsRow['form_title'] ?? null);
            $title = [$this->default => $formTitle !== '' ? $formTitle : 'Formulaire importé'];
        }
        $description = $this->decodeJson(self::cellString($settingsRow['dfs::description'] ?? null), 'settings', 2);
        $formId = self::cellString($settingsRow['form_id'] ?? null);
        $id = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $formId) === 1 ? strtolower($formId) : (string) Str::uuid();
        $versionCell = $settingsRow['version'] ?? null;
        $version = is_numeric($versionCell) && (int) $versionCell >= 1 ? (int) $versionCell : 1;
        $extraSettings = $this->decodeJson(self::cellString($settingsRow['dfs::settings'] ?? null), 'settings', 2);

        $settingsOut = ['languages' => $this->langs, 'default_language' => $this->default];
        if (is_array($extraSettings)) {
            $settingsOut += array_diff_key($extraSettings, ['languages' => 1, 'default_language' => 1]);
        }

        $definition = ['dfs_version' => '1.0', 'id' => $id, 'version' => $version, 'title' => $title];
        if (is_array($description) && $description !== []) {
            $definition['description'] = $description;
        }
        $definition['settings'] = $settingsOut;
        $definition['choice_lists'] = array_filter($this->choiceLists, fn (array $list, string $name): bool => $list !== [] && ! isset($this->inlinedLists[$name]), ARRAY_FILTER_USE_BOTH);
        $definition['sections'] = $sections;
        $definition['follow_up_stages'] = $this->stages;

        $this->finalize($definition);

        return ['definition' => $definition, 'warnings' => $this->warnings];
    }

    /**
     * @return array{headers: string[], rows: array<int, array<string, mixed>>}|null
     */
    private function readSheet(string $path, string $type, string $sheet): ?array
    {
        $probe = SimpleExcelReader::create($path, $type);
        try {
            if (! $probe->hasSheet($sheet)) {
                return null;
            }
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Classeur illisible : '.$e->getMessage(), 0, $e);
        } finally {
            $probe->close();
        }

        $reader = SimpleExcelReader::create($path, $type)->fromSheetName($sheet)->trimHeaderRow();
        try {
            $rawRows = $reader->getRows()->toArray();
            $headers = $reader->getHeaders() ?? [];
        } finally {
            $reader->close();
            unset($reader);
            // L'itérateur de lignes d'OpenSpout forme un cycle qui garde le flux zip ouvert (verrou Windows).
            gc_collect_cycles();
        }
        $normalized = array_map([self::class, 'normalizeHeader'], array_map('strval', $headers));
        $rows = [];
        foreach ($rawRows as $raw) {
            $row = [];
            foreach ($raw as $header => $value) {
                $row[self::normalizeHeader((string) $header)] = $value;
            }
            $rows[] = $row;
        }

        return ['headers' => $normalized, 'rows' => $rows];
    }

    private static function normalizeHeader(string $header): string
    {
        $header = trim($header);
        $lower = strtolower($header);
        if (in_array($lower, ['list name', 'list_name'], true)) {
            return 'list_name';
        }
        if (str_starts_with($lower, 'bind::dm:')) {
            return 'dfs::'.substr($header, 9);
        }
        if (str_starts_with($lower, 'dfs::')) {
            return 'dfs::'.substr($header, 5);
        }
        if (preg_match('/^(label|hint|required_message|constraint_message|required|relevant|constraint|calculation|appearance|default|read_only|readonly|parameters|choice_filter|type|name|form_title|form_id|version|default_language|repeat_count)$/i', $header) === 1) {
            return $lower === 'readonly' ? 'read_only' : $lower;
        }
        if (preg_match('/^(label|hint|required_message|constraint_message)\s*::\s*(.+)$/i', $header, $m) === 1) {
            return strtolower($m[1]).'::'.trim($m[2]);
        }

        return $header;
    }

    /**
     * @return array{base: string, lang: string}|null
     */
    private function parseI18nHeader(string $header): ?array
    {
        if (preg_match('/^(label|hint|required_message|constraint_message)(?:::(.+))?$/', $header, $m) !== 1) {
            return null;
        }
        $suffix = trim($m[2] ?? '');
        if ($suffix === '') {
            return ['base' => $m[1], 'lang' => $this->default];
        }
        $lang = $this->parseLanguage($suffix);

        return ['base' => $m[1], 'lang' => $lang ?? $this->default];
    }

    private function parseLanguage(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/\(([A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*)\)\s*$/', $text, $m) === 1) {
            return strtolower($m[1]);
        }
        if (preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $text) === 1) {
            return strtolower($text);
        }
        $lower = mb_strtolower($text);
        if (isset(self::NAME_TO_CODE[$lower])) {
            return self::NAME_TO_CODE[$lower];
        }
        $slug = preg_replace('/[^a-z]/', '', Str::ascii($lower)) ?? '';
        $code = substr($slug, 0, 3);
        if (strlen($code) < 2) {
            return null;
        }
        $this->warn('survey', null, null, "Langue « {$text} » sans code : code « {$code} » déduit.");

        return $code;
    }

    /**
     * @param  array{headers: string[], rows: array<int, array<string, mixed>>}  $sheet
     */
    private function importChoices(array $sheet): void
    {
        foreach ($sheet['rows'] as $i => $row) {
            $rowNum = $i + 2;
            $list = self::cellString($row['list_name'] ?? null);
            $name = self::cellString($row['name'] ?? null);
            if ($list === '' && $name === '') {
                continue;
            }
            if ($list === '' || $name === '') {
                $this->warn('choices', $rowNum, null, 'Ligne ignorée : list_name ou name manquant.');

                continue;
            }
            $listKey = $this->sanitizeIdentifier($list, 40, false);
            if ($listKey !== $list) {
                $this->warn('choices', $rowNum, null, "Nom de liste « {$list} » normalisé en « {$listKey} ».");
            }
            $code = $this->sanitizeIdentifier($name, 60, true);
            if ($code !== $name) {
                $this->warn('choices', $rowNum, null, "Code de choix « {$name} » normalisé en « {$code} » (liste {$listKey}).");
            }
            foreach ($this->choiceLists[$listKey] ?? [] as $existing) {
                if ($existing['name'] === $code) {
                    $this->warn('choices', $rowNum, null, "Choix « {$code} » en double dans la liste {$listKey} : ignoré.");

                    continue 2;
                }
            }
            $choice = ['name' => $code, 'label' => $this->i18n($row, 'label') ?? [$this->default => $name]];
            $abbr = self::cellString($row['dfs::abbr'] ?? null);
            if ($abbr !== '') {
                if (preg_match('/^[A-Z0-9]{1,6}$/', $abbr) === 1) {
                    $choice['abbr'] = $abbr;
                } else {
                    $this->warn('choices', $rowNum, null, "Abréviation « {$abbr} » invalide (A-Z0-9, 6 max) : ignorée.");
                }
            }
            $choice['@row'] = $row;
            $this->choiceLists[$listKey][] = $choice;
        }
    }

    /**
     * Construit l'arbre des groupes / questions de la feuille `survey`.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(array $rows): array
    {
        $root = ['kind' => 'root', 'children' => []];
        $stack = [&$root];
        $depth = 0;
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $typeCell = self::cellString($row['type'] ?? null);
            if ($typeCell === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $typeCell) ?: [];
            $base = strtolower($parts[0]);
            if (in_array($base, ['begin', 'end', 'select'], true) && isset($parts[1]) && in_array(strtolower($parts[1]), ['group', 'repeat', 'one', 'multiple'], true)) {
                $base .= '_'.strtolower($parts[1]);
                array_splice($parts, 0, 2, [$base]);
            }
            if ($base === 'begin_group' || $base === 'begin_repeat') {
                $node = ['kind' => 'group', 'repeat' => $base === 'begin_repeat', 'row' => $row, 'rowNum' => $rowNum, 'children' => []];
                $stack[$depth]['children'][] = $node;
                $index = count($stack[$depth]['children']) - 1;
                $stack[$depth + 1] = &$stack[$depth]['children'][$index];
                $depth++;

                continue;
            }
            if ($base === 'end_group' || $base === 'end_repeat') {
                if ($depth === 0) {
                    $this->warn('survey', $rowNum, null, "« {$typeCell} » sans groupe ouvert : ignoré.");

                    continue;
                }
                unset($stack[$depth]);
                $depth--;

                continue;
            }
            $orOther = false;
            $list = null;
            foreach (array_slice($parts, 1) as $part) {
                if (strtolower($part) === 'or_other') {
                    $orOther = true;
                } elseif ($list === null) {
                    $list = $part;
                }
            }
            $stack[$depth]['children'][] = ['kind' => 'question', 'base' => $base, 'list' => $list, 'or_other' => $orOther, 'row' => $row, 'rowNum' => $rowNum];
        }
        if ($depth > 0) {
            $this->warn('survey', null, null, "{$depth} groupe(s) non fermé(s) en fin de feuille : fermés automatiquement.");
        }

        return $root['children'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $top
     * @return array<int, array<string, mixed>>
     */
    private function importSections(array $top): array
    {
        $isStage = fn (array $node): bool => $node['kind'] === 'group' && self::cellString($node['row']['dfs::stage'] ?? null) !== '';
        $others = [];
        $stageNodes = [];
        foreach ($top as $node) {
            if ($isStage($node)) {
                $stageNodes[] = $node;
            } else {
                $others[] = $node;
            }
        }
        $allGroups = $others !== [];
        foreach ($others as $node) {
            if ($node['kind'] !== 'group' || $node['repeat']) {
                $allGroups = false;
                break;
            }
        }

        $sections = [];
        if ($allGroups) {
            foreach ($others as $node) {
                $sections[] = $this->importContainer($node, 'section');
            }
            foreach ($stageNodes as $node) {
                $this->stages[] = $this->importContainer($node, 'stage');
            }
        } else {
            foreach ($stageNodes as $node) {
                $this->stages[] = $this->importContainer($node, 'stage');
            }
            if ($others !== []) {
                $key = $this->keyFor('main', null);
                $section = ['key' => $key, 'label' => [$this->default => 'Questionnaire']];
                $section['items'] = $this->importItems($others, true, false, 'section');
                $sections[] = $section;
                if (count($others) > 0 && ($others[0]['kind'] !== 'group' || count($others) > 1)) {
                    $this->warn('survey', null, null, 'Questions hors groupe de premier niveau : regroupées dans une section « '.$key.' » par défaut.');
                }
            }
        }
        // Un questionnaire DFS a au moins une section.
        $sections = array_values(array_filter($sections, static fn (array $s): bool => $s['items'] !== []));
        if ($sections === []) {
            $this->warn('survey', null, null, 'Aucune question importable : section vide créée.');
            $sections[] = ['key' => $this->keyFor('main', null), 'label' => [$this->default => 'Questionnaire'], 'items' => [
                ['key' => $this->keyFor('vide', null), 'type' => 'note', 'label' => [$this->default => 'Questionnaire vide']],
            ]];
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function importContainer(array $node, string $kind): array
    {
        $row = $node['row'];
        $rowNum = $node['rowNum'];
        $name = self::cellString($row['name'] ?? null);
        $key = $this->keyFor($name !== '' ? $name : $kind, $rowNum);
        $out = ['key' => $key];
        if ($kind === 'group') {
            $out = ['type' => 'group', 'key' => $key];
        }
        $out['label'] = $this->i18n($row, 'label') ?? [$this->default => $name !== '' ? $name : $key];
        $relevant = $this->parseExpression($row, 'relevant', $key, $rowNum, null);
        if ($relevant !== null) {
            $out['relevant'] = $relevant;
        }
        if ($kind === 'stage') {
            $due = $row['dfs::due_offset_days'] ?? null;
            $out['due_offset_days'] = is_numeric($due) ? (int) $due : 0;
        }
        if ($kind === 'group') {
            $appearance = strtolower(self::cellString($row['appearance'] ?? null));
            if ($appearance === 'table-list') {
                $out['appearance'] = 'matrix';
            } elseif ($appearance !== '' && $appearance !== 'field-list' && $appearance !== 'w1' && $appearance !== 'w2') {
                $this->warn('survey', $rowNum, $key, "Apparence de groupe « {$appearance} » ignorée.");
            }
            if ($node['repeat']) {
                $out['repeat'] = [];
                $count = $row['repeat_count'] ?? null;
                if (is_numeric($count) && (int) $count >= 1) {
                    $out['repeat'] = ['min' => (int) $count, 'max' => (int) $count];
                } elseif (self::cellString($count) !== '') {
                    $this->warn('survey', $rowNum, $key, 'repeat_count dynamique non pris en charge : répétition libre.');
                }
            }
        }
        $props = $this->props($row, $rowNum, $key);
        $this->mergeProps($out, $props);
        if ($kind === 'stage' && ! isset($out['due_offset_days'])) {
            $out['due_offset_days'] = 0;
        }

        $out['items'] = $this->importItems($node['children'], $kind === 'section', $kind === 'group' && isset($out['repeat']), $kind);

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     * @return array<int, array<string, mixed>>
     */
    private function importItems(array $children, bool $allowGroups, bool $inRepeat, string $containerKind): array
    {
        $items = [];
        foreach ($children as $child) {
            if ($child['kind'] === 'group') {
                if (self::cellString($child['row']['dfs::stage'] ?? null) !== '') {
                    $this->warn('survey', $child['rowNum'], null, 'Étape de suivi imbriquée déplacée vers follow_up_stages.');
                    $this->stages[] = $this->importContainer($child, 'stage');

                    continue;
                }
                if ($allowGroups) {
                    $group = $this->importContainer($child, 'group');
                    if ($group['items'] !== []) {
                        $items[] = $group;
                    }

                    continue;
                }
                $this->warn('survey', $child['rowNum'], null, 'Groupe imbriqué trop profond : ses questions sont remontées dans le conteneur parent.');
                foreach ($this->importItems($child['children'], false, $inRepeat || $child['repeat'], $containerKind) as $item) {
                    $items[] = $item;
                }

                continue;
            }
            $result = $this->importQuestion($child, $inRepeat, $containerKind);
            if ($result === null) {
                continue;
            }
            [$question, $companion] = $result;
            if ($companion !== null) {
                $attached = false;
                foreach ($items as $i => $item) {
                    if (($item['key'] ?? null) === $companion['host']) {
                        $items[$i][$companion['kind']] = $companion['data'];
                        $attached = true;
                        break;
                    }
                }
                if ($attached) {
                    continue;
                }
                $this->warn('survey', $child['rowNum'], $question['key'], "Question compagnon ({$companion['kind']}) sans hôte « {$companion['host']} » : importée comme question ordinaire.");
            }
            $items[] = $question;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: array<string, mixed>, 1: array{kind: string, host: string, data: array<string, mixed>}|null}|null
     */
    private function importQuestion(array $node, bool $inRepeat, string $containerKind): ?array
    {
        $row = $node['row'];
        $rowNum = $node['rowNum'];
        $base = $node['base'];
        $name = self::cellString($row['name'] ?? null);
        if ($name === '') {
            $this->warn('survey', $rowNum, null, "Ligne « {$base} » sans name : ignorée.");

            return null;
        }
        if (in_array($base, self::SKIPPED_TYPES, true)) {
            $this->warn('survey', $rowNum, $name, "Type XLSForm « {$base} » sans équivalent DFS : question ignorée.");

            return null;
        }
        $key = $this->keyFor($name, $rowNum);
        $props = $this->props($row, $rowNum, $key);
        $appearance = strtolower(self::cellString($row['appearance'] ?? null));
        $dfsType = strtolower(self::cellString($row['dfs::type'] ?? null));
        $q = ['key' => $key];
        $companion = null;

        if (isset(self::META_TYPES[$base])) {
            $q['type'] = 'calculate';
            $q['expression'] = self::META_TYPES[$base];
        } else {
            switch ($base) {
                case 'text':
                case 'barcode':
                    $q['type'] = 'text';
                    if ($appearance === 'multiline') {
                        $q['appearance'] = 'multiline';
                    } elseif ($appearance === 'numbers') {
                        $q['format'] = 'phone';
                    } elseif ($appearance !== '') {
                        $this->warn('survey', $rowNum, $key, "Apparence « {$appearance} » ignorée.");
                    }
                    if ($base === 'barcode') {
                        $this->warn('survey', $rowNum, $key, 'barcode importé comme text.');
                    }
                    break;
                case 'integer':
                case 'range':
                    $q['type'] = 'integer';
                    if ($base === 'range') {
                        $this->rangeParameters($q, self::cellString($row['parameters'] ?? null));
                    }
                    break;
                case 'decimal':
                    $q['type'] = $dfsType === 'currency' || self::cellString($row['dfs::currency'] ?? null) !== '' ? 'currency' : 'decimal';
                    break;
                case 'date':
                case 'time':
                case 'geopoint':
                case 'audio':
                    $q['type'] = $base;
                    break;
                case 'datetime':
                    $q['type'] = 'datetime';
                    break;
                case 'image':
                case 'photo':
                    $q['type'] = $appearance === 'signature' ? 'signature' : 'photo';
                    break;
                case 'note':
                    $q['type'] = 'note';
                    break;
                case 'calculate':
                    $q['type'] = 'calculate';
                    break;
                case 'select_one':
                case 'select_multiple':
                case 'rank':
                    $q['type'] = $base;
                    if (! $this->importSelect($q, $node, $row, $rowNum, $key, $appearance, $props)) {
                        $q = ['key' => $key, 'type' => 'text'];
                        $props = array_intersect_key($props, ['tags' => 1, 'relevant' => 1, 'required' => 1, 'read_only' => 1, 'constraint' => 1, 'default' => 1]);
                    }
                    break;
                default:
                    $this->warn('survey', $rowNum, $key, "Type XLSForm inconnu « {$base} » : question ignorée.");

                    return null;
            }
        }
        $type = $q['type'];
        if (in_array($type, ['select_one', 'select_multiple', 'rank'], true) && ! isset($q['choices'])) {
            $q = ['key' => $key, 'type' => 'text'];
            $type = 'text';
        }

        // Textes.
        $label = $this->i18n($row, 'label');
        if ($label !== null) {
            $q['label'] = $label;
        } elseif ($type !== 'calculate') {
            $q['label'] = [$this->default => $name];
        }
        $hint = $this->i18n($row, 'hint');

        // Stop (note + dfs::stop).
        if ($type === 'note' && self::truthy($row['dfs::stop'] ?? null)) {
            $relevant = $this->parseExpression($row, 'relevant', $key, $rowNum, $key);
            if ($relevant === null) {
                $this->warn('survey', $rowNum, $key, 'stop sans relevant (déclencheur) : importé comme note.');
            } elseif ($inRepeat || $containerKind === 'stage') {
                $this->warn('survey', $rowNum, $key, 'stop interdit dans un groupe répété ou une étape : importé comme note.');
            } else {
                $q['type'] = 'stop';
                $q['message'] = $hint ?? $q['label'];
                $q['relevant'] = $relevant;
                $this->mergeProps($q, $props);

                return [$q, null];
            }
        }

        if ($hint !== null && ! in_array($type, ['note', 'calculate', 'stop'], true)) {
            $q['hint'] = $hint;
        }
        $relevant = $this->parseExpression($row, 'relevant', $key, $rowNum, $key);
        if ($relevant !== null) {
            $q['relevant'] = $relevant;
        }

        if ($type === 'calculate') {
            $expression = $this->parseExpression($row, 'calculation', $key, $rowNum, $key);
            if (! isset($q['expression'])) {
                if ($expression === null) {
                    $this->warn('survey', $rowNum, $key, 'calculate sans calculation exploitable : expression nulle.');
                }
                $q['expression'] = $expression;
            }
            $this->mergeProps($q, $props);

            return [$q, null];
        }
        if ($type === 'note') {
            $this->mergeProps($q, $props);

            return [$q, null];
        }

        // Champs communs des questions de saisie.
        $required = $this->parseBoolOrExpression($row, 'required', $key, $rowNum);
        if ($required !== null && $required !== false) {
            $q['required'] = $required;
        }
        $requiredMessage = $this->i18n($row, 'required_message');
        if ($requiredMessage !== null) {
            $q['required_message'] = $requiredMessage;
        }
        $constraint = $this->parseExpression($row, 'constraint', $key, $rowNum, $key);
        if ($constraint !== null) {
            $q['constraint'] = $constraint;
        }
        $constraintMessage = $this->i18n($row, 'constraint_message');
        if ($constraintMessage !== null) {
            $q['constraint_message'] = $constraintMessage;
        }
        $readOnly = $this->parseBoolOrExpression($row, 'read_only', $key, $rowNum);
        if ($readOnly !== null && $readOnly !== false) {
            $q['read_only'] = $readOnly;
        }
        $default = $this->parseDefault($row, $type, $key, $rowNum);
        if ($default !== null) {
            $q['default'] = $default;
        }
        if (self::cellString($row['calculation'] ?? null) !== '') {
            $this->warn('survey', $rowNum, $key, 'Colonne calculation ignorée sur une question qui n\'est pas un calculate.');
        }

        // Compagnons : marqueurs dfs::props, puis heuristiques Kobo.
        if (isset($props['@companion'])) {
            $kind = (string) $props['@companion'];
            $host = $this->keyMap[(string) ($props['@host'] ?? '')] ?? $this->sanitizeIdentifier((string) ($props['@host'] ?? ''), 40, false);
            $data = $props['@'.$kind] ?? null;
            if (is_array($data) && $host !== '') {
                $companion = ['kind' => $kind, 'host' => $host, 'data' => $data];
            }
        } elseif ($type === 'text' && preg_match('/^(.+)_other$/', $name, $m) === 1 && isset($this->keyMap[$m[1]])
            && is_array($relevant) && array_key_first($relevant) === 'selected' && ($relevant['selected'][0]['var'] ?? null) === $this->keyMap[$m[1]] && is_string($relevant['selected'][1] ?? null)) {
            $data = ['choice' => $relevant['selected'][1]];
            if ($label !== null) {
                $data['label'] = $label;
            }
            if ($key !== $this->keyMap[$m[1]].'_other') {
                $data['key'] = $key;
            }
            $data['required'] = $required === true;
            $companion = ['kind' => 'other', 'host' => $this->keyMap[$m[1]], 'data' => $data];
            $this->warn('survey', $rowNum, $key, "Champ « Autre : précisez » fusionné dans « {$this->keyMap[$m[1]]}.other ».");
        } elseif (in_array($type, ['select_one', 'select_multiple'], true) && preg_match('/^(.+)__codes$/', $name, $m) === 1 && isset($this->keyMap[$m[1]])) {
            $data = ['choices' => $q['choices'], 'multiple' => $type === 'select_multiple'];
            if ($label !== null) {
                $data['label'] = $label;
            }
            if ($key !== $this->keyMap[$m[1]].'__codes') {
                $data['key'] = $key;
            }
            $companion = ['kind' => 'postcode', 'host' => $this->keyMap[$m[1]], 'data' => $data];
            $this->warn('survey', $rowNum, $key, "Question de post-codage fusionnée dans « {$this->keyMap[$m[1]]}.postcode ».");
        } elseif (self::cellString($row['dfs::postcode'] ?? null) !== '' && in_array($type, ['select_one', 'select_multiple'], true)) {
            $hostName = self::cellString($row['dfs::postcode']);
            $host = $this->keyMap[$hostName] ?? null;
            if ($host !== null) {
                $data = ['choices' => $q['choices'], 'multiple' => $type === 'select_multiple'];
                if ($label !== null) {
                    $data['label'] = $label;
                }
                if ($key !== $host.'__codes') {
                    $data['key'] = $key;
                }
                $companion = ['kind' => 'postcode', 'host' => $host, 'data' => $data];
            }
        }
        if ($companion !== null) {
            $this->unreserveKey($key);
        }

        $this->mergeProps($q, $props);

        return [$q, $companion];
    }

    /**
     * Type select : liste, or_other, choice_filter. Renvoie false si la liste est inconnue.
     *
     * @param  array<string, mixed>  $q
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $props
     */
    private function importSelect(array &$q, array $node, array $row, int $rowNum, string $key, string $appearance, array &$props): bool
    {
        $listName = $node['list'] !== null ? $this->sanitizeIdentifier((string) $node['list'], 40, false) : '';
        if ($listName === '' || ! isset($this->choiceLists[$listName]) || $this->choiceLists[$listName] === []) {
            $this->warn('survey', $rowNum, $key, 'Liste de choix « '.($node['list'] ?? '').' » introuvable dans la feuille choices : question convertie en text.');

            return false;
        }
        $q['choices'] = $listName;
        if (! empty($props['@inline'])) {
            $q['choices'] = array_map(static fn (array $c): array => array_diff_key($c, ['@row' => 1]), $this->choiceLists[$listName]);
            $this->inlinedLists[$listName] = true;
        }
        if ($node['or_other'] && $q['type'] !== 'rank') {
            $hasOther = false;
            foreach ($this->choiceLists[$listName] as $choice) {
                if ($choice['name'] === 'other') {
                    $hasOther = true;
                }
            }
            if (! $hasOther) {
                $this->choiceLists[$listName][] = ['name' => 'other', 'label' => [$this->default => $this->default === 'fr' ? 'Autre' : 'Other'], '@row' => []];
            }
            $q['other'] = ['choice' => 'other'];
        }
        if ($q['type'] === 'select_one') {
            if (in_array($appearance, ['minimal', 'autocomplete', 'search'], true)) {
                $q['appearance'] = 'dropdown';
            } elseif ($appearance !== '' && preg_match('/^(columns|horizontal|quick|likert)/', $appearance) === 1) {
                $q['appearance'] = 'buttons';
            } elseif ($appearance !== '') {
                $this->warn('survey', $rowNum, $key, "Apparence « {$appearance} » ignorée.");
            }
        } elseif ($q['type'] === 'select_multiple') {
            if ($appearance !== '' && preg_match('/^(columns|horizontal)/', $appearance) === 1) {
                $q['appearance'] = 'chips';
            } elseif ($appearance !== '') {
                $this->warn('survey', $rowNum, $key, "Apparence « {$appearance} » ignorée.");
            }
        }
        $filter = self::cellString($row['choice_filter'] ?? null);
        if ($filter !== '') {
            $ok = true;
            foreach (preg_split('/\s+and\s+/i', $filter) ?: [] as $clause) {
                if (preg_match('/^\s*([A-Za-z_][\w\-]*)\s*=\s*\$\{\s*([A-Za-z_][\w\-]*)\s*\}\s*$/', $clause, $m) === 1
                    || preg_match('/^\s*\$\{\s*([A-Za-z_][\w\-]*)\s*\}\s*=\s*([A-Za-z_][\w\-]*)\s*$/', $clause, $n) === 1) {
                    $column = isset($m[1]) ? $m[1] : $n[2];
                    $parent = isset($m[2]) ? $m[2] : $n[1];
                    $this->listCascades[$listName][$column] = $parent;
                } else {
                    $ok = false;
                }
            }
            if (! $ok) {
                $this->warn('survey', $rowNum, $key, "choice_filter « {$filter} » non reconnu (attendu : colonne=\${question} [and …]) : ignoré.");
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $q
     */
    private function rangeParameters(array &$q, string $parameters): void
    {
        foreach (preg_split('/[;,\s]+/', $parameters) ?: [] as $pair) {
            if (preg_match('/^(start|end)=(-?\d+)$/', $pair, $m) === 1) {
                $q[$m[1] === 'start' ? 'min' : 'max'] = (int) $m[2];
            }
        }
    }

    /**
     * Passe finale : renommages `${x}` → clés DFS, filtres de cascade, références inconnues.
     *
     * @param  array<string, mixed>  $definition
     */
    private function finalize(array &$definition): void
    {
        $renames = array_filter($this->keyMap, static fn (string $key, string $name): bool => $key !== $name, ARRAY_FILTER_USE_BOTH);

        // Clés connues : sections, groupes, questions, étapes, compagnons.
        $known = [];
        $collect = function (array $items) use (&$collect, &$known): void {
            foreach ($items as $item) {
                if (isset($item['key'])) {
                    $known[$item['key']] = true;
                }
                if (isset($item['other'])) {
                    $known[$item['other']['key'] ?? $item['key'].'_other'] = true;
                }
                if (isset($item['postcode'])) {
                    $known[$item['postcode']['key'] ?? $item['key'].'__codes'] = true;
                }
                if (isset($item['items']) && is_array($item['items'])) {
                    $collect($item['items']);
                }
            }
        };
        $collect($definition['sections']);
        $collect($definition['follow_up_stages']);

        // Filtres de cascade.
        foreach ($definition['choice_lists'] as $listName => $choices) {
            $cascade = $this->listCascades[$listName] ?? [];
            foreach ($choices as $i => $choice) {
                $row = $choice['@row'] ?? [];
                unset($definition['choice_lists'][$listName][$i]['@row']);
                foreach ($cascade as $column => $parentName) {
                    $value = $row[$column] ?? null;
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $parent = $this->keyMap[$parentName] ?? $parentName;
                    if (! isset($known[$parent])) {
                        $this->warn('choices', null, null, "choice_filter : question parente « {$parentName} » inconnue, filtre ignoré pour la liste {$listName}.");

                        continue 2;
                    }
                    $definition['choice_lists'][$listName][$i]['filter'][$parent] = is_int($value) || is_float($value) ? LogicEvaluator::norm($value) : self::cellString($value);
                }
            }
            $definition['choice_lists'][$listName] = array_values($definition['choice_lists'][$listName]);
        }
        foreach ($this->choiceLists as $listName => $choices) {
            foreach ($choices as $i => $choice) {
                unset($this->choiceLists[$listName][$i]['@row']);
            }
        }

        // Expressions.
        $fix = function (mixed $expr, string $key, string $field) use ($renames, $known): mixed {
            if ($renames !== []) {
                $expr = self::renameVars($expr, $renames);
            }
            foreach (LogicEvaluator::referencedKeys($expr) as $ref) {
                if (! isset($known[$ref])) {
                    $this->warn('survey', null, $key, "{$field} : référence \${{$ref}} inconnue, expression ignorée.");

                    return null;
                }
            }

            return $expr;
        };
        $fields = ['relevant', 'required', 'read_only', 'constraint', 'default', 'expression'];
        $walk = function (array &$items) use (&$walk, $fix, $fields): void {
            foreach ($items as &$item) {
                foreach ($fields as $field) {
                    if (! array_key_exists($field, $item) || is_bool($item[$field]) || $item[$field] === null) {
                        continue;
                    }
                    if (is_string($item[$field]) && $field !== 'expression') {
                        continue;
                    }
                    $fixed = $fix($item[$field], (string) $item['key'], $field);
                    if ($fixed === null) {
                        if ($field === 'expression') {
                            $item[$field] = null;
                        } else {
                            unset($item[$field]);
                        }
                    } else {
                        $item[$field] = $fixed;
                    }
                }
                if (($item['type'] ?? null) === 'stop' && ! isset($item['relevant'])) {
                    $item['type'] = 'note';
                    unset($item['message']);
                }
                if (isset($item['items']) && is_array($item['items'])) {
                    $walk($item['items']);
                }
            }
            unset($item);
        };
        $walk($definition['sections']);
        $walk($definition['follow_up_stages']);
    }

    /**
     * @param  array<string, string>  $renames
     */
    private static function renameVars(mixed $expr, array $renames): mixed
    {
        if (! is_array($expr)) {
            return $expr;
        }
        if (! array_is_list($expr) && count($expr) === 1 && array_key_first($expr) === 'var' && is_string($expr['var'])) {
            $parts = explode('.', $expr['var'], 2);
            if (isset($renames[$parts[0]])) {
                $parts[0] = $renames[$parts[0]];

                return ['var' => implode('.', $parts)];
            }

            return $expr;
        }
        foreach ($expr as $k => $v) {
            $expr[$k] = self::renameVars($v, $renames);
        }

        return $expr;
    }

    // ------------------------------------------------------------------ helpers d'import

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>|null
     */
    private function i18n(array $row, string $base): ?array
    {
        $out = [];
        foreach ($row as $header => $value) {
            $parsed = $this->parseI18nHeader((string) $header);
            if ($parsed === null || $parsed['base'] !== $base) {
                continue;
            }
            $text = self::cellString($value);
            if ($text !== '') {
                $out[$parsed['lang']] = $text;
            }
        }
        if ($out === []) {
            return null;
        }
        // La langue par défaut d'abord, puis l'ordre des langues du formulaire.
        $ordered = [];
        foreach ($this->langs as $lang) {
            if (isset($out[$lang])) {
                $ordered[$lang] = $out[$lang];
            }
        }

        return $ordered + $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function props(array $row, int $rowNum, ?string $key): array
    {
        $props = $this->decodeJson(self::cellString($row[self::PROPS_COLUMN] ?? null), 'survey', $rowNum, $key);
        $props = is_array($props) ? $props : [];
        // Alias README (`bind::dm:*` → `dfs::*`) : audience, style, matrix, format, currency.
        foreach (['audience', 'style', 'format', 'currency'] as $alias) {
            $value = self::cellString($row['dfs::'.$alias] ?? null);
            if ($value !== '' && ! array_key_exists($alias, $props)) {
                $props[$alias] = $value;
            }
        }
        if (self::truthy($row['dfs::matrix'] ?? null) && ! array_key_exists('appearance', $props)) {
            $props['appearance'] = 'matrix';
        }

        return $props;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $props
     */
    private function mergeProps(array &$target, array $props): void
    {
        foreach ($props as $field => $value) {
            if (str_starts_with((string) $field, '@')) {
                continue;
            }
            if ($value === null) {
                unset($target[$field]);
            } else {
                $target[$field] = $value;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parseExpression(array $row, string $column, string $key, int $rowNum, ?string $selfKey): mixed
    {
        $text = self::cellString($row[$column] ?? null);
        if ($text === '') {
            return null;
        }
        $ast = XPathToLogic::tryParse($text, $selfKey, $error);
        if ($error !== null) {
            $this->warn('survey', $rowNum, $key, "{$column} ignoré : {$error}");

            return null;
        }

        return $ast;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parseBoolOrExpression(array $row, string $column, string $key, int $rowNum): mixed
    {
        $raw = $row[$column] ?? null;
        if (is_bool($raw)) {
            return $raw;
        }
        $text = strtolower(self::cellString($raw));
        if ($text === '') {
            return null;
        }
        if (in_array($text, ['yes', 'true', 'true()', '1', 'oui'], true)) {
            return true;
        }
        if (in_array($text, ['no', 'false', 'false()', '0', 'non'], true)) {
            return false;
        }

        return $this->parseExpression($row, $column, $key, $rowNum, $key);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parseDefault(array $row, string $type, string $key, int $rowNum): mixed
    {
        $raw = $row['default'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return LogicEvaluator::norm($raw);
        }
        if (is_bool($raw)) {
            return $raw;
        }
        $text = self::cellString($raw);
        if ($text === '') {
            return null;
        }
        if (self::looksLikeExpression($text)) {
            return $this->parseExpression($row, 'default', $key, $rowNum, $key);
        }
        if (in_array($type, ['integer', 'decimal', 'currency'], true) && is_numeric($text)) {
            return LogicEvaluator::norm(str_contains($text, '.') ? (float) $text : (int) $text);
        }

        return $text;
    }

    private function decodeJson(string $text, string $sheet, int $rowNum, ?string $key = null): mixed
    {
        if ($text === '') {
            return null;
        }
        try {
            return json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->warn($sheet, $rowNum, $key, 'Colonne dfs::* : JSON invalide ignoré ('.$e->getMessage().').');

            return null;
        }
    }

    private function keyFor(string $name, ?int $rowNum): string
    {
        if (isset($this->keyMap[$name]) && $rowNum !== null) {
            $this->warn('survey', $rowNum, $name, "Nom « {$name} » déjà utilisé : renommé.");
        }
        $base = $this->sanitizeIdentifier($name, 40, false);
        $key = $base;
        $i = 1;
        while (isset($this->usedKeys[$key])) {
            $suffix = '_'.(++$i);
            $key = substr($base, 0, 40 - strlen($suffix)).$suffix;
        }
        $this->usedKeys[$key] = true;
        if (! isset($this->keyMap[$name])) {
            $this->keyMap[$name] = $key;
        }
        if ($key !== $name && $rowNum !== null && $base === $key) {
            $this->warn('survey', $rowNum, $key, "Nom « {$name} » normalisé en clé « {$key} ».");
        }

        return $key;
    }

    private function unreserveKey(string $key): void
    {
        unset($this->usedKeys[$key]);
    }

    private function sanitizeIdentifier(string $name, int $max, bool $digitFirst): string
    {
        $ascii = Str::ascii(trim($name));
        $clean = preg_replace('/[^A-Za-z0-9_]+/', '_', $ascii) ?? '';
        $clean = trim($clean, '_');
        if ($clean === '') {
            $clean = 'x';
        }
        $first = $clean[0];
        if (! ctype_alpha($first) && ! ($digitFirst && ctype_digit($first))) {
            $clean = ($digitFirst ? 'c' : 'q').'_'.ltrim($clean, '_');
        }

        return substr($clean, 0, $max);
    }

    private static function cellString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return LogicEvaluator::toStr($value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(self::cellString($value)), ['yes', 'true', 'true()', '1', 'oui'], true);
    }

    private function warn(string $sheet, ?int $row, ?string $key, string $message): void
    {
        $this->warnings[] = ['sheet' => $sheet, 'row' => $row, 'key' => $key, 'message' => $message];
    }
}
