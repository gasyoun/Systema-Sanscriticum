<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DownloadMarathonMantraVoice;
use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H445 Phase 4 (H546) — voice-message branch of ProcessTelegramMagnetUpdate,
 * mirroring MarathonDay1OnStartTest's fixture shape (Lead + MarathonEnrollment
 * keyed by telegram_chat_id, not magnet_token — a voice note carries no token).
 */
class ProcessTelegramMagnetUpdateMantraVoiceTest extends TestCase
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

    public function test_voice_from_known_deva_enrollee_dispatches_download_and_acks(): void
    {
        Bus::fake([DownloadMarathonMantraVoice::class]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $lead = Lead::factory()->create(['telegram_chat_id' => 555]);
        $enrollment = MarathonEnrollment::factory()->deva()->create(['lead_id' => $lead->id]);

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 555], 'voice' => ['file_id' => 'voice-abc']],
        ]))->handle();

        Bus::assertDispatched(DownloadMarathonMantraVoice::class, fn ($job) => $job->enrollmentId === $enrollment->id && $job->fileId === 'voice-abc');
        Http::assertSent(fn ($req) => str_contains($req->url(), '/sendMessage') && str_contains((string) $req['text'], 'Получили'));
    }

    public function test_voice_from_unlinked_chat_is_a_noop(): void
    {
        Bus::fake([DownloadMarathonMantraVoice::class]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 999999], 'voice' => ['file_id' => 'voice-xyz']],
        ]))->handle();

        Bus::assertNotDispatched(DownloadMarathonMantraVoice::class);
        Http::assertNothingSent();
    }

    public function test_voice_from_zero_cohort_lead_is_a_noop(): void
    {
        Bus::fake([DownloadMarathonMantraVoice::class]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $lead = Lead::factory()->create(['telegram_chat_id' => 556]);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id]); // zero cohort (factory default)

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 556], 'voice' => ['file_id' => 'voice-abc']],
        ]))->handle();

        Bus::assertNotDispatched(DownloadMarathonMantraVoice::class);
        Http::assertNothingSent();
    }

    public function test_voice_after_already_received_is_a_noop(): void
    {
        Bus::fake([DownloadMarathonMantraVoice::class]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $lead = Lead::factory()->create(['telegram_chat_id' => 557]);
        MarathonEnrollment::factory()->deva()->create([
            'lead_id' => $lead->id,
            'day2_voice_received_at' => now()->subMinute(),
        ]);

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 557], 'voice' => ['file_id' => 'voice-again']],
        ]))->handle();

        Bus::assertNotDispatched(DownloadMarathonMantraVoice::class);
        Http::assertNothingSent();
    }
}
