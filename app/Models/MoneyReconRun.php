<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Reconciliation\ReconInvariantViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * H5445 (P3): один прогон ежедневной сверки — срез источников, контрольные
 * суммы и классификация строк доказательств. Неизменяем (триггер + модель):
 * повтор с тем же входом возвращает существующий прогон.
 *
 * @property int $id
 * @property Carbon $business_date
 * @property string $mode
 * @property string $status
 * @property string $input_fingerprint
 * @property string $totals_checksum
 * @property array $sources
 * @property array $totals
 * @property array $classification
 * @property int $exceptions_opened
 */
class MoneyReconRun extends Model
{
    public const UPDATED_AT = null;

    public const COMPLETE = 'complete';

    public const INCOMPLETE = 'incomplete';

    protected $guarded = [];

    protected $casts = [
        'business_date' => 'date',
        'sources' => 'array',
        'totals' => 'array',
        'classification' => 'array',
        'exceptions_opened' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * День хранится строго как Y-m-d: иначе каст `date` пишет «Y-m-d 00:00:00»
     * (SQLite хранит строку как есть), и уникальный ключ (день, отпечаток) не
     * узнал бы тот же день, записанный иначе.
     */
    public function setBusinessDateAttribute(mixed $value): void
    {
        $this->attributes['business_date'] = Carbon::parse($value)->toDateString();
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new ReconInvariantViolation('recon: runs are immutable'));
        static::deleting(fn () => throw new ReconInvariantViolation('recon: runs are never deleted'));
    }
}
