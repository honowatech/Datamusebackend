<?php

namespace App\Models;

use Database\Factories\VerbatimCodebookFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grille de thèmes (codebook) d'une question ouverte, versionnée par question.
 */
class VerbatimCodebook extends Model
{
    /** @use HasFactory<VerbatimCodebookFactory> */
    use HasFactory;

    public const SOURCE_AI = 'ai';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'survey_id',
        'question_key',
        'version',
        'themes',
        'source',
    ];

    protected $attributes = [
        'version' => 1,
        'source' => self::SOURCE_AI,
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'themes' => 'array',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function codings(): HasMany
    {
        return $this->hasMany(VerbatimCoding::class, 'codebook_id');
    }

    public function scopeForQuestion(Builder $query, int $surveyId, string $questionKey): Builder
    {
        return $query->where('survey_id', $surveyId)->where('question_key', $questionKey);
    }

    /** @return list<string> */
    public function themeKeys(): array
    {
        return array_values(array_filter(array_map(
            fn ($t) => is_array($t) ? ($t['key'] ?? null) : null,
            $this->themes ?? []
        )));
    }
}
