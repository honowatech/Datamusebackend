<?php

namespace App\Services\Survey;

/**
 * B-11 — validation stricte de `ReportContent` (`docs/openapi/survey.yaml`).
 *
 * Le contrat impose `additionalProperties: false` sur `ReportContent`, `ReportSection` et `appendix` :
 * toute propriété inventée par le modèle est une erreur. La validation est écrite à la main (le schéma
 * n'existe qu'en YAML OpenAPI ; `opis/json-schema` sert au DFS, dont le schéma est un fichier JSON).
 *
 * Retourne une liste `{path, code, message}` compatible avec l'enveloppe d'erreurs DFS ; `path` est un
 * pointeur JSON (RFC 6901) réutilisé tel quel dans le prompt de réparation.
 */
class ReportContentValidator
{
    public const CHART_TYPES = ['bar', 'line', 'pie', 'donut', 'area'];

    public const CALLOUT_KINDS = ['info', 'warning', 'success', 'quote'];

    public const TRENDS = ['up', 'down', 'flat'];

    private const ROOT_KEYS = ['title', 'subtitle', 'summary', 'key_figures', 'sections', 'recommendations', 'appendix', 'meta'];

    private const SECTION_KEYS = ['heading', 'level', 'paragraphs', 'bullets', 'table', 'chart', 'callouts'];

    private const APPENDIX_KEYS = ['methodology', 'sample', 'tables', 'glossary'];

    private const META_KEYS = ['language', 'generated_at', 'period', 'n'];

    /** @var list<array{path: string, code: string, message: string, severity: string}> */
    private array $errors = [];

    /**
     * @return list<array{path: string, code: string, message: string, severity: string}>
     */
    public function validate(mixed $content): array
    {
        $this->errors = [];

        if (! is_array($content) || $content === [] || array_is_list($content)) {
            $this->error('/', 'invalid_json', 'Le contenu du rapport doit être un objet JSON.');

            return $this->errors;
        }

        $this->extraKeys($content, self::ROOT_KEYS, '');
        $this->requiredString($content, 'title', '/title', 200);
        $this->requiredString($content, 'summary', '/summary');
        $this->optionalString($content, 'subtitle', '/subtitle', 300);

        $this->keyFigures($content['key_figures'] ?? null);
        $this->sections($content['sections'] ?? null);
        $this->recommendations($content['recommendations'] ?? null);
        $this->appendix($content['appendix'] ?? null);
        $this->meta($content['meta'] ?? null);

        return $this->errors;
    }

    public function isValid(mixed $content): bool
    {
        return $this->validate($content) === [];
    }

    // ------------------------------------------------------------------ blocs

    private function keyFigures(mixed $figures): void
    {
        if ($figures === null) {
            return;
        }
        if (! is_array($figures) || ! array_is_list($figures)) {
            $this->error('/key_figures', 'type', '`key_figures` doit être un tableau.');

            return;
        }
        foreach ($figures as $i => $figure) {
            $p = "/key_figures/{$i}";
            if (! is_array($figure) || array_is_list($figure)) {
                $this->error($p, 'type', 'Chaque chiffre clé est un objet {label, value, trend?}.');

                continue;
            }
            $this->extraKeys($figure, ['label', 'value', 'trend'], $p);
            $this->requiredString($figure, 'label', $p.'/label');
            if (! isset($figure['value']) || (! is_string($figure['value']) && ! is_numeric($figure['value']))) {
                $this->error($p.'/value', 'required', '`value` est obligatoire (chaîne).');
            }
            $trend = $figure['trend'] ?? null;
            if ($trend !== null && ! in_array($trend, self::TRENDS, true)) {
                $this->error($p.'/trend', 'enum', '`trend` doit valoir up, down, flat ou null.');
            }
        }
    }

    private function sections(mixed $sections): void
    {
        if (! is_array($sections) || ! array_is_list($sections) || $sections === []) {
            $this->error('/sections', 'required', '`sections` doit contenir au moins une section.');

            return;
        }

        foreach ($sections as $i => $section) {
            $p = "/sections/{$i}";
            if (! is_array($section) || array_is_list($section)) {
                $this->error($p, 'type', 'Chaque section est un objet.');

                continue;
            }
            $this->extraKeys($section, self::SECTION_KEYS, $p);
            $this->requiredString($section, 'heading', $p.'/heading', 200);

            $level = $section['level'] ?? null;
            if (! is_int($level) || $level < 1 || $level > 3) {
                $this->error($p.'/level', 'range', '`level` doit être un entier entre 1 et 3.');
            }

            $this->stringList($section['paragraphs'] ?? null, $p.'/paragraphs', true);
            $this->stringList($section['bullets'] ?? null, $p.'/bullets', false);
            $this->table($section['table'] ?? null, $p.'/table');
            $this->chart($section['chart'] ?? null, $p.'/chart');
            $this->callouts($section['callouts'] ?? null, $p.'/callouts');
        }
    }

