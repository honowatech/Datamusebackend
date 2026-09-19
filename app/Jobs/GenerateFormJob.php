<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Services\Survey\SurveyAiService;
use App\Services\Survey\SurveyVersionService;
use App\Support\ApiKeyResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * B-06b — `POST /surveys/generate` : génération IA d'un questionnaire depuis un texte (ou le texte extrait
 * d'un .docx par `DocxTextExtractor`, côté contrôleur).
 *
 * Payload : uuid de l'`AiJob`, clé API **chiffrée** (`ApiKeyResolver::encryptForJob`), fournisseur et
 * options. La clé n'est jamais écrite en clair, ni dans `ai_jobs.input`, ni dans la table `jobs`.
 *
 * Trois modes, dans cet ordre de priorité :
 *   - `create: false`     → **proposition** : la définition est conservée dans le job
 *     (`result_ref = jobs/{uuid}/proposal`, `ai_jobs.output = {definition, warnings}`) pour la revue et la
 *     fusion côté builder (W-08, `DiffReview`). C'est le mode du bouton « Générer par IA » du builder,
 *     qui envoie aussi `survey_id` — pour l'autorisation et pour rattacher le job au questionnaire ;
 *   - `survey_id` fourni  → `saveDraft()` sur le brouillon de ce questionnaire (`result_ref = surveys/{id}`) ;
 *   - sinon               → `createSurvey()` dans `project_id` (`result_ref = surveys/{id}`).
 */
class GenerateFormJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Une seule tentative : un appel IA raté n'est pas rejoué automatiquement (coût + non idempotent). */
    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  string  $jobUuid  `ai_jobs.uuid`
     * @param  string|null  $encryptedApiKey  clé chiffrée (Crypt) ; `null` → échec immédiat
     * @param  array{project_id?: int, survey_id?: int|null, create?: bool, source_text?: string, languages?: list<string>, default_language?: string, hints?: array<string, mixed>, title?: string|null}  $options
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
                throw new \RuntimeException(ApiKeyResolver::missingKeyMessage($this->provider));
            }

            $definition = $ai->generateForm(
                (string) ($this->options['source_text'] ?? ''),
                [
                    'provider' => $this->provider,
                    'api_key' => ApiKeyResolver::decryptForJob($this->encryptedApiKey),
                    'languages' => $this->options['languages'] ?? null,
                    'default_language' => $this->options['default_language'] ?? null,
                    'hints' => $this->options['hints'] ?? [],
                    'title' => $this->options['title'] ?? null,
                ],
                $job,
            );

            // `create: false` fait foi en premier (E-03) : le builder envoie `survey_id` pour
            // l'autorisation mais veut une **proposition** à comparer, pas un brouillon écrasé.
            if (($this->options['create'] ?? true) === false) {
                $this->storeProposal($job, $ai, $definition);

                return;
            }

            $surveyId = $this->options['survey_id'] ?? null;
            if ($surveyId !== null) {
                $this->updateExistingDraft($job, $versions, (int) $surveyId, $definition);

                return;
            }

            $this->createSurvey($job, $ai, $versions, $definition);
        } catch (Throwable $e) {
            $this->markJobFailed($job, $e);
        }
    }

    /**
     * Échec au niveau de la file (timeout, worker tué) : le job est marqué en échec pour que le web
     * cesse d'interroger `GET /jobs/{uuid}`.
     */
    public function failed(?Throwable $e): void
    {
        $job = AiJob::query()->where('uuid', $this->jobUuid)->first();
        if ($job !== null && ! $job->isTerminal()) {
            $job->markFailed(self::readable($e), 'Génération interrompue.');
        }
    }

    // ------------------------------------------------------------------ modes

    /**
     * @param  array<string, mixed>  $definition
     */
    private function updateExistingDraft(AiJob $job, SurveyVersionService $versions, int $surveyId, array $definition): void
    {
        $survey = Survey::query()->findOrFail($surveyId);
        $base = $versions->draftOf($survey) ?? $versions->currentOf($survey);
        $result = $versions->saveDraft($survey, $definition, (int) ($base?->revision ?? 0));

        $job->forceFill(['survey_id' => $survey->id])->save();
        $job->markDone(
            'surveys/'.$survey->id,
            sprintf('Brouillon mis à jour (v%d, révision %d).', $result->version->version, $result->version->revision),
            [
                'survey_id' => $survey->id,
                'version' => $result->version->version,
                'revision' => $result->version->revision,
                'warnings' => array_values($result->warnings),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function createSurvey(AiJob $job, SurveyAiService $ai, SurveyVersionService $versions, array $definition): void
    {
        $project = SurveyProject::query()->findOrFail((int) ($this->options['project_id'] ?? 0));
        $title = $this->titleFor($definition);

        $survey = $versions->createSurvey($project, $job->user, $title, $definition);
        $version = $versions->currentOf($survey);

        $job->forceFill(['survey_id' => $survey->id])->save();
        $job->markDone(
            'surveys/'.$survey->id,
            sprintf('Questionnaire créé (v%d, %s).', $version?->version ?? 1, self::countLabel($definition)),
            [
                'survey_id' => $survey->id,
                'version' => $version?->version ?? 1,
                'revision' => $version?->revision ?? 0,
                'warnings' => array_values($ai->validateDefinition($definition)->all()),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function storeProposal(AiJob $job, SurveyAiService $ai, array $definition): void
    {
        $job->markDone(
            'jobs/'.$job->uuid.'/proposal',
            sprintf('Proposition prête (%s).', self::countLabel($definition)),
            [
                'definition' => $definition,
                'warnings' => array_values($ai->validateDefinition($definition)->all()),
            ],
        );
    }

    // ------------------------------------------------------------------ helpers

    private function markJobFailed(AiJob $job, Throwable $e): void
    {
        Log::warning('GenerateFormJob: '.$e->getMessage(), ['ai_job' => $this->jobUuid, 'exception' => $e::class]);
        $job->markFailed(self::readable($e), 'Génération interrompue.');
    }

    public static function readable(?Throwable $e): string
    {
        $message = trim((string) $e?->getMessage());

        return $message !== '' ? $message : 'Le traitement a échoué pour une raison inconnue.';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function titleFor(array $definition): string
    {
        $explicit = $this->options['title'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $title = $definition['title'] ?? [];
        if (is_array($title)) {
            $default = $definition['settings']['default_language'] ?? null;
            $candidate = (is_string($default) ? ($title[$default] ?? null) : null) ?? (is_string(reset($title)) ? reset($title) : null);
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Questionnaire généré';
    }

    /**
     * « 9 sections, 64 questions » (message du contrat).
     *
     * @param  array<string, mixed>  $definition
     */
    public static function countLabel(array $definition): string
    {
        $sections = is_array($definition['sections'] ?? null) ? $definition['sections'] : [];
        $questions = 0;
        foreach ($sections as $section) {
            foreach ((is_array($section) ? $section['items'] ?? [] : []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $questions += ($item['type'] ?? null) === 'group' ? count(is_array($item['items'] ?? null) ? $item['items'] : []) : 1;
            }
        }

        return sprintf('%d section%s, %d question%s', count($sections), count($sections) > 1 ? 's' : '', $questions, $questions > 1 ? 's' : '');
    }
}
