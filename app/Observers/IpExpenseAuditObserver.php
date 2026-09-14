<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\IpExpense;
use App\Models\IpExpenseAudit;
use App\Models\User;

/**
 * Аудит контура «Расходы ИП» — конвенции PaymentAuditObserver (H4188):
 * кто и что сделал со строкой; действия без авторизованного пользователя
 * (импорт из CLI) фиксируются как «Система».
 */
class IpExpenseAuditObserver
{
    /** Поля, изменения которых имеет финансовый смысл. */
    private const AUDITED = [
        'spent_at',
        'payee',
        'amount',
        'currency',
        'fx_note',
        'account',
        'category',
        'note',
    ];

    public function created(IpExpense $expense): void
    {
        $this->record($expense, IpExpenseAudit::ACTION_CREATED, $this->snapshot($expense));
    }

    public function updated(IpExpense $expense): void
    {
        $diff = [];

        foreach (self::AUDITED as $field) {
            if (! $expense->wasChanged($field)) {
                continue;
            }
            $diff[$field] = [
                $this->scalar($expense->getOriginal($field)),
                $this->scalar($expense->getAttribute($field)),
            ];
        }

        if (empty($diff)) {
            return;
        }

        $this->record($expense, IpExpenseAudit::ACTION_UPDATED, $diff);
    }

    /**
     * Delete заблокирован в модели (append-only); обсервер на всякий случай
     * пишет ACTION_DELETED, если guard когда-нибудь ослабят явно.
     */
    public function deleted(IpExpense $expense): void
    {
        $this->record($expense, IpExpenseAudit::ACTION_DELETED, $this->snapshot($expense));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function record(IpExpense $expense, string $action, array $changes): void
    {
        $admin = auth()->user();

        IpExpenseAudit::create([
            'ip_expense_id' => $expense->getKey(),
            'admin_id' => $admin instanceof User ? $admin->getKey() : null,
            'admin_name' => $admin instanceof User ? $admin->name : 'Система',
            'action' => $action,
            'amount' => $expense->amount,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function snapshot(IpExpense $expense): array
    {
        $data = [];
        foreach (self::AUDITED as $field) {
            $data[$field] = $this->scalar($expense->getAttribute($field));
        }

        // Провенанс импорта: вкладка книги-источника.
        $data['source_tab'] = $expense->source_tab;

        return $data;
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_scalar($value) || $value === null ? $value : (string) $value;
    }
}
