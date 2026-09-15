<?php

namespace App\Services\Dfs;

use InvalidArgumentException;

/**
 * Traduction d'une expression XPath XLSForm (colonnes `relevant`, `constraint`, `calculation`,
 * `required`, `read_only`, `default`) vers l'AST Logic DFS (README § 16 et § 18).
 *
 * Sous-ensemble reconnu (tokenizer + parseur récursif par précédence XPath) :
 *   - références `${cle}`, `.` (question courante, `$selfKey`), littéraux (nombre, `'texte'`, `"texte"`),
 *     `true()`, `false()` ;
 *   - `= != < <= > >=`, `and`, `or`, `not()`, `+ - * div mod`, moins unaire, parenthèses ;
 *   - fonctions : `selected()`, `count-selected()`, `if()`, `regex()`, `coalesce()`, `concat()`,
 *     `string-length()`, `count()`, `today()`, `now()`, `number()`, `string()`, `date()`,
 *     `contains()`, `position(..)`, `int(decimal-date-time(a) - decimal-date-time(b))`.
 *
 * Correspondances particulières :
 *   - `selected('a b c', ${K})` (premier argument littéral) → `{"in":[${K}, ["a","b","c"]]}` ;
 *   - `contains(botte, aiguille)` → `{"in":[aiguille, botte]}` ;
 *   - `position(..)` → `{"var":"_repeat_index"}` ; `number(x)` → `{"+":[x]}` ; `string(x)` → `{"concat":[x]}` ;
 *   - `int(decimal-date-time(a) - decimal-date-time(b))` → `{"date_diff":[a, b, "days"]}`.
 *
 * Tout ce qui n'est pas reconnu lève une `InvalidArgumentException` dont le message est destiné aux
 * `warnings` de l'import ; l'appelant omet alors l'expression (jamais un DFS invalide).
 */
final class XPathToLogic
{
    private const KEYWORDS = ['and', 'or', 'div', 'mod'];

    /** @var array<int, array{t: string, v: mixed}> */
    private array $tokens = [];

    private int $pos = 0;

    private string $source;

    private ?string $selfKey;

    private function __construct(string $source, ?string $selfKey)
    {
        $this->source = $source;
        $this->selfKey = $selfKey;
    }

    /**
     * @param  string  $xpath  expression XPath (non vide)
     * @param  string|null  $selfKey  clé substituée à `.` (question courante) ; `null` = `.` interdit
     * @return mixed AST Logic (scalaire, liste ou objet à une clé)
     *
     * @throws InvalidArgumentException expression non reconnue
     */
    public static function parse(string $xpath, ?string $selfKey = null): mixed
    {
        $parser = new self($xpath, $selfKey);
        $parser->tokenize();
        if ($parser->tokens === []) {
            throw new InvalidArgumentException('Expression vide.');
        }
        $ast = $parser->parseOr();
        if ($parser->pos < count($parser->tokens)) {
            throw $parser->error('jeton inattendu « '.$parser->describe($parser->tokens[$parser->pos]).' »');
        }
        if (self::containsMarker($ast)) {
            throw $parser->error('decimal-date-time() n\'est reconnu que dans int(decimal-date-time(a) - decimal-date-time(b))');
        }

        return $ast;
    }

    /**
     * Variante sans exception : `null` en cas d'échec, message dans `$error`.
     */
    public static function tryParse(string $xpath, ?string $selfKey, ?string &$error): mixed
    {
        $error = null;
        try {
            return self::parse($xpath, $selfKey);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();

            return null;
        }
    }

    // ------------------------------------------------------------------ tokenizer

