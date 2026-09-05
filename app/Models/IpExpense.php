<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IpExpenseCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Расход контура «Расходы ИП» (H4188) — строка легаси-книги «Расходы по ИП»,
 * переехавшей из Google Sheets в панель. Отдельная от opex (Expense) сущность:
 * книга зеркалит легаси-расходы CRM за Oct'25–May'26 (двойной счёт, см.
 * AUDIT_BOOKKEEPING_MISERABLE_MAP §3), слипать до сверки нельзя.
 *
 * Append-only: удаление запрещено (книга никогда не удаляла строки; правки —
 * аудируются в ip_expense_audits по конвенциям payment_audits).
 *
 * @property int $id
 * @property Carbon|null $spent_at
 * @property string $payee
 * @property numeric-string $amount
 * @property string $currency
 * @property string|null $fx_note
 * @property string|null $account
 * @property IpExpenseCategory $category
 * @property string|null $note
 * @property string $source_tab
 * @property string $import_hash
 */
class IpExpense extends Model
{
    protected $fillable = [
        'spent_at',
        'payee',
        'amount',
        'currency',
        'fx_note',
        'account',
        'category',
        'note',
        'source_tab',
        'import_hash',
    ];

    protected $casts = [
        'spent_at' => 'date',
        'amount' => 'decimal:2',
        'category' => IpExpenseCategory::class,
    ];

    protected static function booted(): void
    {
        // Append-only guard (H4188, миссия п.1): строки контура не удаляются.
        // Исправления — через правку полей (аудит пишет diff), не через delete.
        static::deleting(function (IpExpense $expense): void {
            throw new \RuntimeException(
                "Расход ИП #{$expense->getKey()} удалить нельзя — контур append-only. Правьте поля, аудит сохранит diff."
            );
        });
    }

    public function audits(): HasMany
    {
        return $this->hasMany(IpExpenseAudit::class, 'ip_expense_id');
    }

    /**
     * Сумма расходов за окно по дате траты (кассовый признак), опционально
     * по статье. Строки без даты (NULL spent_at) в окно не попадают.
     */
    public static function totalForWindow(Carbon $start, Carbon $end, ?IpExpenseCategory $category = null): float
    {
        return (float) self::query()
            ->whereNotNull('spent_at')
            ->whereBetween('spent_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->when($category !== null, fn (Builder $q) => $q->where('category', $category->value))
            ->sum('amount');
    }
}
