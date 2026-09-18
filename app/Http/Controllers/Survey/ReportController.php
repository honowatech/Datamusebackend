<?php

namespace App\Http\Controllers\Survey;

use App\Enums\JobKind;
use App\Enums\JobStatus;
use App\Exceptions\ReportContentInvalidException;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Survey\RegenerateSectionRequest;
use App\Http\Requests\Survey\StoreReportFileRequest;
use App\Http\Requests\Survey\StoreReportRequest;
use App\Http\Requests\Survey\SynthesizeSurveyRequest;
use App\Http\Requests\Survey\UpdateReportRequest;
use App\Http\Resources\ReportFileResource;
use App\Http\Resources\ReportResource;
use App\Jobs\GenerateSurveyReportJob;
use App\Jobs\RegenerateReportSectionJob;
use App\Jobs\SynthesizeSurveyJob;
use App\Models\AiJob;
use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\SurveyReport;
use App\Models\User;
use App\Services\Survey\ReportContentValidator;
use App\Services\Survey\ReportMarkdownRenderer;
use App\Support\ApiKeyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * B-11 — synthèse et rapports (contrat : tags IA et Rapports).
 *
 * - `POST /surveys/{id}/synthesis`            analyste, `throttle:ai` → `202 {job_id}` ;
 * - `GET  /surveys/{id}/reports`              superviseur, paginé, sans les contenus ;
 * - `POST /surveys/{id}/reports`              analyste, `throttle:ai` → `202 {job_id, report}` ;
 * - `GET  /reports/{id}`                      superviseur, contenus + fichiers signés ;
 * - `PUT  /reports/{id}`                      analyste, édition manuelle ;
 * - `POST /reports/{id}/regenerate-section`   analyste, `throttle:ai` → `202 {job_id}` ;
 * - `POST /reports/{id}/files`                analyste, archive un DOCX/PDF produit côté client ;
 * - `GET  /reports/{id}/files/{fileId}`       **route signée** (hors Sanctum), téléchargement.
 *
 * Prérequis de la synthèse et de la génération : la datasource doit être matérialisée
 * (`SurveyDatasource::isMaterialized()`), sinon `409 {code: datasource_not_ready}` comme le crosstab de
 * B-10 — le contexte statistique et les thèmes de verbatims n'ont de sens qu'une fois les réponses
 * indexées, et c'est ce qui permet au web de proposer `POST …/datasource/rebuild`.
 */
class ReportController extends ApiController
{
    /** Colonnes de tri autorisées. */
    public const SORTABLE = ['created_at', 'updated_at', 'title', 'status'];

    public function __construct(
        private readonly ReportMarkdownRenderer $renderer,
        private readonly ReportContentValidator $validator,
    ) {}

    // ------------------------------------------------------------------ synthèse

    public function synthesis(SynthesizeSurveyRequest $request, Survey $survey): JsonResponse
    {
        Gate::authorize('generateAi', $survey);
        $notReady = $this->requireDatasource($survey);
        if ($notReady !== null) {
            return $notReady;
        }

        $user = $request->user();
        $apiKey = $this->resolveKey($request, $user, $request->provider());

        $job = AiJob::query()->create([
            'user_id' => $user->id,
            'survey_id' => $survey->id,
            'kind' => JobKind::Synthesis,
            'status' => JobStatus::Queued,
            'progress' => 0,
            'message' => 'Synthèse en attente…',
            'input' => [
                'survey_id' => $survey->id,
                'focus' => $request->focus(),
                'language' => $request->language(),
                'provider' => $request->provider(),
            ],
        ]);

        SynthesizeSurveyJob::dispatch($job->uuid, $apiKey, $request->provider(), [
            'survey_id' => $survey->id,
            'focus' => $request->focus(),
            'language' => $request->language(),
        ]);

        return $this->acceptedJob($job);
    }

    // ------------------------------------------------------------------ rapports

