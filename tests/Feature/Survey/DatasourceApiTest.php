<?php

namespace Tests\Feature\Survey;

use App\Enums\JobKind;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Events\SurveyPublished;
use App\Jobs\MaterializeSurveyDatasourceJob;
use App\Models\AiJob;
use App\Models\EnumeratorAssignment;
use App\Models\ProjectMember;
use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\TargetDatabase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * B-09b : matérialisation automatique et API `Datasource`.
 *
 * Couvre l'état `GET /surveys/{id}/datasource`, le rebuild `202` (job `materialize`, idempotent),
 * la mise en file automatique après publication et après synchronisation mobile, et l'accès partagé
 * à la source (`TargetDatabase::accessibleBy`) depuis `/chat/execute-sql` et `GET /target-dbs`.
 */
class DatasourceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $analyst;

    private User $enumerator;

    private User $stranger;

    private SurveyProject $project;

    private Survey $survey;

    private SurveyVersion $version;

    /** @var array<string, mixed> */
    private static array $fixtureCache = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => UserRole::Analyste]);
        $this->analyst = User::factory()->create(['role' => UserRole::Analyste]);
        $this->enumerator = User::factory()->create(['role' => UserRole::Enqueteur]);
        $this->stranger = User::factory()->create(['role' => UserRole::Analyste]);

        $this->project = SurveyProject::factory()->create(['owner_id' => $this->owner->id, 'name' => 'MunaGo Douala']);
        ProjectMember::factory()->analyste()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
        ProjectMember::factory()->analyste()->create(['project_id' => $this->project->id, 'user_id' => $this->analyst->id]);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $this->enumerator->id, 'zone' => 'Bonamoussadi']);

        $this->survey = Survey::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'title' => 'MunaGo — terrain',
            'slug' => 'munago-datasource',
        ]);
        $this->version = SurveyVersion::factory()->munago()->published()->create([
            'survey_id' => $this->survey->id,
            'published_by' => $this->owner->id,
        ]);
        $this->survey->refresh();

        EnumeratorAssignment::factory()->create([
            'survey_id' => $this->survey->id,
            'user_id' => $this->enumerator->id,
            'zone' => 'Bonamoussadi',
            'quota_target' => 10,
        ]);
    }

    // ================================================================== GET /surveys/{id}/datasource

    public function test_datasource_is_empty_until_the_first_materialization(): void
    {
        $this->actingAs($this->analyst, 'sanctum')
            ->getJson('/api/surveys/'.$this->survey->id.'/datasource')
            ->assertOk()
            ->assertJsonPath('data.survey_id', $this->survey->id)
            ->assertJsonPath('data.status', 'empty')
            ->assertJsonPath('data.target_database_id', null)
            ->assertJsonPath('data.target_database_name', 'Enquête : MunaGo — terrain')
            ->assertJsonPath('data.file_version', 0)
            ->assertJsonPath('data.row_count', 0)
            ->assertJsonPath('data.tables', []);
    }

    public function test_datasource_is_404_before_publication_and_403_for_an_outsider(): void
    {
        $draftSurvey = Survey::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'title' => 'Brouillon',
            'slug' => 'brouillon-datasource',
        ]);

        $this->actingAs($this->analyst, 'sanctum')
            ->getJson('/api/surveys/'.$draftSurvey->id.'/datasource')
            ->assertStatus(404);

        $this->actingAs($this->stranger, 'sanctum')
            ->getJson('/api/surveys/'.$this->survey->id.'/datasource')
            ->assertStatus(403);
    }

    // ================================================================== POST …/datasource/rebuild

    public function test_rebuild_returns_202_and_a_materialize_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->analyst, 'sanctum')
            ->postJson('/api/surveys/'.$this->survey->id.'/datasource/rebuild');

        $response->assertStatus(202)
            ->assertJsonPath('data.kind', 'materialize')
            ->assertJsonStructure(['data' => ['job_id'], 'meta' => ['job_id']]);

        $job = AiJob::query()->where('uuid', $response->json('data.job_id'))->firstOrFail();
        $this->assertSame(JobKind::Materialize, $job->kind);
        $this->assertSame($this->survey->id, $job->survey_id);
        $this->assertSame(JobStatus::Queued, $job->status);

        Queue::assertPushed(
            MaterializeSurveyDatasourceJob::class,
            fn (MaterializeSurveyDatasourceJob $j) => $j->surveyId === $this->survey->id && $j->jobUuid === $job->uuid,
        );

        // Idempotent : tant que le job est en file, le même `job_id` est renvoyé.
        $this->actingAs($this->analyst, 'sanctum')
            ->postJson('/api/surveys/'.$this->survey->id.'/datasource/rebuild')
            ->assertStatus(202)
            ->assertJsonPath('data.job_id', $job->uuid);

        $this->assertSame(1, AiJob::query()->kind(JobKind::Materialize)->count());
    }

    public function test_rebuild_with_sync_builds_the_sqlite_file_and_completes_the_job(): void
    {
        $this->receiveSubmission();

        $response = $this->actingAs($this->analyst, 'sanctum')
            ->postJson('/api/surveys/'.$this->survey->id.'/datasource/rebuild?sync=1');

        $response->assertStatus(202);
        $job = AiJob::query()->where('uuid', $response->json('data.job_id'))->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status);
        $this->assertSame('datasources/'.$this->survey->id, $job->result_ref);

        $state = $this->actingAs($this->analyst, 'sanctum')
            ->getJson('/api/surveys/'.$this->survey->id.'/datasource')
            ->assertOk()
            ->json('data');

        $this->assertSame('ready', $state['status']);
        $this->assertFalse($state['dirty']);
        $this->assertSame(1, $state['row_count']);
        $this->assertGreaterThanOrEqual(1, $state['file_version']);
        $this->assertNull($state['last_error']);
        $this->assertContains('reponses', $state['tables']);
        $this->assertContains('suivi', $state['tables']);

        $targetDb = TargetDatabase::findOrFail($state['target_database_id']);
        $this->assertSame($this->owner->id, $targetDb->user_id, 'la source appartient au propriétaire du projet');
        $this->assertFileExists($targetDb->database);
    }

    public function test_an_enumerator_cannot_rebuild(): void
    {
        Queue::fake();

        $this->actingAs($this->enumerator, 'sanctum')
            ->postJson('/api/surveys/'.$this->survey->id.'/datasource/rebuild')
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    // ================================================================== déclenchement automatique

    public function test_publishing_a_survey_creates_the_datasource_and_queues_a_rebuild(): void
    {
        Queue::fake();

        event(new SurveyPublished($this->survey, $this->version));

        $datasource = SurveyDatasource::query()->where('survey_id', $this->survey->id)->firstOrFail();
        $this->assertTrue($datasource->dirty);
        $this->assertNotNull($datasource->dirty_since);

        Queue::assertPushed(
            MaterializeSurveyDatasourceJob::class,
            fn (MaterializeSurveyDatasourceJob $j) => $j->surveyId === $this->survey->id,
        );
    }

    public function test_a_synced_submission_marks_the_datasource_dirty_and_queues_a_rebuild(): void
    {
        // Première matérialisation (la datasource existe désormais).
        $this->actingAs($this->analyst, 'sanctum')
            ->postJson('/api/surveys/'.$this->survey->id.'/datasource/rebuild?sync=1')
            ->assertStatus(202);

        $datasource = SurveyDatasource::query()->where('survey_id', $this->survey->id)->firstOrFail();
        $this->assertFalse($datasource->dirty);

        Queue::fake();

        $this->actingAs($this->enumerator, 'sanctum')
            ->postJson('/api/mobile/submissions', ['submissions' => [$this->payload()]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'accepted');

        $this->assertTrue($datasource->refresh()->dirty);
        Queue::assertPushed(
            MaterializeSurveyDatasourceJob::class,
            fn (MaterializeSurveyDatasourceJob $j) => $j->surveyId === $this->survey->id,
        );
    }

    public function test_a_submission_without_datasource_queues_nothing(): void
    {
        Queue::fake();

        $this->actingAs($this->enumerator, 'sanctum')
            ->postJson('/api/mobile/submissions', ['submissions' => [$this->payload()]])
            ->assertOk();

        $this->assertNull(SurveyDatasource::query()->where('survey_id', $this->survey->id)->first());
        Queue::assertNotPushed(MaterializeSurveyDatasourceJob::class);
    }

    public function test_materialize_dirty_command_queues_the_pending_rebuilds(): void
    {
        SurveyDatasource::query()->create(['survey_id' => $this->survey->id, 'dirty' => true, 'dirty_since' => now()]);

        Queue::fake();
        $this->artisan('surveys:materialize-dirty')->assertExitCode(0);
        Queue::assertPushed(MaterializeSurveyDatasourceJob::class, 1);

        // En mode `--sync`, la reconstruction a lieu dans le processus et la datasource redevient propre.
        $this->receiveSubmission();
        $this->artisan('surveys:materialize-dirty --sync')->assertExitCode(0);

        $datasource = SurveyDatasource::query()->where('survey_id', $this->survey->id)->firstOrFail();
        $this->assertFalse($datasource->dirty);
        $this->assertSame(1, $datasource->row_count);
    }

    public function test_a_failed_materialization_records_last_error_without_failing_the_request(): void
    {
        // Aucune version : `SurveyMaterializationService` lève, le job capture et renseigne last_error.
        $orphan = Survey::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'title' => 'Sans version',
            'slug' => 'sans-version',
        ]);

        MaterializeSurveyDatasourceJob::dispatchSync($orphan->id);

        $datasource = SurveyDatasource::query()->where('survey_id', $orphan->id)->firstOrFail();
        $this->assertNotNull($datasource->last_error);
        $this->assertStringContainsString('version', $datasource->last_error);
    }

    public function test_old_datasource_files_are_cleaned_up(): void
    {
        $this->receiveSubmission();

        $this->actingAs($this->analyst, 'sanctum')->postJson('/api/surveys/'.$this->survey->id.'/datasource/rebuild?sync=1');
        $first = TargetDatabase::query()->firstOrFail()->database;

        // Simule un ancien fichier non supprimé (verrou Windows au moment de la bascule).
        $stale = str_replace('_v1.sqlite', '_v0.sqlite', $first);
        file_put_contents($stale, 'stale');
        $this->assertFileExists($stale);

        $this->artisan('datasources:cleanup-old-files')->assertExitCode(0);

        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($first, 'le fichier courant est conservé');
    }

    // ================================================================== accès partagé

    public function test_a_non_owner_analyst_reaches_the_survey_source_from_the_chat_and_target_dbs(): void
    {
        $this->receiveSubmission();
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/surveys/'.$this->survey->id.'/datasource/rebuild?sync=1')
            ->assertStatus(202);

        $targetDb = TargetDatabase::query()->firstOrFail();
        $this->assertSame($this->owner->id, $targetDb->user_id);

        // GET /target-dbs liste la source pour l'analyste non propriétaire.
        $this->actingAs($this->analyst, 'sanctum')
            ->getJson('/api/target-dbs')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Enquête : MunaGo — terrain']);

        // /chat/execute-sql fonctionne sur cette source.
        $this->actingAs($this->analyst, 'sanctum')
            ->postJson('/api/chat/execute-sql', [
                'sql_query' => 'SELECT COUNT(*) AS n FROM reponses',
                'database_id' => $targetDb->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('results.0.n', 1);

        // Un analyste étranger au projet reste hors jeu.
        $this->actingAs($this->stranger, 'sanctum')
            ->getJson('/api/target-dbs')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Enquête : MunaGo — terrain']);

        $this->actingAs($this->stranger, 'sanctum')
            ->postJson('/api/chat/execute-sql', [
                'sql_query' => 'SELECT 1',
                'database_id' => $targetDb->id,
            ])
            ->assertStatus(404);
    }

    public function test_an_enumerator_does_not_see_the_survey_source(): void
    {
        $this->receiveSubmission();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/surveys/'.$this->survey->id.'/datasource/rebuild?sync=1');

        $this->actingAs($this->enumerator, 'sanctum')
            ->getJson('/api/target-dbs')
            ->assertOk()
            ->assertJsonCount(0, 'databases');
    }

    // ================================================================== helpers

    private function receiveSubmission(): void
    {
        $this->actingAs($this->enumerator, 'sanctum')
            ->postJson('/api/mobile/submissions', ['submissions' => [$this->payload()]])
            ->assertOk();
    }

    /**
     * Premier payload de la fixture MunaGo, recalé sur le questionnaire du test.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        if (self::$fixtureCache === []) {
            self::$fixtureCache = json_decode(
                (string) file_get_contents(base_path('../docs/fixtures/munago.submissions.json')),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }

        $row = self::$fixtureCache['submissions'][0];
        $row['survey_id'] = $this->survey->id;
        $row['form_version'] = 1;
        $row['device_id'] = 'android-enq1-a3f1';

        return $row;
    }
}