    private function recommendations(mixed $recommendations): void
    {
        if ($recommendations === null) {
            $this->error('/recommendations', 'required', '`recommendations` est obligatoire (tableau de chaînes).');

            return;
        }
        $this->stringList($recommendations, '/recommendations', true);
    }

    private function appendix(mixed $appendix): void
    {
        if ($appendix === null) {
            return;
        }
        if (! is_array($appendix) || array_is_list($appendix)) {
            $this->error('/appendix', 'type', '`appendix` doit être un objet.');

            return;
        }
        $this->extraKeys($appendix, self::APPENDIX_KEYS, '/appendix');
        $this->optionalString($appendix, 'methodology', '/appendix/methodology');
        $this->optionalString($appendix, 'sample', '/appendix/sample');

        $tables = $appendix['tables'] ?? null;
        if ($tables !== null) {
            if (! is_array($tables) || ! array_is_list($tables)) {
                $this->error('/appendix/tables', 'type', '`tables` doit être un tableau.');
            } else {
                foreach ($tables as $i => $table) {
                    $this->table($table, "/appendix/tables/{$i}");
                }
            }
        }

        $glossary = $appendix['glossary'] ?? null;
        if ($glossary !== null) {
            if (! is_array($glossary) || ! array_is_list($glossary)) {
                $this->error('/appendix/glossary', 'type', '`glossary` doit être un tableau.');
            } else {
                foreach ($glossary as $i => $entry) {
                    $p = "/appendix/glossary/{$i}";
                    if (! is_array($entry) || array_is_list($entry)) {
                        $this->error($p, 'type', 'Chaque entrée de glossaire est un objet {term, definition}.');

                        continue;
                    }
                    $this->extraKeys($entry, ['term', 'definition'], $p);
                    $this->requiredString($entry, 'term', $p.'/term');
                    $this->requiredString($entry, 'definition', $p.'/definition');
                }
            }
        }
    }

    private function meta(mixed $meta): void
    {
        if ($meta === null) {
            return;
        }
        if (! is_array($meta) || array_is_list($meta)) {
            $this->error('/meta', 'type', '`meta` doit être un objet.');

            return;
        }
        $this->extraKeys($meta, self::META_KEYS, '/meta');
        foreach (['language', 'generated_at', 'period'] as $field) {
            $this->optionalString($meta, $field, '/meta/'.$field);
        }
        if (isset($meta['n']) && ! is_int($meta['n'])) {
            $this->error('/meta/n', 'type', '`n` doit être un entier.');
        }
    }

