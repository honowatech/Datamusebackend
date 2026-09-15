<?php

namespace Tests\Unit\Dfs;

use App\Services\Dfs\DfsDefaults;
use App\Services\Dfs\DfsValidator;
use App\Services\Dfs\XlsFormConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

class XlsFormRoundTripTest extends TestCase
{
    use DfsTestSupport;

    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        gc_collect_cycles(); // libère les flux zip des lecteurs OpenSpout avant la suppression (Windows)
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    private function tempXlsx(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dfs_roundtrip_'.bin2hex(random_bytes(6)).'.xlsx';
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtures(): array
    {
        return [
            'MunaGo' => ['fixtures/munago.v1.dfs.json'],
            'minimal' => ['dfs/examples/minimal.dfs.json'],
        ];
    }

    #[DataProvider('fixtures')]
    public function test_export_then_import_is_lossless(string $relative): void
    {
        $original = self::loadJson($relative);
        $converter = new XlsFormConverter;
        $path = $this->tempXlsx();

        $export = $converter->toXlsForm($original, $path);
        $this->assertFileExists($path);
        $this->assertIsArray($export['warnings']);

        $import = $converter->fromXlsForm($path);
        $definition = $import['definition'];

        $validation = (new DfsValidator(self::docsPath('dfs/dfs-v1.schema.json')))->validate($definition);
        $this->assertSame([], $validation->errors, self::show($validation->errors));
        $this->assertTrue($validation->isValid());

        $this->assertSame(self::normalize($original), self::normalize($definition));
        $this->assertSame([], array_filter($import['warnings'], static fn (array $w): bool => ! str_contains($w['message'], 'normalisé')), self::show($import['warnings']));
    }

    public function test_munago_export_has_three_sheets_with_expected_headers(): void
    {
        $original = self::loadJson('fixtures/munago.v1.dfs.json');
        $path = $this->tempXlsx();
        $export = (new XlsFormConverter)->toXlsForm($original, $path);

        $survey = SimpleExcelReader::create($path, 'xlsx')->fromSheetName('survey');
        $surveyRows = $survey->getRows()->toArray();
        $surveyHeaders = $survey->getHeaders();
        $survey->close();
        $choices = SimpleExcelReader::create($path, 'xlsx')->fromSheetName('choices');
        $choiceRows = $choices->getRows()->toArray();
        $choiceHeaders = $choices->getHeaders();
        $choices->close();
        $settings = SimpleExcelReader::create($path, 'xlsx')->fromSheetName('settings');
        $settingsRows = $settings->getRows()->toArray();
        $settingsHeaders = $settings->getHeaders();
        $settings->close();

        foreach (['type', 'name', 'label::Français (fr)', 'label::English (en)', 'hint::Français (fr)', 'required', 'required_message::Français (fr)', 'relevant', 'constraint', 'constraint_message::Français (fr)', 'calculation', 'appearance', 'default', 'read_only', 'parameters', 'choice_filter', 'dfs::type', 'dfs::stop', 'dfs::postcode', 'dfs::stage', 'dfs::due_offset_days', 'dfs::props'] as $column) {
            $this->assertContains($column, $surveyHeaders, "colonne survey « {$column} »");
        }
        foreach (['list_name', 'name', 'label::Français (fr)', 'label::English (en)', 'dfs::abbr', 'ville'] as $column) {
            $this->assertContains($column, $choiceHeaders, "colonne choices « {$column} »");
        }
        foreach (['form_title', 'form_id', 'version', 'default_language', 'dfs::title', 'dfs::settings'] as $column) {
            $this->assertContains($column, $settingsHeaders, "colonne settings « {$column} »");
        }

        $byName = [];
        foreach ($surveyRows as $row) {
            if (! str_starts_with($row['type'], 'end_')) {
                $byName[$row['name']] = $row;
            }
        }
        // Sections → groupes de premier niveau.
        $this->assertSame('begin_group', $byName['A']['type']);
        $this->assertSame('end_group', $surveyRows[count($surveyRows) - 1]['type']);
        // stop → note + dfs::stop + relevant déclencheur + hint message.
        $this->assertSame('note', $byName['stop_consentement']['type']);
        $this->assertSame('yes', $byName['stop_consentement']['dfs::stop']);
        $this->assertSame("\${consentement} = 'non'", $byName['stop_consentement']['relevant']);
        $this->assertNotSame('', $byName['stop_consentement']['hint::Français (fr)']);
        // currency → decimal + dfs::type + bornes en contrainte.
        $this->assertSame('decimal', $byName['montant_recu']['type']);
        $this->assertSame('currency', $byName['montant_recu']['dfs::type']);
        $this->assertStringContainsString('. >= 0', $byName['montant_recu']['constraint']);
        $this->assertStringContainsString('. <= 20000', $byName['montant_recu']['constraint']);
        // other → ligne text compagnon.
        $this->assertSame('text', $byName['lieu_enrolement_other']['type']);
        $this->assertSame("selected(\${lieu_enrolement}, 'autre')", $byName['lieu_enrolement_other']['relevant']);
        $this->assertSame('select_one lieux_enrolement', $byName['lieu_enrolement']['type']);
        // postcode → question séparée.
        $this->assertSame('q6_freins', $byName['q6_freins__codes']['dfs::postcode']);
        $this->assertStringStartsWith('select_multiple ', $byName['q6_freins__codes']['type']);
        // Étapes → begin_group dfs::stage.
        $this->assertSame('begin_group', $byName['j4']['type']);
        $this->assertSame('j4', $byName['j4']['dfs::stage']);
        $this->assertSame(4, (int) $byName['j4']['dfs::due_offset_days']);
        $this->assertSame("selected('oui_paiement hesite_puis_oui', \${q13_decision})", $byName['j4']['relevant']);
        // Cascade ville → quartier.
        $this->assertSame('ville=${ville}', $byName['quartier']['choice_filter']);
        $bonamoussadi = array_values(array_filter($choiceRows, static fn (array $r): bool => $r['list_name'] === 'quartiers' && $r['name'] === 'bonamoussadi'))[0];
        $this->assertSame('douala', $bonamoussadi['ville']);
        $this->assertSame('BMP', $bonamoussadi['dfs::abbr']);
        // Multi-langue.
        $this->assertSame('Ville', $byName['ville']['label::Français (fr)']);
        $this->assertSame('City', $byName['ville']['label::English (en)']);
        // Calcul.
        $this->assertSame('count-selected(${f2_capacite})', $byName['nb_signaux']['calculation']);
        // Settings.
        $this->assertCount(1, $settingsRows);
        $this->assertSame('Français (fr)', $settingsRows[0]['default_language']);
        $this->assertSame($original['id'], $settingsRows[0]['form_id']);
        $this->assertSame('Étude de marché MunaGo — terrain', $settingsRows[0]['form_title']);
        $this->assertArrayHasKey('fiche_code', json_decode($settingsRows[0]['dfs::settings'], true));

        $messages = array_column($export['warnings'], 'message');
        $this->assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'stop')));
        $this->assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'Étape de suivi')));
        $this->assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'currency')));
        $this->assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'dfs::settings')));
    }

    public function test_import_without_dfs_columns_still_recovers_structure(): void
    {
        // Les compagnons et la cascade sont reconnus par convention Kobo même sans dfs::props.
        $original = self::loadJson('dfs/examples/minimal.dfs.json');
        $path = $this->tempXlsx();
        (new XlsFormConverter)->toXlsForm($original, $path);

        $reader = SimpleExcelReader::create($path, 'xlsx')->fromSheetName('survey');
        $rows = $reader->getRows()->toArray();
        $reader->close();
        $stripped = $this->tempXlsx();
        $writer = SimpleExcelWriter::create($stripped, 'xlsx')->noHeaderRow();
        $headers = array_values(array_filter(array_keys($rows[0]), static fn (string $h): bool => ! str_starts_with($h, 'dfs::')));
        $writer->nameCurrentSheet('survey')->addHeader($headers);
        foreach ($rows as $row) {
            $writer->addRow(array_map(static fn (string $h) => $row[$h], $headers));
        }
        $choices = SimpleExcelReader::create($path, 'xlsx')->fromSheetName('choices');
        $choiceRows = $choices->getRows()->toArray();
        $choices->close();
        $choiceHeaders = array_values(array_filter(array_keys($choiceRows[0]), static fn (string $h): bool => ! str_starts_with($h, 'dfs::')));
        $writer->addNewSheetAndMakeItCurrent('choices')->addHeader($choiceHeaders);
        foreach ($choiceRows as $row) {
            $writer->addRow(array_map(static fn (string $h) => $row[$h], $choiceHeaders));
        }
        $writer->close();

        $import = (new XlsFormConverter)->fromXlsForm($stripped);
        $definition = $import['definition'];
        $validation = (new DfsValidator(self::docsPath('dfs/dfs-v1.schema.json')))->validate($definition);
        $this->assertSame([], $validation->errors, self::show($validation->errors));

        $questions = [];
        foreach ($definition['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $questions[$item['key']] = $item;
            }
        }
        // Sans dfs::stage, l'étape j7 redevient une section ordinaire.
        $this->assertSame(['A', 'T', 'j7'], array_column($definition['sections'], 'key'));
        $this->assertSame([], $definition['follow_up_stages']);
        $this->assertSame(['choice' => 'autre', 'label' => ['fr' => 'Précisez le lieu'], 'required' => true], $questions['lieu']['other']);
        $this->assertSame('themes_refus', $questions['Q14']['postcode']['choices']);
        $this->assertFalse($questions['Q14']['postcode']['multiple']);
        $this->assertSame(['ville' => 'douala'], $definition['choice_lists']['quartiers'][0]['filter']);
        $this->assertSame('note', $questions['stop_refus']['type'], 'sans dfs::stop, un stop redevient une note');
        $this->assertSame(['and' => [['>=' => [['var' => 'montant'], 0]], ['>=' => [['var' => 'montant'], 1000]]]], $questions['montant']['constraint']);
        $this->assertSame(['fr', 'en'], $definition['settings']['languages']);
        $this->assertSame('Formulaire importé', $definition['title']['fr']);
    }

    /**
     * Défauts du schéma appliqués, clés triées, réels entiers normalisés : égalité structurelle.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private static function normalize(array $definition): array
    {
        return self::sortKeys(DfsDefaults::apply($definition));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sortKeys'], $value);
        }
        ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = self::sortKeys($v);
        }

        return $value;
    }
}
