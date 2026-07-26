<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * СДЕЛКА — одна конкретная потенциальная продажа (GC-C1 / H1641).
 *
 * Зачем отдельно от Lead: у человека может быть НЕСКОЛЬКО сделок (второй курс,
 * апгрейд тарифа) — именно это Lead выразить не может. Lead остаётся
 * «человек/интерес» и этой программой не меняется (спека §2.1).
 *
 * РАНГ 4 лестницы полномочий (спека §2.2): сделка наблюдает денежное ядро и
 * НИКОГДА его не авторизует — не выдаёт доступ, не назначает цену, не
 * отменяет платёж. При расхождении с платежом прав платёж.
 */
class Deal extends Model
{
    use HasFactory;

    public const REASON_WON = 'won';

    public const REASON_LOST = 'lost';

    protected $fillable = [
        'lead_id',
        'user_id',
        'course_id',
        'amount',
        'currency',
        'stage_id',
        'assigned_to',
        'closed_at',
        'closed_reason',
        'source_payment_id',
        'installment_group_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'stage_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Платёж, закрывший сделку. Только чтение — сделка в payments не пишет. */
    public function sourcePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(DealTransition::class)->latest('created_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /** Заголовок карточки на канбан-доске (зеркало Lead::kanban_title). */
    public function getKanbanTitleAttribute(): string
    {
        $who = $this->user?->name
            ?: ($this->lead?->name ?: ($this->lead?->contact ?: null));
        $what = $this->course?->title;

        return trim(($who ?: 'Сделка #'.$this->id).($what ? ' — '.$what : ''));
    }

    /**
     * Единый гард смены стадии — зеркало Lead::blocksRollbackToFirstStage:
     * нельзя молча откатить финальную (won/lost) стадию в первую.
     */
    public static function blocksRollbackToFirstStage(?int $currentStageId, int $newStageId): bool
    {
        $first = DealStage::firstStage();
        if ($first === null || $newStageId !== $first->id || $currentStageId === null) {
            return false;
        }

        return (bool) DealStage::query()
            ->whereKey($currentStageId)
            ->where(fn (Builder $q) => $q->where('is_won', true)->orWhere('is_lost', true))
            ->exists();
    }

    /**
     * Перевести сделку на стадию и записать переход в append-only журнал.
     * Закрытие/переоткрытие closed_at выводится из самой стадии, чтобы доска и
     * мост от оплаты не разъезжались.
     *
     * @param  int|null  $userId  null = «Система» (мост от оплаты)
     */
    public function moveToStage(DealStage $stage, ?int $userId = null, ?string $reason = null): void
    {
        $from = $this->stage_id;

        $attrs = ['stage_id' => $stage->id];
        if ($stage->isFinal()) {
            $attrs['closed_at'] = $this->closed_at ?? now();
            $attrs['closed_reason'] = $reason
                ?? ($stage->is_won ? self::REASON_WON : self::REASON_LOST);
        } else {
            // Возврат в рабочую стадию снова открывает сделку.
            $attrs['closed_at'] = null;
            $attrs['closed_reason'] = null;
        }

        $this->update($attrs);

        DealTransition::create([
            'deal_id' => $this->id,
            'from_stage_id' => $from,
            'to_stage_id' => $stage->id,
            'user_id' => $userId,
            'created_at' => now(),
        ]);
    }
}
