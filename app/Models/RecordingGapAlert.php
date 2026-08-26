<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * H3557: факт «алерт recordings:gap-watch за этот набор пробелов уже уехал».
 * Ключ — sha256 отпечатка набора schedule_id; строка персистентна, поэтому
 * дедуп не умирает вместе с Redis-кэшем при автодеплое.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $window_label
 * @property int $send_count
 * @property Carbon $first_sent_at
 * @property Carbon $last_sent_at
 */
class RecordingGapAlert extends Model
{
    protected $fillable = [
        'fingerprint',
        'window_label',
        'send_count',
        'first_sent_at',
        'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'send_count' => 'integer',
            'first_sent_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }
}
