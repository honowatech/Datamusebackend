<?php

namespace App\Services\Survey;

/**
 * B-11 — rendu `ReportContent` → markdown (`survey_reports.content_md`).
 *
 * `content_json` est la source de vérité : le markdown en est **dérivé** et sert à l'édition libre côté web
 * puis aux exports DOCX/PDF (`reportExportUtils.ts`, W-12). Le rendu est déterministe, sans dépendance :
 * titres `#`, listes `-`, tableaux GFM, encadrés en citation `>`.
 *
 * `sectionMarkdown()` rend une seule section : c'est ce qui permet à `RegenerateReportSectionJob` de
 * remplacer une section dans le markdown sans toucher au reste.
 */
class ReportMarkdownRenderer
{
    private const CALLOUT_PREFIX = [
        'info' => 'Note',
        'warning' => 'Attention',
        'success' => 'Point fort',
        'quote' => 'Verbatim',
    ];

    /**
     * @param  array<string, mixed>  $content
     */
    public function render(array $content): string
    {
        $out = [];

        $title = trim((string) ($content['title'] ?? ''));
        if ($title !== '') {
            $out[] = '# '.$title;
        }
        $subtitle = $content['subtitle'] ?? null;
        if (is_string($subtitle) && trim($subtitle) !== '') {
            $out[] = '*'.trim($subtitle).'*';
        }

        $figures = $this->keyFigures($content['key_figures'] ?? null);
        if ($figures !== null) {
            $out[] = $figures;
        }

        $summary = trim((string) ($content['summary'] ?? ''));
        if ($summary !== '') {
            $out[] = '## Résumé exécutif';
            $out[] = $summary;
        }

        foreach (is_array($content['sections'] ?? null) ? $content['sections'] : [] as $section) {
            if (is_array($section)) {
                $out[] = $this->sectionMarkdown($section);
            }
        }

        $recommendations = array_values(array_filter(
            is_array($content['recommendations'] ?? null) ? $content['recommendations'] : [],
            static fn ($r): bool => is_string($r) && trim($r) !== '',
        ));
        if ($recommendations !== []) {
            $out[] = '## Recommandations';
            $out[] = implode("\n", array_map(static fn (string $r): string => '- '.trim($r), $recommendations));
        }

        $appendix = $this->appendix($content['appendix'] ?? null);
        if ($appendix !== null) {
            $out[] = $appendix;
        }

        return trim(implode("\n\n", array_filter($out, static fn (?string $s): bool => $s !== null && trim($s) !== '')))."\n";
    }

