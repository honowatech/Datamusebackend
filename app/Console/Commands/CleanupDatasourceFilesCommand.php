<?php

namespace App\Console\Commands;

use App\Models\SurveyDatasource;
use App\Services\Survey\SurveyMaterializationService;
use Illuminate\Console\Command;

/**
 * B-09b — tâche planifiée quotidienne (plan § 5.4).
 *
 * La matérialisation bascule par **nom de fichier versionné** (`survey_{id}_v{n}.sqlite`) : sous
 * Windows, l'ancien fichier peut rester verrouillé au moment de la bascule et n'être supprimé qu'au
 * cycle suivant. Cette commande balaie les versions antérieures (au-delà de
 * `survey.datasource_keep_versions`) ainsi que les `.tmp` abandonnés par un rebuild interrompu.
 */
class CleanupDatasourceFilesCommand extends Command
{
    protected $signature = 'datasources:cleanup-old-files
                            {--keep= : Versions antérieures conservées (défaut : survey.datasource_keep_versions)}
                            {--dry-run : Lister les fichiers sans les supprimer}';

    protected $description = 'Supprime les anciens fichiers SQLite des sources de données matérialisées';

    public function handle(SurveyMaterializationService $materialization): int
    {
        $keep = $this->option('keep') !== null
            ? max(0, (int) $this->option('keep'))
            : max(0, (int) config('survey.datasource_keep_versions', 0));
        $dryRun = (bool) $this->option('dry-run');

        $deleted = 0;
        $bytes = 0;

        foreach (SurveyDatasource::query()->with('survey.project')->cursor() as $datasource) {
            $survey = $datasource->survey;
            if ($survey === null) {
                continue;
            }

            $current = (int) $datasource->file_version;
            $dir = dirname($materialization->filePathFor($survey, $current));
            $pattern = $dir.DIRECTORY_SEPARATOR.sprintf('survey_%d_v*.sqlite*', $survey->id);

            foreach (glob($pattern) ?: [] as $path) {
                if (! preg_match('/_v(\d+)\.sqlite(\.tmp|-wal|-shm)?$/', $path, $m)) {
                    continue;
                }
                $version = (int) $m[1];
                $suffix = $m[2] ?? '';

                // Le fichier courant et ses compagnons sont conservés ; les `.tmp` sont toujours obsolètes.
                if ($suffix !== '.tmp' && $version > $current - 1 - $keep) {
                    continue;
                }

                $size = (int) @filesize($path);
                $this->line(($dryRun ? '[sec] ' : '').$path);
                if ($dryRun || @unlink($path)) {
                    $deleted++;
                    $bytes += $size;
                }
            }
        }

        $this->info(sprintf(
            '%d fichier(s) %s (%s).',
            $deleted,
            $dryRun ? 'à supprimer' : 'supprimé(s)',
            $bytes > 0 ? round($bytes / 1024 / 1024, 2).' Mo' : '0 Mo',
        ));

        return self::SUCCESS;
    }
}
