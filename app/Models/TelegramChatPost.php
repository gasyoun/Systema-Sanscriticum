<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H4199: сообщение, отправленное zapisi-ботом в чат группы, с обратной ссылкой
 * на строку расписания. Заполняется SendZapisiBotMessageJob после успешной
 * отправки (message_id — из ответа Telegram). Нужно, чтобы reply-команда
 * админа («Отмена занятия») на пост-напоминание сматчилась обратно в Schedule.
 */
class TelegramChatPost extends Model
{
    /** Пост-напоминание «Скоро занятие» (zapisi:remind-classes). */
    public const KIND_ZAPISI_REMINDER = 'zapisi_reminder';

    protected $fillable = [
        'schedule_id',
        'chat_id',
        'message_id',
        'kind',
        'posted_at',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
