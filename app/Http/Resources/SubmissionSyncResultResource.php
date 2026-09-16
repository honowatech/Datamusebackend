<?php

namespace App\Http\Resources;

use App\Services\Survey\SubmissionSyncResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `SubmissionSyncResult` : un élément de `data.results[]` de `POST /mobile/submissions`.
 *
 * Écart documenté vs le contrat : en plus de `errors` (liste `DfsIssue {path, code, message}`),
 * la réponse porte `errors_by_key` (`{question_key: [message]}`) pour les clients qui affichent
 * les messages directement sous la question.
 *
 * @mixin SubmissionSyncResult
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
