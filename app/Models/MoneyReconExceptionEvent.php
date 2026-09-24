<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Reconciliation\ReconInvariantViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H5445 (P3, D9/D17): след исключения сверки — открытие, разрешение, отклонение,
 * заметка. Только добавление: кто, когда, почему, с каким доказательством.
 */
class MoneyReconExceptionEvent extends Model
{
    public const UPDATED_AT = null;

    public const OPENED = 'opened';

    public const RESOLVED = 'resolved';

    public const DISMISSED = 'dismissed';

    public const NOTE = 'note';

    protected $guarded = [];

    protected $casts = [
        'evidence' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new ReconInvariantViolation('recon: exception events are immutable'));
        static::deleting(fn () => throw new ReconInvariantViolation('recon: exception events are never deleted'));
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(MoneyReconException::class, 'exception_id');
    }
}
