<?php

namespace Tests\Unit\Dfs;

use App\Services\Dfs\LogicToXPath;
use App\Services\Dfs\XPathToLogic;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class XPathToLogicTest extends TestCase
{
    use DfsTestSupport;

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function expressions(): array
    {
        $v = static fn (string $k): array => ['var' => $k];

        return [
            'égalité' => ["\${D2} = 'oui'", ['==' => [$v('D2'), 'oui']]],
            'différence' => ["\${a} != 'x'", ['!=' => [$v('a'), 'x']]],
            'supérieur ou égal' => ['${n} >= 1', ['>=' => [$v('n'), 1]]],
            'et' => ['${n} < 10 and ${n} > 0', ['and' => [['<' => [$v('n'), 10]], ['>' => [$v('n'), 0]]]]],
            'ou n-aire aplati' => ["\${a} = 'x' or \${b} = 'y' or \${c} = 'z'", ['or' => [['==' => [$v('a'), 'x']], ['==' => [$v('b'), 'y']], ['==' => [$v('c'), 'z']]]]],
            'not()' => ["not(\${a} = 'x')", ['!' => [['==' => [$v('a'), 'x']]]]],
            'selected()' => ["selected(\${F2}, 'nounou')", ['selected' => [$v('F2'), 'nounou']]],
            'selected() littéral → in' => ["selected('a b c', \${K})", ['in' => [$v('K'), ['a', 'b', 'c']]]],
            'count-selected()' => ['count-selected(${F2}) >= 1', ['>=' => [['count_selected' => [$v('F2')]], 1]]],
            'if()' => ["if(\${a} = 1, 'un', 'autre')", ['if' => [['==' => [$v('a'), 1]], 'un', 'autre']]],
            'if() sans sinon' => ["if(\${a} = 1, 'un')", ['if' => [['==' => [$v('a'), 1]], 'un']]],
            'coalesce()' => ["coalesce(\${a}, \${b}, 'z')", ['coalesce' => [$v('a'), $v('b'), 'z']]],
            'concat()' => ["concat(\${a}, ' ', \${b})", ['concat' => [$v('a'), ' ', $v('b')]]],
            'string-length()' => ['string-length(${t}) > 0', ['>' => [['length' => [$v('t')]], 0]]],
            'today()' => ['today()', ['today' => []]],
            'now()' => ['now()', ['now' => []]],
            'addition n-aire' => ['${a} + ${b} + 3', ['+' => [$v('a'), $v('b'), 3]]],
            'soustraction' => ['${a} - 1', ['-' => [$v('a'), 1]]],
            'multiplication et div' => ['${a} * 2 div 4', ['/' => [['*' => [$v('a'), 2]], 4]]],
            'mod' => ['${a} mod 2 = 0', ['==' => [['%' => [$v('a'), 2]], 0]]],
            'parenthèses' => ['(${a} + 1) * 2', ['*' => [['+' => [$v('a'), 1]], 2]]],
            'moins unaire' => ['-${a}', ['-' => [$v('a')]]],
            'littéral négatif' => ['-5', -5],
            'décimal' => ['2.5 > 1', ['>' => [2.5, 1]]],
            'booléen true()' => ['${a} = true()', ['==' => [$v('a'), true]]],
            'chaîne vide' => ["\${a} = ''", ['==' => [$v('a'), '']]],
            'guillemets doubles' => ['${a} = "l\'autre"', ['==' => [$v('a'), "l'autre"]]],
            'date_diff en jours' => ['int(decimal-date-time(${d}) - decimal-date-time(today())) >= 0', ['>=' => [['date_diff' => [$v('d'), ['today' => []], 'days']], 0]]],
            'position(..)' => ['position(..) = 1', ['==' => [$v('_repeat_index'), 1]]],
            'number()' => ['number(${a}) > 3', ['>' => [['+' => [$v('a')]], 3]]],
            'contains() → in' => ["contains(\${t}, 'abc')", ['in' => ['abc', $v('t')]]],
            'imbrication' => ['${a} = 1 and (${b} = 2 or ${c} = 3)', ['and' => [['==' => [$v('a'), 1]], ['or' => [['==' => [$v('b'), 2]], ['==' => [$v('c'), 3]]]]]]],
            'chemin de groupe répété' => ["\${G/child} = 'x'", ['==' => [$v('G.child'), 'x']]],
            'regex() sur ${x}' => ["regex(\${tel}, '^6[0-9]{8}$')", ['regex' => [$v('tel'), '^6[0-9]{8}$']]],
            'variable système' => ["\${_status} = 'completed'", ['==' => [$v('_status'), 'completed']]],
            'mots-clés en majuscules' => ["\${a} = 'x' AND \${b} = 'y'", ['and' => [['==' => [$v('a'), 'x']], ['==' => [$v('b'), 'y']]]]],
        ];
    }

    #[DataProvider('expressions')]
    public function test_parses_supported_xpath(string $xpath, mixed $expected): void
    {
        $ast = XPathToLogic::parse($xpath);

        $this->assertTrue(self::jsonEquals($expected, $ast), "{$xpath} → ".self::show($ast).' (attendu '.self::show($expected).')');
    }

    public function test_dot_refers_to_current_question(): void
    {
        $ast = XPathToLogic::parse("regex(., '^6[0-9]{8}$') and . != ''", 'num_whatsapp');

        $this->assertSame(['and' => [['regex' => [['var' => 'num_whatsapp'], '^6[0-9]{8}$']], ['!=' => [['var' => 'num_whatsapp'], '']]]], $ast);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsupported(): array
    {
        return [
            'point sans question courante' => ['. >= 1', 'question courante'],
            'expression incomplète' => ['${a} =', 'fin d\'expression'],
            'fonction inconnue' => ['foo(${a})', 'foo()'],
            'uuid()' => ['uuid()', 'uuid()'],
            'decimal-date-time seule' => ['decimal-date-time(${d}) > 3', 'decimal-date-time()'],
            'double égal' => ['${a} == 1', 'jeton inattendu'],
            'chaîne non fermée' => ["\${a} = 'oups", 'non fermée'],
            'and sans opérande' => ['${a} and', 'fin d\'expression'],
            'int() hors date_diff' => ['int(${a})', 'int()'],
            'regex() motif dynamique' => ['regex(${a}, ${p})', 'motif littéral'],
            'selected() arité' => ['selected(${a})', 'selected() attend 2'],
            'expression vide' => ['   ', 'vide'],
            'caractère inattendu' => ['${a} ? 1', 'inattendu'],
            'parenthèse manquante' => ['(${a} = 1', '« ) » attendu'],
            'identifiant nu' => ['yes', 'identifiant « yes »'],
            'position() sans ..' => ['position(${a})', 'position(..)'],
        ];
    }

    #[DataProvider('unsupported')]
    public function test_rejects_unsupported_xpath_with_message(string $xpath, string $fragment): void
    {
        $ast = XPathToLogic::tryParse($xpath, null, $error);

        $this->assertNull($ast);
        $this->assertIsString($error);
        $this->assertStringContainsString($fragment, $error);

        $this->expectException(InvalidArgumentException::class);
        XPathToLogic::parse($xpath);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function astToXPath(): array
    {
        $v = static fn (string $k): array => ['var' => $k];

        return [
            'égalité' => [['==' => [$v('D2'), 'oui']], "\${D2} = 'oui'"],
            'et de comparaisons' => [['and' => [['>=' => [$v('m'), 1000]], ['<=' => [$v('m'), 20000]]]], '(${m} >= 1000) and (${m} <= 20000)'],
            'in → selected littéral' => [['in' => [$v('d'), ['oui_paiement', 'hesite_puis_oui']]], "selected('oui_paiement hesite_puis_oui', \${d})"],
            'booléen' => [['==' => [$v('a'), true]], '${a} = true()'],
            'not' => [['!' => [['selected' => [$v('F2'), 'aucune']]]], "not(selected(\${F2}, 'aucune'))"],
            'date_diff' => [['date_diff' => [$v('d'), ['today' => []], 'days']], 'int(decimal-date-time(${d}) - decimal-date-time(today()))'],
            'division et modulo' => [['%' => [['/' => [$v('a'), 2]], 3]], '(${a} div 2) mod 3'],
            'if' => [['if' => [['==' => [$v('a'), 1]], 'un', 'autre']], "if(\${a} = 1, 'un', 'autre')"],
            'repeat index' => [['==' => [$v('_repeat_index'), 1]], 'position(..) = 1'],
            'système' => [$v('_seq'), '${_seq}'],
        ];
    }

    #[DataProvider('astToXPath')]
    public function test_logic_to_xpath_is_exact_and_round_trips(mixed $ast, string $expected): void
    {
        $result = LogicToXPath::convert($ast);

        $this->assertSame($expected, $result['xpath']);
        $this->assertTrue($result['exact'], 'aller-retour : '.self::show(XPathToLogic::parse($result['xpath'])));
        $this->assertTrue(self::jsonEquals($ast, XPathToLogic::parse($result['xpath'])));
    }

    public function test_self_key_is_rendered_as_dot(): void
    {
        $result = LogicToXPath::convert(['>=' => [['var' => 'montant'], 1000]], 'montant');

        $this->assertSame('. >= 1000', $result['xpath']);
        $this->assertTrue($result['exact']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function inexact(): array
    {
        $v = static fn (string $k): array => ['var' => $k];

        return [
            'answered' => [['answered' => [$v('a')]]],
            'empty' => [['empty' => [$v('a')]]],
            'null' => [['==' => [$v('a'), null]]],
            'if sans sinon' => [['if' => [['==' => [$v('a'), 1]], 'un']]],
            'date_diff en mois' => [['date_diff' => [$v('d'), ['today' => []], 'months']]],
            'liste littérale' => [['==' => [$v('a'), ['x', 'y']]]],
        ];
    }

    #[DataProvider('inexact')]
    public function test_logic_to_xpath_flags_inexact_translations(mixed $ast): void
    {
        $result = LogicToXPath::convert($ast);

        $this->assertFalse($result['exact']);
        $this->assertNotEmpty($result['notes']);
    }
}
