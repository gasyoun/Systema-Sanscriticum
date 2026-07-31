<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сообщение бота в Telegram-чате группы, привязанное к уроку (волна 2 #ДЗ, C).
 */
class LessonTelegramHook extends Model
{
    public const PURPOSE_OPEN_INVITE = 'open_invite';

    protected $fillable = [
        'lesson_id',
        'group_id',
        'telegram_chat_id',
        'telegram_message_id',
        'purpose',
    ];

    protected $casts = [
        'telegram_message_id' => 'integer',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
