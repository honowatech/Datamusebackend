<?php

namespace Tests\Feature\Survey;

use App\Enums\UserRole;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * B-10 — `GET /surveys/{id}/stats/*`.
 *
 * Les chiffres attendus sont ceux de `docs/fixtures/munago.submissions.json` (`expected_stats`) :
 * 60 soumissions, 52 valides, 8 hors cible, quotas § 12 et KPI § 13 du README DFS.
 */
class StatsTest extends TestCase
{
    use RefreshDatabase;

    private MunagoFixtureLoader $fx;

    private User $supervisor;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        // Les compteurs « 7 derniers jours » (enquêteurs actifs, sparkline) sont relatifs à l'instant
        // courant : on se place au lendemain de la dernière soumission de la fixture.
        Carbon::setTestNow(Carbon::parse('2026-09-16T09:00:00+01:00'));

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function url(string $suffix): string
    {
        return '/api/surveys/'.$this->fx->survey->id.'/stats/'.$suffix;
    }

    public function test_overview_totals_match_the_fixture(): void
    {
        $expected = $this->fx->expectedStats;

        $response = $this->actingAs($this->supervisor)->getJson($this->url('overview'))->assertOk();
        $data = $response->json('data');

        $this->assertSame($expected['n_submissions'], $data['totals']['all']);
        $this->assertSame($expected['n_completed_valid'], $data['totals']['valid']);
        $this->assertSame($expected['n_screened_out'], $data['totals']['screened_out']);
        $this->assertSame(0, $data['totals']['rejected']);
        $this->assertSame(4, $data['totals']['flagged'], 'too_fast (2) + duplicate (2) de la fixture');
        $this->assertSame(3, $data['totals']['enumerators_active']);
        $this->assertSame(3, $data['totals']['follow_ups_pending']);

        $this->assertSame(52, $data['by_status']['submitted']);
        $this->assertSame(8, $data['by_status']['screened_out']);
        $this->assertSame(60, $data['by_channel']['mobile']);
        $this->assertSame(60, $data['by_version']['1']);

        $this->assertNotNull($data['duration']['mean_seconds']);
        $this->assertNotNull($data['duration']['median_seconds']);
        $this->assertNotNull($response->json('meta.cached_at'));
    }

    public function test_overview_end_reasons_match_the_fixture(): void
    {
        $expected = $this->fx->expectedStats['screened_out_by_end_reason'];

        $data = $this->actingAs($this->supervisor)->getJson($this->url('overview'))->assertOk()->json('data');

        $actual = [];
        foreach ($data['end_reasons'] as $reason) {
            $actual[$reason['key']] = $reason['count'];
            $this->assertNotSame('', $reason['label']);
        }

        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
    }

    public function test_overview_quotas_match_the_fixture(): void
    {
        $expected = $this->fx->expectedStats['quotas'];

        $data = $this->actingAs($this->supervisor)->getJson($this->url('overview'))->assertOk()->json('data');
        $quotas = collect($data['quotas'])->keyBy('key');

        $this->assertSame($expected['total_valid']['count'], $quotas['total_valid']['current']);
        $this->assertSame($expected['total_valid']['target'], $quotas['total_valid']['target']);

        $this->assertSame($expected['quartiers_distincts']['count'], $quotas['quartiers_distincts']['current']);

        // `par_reseau` est un plafond : `current` = la valeur la plus représentée hors « direct ».
        $this->assertTrue($quotas['par_reseau']['max']);
        $this->assertSame(6, $quotas['par_reseau']['current']);
        $this->assertSame($expected['par_reseau']['target'], $quotas['par_reseau']['target']);
        $this->assertTrue($quotas['par_reseau']['exceeded']);

        $breakdown = collect($quotas['par_reseau']['breakdown'])->pluck('current', 'value')->all();
        foreach ($expected['par_reseau']['counts'] as $value => $count) {
            $this->assertSame($count, $breakdown[$value] ?? null, "réseau « {$value} »");
        }

        $zones = collect($quotas['par_quartier']['breakdown'])->pluck('current', 'value')->all();
        foreach ($expected['par_quartier']['counts'] as $zone => $count) {
            $this->assertSame($count, $zones[$zone] ?? null, "zone « {$zone} »");
        }
    }

