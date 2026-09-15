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
        'provider',
        'model',
        'content_md',
        'content_json',
        'generated_files',
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
            'tokens_used' => 'integer',
        ];
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
