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

    /** Сезон набора (волна 2, MG 01-09-2026): значения поля season (без года). */
    public const SEASON_SLUGS = [
        'autumn' => 'ОСЕНЬ',
        'january' => 'НАЧАЛО',
        'spring' => 'ВЕСНА',
        'summer' => 'ЛЕТО',
    ];

    /** Прогноз голос→оплата (HYPOTHESIS, калибруется после первого цикла). */
    public const VOTE_TO_PAYMENT_K = 0.5;

    /**
     * Сезонные секции витрины (MG 01-09-2026): месяц earliest_start → сезон.
     * сен-окт → осень; ноя-дек → начало следующего года; янв → начало;
     * фев-мар → весна; апр-авг → лето. Твин Uprava render_waitlist_message.py.
     */
    public const SEASON_BY_MONTH = [
        1 => 'january', 2 => 'spring', 3 => 'spring',
        4 => 'summer', 5 => 'summer', 6 => 'summer',
        7 => 'summer', 8 => 'summer',
        9 => 'autumn', 10 => 'autumn',
        11 => 'january', 12 => 'january',
    ];

    /** Хронологический ранг сезона внутри года (январь = начало года N). */
    public const SEASON_RANK = [
        'autumn' => 3,
        'january' => 0,
        'spring' => 1,
        'summer' => 2,
    ];

    /**
     * (year, slug) для секции: поле season приоритетно, иначе инференс из
     * earliest_start. [null, null] — дата уточняется (секция в конце).
     *
     * @return array{0: int|null, 1: string|null}
     */
    public function seasonSection(): array
    {
        $season = $this->season;
        if (is_string($season) && $season !== '' && str_contains($season, '-')) {
            [$year, $slug] = explode('-', $season, 2);
            if (ctype_digit($year) && isset(self::SEASON_RANK[$slug])) {
                return [(int) $year, $slug];
            }
        }

        $earliest = $this->earliest_start_at;
        if ($earliest === null) {
            return [null, null];
        }

        $month = (int) $earliest->format('n');
        $year = (int) $earliest->format('Y');
        $slug = self::SEASON_BY_MONTH[$month];

        // ноя-дек → «НАЧАЛО» следующего календарного года.
        if ($slug === 'january' && $month >= 11) {
            $year++;
        }

        return [$year, $slug];
    }

    /** Ключ сортировки секций: осень N-1 → начало N → весна N → лето N → осень N. */
    public function seasonSortKey(): array
    {
        [$year, $slug] = $this->seasonSection();

        if ($year === null) {
            return [PHP_INT_MAX, 9];
        }

        // autumn N хронологически позже summer N: rank + 1; january N — до весны.
        $rank = $slug === 'january' ? 0 : self::SEASON_RANK[$slug] + 1;

        return [$year, $rank];
    }

    /** Заголовок секции: «ОСЕНЬ 2027», «дата уточняется». */
    public function seasonLabel(): string
    {
        [$year, $slug] = $this->seasonSection();

        if ($year === null) {
            return 'дата уточняется';
        }

        return self::SEASON_SLUGS[$slug].' '.$year;
    }

    protected $fillable = [
        'slug',
        'course_title',
        'course_id',
        'teacher_name',
        'slot',
        'earliest_start_at',
        'season',
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
