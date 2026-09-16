<?php

namespace App\Http\Controllers\Survey;

use App\Enums\JobKind;
use App\Enums\JobStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Survey\GenerateSurveyRequest;
use App\Http\Requests\Survey\TranslateSurveyRequest;
use App\Jobs\GenerateFormJob;
use App\Jobs\TranslateFormJob;
use App\Models\AiJob;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\User;
use App\Services\Survey\DocxTextExtractor;
use App\Support\ApiKeyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Opérations IA sur la définition d'un questionnaire (contrat : tag IA) :
 *   - `POST /surveys/generate`            génération depuis un texte ou un `.docx` → `202 {job_id}` ;
 *   - `POST /surveys/{survey}/ai/translate` traduction du brouillon         → `202 {job_id}`.
 *
 * Les deux routes portent `throttle:ai` (5/min/utilisateur, `AppServiceProvider`). La clé API est résolue
 * **avant** la mise en file (`ApiKeyResolver::resolveForJob`, chiffrée dans le payload du job) : absente →
 * `422 errors.provider`, jamais un job qui échoue deux minutes plus tard.
 */
class SurveyGenerateController extends ApiController
{
    /** Contrat : `.docx` ≤ 10 Mo (`DocxTextExtractor::MAX_BYTES`). */
    public const MAX_DOCX_BYTES = DocxTextExtractor::MAX_BYTES;

    /** En dessous, l'extraction a manifestement échoué (document vide ou protégé). */
    private const MIN_EXTRACTED_LENGTH = 30;

    public function __construct(private readonly DocxTextExtractor $docx) {}

    /**
     * POST /surveys/generate — analyste du projet (ou du questionnaire visé par `survey_id`).
     */
    public function generate(GenerateSurveyRequest $request): JsonResponse
    {
        $tooLarge = $this->rejectOversizedDocx($request->file('docx'));
        if ($tooLarge !== null) {
            return $tooLarge;
        }

        $user = $request->user();
        $project = SurveyProject::query()->findOrFail($request->projectId());

        $survey = null;
        if ($request->surveyId() !== null) {
            $survey = Survey::query()->findOrFail($request->surveyId());
            if ($survey->project_id !== $project->id) {
                throw ValidationException::withMessages([
                    'survey_id' => ['Ce questionnaire n\'appartient pas au projet indiqué.'],
                ]);
            }
            Gate::authorize('generateAi', $survey);
        } else {
            Gate::authorize('createSurvey', $project);
        }

        $sourceText = $this->sourceText($request);
        $apiKey = $this->resolveKey($request, $user, $request->provider());

        $job = AiJob::query()->create([
            'user_id' => $user->id,
            'survey_id' => $survey?->id,
            'kind' => JobKind::FormGeneration,
            'status' => JobStatus::Queued,
            'progress' => 0,
            'message' => 'Génération en attente…',
            'input' => [
                'project_id' => $project->id,
                'survey_id' => $survey?->id,
                'create' => $request->shouldCreate(),
                'provider' => $request->provider(),
                'languages' => $request->languages(),
                'default_language' => $request->defaultLanguage(),
                'hints' => $request->hints(),
                'title' => $request->title(),
                'source' => $request->hasFile('docx') ? 'docx' : 'text',
                'source_length' => mb_strlen($sourceText),
                'source_preview' => mb_substr($sourceText, 0, 500),
            ],
        ]);

        GenerateFormJob::dispatch($job->uuid, $apiKey, $request->provider(), [
            'project_id' => $project->id,
            'survey_id' => $survey?->id,
            'create' => $request->shouldCreate(),
            'source_text' => $sourceText,
            'languages' => $request->languages(),
            'default_language' => $request->defaultLanguage(),
            'hints' => $request->hints(),
            'title' => $request->title(),
        ]);

        return $this->acceptedJob($job);
    }

    /**
     * POST /surveys/{survey}/ai/translate — analyste du projet.
     */
    public function translate(TranslateSurveyRequest $request, Survey $survey): JsonResponse
    {
        Gate::authorize('generateAi', $survey);

        $user = $request->user();
        $apiKey = $this->resolveKey($request, $user, $request->provider());

        $job = AiJob::query()->create([
            'user_id' => $user->id,
            'survey_id' => $survey->id,
            'kind' => JobKind::Translate,
            'status' => JobStatus::Queued,
            'progress' => 0,
            'message' => 'Traduction en attente…',
            'input' => [
                'survey_id' => $survey->id,
                'target_lang' => $request->targetLang(),
                'overwrite' => $request->overwrite(),
                'provider' => $request->provider(),
            ],
        ]);

        TranslateFormJob::dispatch($job->uuid, $apiKey, $request->provider(), [
            'survey_id' => $survey->id,
            'target_lang' => $request->targetLang(),
            'overwrite' => $request->overwrite(),
        ]);

        return $this->acceptedJob($job);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * `202` + `Location: /api/jobs/{uuid}` (le job peut déjà être terminé si la file est `sync`).
     */
    private function acceptedJob(AiJob $job): JsonResponse
    {
        $job->refresh();

        return $this->accepted($job, ['status' => $job->status?->value], [])
            ->header('Location', url('/api/jobs/'.$job->uuid));
    }

    /**
     * Texte du questionnaire : `source_text`, ou texte extrait du `.docx` (422 si le fichier n'en est pas un).
     */
    private function sourceText(GenerateSurveyRequest $request): string
    {
        $file = $request->file('docx');
        if (! $file instanceof UploadedFile) {
            return trim((string) $request->validated('source_text'));
        }

        try {
            $text = $this->docx->extract($file->getRealPath() ?: $file->getPathname());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['docx' => [$e->getMessage()]]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['docx' => [$e->getMessage()]]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'docx' => ['Le fichier n\'est pas un document Word (.docx) lisible.'],
            ]);
        }

        $text = trim($text);
        if (mb_strlen($text) < self::MIN_EXTRACTED_LENGTH) {
            throw ValidationException::withMessages([
                'docx' => ['Aucun texte exploitable n\'a pu être extrait de ce document.'],
            ]);
        }

        return $text;
    }

    /**
     * `413` avant toute validation : un fichier trop gros ne doit pas produire `422` (contrat).
     */
    private function rejectOversizedDocx(mixed $file): ?JsonResponse
    {
        if ($file instanceof UploadedFile && $file->getSize() > self::MAX_DOCX_BYTES) {
            return $this->fail('Fichier trop volumineux (maximum 10 Mo).', 413);
        }

        return null;
    }

    /**
     * Clé API chiffrée pour le job. Absente → `422 errors.provider` (contrat).
     */
    private function resolveKey(GenerateSurveyRequest|TranslateSurveyRequest $request, User $user, string $provider): string
    {
        $key = app(ApiKeyResolver::class)->resolveForJob($request, $user, $provider);
        if ($key === null) {
            throw ValidationException::withMessages(['provider' => [ApiKeyResolver::missingKeyMessage($provider)]]);
        }

        return $key;
    }
}
