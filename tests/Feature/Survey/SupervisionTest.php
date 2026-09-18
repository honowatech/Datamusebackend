<?php

namespace Tests\Feature\Survey;

use App\Enums\JobKind;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Jobs\RecomputeSubmissionFlagsJob;
use App\Models\AiJob;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\User;
use App\Services\Survey\SubmissionQualityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * B-10 — `GET /surveys/{id}/supervision/flags` et `POST /surveys/{id}/supervision/recompute`.
 */
class SupervisionTest extends TestCase
{
    use RefreshDatabase;

    private MunagoFixtureLoader $fx;

    private User $supervisor;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fx = MunagoFixtureLoader::load();

        $this->supervisor = User::factory()->create(['role' => UserRole::Analyste]);
        ProjectMember::factory()->superviseur()->create([
            'project_id' => $this->fx->project->id,
            'user_id' => $this->supervisor->id,
        ]);
        ProjectMember::factory()->analyste()->create([
            'project_id' => $this->fx->project->id,
            'user_id' => $this->fx->owner->id,
        ]);

        $this->stranger = User::factory()->create(['role' => UserRole::Analyste]);
    }

    private function url(string $suffix): string
    {
        return '/api/surveys/'.$this->fx->survey->id.'/supervision/'.$suffix;
    }

    public function test_flags_list_only_suspicious_submissions_sorted_by_score(): void
    {
        $response = $this->actingAs($this->supervisor)->getJson($this->url('flags'))->assertOk();

        $this->assertSame(4, $response->json('meta.pagination.total'), 'too_fast (2) + duplicate (2)');

        $scores = array_column($response->json('data'), 'suspicion_score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);

        foreach ($response->json('data') as $row) {
            $this->assertNotEmpty($row['flags']);
            $this->assertNotEmpty($row['flag_details']);
            $this->assertArrayHasKey('answers_preview', $row);
            $this->assertArrayHasKey('duplicate_of', $row);
            foreach ($row['flag_details'] as $detail) {
                $this->assertContains($detail['flag'], Submission::FLAGS);
                $this->assertNotSame('', $detail['message']);
            }
        }
    }

    public function test_flags_can_be_filtered_by_flag_score_and_enumerator(): void
    {
        $tooFast = $this->actingAs($this->supervisor)->getJson($this->url('flags').'?flag=too_fast')->assertOk();
        $this->assertSame(2, $tooFast->json('meta.pagination.total'));
        foreach ($tooFast->json('data') as $row) {
            $this->assertContains('too_fast', $row['flags']);
        }

        $none = $this->actingAs($this->supervisor)->getJson($this->url('flags').'?flag=gps_missing')->assertOk();
        $this->assertSame(0, $none->json('meta.pagination.total'));

        $high = $this->actingAs($this->supervisor)->getJson($this->url('flags').'?min_score=100')->assertOk();
        $this->assertSame(0, $high->json('meta.pagination.total'));

        $enumeratorId = $this->fx->submission($this->fx->expectedStats['flags']['too_fast'][0])->enumerator_id;
        $byEnumerator = $this->actingAs($this->supervisor)->getJson($this->url('flags').'?enumerator_id='.$enumeratorId)->assertOk();
        foreach ($byEnumerator->json('data') as $row) {
            $this->assertSame($enumeratorId, $row['enumerator']['id']);
        }
    }

    public function test_duplicate_flag_points_to_its_twin(): void
    {
        $uuids = $this->fx->expectedStats['flags']['duplicate']['uuids'];

        $data = $this->actingAs($this->supervisor)->getJson($this->url('flags').'?flag=duplicate')->assertOk()->json('data');
        $this->assertCount(2, $data);

        $ids = array_column($data, 'id');
        foreach ($data as $row) {
            $this->assertContains($row['duplicate_of'], $ids);
            $this->assertNotSame($row['id'], $row['duplicate_of']);
            $this->assertArrayHasKey('num_whatsapp', $row['answers_preview'], 'les `duplicate_keys` alimentent le drawer');
        }

        $this->assertSame(
            array_map(fn (string $uuid) => $this->fx->submission($uuid)->id, $uuids),
            collect($data)->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_recompute_queues_a_unique_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->supervisor)->postJson($this->url('recompute'))->assertStatus(202);
        $jobId = $response->json('data.job_id');

        $this->assertNotNull($jobId);
        $this->assertSame('recompute_flags', $response->json('data.kind'));
        Queue::assertPushed(RecomputeSubmissionFlagsJob::class, 1);

        // Un second appel pendant le traitement renvoie le même `job_id`.
        $again = $this->actingAs($this->supervisor)->postJson($this->url('recompute'))->assertStatus(202);
        $this->assertSame($jobId, $again->json('data.job_id'));
        Queue::assertPushed(RecomputeSubmissionFlagsJob::class, 1);

        $this->assertSame(1, AiJob::query()->kind(JobKind::RecomputeFlags)->count());
    }

    public function test_recompute_recalculates_flags_and_scores(): void
    {
        // Les fiches de la fixture arrivent avec des drapeaux figés : le recalcul les rejoue.
        Submission::query()->where('survey_id', $this->fx->survey->id)
            ->update(['flags' => json_encode([]), 'suspicion_score' => 0]);

        $this->assertSame(0, Submission::query()->where('survey_id', $this->fx->survey->id)->where('suspicion_score', '>', 0)->count());

        $job = AiJob::query()->create([
            'user_id' => $this->supervisor->id,
            'survey_id' => $this->fx->survey->id,
            'kind' => JobKind::RecomputeFlags,
            'input' => ['survey_id' => $this->fx->survey->id],
        ]);

        (new RecomputeSubmissionFlagsJob($this->fx->survey->id, $job->uuid))
            ->handle(app(SubmissionQualityService::class));

        $job->refresh();
        $this->assertSame(JobStatus::Done, $job->status);
        $this->assertSame(100, (int) $job->progress);

        $flagged = Submission::query()->where('survey_id', $this->fx->survey->id)->where('suspicion_score', '>', 0)->count();
        $this->assertGreaterThan(0, $flagged);

        // Les deux fiches en doublon sur `num_whatsapp` sont retrouvées par le service qualité.
        foreach ($this->fx->expectedStats['flags']['duplicate']['uuids'] as $uuid) {
            $this->assertContains('duplicate', Submission::query()->where('uuid', $uuid)->value('flags'));
        }

        // Le plafond `par_reseau` est dépassé : au moins une fiche porte `quota_exceeded`.
        $this->assertGreaterThan(
            0,
            Submission::query()->where('survey_id', $this->fx->survey->id)->where('flags', 'like', '%quota_exceeded%')->count(),
        );
    }

    public function test_supervision_requires_the_supervisor_role(): void
    {
        $this->getJson($this->url('flags'))->assertStatus(401);
        $this->actingAs($this->stranger)->getJson($this->url('flags'))->assertStatus(403);
        $this->actingAs($this->stranger)->postJson($this->url('recompute'))->assertStatus(403);
        $this->actingAs($this->fx->enumerators[1])->getJson($this->url('flags'))->assertStatus(403);
    }
}
