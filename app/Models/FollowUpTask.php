<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\WorkQueueReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ЗАДАЧА МЕНЕДЖЕРУ по сделке — «позвонить/написать/встретиться до такого-то
 * числа» (GC-C3 / H1836).
 *
 * Промоутит пару полей `leads.next_contact_at` + `leads.assigned_to` в реальный
 * объект: задач может быть НЕСКОЛЬКО, у каждой свой срок, тип и факт закрытия
 * (`done_at`) — именно это парой колонок на лиде выразить нельзя.
 *
 * Висит на {@see Deal}, а не на {@see Lead}: сделка — единица работы воронки
 * после GC-C1 (H1641). Задача НИЧЕГО не авторизует в денежном ядре — она даже
 * не наблюдает его: это чистый операторский слой поверх сделки.
 *
 * Единственный признак «сделано» — `done_at`. Закрытая сделка задачу НЕ
 * закрывает (иначе у «почему задача пропала» стало бы два ответа); менеджер
 * закрывает задачу явно.
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
        'assigned_to',
        'type',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
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

    /** Подпись задачи в списке: тип + заголовок сделки. */
    public function getLabelAttribute(): string
    {
        $type = self::types()[$this->type] ?? $this->type;
        $deal = $this->deal?->kanban_title;

        return trim($type.($deal ? ' — '.$deal : ''));
    }
}
