<?php

namespace App\Models;

use Database\Factories\PublicLinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Lien public de collecte web anonyme (/s/{token}). `is_default` marque le lien web du questionnaire
 * (onglet « Lien Web », `WebLinkService`) : un seul à la fois.
 */
class PublicLink extends Model
{
    /** @use HasFactory<PublicLinkFactory> */
    use HasFactory;

    protected $fillable = [
        'survey_id',
        'token',
        'label',
        'expires_at',
        'max_responses',
        'responses_count',
        'is_active',
        'is_default',
        'created_by',
    ];

    protected $attributes = [
        'responses_count' => 0,
        'is_active' => true,
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'max_responses' => 'integer',
            'responses_count' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PublicLink $link) {
            $link->token ??= self::generateToken();
        });
    }

    public static function generateToken(): string
    {
        return Str::random(40);
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isFull(): bool
    {
        return $this->max_responses !== null && $this->responses_count >= $this->max_responses;
    }

    /** Lien acceptant encore des réponses (sinon 410 côté API). */
    public function isOpen(): bool
    {
        return $this->is_active && ! $this->isExpired() && ! $this->isFull();
    }
}
