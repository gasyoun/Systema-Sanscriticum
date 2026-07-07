<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Аудит финансовых операций в админке: кто (admin) и что сделал с платежом.
 * Append-only — записи не редактируются и не удаляются вручную.
 */
class PaymentAudit extends Model
{
    use HasFactory;

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    /** Только момент записи; updated_at не нужен — лог неизменяем. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_id',
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

    /**
     * Человекочитаемые имена аудируемых полей — для сводки в админке.
     */
    public const FIELD_LABELS = [
        'user_id' => 'Студент (ID)',
        'course_id' => 'Курс (ID)',
        'amount' => 'Сумма',
        'tariff' => 'Тариф',
        'status' => 'Статус',
        'start_block' => 'Блок с',
        'end_block' => 'Блок по',
        'transaction_id' => 'Примечание',
        'is_conditional' => 'Условный доступ',
        'deposit_consumed_at' => 'Зачёт депозита',
        'consumed_amount' => 'Зачтено из депозита',
        'deposit_credit_applied' => 'Предоплата в цене заказа',
    ];

    /**
     * Платёж может быть уже удалён — связь без ограничений, может вернуть null.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_CREATED => 'Создал',
            self::ACTION_UPDATED => 'Изменил',
            self::ACTION_DELETED => 'Удалил',
            default => $this->action,
        };
    }

    /**
     * Человекочитаемая сводка изменений.
     * - created/deleted: «Сумма: 5000, Тариф: full, …» (снимок полей)
     * - updated: «Сумма: 5000 → 0, Статус: pending → paid» (было → стало)
     */
    public function summary(): string
    {
        $changes = $this->changes ?? [];
        if (empty($changes)) {
            return '—';
        }

        $parts = [];
        foreach ($changes as $field => $value) {
            $label = self::FIELD_LABELS[$field] ?? $field;

            // updated хранит пару [old, new]; created/deleted — скаляр-снимок.
            if (is_array($value) && array_key_exists(0, $value) && array_key_exists(1, $value)) {
                $parts[] = sprintf('%s: %s → %s', $label, $this->display($value[0]), $this->display($value[1]));
            } else {
                $parts[] = sprintf('%s: %s', $label, $this->display($value));
            }
        }

        return implode("\n", $parts);
    }

    /** Нормализуем значение поля к строке для показа. */
    private function display(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '∅',
            is_bool($value) => $value ? 'да' : 'нет',
            default => (string) $value,
        };
    }
}
