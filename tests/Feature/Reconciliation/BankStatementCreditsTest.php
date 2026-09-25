<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Models\BankStatementCredit;
use App\Models\BankStatementImport;
use App\Models\MoneyReconException;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reconciliation\BankStatementImporter;
use App\Services\Reconciliation\DailyReconciler;
use App\Services\Reconciliation\StatementFormatError;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H5480 (P3): источник bank_statement — идемпотентный импорт зачислений,
 * покрытие дня целиком, дневной агрегатный контроль и повтор без записи.
 * Только синтетические данные; платежи вставляются без событий модели.
 */
class BankStatementCreditsTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-09-23';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 05:00:00');
        config([
            'features.money_ledger_core' => false,
            'features.money_refund_access_rules' => false,
            'features.money_bank_statement_credits' => true,
            'money_recon.settlement_lag_days' => 0,
            'money_recon.aggregate_tolerance_kopecks' => 0,
            'money_recon.acquiring_max_fee_bps' => 350,
        ]);
    }

    public function test_import_is_idempotent_and_append_only(): void
    {
        $path = $this->writeStatement([
            ['23.09.2026', '5000,00', 'D1', 'Перевод по QR коду ID AB12 от плательщика'],
            ['23.09.2026', '1000,00', 'D2', 'Оплата Заказ №77'],
        ]);

        $first = $this->import($path);
        $this->assertSame('imported', $first['outcome']);
        $this->assertSame(2, $first['imported']);
        $this->assertSame(600000, $first['kopecks']);
        $this->assertSame(2, BankStatementCredit::query()->count());

        // Тот же файл — прежний импорт, ни одной новой строки.
        $second = $this->import($path);
        $this->assertSame('already_imported', $second['outcome']);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(2, BankStatementCredit::query()->count());
        $this->assertSame(1, BankStatementImport::query()->count());

        // Другой файл с теми же строками + одной новой: дубли не удваиваются.
        $wider = $this->writeStatement([
            ['23.09.2026', '5000,00', 'D1', 'Перевод по QR коду ID AB12 от плательщика'],
            ['23.09.2026', '1000,00', 'D2', 'Оплата Заказ №77'],
            ['23.09.2026', '250,00', 'D3', 'Возмещение по договору об обслуживании держателей платежных карт'],
        ]);
        $third = $this->import($wider);
        $this->assertSame(1, $third['imported']);
        $this->assertSame(2, $third['duplicate']);
        $this->assertSame(3, BankStatementCredit::query()->count());
    }

    public function test_credit_rows_are_immutable(): void
    {
        $this->import($this->writeStatement([['23.09.2026', '100,00', 'D9', 'Перевод по QR коду ID ZZ1']]));
        $row = BankStatementCredit::query()->firstOrFail();

        $this->expectException(QueryException::class);
        DB::table('bank_statement_credits')->where('id', $row->id)->update(['amount_kopecks' => 1]);
    }

    public function test_unknown_header_is_refused_not_silently_skipped(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
        file_put_contents($path, "foo;bar\n1;2\n");

        $this->expectException(StatementFormatError::class);
        $this->import($path);
    }

    public function test_partial_day_statement_keeps_the_source_missing(): void
    {
        // Выписка покрывает только половину дня — это НЕ покрытие.
        $this->import(
            $this->writeStatement([['23.09.2026', '100,00', 'D1', 'Перевод по QR коду ID AB12']]),
            CarbonImmutable::parse(self::DAY.' 00:00:00'),
            CarbonImmutable::parse(self::DAY.' 11:59:59'),
        );

        $report = $this->reconcile(persist: false);
        $this->assertSame('missing', $report['sources']['bank_statement']['status']);
        $this->assertContains('bank_statement', $report['missing_sources']);
        $this->assertSame('incomplete', $report['status']);
    }

    public function test_full_day_statement_makes_the_source_present(): void
    {
        $this->import($this->writeStatement([['23.09.2026', '100,00', 'D1', 'Перевод по QR коду ID AB12']]));
        $this->payment(100.00);

        $report = $this->reconcile(persist: false);
        $this->assertSame('present', $report['sources']['bank_statement']['status']);
        $this->assertNotContains('bank_statement', $report['missing_sources']);
        $this->assertSame(
            ['rows' => 1, 'kopecks' => 10000],
            $report['sources']['bank_statement']['credits'][BankStatementCredit::KIND_QR],
        );
    }

    public function test_source_stays_missing_while_the_flag_is_off(): void
    {
        config(['features.money_bank_statement_credits' => false]);
        $this->import($this->writeStatement([['23.09.2026', '100,00', 'D1', 'Перевод по QR коду ID AB12']]));

        $report = $this->reconcile(persist: false);
        $this->assertSame('missing', $report['sources']['bank_statement']['status']);
        $this->assertSame([], $report['new_exception_types']);
    }

    public function test_aggregate_mismatch_opens_exactly_one_exception(): void
    {
        // Банк прислал 5000 ₽ по QR, а оплат в кассе только на 4000 ₽.
        $this->import($this->writeStatement([['23.09.2026', '5000,00', 'D1', 'Перевод по QR коду ID AB12']]));
        $this->payment(4000.00);

        $report = $this->reconcile(persist: true);
        $this->assertSame(DailyReconciler::OUTCOME_RECORDED, $report['outcome']);

        $opened = MoneyReconException::query()
            ->where('source', 'bank_statement')
            ->where('type', MoneyReconException::CURRENCY_AMOUNT_MISMATCH)
            ->get();
        $this->assertCount(1, $opened);
        $this->assertSame(100000, (int) $opened[0]->amount_kopecks);
        $this->assertSame('qr_settlements_vs_payments', $opened[0]->evidence['control']);
        $this->assertCount(1, $opened[0]->evidence['row_hashes']);
    }

    public function test_acquiring_aggregate_within_the_fee_band_opens_nothing(): void
    {
        // 10 000 ₽ оплат, банк прислал 9 800 ₽ — комиссия 200 б.п., в норме.
        $this->import($this->writeStatement([
            ['23.09.2026', '9800,00', 'D1', 'Возмещение по договору об обслуживании держателей платежных карт'],
        ]));
        $this->payment(10000.00);

        $report = $this->reconcile(persist: true);
        $this->assertSame(0, MoneyReconException::query()->where('source', 'bank_statement')->count());
        // Исключения уровня платежа (отсутствующий номер транзакции) к выписке
        // отношения не имеют — контролю выписки сказать нечего.
        $this->assertArrayNotHasKey(MoneyReconException::CURRENCY_AMOUNT_MISMATCH, $report['new_exception_types']);
    }

    public function test_unclassified_credits_open_one_unknown_purpose(): void
    {
        $this->import($this->writeStatement([
            ['23.09.2026', '700,00', 'D1', 'Пополнение счёта'],
            ['23.09.2026', '300,00', 'D2', 'Прочее поступление'],
        ]));

        $this->reconcile(persist: true);

        $opened = MoneyReconException::query()
            ->where('source', 'bank_statement')
            ->where('type', MoneyReconException::UNKNOWN_PURPOSE)
            ->get();
        $this->assertCount(1, $opened);
        $this->assertSame(100000, (int) $opened[0]->amount_kopecks);
        $this->assertSame(2, $opened[0]->evidence['rows']);
    }

    public function test_replay_of_the_same_day_writes_nothing(): void
    {
        $this->import($this->writeStatement([['23.09.2026', '5000,00', 'D1', 'Перевод по QR коду ID AB12']]));
        $this->payment(4000.00);

        $first = $this->reconcile(persist: true);
        $exceptions = MoneyReconException::query()->count();

        $second = $this->reconcile(persist: true);
        $this->assertSame(DailyReconciler::OUTCOME_REPLAY, $second['outcome']);
        $this->assertSame($first['run_id'], $second['run_id']);
        $this->assertSame(0, $second['new_exceptions']);
        $this->assertSame($exceptions, MoneyReconException::query()->count());
        $this->assertSame(1, DB::table('money_recon_runs')->count());
    }

    // ------------------------------------------------------------- helpers --

    /** @return array<string, mixed> */
    private function reconcile(bool $persist): array
    {
        return app(DailyReconciler::class)->run(
            CarbonImmutable::parse(self::DAY),
            allHistory: false,
            persist: $persist,
            mode: 'manual',
        );
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows
     */
    private function writeStatement(array $rows): string
    {
        $lines = ['Дата проводки;Дата зачисления;Направление;Номер документа;Сумма операции в рублях;Назначение платежа'];
        foreach ($rows as [$date, $amount, $doc, $purpose]) {
            $lines[] = implode(';', [$date, $date, 'Входящий', $doc, $amount, $purpose]);
        }
        $lines[] = '23.09.2026;23.09.2026;Исходящий;OUT1;999,00;Оплата аренды';

        $path = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    /** @return array<string, mixed> */
    private function import(string $path, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        return app(BankStatementImporter::class)->importFile(
            $path,
            $from ?? CarbonImmutable::parse(self::DAY.' 00:00:00'),
            $to ?? CarbonImmutable::parse(self::DAY.' 23:59:59'),
        );
    }

    private function payment(float $amount): void
    {
        $user = User::factory()->create();
        DB::table('payments')->insert([
            'user_id' => $user->id,
            'amount' => number_format($amount, 2, '.', ''),
            'status' => 'paid',
            'tariff' => 'full',
            'first_paid_at' => self::DAY.' 12:00:00',
            'created_at' => self::DAY.' 12:00:00',
            'updated_at' => self::DAY.' 12:00:00',
        ]);
    }
}
