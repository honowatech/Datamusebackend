<?php

namespace Tests\Feature\Survey;

use App\Enums\SubmissionChannel;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ProjectMember;
use App\Models\PublicLink;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * F-B4 — `GET /submissions/{id}/sheet` et filtres `channel` / `public_link_id` de la liste.
 */
class SubmissionSheetApiTest extends TestCase
{
    use RefreshDatabase;

    private MunagoFixtureLoader $fx;

    private User $analyst;

    private User $supervisor;

    private User $stranger;

    /** Fiche avec acompte, photo et trois suivis complétés. */
    private const WITH_DEPOSIT = '5237bc92-dd65-482f-ba2b-a385a448239a';

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

    private function url(Submission $submission, string $query = ''): string
    {
        return '/api/submissions/'.$submission->id.'/sheet'.($query === '' ? '' : '?'.$query);
    }

    /**
     * @param  array<string, mixed>  $sheet
     * @return array<string, array<string, mixed>>
     */
    private function itemsByKey(array $sheet): array
    {
        $out = [];
        foreach ($sheet['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $out[$item['key']] = $item;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ droits

    public function test_an_analyst_and_a_supervisor_read_a_sheet(): void
    {
        $submission = $this->fx->submission(self::WITH_DEPOSIT);

        foreach ([$this->analyst, $this->supervisor] as $user) {
            $sheet = $this->actingAs($user)->getJson($this->url($submission))->assertOk()->json('data');

            $this->assertSame($submission->id, $sheet['submission']['id']);
            $this->assertSame($submission->fiche_code, $sheet['submission']['fiche_code']);
            $this->assertSame(9, count($sheet['sections']));
            $this->assertArrayHasKey('origin', $sheet);
            $this->assertArrayHasKey('quality', $sheet);
            $this->assertArrayHasKey('navigation', $sheet);
        }
    }

    public function test_a_stranger_and_an_anonymous_visitor_are_refused(): void
    {
        $submission = $this->fx->submission(self::WITH_DEPOSIT);

        $this->getJson($this->url($submission))->assertStatus(401);
        $this->actingAs($this->stranger)->getJson($this->url($submission))->assertStatus(403);
    }

    public function test_an_enumerator_reads_only_their_own_sheets(): void
    {
        $own = Submission::query()->where('enumerator_id', $this->fx->enumerators[1]->id)->orderBy('id')->firstOrFail();
        $other = Submission::query()->where('enumerator_id', $this->fx->enumerators[2]->id)->orderBy('id')->firstOrFail();

        $this->actingAs($this->fx->enumerators[1])->getJson($this->url($own))->assertOk();
        $this->actingAs($this->fx->enumerators[1])->getJson($this->url($other))->assertStatus(403);
    }

    public function test_pii_is_masked_for_a_supervisor_and_visible_for_an_analyst(): void
    {
        $submission = $this->fx->submission(self::WITH_DEPOSIT);

        $masked = $this->itemsByKey($this->actingAs($this->supervisor)->getJson($this->url($submission))->assertOk()->json('data'));
        $this->assertTrue($masked['num_whatsapp']['masked']);
        $this->assertNull($masked['num_whatsapp']['value']);
        $this->assertNull($masked['num_whatsapp']['display']);

        $visible = $this->itemsByKey($this->actingAs($this->analyst)->getJson($this->url($submission))->assertOk()->json('data'));
        $this->assertFalse($visible['num_whatsapp']['masked']);
        $this->assertSame('699412873', $visible['num_whatsapp']['value']);
    }

    // ------------------------------------------------------------------ langue

    public function test_lang_switches_the_labels(): void
    {
        $submission = $this->fx->submission(self::WITH_DEPOSIT);

        $fr = $this->itemsByKey($this->actingAs($this->analyst)->getJson($this->url($submission))->assertOk()->json('data'));
        $en = $this->itemsByKey($this->actingAs($this->analyst)->getJson($this->url($submission, 'lang=en'))->assertOk()->json('data'));

        $this->assertSame('Acceptez-vous de participer ?', $fr['consentement']['label']);
        $this->assertSame('Do you agree to take part?', $en['consentement']['label']);
        $this->assertSame('Yes', $en['consentement']['display']);
    }

    // ------------------------------------------------------------------ navigation

    public function test_navigation_follows_the_reception_order(): void
    {
        $ordered = Submission::query()->where('survey_id', $this->fx->survey->id)
            ->orderBy('received_at')->orderBy('id')->get(['id'])->pluck('id')->all();
        $middle = Submission::query()->findOrFail($ordered[5]);

        $navigation = $this->actingAs($this->supervisor)->getJson($this->url($middle))->assertOk()->json('data.navigation');

        $this->assertSame($ordered[4], $navigation['previous_id']);
        $this->assertSame($ordered[6], $navigation['next_id']);

        $first = Submission::query()->findOrFail($ordered[0]);
        $this->assertNull($this->actingAs($this->supervisor)->getJson($this->url($first))->json('data.navigation.previous_id'));
    }

    public function test_navigation_of_an_enumerator_stays_inside_their_own_sheets(): void
    {
        $enumerator = $this->fx->enumerators[2];
        $mine = Submission::query()->where('enumerator_id', $enumerator->id)
            ->orderBy('received_at')->orderBy('id')->get(['id'])->pluck('id')->all();
        $this->assertGreaterThan(2, count($mine));

        $navigation = $this->actingAs($enumerator)
            ->getJson($this->url(Submission::query()->findOrFail($mine[1])))->assertOk()->json('data.navigation');

        $this->assertSame($mine[0], $navigation['previous_id']);
        $this->assertSame($mine[2], $navigation['next_id']);

        // Un superviseur, lui, traverse toutes les fiches : les voisins diffèrent.
        $wide = $this->actingAs($this->supervisor)
            ->getJson($this->url(Submission::query()->findOrFail($mine[1])))->assertOk()->json('data.navigation');
        $this->assertNotSame($navigation, $wide);
    }

    // ------------------------------------------------------------------ liste : canal et lien public

    public function test_the_list_filters_by_channel_and_public_link(): void
    {
        $link = PublicLink::factory()->create([
            'survey_id' => $this->fx->survey->id,
            'created_by' => $this->analyst->id,
            'label' => 'Panel parents',
        ]);
        $source = $this->fx->submission(self::WITH_DEPOSIT);

        Submission::query()->create([
            'uuid' => (string) Str::uuid(),
            'survey_id' => $this->fx->survey->id,
            'survey_version_id' => $this->fx->version->id,
            'project_id' => $this->fx->project->id,
            'public_link_id' => $link->id,
            'channel' => SubmissionChannel::Public,
            'status' => SubmissionStatus::Submitted,
            'fiche_code' => 'DLA-BMP-98',
            'language' => 'fr',
            'started_at' => $source->started_at,
            'ended_at' => $source->ended_at,
            'answers' => $source->answers,
            'received_at' => now(),
        ]);

        $base = '/api/surveys/'.$this->fx->survey->id.'/submissions';

        $this->actingAs($this->supervisor)->getJson($base.'?channel=public')
            ->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->actingAs($this->supervisor)->getJson($base.'?channel=mobile')
            ->assertOk()->assertJsonPath('meta.pagination.total', 60);

        $rows = $this->actingAs($this->supervisor)->getJson($base.'?public_link_id='.$link->id)->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('DLA-BMP-98', $rows[0]['fiche_code']);
        $this->assertSame($link->id, $rows[0]['public_link_id']);
    }
}
