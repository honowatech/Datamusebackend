<?php

namespace App\Models;

use App\Enums\FollowUpStatus;
use Database\Factories\FollowUpEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étape de suivi longitudinal (J+4, J+7, J+14...) attachée à une soumission (README § 14).
 */
class FollowUpEntry extends Model
{
    /** @use HasFactory<FollowUpEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'survey_id',
        'stage_key',
        'enumerator_id',
        'due_at',
        'window_ends_at',
        'status',
        'answers',
        'completed_at',
        'client_updated_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'window_ends_at' => 'datetime',
            'status' => FollowUpStatus::class,
            'answers' => 'array',
            'completed_at' => 'datetime',
            'client_updated_at' => 'datetime',
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

    public function enumerator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enumerator_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', FollowUpStatus::Pending->value);
    }

    public function scopeForEnumerator(Builder $query, int $userId): Builder
    {
        return $query->where('enumerator_id', $userId);
    }

    /** Entrées en attente dont l'échéance tombe dans [from, to]. */
    public function scopeDueBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->pending()->whereBetween('due_at', [$from, $to]);
    }

    /** Entrées en attente dont la fenêtre est dépassée (candidates à `missed`). */
    public function scopeOverdue(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        return $query->pending()->where('window_ends_at', '<', $at ?? now());
    }

    public function isPending(): bool
    {
        return $this->status === FollowUpStatus::Pending;
    }
}
