<?php

namespace Tests\Feature\Survey;

use App\Enums\FollowUpStatus;
use App\Enums\SubmissionChannel;
use App\Enums\SubmissionStatus;
use App\Enums\SurveyStatus;
use App\Enums\UserRole;
use App\Events\SubmissionReceived;
use App\Models\EnumeratorAssignment;
use App\Models\FollowUpEntry;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * B-08 : suivis longitudinaux MunaGo (J+4 / J+7 / J+14).
 *
 * Couvre la règle de création du README DFS § 14 (une entrée par étape, `skipped` immédiat sauf
 * référence à une autre étape), `GET /mobile/follow-ups/due`, `POST /mobile/follow-ups` (validation,
 * idempotence, conflits, réévaluation des étapes suivantes) et la commande `follow-ups:mark-missed`.
 */
class FollowUpTest extends TestCase
{
    use RefreshDatabase;

    /** Soumission de la fixture avec acompte (`q13_decision = oui_paiement`). */
    private const DEPOSIT_UUID = '5237bc92-dd65-482f-ba2b-a385a448239a';

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
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $this->enumerator->id, 'zone' => 'Bonamoussadi']);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $this->stranger->id, 'zone' => 'Akwa']);

        $this->survey = Survey::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'title' => 'MunaGo — suivis',
            'slug' => 'munago-suivis',
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================== création (README § 14)

    public function test_entries_are_created_for_every_stage_with_due_and_window_dates(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);

        $entries = FollowUpEntry::query()->where('submission_id', $submission->id)->orderBy('due_at')->get();
        $this->assertSame(['j4', 'j7', 'j14'], $entries->pluck('stage_key')->all());

        $ended = $submission->ended_at;
        $this->assertTrue($entries[0]->due_at->equalTo($ended->copy()->addDays(4)), 'due_at = ended_at + due_offset_days');
        $this->assertTrue($entries[0]->window_ends_at->equalTo($entries[0]->due_at->copy()->addDays(2)));
        $this->assertTrue($entries[1]->due_at->equalTo($ended->copy()->addDays(7)));
        $this->assertTrue($entries[2]->due_at->equalTo($ended->copy()->addDays(14)));
        $this->assertTrue($entries[2]->window_ends_at->equalTo($entries[2]->due_at->copy()->addDays(3)));

        $this->assertSame($this->enumerator->id, $entries[0]->enumerator_id);
        $this->assertSame($this->survey->id, $entries[0]->survey_id);

        // j4/j7 pertinents (décision avec acompte) ; j14 indéterminé (référence `j7_retire`).
        $this->assertSame(FollowUpStatus::Pending, $entries[0]->status);
        $this->assertSame(FollowUpStatus::Pending, $entries[1]->status);
        $this->assertSame(FollowUpStatus::Pending, $entries[2]->status);
    }

    public function test_a_stage_whose_relevance_is_false_on_base_answers_is_skipped_immediately(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID, ['q13_decision' => 'non_clair']);

        $entries = FollowUpEntry::query()->where('submission_id', $submission->id)->get()->keyBy('stage_key');

        // j4/j7 : `relevant` ne lit que des réponses de base → décidable tout de suite.
        $this->assertSame(FollowUpStatus::Skipped, $entries['j4']->status);
        $this->assertSame(FollowUpStatus::Skipped, $entries['j7']->status);
        // j14 : l'expression référence `j7_retire` (autre étape, non complétée) → pertinence indéterminée.
        $this->assertSame(FollowUpStatus::Pending, $entries['j14']->status);
    }

    public function test_no_entry_is_created_for_a_screened_out_submission(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID, [], ['status' => SubmissionStatus::ScreenedOut, 'end_reason' => 'b_stop_age']);

        $this->assertSame(0, FollowUpEntry::query()->where('submission_id', $submission->id)->count());
    }

    public function test_entries_are_created_by_the_mobile_sync_endpoint(): void
    {
        $payload = $this->payload(self::DEPOSIT_UUID);

        $this->actingAs($this->enumerator, 'sanctum')
            ->postJson('/api/mobile/submissions', ['submissions' => [$payload]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'accepted');

        $this->assertSame(3, FollowUpEntry::query()->count());
        $this->assertSame(
            $this->enumerator->id,
            FollowUpEntry::query()->firstOrFail()->enumerator_id,
        );
    }

    public function test_a_second_reception_does_not_duplicate_or_reset_entries(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);
        $entry = FollowUpEntry::query()->where('submission_id', $submission->id)->where('stage_key', 'j4')->firstOrFail();
        $entry->forceFill(['status' => FollowUpStatus::Done, 'answers' => ['j4_rappel_envoye' => 'oui'], 'completed_at' => Carbon::now()])->save();

        event(new SubmissionReceived($submission->refresh(), false));

        $this->assertSame(3, FollowUpEntry::query()->where('submission_id', $submission->id)->count());
        $this->assertSame(FollowUpStatus::Done, $entry->refresh()->status, 'une étape complétée n\'est jamais réinitialisée');
    }

    // ================================================================== GET /mobile/follow-ups/due

    public function test_due_lists_pending_entries_of_the_enumerator_with_context(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);
        Carbon::setTestNow($submission->ended_at->copy()->addDays(5));

        $response = $this->actingAs($this->enumerator, 'sanctum')->getJson('/api/mobile/follow-ups/due');

        $response->assertOk()->assertJsonStructure(['meta' => ['server_time']]);
        $items = $response->json('data');
        $this->assertSame(
            ['j4', 'j7'],
            collect($items)->pluck('stage_key')->all(),
            'fenêtre par défaut : aujourd\'hui − 30 j → + 7 j (j4 en retard, j7 à venir ; j14 est hors fenêtre)',
        );

        $j4 = collect($items)->firstWhere('stage_key', 'j4');
        $this->assertSame($submission->uuid, $j4['parent_submission_uuid']);
        $this->assertSame($submission->id, $j4['parent_submission_id']);
        $this->assertSame($this->survey->id, $j4['survey_id']);
        $this->assertSame(1, $j4['version']);
        $this->assertSame($submission->fiche_code, $j4['fiche_code']);
        $this->assertSame('pending', $j4['status']);

        // respondent_label = settings.followup_contact_keys jointes par « · ».
        $answers = $submission->answers;
        $this->assertSame(
            $answers['num_whatsapp'].' · '.$answers['nom_compte_momo'],
            $j4['respondent_label'],
        );

        // parent_answers_subset : clés lues par les expressions de l'étape + contacts, jamais de clé d'étape.
        $this->assertArrayHasKey('q13_decision', $j4['parent_answers_subset']);
        $this->assertArrayHasKey('num_whatsapp', $j4['parent_answers_subset']);
        $this->assertSame([], $j4['completed_stages']);

        // j14 (hors fenêtre par défaut) : son sous-ensemble n'expose jamais une clé d'étape.
        $far = $this->actingAs($this->enumerator, 'sanctum')
            ->getJson('/api/mobile/follow-ups/due?to='.$submission->ended_at->copy()->addDays(20)->toDateString())
            ->json('data');
        $j14 = collect($far)->firstWhere('stage_key', 'j14');
        $this->assertArrayHasKey('q13_decision', $j14['parent_answers_subset']);
        $this->assertArrayNotHasKey('j7_retire', $j14['parent_answers_subset']);
    }

    public function test_due_is_filtered_by_dates_survey_and_enumerator(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);
        Carbon::setTestNow($submission->ended_at->copy()->addDays(5));

        $day = $submission->ended_at->copy()->addDays(7)->toDateString();
        $filtered = $this->actingAs($this->enumerator, 'sanctum')
            ->getJson('/api/mobile/follow-ups/due?from='.$day.'&to='.$day);
        $filtered->assertOk();
        $this->assertSame(['j7'], collect($filtered->json('data'))->pluck('stage_key')->all());

        $this->actingAs($this->enumerator, 'sanctum')
            ->getJson('/api/mobile/follow-ups/due?survey_id='.($this->survey->id + 999))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Un autre enquêteur ne voit pas les suivis de ses collègues.
        $this->actingAs($this->stranger, 'sanctum')
            ->getJson('/api/mobile/follow-ups/due')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ================================================================== POST /mobile/follow-ups

    public function test_a_follow_up_is_stored_validated_and_idempotent(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);
        Carbon::setTestNow($submission->ended_at->copy()->addDays(4));

        $entry = ['j4_rappel_envoye' => 'oui', 'j4_date_envoi' => Carbon::now()->toDateString()];
        $first = $this->syncFollowUps([$this->followUpPayload($submission, 'j4', $entry)]);

        $first->assertOk()
            ->assertJsonPath('data.results.0.status', 'accepted')
            ->assertJsonPath('data.results.0.stage_key', 'j4')
            ->assertJsonPath('data.results.0.entry_status', 'done')
            ->assertJsonPath('data.results.0.parent_submission_uuid', $submission->uuid);

        $stored = FollowUpEntry::query()->where('submission_id', $submission->id)->where('stage_key', 'j4')->firstOrFail();
        $this->assertSame(FollowUpStatus::Done, $stored->status);
        $this->assertSame($entry, $stored->answers);
        $this->assertNotNull($stored->completed_at);

        // Renvoi du même lot : le serveur gagne (`enumerator_can_edit_after_submit` = false).
        $this->syncFollowUps([$this->followUpPayload($submission, 'j4', ['j4_rappel_envoye' => 'non'])])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'duplicate');

        $this->assertSame($entry, $stored->refresh()->answers);
        $this->assertSame(3, FollowUpEntry::query()->count(), 'aucune entrée supplémentaire');
        $this->assertSame(0, Submission::query()->count() - 1, 'un suivi ne crée pas de nouvelle soumission');
    }

    public function test_invalid_follow_up_answers_are_rejected_per_item(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);
        Carbon::setTestNow($submission->ended_at->copy()->addDays(7));

        $response = $this->syncFollowUps([
            $this->followUpPayload($submission, 'j7', []),                                   // j7_retire manquant
            $this->followUpPayload($submission, 'j7', ['j7_retire' => 'peut_etre']),         // code inconnu
        ]);

        $response->assertOk()
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.errors.0.code', 'required')
            ->assertJsonPath('data.results.0.errors.0.key', 'j7_retire')
            ->assertJsonPath('data.results.1.status', 'rejected')
            ->assertJsonPath('data.results.1.errors.0.code', 'choice');

        $this->assertArrayHasKey('j7_retire', $response->json('data.results.0.errors_by_key'));
        $this->assertSame(
            FollowUpStatus::Pending,
            FollowUpEntry::query()->where('submission_id', $submission->id)->where('stage_key', 'j7')->firstOrFail()->status,
        );
    }

    public function test_completing_j7_reevaluates_the_relevance_of_j14(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);
        Carbon::setTestNow($submission->ended_at->copy()->addDays(7));

        $this->syncFollowUps([$this->followUpPayload($submission, 'j7', ['j7_retire' => 'non', 'j7_motif' => 'silence', 'j7_rembourse' => 'oui'])])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'accepted');

        $entries = FollowUpEntry::query()->where('submission_id', $submission->id)->get()->keyBy('stage_key');
        $this->assertSame(FollowUpStatus::Done, $entries['j7']->status);
        $this->assertSame(FollowUpStatus::Skipped, $entries['j14']->status, 'J+14 seulement si retiré à J+7');

        // Une étape `skipped` ne peut plus être renseignée.
        $this->syncFollowUps([$this->followUpPayload($submission, 'j14', ['j14_active' => 'oui'])])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'conflict')
            ->assertJsonPath('data.results.0.conflict_reason', 'stage_skipped');
    }

    public function test_j14_stays_open_when_the_device_was_withdrawn_at_j7(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);
        Carbon::setTestNow($submission->ended_at->copy()->addDays(7));

        $this->syncFollowUps([$this->followUpPayload($submission, 'j7', ['j7_retire' => 'oui', 'j7_jour_retrait' => Carbon::now()->toDateString()])])
            ->assertJsonPath('data.results.0.status', 'accepted');

        $entries = FollowUpEntry::query()->where('submission_id', $submission->id)->get()->keyBy('stage_key');
        $this->assertSame(FollowUpStatus::Pending, $entries['j14']->status);

        Carbon::setTestNow($submission->ended_at->copy()->addDays(14));
        $this->syncFollowUps([$this->followUpPayload($submission, 'j14', ['j14_active' => 'oui', 'j14_encore_utilise' => 'oui'])])
            ->assertJsonPath('data.results.0.status', 'accepted');

        $this->assertSame(FollowUpStatus::Done, $entries['j14']->refresh()->status);

        // Le suivi complété disparaît de la liste des suivis dus, et `completed_stages` se remplit.
        $due = $this->actingAs($this->enumerator, 'sanctum')->getJson('/api/mobile/follow-ups/due')->json('data');
        $this->assertSame(['j4'], collect($due)->pluck('stage_key')->all());
        $this->assertEqualsCanonicalizing(['j7', 'j14'], $due[0]['completed_stages']);
    }

    public function test_conflicts_are_reported_per_entry(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);
        Carbon::setTestNow($submission->ended_at->copy()->addDays(4));

        // Parent inconnu.
        $unknown = $this->followUpPayload($submission, 'j4', ['j4_rappel_envoye' => 'oui']);
        $unknown['parent_submission_uuid'] = (string) Str::uuid();
        $this->syncFollowUps([$unknown])
            ->assertJsonPath('data.results.0.status', 'conflict')
            ->assertJsonPath('data.results.0.conflict_reason', 'parent_unknown');

        // Enquêteur non affecté au questionnaire.
        $this->actingAs($this->stranger, 'sanctum')
            ->postJson('/api/mobile/follow-ups', ['entries' => [$this->followUpPayload($submission, 'j4', ['j4_rappel_envoye' => 'oui'])]])
            ->assertOk()
            ->assertJsonPath('data.results.0.conflict_reason', 'not_assigned');

        // Questionnaire fermé.
        $this->survey->forceFill(['status' => SurveyStatus::Closed])->save();
        $this->syncFollowUps([$this->followUpPayload($submission, 'j4', ['j4_rappel_envoye' => 'oui'])])
            ->assertJsonPath('data.results.0.conflict_reason', 'survey_closed');
    }

    public function test_an_unknown_stage_is_rejected_and_a_batch_over_fifty_is_refused(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);

        $this->syncFollowUps([$this->followUpPayload($submission, 'j21', [])])
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.errors.0.code', 'stage_unknown');

        $entries = [];
        for ($i = 0; $i < 51; $i++) {
            $entries[] = $this->followUpPayload($submission, 'j4', ['j4_rappel_envoye' => 'oui']);
        }

        $this->actingAs($this->enumerator, 'sanctum')
            ->postJson('/api/mobile/follow-ups', ['entries' => $entries])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['entries']]);
    }

    // ================================================================== follow-ups:mark-missed

    public function test_mark_missed_skips_irrelevant_stages_then_marks_overdue_entries(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);
        Carbon::setTestNow($submission->ended_at->copy()->addDays(7));

        // J+7 : pas de retrait → J+14 n'est plus pertinent (réévaluation à l'échéance).
        $this->syncFollowUps([$this->followUpPayload($submission, 'j7', ['j7_retire' => 'non', 'j7_motif' => 'silence', 'j7_rembourse' => 'oui'])]);

        // Fenêtre de J+4 dépassée (due + 2 j).
        $this->artisan('follow-ups:mark-missed')->assertExitCode(0);

        $entries = FollowUpEntry::query()->where('submission_id', $submission->id)->get()->keyBy('stage_key');
        $this->assertSame(FollowUpStatus::Missed, $entries['j4']->status);
        $this->assertSame(FollowUpStatus::Done, $entries['j7']->status);
        $this->assertSame(FollowUpStatus::Skipped, $entries['j14']->status);
    }

    public function test_mark_missed_leaves_entries_inside_their_window_untouched(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);
        Carbon::setTestNow($submission->ended_at->copy()->addDays(5));

        $this->artisan('follow-ups:mark-missed')->assertExitCode(0);

        $entries = FollowUpEntry::query()->where('submission_id', $submission->id)->get()->keyBy('stage_key');
        $this->assertSame(FollowUpStatus::Pending, $entries['j4']->status, 'fenêtre de 2 jours encore ouverte');
        $this->assertSame(FollowUpStatus::Pending, $entries['j7']->status);
    }

    public function test_a_missed_entry_can_still_be_caught_up_within_seven_days(): void
    {
        $submission = $this->receive(self::DEPOSIT_UUID);
        Carbon::setTestNow($submission->ended_at->copy()->addDays(7));
        $this->artisan('follow-ups:mark-missed');

        $entry = FollowUpEntry::query()->where('submission_id', $submission->id)->where('stage_key', 'j4')->firstOrFail();
        $this->assertSame(FollowUpStatus::Missed, $entry->status);

        $this->syncFollowUps([$this->followUpPayload($submission, 'j4', ['j4_rappel_envoye' => 'oui'])])
            ->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertSame(FollowUpStatus::Done, $entry->refresh()->status);

        // Au-delà de la tolérance de 7 jours, la décision redevient manuelle.
        $entry->forceFill(['status' => FollowUpStatus::Missed])->save();
        Carbon::setTestNow($submission->ended_at->copy()->addDays(30));
        $this->syncFollowUps([$this->followUpPayload($submission, 'j4', ['j4_rappel_envoye' => 'oui'])])
            ->assertJsonPath('data.results.0.conflict_reason', 'stage_missed');
    }

    // ================================================================== helpers

    /**
     * Crée une soumission depuis la fixture et émet `SubmissionReceived` (comme la synchronisation).
     *
     * @param  array<string, mixed>  $answerOverrides
     * @param  array<string, mixed>  $overrides
     */
    private function receive(string $uuid, array $answerOverrides = [], array $overrides = []): Submission
    {
        $row = $this->fixtureRow($uuid);
        $ended = Carbon::parse($row['ended_at']);

        $submission = Submission::query()->create(array_merge([
            'uuid' => $row['uuid'],
            'survey_id' => $this->survey->id,
            'survey_version_id' => $this->version->id,
            'project_id' => $this->project->id,
            'enumerator_id' => $this->enumerator->id,
            'channel' => SubmissionChannel::Mobile,
            'status' => SubmissionStatus::Submitted,
            'fiche_code' => $row['fiche_code'] ?? null,
            'zone' => $row['zone'] ?? null,
            'language' => $row['language'] ?? 'fr',
            'started_at' => Carbon::parse($row['started_at']),
            'ended_at' => $ended,
            'answers' => array_merge($row['answers'], $answerOverrides),
            'client_updated_at' => $ended,
            'received_at' => $ended,
        ], $overrides));

        event(new SubmissionReceived($submission, true));

        return $submission->refresh();
    }

    /**
     * Payload `FollowUpIn` (schéma `$defs/Submission` + `followup_stage`, `parent_submission_uuid`).
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function followUpPayload(Submission $parent, string $stageKey, array $answers): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'survey_id' => $this->survey->id,
            'form_version' => $this->version->version,
            'followup_stage' => $stageKey,
            'parent_submission_uuid' => $parent->uuid,
            'language' => 'fr',
            'device_id' => 'android-enq1-a3f1',
            'started_at' => Carbon::now()->subMinutes(3)->toIso8601String(),
            'ended_at' => Carbon::now()->toIso8601String(),
            'client_updated_at' => Carbon::now()->toIso8601String(),
            'status' => 'completed',
            'answers' => $answers,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function syncFollowUps(array $entries): TestResponse
    {
        return $this->actingAs($this->enumerator, 'sanctum')
            ->postJson('/api/mobile/follow-ups', ['entries' => $entries]);
    }

    /**
     * Payload de synchronisation classique, recalé sur le questionnaire du test.
     *
     * @return array<string, mixed>
     */
    private function payload(string $uuid): array
    {
        $row = $this->fixtureRow($uuid);
        $row['survey_id'] = $this->survey->id;
        $row['form_version'] = 1;
        $row['device_id'] = 'android-enq1-a3f1';

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureRow(string $uuid): array
    {
        foreach ($this->fixture()['submissions'] as $row) {
            if ($row['uuid'] === $uuid) {
                return $row;
            }
        }

        $this->fail("Soumission {$uuid} absente de la fixture.");
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
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
