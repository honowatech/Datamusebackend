<?php

namespace App\Services\Dfs;

use InvalidArgumentException;

/**
 * Traduction d'un AST Logic DFS vers XPath XLSForm (README § 18), inverse de `XPathToLogic`.
 *
 * `convert()` renvoie `{xpath, exact, notes}` : `exact` est vrai lorsque le XPath produit, relu par
 * `XPathToLogic`, redonne structurellement l'AST d'origine (aller-retour sans perte). Sinon l'export
 * conserve l'AST JSON dans `dfs::props` et n'utilise le XPath que pour les outils tiers (Kobo).
 *
 * Non représentables fidèlement : `answered` / `empty` (approximés par `string-length() > 0` /
 * `= ''`), `null` et les listes littérales hors `in`, `date_diff` dans une unité autre que `days`,
 * `if` sans branche « sinon », les chaînes mêlant `'` et `"`.
 */
final class LogicToXPath
{
    private const BINARY = ['==' => '=', '!=' => '!=', '<' => '<', '<=' => '<=', '>' => '>', '>=' => '>=', '-' => '-', '/' => 'div', '%' => 'mod'];

    private const NARY = ['and' => 'and', 'or' => 'or', '+' => '+', '*' => '*'];

    /**
     * @param  mixed  $expr  AST Logic
     * @param  string|null  $selfKey  clé rendue par `.` (constraint / required / read_only de la question courante)
     * @return array{xpath: string, exact: bool, notes: string[]}
     */
    public static function convert(mixed $expr, ?string $selfKey = null): array
    {
        $notes = [];
        $xpath = self::node($expr, $selfKey, $notes);
        $exact = false;
        try {
            $exact = self::same(XPathToLogic::parse($xpath, $selfKey), $expr);
        } catch (InvalidArgumentException) {
            $exact = false;
        }

        return ['xpath' => $xpath, 'exact' => $exact, 'notes' => array_values(array_unique($notes))];
    }

    /**
     * @param  string[]  $notes
     */
    private static function node(mixed $expr, ?string $selfKey, array &$notes): string
    {
        if ($expr === null) {
            $notes[] = 'littéral null rendu par \'\'';

            return "''";
        }
        if (is_bool($expr)) {
            return $expr ? 'true()' : 'false()';
        }
        if (is_int($expr) || is_float($expr)) {
            return LogicEvaluator::toStr($expr);
        }
        if (is_string($expr)) {
            return self::quote($expr, $notes);
        }
        if (! is_array($expr)) {
            $notes[] = 'valeur non représentable';

            return "''";
        }
        if (array_is_list($expr)) {
            $notes[] = 'liste littérale hors `in` non représentable';

            return "''";
        }
        $op = (string) array_key_first($expr);
        $args = $expr[$op];
        if ($op === 'var') {
            return self::variable((string) $args, $selfKey, $notes);
        }
        $args = is_array($args) && array_is_list($args) ? $args : [$args];
        $sub = static function (mixed $a) use ($selfKey, &$notes): string {
            return self::wrap(self::node($a, $selfKey, $notes), $a);
        };
        $plain = static function (mixed $a) use ($selfKey, &$notes): string {
            return self::node($a, $selfKey, $notes);
        };

        if (isset(self::BINARY[$op]) && count($args) === 2) {
            return $sub($args[0]).' '.self::BINARY[$op].' '.$sub($args[1]);
        }
        if (isset(self::NARY[$op])) {
            if ($op === '+' && count($args) === 1) {
                return 'number('.self::node($args[0], $selfKey, $notes).')';
            }

            return implode(' '.self::NARY[$op].' ', array_map($sub, $args));
        }
        switch ($op) {
            case '-':
                if (is_int($args[0] ?? null) || is_float($args[0] ?? null)) {
                    $notes[] = 'négation d\'un littéral';
                }

                return '-'.$sub($args[0] ?? null);
            case '!':
                return 'not('.self::node($args[0] ?? null, $selfKey, $notes).')';
            case 'if':
                return self::ifChain($args, $selfKey, $notes);
            case 'in':
                $haystack = $args[1] ?? null;
                if (is_array($haystack) && array_is_list($haystack)) {
                    $codes = [];
                    foreach ($haystack as $code) {
                        $codes[] = is_string($code) ? $code : LogicEvaluator::toStr($code);
                    }
                    if (array_filter($codes, static fn (string $c): bool => $c === '' || preg_match('/[\s\'"]/', $c) === 1) !== []) {
                        $notes[] = 'codes de `in` non représentables dans selected()';
                    }

                    return 'selected('.self::quote(implode(' ', $codes), $notes).', '.self::node($args[0] ?? null, $selfKey, $notes).')';
                }

                return 'contains('.self::node($haystack, $selfKey, $notes).', '.self::node($args[0] ?? null, $selfKey, $notes).')';
            case 'selected':
                return 'selected('.self::node($args[0] ?? null, $selfKey, $notes).', '.self::node($args[1] ?? null, $selfKey, $notes).')';
            case 'count_selected':
                return 'count-selected('.self::node($args[0] ?? null, $selfKey, $notes).')';
            case 'answered':
                $notes[] = '`answered` approximé par string-length() > 0';

                return 'string-length('.self::node($args[0] ?? null, $selfKey, $notes).') > 0';
            case 'empty':
                $notes[] = '`empty` approximé par = \'\'';

                return $sub($args[0] ?? null)." = ''";
            case 'coalesce':
            case 'concat':
                return $op.'('.implode(', ', array_map($plain, $args)).')';
            case 'regex':
                return 'regex('.self::node($args[0] ?? null, $selfKey, $notes).', '.self::quote((string) ($args[1] ?? ''), $notes).')';
            case 'length':
                return 'string-length('.self::node($args[0] ?? null, $selfKey, $notes).')';
            case 'today':
                return 'today()';
            case 'now':
                return 'now()';
            case 'date_diff':
                $unit = $args[2] ?? 'days';
                if ($unit !== 'days') {
                    $notes[] = "date_diff en « {$unit} » approximé en jours";
                }

                return 'int(decimal-date-time('.self::node($args[0] ?? null, $selfKey, $notes).') - decimal-date-time('.self::node($args[1] ?? null, $selfKey, $notes).'))';
        }
        $notes[] = "opérateur « {$op} » sans équivalent XPath";

        return "''";
    }

