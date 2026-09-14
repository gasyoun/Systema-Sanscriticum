<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H3297 — маркер «уведомление о старте сезона N по каналу X доставлено/поставлено
 * в очередь пользователю Y». Идемпотентность рассылки: повторный прогон
 * season:notify-start пропускает пары, уже записанные здесь.
 */
class SeasonNotification extends Model
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_TELEGRAM = 'telegram';

    protected $fillable = [
        'season_id',
        'user_id',
        'channel',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
