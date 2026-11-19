<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Предложение исключить студента из учебного TG-чата за молчание при
 * закрытом в кабинете уроке — и его исход. Пишет только
 * App\Services\Discipline\ChatSilenceRemovalService.
 *
 * @property int $id
 * @property int $user_id
 * @property ?int $group_id
 * @property string $telegram_chat_id
 * @property string $telegram_user_id
 * @property string $status
 */
class ChatSilenceRemoval extends Model
{
    /** Предложение ушло админам, решения ещё нет. */
    public const STATUS_PROPOSED = 'proposed';

    /** Админ нажал «Исключить», идёт вызов Telegram (защита от двойного нажатия). */
    public const STATUS_EXECUTING = 'executing';

    /** Почтальон забанил. Вход по ссылке-заявке закрыт до разбана. */
    public const STATUS_REMOVED = 'removed';

    /** Telegram отказал (нет прав бота, чат не найден и т. п.). */
    public const STATUS_FAILED = 'failed';

    /** Админ отложил, или к моменту нажатия правило перестало выполняться. */
    public const STATUS_DISMISSED = 'dismissed';

    /** Предложение не нажали — следующий прогон предложит заново. */
    public const STATUS_EXPIRED = 'expired';

    /** Разбанен в дашборде «Записи (бот)». */
    public const STATUS_UNBANNED = 'unbanned';

    protected $fillable = [
        'user_id',
        'group_id',
        'telegram_chat_id',
        'telegram_user_id',
        'last_message_at',
        'lesson_id',
        'block_number',
        'status',
        'proposed_at',
        'next_offer_at',
        'decided_by',
        'decided_at',
        'note',
        'unbanned_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'proposed_at' => 'datetime',
        'next_offer_at' => 'datetime',
        'decided_at' => 'datetime',
        'unbanned_at' => 'datetime',
        'block_number' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** Исключён и ещё не разбанен. */
    public function scopeActiveBan(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REMOVED);
    }

    public static function isBanned(int $userId, string $chatId): bool
    {
        return self::query()
            ->activeBan()
            ->where('user_id', $userId)
            ->where('telegram_chat_id', $chatId)
            ->exists();
    }
}
