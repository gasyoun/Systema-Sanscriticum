<?php

declare(strict_types=1);
namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ChannelPaymentPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_counts_unattributed_receipts_and_explicit_refunds_without_salary_or_other_dates(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $make = fn ($amount, $date, $extra = []) => Payment::withoutEvents(fn () => Payment::create(array_merge([
            'user_id' => $user->id, 'course_id' => $course->id, 'amount' => $amount,
            'tariff' => 'block_1', 'status' => 'paid', 'is_conditional' => false, 'first_paid_at' => $date,
        ], $extra)));
        $first = $make('8000.25', '2026-09-01 00:00:00');
        $make('-1000.10', '2026-09-30 23:59:59', ['refund_of_payment_id' => $first->id, 'tariff' => 'Расход']);
        $make('-500.00', '2026-09-15 12:00:00');
        $make('6000.00', '2026-10-01 00:00:00');
        $make('9000.00', '2026-09-10 00:00:00', ['tariff' => 'salary_payout']);
        $count = Payment::count();
        $this->assertSame(0, Artisan::call('report:channel-roi', [
            '--payments-from' => '2026-09-01', '--payments-to' => '2026-09-30', '--format' => 'json',
        ]));
        $data = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(800025, $data['totals']['receipts_kopecks']);
        $this->assertSame(100010, $data['totals']['refunds_kopecks']);
        $this->assertSame(700015, $data['totals']['net_kopecks']);
        $this->assertSame(-50000, $data['totals']['unclassified_kopecks']);
        $this->assertSame('(unattributed)', $data['rows'][0]['source']);
        $this->assertSame($count, Payment::count());
    }

    public function test_invalid_or_mixed_period_is_rejected(): void
    {
        foreach ([['--payments-from' => '2026-02-30', '--payments-to' => '2026-03-01'],
            ['--format' => 'json'], ['--payments-from' => '2026-09-01', '--payments-to' => '2026-09-30', '--days' => 7],
            ['--payments-from' => '2026-09-01', '--payments-to' => '2026-09-30', '--digest' => true]] as $options) {
            $this->assertSame(1, Artisan::call('report:channel-roi', $options));
        }
    }
}
