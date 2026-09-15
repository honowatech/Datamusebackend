<?php

namespace App\Models;

use App\Enums\VersionStatus;
use App\Support\QuestionIndexBuilder;
use Database\Factories\SurveyVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Version d'un questionnaire (DFS v1). Cycle draft -> published -> archived (README § 15).
 */
class SurveyVersion extends Model
{
    /** @use HasFactory<SurveyVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'survey_id',
        'version',
        'status',
        'definition',
        'definition_hash',
        'revision',
        'question_index',
        'published_at',
        'published_by',
    ];

    protected $attributes = [
        'status' => 'draft',
        'revision' => 1,
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'revision' => 'integer',
            'status' => VersionStatus::class,
            'definition' => 'array',
            'question_index' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Dérive question_index et definition_hash à chaque changement de définition,
        // sauf si l'appelant les a fixés explicitement dans la même sauvegarde
        // (ex. hash figé sur le texte exact servi lors de la publication, B-05).
        static::saving(function (SurveyVersion $version) {
            if (! $version->isDirty('definition') || ! is_array($version->definition)) {
                return;
            }
            if (! $version->isDirty('question_index')) {
                $version->question_index = QuestionIndexBuilder::build($version->definition);
            }
            if (! $version->isDirty('definition_hash')) {
                $version->definition_hash = self::computeHash($version->canonicalJson());
            }
        });
    }

    // ------------------------------------------------------------------ hashing

    /**
     * SHA-256 hexadécimal des octets UTF-8 du texte JSON exact (README § 15).
     */
    public static function computeHash(string $json): string
    {
        return hash('sha256', $json);
    }

    /**
     * Encodage canonique servi au mobile : sans espaces, Unicode et slashes non échappés,
     * ordre des clés conservé.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function encodeCanonical(array $definition): string
    {
        return json_encode(
            $definition,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    public function canonicalJson(): string
    {
        return self::encodeCanonical($this->definition ?? []);
    }

    /**
     * Recalcule l'index et le hash depuis la définition courante (sans sauvegarder).
     */
    public function refreshDerived(): static
    {
        $definition = $this->definition ?? [];
        $this->question_index = QuestionIndexBuilder::build($definition);
        $this->definition_hash = self::computeHash(self::encodeCanonical($definition));

        return $this;
    }

    // ------------------------------------------------------------------ relations

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    // ------------------------------------------------------------------ scopes

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', VersionStatus::Published->value);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', VersionStatus::Draft->value);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', VersionStatus::Archived->value);
    }

    public function scopeForSurvey(Builder $query, int $surveyId): Builder
    {
        return $query->where('survey_id', $surveyId);
    }

    // ------------------------------------------------------------------ helpers

    public function isPublished(): bool
    {
        return $this->status === VersionStatus::Published;
    }

    public function isDraft(): bool
    {
        return $this->status === VersionStatus::Draft;
    }

    public function isArchived(): bool
    {
        return $this->status === VersionStatus::Archived;
    }

    /**
     * Entrée de l'index pour une clé de question.
     *
     * @return array<string, mixed>|null
     */
    public function question(string $key): ?array
    {
        return $this->question_index[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return is_array($this->definition['settings'] ?? null) ? $this->definition['settings'] : [];
    }
}