    public function test_overview_kpis_match_the_fixture(): void
    {
        $expected = $this->fx->expectedStats['kpis'];

        $data = $this->actingAs($this->supervisor)->getJson($this->url('overview'))->assertOk()->json('data');
        $kpis = collect($data['kpis'])->keyBy('key');

        foreach ($expected as $key => $values) {
            $this->assertArrayHasKey($key, $kpis, "KPI « {$key} » manquant");
            $this->assertSame($values['numerator'], $kpis[$key]['numerator'], "numérateur de « {$key} »");
            $this->assertSame($values['denominator'], $kpis[$key]['denominator'], "dénominateur de « {$key} »");
            $this->assertEqualsWithDelta($values['value'], $kpis[$key]['value'], 0.0001, "valeur de « {$key} »");
        }
    }

    public function test_questions_return_one_entry_per_analyzable_question(): void
    {
        $data = $this->actingAs($this->supervisor)
            ->getJson($this->url('questions').'?keys=quartier,age_approx,nb_signaux')
            ->assertOk()->json('data');

        $byKey = collect($data)->keyBy('key');
        $this->assertCount(3, $byKey);

        $this->assertSame('choice', $byKey['quartier']['kind']);
        $this->assertSame(60, $byKey['quartier']['n']);
        $distribution = collect($byKey['quartier']['distribution'])->pluck('count', 'code')->all();
        $this->assertSame(13, $distribution['makepe']);
        $this->assertSame(100.0, round(array_sum($distribution) / $byKey['quartier']['n'] * 100, 1));

        $this->assertSame('numeric', $byKey['age_approx']['kind']);
        $this->assertNotNull($byKey['age_approx']['mean']);
        $this->assertNotEmpty($byKey['age_approx']['histogram']);

        // `nb_signaux` n'est posé que sur les fiches qui passent le filtre F2 : `asked` < total.
        $this->assertLessThanOrEqual(60, $byKey['nb_signaux']['asked']);
    }

    public function test_questions_exclude_pii_and_media_by_default(): void
    {
        $data = $this->actingAs($this->supervisor)->getJson($this->url('questions'))->assertOk()->json('data');
        $keys = collect($data)->pluck('key')->all();

        $this->assertContains('quartier', $keys);
        $this->assertNotContains('num_whatsapp', $keys, 'question `tags: ["pii"]`');
        $this->assertNotContains('nom_compte_momo', $keys, 'question `tags: ["pii"]`');
        $this->assertNotContains('photo_recu_momo', $keys, 'question média');
        $this->assertNotContains('stop_consentement', $keys, 'item `stop`');
    }

