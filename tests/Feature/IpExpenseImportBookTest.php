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
 * Импорт книги «Расходы по ИП» (H4188 п.2): dry-run по умолчанию ничего
 * не пишет; паритет manifest (sha256 csv, Σ строк, число строк) — refusal
 * на расхождение; суммы Decimal-exact; идемпотентность по import_hash
 * (дубликаты строки внутри вкладки сохраняются, а не слипаются).
 */
class IpExpenseImportBookTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture = __DIR__.'/../fixtures/ip-book-snapshot';

    /** @test */
    public function dry_run_creates_nothing_and_reports_parity(): void
    {
        $this->artisan('ip-expenses:import-book', ['path' => $this->fixture])
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();

        $this->assertSame(0, IpExpense::query()->count());
    }

    /** @test */
    public function apply_imports_book_rows_with_fx_category_and_undated(): void
    {
        $this->artisan('ip-expenses:import-book', ['path' => $this->fixture, '--apply' => true])
            ->assertSuccessful();

        // 8 строк с суммой (memo-строка без суммы не импортируется).
        $this->assertSame(8, IpExpense::query()->count());
        $this->assertEquals('52300.50', IpExpense::query()->sum('amount'));

        // Валютная строка: fx_note из счёт-колонки, категория эвристикой.
        $fx = IpExpense::query()->where('payee', 'Подрядчик Бобр')->firstOrFail();
        $this->assertSame('200 евро PayPal', $fx->fx_note);
        $this->assertSame('200 евро PayPal', $fx->account);
        $this->assertTrue($fx->category === IpExpenseCategory::Contractors);
        $this->assertSame('RUB', $fx->currency);
        $this->assertSame('2025-11-05', $fx->spent_at->toDateString());

        $tax = IpExpense::query()->where('payee', 'Налог УСН аванс')->firstOrFail();
        $this->assertTrue($tax->category === IpExpenseCategory::Taxes);
        $this->assertEquals('5000.50', $tax->amount);

        $this->assertTrue(
            IpExpense::query()->where('payee', 'Эквайринг за прием карт')->firstOrFail()
                ->category === IpExpenseCategory::Acquiring
        );
        $this->assertTrue(
            IpExpense::query()->where('payee', 'Зарплата ассистента')->firstOrFail()
                ->category === IpExpenseCategory::Salaries
        );

        // Строка без даты: не теряем деньги, spent_at NULL.
        $undated = IpExpense::query()->where('payee', 'Фрахт контейнер')->firstOrFail();
        $this->assertNull($undated->spent_at);
        $this->assertSame('50 евро PayPal', $undated->fx_note);

        // «Итого» — служебная строка книги, в импорт не попадает.
        $this->assertSame(0, IpExpense::query()->where('payee', 'like', 'Итого%')->count());

        // Провенанс: вкладка книги + аудит импорта со снапшотом.
        $this->assertSame('Октябрь 2025', $tax->source_tab);
        $this->assertSame(8, IpExpenseAudit::query()->where('action', IpExpenseAudit::ACTION_IMPORTED)->count());
        $this->assertNotNull(IpExpenseAudit::query()->where('changes->_snapshot', '2026-09-05')->first());
    }

    /** @test */
    public function rerun_is_idempotent_and_keeps_duplicate_rows(): void
    {
        $this->artisan('ip-expenses:import-book', ['path' => $this->fixture, '--apply' => true])->assertSuccessful();

        // Легитимный дубликат внутри вкладки: две одинаковые строки Альфы.
        // whereDate, не where: SQLite в тестах пишет 'Y-m-d H:i:s' даже под
        // cast 'date' (та же ловушка, что в Expense::scopeInWindow, issue #935).
        $this->assertSame(2, IpExpense::query()->where('payee', 'Поставщик Альфа')->whereDate('spent_at', '2025-11-10')->count());

        $this->artisan('ip-expenses:import-book', ['path' => $this->fixture, '--apply' => true])
            ->expectsOutputToContain('уже в базе: 8')
            ->assertSuccessful();

        $this->assertSame(8, IpExpense::query()->count());
    }

    /** @test */
    public function parity_mismatch_refuses_and_writes_nothing(): void
    {
        $tmp = storage_path('framework/testing/ip-book-corrupt');
        File::deleteDirectory($tmp);
        File::copyDirectory($this->fixture, $tmp);

        $manifestPath = $tmp.'/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $manifest['feeds'][0]['tabs'][0]['sum'] = 999999.0;
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE));

        $this->artisan('ip-expenses:import-book', ['path' => $tmp, '--apply' => true])
            ->expectsOutputToContain('REFUSE')
            ->assertFailed();

        $this->assertSame(0, IpExpense::query()->count());

        File::deleteDirectory($tmp);
    }

    /** @test */
    public function sha_only_mismatch_warns_but_does_not_refuse(): void
    {
        // Блобы вкладок, легших после LF-политики H2004, не могут совпасть
        // с sha256 исходных CRLF-байтов — это предупреждение, не отказ.
        $tmp = storage_path('framework/testing/ip-book-sha-drift');
        File::deleteDirectory($tmp);
        File::copyDirectory($this->fixture, $tmp);

        $manifestPath = $tmp.'/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $manifest['feeds'][0]['tabs'][0]['sha256_csv'] = str_repeat('0', 64);
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE));

        $this->artisan('ip-expenses:import-book', ['path' => $tmp])
            ->expectsOutputToContain('sha256 csv не совпал')
            ->assertSuccessful();

        $this->assertSame(0, IpExpense::query()->count());

        File::deleteDirectory($tmp);
    }
}
