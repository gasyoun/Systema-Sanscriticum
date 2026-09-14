<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одно личное приглашение в одну волну опроса (H4297).
 *
 * Статусы:
 *  - queued:  резерв перед отправкой (идемпотентность; отправка ещё не начата);
 *  - sent:    Telegram подтвердил успех, message_id записан — единственное
 *             основание заявлять «доставлено»;
 *  - failed:  детерминированный отказ Telegram (4xx) — отправки точно не было,
 *             перезапуск команды имеет право повторить;
 *  - unknown: транспортный сбой без ответа/таймаут — сообщение могло уйти,
 *             вслепую не повторяем.
 */
class SurveyInvitation extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_UNKNOWN,
    ];

    protected $fillable = [
        'survey_slug',
        'user_id',
        'telegram_chat_id',
        'channel',
        'status',
        'telegram_message_id',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'telegram_chat_id' => 'integer',
        'telegram_message_id' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Резерв идемпотентности: queued/sent/unknown запрещают повторную отправку, failed — нет. */
    public function scopeBlockingRetry($query, string $slug)
    {
        return $query->where('survey_slug', $slug)
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_SENT, self::STATUS_UNKNOWN]);
    }
}
