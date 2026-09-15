<?php

namespace Tests\Unit\Dfs;

use App\Services\Dfs\FicheCodeGenerator;
use PHPUnit\Framework\TestCase;

class FicheCodeGeneratorTest extends TestCase
{
    /** @var array<string, mixed> */
    private const SETTINGS = [
        'pattern' => '{VILLE}-{QUARTIER}-{NN}',
        'sources' => ['VILLE' => 'ville', 'QUARTIER' => 'quartier'],
        'counter' => ['width' => 2, 'scope' => 'enumerator', 'start' => 1],
    ];

    /** @var array<string, array<int, array<string, mixed>>> */
    private const LISTS = [
        'ville' => [
            ['name' => 'douala', 'label' => ['fr' => 'Douala'], 'abbr' => 'DLA'],
            ['name' => 'yaounde', 'label' => ['fr' => 'Yaoundé'], 'abbr' => 'YDE'],
        ],
        'quartier' => [
            ['name' => 'bonamoussadi', 'label' => ['fr' => 'Bonamoussadi'], 'abbr' => 'BMP'],
            ['name' => 'pk14', 'label' => ['fr' => 'PK14']],
            ['name' => 'akwa', 'label' => ['fr' => 'Akwa']],
            ['name' => 'école_élève', 'label' => ['fr' => 'École']],
        ],
    ];

    public function test_abbr_and_padded_counter(): void
    {
        $code = (new FicheCodeGenerator)->render(self::SETTINGS, ['ville' => 'douala', 'quartier' => 'bonamoussadi'], self::LISTS, 7);

        $this->assertSame('DLA-BMP-07', $code);
    }

    public function test_three_letter_rule_without_abbr(): void
    {
        $g = new FicheCodeGenerator;

        $this->assertSame('DLA-PK1-03', $g->render(self::SETTINGS, ['ville' => 'douala', 'quartier' => 'pk14'], self::LISTS, 3));
        $this->assertSame('YDE-AKW-12', $g->render(self::SETTINGS, ['ville' => 'yaounde', 'quartier' => 'akwa'], self::LISTS, 12));
        $this->assertSame('DLA-ECO-01', $g->render(self::SETTINGS, ['ville' => 'douala', 'quartier' => 'école_élève'], self::LISTS, 1), 'NFD sans diacritiques, majuscules, 3 caractères');
    }

    public function test_full_token_and_counter_overflow(): void
    {
        $settings = ['pattern' => '{VILLE:full}/{QUARTIER:full}/{NN}', 'sources' => self::SETTINGS['sources'], 'counter' => ['width' => 2]];
        $code = (new FicheCodeGenerator)->render($settings, ['ville' => 'douala', 'quartier' => 'école_élève'], self::LISTS, 123);

        $this->assertSame('DOUALA/ECOLEELEVE/123', $code, '`:full` ignore `abbr` et ne tronque pas ; le compteur garde ses chiffres au-delà de `width`');
    }

    public function test_text_source_and_defaults(): void
    {
        $settings = ['pattern' => '{ZONE}-{NN}', 'sources' => ['ZONE' => 'zone_libre']];
        $this->assertSame('PAR-005', (new FicheCodeGenerator)->render($settings + ['counter' => ['width' => 3]], ['zone_libre' => 'Paroisse Sainte-Thérèse'], [], 5));
        $this->assertSame('PAR-05', (new FicheCodeGenerator)->render($settings, ['zone_libre' => 'Paroisse'], [], 5), 'largeur 2 par défaut');
    }

    public function test_null_until_sources_and_counter(): void
    {
        $g = new FicheCodeGenerator;

        $this->assertNull($g->render(self::SETTINGS, ['ville' => 'douala'], self::LISTS, 7), 'source manquante');
        $this->assertNull($g->render(self::SETTINGS, ['ville' => 'douala', 'quartier' => ''], self::LISTS, 7), 'source vide');
        $this->assertNull($g->render(self::SETTINGS, ['ville' => 'douala', 'quartier' => 'akwa'], self::LISTS, null), 'compteur non alloué');
        $this->assertNull($g->render(['pattern' => '{X}', 'sources' => []], ['x' => 1], [], 1), 'jeton sans source');
        $this->assertSame('DLA-AKW', $g->render(['pattern' => '{VILLE}-{QUARTIER}', 'sources' => self::SETTINGS['sources']], ['ville' => 'douala', 'quartier' => 'akwa'], self::LISTS, null), 'sans {NN}, le compteur est inutile');
    }

    public function test_next_suffix(): void
    {
        $g = new FicheCodeGenerator;

        $this->assertSame('DLA-BMP-07', $g->nextSuffix('DLA-BMP-07', ['DLA-BMP-06']));
        $this->assertSame('DLA-BMP-07-B', $g->nextSuffix('DLA-BMP-07', ['DLA-BMP-07']));
        $this->assertSame('DLA-BMP-07-C', $g->nextSuffix('DLA-BMP-07', ['DLA-BMP-07', 'DLA-BMP-07-B']));
        $this->assertSame('DLA-BMP-07-B', $g->nextSuffix('DLA-BMP-07', ['DLA-BMP-07', 'DLA-BMP-07-C']), 'première lettre libre');

        $taken = ['X'];
        for ($i = 1; $i <= 25; $i++) {
            $taken[] = $g->nextSuffix('X', $taken);
        }
        $this->assertSame('X-Z', end($taken));
        $this->assertSame('X-AA', $g->nextSuffix('X', $taken));
    }

    public function test_normalize(): void
    {
        $this->assertSame('YAOUNDE', FicheCodeGenerator::normalize('Yaoundé'));
        $this->assertSame('PK14', FicheCodeGenerator::normalize('pk-14'));
        $this->assertSame('', FicheCodeGenerator::normalize('---'));
    }
}
