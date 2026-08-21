<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherPayout extends Model
{
    /** Тип выплаты: обычная выплата. */
    public const TYPE_REGULAR = 'regular';

    /** Тип выплаты: аванс (висит как «не зачтён», пока не зачтут при полной оплате). */
    public const TYPE_ADVANCE = 'advance';

    protected $fillable = [
        'teacher_id',
        'amount',
        'type',
        'comment',
        'paid_at',
        'settled_at',
        'settled_by',
        // Зачтённая сумма аванса. Обязательно fillable: экшен «Зачесть аванс»
        // (TeacherSalaries::recordPayoutAction) пишет её через ->update([...]).
        // Без fillable mass-assignment молча её отбрасывал → settled_amount оставался
        // 0 → зачтённый аванс не уменьшал баланс ЗП (money-core, #271).
        'settled_amount',
        'period_month',
        'course_id',
        'salary_type',
        'salary_value',
        // Валютная конвертация (PayPal): снимок валюты, курса и суммы в валюте.
        'payout_currency',
        'exchange_rate',
        'rate_date',
        'amount_foreign',
        'breakdown',
        'payment_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'salary_value' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'amount_foreign' => 'decimal:2',
        'rate_date' => 'date',
        'paid_at' => 'date',
        'settled_at' => 'datetime',
        'breakdown' => 'array',
    ];

    /** Символ валюты выплаты. */
    public static function currencySymbol(?string $currency): string
    {
        return match ($currency) {
            'EUR' => '€',
            'USD' => '$',
            'INR' => '₹',
            default => (string) $currency,
        };
    }

    /** Подпись суммы в валюте, например «45.00 €». Пусто, если конвертации нет. */
    public function foreignAmountLabel(): string
    {
        if ((float) $this->amount_foreign <= 0 || empty($this->payout_currency)) {
            return '';
        }

        return number_format((float) $this->amount_foreign, 2, '.', ' ').' '.self::currencySymbol($this->payout_currency);
    }

    /** Это аванс? */
    public function isAdvance(): bool
    {
        return $this->type === self::TYPE_ADVANCE;
    }

    /** Аванс зачтён? */
    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    public function scopeAdvances(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_ADVANCE);
    }

    /** Непогашенные авансы — деньги выданы, но к ЗП ещё не зачтены. */
    public function scopeUnsettledAdvances(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_ADVANCE)->whereNull('settled_at');
    }

    protected static function booted(): void
    {
        // Удаление выплаты снимает её транзакцию-зеркало в «Финансах».
        // withoutEvents — у salary_payout нет побочных хуков, чистим тихо.
        static::deleting(function (self $payout): void {
            if ($payout->payment_id) {
                Payment::withoutEvents(fn () => Payment::whereKey($payout->payment_id)->delete());
            }
        });
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
