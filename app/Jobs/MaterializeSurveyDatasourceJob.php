<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Services\Survey\SurveyMaterializationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * B-09b — reconstruction de la source de données SQLite d'un questionnaire (plan § 5.2).
 *
 * Déclenché par `SurveyPublished` (création initiale), par `SubmissionReceived` (datasource `dirty`),
 * par la commande `surveys:materialize-dirty` (toutes les 5 min) et par
 * `POST /surveys/{id}/datasource/rebuild`.
 *
 * `ShouldBeUnique` **par questionnaire** : une rafale de synchronisation ne met qu'un seul rebuild en
 * file. Le délai de 30 s laisse arriver les soumissions du même lot. Une erreur est capturée,
 * journalisée et écrite dans `survey_datasources.last_error` (plus l'`AiJob` quand la reconstruction
 * a été demandée manuellement) : le job n'est pas relancé indéfiniment.
 */
class MaterializeSurveyDatasourceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    /** Le verrou d'unicité est libéré au plus tard après cette durée (délai + traitement). */
    public int $uniqueFor = 900;

    /**
     * @param  int  $surveyId  questionnaire à matérialiser
     * @param  string|null  $jobUuid  `ai_jobs.uuid` d'un rebuild manuel (suivi via `GET /jobs/{id}`)
     */
    public function __construct(
        public readonly int $surveyId,
        public readonly ?string $jobUuid = null,
    ) {
        $this->delay((int) config('survey.materialize_delay_seconds', 30));
    }

    /** Un seul rebuild en file par questionnaire (le `job_id` manuel ne fait pas partie de la clé). */
    public function uniqueId(): string
    {
        return 'survey-datasource:'.$this->surveyId;
    }

    /**
     * Marque la datasource `dirty` et met un rebuild en file.
     *
     * Sans `$create`, un questionnaire qui n'a pas encore de `survey_datasources` (jamais publié via
     * l'API, jamais reconstruit) est ignoré : la matérialisation reste explicite.
     */
    public static function refresh(int $surveyId, bool $create = false): void
    {
        $datasource = $create
            ? SurveyDatasource::query()->firstOrCreate(['survey_id' => $surveyId])
            : SurveyDatasource::query()->where('survey_id', $surveyId)->first();

        if ($datasource === null) {
            return;
        }

        $datasource->markDirty();
        self::dispatch($surveyId);
    }

    public function handle(SurveyMaterializationService $materialization): void
    {
        $aiJob = $this->jobUuid !== null ? AiJob::query()->where('uuid', $this->jobUuid)->first() : null;

        $survey = Survey::query()->with('project')->find($this->surveyId);
        if ($survey === null) {
            $aiJob?->markFailed('Questionnaire introuvable.', 'Reconstruction abandonnée.');

            return;
        }

        $aiJob?->markRunning('Reconstruction de la source de données…');

        try {
            $result = $materialization->materialize($survey);
        } catch (Throwable $e) {
            $this->recordFailure($e, $aiJob);

            return;
        }

        $aiJob?->markDone(
            'datasources/'.$survey->id,
            sprintf('Source de données reconstruite (%d ligne%s, %d table%s).',
                $result->rowCount, $result->rowCount > 1 ? 's' : '',
                count($result->tables), count($result->tables) > 1 ? 's' : ''),
            $result->toArray(),
        );
    }

    /**
     * Échec au niveau de la file (timeout, worker tué) après épuisement des tentatives.
     */
    public function failed(?Throwable $e): void
    {
        $this->recordFailure($e ?? new \RuntimeException('Reconstruction interrompue.'));
    }

    /**
     * L'exception est **capturée** : `survey_datasources.last_error` porte le diagnostic et la
     * datasource reste `dirty` (la commande planifiée réessaiera).
     */
    private function recordFailure(Throwable $e, ?AiJob $aiJob = null): void
    {
        $message = trim($e->getMessage()) !== '' ? $e->getMessage() : 'La reconstruction a échoué.';
        Log::warning('MaterializeSurveyDatasourceJob: '.$message, ['survey_id' => $this->surveyId, 'exception' => $e::class]);

        SurveyDatasource::query()
            ->firstOrCreate(['survey_id' => $this->surveyId])
            ->forceFill(['last_error' => mb_substr($message, 0, 2000)])
            ->save();

        $aiJob ??= $this->jobUuid !== null ? AiJob::query()->where('uuid', $this->jobUuid)->first() : null;
        if ($aiJob !== null && ! $aiJob->isTerminal()) {
            $aiJob->markFailed($message, 'Reconstruction interrompue.');
        }
    }
}
