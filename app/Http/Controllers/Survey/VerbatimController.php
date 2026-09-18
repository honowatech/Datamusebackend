<?php

namespace App\Http\Controllers\Survey;

use App\Enums\JobKind;
use App\Enums\JobStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Survey\ClassifyVerbatimsRequest;
use App\Http\Requests\Survey\StoreCodebookRequest;
use App\Http\Requests\Survey\UpdateCodebookRequest;
use App\Http\Resources\CodebookResource;
use App\Jobs\ClassifyVerbatimsJob;
use App\Jobs\MaterializeSurveyDatasourceJob;
use App\Models\AiJob;
use App\Models\Survey;
use App\Models\User;
use App\Models\VerbatimCodebook;
use App\Services\Survey\VerbatimService;
use App\Support\ApiKeyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * B-11 — verbatims (contrat : tag Verbatims).
 *
 * - `POST /surveys/{id}/verbatims/classify` — analyste, `throttle:ai` → `202 {job_id}` ;
 * - `GET  /surveys/{id}/verbatims/codebooks` — superviseur, toutes les versions ;
 * - `PUT  /surveys/{id}/verbatims/codebooks` — analyste, crée la version suivante (`source: manual`) ;
 * - `GET  /surveys/{id}/verbatims/codebooks/{id}` — superviseur, avec `themes[].count` ;
 * - `PUT  /surveys/{id}/verbatims/codebooks/{id}` — analyste, renomme / fusionne (`merge_into`) ;
 * - `GET  /surveys/{id}/verbatims/{questionKey}` — superviseur, verbatims codés paginés + comptages.
 *
 * Les routes `codebooks` sont déclarées **avant** `{questionKey}` : une question ne peut de toute façon
 * pas s'appeler `codebooks` ni `classify` sans être rejetée par le validateur DFS.
 *
 * Toute écriture sur un livre de codes marque la datasource `dirty` : `reponses.{key}_themes` reflète
 * les codages après reconstruction.
 */
class VerbatimController extends ApiController
{
    public function __construct(private readonly VerbatimService $verbatims) {}

    // ------------------------------------------------------------------ classification

    public function classify(ClassifyVerbatimsRequest $request, Survey $survey): JsonResponse
    {
        Gate::authorize('generateAi', $survey);

        $key = $request->questionKey();
        $question = $this->question($survey, $key);
        if ($question['pii']) {
            throw ValidationException::withMessages([
                'question_key' => ['Les questions marquées « pii » ne peuvent pas être envoyées à un fournisseur IA.'],
            ]);
        }

        if ($request->mode() === 'apply' && $request->codebookId() === null && $this->verbatims->latestCodebook($survey, $key) === null) {
            throw ValidationException::withMessages([
                'mode' => ["Aucun livre de codes pour « {$key} » : lancez d'abord une découverte (`mode: discover`)."],
            ]);
        }

        $user = $request->user();
        $apiKey = $this->resolveKey($request, $user, $request->provider());

        $job = AiJob::query()->create([
            'user_id' => $user->id,
            'survey_id' => $survey->id,
            'kind' => JobKind::Classify,
            'status' => JobStatus::Queued,
            'progress' => 0,
            'message' => 'Classification en attente…',
            'input' => [
                'survey_id' => $survey->id,
                'question_key' => $key,
                'question_label' => $question['label'],
                'mode' => $request->mode(),
                'codebook_id' => $request->codebookId(),
                'max_themes' => $request->maxThemes(),
                'force' => $request->force(),
                'language' => $request->language(),
                'provider' => $request->provider(),
            ],
        ]);

        ClassifyVerbatimsJob::dispatch($job->uuid, $apiKey, $request->provider(), [
            'survey_id' => $survey->id,
            'question_key' => $key,
            'mode' => $request->mode(),
            'codebook_id' => $request->codebookId(),
            'max_themes' => $request->maxThemes(),
            'force' => $request->force(),
            'language' => $request->language(),
        ]);

        return $this->acceptedJob($job);
    }

    // ------------------------------------------------------------------ livres de codes

    public function codebooks(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        $questionKey = $request->query('question_key');
        $codebooks = $this->verbatims->codebooks($survey, is_string($questionKey) && $questionKey !== '' ? $questionKey : null);

        return $this->ok(CodebookResource::collection($codebooks));
    }