    public function index(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        [$column, $direction] = $this->sort($request, self::SORTABLE, '-created_at');

        $paginator = SurveyReport::query()
            ->where('survey_id', $survey->id)
            ->with('requester')
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate($this->perPage($request))
            ->withQueryString();

        foreach ($paginator->items() as $report) {
            $report->setAttribute('summary_only', true);
        }

        return $this->paginated($paginator, ReportResource::class);
    }

    public function store(StoreReportRequest $request, Survey $survey): JsonResponse
    {
        Gate::authorize('create', [SurveyReport::class, $survey]);
        $notReady = $this->requireDatasource($survey);
        if ($notReady !== null) {
            return $notReady;
        }

        $user = $request->user();
        $apiKey = $this->resolveKey($request, $user, $request->provider());

        $report = SurveyReport::query()->create($request->attributesForReport() + [
            'survey_id' => $survey->id,
            'requested_by' => $user->id,
            'status' => JobStatus::Queued,
            'provider' => $request->provider(),
            'options' => $request->options(),
            'generated_files' => [],
        ]);

        $job = AiJob::query()->create([
            'user_id' => $user->id,
            'survey_id' => $survey->id,
            'kind' => JobKind::Report,
            'status' => JobStatus::Queued,
            'progress' => 0,
            'message' => 'Rapport en attente…',
            'result_ref' => 'reports/'.$report->id,
            'input' => [
                'survey_id' => $survey->id,
                'report_id' => $report->id,
                'orientation' => $report->orientation?->value,
                'language' => $report->language,
                'provider' => $request->provider(),
            ],
        ]);

        $report->forceFill(['job_id' => $job->uuid])->save();

        GenerateSurveyReportJob::dispatch($job->uuid, $apiKey, $request->provider(), ['report_id' => $report->id]);

        $job->refresh();
        $report->refresh()->setAttribute('summary_only', true);

        return $this->accepted($job, [
            'status' => $job->status?->value,
            'report' => new ReportResource($report),
        ])->header('Location', url('/api/reports/'.$report->id));
    }

    public function show(SurveyReport $report): JsonResponse
    {
        Gate::authorize('view', $report);

        return $this->ok(new ReportResource($report->load('requester')), $this->metaOf($report));
    }

    public function update(UpdateReportRequest $request, SurveyReport $report): JsonResponse
    {
        Gate::authorize('update', $report);

        $hasJson = $request->has('content_json') && is_array($request->input('content_json'));
        $hasMarkdown = $request->has('content_md');
        $changes = [];

        if ($request->has('title')) {
            $changes['title'] = (string) $request->validated('title');
        }

        if ($hasJson) {
            $content = (array) $request->input('content_json');
            $errors = $this->validator->validate($content);
            if ($errors !== []) {
                throw new ReportContentInvalidException($errors);
            }
            $changes['content_json'] = $content;
            // Le markdown est **dérivé** du JSON, sauf si l'appelant fournit les deux.
            $changes['content_md'] = $hasMarkdown ? (string) $request->input('content_md') : $this->renderer->render($content);
            $report->mergeMeta(['content_json_stale' => null]);
        } elseif ($hasMarkdown) {
            $changes['content_md'] = (string) $request->input('content_md');
            // Markdown seul : `content_json` ne reflète plus le texte affiché (contrat).
            $report->mergeMeta(['content_json_stale' => $report->content_json === null ? null : true]);
        }

        if ($changes !== []) {
            $report->forceFill($changes);
        }
        $report->save();

        return $this->ok(new ReportResource($report->refresh()->load('requester')), $this->metaOf($report));
    }

