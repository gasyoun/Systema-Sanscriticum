<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankStatementCredit;
use App\Models\BankStatementImport;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\StatementReceiptMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H6705 (H5554 gap [1] finish): строка «неразобранные поступления» — зачисления
 * выписки Точки без связи с оплатой LMS. Канон H5480/H4645: QR и агрегаты
 * эквайринга построчно не маппятся (дневной контроль), transfer маппится
 * через «Заказ №N» = id оплаченного Payment; числа только при полном покрытии
 * цикла импортами.
 */
class StatementReceiptMapperTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function cycle_without_any_import_reports_no_coverage_not_zero(): void
    {
        $result = app(StatementReceiptMapper::class)->cycle('2026-09');

        $this->assertSame('no_coverage', $result['status']);
        $this->assertArrayNotHasKey('unmapped', $result);
    }

    /** @test */
    public function partial_import_coverage_withholds_sums(): void
    {
        $this->import('2026-09-01', '2026-09-15');

        $result = app(StatementReceiptMapper::class)->cycle('2026-09');

        $this->assertSame('partial_coverage', $result['status']);
        $this->assertGreaterThan(0, $result['uncovered_days']);
        $this->assertArrayNotHasKey('unmapped', $result);
    }

    /** @test */
    public function transfers_map_through_paid_orders_and_the_rest_is_unmapped(): void
    {
        $this->import('2026-09-01', '2026-09-30 23:59:59');

        $paidOrder = $this->paidPayment();
        $pendingOrder = $this->paidPayment('pending');

        $this->credit('2026-09-03', 100000, BankStatementCredit::KIND_TRANSFER, (int) $paidOrder->id);
        $this->credit('2026-09-04', 70000, BankStatementCredit::KIND_TRANSFER, (int) $pendingOrder->id);
        $this->credit('2026-09-05', 50000, BankStatementCredit::KIND_TRANSFER, null);
        $this->credit('2026-09-06', 30000, BankStatementCredit::KIND_TRANSFER, 99999999);
        $this->credit('2026-09-07', 20000, BankStatementCredit::KIND_OTHER, null);
        $this->credit('2026-09-08', 40000, BankStatementCredit::KIND_QR, null);
        $this->credit('2026-09-09', 60000, BankStatementCredit::KIND_ACQUIRING, null);

        $result = app(StatementReceiptMapper::class)->cycle('2026-09');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(7, $result['credits']['rows']);
        $this->assertSame(3700.0, $result['credits']['amount']);

        // Неразобранные: перевод без оплаченного заказа (700+500+300) + прочее (200).
        $this->assertSame(4, $result['unmapped']['rows']);
        $this->assertSame(1700.0, $result['unmapped']['amount']);
        $this->assertSame(3, $result['unmapped']['transfer_rows']);
        $this->assertSame(1500.0, $result['unmapped']['transfer_amount']);
        $this->assertSame(1, $result['unmapped']['other_rows']);
        $this->assertSame(200.0, $result['unmapped']['other_amount']);

        // Маппится только перевод по ОПЛАЧЕННОМУ заказу.
        $this->assertSame(1, $result['mapped_transfer']['rows']);
        $this->assertSame(1000.0, $result['mapped_transfer']['amount']);

        // QR и агрегат эквайринга — дневной контроль, не «неразобранные».
        $this->assertSame(2, $result['day_controlled']['rows']);
        $this->assertSame(1000.0, $result['day_controlled']['amount']);
    }

    private function import(string $from, string $to): BankStatementImport
    {
        return BankStatementImport::query()->create([
            'file_sha256' => hash('sha256', $from.'|'.$to),
            'file_name' => 'fixture-statement.csv',
            'provider' => 'tochka',
            'covers_from' => $from,
            'covers_to' => $to,
            'rows_total' => 0,
            'rows_imported' => 0,
            'rows_duplicate' => 0,
            'rows_skipped' => 0,
            'credited_kopecks' => 0,
        ]);
    }

    private function credit(string $bookedOn, int $kopecks, string $kind, ?int $orderRef): void
    {
        BankStatementCredit::query()->create([
            'row_hash' => hash('sha256', $bookedOn.'|'.$kopecks.'|'.$kind.'|'.($orderRef ?? 'x')),
            'import_id' => BankStatementImport::query()->first()->id,
            'booked_on' => $bookedOn,
            'amount_kopecks' => $kopecks,
            'currency' => 'RUB',
            'kind' => $kind,
            'order_ref' => $orderRef,
            'purpose_digest' => hash('sha256', 'purpose|'.$bookedOn.'|'.$kopecks.'|'.$kind),
        ]);
    }

    private function paidPayment(string $status = 'paid'): Payment
    {
        $student = User::factory()->create();
        $course = Course::factory()->create();

        return Payment::withoutEvents(fn (): Payment => Payment::create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => $status,
            'is_conditional' => false,
            'amount' => 1000.0,
            'tariff' => 'block_1',
        ]));
    }
}
