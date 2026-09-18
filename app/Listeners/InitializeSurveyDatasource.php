<?php

namespace App\Listeners;

use App\Events\SurveyPublished;
use App\Jobs\MaterializeSurveyDatasourceJob;

/**
 * B-09b — la publication d'une version crée la `survey_datasources` du questionnaire (et, via le job,
 * la `TargetDatabase` « Enquête : {titre} ») puis lance une première matérialisation : la structure
 * des colonnes suit la nouvelle version, même sans soumission.
 */
class InitializeSurveyDatasource
{
    public function handle(SurveyPublished $event): void
    {
        MaterializeSurveyDatasourceJob::refresh($event->survey->id, create: true);
    }
}
