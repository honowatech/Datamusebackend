<?php

namespace App\Services\Survey;

use App\Enums\SubmissionStatus;
use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\TargetDatabase;
use PDO;
use Throwable;

/**
 * `GET /surveys/{id}/stats/crosstab?row=&col=` (B-10) — tableau croisé calculé **sur le SQLite
 * matérialisé** (plan § 5.3), donc sur les mêmes colonnes que l'export et que le chat SQL.
 *
 * `row` / `col` acceptent :
 *   - une clé de question (`select_one`, `select_multiple`, `rank`, numérique → classes automatiques) ;
 *   - une métadonnée : `zone`, `enqueteur_nom`, `statut`, `canal`, `version_formulaire`, `langue`.
 *
 * Les libellés viennent des tables `questions` et `choix` de la source matérialisée : aucun besoin de
 * reconstruire la disposition. `compute()` renvoie `null` tant que la datasource n'est pas prête
 * (le contrôleur répond alors `409`).
 */
class SurveyCrosstabService
{
    /** Métadonnées croisables directement (colonnes de `reponses`). */
    public const META = [
        'zone' => 'Zone',
        'enqueteur_nom' => 'Enquêteur',
        'statut' => 'Statut',
        'canal' => 'Canal',
        'version_formulaire' => 'Version du formulaire',
        'langue' => 'Langue',
    ];

    /** Nombre de classes créées pour une question numérique. */
    public const NUMERIC_BINS = 5;

    /** Séparateur des codes multiples produit par la matérialisation. */
    public const SEPARATOR = ';';

