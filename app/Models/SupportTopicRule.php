<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTopicRule extends Model
{
    protected $fillable = [
        'category',
        'keywords',
        'priority',
        'is_enabled',
    ];

    protected $casts = [
        'keywords' => 'array',
        'is_enabled' => 'boolean',
    ];
}
