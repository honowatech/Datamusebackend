<?php

namespace App\Http\Resources;

use App\Models\AiJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `Job` : {id (uuid), kind, status, progress, message, result_ref, result, error, survey_id, created_at, updated_at}.
 * `result` : résultat embarqué si petit (attribut transient `result` posé par le contrôleur / job, B-06), sinon null.
 *
 * @mixin AiJob
 */
class JobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'kind' => $this->kind?->value,
            'status' => $this->status?->value,
            'progress' => (int) $this->progress,
            'message' => $this->message,
            'result_ref' => $this->result_ref,
            'result' => $this->resource->getAttribute('result'),
            'error' => $this->error,
            'survey_id' => $this->survey_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