    private function table(mixed $table, string $p): void
    {
        if ($table === null) {
            return;
        }
        if (! is_array($table) || array_is_list($table)) {
            $this->error($p, 'type', 'Un tableau est un objet {title?, columns, rows}.');

            return;
        }
        $this->extraKeys($table, ['title', 'columns', 'rows'], $p);
        $this->optionalString($table, 'title', $p.'/title');

        $columns = $table['columns'] ?? null;
        if (! is_array($columns) || ! array_is_list($columns) || $columns === []) {
            $this->error($p.'/columns', 'required', '`columns` doit contenir au moins une colonne.');
            $columns = [];
        }
        $width = count($columns);

        $rows = $table['rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->error($p.'/rows', 'type', '`rows` doit être un tableau de lignes.');

            return;
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row) || ! array_is_list($row)) {
                $this->error($p."/rows/{$i}", 'type', 'Chaque ligne est un tableau de cellules.');

                continue;
            }
            if ($width > 0 && count($row) !== $width) {
                $this->error($p."/rows/{$i}", 'row_width', sprintf('La ligne a %d cellule(s) pour %d colonne(s).', count($row), $width));
            }
            foreach ($row as $j => $cell) {
                if ($cell !== null && ! is_string($cell) && ! is_numeric($cell)) {
                    $this->error($p."/rows/{$i}/{$j}", 'type', 'Une cellule est une chaîne, un nombre ou null.');
                }
            }
        }
    }

    private function chart(mixed $chart, string $p): void
    {
        if ($chart === null) {
            return;
        }
        if (! is_array($chart) || array_is_list($chart)) {
            $this->error($p, 'type', 'Un graphique est un objet {type, title?, x, series, unit?, source?}.');

            return;
        }
        $this->extraKeys($chart, ['type', 'title', 'x', 'series', 'unit', 'source'], $p);

        if (! in_array($chart['type'] ?? null, self::CHART_TYPES, true)) {
            $this->error($p.'/type', 'enum', '`type` doit valoir '.implode(', ', self::CHART_TYPES).'.');
        }
        $this->optionalString($chart, 'title', $p.'/title');
        $this->optionalString($chart, 'unit', $p.'/unit');
        $this->optionalString($chart, 'source', $p.'/source');

        $x = $chart['x'] ?? null;
        if (! is_array($x) || ! array_is_list($x)) {
            $this->error($p.'/x', 'required', '`x` doit être un tableau de catégories.');
            $x = [];
        }
        $width = count($x);

        $series = $chart['series'] ?? null;
        if (! is_array($series) || ! array_is_list($series) || $series === []) {
            $this->error($p.'/series', 'required', '`series` doit contenir au moins une série.');

            return;
        }
        foreach ($series as $i => $serie) {
            $sp = $p."/series/{$i}";
            if (! is_array($serie) || array_is_list($serie)) {
                $this->error($sp, 'type', 'Chaque série est un objet {name, data}.');

                continue;
            }
            $this->extraKeys($serie, ['name', 'data'], $sp);
            $this->requiredString($serie, 'name', $sp.'/name');
            $data = $serie['data'] ?? null;
            if (! is_array($data) || ! array_is_list($data)) {
                $this->error($sp.'/data', 'required', '`data` doit être un tableau de nombres.');

                continue;
            }
            if ($width > 0 && count($data) !== $width) {
                $this->error($sp.'/data', 'series_width', sprintf('La série a %d point(s) pour %d catégorie(s).', count($data), $width));
            }
            foreach ($data as $j => $point) {
                if ($point !== null && ! is_numeric($point)) {
                    $this->error($sp."/data/{$j}", 'type', 'Un point est un nombre ou null.');
                }
            }
        }
    }

    private function callouts(mixed $callouts, string $p): void
    {
        if ($callouts === null) {
            return;
        }
        if (! is_array($callouts) || ! array_is_list($callouts)) {
            $this->error($p, 'type', '`callouts` doit être un tableau.');

            return;
        }
        foreach ($callouts as $i => $callout) {
            $cp = $p."/{$i}";
            if (! is_array($callout) || array_is_list($callout)) {
                $this->error($cp, 'type', 'Chaque encadré est un objet {kind?, text}.');

                continue;
            }
            $this->extraKeys($callout, ['kind', 'text'], $cp);
            $this->requiredString($callout, 'text', $cp.'/text');
            $kind = $callout['kind'] ?? null;
            if ($kind !== null && ! in_array($kind, self::CALLOUT_KINDS, true)) {
                $this->error($cp.'/kind', 'enum', '`kind` doit valoir '.implode(', ', self::CALLOUT_KINDS).'.');
            }
        }
    }

    // ------------------------------------------------------------------ primitives

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $allowed
     */
    private function extraKeys(array $node, array $allowed, string $p): void
    {
        foreach (array_keys($node) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $this->error($p.'/'.$key, 'additional_property', "Propriété « {$key} » non prévue par le contrat (autorisées : ".implode(', ', $allowed).').');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function requiredString(array $node, string $field, string $p, ?int $max = null): void
    {
        $value = $node[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            $this->error($p, 'required', "« {$field} » est obligatoire (chaîne non vide).");

            return;
        }
        if ($max !== null && mb_strlen($value) > $max) {
            $this->error($p, 'max_length', "« {$field} » dépasse {$max} caractères.");
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function optionalString(array $node, string $field, string $p, ?int $max = null): void
    {
        $value = $node[$field] ?? null;
        if ($value === null) {
            return;
        }
        if (! is_string($value)) {
            $this->error($p, 'type', "« {$field} » doit être une chaîne.");

            return;
        }
        if ($max !== null && mb_strlen($value) > $max) {
            $this->error($p, 'max_length', "« {$field} » dépasse {$max} caractères.");
        }
    }

    private function stringList(mixed $value, string $p, bool $required): void
    {
        if ($value === null) {
            if ($required) {
                $this->error($p, 'required', 'Ce tableau de chaînes est obligatoire.');
            }

            return;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            $this->error($p, 'type', 'Ce champ doit être un tableau de chaînes.');

            return;
        }
        foreach ($value as $i => $item) {
            if (! is_string($item)) {
                $this->error($p."/{$i}", 'type', 'Chaque élément doit être une chaîne.');
            }
        }
    }

    private function error(string $path, string $code, string $message): void
    {
        $this->errors[] = ['path' => $path === '' ? '/' : $path, 'code' => $code, 'message' => $message, 'severity' => 'error'];
    }

    /**
     * Rend la liste d'erreurs lisible pour un prompt de réparation.
     *
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    public static function format(array $errors, int $limit = 30): string
    {
        $lines = [];
        foreach (array_slice($errors, 0, $limit) as $error) {
            $lines[] = sprintf('- %s [%s] %s', $error['path'] ?? '/', $error['code'] ?? 'error', $error['message'] ?? '');
        }
        if (count($errors) > $limit) {
            $lines[] = sprintf('- … et %d autre(s) erreur(s).', count($errors) - $limit);
        }

        return $lines === [] ? '(aucune erreur listée)' : implode("\n", $lines);
    }
}