    public function regenerateSection(RegenerateSectionRequest $request, SurveyReport $report): JsonResponse
    {
        Gate::authorize('generateAi', $report);

        $sections = is_array($report->content_json['sections'] ?? null) ? array_values($report->content_json['sections']) : [];
        if (RegenerateReportSectionJob::indexOf($sections, $request->heading()) === null) {
            throw ValidationException::withMessages([
                'heading' => [sprintf('Aucune section « %s » dans ce rapport.', $request->heading())],
            ]);
        }

        $user = $request->user();
        $apiKey = $this->resolveKey($request, $user, $request->provider());

        $job = AiJob::query()->create([
            'user_id' => $user->id,
            'survey_id' => $report->survey_id,
            'kind' => JobKind::ReportSection,
            'status' => JobStatus::Queued,
            'progress' => 0,
            'message' => 'Régénération en attente…',
            'result_ref' => 'reports/'.$report->id,
            'input' => [
                'report_id' => $report->id,
                'heading' => $request->heading(),
                'instructions' => $request->instructions(),
                'provider' => $request->provider(),
            ],
        ]);

        RegenerateReportSectionJob::dispatch($job->uuid, $apiKey, $request->provider(), [
            'report_id' => $report->id,
            'heading' => $request->heading(),
            'instructions' => $request->instructions(),
        ]);

        return $this->acceptedJob($job);
    }

    // ------------------------------------------------------------------ fichiers archivés

    public function storeFile(StoreReportFileRequest $request, SurveyReport $report): JsonResponse
    {
        Gate::authorize('update', $report);

        $file = $request->file('file');
        $format = $request->fileFormat();
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension !== '' && $extension !== $format) {
            throw ValidationException::withMessages([
                'format' => ["L'extension du fichier (.{$extension}) ne correspond pas au format « {$format} »."],
            ]);
        }

        $disk = self::disk();
        $filename = sprintf('%s-%s.%s', Str::slug(mb_substr((string) $report->title, 0, 60)) ?: 'rapport', now()->format('Ymd-His'), $format);
        $path = sprintf('surveys/%d/reports/%d/%s-%s', $report->survey_id, $report->id, Str::random(8), $filename);

        Storage::disk($disk)->put($path, (string) file_get_contents($file->getRealPath() ?: $file->getPathname()));

        $files = $report->files();
        $entry = [
            'id' => 1 + (int) max(array_merge([0], array_map(static fn (array $f): int => (int) ($f['id'] ?? 0), $files))),
            'format' => $format,
            'filename' => $filename,
            'label' => $request->label(),
            'size' => (int) $file->getSize(),
            'disk' => $disk,
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'created_by' => ['id' => $request->user()->id, 'name' => $request->user()->name],
            'created_at' => now()->toIso8601String(),
        ];
        $files[] = $entry;

        $report->forceFill(['generated_files' => $files])->save();

        return $this->ok(ReportFileResource::present($entry, $report), [], 201);
    }

    /**
     * Téléchargement d'un fichier archivé — route **signée** (`URL::temporarySignedRoute`), sans Sanctum,
     * comme `GET /media/{id}` (B-07).
     */
    public function downloadFile(SurveyReport $report, int $fileId): StreamedResponse|Response
    {
        $entry = null;
        foreach ($report->files() as $file) {
            if ((int) ($file['id'] ?? 0) === $fileId) {
                $entry = $file;
                break;
            }
        }
        abort_if($entry === null, 404, 'Fichier introuvable.');

        $disk = Storage::disk((string) ($entry['disk'] ?? self::disk()));
        abort_unless($disk->exists((string) $entry['path']), 404, 'Fichier introuvable sur le disque.');

        return $disk->download((string) $entry['path'], (string) ($entry['filename'] ?? 'rapport'), [
            'Content-Type' => (string) ($entry['mime'] ?? 'application/octet-stream'),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    public static function disk(): string
    {
        return (string) config('filesystems.survey_media_disk', 'local');
    }

    /**
     * `409 {code: datasource_not_ready}` tant que la source de données n'a pas été matérialisée.
     */
    private function requireDatasource(Survey $survey): ?JsonResponse
    {
        $datasource = SurveyDatasource::query()->where('survey_id', $survey->id)->first();
        if ($datasource !== null && $datasource->isMaterialized()) {
            return null;
        }

        return $this->fail(
            'La source de données de ce questionnaire n\'est pas encore prête : reconstruisez-la puis réessayez.',
            409,
            null,
            ['code' => 'datasource_not_ready'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metaOf(SurveyReport $report): array
    {
        $meta = is_array($report->meta) ? $report->meta : [];

        return $meta === [] ? [] : $meta;
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
