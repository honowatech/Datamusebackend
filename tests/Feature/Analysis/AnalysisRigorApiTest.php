<?php

namespace Tests\Feature\Analysis;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\FollowUpEntry;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\SurveyWeightingPlan;
use App\Models\User;
use Database\Factories\SurveyVersionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Analyse native, chantier 3 : `GET|PUT …/analysis/weighting`, `GET …/analysis/quotas`,
 * `GET …/analysis/cohorts`. Données entièrement synthétiques.
 */
class AnalysisRigorApiTest extends TestCase
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

    private function url(string $suffix): string
    {
        return '/api/surveys/'.$this->survey->id.'/analysis/'.$suffix;
    }

    // ================================================================== pondération

    public function test_weighting_plan_is_empty_until_saved_then_round_trips_with_normalized_shares(): void
    {
        $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('weighting'))
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => [
                'enabled' => false, 'margins' => [], 'bounds' => ['min' => null, 'max' => null],
                'updated_at' => null, 'updated_by' => null, 'warnings' => [],
            ]]);

        $payload = [
            'margins' => [
                // Effectifs de population : ramenés à des parts de somme 1.
                ['variable' => 'sexe', 'targets' => [['code' => 'f', 'share' => 520], ['code' => 'h', 'share' => 480]]],
                ['variable' => 'region', 'targets' => [['code' => 'nord', 'share' => 0.25], ['code' => 'sud', 'share' => 0.75]]],
            ],
            'bounds' => ['min' => 0.2, 'max' => 5],
        ];

        $saved = $this->actingAs($this->analyst, 'sanctum')->putJson($this->url('weighting'), $payload)->assertOk();
        $saved->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.margins.0.variable', 'sexe')
            ->assertJsonPath('data.margins.0.targets', [['code' => 'f', 'share' => 0.52], ['code' => 'h', 'share' => 0.48]])
            ->assertJsonPath('data.margins.1.targets.1', ['code' => 'sud', 'share' => 0.75])
            ->assertJsonPath('data.bounds', ['min' => 0.2, 'max' => 5])
            ->assertJsonPath('data.updated_by', ['id' => $this->analyst->id, 'name' => 'Analyste Test'])
            ->assertJsonPath('data.warnings', []);
        $this->assertNotNull($saved->json('data.updated_at'));

        // Relu à l'identique par un superviseur ; un second PUT remplace le plan (un seul par enquête).
        $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('weighting'))
            ->assertOk()
            ->assertJsonPath('data.margins', $saved->json('data.margins'));

        $this->actingAs($this->analyst, 'sanctum')->putJson($this->url('weighting'), ['enabled' => false, 'margins' => []])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.margins', [])
            ->assertJsonPath('data.bounds', ['min' => null, 'max' => null]);
        $this->assertSame(1, SurveyWeightingPlan::query()->where('survey_id', $this->survey->id)->count());
    }

    public function test_weighting_plan_is_validated_against_the_dictionary(): void
    {
        $response = $this->actingAs($this->analyst, 'sanctum')->putJson($this->url('weighting'), [
            'margins' => [
                ['variable' => 'age', 'targets' => [['code' => '1', 'share' => 1], ['code' => '2', 'share' => 1]]],
                ['variable' => 'telephone', 'targets' => [['code' => 'a', 'share' => 1], ['code' => 'b', 'share' => 1]]],
                ['variable' => 'inconnue', 'targets' => []],
                ['variable' => 'sexe', 'targets' => [['code' => 'f', 'share' => 0.5], ['code' => 'x', 'share' => 0.5], ['code' => 'h', 'share' => -1]]],
                ['variable' => 'region', 'targets' => [['code' => 'nord', 'share' => 1]]],
                ['variable' => 'region', 'targets' => [['code' => 'nord', 'share' => 1], ['code' => 'sud', 'share' => 1]]],
            ],
            'bounds' => ['min' => 2, 'max' => 0.5],
        ])->assertStatus(422)->assertJsonPath('success', false);

        $errors = $response->json('errors');
        foreach ([
            'margins.0.variable', 'margins.1.variable', 'margins.2.variable',
            'margins.3.targets.1.code', 'margins.3.targets.2.share', 'margins.4.targets',
            'bounds.min', 'bounds.max',
        ] as $path) {
            $this->assertArrayHasKey($path, $errors, "erreur attendue sur « $path »");
        }
        $this->assertSame(0, SurveyWeightingPlan::query()->count(), 'un plan invalide ne doit rien enregistrer');

        // Une même variable ne peut porter deux marges.
        $this->actingAs($this->analyst, 'sanctum')->putJson($this->url('weighting'), ['margins' => [
            ['variable' => 'region', 'targets' => [['code' => 'nord', 'share' => 1], ['code' => 'sud', 'share' => 1]]],
            ['variable' => 'region', 'targets' => [['code' => 'nord', 'share' => 1], ['code' => 'sud', 'share' => 3]]],
        ]])->assertStatus(422)->assertJsonValidationErrors(['margins.1.variable'], 'errors');
    }

    public function test_weighting_reports_margins_made_stale_by_a_new_questionnaire_version(): void
    {
        $this->actingAs($this->analyst, 'sanctum')->putJson($this->url('weighting'), ['margins' => [
            ['variable' => 'sexe', 'targets' => [['code' => 'f', 'share' => 1], ['code' => 'h', 'share' => 1]]],
        ]])->assertOk();

        $definition = self::definition();
        $definition['sections'][0]['items'] = array_values(array_filter($definition['sections'][0]['items'], fn ($item) => $item['key'] !== 'sexe'));
        $this->version->forceFill(['definition' => $definition])->save();

        $warnings = $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('weighting'))->assertOk()->json('data.warnings');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('sexe', $warnings[0]);
    }

    public function test_weighting_is_readable_by_supervisors_and_writable_by_analysts_only(): void
    {
        $payload = ['margins' => [['variable' => 'sexe', 'targets' => [['code' => 'f', 'share' => 1], ['code' => 'h', 'share' => 1]]]]];

        $this->getJson($this->url('weighting'))->assertStatus(401);
        $this->putJson($this->url('weighting'), $payload)->assertStatus(401);

        $this->actingAs($this->stranger, 'sanctum')->getJson($this->url('weighting'))->assertStatus(403);
        $this->actingAs($this->enumerator, 'sanctum')->getJson($this->url('weighting'))->assertStatus(403);
        $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('weighting'))->assertOk();

        $this->actingAs($this->stranger, 'sanctum')->putJson($this->url('weighting'), $payload)->assertStatus(403);
        $this->actingAs($this->supervisor, 'sanctum')->putJson($this->url('weighting'), $payload)->assertStatus(403);
        $this->actingAs($this->analyst, 'sanctum')->putJson($this->url('weighting'), $payload)->assertOk();
    }

    // ================================================================== quotas

    public function test_quotas_report_progress_remaining_and_breakdown(): void
    {
        foreach (['f', 'f', 'f', 'h'] as $sexe) {
            Submission::factory()->create($this->submissionAttributes(['sexe' => $sexe, 'region' => 'nord']));
        }
        // Les fiches rejetées et hors cible ne comptent pas.
        Submission::factory()->create($this->submissionAttributes(['sexe' => 'h', 'region' => 'sud']) + ['status' => SubmissionStatus::Rejected]);

        $this->getJson($this->url('quotas'))->assertStatus(401);
        $this->actingAs($this->stranger, 'sanctum')->getJson($this->url('quotas'))->assertStatus(403);

        $quotas = collect($this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('quotas'))->assertOk()->json('data.quotas'))->keyBy('key');

        $this->assertSame(['total', 'par_sexe'], $quotas->keys()->all());
        $this->assertSame('Total de fiches', $quotas['total']['label']);
        $this->assertSame(10, $quotas['total']['target']);
        $this->assertSame(4, $quotas['total']['current']);
        $this->assertSame(6, $quotas['total']['remaining']);
        $this->assertFalse($quotas['total']['reached']);
        $this->assertEquals(40.0, $quotas['total']['pct']);

        $this->assertSame('answer', $quotas['par_sexe']['scope']);
        $this->assertSame(3, $quotas['par_sexe']['current']);
        $this->assertTrue($quotas['par_sexe']['reached']);
        $this->assertSame(0, $quotas['par_sexe']['remaining']);
        $this->assertSame(
            [['value' => 'f', 'current' => 3, 'target' => 3], ['value' => 'h', 'current' => 1, 'target' => 3]],
            $quotas['par_sexe']['breakdown'],
        );
    }

    // ================================================================== cohortes

    public function test_cohorts_report_retention_per_follow_up_stage_and_per_enrollment_period(): void
    {
        // Semaine 1 (lundi 2 mars 2026) : 4 entretiens ; semaine 2 (lundi 9 mars) : 2 entretiens.
        $week1 = array_map(fn () => $this->submissionEndedAt('2026-03-03T10:00:00+01:00'), range(1, 4));
        $week2 = array_map(fn () => $this->submissionEndedAt('2026-03-10T10:00:00+01:00'), range(1, 2));
        // Un entretien sans suivi n'entre dans aucune cohorte ; une fiche rejetée non plus.
        $this->submissionEndedAt('2026-03-04T10:00:00+01:00');
        $rejected = $this->submissionEndedAt('2026-03-04T10:00:00+01:00', SubmissionStatus::Rejected);
        FollowUpEntry::factory()->stage('j4', 4)->done()->create(['submission_id' => $rejected->id]);

        // J+4 : semaine 1 → 3 faits, 1 manqué ; semaine 2 → 1 fait, 1 en attente.
        foreach ($week1 as $i => $submission) {
            $state = FollowUpEntry::factory()->stage('j4', 4);
            ($i < 3 ? $state->done() : $state->missed())->create(['submission_id' => $submission->id]);
        }
        FollowUpEntry::factory()->stage('j4', 4)->done()->create(['submission_id' => $week2[0]->id]);
        FollowUpEntry::factory()->stage('j4', 4)->create(['submission_id' => $week2[1]->id]);
        // J+7 : semaine 1 → 2 faits, 1 manqué (le 4e n'est pas concerné) ; semaine 2 → rien de planifié.
        FollowUpEntry::factory()->stage('j7', 7)->done()->create(['submission_id' => $week1[0]->id]);
        FollowUpEntry::factory()->stage('j7', 7)->done()->create(['submission_id' => $week1[1]->id]);
        FollowUpEntry::factory()->stage('j7', 7)->missed()->create(['submission_id' => $week1[2]->id]);

        $data = $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('cohorts'))->assertOk()->json('data');

        $this->assertSame('week', $data['by']);
        $this->assertSame(
            [
                ['key' => 'j4', 'label' => 'Suivi à 4 jours', 'due_offset_days' => 4, 'window_days' => 3],
                ['key' => 'j7', 'label' => 'Suivi à 7 jours', 'due_offset_days' => 7, 'window_days' => 3],
            ],
            $data['stages'],
        );

        $this->assertSame(6, $data['overall']['base']);
        $this->assertSame(
            ['key' => 'j4', 'planned' => 6, 'done' => 4, 'missed' => 1, 'skipped' => 0, 'pending' => 1, 'retention' => 0.6667, 'response_rate' => 0.8],
            $data['overall']['stages'][0],
        );
        $this->assertSame(
            ['key' => 'j7', 'planned' => 3, 'done' => 2, 'missed' => 1, 'skipped' => 0, 'pending' => 0, 'retention' => 0.3333, 'response_rate' => 0.6667],
            $data['overall']['stages'][1],
        );

        $this->assertSame(['2026-W10', '2026-W11'], array_column($data['cohorts'], 'period'));
        $this->assertSame(['2026-03-02', '2026-03-09'], array_column($data['cohorts'], 'start'));
        $this->assertSame([4, 2], array_column($data['cohorts'], 'base'));
        $this->assertEquals(0.75, $data['cohorts'][0]['stages'][0]['retention']);
        $this->assertEquals(0.5, $data['cohorts'][0]['stages'][1]['retention']);
        $this->assertEquals(0.5, $data['cohorts'][1]['stages'][0]['retention']);
        $this->assertSame(0, $data['cohorts'][1]['stages'][1]['planned']);
        $this->assertNull($data['cohorts'][1]['stages'][1]['response_rate']);

        // Regroupement par mois, libellés en anglais, filtre de dates.
        $monthly = $this->getJson($this->url('cohorts').'?by=month&lang=en&from=2026-03-09')->assertOk()->json('data');
        $this->assertSame('4-day follow-up', $monthly['stages'][0]['label']);
        $this->assertSame(['2026-03'], array_column($monthly['cohorts'], 'period'));
        $this->assertSame(2, $monthly['overall']['base']);

        $this->getJson($this->url('cohorts').'?by=year')->assertStatus(422);
        $this->getJson($this->url('cohorts').'?tz=Mars/Olympus')->assertStatus(422);
    }

    public function test_cohorts_are_empty_without_follow_ups_and_follow_the_stats_authorization(): void
    {
        $this->getJson($this->url('cohorts'))->assertStatus(401);
        $this->actingAs($this->stranger, 'sanctum')->getJson($this->url('cohorts'))->assertStatus(403);
        $this->actingAs($this->enumerator, 'sanctum')->getJson($this->url('cohorts'))->assertStatus(403);

        $data = $this->actingAs($this->analyst, 'sanctum')->getJson($this->url('cohorts'))->assertOk()->json('data');
        $this->assertSame(0, $data['overall']['base']);
        $this->assertSame([], $data['cohorts']);
        $this->assertNull($data['overall']['stages'][0]['retention']);
    }

    // ================================================================== helpers

    private function submissionEndedAt(string $endedAt, SubmissionStatus $status = SubmissionStatus::Submitted): Submission
    {
        $ended = Carbon::parse($endedAt);

        return Submission::factory()->create($this->submissionAttributes(['sexe' => 'f', 'region' => 'nord']) + [
            'status' => $status,
            'started_at' => $ended->copy()->subMinutes(12),
            'ended_at' => $ended,
            'received_at' => $ended->copy()->addMinutes(5),
        ]);
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
        $definition['settings']['languages'] = ['fr', 'en'];
        $definition['settings']['quotas'] = [
            ['key' => 'total', 'label' => ['fr' => 'Total de fiches'], 'target' => 10, 'scope' => 'survey'],
            ['key' => 'par_sexe', 'label' => ['fr' => 'Fiches par sexe'], 'target' => 3, 'scope' => 'answer', 'question' => 'sexe'],
        ];
        $definition['choice_lists'] = [
            'sexes' => [['name' => 'f', 'label' => ['fr' => 'Femme']], ['name' => 'h', 'label' => ['fr' => 'Homme']]],
            'regions' => [['name' => 'nord', 'label' => ['fr' => 'Nord']], ['name' => 'sud', 'label' => ['fr' => 'Sud']]],
        ];
        $definition['sections'] = [[
            'key' => 'S1',
            'label' => ['fr' => 'Section unique'],
            'items' => [
                ['key' => 'sexe', 'type' => 'select_one', 'label' => ['fr' => 'Sexe'], 'choices' => 'sexes'],
                ['key' => 'region', 'type' => 'select_one', 'label' => ['fr' => 'Région'], 'choices' => 'regions'],
                ['key' => 'age', 'type' => 'integer', 'label' => ['fr' => 'Âge']],
                ['key' => 'telephone', 'type' => 'text', 'label' => ['fr' => 'Téléphone'], 'tags' => ['pii']],
            ],
        ]];
        $definition['follow_up_stages'] = [
            ['key' => 'j4', 'label' => ['fr' => 'Suivi à 4 jours', 'en' => '4-day follow-up'], 'due_offset_days' => 4, 'window_days' => 3, 'items' => [
                ['key' => 'j4_contact', 'type' => 'select_one', 'label' => ['fr' => 'Contact établi'], 'choices' => 'sexes'],
            ]],
            ['key' => 'j7', 'label' => ['fr' => 'Suivi à 7 jours', 'en' => '7-day follow-up'], 'due_offset_days' => 7, 'window_days' => 3, 'items' => [
                ['key' => 'j7_note', 'type' => 'integer', 'label' => ['fr' => 'Note']],
            ]],
        ];

        return $definition;
    }
}
