<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Журнал режима просмотра за пользователя (H1947): кто, за кого, в каком режиме
 * и когда вошёл — и когда вышел. Зеркало {@see MessageTemplateAudit}: строка
 * заводится на старте и ЕДИНСТВЕННЫЙ раз обновляется stopped_at на выходе,
 * вручную не правится.
 *
 * stopped_at = NULL означает, что режим не закрыли кнопкой (закрыли вкладку,
 * протухла сессия) — это нормальное состояние, а не потеря данных.
 */
class ImpersonationAudit extends Model
{
    /** Только момент записи; общий updated_at логу не нужен. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'impersonator_id',
        'impersonator_name',
        'target_id',
        'target_name',
        'mode',
        'started_at',
        'stopped_at',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** Сотрудник мог быть удалён — связь может вернуть null. */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    /** Целевой пользователь мог быть удалён — связь может вернуть null. */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id');
    }

    public function isOpen(): bool
    {
        return $this->stopped_at === null;
    }
}
