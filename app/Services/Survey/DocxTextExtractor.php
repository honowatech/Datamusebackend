<?php

namespace App\Services\Survey;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Extraction du texte d'un document Word (.docx) sans dépendance externe : lecture de
 * `word/document.xml` via `ZipArchive` + DOM.
 *
 * - `extract()` renvoie un texte brut : un paragraphe par ligne, cellules d'un tableau séparées
 *   par ` | `, `w:tab` → tabulation, `w:br` → saut de ligne, cases à cocher (`☐`/`☒`, contrôles
 *   `w14:checkbox`, symboles Wingdings) conservées comme `☐`/`☒`, listes numérotées ou à puces
 *   (`w:numPr`) préfixées par `- `, entités décodées, lignes vides compressées.
 * - `extractWithStructure()` renvoie les blocs `{kind: heading|paragraph|table_row|list_item, text, level?}`
 *   d'après `w:pStyle` (Heading1-3 / Titre1-3 / Title) et `w:outlineLvl`.
 *
 * Limites : 10 Mo (fichier et `document.xml`) ; `InvalidArgumentException` si le fichier n'est pas
 * un .docx lisible, `RuntimeException` s'il dépasse la limite.
 */
final class DocxTextExtractor
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const NS_W14 = 'http://schemas.microsoft.com/office/word/2010/wordml';

    /** Caractères Wingdings (octet bas des symboles / zone privée U+F0xx) → case à cocher. */
    private const SYMBOLS = [
        0x6F => '☐', 0x70 => '☐', 0x71 => '☐', 0xA8 => '☐', 0xA6 => '☐', 0xA7 => '☐', 0x6E => '☐',
        0xFE => '☒', 0xFD => '☒', 0xFB => '☒', 0xFC => '✓', 0x52 => '☒',
    ];

    /** @var array<string, string> styleId → nom de style (minuscules) */
    private array $styleNames = [];

    public function extract(string $path): string
    {
        $lines = [];
        $previousKind = null;
        foreach ($this->extractWithStructure($path) as $block) {
            if ($block['kind'] === 'heading' && $previousKind !== null) {
                $lines[] = '';
            }
            $lines[] = $block['kind'] === 'list_item' ? str_repeat('  ', max(0, ($block['level'] ?? 1) - 1)).'- '.$block['text'] : $block['text'];
            $previousKind = $block['kind'];
        }

        return self::compressBlankLines(implode("\n", $lines));
    }

    /**
     * @return array<int, array{kind: string, text: string, level?: int}>
     */
    public function extractWithStructure(string $path): array
    {
        $dom = $this->load($path);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS_W);
        $xp->registerNamespace('w14', self::NS_W14);

        $body = $xp->query('/w:document/w:body')->item(0);
        if (! $body instanceof DOMElement) {
            throw new InvalidArgumentException("Le document Word « {$path} » ne contient pas de corps (w:body).");
        }

        $blocks = [];
        $this->blocks($body, $xp, $blocks);

        return $blocks;
    }

    // ------------------------------------------------------------------ chargement

    private function load(string $path): DOMDocument
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Fichier introuvable : {$path}.");
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new RuntimeException('Le fichier dépasse la taille maximale de 10 Mo.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('Le fichier n\'est pas un document Word (.docx) : archive illisible.');
        }
        try {
            $stat = $zip->statName('word/document.xml');
            if ($stat === false) {
                throw new InvalidArgumentException('Le fichier n\'est pas un document Word (.docx) : word/document.xml absent.');
            }
            if (($stat['size'] ?? 0) > self::MAX_BYTES) {
                throw new RuntimeException('Le contenu du document dépasse la taille maximale de 10 Mo.');
            }
            $xml = $zip->getFromName('word/document.xml');
            $styles = $zip->getFromName('word/styles.xml');
        } finally {
            $zip->close();
        }
        if (! is_string($xml) || $xml === '') {
            throw new InvalidArgumentException('Le fichier n\'est pas un document Word (.docx) : word/document.xml vide.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR);
            if (! $loaded) {
                throw new InvalidArgumentException('Le fichier n\'est pas un document Word (.docx) : XML invalide.');
            }
            $this->styleNames = is_string($styles) && $styles !== '' ? self::parseStyles($styles) : [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }

    /**
     * @return array<string, string>
     */
    private static function parseStyles(string $xml): array
    {
        $dom = new DOMDocument;
        if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            return [];
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS_W);
        $names = [];
        foreach ($xp->query('//w:style[@w:styleId]') as $style) {
            if (! $style instanceof DOMElement) {
                continue;
            }
            $name = $xp->query('./w:name/@w:val', $style)->item(0);
            $names[$style->getAttributeNS(self::NS_W, 'styleId')] = strtolower(trim($name?->nodeValue ?? ''));
        }

        return $names;
    }

    // ------------------------------------------------------------------ blocs

    /**
     * @param  array<int, array{kind: string, text: string, level?: int}>  $blocks
     */
    private function blocks(DOMElement $container, DOMXPath $xp, array &$blocks): void
    {
        foreach ($container->childNodes as $child) {
            if (! $child instanceof DOMElement || $child->namespaceURI !== self::NS_W) {
                continue;
            }
            switch ($child->localName) {
                case 'p':
                    $block = $this->paragraphBlock($child, $xp);
                    if ($block !== null) {
                        $blocks[] = $block;
                    }
                    break;
                case 'tbl':
                    $this->tableBlocks($child, $xp, $blocks);
                    break;
                case 'sdt':
                    $content = $xp->query('./w:sdtContent', $child)->item(0);
                    if ($content instanceof DOMElement) {
                        $this->blocks($content, $xp, $blocks);
                    }
                    break;
                default:
                    // sectPr, bookmarks, proofErr… : ignorés.
                    break;
            }
        }
    }

    /**
     * @param  array<int, array{kind: string, text: string, level?: int}>  $blocks
     */
    private function tableBlocks(DOMElement $table, DOMXPath $xp, array &$blocks): void
    {
        foreach ($xp->query('./w:tr | ./w:sdt/w:sdtContent/w:tr', $table) as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }
            $cells = $xp->query('./w:tc | ./w:sdt/w:sdtContent/w:tc', $row);
            if ($cells->length === 1) {
                // Cellule unique (encadré) : chaque paragraphe reste un bloc à part entière.
                $cell = $cells->item(0);
                if ($cell instanceof DOMElement) {
                    $this->blocks($cell, $xp, $blocks);
                }

                continue;
            }
            $texts = [];
            foreach ($cells as $cell) {
                if ($cell instanceof DOMElement) {
                    $texts[] = $this->cellText($cell, $xp);
                }
            }
            if (array_filter($texts, static fn (string $t): bool => $t !== '') === []) {
                continue;
            }
            $blocks[] = ['kind' => 'table_row', 'text' => implode(' | ', $texts)];
        }
    }

    private function cellText(DOMElement $cell, DOMXPath $xp): string
    {
        $inner = [];
        $this->blocks($cell, $xp, $inner);
        $parts = [];
        foreach ($inner as $block) {
            $text = str_replace("\n", ' ', $block['text']);
            if ($block['kind'] === 'list_item') {
                $text = '- '.$text;
            }
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(' / ', $parts);
    }

    /**
     * @return array{kind: string, text: string, level?: int}|null
     */
    private function paragraphBlock(DOMElement $p, DOMXPath $xp): ?array
    {
        $text = '';
        $this->runs($p, $xp, $text);
        $text = self::clean($text);
        if ($text === '') {
            return null;
        }

        $headingLevel = $this->headingLevel($p, $xp);
        if ($headingLevel !== null && $headingLevel <= 3) {
            return ['kind' => 'heading', 'text' => $text, 'level' => $headingLevel];
        }
        $numPr = $xp->query('./w:pPr/w:numPr', $p)->item(0);
        if ($numPr instanceof DOMElement) {
            $ilvl = $xp->query('./w:ilvl/@w:val', $numPr)->item(0);
            $level = $ilvl !== null && is_numeric($ilvl->nodeValue) ? (int) $ilvl->nodeValue + 1 : 1;

            return ['kind' => 'list_item', 'text' => $text, 'level' => max(1, $level)];
        }

        return ['kind' => 'paragraph', 'text' => $text];
    }

    private function headingLevel(DOMElement $p, DOMXPath $xp): ?int
    {
        $style = $xp->query('./w:pPr/w:pStyle/@w:val', $p)->item(0);
        if ($style !== null) {
            $id = (string) $style->nodeValue;
            $candidates = [$id, $this->styleNames[$id] ?? ''];
            foreach ($candidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                if (preg_match('/^(?:heading|titre|title)\s*(\d)$/i', $candidate, $m) === 1) {
                    return max(1, (int) $m[1]);
                }
                if (preg_match('/^(?:title|titre)$/i', $candidate) === 1) {
                    return 1;
                }
            }
        }
        $outline = $xp->query('./w:pPr/w:outlineLvl/@w:val', $p)->item(0);
        if ($outline !== null && is_numeric($outline->nodeValue)) {
            return (int) $outline->nodeValue + 1;
        }

        return null;
    }

    // ------------------------------------------------------------------ texte des runs

    private function runs(DOMNode $node, DOMXPath $xp, string &$out): void
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $ns = $child->namespaceURI;
            $name = $child->localName;
            if ($ns === self::NS_W) {
                switch ($name) {
                    case 't':
                        $out .= self::mapPrivateUse($child->textContent);

                        continue 2;
                    case 'tab':
                        $out .= "\t";

                        continue 2;
                    case 'br':
                    case 'cr':
                        $out .= "\n";

                        continue 2;
                    case 'sym':
                        $out .= self::symbol($child->getAttributeNS(self::NS_W, 'char'));

                        continue 2;
                    case 'noBreakHyphen':
                        $out .= '-';

                        continue 2;
                    case 'sdt':
                        $checkbox = $xp->query('./w:sdtPr/w14:checkbox', $child)->item(0);
                        if ($checkbox instanceof DOMElement) {
                            $checked = $xp->query('./w14:checked/@w14:val', $checkbox)->item(0);
                            $out .= $checked !== null && in_array($checked->nodeValue, ['1', 'true'], true) ? '☒' : '☐';

                            continue 2;
                        }
                        $content = $xp->query('./w:sdtContent', $child)->item(0);
                        if ($content instanceof DOMElement) {
                            $this->runs($content, $xp, $out);
                        }

                        continue 2;
                    case 'pPr':
                    case 'rPr':
                    case 'del':
                    case 'delText':
                    case 'delInstrText':
                    case 'instrText':
                    case 'footnoteReference':
                    case 'endnoteReference':
                    case 'commentReference':
                    case 'drawing':
                    case 'pict':
                    case 'object':
                        continue 2;
                }
            }
            $this->runs($child, $xp, $out);
        }
    }

    private static function symbol(string $hex): string
    {
        $code = hexdec(substr(trim($hex), -2));

        return self::SYMBOLS[$code] ?? '☐';
    }

    private static function mapPrivateUse(string $text): string
    {
        if (preg_match('/[\x{F000}-\x{F0FF}]/u', $text) !== 1) {
            return $text;
        }

        return (string) preg_replace_callback('/[\x{F000}-\x{F0FF}]/u', static function (array $m): string {
            $code = mb_ord($m[0], 'UTF-8') & 0xFF;

            return self::SYMBOLS[$code] ?? '';
        }, $text);
    }

    private static function clean(string $text): string
    {
        $lines = array_map(static fn (string $l): string => rtrim($l, " \t\r"), explode("\n", $text));

        return trim(implode("\n", $lines), "\n");
    }

    private static function compressBlankLines(string $text): string
    {
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text, "\n");
    }
}
