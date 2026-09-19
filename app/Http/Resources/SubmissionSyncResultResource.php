<?php

namespace App\Http\Resources;

use App\Services\Survey\FollowUpSyncResult;
use App\Services\Survey\SubmissionSyncResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `SubmissionSyncResult` : un élément de `data.results[]` de `POST /mobile/submissions`.
 *
 * Une réponse d'étape de suivi envoyée sur cette route (voie de compatibilité du contrat) est
 * déléguée à `FollowUpService` : l'élément correspondant est alors un `FollowUpSyncResult`, rendu
 * tel quel (`stage_key`, `entry_id`, `entry_status`… au lieu de `server_id` / `fiche_code`).
 *
 * Écart documenté vs le contrat : en plus de `errors` (liste `DfsIssue {path, code, message}`),
 * la réponse porte `errors_by_key` (`{question_key: [message]}`) pour les clients qui affichent
 * les messages directement sous la question.
 *
 * @mixin SubmissionSyncResult|FollowUpSyncResult
 */
class SubmissionSyncResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
