<?php

namespace App\Support;

/**
 * Loi du khi² en PHP pur (aucune extension) : p-valeur exacte par la fonction gamma incomplète
 * régularisée, `p = Q(k/2, χ²/2)`.
 *
 * - `logGamma()`            : approximation de Lanczos (g = 7, 9 coefficients), erreur relative ~1e-15 ;
 * - `regularizedGammaP()`   : développement en série, convergent pour `x < a + 1` ;
 * - `regularizedGammaQ()`   : fraction continue (Lentz modifié) pour `x ≥ a + 1`, ce qui conserve la
 *   précision relative des très petites p-valeurs (1e-50 reste 1e-50, pas 0).
 */
final class ChiSquareDistribution
{
    private const EPSILON = 1e-15;

    private const MAX_ITERATIONS = 100000;

    private const TINY = 1e-300;

    private const LANCZOS = [
        0.99999999999980993,
        676.5203681218851,
        -1259.1392167224028,
        771.32342877765313,
        -176.61502916214059,
        12.507343278686905,
        -0.13857109526572012,
        9.9843695780195716e-6,
        1.5056327351493116e-7,
    ];

    /**
     * Fonction de survie : `P(X ≥ $chi2)` pour `X` suivant un khi² à `$degrees` degrés de liberté.
     */
    public static function survival(float $chi2, int $degrees): float
    {
        if ($degrees <= 0 || is_nan($chi2)) {
            return NAN;
        }
        if ($chi2 <= 0.0) {
            return 1.0;
        }
        if (is_infinite($chi2)) {
            return 0.0;
        }

        return self::regularizedGammaQ($degrees / 2, $chi2 / 2);
    }

    /**
     * Gamma incomplète supérieure régularisée `Q(a, x) = Γ(a, x) / Γ(a)`.
     */
    public static function regularizedGammaQ(float $a, float $x): float
    {
        if ($a <= 0.0 || $x < 0.0) {
            return NAN;
        }
        if ($x === 0.0) {
            return 1.0;
        }
        if ($x < $a + 1.0) {
            return max(0.0, min(1.0, 1.0 - self::series($a, $x)));
        }

        return max(0.0, min(1.0, self::continuedFraction($a, $x)));
    }

    /**
     * Gamma incomplète inférieure régularisée `P(a, x) = γ(a, x) / Γ(a)`.
     */
    public static function regularizedGammaP(float $a, float $x): float
    {
        if ($a <= 0.0 || $x < 0.0) {
            return NAN;
        }
        if ($x === 0.0) {
            return 0.0;
        }
        if ($x < $a + 1.0) {
            return max(0.0, min(1.0, self::series($a, $x)));
        }

        return max(0.0, min(1.0, 1.0 - self::continuedFraction($a, $x)));
    }

    /**
     * `ln Γ(x)` pour `x > 0`.
     */
    public static function logGamma(float $x): float
    {
        if ($x < 0.5) {
            // Formule de réflexion : Γ(x)Γ(1 − x) = π / sin(πx).
            return log(M_PI / abs(sin(M_PI * $x))) - self::logGamma(1.0 - $x);
        }

        $x -= 1.0;
        $sum = self::LANCZOS[0];
        for ($i = 1; $i < 9; $i++) {
            $sum += self::LANCZOS[$i] / ($x + $i);
        }
        $t = $x + 7.5;

        return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($sum);
    }

    /** `P(a, x)` par la série `x^a e^{-x} Σ x^n / Γ(a + n + 1)`. */
    private static function series(float $a, float $x): float
    {
        $term = 1.0 / $a;
        $sum = $term;
        for ($n = 1; $n <= self::MAX_ITERATIONS; $n++) {
            $term *= $x / ($a + $n);
            $sum += $term;
            if (abs($term) < abs($sum) * self::EPSILON) {
                break;
            }
        }

        return $sum * exp(-$x + $a * log($x) - self::logGamma($a));
    }

    /** `Q(a, x)` par fraction continue (algorithme de Lentz modifié). */
    private static function continuedFraction(float $a, float $x): float
    {
        $b = $x + 1.0 - $a;
        $c = 1.0 / self::TINY;
        $d = 1.0 / (abs($b) < self::TINY ? self::TINY : $b);
        $h = $d;
        for ($i = 1; $i <= self::MAX_ITERATIONS; $i++) {
            $an = -$i * ($i - $a);
            $b += 2.0;
            $d = $an * $d + $b;
            if (abs($d) < self::TINY) {
                $d = self::TINY;
            }
            $c = $b + $an / $c;
            if (abs($c) < self::TINY) {
                $c = self::TINY;
            }
            $d = 1.0 / $d;
            $delta = $d * $c;
            $h *= $delta;
            if (abs($delta - 1.0) < self::EPSILON) {
                break;
            }
        }

        return exp(-$x + $a * log($x) - self::logGamma($a)) * $h;
    }
}
