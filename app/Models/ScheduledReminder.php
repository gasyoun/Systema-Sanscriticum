<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Разовое напоминание студенту на конкретную дату/время, поставленное
 * куратором вручную (например, попросить отзыв о курсе после возвращения
 * из поездки). Отправляется автоматически командой reminders:send-due —
 * снимает риск того, что человек забудет напомнить человеку.
 */
class ScheduledReminder extends Model
{
    protected $fillable = [
        'user_id',
        'created_by',
        'message',
        'to_telegram',
        'to_vk',
        'to_email',
        'scheduled_for',
        'sent_at',
    ];

    protected $casts = [
        'to_telegram' => 'boolean',
        'to_vk' => 'boolean',
        'to_email' => 'boolean',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('sent_at')->where('scheduled_for', '<=', now());
    }

    /**
     * Отправить по включённым каналам. Возвращает true, если ушёл хотя бы один.
     */
    public function send(): bool
    {
        $user = $this->user;
        if (! $user) {
            return false;
        }

        $hasTg = $this->to_telegram && ! empty($user->telegram_id);
        $hasVk = $this->to_vk && ! empty($user->vk_id);
        $hasEmail = $this->to_email && filter_var($user->email, FILTER_VALIDATE_EMAIL);

        if (! $hasTg && ! $hasVk && ! $hasEmail) {
            return false;
        }

        if ($hasTg || $hasVk) {
            \App\Jobs\SendMessengerAlerts::dispatch($user, $this->message, $hasTg, $hasVk);
        }
        if ($hasEmail) {
            \Illuminate\Support\Facades\Mail::to($user->email)
                ->queue(new \App\Mail\ScheduledReminderMail($this->message, $user->name));
        }

        return true;
    }
}
