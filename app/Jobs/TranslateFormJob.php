<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\Survey;
use App\Services\Survey\SurveyAiService;
use App\Services\Survey\SurveyVersionService;
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
 * B-06b — `POST /surveys/{survey}/ai/translate` : complète les textes i18n du brouillon dans `target_lang`
 * (les autres langues ne sont jamais modifiées), ajoute la langue à `settings.languages`, puis enregistre
 * le brouillon (`saveDraft`, `revision + 1`). Un autosave concurrent du builder reçoit alors `409`.
 *
 * Payload : uuid de l'`AiJob`, clé API chiffrée, fournisseur et options (`survey_id`, `target_lang`,
 * `overwrite`). `result_ref = surveys/{id}`.
 */
class TranslateFormJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array{survey_id: int, target_lang: string, overwrite?: bool}  $options
     */
    public function __construct(
        public readonly string $jobUuid,
        public readonly ?string $encryptedApiKey,
        public readonly string $provider,
        public readonly array $options = [],
    ) {}

    public function handle(SurveyAiService $ai, SurveyVersionService $versions): void
    {
        $job = AiJob::query()->where('uuid', $this->jobUuid)->first();
        if ($job === null || $job->isTerminal()) {
            return;
        }

        try {
            if ($this->encryptedApiKey === null) {
                throw new RuntimeException(ApiKeyResolver::missingKeyMessage($this->provider));
            }

            $survey = Survey::query()->findOrFail((int) ($this->options['survey_id'] ?? 0));
            $source = $versions->draftOf($survey) ?? $versions->currentOf($survey);
            if ($source === null) {
                throw new RuntimeException('Ce questionnaire ne possède aucune version à traduire.');
            }

            $target = (string) ($this->options['target_lang'] ?? '');
            $before = $ai->missingTranslations($source->definition ?? [], $target, (bool) ($this->options['overwrite'] ?? false));

            $definition = $ai->translateForm(
                $source->definition ?? [],
                $target,
                $job,
                [
                    'provider' => $this->provider,
                    'api_key' => ApiKeyResolver::decryptForJob($this->encryptedApiKey),
                    'overwrite' => (bool) ($this->options['overwrite'] ?? false),
                ],
            );

            $result = $versions->saveDraft($survey, $definition, (int) $source->revision);
            $remaining = $ai->missingTranslations($result->version->definition ?? [], $target);

            $job->markDone(
                'surveys/'.$survey->id,
                sprintf(
                    '%d libellé(s) traduit(s) en « %s » (v%d, révision %d)%s.',
                    max(0, count($before) - count($remaining)),
                    $target,
                    $result->version->version,
                    $result->version->revision,
                    $remaining === [] ? '' : sprintf(', %d non traduit(s)', count($remaining)),
                ),
                [
                    'survey_id' => $survey->id,
                    'version' => $result->version->version,
                    'revision' => $result->version->revision,
                    'target_lang' => $target,
                    'translated' => max(0, count($before) - count($remaining)),
                    'missing' => count($remaining),
                    'warnings' => array_values($result->warnings),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('TranslateFormJob: '.$e->getMessage(), ['ai_job' => $this->jobUuid, 'exception' => $e::class]);
            $job->markFailed(GenerateFormJob::readable($e), 'Traduction interrompue.');
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = AiJob::query()->where('uuid', $this->jobUuid)->first();
        if ($job !== null && ! $job->isTerminal()) {
            $job->markFailed(GenerateFormJob::readable($e), 'Traduction interrompue.');
        }
    }
}
