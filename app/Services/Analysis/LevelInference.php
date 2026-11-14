<?php

namespace App\Services\Analysis;

use PDO;

/**
 * Inférence du niveau de mesure d'une colonne SQLite par profilage (cardinalité, type, motifs de date).
 *
 * Sert au dictionnaire des jeux de données importés (Excel/CSV, où tout est stocké tel que saisi) et
 * aux colonnes `calculate` des enquêtes, dont le type n'est pas déclaré par le schéma DFS.
 *
 * Règles, dans l'ordre (sur un échantillon des `SAMPLE` premières valeurs non vides) :
 *   1. aucune valeur                                      → `text` ;
 *   2. ≥ 95 % de dates (ISO, `JJ/MM/AAAA`, `AAAA/MM/JJ`…)  → `date` ;
 *   3. 100 % de nombres (hors identifiants `0123…`) :
 *        - 2 valeurs distinctes au plus                    → `nominal` (indicatrice 0/1, oui/non codé) ;
 *        - entiers avec `ORDINAL_MAX` valeurs au plus,
 *          répétées (distinct ≤ moitié des lignes)         → `ordinal` (échelle 1–5, note, rang) ;
 *        - sinon                                           → `numeric` ;
 *   4. texte : `NOMINAL_MAX` valeurs distinctes au plus, répétées (distinct ≤ moitié des lignes, ou
 *      très petite colonne) et courtes                     → `nominal` ; sinon → `text`.
 *
 * Ce sont des heuristiques : le navigateur doit laisser l'utilisateur corriger le niveau proposé.
 */
final class LevelInference
{
    public const SAMPLE = 2000;

    public const NOMINAL_MAX = 50;

    public const ORDINAL_MAX = 7;

    private const DATE_PATTERNS = [
        '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/',
        '/^\d{4}\/\d{1,2}\/\d{1,2}( \d{1,2}:\d{2}(:\d{2})?)?$/',
        '/^\d{1,2}[\/.-]\d{1,2}[\/.-]\d{4}( \d{1,2}:\d{2}(:\d{2})?)?$/',
    ];

    /**
     * @return array{level: string, distinct: int, non_null: int, values: list<string>} `values` : modalités
     *                                                                                  (par fréquence décroissante) quand le niveau est `nominal` ou `ordinal`, sinon vide
     */
    public static function infer(PDO $pdo, string $table, string $column): array
    {
        $t = self::quote($table);
        $c = self::quote($column);
        $filter = "$c IS NOT NULL AND TRIM(CAST($c AS TEXT)) != ''";

        $counts = $pdo->query("SELECT COUNT(*) AS n, COUNT(DISTINCT $c) AS d FROM $t WHERE $filter")->fetch(PDO::FETCH_ASSOC);
        $nonNull = (int) ($counts['n'] ?? 0);
        $distinct = (int) ($counts['d'] ?? 0);

        $sample = $pdo->query("SELECT $c FROM $t WHERE $filter LIMIT ".self::SAMPLE)->fetchAll(PDO::FETCH_COLUMN);
        $level = self::classify(array_map(fn ($v) => trim((string) $v), $sample), $distinct, $nonNull);

        $values = [];
        if (in_array($level, ['nominal', 'ordinal'], true)) {
            $rows = $pdo->query("SELECT CAST($c AS TEXT) AS v, COUNT(*) AS n FROM $t WHERE $filter GROUP BY $c ORDER BY n DESC, v LIMIT ".self::NOMINAL_MAX)->fetchAll(PDO::FETCH_ASSOC);
            $values = array_map(fn ($r) => (string) $r['v'], $rows);
            if ($level === 'ordinal') {
                usort($values, fn ($a, $b) => (float) $a <=> (float) $b);
            }
        }

        return ['level' => $level, 'distinct' => $distinct, 'non_null' => $nonNull, 'values' => $values];
    }

    /**
     * Classement pur (sans base) d'un échantillon de valeurs non vides.
     *
     * @param  list<string>  $sample
     */
    public static function classify(array $sample, int $distinct, int $nonNull): string
    {
        $count = count($sample);
        if ($count === 0) {
            return 'text';
        }

        $dates = 0;
        $numbers = 0;
        $integers = 0;
        $length = 0;
        foreach ($sample as $value) {
            $length += mb_strlen($value);
            if (self::isDate($value)) {
                $dates++;
            } elseif (self::isNumber($value)) {
                $numbers++;
                if (preg_match('/^-?\d+$/', $value) === 1) {
                    $integers++;
                }
            }
        }

        if ($dates >= 0.95 * $count) {
            return 'date';
        }
        if ($numbers === $count) {
            if ($distinct <= 2) {
                return 'nominal';
            }

            return $integers === $count && $distinct <= self::ORDINAL_MAX && $distinct * 2 <= $nonNull ? 'ordinal' : 'numeric';
        }

        $repeated = $nonNull <= 20 ? $distinct < $nonNull || $distinct <= 2 : $distinct * 2 <= $nonNull;

        return $distinct <= self::NOMINAL_MAX && $repeated && $length / $count <= 60 ? 'nominal' : 'text';
    }

    public static function isDate(string $value): bool
    {
        foreach (self::DATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Nombre « mesurable » : numérique, mais pas un identifiant à zéros initiaux (`0612…`, `007`). */
    public static function isNumber(string $value): bool
    {
        return is_numeric($value) && preg_match('/^[+-]?0\d/', $value) !== 1;
    }

    public static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