    private function tokenize(): void
    {
        $s = $this->source;
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;

                continue;
            }
            if ($c === '$' && ($s[$i + 1] ?? '') === '{') {
                $end = strpos($s, '}', $i);
                if ($end === false) {
                    throw new InvalidArgumentException("Référence \${…} non fermée dans « {$s} ».");
                }
                $name = trim(substr($s, $i + 2, $end - $i - 2));
                if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_\-.\/]*$/', $name) !== 1) {
                    throw new InvalidArgumentException("Référence \${{$name}} invalide dans « {$s} ».");
                }
                $this->tokens[] = ['t' => 'var', 'v' => $name];
                $i = $end + 1;

                continue;
            }
            if ($c === '.' && ($s[$i + 1] ?? '') === '.') {
                $this->tokens[] = ['t' => 'parent', 'v' => '..'];
                $i += 2;

                continue;
            }
            if ($c === '.' && ! ctype_digit($s[$i + 1] ?? ' ')) {
                $this->tokens[] = ['t' => 'self', 'v' => '.'];
                $i++;

                continue;
            }
            if (ctype_digit($c) || $c === '.') {
                preg_match('/\G(\d+\.\d*|\.\d+|\d+)([eE][+-]?\d+)?/', $s, $m, 0, $i);
                $text = $m[0];
                $this->tokens[] = ['t' => 'num', 'v' => LogicEvaluator::norm(str_contains($text, '.') || str_contains(strtolower($text), 'e') ? (float) $text : (int) $text)];
                $i += strlen($text);

                continue;
            }
            if ($c === "'" || $c === '"') {
                $end = strpos($s, $c, $i + 1);
                if ($end === false) {
                    throw new InvalidArgumentException("Chaîne non fermée dans « {$s} ».");
                }
                $this->tokens[] = ['t' => 'str', 'v' => substr($s, $i + 1, $end - $i - 1)];
                $i = $end + 1;

                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*(?:-[A-Za-z][A-Za-z0-9_]*)*/', $s, $m, 0, $i);
                $word = $m[0];
                $i += strlen($word);
                if (in_array(strtolower($word), self::KEYWORDS, true)) {
                    $this->tokens[] = ['t' => 'kw', 'v' => strtolower($word)];
                } else {
                    $this->tokens[] = ['t' => 'name', 'v' => $word];
                }

                continue;
            }
            $two = substr($s, $i, 2);
            if (in_array($two, ['!=', '<=', '>='], true)) {
                $this->tokens[] = ['t' => 'op', 'v' => $two];
                $i += 2;

                continue;
            }
            if (in_array($c, ['=', '<', '>', '+', '-', '*', '(', ')', ','], true)) {
                $this->tokens[] = ['t' => 'op', 'v' => $c];
                $i++;

                continue;
            }
            throw new InvalidArgumentException("Caractère inattendu « {$c} » à la position {$i} dans « {$s} ».");
        }
    }

    // ------------------------------------------------------------------ parser

    private function parseOr(): mixed
    {
        $parts = [$this->parseAnd()];
        while ($this->isKeyword('or')) {
            $this->pos++;
            $parts[] = $this->parseAnd();
        }

        return count($parts) === 1 ? $parts[0] : ['or' => $parts];
    }

    private function parseAnd(): mixed
    {
        $parts = [$this->parseEquality()];
        while ($this->isKeyword('and')) {
            $this->pos++;
            $parts[] = $this->parseEquality();
        }

        return count($parts) === 1 ? $parts[0] : ['and' => $parts];
    }

    private function parseEquality(): mixed
    {
        $left = $this->parseRelational();
        while ($this->isOp('=') || $this->isOp('!=')) {
            $op = $this->tokens[$this->pos++]['v'];
            $right = $this->parseRelational();
            $left = [$op === '=' ? '==' : '!=' => [$left, $right]];
        }

        return $left;
    }

    private function parseRelational(): mixed
    {
        $left = $this->parseAdditive();
        while ($this->isOp('<') || $this->isOp('<=') || $this->isOp('>') || $this->isOp('>=')) {
            $op = $this->tokens[$this->pos++]['v'];
            $right = $this->parseAdditive();
            $left = [$op => [$left, $right]];
        }

        return $left;
    }

    private function parseAdditive(): mixed
    {
        $left = $this->parseMultiplicative();
        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->tokens[$this->pos++]['v'];
            $right = $this->parseMultiplicative();
            if ($op === '+' && is_array($left) && array_key_first($left) === '+' && count($left) === 1 && count($left['+']) >= 2) {
                $left['+'][] = $right;
            } else {
                $left = [$op => [$left, $right]];
            }
        }

        return $left;
    }

    private function parseMultiplicative(): mixed
    {
        $left = $this->parseUnary();
        while ($this->isOp('*') || $this->isKeyword('div') || $this->isKeyword('mod')) {
            $tok = $this->tokens[$this->pos++]['v'];
            $right = $this->parseUnary();
            if ($tok === '*' && is_array($left) && array_key_first($left) === '*' && count($left) === 1) {
                $left['*'][] = $right;
            } else {
                $left = [$tok === '*' ? '*' : ($tok === 'div' ? '/' : '%') => [$left, $right]];
            }
        }

        return $left;
    }

    private function parseUnary(): mixed
    {
        if ($this->isOp('-')) {
            $this->pos++;
            $operand = $this->parseUnary();
            if (is_int($operand) || is_float($operand)) {
                return LogicEvaluator::norm(-$operand);
            }

            return ['-' => [$operand]];
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): mixed
    {
        $tok = $this->tokens[$this->pos] ?? null;
        if ($tok === null) {
            throw $this->error('fin d\'expression inattendue');
        }
        switch ($tok['t']) {
            case 'num':
            case 'str':
                $this->pos++;

                return $tok['v'];
            case 'var':
                $this->pos++;

                return ['var' => str_replace('/', '.', $tok['v'])];
            case 'self':
                $this->pos++;
                if ($this->selfKey === null) {
                    throw $this->error('« . » (question courante) n\'est pas admis ici');
                }

                return ['var' => $this->selfKey];
            case 'op':
                if ($tok['v'] === '(') {
                    $this->pos++;
                    $inner = $this->parseOr();
                    $this->expect(')');

                    return $inner;
                }
                break;
            case 'name':
                return $this->parseFunction();
        }
        throw $this->error('jeton inattendu « '.$this->describe($tok).' »');
    }

    private function parseFunction(): mixed
    {
        $name = $this->tokens[$this->pos]['v'];
        $this->pos++;
        if (! $this->isOp('(')) {
            throw $this->error("identifiant « {$name} » inattendu (fonction sans parenthèses ou mot-clé inconnu)");
        }
        $this->pos++;
        $args = [];
        if (! $this->isOp(')')) {
            if ($name === 'position' && ($this->tokens[$this->pos]['t'] ?? '') === 'parent') {
                $this->pos++;
                $args[] = '..';
            } else {
                $args[] = $this->parseOr();
                while ($this->isOp(',')) {
                    $this->pos++;
                    $args[] = $this->parseOr();
                }
            }
        }
        $this->expect(')');
        $argc = count($args);
        $lower = strtolower($name);

        switch ($lower) {
            case 'true':
                $this->arity($name, $argc, 0, 0);

                return true;
            case 'false':
                $this->arity($name, $argc, 0, 0);

                return false;
            case 'not':
                $this->arity($name, $argc, 1, 1);

                return ['!' => [$args[0]]];
            case 'selected':
                $this->arity($name, $argc, 2, 2);
                if (is_string($args[0])) {
                    $codes = preg_split('/\s+/', trim($args[0])) ?: [];

                    return ['in' => [$args[1], array_values(array_filter($codes, static fn (string $c): bool => $c !== ''))]];
                }

                return ['selected' => [$args[0], $args[1]]];
            case 'count-selected':
                $this->arity($name, $argc, 1, 1);

                return ['count_selected' => [$args[0]]];
            case 'if':
                $this->arity($name, $argc, 2, 3);

                return ['if' => $args];
            case 'regex':
                $this->arity($name, $argc, 2, 2);
                if (! is_string($args[1])) {
                    throw $this->error('regex() attend un motif littéral');
                }

                return ['regex' => [$args[0], $args[1]]];
            case 'coalesce':
                $this->arity($name, $argc, 1, PHP_INT_MAX);

                return ['coalesce' => $args];
            case 'concat':
                $this->arity($name, $argc, 1, PHP_INT_MAX);

                return ['concat' => $args];
            case 'string-length':
            case 'count':
                $this->arity($name, $argc, 1, 1);

                return ['length' => [$args[0]]];
            case 'today':
                $this->arity($name, $argc, 0, 0);

                return ['today' => []];
            case 'now':
                $this->arity($name, $argc, 0, 0);

                return ['now' => []];
            case 'number':
                $this->arity($name, $argc, 1, 1);

                return ['+' => [$args[0]]];
            case 'string':
                $this->arity($name, $argc, 1, 1);

                return ['concat' => [$args[0]]];
            case 'date':
                $this->arity($name, $argc, 1, 1);

                return $args[0];
            case 'contains':
                $this->arity($name, $argc, 2, 2);

                return ['in' => [$args[1], $args[0]]];
            case 'position':
                if ($args !== ['..']) {
                    throw $this->error('position() n\'est reconnu que sous la forme position(..)');
                }

                return ['var' => '_repeat_index'];
            case 'int':
                $this->arity($name, $argc, 1, 1);
                $inner = $args[0];
                if (is_array($inner) && array_key_first($inner) === '-' && count($inner['-']) === 2
                    && self::isDateTimeMarker($inner['-'][0]) && self::isDateTimeMarker($inner['-'][1])) {
                    return ['date_diff' => [$inner['-'][0]['__ddt'], $inner['-'][1]['__ddt'], 'days']];
                }
                throw $this->error('int() n\'est reconnu que sous la forme int(decimal-date-time(a) - decimal-date-time(b))');
            case 'decimal-date-time':
                $this->arity($name, $argc, 1, 1);

                // Marqueur interne consommé par int(...) ; refusé s'il survit jusqu'à la racine.
                return ['__ddt' => $args[0]];
        }
        throw $this->error("fonction non prise en charge « {$name}() »");
    }

    private static function isDateTimeMarker(mixed $node): bool
    {
        return is_array($node) && array_key_first($node) === '__ddt' && count($node) === 1;
    }

    private static function containsMarker(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }
        if (self::isDateTimeMarker($node)) {
            return true;
        }
        foreach ($node as $child) {
            if (self::containsMarker($child)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ helpers

    private function arity(string $name, int $argc, int $min, int $max): void
    {
        if ($argc < $min || $argc > $max) {
            throw $this->error("{$name}() attend ".($min === $max ? $min : ($max === PHP_INT_MAX ? "au moins {$min}" : "{$min} à {$max}")).' argument(s), '.$argc.' reçu(s)');
        }
    }

    private function expect(string $op): void
    {
        if (! $this->isOp($op)) {
            $tok = $this->tokens[$this->pos] ?? null;
            throw $this->error("« {$op} » attendu".($tok === null ? ' en fin d\'expression' : ', trouvé « '.$this->describe($tok).' »'));
        }
        $this->pos++;
    }

    private function isOp(string $op): bool
    {
        $tok = $this->tokens[$this->pos] ?? null;

        return $tok !== null && $tok['t'] === 'op' && $tok['v'] === $op;
    }

    private function isKeyword(string $kw): bool
    {
        $tok = $this->tokens[$this->pos] ?? null;

        return $tok !== null && $tok['t'] === 'kw' && $tok['v'] === $kw;
    }

    /**
     * @param  array{t: string, v: mixed}  $tok
     */
    private function describe(array $tok): string
    {
        return match ($tok['t']) {
            'var' => '${'.$tok['v'].'}',
            'str' => "'".$tok['v']."'",
            default => (string) $tok['v'],
        };
    }

    private function error(string $detail): InvalidArgumentException
    {
        return new InvalidArgumentException("XPath non pris en charge « {$this->source} » : {$detail}.");
    }
}
