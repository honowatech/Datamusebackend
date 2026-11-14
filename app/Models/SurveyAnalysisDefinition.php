<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Segment enregistré ou variable calculée d'une enquête (docs/analysis-api.md § 9).
 */
class SurveyAnalysisDefinition extends Model
{
    public const KIND_SEGMENT = 'segment';

    public const KIND_VARIABLE = 'variable';

    protected $fillable = [
        'survey_id',
        'kind',
        'key',
        'label',
        'description',
        'level',
        'expression',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'expression' => 'array',
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
