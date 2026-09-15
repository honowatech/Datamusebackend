<?php

namespace App\Services\Dfs;

use Normalizer;

/**
 * Génération du code fiche (README § 11).
 *
 * Jetons du `pattern` :
 *   - `{KEY}`      abréviation de la réponse à `sources.KEY` : `Choice.abbr` si la réponse est un
 *                  choix qui en porte une ; sinon le code du choix (ou le texte) normalisé
 *                  (NFD, sans diacritiques, `[A-Za-z0-9]` seulement, majuscules) tronqué à
 *                  3 caractères (`PK14` → `PK1`, `bonamoussadi` → `BON`).
 *   - `{KEY:full}` le code complet normalisé, sans troncature.
 *   - `{NN}`       compteur zéro-paddé sur `counter.width` chiffres (au-delà, conservés).
 *
 * `render()` renvoie null tant qu'une source est vide ou que le compteur n'est pas alloué alors
 * que le motif le demande. `nextSuffix()` sert au serveur (B-07) pour résoudre une collision
 * `(survey, fiche_code)` : `-B`, `-C`, … première lettre libre.
 */
final class FicheCodeGenerator
{
    /**
     * @param  array<string, mixed>  $ficheCodeSettings  `settings.fiche_code` (pattern, sources, counter)
     * @param  array<string, mixed>  $answers  réponses (au moins les questions sources)
     * @param  array<string, array<int, array<string, mixed>>>  $choiceLists  `{clé de question source: Choice[]}` :
     *                                                                        choix résolus de chaque source `select_one`
     *                                                                        (absent pour `text` / `calculate`)
     * @param  int|null  $seq  compteur alloué (`_seq`), null tant qu'il ne l'est pas
     */
    public function render(array $ficheCodeSettings, array $answers, array $choiceLists, ?int $seq): ?string
    {
        $pattern = $ficheCodeSettings['pattern'] ?? null;
        if (! is_string($pattern) || $pattern === '') {
            return null;
        }
        $sources = (array) ($ficheCodeSettings['sources'] ?? []);
        $counter = (array) ($ficheCodeSettings['counter'] ?? []);
        $width = (int) ($counter['width'] ?? 2);
        $missing = false;

        $out = preg_replace_callback(
            '/\{([A-Za-z0-9_]+)(:full)?\}/',
            function (array $m) use ($sources, $answers, $choiceLists, $seq, $width, &$missing): string {
                $token = $m[1];
                $full = isset($m[2]) && $m[2] !== '';
                if ($token === 'NN' && ! $full) {
                    if ($seq === null) {
                        $missing = true;

                        return '';
                    }

                    return str_pad((string) $seq, $width, '0', STR_PAD_LEFT);
                }
                if (! array_key_exists($token, $sources)) {
                    $missing = true;

                    return '';
                }
                $questionKey = (string) $sources[$token];
                $value = $answers[$questionKey] ?? null;
                if (LogicEvaluator::isEmpty($value)) {
                    $missing = true;

                    return '';
                }
                $code = LogicEvaluator::toStr($value);
                if (! $full) {
                    foreach ($choiceLists[$questionKey] ?? [] as $choice) {
                        $choice = (array) $choice;
                        if (($choice['name'] ?? null) === $code && isset($choice['abbr']) && is_string($choice['abbr']) && $choice['abbr'] !== '') {
                            return $choice['abbr'];
                        }
                    }
                }
                $normalized = self::normalize($code);
                if ($normalized === '') {
                    $missing = true;

                    return '';
                }

                return $full ? $normalized : mb_substr($normalized, 0, 3);
            },
            $pattern,
        );

        return $missing || $out === null ? null : $out;
    }

    /**
     * Normalisation d'un code : décomposition NFD, suppression des diacritiques et de tout caractère
     * hors `[A-Za-z0-9]`, majuscules.
     */
    public static function normalize(string $code): string
    {
        $decomposed = class_exists(Normalizer::class)
            ? (Normalizer::normalize($code, Normalizer::FORM_D) ?: $code)
            : self::fallbackDecompose($code);
        $ascii = preg_replace('/[^A-Za-z0-9]/', '', $decomposed) ?? '';

        return strtoupper($ascii);
    }

    /**
     * Suffixe de collision : renvoie `$code` inchangé s'il est libre, sinon `$code-B`, `$code-C`, …
     * (première lettre libre, puis `-AA`, `-AB`, … au-delà de `-Z`).
     *
     * @param  string[]  $existing  codes déjà attribués dans l'enquête
     */
    public function nextSuffix(string $code, array $existing): string
    {
        $taken = array_fill_keys(array_map('strval', $existing), true);
        if (! isset($taken[$code])) {
            return $code;
        }
        for ($i = 1; $i < 10000; $i++) {
            $candidate = $code.'-'.self::letters($i);
            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return $code.'-'.bin2hex(random_bytes(3));
    }

    /** 1 → B, 2 → C, … 25 → Z, 26 → AA, 27 → AB, … */
    private static function letters(int $n): string
    {
        $n++; // 1 → B
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)).$s;
            $n = intdiv($n, 26);
        }

        return $s;
    }

    /** Repli sans extension intl : translittération des lettres latines accentuées courantes. */
    private static function fallbackDecompose(string $s): string
    {
        static $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N', 'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'Y',
        ];

        return strtr($s, $map);
    }
}
