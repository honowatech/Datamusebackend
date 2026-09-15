<?php

namespace App\Http\Resources;

use App\Models\SurveyVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schémas OpenAPI `SurveyVersionSummary` (défaut) et `SurveyVersion` (`withDefinition()` : `GET /versions/{n}`,
 * `POST /versions/{n}/fork`). `definition_hash` n'est exposé que pour une version publiée ou archivée
 * (null pour un brouillon, dont le texte n'est pas figé). `withWarnings()` ajoute `warnings` (`PUT /draft`).
 *
 * @mixin SurveyVersion
 */
class SurveyVersionResource extends JsonResource
{
    private bool $withDefinition = false;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $warnings = null;

    public function withDefinition(bool $with = true): static
    {
        $this->withDefinition = $with;

        return $this;
    }

    /**
     * @param  array<int, array<string, mixed>>  $warnings
     */
    public function withWarnings(array $warnings): static
    {
        $this->warnings = array_values($warnings);

        return $this;
    }

    public function toArray(Request $request): array
    {
        $settings = $this->resource->settings();

        return [
            'id' => $this->id,
            'survey_id' => $this->survey_id,
            'version' => $this->version,
            'status' => $this->status?->value,
            'revision' => $this->revision,
            'definition_hash' => $this->resource->isDraft() ? null : $this->definition_hash,
            'question_count' => is_array($this->question_index) ? count($this->question_index) : 0,
            'languages' => array_values(is_array($settings['languages'] ?? null) ? $settings['languages'] : []),
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by' => $this->published_by ? UserResource::ref($this->publisher) : null,
            'note' => null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'definition' => $this->when($this->withDefinition, fn () => $this->definition ?? (object) []),
            'warnings' => $this->when($this->warnings !== null, fn () => $this->warnings),
        ];
    }
}
