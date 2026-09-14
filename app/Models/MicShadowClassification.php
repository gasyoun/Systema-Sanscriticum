<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * H4608 — строка shadow-телеметрии MIC: (входящее сообщение × плоскость).
 *
 * Категория null = плоскость не сработала (та самая null-телеметрия G2,
 * которую раньше замечали только ретроспективно — H3380 8/8 null).
 * Текста сообщения здесь нет и быть не может — только sha256-хеш.
 *
 * @property int $id
 * @property string $channel
 * @property int|null $conversation_id
 * @property int|null $message_id
 * @property string $text_hash
 * @property string $plane
 * @property string|null $category
 * @property string|null $reason
 * @property array<int, array{category: string, reason: string}>|null $near_miss
 * @property Carbon|null $classified_at
 */
class MicShadowClassification extends Model
{
    protected $table = 'mic_shadow_classifications';

    protected $fillable = [
        'channel',
        'conversation_id',
        'message_id',
        'text_hash',
        'plane',
        'category',
        'reason',
        'near_miss',
        'classified_at',
    ];

    protected $casts = [
        'near_miss' => 'array',
        'conversation_id' => 'integer',
        'message_id' => 'integer',
        'classified_at' => 'datetime',
    ];
}