    public function test_timeline_groups_by_day_then_by_hour(): void
    {
        $day = $this->actingAs($this->supervisor)->getJson($this->url('timeline'))->assertOk()->json('data');

        $this->assertSame('day', $day['by']);
        $this->assertSame('Africa/Douala', $day['tz']);
        $this->assertSame(60, collect($day['points'])->sum('total'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $day['points'][0]['t']);

        $hour = $this->actingAs($this->supervisor)->getJson($this->url('timeline').'?by=hour')->assertOk()->json('data');
        $this->assertSame('hour', $hour['by']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:00$/', $hour['points'][0]['t']);
        $this->assertGreaterThanOrEqual(count($day['points']), count($hour['points']));

        $this->actingAs($this->supervisor)->getJson($this->url('timeline').'?tz=Mars/Olympus')->assertStatus(422);
    }

    public function test_enumerators_match_the_fixture_counts(): void
    {
        $expected = $this->fx->expectedStats['par_enqueteur'];

        $data = $this->actingAs($this->supervisor)->getJson($this->url('enumerators'))->assertOk()->json('data');
        $this->assertCount(3, $data);

        $byName = collect($data)->keyBy(fn (array $row) => $row['user']['name']);
        foreach ($expected as $deviceId => $count) {
            $n = MunagoFixtureLoader::enumeratorNumberOf($deviceId);
            $row = $byName['Enquêteur '.$n];
            $this->assertSame($count, $row['valid'], "fiches valides de l'enquêteur {$n}");
            $this->assertCount(7, $row['sparkline_7d']);
            $this->assertNotNull($row['mean_duration_seconds']);
            $this->assertSame(10, $row['quota_target']);
        }

        $this->assertSame(52, collect($data)->sum('valid'));
        $this->assertSame(60, collect($data)->sum('total'));
    }

    public function test_zones_expose_quota_progress(): void
    {
        $data = $this->actingAs($this->supervisor)->getJson($this->url('zones'))->assertOk()->json('data');
        $byZone = collect($data)->keyBy('zone');

        $this->assertSame(13, $byZone['Makepe']['total']);
        $this->assertSame(10, $byZone['Makepe']['valid']);
        $this->assertSame(10, $byZone['Makepe']['quota']['target']);
        $this->assertSame(10, $byZone['Makepe']['quota']['current']);
        $this->assertSame(60, collect($data)->sum('total'));
    }

    public function test_geo_returns_points_and_honours_bbox(): void
    {
        $all = $this->actingAs($this->supervisor)->getJson($this->url('geo'))->assertOk();
        $this->assertGreaterThan(0, count($all->json('data')));
        $this->assertFalse($all->json('meta.sampled'));
        $this->assertArrayHasKey('lat', $all->json('data.0'));
        $this->assertArrayHasKey('flags', $all->json('data.0'));

        $empty = $this->actingAs($this->supervisor)->getJson($this->url('geo').'?bbox=0,0,1,1')->assertOk();
        $this->assertSame([], $empty->json('data'));

        $this->actingAs($this->supervisor)->getJson($this->url('geo').'?bbox=oups')->assertStatus(422);
    }

    public function test_crosstab_requires_a_materialized_datasource_then_returns_the_matrix(): void
    {
        $url = $this->url('crosstab').'?row=quartier&col=sexe';

        $this->actingAs($this->supervisor)->getJson($url)
            ->assertStatus(409)
            ->assertJsonPath('code', 'datasource_not_ready');

        $this->actingAs($this->fx->owner)
            ->postJson('/api/surveys/'.$this->fx->survey->id.'/datasource/rebuild?sync=1')
            ->assertStatus(202);

        $data = $this->actingAs($this->supervisor)->getJson($url)->assertOk()->json('data');

        $this->assertSame('quartier', $data['row']['key']);
        $this->assertSame('sexe', $data['col']['key']);
        $this->assertNotEmpty($data['rows']);
        $this->assertNotEmpty($data['cols']);
        $this->assertCount(count($data['rows']), $data['cells']);
        $this->assertCount(count($data['cols']), $data['cells'][0]);

        $total = 0;
        foreach ($data['cells'] as $line) {
            $total += array_sum($line);
        }
        $this->assertSame($data['n'], $total);
        $this->assertSame(52, $data['n'], 'seules les fiches renseignant les deux questions sont croisées');

        $this->actingAs($this->supervisor)
            ->getJson($this->url('crosstab').'?row=zone&col=inconnue_xyz')
            ->assertStatus(422);
    }

    public function test_results_are_cached_for_twenty_seconds(): void
    {
        $first = $this->actingAs($this->supervisor)->getJson($this->url('overview'))->assertOk();
        $cachedAt = $first->json('meta.cached_at');

        Submission::query()->where('survey_id', $this->fx->survey->id)->limit(1)->delete();

        $second = $this->actingAs($this->supervisor)->getJson($this->url('overview'))->assertOk();
        $this->assertSame($cachedAt, $second->json('meta.cached_at'));
        $this->assertSame($first->json('data.totals.all'), $second->json('data.totals.all'));
    }

    public function test_statistics_require_the_supervisor_role(): void
    {
        $this->getJson($this->url('overview'))->assertStatus(401);
        $this->actingAs($this->stranger)->getJson($this->url('overview'))->assertStatus(403);
        $this->actingAs($this->fx->enumerators[1])->getJson($this->url('overview'))->assertStatus(403);
    }
}
