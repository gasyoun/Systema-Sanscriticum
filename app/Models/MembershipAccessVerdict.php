<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MembershipAccessVerdict extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'course_id',
        'lesson_id',
        'surface',
        'mode',
        'would_allow',
        'reason',
        'requested_at',
    ];

    protected $casts = [
        'would_allow' => 'boolean',
        'requested_at' => 'datetime',
    ];
}
