<?php

namespace App\Models;

use App\Enums\ProjectRole;
use Database\Factories\ProjectInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProjectInvitation extends Model
{
    /** @use HasFactory<ProjectInvitationFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'email',
        'role',
        'zone',
        'token',
        'join_code',
        'max_uses',
        'uses',
        'expires_at',
        'invited_by',
        'accepted_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'role' => ProjectRole::class,
            'max_uses' => 'integer',
            'uses' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProjectInvitation $invitation) {
            $invitation->token ??= self::generateToken();
        });
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /** Code de connexion court, sans caractères ambigus (0/O, 1/I/L). */
    public static function generateJoinCode(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(SurveyProject::class, 'project_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->uses >= $this->max_uses;
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isExhausted();
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereColumn('uses', '<', 'max_uses');
    }
}
