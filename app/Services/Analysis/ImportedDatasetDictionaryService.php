<?php

namespace App\Services\Analysis;

use App\Models\TargetDatabase;
use PDO;

/**
 * Dictionnaire de variables d'un jeu de données importé (Excel/CSV → SQLite par utilisateur).
 *
 * Aucun schéma ne décrit ces fichiers : le niveau de mesure de chaque colonne est inféré par profilage
 * (`LevelInference`). Même forme que le dictionnaire d'enquête ; `dfs_type` vaut toujours `null` et
 * `label` reprend le nom de colonne.
 */
class ImportedDatasetDictionaryService
{
    /** Au-delà, le profilage colonne par colonne coûterait trop cher pour une requête synchrone. */
    public const MAX_COLUMNS = 400;

    /**
     * @return array{dataset: array<string, mixed>, variables: list<array<string, mixed>>}
     */
    public function dictionary(TargetDatabase $database, string $path): array
    {
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY rowid")->fetchAll(PDO::FETCH_COLUMN);
        $prefixed = count($tables) > 1;

        $variables = [];
        $tableRows = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            $quoted = LevelInference::quote($table);
            $tableRows[] = ['name' => $table, 'rows' => (int) $pdo->query("SELECT COUNT(*) FROM $quoted")->fetchColumn()];

            foreach ($pdo->query("PRAGMA table_info($quoted)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if (count($variables) >= self::MAX_COLUMNS) {
                    break 2;
                }
                $name = (string) $column['name'];
                $profile = LevelInference::infer($pdo, $table, $name);
                $variables[] = [
                    'name' => $prefixed ? $table.'.'.$name : $name,
                    'label' => $name,
                    'level' => $profile['level'],
                    'dfs_type' => null,
                    'table' => $table,
                    'column' => $name,
                    'choices' => array_map(fn (string $v) => ['code' => $v, 'label' => $v], $profile['values']),
                    'multiple' => false,
                ];
            }
        }

        $mtime = (int) @filemtime($path);

        return [
            'dataset' => [
                'survey_id' => null,
                'target_database_id' => $database->id,
                'name' => $database->name,
                'rows' => $tableRows === [] ? 0 : max(array_column($tableRows, 'rows')),
                'materialized_at' => $mtime > 0 ? date(DATE_ATOM, $mtime) : null,
                'etag' => substr(sha1(implode('|', ['datamuse.import', $database->id, $mtime, (int) @filesize($path)])), 0, 32),
                'tables' => $tableRows,
            ],
            'variables' => $variables,
        ];
    }
}
