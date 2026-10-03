<?php

declare(strict_types=1);

namespace Tests\Feature\Money;

use App\Models\BankStatementCredit;
use App\Models\Teacher;
use App\Models\TeacherPayoutPackage;
use App\Services\Reconciliation\BankStatementControl;
use App\Services\Reconciliation\BankStatementImporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

/**
 * H5522 — money-schema acceptance matrix, cross-dialect.
 *
 * Axes:
 *  - write path: ORM (Eloquent, date-cast attributes) vs raw (DB::table()->insert/update)
 *  - value shape: date 'Y-m-d' vs datetime 'Y-m-d H:i:s' landing in DATE columns
 *  - dialect: SQLite (:memory:, this suite) and MySQL 8.4 (ci.yml mysql-finance-tests
 *    job, DB_CONNECTION=mysql) — every assertion here is dialect-agnostic by design
 *  - migration order: forward up(), dependency-correct rollback down(), forward rebuild
 *
 * Three known-defect probes (each must go RED when its historical defect is
 * reintroduced; receipts in docs/EVIDENCE_MONEY_DB_CROSS_DIALECT_MATRIX_H5522_2026-10-01.md):
 *  1. period uniqueness  — tpp_bi lost its date() normalization (#2855 defect class)
 *  2. statement-day coverage — BankStatementControl accepts a partial-day import (#2859)
 *  3. FK rollback order — migration down() drops a parent before its child
 */
class MoneySchemaAcceptanceMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-09-23';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-24 05:00:00');
        config([
            'features.money_payout_packages' => true,
            'features.money_bank_statement_credits' => true,
            'money_recon.settlement_lag_days' => 0,
        ]);

        // Matrix precondition: FK enforcement must be ON or the rollback-order
        // probe cannot be honest. SQLite: PRAGMA; MySQL: assume InnoDB + FKs.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $on = (int) (DB::select('PRAGMA foreign_keys')[0]->foreign_keys ?? 0);
            $this->assertSame(1, $on, 'PRAGMA foreign_keys must be ON for this matrix');
        }
    }

    // ------------------------------------------------ axis: ORM × raw × date × datetime --

    /**
     * Transcript probe: the same business period written through ORM and raw
     * paths, in date and datetime shapes, must be equivalent when read back
     * and must both hit the period-uniqueness guard. Set MATRIX_TRANSCRIPT=1
     * to print the transcript line for the evidence receipt.
     */
    public function test_period_uniqueness_holds_for_orm_date_and_raw_datetime_writes(): void
    {
        $teacher = Teacher::factory()->create();

        // 1) ORM write: Eloquent date-cast attributes.
        TeacherPayoutPackage::create([
            'package_key' => 'pkg:orm:v1',
            'teacher_id' => $teacher->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);

        // Date/datetime equivalence: a datetime-shaped value in a DATE column
        // reads back with the same date part on both dialects (MySQL truncates,
        // SQLite keeps the string — date() normalizes both).
        $datePart = DB::table('teacher_payout_packages')
            ->where('package_key', 'pkg:orm:v1')
            ->value(DB::raw("date(period_start)"));
        $this->assertSame('2026-09-01', $datePart, 'date(period_start) must normalize the ORM-written value');

        if (env('MATRIX_TRANSCRIPT')) {
            fwrite(STDERR, "\n[MATRIX] driver=".DB::connection()->getDriverName()
                ." orm_period_start=".var_export(DB::table('teacher_payout_packages')->where('package_key', 'pkg:orm:v1')->value('period_start'), true)
                ." date_part=".var_export($datePart, true)."\n");
        }

        // 2) Raw write of the SAME period in datetime shape must be refused by
        //    the tpp_bi trigger regardless of the write path that came first.
        try {
            DB::table('teacher_payout_packages')->insert([
                'package_key' => 'pkg:raw:dup',
                'teacher_id' => $teacher->id,
                'period_start' => '2026-09-01 00:00:00',
                'period_end' => '2026-09-30 00:00:00',
                'state' => 'draft',
                'base_kopecks' => 0,
                'advance_kopecks' => 0,
                'offset_kopecks' => 0,
                'refund_adjustment_kopecks' => 0,
                'total_kopecks' => 0,
            ]);
            $this->fail('raw duplicate of a live ORM-written period was accepted — period uniqueness defect is back');
        } catch (QueryException $e) {
            $this->assertStringContainsString('another live package already covers this teacher and period', $e->getMessage());
        }

        // 3) Reversed order: raw date-shaped first, ORM duplicate second —
        //    the same guard must fire for the ORM write path.
        $teacherB = Teacher::factory()->create();
        DB::table('teacher_payout_packages')->insert([
            'package_key' => 'pkg:raw:v1',
            'teacher_id' => $teacherB->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'state' => 'draft',
            'base_kopecks' => 0,
            'advance_kopecks' => 0,
            'offset_kopecks' => 0,
            'refund_adjustment_kopecks' => 0,
            'total_kopecks' => 0,
        ]);

        try {
            TeacherPayoutPackage::create([
                'package_key' => 'pkg:orm:dup',
                'teacher_id' => $teacherB->id,
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
            ]);
            $this->fail('ORM duplicate of a live raw-written period was accepted — period uniqueness defect is back');
        } catch (QueryException $e) {
            $this->assertStringContainsString('another live package already covers this teacher and period', $e->getMessage());
        }
    }

    // ------------------------------------------------ axis: statement-day coverage --

    /**
     * #2859 defect class: a statement that covers only part of a business day
     * is NOT coverage — the source stays missing, never silently zero/present.
     */
    public function test_statement_day_coverage_requires_one_full_day_import(): void
    {
        $from = CarbonImmutable::parse(self::DAY.' 00:00:00');
        $to = CarbonImmutable::parse(self::DAY.' 23:59:59');
        $control = app(BankStatementControl::class);

        // No imports at all: missing.
        $this->assertSame('missing', $control->source($from, $to)['status']);

        // Partial-day import (00:00–11:59:59): still missing.
        $this->importRows([['23.09.2026', '100,00', 'D1', 'Перевод по QR коду ID AB12']],
            CarbonImmutable::parse(self::DAY.' 00:00:00'),
            CarbonImmutable::parse(self::DAY.' 11:59:59'));
        $partial = $control->source($from, $to);
        $this->assertSame('missing', $partial['status'], 'partial-day import must never count as coverage');

        // Full-day import: present, with the daily aggregate attached.
        $this->importRows([['23.09.2026', '250,00', 'D2', 'Возмещение по договору об обслуживании держателей платежных карт']],
            $from, $to);
        $full = $control->source($from, $to);
        $this->assertSame('present', $full['status'], 'a whole-day import is coverage');
        $this->assertSame(
            ['rows' => 1, 'kopecks' => 25000],
            $full['credits'][BankStatementCredit::KIND_ACQUIRING],
        );
    }

    // ------------------------------------------------ axis: forward / rollback order --

    /**
     * #2859/#2855 defect class: dropping a parent table before its child must
     * fail; the migration's down() must drop children first, and up() must
     * rebuild working triggers.
     */
    public function test_rollback_drops_children_before_parents_and_rebuild_is_forward_clean(): void
    {
        // Seed a raw parent+child pair (raw-insert axis on the statement tables).
        DB::table('bank_statement_imports')->insert([
            'file_sha256' => str_repeat('a', 64),
            'file_name' => 'matrix.csv',
            'provider' => 'tochka',
            'covers_from' => self::DAY.' 00:00:00',
            'covers_to' => self::DAY.' 23:59:59',
            'created_at' => self::DAY.' 05:00:00',
        ]);
        $importId = (int) DB::table('bank_statement_imports')->where('file_sha256', str_repeat('a', 64))->value('id');
        DB::table('bank_statement_credits')->insert([
            'row_hash' => str_repeat('b', 64),
            'import_id' => $importId,
            'booked_on' => self::DAY,
            'amount_kopecks' => 10000,
            'currency' => 'RUB',
            'kind' => BankStatementCredit::KIND_QR,
            'purpose_digest' => str_repeat('c', 64),
            'created_at' => self::DAY.' 05:00:00',
        ]);

        // (a) Wrong rollback order — parent first — must be refused by the FK
        //     on both dialects (SQLite: implicit DELETE violation; MySQL 8:
        //     ER_FK_CANNOT_DROP_PARENT).
        try {
            Schema::drop('bank_statement_imports');
            $this->fail('parent bank_statement_imports dropped before its child — FK rollback-order defect is back');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
            $this->assertTrue(Schema::hasTable('bank_statement_imports'), 'the parent must survive a wrong-order drop');
        }

        // (b) Dependency-correct rollback: the real migration down() drops the
        //     child first, then the parent, with rows present, without error.
        $statement = require database_path('migrations/2026_09_25_090000_create_bank_statement_credit_tables.php');
        $statement->down();
        $this->assertFalse(Schema::hasTable('bank_statement_credits'));
        $this->assertFalse(Schema::hasTable('bank_statement_imports'));

        // (c) Forward rebuild: up() recreates both tables and their append-only
        //     triggers — raw UPDATE must be refused again.
        $statement->up();
        $this->assertTrue(Schema::hasTable('bank_statement_imports'));
        DB::table('bank_statement_imports')->insert([
            'file_sha256' => str_repeat('d', 64),
            'file_name' => 'matrix-rebuild.csv',
            'provider' => 'tochka',
            'covers_from' => self::DAY.' 00:00:00',
            'covers_to' => self::DAY.' 23:59:59',
            'created_at' => self::DAY.' 05:00:00',
        ]);
        DB::table('bank_statement_credits')->insert([
            'row_hash' => str_repeat('e', 64),
            'import_id' => (int) DB::table('bank_statement_imports')->where('file_sha256', str_repeat('d', 64))->value('id'),
            'booked_on' => self::DAY,
            'amount_kopecks' => 10000,
            'currency' => 'RUB',
            'kind' => BankStatementCredit::KIND_QR,
            'purpose_digest' => str_repeat('f', 64),
            'created_at' => self::DAY.' 05:00:00',
        ]);
        $rebuiltRow = (int) DB::table('bank_statement_credits')->where('row_hash', str_repeat('e', 64))->value('id');
        try {
            DB::table('bank_statement_credits')->where('id', $rebuiltRow)->update(['amount_kopecks' => 1]);
            $this->fail('rebuilt append-only trigger did not refuse a raw UPDATE — forward rebuild is not clean');
        } catch (QueryException $e) {
            $this->assertStringContainsString('statement: credit rows are immutable', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- helpers --

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows
     */
    private function importRows(array $rows, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $lines = ['Дата проводки;Дата зачисления;Направление;Номер документа;Сумма операции в рублях;Назначение платежа'];
        foreach ($rows as [$date, $amount, $doc, $purpose]) {
            $lines[] = implode(';', [$date, $date, 'Входящий', $doc, $amount, $purpose]);
        }
        $lines[] = '23.09.2026;23.09.2026;Исходящий;OUT1;999,00;Оплата аренды';

        $path = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
        file_put_contents($path, implode("\n", $lines)."\n");

        app(BankStatementImporter::class)->importFile($path, $from, $to);
    }
}
