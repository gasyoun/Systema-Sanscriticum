<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Голос участника чата в опросе бота (последний; пустой option_ids — отозван).
 *
 * @property array<int, int> $option_ids
 */
class TelegramPollAnswer extends Model
{
    protected $fillable = [
        'telegram_poll_id',
        'telegram_user_id',
        'user_id',
        'tg_username',
        'tg_name',
        'option_ids',
        'answered_at',
    ];

    protected $casts = [
        'option_ids' => 'array',
        'answered_at' => 'datetime',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(TelegramPoll::class, 'telegram_poll_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRetracted(): bool
    {
        return ($this->option_ids ?? []) === [];
    }

    /** Как показать голосующего: имя из кабинета, иначе из Telegram. */
    public function displayName(): string
    {
        if ($this->user !== null) {
            return (string) $this->user->name;
        }

        $name = trim((string) $this->tg_name);
        $username = $this->tg_username ? '@'.$this->tg_username : '';

        return trim($name.($name !== '' && $username !== '' ? ' ' : '').$username) ?: 'id '.$this->telegram_user_id;
    }
}
