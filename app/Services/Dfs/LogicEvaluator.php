<?php

namespace App\Services\Dfs;

use stdClass;

/**
 * Évaluateur du langage « Logic » de DFS v1 (docs/dfs/README.md § 16).
 *
 * Port fidèle de l'évaluateur de référence `scripts/dfs-logic-ref.mjs` : mêmes règles de
 * coercition (`toNumber`, `truthy`, `isEmpty`, `toStr`), même égalité `==` (§ 16.4), mêmes
 * dates (`today`, `now`, `date_diff`) et mêmes diagnostics.
 *
 * Contrat :
 *   evaluate(expr, answers, context, options) -> EvalResult { value, diagnostics[] }
 *
 *   - expr     : expression Logic (littéral, liste littérale ou objet `{ op: [args] }`).
 *                Les objets sont acceptés sous forme de tableau associatif ou de stdClass.
 *   - answers  : réponses `{ clé: valeur }` ; une clé absente vaut null.
 *   - context  : variables système (`_status`, `_lang`, …) et horloge de test facultative
 *                (`now` : ISO-8601 avec décalage, `today` : YYYY-MM-DD).
 *   - options  : `knownKeys` (ou `known_keys`) : liste des clés du formulaire ; une clé
 *                absente de cette liste (et des réponses) produit le diagnostic `unknown_var`.
 *
 * Une expression ne lève jamais d'exception : tout cas non défini renvoie null et enregistre
 * un diagnostic (§ 16.6). L'horloge machine n'est lue que si le contexte ne la fixe pas.
 *
 * Représentation des valeurs JSON en PHP : les listes sont des tableaux `array_is_list`, les
 * objets des tableaux associatifs ou des stdClass. Un tableau vide est considéré comme une
 * liste vide (`{}` et `[]` sont indiscernables après `json_decode(…, true)` ; les deux sont
 * « vides » et « faux », seule `==` les distingue en théorie).
 */
final class LogicEvaluator
{
    public const NUMERIC_RE = '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/';

    private const DATE_RE = '/^(\d{4})-(\d{2})-(\d{2})$/';

