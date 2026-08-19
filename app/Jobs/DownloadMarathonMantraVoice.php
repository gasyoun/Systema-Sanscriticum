<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use App\Services\CuratorNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * H445 Phase 4 (H546) — downloads the Day-2 mantra-reading voice note a
 * `deva`-cohort enrollee sends to the marathon lead-magnet bot, landing it
 * in a NON-public disk (private submission, never a shareable URL — same
 * disk class as HomeworkFile). Pattern mirrors DownloadTelegramZapisiMedia
 * (getFile + raw download over the Bot API), but keyed to a
 * MarathonEnrollment rather than a raw chat/message pair, and uses the
 * marathon lead-magnet bot token (MarketingSetting::tg_bot_token) — the
 * bot the enrollee is actually talking to (@samskrte), not the zapisi bot.
 */
class DownloadMarathonMantraVoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public const DISK = 'local';

    public function __construct(
        public readonly int $enrollmentId,
        public readonly string $fileId,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $enrollment = MarathonEnrollment::find($this->enrollmentId);
        if (! $enrollment || $enrollment->hasSubmittedDay2Voice()) {
            // Already received (duplicate webhook delivery) or the enrollment
            // vanished — nothing to do, never overwrite an existing submission.
            return;
        }

        $token = (string) (MarketingSetting::cached()?->tg_bot_token ?? '');
        if ($token === '') {
            Log::warning('DownloadMarathonMantraVoice: no tg_bot_token configured', ['enrollment_id' => $this->enrollmentId]);

            return;
        }

        $getFile = Http::get("https://api.telegram.org/bot{$token}/getFile", ['file_id' => $this->fileId]);
        if (! $getFile->successful() || ! ($getFile->json('ok') ?? false)) {
            Log::warning('DownloadMarathonMantraVoice: getFile failed', ['enrollment_id' => $this->enrollmentId, 'status' => $getFile->status()]);

            return;
        }

        $filePath = (string) ($getFile->json('result.file_path') ?? '');
        if ($filePath === '') {
            return;
        }

        $download = Http::get("https://api.telegram.org/file/bot{$token}/{$filePath}");
        if (! $download->successful()) {
            Log::warning('DownloadMarathonMantraVoice: file download failed', ['enrollment_id' => $this->enrollmentId, 'status' => $download->status()]);

            return;
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'oga';
        $relativePath = "marathon-mantra-voice/{$this->enrollmentId}.{$extension}";

        Storage::disk(self::DISK)->put($relativePath, $download->body());

        $enrollment->update([
            'day2_voice_telegram_file_id' => $this->fileId,
            'day2_voice_disk' => self::DISK,
            'day2_voice_path' => $relativePath,
            'day2_voice_received_at' => now(),
        ]);

        Log::info('DownloadMarathonMantraVoice — stored', ['enrollment_id' => $this->enrollmentId]);

        // H546 §2 — paid track only; free track is self-assessed, no review queue.
        if ($enrollment->isPaidTrack()) {
            app(CuratorNotifier::class)->marathonMantraVoiceReceived($enrollment->fresh());
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('DownloadMarathonMantraVoice failed permanently', [
            'enrollment_id' => $this->enrollmentId,
            'error' => $e->getMessage(),
        ]);
    }
}
