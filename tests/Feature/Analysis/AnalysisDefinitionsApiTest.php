<?php

namespace Tests\Feature\Analysis;

use App\Enums\UserRole;
use App\Models\ProjectMember;
use App\Models\Survey;
use App\Models\SurveyAnalysisDefinition;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\User;
use Database\Factories\SurveyVersionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Analyse native, chantier 5 : segments enregistrés et variables calculées
 * (`…/analysis/segments`, `…/analysis/computed-variables`). Données synthétiques.
 */
class AnalysisDefinitionsApiTest extends TestCase
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

    private function url(string $resource, ?string $key = null): string
    {
        return '/api/surveys/'.$this->survey->id.'/analysis/'.$resource.($key === null ? '' : '/'.$key);
    }

    public function test_segments_are_created_listed_updated_and_deleted(): void
    {
        $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('segments'))->assertOk()->assertJsonPath('data', []);

        $expression = ['and' => [['==' => [['var' => 'region'], 'nord']], ['>=' => [['var' => 'age'], 30]]]];
        $created = $this->actingAs($this->analyst, 'sanctum')->postJson($this->url('segments'), [
            'label' => 'Nord, 30 ans et plus',
            'description' => 'Cible prioritaire',
            'expression' => $expression,
        ])->assertStatus(201);

        $created->assertJsonPath('data.key', 'nord_30_ans_et_plus')
            ->assertJsonPath('data.label', 'Nord, 30 ans et plus')
            ->assertJsonPath('data.expression', $expression)
            ->assertJsonPath('data.references', ['region', 'age'])
            ->assertJsonPath('data.missing', [])
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.updated_by', ['id' => $this->analyst->id, 'name' => 'Analyste Test'])
            ->assertJsonMissingPath('data.level');

        // Clé explicite, métadonnée de soumission et opérateurs variés.
        $this->postJson($this->url('segments'), [
            'key' => 'mobile_recent',
            'label' => 'Collecte mobile',
            'expression' => ['in' => [['var' => '_canal'], ['mobile', 'web']]],
        ])->assertStatus(201)->assertJsonPath('data.key', 'mobile_recent');

        $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('segments'))->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.key', 'mobile_recent');

        $this->actingAs($this->analyst, 'sanctum')->putJson($this->url('segments', 'mobile_recent'), [
            'label' => 'Collecte mobile uniquement',
            'expression' => ['==' => [['var' => '_canal'], 'mobile']],
        ])->assertOk()
            ->assertJsonPath('data.key', 'mobile_recent')
            ->assertJsonPath('data.label', 'Collecte mobile uniquement')
            ->assertJsonPath('data.references', ['_canal']);

        $this->putJson($this->url('segments', 'inexistant'), ['label' => 'x', 'expression' => ['!' => [true]]])->assertStatus(404);

        $this->deleteJson($this->url('segments', 'mobile_recent'))->assertStatus(204);
        $this->deleteJson($this->url('segments', 'mobile_recent'))->assertStatus(404);
        $this->assertSame(1, SurveyAnalysisDefinition::query()->count());
    }

    public function test_computed_variables_carry_a_level_and_can_be_used_by_segments(): void
    {
        $variable = $this->actingAs($this->analyst, 'sanctum')->postJson($this->url('computed-variables'), [
            'key' => 'tranche_age',
            'label' => "Tranche d'âge",
            'level' => 'ordinal',
            'expression' => ['if' => [['<' => [['var' => 'age'], 30]], 'jeune', ['<' => [['var' => 'age'], 60]], 'adulte', 'senior']],
        ])->assertStatus(201);
        $variable->assertJsonPath('data.level', 'ordinal')->assertJsonPath('data.references', ['age']);

        // Un segment peut citer la variable calculée ; une variable calculée ne peut pas en citer une autre.
        $this->postJson($this->url('segments'), [
            'key' => 'seniors',
            'label' => 'Seniors',
            'expression' => ['==' => [['var' => 'tranche_age'], 'senior']],
        ])->assertStatus(201);

        $this->postJson($this->url('computed-variables'), [
            'key' => 'est_senior',
            'label' => 'Est senior',
            'level' => 'nominal',
            'expression' => ['==' => [['var' => 'tranche_age'], 'senior']],
        ])->assertStatus(422)->assertJsonValidationErrors(['expression.==.0.var'], 'errors');

        // Les deux ressources sont étanches.
        $this->getJson($this->url('computed-variables'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'tranche_age');
        $this->getJson($this->url('segments'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'seniors');

        // Tant qu'un segment la cite, la variable ne peut pas être supprimée.
        $this->deleteJson($this->url('computed-variables', 'tranche_age'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'variable_in_use')
            ->assertJsonPath('segments', ['seniors']);
        $this->deleteJson($this->url('segments', 'seniors'))->assertStatus(204);
        $this->deleteJson($this->url('computed-variables', 'tranche_age'))->assertStatus(204);
    }

    public function test_definitions_are_validated_like_dfs_logic_expressions(): void
    {
        $this->actingAs($this->analyst, 'sanctum');

        $cases = [
            'label' => ['label' => '', 'expression' => ['!' => [true]]],
            'expression' => ['label' => 'Sans expression'],
            'expression.var' => ['label' => 'Variable inconnue', 'expression' => ['var' => 'inconnue']],
            'expression.==.0.var' => ['label' => 'Donnée personnelle', 'expression' => ['==' => [['var' => 'telephone'], '699']]],
            'expression.contient' => ['label' => 'Opérateur inconnu', 'expression' => ['contient' => [['var' => 'region'], 'n']]],
            'expression.==' => ['label' => 'Mauvaise arité', 'expression' => ['==' => [['var' => 'region']]]],
            'expression.and.0' => ['label' => 'Deux opérateurs', 'expression' => ['and' => [['==' => [1, 1], '!=' => [1, 2]]]]],
            'key' => ['key' => 'Clé Invalide', 'label' => 'Clé', 'expression' => ['!' => [true]]],
        ];
        foreach ($cases as $path => $payload) {
            $this->postJson($this->url('segments'), $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors([$path], 'errors');
        }

        // Variable calculée : niveau obligatoire, clé distincte des variables du dictionnaire.
        $this->postJson($this->url('computed-variables'), ['label' => 'Sans niveau', 'expression' => ['+' => [['var' => 'age'], 1]]])
            ->assertStatus(422)->assertJsonValidationErrors(['level'], 'errors');
        $this->postJson($this->url('computed-variables'), ['key' => 'age', 'label' => 'Âge bis', 'level' => 'numeric', 'expression' => ['+' => [['var' => 'age'], 1]]])
            ->assertStatus(422)->assertJsonValidationErrors(['key'], 'errors');

        // Clé déjà prise, et clé non modifiable.
        $this->postJson($this->url('segments'), ['key' => 'nord', 'label' => 'Nord', 'expression' => ['==' => [['var' => 'region'], 'nord']]])->assertStatus(201);
        $this->postJson($this->url('segments'), ['key' => 'nord', 'label' => 'Nord bis', 'expression' => ['==' => [['var' => 'region'], 'nord']]])
            ->assertStatus(422)->assertJsonValidationErrors(['key'], 'errors');
        $this->putJson($this->url('segments', 'nord'), ['key' => 'nord2', 'label' => 'Nord', 'expression' => ['==' => [['var' => 'region'], 'nord']]])
            ->assertStatus(422)->assertJsonValidationErrors(['key'], 'errors');

        $this->assertSame(1, SurveyAnalysisDefinition::query()->count());
    }

    public function test_a_definition_citing_a_variable_that_left_the_dictionary_is_flagged(): void
    {
        $this->actingAs($this->analyst, 'sanctum')->postJson($this->url('segments'), [
            'key' => 'nord', 'label' => 'Nord', 'expression' => ['==' => [['var' => 'region'], 'nord']],
        ])->assertStatus(201);

        $definition = self::definition();
        $definition['sections'][0]['items'] = array_values(array_filter($definition['sections'][0]['items'], fn ($item) => $item['key'] !== 'region'));
        $this->version->forceFill(['definition' => $definition])->save();

        $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('segments'))->assertOk()
            ->assertJsonPath('data.0.valid', false)
            ->assertJsonPath('data.0.missing', ['region']);
    }

    public function test_definitions_are_readable_by_supervisors_and_writable_by_analysts_only(): void
    {
        $payload = ['label' => 'Nord', 'expression' => ['==' => [['var' => 'region'], 'nord']]];

        foreach (['segments', 'computed-variables'] as $resource) {
            $this->app['auth']->forgetGuards();
            $this->getJson($this->url($resource))->assertStatus(401);
            $this->postJson($this->url($resource), $payload)->assertStatus(401);

            $this->actingAs($this->stranger, 'sanctum')->getJson($this->url($resource))->assertStatus(403);
            $this->actingAs($this->enumerator, 'sanctum')->getJson($this->url($resource))->assertStatus(403);
            $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url($resource))->assertOk();

            $this->actingAs($this->supervisor, 'sanctum')->postJson($this->url($resource), $payload)->assertStatus(403);
            $this->actingAs($this->supervisor, 'sanctum')->putJson($this->url($resource, 'nord'), $payload)->assertStatus(403);
            $this->actingAs($this->supervisor, 'sanctum')->deleteJson($this->url($resource, 'nord'))->assertStatus(403);
        }

        $this->actingAs($this->analyst, 'sanctum')->postJson($this->url('segments'), $payload)->assertStatus(201);
    }

    /**
     * @return array<string, mixed>
     */
    private static function definition(): array
    {
        $definition = SurveyVersionFactory::minimalDefinition();
        $definition['choice_lists'] = [
            'regions' => [['name' => 'nord', 'label' => ['fr' => 'Nord']], ['name' => 'sud', 'label' => ['fr' => 'Sud']]],
        ];
        $definition['sections'] = [[
            'key' => 'S1',
            'label' => ['fr' => 'Section unique'],
            'items' => [
                ['key' => 'region', 'type' => 'select_one', 'label' => ['fr' => 'Région'], 'choices' => 'regions'],
                ['key' => 'age', 'type' => 'integer', 'label' => ['fr' => 'Âge']],
                ['key' => 'telephone', 'type' => 'text', 'label' => ['fr' => 'Téléphone'], 'tags' => ['pii']],
            ],
        ]];

        return $definition;
    }
}
