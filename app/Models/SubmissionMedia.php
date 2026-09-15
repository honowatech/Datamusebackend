<?php

namespace App\Models;

use App\Enums\MediaState;
use Database\Factories\SubmissionMediaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fichier média attaché à une question (photo, signature, audio). Stocké sur un disque privé.
 */
class SubmissionMedia extends Model
{
    /** @use HasFactory<SubmissionMediaFactory> */
    use HasFactory;

    protected $table = 'submission_media';

    protected $fillable = [
        'submission_id',
        'question_key',
        'repeat_index',
        'disk',
        'path',
        'mime',
        'size',
        'sha256',
        'state',
    ];

    protected $attributes = [
        'repeat_index' => 0,
        'disk' => 'local',
        'size' => 0,
        'state' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'repeat_index' => 'integer',
            'size' => 'integer',
            'state' => MediaState::class,
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('state', MediaState::Pending->value);
    }

    public function scopeUploaded(Builder $query): Builder
    {
        return $query->where('state', MediaState::Uploaded->value);
    }

    public function isUploaded(): bool
    {
        return $this->state === MediaState::Uploaded;
    }
}
