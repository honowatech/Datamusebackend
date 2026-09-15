<?php

namespace Tests\Unit\Dfs;

use App\Services\Dfs\EngineContext;
use App\Services\Dfs\FormEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scénarios de conformité du moteur (docs/dfs/engine-tests.json) sur le questionnaire MunaGo
 * (docs/fixtures/munago.v1.dfs.json). Les étapes sont appliquées dans l'ordre ; les attentes sont
 * vérifiées après la dernière étape (conventions décrites dans le fichier de scénarios).
 */
class EngineConformanceTest extends TestCase
{
    use DfsTestSupport;

    /** @var array<string, mixed>|null */
    private static ?array $fixture = null;

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function scenarios(): iterable
    {
        $suite = self::loadJson('dfs/engine-tests.json');
        foreach ($suite['cases'] ?? [] as $case) {
            yield $case['id'] => [$case];
        }
    }

    public function test_suite_has_44_scenarios(): void
    {
        $suite = self::loadJson('dfs/engine-tests.json');
        $this->assertCount(44, $suite['cases']);
        $this->assertSame('docs/fixtures/munago.v1.dfs.json', $suite['form']);
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('scenarios')]
    public function test_scenario(array $case): void
    {
        $id = $case['id'];
        $definition = self::fixture();
        foreach ($case['steps'] as $step) {
            if (isset($step['settings'])) {
                $definition['settings'] = array_merge($definition['settings'], $step['settings']);
            }
        }

        $engine = new FormEngine($definition, new EngineContext);
        foreach ($case['steps'] as $step) {
            if (isset($step['set'])) {
                $engine->setAnswers($step['set']);
            }
            if (isset($step['toggle'])) {
                foreach ($step['toggle'] as $key => $code) {
                    $engine->toggleChoice($key, $code);
                }
            }
            if (isset($step['context'])) {
                $engine->applyContext($step['context']);
            }
            if (isset($step['lang'])) {
                $engine->setLang($step['lang']);
            }
            if (isset($step['stage'])) {
                $engine->enterStage($step['stage']);
            }
            if (! empty($step['finalize'])) {
                $engine->finalize();
            }
        }

        $expect = $case['expect'];
        $state = $engine->state();
        $problems = [];

        if (isset($expect['status']) && $state['status'] !== $expect['status']) {
            $problems[] = "status : attendu {$expect['status']}, obtenu {$state['status']}";
        }
        if (array_key_exists('end_reason', $expect) && $state['endReason'] !== $expect['end_reason']) {
            $problems[] = 'end_reason : attendu '.self::show($expect['end_reason']).', obtenu '.self::show($state['endReason']);
        }
        foreach ($expect['visible_keys'] ?? [] as $key) {
            if (! $engine->isVisible($key)) {
                $problems[] = "{$key} devrait être visible";
            }
        }
        foreach ($expect['hidden_keys'] ?? [] as $key) {
            if ($engine->isVisible($key)) {
                $problems[] = "{$key} devrait être masqué";
            }
        }
        if (isset($expect['visible_sections']) && $engine->visibleSections() !== $expect['visible_sections']) {
            $problems[] = 'visible_sections : attendu '.self::show($expect['visible_sections']).', obtenu '.self::show($engine->visibleSections());
        }
        if (isset($expect['validation_errors']) || isset($expect['no_validation_errors'])) {
            $errors = [];
            foreach ($engine->validate() as $error) {
                $errors[$error['key']] ??= $error['code'];
            }
            foreach ($expect['validation_errors'] ?? [] as $key => $code) {
                if (($errors[$key] ?? null) !== $code) {
                    $problems[] = "validation {$key} : attendu {$code}, obtenu ".self::show($errors[$key] ?? null);
                }
            }
            foreach ($expect['no_validation_errors'] ?? [] as $key) {
                if (isset($errors[$key])) {
                    $problems[] = "validation {$key} : erreur inattendue {$errors[$key]}";
                }
            }
        }
        if (isset($expect['calculated'])) {
            $calculated = $state['calculated'] + ['fiche_code' => $engine->ficheCode()];
            foreach ($expect['calculated'] as $key => $value) {
                if (! array_key_exists($key, $calculated) || ! self::jsonEquals($calculated[$key], $value)) {
                    $problems[] = "calculated {$key} : attendu ".self::show($value).', obtenu '.(array_key_exists($key, $calculated) ? self::show($calculated[$key]) : '<absent>');
                }
            }
        }
        foreach ($expect['answers'] ?? [] as $key => $value) {
            if (! self::jsonEquals($state['answers'][$key] ?? null, $value)) {
                $problems[] = "answers {$key} : attendu ".self::show($value).', obtenu '.self::show($state['answers'][$key] ?? null);
            }
        }
        if (isset($expect['payload_includes']) || isset($expect['payload_excludes'])) {
            $payload = $engine->payloadAnswers();
            foreach ($expect['payload_includes'] ?? [] as $key => $value) {
                if (! array_key_exists($key, $payload) || ! self::jsonEquals($payload[$key], $value)) {
                    $problems[] = "payload {$key} : attendu ".self::show($value).', obtenu '.(array_key_exists($key, $payload) ? self::show($payload[$key]) : '<absent>');
                }
            }
            foreach ($expect['payload_excludes'] ?? [] as $key) {
                if (array_key_exists($key, $payload)) {
                    $problems[] = "payload ne devrait pas contenir {$key}";
                }
            }
        }
        if (isset($expect['pages_count'])) {
            $count = count($engine->pages($definition['settings']['pagination']));
            if ($count !== $expect['pages_count']) {
                $problems[] = "pages_count : attendu {$expect['pages_count']}, obtenu {$count}";
            }
        }
        foreach ($expect['stage_relevant'] ?? [] as $stage => $relevant) {
            if ($engine->stageRelevant($stage) !== $relevant) {
                $problems[] = "stage_relevant {$stage} : attendu ".self::show($relevant);
            }
        }
        foreach ($expect['labels'] ?? [] as $key => $text) {
            if ($engine->label($key) !== $text) {
                $problems[] = "label {$key} : attendu ".self::show($text).', obtenu '.self::show($engine->label($key));
            }
        }
        foreach ($engine->diagnostics() as $diagnostic) {
            $problems[] = 'diagnostic inattendu '.self::show($diagnostic);
        }

        $this->assertSame([], $problems, "{$id} :\n - ".implode("\n - ", $problems));
    }

