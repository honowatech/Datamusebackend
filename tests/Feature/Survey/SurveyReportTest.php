<?php

namespace Tests\Feature\Survey;

use App\Enums\JobKind;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateSurveyReportJob;
use App\Models\AiJob;
use App\Models\ProjectMember;
use App\Models\SurveyReport;
use App\Models\User;
use Database\Seeders\SystemPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * B-11 — synthèse et rapports : génération depuis un brief, validation stricte de `ReportContent`,
 * édition manuelle, régénération d'une section, archivage de fichiers.
 *
 * Le fournisseur IA est simulé par `Http::fake()` ; la file de test est `sync`.
 */
class SurveyReportTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI = 'generativelanguage.googleapis.com/*';

    private MunagoFixtureLoader $fx;

    private User $supervisor;

    private User $enumerator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemPromptSeeder::class);
        config(['services.gemini.key' => 'cle-serveur-de-test']);

        $this->fx = MunagoFixtureLoader::load();

        $this->supervisor = User::factory()->create(['role' => UserRole::Analyste]);
        ProjectMember::factory()->superviseur()->create(['project_id' => $this->fx->project->id, 'user_id' => $this->supervisor->id]);

        $this->enumerator = $this->fx->enumerators[1];
    }

    // ================================================================== prérequis datasource

    public function test_report_and_synthesis_require_a_materialized_datasource(): void
    {
        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/reports'), $this->brief())
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'datasource_not_ready');

        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/synthesis'), ['focus' => 'freins au paiement'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'datasource_not_ready');

        $this->assertSame(0, SurveyReport::query()->count());
    }

    // ================================================================== synthèse

    public function test_synthesis_produces_markdown_in_the_job_result(): void
    {
        $this->materialize();
        $this->fakeGemini(["## Ce que disent les données\n\n62 % (n = 45) acceptent l'acompte."]);

        $response = $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/synthesis'), ['focus' => 'freins au paiement']);

        $response->assertStatus(202)
            ->assertJsonPath('data.kind', 'synthesis');

        $uuid = $response->json('data.job_id');
        $job = AiJob::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status, (string) $job->error);
        $this->assertSame('syntheses/'.$uuid, $job->result_ref);
        $this->assertStringContainsString('Ce que disent les données', (string) $job->output['content_md']);
        $this->assertSame(60, $job->output['n']);

        $this->actingAs($this->supervisor, 'sanctum')->getJson('/api/jobs/'.$uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.result.focus', 'freins au paiement');

        // Le contexte envoyé au modèle est statistique : il contient les agrégats, jamais les colonnes `pii`.
        $sent = (string) json_encode(Http::recorded()[0][0]->data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Fiches exploitables : 60', $sent);
        $this->assertStringContainsString('Résultats par question', $sent);
        // Les questions `pii` n'entrent jamais dans le contexte envoyé au fournisseur.
        $this->assertStringNotContainsString('num_whatsapp', $sent);
        $this->assertStringNotContainsString('nom_compte_momo', $sent);
    }

    // ================================================================== génération d'un rapport

    public function test_report_is_created_queued_then_done_with_valid_content_json(): void
    {
        $this->materialize();
        $this->fakeGemini([(string) json_encode($this->reportJson(), JSON_UNESCAPED_UNICODE)]);

        $response = $this->actingAs($this->fx->owner, 'sanctum')->postJson($this->url('/reports'), $this->brief());

        $response->assertStatus(202)
            ->assertJsonPath('data.kind', 'report')
            ->assertJsonStructure(['data' => ['job_id', 'report' => ['id', 'status', 'title']]]);

        $reportId = $response->json('data.report.id');
        $this->assertSame(url('/api/reports/'.$reportId), $response->headers->get('Location'));

        $job = AiJob::query()->where('uuid', $response->json('data.job_id'))->firstOrFail();
        $this->assertSame(JobKind::Report, $job->kind);
        $this->assertSame(JobStatus::Done, $job->status, (string) $job->error);
        $this->assertSame('reports/'.$reportId, $job->result_ref);

        $report = SurveyReport::query()->findOrFail($reportId);
        $this->assertSame(JobStatus::Done, $report->status);
        $this->assertSame($job->uuid, $report->job_id);
        $this->assertNull($report->error);
        $this->assertSame('Marché MunaGo à Douala', $report->content_json['title']);
        $this->assertCount(2, $report->content_json['sections']);
        // `meta` est renseigné par le serveur, jamais par le modèle.
        $this->assertSame('fr', $report->content_json['meta']['language']);
        $this->assertSame(60, $report->content_json['meta']['n']);

        // `content_md` est dérivé de `content_json`.
        $this->assertStringContainsString('# Marché MunaGo à Douala', (string) $report->content_md);
        $this->assertStringContainsString('## Résumé exécutif', (string) $report->content_md);
        $this->assertStringContainsString('### Adoption', (string) $report->content_md);
        $this->assertStringContainsString('| Quartier | Fiches |', (string) $report->content_md);
        $this->assertStringContainsString('> **Verbatim**', (string) $report->content_md);
        $this->assertStringContainsString('## Recommandations', (string) $report->content_md);

        $this->actingAs($this->supervisor, 'sanctum')->getJson('/api/reports/'.$reportId)
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.orientation', 'commercial')
            ->assertJsonPath('data.tone', 'factuel')
            ->assertJsonPath('data.include_verbatims', true)
            ->assertJsonPath('data.content_json.sections.0.heading', 'Adoption');
    }

    public function test_invalid_json_triggers_one_repair_then_fails_the_report(): void
    {
        $this->materialize();

        // 1) contenu invalide (propriété inventée + section sans `level`), 2) réparation encore invalide.
        $broken = (string) json_encode([
            'title' => 'Rapport',
            'summary' => 'Résumé',
            'sections' => [['heading' => 'A', 'paragraphs' => ['x'], 'inventé' => true]],
            'recommendations' => ['faire mieux'],
        ], JSON_UNESCAPED_UNICODE);

        $this->fakeGemini([$broken, 'ceci n\'est pas du JSON']);

        $response = $this->actingAs($this->fx->owner, 'sanctum')->postJson($this->url('/reports'), $this->brief());
        $response->assertStatus(202);

        Http::assertSentCount(2);

        $job = AiJob::query()->where('uuid', $response->json('data.job_id'))->firstOrFail();
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertStringContainsString('invalide après une tentative de réparation', (string) $job->error);

        $report = SurveyReport::query()->findOrFail($response->json('data.report.id'));
        $this->assertSame(JobStatus::Failed, $report->status);
        $this->assertNotNull($report->error);
        $this->assertNull($report->content_json);
    }

    public function test_a_repaired_report_is_saved(): void
    {
        $this->materialize();

        $broken = (string) json_encode(['title' => 'Rapport', 'summary' => 'Résumé', 'sections' => [], 'recommendations' => []]);
        $this->fakeGemini([$broken, (string) json_encode($this->reportJson(), JSON_UNESCAPED_UNICODE)]);

        $response = $this->actingAs($this->fx->owner, 'sanctum')->postJson($this->url('/reports'), $this->brief());
        $response->assertStatus(202);

        Http::assertSentCount(2);
        $report = SurveyReport::query()->findOrFail($response->json('data.report.id'));
        $this->assertSame(JobStatus::Done, $report->status, (string) $report->error);
        $this->assertCount(2, $report->content_json['sections']);
    }

    // ================================================================== liste et édition

    public function test_listing_reports_omits_the_contents(): void
    {
        $report = $this->doneReport();

        $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('/reports'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report->id)
            ->assertJsonMissingPath('data.0.content_md')
            ->assertJsonMissingPath('data.0.content_json')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_updating_only_the_markdown_marks_content_json_stale(): void
    {
        $report = $this->doneReport();

        $this->actingAs($this->fx->owner, 'sanctum')->putJson('/api/reports/'.$report->id, [
            'content_md' => "# Titre édité à la main\n\nTexte.",
        ])
            ->assertOk()
            ->assertJsonPath('meta.content_json_stale', true);

        $report->refresh();
        $this->assertStringContainsString('édité à la main', (string) $report->content_md);
        $this->assertSame('Marché MunaGo à Douala', $report->content_json['title'], 'le JSON n\'est pas touché');

        // Renvoyer un `content_json` valide lève le drapeau et re-rend le markdown.
        $content = $report->content_json;
        $content['title'] = 'Titre corrigé';
        $this->actingAs($this->fx->owner, 'sanctum')->putJson('/api/reports/'.$report->id, ['content_json' => $content])
            ->assertOk()
            ->assertJsonMissingPath('meta.content_json_stale');

        $report->refresh();
        $this->assertStringContainsString('# Titre corrigé', (string) $report->content_md);
    }

    public function test_updating_with_an_invalid_content_json_is_rejected(): void
    {
        $report = $this->doneReport();

        $this->actingAs($this->fx->owner, 'sanctum')->putJson('/api/reports/'.$report->id, [
            'content_json' => ['title' => 'x', 'summary' => 'y', 'sections' => [['heading' => 'A', 'level' => 9, 'paragraphs' => []]], 'recommendations' => []],
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.path', '/sections/0/level');
    }

    // ================================================================== régénération d'une section

    public function test_regenerating_a_section_replaces_only_that_section(): void
    {
        $report = $this->doneReport([(string) json_encode([
            'heading' => 'Adoption',
            'level' => 2,
            'paragraphs' => ['Nouvelle rédaction de la section Adoption.'],
            'bullets' => ['62 % (n = 45) acceptent'],
        ], JSON_UNESCAPED_UNICODE)]);
        $before = $report->content_json;

        $response = $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson('/api/reports/'.$report->id.'/regenerate-section', ['heading' => 'Adoption', 'instructions' => 'Plus court.']);

        $response->assertStatus(202)->assertJsonPath('data.kind', 'report_section');

        $job = AiJob::query()->where('uuid', $response->json('data.job_id'))->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status, (string) $job->error);
        $this->assertSame(0, $job->output['section_index']);
        $this->assertFalse($job->output['markdown_rerendered'], 'le markdown est corrigé en place');

        $report->refresh();
        $this->assertSame('Nouvelle rédaction de la section Adoption.', $report->content_json['sections'][0]['paragraphs'][0]);
        // La seconde section est inchangée, ainsi que le reste du contenu.
        $this->assertSame($before['sections'][1], $report->content_json['sections'][1]);
        $this->assertSame($before['summary'], $report->content_json['summary']);
        $this->assertSame($before['recommendations'], $report->content_json['recommendations']);

        $this->assertStringContainsString('Nouvelle rédaction de la section Adoption.', (string) $report->content_md);
        $this->assertStringNotContainsString('Les quartiers de Bonamoussadi', (string) $report->content_md);
        $this->assertStringContainsString('Freins', (string) $report->content_md, 'la seconde section reste dans le markdown');
    }

    public function test_regenerating_an_unknown_heading_is_rejected(): void
    {
        $report = $this->doneReport();

        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson('/api/reports/'.$report->id.'/regenerate-section', ['heading' => 'Section absente'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('heading');
    }

    // ================================================================== fichiers archivés

    public function test_archiving_a_docx_then_downloading_it_with_a_signed_url(): void
    {
        Storage::fake('local');
        $report = $this->doneReport();

        $response = $this->actingAs($this->fx->owner, 'sanctum')->post('/api/reports/'.$report->id.'/files', [
            'file' => UploadedFile::fake()->create('rapport.docx', 120),
            'format' => 'docx',
            'label' => 'Version direction',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('data.format', 'docx')
            ->assertJsonPath('data.label', 'Version direction')
            ->assertJsonStructure(['data' => ['id', 'filename', 'size', 'signed_url', 'created_at']]);

        $report->refresh();
        $this->assertCount(1, $report->files());
        Storage::disk('local')->assertExists($report->files()[0]['path']);

        // L'URL signée télécharge le fichier ; sans signature, 403.
        $signed = $response->json('data.signed_url');
        $this->get($signed)->assertOk()->assertHeader('content-disposition');
        $this->get('/api/reports/'.$report->id.'/files/'.$response->json('data.id'))->assertStatus(403);

        // Un format incohérent avec l'extension est refusé.
        $this->actingAs($this->fx->owner, 'sanctum')->post('/api/reports/'.$report->id.'/files', [
            'file' => UploadedFile::fake()->create('rapport.docx', 10),
            'format' => 'pdf',
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('format');
    }

    // ================================================================== autorisations et débit

    public function test_an_enumerator_cannot_create_or_read_a_report(): void
    {
        $report = $this->doneReport();

        $this->actingAs($this->enumerator, 'sanctum')->postJson($this->url('/reports'), $this->brief())->assertStatus(403);
        $this->actingAs($this->enumerator, 'sanctum')->getJson($this->url('/reports'))->assertStatus(403);
        $this->actingAs($this->enumerator, 'sanctum')->getJson('/api/reports/'.$report->id)->assertStatus(403);
        $this->actingAs($this->enumerator, 'sanctum')->postJson($this->url('/synthesis'))->assertStatus(403);
    }

    public function test_a_supervisor_cannot_edit_or_regenerate_a_report(): void
    {
        $report = $this->doneReport();

        $this->actingAs($this->supervisor, 'sanctum')->putJson('/api/reports/'.$report->id, ['title' => 'Nouveau'])->assertStatus(403);
        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/reports/'.$report->id.'/regenerate-section', ['heading' => 'Adoption'])
            ->assertStatus(403);
    }

    public function test_report_creation_is_rate_limited_to_five_per_minute(): void
    {
        $this->materialize();
        Bus::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->fx->owner, 'sanctum')->postJson($this->url('/reports'), $this->brief())->assertStatus(202);
        }

        $this->actingAs($this->fx->owner, 'sanctum')->postJson($this->url('/reports'), $this->brief())->assertStatus(429);
        Bus::assertDispatchedTimes(GenerateSurveyReportJob::class, 5);
    }

    // ================================================================== helpers

    private function url(string $suffix): string
    {
        return '/api/surveys/'.$this->fx->survey->id.$suffix;
    }

    private function materialize(): void
    {
        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/datasource/rebuild').'?sync=1')
            ->assertStatus(202);
    }

    /** @param list<string> $texts */
    private function fakeGemini(array $texts): void
    {
        $sequence = Http::sequence();
        foreach ($texts as $text) {
            $sequence->push(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
        }

        Http::fake([self::GEMINI => $sequence]);
    }

    /** @return array<string, mixed> */
    private function brief(): array
    {
        return [
            'title' => 'Marché MunaGo à Douala',
            'brief' => 'Évaluer le potentiel commercial du traceur MunaGo auprès des parents de Douala et identifier les freins au paiement.',
            'orientation' => 'commercial',
            'audience' => 'Direction commerciale',
            'language' => 'fr',
            'sections' => ['Adoption', 'Freins'],
        ];
    }

    /**
     * Rapport valide renvoyé par le modèle simulé (deux sections, un tableau, un graphique, une citation).
     *
     * @return array<string, mixed>
     */
    private function reportJson(): array
    {
        return [
            'title' => 'Marché MunaGo à Douala',
            'subtitle' => 'Étude terrain, 60 fiches',
            'summary' => 'Le traceur suscite un intérêt net chez les parents de Douala. Le frein principal reste la confiance dans le traitement des données.',
            'key_figures' => [
                ['label' => 'Fiches exploitables', 'value' => '60'],
                ['label' => 'Taux d\'acompte', 'value' => '8 %', 'trend' => 'up'],
            ],
            'sections' => [
                [
                    'heading' => 'Adoption',
                    'level' => 2,
                    'paragraphs' => ['Les quartiers de Bonamoussadi et Akwa concentrent les intentions les plus fortes.'],
                    'bullets' => ['45 fiches valides'],
                    'table' => [
                        'title' => 'Répartition par quartier',
                        'columns' => ['Quartier', 'Fiches'],
                        'rows' => [['Bonamoussadi', 21], ['Akwa', 20], ['Makepe', 19]],
                    ],
                ],
                [
                    'heading' => 'Freins',
                    'level' => 2,
                    'paragraphs' => ['La confiance dans le traitement des données domine les objections.'],
                    'chart' => [
                        'type' => 'bar',
                        'title' => 'Freins cités',
                        'x' => ['Confiance', 'Prix'],
                        'series' => [['name' => 'Citations', 'data' => [30, 12]]],
                        'unit' => null,
                    ],
                    'callouts' => [['kind' => 'quote', 'text' => 'Qui voit les données ?']],
                ],
            ],
            'recommendations' => ['Publier une politique de confidentialité en français simple.', 'Proposer un paiement échelonné.'],
            'appendix' => [
                'methodology' => 'Échantillon de convenance, 3 quartiers de Douala.',
                'sample' => 'n = 60, septembre 2026.',
            ],
        ];
    }

    /**
     * Rapport déjà généré (état `done`).
     *
     * `Http::fake()` **fusionne** les stubs d'un appel à l'autre : toute la séquence attendue par le test
     * (génération puis régénération de section…) doit être déclarée ici, en une fois.
     *
     * @param  list<string>  $followUpTexts  réponses simulées des appels IA suivants
     */
    private function doneReport(array $followUpTexts = []): SurveyReport
    {
        $this->materialize();
        $this->fakeGemini(array_merge(
            [(string) json_encode($this->reportJson(), JSON_UNESCAPED_UNICODE)],
            $followUpTexts,
        ));

        $response = $this->actingAs($this->fx->owner, 'sanctum')->postJson($this->url('/reports'), $this->brief());
        $response->assertStatus(202);

        $report = SurveyReport::query()->findOrFail($response->json('data.report.id'));
        $this->assertSame(JobStatus::Done, $report->status, (string) $report->error);

        return $report;
    }
}
