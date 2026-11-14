<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plan de pondération d'une enquête : marges cibles du calage et bornes des poids
 * (docs/analysis-api.md § 5).
 */
class SurveyWeightingPlan extends Model
{
    protected $fillable = [
        'survey_id',
        'enabled',
        'margins',
        'min_weight',
        'max_weight',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'margins' => 'array',
            'min_weight' => 'float',
            'max_weight' => 'float',
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
