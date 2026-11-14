<?php

namespace Tests\Unit\Analysis;

use App\Services\Survey\SurveyCrosstabService;
use App\Support\ChiSquareDistribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * p-valeur exacte du khi² (gamma incomplète régularisée) confrontée à des valeurs de référence :
 * quantiles des tables usuelles (R `qchisq`, 15 chiffres) et formes closes des degrés pairs.
 */
class ChiSquareDistributionTest extends TestCase
{
    /**
     * @return array<string, array{float, int, float}>
     */
    public static function criticalValues(): array
    {
        return [
            'df 1, p .05' => [3.841458820694124, 1, 0.05],
            'df 1, p .01' => [6.634896601021213, 1, 0.01],
            'df 1, p .001' => [10.827566170662733, 1, 0.001],
            'df 2, p .05' => [5.991464547107979, 2, 0.05],
            'df 3, p .05' => [7.814727903251179, 3, 0.05],
            'df 4, p .05' => [9.487729036781154, 4, 0.05],
            'df 5, p .05' => [11.070497693516351, 5, 0.05],
            'df 9, p .05' => [16.918977604620448, 9, 0.05],
            'df 10, p .01' => [23.209251158954356, 10, 0.01],
            'df 30, p .95' => [18.492660981953467, 30, 0.95],
            'df 100, p .05' => [124.34211340400407, 100, 0.05],
        ];
    }

    #[DataProvider('criticalValues')]
    public function test_survival_matches_reference_quantiles(float $chi2, int $df, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, ChiSquareDistribution::survival($chi2, $df), 1e-10);
    }

    public function test_even_degrees_match_their_closed_forms_far_into_the_tail(): void
    {
        foreach ([0.01, 0.5, 2.0, 3.0, 10.0, 40.0, 150.0, 600.0] as $x) {
            $h = $x / 2;
            $closed = [
                2 => exp(-$h),
                4 => exp(-$h) * (1 + $h),
                6 => exp(-$h) * (1 + $h + $h * $h / 2),
            ];
            foreach ($closed as $df => $expected) {
                $actual = ChiSquareDistribution::survival($x, $df);
                // Précision relative : une p-valeur de 1e-130 doit rester 1e-130, pas 0.
                $this->assertEqualsWithDelta(1.0, $actual / $expected, 1e-9, "khi² = $x, ddl = $df");
            }
        }
    }

    public function test_one_degree_matches_the_normal_tail(): void
    {
        // ddl = 1 : p = erfc(√(x/2)) ; pour x = 1,96², p bilatérale = 0,05 (à 1e-5 près, 1,96 est arrondi).
        $this->assertEqualsWithDelta(0.05, ChiSquareDistribution::survival(1.96 ** 2, 1), 1e-5);
        $this->assertEqualsWithDelta(0.31731050786291415, ChiSquareDistribution::survival(1.0, 1), 1e-12);
        $this->assertEqualsWithDelta(0.04550026389635842, ChiSquareDistribution::survival(4.0, 1), 1e-12);
        $this->assertEqualsWithDelta(0.0026997960632601866, ChiSquareDistribution::survival(9.0, 1), 1e-13);
    }

    public function test_boundaries(): void
    {
        $this->assertSame(1.0, ChiSquareDistribution::survival(0.0, 3));
        $this->assertSame(0.0, ChiSquareDistribution::survival(INF, 3));
        $this->assertNan(ChiSquareDistribution::survival(1.0, 0));
        $this->assertEqualsWithDelta(1.0, ChiSquareDistribution::regularizedGammaP(2.5, 1.3) + ChiSquareDistribution::regularizedGammaQ(2.5, 1.3), 1e-14);
        $this->assertEqualsWithDelta(log(24.0), ChiSquareDistribution::logGamma(5.0), 1e-12);
        $this->assertEqualsWithDelta(0.5 * log(M_PI), ChiSquareDistribution::logGamma(0.5), 1e-12);
    }

    public function test_crosstab_chi_square_uses_the_exact_p_value_and_reports_cramers_v(): void
    {
        // Tableau 2 × 2 classique : khi² = 100·(20·40 − 30·10)² / (50·50·30·70) = 4,7619, ddl = 1.
        $matrix = [[20, 30], [10, 40]];
        [$chi2, $p] = SurveyCrosstabService::chiSquare($matrix, [50, 50], [30, 70], 100);

        $this->assertSame(4.7619, $chi2);
        $this->assertEqualsWithDelta(0.0290963, $p, 1e-6);
        $this->assertSame(0.2182, SurveyCrosstabService::cramersV($chi2, 100, 2, 2));

        // Indépendance parfaite : khi² = 0, p = 1, V = 0.
        [$chi2, $p] = SurveyCrosstabService::chiSquare([[10, 20], [20, 40]], [30, 60], [30, 60], 90);
        $this->assertSame(0.0, $chi2);
        $this->assertSame(1.0, $p);
        $this->assertSame(0.0, SurveyCrosstabService::cramersV($chi2, 90, 2, 2));

        // Association parfaite 3 × 3 : V = 1 et une p-valeur minuscule mais non nulle.
        [$chi2, $p] = SurveyCrosstabService::chiSquare([[50, 0, 0], [0, 50, 0], [0, 0, 50]], [50, 50, 50], [50, 50, 50], 150);
        $this->assertSame(300.0, $chi2);
        $this->assertGreaterThan(0.0, $p);
        $this->assertLessThan(1e-60, $p);
        $this->assertSame(1.0, SurveyCrosstabService::cramersV($chi2, 150, 3, 3));

        // Tableau dégénéré (une seule colonne) : rien n'est défini.
        $this->assertSame([null, null], SurveyCrosstabService::chiSquare([[5], [7]], [5, 7], [12], 12));
        $this->assertNull(SurveyCrosstabService::cramersV(null, 12, 2, 1));
    }
}
