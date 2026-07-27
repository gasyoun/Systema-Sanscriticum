<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\MutualSettlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Акт взаимозачёта «преподаватель-ученик» (H1730, волна B).
 *
 * Фиксированный снимок двух цифр за период — сколько человек заплатил школе как
 * ученик и сколько ему начислено как преподавателю. ФИКСАЦИЯ ЗАМОРАЖИВАЕТ
 * ЧИСЛА: после status=fixed суммы читаются из этой строки, а не пересчитываются,
 * иначе поздняя оплата задним числом сдвинула бы уже подписанную цифру.
 *
 * Пересмотр не правит акт, а создаёт новый и переводит прежний в superseded —
 * история остаётся полной.
 *
 * Считает и фиксирует {@see MutualSettlementService}.
 */
class MutualSettlement extends Model
{
    /** Черновик — посчитан, но не подписан. */
    public const STATUS_DRAFT = 'draft';

    /** Зафиксирован: суммы заморожены, акт готов к зачёту в выплате. */
    public const STATUS_FIXED = 'fixed';

    /** Заменён более поздней фиксацией того же периода. */
    public const STATUS_SUPERSEDED = 'superseded';

    /** Остаток в пользу школы — человек доплачивает. */
    public const DIRECTION_STUDENT_PAYS = 'student_pays';

    /** Остаток в пользу человека — школа доплачивает. */
    public const DIRECTION_SCHOOL_PAYS = 'school_pays';

    /** Суммы сошлись — никто никому не должен. */
    public const DIRECTION_ZERO = 'zero';

    protected $fillable = [
        'user_id',
        'teacher_id',
        'course_id',
        'block_number',
        'period_from',
        'period_to',
        'tuition_amount',
        'salary_amount',
        'offset_amount',
        'net_direction',
        'net_amount',
        'status',
        'fixed_at',
        'fixed_by',
        'note',
        'payout_id',
        'breakdown',
    ];

    protected $casts = [
        'tuition_amount' => 'decimal:2',
        'salary_amount' => 'decimal:2',
        'offset_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'block_number' => 'integer',
        'period_from' => 'date',
        'period_to' => 'date',
        'fixed_at' => 'datetime',
        'breakdown' => 'array',
    ];

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Черновик',
            self::STATUS_FIXED => 'Зафиксирован',
            self::STATUS_SUPERSEDED => 'Заменён',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function directionLabels(): array
    {
        return [
            self::DIRECTION_STUDENT_PAYS => 'Остаток доплачивает человек',
            self::DIRECTION_SCHOOL_PAYS => 'Остаток доплачивает школа',
            self::DIRECTION_ZERO => 'Суммы сошлись',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    public function directionLabel(): string
    {
        return self::directionLabels()[$this->net_direction] ?? (string) $this->net_direction;
    }

    /** Зафиксирован и ещё не израсходован ни одной выплатой. */
    public function isAvailableForOffset(): bool
    {
        return $this->status === self::STATUS_FIXED
            && $this->payout_id === null
            && (float) $this->offset_amount > 0;
    }

    public function scopeFixed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FIXED);
    }

    /** Ещё не зачтённые в выплате акты — кандидаты для зачёта на странице ЗП. */
    public function scopeUnconsumed(Builder $query): Builder
    {
        return $query->whereNull('payout_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(TeacherPayout::class, 'payout_id');
    }

    public function fixedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fixed_by');
    }
}
