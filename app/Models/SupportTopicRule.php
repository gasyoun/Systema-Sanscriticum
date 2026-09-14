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
        'plane',
        'pattern_hash',
        'negations',
    ];

    protected $casts = [
        'keywords' => 'array',
        'negations' => 'array',
        'is_enabled' => 'boolean',
    ];
}
