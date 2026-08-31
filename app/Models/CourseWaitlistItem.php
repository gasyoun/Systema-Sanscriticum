<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Строка «Списка ожидания» (MG ruling 31-08-2026): курс-кандидат, за который
 * голосуют зарегистрированные ученики и который стартует при наборе нужного
 * числа оплат (min_payers, default 8). Денормализованные витринные поля
 * (course_title, teacher_name, slot) живут здесь, пока курс ещё не создан;
 * после привязки course_id витрина продаёт через обычный магазин.
 *
 * Статусы: collecting → payment_open → (payment_deadline_passed) →
 * postponed | scheduled | closed. Лестница переносов (4 попытки из года в год,
 * максимум 4 года): попытка «до конца октября» провалилась → грамматика в
 * январе / прочие в марте; январь/март не начались → июль; июль не начался →
 * сентябрь–октябрь следующего года. earliest_start_at уважается всегда.
 */
class CourseWaitlistItem extends Model
{
    use HasFactory;

    public const STATUSES = [
        'collecting' => 'Сбор голосов',
        'payment_open' => 'Открыта оплата',
        'payment_deadline_passed' => 'Дедлайн оплаты прошёл',
        'postponed' => 'Перенесено',
        'scheduled' => 'Запланировано',
        'closed' => 'Закрыто',
    ];

    public const STATUS_COLLECTING = 'collecting';

    public const STATUS_PAYMENT_OPEN = 'payment_open';

    public const STATUS_PAYMENT_DEADLINE_PASSED = 'payment_deadline_passed';

    public const STATUS_POSTPONED = 'postponed';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CLOSED = 'closed';

    public const KINDS = [
        'grammar' => 'Грамматика',
        'other' => 'Прочее',
    ];

    public const NOVELTIES = [
        'new' => 'Впервые',
        'repeat' => 'Возвращается',
    ];

    /** Прогноз голос→оплата (HYPOTHESIS, калибруется после первого цикла). */
    public const VOTE_TO_PAYMENT_K = 0.5;

    protected $fillable = [
        'slug',
        'course_title',
        'course_id',
        'teacher_name',
        'slot',
        'earliest_start_at',
        'min_payers',
        'block_price_rub',
        'kind',
        'status',
        'start_attempts',
        'planned_start_at',
        'historical_paid_n',
        'historical_notes',
        'is_listed',
        'sort_order',
    ];

    protected $casts = [
        'earliest_start_at' => 'date',
        'planned_start_at' => 'date',
        'min_payers' => 'integer',
        'block_price_rub' => 'integer',
        'historical_paid_n' => 'integer',
        'start_attempts' => 'integer',
        'is_listed' => 'boolean',
    ];

    protected $attributes = [
        'status' => 'collecting',
        'min_payers' => 8,
        'kind' => 'other',
        'start_attempts' => 0,
        'is_listed' => true,
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(WaitlistVote::class, 'course_waitlist_item_id');
    }

    public function voters(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'waitlist_votes', 'course_waitlist_item_id', 'user_id')
            ->withTimestamps();
    }

    // ================= Витринные производные =================

    public function votesCount(): int
    {
        return $this->votes()->count();
    }

    /** Порог достигнут — можно открывать оплату (после проверки куратором/прогноза). */
    public function hasThreshold(): bool
    {
        return $this->votesCount() >= $this->min_payers;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ($this->status ?? '—');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ($this->kind ?? '—');
    }

    /**
     * Прогноз оплат (диапазон) для карточки/админки: голоса × k_голос→оплата,
     * потолок — исторический набор повторного потока (спад 25–60 % прошлого).
     */
    public function forecastPayments(): array
    {
        $votes = $this->votesCount();
        $base = (int) floor($votes * self::VOTE_TO_PAYMENT_K);

        $low = $base;
        $high = $base;

        if ($this->historical_paid_n !== null) {
            // Повторный поток живёт на 25–60 % прошлого набора (данные 2025→2026).
            $ceiling = (int) floor($this->historical_paid_n * 0.6);
            $high = min($high, max($ceiling, $base));
            $low = min($low, $ceiling === 0 ? 0 : max((int) round($low * 0.5), 0));
        }

        return ['low' => max(0, $low), 'high' => max($low, $high)];
    }
}
