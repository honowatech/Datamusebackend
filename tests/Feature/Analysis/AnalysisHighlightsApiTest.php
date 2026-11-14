<?php

namespace Tests\Feature\Analysis;

use App\Enums\UserRole;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyAnalysisHighlights;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\User;
use App\Services\Survey\SurveyMaterializationService;
use Database\Factories\SurveyVersionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Analyse native, chantier 4 : `GET|PUT /surveys/{id}/analysis/highlights`. Données synthétiques.
 */
class AnalysisHighlightsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $analyst;

    private User $supervisor;

    private User $enumerator;

    private User $stranger;

    private Survey $survey;

    private SurveyVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->create(['role' => UserRole::Analyste, 'name' => 'Analyste Test']);
        $this->supervisor = User::factory()->create(['role' => UserRole::Analyste]);
        $this->enumerator = User::factory()->create(['role' => UserRole::Enqueteur]);
        $this->stranger = User::factory()->create(['role' => UserRole::Analyste]);

        $project = SurveyProject::factory()->create(['owner_id' => $this->analyst->id]);
        ProjectMember::factory()->analyste()->create(['project_id' => $project->id, 'user_id' => $this->analyst->id]);
        ProjectMember::factory()->superviseur()->create(['project_id' => $project->id, 'user_id' => $this->supervisor->id]);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $project->id, 'user_id' => $this->enumerator->id]);

        $this->survey = Survey::factory()->create(['project_id' => $project->id, 'created_by' => $this->analyst->id]);
        $this->version = SurveyVersion::factory()->published()->create([
            'survey_id' => $this->survey->id,
            'definition' => self::definition(),
        ]);
        $this->survey->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function url(): string
    {
        return '/api/surveys/'.$this->survey->id.'/analysis/highlights';
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(array $overrides = []): array
    {
        return $overrides + [
            'variables' => ['region', 'satisfaction'],
            'kind' => 'association',
            'test' => 'chi2',
            'statistic' => 18.42,
            'df' => 4,
            'p_value' => 0.00102,
            'p_adjusted' => 0.0153,
            'effect' => ['name' => 'cramers_v', 'value' => 0.31],
            'n' => 240,
            'text' => [
                'fr' => 'La satisfaction varie nettement selon la région (V de Cramér = 0,31).',
                'en' => 'Satisfaction differs markedly across regions (Cramér\'s V = 0.31).',
            ],
            'details' => ['top_cell' => ['row' => 'nord', 'col' => '5', 'residual' => 2.9]],
        ];
    }

    public function test_highlights_are_empty_until_saved_then_round_trip_in_rank_order(): void
    {
        $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.stale', false)
            ->assertJsonPath('data.computed_at', null)
            ->assertJsonPath('data.current_materialized_at', null);

        $materializedAt = $this->materialize();

        $payload = [
            'dataset_materialized_at' => $materializedAt,
            'weighted' => true,
            'engine' => 'datamuse-web/1.0',
            'params' => ['alpha' => 0.05, 'correction' => 'benjamini-hochberg', 'pairs_tested' => 6],
            'items' => [
                self::item(),
                self::item([
                    'variables' => ['region', 'age'], 'kind' => 'difference', 'test' => 'anova', 'statistic' => 6.1, 'df' => 2,
                    'effect' => ['name' => 'eta_squared', 'value' => 0.12],
                    'text' => ['fr' => "L'âge moyen diffère selon la région."], 'details' => null,
                ]),
                self::item(['variables' => ['_zone'], 'kind' => 'distribution', 'test' => null, 'statistic' => null, 'df' => null, 'p_value' => null, 'p_adjusted' => null, 'effect' => null]),
            ],
        ];

        $saved = $this->actingAs($this->analyst, 'sanctum')->putJson($this->url(), $payload)->assertOk();
        $saved->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0', self::item())
            ->assertJsonPath('data.items.1.variables', ['region', 'age'])
            ->assertJsonPath('data.items.1.text', ['fr' => "L'âge moyen diffère selon la région."])
            ->assertJsonPath('data.items.1.details', null)
            ->assertJsonPath('data.items.2.effect', null)
            ->assertJsonPath('data.weighted', true)
            ->assertJsonPath('data.engine', 'datamuse-web/1.0')
            ->assertJsonPath('data.params.correction', 'benjamini-hochberg')
            ->assertJsonPath('data.stale', false)
            ->assertJsonPath('data.updated_by', ['id' => $this->analyst->id, 'name' => 'Analyste Test']);
        $this->assertNotNull($saved->json('data.computed_at'));
        $this->assertSame($saved->json('data.current_materialized_at'), $saved->json('data.dataset_materialized_at'));

        // Le superviseur lit le même jeu ; un nouveau PUT le remplace (un seul jeu par enquête).
        $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url())->assertOk()
            ->assertJsonPath('data.items', $saved->json('data.items'));

        $this->actingAs($this->analyst, 'sanctum')->putJson($this->url(), ['items' => []])->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.weighted', false);
        $this->assertSame(1, SurveyAnalysisHighlights::query()->count());
    }

    public function test_highlights_become_stale_after_a_new_materialization(): void
    {
        $materializedAt = $this->materialize();
        $this->actingAs($this->analyst, 'sanctum')->putJson($this->url(), ['dataset_materialized_at' => $materializedAt, 'items' => [self::item()]])
            ->assertOk()->assertJsonPath('data.stale', false);

        Carbon::setTestNow(now()->addHour());
        Submission::factory()->create($this->submissionAttributes(['region' => 'sud', 'satisfaction' => '2', 'age' => 44]));
        app(SurveyMaterializationService::class)->materialize($this->survey->refresh());

        $data = $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url())->assertOk()->json('data');
        $this->assertTrue($data['stale']);
        $this->assertCount(1, $data['items'], 'un jeu périmé reste servi, avec son drapeau');
        $this->assertNotSame($data['dataset_materialized_at'], $data['current_materialized_at']);

        // Sans date de matérialisation, un jeu est réputé périmé dès qu'un fichier existe.
        $this->actingAs($this->analyst, 'sanctum')->putJson($this->url(), ['items' => [self::item()]])
            ->assertOk()->assertJsonPath('data.stale', true);
    }

    public function test_highlights_are_validated_and_cannot_cite_pii_or_unknown_variables(): void
    {
        $response = $this->actingAs($this->analyst, 'sanctum')->putJson($this->url(), [
            'dataset_materialized_at' => 'hier',
            'engine' => str_repeat('x', 61),
            'params' => [1, 2],
            'items' => [
                self::item(['variables' => ['region', 'telephone']]),
                self::item(['variables' => ['inconnue']]),
                self::item(['variables' => []]),
                self::item(['text' => []]),
                self::item(['p_value' => 1.5, 'p_adjusted' => '0.01']),
                self::item(['effect' => ['name' => 'cramers v !', 'value' => 0.3]]),
                self::item(['text' => ['fr' => str_repeat('a', 1001)]]),
                self::item(['details' => ['blob' => str_repeat('z', 9000)]]),
                'pas un objet',
            ],
        ])->assertStatus(422)->assertJsonPath('success', false);

        $errors = $response->json('errors');
        foreach ([
            'dataset_materialized_at', 'engine', 'params',
            'items.0.variables.1', 'items.1.variables.0', 'items.2.variables', 'items.3.text',
            'items.4.p_value', 'items.4.p_adjusted', 'items.5.effect.name', 'items.6.text.fr', 'items.7.details', 'items.8',
        ] as $path) {
            $this->assertArrayHasKey($path, $errors, "erreur attendue sur « $path »");
        }
        $this->assertSame(0, SurveyAnalysisHighlights::query()->count(), 'un jeu invalide ne doit rien enregistrer');

        $tooMany = array_fill(0, 201, self::item());
        $this->actingAs($this->analyst, 'sanctum')->putJson($this->url(), ['items' => $tooMany])
            ->assertStatus(422)->assertJsonValidationErrors(['items'], 'errors');
    }

    public function test_a_fact_whose_variable_left_the_dictionary_is_no_longer_served(): void
    {
        $this->actingAs($this->analyst, 'sanctum')->putJson($this->url(), ['items' => [
            self::item(),
            self::item(['variables' => ['age']]),
        ]])->assertOk()->assertJsonCount(2, 'data.items');

        // La question `satisfaction` devient une donnée personnelle dans la définition.
        $definition = self::definition();
        foreach ($definition['sections'][0]['items'] as &$item) {
            if ($item['key'] === 'satisfaction') {
                $item['tags'] = ['pii'];
            }
        }
        unset($item);
        $this->version->forceFill(['definition' => $definition])->save();

        $items = $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url())->assertOk()->json('data.items');
        $this->assertSame([['age']], array_column($items, 'variables'));
    }

    public function test_highlights_are_readable_by_supervisors_and_writable_by_analysts_only(): void
    {
        $payload = ['items' => [self::item()]];

        $this->getJson($this->url())->assertStatus(401);
        $this->putJson($this->url(), $payload)->assertStatus(401);

        $this->actingAs($this->stranger, 'sanctum')->getJson($this->url())->assertStatus(403);
        $this->actingAs($this->enumerator, 'sanctum')->getJson($this->url())->assertStatus(403);
        $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url())->assertOk();

        $this->actingAs($this->stranger, 'sanctum')->putJson($this->url(), $payload)->assertStatus(403);
        $this->actingAs($this->supervisor, 'sanctum')->putJson($this->url(), $payload)->assertStatus(403);
        $this->actingAs($this->analyst, 'sanctum')->putJson($this->url(), $payload)->assertOk();
    }

    // ================================================================== helpers

    /** Matérialise l'enquête et renvoie `dataset.materialized_at` tel que le dictionnaire l'expose. */
    private function materialize(): string
    {
        Submission::factory()->create($this->submissionAttributes(['region' => 'nord', 'satisfaction' => '5', 'age' => 30]));
        app(SurveyMaterializationService::class)->materialize($this->survey->refresh());

        return (string) $this->actingAs($this->analyst, 'sanctum')
            ->getJson('/api/surveys/'.$this->survey->id.'/analysis/dictionary')
            ->json('data.dataset.materialized_at');
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function submissionAttributes(array $answers): array
    {
        return [
            'survey_id' => $this->survey->id,
            'survey_version_id' => $this->version->id,
            'project_id' => $this->survey->project_id,
            'enumerator_id' => $this->enumerator->id,
            'answers' => $answers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function definition(): array
    {
        $definition = SurveyVersionFactory::minimalDefinition();
        $definition['choice_lists'] = [
            'regions' => [['name' => 'nord', 'label' => ['fr' => 'Nord']], ['name' => 'sud', 'label' => ['fr' => 'Sud']]],
            'echelle' => array_map(fn ($n) => ['name' => (string) $n, 'label' => ['fr' => 'Niveau '.$n]], range(1, 5)),
        ];
        $definition['sections'] = [[
            'key' => 'S1',
            'label' => ['fr' => 'Section unique'],
            'items' => [
                ['key' => 'region', 'type' => 'select_one', 'label' => ['fr' => 'Région'], 'choices' => 'regions'],
                ['key' => 'satisfaction', 'type' => 'select_one', 'label' => ['fr' => 'Satisfaction'], 'choices' => 'echelle'],
                ['key' => 'age', 'type' => 'integer', 'label' => ['fr' => 'Âge']],
                ['key' => 'telephone', 'type' => 'text', 'label' => ['fr' => 'Téléphone'], 'tags' => ['pii']],
            ],
        ]];

        return $definition;
    }
}
