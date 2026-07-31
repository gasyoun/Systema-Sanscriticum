<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExpenseCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Операционный расход (opex) — недостающая расходная сторона ОПиУ. До этой
 * модели LMS знала только два вида расходов: маркетинг (Ad*Spend) и ЗП
 * (TeacherPayout). Эквайринг, хостинг, подрядчики, налоги жили только в
 * excel-шаблонах «Нескучных финансов»; теперь — здесь, чтобы ОПиУ считался
 * из живых данных.
 *
 * Денежная единица — рубли с копейками (decimal:2), как и везде в проекте
 * (Payment.amount, TeacherPayout.amount, Ad*Spend). НЕ minor units — единый
 * с денежным ядром формат важнее.
 *
 * @property int $id
 * @property Carbon $spent_at
 * @property ExpenseCategory $category
 * @property numeric-string $amount
 * @property string|null $counterparty
 * @property string|null $note
 */
class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'spent_at',
        'category',
        'amount',
        'counterparty',
        'note',
        // Провенанс моста «Расход»→opex (H2003): id исходного легаси-платежа,
        // уникален — обеспечивает идемпотентность expenses:bridge-raskhod.
        'payment_id',
    ];

    protected $casts = [
        'spent_at' => 'date',
        'category' => ExpenseCategory::class,
        'amount' => 'decimal:2',
    ];

    /**
     * Расходы, попавшие в окно [начало, конец] по дате траты (кассовый признак).
     * Границы — начало/конец суток: сравнение по `toDateString()` теряло записи
     * последнего дня окна, у которых значение хранится со временем (SQLite в
     * тестах пишет 'Y-m-d H:i:s' даже под cast 'date') — '2026-07-31 09:00:00'
     * строково больше верхней границы '2026-07-31' (issue #935, H1996).
     */
    public function scopeInWindow(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween('spent_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]);
    }

    /**
     * Сумма opex за окно, опционально по одной статье.
     */
    public static function totalForWindow(Carbon $start, Carbon $end, ?ExpenseCategory $category = null): float
    {
        return (float) self::query()
            ->inWindow($start, $end)
            ->when($category !== null, fn (Builder $q) => $q->where('category', $category->value))
            ->sum('amount');
    }

    /**
     * Суммы opex за окно, сгруппированные по статье: [category-value => сумма].
     * Присутствуют все статьи (нулевые тоже), чтобы ОПиУ не «терял» строки.
     *
     * @return array<string,float>
     */
    public static function byCategoryForWindow(Carbon $start, Carbon $end): array
    {
        $sums = self::query()
            ->inWindow($start, $end)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $out = [];
        foreach (ExpenseCategory::cases() as $case) {
            $out[$case->value] = (float) ($sums[$case->value] ?? 0.0);
        }

        return $out;
    }
}
