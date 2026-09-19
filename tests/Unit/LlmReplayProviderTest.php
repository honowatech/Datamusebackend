<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Dfs\DfsDefaults;
use App\Services\Dfs\DfsValidator;
use App\Services\LlmProviderService;
use App\Services\LlmReplayProvider;
use App\Support\ApiKeyResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * E-03 — fournisseur IA « rejeu » (`LLM_DRIVER=replay`).
 *
 * Couvre : garde-fou de production, sélection d'une réponse (nom du prompt, `vars`, `contains`,
 * `regex`, défaut), transformations (`translation`, `verbatim_classify`), substitution des chiffres
 * réels (`figures`), absence totale d'appel réseau, et non-exigence d'une clé API.
 *
 * Le corpus utilisé est **celui du dépôt** (`storage/app/llm-replay/`) : ces tests échouent donc si une
 * réponse versionnée est supprimée ou si son `index.json` devient incohérent.
 */
class LlmReplayProviderTest extends TestCase
{
    private LlmReplayProvider $replay;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.llm.driver' => LlmProviderService::DRIVER_REPLAY,
            'services.llm.replay_latency_min_ms' => 0,
            'services.llm.replay_latency_max_ms' => 0,
        ]);

        $this->replay = new LlmReplayProvider;
    }

    // ------------------------------------------------------------------ garde-fous

    public function test_replay_is_refused_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('réservé au développement');
            LlmReplayProvider::assertAllowed();
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_is_replay_propagates_the_production_refusal(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(RuntimeException::class);
            LlmProviderService::isReplay();
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_driver_live_keeps_the_real_providers(): void
    {
        config(['services.llm.driver' => LlmProviderService::DRIVER_LIVE]);

        $this->assertFalse(LlmProviderService::isReplay());
        $this->assertSame('gemini', LlmProviderService::effectiveProvider('gemini'));
        $this->assertSame(
            (string) config('services.gemini.model'),
            LlmProviderService::effectiveModel('gemini'),
        );
    }

    public function test_replay_marks_the_provider_and_the_model(): void
    {
        $this->assertTrue(LlmProviderService::isReplay());
        $this->assertSame('replay', LlmProviderService::effectiveProvider('gemini'));
        $this->assertSame('replay', LlmProviderService::effectiveProvider('deepseek'));
        $this->assertSame('replay', LlmProviderService::effectiveModel('gemini'));
    }

    public function test_no_api_key_is_required_in_replay_mode(): void
    {
        config(['services.gemini.key' => null, 'services.deepseek.key' => null]);
        $user = new User(['name' => 'U', 'email' => 'u@example.com']);

        $this->assertSame(ApiKeyResolver::REPLAY_PLACEHOLDER, (new ApiKeyResolver)->resolve(null, $user, 'gemini'));
        $this->assertNotNull((new ApiKeyResolver)->resolveForJob(null, $user, 'gemini'));
    }

    public function test_a_missing_prompt_name_is_an_explicit_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nom du prompt est absent');

        $this->replay->generate([['role' => 'user', 'content' => 'bonjour']], null);
    }

    public function test_an_unknown_prompt_names_the_missing_folder(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prompt « prompt_inexistant »');

        $this->replay->generate([['role' => 'user', 'content' => 'x']], null, ['prompt_name' => 'prompt_inexistant']);
    }

    // ------------------------------------------------------------------ aucun appel réseau

    public function test_generate_never_hits_the_network(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $raw = (new LlmProviderService)->generate('gemini', 'peu-importe', [
            ['role' => 'user', 'content' => 'Quel est le taux d\'acompte par quartier ?'],
        ], 'système', ['prompt_name' => 'sql_simple']);

        Http::assertNothingSent();
        $this->assertSame('sql', json_decode($raw, true)['type']);
    }

    // ------------------------------------------------------------------ sélection

    public function test_selection_by_regex_picks_the_matching_demo_question(): void
    {
        $acompte = $this->decodeSql('sql_simple', 'Quel est le taux d\'acompte par quartier ?');
        $duree = $this->decodeSql('sql_simple', 'Quelle est la durée moyenne d\'entretien par enquêteur ?');

        $this->assertStringContainsString('acompte_verse', $acompte['content']);
        $this->assertStringContainsString('quartier_lib', $acompte['content']);
        $this->assertStringContainsString('duree_sec', $duree['content']);
        $this->assertStringContainsString('enqueteur_nom', $duree['content']);
        $this->assertSame('quartier', $acompte['chart_config']['x_axis']);
    }

    public function test_an_uncovered_question_gets_an_honest_message_not_invented_sql(): void
    {
        $out = $this->decodeSql('sql_simple', 'Combien de familles possèdent un chien ?');

        $this->assertSame('message', $out['type']);
        $this->assertStringContainsString('rejeu', $out['content']);
    }

    public function test_selection_by_system_prompt_prefers_the_ai_themes_column(): void
    {
        $avec = $this->decodeSql('sql_complexe', 'Quels sont les freins les plus cités ?', 'TABLE reponses : q6_freins_themes TEXT');
        $sans = $this->decodeSql('sql_complexe', 'Quels sont les freins les plus cités ?', 'TABLE reponses : q6_freins_codes TEXT');

        $this->assertStringContainsString('q6_freins_themes', $avec['content']);
        $this->assertStringContainsString('q6_freins_codes', $sans['content']);
        $this->assertStringNotContainsString('q6_freins_themes', $sans['content']);
    }

    public function test_selection_by_vars_picks_the_executive_report(): void
    {
        $long = $this->decode('commercial_report', 'DONNÉES DE L\'ENQUÊTE', ['orientation' => 'commercial']);
        $court = $this->decode('commercial_report', 'DONNÉES DE L\'ENQUÊTE', ['orientation' => 'executif']);

        $this->assertGreaterThan(count($court['sections']), count($long['sections']));
        $this->assertSame('MunaGo Douala — note de décision', $court['title']);
    }

    public function test_the_generated_form_is_a_valid_dfs_first_try(): void
    {
        $definition = $this->decode('form_generation', "Questionnaire MunaGo — traceur GPS pour enfants\nTest d'acompte");

        $result = app(DfsValidator::class)->validate(
            DfsDefaults::apply($definition)
        );

        $this->assertSame([], $result->errors, 'le DFS rejoué doit passer le validateur sans réparation');
        $this->assertGreaterThanOrEqual(8, count($definition['sections']));
        $this->assertCount(3, $definition['follow_up_stages']);
    }

    public function test_the_repair_variant_is_invalid_and_its_repair_is_valid(): void
    {
        $invalide = $this->decode('form_generation', "Questionnaire MunaGo\nConsignes : demo-reparation");
        $corrige = $this->decode('form_repair', "Document à corriger :\n\nMunaGo …");

        $validator = app(DfsValidator::class);
        $codes = array_column($validator->validate(DfsDefaults::apply($invalide))->errors, 'code');

        $this->assertContains('schema', $codes, 'la variante doit être structurellement invalide');
        $this->assertSame([], $validator->validate(DfsDefaults::apply($corrige))->errors);
    }

    // ------------------------------------------------------------------ transformations

    public function test_translation_echoes_the_requested_paths(): void
    {
        $batch = json_encode([
            ['path' => '/sections/0/label', 'text' => 'H. Confiance et marque'],
            ['path' => '/choice_lists/oui_non/0/label', 'text' => 'Oui'],
            ['path' => '/sections/9/items/3/hint', 'text' => 'Un texte absent du corpus de traduction'],
        ], JSON_UNESCAPED_UNICODE);

        $rows = json_decode($this->raw('form_translation', $batch, ['target_lang' => 'en']), true);

        $this->assertSame(['/sections/0/label', '/choice_lists/oui_non/0/label', '/sections/9/items/3/hint'], array_column($rows, 'path'));
        $this->assertSame('H. Trust and brand', $rows[0]['text']);
        $this->assertSame('Yes', $rows[1]['text']);
        $this->assertSame('Un texte absent du corpus de traduction', $rows[2]['text'], 'un texte hors corpus est recopié, jamais inventé');
    }

    public function test_classification_answers_every_requested_ref(): void
    {
        $batch = json_encode([
            ['ref' => 41, 'text' => 'Le prix. 20 000 plus l\'abonnement chaque mois, ça fait beaucoup avec l\'école.'],
            ['ref' => 'abc', 'text' => "Qui voit les données ? Vous ?  Si quelqu'un pirate, il sait où est mon enfant."],
            ['ref' => 99, 'text' => 'Verbatim absent du corpus'],
        ], JSON_UNESCAPED_UNICODE);

        $rows = json_decode($this->raw('verbatim_classify', $batch, ['question_key' => 'q6_freins']), true);

        $this->assertSame([41, 'abc', 99], array_column($rows, 'ref'));
        $this->assertSame(['prix_abonnement'], $rows[0]['themes']);
        $this->assertSame('negative', $rows[0]['sentiment']);
        $this->assertSame(['confiance_donnees'], $rows[1]['themes'], 'espaces et apostrophes normalisés');
        $this->assertSame([], $rows[2]['themes'], 'un verbatim hors corpus n\'est pas codé de force');
        $this->assertLessThan(0.5, $rows[2]['confidence']);
    }

    public function test_discover_refuses_an_uncovered_question_instead_of_inventing_themes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('aucune règle ne correspond');

        $this->replay->generate(
            [['role' => 'user', 'content' => '1. une réponse']],
            null,
            ['prompt_name' => 'verbatim_discover', 'replay_vars' => ['question_key' => 'question_inconnue']],
        );
    }

    // ------------------------------------------------------------------ chiffres réels

    public function test_figures_are_read_from_the_context_never_hardcoded(): void
    {
        $context = <<<'MD'
        # Enquête : test
        ## Échantillon
        - Fiches exploitables : 120 (dont 100 valides, 20 hors cible)
        - Durée médiane : 900 s
        ## Indicateurs clés
        - % d'acompte / fiches valides : 42 % (42/100)
        - % retrait ≤ 7 j parmi les acomptes : 10 % (4/42)
        - % activé à J+14 parmi les appareils retirés : 25 % (1/4)
        - % hors cible / contacts : 16.7 % (20/120)
        MD;

        $markdown = $this->raw('survey_synthesis', $context);

        $this->assertStringContainsString('**120 fiches exploitables**', $markdown);
        $this->assertStringContainsString('**42 % (42/100)**', $markdown);
        $this->assertStringContainsString('**900 secondes**', $markdown);
        $this->assertStringNotContainsString('{{', $markdown, 'aucun marqueur ne doit subsister');
    }

    public function test_synthesis_says_so_when_verbatims_are_not_classified(): void
    {
        $markdown = $this->raw('survey_synthesis', "# Enquête\n- Fiches exploitables : 10 (dont 9 valides, 1 hors cible)");

        $this->assertStringContainsString('ne sont pas encore classées', $markdown);
    }

    public function test_report_figures_are_json_escaped(): void
    {
        $context = "## Verbatims — 6. Freins (`q6_freins`, 5 codés)\n- **Prix « barré »** : 3 (60 %)\n  > Il a dit \"non\" tout de suite\n\n## fin\n";

        $decoded = $this->decode('commercial_report', $context, ['orientation' => 'commercial']);

        $this->assertIsArray($decoded, 'une citation contenant des guillemets ne doit pas casser le JSON');
        $this->assertStringContainsString('Prix « barré »', json_encode($decoded, JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $vars
     */
    private function raw(string $prompt, string $message, array $vars = [], ?string $system = null): string
    {
        return $this->replay->generate(
            [['role' => 'user', 'content' => $message]],
            $system,
            ['prompt_name' => $prompt, 'replay_vars' => $vars],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $prompt, string $message, array $vars = []): array
    {
        $decoded = json_decode($this->raw($prompt, $message, $vars), true);
        $this->assertIsArray($decoded, "la réponse rejouée de « {$prompt} » doit être du JSON valide : ".json_last_error_msg());

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSql(string $prompt, string $question, ?string $system = null): array
    {
        $decoded = json_decode($this->raw($prompt, $question, [], $system), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
