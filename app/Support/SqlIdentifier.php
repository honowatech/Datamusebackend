<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Nettoyage d'identifiants SQL (tables, colonnes) pour les bases SQLite produites par l'application
 * (import de fichiers et matérialisation des enquêtes, plan § 5.2).
 *
 * - `normalize()` : translittération ASCII, minuscules, tout caractère hors [a-z0-9_] → `_`, bords `_` retirés
 *   (peut renvoyer une chaîne vide ou commençant par un chiffre : c'est le comportement historique de l'import).
 * - `clean()`     : `normalize()` + préfixe (`c_` colonnes / `t_` tables) si vide ou débutant par un chiffre,
 *   mot réservé SQLite suffixé `_`, troncature à `$max` caractères.
 * - `unique()`    : dédoublonnage `_2`, `_3`, … dans un ensemble de noms déjà pris (troncature préservée).
 */
final class SqlIdentifier
{
    public const MAX_LENGTH = 60;

    public const COLUMN_PREFIX = 'c_';

    public const TABLE_PREFIX = 't_';

    /**
     * Mots-clés SQLite (https://sqlite.org/lang_keywords.html) : un identifiant identique est suffixé `_`.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'abort', 'action', 'add', 'after', 'all', 'alter', 'always', 'analyze', 'and', 'as', 'asc', 'attach',
        'autoincrement', 'before', 'begin', 'between', 'by', 'cascade', 'case', 'cast', 'check', 'collate',
        'column', 'commit', 'conflict', 'constraint', 'create', 'cross', 'current', 'current_date',
        'current_time', 'current_timestamp', 'database', 'default', 'deferrable', 'deferred', 'delete',
        'desc', 'detach', 'distinct', 'do', 'drop', 'each', 'else', 'end', 'escape', 'except', 'exclude',
        'exclusive', 'exists', 'explain', 'fail', 'filter', 'first', 'following', 'for', 'foreign', 'from',
        'full', 'generated', 'glob', 'group', 'groups', 'having', 'if', 'ignore', 'immediate', 'in', 'index',
        'indexed', 'initially', 'inner', 'insert', 'instead', 'intersect', 'into', 'is', 'isnull', 'join',
        'key', 'last', 'left', 'like', 'limit', 'match', 'materialized', 'natural', 'no', 'not', 'nothing',
        'notnull', 'null', 'nulls', 'of', 'offset', 'on', 'or', 'order', 'others', 'outer', 'over',
        'partition', 'plan', 'pragma', 'preceding', 'primary', 'query', 'raise', 'range', 'recursive',
        'references', 'regexp', 'reindex', 'release', 'rename', 'replace', 'restrict', 'returning', 'right',
        'rollback', 'row', 'rows', 'savepoint', 'select', 'set', 'table', 'temp', 'temporary', 'then', 'ties',
        'to', 'transaction', 'trigger', 'unbounded', 'union', 'unique', 'update', 'using', 'vacuum', 'values',
        'view', 'virtual', 'when', 'where', 'window', 'with', 'without',
    ];

    /**
     * Forme brute : ASCII, minuscules, `_` pour tout caractère non alphanumérique, bords `_` retirés.
     * Les underscores internes (y compris doublés, ex. `q5__codes`) sont conservés.
     */
    public static function normalize(string $raw): string
    {
        // Str::ascii (table de translittération embarquée) plutôt qu'iconv : résultat identique
        // sur Windows et Linux (« é » → « e », alors qu'iconv donne « 'e » ou « ? » selon la locale).
        $ascii = Str::ascii(trim($raw));
        $ascii = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $ascii) ?? '');

        return trim($ascii, '_');
    }

    /**
     * Identifiant sûr : non vide, ne commence pas par un chiffre, pas un mot réservé, ≤ `$max` caractères.
     */
    public static function clean(string $raw, int $max = self::MAX_LENGTH, string $prefix = self::COLUMN_PREFIX): string
    {
        $max = max(1, $max);
        $name = self::normalize($raw);

        if ($name === '' || ctype_digit($name[0])) {
            $name = $prefix.$name;
        }
        if (in_array($name, self::RESERVED, true)) {
            $name .= '_';
        }
        if (strlen($name) > $max) {
            $name = rtrim(substr($name, 0, $max), '_');
            if ($name === '') {
                $name = rtrim(substr($prefix, 0, $max), '_') ?: 'c';
            }
        }

        return $name;
    }

    /**
     * Rend `$name` unique parmi `$taken` (clés du tableau, insensible à la casse) en suffixant `_2`, `_3`, …
     * Le nom retourné est ajouté à `$taken`. Le résultat ne dépasse jamais `$max` caractères.
     *
     * @param  array<string, mixed>  $taken  ensemble des noms déjà pris (clé = nom)
     */
    public static function unique(string $name, array &$taken, int $max = self::MAX_LENGTH): string
    {
        $max = max(1, $max);
        $candidate = strlen($name) > $max ? rtrim(substr($name, 0, $max), '_') : $name;
        $n = 1;

        while (isset($taken[strtolower($candidate)])) {
            $n++;
            $suffix = '_'.$n;
            $base = $name;
            if (strlen($base) + strlen($suffix) > $max) {
                $base = rtrim(substr($base, 0, $max - strlen($suffix)), '_');
            }
            $candidate = $base.$suffix;
        }

        $taken[strtolower($candidate)] = true;

        return $candidate;
    }

    public static function isReserved(string $name): bool
    {
        return in_array(strtolower($name), self::RESERVED, true);
    }
}
