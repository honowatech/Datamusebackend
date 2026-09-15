<?php

namespace App\Models;

use Database\Factories\SurveyDatasourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Source de données matérialisée d'un questionnaire (SQLite TargetDatabase, plan § 5.2).
 */
class SurveyDatasource extends Model
{
    /** @use HasFactory<SurveyDatasourceFactory> */
    use HasFactory;

    protected $fillable = [
        'survey_id',
        'target_database_id',
        'file_version',
        'dirty',
        'dirty_since',
        'last_materialized_at',
        'row_count',
        'last_error',
    ];

    protected $attributes = [
        'file_version' => 0,
        'dirty' => false,
        'row_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'file_version' => 'integer',
            'dirty' => 'boolean',
            'dirty_since' => 'datetime',
            'last_materialized_at' => 'datetime',
            'row_count' => 'integer',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function targetDatabase(): BelongsTo
    {
        return $this->belongsTo(TargetDatabase::class);
    }

    public function scopeDirty(Builder $query): Builder
    {
        return $query->where('dirty', true);
    }

    public function markDirty(): static
    {
        if (! $this->dirty) {
            $this->forceFill(['dirty' => true, 'dirty_since' => now()])->save();
        }

        return $this;
    }

    public function isMaterialized(): bool
    {
        return $this->target_database_id !== null && $this->last_materialized_at !== null;
    }
}
