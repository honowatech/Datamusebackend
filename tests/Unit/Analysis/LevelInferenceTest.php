<?php

namespace Tests\Unit\Analysis;

use App\Services\Analysis\LevelInference;
use PDO;
use PHPUnit\Framework\TestCase;

class LevelInferenceTest extends TestCase
{
    public function test_levels_are_inferred_from_cardinality_type_and_date_patterns(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE "ventes" ("region" TEXT, "montant" TEXT, "note" TEXT, "actif" TEXT, "date_iso" TEXT, "date_fr" TEXT, "telephone" TEXT, "commentaire" TEXT, "vide" TEXT)');
        $insert = $pdo->prepare('INSERT INTO "ventes" VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($i = 0; $i < 60; $i++) {
            $insert->execute([
                ['Nord', 'Sud', 'Est'][$i % 3],
                (string) (1000 + $i * 37.5),
                (string) (1 + $i % 5),
                (string) ($i % 2),
                sprintf('2026-%02d-%02d', 1 + $i % 12, 1 + $i % 28),
                sprintf('%02d/%02d/2026', 1 + $i % 28, 1 + $i % 12),
                sprintf('06%08d', $i * 7919),
                'Remarque libre numéro '.$i.' sur la visite',
                $i % 4 === 0 ? '' : null,
            ]);
        }

        $levels = [];
        foreach (['region', 'montant', 'note', 'actif', 'date_iso', 'date_fr', 'telephone', 'commentaire', 'vide'] as $column) {
            $levels[$column] = LevelInference::infer($pdo, 'ventes', $column);
        }

        $this->assertSame('nominal', $levels['region']['level']);
        $this->assertEqualsCanonicalizing(['Nord', 'Sud', 'Est'], $levels['region']['values']);
        $this->assertSame('numeric', $levels['montant']['level']);
        $this->assertSame([], $levels['montant']['values']);
        $this->assertSame('ordinal', $levels['note']['level']);
        $this->assertSame(['1', '2', '3', '4', '5'], $levels['note']['values']);
        $this->assertSame('nominal', $levels['actif']['level'], 'une indicatrice 0/1 est une variable nominale');
        $this->assertSame('date', $levels['date_iso']['level']);
        $this->assertSame('date', $levels['date_fr']['level']);
        $this->assertSame('text', $levels['telephone']['level'], 'un identifiant à zéro initial n\'est pas un nombre');
        $this->assertSame('text', $levels['commentaire']['level']);
        $this->assertSame('text', $levels['vide']['level']);
        $this->assertSame(0, $levels['vide']['non_null']);
    }
}
