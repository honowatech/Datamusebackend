<?php

namespace App\Services\Survey;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Description d'un fichier SQLite au format produit par `FileIngestionService::ingestFile()['schema']`
 * (`{tables, schema_details, table_counts}`), pour que le frontend l'affiche sans changement :
 *
 *   schema_details[table] = [{name, type, nullable, sample_values}] ; `type` en minuscules ;
 *   `sample_values` = jusqu'à 8 valeurs distinctes non nulles pour les colonnes text/varchar.
 */
class SqliteSchemaDescriber
{
    public const SAMPLE_LIMIT = 8;

    /**
     * @return array{tables: list<string>, schema_details: array<string, list<array{name: string, type: string, nullable: bool, sample_values: list<mixed>}>>, table_counts: array<string, int>}
     */
    public function describe(string $sqlitePath): array
    {
        if (! is_file($sqlitePath)) {
            throw new RuntimeException("Fichier SQLite introuvable : {$sqlitePath}");
        }

        $pdo = new PDO('sqlite:'.$sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA query_only = 1;');

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY rowid")
            ->fetchAll(PDO::FETCH_COLUMN);

        $schemaDetails = [];
        $tableCounts = [];

        foreach ($tables as $table) {
            $quoted = '"'.str_replace('"', '""', $table).'"';
            $columnsInfo = [];

            foreach ($pdo->query("PRAGMA table_info({$quoted})")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $type = strtolower((string) ($col['type'] ?? ''));
                $columnsInfo[] = [
                    'name' => (string) $col['name'],
                    'type' => $type === '' ? 'text' : $type,
                    'nullable' => (int) ($col['notnull'] ?? 0) === 0 && (int) ($col['pk'] ?? 0) === 0,
                    'sample_values' => [],
                ];
            }

            foreach ($columnsInfo as &$c) {
                if ($c['type'] === 'text' || str_contains($c['type'], 'char')) {
                    try {
                        $qc = '"'.str_replace('"', '""', $c['name']).'"';
                        $stmt = $pdo->query("SELECT DISTINCT {$qc} FROM {$quoted} WHERE {$qc} IS NOT NULL LIMIT ".self::SAMPLE_LIMIT);
                        $c['sample_values'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Throwable) {
                        // colonne illisible : échantillon vide
                    }
                }
            }
            unset($c);

            $schemaDetails[$table] = $columnsInfo;
            $tableCounts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$quoted}")->fetchColumn();
        }

        return [
            'tables' => array_values($tables),
            'schema_details' => $schemaDetails,
            'table_counts' => $tableCounts,
        ];
    }
}
