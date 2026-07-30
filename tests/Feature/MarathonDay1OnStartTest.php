<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Jobs\SendLeadMagnet;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Product ruling (post-H1939): first marathon Day-1 message goes out on /start,
 * not on the next calendar day.
 */
class MarathonDay1OnStartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'tg_bot_username' => 'samskrte_bot',
            'tg_bot_token' => 'fake-tg-token',
        ]);
    }

    public function test_start_sends_day1_same_calendar_day(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        Bus::fake([SendLeadMagnet::class]);

        $token = Str::random(16);
        $lead = Lead::factory()->create([
            'telegram_chat_id' => null,
            'magnet_token' => $token,
            'magnet_channel' => 'telegram',
        ]);
        MarathonEnrollment::factory()->create([
            'lead_id' => $lead->id,
            'day0_started_at' => now(),
            'day1_completed_at' => null,
        ]);

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 777888999], 'text' => '/start '.$token],
        ]))->handle();

        $this->assertSame(777888999, (int) $lead->fresh()->telegram_chat_id);
        $this->assertNotNull(
            MarathonEnrollment::where('lead_id', $lead->id)->value('day1_completed_at')
        );
        Http::assertSent(fn ($req) => str_contains($req->url(), '/sendMessage')
            && str_contains((string) $req['text'], 'День 1'));
    }

    public function test_second_start_does_not_resend_day1(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        Bus::fake([SendLeadMagnet::class]);

        $token = Str::random(16);
        $lead = Lead::factory()->create([
            'telegram_chat_id' => 111,
            'magnet_token' => $token,
            'magnet_channel' => 'telegram',
        ]);
        MarathonEnrollment::factory()->create([
            'lead_id' => $lead->id,
            'day0_started_at' => now(),
            'day1_completed_at' => now()->subMinute(),
        ]);

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 111], 'text' => '/start '.$token],
        ]))->handle();

        Http::assertNothingSent();
    }

    public function test_start_without_marathon_enrollment_sends_no_day1(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        Bus::fake([SendLeadMagnet::class]);

        $token = Str::random(16);
        Lead::factory()->create([
            'telegram_chat_id' => null,
            'magnet_token' => $token,
            'magnet_channel' => 'telegram',
        ]);

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 42], 'text' => '/start '.$token],
        ]))->handle();

        Http::assertNothingSent();
    }
}
