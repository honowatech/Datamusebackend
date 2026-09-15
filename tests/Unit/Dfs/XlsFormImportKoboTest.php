<?php

namespace Tests\Unit\Dfs;

use App\Services\Dfs\DfsValidator;
use App\Services\Dfs\XlsFormConverter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Import d'un XLSForm « externe » (convention Kobo, sans aucune colonne dfs::*).
 */
class XlsFormImportKoboTest extends TestCase
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

    private function tempPath(string $extension = 'xlsx'): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dfs_kobo_'.bin2hex(random_bytes(6)).'.'.$extension;
        $this->tempFiles[] = $path;

        return $path;
    }

    private function buildKoboForm(): string
    {
        $path = $this->tempPath();
        $writer = SimpleExcelWriter::create($path, 'xlsx')->noHeaderRow();

        $header = ['type', 'name', 'label::English (en)', 'label::French (fr)', 'hint', 'required', 'relevant', 'constraint', 'constraint_message', 'calculation', 'appearance', 'choice_filter', 'repeat_count', 'default'];
        $rows = [
            ['start', 'start', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['end', 'end', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['begin_group', 'intro', 'Introduction', 'Introduction', '', '', '', '', '', '', '', '', '', ''],
            ['note', 'welcome', 'Welcome to the survey', 'Bienvenue', '', '', '', '', '', '', '', '', '', ''],
            ['select_one yes_no', 'consent', 'Do you consent?', 'Consentez-vous ?', 'Read aloud', 'yes', '', '', '', '', '', '', '', ''],
            ['text', 'name', 'Your name', 'Votre nom', '', 'true()', "\${consent} = 'yes'", '', '', '', '', '', '', ''],
            ['integer', 'age', 'Your age', 'Votre âge', '', 'no', '', '. >= 18 and . <= 99', 'Between 18 and 99', '', '', '', '', 30],
            ['select_one region', 'region', 'Region', 'Région', '', '', '', '', '', '', 'minimal', '', '', ''],
            ['select_one district', 'district', 'District', 'District', '', '', '', '', '', '', '', 'region=${region}', '', ''],
            ['select_multiple fruits or_other', 'likes', 'Fruits you like', 'Fruits aimés', '', '', '', '', '', '', 'columns', '', '', ''],
            ['calculate', 'n_likes', '', '', '', '', '', '', '', 'count-selected(${likes})', '', '', '', ''],
            ['calculate', 'bad_calc', '', '', '', '', '', '', '', 'uuid()', '', '', '', ''],
            ['text', 'weird-name!', 'Weird', 'Bizarre', '', '', "starts-with(\${name}, 'a')", '', '', '', '', '', '', ''],
            ['barcode', 'code', 'Scan', 'Scanner', '', '', '', '', '', '', '', '', '', ''],
            ['geoshape', 'area', 'Area', 'Zone', '', '', '', '', '', '', '', '', '', ''],
            ['end_group', 'intro', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['begin_repeat', 'children', 'Children', 'Enfants', '', '', "\${consent} = 'yes'", '', '', '', '', '', 3, ''],
            ['text', 'child_name', 'Child name', 'Nom de l\'enfant', '', 'yes', '', '', '', '', '', '', '', ''],
            ['integer', 'child_age', 'Child age', 'Âge de l\'enfant', '', '', '', '. < 18', '', '', '', '', '', ''],
            ['end_repeat', 'children', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['text', 'comment', 'Comment', 'Commentaire', '', '', '${n_likes} > 0', '', '', '', 'multiline', '', '', ''],
        ];
        $writer->nameCurrentSheet('survey')->addHeader($header);
        foreach ($rows as $row) {
            $writer->addRow($row);
        }

        $choiceHeader = ['list_name', 'name', 'label::English (en)', 'label::French (fr)', 'region'];
        $choiceRows = [
            ['yes_no', 'yes', 'Yes', 'Oui', ''],
            ['yes_no', 'no', 'No', 'Non', ''],
            ['region', 'north', 'North', 'Nord', ''],
            ['region', 'south', 'South', 'Sud', ''],
            ['district', 'n1', 'North 1', 'Nord 1', 'north'],
            ['district', 'n2', 'North 2', 'Nord 2', 'north'],
            ['district', 's1', 'South 1', 'Sud 1', 'south'],
            ['fruits', 'apple', 'Apple', 'Pomme', ''],
            ['fruits', 'banana', 'Banana', 'Banane', ''],
        ];
        $writer->addNewSheetAndMakeItCurrent('choices')->addHeader($choiceHeader);
        foreach ($choiceRows as $row) {
            $writer->addRow($row);
        }

        $writer->addNewSheetAndMakeItCurrent('settings')->addHeader(['form_title', 'form_id', 'default_language', 'version']);
        $writer->addRow(['Kobo test form', 'kobo_test', 'English (en)', '2024010101']);
        $writer->close();

        return $path;
    }

    public function test_imports_external_kobo_form_into_a_valid_dfs(): void
    {
        $path = $this->buildKoboForm();
        $result = (new XlsFormConverter)->fromXlsForm($path);
        $definition = $result['definition'];
        $warnings = array_column($result['warnings'], 'message');

        $validation = (new DfsValidator(self::docsPath('dfs/dfs-v1.schema.json')))->validate($definition);
        $this->assertSame([], $validation->errors, self::show($validation->errors));

        // Langues et en-tête.
        $this->assertSame('en', $definition['settings']['default_language']);
        $this->assertSame(['en', 'fr'], $definition['settings']['languages']);
        $this->assertSame(['en' => 'Kobo test form'], $definition['title']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $definition['id']);
        $this->assertSame(2024010101, $definition['version']);

        // Une section par défaut car le premier niveau mêle métadonnées, groupe, répétition et question.
        $this->assertCount(1, $definition['sections']);
        $section = $definition['sections'][0];
        $this->assertSame('main', $section['key']);
        $keys = array_column($section['items'], 'key');
        $this->assertSame(['start', 'end', 'intro', 'children', 'comment'], $keys);

        $items = [];
        foreach ($section['items'] as $item) {
            $items[$item['key']] = $item;
        }
        $this->assertSame(['var' => '_start_time'], $items['start']['expression']);
        $this->assertSame(['var' => '_end_time'], $items['end']['expression']);

        // Groupe intro.
        $intro = $items['intro'];
        $this->assertSame('group', $intro['type']);
        $this->assertSame(['en' => 'Introduction', 'fr' => 'Introduction'], $intro['label']);
        $introItems = [];
        foreach ($intro['items'] as $item) {
            $introItems[$item['key']] = $item;
        }
        $this->assertSame(['welcome', 'consent', 'name', 'age', 'region', 'district', 'likes', 'n_likes', 'bad_calc', 'weird_name', 'code'], array_keys($introItems));

        $this->assertSame('note', $introItems['welcome']['type']);
        $this->assertTrue($introItems['consent']['required']);
        $this->assertSame(['en' => 'Read aloud'], $introItems['consent']['hint']);
        $this->assertSame('yes_no', $introItems['consent']['choices']);
        $this->assertTrue($introItems['name']['required']);
        $this->assertSame(['==' => [['var' => 'consent'], 'yes']], $introItems['name']['relevant']);
        $this->assertArrayNotHasKey('required', $introItems['age']);
        $this->assertSame(['and' => [['>=' => [['var' => 'age'], 18]], ['<=' => [['var' => 'age'], 99]]]], $introItems['age']['constraint']);
        $this->assertSame(['en' => 'Between 18 and 99'], $introItems['age']['constraint_message']);
        $this->assertSame(30, $introItems['age']['default']);
        $this->assertSame('dropdown', $introItems['region']['appearance']);
        $this->assertSame('chips', $introItems['likes']['appearance']);
        $this->assertSame(['choice' => 'other'], $introItems['likes']['other']);
        $this->assertSame(['count_selected' => [['var' => 'likes']]], $introItems['n_likes']['expression']);
        $this->assertNull($introItems['bad_calc']['expression']);
        $this->assertArrayNotHasKey('relevant', $introItems['weird_name']);
        $this->assertSame('text', $introItems['code']['type']);

        // Répétition.
        $children = $items['children'];
        $this->assertSame('group', $children['type']);
        $this->assertSame(['min' => 3, 'max' => 3], $children['repeat']);
        $this->assertSame(['==' => [['var' => 'consent'], 'yes']], $children['relevant']);
        $this->assertSame(['child_name', 'child_age'], array_column($children['items'], 'key'));
        $this->assertSame(['<' => [['var' => 'child_age'], 18]], $children['items'][1]['constraint']);

        // Question de premier niveau après la répétition.
        $this->assertSame('multiline', $items['comment']['appearance']);
        $this->assertSame(['>' => [['var' => 'n_likes'], 0]], $items['comment']['relevant']);

        // Listes : or_other ajoute « other », la cascade devient Choice.filter.
        $fruits = array_column($definition['choice_lists']['fruits'], 'name');
        $this->assertSame(['apple', 'banana', 'other'], $fruits);
        $this->assertSame(['en' => 'Other'], $definition['choice_lists']['fruits'][2]['label']);
        $districts = $definition['choice_lists']['district'];
        $this->assertSame(['region' => 'north'], $districts[0]['filter']);
        $this->assertSame(['region' => 'south'], $districts[2]['filter']);
        $this->assertSame(['en' => 'Yes', 'fr' => 'Oui'], $definition['choice_lists']['yes_no'][0]['label']);
        $this->assertSame([], $definition['follow_up_stages']);

        // Avertissements attendus.
        $this->assertNotEmpty(array_filter($warnings, static fn (string $m): bool => str_contains($m, 'uuid()')));
        $this->assertNotEmpty(array_filter($warnings, static fn (string $m): bool => str_contains($m, 'starts-with')));
        $this->assertNotEmpty(array_filter($warnings, static fn (string $m): bool => str_contains($m, 'geoshape')));
        $this->assertNotEmpty(array_filter($warnings, static fn (string $m): bool => str_contains($m, 'barcode')));
        $this->assertNotEmpty(array_filter($warnings, static fn (string $m): bool => str_contains($m, 'weird_name')));
        $this->assertNotEmpty(array_filter($warnings, static fn (string $m): bool => str_contains($m, 'section')));
        $badCalc = array_values(array_filter($result['warnings'], static fn (array $w): bool => $w['key'] === 'bad_calc' && str_contains($w['message'], 'uuid()')));
        $this->assertSame(13, $badCalc[0]['row'], 'la ligne du classeur est indiquée');
        $this->assertSame('survey', $badCalc[0]['sheet']);
    }

    public function test_rejects_workbook_without_survey_sheet(): void
    {
        $path = $this->tempPath();
        $writer = SimpleExcelWriter::create($path, 'xlsx');
        $writer->nameCurrentSheet('Feuil1')->addRow(['a' => 1]);
        $writer->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('survey');
        (new XlsFormConverter)->fromXlsForm($path);
    }

    public function test_bind_dm_aliases_are_recognized(): void
    {
        $path = $this->tempPath();
        $writer = SimpleExcelWriter::create($path, 'xlsx')->noHeaderRow();
        $writer->nameCurrentSheet('survey')->addHeader(['type', 'name', 'label', 'hint', 'relevant', 'bind::dm:stop', 'bind::dm:currency', 'bind::dm:audience']);
        $writer->addRow(['begin_group', 'A', 'Section A', '', '', '', '', '']);
        $writer->addRow(['select_one yn', 'ok', 'OK ?', '', '', '', '', '']);
        $writer->addRow(['note', 'fin', 'Fin', 'Merci, au revoir.', "\${ok} = 'non'", 'true', '', '']);
        $writer->addRow(['decimal', 'prix', 'Prix', '', '', '', 'XAF', '']);
        $writer->addRow(['note', 'consigne', 'Ne pas lire', '', '', '', '', 'enumerator']);
        $writer->addRow(['end_group', 'A', '', '', '', '', '', '']);
        $writer->addNewSheetAndMakeItCurrent('choices')->addHeader(['list_name', 'name', 'label']);
        $writer->addRow(['yn', 'oui', 'Oui']);
        $writer->addRow(['yn', 'non', 'Non']);
        $writer->close();

        $result = (new XlsFormConverter)->fromXlsForm($path);
        $definition = $result['definition'];
        $validation = (new DfsValidator(self::docsPath('dfs/dfs-v1.schema.json')))->validate($definition);
        $this->assertSame([], $validation->errors, self::show($validation->errors));

        $this->assertSame('fr', $definition['settings']['default_language']);
        $items = [];
        foreach ($definition['sections'][0]['items'] as $item) {
            $items[$item['key']] = $item;
        }
        $this->assertSame('stop', $items['fin']['type']);
        $this->assertSame(['fr' => 'Merci, au revoir.'], $items['fin']['message']);
        $this->assertSame(['==' => [['var' => 'ok'], 'non']], $items['fin']['relevant']);
        $this->assertSame('currency', $items['prix']['type'], 'decimal + bind::dm:currency = montant DFS');
        $this->assertSame('XAF', $items['prix']['currency']);
        $this->assertSame('enumerator', $items['consigne']['audience']);
    }
}