    /**
     * Markdown d'une section unique (titre compris).
     *
     * @param  array<string, mixed>  $section
     */
    public function sectionMarkdown(array $section): string
    {
        $level = (int) ($section['level'] ?? 2);
        $level = max(1, min(3, $level)) + 1; // `# titre du rapport` occupe déjà le niveau 1.
        $blocks = [str_repeat('#', $level).' '.trim((string) ($section['heading'] ?? ''))];

        foreach (is_array($section['paragraphs'] ?? null) ? $section['paragraphs'] : [] as $paragraph) {
            if (is_string($paragraph) && trim($paragraph) !== '') {
                $blocks[] = trim($paragraph);
            }
        }

        $bullets = array_values(array_filter(
            is_array($section['bullets'] ?? null) ? $section['bullets'] : [],
            static fn ($b): bool => is_string($b) && trim($b) !== '',
        ));
        if ($bullets !== []) {
            $blocks[] = implode("\n", array_map(static fn (string $b): string => '- '.trim($b), $bullets));
        }

        $table = $this->table($section['table'] ?? null);
        if ($table !== null) {
            $blocks[] = $table;
        }

        $chart = $this->chart($section['chart'] ?? null);
        if ($chart !== null) {
            $blocks[] = $chart;
        }

        foreach (is_array($section['callouts'] ?? null) ? $section['callouts'] : [] as $callout) {
            if (! is_array($callout) || ! is_string($callout['text'] ?? null)) {
                continue;
            }
            $kind = is_string($callout['kind'] ?? null) ? $callout['kind'] : 'info';
            $prefix = self::CALLOUT_PREFIX[$kind] ?? self::CALLOUT_PREFIX['info'];
            $lines = array_map(
                static fn (string $line): string => '> '.$line,
                explode("\n", trim($callout['text'])),
            );
            $blocks[] = '> **'.$prefix."**\n".implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Titre markdown d'une section (`## Titre`), utilisé pour localiser la section à remplacer.
     *
     * @param  array<string, mixed>  $section
     */
    public function sectionHeadingLine(array $section): string
    {
        $level = max(1, min(3, (int) ($section['level'] ?? 2))) + 1;

        return str_repeat('#', $level).' '.trim((string) ($section['heading'] ?? ''));
    }

    /**
     * Remplace dans `$markdown` le bloc de la section `$heading` par `$replacement`. Si la section n'est
     * pas retrouvée (markdown édité à la main), le markdown est renvoyé inchangé et l'appelant re-rend
     * l'ensemble depuis `content_json`.
     */
    public function replaceSection(string $markdown, string $headingLine, string $replacement): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $needle = trim($headingLine);
        $start = null;
        $level = strlen((string) (explode(' ', $needle)[0] ?? '#'));

        foreach ($lines as $i => $line) {
            if (trim($line) === $needle) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return null;
        }

        $end = count($lines);
        for ($i = $start + 1; $i < count($lines); $i++) {
            if (preg_match('/^(#{1,6})\s/', trim($lines[$i]), $m) === 1 && strlen($m[1]) <= $level) {
                $end = $i;
                break;
            }
        }

        $before = array_slice($lines, 0, $start);
        $after = array_slice($lines, $end);

        return rtrim(implode("\n", array_merge(
            $before,
            preg_split('/\r\n|\r|\n/', trim($replacement)) ?: [],
            [''],
            $after,
        )))."\n";
    }

    // ------------------------------------------------------------------ blocs

    private function keyFigures(mixed $figures): ?string
    {
        if (! is_array($figures) || $figures === []) {
            return null;
        }

        $rows = [];
        foreach ($figures as $figure) {
            if (! is_array($figure)) {
                continue;
            }
            $arrow = match ($figure['trend'] ?? null) {
                'up' => ' ↑',
                'down' => ' ↓',
                'flat' => ' →',
                default => '',
            };
            $rows[] = '- **'.trim((string) ($figure['label'] ?? '')).'** : '.trim((string) ($figure['value'] ?? '')).$arrow;
        }

        return $rows === [] ? null : implode("\n", $rows);
    }

    private function table(mixed $table): ?string
    {
        if (! is_array($table) || ! is_array($table['columns'] ?? null) || $table['columns'] === []) {
            return null;
        }

        $columns = array_map(static fn ($c): string => self::cell($c), $table['columns']);
        $lines = [];
        if (is_string($table['title'] ?? null) && trim($table['title']) !== '') {
            $lines[] = '**'.trim($table['title']).'**';
            $lines[] = '';
        }
        $lines[] = '| '.implode(' | ', $columns).' |';
        $lines[] = '| '.implode(' | ', array_fill(0, count($columns), '---')).' |';

        foreach (is_array($table['rows'] ?? null) ? $table['rows'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cells = array_map(static fn ($c): string => self::cell($c), array_values($row));
            $cells = array_pad(array_slice($cells, 0, count($columns)), count($columns), '');
            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines);
    }

    /**
     * Un graphique est rendu comme un tableau (catégories × séries) : le markdown reste lisible et les
     * exports côté client reconstruisent le visuel depuis `content_json`.
     */
    private function chart(mixed $chart): ?string
    {
        if (! is_array($chart) || ! is_array($chart['x'] ?? null) || ! is_array($chart['series'] ?? null)) {
            return null;
        }

        $series = array_values(array_filter($chart['series'], 'is_array'));
        if ($series === []) {
            return null;
        }

        $unit = is_string($chart['unit'] ?? null) && $chart['unit'] !== '' ? ' ('.$chart['unit'].')' : '';
        $columns = array_merge([''], array_map(
            static fn (array $s): string => self::cell($s['name'] ?? '').$unit,
            $series,
        ));

        $rows = [];
        foreach ($chart['x'] as $i => $category) {
            $cells = [self::cell($category)];
            foreach ($series as $serie) {
                $data = is_array($serie['data'] ?? null) ? $serie['data'] : [];
                $cells[] = self::cell($data[$i] ?? null);
            }
            $rows[] = $cells;
        }

        return $this->table([
            'title' => is_string($chart['title'] ?? null) ? $chart['title'] : null,
            'columns' => $columns,
            'rows' => $rows,
        ]);
    }

    private function appendix(mixed $appendix): ?string
    {
        if (! is_array($appendix) || $appendix === []) {
            return null;
        }

        $blocks = ['## Annexes'];

        if (is_string($appendix['methodology'] ?? null) && trim($appendix['methodology']) !== '') {
            $blocks[] = "### Méthodologie\n\n".trim($appendix['methodology']);
        }
        if (is_string($appendix['sample'] ?? null) && trim($appendix['sample']) !== '') {
            $blocks[] = "### Échantillon\n\n".trim($appendix['sample']);
        }
        foreach (is_array($appendix['tables'] ?? null) ? $appendix['tables'] : [] as $table) {
            $rendered = $this->table($table);
            if ($rendered !== null) {
                $blocks[] = $rendered;
            }
        }
        $glossary = [];
        foreach (is_array($appendix['glossary'] ?? null) ? $appendix['glossary'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['term'] ?? null)) {
                $glossary[] = '- **'.trim($entry['term']).'** : '.trim((string) ($entry['definition'] ?? ''));
            }
        }
        if ($glossary !== []) {
            $blocks[] = "### Glossaire\n\n".implode("\n", $glossary);
        }

        return count($blocks) === 1 ? null : implode("\n\n", $blocks);
    }

    private static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'oui' : 'non';
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
        }

        return str_replace(['|', "\n"], ['\\|', ' '], (string) $value);
    }
}
