<?php

namespace App\Listeners;

use App\Events\SubmissionReceived;
use App\Jobs\MaterializeSurveyDatasourceJob;

/**
 * B-09b — une soumission reçue rend la source de données obsolète : `survey_datasources.dirty` passe
 * à vrai et un `MaterializeSurveyDatasourceJob` (unique par questionnaire, différé de 30 s) est mis
 * en file, ce qui regroupe naturellement les rafales de synchronisation d'un lot.
 *
 * Un questionnaire sans `survey_datasources` (jamais publié via l'API, jamais reconstruit) est
 * ignoré : la première matérialisation vient de `SurveyPublished` ou du rebuild manuel.
 */
class MarkDatasourceDirty
{
    public function handle(SubmissionReceived $event): void
    {
        MaterializeSurveyDatasourceJob::refresh($event->submission->survey_id);
    }
}
