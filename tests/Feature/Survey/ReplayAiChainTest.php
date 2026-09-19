<?php

namespace Tests\Feature\Survey;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\AiJob;
use App\Models\ProjectMember;
use App\Models\SurveyReport;
use App\Models\User;
use App\Models\VerbatimCodebook;
use App\Services\LlmReplayProvider;
use App\Services\Survey\ReportContentValidator;
use App\Services\Survey\SurveyInsightContext;
use Database\Seeders\SystemPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * E-03 — la chaîne IA **complète** en mode rejeu (`LLM_DRIVER=replay`) : contrôleur → job → service →
 * fournisseur → validation → persistance.
 *
 * Ce que ces tests vérifient, et qui n'est pas couvert par `LlmReplayProviderTest` :
 *   - aucune clé API n'est exigée par les contrôleurs (`422 errors.provider` ne doit pas apparaître) ;
 *   - la simulation est **marquée** : `ai_jobs.provider = replay`, message suffixé « (simulé) »,
 *     `survey_reports.provider/model = replay`, `Job.simulated = true` dans l'API ;
 *   - aucune requête réseau n'est émise (`Http::preventStrayRequests()`).
 *
 * La file de test est `sync` : le job est terminé au retour du `202`.
 */
class ReplayAiChainTest extends TestCase
{
    use RefreshDatabase;

