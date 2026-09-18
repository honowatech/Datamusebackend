<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Enums\SubmissionStatus;
use App\Models\AiJob;
use App\Models\Submission;
use App\Models\Survey;
use App\Services\Survey\SubmissionQualityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * B-10 — `POST /surveys/{id}/supervision/recompute` : recalcule `flags` et `suspicion_score` de
 * **toutes** les soumissions non rejetées d'un questionnaire (utile après modification des zones
 * `settings.geo.zones`, des `duplicate_keys` ou des quotas `max`).
 *
 * `ShouldBeUnique` par questionnaire : un second appel pendant le traitement renvoie le `job_id`
 * déjà en cours. La progression de l'`AiJob` est mise à jour tous les `PROGRESS_STEP` fiches.
 * Une fois terminé, la datasource est marquée `dirty` (la colonne `flags` de `reponses` change).
 */
class RecomputeSubmissionFlagsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 1800;

    /** Fréquence de mise à jour de `ai_jobs.progress`. */
    public const PROGRESS_STEP = 25;

    public function __construct(
        public readonly int $surveyId,
        public readonly ?string $jobUuid = null,
    ) {}

    public function uniqueId(): string
    {
        return 'survey-recompute-flags:'.$this->surveyId;
    }

    public function handle(SubmissionQualityService $quality): void
    {
        $aiJob = $this->jobUuid !== null ? AiJob::query()->where('uuid', $this->jobUuid)->first() : null;
        $survey = Survey::query()->find($this->surveyId);

        if ($survey === null) {
            $aiJob?->forceFill(['status' => JobStatus::Failed, 'error' => 'Questionnaire introuvable.'])->save();

            return;
        }

        $aiJob?->forceFill(['status' => JobStatus::Running, 'progress' => 0, 'message' => 'Recalcul des drapeaux qualité…'])->save();

        try {
            $settingsByVersion = [];
            $total = Submission::query()->where('survey_id', $survey->id)
                ->where('status', '!=', SubmissionStatus::Rejected->value)->count();
            $done = 0;
            $flagged = 0;

            Submission::query()
                ->where('survey_id', $survey->id)
                ->where('status', '!=', SubmissionStatus::Rejected->value)
                ->with('version')
                ->orderBy('id')
                ->chunkById(200, function ($submissions) use ($quality, &$settingsByVersion, &$done, &$flagged, $total, $aiJob): void {
                    foreach ($submissions as $submission) {
                        $versionId = (int) $submission->survey_version_id;
                        $settingsByVersion[$versionId] ??= $submission->version?->settings() ?? [];

                        $result = $quality->apply($submission, $settingsByVersion[$versionId]);
                        $flagged += $result['flags'] === [] ? 0 : 1;
                        $done++;

                        if ($aiJob !== null && $done % self::PROGRESS_STEP === 0 && $total > 0) {
                            $aiJob->forceFill(['progress' => (int) round($done / $total * 100)])->save();
                        }
                    }
                });

            $aiJob?->forceFill([
                'status' => JobStatus::Done,
                'progress' => 100,
                'message' => sprintf('%d fiche(s) recalculée(s), %d signalée(s).', $done, $flagged),
                'result_ref' => 'survey:'.$survey->id,
            ])->save();

            MaterializeSurveyDatasourceJob::refresh($survey->id);
        } catch (Throwable $e) {
            Log::error('RecomputeSubmissionFlagsJob', ['survey_id' => $this->surveyId, 'error' => $e->getMessage()]);
            $aiJob?->forceFill(['status' => JobStatus::Failed, 'error' => $e->getMessage()])->save();
        }
    }
}
