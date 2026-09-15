<?php

namespace App\Http\Controllers;

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

        return $this->ok(new JobResource($job));
    }
}
