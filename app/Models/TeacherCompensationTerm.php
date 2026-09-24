<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * H5444 (D15): версионированное условие оплаты преподавателя.
 *
 * Запись неизменяема — правка ставки создаёт НОВУЮ версию, а старая остаётся
 * доказательством того, по чему считали раньше. Чат и старый реестр живут в
 * `source`/`evidence_key` как доказательство миграции и расчётом не управляют.
 */
class TeacherCompensationTerm extends Model
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_PER_LESSON = 'per_lesson';

    public const TYPE_PER_BLOCK = 'per_block';

    public const TYPE_FIXED = 'fixed';

    public const SOURCE_SYSTEMA = 'systema';

    public const SOURCE_MIGRATED_CHAT = 'migrated_chat';

    public const SOURCE_MIGRATED_REGISTRY = 'migrated_registry';

    protected $fillable = [
        'term_key', 'teacher_id', 'version', 'compensation_type', 'rate_ppm', 'rate_kopecks',
        'calc_currency', 'effective_from', 'confirmed_by', 'confirmed_at', 'source',
        'evidence_key', 'notes',
    ];

    protected $casts = [
        'version' => 'integer',
        'rate_ppm' => 'integer',
        'rate_kopecks' => 'integer',
        'effective_from' => 'date',
        'confirmed_at' => 'datetime',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TeacherCompensationAssignment::class, 'term_id');
    }

    /**
     * Применить условие к рублёвой базе. Целочисленно, с округлением половины
     * вверх по модулю — ни одной операции с плавающей точкой (D14).
     *
     * @param  int  $baseKopecks  база начисления в копейках (для percent)
     * @param  int  $units  число уроков/блоков (для per_lesson / per_block)
     */
    public function applyToKopecks(int $baseKopecks, int $units = 0): int
    {
        return match ($this->compensation_type) {
            self::TYPE_PERCENT => intdiv($baseKopecks * (int) $this->rate_ppm + 500_000, 1_000_000),
            self::TYPE_PER_LESSON, self::TYPE_PER_BLOCK => $units * (int) $this->rate_kopecks,
            self::TYPE_FIXED => (int) $this->rate_kopecks,
            default => throw new \LogicException("payout: unknown compensation type {$this->compensation_type}"),
        };
    }

    /** Человекочитаемая ставка для отчётов сверки. */
    public function rateLabel(): string
    {
        return match ($this->compensation_type) {
            self::TYPE_PERCENT => number_format((int) $this->rate_ppm / 10_000, 2, '.', '').'%',
            default => number_format((int) $this->rate_kopecks / 100, 2, '.', ' ').' ₽',
        };
    }
}
