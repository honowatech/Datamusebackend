<?php

namespace App\Http\Controllers\Survey;

use App\Enums\JobKind;
use App\Enums\SubmissionStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\SubmissionResource;
use App\Jobs\RecomputeSubmissionFlagsJob;
use App\Models\AiJob;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Services\Survey\SubmissionQualityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Supervision terrain (B-10, contrat : tag Supervision) — rôle superviseur.
 *
 * - `GET /surveys/{id}/supervision/flags` : fiches portant au moins un drapeau (ou le drapeau `flag`),
 *   triées par `suspicion_score` décroissant, paginées, au schéma `FlaggedSubmission` (résumé +
 *   `flag_details` explicités, `duplicate_of`, `answers_preview`).
 * - `POST /surveys/{id}/supervision/recompute` : `202 {job_id}` (job `recompute_flags`, unique par
 *   questionnaire) ; un appel pendant un recalcul en cours renvoie le `job_id` déjà en file.
 */
class SupervisionController extends ApiController
{
    /** Nombre de réponses clés exposées dans `answers_preview`. */
    public const PREVIEW_KEYS = 8;

    public function __construct(private readonly SubmissionQualityService $quality) {}

    public function flags(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        $query = Submission::query()
            ->where('survey_id', $survey->id)
            ->with(['enumerator:id,name', 'reviewer:id,name', 'followUps:id,submission_id,status'])
            ->withCount(['media as media_pending_count' => fn ($q) => $q->where('state', '!=', 'uploaded')]);

        $flag = $request->query('flag');
        if (is_string($flag) && in_array($flag, Submission::FLAGS, true)) {
            $query->where('flags', 'like', '%"'.$flag.'"%');
        } else {
            // « Au moins un drapeau » : le score est le résumé pondéré, il ne vaut 0 que sans drapeau.
            $query->where('suspicion_score', '>', 0);
        }

        $minScore = (int) $request->query('min_score', 0);
        if ($minScore > 0) {
            $query->where('suspicion_score', '>=', $minScore);
        }

        $enumeratorId = (int) $request->query('enumerator_id', 0);
        if ($enumeratorId > 0) {
            $query->where('enumerator_id', $enumeratorId);
        }

        $status = $request->query('status');
        if (is_string($status) && in_array($status, SubmissionStatus::values(), true)) {
            $query->where('status', $status);
        }

        $paginator = $query->orderByDesc('suspicion_score')->orderByDesc('id')
            ->paginate($this->perPage($request))->withQueryString();

        $versions = SurveyVersion::query()->where('survey_id', $survey->id)
            ->pluck('version', 'id')->map(fn ($v) => (int) $v)->all();
        $settingsCache = [];

        $data = [];
        foreach ($paginator->items() as $submission) {
            /** @var Submission $submission */
            $submission->setAttribute('version_number', $versions[$submission->survey_version_id] ?? null);
            $versionId = (int) $submission->survey_version_id;
            $settingsCache[$versionId] ??= SurveyVersion::query()->find($versionId)?->settings() ?? [];
            $settings = $settingsCache[$versionId];

            $data[] = SubmissionResource::summary($submission) + [
                'flag_details' => $this->quality->flagDetails($submission, $settings),
                'duplicate_of' => $submission->hasFlag(Submission::FLAG_DUPLICATE)
                    ? $this->quality->duplicateOf($submission, $settings)
                    : null,
                'answers_preview' => $this->preview($submission, $settings),
            ];
        }

        return $this->ok($data, ['pagination' => self::paginationMeta($paginator)]);
    }

    public function recompute(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('reviewSubmissions', $survey);

        $pending = AiJob::query()
            ->where('survey_id', $survey->id)
            ->kind(JobKind::RecomputeFlags)
            ->pending()
            ->latest('id')
            ->first();

        if ($pending !== null) {
            return $this->accepted($pending);
        }

        $job = AiJob::query()->create([
            'user_id' => $request->user()->id,
            'survey_id' => $survey->id,
            'kind' => JobKind::RecomputeFlags,
            'message' => 'Recalcul planifié.',
            'input' => ['survey_id' => $survey->id],
        ]);

        RecomputeSubmissionFlagsJob::dispatch($survey->id, $job->uuid);

        return $this->accepted($job->refresh());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Réponses utiles au drawer de supervision : `duplicate_keys` puis sources du code fiche.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function preview(Submission $submission, array $settings): array
    {
        $answers = is_array($submission->answers) ? $submission->answers : [];

        $keys = array_values(array_filter((array) ($settings['duplicate_keys'] ?? []), 'is_string'));
        foreach ((array) ($settings['fiche_code']['sources'] ?? []) as $source) {
            if (is_string($source)) {
                $keys[] = $source;
            }
        }

        $out = [];
        foreach (array_slice(array_unique($keys), 0, self::PREVIEW_KEYS) as $key) {
            if (array_key_exists($key, $answers)) {
                $out[$key] = $answers[$key];
            }
        }

        return $out;
    }
}
