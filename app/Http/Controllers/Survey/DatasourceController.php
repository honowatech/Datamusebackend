<?php

namespace App\Http\Controllers\Survey;

use App\Enums\JobKind;
use App\Http\Controllers\ApiController;
use App\Jobs\MaterializeSurveyDatasourceJob;
use App\Models\AiJob;
use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\TargetDatabase;
use App\Services\Survey\SqliteSchemaDescriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Source de données matérialisée d'un questionnaire (B-09b, contrat : tag Datasource).
 *
 * `GET /surveys/{id}/datasource` — état (`empty|building|ready|error`), `target_database_id` à passer
 * à `POST /target-db/connect` pour « Ouvrir dans le chat ». `404` tant que le questionnaire n'a jamais
 * été publié.
 *
 * `POST /surveys/{id}/datasource/rebuild` — `202 {job_id}` ; le job est `ShouldBeUnique` par
 * questionnaire, donc une reconstruction déjà en file renvoie simplement son `job_id`.
 * `?sync=1` exécute la reconstruction dans la requête (tests et environnement local sans worker).
 */
class DatasourceController extends ApiController
{
    public function __construct(private readonly SqliteSchemaDescriber $describer) {}

    public function show(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);
        abort_if(! $survey->isPublished(), 404, "Ce questionnaire n'a pas encore de version publiée.");

        return $this->ok($this->payload($survey));
    }

    public function rebuild(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('exportSubmissions', $survey);
        abort_if(! $survey->isPublished(), 404, "Ce questionnaire n'a pas encore de version publiée.");

        $pending = $this->pendingJob($survey);
        if ($pending !== null) {
            return $this->accepted($pending, ['datasource' => $this->payload($survey)]);
        }

        $job = AiJob::query()->create([
            'user_id' => $request->user()->id,
            'survey_id' => $survey->id,
            'kind' => JobKind::Materialize,
            'message' => 'Reconstruction planifiée.',
            'input' => ['survey_id' => $survey->id],
        ]);

        SurveyDatasource::query()->firstOrCreate(['survey_id' => $survey->id])->markDirty();

        // `?sync=1` : reconstruction immédiate (le délai de 30 s et le verrou d'unicité ne
        // s'appliquent qu'à la mise en file).
        if ($request->boolean('sync')) {
            MaterializeSurveyDatasourceJob::dispatchSync($survey->id, $job->uuid);
        } else {
            MaterializeSurveyDatasourceJob::dispatch($survey->id, $job->uuid);
        }

        return $this->accepted($job->refresh(), ['datasource' => $this->payload($survey->refresh())]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Schéma OpenAPI `Datasource`.
     *
     * @return array<string, mixed>
     */
    private function payload(Survey $survey): array
    {
        $datasource = SurveyDatasource::query()->where('survey_id', $survey->id)->first();
        $targetDb = $datasource?->target_database_id !== null
            ? TargetDatabase::query()->find($datasource->target_database_id)
            : null;
        $pending = $this->pendingJob($survey);

        return [
            'survey_id' => $survey->id,
            'target_database_id' => $targetDb?->id,
            'target_database_name' => $targetDb?->name ?? 'Enquête : '.$survey->title,
            'status' => match (true) {
                $pending !== null => 'building',
                ($datasource?->last_error ?? null) !== null => 'error',
                (bool) $datasource?->isMaterialized() => 'ready',
                default => 'empty',
            },
            'dirty' => (bool) ($datasource?->dirty ?? false),
            'dirty_since' => $datasource?->dirty_since?->toIso8601String(),
            'last_materialized_at' => $datasource?->last_materialized_at?->toIso8601String(),
            'file_version' => (int) ($datasource?->file_version ?? 0),
            'row_count' => (int) ($datasource?->row_count ?? 0),
            'last_duration_ms' => $datasource?->last_duration_ms,
            'tables' => $this->describer->tables($targetDb?->database),
            'last_error' => $datasource?->last_error,
            'building_job_id' => $pending?->uuid,
        ];
    }

    private function pendingJob(Survey $survey): ?AiJob
    {
        return AiJob::query()
            ->where('survey_id', $survey->id)
            ->kind(JobKind::Materialize)
            ->pending()
            ->latest('id')
            ->first();
    }
}