    /**
     * @param  array<string, mixed>  $filters  filtres normalisés de `SurveyStatsService::filters()`
     * @return array<string, mixed>|null null = datasource indisponible (→ 409)
     *
     * @throws \InvalidArgumentException si `row` ou `col` est inconnue
     */
    public function compute(Survey $survey, string $row, string $col, array $filters = [], ?string $lang = null): ?array
    {
        $path = $this->sqlitePath($survey);
        if ($path === null) {
            return null;
        }

        $pdo = $this->open($path);
        if ($pdo === null) {
            return null;
        }

        $rowSpec = $this->resolve($pdo, $row);
        $colSpec = $this->resolve($pdo, $col);

        [$where, $bindings] = $this->conditions($filters);
        $sql = sprintf(
            'SELECT "%s" AS r, "%s" AS c FROM reponses %s',
            $rowSpec['column'],
            $colSpec['column'],
            $where === '' ? '' : 'WHERE '.$where,
        );

        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);
        $raw = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $this->aggregate($rowSpec, $colSpec, $raw);
    }

    // ------------------------------------------------------------------ résolution des axes

    /**
     * @return array{key: string, label: string, column: string, numeric: bool, multiple: bool, order: array<string, int>, labels: array<string, string>}
     *
     * @throws \InvalidArgumentException
     */
    private function resolve(PDO $pdo, string $spec): array
    {
        $spec = trim($spec);

        if (array_key_exists($spec, self::META)) {
            return [
                'key' => $spec,
                'label' => self::META[$spec],
                'column' => $spec,
                'numeric' => false,
                'multiple' => false,
                'order' => [],
                'labels' => [],
            ];
        }

        $statement = $pdo->prepare('SELECT question_key, type, libelle, colonne FROM questions WHERE question_key = ? LIMIT 1');
        $statement->execute([$spec]);
        $question = $statement->fetch(PDO::FETCH_ASSOC);

        if ($question === false || ! is_string($question['colonne'] ?? null)) {
            throw new \InvalidArgumentException("La clé « {$spec} » n'est pas croisable dans cette source de données.");
        }

        $type = (string) $question['type'];
        $labels = [];
        $order = [];
        $choices = $pdo->prepare('SELECT code, libelle, ordre FROM choix WHERE question_key = ? ORDER BY ordre');
        $choices->execute([$spec]);
        foreach ($choices->fetchAll(PDO::FETCH_ASSOC) as $choice) {
            $labels[(string) $choice['code']] = (string) $choice['libelle'];
            $order[(string) $choice['code']] = (int) $choice['ordre'];
        }

        return [
            'key' => $spec,
            'label' => (string) $question['libelle'],
            'column' => (string) $question['colonne'],
            'numeric' => in_array($type, ['integer', 'decimal', 'currency'], true),
            'multiple' => in_array($type, ['select_multiple', 'rank'], true),
            'order' => $order,
            'labels' => $labels,
        ];
    }

    /**
     * Conditions SQL issues des filtres de statistiques (mêmes sémantiques que les autres agrégats).
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function conditions(array $filters): array
    {
        $clauses = [];
        $bindings = [];

        if (($filters['status'] ?? null) !== null) {
            $clauses[] = 'statut = ?';
            $bindings[] = $filters['status'];
        } else {
            $clauses[] = 'statut != ?';
            $bindings[] = SubmissionStatus::Rejected->value;
        }
        if (($filters['zone'] ?? null) !== null) {
            $clauses[] = 'zone = ?';
            $bindings[] = $filters['zone'];
        }
        if (($filters['enumerator_id'] ?? null) !== null) {
            $clauses[] = 'enqueteur_id = ?';
            $bindings[] = (int) $filters['enumerator_id'];
        }
        // `fin` est stocké en ISO-8601 : la comparaison porte sur la partie date (`YYYY-MM-DD`).
        if (($filters['from'] ?? null) !== null) {
            $clauses[] = 'substr(fin, 1, 10) >= ?';
            $bindings[] = substr((string) $filters['from'], 0, 10);
        }
        if (($filters['to'] ?? null) !== null) {
            $clauses[] = 'substr(fin, 1, 10) <= ?';
            $bindings[] = substr((string) $filters['to'], 0, 10);
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    // ------------------------------------------------------------------ agrégation

    /**
     * @param  array{key: string, label: string, column: string, numeric: bool, multiple: bool, order: array<string, int>, labels: array<string, string>}  $rowSpec
     * @param  array{key: string, label: string, column: string, numeric: bool, multiple: bool, order: array<string, int>, labels: array<string, string>}  $colSpec
     * @param  list<array<string, mixed>>  $raw
     * @return array<string, mixed>
     */
    private function aggregate(array $rowSpec, array $colSpec, array $raw): array
    {
        $rowBins = $rowSpec['numeric'] ? self::bins(array_column($raw, 'r')) : null;
        $colBins = $colSpec['numeric'] ? self::bins(array_column($raw, 'c')) : null;

        $cells = [];
        $rowTotals = [];
        $colTotals = [];
        $n = 0;

        foreach ($raw as $line) {
            $rowCodes = self::codes($line['r'] ?? null, $rowSpec, $rowBins);
            $colCodes = self::codes($line['c'] ?? null, $colSpec, $colBins);
            if ($rowCodes === [] || $colCodes === []) {
                continue;
            }
            $n++;
            foreach ($rowCodes as $r) {
                $rowTotals[$r] = ($rowTotals[$r] ?? 0) + 1;
                foreach ($colCodes as $c) {
                    $cells[$r][$c] = ($cells[$r][$c] ?? 0) + 1;
                }
            }
            foreach ($colCodes as $c) {
                $colTotals[$c] = ($colTotals[$c] ?? 0) + 1;
            }
        }

        $rowKeys = self::sortCodes(array_keys($rowTotals), $rowSpec, $rowTotals, $rowBins);
        $colKeys = self::sortCodes(array_keys($colTotals), $colSpec, $colTotals, $colBins);

        $matrix = [];
        foreach ($rowKeys as $r) {
            $line = [];
            foreach ($colKeys as $c) {
                $line[] = (int) ($cells[$r][$c] ?? 0);
            }
            $matrix[] = $line;
        }

        [$chi2, $p] = self::chiSquare($matrix, array_map(fn ($r) => $rowTotals[$r], $rowKeys), array_map(fn ($c) => $colTotals[$c], $colKeys), $n);

        return [
            'row' => ['key' => $rowSpec['key'], 'label' => $rowSpec['label']],
            'col' => ['key' => $colSpec['key'], 'label' => $colSpec['label']],
            'rows' => array_map(fn ($code) => [
                'code' => (string) $code,
                'label' => $rowSpec['labels'][$code] ?? (string) $code,
                'total' => (int) $rowTotals[$code],
            ], $rowKeys),
            'cols' => array_map(fn ($code) => [
                'code' => (string) $code,
                'label' => $colSpec['labels'][$code] ?? (string) $code,
                'total' => (int) $colTotals[$code],
            ], $colKeys),
            'cells' => $matrix,
            'n' => $n,
            'chi2' => $chi2,
            'p_value' => $p,
        ];
    }

    /**
     * @param  array{multiple: bool, numeric: bool}  $spec
     * @param  array{min: float, width: float}|null  $bins
     * @return list<string>
     */
    private static function codes(mixed $value, array $spec, ?array $bins): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if ($spec['numeric']) {
            return $bins === null || ! is_numeric($value) ? [] : [self::binLabel((float) $value, $bins)];
        }
        if ($spec['multiple']) {
            return array_values(array_filter(array_map('trim', explode(self::SEPARATOR, (string) $value)), fn ($c) => $c !== ''));
        }

        return [(string) $value];
    }

    /**
     * @param  list<mixed>  $values
     * @return array{min: float, width: float}|null
     */
    private static function bins(array $values): ?array
    {
        $numbers = array_values(array_map('floatval', array_filter($values, 'is_numeric')));
        if ($numbers === []) {
            return null;
        }
        $min = min($numbers);
        $max = max($numbers);
        $width = $max > $min ? ($max - $min) / self::NUMERIC_BINS : 1.0;

        return ['min' => $min, 'width' => $width];
    }

    /**
     * @param  array{min: float, width: float}  $bins
     */
    private static function binLabel(float $value, array $bins): string
    {
        $index = min(self::NUMERIC_BINS - 1, max(0, (int) floor(($value - $bins['min']) / $bins['width'])));
        $from = $bins['min'] + $index * $bins['width'];
        $to = $from + $bins['width'];

        return self::number($from).' – '.self::number($to);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  list<string>  $codes
     * @param  array{order: array<string, int>}  $spec
     * @param  array<string, int>  $totals
     * @param  array{min: float, width: float}|null  $bins
     * @return list<string>
     */
    private static function sortCodes(array $codes, array $spec, array $totals, ?array $bins): array
    {
        usort($codes, function ($a, $b) use ($spec, $totals, $bins) {
            if ($bins !== null) {
                return self::binRank((string) $a, $bins) <=> self::binRank((string) $b, $bins);
            }
            $orderA = $spec['order'][$a] ?? null;
            $orderB = $spec['order'][$b] ?? null;
            if ($orderA !== null && $orderB !== null) {
                return $orderA <=> $orderB;
            }

            return ($totals[$b] <=> $totals[$a]) ?: strcmp((string) $a, (string) $b);
        });

        return array_values($codes);
    }

    /**
     * @param  array{min: float, width: float}  $bins
     */
    private static function binRank(string $label, array $bins): float
    {
        return (float) (explode(' – ', $label)[0] ?? 0);
    }

    // ------------------------------------------------------------------ khi²

    /**
     * Khi² d'indépendance et `p_value` (approximation Wilson–Hilferty de la loi du khi²).
     *
     * @param  list<list<int>>  $matrix
     * @param  list<int>  $rowTotals
     * @param  list<int>  $colTotals
     * @return array{0: float|null, 1: float|null}
     */
    public static function chiSquare(array $matrix, array $rowTotals, array $colTotals, int $n): array
    {
        $degrees = (count($rowTotals) - 1) * (count($colTotals) - 1);
        if ($n === 0 || $degrees <= 0) {
            return [null, null];
        }

        $chi2 = 0.0;
        foreach ($matrix as $i => $line) {
            foreach ($line as $j => $observed) {
                $expected = ($rowTotals[$i] * $colTotals[$j]) / $n;
                if ($expected <= 0.0) {
                    continue;
                }
                $chi2 += (($observed - $expected) ** 2) / $expected;
            }
        }

        // Wilson–Hilferty : (χ²/k)^(1/3) ≈ N(1 − 2/(9k), 2/(9k)).
        $factor = 2 / (9 * $degrees);
        $z = ((($chi2 / $degrees) ** (1 / 3)) - (1 - $factor)) / sqrt($factor);
        $p = 0.5 * self::erfc($z / sqrt(2));

        return [round($chi2, 4), round(max(0.0, min(1.0, $p)), 6)];
    }

    /**
     * Fonction d'erreur complémentaire (PHP n'en fournit pas) — approximation de Numerical Recipes,
     * erreur relative < 1,2·10⁻⁷, largement suffisante pour une `p_value` indicative.
     */
    public static function erfc(float $x): float
    {
        $z = abs($x);
        $t = 1.0 / (1.0 + 0.5 * $z);
        $value = $t * exp(-$z * $z - 1.26551223 + $t * (1.00002368 + $t * (0.37409196 + $t * (0.09678418
            + $t * (-0.18628806 + $t * (0.27886807 + $t * (-1.13520398 + $t * (1.48851587
            + $t * (-0.82215223 + $t * 0.17087277)))))))));

        return $x >= 0 ? $value : 2.0 - $value;
    }

    // ------------------------------------------------------------------ accès au fichier

    private function sqlitePath(Survey $survey): ?string
    {
        $datasource = SurveyDatasource::query()->where('survey_id', $survey->id)->first();
        if ($datasource === null || ! $datasource->isMaterialized() || $datasource->target_database_id === null) {
            return null;
        }

        $path = TargetDatabase::query()->whereKey($datasource->target_database_id)->value('database');

        return is_string($path) && is_file($path) ? $path : null;
    }

    private function open(string $path): ?PDO
    {
        try {
            $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('reponses', 'questions', 'choix')")
                ->fetchAll(PDO::FETCH_COLUMN);

            return count($tables) === 3 ? $pdo : null;
        } catch (Throwable) {
            return null;
        }
    }
}