    private const DATETIME_RE = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,9}))?)?(Z|[+-]\d{2}:?\d{2})?$/';

    public const SYSTEM_VARS = ['_status', '_start_time', '_end_time', '_enumerator', '_device', '_zone', '_lang', '_repeat_index', '_seq'];

    private const MS_PER_UNIT = ['seconds' => 1000, 'minutes' => 60000, 'hours' => 3600000, 'days' => 86400000];

    private const MAX_DEPTH = 64;

    /** Arité [min, max] de chaque opérateur (hors `var`, qui prend une chaîne). */
    public const ARITY = [
        '==' => [2, 2], '!=' => [2, 2], '<' => [2, 2], '<=' => [2, 2], '>' => [2, 2], '>=' => [2, 2],
        'and' => [1, PHP_INT_MAX], 'or' => [1, PHP_INT_MAX], '!' => [1, 1], 'if' => [2, PHP_INT_MAX],
        'in' => [2, 2], 'selected' => [2, 2], 'count_selected' => [1, 1], 'answered' => [1, 1], 'empty' => [1, 1],
        'coalesce' => [1, PHP_INT_MAX], 'concat' => [1, PHP_INT_MAX],
        '+' => [1, PHP_INT_MAX], '-' => [1, 2], '*' => [2, PHP_INT_MAX], '/' => [2, 2], '%' => [2, 2],
        'regex' => [2, 2], 'length' => [1, 1], 'today' => [0, 0], 'now' => [0, 0], 'date_diff' => [3, 3],
    ];

    /** @var array<string, mixed> */
    private array $answers = [];

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<string, true>|null */
    private ?array $knownKeys = null;

    /** @var array<int, array{path: string, code: string, message: string}> */
    private array $diagnostics = [];

    /**
     * Point d'entrée. Voir l'en-tête de la classe.
     *
     * @param  array<string, mixed>|stdClass  $answers
     * @param  array<string, mixed>|stdClass  $context
     * @param  array<string, mixed>  $options
     */
    public function evaluate(mixed $expr, array|stdClass $answers = [], array|stdClass $context = [], array $options = []): EvalResult
    {
        $this->answers = self::toAssoc($answers);
        $this->context = self::toAssoc($context);
        $known = $options['knownKeys'] ?? $options['known_keys'] ?? null;
        $this->knownKeys = is_array($known) ? array_fill_keys(array_map('strval', $known), true) : null;
        $this->diagnostics = [];

        $value = $this->evalNode($expr, '', 0);

        return new EvalResult($value, $this->diagnostics);
    }

    // ---------------------------------------------------------------------------
    // Types de valeurs (§ 16.2)
    // ---------------------------------------------------------------------------

    /** Liste JSON (tableau séquentiel ; un tableau vide est une liste). */
    public static function isList(mixed $v): bool
    {
        return is_array($v) && array_is_list($v);
    }

    /** Objet JSON (stdClass ou tableau associatif non vide). */
    public static function isObject(mixed $v): bool
    {
        return $v instanceof stdClass || (is_array($v) && ! array_is_list($v));
    }

    /**
     * Convertit un nombre ou une chaîne numérique en nombre ; sinon null.
     */
    public static function toNumber(mixed $v): int|float|null
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? $v : null;
        }
        if (is_string($v)) {
            $s = trim($v);
            if (preg_match(self::NUMERIC_RE, $s) !== 1) {
                return null;
            }
            if (preg_match('/^[+-]?\d+$/', $s) === 1) {
                $i = (int) $s;
                if ((string) $i === ltrim($s, '+') || (string) $i === $s) {
                    return $i;
                }
                $f = (float) $s;

                return is_finite($f) ? self::norm($f) : null;
            }
            $f = (float) $s;

            return is_finite($f) ? self::norm($f) : null;
        }

        return null;
    }

    /** Valeur de vérité : null, false, 0, "", [], {} → faux ; tout le reste → vrai. */
    public static function truthy(mixed $v): bool
    {
        if ($v === null || $v === false || $v === '') {
            return false;
        }
        if (is_int($v) || is_float($v)) {
            return $v != 0;
        }
        if (is_array($v)) {
            return $v !== [];
        }
        if ($v instanceof stdClass) {
            return (array) $v !== [];
        }

        return true;
    }

    /** Vide au sens de answered/empty : null, "", [], {} (0 et false sont des réponses). */
    public static function isEmpty(mixed $v): bool
    {
        if ($v === null || $v === '') {
            return true;
        }
        if (is_array($v)) {
            return $v === [];
        }
        if ($v instanceof stdClass) {
            return (array) $v === [];
        }

        return false;
    }

    /** Conversion en chaîne de `concat` (§ 16.3). */
    public static function toStr(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return self::numberToString($v);
        }
        if (is_string($v)) {
            return $v;
        }
        if (self::isList($v)) {
            return implode(',', array_map([self::class, 'toStr'], $v));
        }

        return '';
    }

    /** Notation JSON canonique d'un nombre réel, alignée sur `String(n)` de JavaScript. */
    public static function numberToString(float $f): string
    {
        if (is_nan($f)) {
            return 'NaN';
        }
        if (is_infinite($f)) {
            return $f > 0 ? 'Infinity' : '-Infinity';
        }
        if ($f == floor($f) && abs($f) < 1e21) {
            return sprintf('%.0f', $f === 0.0 ? 0.0 : $f);
        }
        $s = json_encode($f);
        if (! is_string($s)) {
            return (string) $f;
        }

        // PHP écrit `1.0e+25`, JavaScript `1e+25`.
        return preg_replace('/\.0+e/', 'e', $s) ?? $s;
    }

    /** Égalité `==` (§ 16.4), évaluée dans l'ordre des règles. */
    public static function equals(mixed $a, mixed $b): bool
    {
        $ea = $a === null || $a === '';
        $eb = $b === null || $b === '';
        if ($ea || $eb) {
            return $ea && $eb; // 1. vides scalaires
        }
        if (is_bool($a) || is_bool($b)) {
            return $a === $b; // 2. booléens
        }
        $na = self::toNumber($a);
        $nb = self::toNumber($b);
        if ($na !== null && $nb !== null) {
            return $na == $nb; // 3. numérique
        }
        if (is_string($a) && is_string($b)) {
            return $a === $b; // 4. chaînes
        }
        if (self::isList($a) && self::isList($b)) { // 5. listes
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $i => $x) {
                if (! self::equals($x, $b[$i])) {
                    return false;
                }
            }

            return true;
        }

        return false; // 6. objets et mixtes
    }

    /**
     * -0 n'existe pas en JSON et un réel entier est stocké comme entier : normalise le résultat
     * des opérations arithmétiques.
     */
    public static function norm(int|float $n): int|float
    {
        if (is_float($n)) {
            if ($n == 0.0) {
                return 0;
            }
            if (is_finite($n) && $n == floor($n) && abs($n) < 9007199254740992.0) {
                return (int) $n;
            }
        }

        return $n;
    }

    // ---------------------------------------------------------------------------
    // Dates (today / now / date_diff)
    // ---------------------------------------------------------------------------

    private static function daysFromCivil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /** @return array{0: int, 1: int, 2: int} [année, mois, jour] */
    private static function civilFromDays(int $z): array
    {
        $z += 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp + ($mp < 10 ? 3 : -9);

        return [$y + ($m <= 2 ? 1 : 0), $m, $d];
    }

    private static function daysInMonth(int $y, int $m): int
    {
        if ($m === 2) {
            $leap = ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0;

            return $leap ? 29 : 28;
        }

        return in_array($m, [4, 6, 9, 11], true) ? 30 : 31;
    }

    /**
     * Parse `YYYY-MM-DD` (minuit local, `offsetMin`) ou ISO-8601 ; retourne `{ms, offsetMin}` ou null.
     *
     * @return array{ms: int, offsetMin: int}|null
     */
    public static function parseTemporal(mixed $s, int $offsetMin): ?array
    {
        if (! is_string($s)) {
            return null;
        }
        $h = 0;
        $mi = 0;
        $sec = 0;
        $fracMs = 0;
        $off = $offsetMin;
        if (preg_match(self::DATE_RE, $s, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match(self::DATETIME_RE, $s, $m) === 1) {
            [$y, $mo, $d, $h, $mi] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]];
            $sec = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
            $fracMs = isset($m[7]) && $m[7] !== '' ? (int) round(((float) ('0.'.$m[7])) * 1000) : 0;
            if (isset($m[8]) && $m[8] !== '' && $m[8] !== 'Z') {
                $sign = $m[8][0] === '-' ? -1 : 1;
                $digits = str_replace(':', '', substr($m[8], 1));
                $off = $sign * ((int) substr($digits, 0, 2) * 60 + (int) substr($digits, 2));
            } elseif (isset($m[8]) && $m[8] === 'Z') {
                $off = 0;
            }
        } else {
            return null;
        }
        if ($mo < 1 || $mo > 12 || $d < 1 || $h > 23 || $mi > 59 || $sec > 59) {
            return null;
        }
        if ($d > self::daysInMonth($y, $mo)) {
            return null; // ex. 30 février
        }
        $days = self::daysFromCivil($y, $mo, $d);
        $utc = $days * 86400000 + $h * 3600000 + $mi * 60000 + $sec * 1000;

        return ['ms' => $utc + $fracMs - $off * 60000, 'offsetMin' => $off];
    }

    /**
     * Décomposition calendaire d'un instant dans le décalage donné.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int}
     */
    private static function calendarParts(int $ms, int $offsetMin): array
    {
        $t = $ms + $offsetMin * 60000;
        $days = intdiv($t, 86400000);
        $rem = $t - $days * 86400000;
        if ($rem < 0) {
            $days--;
            $rem += 86400000;
        }
        [$y, $mo, $d] = self::civilFromDays($days);
        $h = intdiv($rem, 3600000);
        $rem -= $h * 3600000;
        $mi = intdiv($rem, 60000);
        $rem -= $mi * 60000;
        $s = intdiv($rem, 1000);
        $msPart = $rem - $s * 1000;

        return [$y, $mo, $d, $h, $mi, $s, $msPart];
    }

    /**
     * Horloge : `{nowMs, offsetMin}` fixée par `context.now`, sinon horloge machine.
     *
     * @return array{nowMs: int, offsetMin: int}
     */
    private function clock(): array
    {
        $fixed = isset($this->context['now']) && is_string($this->context['now'])
            ? self::parseTemporal($this->context['now'], 0)
            : null;
        if ($fixed !== null) {
            return ['nowMs' => $fixed['ms'], 'offsetMin' => $fixed['offsetMin']];
        }
        $now = new \DateTimeImmutable;

        return [
            'nowMs' => $now->getTimestamp() * 1000 + intdiv((int) $now->format('u'), 1000),
            'offsetMin' => intdiv($now->getOffset(), 60),
        ];
    }

    private static function pad(int $n, int $w = 2): string
    {
        return str_pad((string) abs($n), $w, '0', STR_PAD_LEFT);
    }

    private static function formatOffset(int $min): string
    {
        $sign = $min < 0 ? '-' : '+';

        return $sign.self::pad(intdiv(abs($min), 60)).':'.self::pad(abs($min) % 60);
    }

    public static function formatToday(int $ms, int $offsetMin): string
    {
        [$y, $mo, $d] = self::calendarParts($ms, $offsetMin);

        return self::pad($y, 4).'-'.self::pad($mo).'-'.self::pad($d);
    }

    public static function formatNow(int $ms, int $offsetMin): string
    {
        [$y, $mo, $d, $h, $mi, $s] = self::calendarParts($ms, $offsetMin);

        return self::pad($y, 4).'-'.self::pad($mo).'-'.self::pad($d).'T'.self::pad($h).':'.self::pad($mi).':'.self::pad($s).self::formatOffset($offsetMin);
    }

    /** Instant courant formaté (ISO-8601 avec décalage), selon l'horloge du contexte donné. */
    public static function nowString(array $context = []): string
    {
        $ev = new self;
        $ev->context = $context;
        $c = $ev->clock();

        return self::formatNow($c['nowMs'], $c['offsetMin']);
    }

    /** Date locale du jour, selon l'horloge du contexte donné. */
    public static function todayString(array $context = []): string
    {
        if (isset($context['today']) && is_string($context['today']) && preg_match(self::DATE_RE, $context['today']) === 1) {
            return $context['today'];
        }
        $ev = new self;
        $ev->context = $context;
        $c = $ev->clock();

        return self::formatToday($c['nowMs'], $c['offsetMin']);
    }

    /**
     * `date_diff(a, b, unit)` sur deux instants déjà parsés : a − b tronqué vers zéro.
     *
     * @param  array{ms: int, offsetMin: int}  $a
     * @param  array{ms: int, offsetMin: int}  $b
     */
    private static function dateDiff(array $a, array $b, string $unit, int $offsetMin): int
    {
        if (isset(self::MS_PER_UNIT[$unit])) {
            $q = ($a['ms'] - $b['ms']) / self::MS_PER_UNIT[$unit];

            return (int) $q; // troncature vers zéro
        }
        // Unités calendaires : mois entiers écoulés, corrigés par la position dans le mois.
        $pa = self::calendarParts($a['ms'], $offsetMin);
        $pb = self::calendarParts($b['ms'], $offsetMin);
        $months = ($pa[0] - $pb[0]) * 12 + ($pa[1] - $pb[1]);
        $cmp = 0;
        for ($i = 2; $i < 7 && $cmp === 0; $i++) {
            $cmp = $pa[$i] <=> $pb[$i];
        }
        if ($months > 0 && $cmp < 0) {
            $months--;
        }
        if ($months < 0 && $cmp > 0) {
            $months++;
        }

        return $unit === 'years' ? (int) ($months / 12) : $months;
    }

    // ---------------------------------------------------------------------------
    // Évaluation
    // ---------------------------------------------------------------------------

    private function diag(string $code, string $path, string $message): mixed
    {
        $this->diagnostics[] = ['path' => $path === '' ? '/' : $path, 'code' => $code, 'message' => $message];

        return null;
    }

    /** Chemin JSON Pointer (RFC 6901) du nœud, pour les diagnostics. */
    private static function seg(string $path, string|int $key): string
    {
        return $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
    }

    /** @return array<string, mixed> */
    private static function toAssoc(mixed $v): array
    {
        if ($v instanceof stdClass) {
            return (array) $v;
        }

        return is_array($v) ? $v : [];
    }

    private function evalNode(mixed $node, string $path, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return $this->diag('max_depth', $path, "Profondeur d'expression supérieure à ".self::MAX_DEPTH);
        }
        if ($node === null) {
            return null;
        }
        if ($node instanceof stdClass) {
            $node = (array) $node;
            if ($node === []) {
                return $this->diag('bad_expr', $path, 'Un nœud opérateur a exactement une clé');
            }
        }
        if (! is_array($node)) {
            return $node; // littéral scalaire
        }
        if (array_is_list($node)) { // liste littérale (un tableau vide est une liste vide)
            $out = [];
            foreach ($node as $i => $e) {
                $out[] = $this->evalNode($e, self::seg($path, $i), $depth + 1);
            }

            return $out;
        }

        if (count($node) !== 1) {
            return $this->diag('bad_expr', $path, 'Un nœud opérateur a exactement une clé');
        }
        $op = (string) array_key_first($node);
        $args = $node[$op];
        $p = self::seg($path, $op);

        if ($op === 'var') {
            if (! is_string($args)) {
                return $this->diag('type_error', $p, '`var` attend une chaîne');
            }

            return $this->lookupVar($args, $p);
        }
        if (! array_key_exists($op, self::ARITY)) {
            return $this->diag('unknown_op', $p, "Opérateur inconnu : {$op}");
        }
        if (! self::isList($args)) {
            return $this->diag('bad_arity', $p, "Les arguments de `{$op}` doivent être un tableau");
        }
        [$min, $max] = self::ARITY[$op];
        $n = count($args);
        if ($n < $min || $n > $max) {
            $expected = $min === $max ? (string) $min : $min.' à '.($max === PHP_INT_MAX ? '∞' : $max);

            return $this->diag('bad_arity', $p, "`{$op}` attend {$expected} argument(s), reçu {$n}");
        }

        $ev = fn (int $i): mixed => $this->evalNode($args[$i], self::seg($p, $i), $depth + 1);

        // Opérateurs paresseux (court-circuit) : seuls les arguments nécessaires sont évalués.
        switch ($op) {
            case 'and':
                for ($i = 0; $i < $n; $i++) {
                    if (! self::truthy($ev($i))) {
                        return false;
                    }
                }

                return true;
            case 'or':
                for ($i = 0; $i < $n; $i++) {
                    if (self::truthy($ev($i))) {
                        return true;
                    }
                }

                return false;
            case 'if':
                $i = 0;
                for (; $i + 1 < $n; $i += 2) {
                    if (self::truthy($ev($i))) {
                        return $ev($i + 1);
                    }
                }

                return $i < $n ? $ev($i) : null; // branche `sinon` ou null
            default:
                break;
        }

        // Opérateurs stricts : tous les arguments sont évalués d'abord.
        $a = [];
        for ($i = 0; $i < $n; $i++) {
            $a[] = $ev($i);
        }

        return $this->applyStrict($op, $a, $args, $p);
    }

    /** Lecture d'une réponse ou d'une variable système, avec chemin pointé. */
    private function lookupVar(string $ref, string $p): mixed
    {
        $segments = explode('.', $ref);
        $head = $segments[0];
        if (str_starts_with($head, '_')) {
            // Variables système ; toute autre variable préfixée `_` vaut null (§ 16.5).
            $cur = in_array($head, self::SYSTEM_VARS, true) ? ($this->context[$head] ?? null) : null;
        } elseif (array_key_exists($head, $this->answers)) {
            $cur = $this->answers[$head];
        } else {
            if ($this->knownKeys !== null && ! isset($this->knownKeys[$head])) {
                $this->diag('unknown_var', $p, "Clé inconnue : {$head}");
            }

            return null;
        }
        $count = count($segments);
        for ($i = 1; $i < $count; $i++) {
            $s = $segments[$i];
            if ($cur instanceof stdClass) {
                $cur = (array) $cur;
            }
            if (is_array($cur) && array_is_list($cur) && preg_match('/^\d+$/', $s) === 1) {
                $cur = $cur[(int) $s] ?? null;
                if ($cur === null) {
                    return null;
                }
            } elseif (is_array($cur) && ! array_is_list($cur) && array_key_exists($s, $cur)) {
                $cur = $cur[$s];
            } else {
                return null; // chemin cassé
            }
        }

        return $cur;
    }

    /**
     * Réduction arithmétique : null si un argument est null (silencieux) ou non numérique (diagnostic).
     *
     * @param  array<int, mixed>  $a
     * @return array<int, int|float>|null
     */
    private function numbers(array $a, string $p): ?array
    {
        $out = [];
        foreach ($a as $i => $v) {
            if ($v === null) {
                return null;
            }
            $n = self::toNumber($v);
            if ($n === null) {
                $this->diag('type_error', self::seg($p, $i), 'Argument non numérique');

                return null;
            }
            $out[] = $n;
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $a  arguments évalués
     * @param  array<int, mixed>  $rawArgs  arguments bruts (motif regex, unité de date)
     */
    private function applyStrict(string $op, array $a, array $rawArgs, string $p): mixed
    {
        switch ($op) {
            case '==':
                return self::equals($a[0], $a[1]);
            case '!=':
                return ! self::equals($a[0], $a[1]);
            case '<':
            case '<=':
            case '>':
            case '>=':
                $x = self::toNumber($a[0]);
                $y = self::toNumber($a[1]);
                if ($x === null || $y === null) {
                    return false;
                }

                return match ($op) {
                    '<' => $x < $y,
                    '<=' => $x <= $y,
                    '>' => $x > $y,
                    default => $x >= $y,
                };
            case '!':
                return ! self::truthy($a[0]);
            case 'in':
                [$needle, $hay] = $a;
                // Aiguille vide (null ou "") : jamais contenue (évite le faux positif de la sous-chaîne vide).
                if (self::isEmpty($needle) && ! self::isList($needle)) {
                    return false;
                }
                if (self::isList($hay)) {
                    foreach ($hay as $e) {
                        if (self::equals($e, $needle)) {
                            return true;
                        }
                    }

                    return false;
                }
                if (is_string($hay)) {
                    return str_contains($hay, self::toStr($needle));
                }

                return false;
            case 'selected':
                [$resp, $code] = $a;
                if (self::isList($resp)) {
                    foreach ($resp as $e) {
                        if (self::equals($e, $code)) {
                            return true;
                        }
                    }

                    return false;
                }
                if (is_string($resp)) {
                    return self::equals($resp, $code);
                }

                return false;
            case 'count_selected':
                if (self::isList($a[0])) {
                    return count($a[0]);
                }

                return is_string($a[0]) && $a[0] !== '' ? 1 : 0;
            case 'answered':
                return ! self::isEmpty($a[0]);
            case 'empty':
                return self::isEmpty($a[0]);
            case 'coalesce':
                foreach ($a as $v) {
                    if ($v !== null && $v !== '') {
                        return $v;
                    }
                }

                return null;
            case 'concat':
                return implode('', array_map([self::class, 'toStr'], $a));
            case '+':
                $n = $this->numbers($a, $p);
                if ($n === null) {
                    return null;
                }
                $sum = 0;
                foreach ($n as $x) {
                    $sum += $x;
                }

                return self::norm($sum);
            case '-':
                $n = $this->numbers($a, $p);
                if ($n === null) {
                    return null;
                }

                return self::norm(count($n) === 1 ? -$n[0] : $n[0] - $n[1]);
            case '*':
                $n = $this->numbers($a, $p);
                if ($n === null) {
                    return null;
                }
                $prod = 1;
                foreach ($n as $x) {
                    $prod *= $x;
                }

                return self::norm($prod);
            case '/':
            case '%':
                $n = $this->numbers($a, $p);
                if ($n === null) {
                    return null;
                }
                if ($n[1] == 0) {
                    return $this->diag('division_by_zero', $p, 'Division par zéro');
                }

                return self::norm($op === '/' ? $n[0] / $n[1] : fmod((float) $n[0], (float) $n[1]));
            case 'regex':
                $pattern = $rawArgs[1] ?? null;
                if (! is_string($pattern)) {
                    return $this->diag('type_error', self::seg($p, 1), 'Le motif doit être une chaîne littérale');
                }
                if ($a[0] === null) {
                    return false;
                }
                $subject = is_string($a[0]) ? $a[0] : self::toStr($a[0]);
                $re = '#'.str_replace('#', '\#', $pattern).'#u';
                $compileError = null;
                set_error_handler(static function (int $errno, string $message) use (&$compileError): bool {
                    $compileError = $message;

                    return true;
                });
                try {
                    $result = preg_match($re, $subject);
                } finally {
                    restore_error_handler();
                }
                if ($result === false) {
                    if (preg_last_error() === PREG_BAD_UTF8_ERROR) {
                        return false;
                    }

                    return $this->diag('invalid_regex', self::seg($p, 1), 'Motif invalide : '.($compileError ?? preg_last_error_msg()));
                }

                return $result === 1;
            case 'length':
                if ($a[0] === null) {
                    return 0;
                }
                if (is_string($a[0])) {
                    return mb_strlen($a[0], 'UTF-8'); // points de code Unicode
                }
                if (self::isList($a[0])) {
                    return count($a[0]);
                }

                return $this->diag('type_error', self::seg($p, 0), '`length` attend une chaîne ou une liste');
            case 'today':
                if (isset($this->context['today']) && is_string($this->context['today']) && preg_match(self::DATE_RE, $this->context['today']) === 1) {
                    return $this->context['today'];
                }
                $c = $this->clock();

                return self::formatToday($c['nowMs'], $c['offsetMin']);
            case 'now':
                if (isset($this->context['now']) && is_string($this->context['now']) && self::parseTemporal($this->context['now'], 0) !== null) {
                    return $this->context['now'];
                }
                $c = $this->clock();

                return self::formatNow($c['nowMs'], $c['offsetMin']);
            case 'date_diff':
                $unit = $rawArgs[2] ?? null;
                if (! is_string($unit) || ! (isset(self::MS_PER_UNIT[$unit]) || $unit === 'months' || $unit === 'years')) {
                    return $this->diag('type_error', self::seg($p, 2), 'Unité de `date_diff` invalide');
                }
                if ($a[0] === null || $a[1] === null) {
                    return null;
                }
                $offsetMin = $this->clock()['offsetMin'];
                $x = self::parseTemporal($a[0], $offsetMin);
                $y = self::parseTemporal($a[1], $offsetMin);
                if ($x === null) {
                    return $this->diag('invalid_date', self::seg($p, 0), 'Date invalide : '.self::toStr($a[0]));
                }
                if ($y === null) {
                    return $this->diag('invalid_date', self::seg($p, 1), 'Date invalide : '.self::toStr($a[1]));
                }

                return self::dateDiff($x, $y, $unit, $offsetMin);
            default:
                return $this->diag('unknown_op', $p, "Opérateur inconnu : {$op}");
        }
    }

    // ---------------------------------------------------------------------------
    // Analyse statique (validateur, moteur)
    // ---------------------------------------------------------------------------

    /**
     * Références `var` d'une expression, sans l'évaluer : `[{key, ref, path}]` où `key` est la tête
     * du chemin (clé de question ou variable système), `ref` le chemin complet et `path` le JSON
     * Pointer du nœud dans l'expression.
     *
     * @return array<int, array{key: string, ref: string, path: string}>
     */
    public static function referencedVars(mixed $expr, string $path = ''): array
    {
        $out = [];
        self::collectVars($expr, $path, $out);

        return $out;
    }

    /**
     * Clés de questions (hors variables système `_x`) référencées par une expression, dédoublonnées.
     *
     * @return string[]
     */
    public static function referencedKeys(mixed $expr): array
    {
        $keys = [];
        foreach (self::referencedVars($expr) as $v) {
            if (! str_starts_with($v['key'], '_')) {
                $keys[$v['key']] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param  array<int, array{key: string, ref: string, path: string}>  $out
     */
    private static function collectVars(mixed $node, string $path, array &$out): void
    {
        if ($node instanceof stdClass) {
            $node = (array) $node;
        }
        if (! is_array($node)) {
            return;
        }
        if (array_is_list($node)) {
            foreach ($node as $i => $e) {
                self::collectVars($e, self::seg($path, $i), $out);
            }

            return;
        }
        foreach ($node as $op => $args) {
            $p = self::seg($path, (string) $op);
            if ($op === 'var') {
                if (is_string($args)) {
                    $head = explode('.', $args)[0];
                    $out[] = ['key' => $head, 'ref' => $args, 'path' => $p];
                }

                continue;
            }
            self::collectVars($args, $p, $out);
        }
    }
}
