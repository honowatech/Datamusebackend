<?php

namespace Tests\Unit\Survey;

use App\Services\Survey\SqliteSchemaDescriber;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SqliteSchemaDescriberTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'dm_describe_').'.sqlite';
        $pdo = new PDO('sqlite:'.$this->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE "reponses" ("submission_id" INTEGER PRIMARY KEY, "statut" TEXT, "age" INTEGER, "lat" REAL, "libre" VARCHAR(20), "flou")');
        $pdo->exec('CREATE TABLE "_meta" ("cle" TEXT PRIMARY KEY, "valeur" TEXT)');
        $stmt = $pdo->prepare('INSERT INTO "reponses" VALUES (?, ?, ?, ?, ?, ?)');
        for ($i = 1; $i <= 12; $i++) {
            $stmt->execute([$i, $i % 2 ? 'submitted' : 'validated', 20 + $i, 4.0 + $i / 100, 'v'.$i, null]);
        }
        $pdo->exec("INSERT INTO \"_meta\" VALUES ('row_count', '12')");
        $pdo = null;
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink(substr($this->path, 0, -7));
        parent::tearDown();
    }

    public function test_describe_matches_file_ingestion_schema_structure(): void
    {
        $schema = (new SqliteSchemaDescriber)->describe($this->path);

        $this->assertSame(['tables', 'schema_details', 'table_counts'], array_keys($schema));
        $this->assertSame(['reponses', '_meta'], $schema['tables'], 'ordre de création, sans sqlite_sequence');
        $this->assertSame(['reponses' => 12, '_meta' => 1], $schema['table_counts']);

        $columns = $schema['schema_details']['reponses'];
        $this->assertSame(['submission_id', 'statut', 'age', 'lat', 'libre', 'flou'], array_column($columns, 'name'));
        $this->assertSame(['integer', 'text', 'integer', 'real', 'varchar(20)', 'text'], array_column($columns, 'type'));
        foreach ($columns as $column) {
            $this->assertSame(['name', 'type', 'nullable', 'sample_values'], array_keys($column));
            $this->assertIsBool($column['nullable']);
            $this->assertIsArray($column['sample_values']);
        }
        $this->assertFalse($columns[0]['nullable'], 'clé primaire');
        $this->assertTrue($columns[1]['nullable']);

        $statut = $columns[1];
        $this->assertEqualsCanonicalizing(['submitted', 'validated'], $statut['sample_values'], 'valeurs distinctes non nulles pour les colonnes text');
        $this->assertSame([], $columns[2]['sample_values'], 'pas d\'échantillon pour les colonnes numériques');
        $this->assertSame([], $columns[3]['sample_values']);
        $this->assertCount(8, $columns[4]['sample_values'], 'échantillon limité à 8 valeurs (varchar traité comme text)');
        $this->assertSame([], $columns[5]['sample_values'], 'colonne sans type déclaré → text, aucune valeur non nulle');

        $this->assertSame(['row_count'], $schema['schema_details']['_meta'][0]['sample_values']);
    }

    public function test_describe_rejects_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        (new SqliteSchemaDescriber)->describe($this->path.'.missing');
    }
}
