<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemPrompt extends Model
{
    protected $fillable = [
        'name',
        'content',
        'is_default'
    ];
}
