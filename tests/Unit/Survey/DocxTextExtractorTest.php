<?php

namespace Tests\Unit\Survey;

use App\Services\Survey\DocxTextExtractor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class DocxTextExtractorTest extends TestCase
{
    private const MUNAGO_DOCX = 'C:\Users\DELL\Downloads\MunaGo-Questionnaire-Terrain V1.0.docx';

    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    private function tempPath(string $extension): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'docx_test_'.bin2hex(random_bytes(6)).'.'.$extension;
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_extracts_real_munago_questionnaire(): void
    {
        if (! is_file(self::MUNAGO_DOCX)) {
            $this->markTestSkipped('Questionnaire MunaGo .docx introuvable : '.self::MUNAGO_DOCX);
        }
        $extractor = new DocxTextExtractor;
        $text = $extractor->extract(self::MUNAGO_DOCX);

        $this->assertStringContainsString("Questionnaire d'étude de marché", $text);
        $this->assertStringContainsString('F1.', $text);
        $this->assertStringContainsString('☐', $text);
        $this->assertStringContainsString('J+14', $text);
        $this->assertStringContainsString(' | ', $text, 'cellules de tableau séparées par |');
        $this->assertStringContainsString("\n- ", $text, 'listes préfixées par « - »');
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringNotContainsString('<w:', $text);
        $this->assertDoesNotMatchRegularExpression("/\n{3,}/", $text, 'lignes vides compressées');

        $blocks = $extractor->extractWithStructure(self::MUNAGO_DOCX);
        $kinds = array_count_values(array_column($blocks, 'kind'));
        $this->assertGreaterThanOrEqual(8, $kinds['heading'] ?? 0);
        $this->assertGreaterThan(0, $kinds['table_row'] ?? 0);
        $this->assertGreaterThan(0, $kinds['list_item'] ?? 0);
        $this->assertGreaterThan(0, $kinds['paragraph'] ?? 0);
        $headings = array_values(array_filter($blocks, static fn (array $b): bool => $b['kind'] === 'heading'));
        $this->assertStringContainsString('Pourquoi ce questionnaire existe', $headings[0]['text']);
        $this->assertSame(1, $headings[0]['level']);
        $level2 = array_values(array_filter($headings, static fn (array $b): bool => $b['level'] === 2));
        $this->assertNotEmpty($level2);
        $this->assertStringContainsString('En-tête enquêteur', $level2[0]['text']);
    }

    public function test_rejects_non_docx_file(): void
    {
        $path = $this->tempPath('docx');
        file_put_contents($path, "Ceci n'est pas un document Word.");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('.docx');
        (new DocxTextExtractor)->extract($path);
    }

    public function test_rejects_zip_without_document_xml(): void
    {
        $path = $this->tempPath('docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('hello.txt', 'bonjour');
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('word/document.xml');
        (new DocxTextExtractor)->extract($path);
    }

    public function test_rejects_missing_file(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DocxTextExtractor)->extract(sys_get_temp_dir().DIRECTORY_SEPARATOR.'absent_'.bin2hex(random_bytes(4)).'.docx');
    }

    public function test_rejects_file_over_ten_megabytes(): void
    {
        $path = $this->tempPath('docx');
        $handle = fopen($path, 'wb');
        fseek($handle, DocxTextExtractor::MAX_BYTES + 1);
        fwrite($handle, 'x');
        fclose($handle);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('10 Mo');
        (new DocxTextExtractor)->extract($path);
    }

    public function test_extracts_synthetic_docx_with_table_list_checkbox_and_breaks(): void
    {
        $path = $this->tempPath('docx');
        $this->writeSyntheticDocx($path);
        $extractor = new DocxTextExtractor;

        $blocks = $extractor->extractWithStructure($path);
        $this->assertSame([
            ['kind' => 'heading', 'text' => 'Titre principal', 'level' => 1],
            ['kind' => 'heading', 'text' => 'B. Filtre', 'level' => 2],
            ['kind' => 'paragraph', 'text' => "Tom & Jerry\tsuite\nligne 2"],
            ['kind' => 'list_item', 'text' => 'Premier point', 'level' => 1],
            ['kind' => 'list_item', 'text' => 'Sous-point', 'level' => 2],
            ['kind' => 'paragraph', 'text' => '☒ Oui ☐ Non ☐ Symbole'],
            ['kind' => 'table_row', 'text' => 'Question | Réponse'],
            ['kind' => 'table_row', 'text' => 'F1. Enfant scolarisé ? | ☐ Oui / ☐ Non'],
            ['kind' => 'paragraph', 'text' => 'Encadré seul'],
            ['kind' => 'paragraph', 'text' => 'Titre trop profond'],
        ], $blocks);

        $text = $extractor->extract($path);
        $this->assertSame(implode("\n", [
            'Titre principal',
            '',
            'B. Filtre',
            "Tom & Jerry\tsuite",
            'ligne 2',
            '- Premier point',
            '  - Sous-point',
            '☒ Oui ☐ Non ☐ Symbole',
            'Question | Réponse',
            'F1. Enfant scolarisé ? | ☐ Oui / ☐ Non',
            'Encadré seul',
            'Titre trop profond',
        ]), $text);
    }

    private function writeSyntheticDocx(string $path): void
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"';
        $p = static fn (string $inner, string $pPr = ''): string => '<w:p>'.($pPr !== '' ? '<w:pPr>'.$pPr.'</w:pPr>' : '').$inner.'</w:p>';
        $r = static fn (string $text): string => '<w:r><w:t xml:space="preserve">'.$text.'</w:t></w:r>';
        $checkbox = static fn (bool $checked): string => '<w:sdt><w:sdtPr><w14:checkbox><w14:checked w14:val="'.($checked ? '1' : '0').'"/></w14:checkbox></w:sdtPr><w:sdtContent><w:r><w:t>'.($checked ? '☒' : '☐').'</w:t></w:r></w:sdtContent></w:sdt>';
        $cell = static fn (string $inner): string => '<w:tc>'.$inner.'</w:tc>';

        $body = implode('', [
            $p($r('Titre principal'), '<w:pStyle w:val="Titre1"/>'),
            $p($r('B. Filtre'), '<w:pStyle w:val="Heading2"/>'),
            $p($r('Tom &amp; Jerry').'<w:r><w:tab/><w:t>suite</w:t><w:br/><w:t>ligne 2</w:t></w:r>', '<w:tabs><w:tab w:val="left" w:pos="720"/></w:tabs>'),
            $p($r('Premier point'), '<w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>'),
            $p($r('Sous-point'), '<w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr>'),
            $p($checkbox(true).$r(' Oui ').$checkbox(false).$r(' Non ').'<w:r><w:sym w:font="Wingdings" w:char="F0A8"/><w:t xml:space="preserve"> Symbole</w:t></w:r>'),
            '<w:p/>',
            '<w:p><w:r><w:t>   </w:t></w:r></w:p>',
            '<w:tbl>'
                .'<w:tr>'.$cell($p($r('Question'))).$cell($p($r('Réponse'))).'</w:tr>'
                .'<w:tr>'.$cell($p($r('F1. Enfant scolarisé ?'))).$cell($p($r('☐ Oui')).$p($r('☐ Non'))).'</w:tr>'
                .'<w:tr>'.$cell($p($r('Encadré seul'))).'</w:tr>'
                .'</w:tbl>',
            $p($r('Titre trop profond'), '<w:pStyle w:val="Heading4"/>'),
            $p('<w:del><w:r><w:delText>supprimé</w:delText></w:r></w:del>'),
            '<w:sectPr/>',
        ]);
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document '.$w.'><w:body>'.$body.'</w:body></w:document>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles '.$w.'><w:style w:type="paragraph" w:styleId="Titre1"><w:name w:val="heading 1"/></w:style><w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/></w:style></w:styles>';
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $document);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->close();
    }
}
