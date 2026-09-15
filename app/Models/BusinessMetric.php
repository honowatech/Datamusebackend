<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessMetric extends Model
{
    protected $fillable = [
        'user_id',
        'target_database_id',
        'term',
        'sql_definition',
        'description',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targetDatabase(): BelongsTo
    {
        return $this->belongsTo(TargetDatabase::class);
    }
}
