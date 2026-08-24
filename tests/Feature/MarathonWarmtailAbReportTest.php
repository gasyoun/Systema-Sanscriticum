<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarathonWarmtailAbReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'tg_bot_username' => 'samskrte_bot',
            'tg_bot_token' => 'fake-tg',
        ]);
    }

    private function enrollmentWithLead(string $start): MarathonEnrollment
    {
        $lead = Lead::factory()->create(['telegram_chat_id' => '12345', 'magnet_token' => Str::random(12)]);

        return MarathonEnrollment::factory()->create(['lead_id' => $lead->id, 'day0_started_at' => $start]);
    }

    public function test_reports_both_waves_with_zero_counts_before_any_traffic(): void
    {
        config(['marathon.warm_tail_wave2_from' => now()->subDays(5)->toDateString()]);
        $this->enrollmentWithLead(now()->subDays(9)->toDateString());
        $this->enrollmentWithLead(now()->subDays(4)->toDateString());

        Artisan::call('marathon:warmtail-ab-report');
        $output = Artisan::output();

        $this->assertStringContainsString('flagship', $output);
        $this->assertStringContainsString('membership', $output);
        $this->assertStringContainsString('0 purchaser(s)', $output);
    }

    public function test_counts_an_offer_branch_purchase_inside_the_window_but_not_the_tripwire(): void
    {
        config(['marathon.warm_tail_wave2_from' => now()->subDays(5)->toDateString()]);

        $enrollment = $this->enrollmentWithLead(now()->subDays(9)->toDateString());
        $user = User::factory()->create(['lead_id' => $enrollment->lead_id]);

        // PaymentObserver fires real Telegram/mail side effects on paid
        // statuses — silenced for this read-only report test.
        $silent = fn (array $attrs) => Payment::withoutEvents(fn () => Payment::create($attrs));

        // Tripwire itself — must NOT count as an offer-branch purchase.
        $silent([
            'user_id' => $user->id,
            'lead_id' => $enrollment->lead_id,
            'course_id' => null,
            'amount' => 500,
            'tariff' => 'marathon_paid',
            'status' => 'paid',
            'first_paid_at' => now()->subDays(8),
        ]);

        // Real offer purchase inside the day0..+16d window.
        $silent([
            'user_id' => $user->id,
            'course_id' => null,
            'amount' => 6000,
            'tariff' => 'block_1',
            'status' => 'paid',
            'first_paid_at' => now()->subDays(3),
        ]);

        // Outside the window — ignored.
        $silent([
            'user_id' => $user->id,
            'course_id' => null,
            'amount' => 99999,
            'tariff' => 'full',
            'status' => 'paid',
            'first_paid_at' => now()->subDays(40),
        ]);

        Artisan::call('marathon:warmtail-ab-report');
        $output = Artisan::output();

        $this->assertStringContainsString('1 purchaser(s)', $output);
        $this->assertStringContainsString('6 000', str_replace("\u{00a0}", ' ', $output));
    }
}
