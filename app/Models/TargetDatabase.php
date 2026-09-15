<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TargetDatabase extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    /**
     * Get the user that owns the target database connection.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