    public function storeCodebook(StoreCodebookRequest $request, Survey $survey): JsonResponse
    {
        Gate::authorize('generateAi', $survey);

        $key = $request->questionKey();
        $this->question($survey, $key);

        $normalized = VerbatimService::normalizeThemes($request->themes());
        if ($normalized === []) {
            throw ValidationException::withMessages(['themes' => ['Aucun thème exploitable (clés vides ou en double).']]);
        }

        // Idempotence du contrat : une dernière version identique est renvoyée telle quelle (200).
        $latest = $this->verbatims->latestCodebook($survey, $key);
        if ($latest !== null && $latest->source === VerbatimCodebook::SOURCE_MANUAL
            && json_encode($latest->themes) === json_encode($normalized)) {
            return $this->ok($this->withCounts($latest), [], 200);
        }

        $codebook = $this->verbatims->createCodebook($survey, $key, $normalized, VerbatimCodebook::SOURCE_MANUAL);
        MaterializeSurveyDatasourceJob::refresh($survey->id);

        return $this->ok($this->withCounts($codebook), [], 201);
    }

    public function showCodebook(Survey $survey, VerbatimCodebook $codebook): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);
        $this->assertBelongs($survey, $codebook);

        return $this->ok($this->withCounts($codebook));
    }

    public function updateCodebook(UpdateCodebookRequest $request, Survey $survey, VerbatimCodebook $codebook): JsonResponse
    {
        Gate::authorize('generateAi', $survey);
        $this->assertBelongs($survey, $codebook);

        $result = $this->verbatims->updateThemes($codebook, $request->themes());
        if ($result['themes'] === []) {
            throw ValidationException::withMessages(['themes' => ['Un livre de codes doit conserver au moins un thème.']]);
        }

        MaterializeSurveyDatasourceJob::refresh($survey->id);

        return $this->ok($this->withCounts($codebook->refresh()), [
            'merged' => $result['merged'],
            'recoded' => $result['recoded'],
        ]);
    }

    // ------------------------------------------------------------------ lecture

    public function index(Request $request, Survey $survey, string $questionKey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        $question = $this->question($survey, $questionKey);
        if ($question['pii']) {
            return $this->fail('Cette question contient des données personnelles : ses réponses ne sont pas exposées ici.', 403);
        }

        $codebookId = $request->query('codebook_id');
        $result = $this->verbatims->listCoded($survey, $questionKey, [
            'codebook_id' => is_numeric($codebookId) ? (int) $codebookId : null,
            'theme' => is_string($request->query('theme')) && $request->query('theme') !== '' ? (string) $request->query('theme') : null,
            'sentiment' => VerbatimService::sentiment($request->query('sentiment')),
            'q' => is_string($request->query('q')) ? (string) $request->query('q') : null,
        ]);

        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->query('page', 1));
        $total = count($result['items']);
        $items = array_slice($result['items'], ($page - 1) * $perPage, $perPage);

        $labels = $this->verbatims->themeLabels($survey, $questionKey);
        $themes = [];
        foreach ($result['counts'] as $theme => $count) {
            $themes[] = [
                'key' => (string) $theme,
                'label' => $labels[$theme] ?? (string) $theme,
                'count' => $count,
                'pct' => $total === 0 ? 0.0 : round($count / max(1, count($result['items'])) * 100, 1),
            ];
        }

        return $this->ok($items, [
            'question' => ['key' => $questionKey, 'label' => $question['label']],
            'codebook_id' => $result['codebook']?->id,
            'codebook_version' => $result['codebook']?->version,
            'themes' => $themes,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array{def: array<string, mixed>, label: string, pii: bool}
     */
    private function question(Survey $survey, string $key): array
    {
        try {
            return $this->verbatims->textQuestion($survey, $key);
        } catch (RuntimeException $e) {
            if ($e->getCode() === 404) {
                abort(404, $e->getMessage());
            }
            throw ValidationException::withMessages(['question_key' => [$e->getMessage()]]);
        }
    }

    private function assertBelongs(Survey $survey, VerbatimCodebook $codebook): void
    {
        abort_unless((int) $codebook->survey_id === (int) $survey->id, 404, 'Ce livre de codes n\'appartient pas à ce questionnaire.');
    }

    private function withCounts(VerbatimCodebook $codebook): CodebookResource
    {
        $codebook->setAttribute('counts', $this->verbatims->counts($codebook));

        return new CodebookResource($codebook);
    }

    private function acceptedJob(AiJob $job): JsonResponse
    {
        $job->refresh();

        return $this->accepted($job, ['status' => $job->status?->value], [])
            ->header('Location', url('/api/jobs/'.$job->uuid));
    }

    private function resolveKey(Request $request, User $user, string $provider): string
    {
        $key = app(ApiKeyResolver::class)->resolveForJob($request, $user, $provider);
        if ($key === null) {
            throw ValidationException::withMessages(['provider' => [ApiKeyResolver::missingKeyMessage($provider)]]);
        }

        return $key;
    }
}
