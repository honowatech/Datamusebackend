<?php

namespace App\Models;

use App\Enums\JobKind;
use App\Enums\JobStatus;
use Database\Factories\AiJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Suivi d'une opération asynchrone (202 {job_id} puis GET /jobs/{id}).
 */
class AiJob extends Model
{
    /** @use HasFactory<AiJobFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'survey_id',
        'kind',
        'status',
        'progress',
        'message',
        'input',
        'result_ref',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'status' => 'queued',
        'progress' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => JobKind::class,
            'status' => JobStatus::class,
            'progress' => 'integer',
            'input' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiJob $job) {
            $job->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value]);
    }

    public function scopeKind(Builder $query, JobKind|string $kind): Builder
    {
        return $query->where('kind', $kind instanceof JobKind ? $kind->value : $kind);
    }

    // ------------------------------------------------------------------ transitions

    public function markRunning(?string $message = null): static
    {
        $this->forceFill([
            'status' => JobStatus::Running,
            'started_at' => $this->started_at ?? now(),
            'message' => $message ?? $this->message,
        ])->save();

        return $this;
    }

    public function setProgress(int $progress, ?string $message = null): static
    {
        $this->forceFill([
            'progress' => max(0, min(100, $progress)),
            'message' => $message ?? $this->message,
        ])->save();

        return $this;
    }

    public function markDone(?string $resultRef = null, ?string $message = null): static
    {
        $this->forceFill([
            'status' => JobStatus::Done,
            'progress' => 100,
            'result_ref' => $resultRef ?? $this->result_ref,
            'message' => $message ?? $this->message,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function markFailed(string $error, ?string $message = null): static
    {
        $this->forceFill([
            'status' => JobStatus::Failed,
            'error' => $error,
            'message' => $message ?? $this->message,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
