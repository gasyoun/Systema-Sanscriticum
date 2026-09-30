<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Lead;
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

    public function test_uses_effective_lead_priority_user_utm_and_source_payment_for_refunds(): void
    {
        $course = Course::factory()->create();
        $landing = LandingPage::create(['title' => 'L', 'slug' => 'period-attribution']);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'lead@example.com',
            'source' => null, 'utm_source' => 'vk-raw',
            'utm_campaign' => 'grammar',
        ]);
        $lead->forceFill(['inferred_source' => 'vk-inferred'])->save();
        $leadUser = User::factory()->create(['lead_id' => $lead->id]);
        $utmUser = User::factory()->create(['lead_id' => null, 'utm_source' => 'youtube', 'utm_campaign' => 'video-1']);
        $make = fn (User $user, $amount, $date, $extra = []) => Payment::withoutEvents(fn () => Payment::create(array_merge([
            'user_id' => $user->id, 'course_id' => $course->id, 'amount' => $amount,
            'tariff' => 'block_1', 'status' => 'paid', 'is_conditional' => false, 'first_paid_at' => $date,
        ], $extra)));
        $source = $make($leadUser, '8000.00', '2026-09-02 12:00:00');
        $make($leadUser, '-1000.00', '2026-09-20 12:00:00', [
            'lead_id' => null, 'refund_of_payment_id' => $source->id, 'tariff' => 'Расход',
        ]);
        $make($utmUser, '6000.00', '2026-09-03 12:00:00');

        $this->assertSame(0, Artisan::call('report:channel-roi', [
            '--payments-from' => '2026-09-01', '--payments-to' => '2026-09-30', '--format' => 'json',
        ]));
        $rows = collect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['rows'])->keyBy('source');

        $this->assertArrayHasKey('vk-inferred', $rows->all(), 'sources: '.$rows->keys()->implode(', '));
        $this->assertArrayHasKey('youtube', $rows->all());
        $this->assertSame('inferred', $rows['vk-inferred']['evidence']);
        $this->assertSame(800000, $rows['vk-inferred']['receipts_kopecks']);
        $this->assertSame(100000, $rows['vk-inferred']['refunds_kopecks']);
        $this->assertSame('tracked-user', $rows['youtube']['evidence']);
        $this->assertSame(600000, $rows['youtube']['receipts_kopecks']);
    }
}
