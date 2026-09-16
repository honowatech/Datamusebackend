<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'une soumission supprimée définitivement : son `uuid` reste « brûlé » afin que le mobile
 * qui le renvoie reçoive `duplicate` (B-07 `SubmissionSyncService`) plutôt que de recréer la fiche.
 */
class DeletedSubmission extends Model
{
    protected $fillable = [
        'uuid',
        'survey_id',
        'deleted_by',
        'fiche_code',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /** Enregistre l'uuid d'une soumission avant sa suppression (idempotent). */
    public static function remember(Submission $submission, ?int $userId = null): self
    {
        return self::query()->updateOrCreate(
            ['uuid' => $submission->uuid],
            [
                'survey_id' => $submission->survey_id,
                'deleted_by' => $userId,
                'fiche_code' => $submission->fiche_code,
                'deleted_at' => now(),
            ],
        );
    }

    public static function has(string $uuid): bool
    {
        return self::query()->where('uuid', $uuid)->exists();
    }
}
