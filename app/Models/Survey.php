<?php

namespace App\Models;

use App\Enums\SurveyStatus;
use App\Enums\VersionStatus;
use Database\Factories\SurveyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Questionnaire d'un projet. Ses définitions vivent dans survey_versions (DFS v1).
 */
class Survey extends Model
{
    /** @use HasFactory<SurveyFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'created_by',
        'title',
        'slug',
        'status',
        'current_version_id',
        'published_version_id',
        'submissions_count',
        'last_submission_at',
    ];

    protected $attributes = [
        'status' => 'draft',
        'submissions_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => SurveyStatus::class,
            'submissions_count' => 'integer',
            'last_submission_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relations

    public function project(): BelongsTo
    {
        return $this->belongsTo(SurveyProject::class, 'project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SurveyVersion::class)->orderBy('version');
    }

    /** Version pointée par published_version_id. */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'published_version_id');
    }

    /** Brouillon courant pointé par current_version_id. */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'current_version_id');
    }

    /** Brouillon en cours (status = draft), indépendamment du pointeur. */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(SurveyVersion::class)
            ->where('status', VersionStatus::Draft->value)
            ->latestOfMany('version');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(SurveyVersion::class)->latestOfMany('version');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EnumeratorAssignment::class);
    }

    /** Enquêteurs affectés, avec pivot (zone, quota_target, starts_at, ends_at). */
    public function enumerators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'enumerator_assignments', 'survey_id', 'user_id')
            ->withPivot(['zone', 'quota_target', 'starts_at', 'ends_at'])
            ->withTimestamps();
    }

    public function datasource(): HasOne
    {
        return $this->hasOne(SurveyDatasource::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(SurveyReport::class);
    }

    public function publicLinks(): HasMany
    {
        return $this->hasMany(PublicLink::class);
    }

    public function followUpEntries(): HasMany
    {
        return $this->hasMany(FollowUpEntry::class);
    }

    public function codebooks(): HasMany
    {
        return $this->hasMany(VerbatimCodebook::class);
    }

    public function codings(): HasMany
    {
        return $this->hasMany(VerbatimCoding::class);
    }

    public function aiJobs(): HasMany
    {
        return $this->hasMany(AiJob::class);
    }

    // ------------------------------------------------------------------ scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SurveyStatus::Active->value);
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_version_id');
    }

    // ------------------------------------------------------------------ helpers

    public function isActive(): bool
    {
        return $this->status === SurveyStatus::Active;
    }

    public function isPublished(): bool
    {
        return $this->published_version_id !== null;
    }
}
