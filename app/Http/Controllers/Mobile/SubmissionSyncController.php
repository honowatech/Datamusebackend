<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Mobile\SyncSubmissionsRequest;
use App\Http\Resources\SubmissionSyncResultResource;
use App\Services\Survey\SubmissionSyncService;
use Illuminate\Http\JsonResponse;

/**
 * `POST /mobile/submissions` — synchronisation d'un lot de soumissions (B-07).
 *
 * Le lot n'est **jamais** tout-ou-rien : chaque élément est traité dans sa propre transaction et
 * reçoit son propre `status` (`accepted | duplicate | updated | rejected | conflict`) ; la réponse
 * HTTP reste `200` même si certains éléments échouent. Toute la logique est dans
 * `SubmissionSyncService` (réutilisé par le canal public B-12).
 *
 * Un lot de plus de 50 éléments est refusé en `413` avant toute écriture (plan § 5.3 ; le contrat
 * OpenAPI mentionne `422` pour ce cas — écart documenté dans `docs/AVANCEMENT.md`).
 */
class SubmissionSyncController extends ApiController
{
    public function __construct(private readonly SubmissionSyncService $sync) {}

    public function store(SyncSubmissionsRequest $request): JsonResponse
    {
        $payloads = $request->submissions();

        if (count($payloads) > SubmissionSyncService::MAX_BATCH) {
            return $this->fail(
                'Lot trop volumineux : '.SubmissionSyncService::MAX_BATCH.' soumissions au maximum par envoi.',
                413,
                ['submissions' => ['Le lot doit contenir au plus '.SubmissionSyncService::MAX_BATCH.' soumissions.']],
            );
        }

        // E-01 : le mobile marque chaque envoi avec `X-App-Version` (M-09) ; la version est
        // rafraîchie sur `devices.app_version` et figée sur chaque `submissions.app_version`.
        $results = $this->sync->syncBatch(
            $request->user(),
            $payloads,
            appVersion: $request->header(SubmissionSyncService::APP_VERSION_HEADER),
        );

        return $this->ok([
            'results' => SubmissionSyncResultResource::collection($results),
        ]);
    }
}
