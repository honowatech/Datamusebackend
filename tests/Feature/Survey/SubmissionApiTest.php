<?php

namespace Tests\Feature\Survey;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Jobs\MaterializeSurveyDatasourceJob;
use App\Models\DeletedSubmission;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\SurveyDatasource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * B-10 — `GET /surveys/{id}/submissions`, `GET|PATCH|DELETE /submissions/{id}`.
 */
class SubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    private MunagoFixtureLoader $fx;

    private User $analyst;

    private User $supervisor;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fx = MunagoFixtureLoader::load();

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
    }

    private function listUrl(string $query = ''): string
    {
        return '/api/surveys/'.$this->fx->survey->id.'/submissions'.($query === '' ? '' : '?'.$query);
    }

    // ------------------------------------------------------------------ liste

    public function test_list_is_paginated_and_sorted_by_received_at_desc(): void
    {
        $response = $this->actingAs($this->supervisor)->getJson($this->listUrl('per_page=10'))->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(60, $response->json('meta.pagination.total'));
        $this->assertSame(6, $response->json('meta.pagination.last_page'));

        $received = array_column($response->json('data'), 'received_at');
        $sorted = $received;
        rsort($sorted);
        $this->assertSame($sorted, $received);

        $first = $response->json('data.0');
        $this->assertArrayHasKey('flags', $first);
        $this->assertArrayHasKey('suspicion_score', $first);
        $this->assertArrayHasKey('follow_ups', $first);
        $this->assertArrayHasKey('media_pending', $first);
        $this->assertSame($this->fx->survey->id, $first['survey_id']);
        $this->assertSame(1, $first['version']);
    }

    public function test_list_filters_by_status_zone_enumerator_flag_and_dates(): void
    {
        $screened = $this->actingAs($this->supervisor)->getJson($this->listUrl('status=screened_out'))->assertOk();
        $this->assertSame(8, $screened->json('meta.pagination.total'));

        $zone = $this->actingAs($this->supervisor)->getJson($this->listUrl('zone=Makepe'))->assertOk();
        $this->assertSame(13, $zone->json('meta.pagination.total'));

        $enumerator = $this->actingAs($this->supervisor)
            ->getJson($this->listUrl('enumerator_id='.$this->fx->enumerators[2]->id))->assertOk();
        $this->assertSame(16, $enumerator->json('meta.pagination.total'));

        $flagged = $this->actingAs($this->supervisor)->getJson($this->listUrl('flag=too_fast'))->assertOk();
        $this->assertSame(2, $flagged->json('meta.pagination.total'));

        $channel = $this->actingAs($this->supervisor)->getJson($this->listUrl('channel=public'))->assertOk();
        $this->assertSame(0, $channel->json('meta.pagination.total'));

        $version = $this->actingAs($this->supervisor)->getJson($this->listUrl('version=1'))->assertOk();
        $this->assertSame(60, $version->json('meta.pagination.total'));

        $window = $this->actingAs($this->supervisor)->getJson($this->listUrl('from=2026-09-01&to=2026-09-01'))->assertOk();
        $this->assertGreaterThan(0, $window->json('meta.pagination.total'));
        $this->assertLessThan(60, $window->json('meta.pagination.total'));
    }

    public function test_search_matches_fiche_code_but_never_a_pii_answer(): void
    {
        $target = Submission::query()->where('survey_id', $this->fx->survey->id)
            ->whereNotNull('fiche_code')->orderBy('id')->first();

        $byCode = $this->actingAs($this->supervisor)->getJson($this->listUrl('q='.$target->fiche_code))->assertOk();
        $this->assertGreaterThanOrEqual(1, $byCode->json('meta.pagination.total'));
        $this->assertContains($target->id, array_column($byCode->json('data'), 'id'));

        // `num_whatsapp` porte `tags: ["pii"]` : sa valeur ne doit pas rendre la fiche trouvable.
        $phone = (string) ($this->fx->expectedStats['flags']['duplicate']['value'] ?? '677205519');
        $byPhone = $this->actingAs($this->supervisor)->getJson($this->listUrl('q='.$phone))->assertOk();
        $this->assertSame(0, $byPhone->json('meta.pagination.total'));
    }

    public function test_sort_is_restricted_to_the_whitelist(): void
    {
        $response = $this->actingAs($this->supervisor)->getJson($this->listUrl('sort=-suspicion_score&per_page=5'))->assertOk();
        $scores = array_column($response->json('data'), 'suspicion_score');
        $this->assertSame(40, $scores[0]);

        // Colonne inconnue → repli sur le tri par défaut, jamais d'erreur SQL.
        $this->actingAs($this->supervisor)->getJson($this->listUrl('sort=answers'))->assertOk();
    }

    public function test_an_enumerator_only_sees_their_own_submissions(): void
    {
        $response = $this->actingAs($this->fx->enumerators[3])->getJson($this->listUrl())->assertOk();

        $this->assertSame(14, $response->json('meta.pagination.total'));
        foreach ($response->json('data') as $row) {
            $this->assertSame($this->fx->enumerators[3]->id, $row['enumerator']['id']);
        }
    }

    public function test_a_stranger_cannot_list_submissions(): void
    {
        $this->getJson($this->listUrl())->assertStatus(401);
        $this->actingAs($this->stranger)->getJson($this->listUrl())->assertStatus(403);
    }

    // ------------------------------------------------------------------ détail

    public function test_detail_exposes_answers_media_follow_ups_and_flag_details(): void
    {
        $submission = Submission::query()->where('survey_id', $this->fx->survey->id)
            ->whereHas('followUps')->whereHas('media')->orderBy('id')->firstOrFail();

        $data = $this->actingAs($this->supervisor)
            ->getJson('/api/submissions/'.$submission->id)->assertOk()->json('data');

        $this->assertSame($submission->uuid, $data['uuid']);
        $this->assertNotEmpty($data['answers']);
        $this->assertNotEmpty($data['labels']);
        $this->assertArrayHasKey('quartier', $data['labels']);
        $this->assertNotEmpty($data['media']);
        $this->assertStringContainsString('/api/media/', $data['media'][0]['signed_url']);
        $this->assertStringContainsString('signature=', $data['media'][0]['signed_url']);
        $this->assertNotEmpty($data['follow_ups_entries']);
        $this->assertSame([], $data['codings']);
        $this->assertArrayHasKey('device', $data);
        $this->assertSame($this->fx->devices[MunagoFixtureLoader::enumeratorNumberOf($submission->device?->device_id)]->device_id, $data['device']['device_id']);
    }

    public function test_flag_details_explain_each_flag(): void
    {
        $uuid = $this->fx->expectedStats['flags']['too_fast'][0];
        $submission = $this->fx->submission($uuid);

        $data = $this->actingAs($this->supervisor)
            ->getJson('/api/submissions/'.$submission->id)->assertOk()->json('data');

        $this->assertNotEmpty($data['flag_details']);
        $this->assertSame('too_fast', $data['flag_details'][0]['flag']);
        $this->assertStringContainsString('minimum', $data['flag_details'][0]['message']);
        $this->assertSame(30, $data['flag_details'][0]['score']);
    }

    public function test_an_enumerator_reads_their_own_submission_but_not_another_one(): void
    {
        $own = Submission::query()->where('enumerator_id', $this->fx->enumerators[1]->id)->firstOrFail();
        $other = Submission::query()->where('enumerator_id', $this->fx->enumerators[2]->id)->firstOrFail();

        $this->actingAs($this->fx->enumerators[1])->getJson('/api/submissions/'.$own->id)->assertOk();
        $this->actingAs($this->fx->enumerators[1])->getJson('/api/submissions/'.$other->id)->assertStatus(403);
    }

    // ------------------------------------------------------------------ revue

    public function test_patch_reviews_a_submission_and_marks_the_datasource_dirty(): void
    {
        Queue::fake();
        SurveyDatasource::query()->create(['survey_id' => $this->fx->survey->id, 'dirty' => false]);

        $submission = Submission::query()->where('survey_id', $this->fx->survey->id)->orderBy('id')->firstOrFail();

        $data = $this->actingAs($this->supervisor)
            ->patchJson('/api/submissions/'.$submission->id, [
                'status' => 'validated',
                'quality_notes' => 'Vérifiée avec l\'enquêteur.',
            ])
            ->assertOk()->json('data');

        $this->assertSame('validated', $data['status']);
        $this->assertSame($this->supervisor->id, $data['reviewed_by']['id']);
        $this->assertNotNull($data['reviewed_at']);

        $submission->refresh();
        $this->assertSame(SubmissionStatus::Validated, $submission->status);
        $this->assertSame("Vérifiée avec l'enquêteur.", $submission->quality_notes);

        $this->assertTrue(SurveyDatasource::query()->where('survey_id', $this->fx->survey->id)->value('dirty'));
        Queue::assertPushed(MaterializeSurveyDatasourceJob::class);
    }

    public function test_patch_rejects_an_unknown_status_and_the_enumerator_role(): void
    {
        $submission = Submission::query()->where('survey_id', $this->fx->survey->id)->orderBy('id')->firstOrFail();

        $this->actingAs($this->supervisor)
            ->patchJson('/api/submissions/'.$submission->id, ['status' => 'screened_out'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->actingAs($this->fx->enumerators[1])
            ->patchJson('/api/submissions/'.$submission->id, ['status' => 'validated'])
            ->assertStatus(403);
    }

    public function test_patch_is_idempotent(): void
    {
        $submission = Submission::query()->where('survey_id', $this->fx->survey->id)->orderBy('id')->firstOrFail();

        $first = $this->actingAs($this->supervisor)->patchJson('/api/submissions/'.$submission->id, ['status' => 'rejected'])->assertOk();
        $second = $this->actingAs($this->supervisor)->patchJson('/api/submissions/'.$submission->id, ['status' => 'rejected'])->assertOk();

        $this->assertSame($first->json('data.status'), $second->json('data.status'));
        $this->assertSame(1, Submission::query()->where('id', $submission->id)->count());
    }

    // ------------------------------------------------------------------ suppression

    public function test_delete_burns_the_uuid_and_decrements_the_counter(): void
    {
        // Sans `Queue::fake()`, la file de test est `sync` : le rebuild retirerait aussitôt `dirty`.
        Queue::fake();
        SurveyDatasource::query()->create(['survey_id' => $this->fx->survey->id, 'dirty' => false]);

        $submission = Submission::query()->where('survey_id', $this->fx->survey->id)->orderBy('id')->firstOrFail();
        $uuid = $submission->uuid;
        $before = (int) $this->fx->survey->fresh()->submissions_count;

        $this->actingAs($this->analyst)->deleteJson('/api/submissions/'.$submission->id)->assertNoContent();

        $this->assertDatabaseMissing('submissions', ['uuid' => $uuid]);
        $this->assertTrue(DeletedSubmission::has($uuid));
        $this->assertSame($this->analyst->id, (int) DeletedSubmission::query()->where('uuid', $uuid)->value('deleted_by'));
        $this->assertSame($before - 1, (int) $this->fx->survey->fresh()->submissions_count);
        $this->assertDatabaseMissing('submission_media', ['submission_id' => $submission->id]);
        $this->assertTrue(SurveyDatasource::query()->where('survey_id', $this->fx->survey->id)->value('dirty'));
    }

    public function test_delete_requires_the_analyst_role(): void
    {
        $submission = Submission::query()->where('survey_id', $this->fx->survey->id)->orderBy('id')->firstOrFail();

        $this->actingAs($this->supervisor)->deleteJson('/api/submissions/'.$submission->id)->assertStatus(403);
        $this->actingAs($this->fx->enumerators[1])->deleteJson('/api/submissions/'.$submission->id)->assertStatus(403);
        $this->assertDatabaseHas('submissions', ['id' => $submission->id]);
    }

    public function test_a_deleted_uuid_resynced_by_the_mobile_answers_duplicate(): void
    {
        $submission = Submission::query()->where('survey_id', $this->fx->survey->id)
            ->where('enumerator_id', $this->fx->enumerators[1]->id)->orderBy('id')->firstOrFail();
        $uuid = $submission->uuid;

        $this->actingAs($this->analyst)->deleteJson('/api/submissions/'.$submission->id)->assertNoContent();

        $response = $this->actingAs($this->fx->enumerators[1])->postJson('/api/mobile/submissions', [
            'submissions' => [[
                'uuid' => $uuid,
                'survey_id' => $this->fx->survey->id,
                'form_version' => 1,
                'language' => 'fr',
                'started_at' => '2026-09-01T08:00:00+01:00',
                'ended_at' => '2026-09-01T08:20:00+01:00',
                'client_updated_at' => '2026-09-01T08:20:00+01:00',
                'status' => 'completed',
                'answers' => [],
            ]],
        ])->assertOk();

        $this->assertSame('duplicate', $response->json('data.results.0.status'));
        $this->assertDatabaseMissing('submissions', ['uuid' => $uuid]);
    }
}