    public function test_engine_refuses_unknown_major_version(): void
    {
        $definition = self::fixture();
        $definition['dfs_version'] = '2.0';

        $this->expectException(\InvalidArgumentException::class);
        new FormEngine($definition, new EngineContext);
    }

    public function test_seq_provider_is_called_once_when_sources_are_complete(): void
    {
        $calls = [];
        $ctx = new EngineContext(enumeratorId: 12, seqProvider: function (array $info) use (&$calls): int {
            $calls[] = $info;

            return 7;
        });
        $engine = new FormEngine(self::fixture(), $ctx);
        $engine->setAnswer('ville', 'douala');
        $this->assertSame([], $calls);
        $this->assertNull($engine->ficheCode());

        $engine->setAnswer('quartier', 'bonamoussadi');
        $this->assertCount(1, $calls);
        $this->assertSame('enumerator', $calls[0]['scope']);
        $this->assertSame(12, $calls[0]['enumerator_id']);
        $this->assertSame(['VILLE' => 'douala', 'QUARTIER' => 'bonamoussadi'], $calls[0]['sources']);
        $this->assertSame('DLA-BMP-07', $engine->ficheCode());
        $this->assertSame(7, $engine->calculated()['numero_fiche']);

        // Changer une source recalcule les jetons mais conserve le compteur.
        $engine->setAnswer('quartier', 'akwa');
        $this->assertCount(1, $calls);
        $this->assertSame('DLA-AKW-07', $engine->ficheCode());
    }

    public function test_repeated_group_instances_and_validation(): void
    {
        $definition = self::fixture();
        $definition['sections'][2]['items'][] = [
            'type' => 'group', 'key' => 'enfants', 'label' => ['fr' => 'Enfants'], 'repeat' => ['min' => 1, 'max' => 3],
            'items' => [
                ['key' => 'prenom', 'type' => 'text', 'label' => ['fr' => 'Prénom'], 'required' => true],
                ['key' => 'age', 'type' => 'integer', 'label' => ['fr' => 'Âge'], 'min' => 3, 'max' => 18],
                ['key' => 'rang', 'type' => 'calculate', 'expression' => ['var' => '_repeat_index']],
                ['key' => 'ecole', 'type' => 'text', 'label' => ['fr' => 'École'], 'relevant' => ['>=' => [['var' => 'age'], 6]]],
            ],
        ];
        $definition['sections'][2]['items'][] = ['key' => 'nb_enfants', 'type' => 'calculate', 'expression' => ['length' => [['var' => 'enfants']]]];
        $definition['sections'][2]['items'][] = ['key' => 'age_premier', 'type' => 'calculate', 'expression' => ['var' => 'enfants.0.age']];

        $engine = new FormEngine($definition, new EngineContext);
        $engine->setAnswers(['consentement' => 'oui', 'deja_contacte' => 'non', 'f1_enfant_scolarise' => 'oui', 'f2_capacite' => ['nounou']]);

        $this->assertSame(1, $engine->calculated()['nb_enfants'], 'repeat.min crée une instance');
        $engine->setRepeatAnswer('enfants', 0, 'age', 4);
        $engine->setRepeatAnswer('enfants', 1, 'prenom', 'Awa');
        $engine->setRepeatAnswer('enfants', 1, 'age', 9);
        $this->assertSame(2, $engine->calculated()['nb_enfants']);
        $this->assertSame(4, $engine->calculated()['age_premier']);

        $payload = $engine->payloadAnswers();
        $this->assertSame([['age' => 4, 'rang' => 1], ['prenom' => 'Awa', 'age' => 9, 'rang' => 2]], $payload['enfants']);

        $errors = array_values(array_filter($engine->validate(), static fn (array $e): bool => $e['key'] === 'prenom'));
        $this->assertCount(1, $errors);
        $this->assertSame(['key' => 'prenom', 'repeat_index' => 0, 'code' => 'required'], array_intersect_key($errors[0], ['key' => 1, 'repeat_index' => 1, 'code' => 1]));

        $this->assertSame(2, $engine->addRepeatInstance('enfants'));
        $this->assertNull($engine->addRepeatInstance('enfants'), 'repeat.max atteint');
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        return self::$fixture ??= self::loadJson('fixtures/munago.v1.dfs.json');
    }
}
