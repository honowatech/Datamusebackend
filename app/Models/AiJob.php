<?php

namespace App\Models;

use App\Enums\JobKind;
use App\Enums\JobStatus;
use App\Services\LlmProviderService;
use App\Services\LlmReplayProvider;
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
        'provider',
        'status',
        'progress',
        'message',
        'input',
        'result_ref',
        'output',
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
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiJob $job) {
            $job->uuid ??= (string) Str::uuid();
            $job->provider ??= LlmProviderService::effectiveProvider(
                is_array($job->input) ? ($job->input['provider'] ?? null) : null
            );
        });
    }

    /**
     * Le job a-t-il été (ou sera-t-il) servi par le fournisseur de rejeu ?
     */
    public function isSimulated(): bool
    {
        return $this->provider === LlmReplayProvider::PROVIDER;
    }

    /**
     * Suffixe « (simulé) » sur tout message de job produit en mode rejeu : l'origine artificielle du
     * résultat est visible dans l'API, dans l'interface et dans la base, sans exception.
     */
    private static function annotate(?string $message): ?string
    {
        if ($message === null || $message === '' || ! LlmProviderService::isReplay()) {
            return $message;
        }

        return str_ends_with($message, LlmReplayProvider::SIMULATED_SUFFIX)
            ? $message
            : $message.LlmReplayProvider::SIMULATED_SUFFIX;
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
            'message' => self::annotate($message) ?? $this->message,
        ])->save();

        return $this;
    }

    public function setProgress(int $progress, ?string $message = null): static
    {
        $this->forceFill([
            'progress' => max(0, min(100, $progress)),
            'message' => self::annotate($message) ?? $this->message,
        ])->save();

        return $this;
    }

    public function markDone(?string $resultRef = null, ?string $message = null, ?array $output = null): static
    {
        $this->forceFill([
            'status' => JobStatus::Done,
            'progress' => 100,
            'result_ref' => $resultRef ?? $this->result_ref,
            'output' => $output ?? $this->output,
            'message' => self::annotate($message) ?? $this->message,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function markFailed(string $error, ?string $message = null): static
    {
        $this->forceFill([
            'status' => JobStatus::Failed,
            'error' => $error,
            'message' => self::annotate($message) ?? $this->message,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
