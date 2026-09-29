<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaypalPaymentEvidenceLink;
use App\Models\PaypalReceiptEvidence;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\Payments\PaypalReceiptEvidenceImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PaypalReceiptEvidenceImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_fee_bearing_receipt_can_prove_two_block_rows_without_changing_money_tables(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $a = $this->payment($user, $course, 80, 'USD');
        $b = $this->payment($user, $course, 80, 'USD');
        [$csv, $map] = $this->files([$a->id, $b->id]);
        $beforePayments = Payment::query()->get()->toJson();
        $beforePayouts = TeacherPayout::query()->get()->toJson();

        $dry = app(PaypalReceiptEvidenceImporter::class)->import($csv, $map);
        $this->assertSame('dry_run', $dry['outcome']);
        $this->assertSame(2, $dry['linked_payments']);
        $this->assertSame(0, PaypalReceiptEvidence::query()->count());

        $applied = app(PaypalReceiptEvidenceImporter::class)->import($csv, $map, true);
        $this->assertSame('imported', $applied['outcome']);
        $this->assertSame(15108, PaypalReceiptEvidence::query()->firstOrFail()->net_minor);
        $this->assertSame(-892, PaypalReceiptEvidence::query()->firstOrFail()->fee_minor);
        $this->assertSame(16000, PaypalReceiptEvidence::query()->firstOrFail()->gross_minor);
        $this->assertSame(2, PaypalPaymentEvidenceLink::query()->count());
        $this->assertSame($beforePayments, Payment::query()->get()->toJson());
        $this->assertSame($beforePayouts, TeacherPayout::query()->get()->toJson());

        $again = app(PaypalReceiptEvidenceImporter::class)->import($csv, $map, true);
        $this->assertSame(2, $again['duplicate_links']);
        $this->assertSame(1, PaypalReceiptEvidence::query()->count());
    }

    public function test_currency_or_gross_mismatch_fails_closed_and_reversal_is_not_income(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $payment = $this->payment($user, $course, 100, 'EUR');
        [$csv, $map] = $this->files([$payment->id], currency: 'USD');

        $result = app(PaypalReceiptEvidenceImporter::class)->import($csv, $map, true);
        $this->assertSame('incomplete', $result['outcome']);
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(1, $result['receipts']);
        $this->assertSame(0, PaypalReceiptEvidence::query()->count());
    }

    public function test_evidence_is_append_only(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $payment = $this->payment($user, $course, 160, 'USD');
        [$csv, $map] = $this->files([$payment->id]);
        app(PaypalReceiptEvidenceImporter::class)->import($csv, $map, true);

        $this->expectException(QueryException::class);
        DB::table('paypal_receipt_evidence')->update(['net_minor' => 1]);
    }

    private function payment(User $user, Course $course, float $foreign, string $currency): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'paid',
            'amount' => 6000,
            'foreign_amount' => $foreign,
            'foreign_currency' => $currency,
            'tariff' => 'Блок',
            'received_account' => Payment::RECEIVED_SCHOOL,
            'first_paid_at' => '2026-09-14 12:00:00',
        ]);
    }

    /** @param list<int> $paymentIds @return array{string,string} */
    private function files(array $paymentIds, string $currency = 'USD'): array
    {
        $dir = storage_path('framework/testing/paypal-'.uniqid());
        mkdir($dir, 0777, true);
        $csv = $dir.'/activity.csv';
        $map = $dir.'/mapping.json';
        file_put_contents($csv, implode("\n", [
            'Date,Name,Type,Status,Currency,Amount,Fees,Total,Transaction ID,Item Title',
            "14/09/2026,Arkady Simkin,General Payment,Completed,{$currency},151.08,-8.92,151.08,TX-1,Two blocks",
            '21/09/2026,PayPal,Reversal of General Account Hold,Completed,EUR,986.49,0.00,986.49,REV-1,',
        ]));
        file_put_contents($map, json_encode(['transactions' => ['TX-1' => $paymentIds]], JSON_THROW_ON_ERROR));

        return [$csv, $map];
    }
}
