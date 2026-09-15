<?php

namespace App\Models;

use Database\Factories\VerbatimCodingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Codage d'un verbatim (thèmes attribués + sentiment) pour une soumission et une question.
 */
class VerbatimCoding extends Model
{
    /** @use HasFactory<VerbatimCodingFactory> */
    use HasFactory;

    public const SOURCE_AI = 'ai';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'submission_id',
        'survey_id',
        'question_key',
        'codebook_id',
        'themes',
        'sentiment',
        'confidence',
        'source',
    ];

    protected $attributes = [
        'source' => self::SOURCE_AI,
    ];

    protected function casts(): array
    {
        return [
            'themes' => 'array',
            'confidence' => 'decimal:3',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function codebook(): BelongsTo
    {
        return $this->belongsTo(VerbatimCodebook::class, 'codebook_id');
    }

    public function scopeForQuestion(Builder $query, int $surveyId, string $questionKey): Builder
    {
        return $query->where('survey_id', $surveyId)->where('question_key', $questionKey);
    }
}
