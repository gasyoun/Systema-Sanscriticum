<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only аудит-след строки реестра H2746: кто, когда и на каком основании
 * подтвердил кандидата, исключил, засчитал долг, засчитал взнос, вернул в чат.
 *
 * Строку события нельзя ни изменить, ни удалить — журнал, который можно
 * подчистить, не доказательство. Попытка апдейта/удаления падает исключением,
 * а не молча проходит.
 */
class CourseDebtChatRemovalEvent extends Model
{
    public const QUALIFIED = 'qualified';

    public const REMOVED = 'removed';

    public const DEBT_SETTLED = 'debt_settled';

    public const FEE_SETTLED = 'fee_settled';

    public const FEE_WAIVED = 'fee_waived';

    public const RESTORED = 'restored';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'removal_id',
        'event',
        'actor_user_id',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Аудит-след H2746 неизменяем: событие нельзя отредактировать.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Аудит-след H2746 неизменяем: событие нельзя удалить.');
        });
    }

    public function removal(): BelongsTo
    {
        return $this->belongsTo(CourseDebtChatRemoval::class, 'removal_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
