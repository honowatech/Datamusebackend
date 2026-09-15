<?php

namespace Tests\Unit\Dfs;

/**
 * Accès aux fichiers du contrat (`docs/`) et égalité structurelle JSON pour les tests de conformité.
 */
trait DfsTestSupport
{
    protected static function docsPath(string $relative): string
    {
        $path = dirname(__DIR__, 3).'/../docs/'.ltrim($relative, '/');
        if (! is_file($path)) {
            self::markTestSkipped("Fichier du contrat introuvable : {$path}");
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function loadJson(string $relative): array
    {
        $decoded = json_decode((string) file_get_contents(self::docsPath($relative)), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Égalité structurelle des valeurs JSON : les nombres sont comparés numériquement (7 == 7.0),
     * tout le reste strictement.
     */
    protected static function jsonEquals(mixed $a, mixed $b): bool
    {
        if (is_int($a) || is_float($a)) {
            return (is_int($b) || is_float($b)) && $a == $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (! array_key_exists($k, $b) || ! self::jsonEquals($v, $b[$k])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    protected static function show(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: gettype($v);
    }
}
