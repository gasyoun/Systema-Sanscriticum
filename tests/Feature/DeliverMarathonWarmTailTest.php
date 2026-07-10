<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverMarathonWarmTailTest extends TestCase
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

    private function enrollment(array $overrides = []): MarathonEnrollment
    {
        $lead = Lead::factory()->create(['telegram_chat_id' => '12345', 'magnet_token' => Str::random(12)]);

        return MarathonEnrollment::factory()->create(array_merge(['lead_id' => $lead->id], $overrides));
    }

    public function test_sends_day1_warm_tail_message_on_day4(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $enrollment = $this->enrollment(['day0_started_at' => now()->subDays(4)]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        $this->assertSame(1, $enrollment->fresh()->warm_tail_last_day_sent);
        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'Как вам марафон'));
    }

    public function test_does_not_send_during_the_3_day_marathon(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->enrollment(['day0_started_at' => now()->subDays(2)]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_resend_the_same_day(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->enrollment(['day0_started_at' => now()->subDays(4), 'warm_tail_last_day_sent' => 1]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_progresses_to_the_next_day_once_it_arrives(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $enrollment = $this->enrollment(['day0_started_at' => now()->subDays(5), 'warm_tail_last_day_sent' => 1]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        $this->assertSame(2, $enrollment->fresh()->warm_tail_last_day_sent);
        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'а если не успею каждый день'));
    }

    public function test_stops_after_the_warm_tail_window_elapses(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->enrollment(['day0_started_at' => now()->subDays(20), 'warm_tail_last_day_sent' => 13]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_excludes_paid_enrollments(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->enrollment(['day0_started_at' => now()->subDays(4), 'paid_at' => now()->subDay()]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_skips_enrollment_without_telegram_chat_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $lead = Lead::factory()->create(['telegram_chat_id' => null]);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id, 'day0_started_at' => now()->subDays(4)]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_interpolates_host_and_coupon_placeholders(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config(['marathon.host_name' => 'Тестовый Ведущий', 'marathon.coupon_amount' => 1000]);
        $this->enrollment(['day0_started_at' => now()->subDays(6), 'warm_tail_last_day_sent' => 2]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'Тестовый Ведущий'));
    }
}
