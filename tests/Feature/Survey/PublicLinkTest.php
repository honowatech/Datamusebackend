<?php

namespace Tests\Feature\Survey;

use App\Enums\SubmissionChannel;
use App\Enums\SurveyStatus;
use App\Enums\UserRole;
use App\Jobs\MaterializeSurveyDatasourceJob;
use App\Models\ProjectMember;
use App\Models\PublicLink;
use App\Models\Submission;
use App\Models\SurveyDatasource;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * B-12 — liens publics (`/surveys/{id}/public-links`) et collecte anonyme (`/public/surveys/{token}`).
 */
class PublicLinkTest extends TestCase
{
    use RefreshDatabase;

    private MunagoFixtureLoader $fx;

    private User $analyst;

    private User $supervisor;

    private User $stranger;

    private PublicLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fx = MunagoFixtureLoader::load(['limit' => 6]);
        $this->fx->survey->forceFill(['status' => SurveyStatus::Active])->save();

        $this->analyst = $this->fx->owner;
        ProjectMember::factory()->analyste()->create([
            'project_id' => $this->fx->project->id,
            'user_id' => $this->analyst->id,
        ]);

        $this->supervisor = User::factory()->create(['role' => UserRole::Analyste]);
        ProjectMember::factory()->superviseur()->create([
            'project_id' => $this->fx->project->id,
            'user_id' => $this->supervisor->id,
        ]);

        $this->stranger = User::factory()->create(['role' => UserRole::Analyste]);

