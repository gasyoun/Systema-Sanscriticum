<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * H6095: строка журнала размещений — одна публикация /ga-ключа. БЕЗ
 * персональных данных: только слаг, UTM-кортеж и агрегаты кликов.
 *
 * @property string $link
 * @property string $campaign
 * @property string $creative
 * @property string $channel
 * @property string|null $kind
 * @property string|null $destination
 * @property array<string, string>|null $utm
 * @property string|null $permalink
 * @property Carbon|null $published_at
 * @property int|null $clicks_24h
 * @property int|null $clicks_72h
 */
class AnonsPlacement extends Model
{
    protected $fillable = [
        'link', 'campaign', 'creative', 'channel', 'kind', 'destination', 'utm',
        'permalink', 'published_at', 'clicks_24h', 'clicks_72h',
    ];

    protected $casts = [
        'utm' => 'array',
        'published_at' => 'datetime',
    ];
}
