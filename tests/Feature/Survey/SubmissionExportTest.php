<?php

namespace Tests\Feature\Survey;

use App\Enums\UserRole;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\SurveyDatasource;
use App\Models\TargetDatabase;
use App\Models\User;
use App\Services\Survey\SurveyMaterializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * B-10 — `GET /surveys/{id}/submissions/export?format=csv|xlsx`.
 *
 * Exigence centrale : les colonnes de l'export sont **exactement** celles de la table `reponses` de la
 * source matérialisée (mêmes noms, même ordre), `include_pii` près.
 */
class SubmissionExportTest extends TestCase
{
    use RefreshDatabase;

    private MunagoFixtureLoader $fx;

    private User $analyst;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fx = MunagoFixtureLoader::load(['limit' => 12]);

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
    }

    private function url(string $query): string
    {
        return '/api/surveys/'.$this->fx->survey->id.'/submissions/export?'.$query;
    }

    /**
     * @return list<list<string>>
     */
    private function csv(string $content): array
    {
        $content = ltrim($content, "\u{FEFF}");
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($line === [null] || $line === ['']) {
                continue;
            }
            $rows[] = $line;
        }
        fclose($handle);

        return $rows;
    }

    public function test_csv_columns_are_exactly_the_reponses_columns(): void
    {
        $response = $this->actingAs($this->analyst)->get($this->url('format=csv&include_pii=1'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));

        $rows = $this->csv($response->streamedContent());
        $header = $rows[0];

        $expected = app(SurveyMaterializationService::class)->layoutFor($this->fx->survey)->columnNames();
        $this->assertSame($expected, $header);

        $this->assertSame(['submission_id', 'uuid', 'fiche_code', 'enqueteur_id', 'enqueteur_nom'], array_slice($header, 0, 5));
        $this->assertCount(12, array_slice($rows, 1), 'une ligne par soumission');
    }

    public function test_csv_rows_match_the_materialized_table(): void
    {
        // Matérialisation réelle puis comparaison ligne à ligne avec `SELECT * FROM reponses`.
        $this->actingAs($this->analyst)
            ->postJson('/api/surveys/'.$this->fx->survey->id.'/datasource/rebuild?sync=1')
            ->assertStatus(202);

        $path = TargetDatabase::query()
            ->whereKey(SurveyDatasource::query()->where('survey_id', $this->fx->survey->id)->value('target_database_id'))
            ->value('database');
        $pdo = new PDO('sqlite:'.$path);
        $materialized = $pdo->query('SELECT * FROM reponses ORDER BY submission_id')->fetchAll(PDO::FETCH_ASSOC);

        $rows = $this->csv($this->actingAs($this->analyst)->get($this->url('format=csv&include_pii=1'))->streamedContent());
        $header = array_shift($rows);

        $this->assertSame(array_keys($materialized[0]), $header);
        $this->assertCount(count($materialized), $rows);

        foreach ($materialized as $i => $expectedRow) {
            $actual = array_combine($header, $rows[$i]);
            $this->assertSame((string) $expectedRow['uuid'], $actual['uuid']);
            $this->assertSame((string) ($expectedRow['fiche_code'] ?? ''), $actual['fiche_code']);
            $this->assertSame((string) ($expectedRow['quartier'] ?? ''), $actual['quartier']);
            $this->assertSame((string) ($expectedRow['quartier_lib'] ?? ''), $actual['quartier_lib']);
        }
    }

    public function test_pii_columns_are_removed_without_include_pii(): void
    {
        $withPii = $this->csv($this->actingAs($this->analyst)->get($this->url('format=csv&include_pii=1'))->streamedContent())[0];
        $withoutPii = $this->csv($this->actingAs($this->analyst)->get($this->url('format=csv'))->streamedContent())[0];

        $this->assertContains('num_whatsapp', $withPii);
        $this->assertContains('nom_compte_momo', $withPii);
        $this->assertNotContains('num_whatsapp', $withoutPii);
        $this->assertNotContains('nom_compte_momo', $withoutPii);

        // Toutes les autres colonnes restent, dans le même ordre.
        $this->assertSame(array_values(array_diff($withPii, ['num_whatsapp', 'nom_compte_momo'])), $withoutPii);
    }

    public function test_filters_apply_to_the_export(): void
    {
        $all = $this->csv($this->actingAs($this->analyst)->get($this->url('format=csv'))->streamedContent());
        $screened = $this->csv($this->actingAs($this->analyst)->get($this->url('format=csv&status=screened_out'))->streamedContent());

        $this->assertGreaterThan(count($screened), count($all));
        $statusIndex = array_search('statut', $screened[0], true);
        foreach (array_slice($screened, 1) as $row) {
            $this->assertSame('screened_out', $row[$statusIndex]);
        }
    }

    public function test_xlsx_export_streams_a_spreadsheet(): void
    {
        $response = $this->actingAs($this->analyst)->get($this->url('format=xlsx'));
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $this->assertSame("PK\x03\x04", substr($content, 0, 4), 'un .xlsx est une archive ZIP');
        $this->assertGreaterThan(1000, strlen($content));
    }

    public function test_format_is_required_and_row_cap_is_enforced(): void
    {
        $this->actingAs($this->analyst)->getJson($this->url('format=pdf'))->assertStatus(422);
        $this->actingAs($this->analyst)->getJson('/api/surveys/'.$this->fx->survey->id.'/submissions/export')->assertStatus(422);

        config(['survey.export_max_rows' => 1]);
        $this->assertGreaterThan(1, Submission::query()->where('survey_id', $this->fx->survey->id)->count());

        $this->actingAs($this->analyst)->getJson($this->url('format=csv'))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_export_requires_the_analyst_role(): void
    {
        $this->getJson($this->url('format=csv'))->assertStatus(401);
        $this->actingAs($this->supervisor)->getJson($this->url('format=csv'))->assertStatus(403);
        $this->actingAs($this->fx->enumerators[1])->getJson($this->url('format=csv'))->assertStatus(403);
    }
}
