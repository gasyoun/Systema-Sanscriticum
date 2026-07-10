<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverMarathonRecordingTest extends TestCase
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

    private function enrollment(): MarathonEnrollment
    {
        $lead = Lead::factory()->create(['telegram_chat_id' => '12345', 'magnet_token' => Str::random(12)]);

        return MarathonEnrollment::factory()->create(['lead_id' => $lead->id]);
    }

    public function test_sends_recording_link_once_available(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $schedule = Schedule::create([
            'title' => 'Марафон День 3',
            'start' => now()->subDay(),
            'end' => now()->subHours(22),
            'zoom_recording_url' => 'https://zoom.us/rec/marathon',
        ]);
        config(['marathon.schedule_id' => $schedule->id]);

        $enrollment = $this->enrollment();

        $this->artisan('marathon:deliver-recording')->assertSuccessful();

        $this->assertNotNull($enrollment->fresh()->recording_sent_at);
        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'https://zoom.us/rec/marathon'));
    }

    public function test_does_not_send_before_the_session_ends(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $schedule = Schedule::create([
            'title' => 'Марафон День 3',
            'start' => now()->addHour(),
            'end' => now()->addHours(3),
            'zoom_recording_url' => 'https://zoom.us/rec/marathon',
        ]);
        config(['marathon.schedule_id' => $schedule->id]);

        $this->enrollment();

        $this->artisan('marathon:deliver-recording')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_send_without_a_recording_url(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $schedule = Schedule::create([
            'title' => 'Марафон День 3',
            'start' => now()->subDay(),
            'end' => now()->subHours(22),
        ]);
        config(['marathon.schedule_id' => $schedule->id]);

        $this->enrollment();

        $this->artisan('marathon:deliver-recording')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_resend(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $schedule = Schedule::create([
            'title' => 'Марафон День 3',
            'start' => now()->subDay(),
            'end' => now()->subHours(22),
            'zoom_recording_url' => 'https://zoom.us/rec/marathon',
        ]);
        config(['marathon.schedule_id' => $schedule->id]);

        $lead = Lead::factory()->create(['telegram_chat_id' => '12345', 'magnet_token' => Str::random(12)]);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id, 'recording_sent_at' => now()]);

        $this->artisan('marathon:deliver-recording')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_nothing_without_a_configured_schedule(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config(['marathon.schedule_id' => null]);

        $this->enrollment();

        $this->artisan('marathon:deliver-recording')->assertSuccessful();

        Http::assertNothingSent();
    }
}
