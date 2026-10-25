<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Models\BankStatementCredit;
use App\Models\BankStatementImport;
use App\Models\MoneyReconException;
use App\Models\User;
use App\Services\Reconciliation\BankStatementControl;
use App\Services\Reconciliation\BankStatementCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H7267 (остаток H5445/H5480): покрытие выписки по месяцам и дням.
 *
 * Доказываем три вещи:
 *  1. покрытие считается только по периоду, который назвал человек, и день
 *     покрыт только целиком — частичный период покрытием не является;
 *  2. даты строк выписки покрытие не создают (день без зачислений покрыт,
 *     строка вне периода день не покрывает);
 *  3. отчёт только читает: ни платежей, ни доступа, ни флагов, ни таблиц
 *     сверки он не трогает, а новый импорт честно переводит день в `present`.
 */
class BankStatementCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-08-01';

    private const TO = '2026-10-25';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 09:00:00');
        config([
            'features.money_ledger_core' => false,
            'features.money_refund_access_rules' => false,
            'features.money_bank_statement_credits' => true,
            'money_recon.settlement_lag_days' => 0,
            'money_recon.aggregate_tolerance_kopecks' => 0,
            'money_recon.acquiring_max_fee_bps' => 350,
        ]);
    }

    public function test_month_table_counts_only_fully_covered_days(): void
    {
        // Один импорт: 2026-08-01…2026-09-28. Сентябрь покрыт не целиком.
        $this->importCovering('2026-08-01', '2026-09-28');

        $report = $this->report(self::FROM, self::TO);
        $months = collect($report['months'])->keyBy('month');

        $this->assertSame(['2026-08', '2026-09', '2026-10'], $months->keys()->all());

        $august = $months['2026-08'];
        $this->assertSame(31, $august['days']);
        $this->assertSame(31, $august['covered']);
        $this->assertSame(0, $august['missing']);
        $this->assertSame(100.0, $august['coverage_pct']);
        $this->assertSame(['2026-08-01..2026-08-31'], $august['covered_ranges']);
        $this->assertSame([], $august['missing_ranges']);

        $september = $months['2026-09'];
        $this->assertSame(30, $september['days']);
        $this->assertSame(28, $september['covered']);
        $this->assertSame(2, $september['missing']);
        $this->assertSame(['2026-09-01..2026-09-28'], $september['covered_ranges']);
        $this->assertSame(['2026-09-29..2026-09-30'], $september['missing_ranges']);

        $october = $months['2026-10'];
        $this->assertSame(25, $october['days']);
        $this->assertSame(0, $october['covered']);
        $this->assertSame(25, $october['missing']);
        $this->assertSame(['2026-10-01..2026-10-25'], $october['missing_ranges']);

        $this->assertSame(86, $report['totals']['days']);
        $this->assertSame(59, $report['totals']['covered']);
        $this->assertSame(27, $report['totals']['missing']);
        $this->assertSame(68.6, $report['totals']['coverage_pct']);
        $this->assertSame(['2026-09-29..2026-10-25'], $report['totals']['missing_ranges']);

        $this->assertCount(1, $report['imports']);
        $this->assertSame('tochka_api', $report['imports'][0]['provider']);
        $this->assertSame('2026-09-28', $report['imports'][0]['covers_to']);
    }

    public function test_row_dates_never_infer_coverage(): void
    {
        // Период, названный человеком: 01…28 сентября. Строка выписки
        // датирована 30-м — покрытием она не становится.
        $this->importCovering('2026-09-01', '2026-09-28', [
            ['2026-09-30', 100000, BankStatementCredit::KIND_QR],
        ]);

        $report = $this->report('2026-09-28', '2026-09-30');
        $days = collect($report['days'])->keyBy('date');

        $this->assertTrue($days['2026-09-28']['covered']);
        $this->assertFalse($days['2026-09-29']['covered']);
        $this->assertFalse($days['2026-09-30']['covered']);
        $this->assertNull($days['2026-09-30']['import_id']);
        $this->assertSame(['2026-09-29..2026-09-30'], $report['totals']['missing_ranges']);
    }

    public function test_partial_day_is_not_coverage(): void
    {
        // Выписка до 11:59:59 — это половина дня, а не день.
        $this->importCovering('2026-09-23 00:00:00', '2026-09-23 11:59:59');

        $report = $this->report('2026-09-23', '2026-09-23');

        $this->assertSame(1, $report['totals']['days']);
        $this->assertSame(0, $report['totals']['covered']);
        $this->assertSame(1, $report['totals']['missing']);
        $this->assertSame(['2026-09-23'], $report['totals']['missing_ranges']);
    }

    public function test_second_import_closes_the_gap_and_the_source_turns_present(): void
    {
        $control = app(BankStatementControl::class);
        $day = CarbonImmutable::parse('2026-09-29');
        $flagBefore = config('features.money_bank_statement_credits');

        $this->payment(1000.00, '2026-09-29');
        $paymentsBefore = DB::table('payments')->count();
        $usersBefore = DB::table('users')->count();
        $exceptionsBefore = MoneyReconException::query()->count();

        $this->importCovering('2026-08-01', '2026-09-28');
        $this->assertSame('missing', $control->source($day, $day)['status']);

        // Новый импорт закрывает 29…30 сентября.
        $this->importCovering('2026-09-29', '2026-09-30', [
            ['2026-09-29', 100000, BankStatementCredit::KIND_QR],
        ]);

        $this->assertSame('present', $control->source($day, $day)['status']);
        $this->assertSame(
            100000,
            $control->source($day, $day)['credits'][BankStatementCredit::KIND_QR]['kopecks'],
        );

        // Деньги, доступ, флаги и очередь исключений не изменились.
        $this->assertSame($paymentsBefore, DB::table('payments')->count());
        $this->assertSame($usersBefore, DB::table('users')->count());
        $this->assertSame($exceptionsBefore, MoneyReconException::query()->count());
        $this->assertSame($flagBefore, config('features.money_bank_statement_credits'));
    }

    public function test_report_command_is_read_only_and_writes_its_json(): void
    {
        $this->importCovering('2026-08-01', '2026-09-28');
        $this->payment(1000.00, '2026-09-15');

        $before = [
            'payments' => DB::table('payments')->count(),
            'users' => DB::table('users')->count(),
            'imports' => BankStatementImport::query()->count(),
            'credits' => BankStatementCredit::query()->count(),
            'exceptions' => MoneyReconException::query()->count(),
        ];

        $json = null;
        $writes = $this->recordWrites(function () use (&$json): void {
            $json = tempnam(sys_get_temp_dir(), 'coverage').'.json';
            $this->artisan('money:statement-coverage', [
                '--from' => self::FROM,
                '--to' => self::TO,
                '--json' => $json,
            ])->assertExitCode(0);
        });

        $this->assertSame([], $writes, 'отчёт покрытия не должен писать в базу');
        $this->assertSame($before, [
            'payments' => DB::table('payments')->count(),
            'users' => DB::table('users')->count(),
            'imports' => BankStatementImport::query()->count(),
            'credits' => BankStatementCredit::query()->count(),
            'exceptions' => MoneyReconException::query()->count(),
        ]);

        $this->assertFileExists($json);
        $decoded = json_decode((string) file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(59, $decoded['totals']['covered']);
        $this->assertSame(27, $decoded['totals']['missing']);
        $this->assertSame('2026-08-01..2026-10-25', $decoded['window']['from'].'..'.$decoded['window']['to']);
        @unlink($json);
    }

    public function test_default_window_starts_at_the_first_import_and_stops_at_yesterday(): void
    {
        $this->importCovering('2026-08-01', '2026-09-28');

        $json = tempnam(sys_get_temp_dir(), 'coverage').'.json';
        $this->artisan('money:statement-coverage', ['--json' => $json])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);

        // Сегодня (26-10) ещё не наступило для сверки: окно кончается вчера.
        $this->assertSame('2026-08-01', $decoded['window']['from']);
        $this->assertSame('2026-10-25', $decoded['window']['to']);
        $this->assertSame(27, $decoded['totals']['missing']);
        @unlink($json);
    }

    // ------------------------------------------------------------- helpers --

    /** @return array<string, mixed> */
    private function report(string $from, string $to): array
    {
        return app(BankStatementCoverage::class)->report(
            CarbonImmutable::parse($from.($this->hasTime($from) ? '' : ' 00:00:00')),
            CarbonImmutable::parse($to.($this->hasTime($to) ? '' : ' 00:00:00')),
        );
    }

    private function hasTime(string $value): bool
    {
        return str_contains($value, ':');
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string}>  $credits
     */
    private function importCovering(string $from, string $to, array $credits = []): BankStatementImport
    {
        $import = BankStatementImport::create([
            'file_sha256' => hash('sha256', uniqid('h7267', true)),
            'file_name' => 'Tochka_'.substr($from, 0, 7).'.csv',
            'provider' => 'tochka_api',
            'covers_from' => $this->hasTime($from) ? $from : $from.' 00:00:00',
            'covers_to' => $this->hasTime($to) ? $to : $to.' 23:59:59',
            'rows_total' => count($credits),
            'rows_imported' => count($credits),
            'credited_kopecks' => array_sum(array_map(static fn (array $c): int => $c[1], $credits)),
        ]);

        foreach ($credits as [$bookedOn, $kopecks, $kind]) {
            BankStatementCredit::create([
                'row_hash' => hash('sha256', uniqid('row', true)),
                'import_id' => $import->id,
                'booked_on' => $bookedOn,
                'amount_kopecks' => $kopecks,
                'currency' => 'RUB',
                'kind' => $kind,
                'purpose_digest' => hash('sha256', 'purpose'),
            ]);
        }

        return $import;
    }

    /**
     * Все пишущие SQL-запросы, выполненные внутри $work.
     *
     * @return list<string>
     */
    private function recordWrites(callable $work): array
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|drop|create|truncate)\b/i', (string) $query->sql) === 1) {
                $writes[] = (string) $query->sql;
            }
        });
        $work();

        return $writes;
    }

    private function payment(float $amount, string $day): void
    {
        $user = User::factory()->create();
        DB::table('payments')->insert([
            'user_id' => $user->id,
            'amount' => number_format($amount, 2, '.', ''),
            'status' => 'paid',
            'tariff' => 'full',
            'first_paid_at' => $day.' 12:00:00',
            'created_at' => $day.' 12:00:00',
            'updated_at' => $day.' 12:00:00',
        ]);
    }
}
