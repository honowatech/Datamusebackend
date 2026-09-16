<?php

namespace App\Models;

use App\Enums\MediaState;
use Database\Factories\SubmissionMediaFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

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

    /**
     * Extension usuelle du type MIME reçu (sert au nom de fichier sur le disque privé).
     */
    public static function extensionFor(string $mime, string $fallback = 'bin'): string
    {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/mp4', 'audio/x-m4a', 'audio/m4a' => 'm4a',
            'audio/aac' => 'aac',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            default => $fallback,
        };
    }

    /**
     * Chemin de stockage : `surveys/{survey_id}/submissions/{uuid}/{key}[_{i}].{ext}` (plan § 5.4).
     */
    public static function storagePath(int $surveyId, string $uuid, string $questionKey, ?int $repeatIndex, string $extension): string
    {
        $name = $questionKey.(($repeatIndex ?? 0) > 0 ? '_'.$repeatIndex : '').'.'.$extension;

        return sprintf('surveys/%d/submissions/%s/%s', $surveyId, $uuid, $name);
    }

    /**
     * URL signée temporaire vers `GET /api/media/{id}` (route `media.show`, sans Sanctum).
     * Renvoie null tant que le fichier n'est pas reçu.
     */
    public function signedUrl(int $minutes = 30): ?string
    {
        if (! $this->isUploaded() || $this->id === null) {
            return null;
        }

        return URL::temporarySignedRoute('media.show', now()->addMinutes($minutes), ['media' => $this->id]);
    }

    /** Disque effectif du fichier (colonne `disk`, sinon disque configuré). */
    public function storage(): Filesystem
    {
        return Storage::disk($this->disk ?: config('filesystems.survey_media_disk', 'local'));
    }

    public function fileExists(): bool
    {
        return $this->path !== null && $this->storage()->exists($this->path);
    }
}