        $this->link = PublicLink::factory()->create([
            'survey_id' => $this->fx->survey->id,
            'created_by' => $this->analyst->id,
            'label' => 'Campagne WhatsApp',
        ]);
    }

    /**
     * Payload public valide : les réponses d'une fiche complète de la fixture, un uuid neuf et une
     * durée supérieure au tiers de `min_duration_seconds` (600 / 3 = 200 s).
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $source = collect($this->fx->fixture['submissions'])
            ->first(fn (array $s) => ($s['status'] ?? '') !== 'screened_out');

        $startedAt = Carbon::parse('2026-09-16T10:00:00+01:00');

        return array_replace([
            'uuid' => (string) Str::uuid(),
            'language' => 'fr',
            'started_at' => $startedAt->toIso8601String(),
            'ended_at' => $startedAt->copy()->addMinutes(5)->toIso8601String(),
            'client_updated_at' => $startedAt->copy()->addMinutes(5)->toIso8601String(),
            'status' => 'completed',
            'answers' => $source['answers'],
        ], $overrides);
    }

    // ------------------------------------------------------------------ CRUD authentifié

    public function test_analyst_creates_lists_and_deactivates_a_link(): void
    {
        $created = $this->actingAs($this->analyst)
            ->postJson('/api/surveys/'.$this->fx->survey->id.'/public-links', [
                'label' => 'Relais paroisse',
                'max_responses' => 50,
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame('Relais paroisse', $created['label']);
        $this->assertSame(50, $created['max_responses']);
        $this->assertSame(50, $created['remaining']);
        $this->assertSame('open', $created['state']);
        $this->assertSame(40, strlen($created['token']));
        $this->assertStringEndsWith('/s/'.$created['token'], $created['url']);
        $this->assertSame($this->analyst->id, $created['created_by']['id']);

        $list = $this->actingAs($this->supervisor)
            ->getJson('/api/surveys/'.$this->fx->survey->id.'/public-links')->assertOk()->json('data');
        $this->assertCount(2, $list);

        $this->actingAs($this->analyst)
            ->deleteJson('/api/surveys/'.$this->fx->survey->id.'/public-links/'.$created['id'])
            ->assertNoContent();

        $this->assertFalse((bool) PublicLink::query()->whereKey($created['id'])->value('is_active'));

        // Le lien désactivé répond 410 sans perdre ses réponses.
        $this->getJson('/api/public/surveys/'.$created['token'])
            ->assertStatus(410)
            ->assertJsonPath('reason', 'inactive');
    }

    public function test_creation_is_refused_when_the_survey_forbids_public_links(): void
    {
        $version = $this->fx->survey->publishedVersion;
        $definition = $version->definition;
        $definition['settings']['allow_public_link'] = false;
        $version->forceFill(['definition' => $definition])->save();
        $this->fx->survey->refresh();

        $this->actingAs($this->analyst)
            ->postJson('/api/surveys/'.$this->fx->survey->id.'/public-links')
            ->assertStatus(409)
            ->assertJsonPath('code', 'public_link_disabled');
    }

    public function test_creation_is_refused_on_a_closed_survey_and_for_a_supervisor(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson('/api/surveys/'.$this->fx->survey->id.'/public-links')
            ->assertStatus(403);

        $this->actingAs($this->stranger)
            ->getJson('/api/surveys/'.$this->fx->survey->id.'/public-links')
            ->assertStatus(403);

        $this->fx->survey->forceFill(['status' => SurveyStatus::Closed])->save();
        $this->actingAs($this->analyst)
            ->postJson('/api/surveys/'.$this->fx->survey->id.'/public-links')
            ->assertStatus(409)
            ->assertJsonPath('code', 'survey_not_active');
    }

    // ------------------------------------------------------------------ définition publique

    public function test_public_definition_is_filtered_and_needs_no_authentication(): void
    {
        $data = $this->getJson('/api/public/surveys/'.$this->link->token)->assertOk()->json('data');

        $this->assertSame($this->fx->survey->id, $data['survey_id']);
        $this->assertSame(1, $data['version']);
        $this->assertNull($data['remaining']);
        $this->assertSame(200, $data['min_duration_seconds'], '600 / 3');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['definition_hash']);

        $json = (string) json_encode($data['definition']);
        $this->assertStringNotContainsString('"num_whatsapp"', $json, 'question `tags: ["pii"]`');
        $this->assertStringNotContainsString('"nom_compte_momo"', $json, 'question `tags: ["pii"]`');
        $this->assertStringNotContainsString('"q15_observation"', $json, 'question `enumerator_only`');
        $this->assertStringContainsString('"quartier"', $json);
        $this->assertSame([], $data['definition']['follow_up_stages']);

        foreach ($data['definition']['sections'] as $section) {
            $this->assertNotEmpty($section['items'], 'aucune section vide ne doit subsister');
            foreach ($section['items'] as $item) {
                $this->assertNotSame('enumerator', $item['audience'] ?? null);
            }
        }

        // L'empreinte porte bien sur la définition filtrée, pas sur celle de la version publiée.
        $this->assertNotSame(
            SurveyVersion::query()->whereKey($this->fx->version->id)->value('definition_hash'),
            $data['definition_hash'],
        );
    }

    public function test_unknown_token_returns_404(): void
    {
        $this->getJson('/api/public/surveys/inconnu123')->assertStatus(404);
    }

    public function test_expired_full_and_inactive_links_return_410_with_a_reason(): void
    {
        $cases = [
            'expired' => PublicLink::factory()->expired(),
            'full' => PublicLink::factory()->full(),
            'inactive' => PublicLink::factory()->inactive(),
        ];

        foreach ($cases as $reason => $factory) {
            $link = $factory->create(['survey_id' => $this->fx->survey->id, 'created_by' => $this->analyst->id]);

            $this->getJson('/api/public/surveys/'.$link->token)
                ->assertStatus(410)
                ->assertJsonPath('success', false)
                ->assertJsonPath('reason', $reason);

            $this->postJson('/api/public/surveys/'.$link->token.'/submissions', [
                'submission' => $this->payload(),
            ])->assertStatus(410)->assertJsonPath('reason', $reason);
        }
    }

    // ------------------------------------------------------------------ soumission

    public function test_a_public_submission_is_stored_on_the_public_channel(): void
    {
        Queue::fake();
        SurveyDatasource::query()->create(['survey_id' => $this->fx->survey->id, 'dirty' => false]);

        $payload = $this->payload();

        $result = $this->postJson('/api/public/surveys/'.$this->link->token.'/submissions', [
            'submission' => $payload,
            'website' => '',
        ])->assertOk()->json('data');

        $this->assertSame('accepted', $result['status']);
        $this->assertNotNull($result['server_id']);

        $submission = Submission::query()->where('uuid', $payload['uuid'])->firstOrFail();
        $this->assertSame(SubmissionChannel::Public, $submission->channel);
        $this->assertNull($submission->enumerator_id);
        $this->assertNull($submission->device_id);
        $this->assertSame($this->fx->survey->id, $submission->survey_id);
        $this->assertSame($this->fx->version->id, $submission->survey_version_id);

        $this->assertSame(1, (int) $this->link->fresh()->responses_count);

        // La matérialisation suit automatiquement (écouteur de `SubmissionReceived`).
        $this->assertTrue(SurveyDatasource::query()->where('survey_id', $this->fx->survey->id)->value('dirty'));
        Queue::assertPushed(MaterializeSurveyDatasourceJob::class);
    }

    public function test_the_honeypot_pretends_to_accept_without_storing_anything(): void
    {
        $payload = $this->payload();

        $result = $this->postJson('/api/public/surveys/'.$this->link->token.'/submissions', [
            'submission' => $payload,
            'website' => 'https://spam.example',
        ])->assertOk()->json('data');

        $this->assertSame('accepted', $result['status']);
        $this->assertNull($result['server_id']);
        $this->assertDatabaseMissing('submissions', ['uuid' => $payload['uuid']]);
        $this->assertSame(0, (int) $this->link->fresh()->responses_count);
    }

    public function test_a_submission_filled_too_fast_is_rejected(): void
    {
        $startedAt = Carbon::parse('2026-09-16T10:00:00+01:00');
        $payload = $this->payload([
            'started_at' => $startedAt->toIso8601String(),
            'ended_at' => $startedAt->copy()->addSeconds(30)->toIso8601String(),
        ]);

        $result = $this->postJson('/api/public/surveys/'.$this->link->token.'/submissions', [
            'submission' => $payload,
        ])->assertOk()->json('data');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('too_fast', $result['errors'][0]['code']);
        $this->assertDatabaseMissing('submissions', ['uuid' => $payload['uuid']]);
        $this->assertSame(0, (int) $this->link->fresh()->responses_count);
    }

    // ==== E-01 ====
    /**
     * Le canal public reçoit la définition filtrée : les questions `pii` retirées ne doivent pas
     * être exigées à la validation. Sur MunaGo, `num_whatsapp` et `nom_compte_momo` sont `pii`,
     * obligatoires et pertinentes dès `acompte_verse` : sans ce correctif, **toute** réponse
     * publique décrivant un acompte était rejetée alors que le formulaire servi ne les demandait pas.
     */
    public function test_a_public_submission_is_not_rejected_for_pii_questions_removed_from_the_public_form(): void
    {
        Queue::fake();

        $source = collect($this->fx->fixture['submissions'])
            ->first(fn (array $s) => ($s['answers']['acompte_verse'] ?? null) === true);
        $this->assertNotNull($source, 'La fixture doit contenir une fiche avec acompte.');

        $answers = $source['answers'];
        unset($answers['num_whatsapp'], $answers['nom_compte_momo']);
        // `date_limite_retrait` est contraint à [aujourd'hui, J+10] par rapport à la date d'entretien.
        $answers['date_limite_retrait'] = Carbon::parse('2026-09-16')->addDays(7)->toDateString();

        $payload = $this->payload(['answers' => $answers]);

        $result = $this->postJson('/api/public/surveys/'.$this->link->token.'/submissions', [
            'submission' => $payload,
            'website' => '',
        ])->assertOk()->json('data');

        $this->assertSame('accepted', $result['status'], 'Réponse rejetée : '.json_encode($result['errors'] ?? []));

        $submission = Submission::query()->where('uuid', $payload['uuid'])->firstOrFail();
        $this->assertSame(SubmissionChannel::Public, $submission->channel);
        // Les réponses `pii` ne sont ni exigées ni stockées sur le canal public.
        $this->assertArrayNotHasKey('num_whatsapp', $submission->answers ?? []);
        $this->assertArrayNotHasKey('nom_compte_momo', $submission->answers ?? []);
    }

    /** Le canal mobile continue, lui, d'exiger les questions `pii` obligatoires. */
    public function test_the_mobile_channel_still_requires_the_pii_questions(): void
    {
        $enumerator = $this->fx->enumerators[1];

        $source = collect($this->fx->fixture['submissions'])
            ->first(fn (array $s) => ($s['answers']['acompte_verse'] ?? null) === true);

        $answers = $source['answers'];
        unset($answers['num_whatsapp'], $answers['nom_compte_momo']);
        $answers['date_limite_retrait'] = Carbon::parse('2026-09-16')->addDays(7)->toDateString();

        $payload = $this->payload(['answers' => $answers, 'survey_id' => $this->fx->survey->id, 'form_version' => $this->fx->version->version]);

        $result = $this->actingAs($enumerator)
            ->postJson('/api/mobile/submissions', ['submissions' => [$payload]])
            ->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertArrayHasKey('num_whatsapp', $result['errors_by_key']);
    }
    // ==== /E-01 ====

    public function test_resending_the_same_uuid_answers_duplicate_without_counting_twice(): void
    {
        $payload = $this->payload();
        $body = ['submission' => $payload];

        $this->postJson('/api/public/surveys/'.$this->link->token.'/submissions', $body)
            ->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->postJson('/api/public/surveys/'.$this->link->token.'/submissions', $body)
            ->assertOk()->assertJsonPath('data.status', 'duplicate');

        $this->assertSame(1, Submission::query()->where('uuid', $payload['uuid'])->count());
        $this->assertSame(1, (int) $this->link->fresh()->responses_count);
    }

    public function test_a_link_becomes_full_once_max_responses_is_reached(): void
    {
        $link = PublicLink::factory()->create([
            'survey_id' => $this->fx->survey->id,
            'created_by' => $this->analyst->id,
            'max_responses' => 1,
        ]);

        $this->postJson('/api/public/surveys/'.$link->token.'/submissions', ['submission' => $this->payload()])
            ->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->getJson('/api/public/surveys/'.$link->token)
            ->assertStatus(410)
            ->assertJsonPath('reason', 'full');

        $this->postJson('/api/public/surveys/'.$link->token.'/submissions', ['submission' => $this->payload()])
            ->assertStatus(410)
            ->assertJsonPath('reason', 'full');
    }

    public function test_write_throttle_returns_429_after_ten_calls_per_minute(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/public/surveys/'.$this->link->token.'/submissions', [
                'submission' => $this->payload(),
                'website' => 'bot',
            ])->assertOk();
        }

        $this->postJson('/api/public/surveys/'.$this->link->token.'/submissions', [
            'submission' => $this->payload(),
            'website' => 'bot',
        ])->assertStatus(429);
    }

    public function test_cors_is_open_on_the_public_routes(): void
    {
        $this->getJson('/api/public/surveys/'.$this->link->token)
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    // ------------------------------------------------------------------ médias

    public function test_a_media_can_be_uploaded_for_a_submission_of_this_link(): void
    {
        Storage::fake('local');

        $payload = $this->payload();
        $payload['answers']['acompte_verse'] = true;
        $payload['answers']['num_whatsapp'] = '699112233';
        $payload['answers']['nom_compte_momo'] = 'Mme Ngo';

        $file = UploadedFile::fake()->createWithContent('recu.jpg', 'contenu-du-recu');
        $sha256 = hash('sha256', 'contenu-du-recu');
        $payload['media'] = [[
            'question_key' => 'photo_recu_momo',
            'repeat_index' => 0,
            'sha256' => $sha256,
            'mime' => 'image/jpeg',
            'size' => strlen('contenu-du-recu'),
        ]];

        $result = $this->postJson('/api/public/surveys/'.$this->link->token.'/submissions', ['submission' => $payload])
            ->assertOk()->json('data');
        $this->assertSame('accepted', $result['status'], (string) json_encode($result['errors'] ?? []));
        $this->assertNotEmpty($result['pending_media']);

        $upload = $this->post(
            '/api/public/surveys/'.$this->link->token.'/submissions/'.$payload['uuid'].'/media/photo_recu_momo',
            ['file' => $file, 'sha256' => $sha256, 'repeat_index' => 0],
            ['Idempotency-Key' => 'pub-1'],
        );
        $upload->assertStatus(201);
        $this->assertSame('uploaded', $upload->json('data.status'));

        $this->assertDatabaseHas('submission_media', [
            'question_key' => 'photo_recu_momo',
            'sha256' => $sha256,
            'state' => 'uploaded',
        ]);
    }

    public function test_a_media_for_a_submission_of_another_link_is_refused(): void
    {
        $other = PublicLink::factory()->create(['survey_id' => $this->fx->survey->id, 'created_by' => $this->analyst->id]);
        $payload = $this->payload();

        $this->postJson('/api/public/surveys/'.$this->link->token.'/submissions', ['submission' => $payload])->assertOk();

        $this->post(
            '/api/public/surveys/'.$other->token.'/submissions/'.$payload['uuid'].'/media/photo_recu_momo',
            ['file' => UploadedFile::fake()->createWithContent('x.jpg', 'abc'), 'sha256' => hash('sha256', 'abc')],
        )->assertStatus(404);
    }
}
