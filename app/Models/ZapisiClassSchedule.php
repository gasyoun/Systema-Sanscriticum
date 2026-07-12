<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H164 (D10): ad-hoc "class starts at" row for the 1h-out reminder job
 * (zapisi:send-class-reminders). Deliberately independent of
 * lessons/Group/Tariff — those model course curriculum, not ad-hoc booking
 * slots taken in the @zapisi_ORSbot chat.
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

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }
}
