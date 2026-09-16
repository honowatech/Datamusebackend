<?php

namespace App\Http\Controllers;

use App\Enums\JobStatus;
use App\Http\Resources\JobResource;
use App\Models\AiJob;
use App\Policies\Concerns\ChecksProjectRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suivi des opérations asynchrones : `GET /jobs/{uuid}` (contrat : tag Jobs).
 */
class JobController extends ApiController
{
    use ChecksProjectRole;

    public function show(Request $request, string $uuid): JsonResponse
    {
        $job = AiJob::query()->with('survey:id,project_id')->where('uuid', $uuid)->firstOrFail();
        $user = $request->user();

        $allowed = $user->isAdmin()
            || $job->user_id === $user->id
            || ($job->survey !== null && $this->isMember($user, $job->survey->project_id));

        abort_unless($allowed, 403, "Cette action n'est pas autorisée.");

        // `Job.result` : résultat embarqué, uniquement lorsque le job a réussi (B-06b). Selon le job :
        // proposition DFS `{definition, warnings}`, ou `{survey_id, version, revision, warnings}`.
        // Attribut transient (aucune colonne `result` : la valeur vient de `ai_jobs.output`).
        if ($job->status === JobStatus::Done && is_array($job->output)) {
            $job->setAttribute('result', $job->output);
        }

        return $this->ok(new JobResource($job));
    }
}
