<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Models\Course;
use App\Models\Group;
use App\Models\MoneyBankStatement;
use App\Models\MoneyBankStatementCredit;
use App\Models\MoneyReconException;
use App\Models\MoneyReconRun;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reconciliation\BankStatementImporter;
use App\Services\Reconciliation\DailyReconciler;
use App\Services\Reconciliation\StatementFormatError;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H5480 (P3b): импорт зачислений банковской выписки и источник
 * `bank_statement` ежедневной сверки.
 *
 * Проверяется ровно то, чего у H5445 не было: идемпотентный повторный импорт,
 * «выписка покрывает день не целиком → источник остаётся missing», ОДНО
 * исключение на расхождение дневного агрегата и повтор сверки, который ничего
 * не пишет. Данные синтетические, файлы — во временном каталоге.
 */
class BankStatementCreditsTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-09-23';

    private User $student;

    private Course $course;

    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 05:00:00');
        config([
            'features.money_ledger_core' => false,
            'money_recon.settlement_lag_days' => 1,
            'money_recon.acquiring_fee_max_bps' => 300,
            'money_recon.aggregate_tolerance_kopecks' => 100,
        ]);

        $this->course = Course::factory()->create();
        $group = Group::factory()->create();
        $this->course->groups()->attach($group);
        $this->student = User::factory()->create();
        $this->student->groups()->attach($group);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers --

    private function statementFile(string $csv, string $name = 'Tochka.csv'): string
    {
        $path = sys_get_temp_dir().'/h5480-'.bin2hex(random_bytes(6)).'-'.$name;
        file_put_contents($path, $csv);
        $this->tmp[] = $path;

        return $path;
    }

    /** Выписка счёта Точки: QR-расчёт + дневной агрегат эквайринга + перевод. */
    private function accountCsv(string $date = '23.09.2026', string $qr = '5 000,00', string $card = '3 000,00'): string
    {
        return implode("\n", [
            'Номер документа;Дата проводки;Дата зачисления;Направление;Сумма операции в рублях;Назначение платежа',
            "101;{$date};{$date};Входящий;{$qr};Перевод по QR коду ID AB12CD за 23.09.2026",
            "102;{$date};{$date};Входящий;{$card};Перечисление по договору об обслуживании держателей платежных карт за 23.09.2026",
            "103;{$date};{$date};Исходящий;900,00;Комиссия банка",
        ])."\n";
    }

    private function import(string $path, ?string $from = self::DAY, ?string $to = self::DAY): array
    {
        return app(BankStatementImporter::class)->import(
            $path,
            $from === null ? null : CarbonImmutable::parse($from),
            $to === null ? null : CarbonImmutable::parse($to),
        );
    }

    private function pay(string $amount, array $attrs = []): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create(array_merge([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => $amount,
            'tariff' => 'block_1',
            'status' => 'paid',
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'first_paid_at' => self::DAY.' 12:00:00',
            'created_at' => self::DAY.' 12:00:00',
        ], $attrs)));
    }

    private function reconcile(bool $persist = true): array
    {
        return app(DailyReconciler::class)->run(CarbonImmutable::parse(self::DAY), false, $persist, 'manual');
    }

    // -------------------------------------------------------------- tests --

    public function test_import_is_idempotent_by_file_and_by_row(): void
    {
        $path = $this->statementFile($this->accountCsv());

        $first = $this->import($path);
        $this->assertSame(2, $first['parsed'], 'списания в зачисления не попадают');
        $this->assertSame(2, $first['inserted']);
        $this->assertSame(800000, $first['kopecks']);

        $second = $this->import($path);
        $this->assertTrue($second['already_imported']);
        $this->assertSame(0, $second['inserted']);

        // Тот же период другим файлом: строки те же → дедупликация по row_hash.
        $third = $this->import($this->statementFile($this->accountCsv()."\n", 'Tochka-again.csv'));
        $this->assertFalse($third['already_imported']);
        $this->assertSame(0, $third['inserted'], 'повтор тех же строк не удваивает зачисления');

        $this->assertSame(2, MoneyBankStatementCredit::query()->count());
        $kinds = MoneyBankStatementCredit::query()->orderBy('id')->pluck('kind')->all();
        $this->assertSame([MoneyBankStatementCredit::KIND_QR, MoneyBankStatementCredit::KIND_CARD_AGGREGATE], $kinds);
    }

    public function test_unknown_header_is_refused_rather_than_silently_skipped(): void
    {
        $path = $this->statementFile("что-то;совсем;другое\n1;2;3\n");

        $this->expectException(StatementFormatError::class);
        $this->import($path);
    }

    public function test_source_stays_missing_when_the_statement_does_not_cover_the_whole_day(): void
    {
        // Выписка покрывает только предыдущий день.
        $this->import($this->statementFile($this->accountCsv('22.09.2026')), '2026-09-22', '2026-09-22');

        $r = $this->reconcile(false);

        $this->assertSame('missing', $r['sources']['bank_statement']['status']);
        $this->assertStringContainsString('2026-09-22', (string) $r['sources']['bank_statement']['note']);
        $this->assertContains('bank_statement', $r['missing_sources']);
        $this->assertSame(MoneyReconRun::INCOMPLETE, $r['status']);
    }

    public function test_covered_day_makes_the_source_present_with_daily_aggregates(): void
    {
        // Оплаты канала bank_acquiring на 8 200 ₽; банк зачислил 8 000 ₽
        // (за вычетом комиссии ≈2,4 %) — в коридоре, исключений нет.
        $this->pay('5000.00');
        $this->pay('3200.00');
        $this->import($this->statementFile($this->accountCsv()));

        $r = $this->reconcile(false);
        $source = $r['sources']['bank_statement'];

        $this->assertSame('present', $source['status']);
        $this->assertSame(self::DAY, $source['business_date']);
        $this->assertSame(800000, $source['kopecks']);
        $this->assertSame(500000, $source['by_kind'][MoneyBankStatementCredit::KIND_QR]['kopecks']);
        $this->assertSame(300000, $source['by_kind'][MoneyBankStatementCredit::KIND_CARD_AGGREGATE]['kopecks']);
        $this->assertSame(820000, $source['control']['expected_kopecks']);
        $this->assertSame(2, $source['control']['expected_payments']);
        $this->assertNotContains('bank_statement', $r['missing_sources']);
        $this->assertSame(
            [],
            array_values(array_filter($r['new_exception_types'] ?? [], fn ($n, $t) => $t === MoneyReconException::CURRENCY_AMOUNT_MISMATCH, ARRAY_FILTER_USE_BOTH)),
            'агрегат в коридоре исключений не открывает'
        );
    }

    public function test_aggregate_gap_opens_exactly_one_exception_and_replay_writes_nothing(): void
    {
        // Оплат на 20 000 ₽, а банк зачислил 8 000 ₽ — разрыв далеко за
        // коридором комиссии: ровно одно исключение.
        $this->pay('20000.00');
        $this->import($this->statementFile($this->accountCsv()));

        $first = $this->reconcile();
        $this->assertSame(DailyReconciler::OUTCOME_RECORDED, $first['outcome']);

        $mismatch = MoneyReconException::query()
            ->where('type', MoneyReconException::CURRENCY_AMOUNT_MISMATCH)
            ->where('source', 'bank_statement')->get();
        $this->assertCount(1, $mismatch, 'разрыв дневного агрегата = ровно одно исключение');
        $this->assertSame('bank-statement-day:'.self::DAY, $mismatch[0]->source_ref);
        $this->assertSame(800000 - 2000000, $mismatch[0]->amount_kopecks);
        $this->assertSame(2, count($mismatch[0]->evidence['row_hashes']), 'в доказательстве — хэши строк выписки');

        $runs = MoneyReconRun::query()->count();
        $exceptions = MoneyReconException::query()->count();

        $replay = $this->reconcile();
        $this->assertSame(DailyReconciler::OUTCOME_REPLAY, $replay['outcome']);
        $this->assertSame($runs, MoneyReconRun::query()->count());
        $this->assertSame($exceptions, MoneyReconException::query()->count());
    }

    public function test_unrecognised_purpose_opens_unknown_purpose_for_the_day(): void
    {
        $this->pay('5000.00');
        $csv = implode("\n", [
            'Номер документа;Дата проводки;Дата зачисления;Направление;Сумма операции в рублях;Назначение платежа',
            '101;23.09.2026;23.09.2026;Входящий;5 000,00;Перевод по QR коду ID AB12CD',
            '102;23.09.2026;23.09.2026;Входящий;1 500,00;Прочее поступление без узнаваемого назначения',
        ])."\n";
        $this->import($this->statementFile($csv));

        $this->reconcile();

        $unknown = MoneyReconException::query()
            ->where('type', MoneyReconException::UNKNOWN_PURPOSE)
            ->where('source', 'bank_statement')->get();
        $this->assertCount(1, $unknown);
        $this->assertSame(150000, $unknown[0]->amount_kopecks);
        $this->assertSame(1, $unknown[0]->evidence['rows']);

        // Назначение нераспознанной строки не хранится (ПД не добавляем).
        $row = MoneyBankStatementCredit::query()->where('kind', MoneyBankStatementCredit::KIND_OTHER)->sole();
        $this->assertNull($row->purpose);
        $this->assertSame(64, strlen((string) $row->purpose_digest));
    }

    public function test_statement_credits_are_append_only(): void
    {
        $this->import($this->statementFile($this->accountCsv()));
        $row = MoneyBankStatementCredit::query()->orderBy('id')->firstOrFail();

        try {
            $row->forceFill(['amount_kopecks' => 1])->save();
            $this->fail('строку выписки удалось изменить — append-only нарушен');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_period_is_derived_from_rows_when_not_given(): void
    {
        $r = $this->import($this->statementFile($this->accountCsv()), null, null);

        $this->assertSame(MoneyBankStatement::PERIOD_DERIVED, $r['statement']->period_source);
        $this->assertSame(self::DAY, $r['statement']->covers_from->toDateString());
        $this->assertSame(self::DAY, $r['statement']->covers_to->toDateString());
    }
}
