<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliverDueMarathonContentTest extends TestCase
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

    private function enrollment(array $leadOverrides = [], array $enrollmentOverrides = []): MarathonEnrollment
    {
        $lead = Lead::factory()->create(array_merge([
            'telegram_chat_id' => '12345',
            'magnet_token' => \Illuminate\Support\Str::random(12),
        ], $leadOverrides));

        return MarathonEnrollment::factory()->create(array_merge([
            'lead_id' => $lead->id,
        ], $enrollmentOverrides));
    }

    public function test_sends_day1_content_when_personal_day_is_1(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->enrollment([], ['day0_started_at' => now()->subDay()]);

        $this->artisan('marathon:deliver-due')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/sendMessage')
            && str_contains((string) $req['text'], 'День 1'));
    }

    public function test_sends_day1_and_day2_together_when_catching_up_late(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        // Command hasn't run in a while — enrollee is already on day 2 with
        // neither day marked delivered yet. Both should go out in one pass.
        $this->enrollment([], ['day0_started_at' => now()->subDays(2)]);

        $this->artisan('marathon:deliver-due')->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_does_not_resend_already_delivered_day(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->enrollment([], [
            'day0_started_at' => now()->subDay(),
            'day1_completed_at' => now(),
        ]);

        $this->artisan('marathon:deliver-due')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_skips_enrollment_without_telegram_chat_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->enrollment(['telegram_chat_id' => null], ['day0_started_at' => now()->subDay()]);

        $this->artisan('marathon:deliver-due')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_send_day1_before_personal_day_1(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->enrollment([], ['day0_started_at' => now()]);

        $this->artisan('marathon:deliver-due')->assertSuccessful();

        Http::assertNothingSent();
    }
}
