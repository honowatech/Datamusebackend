<?php

namespace App\Models;

use App\Enums\SubmissionChannel;
use App\Enums\SubmissionStatus;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Soumission d'entretien (mobile, lien public ou web). Idempotente par `uuid`.
 */
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    public const FLAG_TOO_FAST = 'too_fast';

    public const FLAG_DUPLICATE = 'duplicate';

    public const FLAG_OFF_HOURS = 'off_hours';

    public const FLAG_GPS_MISSING = 'gps_missing';

    public const FLAG_GPS_OUTSIDE_ZONE = 'gps_outside_zone';

    public const FLAG_CLOCK_SKEW = 'clock_skew';

    /** Plafond de quota dépassé (`settings.quotas[].max`, B-07 SubmissionQualityService). */
    public const FLAG_QUOTA_EXCEEDED = 'quota_exceeded';

    /** @var list<string> */
    public const FLAGS = [
        self::FLAG_TOO_FAST,
        self::FLAG_DUPLICATE,
        self::FLAG_OFF_HOURS,
        self::FLAG_GPS_MISSING,
        self::FLAG_GPS_OUTSIDE_ZONE,
        self::FLAG_CLOCK_SKEW,
        self::FLAG_QUOTA_EXCEEDED,
    ];

    protected $fillable = [
        'uuid',
        'survey_id',
        'survey_version_id',
        'project_id',
        'enumerator_id',
        'device_id',
        'channel',
        'status',
        'fiche_code',
        'zone',
        'language',
        'started_at',
        'ended_at',
        'duration_seconds',
        'geo_lat',
        'geo_lng',
        'geo_accuracy',
        'geo',
        'answers',
        'end_reason',
        'answers_hash',
        'client_updated_at',
        'received_at',
        'flags',
        'suspicion_score',
        'quality_notes',
        'reviewed_by',
        'reviewed_at',
        'device_time_offset_ms',
        // ==== E-01 ==== version de l'application ayant transmis la fiche (`X-App-Version`)
        'app_version',
        // ==== /E-01 ====
    ];

    protected $attributes = [
        'channel' => 'mobile',
        'status' => 'submitted',
        'language' => 'fr',
        'suspicion_score' => 0,
    ];

    protected function casts(): array
    {
        return [
            'channel' => SubmissionChannel::class,
            'status' => SubmissionStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'geo_lat' => 'decimal:7',
            'geo_lng' => 'decimal:7',
            'geo_accuracy' => 'decimal:2',
            'geo' => 'array',
            'answers' => 'array',
            'client_updated_at' => 'datetime',
            'received_at' => 'datetime',
            'flags' => 'array',
            'suspicion_score' => 'integer',
            'reviewed_at' => 'datetime',
            'device_time_offset_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Submission $submission) {
            if ($submission->duration_seconds === null && $submission->started_at && $submission->ended_at) {
                $submission->duration_seconds = max(0, $submission->ended_at->getTimestamp() - $submission->started_at->getTimestamp());
            }
            if ($submission->answers_hash === null && is_array($submission->answers)) {
                $submission->answers_hash = self::hashAnswers($submission->answers);
            }
        });
    }

    /**
     * Empreinte stable des réponses (clés triées) pour la détection de doublons.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function hashAnswers(array $answers): string
    {
        $normalized = self::ksortRecursive($answers);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function ksortRecursive(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::ksortRecursive($v);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    // ------------------------------------------------------------------ relations

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'survey_version_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(SurveyProject::class, 'project_id');
    }

    public function enumerator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enumerator_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function media(): HasMany
    {
        return $this->hasMany(SubmissionMedia::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUpEntry::class);
    }

    public function codings(): HasMany
    {
        return $this->hasMany(VerbatimCoding::class);
    }

    // ------------------------------------------------------------------ scopes

    /** Soumissions comptées dans le dénominateur « valid » : status hors {rejected, screened_out}. */
    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNotIn('status', array_map(
            fn (SubmissionStatus $s) => $s->value,
            SubmissionStatus::invalid()
        ));
    }

    public function scopeForSurvey(Builder $query, int $surveyId): Builder
    {
        return $query->where('survey_id', $surveyId);
    }

    public function scopeStatus(Builder $query, SubmissionStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof SubmissionStatus ? $status->value : $status);
    }

    public function scopeScreenedOut(Builder $query): Builder
    {
        return $query->where('status', SubmissionStatus::ScreenedOut->value);
    }

    public function scopeForEnumerator(Builder $query, int $userId): Builder
    {
        return $query->where('enumerator_id', $userId);
    }

    public function scopeChannel(Builder $query, SubmissionChannel|string $channel): Builder
    {
        return $query->where('channel', $channel instanceof SubmissionChannel ? $channel->value : $channel);
    }

    public function scopeSuspicious(Builder $query, int $minScore = 1): Builder
    {
        return $query->where('suspicion_score', '>=', $minScore);
    }

    // ------------------------------------------------------------------ helpers

    public function isValid(): bool
    {
        return $this->status->isValid();
    }

    public function isScreenedOut(): bool
    {
        return $this->status === SubmissionStatus::ScreenedOut;
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags ?? [], true);
    }

    public function answer(string $key, mixed $default = null): mixed
    {
        return $this->answers[$key] ?? $default;
    }
}
