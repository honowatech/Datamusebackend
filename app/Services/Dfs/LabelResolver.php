<?php

namespace App\Services\Dfs;

use stdClass;

/**
 * Résolution des textes multilingues (`I18n`) et interpolation `${cle}` (README § 9 et § 10).
 *
 * Repli : `texte[L]` si présent et non vide → `texte[default_language]` → première valeur non vide
 * (ordre des clés) → `$fallback` (la `key` de l'élément pour un `label`, `""` pour les autres textes).
 *
 * `$answers` est un dictionnaire `{clé: valeur affichable}` : chaque `${clé}` est remplacé par la
 * valeur convertie en chaîne (`LogicEvaluator::toStr`), `""` si absente ou nulle. La conversion des
 * codes en libellés (select_one, select_multiple, rank) est faite en amont par le moteur
 * (`FormEngine::displayValues()`).
 */
final class LabelResolver
{
    /**
     * @param  array<string, string>|stdClass|string  $label
     * @param  array<string, mixed>  $answers
     */
    public static function t(array|stdClass|string $label, string $lang, string $default, array $answers = [], string $fallback = ''): string
    {
        return self::interpolate(self::resolve($label, $lang, $default, $fallback), $answers);
    }

    /**
     * Résolution sans interpolation.
     *
     * @param  array<string, string>|stdClass|string  $label
     */
    public static function resolve(array|stdClass|string $label, string $lang, string $default, string $fallback = ''): string
    {
        if (is_string($label)) {
            return $label;
        }
        $label = $label instanceof stdClass ? (array) $label : $label;
        if (self::present($label, $lang)) {
            return $label[$lang];
        }
        if (self::present($label, $default)) {
            return $label[$default];
        }
        foreach ($label as $text) {
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return $fallback;
    }

    /**
     * Remplace chaque `${cle}` par la valeur affichable correspondante (`""` si absente).
     *
     * @param  array<string, mixed>  $answers
     */
    public static function interpolate(string $text, array $answers): string
    {
        if (! str_contains($text, '${')) {
            return $text;
        }

        return preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static fn (array $m): string => LogicEvaluator::toStr($answers[$m[1]] ?? null),
            $text,
        ) ?? $text;
    }

    /**
     * Clés interpolées `${cle}` présentes dans un texte (ou dans toutes les langues d'un I18n).
     *
     * @param  array<string, string>|stdClass|string  $label
     * @return string[]
     */
    public static function placeholders(array|stdClass|string $label): array
    {
        $texts = is_string($label) ? [$label] : array_values((array) $label);
        $keys = [];
        foreach ($texts as $text) {
            if (! is_string($text)) {
                continue;
            }
            if (preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', $text, $m) > 0) {
                foreach ($m[1] as $k) {
                    $keys[$k] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * @param  array<string, mixed>  $label
     */
    private static function present(array $label, string $lang): bool
    {
        return isset($label[$lang]) && is_string($label[$lang]) && $label[$lang] !== '';
    }
}
