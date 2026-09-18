<?php

namespace Tests\Feature\Survey;

use App\Enums\JobKind;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Jobs\ClassifyVerbatimsJob;
use App\Jobs\MaterializeSurveyDatasourceJob;
use App\Models\AiJob;
use App\Models\ProjectMember;
use App\Models\SurveyDatasource;
use App\Models\User;
use App\Models\VerbatimCodebook;
use App\Models\VerbatimCoding;
use Database\Seeders\SystemPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * B-11 — verbatims : classification IA, livres de codes, fusion de thèmes, lecture.
 *
 * Le fournisseur IA est simulé par `Http::fake()` sur l'URL Gemini ; la file de test est `sync`, donc
 * `dispatch()` exécute le job pendant la requête (l'`AiJob` est déjà terminé au retour, sauf `Bus::fake()`).
 *
 * Question de référence : `q6_freins` de la fixture MunaGo (52 réponses ouvertes, non `pii`).
 * Question `pii` de référence : `num_whatsapp`.
 */
class VerbatimTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI = 'generativelanguage.googleapis.com/*';

    private const KEY = 'q6_freins';

    private MunagoFixtureLoader $fx;

    private User $supervisor;

    private User $enumerator;

    private User $stranger;

    /** @var list<PDO> */
    private array $handles = [];

    /** @var list<int>|null identifiants des soumissions ayant répondu à `q6_freins` */
    private ?array $codedIds = null;

    /** Curseur des lots simulés (chaque appel de `classifyJson()` consomme les suivants). */
    private int $classifyOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemPromptSeeder::class);
        config(['services.gemini.key' => 'cle-serveur-de-test']);

        $this->fx = MunagoFixtureLoader::load();

        $this->supervisor = User::factory()->create(['role' => UserRole::Analyste]);
        ProjectMember::factory()->superviseur()->create(['project_id' => $this->fx->project->id, 'user_id' => $this->supervisor->id]);

        $this->enumerator = $this->fx->enumerators[1];
        $this->stranger = User::factory()->create(['role' => UserRole::Analyste]);
    }

    protected function tearDown(): void
    {
        $this->handles = [];
        gc_collect_cycles();
        parent::tearDown();
    }

    // ================================================================== classify → 202

    public function test_classify_returns_202_with_a_queued_classify_job(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/verbatims/classify'), ['question_key' => self::KEY, 'mode' => 'discover', 'max_themes' => 6]);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.kind', 'classify')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonStructure(['data' => ['job_id'], 'meta' => ['job_id']]);

        $uuid = $response->json('data.job_id');
        $this->assertSame(url('/api/jobs/'.$uuid), $response->headers->get('Location'));

        $job = AiJob::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(JobKind::Classify, $job->kind);
        $this->assertSame(JobStatus::Queued, $job->status);
        $this->assertSame(self::KEY, $job->input['question_key']);
        $this->assertSame(6, $job->input['max_themes']);
        // La clé API n'est jamais stockée dans le job de suivi.
        $this->assertStringNotContainsString('cle-serveur-de-test', (string) json_encode($job->input));

        Bus::assertDispatched(ClassifyVerbatimsJob::class, fn (ClassifyVerbatimsJob $j) => $j->jobUuid === $uuid);
    }

    // ================================================================== discover + apply

    public function test_discover_creates_a_codebook_and_persists_codings(): void
    {
        $this->materialize();
        // La reconstruction est mise en file mais **pas** exécutée : on observe l'état `dirty` posé par le job.
        Bus::fake([MaterializeSurveyDatasourceJob::class]);
        $this->fakeDiscoverThenClassify();

        $response = $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/verbatims/classify'), ['question_key' => self::KEY, 'mode' => 'discover', 'max_themes' => 3]);

        $response->assertStatus(202);
        $job = AiJob::query()->where('uuid', $response->json('data.job_id'))->firstOrFail();
        $this->assertSame(JobStatus::Done, $job->status, (string) $job->error);

        $codebook = VerbatimCodebook::query()->forQuestion($this->fx->survey->id, self::KEY)->firstOrFail();
        $this->assertSame(1, $codebook->version);
        $this->assertSame(VerbatimCodebook::SOURCE_AI, $codebook->source);
        $this->assertSame(['confiance_donnees', 'prix', 'autonomie'], $codebook->themeKeys());
        $this->assertSame('codebooks/'.$codebook->id, $job->result_ref);
        $this->assertSame($codebook->id, $job->output['codebook_id']);

        // 52 réponses ouvertes → 2 lots de 40 ; toutes les fiches du lot sont codées.
        $codings = VerbatimCoding::query()->where('codebook_id', $codebook->id)->get();
        $this->assertSame(52, $codings->count());
        $this->assertSame(VerbatimCoding::SOURCE_AI, $codings->first()->source);
        $this->assertSame('negative', $codings->first()->sentiment);
        $this->assertEqualsCanonicalizing(['confiance_donnees'], $codings->first()->themes);
        $this->assertSame('0.900', (string) $codings->first()->confidence);

        // La datasource est marquée `dirty` : les thèmes entreront dans `reponses.{key}_themes`.
        $datasource = SurveyDatasource::query()->where('survey_id', $this->fx->survey->id)->first();
        $this->assertNotNull($datasource);
        $this->assertTrue($datasource->dirty);
        Bus::assertDispatched(MaterializeSurveyDatasourceJob::class);
    }

    public function test_codings_appear_in_reponses_themes_after_rebuild(): void
    {
        $this->materialize();
        $this->fakeDiscoverThenClassify();

        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/verbatims/classify'), ['question_key' => self::KEY, 'mode' => 'discover'])
            ->assertStatus(202);

        $this->materialize();

        $datasource = SurveyDatasource::query()->where('survey_id', $this->fx->survey->id)->firstOrFail();
        $this->assertFalse($datasource->dirty);

        $pdo = $this->open((string) $datasource->targetDatabase->database);
        $columns = array_column($pdo->query('PRAGMA table_info(reponses)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertContains(self::KEY.'_themes', $columns);
        $this->assertContains(self::KEY.'_sentiment', $columns);

        $row = $pdo->query('SELECT '.self::KEY.'_themes AS themes, '.self::KEY."_sentiment AS sentiment FROM reponses WHERE {$this->quotedKey()} IS NOT NULL AND ".self::KEY.'_themes IS NOT NULL LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertStringContainsString('confiance_donnees', (string) $row['themes']);
        $this->assertSame('negative', $row['sentiment']);

        $coded = (int) $pdo->query('SELECT COUNT(*) FROM reponses WHERE '.self::KEY.'_themes IS NOT NULL')->fetchColumn();
        $this->assertSame(52, $coded);
    }

    public function test_apply_without_codebook_is_rejected(): void
    {
        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/verbatims/classify'), ['question_key' => self::KEY, 'mode' => 'apply'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('mode');
    }

    public function test_apply_only_codes_the_remaining_verbatims(): void
    {
        $codebook = VerbatimCodebook::query()->create([
            'survey_id' => $this->fx->survey->id,
            'question_key' => self::KEY,
            'version' => 1,
            'themes' => [['key' => 'prix', 'label' => 'Prix']],
            'source' => VerbatimCodebook::SOURCE_MANUAL,
        ]);
        VerbatimCoding::query()->create([
            'submission_id' => $this->firstCodedSubmissionId(),
            'survey_id' => $this->fx->survey->id,
            'question_key' => self::KEY,
            'codebook_id' => $codebook->id,
            'themes' => ['prix'],
            'source' => VerbatimCoding::SOURCE_MANUAL,
        ]);

        // 51 restants (la première fiche est déjà codée) → 2 lots (40 + 11).
        $pending = array_slice($this->codedSubmissionIds(), 1);
        $this->fakeGemini([
            $this->classifyJsonFor(array_slice($pending, 0, 40), ['prix']),
            $this->classifyJsonFor(array_slice($pending, 40), ['prix']),
        ]);

        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/verbatims/classify'), ['question_key' => self::KEY, 'mode' => 'apply'])
            ->assertStatus(202);

        Http::assertSentCount(2);
        $this->assertSame(52, VerbatimCoding::query()->where('codebook_id', $codebook->id)->count());
        // Le codage manuel préexistant n'a pas été écrasé.
        $this->assertSame(
            VerbatimCoding::SOURCE_MANUAL,
            VerbatimCoding::query()->where('codebook_id', $codebook->id)->where('submission_id', $this->firstCodedSubmissionId())->value('source'),
        );
    }

    // ================================================================== livres de codes

    public function test_supervisor_lists_codebooks_and_reads_one_with_theme_counts(): void
    {
        $this->fakeDiscoverThenClassify();
        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/verbatims/classify'), ['question_key' => self::KEY, 'mode' => 'discover'])
            ->assertStatus(202);

        $codebook = VerbatimCodebook::query()->forQuestion($this->fx->survey->id, self::KEY)->firstOrFail();

        $this->actingAs($this->supervisor, 'sanctum')
            ->getJson($this->url('/verbatims/codebooks'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question_key', self::KEY)
            ->assertJsonPath('data.0.source', 'ai');

        $this->actingAs($this->supervisor, 'sanctum')
            ->getJson($this->url('/verbatims/codebooks/'.$codebook->id))
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.coded_count', 52)
            ->assertJsonPath('data.themes.0.key', 'confiance_donnees')
            ->assertJsonPath('data.themes.0.count', 52);
    }

    public function test_analyst_creates_a_manual_codebook_as_the_next_version(): void
    {
        VerbatimCodebook::query()->create([
            'survey_id' => $this->fx->survey->id,
            'question_key' => self::KEY,
            'version' => 1,
            'themes' => [['key' => 'prix', 'label' => 'Prix']],
            'source' => VerbatimCodebook::SOURCE_AI,
        ]);

        $payload = [
            'question_key' => self::KEY,
            'themes' => [
                ['key' => 'prix', 'label' => 'Prix trop élevé'],
                ['key' => 'Vie privée !', 'label' => 'Crainte sur la vie privée'],
            ],
        ];

        $response = $this->actingAs($this->fx->owner, 'sanctum')->putJson($this->url('/verbatims/codebooks'), $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.themes.1.key', 'vie_privee');

        // Idempotent : le même corps renvoie la même version (200, pas de v3).
        $this->actingAs($this->fx->owner, 'sanctum')->putJson($this->url('/verbatims/codebooks'), $payload)
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->assertSame(2, VerbatimCodebook::query()->forQuestion($this->fx->survey->id, self::KEY)->count());
    }

    public function test_merging_two_themes_reassigns_their_codings(): void
    {
        $codebook = VerbatimCodebook::query()->create([
            'survey_id' => $this->fx->survey->id,
            'question_key' => self::KEY,
            'version' => 1,
            'themes' => [
                ['key' => 'prix', 'label' => 'Prix'],
                ['key' => 'cout', 'label' => 'Coût'],
                ['key' => 'autonomie', 'label' => 'Autonomie'],
            ],
            'source' => VerbatimCodebook::SOURCE_AI,
        ]);

        $ids = array_slice($this->codedSubmissionIds(), 0, 3);
        VerbatimCoding::query()->create(['submission_id' => $ids[0], 'survey_id' => $this->fx->survey->id, 'question_key' => self::KEY, 'codebook_id' => $codebook->id, 'themes' => ['cout']]);
        VerbatimCoding::query()->create(['submission_id' => $ids[1], 'survey_id' => $this->fx->survey->id, 'question_key' => self::KEY, 'codebook_id' => $codebook->id, 'themes' => ['prix', 'cout']]);
        VerbatimCoding::query()->create(['submission_id' => $ids[2], 'survey_id' => $this->fx->survey->id, 'question_key' => self::KEY, 'codebook_id' => $codebook->id, 'themes' => ['autonomie']]);

        $response = $this->actingAs($this->fx->owner, 'sanctum')->putJson($this->url('/verbatims/codebooks/'.$codebook->id), [
            'themes' => [
                ['key' => 'prix', 'label' => 'Prix trop élevé'],
                ['key' => 'cout', 'label' => 'Coût', 'merge_into' => 'prix'],
                ['key' => 'autonomie', 'label' => 'Autonomie de la batterie'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.themes.0.label', 'Prix trop élevé')
            ->assertJsonCount(2, 'data.themes')
            ->assertJsonPath('meta.merged.cout', 'prix')
            ->assertJsonPath('meta.recoded', 2);

        $codebook->refresh();
        $this->assertSame(['prix', 'autonomie'], $codebook->themeKeys());

        $this->assertSame(['prix'], VerbatimCoding::query()->where('submission_id', $ids[0])->value('themes'));
        $this->assertSame(['prix'], VerbatimCoding::query()->where('submission_id', $ids[1])->value('themes'), 'la fusion dédoublonne');
        $this->assertSame(['autonomie'], VerbatimCoding::query()->where('submission_id', $ids[2])->value('themes'));

        // Idempotent : rejouer la même fusion ne change plus rien.
        $this->actingAs($this->fx->owner, 'sanctum')->putJson($this->url('/verbatims/codebooks/'.$codebook->id), [
            'themes' => [
                ['key' => 'prix', 'label' => 'Prix trop élevé'],
                ['key' => 'autonomie', 'label' => 'Autonomie de la batterie'],
            ],
        ])->assertOk()->assertJsonPath('meta.recoded', 0);
    }

    // ================================================================== lecture

    public function test_listing_verbatims_returns_codings_and_theme_counts(): void
    {
        $this->fakeDiscoverThenClassify();
        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/verbatims/classify'), ['question_key' => self::KEY, 'mode' => 'discover'])
            ->assertStatus(202);

        $response = $this->actingAs($this->supervisor, 'sanctum')
            ->getJson($this->url('/verbatims/'.self::KEY).'?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.pagination.total', 52)
            ->assertJsonPath('meta.question.key', self::KEY)
            ->assertJsonPath('meta.themes.0.key', 'confiance_donnees')
            ->assertJsonPath('meta.themes.0.count', 52)
            ->assertJsonStructure(['data' => [['submission_id', 'uuid', 'fiche_code', 'question_key', 'text', 'themes', 'sentiment', 'confidence', 'enumerator', 'ended_at']]]);

        // Filtre par thème : un thème absent ne renvoie rien.
        $this->actingAs($this->supervisor, 'sanctum')
            ->getJson($this->url('/verbatims/'.self::KEY).'?theme=autonomie')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_unknown_question_returns_404_and_pii_is_refused(): void
    {
        $this->actingAs($this->supervisor, 'sanctum')
            ->getJson($this->url('/verbatims/cle_inconnue'))
            ->assertStatus(404);

        $this->actingAs($this->supervisor, 'sanctum')
            ->getJson($this->url('/verbatims/num_whatsapp'))
            ->assertStatus(403);

        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/verbatims/classify'), ['question_key' => 'num_whatsapp', 'mode' => 'discover'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_key');

        // Une question fermée n'est pas classable.
        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/verbatims/classify'), ['question_key' => 'acompte_verse', 'mode' => 'discover'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_key');
    }

    // ================================================================== autorisations et débit

    public function test_an_enumerator_cannot_classify_nor_read_verbatims(): void
    {
        $this->actingAs($this->enumerator, 'sanctum')
            ->postJson($this->url('/verbatims/classify'), ['question_key' => self::KEY, 'mode' => 'discover'])
            ->assertStatus(403);

        $this->actingAs($this->enumerator, 'sanctum')
            ->getJson($this->url('/verbatims/'.self::KEY))
            ->assertStatus(403);

        $this->actingAs($this->stranger, 'sanctum')
            ->getJson($this->url('/verbatims/codebooks'))
            ->assertStatus(403);
    }

    public function test_a_supervisor_cannot_modify_a_codebook(): void
    {
        $codebook = VerbatimCodebook::query()->create([
            'survey_id' => $this->fx->survey->id,
            'question_key' => self::KEY,
            'version' => 1,
            'themes' => [['key' => 'prix', 'label' => 'Prix']],
            'source' => VerbatimCodebook::SOURCE_AI,
        ]);

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson($this->url('/verbatims/codebooks/'.$codebook->id), ['themes' => [['key' => 'prix', 'label' => 'Prix élevé']]])
            ->assertStatus(403);
    }

    public function test_classify_is_rate_limited_to_five_per_minute(): void
    {
        Bus::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->fx->owner, 'sanctum')
                ->postJson($this->url('/verbatims/classify'), ['question_key' => self::KEY, 'mode' => 'discover'])
                ->assertStatus(202);
        }

        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/verbatims/classify'), ['question_key' => self::KEY, 'mode' => 'discover'])
            ->assertStatus(429);
    }

    // ================================================================== helpers

    private function url(string $suffix): string
    {
        return '/api/surveys/'.$this->fx->survey->id.$suffix;
    }

    private function quotedKey(): string
    {
        return self::KEY;
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

    /** Découverte (3 thèmes) puis deux lots de classification (40 + 12). */
    private function fakeDiscoverThenClassify(): void
    {
        $this->fakeGemini([
            json_encode([
                ['key' => 'confiance_donnees', 'label' => 'Confiance dans les données', 'description' => 'Crainte sur la vie privée', 'examples' => ['Qui voit les données ?']],
                ['key' => 'prix', 'label' => 'Prix trop élevé'],
                ['key' => 'autonomie', 'label' => 'Autonomie de la batterie'],
            ], JSON_UNESCAPED_UNICODE),
            $this->classifyJson(40, ['confiance_donnees']),
            $this->classifyJson(12, ['confiance_donnees']),
        ]);
    }

    /**
     * Réponse simulée d'un lot : les `ref` sont les identifiants de soumission réellement envoyés.
     *
     * @param  list<string>  $themes
     */
    private function classifyJson(int $count, array $themes): string
    {
        $ids = array_slice($this->codedSubmissionIds(), $this->classifyOffset, $count);
        $this->classifyOffset += $count;

        return $this->classifyJsonFor($ids, $themes);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $themes
     */
    private function classifyJsonFor(array $ids, array $themes): string
    {
        return (string) json_encode(array_map(
            static fn (int $id): array => ['ref' => $id, 'themes' => $themes, 'sentiment' => 'negative', 'confidence' => 0.9],
            array_values($ids),
        ));
    }

    /**
     * Identifiants des soumissions ayant répondu à `q6_freins`, dans l'ordre du service.
     *
     * @return list<int>
     */
    private function codedSubmissionIds(): array
    {
        if ($this->codedIds === null) {
            $this->codedIds = $this->fx->submissions
                ->filter(fn ($s) => is_string($s->answers[self::KEY] ?? null) && trim($s->answers[self::KEY]) !== '')
                ->map(fn ($s) => (int) $s->id)
                ->sort()
                ->values()
                ->all();
        }

        return $this->codedIds;
    }

    /** Crée et matérialise la source de données (comme le ferait la publication + un rebuild). */
    private function materialize(): void
    {
        $this->actingAs($this->fx->owner, 'sanctum')
            ->postJson($this->url('/datasource/rebuild').'?sync=1')
            ->assertStatus(202);
    }

    private function firstCodedSubmissionId(): int
    {
        return $this->codedSubmissionIds()[0];
    }

    private function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->handles[] = $pdo;

        return $pdo;
    }
}
