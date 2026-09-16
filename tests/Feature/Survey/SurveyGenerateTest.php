<?php

namespace Tests\Feature\Survey;

use App\Enums\JobKind;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateFormJob;
use App\Models\AiJob;
use App\Models\ProjectMember;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\User;
use App\Services\Survey\DocxTextExtractor;
use App\Services\Survey\SurveyVersionService;
use Database\Seeders\SystemPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B-06b : `POST /surveys/generate` (texte ou .docx) et `POST /surveys/{survey}/ai/translate`.
 *
 * Le fournisseur IA est simulé par `Http::fake()` sur l'URL Gemini ; la file de test est `sync`, donc
 * `dispatch()` exécute le job pendant la requête : l'`AiJob` est déjà terminé au retour (sauf `Bus::fake()`).
 */
class SurveyGenerateTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI = 'generativelanguage.googleapis.com/*';

    private const MUNAGO_DOCX = 'C:\Users\DELL\Downloads\MunaGo-Questionnaire-Terrain V1.0.docx';

    private User $owner;

    private User $enumerator;

    private SurveyProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemPromptSeeder::class);
        config(['services.gemini.key' => 'cle-serveur-de-test']);

        $this->owner = User::factory()->create(['role' => UserRole::Analyste]);
        $this->enumerator = User::factory()->create(['role' => UserRole::Enqueteur]);

        $this->project = SurveyProject::factory()->create(['owner_id' => $this->owner->id, 'name' => 'MunaGo Douala']);
        ProjectMember::factory()->analyste()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $this->enumerator->id]);
    }

    // ================================================================== génération (texte)

    public function test_generate_from_text_returns_202_with_queued_job(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/surveys/generate', [
            'project_id' => $this->project->id,
            'source_text' => $this->sourceText(),
            'language' => 'fr',
            'hints' => ['fiche_code_pattern' => '{VILLE}-{QUARTIER}-{NN}', 'follow_up_days' => [4, 7, 14], 'currency' => 'XAF'],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.kind', 'form_generation')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonStructure(['data' => ['job_id'], 'meta' => ['job_id']]);

        $uuid = $response->json('data.job_id');
        $this->assertSame(url('/api/jobs/'.$uuid), $response->headers->get('Location'));

        $job = AiJob::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(JobKind::FormGeneration, $job->kind);
        $this->assertSame(JobStatus::Queued, $job->status);
        $this->assertSame($this->owner->id, $job->user_id);
        $this->assertSame($this->project->id, $job->input['project_id']);
        $this->assertSame([4, 7, 14], $job->input['hints']['follow_up_days']);
        // La clé API n'est jamais stockée (chiffrée ou non) dans le job de suivi.
        $this->assertStringNotContainsString('cle-serveur-de-test', json_encode($job->input));

        Bus::assertDispatched(GenerateFormJob::class, fn (GenerateFormJob $j) => $j->jobUuid === $uuid);
    }

    public function test_generate_from_text_creates_survey_with_valid_draft(): void
    {
        $this->fakeGemini([$this->generatedJson()]);

        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/surveys/generate', [
            'project_id' => $this->project->id,
            'source_text' => $this->sourceText(),
            'language' => 'fr',
        ]);

        $response->assertStatus(202);
        $uuid = $response->json('data.job_id');

        $job = AiJob::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status, (string) $job->error);
        $this->assertSame(100, $job->progress);
        $this->assertNotNull($job->survey_id);
        $this->assertSame('surveys/'.$job->survey_id, $job->result_ref);

        $survey = Survey::query()->findOrFail($job->survey_id);
        $this->assertSame($this->project->id, $survey->project_id);
        $this->assertSame('MunaGo — étude de marché terrain', $survey->title);

        $draft = app(SurveyVersionService::class)->draftOf($survey);
        $this->assertNotNull($draft);
        $this->assertSame(1, $draft->version);
        $definition = $draft->definition;
        $this->assertSame('1.0', $definition['dfs_version']);
        $this->assertSame(1, $definition['version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $definition['id']);
        $this->assertSame(['fr'], $definition['settings']['languages']);
        $this->assertSame('fr', $definition['settings']['default_language']);
        $this->assertCount(2, $definition['sections']);

        // Le brouillon est valide (aucune erreur du validateur DFS).
        $report = app(SurveyVersionService::class)->validateDraft($survey);
        $this->assertTrue($report['valid'], json_encode($report['errors'], JSON_UNESCAPED_UNICODE));

        // `GET /jobs/{uuid}` expose le résultat.
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/jobs/'.$uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.result.survey_id', $survey->id)
            ->assertJsonPath('data.result.version', 1);

        Http::assertSentCount(1);
    }

    public function test_invalid_json_triggers_a_single_repair_attempt(): void
    {
        $this->fakeGemini(['{"dfs_version": "1.0", "sections": [', $this->generatedJson()]);

        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/surveys/generate', [
            'project_id' => $this->project->id,
            'source_text' => $this->sourceText(),
        ]);

        $uuid = $response->assertStatus(202)->json('data.job_id');
        $job = AiJob::query()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame(JobStatus::Done, $job->status, (string) $job->error);
        $this->assertNotNull($job->survey_id);
        Http::assertSentCount(2);

        // Le second appel porte bien le prompt de réparation et la liste des erreurs.
        Http::assertSent(function (ClientRequest $request): bool {
            $system = $request->data()['system_instruction']['parts'][0]['text'] ?? '';

            return str_contains($system, 'correcteur de documents JSON') && str_contains($system, 'invalid_json');
        });
    }

    public function test_still_invalid_after_repair_fails_the_job(): void
    {
        $this->fakeGemini(['pas du tout du JSON', '{"dfs_version": "1.0"']);

        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/surveys/generate', [
            'project_id' => $this->project->id,
            'source_text' => $this->sourceText(),
        ]);

        $uuid = $response->assertStatus(202)->json('data.job_id');
        $job = AiJob::query()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertStringContainsString('après une tentative de réparation', (string) $job->error);
        $this->assertNull($job->survey_id);
        $this->assertSame(0, Survey::query()->count());
        Http::assertSentCount(2);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/jobs/'.$uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.result', null);
    }

    public function test_proposal_mode_returns_the_definition_in_the_job_result(): void
    {
        $this->fakeGemini([$this->generatedJson()]);

        $uuid = $this->actingAs($this->owner, 'sanctum')->postJson('/api/surveys/generate', [
            'project_id' => $this->project->id,
            'source_text' => $this->sourceText(),
            'create' => false,
        ])->assertStatus(202)->json('data.job_id');

        $job = AiJob::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status, (string) $job->error);
        $this->assertSame('jobs/'.$uuid.'/proposal', $job->result_ref);
        $this->assertNull($job->survey_id);
        $this->assertSame(0, Survey::query()->count(), 'le mode proposition ne crée aucun questionnaire');

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/jobs/'.$uuid)
            ->assertOk()
            ->assertJsonPath('data.result_ref', 'jobs/'.$uuid.'/proposal')
            ->assertJsonPath('data.result.definition.dfs_version', '1.0')
            ->assertJsonPath('data.result.definition.sections.0.key', 'entete');
    }

    public function test_generate_into_an_existing_survey_updates_its_draft(): void
    {
        $survey = app(SurveyVersionService::class)->createSurvey($this->project, $this->owner, 'Brouillon vierge');
        $this->fakeGemini([$this->generatedJson()]);

        $uuid = $this->actingAs($this->owner, 'sanctum')->postJson('/api/surveys/generate', [
            'project_id' => $this->project->id,
            'survey_id' => $survey->id,
            'source_text' => $this->sourceText(),
        ])->assertStatus(202)->json('data.job_id');

        $job = AiJob::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status, (string) $job->error);
        $this->assertSame($survey->id, $job->survey_id);
        $this->assertSame(1, Survey::query()->count());

        $draft = app(SurveyVersionService::class)->draftOf($survey->fresh());
        $this->assertSame(1, $draft->revision, 'le brouillon existant est remplacé, pas dupliqué');
        $this->assertSame('entete', $draft->definition['sections'][0]['key']);
    }

    // ================================================================== génération (.docx)

    public function test_generate_from_the_real_munago_docx(): void
    {
        if (! is_file(self::MUNAGO_DOCX)) {
            $this->markTestSkipped('Questionnaire MunaGo .docx introuvable : '.self::MUNAGO_DOCX);
        }

        $text = (new DocxTextExtractor)->extract(self::MUNAGO_DOCX);
        $this->assertGreaterThan(5000, mb_strlen($text), 'le questionnaire MunaGo doit produire un texte substantiel');

        $this->fakeGemini([$this->generatedJson()]);

        $uuid = $this->actingAs($this->owner, 'sanctum')->post('/api/surveys/generate', [
            'project_id' => $this->project->id,
            'language' => 'fr',
            'docx' => new UploadedFile(self::MUNAGO_DOCX, 'MunaGo.docx', null, null, true),
        ], ['Accept' => 'application/json'])->assertStatus(202)->json('data.job_id');

        $job = AiJob::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status, (string) $job->error);
        $this->assertSame('docx', $job->input['source']);
        $this->assertGreaterThan(5000, $job->input['source_length']);

        // Le texte extrait est bien celui envoyé au modèle.
        Http::assertSent(function (ClientRequest $request): bool {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($prompt, '=== DÉBUT DU DOCUMENT ===') && mb_strlen($prompt) > 5000;
        });
    }

    public function test_docx_over_10_mb_returns_413(): void
    {
        Bus::fake();

        $this->actingAs($this->owner, 'sanctum')->post('/api/surveys/generate', [
            'project_id' => $this->project->id,
            'docx' => UploadedFile::fake()->create('enorme.docx', 11 * 1024),
        ], ['Accept' => 'application/json'])
            ->assertStatus(413)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Fichier trop volumineux (maximum 10 Mo).');

        $this->assertSame(0, AiJob::query()->count());
    }

    public function test_non_docx_file_returns_422(): void
    {
        Bus::fake();

        $this->actingAs($this->owner, 'sanctum')->post('/api/surveys/generate', [
            'project_id' => $this->project->id,
            'docx' => UploadedFile::fake()->createWithContent('notes.txt', 'Ceci n\'est pas un document Word.'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['docx']]);

        $this->assertSame(0, AiJob::query()->count());
    }

    public function test_generate_without_source_returns_422(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/surveys/generate', [
            'project_id' => $this->project->id,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['source_text']]);
    }

    public function test_missing_api_key_returns_422_on_provider(): void
    {
        config(['services.gemini.key' => null]);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/surveys/generate', [
            'project_id' => $this->project->id,
            'source_text' => $this->sourceText(),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['provider']]);
    }

    // ================================================================== autorisation et débit

    public function test_enumerator_cannot_generate(): void
    {
        $this->actingAs($this->enumerator, 'sanctum')->postJson('/api/surveys/generate', [
            'project_id' => $this->project->id,
            'source_text' => $this->sourceText(),
        ])->assertStatus(403);

        $this->assertSame(0, AiJob::query()->count());
    }

    public function test_ai_throttle_returns_429_on_the_sixth_call(): void
    {
        Bus::fake();

        $payload = ['project_id' => $this->project->id, 'source_text' => $this->sourceText()];

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($this->owner, 'sanctum')
                ->postJson('/api/surveys/generate', $payload)
                ->assertStatus(202);
        }

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/surveys/generate', $payload)
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    // ================================================================== traduction

    public function test_translate_fills_only_the_missing_i18n(): void
    {
        $service = app(SurveyVersionService::class);
        $survey = $service->createSurvey($this->project, $this->owner, 'À traduire', $this->partiallyTranslatedDefinition());

        // Le modèle renvoie « EN: » + le texte source pour chaque chemin demandé.
        Http::fake([self::GEMINI => function (ClientRequest $request) {
            $items = json_decode($request->data()['contents'][0]['parts'][0]['text'] ?? '[]', true);
            $rows = array_map(static fn (array $i): array => ['path' => $i['path'], 'text' => 'EN: '.$i['text']], $items);

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($rows)]]]]]]);
        }]);

        $uuid = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/surveys/{$survey->id}/ai/translate", ['target_lang' => 'en'])
            ->assertStatus(202)
            ->assertJsonPath('data.kind', 'translate')
            ->json('data.job_id');

        $job = AiJob::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status, (string) $job->error);
        $this->assertSame('surveys/'.$survey->id, $job->result_ref);
        $this->assertSame($survey->id, $job->survey_id);

        $draft = $service->draftOf($survey->fresh());
        $this->assertSame(1, $draft->revision);
        $definition = $draft->definition;

        // Langue ajoutée aux paramètres.
        $this->assertSame(['fr', 'en'], $definition['settings']['languages']);

        // Traduction existante conservée telle quelle.
        $this->assertSame('Already translated', $definition['sections'][0]['items'][0]['label']['en']);

        // Chemins manquants complétés, sans toucher au français.
        $this->assertSame('EN: À traduire', $definition['title']['en']);
        $this->assertSame('À traduire', $definition['title']['fr']);
        $this->assertSame('EN: Quel est votre âge ?', $definition['sections'][0]['items'][1]['label']['en']);
        $this->assertSame('EN: En années révolues.', $definition['sections'][0]['items'][1]['hint']['en']);
        $this->assertSame('EN: Oui', $definition['choice_lists']['oui_non'][0]['label']['en']);
        $this->assertSame('EN: Section unique', $definition['sections'][0]['label']['en']);

        $this->assertSame('en', $job->output['target_lang']);
        $this->assertGreaterThan(0, $job->output['translated']);
    }

    public function test_translate_requires_a_valid_target_language(): void
    {
        $survey = app(SurveyVersionService::class)->createSurvey($this->project, $this->owner, 'À traduire');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/surveys/{$survey->id}/ai/translate", ['target_lang' => 'anglais britannique'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['target_lang']]);
    }

    public function test_enumerator_cannot_translate(): void
    {
        $survey = app(SurveyVersionService::class)->createSurvey($this->project, $this->owner, 'À traduire');

        $this->actingAs($this->enumerator, 'sanctum')
            ->postJson("/api/surveys/{$survey->id}/ai/translate", ['target_lang' => 'en'])
            ->assertStatus(403);
    }

    // ================================================================== utilitaires

    /**
     * @param  list<string>  $texts  réponses successives du modèle (texte brut renvoyé par Gemini)
     */
    private function fakeGemini(array $texts): void
    {
        $sequence = Http::sequence();
        foreach ($texts as $text) {
            $sequence->push(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
        }

        Http::fake([self::GEMINI => $sequence]);
    }

    private function sourceText(): string
    {
        return <<<'TEXTE'
        QUESTIONNAIRE MUNAGO — DOUALA
        Code fiche : VILLE-QUARTIER-NN
        A. En-tête : ville, quartier (Autre : précisez ______)
        (Ne pas lire cette consigne au répondant.)
        B. Acceptez-vous de répondre ? Oui / Non — Si NON → STOP, remercier et partir.
        E. Texte à lire tel quel : « Il s'agit d'un petit appareil que l'enfant met dans son sac à dos… »
        Q13. Décision : verse un acompte / refuse. Montant reçu : ______ FCFA
        Q14. Qu'est-ce qui vous empêcherait d'utiliser cette solution ? (mot pour mot)
        Suivi : J+7 — appareil retiré ? Oui / Non
        Quota : 30 fiches valides. Durée cible 12-18 min.
        TEXTE;
    }

    /**
     * Réponse simulée du modèle : version condensée du questionnaire MunaGo (filtre STOP, « Autre :
     * précisez », note script, note enquêteur, montant FCFA, verbatim multiline, code fiche, quota, KPI,
     * étape J+7).
     */
    private function generatedJson(): string
    {
        return (string) json_encode([
            'dfs_version' => '1.0',
            'id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'version' => 1,
            'title' => ['fr' => 'MunaGo — étude de marché terrain'],
            'settings' => [
                'languages' => ['fr'],
                'default_language' => 'fr',
                'fiche_code' => [
                    'pattern' => '{VILLE}-{QUARTIER}-{NN}',
                    'sources' => ['VILLE' => 'ville', 'QUARTIER' => 'quartier'],
                    'counter' => ['width' => 2, 'scope' => 'enumerator'],
                ],
                'quotas' => [['key' => 'valides', 'label' => ['fr' => 'Fiches valides'], 'target' => 30, 'scope' => 'survey']],
                'kpis' => [[
                    'key' => 'taux_acompte',
                    'label' => ['fr' => "Taux d'acompte"],
                    'numerator' => ['==' => [['var' => 'decision'], 'oui_acompte']],
                    'denominator' => 'valid',
                ]],
                'timing' => ['min_duration_seconds' => 600, 'max_duration_seconds' => 1080],
            ],
            'choice_lists' => [
                'oui_non' => [
                    ['name' => 'oui', 'label' => ['fr' => 'Oui']],
                    ['name' => 'non', 'label' => ['fr' => 'Non']],
                ],
                'villes' => [['name' => 'douala', 'label' => ['fr' => 'Douala'], 'abbr' => 'DLA']],
                'quartiers' => [
                    ['name' => 'bonamoussadi', 'label' => ['fr' => 'Bonamoussadi'], 'abbr' => 'BMP'],
                    ['name' => 'autre', 'label' => ['fr' => 'Autre']],
                ],
                'decisions' => [
                    ['name' => 'oui_acompte', 'label' => ['fr' => 'Oui, verse un acompte']],
                    ['name' => 'non', 'label' => ['fr' => 'Non']],
                ],
            ],
            'sections' => [
                [
                    'key' => 'entete',
                    'label' => ['fr' => 'A. En-tête et filtre'],
                    'items' => [
                        ['key' => 'ville', 'type' => 'select_one', 'required' => true, 'choices' => 'villes', 'label' => ['fr' => 'Ville']],
                        [
                            'key' => 'quartier', 'type' => 'select_one', 'required' => true, 'choices' => 'quartiers',
                            'label' => ['fr' => 'Quartier'],
                            'other' => ['choice' => 'autre', 'label' => ['fr' => 'Précisez']],
                        ],
                        [
                            'key' => 'consigne_intro', 'type' => 'note', 'audience' => 'enumerator', 'style' => 'info',
                            'label' => ['fr' => 'Ne pas lire cette consigne au répondant.'],
                        ],
                        ['key' => 'accepte', 'type' => 'select_one', 'required' => true, 'choices' => 'oui_non', 'label' => ['fr' => 'B. Acceptez-vous de répondre ?']],
                        [
                            'key' => 'stop_refus', 'type' => 'stop',
                            'label' => ['fr' => "Fin de l'entretien"],
                            'message' => ['fr' => 'Remercier et partir.'],
                            'relevant' => ['==' => [['var' => 'accepte'], 'non']],
                        ],
                    ],
                ],
                [
                    'key' => 'engagement',
                    'label' => ['fr' => "E. Test d'engagement"],
                    'items' => [
                        [
                            'key' => 'script_produit', 'type' => 'note', 'audience' => 'respondent', 'style' => 'script',
                            'label' => ['fr' => "« Il s'agit d'un petit appareil que l'enfant met dans son sac à dos… »"],
                        ],
                        ['key' => 'decision', 'type' => 'select_one', 'required' => true, 'choices' => 'decisions', 'label' => ['fr' => 'Q13. Décision']],
                        [
                            'key' => 'montant_acompte', 'type' => 'currency', 'currency' => 'XAF', 'decimals' => 0, 'min' => 0,
                            'label' => ['fr' => 'Montant reçu (FCFA)'],
                            'relevant' => ['==' => [['var' => 'decision'], 'oui_acompte']],
                        ],
                        [
                            'key' => 'frein_principal', 'type' => 'text', 'appearance' => 'multiline',
                            'label' => ['fr' => "Q14. Qu'est-ce qui vous empêcherait d'utiliser cette solution ?"],
                            'hint' => ['fr' => 'Noter les mots exacts.'],
                        ],
                    ],
                ],
            ],
            'follow_up_stages' => [[
                'key' => 'j7',
                'label' => ['fr' => 'J+7 — retrait'],
                'due_offset_days' => 7,
                'window_days' => 3,
                'channel' => 'call',
                'relevant' => ['==' => [['var' => 'decision'], 'oui_acompte']],
                'items' => [['key' => 'j7_retire', 'type' => 'select_one', 'required' => true, 'choices' => 'oui_non', 'label' => ['fr' => 'Appareil retiré ?']]],
            ]],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Définition `fr` dont un seul libellé possède déjà sa traduction anglaise.
     *
     * @return array<string, mixed>
     */
    private function partiallyTranslatedDefinition(): array
    {
        return [
            'dfs_version' => '1.0',
            'id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'version' => 1,
            'title' => ['fr' => 'À traduire'],
            'settings' => ['languages' => ['fr'], 'default_language' => 'fr'],
            'choice_lists' => [
                'oui_non' => [
                    ['name' => 'oui', 'label' => ['fr' => 'Oui']],
                    ['name' => 'non', 'label' => ['fr' => 'Non']],
                ],
            ],
            'sections' => [[
                'key' => 'unique',
                'label' => ['fr' => 'Section unique'],
                'items' => [
                    ['key' => 'consent', 'type' => 'select_one', 'choices' => 'oui_non', 'label' => ['fr' => 'Consentez-vous ?', 'en' => 'Already translated']],
                    ['key' => 'age', 'type' => 'integer', 'min' => 0, 'max' => 120, 'label' => ['fr' => 'Quel est votre âge ?'], 'hint' => ['fr' => 'En années révolues.']],
                ],
            ]],
            'follow_up_stages' => [],
        ];
    }
}
