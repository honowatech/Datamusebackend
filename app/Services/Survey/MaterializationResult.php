<?php

namespace App\Services\Survey;

/**
 * Résultat d'une matérialisation (plan § 5.2).
 */
final class MaterializationResult
{
    /**
     * @param  list<array{name: string, type: string}>  $columns  colonnes de la table `reponses`, dans l'ordre
     * @param  list<string>  $tables  tables créées dans le fichier SQLite
     * @param  list<string>  $warnings  avertissements non bloquants (ancien fichier non supprimé, clé inconnue…)
     */
    public function __construct(
        public readonly int $targetDatabaseId,
        public readonly string $filePath,
        public readonly int $fileVersion,
        public readonly int $rowCount,
        public readonly array $columns,
        public readonly array $tables,
        public readonly int $durationMs,
        public readonly array $warnings = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target_database_id' => $this->targetDatabaseId,
            'file_path' => $this->filePath,
            'file_version' => $this->fileVersion,
            'row_count' => $this->rowCount,
            'columns' => $this->columns,
            'tables' => $this->tables,
            'duration_ms' => $this->durationMs,
            'warnings' => $this->warnings,
        ];
    }
}
