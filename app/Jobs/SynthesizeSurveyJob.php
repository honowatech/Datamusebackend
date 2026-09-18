<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\Survey;
use App\Services\Survey\SurveyAiService;
use App\Services\Survey\SurveyInsightContext;
use App\Support\ApiKeyResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * B-11 — `POST /surveys/{id}/synthesis` : synthèse markdown des résultats.
 *
 * Le contexte est **statistique** (`SurveyInsightContext` : overview, quotas, KPI, distributions, thèmes
 * de verbatims et citations) : aucune ligne brute ni aucune question `pii` n'est transmise au modèle.
 *
 * `result_ref = "syntheses/{uuid du job}"` et le markdown est rendu par `GET /jobs/{id}` →
 * `result.content_md` (contrat : le résultat est petit, il est embarqué dans `ai_jobs.output`).
 */
class SynthesizeSurveyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array{survey_id: int, focus?: ?string, language?: string}  $options
     */
    public function __construct(
        public readonly string $jobUuid,
        public readonly ?string $encryptedApiKey,
        public readonly string $provider,
        public readonly array $options = [],
    ) {}

    public function handle(SurveyAiService $ai, SurveyInsightContext $contextBuilder): void
    {
        $job = AiJob::query()->where('uuid', $this->jobUuid)->first();
        if ($job === null || $job->isTerminal()) {
            return;
        }

        try {
            if ($this->encryptedApiKey === null) {
                throw new RuntimeException(ApiKeyResolver::missingKeyMessage($this->provider));
            }

            $survey = Survey::query()->findOrFail((int) $this->options['survey_id']);

            $job->markRunning('Agrégation des résultats…')->setProgress(15, 'Agrégation des résultats…');
            $context = $contextBuilder->build($survey, ['lang' => $this->options['language'] ?? null]);

            $job->setProgress(45, 'Rédaction de la synthèse…');
            $markdown = $ai->synthesize($contextBuilder->toMarkdown($context), [
                'provider' => $this->provider,
                'api_key' => ApiKeyResolver::decryptForJob($this->encryptedApiKey),
                'focus' => $this->options['focus'] ?? null,
                'language' => $this->options['language'] ?? 'fr',
            ]);

            $job->markDone(
                'syntheses/'.$job->uuid,
                sprintf('Synthèse rédigée (%d caractères).', mb_strlen($markdown)),
                [
                    'content_md' => $markdown,
                    'survey_id' => $survey->id,
                    'n' => $context['sample']['n_total'] ?? 0,
                    'focus' => $this->options['focus'] ?? null,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('SynthesizeSurveyJob: '.$e->getMessage(), ['ai_job' => $this->jobUuid, 'exception' => $e::class]);
            $job->markFailed(GenerateFormJob::readable($e), 'Synthèse interrompue.');
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = AiJob::query()->where('uuid', $this->jobUuid)->first();
        if ($job !== null && ! $job->isTerminal()) {
            $job->markFailed(GenerateFormJob::readable($e), 'Synthèse interrompue.');
        }
    }
}
