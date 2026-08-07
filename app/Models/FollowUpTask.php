<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\WorkQueueReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * ЗАДАЧА МЕНЕДЖЕРУ / КУРАТОРУ — «позвонить/написать/встретиться до такого-то
 * числа» (GC-C3 / H1836; support link H2381).
 *
 * CRM-ветка: висит на {@see Deal}. Support-ветка (H2381): `deal_id` null +
 * `support_conversation_id` + optional `note` — Jivo-style follow-up from
 * Helpdesk. Academic reminders stay on ScheduledReminder/ReminderSuggestion.
 *
 * Единственный признак «сделано» — `done_at`. Закрытая сделка задачу НЕ
 * закрывает; менеджер/куратор закрывает задачу явно.
 */
class FollowUpTask extends Model
{
    use HasFactory;

    public const TYPE_CALL = 'call';

    public const TYPE_MESSAGE = 'message';

    public const TYPE_MEETING = 'meeting';

    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'deal_id',
        'support_conversation_id',
        'assigned_to',
        'type',
        'note',
        'due_at',
        'done_at',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    /**
     * Типы задач для селектов и подписей.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_CALL => 'Позвонить',
            self::TYPE_MESSAGE => 'Написать',
            self::TYPE_MEETING => 'Встреча',
            self::TYPE_OTHER => 'Другое',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function supportConversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isSupportTask(): bool
    {
        return $this->support_conversation_id !== null;
    }

    /** CRM deal tasks only (excludes support-dialog follow-ups). */
    public function scopeForDeals(Builder $query): Builder
    {
        return $query->whereNotNull('deal_id');
    }

    /** Support-dialog follow-ups only. */
    public function scopeForSupport(Builder $query): Builder
    {
        return $query->whereNotNull('support_conversation_id');
    }

    /** Незакрытые задачи. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('done_at');
    }

    /**
     * Бакет кокпита: открытые задачи, у которых срок наступил (или прошёл).
     * Сравнение по ДАТЕ, а не по времени — зеркало
     * {@see WorkQueueReport::leadsToContact()}: задача на сегодня
     * видна с утра, а не с наступлением часа.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->open()->whereDate('due_at', '<=', now()->toDateString());
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /** Срок прошёл (не «наступил сегодня»), задача ещё открыта. */
    public function isOverdue(): bool
    {
        return ! $this->isDone()
            && $this->due_at !== null
            && $this->due_at->startOfDay()->lt(now()->startOfDay());
    }

    /** Идемпотентно: повторное закрытие не сдвигает исходную отметку. */
    public function markDone(): void
    {
        if ($this->isDone()) {
            return;
        }

        $this->update(['done_at' => now()]);
    }

    /** Подпись задачи в списке: тип + заголовок сделки / support-диалог. */
    public function getLabelAttribute(): string
    {
        $type = self::types()[$this->type] ?? $this->type;

        if ($this->isSupportTask()) {
            $suffix = $this->note
                ? Str::limit($this->note, 40)
                : 'диалог #'.$this->support_conversation_id;

            return trim($type.' — '.$suffix);
        }

        $deal = $this->deal?->kanban_title;

        return trim($type.($deal ? ' — '.$deal : ''));
    }
}
