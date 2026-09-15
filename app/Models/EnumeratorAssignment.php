<?php

namespace App\Models;

use Database\Factories\EnumeratorAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnumeratorAssignment extends Model
{
    /** @use HasFactory<EnumeratorAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'survey_id',
        'user_id',
        'zone',
        'quota_target',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'quota_target' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enumerator(): BelongsTo
    {
        return $this->user();
    }

    /** Affectations actives à l'instant donné (fenêtre starts_at/ends_at ouverte ou absente). */
    public function scopeCurrent(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }

    public function isCurrent(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return ($this->starts_at === null || $this->starts_at <= $at)
            && ($this->ends_at === null || $this->ends_at >= $at);
    }
}
