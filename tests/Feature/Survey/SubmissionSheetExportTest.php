<?php

namespace Tests\Feature\Survey;

use App\Enums\UserRole;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\User;
use App\Services\Survey\SurveyMaterializationService;
use App\Support\CsvWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * F-B5 — `GET /submissions/{id}/export?layout=long|wide`.
 *
 * Deux exigences centrales : `wide` rend **exactement** les colonnes de l'export d'enquête, et
 * l'écriture (BOM, séparateur, garde anti-injection) est celle de `App\Support\CsvWriter`.
 */
class SubmissionSheetExportTest extends TestCase
{
    use RefreshDatabase;

    private MunagoFixtureLoader $fx;

    private User $analyst;

    private User $supervisor;

    private User $stranger;

    /** Fiche avec acompte, photo, post-codage et trois suivis complétés. */
    private const WITH_DEPOSIT = '5237bc92-dd65-482f-ba2b-a385a448239a';

    /** Fiche hors cible (`stop_f1_enfant`). */
    private const SCREENED_OUT = '1edd5be3-cb4a-43f9-a4cb-0c6db34f666f';

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
        return '/api/submissions/'.$submission->id.'/export'.($query === '' ? '' : '?'.$query);
    }

    /**
     * @return list<list<string>>
     */
    private function csv(string $content): array
    {
        $this->assertStringStartsWith(CsvWriter::BOM, $content, 'le BOM UTF-8 est présent');
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, ltrim($content, CsvWriter::BOM));
        rewind($handle);

        $rows = [];
        while (($line = fgetcsv($handle, 0, CsvWriter::DELIMITER, CsvWriter::ENCLOSURE, CsvWriter::ESCAPE)) !== false) {
            $rows[] = $line === [null] ? [] : $line;
        }
        fclose($handle);

        return $rows;
    }

    private function submission(string $uuid): Submission
    {
        return $this->fx->submission($uuid);
    }

    // ------------------------------------------------------------------ disposition « long »

    public function test_long_layout_has_a_header_block_then_one_row_per_asked_question(): void
    {
        $submission = $this->submission(self::WITH_DEPOSIT);

        $response = $this->actingAs($this->analyst)->get($this->url($submission, 'layout=long&include_pii=1'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Content-Disposition', (string) $response->headers->get('Access-Control-Expose-Headers'));
        $this->assertStringContainsString(
            'munago-test-terrain_DLA-BMP-25_long.csv',
            (string) $response->headers->get('Content-Disposition'),
        );

        $rows = $this->csv($response->streamedContent());

        $this->assertSame(['champ', 'valeur'], $rows[0]);
        $header = collect($rows)->takeWhile(fn (array $r) => $r !== [])->mapWithKeys(fn (array $r) => [$r[0] => $r[1] ?? null])->all();
        $this->assertArrayHasKey('Enquête', $header);
        $this->assertSame('1', $header['Version']);
        $this->assertSame('DLA-BMP-25', $header['Fiche']);
        $this->assertStringStartsWith('Enquêteur : ', $header['Origine']);
        $this->assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', $header['Début']);
        $this->assertSame('submitted', $header['Statut']);

        $blank = array_search([], $rows, true);
        $this->assertIsInt($blank, 'une ligne vide sépare l\'en-tête des réponses');
        $this->assertSame(
            ['fiche_code', 'section', 'question_key', 'question', 'reponse', 'code', 'autre', 'themes', 'etape'],
            $rows[$blank + 1],
        );

        $data = array_slice($rows, $blank + 2);
        $byKey = collect($data)->keyBy(fn (array $r) => $r[2]);

        $this->assertSame('DLA-BMP-25', $data[0][0]);
        $this->assertSame('Oui', $byKey['consentement'][4]);
        $this->assertSame('oui', $byKey['consentement'][5]);
        $this->assertSame('5 000 FCFA', $byKey['montant_recu'][4]);
        $this->assertSame('699412873', $byKey['num_whatsapp'][4], 'include_pii=1');

        // Post-codage enquêteur : une ligne de plus, sous la clé compagnon.
        $this->assertTrue($byKey->has('q6_freins__codes'));
        $this->assertNotSame('', $byKey['q6_freins__codes'][4]);

        // Aucune question non posée sans `include_unasked`.
        $this->assertFalse($byKey->has('j13_inexistant'));
        $stageRows = array_values(array_filter($data, fn (array $r) => $r[8] !== ''));
        $this->assertNotSame([], $stageRows, 'les réponses de suivi portent leur étape');
        $this->assertSame(['j4', 'j7', 'j14'], array_values(array_unique(array_column($stageRows, 8))));
    }

    public function test_include_unasked_adds_the_questions_that_were_never_asked(): void
    {
        $submission = $this->submission(self::SCREENED_OUT);

        $withoutUnasked = $this->dataRows($this->actingAs($this->analyst)
            ->get($this->url($submission, 'layout=long&include_pii=1'))->streamedContent());
        $withUnasked = $this->dataRows($this->actingAs($this->analyst)
            ->get($this->url($submission, 'layout=long&include_pii=1&include_unasked=1'))->streamedContent());

        $this->assertGreaterThan(count($withoutUnasked), count($withUnasked));
        $this->assertNotContains('q13_decision', array_column($withoutUnasked, 2));
        $this->assertContains('q13_decision', array_column($withUnasked, 2));
    }

    public function test_pii_rows_are_absent_for_a_supervisor_and_include_pii_is_refused(): void
    {
        $submission = $this->submission(self::WITH_DEPOSIT);

        $rows = $this->dataRows($this->actingAs($this->supervisor)
            ->get($this->url($submission, 'layout=long'))->streamedContent());
        $this->assertNotContains('num_whatsapp', array_column($rows, 2));
        $this->assertNotContains('nom_compte_momo', array_column($rows, 2));

        $this->actingAs($this->supervisor)->getJson($this->url($submission, 'layout=long&include_pii=1'))
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_accents_survive_and_a_formula_cell_is_neutralised(): void
    {
        $submission = $this->submission(self::WITH_DEPOSIT);
        $answers = $submission->answers;
        $answers['q6_freins'] = '=SOMME(A1:A9) — coût élevé';
        $submission->forceFill(['answers' => $answers])->save();

        $content = $this->actingAs($this->analyst)
            ->get($this->url($submission->refresh(), 'layout=long&include_pii=1'))->streamedContent();

        $this->assertStringContainsString('coût élevé', $content, 'accents UTF-8 conservés');
        $rows = collect($this->dataRows($content))->keyBy(fn (array $r) => $r[2]);
        $this->assertSame("'=SOMME(A1:A9) — coût élevé", $rows['q6_freins'][4]);
        $this->assertSame("'=SOMME(A1:A9) — coût élevé", $rows['q6_freins'][5]);
    }

    // ------------------------------------------------------------------ disposition « wide »

    public function test_wide_layout_matches_the_survey_export_columns(): void
    {
        $submission = $this->submission(self::WITH_DEPOSIT);

        $rows = $this->csv($this->actingAs($this->analyst)
            ->get($this->url($submission, 'layout=wide&include_pii=1'))->streamedContent());

        $expected = app(SurveyMaterializationService::class)->layoutFor($this->fx->survey)->columnNames();
        $this->assertSame($expected, $rows[0], 'colonnes strictement identiques à l\'export d\'enquête');
        $this->assertCount(2, array_filter($rows, fn (array $r) => $r !== []), 'un en-tête + une ligne');

        $line = array_combine($rows[0], $rows[1]);
        $this->assertSame((string) $submission->uuid, $line['uuid']);
        $this->assertSame('DLA-BMP-25', $line['fiche_code']);
        $this->assertSame('5000', $line['montant_recu']);

        // Comparaison ligne à ligne avec l'export de l'enquête entière.
        $surveyCsv = $this->csv($this->actingAs($this->analyst)
            ->get('/api/surveys/'.$this->fx->survey->id.'/submissions/export?format=csv&include_pii=1')
            ->streamedContent());
        $surveyHeader = $surveyCsv[0];
        $this->assertSame($surveyHeader, $rows[0]);
        $match = collect(array_slice($surveyCsv, 1))->first(fn (array $r) => $r[array_search('uuid', $surveyHeader, true)] === (string) $submission->uuid);
        $this->assertSame($match, $rows[1], 'la ligne est celle de l\'export d\'enquête, à l\'identique');
    }

    public function test_wide_layout_drops_the_pii_columns_without_include_pii(): void
    {
        $submission = $this->submission(self::WITH_DEPOSIT);

        $header = $this->csv($this->actingAs($this->supervisor)
            ->get($this->url($submission, 'layout=wide'))->streamedContent())[0];

        $this->assertNotContains('num_whatsapp', $header);
        $this->assertNotContains('nom_compte_momo', $header);
        $this->assertContains('montant_recu', $header);
    }

    // ------------------------------------------------------------------ droits et paramètres

    public function test_an_enumerator_exports_their_own_sheet_only(): void
    {
        $own = Submission::query()->where('enumerator_id', $this->fx->enumerators[1]->id)->orderBy('id')->firstOrFail();
        $other = Submission::query()->where('enumerator_id', $this->fx->enumerators[2]->id)->orderBy('id')->firstOrFail();

        // Anonyme d'abord : `actingAs` vaut pour toutes les requêtes suivantes du test.
        $this->getJson($this->url($own))->assertStatus(401);

        $this->actingAs($this->fx->enumerators[1])->get($this->url($own, 'layout=long'))->assertOk();
        $this->actingAs($this->fx->enumerators[1])->getJson($this->url($other, 'layout=long'))->assertStatus(403);
        $this->actingAs($this->stranger)->getJson($this->url($own))->assertStatus(403);
    }

    public function test_unknown_format_and_layout_are_refused(): void
    {
        $submission = $this->submission(self::WITH_DEPOSIT);

        $this->actingAs($this->analyst)->getJson($this->url($submission, 'format=xlsx'))->assertStatus(422);
        $this->actingAs($this->analyst)->getJson($this->url($submission, 'layout=pivot'))->assertStatus(422);
        // `format` est facultatif : csv par défaut.
        $this->actingAs($this->analyst)->get($this->url($submission))->assertOk();
    }

    /**
     * F-E1 : en requête **cross-origin**, `HandleCors` réécrit `Access-Control-Expose-Headers` à partir
     * de `config('cors.exposed_headers')` et écrase donc l'en-tête posé par le contrôleur. Sans
     * `Content-Disposition` dans la configuration, le navigateur ne peut pas lire le nom du fichier.
     */
    public function test_content_disposition_stays_exposed_on_a_cross_origin_request(): void
    {
        $submission = $this->submission(self::WITH_DEPOSIT);
        $origin = (string) config('cors.allowed_origins')[0];

        foreach (['layout=long', 'layout=wide'] as $query) {
            $response = $this->actingAs($this->analyst)->get($this->url($submission, $query), ['Origin' => $origin]);

            $response->assertOk();
            $this->assertStringContainsString(
                'Content-Disposition',
                (string) $response->headers->get('Access-Control-Expose-Headers'),
                "le nom du fichier reste lisible par le navigateur ($query)",
            );
            $this->assertStringContainsString('.csv', (string) $response->headers->get('Content-Disposition'));
        }
    }

    /** L'export de TOUTE l'enquête voyage par le même en-tête : il profite de la même correction. */
    public function test_the_survey_export_also_exposes_content_disposition_cross_origin(): void
    {
        $origin = (string) config('cors.allowed_origins')[0];

        $response = $this->actingAs($this->analyst)
            ->get('/api/surveys/'.$this->fx->survey->id.'/submissions/export?format=csv', ['Origin' => $origin]);

        $response->assertOk();
        $this->assertStringContainsString(
            'Content-Disposition',
            (string) $response->headers->get('Access-Control-Expose-Headers'),
        );
    }

    /**
     * Lignes de données d'un export `long` (après le bloc d'en-tête et la ligne vide).
     *
     * @return list<list<string>>
     */
    private function dataRows(string $content): array
    {
        $rows = $this->csv($content);
        $blank = array_search([], $rows, true);

        return array_values(array_filter(array_slice($rows, $blank + 2), fn (array $r) => $r !== []));
    }
}
