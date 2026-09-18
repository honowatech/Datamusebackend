<?php

namespace App\Console\Commands;

use App\Jobs\MaterializeSurveyDatasourceJob;
use App\Models\SurveyDatasource;
use App\Services\Survey\SurveyMaterializationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * B-09b — tâche planifiée toutes les 5 minutes (plan § 5.4).
 *
 * Filet de sécurité de la matérialisation événementielle : reconstruit les sources marquées `dirty`
 * (soumission reçue pendant que le worker était arrêté, job en échec, suivi complété…).
 */
class MaterializeDirtyDatasourcesCommand extends Command
{
    protected $signature = 'surveys:materialize-dirty
                            {--sync : Reconstruire dans le processus courant au lieu de mettre en file}
                            {--limit=50 : Nombre maximal de questionnaires traités}';

    protected $description = 'Reconstruit les sources de données des questionnaires marqués « obsolète »';

    public function handle(SurveyMaterializationService $materialization): int
    {
        $datasources = SurveyDatasource::query()
            ->dirty()
            ->with('survey.project')
            ->orderBy('dirty_since')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($datasources->isEmpty()) {
            $this->info('Aucune source de données à reconstruire.');

            return self::SUCCESS;
        }

        $failures = 0;
        foreach ($datasources as $datasource) {
            $survey = $datasource->survey;
            if ($survey === null) {
                continue;
            }

            if (! $this->option('sync')) {
                MaterializeSurveyDatasourceJob::dispatch($survey->id);
                $this->line("Questionnaire #{$survey->id} : reconstruction mise en file.");

                continue;
            }

            try {
                $result = $materialization->materialize($survey);
                $this->line("Questionnaire #{$survey->id} : {$result->rowCount} ligne(s) en {$result->durationMs} ms.");
            } catch (Throwable $e) {
                $failures++;
                $this->error("Questionnaire #{$survey->id} : ".$e->getMessage());
            }
        }

        $this->info(sprintf('%d source(s) traitée(s), %d échec(s).', $datasources->count(), $failures));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
