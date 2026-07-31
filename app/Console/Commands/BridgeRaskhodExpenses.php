<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Console\Command;

/**
 * Мост «Расход» → opex-леджер (H2003, issue #953). Легаси-конвенция вносила
 * реальные расходы школы отрицательными платежами с тарифом «Расход»; кокпит же
 * читает opex ТОЛЬКО из модели Expense (а «Расход» исключает из выручки через
 * NON_REVENUE_TARIFFS) — эти деньги были невидимы для ОПиУ/ДДС целиком.
 *
 * Мост копирует (не переносит): payments не трогаются, все их потребители
 * (TeacherSalaryService::EXPENSE_TARIFF и пр.) работают как раньше. Идемпотентен
 * за счет expenses.payment_id (уникальный индекс): платеж не может стать двумя
 * расходами, повторный прогон доливает только новое. Статья у всех мостовых
 * строк одна — «Налоги, банк, прочее» (у платежа нет сигнала для категоризации);
 * оператор пере-категоризует в админке «Финансы → Расходы (opex)», payment_id
 * при этом сохраняется.
 *
 * По умолчанию — dry-run (только отчет); писать — с флагом --apply.
 * Ежедневный прогон в Kernel держит две конвенции в синхроне, пока владелец
 * ввода расходов не определен (@DECIDE в issue #953).
 */
class BridgeRaskhodExpenses extends Command
{
    protected $signature = 'expenses:bridge-raskhod {--apply : Записать мост; без флага — только отчет (dry-run)}';

    protected $description = 'Долить платежи с тарифом «Расход» в opex-леджер Expense (идемпотентно, по payment_id)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $bridged = Expense::query()->whereNotNull('payment_id')->pluck('payment_id');

        $candidates = Payment::query()
            ->where('tariff', 'Расход')
            ->whereIn('status', ['paid', 'success'])
            ->whereNotIn('id', $bridged)
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Мост синхронен: непереброшенных платежей «Расход» нет.');

            return self::SUCCESS;
        }

        $byMonth = $candidates->groupBy(fn (Payment $p): string => $p->created_at->format('Y-m'));
        $this->table(
            ['Месяц', 'Платежей', 'Сумма (₽, по модулю)'],
            $byMonth->map(fn ($rows, $m): array => [
                $m,
                $rows->count(),
                number_format($rows->sum(fn (Payment $p): float => abs((float) $p->amount)), 2, ',', ' '),
            ])->values()->all(),
        );

        $future = $candidates->filter(fn (Payment $p): bool => $p->created_at->isFuture());
        foreach ($future as $p) {
            $this->warn("Внимание: платеж #{$p->id} датирован будущим ({$p->created_at->toDateString()}) — уйдет в расходы будущего месяца. Проверьте дату (issue #953).");
        }
        $positive = $candidates->filter(fn (Payment $p): bool => (float) $p->amount > 0);
        foreach ($positive as $p) {
            $this->warn("Внимание: платеж #{$p->id} с ПОЛОЖИТЕЛЬНОЙ суммой {$p->amount} — мост берет модуль; проверьте, расход ли это.");
        }

        if (! $apply) {
            $this->info("Dry-run: {$candidates->count()} платеж(ей) было бы переброшено. Запустите с --apply, чтобы записать.");

            return self::SUCCESS;
        }

        $created = 0;
        foreach ($candidates as $p) {
            $note = "Мост «Расход» → opex (payment #{$p->id})";
            if (filled($p->payer_note)) {
                $note .= ': '.$p->payer_note;
            }

            Expense::create([
                'spent_at' => $p->created_at->toDateString(),
                'category' => ExpenseCategory::TaxesBankOther,
                'amount' => abs((float) $p->amount),
                'counterparty' => null,
                'note' => $note,
                'payment_id' => $p->id,
            ]);
            $created++;
        }

        $this->info("Переброшено {$created} платеж(ей) в opex-леджер.");

        return self::SUCCESS;
    }
}
