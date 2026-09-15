<?php

namespace Tests\Unit\Dfs;

use App\Services\Dfs\LogicEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vecteurs de conformité Logic (docs/dfs/logic-tests.json) : chaque cas doit produire la valeur attendue
 * et, s'il est attendu, le diagnostic `expected_diagnostic` (aucun diagnostic sinon).
 */
class LogicConformanceTest extends TestCase
{
    use DfsTestSupport;

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        $suite = self::loadJson('dfs/logic-tests.json');
        foreach ($suite['cases'] ?? [] as $case) {
            yield $case['id'] => [$case];
        }
    }

    public function test_suite_has_289_cases(): void
    {
        $suite = self::loadJson('dfs/logic-tests.json');
        $ids = array_column($suite['cases'], 'id');
        $this->assertCount(289, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)), 'Identifiants de cas dupliqués');
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('cases')]
    public function test_case(array $case): void
    {
        $id = $case['id'];
        $this->assertArrayHasKey('expected', $case, "{$id} : champ `expected` manquant");

        $result = (new LogicEvaluator)->evaluate(
            $case['expr'],
            $case['answers'] ?? [],
            $case['context'] ?? [],
            ['knownKeys' => $case['known_keys'] ?? null],
        );
        $codes = $result->codes();

        $this->assertTrue(
            self::jsonEquals($result->value, $case['expected']),
            sprintf('%s : valeur attendue %s, obtenue %s (expr=%s, answers=%s, diagnostics=%s)', $id, self::show($case['expected']), self::show($result->value), self::show($case['expr']), self::show($case['answers'] ?? []), self::show($result->diagnostics)),
        );
        if (isset($case['expected_diagnostic'])) {
            $this->assertContains($case['expected_diagnostic'], $codes, sprintf('%s : diagnostic attendu %s, obtenu [%s]', $id, $case['expected_diagnostic'], implode(', ', $codes)));
        } else {
            $this->assertSame([], $codes, sprintf('%s : aucun diagnostic attendu, obtenu [%s]', $id, implode(', ', $codes)));
        }
    }

    public function test_evaluator_accepts_stdclass_input(): void
    {
        $expr = json_decode('{"and":[{"==":[{"var":"D2"},"oui"]},{"selected":[{"var":"F2"},"nounou"]}]}');
        $answers = json_decode('{"D2":"oui","F2":["nounou","vehicule"]}');
        $result = (new LogicEvaluator)->evaluate($expr, $answers, (object) ['_lang' => 'fr']);

        $this->assertTrue($result->value);
        $this->assertSame([], $result->diagnostics);
    }

    public function test_referenced_keys_ignores_system_vars(): void
    {
        $expr = ['and' => [['==' => [['var' => 'decision'], 'oui']], ['>=' => [['var' => 'montant'], ['var' => '_seq']]], ['var' => 'G.0.enfant']]];

        $this->assertSame(['decision', 'montant', 'G'], LogicEvaluator::referencedKeys($expr));
        $this->assertSame('/and/0/==/0/var', LogicEvaluator::referencedVars($expr)[0]['path']);
    }
}
