<?php

namespace Tests\Feature\Survey;

use App\Enums\MediaState;
use App\Enums\SurveyStatus;
use App\Enums\UserRole;
use App\Enums\VersionStatus;
use App\Events\SubmissionReceived;
use App\Models\DeletedSubmission;
use App\Models\Device;
use App\Models\EnumeratorAssignment;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\SubmissionMedia;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\User;
use Database\Factories\SurveyVersionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * B-07 : API de synchronisation mobile — sonde, appareils, manifest (ETag/304), définition publiée
 * transportée comme chaîne JSON, lot de soumissions idempotent (conflits, fiche code, drapeaux
 * qualité, événement), envoi de médias (idempotence, sha256, URL signée) et état des uuid.
 *
 * Les payloads proviennent de la fixture du contrat `docs/fixtures/munago.submissions.json`.
 */
class MobileSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

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
        $this->enumerator = User::factory()->create(['role' => UserRole::Enqueteur]);
        $this->stranger = User::factory()->create(['role' => UserRole::Enqueteur]);

        $this->project = SurveyProject::factory()->create(['owner_id' => $this->owner->id, 'name' => 'MunaGo Douala']);
        ProjectMember::factory()->analyste()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $this->enumerator->id, 'zone' => 'Logpom']);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $this->stranger->id, 'zone' => 'Akwa']);

        $this->survey = Survey::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'title' => 'MunaGo — terrain',
            'slug' => 'munago-terrain',
        ]);
        $this->version = SurveyVersion::factory()->munago()->published()->create([
            'survey_id' => $this->survey->id,
            'published_by' => $this->owner->id,
        ]);
        $this->survey->refresh();

        EnumeratorAssignment::factory()->create([
            'survey_id' => $this->survey->id,
            'user_id' => $this->enumerator->id,
            'zone' => 'Logpom',
            'quota_target' => 10,
        ]);
    }

    // ================================================================== ping

    public function test_ping_is_public_and_returns_204_with_server_time_header(): void
    {
        $response = $this->getJson('/api/mobile/ping');

        $response->assertNoContent();
        $this->assertNotEmpty($response->headers->get('X-Server-Time'));
        $this->assertNotFalse(strtotime((string) $response->headers->get('X-Server-Time')));
    }

    // ================================================================== appareils

    public function test_device_registration_upserts_and_measures_clock_offset(): void
    {
        $payload = [
            'device_id' => 'android-enq1-a3f1',
            'platform' => 'android',
            'app_version' => '1.0.0+1',
            'model' => 'Tecno Spark 10',
            'device_time' => now()->addMinutes(2)->toIso8601String(),
        ];

        $first = $this->actingAs($this->enumerator, 'sanctum')->postJson('/api/mobile/devices', $payload);
        $first->assertOk()
            ->assertJsonPath('data.device_id', 'android-enq1-a3f1')
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.model', 'Tecno Spark 10')
            ->assertJsonStructure(['meta' => ['server_time']]);

        $this->assertGreaterThan(100_000, (int) $first->json('data.time_offset_ms'));

        $second = $this->actingAs($this->enumerator, 'sanctum')
            ->postJson('/api/mobile/devices', ['device_id' => 'android-enq1-a3f1', 'platform' => 'android', 'app_version' => '1.1.0+7']);

        $second->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, Device::query()->where('user_id', $this->enumerator->id)->count());
        $this->assertSame('1.1.0+7', Device::query()->first()->app_version);
        $this->assertNull($second->json('data.time_offset_ms'));
    }

    // ================================================================== manifest

    public function test_manifest_lists_assigned_survey_with_assignment_quotas_and_etag(): void
    {
        $response = $this->actingAs($this->enumerator, 'sanctum')->getJson('/api/mobile/forms');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.survey_id', $this->survey->id)
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('data.0.definition_hash', $this->version->definition_hash)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.zone', 'Logpom')
            ->assertJsonPath('data.0.quota_target', 10)
            ->assertJsonPath('data.0.assignment.zone', 'Logpom')
            ->assertJsonPath('data.0.my_counts.completed', 0)
            ->assertJsonPath('data.0.settings_summary.default_language', 'fr')
            ->assertJsonPath('data.0.settings_summary.geo.capture', 'both')
            ->assertJsonStructure(['meta' => ['server_time'], 'data' => [['quotas', 'settings_summary']]]);

        $this->assertNotEmpty($response->headers->get('ETag'));
        $this->assertStringStartsWith('"sha256-', (string) $response->headers->get('ETag'));
        $this->assertSame(
            ['total_valid', 'quartiers_distincts', 'par_reseau', 'par_quartier'],
            array_column($response->json('data.0.quotas'), 'key'),
        );
    }

    public function test_manifest_is_empty_for_an_unassigned_enumerator(): void
    {
        $this->actingAs($this->stranger, 'sanctum')
            ->getJson('/api/mobile/forms')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_manifest_returns_304_when_if_none_match_matches(): void
    {
        $etag = (string) $this->actingAs($this->enumerator, 'sanctum')->getJson('/api/mobile/forms')->headers->get('ETag');

        $response = $this->actingAs($this->enumerator, 'sanctum')
            ->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/mobile/forms');

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $this->assertNotEmpty($response->headers->get('X-Server-Time'));
    }

    // ================================================================== définition

    public function test_form_definition_is_a_json_string_whose_sha256_matches_the_hash(): void
    {
        $response = $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/mobile/forms/{$this->survey->id}");

        $response->assertOk()
            ->assertJsonPath('data.survey_id', $this->survey->id)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'published');

        $definition = $response->json('data.definition');
        $this->assertIsString($definition);
        $this->assertSame($response->json('data.definition_hash'), hash('sha256', $definition));
        $this->assertSame($this->version->definition_hash, hash('sha256', $definition));

        $decoded = json_decode($definition, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.0', $decoded['dfs_version']);
        $this->assertSame(SurveyVersionFactory::munagoDefinition()['title']['fr'], $decoded['title']['fr']);
        $this->assertSame(['fr', 'en'], $decoded['settings']['languages']);
    }

    public function test_form_definition_is_forbidden_for_an_unassigned_enumerator_and_404_without_publication(): void
    {
        $this->actingAs($this->stranger, 'sanctum')
            ->getJson("/api/mobile/forms/{$this->survey->id}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $draftSurvey = Survey::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        SurveyVersion::factory()->create(['survey_id' => $draftSurvey->id]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/mobile/forms/{$draftSurvey->id}")
            ->assertStatus(404);
    }

    // ================================================================== lot de soumissions

    public function test_batch_accepts_valid_and_screened_out_and_rejects_an_invalid_payload(): void
    {
        $valid = $this->payloadAt(0);
        $screenedOut = $this->payloadAt(1);
        $invalid = $this->payloadAt(2);
        unset($invalid['answers']['consentement']);

        $response = $this->sync([$valid, $screenedOut, $invalid]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data.results')
            ->assertJsonStructure(['meta' => ['server_time']]);

        $results = $response->json('data.results');
        $this->assertSame('accepted', $results[0]['status']);
        $this->assertSame('accepted', $results[1]['status']);
        $this->assertSame('rejected', $results[2]['status']);

        $this->assertArrayHasKey('consentement', $results[2]['errors_by_key']);
        $this->assertSame('/answers/consentement', $results[2]['errors'][0]['path']);
        $this->assertSame('required', $results[2]['errors'][0]['code']);
        $this->assertNull($results[2]['server_id']);

        $stored = Submission::query()->where('uuid', $valid['uuid'])->firstOrFail();
        $this->assertSame('submitted', $stored->status->value);
        $this->assertSame($this->enumerator->id, $stored->enumerator_id);
        $this->assertSame('mobile', $stored->channel->value);
        $this->assertSame('DLA-LGP-01', $stored->fiche_code);
        $this->assertNotNull($stored->received_at);
        $this->assertNotNull($stored->answers_hash);
        $this->assertSame(828, $stored->duration_seconds);
        $this->assertEqualsWithDelta(4.06701, (float) $stored->geo_lat, 0.00001);
        $this->assertSame($valid['geo'], $stored->geo);
        $this->assertSame('valide', $stored->answers['resultat_filtre']);

        $out = Submission::query()->where('uuid', $screenedOut['uuid'])->firstOrFail();
        $this->assertSame('screened_out', $out->status->value);
        $this->assertSame('stop_consentement', $out->end_reason);

        $this->assertSame(2, $this->survey->fresh()->submissions_count);
        $this->assertNotNull($this->survey->fresh()->last_submission_at);
        $this->assertSame(0, Submission::query()->where('uuid', $invalid['uuid'])->count());
    }

    public function test_resending_the_same_submission_is_a_duplicate(): void
    {
        $payload = $this->payloadAt(0);

        $this->sync([$payload])->assertJsonPath('data.results.0.status', 'accepted');

        $second = $this->sync([$payload]);
        $second->assertJsonPath('data.results.0.status', 'duplicate')
            ->assertJsonPath('data.results.0.server_status', 'submitted');

        $this->assertNotNull($second->json('data.results.0.server_id'));
        $this->assertSame(1, Submission::query()->count());
        $this->assertSame(1, $this->survey->fresh()->submissions_count);
    }

    public function test_newer_payload_is_a_duplicate_unless_the_survey_allows_editing_after_submit(): void
    {
        $payload = $this->payloadAt(0);
        $this->sync([$payload])->assertJsonPath('data.results.0.status', 'accepted');

        $edited = $payload;
        $edited['client_updated_at'] = Carbon::parse($payload['client_updated_at'])->addHour()->toIso8601String();
        $edited['answers']['age_approx'] = 51;

        $this->sync([$edited])->assertJsonPath('data.results.0.status', 'duplicate');
        $this->assertSame(43, Submission::query()->where('uuid', $payload['uuid'])->firstOrFail()->answers['age_approx']);

        // Même questionnaire, réglage `enumerator_can_edit_after_submit` activé.
        $definition = $this->version->definition;
        $definition['settings']['enumerator_can_edit_after_submit'] = true;
        $this->version->forceFill(['definition' => $definition])->save();

        $this->sync([$edited])->assertJsonPath('data.results.0.status', 'updated');
        $this->assertSame(51, Submission::query()->where('uuid', $payload['uuid'])->firstOrFail()->answers['age_approx']);
        $this->assertSame(1, Submission::query()->count());
        $this->assertSame(1, $this->survey->fresh()->submissions_count);
    }

    public function test_a_rejected_payload_resent_corrected_replaces_the_previous_attempt(): void
    {
        $payload = $this->payloadAt(2);
        $broken = $payload;
        unset($broken['answers']['consentement']);

        $this->sync([$broken])->assertJsonPath('data.results.0.status', 'rejected');
        $this->assertSame(0, Submission::query()->count());

        $this->sync([$payload])->assertJsonPath('data.results.0.status', 'updated');
        $this->assertSame(1, Submission::query()->where('uuid', $payload['uuid'])->count());
        $this->assertSame(1, $this->survey->fresh()->submissions_count);
    }

    public function test_conflicts_cover_archived_version_closed_survey_and_unassigned_enumerator(): void
    {
        // 1. enquêteur non assigné
        $this->actingAs($this->stranger, 'sanctum')
            ->postJson('/api/mobile/submissions', ['submissions' => [$this->payloadAt(0)]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'conflict')
            ->assertJsonPath('data.results.0.conflict_reason', 'not_assigned');

        // 2. version archivée : la v1 est archivée par la publication de la v2 il y a une heure.
        $v2 = SurveyVersion::factory()->create([
            'survey_id' => $this->survey->id,
            'version' => 2,
            'status' => VersionStatus::Published,
            'definition' => SurveyVersionFactory::munagoDefinition(),
            'published_at' => now()->subHour(),
            'published_by' => $this->owner->id,
        ]);
        $this->version->forceFill(['status' => VersionStatus::Archived])->save();
        $this->survey->forceFill(['published_version_id' => $v2->id, 'current_version_id' => $v2->id])->save();

        $late = $this->payloadAt(0);
        $late['started_at'] = now()->subMinutes(10)->toIso8601String();
        $late['ended_at'] = now()->subMinutes(2)->toIso8601String();
        $late['client_updated_at'] = now()->toIso8601String();

        $this->sync([$late])
            ->assertJsonPath('data.results.0.status', 'conflict')
            ->assertJsonPath('data.results.0.conflict_reason', 'version_archived');

        // Entretien commencé AVANT l'archivage : accepté sur la version archivée (README § 15).
        $early = $this->payloadAt(2);
        $this->sync([$early])->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertSame($this->version->id, Submission::query()->where('uuid', $early['uuid'])->firstOrFail()->survey_version_id);

        // 3. questionnaire fermé
        $this->survey->forceFill(['status' => SurveyStatus::Closed])->save();
        $this->sync([$this->payloadAt(3)])
            ->assertJsonPath('data.results.0.status', 'conflict')
            ->assertJsonPath('data.results.0.conflict_reason', 'survey_closed');
    }

    public function test_fiche_code_collision_is_suffixed_and_reported(): void
    {
        $first = $this->payloadAt(0);
        $second = $this->payloadAt(2);
        $second['fiche_code'] = $first['fiche_code'];

        $this->sync([$first])->assertJsonPath('data.results.0.fiche_code', 'DLA-LGP-01');

        $response = $this->sync([$second]);
        $response->assertJsonPath('data.results.0.status', 'accepted')
            ->assertJsonPath('data.results.0.fiche_code', 'DLA-LGP-01-B')
            ->assertJsonPath('data.results.0.fiche_code_reassigned', true);

        $this->assertSame('DLA-LGP-01-B', Submission::query()->where('uuid', $second['uuid'])->firstOrFail()->fiche_code);
    }

    public function test_quality_flags_are_computed_on_reception(): void
    {
        // too_fast : 312 s < settings.timing.min_duration_seconds (600).
        $fast = $this->payloadByUuid('fac23252-9a9a-4b90-8d9e-3fb80a1a09c5');
        $response = $this->sync([$fast]);
        $response->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertContains(Submission::FLAG_TOO_FAST, $response->json('data.results.0.flags'));

        $stored = Submission::query()->where('uuid', $fast['uuid'])->firstOrFail();
        $this->assertTrue($stored->hasFlag(Submission::FLAG_TOO_FAST));
        $this->assertGreaterThan(0, $stored->suspicion_score);

        // clock_skew : |offset| > 10 min.
        $skewed = $this->payloadAt(4);
        $skewed['device_time_offset_ms'] = 15 * 60 * 1000;
        $this->assertContains(
            Submission::FLAG_CLOCK_SKEW,
            $this->sync([$skewed])->json('data.results.0.flags'),
        );

        // duplicate : même `num_whatsapp` (settings.duplicate_keys).
        $a = $this->payloadByUuid('cb09fafa-aa37-4cc2-8ae8-780e0263f3f3');
        $b = $this->payloadByUuid('ac22f891-5d58-40c0-8da1-1a3632a4dde4');
        $this->assertSame($a['answers']['num_whatsapp'], $b['answers']['num_whatsapp']);

        $this->sync([$a]);
        $this->assertContains(Submission::FLAG_DUPLICATE, $this->sync([$b])->json('data.results.0.flags'));
    }

    public function test_off_hours_flag_uses_the_local_time_of_the_payload(): void
    {
        $payload = $this->payloadAt(0);
        $payload['started_at'] = '2026-09-01T05:10:00+01:00';
        $payload['ended_at'] = '2026-09-01T05:40:00+01:00';
        $payload['client_updated_at'] = '2026-09-01T05:41:00+01:00';
        $payload['geo'] = null;

        $this->assertContains(
            Submission::FLAG_OFF_HOURS,
            $this->sync([$payload])->json('data.results.0.flags'),
        );
    }

    public function test_submission_received_event_is_dispatched(): void
    {
        Event::fake([SubmissionReceived::class]);

        $payload = $this->payloadAt(0);
        $this->sync([$payload])->assertJsonPath('data.results.0.status', 'accepted');

        Event::assertDispatched(
            SubmissionReceived::class,
            fn (SubmissionReceived $event) => $event->submission->uuid === $payload['uuid'] && $event->created === true,
        );
        Event::assertDispatchedTimes(SubmissionReceived::class, 1);
    }

    public function test_a_batch_larger_than_fifty_is_refused(): void
    {
        $payloads = [];
        for ($i = 0; $i < 51; $i++) {
            $payload = $this->payloadAt(0);
            $payload['uuid'] = (string) Str::uuid();
            $payload['fiche_code'] = 'DLA-LGP-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $payloads[] = $payload;
        }

        $this->actingAs($this->enumerator, 'sanctum')
            ->postJson('/api/mobile/submissions', ['submissions' => $payloads])
            ->assertStatus(413)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Submission::query()->count());
    }

    public function test_mobile_group_uses_the_600_per_minute_limiter(): void
    {
        $response = $this->actingAs($this->enumerator, 'sanctum')->getJson('/api/mobile/forms');

        $this->assertSame('600', (string) $response->headers->get('X-RateLimit-Limit'));
        $this->assertLessThanOrEqual(600, (int) $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_a_deleted_uuid_is_never_recreated(): void
    {
        $payload = $this->payloadAt(0);
        $this->sync([$payload])->assertJsonPath('data.results.0.status', 'accepted');

        $submission = Submission::query()->where('uuid', $payload['uuid'])->firstOrFail();
        DeletedSubmission::remember($submission, $this->owner->id);
        $submission->delete();

        $this->sync([$payload])->assertJsonPath('data.results.0.status', 'duplicate');
        $this->assertSame(0, Submission::query()->count());
    }

    // ================================================================== médias

    public function test_media_upload_stores_the_file_and_is_idempotent(): void
    {
        Storage::fake('local');
        $payload = $this->payloadWithMedia();
        $this->sync([$payload]);

        $response = $this->uploadMedia($payload['uuid'], 'photo_recu_momo', 'contenu-photo');

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'uploaded')
            ->assertJsonPath('data.question_key', 'photo_recu_momo')
            ->assertJsonPath('data.mime', 'image/jpeg')
            ->assertJsonStructure(['meta' => ['server_time']]);

        $media = SubmissionMedia::query()->firstOrFail();
        $this->assertSame(MediaState::Uploaded, $media->state);
        $this->assertSame(
            sprintf('surveys/%d/submissions/%s/photo_recu_momo.jpg', $this->survey->id, $payload['uuid']),
            $media->path,
        );
        Storage::disk('local')->assertExists($media->path);

        $submission = Submission::query()->where('uuid', $payload['uuid'])->firstOrFail();
        $this->assertSame($media->id, $submission->answers['photo_recu_momo']['media_id']);

        // Même fichier renvoyé (réseau instable) → already_exists, aucun doublon.
        $this->uploadMedia($payload['uuid'], 'photo_recu_momo', 'contenu-photo')
            ->assertOk()
            ->assertJsonPath('data.status', 'already_exists')
            ->assertJsonPath('data.media_id', $media->id);

        $this->assertSame(1, SubmissionMedia::query()->count());
    }

    public function test_media_upload_rejects_a_sha256_mismatch_and_a_non_media_question(): void
    {
        Storage::fake('local');
        $payload = $this->payloadWithMedia();
        $this->sync([$payload]);

        $this->actingAs($this->enumerator, 'sanctum')->post(
            "/api/mobile/submissions/{$payload['uuid']}/media/photo_recu_momo",
            [
                'sha256' => str_repeat('a', 64),
                'file' => UploadedFile::fake()->createWithContent('recu.jpg', 'contenu-photo'),
            ],
            ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()],
        )
            ->assertStatus(422)
            ->assertJsonPath('errors.sha256.0', 'sha256_mismatch');

        $this->uploadMedia($payload['uuid'], 'q6_freins', 'contenu-photo')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['question_key']]);

        $this->assertSame(0, SubmissionMedia::query()->uploaded()->count());
    }

    public function test_signed_media_url_serves_the_file_and_expires(): void
    {
        Storage::fake('local');
        $payload = $this->payloadWithMedia();
        $this->sync([$payload]);
        $this->uploadMedia($payload['uuid'], 'photo_recu_momo', 'contenu-photo')->assertStatus(201);

        $media = SubmissionMedia::query()->firstOrFail();
        $url = $media->signedUrl(30);
        $this->assertIsString($url);

        $this->get($url)->assertOk();

        // Sans signature : refusé.
        $this->get("/api/media/{$media->id}")->assertStatus(403);

        // Signature expirée.
        $expired = URL::temporarySignedRoute('media.show', now()->subMinute(), ['media' => $media->id]);
        $this->get($expired)->assertStatus(403);
    }

    // ================================================================== état des uuid

    public function test_submission_status_reports_every_state(): void
    {
        Storage::fake('local');

        $plain = $this->payloadAt(0);
        $withMedia = $this->payloadWithMedia();
        $deleted = $this->payloadAt(2);
        $this->sync([$plain, $withMedia, $deleted]);

        $deletedSubmission = Submission::query()->where('uuid', $deleted['uuid'])->firstOrFail();
        DeletedSubmission::remember($deletedSubmission, $this->owner->id);
        $deletedSubmission->delete();

        $unknown = (string) Str::uuid();
        $uuids = implode(',', [$plain['uuid'], $withMedia['uuid'], $deleted['uuid'], $unknown]);

        $response = $this->actingAs($this->enumerator, 'sanctum')->getJson('/api/mobile/submissions/status?uuids='.$uuids);

        $response->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.state', 'received')
            ->assertJsonPath('data.1.state', 'media_pending')
            ->assertJsonPath('data.2.state', 'deleted')
            ->assertJsonPath('data.3.state', 'unknown')
            ->assertJsonStructure(['meta' => ['server_time']]);

        $this->assertSame('photo_recu_momo', $response->json('data.1.pending_media.0.question_key'));

        $this->uploadMedia($withMedia['uuid'], 'photo_recu_momo', 'contenu-photo')->assertStatus(201);

        $this->actingAs($this->enumerator, 'sanctum')
            ->getJson('/api/mobile/submissions/status?uuids='.$withMedia['uuid'])
            ->assertOk()
            ->assertJsonPath('data.0.state', 'complete');
    }

    public function test_submission_status_requires_uuids(): void
    {
        $this->actingAs($this->enumerator, 'sanctum')
            ->getJson('/api/mobile/submissions/status')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ================================================================== helpers

    /**
     * @param  list<array<string, mixed>>  $submissions
     */
    private function sync(array $submissions): TestResponse
    {
        return $this->actingAs($this->enumerator, 'sanctum')
            ->postJson('/api/mobile/submissions', ['submissions' => $submissions]);
    }

    private function uploadMedia(string $uuid, string $questionKey, string $content): TestResponse
    {
        return $this->actingAs($this->enumerator, 'sanctum')->post(
            "/api/mobile/submissions/{$uuid}/media/{$questionKey}",
            [
                'sha256' => hash('sha256', $content),
                'file' => UploadedFile::fake()->createWithContent('recu.jpg', $content),
            ],
            ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()],
        );
    }

    /**
     * Payload de la fixture, recalé sur le questionnaire créé par ce test.
     *
     * @return array<string, mixed>
     */
    private function payloadAt(int $index): array
    {
        return $this->rebase(self::fixture()['submissions'][$index]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadByUuid(string $uuid): array
    {
        foreach (self::fixture()['submissions'] as $row) {
            if ($row['uuid'] === $uuid) {
                return $this->rebase($row);
            }
        }

        $this->fail("Soumission {$uuid} absente de la fixture.");
    }

    /**
     * Première soumission de la fixture annonçant un média.
     *
     * @return array<string, mixed>
     */
    private function payloadWithMedia(): array
    {
        foreach (self::fixture()['submissions'] as $row) {
            if (($row['media'] ?? []) !== []) {
                return $this->rebase($row);
            }
        }

        $this->fail('Aucune soumission avec média dans la fixture.');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rebase(array $row): array
    {
        $row['survey_id'] = $this->survey->id;
        $row['form_version'] = 1;
        $row['device_id'] = 'android-enq1-a3f1';

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        if (self::$fixtureCache === []) {
            self::$fixtureCache = json_decode(
                (string) file_get_contents(base_path('../docs/fixtures/munago.submissions.json')),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }

        return self::$fixtureCache;
    }
}