    /**
     * @param  array<int, mixed>  $args
     * @param  string[]  $notes
     */
    private static function ifChain(array $args, ?string $selfKey, array &$notes): string
    {
        $n = count($args);
        if ($n < 2) {
            $notes[] = '`if` incomplet';

            return "''";
        }
        if ($n === 2) {
            $notes[] = '`if` sans branche sinon (rendu par \'\')';

            return 'if('.self::node($args[0], $selfKey, $notes).', '.self::node($args[1], $selfKey, $notes).", '')";
        }
        if ($n === 3) {
            return 'if('.self::node($args[0], $selfKey, $notes).', '.self::node($args[1], $selfKey, $notes).', '.self::node($args[2], $selfKey, $notes).')';
        }
        $rest = array_slice($args, 2);
        if (count($rest) === 1) {
            return 'if('.self::node($args[0], $selfKey, $notes).', '.self::node($args[1], $selfKey, $notes).', '.self::node($rest[0], $selfKey, $notes).')';
        }
        $notes[] = '`if` à branches multiples imbriqué';

        return 'if('.self::node($args[0], $selfKey, $notes).', '.self::node($args[1], $selfKey, $notes).', '.self::ifChain($rest, $selfKey, $notes).')';
    }

    /**
     * @param  string[]  $notes
     */
    private static function variable(string $path, ?string $selfKey, array &$notes): string
    {
        $head = explode('.', $path)[0];
        if ($path === '_repeat_index') {
            return 'position(..)';
        }
        if (str_starts_with($head, '_')) {
            $notes[] = "variable système \${{$path}} sans équivalent Kobo";
        }
        if ($selfKey !== null && $path === $selfKey) {
            return '.';
        }

        return '${'.str_replace('.', '/', $path).'}';
    }

    /**
     * @param  string[]  $notes
     */
    private static function quote(string $s, array &$notes): string
    {
        if (! str_contains($s, "'")) {
            return "'".$s."'";
        }
        if (! str_contains($s, '"')) {
            return '"'.$s.'"';
        }
        $notes[] = 'chaîne mêlant apostrophes et guillemets rendue par concat()';
        $parts = [];
        foreach (explode("'", $s) as $i => $piece) {
            if ($i > 0) {
                $parts[] = '"\'"';
            }
            if ($piece !== '') {
                $parts[] = "'".$piece."'";
            }
        }

        return 'concat('.implode(', ', $parts).')';
    }

    /**
     * Parenthèse un opérande composé (opérateur binaire ou n-aire).
     */
    private static function wrap(string $xpath, mixed $expr): string
    {
        if (! is_array($expr) || array_is_list($expr)) {
            return $xpath;
        }
        $op = (string) array_key_first($expr);
        if ($op === 'var' || $op === 'empty' || $op === 'answered') {
            return $op === 'var' ? $xpath : '('.$xpath.')';
        }
        if (isset(self::BINARY[$op]) || isset(self::NARY[$op]) || $op === '-') {
            return '('.$xpath.')';
        }

        return $xpath;
    }

    /**
     * Égalité structurelle (nombres comparés numériquement).
     */
    public static function same(mixed $a, mixed $b): bool
    {
        if (is_int($a) || is_float($a)) {
            return (is_int($b) || is_float($b)) && $a == $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (! array_key_exists($k, $b) || ! self::same($v, $b[$k])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }
}
