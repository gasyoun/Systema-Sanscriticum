<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IpExpenseCategory;
use App\Models\IpExpense;
use App\Models\IpExpenseAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Импорт банковских выписок в «Расходы ИП» (H4200): dry-run по умолчанию
 * ничего не пишет; месячные/паритетные гейты (aggregates Точки, --expect-total,
 * окно двойного счёта ≤ 2026-07) — refusal на расхождение; Сбер — cp1251/«;»,
 * PayPal — нативная валюта без придуманного курса; идемпотентность по
 * import_hash с сохранением легитимных дубликатов.
 */
class IpExpenseImportStatementTest extends TestCase
{
    use RefreshDatabase;

    private string $tochka = __DIR__.'/../fixtures/bank-statements/tochka/2026-08-debits.csv';

    private string $paypal = __DIR__.'/../fixtures/bank-statements/paypal/2026-08.csv';

    /** @test */
    public function tochka_dry_run_reports_and_writes_nothing(): void
    {
        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'tochka',
            'file' => $this->tochka,
            '--month' => '2026-08',
        ])
            ->expectsOutputToContain('dry-run')
            ->expectsOutputToContain('Двойной счёт')
            ->assertSuccessful();

        $this->assertSame(0, IpExpense::query()->count());
    }

    /** @test */
    public function tochka_apply_imports_with_provenance_and_is_idempotent(): void
    {
        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'tochka',
            'file' => $this->tochka,
            '--month' => '2026-08',
            '--apply' => true,
        ])->assertSuccessful();

        // 6 строк, Σ 22299.50, легитимный дубликат Бобра сохранён.
        $this->assertSame(6, IpExpense::query()->count());
        $this->assertEquals('22299.50', IpExpense::query()->where('currency', 'RUB')->sum('amount'));
        $this->assertSame(2, IpExpense::query()->where('payee', 'Подрядчик Бобр')->whereDate('spent_at', '2026-08-20')->count());

        $hosting = IpExpense::query()->where('payee', 'Хостинг Timeweb')->firstOrFail();
        $this->assertTrue($hosting->category === IpExpenseCategory::Contractors);
        $this->assertSame('Точка ИП', $hosting->account);
        $this->assertSame('Выписка Точка 2026-08', $hosting->source_tab);
        $this->assertSame('RUB', $hosting->currency);
        $this->assertNull($hosting->fx_note);

        $tax = IpExpense::query()->where('payee', 'ФНС Налог УСН аванс')->firstOrFail();
        $this->assertTrue($tax->category === IpExpenseCategory::Taxes);

        // Аудит imported_statement с провенансом файла.
        $this->assertSame(6, IpExpenseAudit::query()->where('action', IpExpenseAudit::ACTION_IMPORTED_STATEMENT)->count());
        $audit = IpExpenseAudit::query()->where('action', IpExpenseAudit::ACTION_IMPORTED_STATEMENT)->firstOrFail();
        $this->assertSame('Точка', $audit->changes['_statement']);
        $this->assertSame('2026-08', $audit->changes['_month']);
        $this->assertSame(64, strlen((string) $audit->changes['_file_sha256']));

        // Повторный прогон: 0 новых, дубликаты не слиплись.
        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'tochka',
            'file' => $this->tochka,
            '--month' => '2026-08',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(6, IpExpense::query()->count());
    }

    /** @test */
    public function aggregates_gate_refuses_on_debit_sum_mismatch(): void
    {
        $tsv = storage_path('framework/testing/tochka-aggregates.tsv');
        File::ensureDirectoryExists(dirname($tsv));
        File::put($tsv, implode("\n", [
            'snapshot_utc	month	credit_sum	debit_sum	credit_count	debit_count	tx_count	start_balance	end_balance	status',
            '2026-09-03T14:22:42Z	2026-08	0.0	99999.99	0	99	99	0.00	0.00	ok',
            '',
        ]));

        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'tochka',
            'file' => $this->tochka,
            '--month' => '2026-08',
            '--aggregates' => $tsv,
            '--apply' => true,
        ])
            ->expectsOutputToContain('REFUSE')
            ->assertFailed();

        $this->assertSame(0, IpExpense::query()->count());

        File::delete($tsv);
    }

    /** @test */
    public function aggregates_gate_passes_on_exact_match(): void
    {
        $tsv = storage_path('framework/testing/tochka-aggregates-ok.tsv');
        File::ensureDirectoryExists(dirname($tsv));
        File::put($tsv, implode("\n", [
            'snapshot_utc	month	credit_sum	debit_sum	credit_count	debit_count	tx_count	start_balance	end_balance	status',
            '2026-09-03T14:22:42Z	2026-08	0.0	22299.50	0	6	6	0.00	0.00	ok',
            '',
        ]));

        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'tochka',
            'file' => $this->tochka,
            '--month' => '2026-08',
            '--aggregates' => $tsv,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(6, IpExpense::query()->count());

        File::delete($tsv);
    }

    /** @test */
    public function expect_total_gate_refuses_on_mismatch(): void
    {
        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'tochka',
            'file' => $this->tochka,
            '--month' => '2026-08',
            '--expect-total' => ['RUB=1.00'],
            '--apply' => true,
        ])
            ->expectsOutputToContain('REFUSE')
            ->assertFailed();

        $this->assertSame(0, IpExpense::query()->count());
    }

    /** @test */
    public function overlap_month_refuses_until_acknowledged(): void
    {
        $tmp = storage_path('framework/testing/tochka-2026-07.csv');
        File::ensureDirectoryExists(dirname($tmp));
        File::put($tmp, str_replace('2026-08', '2026-07', (string) file_get_contents($this->tochka)));

        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'tochka',
            'file' => $tmp,
            '--month' => '2026-07',
        ])
            ->expectsOutputToContain('двойного счёта')
            ->assertFailed();

        $this->assertSame(0, IpExpense::query()->count());

        // Осознанный оверрайд: dry-run проходит, предупреждение остаётся.
        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'tochka',
            'file' => $tmp,
            '--month' => '2026-07',
            '--overlap-acknowledged' => true,
        ])
            ->expectsOutputToContain('Двойной счёт')
            ->assertSuccessful();

        $this->assertSame(0, IpExpense::query()->count());

        File::delete($tmp);
    }

    /** @test */
    public function month_mismatch_refuses(): void
    {
        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'tochka',
            'file' => $this->tochka,
            '--month' => '2026-09',
        ])
            ->expectsOutputToContain('REFUSE')
            ->assertFailed();

        $this->assertSame(0, IpExpense::query()->count());
    }

    /** @test */
    public function sber_cp1251_semicolon_export_parses_debits_only(): void
    {
        $csv = storage_path('framework/testing/sber-2026-08.csv');
        File::ensureDirectoryExists(dirname($csv));

        $body = implode("\n", [
            'Дата операции;Дата списания;Номер документа;Плательщик;Получатель;Назначение платежа;Дебет;Кредит',
            '05.08.2026;05.08.2026;101;ИП Гасунс;ФНС России УФК;Налог УСН за 2 квартал;15 000,50;0,00',
            '07.08.2026;07.08.2026;102;ИП Гасунс;ООО Арендатор;Аренда офиса август;25 000,00;0,00',
            '12.08.2026;12.08.2026;103;ООО Клиент;ИП Гасунс;Оплата по счету 55;0,00;60 000,00',
            '19.08.2026;19.08.2026;104;ИП Гасунс;Timeweb;Хостинг;1 899,00;0,00',
            '',
        ]);
        File::put($csv, iconv('UTF-8', 'CP1251', $body) ?: '');

        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'sber',
            'file' => $csv,
            '--month' => '2026-08',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(3, IpExpense::query()->count());
        $this->assertEquals('41899.50', IpExpense::query()->sum('amount'));

        // Кредитная строка не прошла, суммы «15 000,50» нормализованы.
        $this->assertSame(0, IpExpense::query()->whereIn('payee', ['ООО Клиент', 'ИП Гасунс'])->count());
        $tax = IpExpense::query()->where('payee', 'ФНС России УФК')->firstOrFail();
        $this->assertEquals('15000.50', $tax->amount);
        $this->assertTrue($tax->category === IpExpenseCategory::Taxes);
        $this->assertSame('2026-08-05', $tax->spent_at->toDateString());
        $this->assertSame('Выписка Сбер 2026-08', $tax->source_tab);

        File::delete($csv);
    }

    /** @test */
    public function paypal_keeps_native_currency_and_skips_income_internal_pending(): void
    {
        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'paypal',
            'file' => $this->paypal,
            '--month' => '2026-08',
            '--apply' => true,
        ])->assertSuccessful();

        // Только 2 Completed-расхода: входящий + Transfer to Bank + Pending мимо.
        $this->assertSame(2, IpExpense::query()->count());

        $netlify = IpExpense::query()->where('payee', 'Хостинг Netlify')->firstOrFail();
        $this->assertSame('EUR', $netlify->currency);
        $this->assertEquals('12.99', $netlify->amount);
        $this->assertSame('2026-08-21', $netlify->spent_at->toDateString());
        $this->assertSame('PayPal', $netlify->account);
        $this->assertStringContainsString('-12.99 EUR', (string) $netlify->fx_note);
        $this->assertTrue($netlify->category === IpExpenseCategory::Contractors);

        // RUB-Σ не тронут нативной валютой: рублёвых строк нет.
        $this->assertEquals('0', IpExpense::query()->where('currency', 'RUB')->sum('amount'));

        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'paypal',
            'file' => $this->paypal,
            '--month' => '2026-08',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(2, IpExpense::query()->count());
    }

    /** @test */
    public function unknown_bank_fails(): void
    {
        $this->artisan('ip-expenses:import-statement', [
            'bank' => 'alfa',
            'file' => $this->tochka,
            '--month' => '2026-08',
        ])->assertFailed();
    }
}
