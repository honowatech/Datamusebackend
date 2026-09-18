<?php

namespace App\Http\Resources;

use App\Models\VerbatimCodebook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `Codebook` (B-11).
 *
 * `themes[].count`, `coded_count` et `uncoded_count` ne sont renseignés que lorsque le contrôleur a posé
 * les compteurs (`VerbatimService::counts()`) : la liste des versions n'en a pas besoin, le détail si.
 *
 * @mixin VerbatimCodebook
 */
class CodebookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{coded: int, uncoded: int, by_theme: array<string, int>}|null $counts */
        $counts = $this->resource->getAttribute('counts');

        $themes = [];
        foreach (is_array($this->themes) ? $this->themes : [] as $theme) {
            if (! is_array($theme)) {
                continue;
            }
            if ($counts !== null) {
                $theme['count'] = (int) ($counts['by_theme'][$theme['key'] ?? ''] ?? 0);
            }
            $themes[] = $theme;
        }

        return [
            'id' => (int) $this->id,
            'survey_id' => (int) $this->survey_id,
            'question_key' => $this->question_key,
            'version' => (int) $this->version,
            'themes' => $themes,
            'source' => $this->source,
            'coded_count' => $counts === null ? null : $counts['coded'],
            'uncoded_count' => $counts === null ? null : $counts['uncoded'],
            'provider' => $this->resource->getAttribute('provider'),
            'model' => $this->resource->getAttribute('model'),
            // `verbatim_codebooks` n'a pas de colonne `created_by` (migration B-01) : champ optionnel du contrat.
            'created_by' => null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
