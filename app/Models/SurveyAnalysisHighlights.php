<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faits saillants enregistrés d'une enquête (docs/analysis-api.md § 8).
 */
class SurveyAnalysisHighlights extends Model
{
    protected $table = 'survey_analysis_highlights';

    protected $fillable = [
        'survey_id',
        'dataset_materialized_at',
        'weighted',
        'engine',
        'params',
        'items',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'dataset_materialized_at' => 'datetime',
            'weighted' => 'boolean',
            'params' => 'array',
            'items' => 'array',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
