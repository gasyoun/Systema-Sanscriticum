<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H5163 — pilot reporting leg: first-ever buyers with attribution, refunds
 * and continuation separated. Read-only over payments.
 */
class ReportFirstTimeBuyersTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_time_buyers_with_refunds_continuation_and_attribution(): void
    {
        $leadVk = Lead::factory()->create(['utm_source' => 'vk', 'utm_campaign' => 'probe_sep26']);
        $leadOrganic = Lead::factory()->create(['utm_source' => null, 'source' => null, 'inferred_source' => null]);
        $buyerVk = User::factory()->create(['lead_id' => $leadVk->id]);
        $buyerRefunded = User::factory()->create(['lead_id' => $leadOrganic->id]);
        $buyerNoLead = User::factory()->create();
        $notABuyerYet = User::factory()->create(['lead_id' => $leadVk->id]);

        $course = Course::factory()->create();

        // Continued buyer: ₽500 tripwire first, then a block — both revenue.
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $buyerVk->id, 'course_id' => $course->id, 'amount' => 500,
            'tariff' => 'marathon_paid', 'status' => 'paid', 'first_paid_at' => now()->subDays(3),
        ]));
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $buyerVk->id, 'course_id' => $course->id, 'amount' => 6000,
            'tariff' => 'block_1', 'status' => 'paid', 'first_paid_at' => now()->subDay(),
        ]));

        // Refunded-only buyer: still a first-time buyer, net 0.
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $buyerRefunded->id, 'course_id' => $course->id, 'amount' => 4000,
            'tariff' => 'block_5', 'status' => 'refunded', 'first_paid_at' => now()->subDays(2),
        ]));

        // Unattributed buyer — lands in the '—' channel, not dropped.
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $buyerNoLead->id, 'course_id' => $course->id, 'amount' => 7000,
            'tariff' => 'full', 'status' => 'paid', 'first_paid_at' => now()->subDays(2),
        ]));

        // Deposit is pre-purchase: never makes a first-time buyer.
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $notABuyerYet->id, 'course_id' => $course->id, 'amount' => 1000,
            'tariff' => 'deposit', 'status' => 'paid', 'first_paid_at' => now()->subDay(),
        ]));

        $paymentsBefore = DB::table('payments')->count();
        Artisan::call('report:first-time-buyers');
        $output = str_replace("\u{00a0}", ' ', Artisan::output());

        $this->assertStringContainsString('vk / probe_sep26', $output);
        $this->assertStringContainsString('ИТОГО', $output);
        $this->assertStringContainsString('4 000', $output, 'refunded rubles shown as their own column');
        $this->assertStringContainsString('—', $output, 'unattributed buyer visible in the dash channel');
        $this->assertStringNotContainsString('deposit', $output, 'deposit never counts as a first purchase');

        // Read-only: no rows added, changed or removed by the report.
        $this->assertSame($paymentsBefore, DB::table('payments')->count());
        $this->assertSame(0, DB::table('payments')->whereNotNull('updated_at')->where('updated_at', '!=', DB::raw('created_at'))->count());
    }

    public function test_days_window_filters_by_first_ever_purchase_date(): void
    {
        $course = Course::factory()->create();
        $oldBuyer = User::factory()->create();
        $recentBuyer = User::factory()->create();

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $oldBuyer->id, 'course_id' => $course->id, 'amount' => 12345,
            'tariff' => 'full', 'status' => 'paid', 'first_paid_at' => now()->subDays(10),
        ]));
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $recentBuyer->id, 'course_id' => $course->id, 'amount' => 500,
            'tariff' => 'marathon_paid', 'status' => 'paid', 'first_paid_at' => now()->subDay(),
        ]));

        Artisan::call('report:first-time-buyers', ['--days' => 7]);
        $output = str_replace("\u{00a0}", ' ', Artisan::output());

        $this->assertStringContainsString('500', $output);
        $this->assertStringNotContainsString('12 345', $output, 'first purchase outside the window drops the buyer from the cohort');
    }

    public function test_by_source_collapses_campaigns(): void
    {
        $leadA = Lead::factory()->create(['utm_source' => 'vk', 'utm_campaign' => 'camp_a']);
        $leadB = Lead::factory()->create(['utm_source' => 'vk', 'utm_campaign' => 'camp_b']);
        $course = Course::factory()->create();

        foreach ([$leadA, $leadB] as $lead) {
            $user = User::factory()->create(['lead_id' => $lead->id]);
            Payment::withoutEvents(fn () => Payment::create([
                'user_id' => $user->id, 'course_id' => $course->id, 'amount' => 500,
                'tariff' => 'marathon_paid', 'status' => 'paid', 'first_paid_at' => now()->subDay(),
            ]));
        }

        Artisan::call('report:first-time-buyers', ['--by-source' => true]);
        $output = str_replace("\u{00a0}", ' ', Artisan::output());

        $this->assertStringNotContainsString('camp_a', $output);
        $this->assertStringNotContainsString('camp_b', $output);
        $this->assertStringContainsString('| vk', $output);
    }
}
