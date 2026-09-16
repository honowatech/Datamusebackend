<?php

namespace App\Models;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Appareil mobile enregistré par un utilisateur (POST /mobile/devices).
 * `device_id` est l'identifiant côté application ; `id` est la clé serveur référencée par submissions.
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_id',
        'platform',
        'model',
        'app_version',
        'push_token',
        'last_seen_at',
    ];

    protected $hidden = ['push_token'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function touchSeen(): static
    {
        $this->forceFill(['last_seen_at' => now()])->save();

        return $this;
    }
}
