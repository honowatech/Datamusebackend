<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Models\AiJob;
use App\Models\Survey;
use App\Models\SurveyReport;
use App\Services\LlmProviderService;
use App\Services\Survey\ReportMarkdownRenderer;
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
 * B-11 — `POST /surveys/{id}/reports` : rédaction d'un rapport depuis un brief.
 *
 * Entrée du modèle : le brief **plus** le contexte statistique (`SurveyInsightContext` : échantillon,
 * KPI, quotas, distributions, thèmes de verbatims et citations marquantes). Jamais les lignes brutes,
 * jamais une question `pii`.
 *
 * Sortie : `content_json` conforme à `ReportContent` (validation stricte, **une** tentative de réparation
 * par `SurveyAiService::writeReport()`), puis `content_md` **dérivé** par `ReportMarkdownRenderer`.
 * En cas d'échec, le rapport passe `failed` avec `error` renseigné, comme le job.
 */
class GenerateSurveyReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array{report_id: int}  $options
     */
    public function __construct(
        public readonly string $jobUuid,
        public readonly ?string $encryptedApiKey,
        public readonly string $provider,
        public readonly array $options = [],
    ) {}

    public function handle(SurveyAiService $ai, SurveyInsightContext $contextBuilder, ReportMarkdownRenderer $renderer): void
    {
        $job = AiJob::query()->where('uuid', $this->jobUuid)->first();
        if ($job === null || $job->isTerminal()) {
            return;
        }

        $report = SurveyReport::query()->find((int) ($this->options['report_id'] ?? 0));
        if ($report === null) {
            $job->markFailed('Rapport introuvable.', 'Génération interrompue.');

            return;
        }

        try {
            if ($this->encryptedApiKey === null) {
                throw new RuntimeException(ApiKeyResolver::missingKeyMessage($this->provider));
            }

            $survey = Survey::query()->findOrFail($report->survey_id);

            $report->forceFill(['status' => JobStatus::Running, 'error' => null])->save();
            $job->markRunning('Agrégation des résultats…')->setProgress(10, 'Agrégation des résultats…');

            $context = $contextBuilder->build($survey, [
                'lang' => $report->language,
                'include_verbatims' => $report->option('include_verbatims', true) !== false,
            ]);

            $job->setProgress(40, 'Rédaction du rapport…');
            $content = $ai->writeReport($contextBuilder->toMarkdown($context), [
                'title' => (string) $report->title,
                'brief' => (string) $report->brief,
                'orientation' => $report->orientation?->value ?? 'commercial',
                'audience' => (string) $report->audience,
                'language' => (string) $report->language,
                'tone' => (string) $report->option('tone', 'factuel'),
                'length' => (string) $report->option('length', 'moyen'),
                'sections' => $report->option('sections', []),
            ], [
                'provider' => $this->provider,
                'api_key' => ApiKeyResolver::decryptForJob($this->encryptedApiKey),
            ]);

            $job->setProgress(85, 'Mise en forme du markdown…');

            $content = self::withMeta($content, $report, $context);
            $report->forceFill([
                'status' => JobStatus::Done,
                'content_json' => $content,
                'content_md' => $renderer->render($content),
                'provider' => LlmProviderService::effectiveProvider($this->provider),
                'model' => LlmProviderService::effectiveModel($this->provider),
                'error' => null,
            ])->mergeMeta(['content_json_stale' => null])->save();

            $job->markDone(
                'reports/'.$report->id,
                sprintf('Rapport rédigé (%d section%s).', count($content['sections']), count($content['sections']) > 1 ? 's' : ''),
                ['report_id' => $report->id, 'sections' => count($content['sections'])],
            );
        } catch (Throwable $e) {
            Log::warning('GenerateSurveyReportJob: '.$e->getMessage(), ['ai_job' => $this->jobUuid, 'exception' => $e::class]);
            $message = GenerateFormJob::readable($e);
            $report->forceFill(['status' => JobStatus::Failed, 'error' => $message])->save();
            $job->markFailed($message, 'Génération interrompue.');
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = AiJob::query()->where('uuid', $this->jobUuid)->first();
        if ($job !== null && ! $job->isTerminal()) {
            $job->markFailed(GenerateFormJob::readable($e), 'Génération interrompue.');
        }
        $report = SurveyReport::query()->find((int) ($this->options['report_id'] ?? 0));
        if ($report !== null && $report->status !== JobStatus::Done) {
            $report->forceFill(['status' => JobStatus::Failed, 'error' => GenerateFormJob::readable($e)])->save();
        }
    }

    /**
     * `meta` du contenu : l'échantillon et la date de génération viennent du **serveur**, jamais du modèle
     * (le contrat autorise `meta.{language, generated_at, period, n}`).
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function withMeta(array $content, SurveyReport $report, array $context): array
    {
        $from = $context['sample']['period']['from'] ?? null;
        $to = $context['sample']['period']['to'] ?? null;

        $content['meta'] = array_filter([
            'language' => (string) $report->language,
            'generated_at' => now()->toIso8601String(),
            'period' => is_string($from) && is_string($to) ? substr($from, 0, 10).' → '.substr($to, 0, 10) : null,
            'n' => (int) ($context['sample']['n_total'] ?? 0),
        ], static fn ($v): bool => $v !== null);

        return $content;
    }
}
