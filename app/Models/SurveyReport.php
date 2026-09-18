<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\ReportOrientation;
use Database\Factories\SurveyReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rapport généré par IA depuis un brief (markdown + structure JSON ; fichiers DOCX/PDF archivés).
 */
class SurveyReport extends Model
{
    /** @use HasFactory<SurveyReportFactory> */
    use HasFactory;

    protected $fillable = [
        'survey_id',
        'requested_by',
        'title',
        'brief',
        'orientation',
        'audience',
        'language',
        'status',
        'job_id',
        'provider',
        'model',
        'error',
        'content_md',
        'content_json',
        'generated_files',
        'options',
        'meta',
        'tokens_used',
    ];

    protected $attributes = [
        'orientation' => 'commercial',
        'language' => 'fr',
        'status' => 'queued',
        'tokens_used' => 0,
    ];

    protected function casts(): array
    {
        return [
            'orientation' => ReportOrientation::class,
            'status' => JobStatus::class,
            'content_json' => 'array',
            'generated_files' => 'array',
            'options' => 'array',
            'meta' => 'array',
            'tokens_used' => 'integer',
        ];
    }

    /**
     * Option du `ReportIn` stockée dans `options` (`tone`, `length`, `sections`, `include_verbatims`).
     */
    public function option(string $key, mixed $default = null): mixed
    {
        $options = is_array($this->options) ? $this->options : [];

        return $options[$key] ?? $default;
    }

    /**
     * Fusionne des entrées dans `meta` (drapeau `content_json_stale`) sans écraser les autres.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeMeta(array $values): static
    {
        $this->meta = array_filter(
            array_merge(is_array($this->meta) ? $this->meta : [], $values),
            static fn ($v): bool => $v !== null,
        );

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function files(): array
    {
        return array_values(array_filter(
            is_array($this->generated_files) ? $this->generated_files : [],
            'is_array',
        ));
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDone(): bool
    {
        return $this->status === JobStatus::Done;
    }
}
