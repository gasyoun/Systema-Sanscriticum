<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Track C (H164, D10): ad-hoc "записи" class-reminder schedule for the
 * zapisi_ORSbot chat. Independent of Lesson/Group/Schedule (curriculum
 * models) — see the migration docblock.
 */
class ZapisiClassSchedule extends Model
{
    protected $fillable = [
        'chat_id',
        'starts_at',
        'message_template',
        'sent_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
