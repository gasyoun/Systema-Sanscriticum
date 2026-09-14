<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only аудит контура «Расходы ИП» — конвенции payment_audits
 * (H4188): кто и что сделал со строкой расхода; записи не редактируются
 * и не удаляются вручную; только created_at, без updated_at.
 *
 * @property int $id
 * @property int|null $ip_expense_id
 * @property int|null $admin_id
 * @property string|null $admin_name
 * @property string $action
 * @property numeric-string|null $amount
 * @property array|null $changes
 */
class IpExpenseAudit extends Model
{
    public const ACTION_IMPORTED = 'imported';

    /** Импорт из банковской выписки (Точка/Сбер/PayPal, H4200). */
    public const ACTION_IMPORTED_STATEMENT = 'imported_statement';

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    /** Только момент записи; лог неизменяем. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'ip_expense_id',
        'admin_id',
        'admin_name',
        'action',
        'amount',
        'changes',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /** Человеческие подписи аудируемых полей — для сводки. */
    public const FIELD_LABELS = [
        'spent_at' => 'Дата',
        'payee' => 'Получатель',
        'amount' => 'Сумма',
        'currency' => 'Валюта',
        'fx_note' => 'Валютная деталь',
        'account' => 'Счёт',
        'category' => 'Статья',
        'note' => 'Примечание',
        'source_tab' => 'Вкладка книги',
        '_snapshot' => 'Снапшот',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(IpExpense::class, 'ip_expense_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_IMPORTED => 'Импорт из книги',
            self::ACTION_IMPORTED_STATEMENT => 'Импорт из выписки',
            self::ACTION_CREATED => 'Создал',
            self::ACTION_UPDATED => 'Изменил',
            self::ACTION_DELETED => 'Удалил',
            default => $this->action,
        };
    }
}
