<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\ApiController;
use App\Services\Survey\SubmissionSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /mobile/submissions/status?uuids=` — réconciliation de la file d'envoi (B-07).
 *
 * Jusqu'à 200 uuid séparés par des virgules. États (`SubmissionStatusItem`) :
 * `unknown` (inconnu du serveur ou appartenant à quelqu'un d'autre), `received` (reçue, statut
 * serveur non terminal), `media_pending` (médias annoncés encore attendus), `complete` (tout reçu),
 * `deleted` (supprimée côté web : le mobile doit oublier sa copie locale).
 */
class SubmissionStatusController extends ApiController
{
    public const MAX_UUIDS = 200;

    public function __construct(private readonly SubmissionSyncService $sync) {}

    public function index(Request $request): JsonResponse
    {
        $raw = $request->query('uuids');
        $uuids = is_array($raw) ? $raw : array_filter(array_map('trim', explode(',', (string) $raw)), fn ($v) => $v !== '');
        $uuids = array_values($uuids);

        if ($uuids === []) {
            return $this->fail('Le paramètre `uuids` est obligatoire.', 422, [
                'uuids' => ['Le paramètre `uuids` est obligatoire.'],
            ]);
        }
        if (count($uuids) > self::MAX_UUIDS) {
            return $this->fail('Au plus '.self::MAX_UUIDS.' uuid par appel.', 422, [
                'uuids' => ['Au plus '.self::MAX_UUIDS.' uuid par appel.'],
            ]);
        }

        return $this->ok($this->sync->statusOf($request->user(), $uuids));
    }
}
