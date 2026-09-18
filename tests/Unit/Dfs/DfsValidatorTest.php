<?php

namespace Tests\Unit\Dfs;

use App\Services\Dfs\DfsValidator;
use App\Services\Dfs\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DfsValidatorTest extends TestCase
{
    use DfsTestSupport;

    private static ?DfsValidator $validator = null;

    private static function validator(): DfsValidator
    {
        return self::$validator ??= new DfsValidator(self::docsPath('dfs/dfs-v1.schema.json'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function minimal(): array
    {
        return self::loadJson('dfs/examples/minimal.dfs.json');
    }

    public function test_munago_fixture_is_valid(): void
    {
        $result = self::validator()->validate((string) file_get_contents(self::docsPath('fixtures/munago.v1.dfs.json')));

        $this->assertTrue($result->isValid(), self::show($result->errors));
        $this->assertSame([], $result->errors);
        // Sections C–H n'ont pas de traduction anglaise ; `num_whatsapp` / `nom_compte_momo` sont `pii`
        // et obligatoires alors que `allow_public_link` est vrai (B-13) : avertissements seulement.
        $this->assertEqualsCanonicalizing(['missing_translation', 'pii_required_public'], $result->warningCodes());
    }

    public function test_a_required_pii_question_warns_when_the_public_link_is_allowed(): void
    {
        // `minimal.dfs.json` porte déjà `whatsapp` (pii, obligatoire) mais `allow_public_link` est faux.
        $d = self::minimal();
        $this->assertNotContains('pii_required_public', self::validator()->validate($d)->warningCodes());

        $d['settings']['allow_public_link'] = true;
        $d['sections'][0]['items'][0]['tags'] = ['pii'];
        $d['sections'][0]['items'][0]['required'] = true;

        $result = self::validator()->validate($d);

        // Avertissement, jamais erreur : la question peut rester hors du parcours public.
        $this->assertTrue($result->isValid(), self::show($result->errors));
        $this->assertContains('pii_required_public', $result->warningCodes());
        $warning = array_values(array_filter($result->warnings, static fn (array $w): bool => $w['code'] === 'pii_required_public'))[0];
        $this->assertSame('/sections/0/items/0/required', $warning['path']);
        $this->assertSame('warning', $warning['severity']);
        $this->assertStringContainsString('lien public', $warning['message']);

        // Lien public désactivé → plus d'avertissement.
        $d['settings']['allow_public_link'] = false;
        $this->assertNotContains('pii_required_public', self::validator()->validate($d)->warningCodes());

        // Une seule question visée à la fois : `ville` (ajoutée ci-dessus) et `whatsapp` (déjà `pii`).
        $this->assertCount(
            2,
            array_filter($result->warnings, static fn (array $w): bool => $w['code'] === 'pii_required_public'),
        );

        // Question facultative → plus d'avertissement pour elle.
        $d['settings']['allow_public_link'] = true;
        $d['sections'][0]['items'][0]['required'] = false;
        $paths = array_column(array_filter(
            self::validator()->validate($d)->warnings,
            static fn (array $w): bool => $w['code'] === 'pii_required_public',
        ), 'path');
        $this->assertNotContains('/sections/0/items/0/required', $paths);

        // Question `pii` obligatoire d'une étape de suivi : jamais servie au canal public.
        $d['follow_up_stages'][0]['items'][0]['tags'] = ['pii'];
        $this->assertSame(true, $d['follow_up_stages'][0]['items'][0]['required']);
        $stagePaths = array_column(array_filter(
            self::validator()->validate($d)->warnings,
            static fn (array $w): bool => $w['code'] === 'pii_required_public',
        ), 'path');
        $this->assertNotContains('/follow_up_stages/0/items/0/required', $stagePaths);
    }

    public function test_minimal_example_is_valid(): void
    {
        $result = self::validator()->validate(self::minimal());

        $this->assertTrue($result->isValid(), self::show($result->errors));
        $this->assertNotContains('forward_reference', $result->warningCodes());
    }

    public function test_invalid_json_text(): void
    {
        $result = self::validator()->validate('{"dfs_version": ');

        $this->assertFalse($result->isValid());
        $this->assertSame(['invalid_json'], $result->errorCodes());
        $this->assertSame('/', $result->errors[0]['path']);
    }

    /**
     * Huit définitions invalides construites à partir de l'exemple minimal → code et chemin attendus.
     *
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'type de question inconnu (schéma)' => [
            static function (array $d): array {
                $d['sections'][0]['items'][0]['type'] = 'bogus';

                return $d;
            }, 'schema', '/sections/0/items/0/type',
        ];
        yield 'valeur hors enum (schéma)' => [
            static function (array $d): array {
                $d['settings']['pagination'] = 'carousel';

                return $d;
            }, 'schema', '/settings/pagination',
        ];
        yield 'clé dupliquée' => [
            static function (array $d): array {
                $d['sections'][1]['items'][1]['key'] = 'ville';

                return $d;
            }, 'duplicate_key', '/sections/1/items/1/key',
        ];
        yield 'collision avec un compagnon _other' => [
            static function (array $d): array {
                $d['sections'][0]['items'][3]['key'] = 'lieu_other';

                return $d;
            }, 'companion_collision', '/sections/0/items/2/other',
        ];
        yield 'référence var inconnue' => [
            static function (array $d): array {
                $d['sections'][1]['relevant'] = ['==' => [['var' => 'inexistante'], 1]];

                return $d;
            }, 'unknown_var', '/sections/1/relevant/==/0/var',
        ];
        yield 'liste de choix inconnue' => [
            static function (array $d): array {
                $d['sections'][0]['items'][0]['choices'] = 'villes_fantomes';

                return $d;
            }, 'unknown_choice_list', '/sections/0/items/0/choices',
        ];
        yield 'other.choice absent de la liste' => [
            static function (array $d): array {
                $d['sections'][0]['items'][2]['other']['choice'] = 'zzz';

                return $d;
            }, 'other_choice_missing', '/sections/0/items/2/other/choice',
        ];
        yield 'exclusive absent de la liste' => [
            static function (array $d): array {
                $d['sections'][0]['items'][7]['exclusive'] = ['zzz'];

                return $d;
            }, 'exclusive_choice_missing', '/sections/0/items/7/exclusive/0',
        ];
        yield 'source du code fiche inconnue' => [
            static function (array $d): array {
                $d['settings']['fiche_code']['sources']['VILLE'] = 'nope';

                return $d;
            }, 'fiche_source_unknown', '/settings/fiche_code/sources/VILLE',
        ];
        yield 'jeton du code fiche sans source' => [
            static function (array $d): array {
                $d['settings']['fiche_code']['pattern'] = '{VILLE}-{ZONE}-{NN}';

                return $d;
            }, 'fiche_token_unknown', '/settings/fiche_code/pattern',
        ];
        yield 'opérateur inconnu' => [
            static function (array $d): array {
                $d['sections'][1]['relevant'] = ['foo' => [1]];

                return $d;
            }, 'unknown_operator', '/sections/1/relevant/foo',
        ];
        yield 'arité invalide' => [
            static function (array $d): array {
                $d['sections'][1]['relevant'] = ['==' => [1, 2, 3]];

                return $d;
            }, 'bad_arity', '/sections/1/relevant/==',
        ];
        yield 'stop dans un groupe répété' => [
            static function (array $d): array {
                $d['sections'][0]['items'][] = [
                    'type' => 'group', 'key' => 'G', 'label' => ['fr' => 'Groupe'], 'repeat' => ['min' => 1],
                    'items' => [['key' => 'stop_g', 'type' => 'stop', 'label' => ['fr' => 'Fin'], 'message' => ['fr' => 'Fin'], 'relevant' => ['==' => [['var' => 'ville'], 'x']]]],
                ];

                return $d;
            }, 'stop_in_repeat', '/sections/0/items/11/items/0',
        ];
        yield 'default_language absente d\'un I18n' => [
            static function (array $d): array {
                $d['sections'][0]['items'][0]['label'] = ['en' => 'City'];

                return $d;
            }, 'missing_default_language', '/sections/0/items/0/label',
        ];
        yield 'calculate circulaire' => [
            static function (array $d): array {
                $d['sections'][0]['items'][10]['expression'] = ['+' => [['var' => 'numero_fiche'], 1]];

                return $d;
            }, 'circular_calculate', '/sections/0/items/10/expression/+/0/var',
        ];
        yield 'interpolation inconnue' => [
            static function (array $d): array {
                $d['sections'][1]['items'][0]['label'] = ['fr' => 'Bonjour ${inconnu}'];

                return $d;
            }, 'unknown_interpolation', '/sections/1/items/0/label',
        ];
        yield 'version majeure inconnue' => [
            static function (array $d): array {
                $d['dfs_version'] = '2.0';

                return $d;
            }, 'unsupported_version', '/dfs_version',
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[DataProvider('invalidDefinitions')]
    public function test_invalid_definition(callable $mutate, string $code, string $path): void
    {
        $result = self::validator()->validate($mutate(self::minimal()));

        $this->assertFalse($result->isValid(), 'La définition devrait être invalide');
        $this->assertContains($code, $result->errorCodes(), 'Erreurs obtenues : '.self::show($result->errors));
        $paths = array_column(array_filter($result->errors, static fn (array $e): bool => $e['code'] === $code), 'path');
        $this->assertContains($path, $paths, "Chemin attendu {$path}, obtenus : ".self::show($paths));
        foreach ($result->errors as $error) {
            $this->assertSame('error', $error['severity']);
            $this->assertStringStartsWith('/', $error['path']);
            $this->assertNotSame('', $error['message']);
        }
    }

    public function test_forward_reference_is_a_warning_and_translation_gaps_are_reported(): void
    {
        $d = self::minimal();
        // `ville` (section A) référence `decision` (section T) : référence avant.
        $d['sections'][0]['items'][0]['relevant'] = ['!=' => [['var' => 'decision'], 'x']];
        $result = self::validator()->validate($d);

        $this->assertTrue($result->isValid(), self::show($result->errors));
        $this->assertContains('forward_reference', $result->warningCodes());
        $this->assertContains('missing_translation', $result->warningCodes());
        $warning = array_values(array_filter($result->warnings, static fn (array $w): bool => $w['code'] === 'forward_reference'))[0];
        $this->assertSame('/sections/0/items/0/relevant/!=/0/var', $warning['path']);
        $this->assertSame('warning', $warning['severity']);
    }

    public function test_validation_result_helpers(): void
    {
        $result = new ValidationResult(
            [['path' => '/a', 'code' => 'x', 'message' => 'm', 'severity' => 'error']],
            [['path' => '/b', 'code' => 'y', 'message' => 'm', 'severity' => 'warning']],
        );

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasError('x'));
        $this->assertTrue($result->hasWarning('y'));
        $this->assertCount(2, $result->all());
    }

    public function test_default_schema_path_points_to_docs(): void
    {
        $this->assertStringEndsWith('docs/dfs/dfs-v1.schema.json', str_replace('\\', '/', DfsValidator::defaultSchemaPath()));
    }
}
