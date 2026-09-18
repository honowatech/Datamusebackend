<?php

namespace App\Http\Resources;

use App\Models\SurveyReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `Report` (B-11).
 *
 * `content_md` / `content_json` ne sont renvoyés que par le détail : la liste (`GET /surveys/{id}/reports`)
 * pose l'attribut transient `summary_only` pour les omettre (contrat : « Sans `content_md`/`content_json` »).
 * Les options du `ReportIn` (`tone`, `length`, `sections`, `include_verbatims`) sont stockées dans la
 * colonne `options` et **aplaties** ici, conformément au contrat.
 *
 * @mixin SurveyReport
 */
class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summaryOnly = (bool) $this->resource->getAttribute('summary_only');

        $payload = [
            'id' => (int) $this->id,
            'survey_id' => (int) $this->survey_id,
            'title' => $this->title,
            'brief' => $this->brief,
            'orientation' => $this->orientation?->value,
            'audience' => $this->audience,
            'language' => $this->language,
            'tone' => $this->option('tone', 'factuel'),
            'length' => $this->option('length', 'moyen'),
            'sections' => array_values((array) $this->option('sections', [])),
            'include_verbatims' => (bool) $this->option('include_verbatims', true),
            'status' => $this->status?->value,
            'job_id' => $this->job_id,
            'provider' => $this->provider,
            'model' => $this->model,
            'generated_files' => array_map(
                fn (array $file): array => ReportFileResource::present($file, $this->resource),
                $this->files(),
            ),
            'tokens_used' => $this->tokens_used === null ? null : (int) $this->tokens_used,
            'error' => $this->error,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester === null ? null : new UserResource($this->requester)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if (! $summaryOnly) {
            $payload['content_md'] = $this->content_md;
            $payload['content_json'] = is_array($this->content_json) ? $this->content_json : null;
        }

        return $payload;
    }
}
