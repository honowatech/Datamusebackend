<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\Survey;
use App\Models\SurveyReport;
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
 * B-11 — `POST /reports/{id}/regenerate-section` : réécriture d'**une seule** section.
 *
 * La section est repérée par son `heading` dans `content_json.sections[]` (comparaison insensible à la
 * casse et aux espaces). Seule cette entrée est remplacée ; le reste de `content_json` est intact et le
 * markdown est mis à jour **en place** (`ReportMarkdownRenderer::replaceSection`). Si le markdown a été
 * édité à la main au point que le titre n'y figure plus, l'ensemble est re-rendu depuis `content_json`.
 *
 * Le statut du rapport n'est pas modifié (le rapport reste `done` pendant la régénération) : c'est le job
 * qui porte l'état, comme le prévoit le contrat (`202` + `GET /jobs/{id}`).
 */
class RegenerateReportSectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array{report_id: int, heading: string, instructions?: ?string}  $options
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

        try {
            if ($this->encryptedApiKey === null) {
                throw new RuntimeException(ApiKeyResolver::missingKeyMessage($this->provider));
            }

            $report = SurveyReport::query()->findOrFail((int) $this->options['report_id']);
            $survey = Survey::query()->findOrFail($report->survey_id);

            $content = is_array($report->content_json) ? $report->content_json : [];
            $sections = is_array($content['sections'] ?? null) ? array_values($content['sections']) : [];
            $index = self::indexOf($sections, (string) $this->options['heading']);
            if ($index === null) {
                throw new RuntimeException(sprintf('Section « %s » introuvable dans ce rapport.', $this->options['heading']));
            }

            $job->markRunning('Agrégation des résultats…')->setProgress(15, 'Agrégation des résultats…');
            $context = $contextBuilder->build($survey, [
                'lang' => $report->language,
                'include_verbatims' => $report->option('include_verbatims', true) !== false,
            ]);

            $job->setProgress(50, sprintf('Réécriture de « %s »…', $this->options['heading']));
            $rewritten = $ai->regenerateSection($contextBuilder->toMarkdown($context), $sections[$index], [
                'provider' => $this->provider,
                'api_key' => ApiKeyResolver::decryptForJob($this->encryptedApiKey),
                'instructions' => $this->options['instructions'] ?? null,
                'language' => (string) $report->language,
                'title' => (string) $report->title,
                'audience' => (string) $report->audience,
                'tone' => (string) $report->option('tone', 'factuel'),
            ]);

            $previous = $sections[$index];
            $sections[$index] = $rewritten;
            $content['sections'] = $sections;

            $markdown = (string) $report->content_md;
            $replaced = $markdown === '' ? null : $renderer->replaceSection(
                $markdown,
                $renderer->sectionHeadingLine($previous),
                $renderer->sectionMarkdown($rewritten),
            );

            $report->forceFill([
                'content_json' => $content,
                'content_md' => $replaced ?? $renderer->render($content),
                'provider' => $this->provider,
                'model' => config("services.{$this->provider}.model"),
            ])->mergeMeta(['content_json_stale' => null])->save();

            $job->markDone(
                'reports/'.$report->id,
                sprintf('Section « %s » régénérée.', $rewritten['heading'] ?? $this->options['heading']),
                [
                    'report_id' => $report->id,
                    'heading' => $rewritten['heading'] ?? $this->options['heading'],
                    'section_index' => $index,
                    'markdown_rerendered' => $replaced === null,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('RegenerateReportSectionJob: '.$e->getMessage(), ['ai_job' => $this->jobUuid, 'exception' => $e::class]);
            $job->markFailed(GenerateFormJob::readable($e), 'Régénération interrompue.');
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = AiJob::query()->where('uuid', $this->jobUuid)->first();
        if ($job !== null && ! $job->isTerminal()) {
            $job->markFailed(GenerateFormJob::readable($e), 'Régénération interrompue.');
        }
    }

    /**
     * Index de la section dont le `heading` correspond (casse et espaces ignorés).
     *
     * @param  list<mixed>  $sections
     */
    public static function indexOf(array $sections, string $heading): ?int
    {
        $needle = self::normalize($heading);
        foreach ($sections as $i => $section) {
            if (is_array($section) && self::normalize((string) ($section['heading'] ?? '')) === $needle) {
                return $i;
            }
        }

        return null;
    }

    private static function normalize(string $heading): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $heading)));
    }
}