    private MunagoFixtureLoader $fx;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemPromptSeeder::class);

        // Mode rejeu, sans latence, et AUCUNE clé API disponible nulle part.
        config([
            'services.llm.driver' => 'replay',
            'services.llm.replay_latency_min_ms' => 0,
            'services.llm.replay_latency_max_ms' => 0,
            'services.gemini.key' => null,
            'services.deepseek.key' => null,
        ]);

        Http::preventStrayRequests();
        Http::fake();

        $this->fx = MunagoFixtureLoader::load();
        $this->analyst = User::factory()->create(['role' => UserRole::Analyste]);
        ProjectMember::factory()->analyste()->create([
            'project_id' => $this->fx->project->id,
            'user_id' => $this->analyst->id,
        ]);
    }

    public function test_form_generation_needs_no_api_key_and_is_marked_simulated(): void
    {
        $response = $this->actingAs($this->analyst)->postJson('/api/surveys/generate', [
            'project_id' => $this->fx->project->id,
            'create' => true,
            'source_text' => "Questionnaire MunaGo — traceur GPS pour enfants.\nTest d'acompte de 5 000 FCFA par Mobile Money.\nF1. Avez-vous au moins un enfant scolarisé ? Non → STOP.",
            'title' => 'MunaGo — rejeu',
        ]);

        $response->assertStatus(202);
        Http::assertNothingSent();

        $job = AiJob::query()->where('uuid', $response->json('data.job_id'))->firstOrFail();

        $this->assertSame(JobStatus::Done, $job->status, 'échec : '.$job->error);
        $this->assertSame(LlmReplayProvider::PROVIDER, $job->provider);
        $this->assertTrue($job->isSimulated());
        $this->assertStringEndsWith(' (simulé)', (string) $job->message);

        // Le questionnaire est réellement créé et publiable : le DFS rejoué a passé le validateur.
        $this->assertNotNull($job->survey_id);
        $this->assertStringStartsWith('surveys/', (string) $job->result_ref);
        $this->assertSame([], $job->output['warnings'] ?? []);
    }

    public function test_the_job_endpoint_exposes_the_simulation(): void
    {
        $uuid = $this->actingAs($this->analyst)->postJson('/api/surveys/generate', [
            'project_id' => $this->fx->project->id,
            'create' => false,
            'source_text' => 'Questionnaire MunaGo — traceur GPS pour enfants, test d\'acompte.',
        ])->json('data.job_id');

        $this->actingAs($this->analyst)->getJson('/api/jobs/'.$uuid)
            ->assertOk()
            ->assertJsonPath('data.provider', 'replay')
            ->assertJsonPath('data.simulated', true)
            ->assertJsonPath('data.status', 'done');
    }

    public function test_verbatim_discover_then_classify_writes_real_codings(): void
    {
        $response = $this->actingAs($this->analyst)->postJson(
            "/api/surveys/{$this->fx->survey->id}/verbatims/classify",
            ['question_key' => 'q6_freins', 'mode' => 'discover', 'max_themes' => 9],
        );

        $response->assertStatus(202);
        Http::assertNothingSent();

        $job = AiJob::query()->where('uuid', $response->json('data.job_id'))->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status, 'échec : '.$job->error);
        $this->assertSame(LlmReplayProvider::PROVIDER, $job->provider);
        $this->assertStringEndsWith(' (simulé)', (string) $job->message);

        $codebook = VerbatimCodebook::query()->findOrFail($job->output['codebook_id']);
        $keys = $codebook->themeKeys();

        $this->assertContains('prix_abonnement', $keys);
        $this->assertContains('casse_perte_batterie', $keys);
        $this->assertLessThanOrEqual(9, count($keys));

        // Les codages portent sur les vraies soumissions, avec des thèmes du livre de codes.
        $this->assertGreaterThan(30, $job->output['coded']);
        foreach (array_keys($job->output['by_theme']) as $theme) {
            $this->assertContains($theme, $keys);
        }
    }

    public function test_report_generation_marks_the_report_as_replayed(): void
    {
        $this->materialize();

        $response = $this->actingAs($this->analyst)->postJson("/api/surveys/{$this->fx->survey->id}/reports", [
            'title' => 'MunaGo — go / no-go',
            'brief' => 'Faut-il poursuivre le projet ? Décision attendue sur le test d\'acompte.',
            'orientation' => 'commercial',
            'audience' => 'Direction commerciale',
        ]);

        $response->assertStatus(202);
        Http::assertNothingSent();

        $job = AiJob::query()->where('uuid', $response->json('data.job_id'))->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status, 'échec : '.$job->error);

        $report = SurveyReport::query()->findOrFail($job->output['report_id']);

        $this->assertSame(JobStatus::Done, $report->status);
        $this->assertSame(LlmReplayProvider::PROVIDER, $report->provider);
        $this->assertSame(LlmReplayProvider::PROVIDER, $report->model);
        $this->assertGreaterThanOrEqual(4, count($report->content_json['sections']));
        $this->assertNotEmpty($report->content_md);

        // Aucun marqueur de substitution ne doit subsister dans le rendu.
        $this->assertStringNotContainsString('{{', (string) $report->content_md);
    }

    public function test_an_invalid_report_is_repaired_before_being_stored(): void
    {
        $this->materialize();

        $response = $this->actingAs($this->analyst)->postJson("/api/surveys/{$this->fx->survey->id}/reports", [
            'title' => 'MunaGo — go / no-go',
            'brief' => 'Décision go/no-go. demo-reparation',
            'orientation' => 'commercial',
            'audience' => 'Direction commerciale',
        ]);

        $response->assertStatus(202);
        $job = AiJob::query()->where('uuid', $response->json('data.job_id'))->firstOrFail();

        $this->assertSame(JobStatus::Done, $job->status, 'la réparation doit rattraper le rapport : '.$job->error);

        $content = SurveyReport::query()->findOrFail($job->output['report_id'])->content_json;

        $this->assertArrayNotHasKey('author', $content, 'la propriété inventée doit avoir été retirée');
        $this->assertSame([], app(ReportContentValidator::class)->validate($content));
    }

    public function test_synthesis_only_cites_figures_present_in_the_statistics(): void
    {
        $this->materialize();

        $response = $this->actingAs($this->analyst)
            ->postJson("/api/surveys/{$this->fx->survey->id}/synthesis", ['language' => 'fr']);

        $response->assertStatus(202);
        $job = AiJob::query()->where('uuid', $response->json('data.job_id'))->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status, 'échec : '.$job->error);

        $markdown = (string) $job->output['content_md'];
        $stats = app(SurveyInsightContext::class);
        $context = $stats->toMarkdown($stats->build($this->fx->survey, ['lang' => 'fr']));

        $this->assertStringNotContainsString('{{', $markdown);

        // Le taux d'acompte cité est celui des statistiques réelles, pas une valeur figée dans le corpus.
        $this->assertSame(1, preg_match('/acompte \/ fiches valides : ([\d.,]+) %/u', $context, $m));
        $this->assertStringContainsString($m[1].' %', $markdown);
    }

    /** La synthèse et les rapports exigent une source matérialisée (sinon `409 datasource_not_ready`). */
    private function materialize(): void
    {
        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson("/api/surveys/{$this->fx->survey->id}/datasource/rebuild?sync=1")
            ->assertStatus(202);
    }
}
