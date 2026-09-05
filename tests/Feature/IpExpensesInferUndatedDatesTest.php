<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IpExpense;
use App\Models\IpExpenseAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Вывод дат undated-строк «Расходов ИП» (H4188 residual): месяц берётся
 * из вкладки книги (source_tab) — последняя датированная строка вкладки,
 * без датированных — конец месяца вкладки. Dry-run ничего не пишет;
 * apply уходит в аудит (null → дата, «Система»).
 */
class IpExpensesInferUndatedDatesTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $payee, ?string $spentAt, string $tab, string $amount = '100.00'): IpExpense
    {
        return IpExpense::query()->create([
            'spent_at' => $spentAt,
            'payee' => $payee,
            'amount' => $amount,
            'category' => 'other',
            'source_tab' => $tab,
            'import_hash' => 'hash-'.md5($payee.$tab.$amount.strlen($spentAt ?? 'x')).'-'.uniqid(),
        ]);
    }

    /** @test */
    public function dry_run_writes_nothing(): void
    {
        $this->row('Комиссия банка', null, 'Март 2026');
        $this->row('Аренда', '2026-03-15', 'Март 2026');

        $this->artisan('ip-expenses:infer-undated-dates')->assertSuccessful();

        $this->assertSame(1, IpExpense::query()->whereNull('spent_at')->count());
    }

    /** @test */
    public function undated_takes_last_dated_day_of_its_own_tab(): void
    {
        $this->row('Ранний', '2025-11-05', 'Ноябрь 2025');
        $this->row('Поздний', '2025-11-10', 'Ноябрь 2025');
        $this->row('Комиссия и услуги банка', null, 'Ноябрь 2025');
        // Декабрьская датированная строка не должна влиять на ноябрьскую.
        $this->row('Декабрьская', '2025-12-20', 'Декабрь 2025');

        $this->artisan('ip-expenses:infer-undated-dates', ['--apply' => true])->assertSuccessful();

        $inferred = IpExpense::query()->where('payee', 'Комиссия и услуги банка')->firstOrFail();
        $this->assertSame('2025-11-10', $inferred->spent_at->toDateString());
    }

    /** @test */
    public function tab_without_dated_rows_falls_back_to_month_end(): void
    {
        $this->row('Налог на прибыль', null, 'Февраль 2026');

        $this->artisan('ip-expenses:infer-undated-dates', ['--apply' => true])->assertSuccessful();

        $this->assertSame('2026-02-28', IpExpense::query()->where('payee', 'Налог на прибыль')->firstOrFail()->spent_at->toDateString());
    }

    /** @test */
    public function unparseable_tab_is_skipped_but_rest_still_applies(): void
    {
        $this->row('Без месяца', null, 'разное');
        $this->row('С месяцем', null, 'Июнь 2026');

        $this->artisan('ip-expenses:infer-undated-dates', ['--apply' => true])->assertSuccessful();

        $this->assertNull(IpExpense::query()->where('payee', 'Без месяца')->firstOrFail()->spent_at);
        $this->assertSame('2026-06-30', IpExpense::query()->where('payee', 'С месяцем')->firstOrFail()->spent_at->toDateString());
    }

    /** @test */
    public function apply_records_audit_diff_and_rerun_is_noop(): void
    {
        $this->row('Комиссия банка', null, 'Март 2026');
        $this->row('Аренда', '2026-03-15', 'Март 2026');

        $this->artisan('ip-expenses:infer-undated-dates', ['--apply' => true])->assertSuccessful();

        $row = IpExpense::query()->where('payee', 'Комиссия банка')->firstOrFail();
        $audit = IpExpenseAudit::query()
            ->where('action', IpExpenseAudit::ACTION_UPDATED)
            ->where('ip_expense_id', $row->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('Система', $audit->admin_name);
        $this->assertSame([null, '2026-03-15'], $audit->changes['spent_at'] ?? $audit->changes['Дата'] ?? null);

        // Повторный прогон: undated нет, no-op.
        $this->artisan('ip-expenses:infer-undated-dates')->assertSuccessful();
        $this->assertSame(0, IpExpense::query()->whereNull('spent_at')->count());
    }
}
