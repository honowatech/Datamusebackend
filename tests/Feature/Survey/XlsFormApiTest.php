<?php

namespace Tests\Feature\Survey;

use App\Enums\UserRole;
use App\Http\Controllers\Survey\SurveyXlsFormController;
use App\Models\ProjectMember;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\User;
use App\Services\Dfs\XlsFormConverter;
use App\Services\Survey\SurveyVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * B-06b : `POST /surveys/import/xlsform` et `GET /surveys/{survey}/export/xlsform`.
 *
 * L'aller-retour DFS ⇄ XLSForm lui-même est couvert par `Tests\Unit\Dfs\XlsFormRoundTripTest` (B-06a) ;
 * ce test vérifie les endpoints : création du questionnaire, avertissements, fichier produit, rôles, 413.
 */
class XlsFormApiTest extends TestCase
{
    use RefreshDatabase;

    private const MUNAGO_FIXTURE = '../docs/fixtures/munago.v1.dfs.json';

    private User $owner;

    private User $enumerator;

    private SurveyProject $project;

    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => UserRole::Analyste]);
        $this->enumerator = User::factory()->create(['role' => UserRole::Enqueteur]);

        $this->project = SurveyProject::factory()->create(['owner_id' => $this->owner->id, 'name' => 'MunaGo Douala']);
        ProjectMember::factory()->analyste()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $this->enumerator->id]);
    }

    protected function tearDown(): void
    {
        // OpenSpout garde le flux zip ouvert jusqu'au ramassage des cycles (suppression bloquée sous Windows).
        gc_collect_cycles();
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    // ================================================================== import

    public function test_import_of_a_datamuse_export_creates_a_survey_without_warnings(): void
    {
        $path = $this->munagoXlsForm();

        $response = $this->actingAs($this->owner, 'sanctum')->post('/api/surveys/import/xlsform', [
            'project_id' => $this->project->id,
            'file' => new UploadedFile($path, 'munago.xlsx', null, null, true),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.warnings', [])
            ->assertJsonPath('data.survey.project_id', $this->project->id)
            ->assertJsonPath('data.survey.status', 'draft')
            ->assertJsonPath('data.survey.draft_version.version', 1)
            ->assertJsonPath('data.survey.draft_version.revision', 0);

        $survey = Survey::query()->findOrFail($response->json('data.survey.id'));
        $draft = app(SurveyVersionService::class)->draftOf($survey);
        $this->assertNotNull($draft);

        $original = $this->munagoDefinition();
        $this->assertSame('1.0', $draft->definition['dfs_version']);
        $this->assertCount(count($original['sections']), $draft->definition['sections']);
        $this->assertCount(count($original['follow_up_stages']), $draft->definition['follow_up_stages']);
        $this->assertSame(
            array_column($original['sections'], 'key'),
            array_column($draft->definition['sections'], 'key'),
        );

        // Le brouillon importé est valide.
        $report = app(SurveyVersionService::class)->validateDraft($survey);
        $this->assertTrue($report['valid'], json_encode($report['errors'], JSON_UNESCAPED_UNICODE));
    }

    public function test_import_of_a_minimal_kobo_xlsform_reports_warnings(): void
    {
        $path = $this->koboXlsForm();

        $response = $this->actingAs($this->owner, 'sanctum')->post('/api/surveys/import/xlsform', [
            'project_id' => $this->project->id,
            'title' => 'Enquête Kobo',
            'file' => new UploadedFile($path, 'kobo.xlsx', null, null, true),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201)->assertJsonPath('data.survey.title', 'Enquête Kobo');

        $warnings = $response->json('data.warnings');
        $this->assertNotEmpty($warnings, 'un XLSForm Kobo contient des constructions non convertibles');
        foreach ($warnings as $warning) {
            $this->assertSame(SurveyXlsFormController::WARNING_CODE, $warning['code']);
            $this->assertSame('warning', $warning['severity']);
            $this->assertArrayHasKey('path', $warning);
            $this->assertNotSame('', $warning['message']);
        }

        // Malgré les avertissements, la définition produite est valide et contient les questions reconnues.
        $survey = Survey::query()->findOrFail($response->json('data.survey.id'));
        $report = app(SurveyVersionService::class)->validateDraft($survey);
        $this->assertTrue($report['valid'], json_encode($report['errors'], JSON_UNESCAPED_UNICODE));
    }

    public function test_import_rejects_a_non_xlsform_file(): void
    {
        $this->actingAs($this->owner, 'sanctum')->post('/api/surveys/import/xlsform', [
            'project_id' => $this->project->id,
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'rien à voir'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['file']]);

        $this->assertSame(0, Survey::query()->count());
    }

    public function test_import_over_5_mb_returns_413(): void
    {
        $this->actingAs($this->owner, 'sanctum')->post('/api/surveys/import/xlsform', [
            'project_id' => $this->project->id,
            'file' => UploadedFile::fake()->create('enorme.xlsx', 6 * 1024),
        ], ['Accept' => 'application/json'])
            ->assertStatus(413)
            ->assertJsonPath('message', 'Fichier trop volumineux (maximum 5 Mo).');

        $this->assertSame(0, Survey::query()->count());
    }

    public function test_enumerator_cannot_import(): void
    {
        $path = $this->munagoXlsForm();

        $this->actingAs($this->enumerator, 'sanctum')->post('/api/surveys/import/xlsform', [
            'project_id' => $this->project->id,
            'file' => new UploadedFile($path, 'munago.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])->assertStatus(403);

        $this->assertSame(0, Survey::query()->count());
    }

    // ================================================================== export

    public function test_export_returns_a_three_sheet_workbook(): void
    {
        $survey = app(SurveyVersionService::class)
            ->createSurvey($this->project, $this->owner, 'MunaGo terrain', $this->munagoDefinition());

        $response = $this->actingAs($this->owner, 'sanctum')->get("/api/surveys/{$survey->id}/export/xlsform");

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('munago-terrain-v1.xlsx', (string) $response->headers->get('Content-Disposition'));

        $warnings = json_decode((string) $response->headers->get(SurveyXlsFormController::WARNINGS_HEADER), true);
        $this->assertIsArray($warnings);

        $path = $this->tempPath('xlsx');
        file_put_contents($path, $this->downloadContent($response->baseResponse));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();

        foreach (['survey', 'choices', 'settings'] as $sheet) {
            $this->assertStringContainsString('name="'.$sheet.'"', $workbook, "feuille « {$sheet} » attendue");
        }
    }

    public function test_export_of_an_unknown_version_returns_404(): void
    {
        $survey = app(SurveyVersionService::class)->createSurvey($this->project, $this->owner, 'MunaGo terrain');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}/export/xlsform?version=42")
            ->assertStatus(404);
    }

    public function test_enumerator_cannot_export(): void
    {
        $survey = app(SurveyVersionService::class)->createSurvey($this->project, $this->owner, 'MunaGo terrain');

        $this->actingAs($this->enumerator, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}/export/xlsform")
            ->assertStatus(403);
    }

    // ================================================================== utilitaires

    /**
     * Classeur produit par `XlsFormConverter::toXlsForm()` depuis la fixture MunaGo (export Datamuse).
     */
    private function munagoXlsForm(): string
    {
        $path = $this->tempPath('xlsx');
        (new XlsFormConverter)->toXlsForm($this->munagoDefinition(), $path);
        $this->assertFileExists($path);

        return $path;
    }

    /**
     * XLSForm « externe » minimal (conventions Kobo, aucune colonne `dfs::*`), avec des constructions non
     * convertibles (types `barcode` / `geoshape`, fonction XPath inconnue, nom de question invalide).
     */
    private function koboXlsForm(): string
    {
        $path = $this->tempPath('xlsx');
        $writer = SimpleExcelWriter::create($path, 'xlsx')->noHeaderRow();

        $writer->nameCurrentSheet('survey')->addHeader(['type', 'name', 'label', 'required', 'relevant', 'calculation']);
        foreach ([
            ['select_one yes_no', 'consent', 'Consentez-vous ?', 'yes', '', ''],
            ['text', 'nom', 'Votre nom', '', "\${consent} = 'yes'", ''],
            ['barcode', 'code_barres', 'Scanner le code', '', '', ''],
            ['geoshape', 'zone', 'Tracer la zone', '', '', ''],
            ['calculate', 'jeton', '', '', '', 'uuid()'],
            ['text', 'nom-bizarre!', 'Nom bizarre', '', '', ''],
        ] as $row) {
            $writer->addRow($row);
        }

        $writer->addNewSheetAndMakeItCurrent('choices')->addHeader(['list_name', 'name', 'label']);
        $writer->addRow(['yes_no', 'yes', 'Oui'])->addRow(['yes_no', 'no', 'Non']);

        $writer->addNewSheetAndMakeItCurrent('settings')->addHeader(['form_title', 'default_language']);
        $writer->addRow(['Enquête Kobo', 'French (fr)']);

        $writer->close();
        unset($writer);
        gc_collect_cycles();

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function munagoDefinition(): array
    {
        $path = base_path(self::MUNAGO_FIXTURE);
        if (! is_file($path)) {
            $this->markTestSkipped("Fixture MunaGo introuvable : {$path}");
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function tempPath(string $extension): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xlsform_api_'.bin2hex(random_bytes(6)).'.'.$extension;
        $this->tempFiles[] = $path;

        return $path;
    }

    private function downloadContent(mixed $response): string
    {
        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        return (string) file_get_contents($response->getFile()->getPathname());
    }
}
