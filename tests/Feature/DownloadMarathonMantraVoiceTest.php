<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DownloadMarathonMantraVoice;
use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H445 Phase 4 (H546) — mirrors DownloadTelegramZapisiMediaTest's getFile +
 * raw-download pattern, but keyed to a MarathonEnrollment and landing on
 * the private 'local' disk (never public — H546 §3, storage/app disk class
 * shared with HomeworkFile, not a shareable URL).
 */
class DownloadMarathonMantraVoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DownloadMarathonMantraVoice::DISK);
        MarketingSetting::create(['tg_bot_token' => 'fake-tg-token']);
    }

    public function test_downloads_and_stores_voice_and_marks_received(): void
    {
        Http::fake([
            'api.telegram.org/botfake-tg-token/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'voice/file_1.oga']], 200),
            'api.telegram.org/file/botfake-tg-token/*' => Http::response('binary-voice-bytes', 200),
        ]);

        $enrollment = MarathonEnrollment::factory()->deva()->create();

        (new DownloadMarathonMantraVoice($enrollment->id, 'file-id-1'))->handle();

        $enrollment->refresh();
        $this->assertNotNull($enrollment->day2_voice_received_at);
        $this->assertSame('file-id-1', $enrollment->day2_voice_telegram_file_id);
        $this->assertSame(DownloadMarathonMantraVoice::DISK, $enrollment->day2_voice_disk);
        Storage::disk(DownloadMarathonMantraVoice::DISK)->assertExists($enrollment->day2_voice_path);
        $this->assertSame('binary-voice-bytes', Storage::disk(DownloadMarathonMantraVoice::DISK)->get($enrollment->day2_voice_path));
    }

    public function test_already_received_is_a_noop_never_overwrites(): void
    {
        Http::fake();

        $enrollment = MarathonEnrollment::factory()->deva()->create([
            'day2_voice_received_at' => now()->subHour(),
            'day2_voice_path' => 'marathon-mantra-voice/existing.oga',
        ]);

        (new DownloadMarathonMantraVoice($enrollment->id, 'new-file-id'))->handle();

        Http::assertNothingSent();
        $this->assertSame('marathon-mantra-voice/existing.oga', $enrollment->fresh()->day2_voice_path);
    }

    public function test_no_token_is_a_noop(): void
    {
        MarketingSetting::query()->update(['tg_bot_token' => null]);
        MarketingSetting::flushCached();
        Http::fake();

        $enrollment = MarathonEnrollment::factory()->deva()->create();

        (new DownloadMarathonMantraVoice($enrollment->id, 'file-id-1'))->handle();

        Http::assertNothingSent();
        $this->assertNull($enrollment->fresh()->day2_voice_received_at);
    }
}
