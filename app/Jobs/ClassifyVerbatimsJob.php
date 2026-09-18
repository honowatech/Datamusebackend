<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\Survey;
use App\Models\VerbatimCodebook;
use App\Services\Survey\SurveyAiService;
use App\Services\Survey\VerbatimService;
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
 * B-11 — `POST /surveys/{id}/verbatims/classify` : classification IA des réponses ouvertes.
 *
 * Deux modes :
 *   - `discover` : ≤ `VerbatimService::DISCOVER_SAMPLE` verbatims sont envoyés au modèle
 *     (prompt `verbatim_discover`), qui propose ≤ `max_themes` thèmes. Un livre de codes `version + 1`
 *     (`source: ai`) est créé, **puis** appliqué à toutes les réponses (le contrat : « propose … puis
 *     applique ») ;
 *   - `apply` : applique le livre de codes demandé (défaut : la dernière version) aux réponses non encore
 *     codées, par lots de `VerbatimService::CLASSIFY_BATCH` (prompt `verbatim_classify`). `force` recode
 *     tout.
 *
 * À la fin, la datasource est marquée `dirty` et un rebuild est mis en file : `reponses.{key}_themes` et
 * `{key}_sentiment` apparaissent alors dans le SQLite matérialisé (B-09).
 *
 * Un lot dont l'appel IA échoue est **journalisé et ignoré** : les lots déjà écrits restent acquis et le
 * job se termine `done` en signalant les lots perdus. Seul un échec global (clé absente, prompt manquant,
 * découverte impossible) fait passer le job `failed`.
 */
class ClassifyVerbatimsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Une seule tentative : un appel IA raté n'est pas rejoué automatiquement (coût + non idempotent). */
    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  string  $jobUuid  `ai_jobs.uuid`
     * @param  string|null  $encryptedApiKey  clé chiffrée (`ApiKeyResolver::encryptForJob`)
     * @param  array{survey_id: int, question_key: string, mode: string, codebook_id?: int|null, max_themes?: int, force?: bool, language?: string}  $options
     */
    public function __construct(
        public readonly string $jobUuid,
        public readonly ?string $encryptedApiKey,
        public readonly string $provider,
        public readonly array $options = [],
    ) {}

    public function handle(SurveyAiService $ai, VerbatimService $verbatims): void
    {
        $job = AiJob::query()->where('uuid', $this->jobUuid)->first();
        if ($job === null || $job->isTerminal()) {
            return;
        }

        try {
            if ($this->encryptedApiKey === null) {
                throw new RuntimeException(ApiKeyResolver::missingKeyMessage($this->provider));
            }
            $apiKey = ApiKeyResolver::decryptForJob($this->encryptedApiKey);

            $survey = Survey::query()->findOrFail((int) $this->options['survey_id']);
            $key = (string) $this->options['question_key'];
            $question = $verbatims->textQuestion($survey, $key);
            $language = (string) ($this->options['language'] ?? 'fr');

            $opts = [
                'provider' => $this->provider,
                'api_key' => $apiKey,
                'question_label' => $question['label'],
                'language' => $language,
            ];

            $job->markRunning('Lecture des réponses ouvertes…')->setProgress(5, 'Lecture des réponses ouvertes…');

            $codebook = $this->options['mode'] === 'discover'
                ? $this->discover($job, $ai, $verbatims, $survey, $key, $opts)
                : $this->codebookToApply($verbatims, $survey, $key);

            $written = $this->apply($job, $ai, $verbatims, $survey, $key, $codebook, $opts);

            MaterializeSurveyDatasourceJob::refresh($survey->id);

            $counts = $verbatims->counts($codebook);
            $job->markDone(
                'codebooks/'.$codebook->id,
                sprintf(
                    '%d verbatim(s) classé(s) sur %d thème(s) (livre de codes v%d).',
                    $written,
                    count($codebook->themeKeys()),
                    $codebook->version,
                ),
                [
                    'codebook_id' => $codebook->id,
                    'question_key' => $key,
                    'version' => $codebook->version,
                    'themes' => $codebook->themes,
                    'coded' => $counts['coded'],
                    'by_theme' => $counts['by_theme'],
                ],
            );
        } catch (Throwable $e) {
            Log::warning('ClassifyVerbatimsJob: '.$e->getMessage(), ['ai_job' => $this->jobUuid, 'exception' => $e::class]);
            $job->markFailed(GenerateFormJob::readable($e), 'Classification interrompue.');
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = AiJob::query()->where('uuid', $this->jobUuid)->first();
        if ($job !== null && ! $job->isTerminal()) {
            $job->markFailed(GenerateFormJob::readable($e), 'Classification interrompue.');
        }
    }

    // ------------------------------------------------------------------ modes

    /**
     * @param  array{provider?: string, api_key: string, question_label?: string, language?: string}  $opts
     */
    private function discover(AiJob $job, SurveyAiService $ai, VerbatimService $verbatims, Survey $survey, string $key, array $opts): VerbatimCodebook
    {
        $sample = $verbatims->verbatims($survey, $key, ['limit' => VerbatimService::DISCOVER_SAMPLE]);
        if ($sample === []) {
            throw new RuntimeException("Aucune réponse ouverte à classer pour « {$key} ».");
        }

        $job->setProgress(20, sprintf('Découverte des thèmes sur %d verbatim(s)…', count($sample)));

        $themes = $ai->discoverThemes(
            array_map(static fn (array $v): string => $v['text'], $sample),
            $opts + ['max_themes' => (int) ($this->options['max_themes'] ?? 10)],
        );

        $codebook = $verbatims->createCodebook($survey, $key, $themes, VerbatimCodebook::SOURCE_AI);
        $job->setProgress(35, sprintf('%d thème(s) proposé(s) ; classification en cours…', count($themes)));

        return $codebook;
    }

    private function codebookToApply(VerbatimService $verbatims, Survey $survey, string $key): VerbatimCodebook
    {
        $id = $this->options['codebook_id'] ?? null;

        $codebook = $id !== null
            ? VerbatimCodebook::query()->forQuestion($survey->id, $key)->whereKey((int) $id)->first()
            : $verbatims->latestCodebook($survey, $key);

        if ($codebook === null) {
            throw new RuntimeException(
                $id !== null
                    ? "Le livre de codes #{$id} n'existe pas pour la question « {$key} »."
                    : "Aucun livre de codes pour « {$key} » : lancez d'abord une découverte (`mode: discover`)."
            );
        }

        return $codebook;
    }

    /**
     * @param  array{provider?: string, api_key: string, question_label?: string, language?: string}  $opts
     */
    private function apply(AiJob $job, SurveyAiService $ai, VerbatimService $verbatims, Survey $survey, string $key, VerbatimCodebook $codebook, array $opts): int
    {
        $force = (bool) ($this->options['force'] ?? false) || $this->options['mode'] === 'discover';

        $pending = $verbatims->verbatims($survey, $key, $force ? [] : ['only_uncoded_for' => $codebook->id]);
        if ($pending === []) {
            $job->setProgress(95, 'Toutes les réponses sont déjà codées.');

            return 0;
        }

        $batches = array_chunk($pending, VerbatimService::CLASSIFY_BATCH);
        $total = count($batches);
        $written = 0;
        $lost = 0;

        foreach ($batches as $i => $batch) {
            $job->setProgress(
                (int) round(40 + 55 * ($i / max(1, $total))),
                sprintf('Classification des verbatims (lot %d/%d)…', $i + 1, $total),
            );

            try {
                $result = $ai->classifyBatch(
                    array_map(
                        static fn (array $v): array => ['ref' => $v['submission_id'], 'text' => $v['text']],
                        $batch,
                    ),
                    $codebook->themes ?? [],
                    $opts,
                );
            } catch (Throwable $e) {
                Log::warning('ClassifyVerbatimsJob: lot ignoré — '.$e->getMessage(), ['ai_job' => $this->jobUuid, 'batch' => $i]);
                $lost++;

                continue;
            }

            $codings = [];
            foreach ($batch as $verbatim) {
                $coding = $result[(string) $verbatim['submission_id']] ?? null;
                if ($coding === null) {
                    continue;
                }
                $codings[] = [
                    'submission_id' => $verbatim['submission_id'],
                    'themes' => $coding['themes'],
                    'sentiment' => $coding['sentiment'],
                    'confidence' => $coding['confidence'],
                ];
            }

            $written += $verbatims->storeCodings($codebook, $codings);
        }

        if ($lost > 0) {
            $job->setProgress(95, sprintf('%d lot(s) non classé(s) : relancez en mode « apply ».', $lost));
        }

        return $written;
    }
}
