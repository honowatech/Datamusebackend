<?php

namespace App\Support;

/**
 * Écriture CSV commune à l'export d'enquête (B-10) et à l'export d'UNE fiche (F-B5).
 *
 * Un seul jeu de règles, pour que les deux fichiers s'ouvrent de la même façon :
 *
 * - **BOM UTF-8** en tête : Excel (Windows) reconnaît alors les accents sans import manuel ;
 * - séparateur `,`, guillemet `"`, échappement `\` — ceux de `fputcsv()` tels qu'utilisés par B-10 ;
 * - **garde anti-injection de formules** : une cellule *texte* commençant par `=`, `+`, `-`, `@`,
 *   une tabulation ou un retour chariot est préfixée d'une apostrophe, sauf si elle est entièrement
 *   numérique (`-5` reste `-5`). Les nombres passent en `int` / `float` et ne sont jamais touchés.
 */
final class CsvWriter
{
    public const BOM = "\u{FEFF}";

    public const DELIMITER = ',';

    public const ENCLOSURE = '"';

    public const ESCAPE = '\\';

    /** Premiers caractères qui font d'une cellule une formule dans un tableur. */
    private const DANGEROUS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Écrit le BOM puis chaque ligne sur un flux déjà ouvert.
     *
     * @param  resource  $handle
     * @param  iterable<int, array<int|string, mixed>>  $rows
     */
    public static function write($handle, iterable $rows, bool $bom = true): void
    {
        if ($bom) {
            fwrite($handle, self::BOM);
        }
        foreach ($rows as $row) {
            self::writeRow($handle, $row);
        }
    }

    /**
     * @param  resource  $handle
     * @param  array<int|string, mixed>  $row
     */
    public static function writeRow($handle, array $row): void
    {
        fputcsv($handle, array_map(self::cell(...), array_values($row)), self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
    }

    /**
     * Valeur d'une cellule : `null` → `""`, booléen → `1`/`0`, nombre conservé tel quel, tableau en
     * JSON, texte neutralisé si le tableur le lirait comme une formule.
     */
    public static function cell(mixed $value): string|int|float
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 1 : 0,
            is_int($value), is_float($value) => $value,
            is_array($value) => self::guard((string) json_encode($value, JSON_UNESCAPED_UNICODE)),
            default => self::guard((string) $value),
        };
    }

    /**
     * Neutralise une cellule que le tableur interpréterait comme une formule. Une valeur numérique
     * (`-5`, `+3.2`) est laissée intacte : elle doit rester un nombre à l'ouverture.
     */
    public static function guard(string $value): string
    {
        if ($value === '' || ! in_array($value[0], self::DANGEROUS, true) || is_numeric($value)) {
            return $value;
        }

        return "'".$value;
    }
}
