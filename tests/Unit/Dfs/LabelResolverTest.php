<?php

namespace Tests\Unit\Dfs;

use App\Services\Dfs\LabelResolver;
use PHPUnit\Framework\TestCase;

class LabelResolverTest extends TestCase
{
    public function test_fallback_chain(): void
    {
        $label = ['fr' => 'Ville', 'en' => 'City'];

        $this->assertSame('City', LabelResolver::t($label, 'en', 'fr'));
        $this->assertSame('Ville', LabelResolver::t($label, 'de', 'fr'), 'langue absente → default_language');
        $this->assertSame('Ville', LabelResolver::t(['fr' => 'Ville', 'en' => ''], 'en', 'fr'), 'traduction vide → repli');
        $this->assertSame('Stadt', LabelResolver::t(['de' => 'Stadt'], 'en', 'fr'), 'ni L ni défaut → première valeur non vide');
        $this->assertSame('ville', LabelResolver::t(['de' => ''], 'en', 'fr', [], 'ville'), 'aucune valeur → clé (label)');
        $this->assertSame('', LabelResolver::t([], 'en', 'fr'), 'aucune valeur → chaîne vide (autres textes)');
        $this->assertSame('brut', LabelResolver::t('brut', 'en', 'fr'));
        $this->assertSame('City', LabelResolver::t((object) ['fr' => 'Ville', 'en' => 'City'], 'en', 'fr'));
    }

    public function test_interpolation(): void
    {
        $label = ['fr' => 'Retrait à ${point_retrait} avant le ${date_limite} (fiche ${_seq}, ${inconnu}).'];
        $answers = ['point_retrait' => 'Bureau Honowa, Bonanjo', 'date_limite' => '2026-09-22', '_seq' => 7];

        $this->assertSame(
            'Retrait à Bureau Honowa, Bonanjo avant le 2026-09-22 (fiche 7, ).',
            LabelResolver::t($label, 'fr', 'fr', $answers),
        );
        $this->assertSame('Total : 4000', LabelResolver::interpolate('Total : ${montant}', ['montant' => 4000]));
        $this->assertSame('Total : 2.5', LabelResolver::interpolate('Total : ${montant}', ['montant' => 2.5]));
        $this->assertSame('a, b', LabelResolver::interpolate('${liste}', ['liste' => 'a, b']));
        $this->assertSame('', LabelResolver::interpolate('${vide}', ['vide' => null]));
        $this->assertSame('sans accolades', LabelResolver::interpolate('sans accolades', ['x' => 1]));
    }

    public function test_placeholders(): void
    {
        $this->assertSame(['a', '_seq', 'b'], LabelResolver::placeholders(['fr' => '${a} ${_seq}', 'en' => '${a} ${b}']));
        $this->assertSame([], LabelResolver::placeholders('rien'));
    }
}
